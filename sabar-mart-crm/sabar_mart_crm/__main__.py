"""Run a seeded end-to-end scenario and print role-scoped JSON reports.

    python -m sabar_mart_crm            # all reports
    python -m sabar_mart_crm admin      # one report: admin|support|finance|vendor|tasks
"""

from __future__ import annotations

import json
import sys
from datetime import datetime, timedelta
from decimal import Decimal

from . import config
from .engine import CRMEngine
from .models import Affiliate, Customer, MarketingAsset, Order, SupportTicket, Vendor
from .rbac import Role
from .store import Product


def seed(now: datetime, crm: CRMEngine | None = None) -> CRMEngine:
    crm = crm or CRMEngine()
    t0 = now - timedelta(days=40)

    for vid, name, docs_ok in (("V-100", "Kiran Textiles", True), ("V-200", "QuickGadgets", True)):
        crm.vendors.onboard(Vendor(vid, name, f"ops@{name.split()[0].lower()}.example"))
        for doc in config.REQUIRED_VENDOR_DOCUMENTS:
            crm.vendors.submit_document(vid, doc)
            crm.vendors.review_document(vid, doc, approved=docs_ok)
        for m in config.STORE_SETUP_MILESTONES:
            crm.vendors.complete_milestone(vid, m, t0)
    crm.vendors.onboard(Vendor("V-300", "Fresh Spices Co", "hello@freshspices.example"))
    crm.vendors.submit_document("V-300", "tax_id")

    for pid, vid, name, cat in (
        ("P-1", "V-100", "Cotton Kurta", "apparel"), ("P-2", "V-100", "Silk Saree", "apparel"),
        ("P-3", "V-100", "Linen Shirt", "apparel"), ("P-4", "V-200", "Wireless Earbuds", "electronics"),
        ("P-5", "V-200", "Phone Charger", "electronics"), ("P-6", "V-200", "Smart Watch", "electronics"),
        ("P-7", "V-100", "Block Print Dupatta", "apparel"), ("P-8", "V-100", "Cotton Bedsheet", "home"),
    ):
        crm.store.products[pid] = Product(pid, vid, name, cat)

    crm.customers.register(Customer("C-1", "Asha Patel", "asha@example.com", "9876500001", t0))
    crm.customers.register(Customer("C-2", "Rahul Mehta", "rahul@example.com", "9876500002", t0))
    crm.customers.register(Customer("C-3", "Neha Shah", "neha@example.com", "9876500003", t0))
    for _ in range(3):
        crm.customers.record_browse("C-1", "apparel")
    crm.customers.record_browse("C-3", "electronics")
    crm.customers.update_cart("C-3", "P-6", Decimal("4999"), now - timedelta(hours=30))

    crm.affiliates.register(Affiliate("A-1", "StyleByRiya", "riya@influencer.example", "RIYA10"))
    crm.affiliates.approve("A-1")
    crm.affiliates.add_asset(MarketingAsset("AS-1", "banner", "Festive Sale 728x90", "https://cdn.example/fs.png"))
    crm.affiliates.add_asset(MarketingAsset("AS-2", "discount_code", "Gold-only 15% code", "GOLD15", "Gold"))
    crm.affiliates.distribute_asset("A-1", "AS-1", "Bronze")

    # Orders: C-1 is a loyal apparel buyer referred by A-1; V-200 ships late and gets poor reviews.
    crm.affiliates.track_click("RIYA10", "P-1", "fp-asha", t0)
    plan = [
        ("O-1", "C-1", "V-100", "P-1", "apparel", "2500", 0, "RIYA10", False, False),
        ("O-2", "C-1", "V-100", "P-2", "apparel", "18000", 5, None, False, False),
        ("O-3", "C-1", "V-100", "P-3", "apparel", "1800", 30, None, False, False),
        ("O-4", "C-2", "V-200", "P-4", "electronics", "3500", 10, None, True, True),
        ("O-5", "C-2", "V-200", "P-5", "electronics", "900", 12, None, True, False),
    ]
    for oid, cid, vid, pid, cat, amt, day, ref, late, ret in plan:
        placed = t0 + timedelta(days=day)
        crm.place_order(Order(oid, cid, vid, pid, cat, Decimal(amt), placed, referral_code=ref))
        crm.ship_order(oid, late=late, now=placed + timedelta(days=1))
        crm.deliver_order(oid, now=placed + timedelta(days=3))
        if ret:
            crm.return_order(oid, now=placed + timedelta(days=6))

    for score in (3, 2, 4, 2):
        crm.vendors.add_review("V-200", score, now - timedelta(days=5))
    crm.vendors.add_review("V-100", 5, now - timedelta(days=5))

    crm.submit_ticket(SupportTicket("T-1", "C-2", "Earbuds broken, want refund",
                                    "Terrible quality, arrived damaged and late. Unacceptable!!", now, "O-4"), now)
    crm.submit_ticket(SupportTicket("T-2", "C-1", "Delivery question", "Thanks! When will my order ship?", now), now)
    crm.submit_ticket(SupportTicket("T-3", "C-3", "Unauthorized charge",
                                    "I see an unauthorized payment on my card, account may be hacked", now), now)
    crm.customers.request_refund("O-2", Decimal("18000"), now)
    return crm


def reports(crm: CRMEngine, now: datetime) -> dict[str, object]:
    period = (now - timedelta(days=60), now)
    return {
        "admin": crm.global_analytics(Role.ADMIN, now),
        "support": {
            "queue": crm.support_queue(Role.SUPPORT_AGENT),
            "customer_C-1": crm.customer_profile(Role.SUPPORT_AGENT, "C-1", now),
            "abandoned_carts": crm.customers.abandoned_carts(now),
        },
        "marketing": {"customer_C-1": crm.customer_profile(Role.MARKETING, "C-1", now)},
        "finance": {
            "vendor_payouts": crm.vendor_payout_report(Role.FINANCE, now),
            "affiliate_payouts": crm.affiliate_payout_report(Role.FINANCE, *period, now),
        },
        "vendor": {v: crm.vendor_scorecard(Role.VENDOR_MANAGER, v) for v in crm.store.vendors},
        "tasks": {team: crm.task_queue(Role.ADMIN, team)
                  for team in ("customer_support", "trust_and_safety", "finance", "vendor_success", "affiliate_ops")},
    }


def main(argv: list[str]) -> int:
    now = datetime(2026, 10, 1, 12, 0)
    crm = seed(now)
    out = reports(crm, now)
    if argv:
        out = {k: out[k] for k in argv}
    print(json.dumps(out, indent=2, default=str))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
