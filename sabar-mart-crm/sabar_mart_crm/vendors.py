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

SALE_COMPONENTS = ("sale", "commission", "commission_gst", "tcs", "tds")
# Ledger kind used when a refund reverses each sale component ("refund" keeps its historical name).
REVERSAL_KIND = {"sale": "refund", "refund": "sale", **{k: f"{k}_reversal" for k in SALE_COMPONENTS[1:]}}


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
        """Post the sale and everything withheld from it: commission, GST on commission, TCS and TDS."""
        vendor = self.store.vendors[order.vendor_id]
        commission = money(order.amount * vendor.commission_rate)
        pan_verified = vendor.documents.get("pan_card") == DOC_APPROVED
        tds_rate = config.TDS_194O_RATE if pan_verified else config.TDS_194O_NO_PAN_RATE
        for kind, amount in (
            ("sale", order.amount),
            ("commission", -commission),
            ("commission_gst", -(commission * config.GST_ON_COMMISSION_RATE)),
            ("tcs", -(order.amount * config.GST_TCS_RATE)),
            ("tds", -(order.amount * tds_rate)),
        ):
            self._post(order.vendor_id, kind, amount, now, order.order_id)

    def apply_refund(self, order: Order, amount: Decimal, now: datetime) -> None:
        """Refund part or all of a delivered order, reversing each posted component pro rata.

        Reversals are computed on the cumulative refunded amount, so any sequence of partial
        refunds that adds up to the full order reverses the original entries exactly.
        """
        amount = money(amount)
        if amount <= 0 or amount > order.net_amount:
            raise ValueError(f"order {order.order_id}: refund {amount} exceeds refundable {order.net_amount}")
        originals: dict[str, Decimal] = {}
        for e in self._entries(order.vendor_id):
            if e.reference == order.order_id and e.kind in SALE_COMPONENTS:
                originals[e.kind] = originals.get(e.kind, Decimal(0)) + e.amount
        before, after = order.refunded, order.refunded + amount
        for kind, original in originals.items():
            delta = money(original * after / order.amount) - money(original * before / order.amount)
            if delta:
                self._post(order.vendor_id, REVERSAL_KIND[kind], -delta, now, order.order_id)
        order.refunded = after

    def _entries(self, vendor_id: str) -> list[LedgerEntry]:
        return self.store.find("ledger", vendor_id=vendor_id)

    def _held_amount(self, entries: list[LedgerEntry], now: datetime) -> Decimal:
        """Net proceeds of delivered orders still inside the return window."""
        open_orders = set()
        for e in entries:
            order = self.store.orders.get(e.reference) if e.kind == "sale" else None
            if (order and order.status == OrderStatus.DELIVERED and order.delivered_at
                    and now - order.delivered_at < timedelta(days=config.RETURN_WINDOW_DAYS)):
                open_orders.add(order.order_id)
        return money(sum((e.amount for e in entries if e.reference in open_orders), Decimal(0)))

    def ledger_summary(self, vendor_id: str, now: datetime) -> dict[str, Any]:
        entries = self._entries(vendor_id)

        def total(*kinds: str) -> Decimal:
            return money(sum((e.amount for e in entries if e.kind in kinds), Decimal(0)))

        def net_withheld(kind: str) -> Decimal:
            return -total(kind, REVERSAL_KIND[kind])

        balance = money(sum((e.amount for e in entries), Decimal(0)))
        held = min(self._held_amount(entries, now), max(balance, Decimal(0)))
        payouts = [e for e in entries if e.kind == "payout"]
        last_payout = max((e.created_at for e in payouts), default=None)
        next_payout = last_payout + timedelta(days=config.PAYOUT_CYCLE_DAYS) if last_payout else now
        return {
            "vendor_id": vendor_id,
            "gross_sales": total("sale"),
            "refunds": -total("refund"),
            "commission_deducted": net_withheld("commission"),
            "gst_on_commission": net_withheld("commission_gst"),
            "tcs_withheld": net_withheld("tcs"),
            "tds_withheld": net_withheld("tds"),
            "paid_out": -total("payout"),
            "outstanding_balance": balance,
            "held_in_return_window": held,
            "payable_now": money(max(balance - held, Decimal(0))),
            "last_payout_at": last_payout,
            "next_payout_due": next_payout,
        }

    def tax_report(self, period_start: datetime, period_end: datetime) -> dict[str, Any]:
        """Per-vendor amounts for TCS (GSTR-8) and TDS (194-O) filings, by ledger posting date."""
        rows = []
        for vendor_id in self.store.vendors:
            entries = [e for e in self._entries(vendor_id) if period_start <= e.created_at < period_end]

            def net(kind: str) -> Decimal:
                return money(sum((e.amount for e in entries if e.kind in (kind, REVERSAL_KIND[kind])), Decimal(0)))

            def total(kind: str) -> Decimal:
                return money(sum((e.amount for e in entries if e.kind == kind), Decimal(0)))

            row = {
                "vendor_id": vendor_id,
                "gross_sales": total("sale"),
                "refunds": -total("refund"),
                "net_taxable_value": net("sale"),  # sales minus refunds
                "tcs_collected": -net("tcs"),
                "tds_deducted": -net("tds"),
                "commission_earned": -net("commission"),
                "gst_on_commission": -net("commission_gst"),
            }
            if any(row[k] for k in row if k != "vendor_id"):
                rows.append(row)
        totals = {k: money(sum((r[k] for r in rows), Decimal(0))) for k in
                  ("gross_sales", "refunds", "net_taxable_value", "tcs_collected", "tds_deducted",
                   "commission_earned", "gst_on_commission")}
        return {"period": {"start": period_start, "end": period_end}, "vendors": rows, "totals": totals,
                "rates": {"gst_tcs": config.GST_TCS_RATE, "tds_194o": config.TDS_194O_RATE,
                          "tds_194o_no_pan": config.TDS_194O_NO_PAN_RATE,
                          "gst_on_commission": config.GST_ON_COMMISSION_RATE}}

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
