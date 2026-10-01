# Sabar Mart CRM Engine

The central CRM for the sabarmart.com multi-vendor marketplace. It is the single source of truth for four areas: **Customers**, **Vendors**, **Affiliates** and **Internal Teams**.

The core engine uses only the Python standard library and needs Python 3.10 or later. All money is handled as `Decimal`. The optional REST API uses FastAPI, and optional database storage (MySQL/MariaDB, PostgreSQL or SQLite) uses SQLAlchemy.

**Hosting on MilesWeb:** see [DEPLOY_MILESWEB.md](DEPLOY_MILESWEB.md) for cPanel shared hosting (Passenger + MySQL) and for VPS setups.

```bash
cd sabar-mart-crm
python -m sabar_mart_crm                 # seeded demo, prints every role's report as JSON
python -m sabar_mart_crm admin tasks     # print selected reports only (admin|support|marketing|finance|vendor|tasks)
pip install -e ".[dev]"                  # only needed for the REST API, database and their tests
python -m unittest discover -s tests -v
# Also run the database suites against MySQL/MariaDB (use an empty scratch database):
TEST_MYSQL_URL="mysql+pymysql://user:pass@localhost/crm_test?charset=utf8mb4" python -m unittest discover -s tests
```

## REST API

```bash
pip install -e ".[api]"
SABAR_CRM_API_KEYS="sk-admin-change-me:admin,sk-store-change-me:system" \
SABAR_CRM_SEED_DEMO=1 \
uvicorn sabar_mart_crm.api:app --reload
```

Interactive docs are served at `http://localhost:8000/docs`. `SABAR_CRM_SEED_DEMO=1` loads the sample marketplace. Leave it out to start empty.

**Authentication.** Every endpoint except `/health` needs `Authorization: Bearer <key>`. The key decides who the caller is and which role they have, so callers can never pick their own role. There are two kinds of key:

- **Personal keys (recommended for people).** Create them with `POST /users` (admin only) or with `python -m sabar_mart_crm.db user-add priya finance`. The key is shown once. Only its SHA-256 hash is stored, so a lost key can only be replaced (`rotate-key`), never recovered. `deactivate` revokes a key immediately.
- **Environment keys,** for bootstrapping and for service accounts such as the storefront. Set them as `SABAR_CRM_API_KEYS="key:role[:name],..."`, for example `…:system:storefront`.

**Audit log.** `GET /audit` (admin only, filter with `?actor=`) records who did what and when, by person. It covers every change, every refused request (403), and every read of sensitive data: customer profiles, ledgers, payout reports, the tax report and the audit log itself. Request bodies are never logged.

Serve the API over HTTPS only.

```bash
curl -H "Authorization: Bearer sk-admin-change-me" localhost:8000/analytics
```

| Area | Endpoints |
|---|---|
| Meta | `GET /health`, `GET /me` |
| Customers | `POST /customers`, `GET /customers/{id}`, `GET /customers/{id}/recommendations`, `GET /customers/abandoned-carts`, `POST /customers/{id}/browse`, `PUT` and `DELETE /customers/{id}/cart/{product_id}` |
| Storefront | `POST /events`: batches of up to 100 events, each applied at most once, in order (see below) |
| Orders | `POST /products`, `PUT /products/{id}` (create or update), `POST /orders`, `POST /orders/{id}/ship`, `/deliver`, `/return`, `/cancel` |
| Support | `POST /tickets`, `GET /tickets` |
| Refunds | `POST /orders/{id}/refunds`, `GET /refunds?status=&order_id=`, `POST /refunds/{id}/approve`, `POST /refunds/{id}/reject` |
| Vendors | `POST /vendors`, `GET /vendors/{id}`, `PUT /vendors/{id}/documents/{doc}`, `POST /vendors/{id}/documents/{doc}/review`, `PUT /vendors/{id}/milestones/{m}`, `POST /vendors/{id}/reviews` |
| Finance | `GET /vendors/payouts`, `GET /vendors/{id}/ledger`, `POST /vendors/{id}/payouts`, `GET /affiliates/payouts`, `GET /affiliates/{id}/commission`, `GET /affiliates/{id}/payout-preview`, `POST` and `GET /affiliates/{id}/payouts`, `GET /finance/tax-report?start=&end=` |
| Affiliates | `POST /affiliates`, `GET /affiliates/{id}`, `POST /affiliates/{id}/approve`, `POST /referrals/clicks`, `POST /affiliate-assets`, `POST /affiliates/{id}/assets/{asset_id}` |
| Internal | `GET /tasks?team=…`, `POST /tasks/{id}/resolve`, `GET /analytics` |
| Privacy (DPDP) | `POST /customers/{id}/consent`, `GET /customers/{id}/export`, `POST /customers/{id}/erase`, `GET /affiliates/{id}/export`, `POST /affiliates/{id}/erase` |
| Admin | `POST /users`, `GET /users`, `POST /users/{name}/deactivate`, `POST /users/{name}/rotate-key`, `GET /audit` |

