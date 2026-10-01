'use strict';

// End-to-end test against a running CRM. Skipped unless these are set:
//   CRM_URL, CRM_SYSTEM_KEY (role system), CRM_ADMIN_KEY (role admin), TEST_OUTBOX_MYSQL_URL
// The CRM database should be empty; the test uses ids prefixed with LIVE-.

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { CrmClient, MysqlOutboxStore, OutboxWorker, events } = require('../src');

const { CRM_URL, CRM_SYSTEM_KEY, CRM_ADMIN_KEY, TEST_OUTBOX_MYSQL_URL } = process.env;
let mysql;
try { mysql = require('mysql2/promise'); } catch { mysql = null; }
const ready = CRM_URL && CRM_SYSTEM_KEY && CRM_ADMIN_KEY && TEST_OUTBOX_MYSQL_URL && mysql;

test('storefront -> outbox -> CRM, surviving an outage', { skip: !ready && 'live CRM env not set' }, async () => {
  const pool = mysql.createPool({ uri: TEST_OUTBOX_MYSQL_URL, connectionLimit: 4 });
  const admin = new CrmClient({ baseUrl: CRM_URL, apiKey: CRM_ADMIN_KEY });
  const quiet = { warn() {}, error() {}, info() {} };
  try {
    // Own table, so this can run in parallel with outbox.test.js against the same database.
    const table = 'live_test_crm_outbox';
    await pool.query(`DROP TABLE IF EXISTS ${table}`);
    await pool.query(fs.readFileSync(path.join(__dirname, '..', 'sql', 'outbox.mysql.sql'), 'utf8')
      .replace('storefront_crm_outbox', table));
    await pool.query('CREATE TABLE IF NOT EXISTS live_orders (id VARCHAR(64) PRIMARY KEY, amount DECIMAL(12,2))');
    await pool.query('DELETE FROM live_orders');
    const store = new MysqlOutboxStore(pool, { table });

    // Vendors are onboarded in the CRM by the vendor team, not by the storefront.
    await admin.request('POST', '/vendors', {
      body: { vendor_id: 'LIVE-V1', store_name: 'Live Vendor', contact_email: 'ops@live.example' },
    }).catch((e) => { if (e.status !== 409) throw e; });

    // Storefront checkout: business rows and CRM events commit together.
    const conn = await pool.getConnection();
    await conn.beginTransaction();
    await conn.query('INSERT INTO live_orders VALUES (?, ?)', ['LIVE-O1', '1180.00']);
    await store.enqueue([
      events.productUpserted({ productId: 'LIVE-P1', vendorId: 'LIVE-V1', name: 'Kurta', category: 'apparel', gstRate: '0.18' }),
      events.customerRegistered({ customerId: 'LIVE-C1', name: 'Asha', email: 'asha@live.example', phone: '9876500001' }),
      events.orderPlaced({ orderId: 'LIVE-O1', customerId: 'LIVE-C1', productId: 'LIVE-P1', amount: '1180.00' }),
      events.orderShipped({ orderId: 'LIVE-O1' }),
      events.orderDelivered({ orderId: 'LIVE-O1' }),
    ], conn);
    await conn.commit();
    conn.release();

    // 1. CRM unreachable: nothing is lost, events stay pending.
    const down = new CrmClient({ baseUrl: 'http://127.0.0.1:9', apiKey: CRM_SYSTEM_KEY, maxRetries: 0, timeoutMs: 500 });
    const w1 = new OutboxWorker({ client: down, store, logger: quiet });
    w1.delayFor = () => 0;
    assert.equal(await w1.runOnce(), 0);
    assert.deepEqual(await store.stats(), { pending: 5, sent: 0, dead: 0 });

    // 2. CRM back: everything is delivered in order.
    const up = new CrmClient({ baseUrl: CRM_URL, apiKey: CRM_SYSTEM_KEY });
    const w2 = new OutboxWorker({ client: up, store, logger: quiet });
    assert.equal(await w2.runOnce(), 5);
    assert.deepEqual(await store.stats(), { pending: 0, sent: 5, dead: 0 });

    // 3. The same events emitted again (e.g. a storefront bug or replayed job) are not re-applied.
    await pool.query(`UPDATE ${table} SET status = 'pending'`);
    assert.equal(await w2.runOnce(), 5);
    const ledger = await admin.request('GET', '/vendors/LIVE-V1/ledger');
    assert.equal(ledger.gross_sales, '1180.00');
    assert.equal(ledger.tcs_withheld, '5.00'); // 0.5% of 1180 / 1.18
    const profile = await admin.customerProfile('LIVE-C1');
    assert.equal(profile.loyalty_points, 11);
    const audit = await admin.request('GET', '/audit', { query: { limit: 20 } });
    assert.ok(audit.some((e) => e.path === '/events/order.placed' && e.outcome === 'replayed'));
  } finally {
    await pool.end();
  }
});
