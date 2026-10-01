'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { CrmClient, CrmError, events } = require('../src');

/** A fake fetch that replays scripted responses and records every call. */
function fakeFetch(script) {
  const calls = [];
  const fn = async (url, init) => {
    calls.push({ url: String(url), ...init, headers: { ...init.headers } });
    const step = script[Math.min(calls.length - 1, script.length - 1)];
    if (step instanceof Error) throw step;
    const { status = 200, body, headers = {} } = step;
    return new Response(body === undefined ? null : JSON.stringify(body), { status, headers });
  };
  fn.calls = calls;
  return fn;
}

const client = (fetch, opts = {}) =>
  new CrmClient({ baseUrl: 'https://crm.example/', apiKey: 'k', fetch, retryBaseMs: 1, ...opts });

test('sends auth and an idempotency key, reusing it across retries', async () => {
  const fetch = fakeFetch([
    new TypeError('socket hang up'),
    { status: 503, body: { detail: 'busy' }, headers: { 'retry-after': '0' } },
    { status: 201, body: { refund_id: 'RF-1' } },
  ]);
  const res = await client(fetch).requestRefund('O-1', { amount: '10.00', reason: 'late' });
  assert.deepEqual(res, { refund_id: 'RF-1' });
  assert.equal(fetch.calls.length, 3);
  const keys = new Set(fetch.calls.map((c) => c.headers['Idempotency-Key']));
  assert.equal(keys.size, 1, 'same key on every retry');
  assert.equal(fetch.calls[0].headers.Authorization, 'Bearer k');
  assert.equal(fetch.calls[0].url, 'https://crm.example/orders/O-1/refunds');
});

test('does not retry client errors', async () => {
  const fetch = fakeFetch([{ status: 409, body: { detail: 'exceeds refundable' } }]);
  await assert.rejects(client(fetch).requestRefund('O-1', { amount: 5, reason: 'x' }), (err) => {
    assert.ok(err instanceof CrmError);
    assert.equal(err.status, 409);
    assert.equal(err.retryable, false);
    assert.match(err.message, /exceeds refundable/);
    return true;
  });
  assert.equal(fetch.calls.length, 1);
});

test('gives up after maxRetries with a retryable error', async () => {
  const fetch = fakeFetch([{ status: 502, body: 'bad gateway' }]);
  await assert.rejects(client(fetch, { maxRetries: 2 }).health(), (err) => err.retryable && err.status === 502);
  assert.equal(fetch.calls.length, 3);
});

test('times out slow requests and retries them', async () => {
  let n = 0;
  const fetch = async (url, init) => {
    n += 1;
    if (n === 1) {
      // A request that never answers. The keep-alive timer stands in for the open socket a real
      // fetch would hold (AbortSignal.timeout alone does not keep Node's event loop running).
      return new Promise((_, reject) => {
        const keepAlive = setTimeout(() => {}, 10000);
        init.signal.addEventListener('abort', () => { clearTimeout(keepAlive); reject(init.signal.reason); });
      });
    }
    return new Response(JSON.stringify({ status: 'ok' }), { status: 200 });
  };
  const res = await client(fetch, { timeoutMs: 20 }).health();
  assert.deepEqual(res, { status: 'ok' });
  assert.equal(n, 2);
});

test('sendEvents chunks by 100 and stops after a retryable failure', async () => {
  const many = Array.from({ length: 250 }, (_, i) => events.orderDelivered({ orderId: `O-${i}` }));
  const ok = (evs) => evs.map((e) => ({ id: e.id, status: 'ok' }));
  const fetch = async (url, init) => {
    const { events: chunk } = JSON.parse(init.body);
    fetch.sizes.push(chunk.length);
    const results = ok(chunk);
    if (fetch.sizes.length === 2) {
      results[10] = { id: chunk[10].id, status: 'error', http_status: 503, retryable: true };
      for (let i = 11; i < results.length; i += 1) results[i] = { id: chunk[i].id, status: 'not_processed', retryable: true };
    }
    return new Response(JSON.stringify({ results }), { status: 200 });
  };
  fetch.sizes = [];
  const results = await client(fetch).sendEvents(many);
  assert.deepEqual(fetch.sizes, [100, 100]);
  assert.equal(results.length, 250);
  assert.equal(results.filter((r) => r.status === 'ok').length, 110);
  assert.equal(results[249].status, 'not_processed');
});

test('event builders validate input and produce stable ids', () => {
  const placed = events.orderPlaced({ orderId: 'O-1', customerId: 'C-1', productId: 'P-1', amount: 1499.5 });
  assert.equal(placed.id, 'order.placed:O-1');
  assert.equal(placed.data.amount, '1499.50');
  assert.throws(() => events.orderPlaced({ orderId: 'O-1', customerId: 'C-1', productId: 'P-1', amount: '12.345' }));
  assert.throws(() => events.orderPlaced({ orderId: 'O-1', customerId: 'C-1', productId: 'P-1', amount: -1 }));
  assert.throws(() => events.customerRegistered({ customerId: 'C-1' }), /name is required/);

  const p1 = events.productUpserted({ productId: 'P-1', vendorId: 'V-1', name: 'Kurta', category: 'apparel', gstRate: '0.05' });
  const p1again = events.productUpserted({ productId: 'P-1', vendorId: 'V-1', name: 'Kurta', category: 'apparel', gstRate: '0.05' });
  const p1changed = events.productUpserted({ productId: 'P-1', vendorId: 'V-1', name: 'Kurta', category: 'apparel', gstRate: '0.18' });
  assert.equal(p1.id, p1again.id);
  assert.notEqual(p1.id, p1changed.id);
  assert.notEqual(events.customerBrowsed({ customerId: 'C-1', category: 'x' }).id,
    events.customerBrowsed({ customerId: 'C-1', category: 'x' }).id);
});
