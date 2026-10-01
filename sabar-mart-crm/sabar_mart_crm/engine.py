"""CRMEngine: the single coordinator across customers, vendors, affiliates and teams.

Order lifecycle events enter here once and fan out to every pillar that cares
(loyalty, vendor ledger, vendor scorecards, affiliate attribution). All reads
that expose data go through role-gated views.
"""

from __future__ import annotations

from collections import Counter
from datetime import datetime, timedelta
from decimal import Decimal
from typing import Any

from . import config
from .affiliates import AffiliateManager
from .customers import CustomerManager
from .escalation import EscalationEngine
from .models import Order, OrderStatus, SupportTicket, money, to_jsonable
from .rbac import Permission, Role, has_permission, mask_pii, require, require_team
from .store import Store
from .vendors import VendorManager


class CRMEngine:
    def __init__(self, store: Store | None = None) -> None:
        self.store = store or Store()
        self.escalations = EscalationEngine(self.store)
        self.customers = CustomerManager(self.store, self.escalations)
        self.vendors = VendorManager(self.store, self.escalations)
        self.affiliates = AffiliateManager(self.store, self.escalations)

    # --- Order lifecycle (cross-pillar fan-out) ----------------------------------

    def place_order(self, order: Order) -> dict[str, Any]:
        self.customers.place_order(order)
        attribution = self.affiliates.attribute(order)
        return {"order_id": order.order_id, "status": order.status, "affiliate_attribution": attribution}

    def ship_order(self, order_id: str, late: bool, now: datetime) -> None:
        order = self._transition(order_id, {OrderStatus.PLACED}, OrderStatus.SHIPPED)
        order.shipped_late = late
        self.vendors.evaluate(order.vendor_id, now)

    def deliver_order(self, order_id: str, now: datetime) -> dict[str, Any]:
        order = self._transition(order_id, {OrderStatus.SHIPPED}, OrderStatus.DELIVERED)
        order.delivered_at = now
        self.vendors.record_sale(order, now)
        triggers = self.customers.award_loyalty(order)
        self.vendors.evaluate(order.vendor_id, now)
        return {"order_id": order_id, "loyalty_triggers": triggers}

    def return_order(self, order_id: str, now: datetime) -> None:
        order = self.store.orders[order_id]
        if order.delivered_at and now - order.delivered_at > timedelta(days=config.RETURN_WINDOW_DAYS):
            raise ValueError(f"order {order_id}: return window of {config.RETURN_WINDOW_DAYS} days has closed")
        order = self._transition(order_id, {OrderStatus.DELIVERED}, OrderStatus.RETURNED)
        self.vendors.record_return(order, now)
        self.customers.revoke_loyalty(order)
        self.vendors.evaluate(order.vendor_id, now)

    def cancel_order(self, order_id: str, now: datetime) -> None:
        order = self._transition(order_id, {OrderStatus.PLACED}, OrderStatus.CANCELLED)
        self.vendors.evaluate(order.vendor_id, now)

    def _transition(self, order_id: str, allowed_from: set[OrderStatus], to: OrderStatus) -> Order:
        order = self.store.orders[order_id]
        if order.status not in allowed_from:
            raise ValueError(f"order {order_id}: cannot move {order.status.value} -> {to.value}")
        order.status = to
        return order

    def submit_ticket(self, ticket: SupportTicket, now: datetime) -> SupportTicket:
        return self.customers.route_ticket(ticket, now)

    # --- Role-gated views ---------------------------------------------------------

    def customer_profile(self, role: Role, customer_id: str, now: datetime) -> dict[str, Any]:
        require(role, Permission.VIEW_CUSTOMER_PROFILE)
        c = self.store.customers[customer_id]
        record = {
            "customer_id": c.customer_id,
            "name": c.name,
            "email": c.email,
            "phone": c.phone,
            "lifecycle_stage": self.customers.lifecycle_stage(customer_id, now),
            "lifetime_value": self.customers.lifetime_value(customer_id),
            "orders": len(c.order_ids),
            "loyalty_points": c.loyalty_points,
        }
        if not has_permission(role, Permission.VIEW_CUSTOMER_PII):
            record = mask_pii(record)
        if has_permission(role, Permission.VIEW_TICKETS):
            record["open_tickets"] = [t.ticket_id for t in self.store.tickets.values() if t.customer_id == customer_id]
        if has_permission(role, Permission.VIEW_RECOMMENDATIONS):
            record["recommendations"] = self.customers.recommendations(customer_id)
        return to_jsonable(record)

    def support_queue(self, role: Role) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_TICKETS)
        rank = {"critical": 0, "high": 1, "medium": 2, "low": 3}
        tickets = sorted(self.store.tickets.values(), key=lambda t: (rank[t.priority.value], t.created_at))
        return to_jsonable([
            {"ticket_id": t.ticket_id, "customer_id": t.customer_id, "priority": t.priority,
             "sentiment": t.sentiment, "assigned_team": t.assigned_team, "subject": t.subject,
             "routing_reasons": t.routing_reasons}
            for t in tickets
        ])

    def vendor_scorecard(self, role: Role, vendor_id: str) -> dict[str, Any]:
        require(role, Permission.VIEW_VENDOR_PROFILE)
        vendor = self.store.vendors[vendor_id]
        card = {"store_name": vendor.store_name, "flagged": vendor.is_flagged, **self.vendors.performance(vendor_id)}
        if has_permission(role, Permission.MANAGE_VENDOR_ONBOARDING):
            card["onboarding"] = self.vendors.onboarding_status(vendor_id)
        return to_jsonable(card)

    def vendor_payout_report(self, role: Role, now: datetime) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_VENDOR_LEDGER)
        return to_jsonable([self.vendors.ledger_summary(v, now) for v in self.store.vendors])

    def affiliate_payout_report(self, role: Role, period_start: datetime, period_end: datetime,
                                now: datetime) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_AFFILIATE_PAYOUTS)
        return to_jsonable([
            self.affiliates.commission_statement(a, period_start, period_end, now) for a in self.store.affiliates
        ])

    def task_queue(self, role: Role, team: str) -> list[dict[str, Any]]:
        require(role, Permission.VIEW_TASKS)
        require_team(role, team)
        return to_jsonable(self.escalations.queue_for(team))

    def global_analytics(self, role: Role, now: datetime) -> dict[str, Any]:
        require(role, Permission.VIEW_GLOBAL_ANALYTICS)
        orders = list(self.store.orders.values())
        status_counts = Counter(o.status.value for o in orders)
        gmv = money(sum((o.amount for o in orders if o.status == OrderStatus.DELIVERED), Decimal(0)))
        commission = money(-sum((e.amount for e in self.store.ledger.values()
                                 if e.kind in ("commission", "commission_reversal")), Decimal(0)))
        stages = Counter(self.customers.lifecycle_stage(c, now).value for c in self.store.customers)
        attributed = [o for o in orders if o.order_id in self.store.attributions]
        return to_jsonable({
            "generated_at": now,
            "customers": {"total": len(self.store.customers), "by_lifecycle_stage": dict(stages),
                          "abandoned_carts": len(self.customers.abandoned_carts(now))},
            "orders": {"total": len(orders), "by_status": dict(status_counts)},
            "revenue": {"gmv_delivered": gmv, "marketplace_commission_net": commission},
            "vendors": {"total": len(self.store.vendors),
                        "verified": sum(v.verification_status.value == "verified" for v in self.store.vendors.values()),
                        "flagged": [v.vendor_id for v in self.store.vendors.values() if v.is_flagged]},
            "affiliates": {"total": len(self.store.affiliates),
                           "approved": sum(a.approved for a in self.store.affiliates.values()),
                           "attributed_orders": len(attributed),
                           "attributed_sales": money(sum((o.amount for o in attributed), Decimal(0)))},
            "support": {"tickets": len(self.store.tickets),
                        "by_priority": dict(Counter(t.priority.value for t in self.store.tickets.values()))},
            "open_tasks": sum(t.status.value != "resolved" for t in self.escalations.tasks.values()),
        })