Status codes: `401` for a missing or unknown key, `403` when the role lacks permission (checked before existence, so IDs cannot be probed), `404` for an unknown ID, `409` for a duplicate ID or a broken business rule (such as a return after the window or delivering before shipping), and `422` for an invalid body.

**Safe retries.** Send an `Idempotency-Key` header with any change request, such as a UUID or `order.shipped:O-1`. If a request times out and the client sends it again with the same key, the CRM returns the original response with an `Idempotent-Replayed: true` header and does not apply the change a second time.
- The key and the change are saved in the same transaction, so a key is only remembered once its change is committed.
- Keys belong to the caller who sent them. Reusing a key for a different request returns `422`.
- Keys are remembered for 72 hours. Run `python -m sabar_mart_crm.db purge` daily to remove older ones.

The server decides some values itself instead of trusting the client. It sets every timestamp. It takes an order's vendor and category from the product catalog. It works out an affiliate's tier from their completed orders before handing out tier-restricted assets.

### Connecting the storefront (Node.js)

[`clients/node`](clients/node) is a ready-made package for the sabarmart.com Node.js backend. It has three parts:
- **Event builders:** create each event with a stable ID.
- **Outbox:** the store writes each event in the same database transaction as the order or signup.
- **Background worker:** delivers the events to `POST /events`, in order and with retries.

Checkout never waits on the CRM, and an outage loses nothing. See its [README](clients/node/README.md) and the [Express example](clients/node/examples/express-integration.js).

`POST /events` takes `{"events": [{"id", "type", "data"}]}`. Each event is applied in its own transaction, and its `id` doubles as its idempotency key. Each result has a `status`:
- `ok`: applied.
- `replayed`: already applied earlier; nothing changed.
- `error`: rejected. `retryable` says whether sending it again can help.
- `not_processed`: skipped after a retryable error, so later events can't overtake earlier ones.

Event types: `customer.registered`, `customer.browsed`, `cart.item_added`, `cart.item_removed`, `product.upserted`, `order.placed`, `order.shipped`, `order.delivered`, `order.returned`, `order.cancelled`, `referral.clicked`, `vendor.reviewed`, `ticket.created`.

### Storage

| `DATABASE_URL` | Storage |
|---|---|
| not set | In memory. Data is lost on restart. Good for trying it out |
| `mysql+pymysql://user:pass@localhost/db?charset=utf8mb4` | MySQL / MariaDB, as provided by MilesWeb cPanel |
| `postgresql+psycopg://user:pass@host/db` | PostgreSQL (`pip install psycopg`) |
| `sqlite:////absolute/path/crm.sqlite3` | A single SQLite file |

With a database, tables are created automatically at startup. Turn that off with `SABAR_CRM_AUTO_MIGRATE=0` and run `python -m sabar_mart_crm.db init` instead. Use `python -m sabar_mart_crm.db seed` to load the demo data and `python -m sabar_mart_crm.db check` to print row counts.

