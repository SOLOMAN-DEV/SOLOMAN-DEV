'use strict';

/**
 * Example: wiring the Sabar Mart CRM into an Express + MySQL storefront.
 *
 * The pattern everywhere is the same: write your own rows AND the CRM events in ONE
 * transaction, then let the OutboxWorker deliver the events. Checkout never waits on, or
 * fails because of, the CRM.
 *
 * Environment:
 *   DATABASE_URL     mysql://user:pass@localhost/storefront   (the storefront's own database)
 *   CRM_URL          https://crm.sabarmart.com
 *   CRM_API_KEY      the storefront's key (role "system", e.g. SABAR_CRM_API_KEYS="<key>:system:storefront")
 */

const express = require('express');
const mysql = require('mysql2/promise');
const { CrmClient, MysqlOutboxStore, OutboxWorker, events } = require('@sabarmart/crm-client');

const pool = mysql.createPool({ uri: process.env.DATABASE_URL, connectionLimit: 10 });
const crm = new CrmClient({ baseUrl: process.env.CRM_URL, apiKey: process.env.CRM_API_KEY });
const outbox = new MysqlOutboxStore(pool);

// One sender is active at a time even if you run several Node processes (PM2 cluster, etc.).
const worker = new OutboxWorker({
  client: crm,
  store: outbox,
  onDeadLetter: ({ id, error }) => console.error(`CRM rejected event ${id}: ${error}`), // e.g. alert ops
}).start();

/** Run `fn(conn)` in a transaction; `fn` may call outbox.enqueue(events, conn). */
async function inTransaction(fn) {
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();
    const result = await fn(conn);
    await conn.commit();
    return result;
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

const app = express();
app.use(express.json());

app.post('/signup', async (req, res) => {
  const { id, name, email, phone } = req.body;
  await inTransaction(async (conn) => {
    await conn.query('INSERT INTO customers (id, name, email, phone) VALUES (?, ?, ?, ?)', [id, name, email, phone]);
    await outbox.enqueue([events.customerRegistered({ customerId: id, name, email, phone })], conn);
  });
  res.status(201).json({ id });
});

// Catalogue changes (new product, price or GST rate change). gstRate is the GST included in the price.
app.put('/admin/products/:id', async (req, res) => {
  const { vendorId, name, category, gstRate } = req.body;
  await inTransaction(async (conn) => {
    await conn.query(
      'REPLACE INTO products (id, vendor_id, name, category, gst_rate) VALUES (?, ?, ?, ?, ?)',
      [req.params.id, vendorId, name, category, gstRate]);
    await outbox.enqueue([events.productUpserted({ productId: req.params.id, vendorId, name, category, gstRate })], conn);
  });
  res.sendStatus(204);
});

// Activity tracking can be fire-and-forget: enqueue without a business transaction.
app.post('/track/browse', async (req, res) => {
  await outbox.enqueue([events.customerBrowsed({ customerId: req.body.customerId, category: req.body.category })]);
  res.sendStatus(202);
});

app.post('/cart/items', async (req, res) => {
  const { customerId, productId, price } = req.body;
  await outbox.enqueue([events.cartItemAdded({ customerId, productId, price })]);
  res.sendStatus(202);
});

// Affiliate landing: ?ref=CODE on a product page.
app.get('/p/:productId', async (req, res) => {
  if (req.query.ref) {
    await outbox.enqueue([events.referralClicked({
      referralCode: String(req.query.ref),
      productId: req.params.productId,
      visitorFingerprint: req.get('x-visitor-id') || req.ip, // use your own stable visitor id
    })]);
  }
  res.send('product page');
});

// Checkout: one CRM order per order line (the CRM tracks vendor and GST per product).
app.post('/checkout', async (req, res) => {
  const { customerId, lines, referralCode } = req.body; // lines: [{ lineId, productId, amount }]
  await inTransaction(async (conn) => {
    for (const line of lines) {
      await conn.query('INSERT INTO order_lines (id, customer_id, product_id, amount) VALUES (?, ?, ?, ?)',
        [line.lineId, customerId, line.productId, line.amount]);
    }
    await outbox.enqueue(lines.map((line) => events.orderPlaced({
      orderId: line.lineId, customerId, productId: line.productId, amount: String(line.amount), referralCode,
    })), conn);
  });
  res.status(201).json({ ok: true });
});

// Fulfilment updates from your warehouse / courier webhooks.
app.post('/order-lines/:id/status', async (req, res) => {
  const { status, late } = req.body; // shipped | delivered | returned | cancelled
  const build = {
    shipped: () => events.orderShipped({ orderId: req.params.id, late }),
    delivered: () => events.orderDelivered({ orderId: req.params.id }),
    returned: () => events.orderReturned({ orderId: req.params.id }),
    cancelled: () => events.orderCancelled({ orderId: req.params.id }),
  }[status];
  if (!build) return res.status(400).json({ error: 'unknown status' });
  await inTransaction(async (conn) => {
    await conn.query('UPDATE order_lines SET status = ? WHERE id = ?', [status, req.params.id]);
    await outbox.enqueue([build()], conn);
  });
  res.sendStatus(204);
});

app.post('/reviews', async (req, res) => {
  const { reviewId, vendorId, score } = req.body;
  await inTransaction(async (conn) => {
    await conn.query('INSERT INTO reviews (id, vendor_id, score) VALUES (?, ?, ?)', [reviewId, vendorId, score]);
    await outbox.enqueue([events.vendorReviewed({ reviewId, vendorId, score })], conn);
  });
  res.sendStatus(201);
});

app.post('/support', async (req, res) => {
  const { ticketId, customerId, subject, body, orderId } = req.body;
  await outbox.enqueue([events.ticketCreated({ ticketId, customerId, subject, body, orderId })]);
  res.sendStatus(202);
});

// Health page for your ops: how far behind the CRM sync is.
app.get('/internal/crm-sync', async (req, res) => res.json(await outbox.stats()));

// Housekeeping: drop delivered events after 7 days.
setInterval(() => outbox.purgeSent(7 * 24 * 3600 * 1000).catch(console.error), 3600 * 1000).unref();

const server = app.listen(process.env.PORT || 3000);
process.on('SIGTERM', () => { worker.stop(); server.close(() => pool.end()); });
