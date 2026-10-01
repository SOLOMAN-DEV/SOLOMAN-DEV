"""Customer management (B2C): lifecycle, ticket routing, personalization."""

from __future__ import annotations

import re
from collections import Counter
from datetime import datetime, timedelta
from decimal import Decimal
from typing import Any

from . import config
from .escalation import EscalationEngine
from .models import (
    Customer,
    LifecycleStage,
    Order,
    OrderStatus,
    Priority,
    Sentiment,
    SupportTicket,
    money,
)
from .store import Store

NEGATIVE_WORDS = {
    "angry", "terrible", "worst", "awful", "broken", "damaged", "fraud", "scam", "unacceptable",
    "disappointed", "useless", "never", "refund", "late", "missing", "wrong", "furious", "complaint",
}
POSITIVE_WORDS = {"thanks", "thank", "great", "love", "excellent", "happy", "awesome", "appreciate"}

# Ordered: first matching rule wins.
TEAM_RULES: tuple[tuple[str, frozenset[str]], ...] = (
    ("trust_and_safety", frozenset({"fraud", "scam", "hacked", "unauthorized", "stolen", "phishing"})),
    ("payments", frozenset({"payment", "charged", "charge", "billing", "invoice", "upi", "card"})),
    ("returns_and_refunds", frozenset({"refund", "return", "exchange", "replacement"})),
    ("logistics", frozenset({"delivery", "shipping", "shipped", "courier", "tracking", "late", "missing"})),
)
CRITICAL_WORDS = frozenset({"fraud", "hacked", "unauthorized", "legal", "lawyer", "police"})

COMPLETED = (OrderStatus.DELIVERED,)


def _tokens(text: str) -> list[str]:
    return re.findall(r"[a-z]+", text.lower())


