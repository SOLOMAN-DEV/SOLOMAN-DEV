# @sabarmart/crm-client

This package connects the Sabar Mart storefront (Node.js 18+) to the Sabar Mart CRM API. It has **no runtime dependencies**. To use the MySQL outbox, also install `mysql2`.

## How it works

```
checkout handler ──(one DB transaction)──► orders table + storefront_crm_outbox
                                                         │
                         OutboxWorker (background) ──────┘──► POST /events on the CRM
```

1. **Your store records the event with its own change.** It writes the event into its own database, in the same transaction as the business change (the order row, the signup and so on). If the transaction rolls back, no event is left behind.
2. **A background worker delivers the events.** `OutboxWorker` sends them in order and retries with back-off. If the CRM is down or slow, **checkout is unaffected** and the events wait in the table.
3. **Nothing is applied twice.** Every event has a stable `id`, which the CRM uses as its idempotency key. Delivering the same event twice changes nothing; the CRM answers `replayed`.
4. **Bad events don't block the queue.** If the CRM rejects an event permanently (for example a 422 validation error, or a 404 for an unknown vendor), it's marked `dead` and stops blocking later events. If the whole request fails, for example because the API key is wrong, nothing is discarded; the worker backs off and logs an error.

## Setup

```bash
npm install ./clients/node mysql2        # or copy the folder into your repo
mysql storefront_db < node_modules/@sabarmart/crm-client/sql/outbox.mysql.sql
```

Ask a CRM admin for a key with the `system` role. In the CRM, set it as `SABAR_CRM_API_KEYS="<long-random-key>:system:storefront"`.

```js
const { CrmClient, MysqlOutboxStore, OutboxWorker, events } = require('@sabarmart/crm-client');

const crm = new CrmClient({ baseUrl: process.env.CRM_URL, apiKey: process.env.CRM_API_KEY });
const outbox = new MysqlOutboxStore(pool);                  // your mysql2/promise pool
new OutboxWorker({ client: crm, store: outbox }).start();   // safe to run in every process

// inside your checkout transaction (conn = the transaction's connection):
await outbox.enqueue([events.orderPlaced({ orderId, customerId, productId, amount: '1180.00' })], conn);
```

See [`examples/express-integration.js`](examples/express-integration.js) for every hook point: signup, catalogue, browsing, cart, referral links, checkout, fulfilment, reviews and support.

## Events

| Builder | When to emit | Event id |
|---|---|---|
| `customerRegistered` | account created | `customer.registered:<customerId>` |
| `productUpserted` | product created or changed (name, category, **gstRate**) | derived from the content |
| `customerBrowsed`, `cartItemAdded`, `cartItemRemoved` | storefront activity | random |
| `referralClicked` | product visited via `?ref=CODE` | random |
| `orderPlaced` | per order line, **amount incl. GST** | `order.placed:<orderId>` |
| `orderShipped` / `orderDelivered` / `orderReturned` / `orderCancelled` | fulfilment updates | `order.<state>:<orderId>` |
| `vendorReviewed` | customer rated a vendor | `vendor.reviewed:<reviewId>` |
| `ticketCreated` | support request | `ticket.created:<ticketId>` |

Rules:
- **Money:** send amounts as strings with up to 2 decimals, such as `"1180.00"`. A number is accepted and converted with `toFixed(2)`.
- **GST rate:** `gstRate` is the GST already included in the price, as a fraction, such as `"0.18"`.
- **Order of setup:** vendors must exist in the CRM before their products arrive. Vendors are onboarded by the vendor team in the CRM.
- **One order per line:** a checkout with several lines becomes one CRM order per line, because the vendor and GST rate are per product.

## Direct calls

For things a person does, such as a support agent requesting a refund, call the API directly. Each write gets an idempotency key that is reused on retries:

```js
await crm.requestRefund('O-1', { amount: '250.00', reason: 'damaged' }, `refund:${ticketId}`);
await crm.request('GET', '/customers/C-1');
```

`CrmError` has `status`, `detail` and `retryable` fields.

## Operations

- `await outbox.stats()` → `{ pending, sent, dead }`. Alert if `pending` keeps growing or `dead > 0`.
- To inspect dead events: `SELECT * FROM storefront_crm_outbox WHERE status = 'dead'`. Fix the cause, then `UPDATE … SET status = 'pending', attempts = 0` to resend them.
- To clean up delivered events: `await outbox.purgeSent(7 * 24 * 3600 * 1000)`.

## Tests

```bash
npm test                                             # unit tests
TEST_OUTBOX_MYSQL_URL=mysql://u:p@localhost/scratch npm test   # + MySQL store (needs mysql2)
# + end-to-end against a running CRM (empty database):
CRM_URL=http://127.0.0.1:8000 CRM_SYSTEM_KEY=... CRM_ADMIN_KEY=... TEST_OUTBOX_MYSQL_URL=... npm test
```