Every request runs in one transaction under a database-wide lock, and it commits before the response is sent. Multiple worker processes are therefore safe: no lost updates, and IDs stay unique. The trade-off is that requests run one at a time. See [DEPLOY_MILESWEB.md](DEPLOY_MILESWEB.md#how-the-storage-works-and-its-limits).

Other environment variables: `SABAR_CRM_API_KEYS` (required), `SABAR_CRM_DOCS=0` (hide `/docs` in production), and `SABAR_CRM_SEED_DEMO=1` (load demo data into an empty store).

## Architecture

| Module | Responsibility |
|---|---|
| `config.py` | Every policy threshold: refund limit, 3.5★ review floor, return window, affiliate tiers and more |
| `store.py` | The in-memory store that every pillar shares, and the collection registry |
| `db.py` | SQL persistence: per-request unit of work, cross-process locking, `init`/`seed`/`check` CLI |
| `engine.py` | `CRMEngine` handles order lifecycle events across pillars and serves the role-gated views |
| `customers.py` | Lifecycle stages, cart abandonment, loyalty points and milestones, ticket routing, refund checks, recommendations |
| `vendors.py` | Document verification, store setup milestones, scorecards, ledger (sale, commission, refund, payout) |
| `affiliates.py` | Click validation, last-click attribution, tiered commission after the return window, asset distribution gated by tier |
| `api.py` | FastAPI REST layer: API-key auth, request validation, role checks on every endpoint, one transaction per request |
| `passenger_wsgi.py` | Passenger (cPanel) entry point that wraps the ASGI app as WSGI |
| `rbac.py` | Roles, permissions, team queue scoping, PII masking |
| `privacy.py` | DPDP consent, data export and anonymising erasure |
| `escalation.py` | De-duplicated internal tasks, routed to team queues and sorted by priority |

### Order lifecycle fan-out

| Event | Effects |
|---|---|
| `place_order` | Customer history, product popularity, affiliate attribution |
| `ship_order` | Late-shipment tracking, then a vendor scorecard re-check |
| `deliver_order` | Vendor ledger posts the sale and the commission, the customer earns loyalty points (milestone triggers can fire), vendor scorecard re-check |
| `return_order` | Only allowed inside the return window. Reverses the sale and the commission, revokes the loyalty points and voids the affiliate commission |
| `cancel_order` | Lowers the vendor's fulfillment rate |

## Money rules

**Vendor ledger.** Each delivered order posts five entries at once. Withheld amounts are recorded as negative entries.

| Entry | Amount (default rates) |
|---|---|
| Sale | + order amount |
| Commission | − 10% of the order (each vendor can have their own rate) |
| GST on commission | − 18% of the commission |
| TCS | − 0.5% of the taxable value (CGST s.52) |
| TDS | − 0.1% of the taxable value (s.194-O), or 5% if the vendor's PAN card is not verified |

Prices include GST, so the **taxable value** is price ÷ (1 + the product's GST rate). For example, ₹1,180 at 18% has a taxable value of ₹1,000. Every product must be sent with its `gst_rate` (e.g. `0.05`, `0.18`, `0.40`), and each order keeps the rate it was sold at. Commission is charged on the GST-inclusive price.

Vendors are paid the balance, minus orders still inside the return window. `GET /finance/tax-report` adds up, per vendor and for any period: sales including GST, the taxable value, TCS, TDS and GST on commission, for filing.

> ⚠️ **Have your Chartered Accountant confirm the rates and their base** (`config.py`) before go-live. The defaults follow Budget 2024. Threshold exemptions are not modelled.

**Refunds.** Only delivered orders can be refunded. An undelivered order is cancelled instead.
- Refunds up to the standard limit (10,000) are applied straight away. Larger ones wait for finance approval, and the person who requested a refund can never approve it.
- Pending refunds count against what is still refundable.
- An approved refund reverses each ledger entry in proportion, and the reversals add up exactly even across several partial refunds. It also takes back the loyalty points earned on the refunded amount.
- A return refunds whatever has not been refunded yet and cancels any refunds still pending.

**Affiliate payouts.** Commission is paid only after the return window closes, at the affiliate's tier. The tier is based on their completed orders to date.
- Each order's rate is fixed the first time it is paid.
- A payout run records exactly what it paid, so nothing is paid twice.
- If a paid order is refunded later, the next payout deducts the difference (a clawback). When the clawback is larger than the new commission, the remainder carries forward to the next run.

## Privacy (DPDP Act 2023)

| Right | How |
|---|---|
| **Consent** | `marketing_consent` is set at signup (opt-in only) and changed with `POST /customers/{id}/consent` or a `customer.consent_updated` event. Each change records when it happened and where it came from. Without consent, a customer gets no cart-recovery campaign and no personalised recommendations. |
| **Access** | `GET /customers/{id}/export` (support, admin) returns everything held: profile, consent, loyalty, browsing, cart, orders, refunds and tickets. The same exists for affiliates. |
| **Erasure** | `POST /customers/{id}/erase` (admin only) anonymises the person: name, email, phone, activity, ticket text, refund reasons, and for affiliates their personal referral code. Orders, ledger entries, refunds and payouts are **kept without personal data**, because tax law requires them. Afterwards, any new event for that customer is rejected with `409`. |
| **Erasure is refused** | while an obligation is still running: orders in transit or refunds awaiting a decision, and for affiliates, commission still owed. Finish those, then erase. |

Every export and erasure is in the audit log under the name of the person who did it.

Things you still need to do outside the CRM:
- **Grievance officer:** publish one and a privacy notice.
- **Requests:** answer data requests within your stated timelines.
- **Backups:** delete them on the retention schedule, because erased data can still be in older backups.

## Escalation rules

| Rule | Trigger | Owner team | Priority |
|---|---|---|---|
| `vendor_low_review_score` | Average review below 3.5 | vendor_success | high |
| `vendor_low_fulfillment` | Fulfillment rate below 95% | vendor_success | high |
| `vendor_shipping_delays` | Late shipments above 10% | vendor_success | medium |
| `vendor_high_returns` | Return rate above 15% | vendor_success | medium |
| `refund_exceeds_limit` | Refund above 10,000 (needs approval; amounts above what is refundable are rejected outright) | finance | high |
| `critical_support_ticket` | Fraud, hacked or legal keywords | trust_and_safety / customer_support | critical |
| `affiliate_self_referral` | Affiliate email matches the buyer's email | affiliate_ops | medium |

A rule that fires again for the same subject updates the task that is already open. It does not create a duplicate.

## Role-based access control

| Role | Can see |
|---|---|
| `system` | Nothing to read. Can push customers, products, orders, browsing, carts, clicks, reviews and tickets |
| `support_agent` | Customer profiles with full PII, tickets, refund requests, consent changes, data exports, the customer_support and trust_and_safety queues |
| `finance` | Vendor ledgers and payouts, affiliate payouts, the tax report, refund approval, vendor profiles, the finance queue |
| `vendor_manager` | Vendor scorecards and onboarding, the vendor_success queue |
| `affiliate_manager` | Affiliates and asset distribution, the affiliate_ops queue |
| `marketing` | Customer profiles with **masked PII**, recommendations |
| `admin` | Everything, including global analytics, user management and the audit log |

Any call outside a role's permissions raises `AccessDenied`.

## Example alert (JSON)

```json
{
  "task_id": "TSK-00003",
  "rule": "vendor_low_review_score",
  "title": "Vendor QuickGadgets: avg review 2.75 < 3.5",
  "owner_team": "vendor_success",
  "priority": "high",
  "subject_type": "vendor",
  "subject_id": "V-200",
  "status": "open"
}
```
