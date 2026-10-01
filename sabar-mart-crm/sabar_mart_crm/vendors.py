"""Vendor management (B2B): onboarding, performance analytics, financial ledger."""

from __future__ import annotations

from datetime import datetime, timedelta
from decimal import Decimal
from typing import Any

from . import config
from .escalation import EscalationEngine
from .models import LedgerEntry, Order, OrderStatus, Priority, VerificationStatus, Vendor, money
from .store import Store

DOC_PENDING, DOC_APPROVED, DOC_REJECTED = "pending", "approved", "rejected"


def _ratio(num: int, den: int) -> Decimal:
    return (Decimal(num) / Decimal(den)).quantize(Decimal("0.0001")) if den else Decimal(0)


class VendorManager:
    def __init__(self, store: Store, escalations: EscalationEngine) -> None:
        self.store = store
        self.escalations = escalations

    # --- Onboarding & compliance ----------------------------------------------

    def onboard(self, vendor: Vendor) -> Vendor:
        self.store.vendors[vendor.vendor_id] = vendor
        return vendor

    def submit_document(self, vendor_id: str, doc_type: str) -> None:
        if doc_type not in config.REQUIRED_VENDOR_DOCUMENTS:
            raise ValueError(f"unknown document type '{doc_type}'")
        vendor = self.store.vendors[vendor_id]
        vendor.documents[doc_type] = DOC_PENDING
        self._refresh_verification(vendor)

    def review_document(self, vendor_id: str, doc_type: str, approved: bool) -> None:
        vendor = self.store.vendors[vendor_id]
        if doc_type not in vendor.documents:
            raise ValueError(f"document '{doc_type}' was never submitted")
        vendor.documents[doc_type] = DOC_APPROVED if approved else DOC_REJECTED
        self._refresh_verification(vendor)

    def _refresh_verification(self, vendor: Vendor) -> None:
        docs = vendor.documents
        if any(state == DOC_REJECTED for state in docs.values()):
            vendor.verification_status = VerificationStatus.REJECTED
        elif all(docs.get(d) == DOC_APPROVED for d in config.REQUIRED_VENDOR_DOCUMENTS):
            vendor.verification_status = VerificationStatus.VERIFIED
        elif docs:
            vendor.verification_status = VerificationStatus.UNDER_REVIEW
        else:
            vendor.verification_status = VerificationStatus.PENDING

    def complete_milestone(self, vendor_id: str, milestone: str, now: datetime) -> None:
        if milestone not in config.STORE_SETUP_MILESTONES:
            raise ValueError(f"unknown milestone '{milestone}'")
        self.store.vendors[vendor_id].milestones.setdefault(milestone, now)

    def onboarding_status(self, vendor_id: str) -> dict[str, Any]:
        vendor = self.store.vendors[vendor_id]
        docs = {d: vendor.documents.get(d, "missing") for d in config.REQUIRED_VENDOR_DOCUMENTS}
        steps = {m: (m in vendor.milestones) for m in config.STORE_SETUP_MILESTONES}
        total = len(docs) + len(steps)
        done = sum(v == DOC_APPROVED for v in docs.values()) + sum(steps.values())
        return {
            "vendor_id": vendor_id,
            "store_name": vendor.store_name,
            "verification_status": vendor.verification_status.value,
            "documents": docs,
            "store_setup": steps,
            "progress_pct": round(100 * done / total),
            "can_go_live": vendor.verification_status == VerificationStatus.VERIFIED and all(steps.values()),
            "next_actions": [f"submit:{d}" for d, s in docs.items() if s == "missing"]
            + [f"resubmit:{d}" for d, s in docs.items() if s == DOC_REJECTED]
            + [f"complete:{m}" for m, ok in steps.items() if not ok],
        }

    # --- Performance analytics ------------------------------------------------

    def add_review(self, vendor_id: str, score: Decimal | int | float, now: datetime) -> None:
        score = Decimal(str(score))
        if not Decimal(1) <= score <= Decimal(5):
            raise ValueError("review score must be between 1 and 5")
        self.store.vendors[vendor_id].review_scores.append(score)
        self.evaluate(vendor_id, now)

    def performance(self, vendor_id: str) -> dict[str, Any]:
        vendor = self.store.vendors[vendor_id]
        orders = self.store.orders_for_vendor(vendor_id)
        cancelled = sum(o.status == OrderStatus.CANCELLED for o in orders)
        dispatched = [o for o in orders if o.status in (OrderStatus.SHIPPED, OrderStatus.DELIVERED, OrderStatus.RETURNED)]
        delivered = [o for o in orders if o.status in (OrderStatus.DELIVERED, OrderStatus.RETURNED)]
        returned = sum(o.status == OrderStatus.RETURNED for o in orders)
        avg_review = (
            (sum(vendor.review_scores) / len(vendor.review_scores)).quantize(Decimal("0.01"))
            if vendor.review_scores else None
        )
        return {
            "vendor_id": vendor_id,
            "total_orders": len(orders),
            "fulfillment_rate": _ratio(len(orders) - cancelled, len(orders)) if orders else None,
            "shipping_delay_rate": _ratio(sum(o.shipped_late for o in dispatched), len(dispatched)),
            "return_rate": _ratio(returned, len(delivered)),
            "avg_review_score": avg_review,
            "review_count": len(vendor.review_scores),
        }

    def evaluate(self, vendor_id: str, now: datetime) -> list[str]:
        """Check performance against thresholds, raising vendor_success tasks on breach."""
        perf = self.performance(vendor_id)
        vendor = self.store.vendors[vendor_id]
        breaches: list[tuple[str, str, Priority]] = []
        if perf["avg_review_score"] is not None and perf["avg_review_score"] < config.MIN_REVIEW_SCORE:
            breaches.append(("vendor_low_review_score",
                             f"avg review {perf['avg_review_score']} < {config.MIN_REVIEW_SCORE}", Priority.HIGH))
        if perf["fulfillment_rate"] is not None and perf["fulfillment_rate"] < config.MIN_FULFILLMENT_RATE:
            breaches.append(("vendor_low_fulfillment",
                             f"fulfillment {perf['fulfillment_rate']} < {config.MIN_FULFILLMENT_RATE}", Priority.HIGH))
        if perf["shipping_delay_rate"] > config.MAX_SHIPPING_DELAY_RATE:
            breaches.append(("vendor_shipping_delays",
                             f"delay rate {perf['shipping_delay_rate']} > {config.MAX_SHIPPING_DELAY_RATE}", Priority.MEDIUM))
        if perf["return_rate"] > config.MAX_RETURN_RATE:
            breaches.append(("vendor_high_returns",
                             f"return rate {perf['return_rate']} > {config.MAX_RETURN_RATE}", Priority.MEDIUM))

        for rule, reason, priority in breaches:
            self.escalations.raise_task(
                rule=rule,
                title=f"Vendor {vendor.store_name}: {reason}",
                owner_team="vendor_success",
                priority=priority,
                subject_type="vendor",
                subject_id=vendor_id,
                now=now,
                details={"metric": reason},
            )
        vendor.is_flagged = bool(breaches)
        return [rule for rule, _, _ in breaches]

    # --- Financial ledger -----------------------------------------------------

    def _post(self, vendor_id: str, kind: str, amount: Decimal, now: datetime, reference: str) -> LedgerEntry:
        entry = LedgerEntry(f"LED-{self.store.next_id('ledger'):06d}", vendor_id, kind, money(amount), now, reference)
        self.store.ledger[entry.entry_id] = entry
        return entry

    def record_sale(self, order: Order, now: datetime) -> None:
        vendor = self.store.vendors[order.vendor_id]
        self._post(order.vendor_id, "sale", order.amount, now, order.order_id)
        self._post(order.vendor_id, "commission", -(order.amount * vendor.commission_rate), now, order.order_id)

    def record_return(self, order: Order, now: datetime) -> None:
        """Reverse the sale and refund the marketplace commission on it."""
        vendor = self.store.vendors[order.vendor_id]
        self._post(order.vendor_id, "refund", -order.amount, now, order.order_id)
        self._post(order.vendor_id, "commission_reversal", order.amount * vendor.commission_rate, now, order.order_id)

    def _entries(self, vendor_id: str) -> list[LedgerEntry]:
        return self.store.find("ledger", vendor_id=vendor_id)

    def _held_amount(self, vendor_id: str, now: datetime) -> Decimal:
        """Net proceeds of delivered orders still inside the return window."""
        vendor = self.store.vendors[vendor_id]
        held = Decimal(0)
        for order in self.store.orders_for_vendor(vendor_id):
            if (order.status == OrderStatus.DELIVERED and order.delivered_at
                    and now - order.delivered_at < timedelta(days=config.RETURN_WINDOW_DAYS)):
                held += order.amount * (1 - vendor.commission_rate)
        return money(held)

    def ledger_summary(self, vendor_id: str, now: datetime) -> dict[str, Any]:
        entries = self._entries(vendor_id)

        def total(*kinds: str) -> Decimal:
            return money(sum((e.amount for e in entries if e.kind in kinds), Decimal(0)))

        balance = money(sum((e.amount for e in entries), Decimal(0)))
        held = min(self._held_amount(vendor_id, now), max(balance, Decimal(0)))
        payouts = [e for e in entries if e.kind == "payout"]
        last_payout = max((e.created_at for e in payouts), default=None)
        next_payout = last_payout + timedelta(days=config.PAYOUT_CYCLE_DAYS) if last_payout else now
        return {
            "vendor_id": vendor_id,
            "gross_sales": total("sale"),
            "refunds": -total("refund"),
            "commission_deducted": -(total("commission") + total("commission_reversal")),
            "paid_out": -total("payout"),
            "outstanding_balance": balance,
            "held_in_return_window": held,
            "payable_now": money(max(balance - held, Decimal(0))),
            "last_payout_at": last_payout,
            "next_payout_due": next_payout,
        }

    def run_payout(self, vendor_id: str, now: datetime) -> dict[str, Any]:
        summary = self.ledger_summary(vendor_id, now)
        vendor = self.store.vendors[vendor_id]
        if vendor.verification_status != VerificationStatus.VERIFIED:
            return {"vendor_id": vendor_id, "status": "blocked", "reason": "vendor not verified"}
        if now < summary["next_payout_due"]:
            return {"vendor_id": vendor_id, "status": "not_due", "next_payout_due": summary["next_payout_due"]}
        amount = summary["payable_now"]
        if amount <= 0:
            return {"vendor_id": vendor_id, "status": "nothing_payable"}
        entry = self._post(vendor_id, "payout", -amount, now, f"PAYOUT-{now:%Y%m%d}")
        return {"vendor_id": vendor_id, "status": "paid", "amount": amount, "ledger_entry": entry.entry_id}
