"""Data-principal rights under India's Digital Personal Data Protection Act, 2023.

- Access: ``export_customer`` / ``export_affiliate`` return everything held about the person.
- Erasure: ``erase_customer`` / ``erase_affiliate`` anonymise the person. Orders, ledger
  entries, refunds and payouts are kept, because GST and income-tax law require those records
  to be retained, but nothing in them identifies the person any more.
- Consent: marketing consent is recorded with time and source; campaigns and personalised
  recommendations only target customers who opted in.

Erasure is refused while it would break an obligation still in progress (orders in transit,
refunds awaiting a decision, commission still owed). Finish those first, then erase.
"""

from __future__ import annotations

import hashlib
from datetime import datetime
from typing import Any

from .models import OrderStatus, RefundStatus, money, to_jsonable
from .store import Store

ERASED = "[erased]"


def _token(kind: str, ident: str) -> str:
    return hashlib.sha256(f"{kind}:{ident}".encode()).hexdigest()[:12]


class PrivacyManager:
    def __init__(self, store: Store, affiliates: Any) -> None:
        self.store = store
        self.affiliates = affiliates

    # --- Consent ----------------------------------------------------------------

    def set_marketing_consent(self, customer_id: str, granted: bool, source: str, now: datetime) -> dict[str, Any]:
        customer = self.store.customers[customer_id]
        self._ensure_not_erased(customer)
        customer.marketing_consent = granted
        customer.consent_updated_at = now
        customer.consent_source = source
        return {"customer_id": customer_id, "marketing_consent": granted, "updated_at": now, "source": source}

    # --- Access -----------------------------------------------------------------

    def export_customer(self, customer_id: str, now: datetime) -> dict[str, Any]:
        c = self.store.customers[customer_id]
        orders = sorted(self.store.orders_for_customer(customer_id), key=lambda o: o.placed_at)
        tickets = sorted(self.store.find("tickets", customer_id=customer_id), key=lambda t: t.created_at)
        refunds = [r for o in orders for r in self.store.find("refunds", order_id=o.order_id)]
        return to_jsonable({
            "subject": {"type": "customer", "id": customer_id},
            "generated_at": now,
            "erased_at": c.erased_at,
            "profile": {"name": c.name, "email": c.email, "phone": c.phone, "signed_up_at": c.signed_up_at},
            "consent": {"marketing": c.marketing_consent, "updated_at": c.consent_updated_at,
                        "source": c.consent_source},
            "loyalty": {"points": c.loyalty_points, "milestones_awarded": c.milestones_awarded},
            "activity": {"browsed_categories": c.browsed_categories, "cart": c.cart,
                         "cart_updated_at": c.cart_updated_at},
            "orders": [{"order_id": o.order_id, "product_id": o.product_id, "vendor_id": o.vendor_id,
                        "amount": o.amount, "refunded": o.refunded, "status": o.status,
                        "placed_at": o.placed_at, "delivered_at": o.delivered_at,
                        "referral_code": o.referral_code} for o in orders],
            "refunds": [{"refund_id": r.refund_id, "order_id": r.order_id, "amount": r.amount,
                         "reason": r.reason, "status": r.status, "requested_at": r.requested_at}
                        for r in refunds],
            "support_tickets": [{"ticket_id": t.ticket_id, "subject": t.subject, "body": t.body,
                                 "created_at": t.created_at, "order_id": t.order_id} for t in tickets],
        })

    def export_affiliate(self, affiliate_id: str, now: datetime) -> dict[str, Any]:
        a = self.store.affiliates[affiliate_id]
        payouts = sorted(self.store.find("affiliate_payouts", affiliate_id=affiliate_id), key=lambda p: p.payout_id)
        return to_jsonable({
            "subject": {"type": "affiliate", "id": affiliate_id},
            "generated_at": now,
            "erased_at": a.erased_at,
            "profile": {"name": a.name, "email": a.email, "referral_code": a.referral_code, "approved": a.approved},
            "referred_orders": len(self.store.find("attributions", affiliate_id=affiliate_id)),
            "payouts": [{"payout_id": p.payout_id, "created_at": p.created_at, "amount": p.amount}
                        for p in payouts],
            "assets": a.assets,
        })

    # --- Erasure ----------------------------------------------------------------

    def erase_customer(self, customer_id: str, actor: str, now: datetime) -> dict[str, Any]:
        c = self.store.customers[customer_id]
        self._ensure_not_erased(c)
        orders = self.store.orders_for_customer(customer_id)
        open_orders = [o.order_id for o in orders if o.status in (OrderStatus.PLACED, OrderStatus.SHIPPED)]
        pending = [r.refund_id for o in orders for r in self.store.find("refunds", order_id=o.order_id)
                   if r.status == RefundStatus.PENDING]
        if open_orders or pending:
            raise ValueError(f"customer {customer_id} cannot be erased yet: open orders {open_orders}, "
                             f"pending refunds {pending}; complete or cancel them first")

        token = _token("customer", customer_id)
        c.name = "Erased customer"
        c.email = f"erased-{token}@erased.invalid"
        c.phone = ""
        c.browsed_categories, c.cart, c.cart_updated_at = {}, {}, None
        c.marketing_consent, c.consent_updated_at, c.consent_source = False, now, "erasure"
        c.loyalty_points, c.milestones_awarded = 0, []
        c.erased_at = now
        for t in self.store.find("tickets", customer_id=customer_id):
            t.subject = t.body = ERASED
            t.routing_reasons = []
        refunds = [r for o in orders for r in self.store.find("refunds", order_id=o.order_id)]
        for r in refunds:
            r.reason = ERASED
        return {"customer_id": customer_id, "erased_at": now, "erased_by": actor,
                "retained": {"orders": len(orders), "refunds": len(refunds),
                             "reason": "tax and accounting records required by law (no personal data)"}}

    def erase_affiliate(self, affiliate_id: str, actor: str, now: datetime) -> dict[str, Any]:
        a = self.store.affiliates[affiliate_id]
        if a.erased_at is not None:
            raise ValueError(f"affiliate {affiliate_id} has already been erased")
        owed = self.affiliates.payout_preview(affiliate_id, now)["amount"]
        if owed > 0:
            raise ValueError(f"affiliate {affiliate_id} cannot be erased yet: commission {money(owed)} is still "
                             f"owed; run their final payout first")
        token = _token("affiliate", affiliate_id)
        old_code = a.referral_code
        a.name, a.email = "Erased affiliate", f"erased-{token}@erased.invalid"
        # Referral codes are often personal (e.g. a name); replace it everywhere it is stored.
        a.referral_code = f"ERASED-{token}"
        a.approved = False
        a.erased_at = now
        for click in self.store.find("clicks", referral_code=old_code):
            click.referral_code = a.referral_code
        for order in self.store.find("attributions", affiliate_id=affiliate_id):
            o = self.store.orders[order.order_id]
            if o.referral_code == old_code:
                o.referral_code = a.referral_code
        return {"affiliate_id": affiliate_id, "erased_at": now, "erased_by": actor}

    @staticmethod
    def _ensure_not_erased(customer: Any) -> None:
        if customer.erased_at is not None:
            raise ValueError(f"customer {customer.customer_id} has been erased")
