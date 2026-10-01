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

**Authentication.** Every endpoint except `/health` needs `Authorization: Bearer <key>`. `SABAR_CRM_API_KEYS` maps each key to a role, so callers can never pick their own role. Each role's permissions apply exactly as listed in the RBAC table below, and the `system` role is for the storefront to push events. Use long random keys in production and serve the API over HTTPS.

```bash
curl -H "Authorization: Bearer sk-admin-change-me" localhost:8000/analytics
```

| Area | Endpoints |
|---|---|
| Meta | `GET /health`, `GET /me` |
| Customers | `POST /customers`, `GET /customers/{id}`, `GET /customers/{id}/recommendations`, `GET /customers/abandoned-carts`, `POST /customers/{id}/browse`, `PUT` and `DELETE /customers/{id}/cart/{product_id}` |
| Orders | `POST /products`, `POST /orders`, `POST /orders/{id}/ship`, `/deliver`, `/return`, `/cancel` |
| Support | `POST /tickets`, `GET /tickets`, `POST /orders/{id}/refunds` |
| Vendors | `POST /vendors`, `GET /vendors/{id}`, `PUT /vendors/{id}/documents/{doc}`, `POST /vendors/{id}/documents/{doc}/review`, `PUT /vendors/{id}/milestones/{m}`, `POST /vendors/{id}/reviews` |
| Finance | `GET /vendors/payouts`, `GET /vendors/{id}/ledger`, `POST /vendors/{id}/payouts`, `GET /affiliates/payouts`, `GET /affiliates/{id}/commission` |
| Affiliates | `POST /affiliates`, `GET /affiliates/{id}`, `POST /affiliates/{id}/approve`, `POST /referrals/clicks`, `POST /affiliate-assets`, `POST /affiliates/{id}/assets/{asset_id}` |
| Internal | `GET /tasks?team=…`, `POST /tasks/{id}/resolve`, `GET /analytics` |

Status codes: `401` for a missing or unknown key, `403` when the role lacks permission (checked before existence, so IDs cannot be probed), `404` for an unknown ID, `409` for a duplicate ID or a broken business rule (such as a return after the window or delivering before shipping), and `422` for an invalid body.

The server decides some values itself instead of trusting the client. It sets every timestamp. It takes an order's vendor and category from the product catalog. It works out an affiliate's tier from their completed orders before handing out tier-restricted assets.

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
| `escalation.py` | De-duplicated internal tasks, routed to team queues and sorted by priority |

### Order lifecycle fan-out

| Event | Effects |
|---|---|
| `place_order` | Customer history, product popularity, affiliate attribution |
| `ship_order` | Late-shipment tracking, then a vendor scorecard re-check |
| `deliver_order` | Vendor ledger posts the sale and the commission, the customer earns loyalty points (milestone triggers can fire), vendor scorecard re-check |
| `return_order` | Only allowed inside the return window. Reverses the sale and the commission, revokes the loyalty points and voids the affiliate commission |
| `cancel_order` | Lowers the vendor's fulfillment rate |

## Escalation rules

| Rule | Trigger | Owner team | Priority |
|---|---|---|---|
| `vendor_low_review_score` | Average review below 3.5 | vendor_success | high |
| `vendor_low_fulfillment` | Fulfillment rate below 95% | vendor_success | high |
| `vendor_shipping_delays` | Late shipments above 10% | vendor_success | medium |
| `vendor_high_returns` | Return rate above 15% | vendor_success | medium |
| `refund_exceeds_limit` | Refund above 10,000 or above the order value | finance | high |
| `critical_support_ticket` | Fraud, hacked or legal keywords | trust_and_safety / customer_support | critical |
| `affiliate_self_referral` | Affiliate email matches the buyer's email | affiliate_ops | medium |

A rule that fires again for the same subject updates the task that is already open. It does not create a duplicate.

## Role-based access control

| Role | Can see |
|---|---|
| `system` | Nothing to read. Can push customers, products, orders, browsing, carts, clicks, reviews and tickets |
| `support_agent` | Customer profiles with full PII, tickets, the customer_support and trust_and_safety queues |
| `finance` | Vendor ledgers and payouts, affiliate payouts, vendor profiles, the finance queue |
| `vendor_manager` | Vendor scorecards and onboarding, the vendor_success queue |
| `affiliate_manager` | Affiliates and asset distribution, the affiliate_ops queue |
| `marketing` | Customer profiles with **masked PII**, recommendations |
| `admin` | Everything, including global analytics |

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