class CustomerManager:
    def __init__(self, store: Store, escalations: EscalationEngine) -> None:
        self.store = store
        self.escalations = escalations

    # --- Lifecycle tracking ---------------------------------------------

    def register(self, customer: Customer) -> Customer:
        self.store.customers[customer.customer_id] = customer
        return customer

    def record_browse(self, customer_id: str, category: str) -> None:
        cats = self.store.customers[customer_id].browsed_categories
        cats[category] = cats.get(category, 0) + 1

    def update_cart(self, customer_id: str, product_id: str, price: Decimal | None, now: datetime) -> None:
        """Add (price given) or remove (price None) a cart line."""
        customer = self.store.customers[customer_id]
        if price is None:
            customer.cart.pop(product_id, None)
        else:
            customer.cart[product_id] = money(price)
        customer.cart_updated_at = now

    def place_order(self, order: Order) -> Order:
        customer = self.store.customers[order.customer_id]
        self.store.orders[order.order_id] = order
        customer.order_ids.append(order.order_id)
        customer.cart.pop(order.product_id, None)
        product = self.store.products.get(order.product_id)
        if product:
            product.popularity += 1
        return order

    def lifetime_value(self, customer_id: str) -> Decimal:
        orders = self.store.orders_for_customer(customer_id)
        return money(sum((o.net_amount for o in orders if o.status in COMPLETED), Decimal(0)))

    def lifecycle_stage(self, customer_id: str, now: datetime) -> LifecycleStage:
        customer = self.store.customers[customer_id]
        orders = [o for o in self.store.orders_for_customer(customer_id) if o.status != OrderStatus.CANCELLED]
        cart_abandoned = bool(
            customer.cart
            and customer.cart_updated_at
            and now - customer.cart_updated_at >= timedelta(hours=config.CART_ABANDONMENT_HOURS)
        )
        if not orders:
            if cart_abandoned:
                return LifecycleStage.CART_ABANDONED
            return LifecycleStage.BROWSING if customer.browsed_categories else LifecycleStage.SIGNED_UP

        last_order = max(o.placed_at for o in orders)
        if now - last_order > timedelta(days=config.CHURN_RISK_DAYS):
            return LifecycleStage.AT_RISK
        if self.lifetime_value(customer_id) >= config.VIP_MIN_LIFETIME_VALUE:
            return LifecycleStage.VIP
        if len(orders) >= config.LOYAL_MIN_ORDERS:
            return LifecycleStage.LOYAL
        if cart_abandoned:
            return LifecycleStage.CART_ABANDONED
        return LifecycleStage.REPEAT if len(orders) > 1 else LifecycleStage.FIRST_PURCHASE

    def abandoned_carts(self, now: datetime) -> list[dict[str, Any]]:
        out = []
        for c in self.store.customers.values():
            if c.cart and c.cart_updated_at and now - c.cart_updated_at >= timedelta(hours=config.CART_ABANDONMENT_HOURS):
                out.append({
                    "customer_id": c.customer_id,
                    "items": len(c.cart),
                    "cart_value": str(money(sum(c.cart.values(), Decimal(0)))),
                    "hours_idle": int((now - c.cart_updated_at).total_seconds() // 3600),
                    "action": "send_cart_recovery_campaign",
                })
        return out

    # --- Loyalty ----------------------------------------------------------

    def award_loyalty(self, order: Order) -> list[dict[str, Any]]:
        """Credit points for a delivered order; return any milestone reward triggers."""
        customer = self.store.customers[order.customer_id]
        customer.loyalty_points += self.points_for(order.amount)
        triggers = []
        for milestone in config.LOYALTY_MILESTONES:
            if customer.loyalty_points >= milestone and milestone not in customer.milestones_awarded:
                customer.milestones_awarded.append(milestone)
                triggers.append({
                    "trigger": "loyalty_milestone_reached",
                    "customer_id": customer.customer_id,
                    "milestone_points": milestone,
                    "balance": customer.loyalty_points,
                    "suggested_reward": f"voucher_tier_{config.LOYALTY_MILESTONES.index(milestone) + 1}",
                })
        return triggers

    @staticmethod
    def points_for(amount: Decimal) -> int:
        return int(amount // 100) * config.LOYALTY_POINTS_PER_100

    def revoke_loyalty(self, customer_id: str, net_before: Decimal, net_after: Decimal) -> int:
        """Take back the points earned on the refunded part of an order."""
        customer = self.store.customers[customer_id]
        revoked = self.points_for(net_before) - self.points_for(net_after)
        customer.loyalty_points = max(0, customer.loyalty_points - revoked)
        return revoked

    # --- Support ticket intelligence ----------------------------------------

    @staticmethod
    def analyse_sentiment(text: str) -> tuple[Sentiment, int]:
        tokens = _tokens(text)
        score = sum(t in POSITIVE_WORDS for t in tokens) - sum(t in NEGATIVE_WORDS for t in tokens)
        score -= text.count("!") // 2
        if score < 0:
            return Sentiment.NEGATIVE, score
        return (Sentiment.POSITIVE if score > 0 else Sentiment.NEUTRAL), score

    def route_ticket(self, ticket: SupportTicket, now: datetime) -> SupportTicket:
        text = f"{ticket.subject} {ticket.body}"
        words = set(_tokens(text))
        sentiment, score = self.analyse_sentiment(text)
        stage = self.lifecycle_stage(ticket.customer_id, now)
        order = self.store.orders.get(ticket.order_id) if ticket.order_id else None
        reasons = [f"sentiment={sentiment.value} (score {score})", f"lifecycle={stage.value}"]

        team = next((name for name, kws in TEAM_RULES if words & kws), "general_support")
        reasons.append(f"keyword routing -> {team}")

        priority = Priority.LOW
        if sentiment == Sentiment.NEGATIVE:
            priority = Priority.HIGH if score <= -3 else Priority.MEDIUM
        if order and order.amount >= config.STANDARD_REFUND_LIMIT:
            priority = max(priority, Priority.HIGH, key=_prio_rank)
            reasons.append(f"high-value order {order.order_id} ({order.amount})")
        if stage in (LifecycleStage.VIP, LifecycleStage.LOYAL):
            priority = max(priority, Priority.HIGH, key=_prio_rank)
            if team == "general_support":
                team = "vip_concierge"
            reasons.append("loyal/VIP customer uplift")
        if words & CRITICAL_WORDS:
            priority = Priority.CRITICAL
            reasons.append("critical keyword detected")

        ticket.sentiment, ticket.priority, ticket.assigned_team, ticket.routing_reasons = sentiment, priority, team, reasons
        self.store.tickets[ticket.ticket_id] = ticket

        if priority == Priority.CRITICAL:
            self.escalations.raise_task(
                rule="critical_support_ticket",
                title=f"Critical ticket {ticket.ticket_id}: {ticket.subject}",
                owner_team="trust_and_safety" if team == "trust_and_safety" else "customer_support",
                priority=Priority.CRITICAL,
                subject_type="ticket",
                subject_id=ticket.ticket_id,
                now=now,
                details={"customer_id": ticket.customer_id, "assigned_team": team},
            )
        return ticket

    # --- Personalization ----------------------------------------------------

    def recommendations(self, customer_id: str, limit: int = 5) -> list[dict[str, Any]]:
        customer = self.store.customers[customer_id]
        purchased = {self.store.orders[oid].product_id for oid in customer.order_ids}
        affinity: Counter[str] = Counter(customer.browsed_categories)
        for oid in customer.order_ids:
            order = self.store.orders[oid]
            if order.status != OrderStatus.CANCELLED:
                affinity[order.category] += 3 if order.status != OrderStatus.RETURNED else -2
        max_pop = max((p.popularity for p in self.store.products.values()), default=0) or 1

        scored = []
        for product in self.store.products.values():
            if product.product_id in purchased or product.product_id in customer.cart:
                continue
            vendor = self.store.vendors.get(product.vendor_id)
            if vendor and vendor.is_flagged:
                continue
            score = affinity.get(product.category, 0) * 1.0 + product.popularity / max_pop
            if score <= 0:
                continue
            scored.append((score, product))
        scored.sort(key=lambda sp: (-sp[0], sp[1].product_id))
        return [
            {"product_id": p.product_id, "name": p.name, "category": p.category, "score": round(s, 3),
             "reason": "category_affinity" if affinity.get(p.category, 0) > 0 else "trending"}
            for s, p in scored[:limit]
        ]


def _prio_rank(p: Priority) -> int:
    return [Priority.LOW, Priority.MEDIUM, Priority.HIGH, Priority.CRITICAL].index(p)
