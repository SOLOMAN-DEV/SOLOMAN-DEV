'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { MemoryOutboxStore, MysqlOutboxStore, OutboxWorker, CrmError, events } = require('../src');

const quiet = { warn() {}, error() {}, info() {} };

/** A fake CrmClient whose sendEvents answers from a per-call script. */
function fakeClient(answer) {
  const sent = [];
  return {
    sent,
    async sendEvents(evs) {
      sent.push(evs.map((e) => e.id));
      return answer(evs, sent.length);
    },
  };
}

const allOk = (evs) => evs.map((e) => ({ id: e.id, status: 'ok' }));

function sampleEvents() {
  return [
    events.customerRegistered({ customerId: 'C-1', name: 'Asha', email: 'a@example.com', phone: '98765' }),
    events.orderPlaced({ orderId: 'O-1', customerId: 'C-1', productId: 'P-1', amount: '100.00' }),
    events.orderShipped({ orderId: 'O-1' }),
  ];
}

async function exerciseStore(store) {
  const evs = sampleEvents();
  await store.enqueue(evs);
  await store.enqueue([evs[0]]); // duplicate id is ignored
  assert.deepEqual(await store.stats(), { pending: 3, sent: 0, dead: 0 });

  // 1st run: event 2 fails retryably -> 1 sent, 2 and 3 wait (order is preserved).
  const client = fakeClient((batch, n) => {
    if (n === 1) {
      return [
        { id: batch[0].id, status: 'ok' },
        { id: batch[1].id, status: 'error', http_status: 503, retryable: true, detail: 'busy' },
        { id: batch[2].id, status: 'not_processed', retryable: true },
      ];
    }
    return allOk(batch);
  });
  const worker = new OutboxWorker({ client, store, logger: quiet });
  worker.delayFor = () => 0; // no waiting in tests
  assert.equal(await worker.runOnce(), 3);
  assert.deepEqual(await store.stats(), { pending: 2, sent: 1, dead: 0 });

  // 2nd run delivers the rest, in order.
  assert.equal(await worker.runOnce(), 2);
  assert.deepEqual(client.sent[1], [evs[1].id, evs[2].id]);
  assert.deepEqual(await store.stats(), { pending: 0, sent: 3, dead: 0 });
  assert.equal(await worker.runOnce(), 0);
}

test('memory store: delivers in order, retries, de-duplicates', async () => {
  await exerciseStore(new MemoryOutboxStore());
});

test('permanent rejections are dead-lettered and stop blocking the queue', async () => {
  const store = new MemoryOutboxStore();
  await store.enqueue(sampleEvents());
  const dead = [];
  const client = fakeClient((batch) => batch.map((e, i) => (i === 0
    ? { id: e.id, status: 'error', http_status: 422, retryable: false, detail: 'invalid' }
    : { id: e.id, status: 'ok' })));
  const worker = new OutboxWorker({ client, store, logger: quiet, onDeadLetter: (d) => dead.push(d) });
  await worker.runOnce();
  assert.deepEqual(await store.stats(), { pending: 0, sent: 2, dead: 1 });
  assert.equal(dead[0].error, '422: invalid');
});

test('a failed request (CRM down, bad key) backs off but never loses events', async () => {
  const store = new MemoryOutboxStore();
  await store.enqueue(sampleEvents());
  const client = fakeClient(() => { throw new CrmError('401 bad key', { status: 401, retryable: false }); });
  const worker = new OutboxWorker({ client, store, logger: quiet });
  assert.equal(await worker.runOnce(), 0);
  assert.deepEqual(await store.stats(), { pending: 3, sent: 0, dead: 0 });
  assert.deepEqual(await store.nextBatch(10), [], 'head event is waiting for its retry time');
});

test('only one worker sends at a time', async () => {
  const store = new MemoryOutboxStore();
  await store.enqueue(sampleEvents());
  let release;
  const gate = new Promise((r) => { release = r; });
  const client = fakeClient(async (batch) => { await gate; return allOk(batch); });
  const a = new OutboxWorker({ client, store, logger: quiet });
  const b = new OutboxWorker({ client, store, logger: quiet });
  const first = a.runOnce();
  assert.equal(await b.runOnce(), null, 'second worker skipped while the first holds the lock');
  release();
  assert.equal(await first, 3);
});

const MYSQL_URL = process.env.TEST_OUTBOX_MYSQL_URL;
let mysql;
try { mysql = require('mysql2/promise'); } catch { mysql = null; }

test('mysql store: same behaviour against a real database', { skip: !(MYSQL_URL && mysql) && 'set TEST_OUTBOX_MYSQL_URL and install mysql2' }, async () => {
  const fs = require('node:fs');
  const path = require('node:path');
  const pool = mysql.createPool({ uri: MYSQL_URL, connectionLimit: 4 });
  try {
    await pool.query('DROP TABLE IF EXISTS storefront_crm_outbox');
    await pool.query(fs.readFileSync(path.join(__dirname, '..', 'sql', 'outbox.mysql.sql'), 'utf8'));
    const store = new MysqlOutboxStore(pool);
    await exerciseStore(store);

    // enqueue inside the caller's transaction: a rollback leaves no event behind
    const conn = await pool.getConnection();
    await conn.beginTransaction();
    await store.enqueue([events.orderCancelled({ orderId: 'O-9' })], conn);
    await conn.rollback();
    conn.release();
    assert.equal((await store.stats()).pending, 0);

    assert.equal(await store.purgeSent(0), 3);
  } finally {
    await pool.end();
  }
});
