# Sabar Mart CRM Engine

The central CRM for the sabarmart.com multi-vendor marketplace. It is the single source of truth for four areas: **Customers**, **Vendors**, **Affiliates** and **Internal Teams**.

It uses only the Python standard library and needs Python 3.10 or later. All money is handled as `Decimal`.

```bash
cd sabar-mart-crm
python -m sabar_mart_crm                 # seeded demo, prints every role's report as JSON
python -m sabar_mart_crm admin tasks     # print selected reports only (admin|support|marketing|finance|vendor|tasks)
python -m unittest discover -s tests -v
```

## Architecture

| Module | Responsibility |
|---|---|
| `config.py` | Every policy threshold: refund limit, 3.5★ review floor, return window, affiliate tiers and more |
| `store.py` | The in-memory store that every pillar shares. Replace it with a DB-backed class that has the same attributes |
| `engine.py` | `CRMEngine` handles order lifecycle events across pillars and serves the role-gated views |
| `customers.py` | Lifecycle stages, cart abandonment, loyalty points and milestones, ticket routing, refund checks, recommendations |
| `vendors.py` | Document verification, store setup milestones, scorecards, ledger (sale, commission, refund, payout) |
| `affiliates.py` | Click validation, last-click attribution, tiered commission after the return window, asset distribution gated by tier |
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
