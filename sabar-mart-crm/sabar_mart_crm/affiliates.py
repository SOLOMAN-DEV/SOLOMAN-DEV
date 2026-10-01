"""Affiliate & influencer management: referrals, tiered commissions, assets."""

from __future__ import annotations

from datetime import datetime, timedelta
from decimal import Decimal
from typing import Any

from . import config
from .escalation import EscalationEngine
from .models import (
    Affiliate,
    AffiliatePayout,
    Attribution,
    MarketingAsset,
    Order,
    OrderStatus,
    Priority,
    ReferralClick,
    money,
)
from .store import Store

DUPLICATE_CLICK_WINDOW = timedelta(hours=24)


def tier_for(completed_orders: int) -> tuple[str, Decimal]:
    name, rate = config.AFFILIATE_TIERS[0][0], config.AFFILIATE_TIERS[0][2]
    for tier_name, minimum, tier_rate in config.AFFILIATE_TIERS:
        if completed_orders >= minimum:
            name, rate = tier_name, tier_rate
    return name, rate


def _tier_rank(name: str) -> int:
    return [t[0] for t in config.AFFILIATE_TIERS].index(name)


class AffiliateManager:
    def __init__(self, store: Store, escalations: EscalationEngine) -> None:
        self.store = store
        self.escalations = escalations

    def register(self, affiliate: Affiliate) -> Affiliate:
        if self.store.affiliate_by_code(affiliate.referral_code):
            raise ValueError(f"referral code '{affiliate.referral_code}' already in use")
        self.store.affiliates[affiliate.affiliate_id] = affiliate
        return affiliate

    def approve(self, affiliate_id: str) -> None:
        self.store.affiliates[affiliate_id].approved = True

    # --- Referral tracking ----------------------------------------------------

    def track_click(self, referral_code: str, product_id: str, visitor_fingerprint: str,
                    now: datetime) -> dict[str, Any]:
        affiliate = self.store.affiliate_by_code(referral_code)
        if not affiliate:
            return {"valid": False, "reason": "unknown_referral_code"}
        if not affiliate.approved:
            return {"valid": False, "reason": "affiliate_not_approved"}
        if product_id not in self.store.products:
            return {"valid": False, "reason": "unknown_product"}
        for c in self.store.find("clicks", referral_code=referral_code):
            if (c.product_id == product_id
                    and c.visitor_fingerprint == visitor_fingerprint
                    and now - c.clicked_at < DUPLICATE_CLICK_WINDOW):
                return {"valid": False, "reason": "duplicate_click", "original_click_id": c.click_id}
        click = ReferralClick(f"CLK-{self.store.next_id('click'):06d}", referral_code, product_id, now,
                              visitor_fingerprint)
        self.store.clicks[click.click_id] = click
        return {"valid": True, "click_id": click.click_id, "affiliate_id": affiliate.affiliate_id}

    def attribute(self, order: Order) -> dict[str, Any]:
        """Map an order to the affiliate whose click led to it (last click within window)."""
        if not order.referral_code:
            return {"attributed": False, "reason": "no_referral_code"}
        affiliate = self.store.affiliate_by_code(order.referral_code)
        if not affiliate or not affiliate.approved:
            return {"attributed": False, "reason": "invalid_or_unapproved_code"}
        customer = self.store.customers.get(order.customer_id)
        if customer and customer.email.lower() == affiliate.email.lower():
            self.escalations.raise_task(
                rule="affiliate_self_referral",
                title=f"Self-referral attempt by affiliate {affiliate.affiliate_id}",
                owner_team="affiliate_ops",
                priority=Priority.MEDIUM,
                subject_type="affiliate",
                subject_id=affiliate.affiliate_id,
                now=order.placed_at,
                details={"order_id": order.order_id},
            )
            return {"attributed": False, "reason": "self_referral"}
        window = timedelta(days=config.ATTRIBUTION_WINDOW_DAYS)
        clicks = [c for c in self.store.find("clicks", referral_code=order.referral_code)
                  if c.product_id == order.product_id
                  and timedelta(0) <= order.placed_at - c.clicked_at <= window]
        if not clicks:
            return {"attributed": False, "reason": "no_qualifying_click_in_window"}
        click = max(clicks, key=lambda c: c.clicked_at)
        self.store.attributions[order.order_id] = Attribution(
            order.order_id, affiliate.affiliate_id, click.click_id, order.amount)
        return {"attributed": True, "order_id": order.order_id, "affiliate_id": affiliate.affiliate_id,
                "click_id": click.click_id, "amount": order.amount}

    # --- Commission calculation -----------------------------------------------

    def _completed(self, order: Order, now: datetime) -> bool:
        """Delivered and past the return window (a later return is no longer possible)."""
        return (order.status in (OrderStatus.DELIVERED, OrderStatus.RETURNED) and order.delivered_at is not None
                and now - order.delivered_at >= timedelta(days=config.RETURN_WINDOW_DAYS))

    def lifetime_tier(self, affiliate_id: str, now: datetime) -> tuple[str, Decimal]:
        """Tier from all completed, non-refunded referred orders to date."""
        count_ = sum(1 for rec in self.store.find("attributions", affiliate_id=affiliate_id)
                     if self._completed(order := self.store.orders[rec.order_id], now) and order.net_amount > 0)
        return tier_for(count_)

    def _earned(self, rec: Attribution, order: Order, current_rate: Decimal) -> Decimal:
        rate = rec.commission_rate if rec.commission_rate is not None else current_rate
        return money(order.net_amount * rate)

    def payout_preview(self, affiliate_id: str, now: datetime) -> dict[str, Any]:
        """What a payout run would pay now: new commission plus clawbacks on refunds of paid orders."""
        tier, rate = self.lifetime_tier(affiliate_id, now)
        lines = []
        for rec in self.store.find("attributions", affiliate_id=affiliate_id):
            order = self.store.orders[rec.order_id]
            if not self._completed(order, now):
                continue
            due = self._earned(rec, order, rate) - rec.commission_paid
            if due:
                lines.append({"order_id": order.order_id, "net_sales": str(money(order.net_amount)),
                              "commission": str(due), "kind": "commission" if due > 0 else "clawback"})
        lines.sort(key=lambda line: line["order_id"])
        total = money(sum((Decimal(line["commission"]) for line in lines), Decimal(0)))
        return {"affiliate_id": affiliate_id, "tier": tier, "commission_rate": rate, "amount": total,
                "lines": lines}

    def run_payout(self, affiliate_id: str, actor: str, now: datetime) -> dict[str, Any]:
        affiliate = self.store.affiliates[affiliate_id]
        if not affiliate.approved:
            return {"affiliate_id": affiliate_id, "status": "blocked", "reason": "affiliate not approved"}
        preview = self.payout_preview(affiliate_id, now)
        if preview["amount"] <= 0:
            # Clawbacks larger than new commission carry forward to the next run.
            return {"affiliate_id": affiliate_id, "status": "nothing_payable", "net_due": preview["amount"]}
        payout = AffiliatePayout(f"AP-{self.store.next_id('affiliate_payout'):06d}", affiliate_id, now, actor,
                                 preview["tier"], preview["commission_rate"], preview["amount"], preview["lines"])
        for line in preview["lines"]:
            rec = self.store.attributions[line["order_id"]]
            if rec.commission_rate is None:
                rec.commission_rate = preview["commission_rate"]
            rec.commission_paid += Decimal(line["commission"])
        self.store.affiliate_payouts[payout.payout_id] = payout
        return {"affiliate_id": affiliate_id, "status": "paid", "payout_id": payout.payout_id,
                "amount": payout.amount, "tier": payout.tier, "lines": payout.lines}

    def commission_statement(self, affiliate_id: str, period_start: datetime, period_end: datetime,
                             now: datetime) -> dict[str, Any]:
        """Commission on referred orders placed in the period, split into paid and outstanding."""
        tier, rate = self.lifetime_tier(affiliate_id, now)
        completed, pending, voided = [], [], []
        for rec in self.store.find("attributions", affiliate_id=affiliate_id):
            order = self.store.orders[rec.order_id]
            if not period_start <= order.placed_at < period_end:
                continue
            if order.status == OrderStatus.CANCELLED or order.net_amount <= 0:
                voided.append((rec, order))
            elif self._completed(order, now):
                completed.append((rec, order))
            else:
                pending.append((rec, order))

        earned = money(sum((self._earned(rec, o, rate) for rec, o in completed), Decimal(0)))
        paid = money(sum((rec.commission_paid for rec, _ in completed + voided), Decimal(0)))
        return {
            "affiliate_id": affiliate_id,
            "period": {"start": period_start, "end": period_end},
            "tier": tier,
            "commission_rate": rate,
            "completed_orders": len(completed),
            "eligible_sales": money(sum((o.net_amount for _, o in completed), Decimal(0))),
            "commission_payable": earned,
            "commission_paid": paid,
            "commission_outstanding": earned - paid,
            "pending_orders": [o.order_id for _, o in pending],
            "pending_sales": money(sum((o.net_amount for _, o in pending), Decimal(0))),
            "voided_orders": [o.order_id for _, o in voided],
        }

    # --- Asset distribution ---------------------------------------------------

    def add_asset(self, asset: MarketingAsset) -> MarketingAsset:
        self.store.assets[asset.asset_id] = asset
        return asset

    def distribute_asset(self, affiliate_id: str, asset_id: str, current_tier: str) -> dict[str, Any]:
        affiliate = self.store.affiliates[affiliate_id]
        asset = self.store.assets[asset_id]
        if not affiliate.approved:
            return {"distributed": False, "reason": "affiliate_not_approved"}
        if asset.restricted_to_tier and _tier_rank(current_tier) < _tier_rank(asset.restricted_to_tier):
            return {"distributed": False, "reason": f"requires_tier_{asset.restricted_to_tier}"}
        if asset_id not in affiliate.assets:
            affiliate.assets.append(asset_id)
        return {"distributed": True, "affiliate_id": affiliate_id, "asset_id": asset_id, "kind": asset.kind}
