import unittest
from datetime import datetime, timedelta
from decimal import Decimal

from sabar_mart_crm import AccessDenied, CRMEngine, Product, Role
from sabar_mart_crm import config
from sabar_mart_crm.affiliates import tier_for
from sabar_mart_crm.models import (
    Affiliate,
    Customer,
    LifecycleStage,
    MarketingAsset,
    Order,
    Priority,
    SupportTicket,
    VerificationStatus,
    Vendor,
)

NOW = datetime(2026, 10, 1, 12, 0)


def make_engine() -> CRMEngine:
    crm = CRMEngine()
    crm.vendors.onboard(Vendor("V-1", "Good Store", "ops@good.example"))
    for doc in config.REQUIRED_VENDOR_DOCUMENTS:
        crm.vendors.submit_document("V-1", doc)
        crm.vendors.review_document("V-1", doc, approved=True)
    crm.store.products["P-1"] = Product("P-1", "V-1", "Kurta", "apparel")
    crm.store.products["P-2"] = Product("P-2", "V-1", "Saree", "apparel")
    crm.store.products["P-3"] = Product("P-3", "V-1", "Lamp", "home")
    crm.customers.register(Customer("C-1", "Asha", "asha@example.com", "9876500001", NOW - timedelta(days=60)))
    return crm


def full_order(crm, oid, amount, placed, product="P-1", customer="C-1", ref=None, late=False):
    crm.place_order(Order(oid, customer, "V-1", product, "apparel", Decimal(amount), placed, referral_code=ref))
    crm.ship_order(oid, late=late, now=placed + timedelta(days=1))
    crm.deliver_order(oid, now=placed + timedelta(days=2))


class CustomerTests(unittest.TestCase):
    def test_lifecycle_progression(self):
        crm = make_engine()
        self.assertEqual(crm.customers.lifecycle_stage("C-1", NOW), LifecycleStage.SIGNED_UP)
        crm.customers.record_browse("C-1", "apparel")
        self.assertEqual(crm.customers.lifecycle_stage("C-1", NOW), LifecycleStage.BROWSING)
        crm.customers.update_cart("C-1", "P-1", Decimal("500"), NOW - timedelta(hours=25))
        self.assertEqual(crm.customers.lifecycle_stage("C-1", NOW), LifecycleStage.CART_ABANDONED)
        full_order(crm, "O-1", "500", NOW - timedelta(days=10))
        self.assertEqual(crm.customers.lifecycle_stage("C-1", NOW), LifecycleStage.FIRST_PURCHASE)
        full_order(crm, "O-2", "500", NOW - timedelta(days=5), product="P-2")
        self.assertEqual(crm.customers.lifecycle_stage("C-1", NOW), LifecycleStage.REPEAT)
        full_order(crm, "O-3", "500", NOW - timedelta(days=4), product="P-3")
        self.assertEqual(crm.customers.lifecycle_stage("C-1", NOW), LifecycleStage.LOYAL)
        self.assertEqual(crm.customers.lifecycle_stage("C-1", NOW + timedelta(days=120)), LifecycleStage.AT_RISK)

    def test_loyalty_milestone_fires_once(self):
        crm = make_engine()
        crm.place_order(Order("O-1", "C-1", "V-1", "P-1", "apparel", Decimal("12000"), NOW))
        crm.ship_order("O-1", late=False, now=NOW)
        result = crm.deliver_order("O-1", now=NOW)
        self.assertEqual([t["milestone_points"] for t in result["loyalty_triggers"]], [100])
        crm.return_order("O-1", now=NOW + timedelta(days=1))
        self.assertEqual(crm.store.customers["C-1"].loyalty_points, 0)

    def test_ticket_routing(self):
        crm = make_engine()
        t = crm.submit_ticket(SupportTicket("T-1", "C-1", "Refund please",
                                            "Terrible, broken and damaged item. Unacceptable!", NOW), NOW)
        self.assertEqual(t.assigned_team, "returns_and_refunds")
        self.assertEqual(t.priority, Priority.HIGH)
        t = crm.submit_ticket(SupportTicket("T-2", "C-1", "Account hacked", "Someone placed orders", NOW), NOW)
        self.assertEqual(t.priority, Priority.CRITICAL)
        self.assertEqual(t.assigned_team, "trust_and_safety")
        self.assertEqual(len(crm.escalations.queue_for("trust_and_safety")), 1)

    def test_refund_over_limit_escalates_to_finance(self):
        crm = make_engine()
        full_order(crm, "O-1", "15000", NOW - timedelta(days=3))
        self.assertEqual(crm.request_refund("O-1", Decimal("500"), "late", "agent", NOW).status.value, "approved")
        res = crm.request_refund("O-1", Decimal("12000"), "damaged", "agent", NOW)
        self.assertEqual(res.status.value, "pending_review")
        self.assertEqual(crm.escalations.queue_for("finance")[0].rule, "refund_exceeds_limit")

    def test_recommendations_use_affinity_and_skip_purchased(self):
        crm = make_engine()
        full_order(crm, "O-1", "500", NOW - timedelta(days=3))
        recs = crm.customers.recommendations("C-1")
        self.assertEqual(recs[0]["product_id"], "P-2")
        self.assertNotIn("P-1", [r["product_id"] for r in recs])


class VendorTests(unittest.TestCase):
    def test_onboarding_status(self):
        crm = make_engine()
        crm.vendors.onboard(Vendor("V-2", "New", "new@example"))
        crm.vendors.submit_document("V-2", "tax_id")
        status = crm.vendors.onboarding_status("V-2")
        self.assertEqual(status["verification_status"], "under_review")
        self.assertFalse(status["can_go_live"])
        crm.vendors.review_document("V-2", "tax_id", approved=False)
        self.assertEqual(crm.store.vendors["V-2"].verification_status, VerificationStatus.REJECTED)
        self.assertIn("resubmit:tax_id", crm.vendors.onboarding_status("V-2")["next_actions"])

    def test_low_review_score_flags_vendor(self):
        crm = make_engine()
        crm.vendors.add_review("V-1", 4, NOW)
        self.assertFalse(crm.store.vendors["V-1"].is_flagged)
        crm.vendors.add_review("V-1", 2, NOW)  # avg 3.0
        self.assertTrue(crm.store.vendors["V-1"].is_flagged)
        crm.vendors.add_review("V-1", 1, NOW)
        tasks = crm.escalations.queue_for("vendor_success")
        self.assertEqual([t.rule for t in tasks], ["vendor_low_review_score"])  # de-duplicated

    def test_ledger_and_payout(self):
        crm = make_engine()
        full_order(crm, "O-1", "1000", NOW - timedelta(days=30))
        full_order(crm, "O-2", "2000", NOW - timedelta(days=3), product="P-2")
        summary = crm.vendors.ledger_summary("V-1", NOW)
        self.assertEqual(summary["gross_sales"], Decimal("3000.00"))
        self.assertEqual(summary["commission_deducted"], Decimal("300.00"))
        self.assertEqual(summary["gst_on_commission"], Decimal("54.00"))
        self.assertEqual(summary["tcs_withheld"], Decimal("15.00"))
        self.assertEqual(summary["tds_withheld"], Decimal("3.00"))
        self.assertEqual(summary["held_in_return_window"], Decimal("1752.00"))
        self.assertEqual(summary["payable_now"], Decimal("876.00"))
        payout = crm.vendors.run_payout("V-1", NOW)
        self.assertEqual((payout["status"], payout["amount"]), ("paid", Decimal("876.00")))
        self.assertEqual(crm.vendors.run_payout("V-1", NOW + timedelta(days=1))["status"], "not_due")

    def test_return_reverses_sale_and_commission(self):
        crm = make_engine()
        full_order(crm, "O-1", "1000", NOW - timedelta(days=3))
        crm.return_order("O-1", NOW)
        self.assertEqual(crm.vendors.ledger_summary("V-1", NOW)["outstanding_balance"], Decimal("0.00"))

    def test_return_after_window_rejected(self):
        crm = make_engine()
        full_order(crm, "O-1", "1000", NOW - timedelta(days=30))
        with self.assertRaises(ValueError):
            crm.return_order("O-1", NOW)

    def test_unverified_vendor_payout_blocked(self):
        crm = make_engine()
        crm.vendors.onboard(Vendor("V-2", "New", "new@example"))
        self.assertEqual(crm.vendors.run_payout("V-2", NOW)["status"], "blocked")


class AffiliateTests(unittest.TestCase):
    def setUp(self):
        self.crm = make_engine()
        self.crm.affiliates.register(Affiliate("A-1", "Riya", "riya@example.com", "RIYA10"))

    def test_click_validation(self):
        aff = self.crm.affiliates
        self.assertEqual(aff.track_click("RIYA10", "P-1", "fp", NOW)["reason"], "affiliate_not_approved")
        aff.approve("A-1")
        self.assertTrue(aff.track_click("RIYA10", "P-1", "fp", NOW)["valid"])
        self.assertEqual(aff.track_click("RIYA10", "P-1", "fp", NOW)["reason"], "duplicate_click")
        self.assertEqual(aff.track_click("NOPE", "P-1", "fp", NOW)["reason"], "unknown_referral_code")

    def test_commission_only_after_return_window(self):
        aff = self.crm.affiliates
        aff.approve("A-1")
        aff.track_click("RIYA10", "P-1", "fp", NOW - timedelta(days=31))
        aff.track_click("RIYA10", "P-2", "fp", NOW - timedelta(days=6))
        full_order(self.crm, "O-1", "1000", NOW - timedelta(days=30), ref="RIYA10")
        full_order(self.crm, "O-2", "2000", NOW - timedelta(days=5), product="P-2", ref="RIYA10")
        stmt = aff.commission_statement("A-1", NOW - timedelta(days=60), NOW, NOW)
        self.assertEqual(stmt["completed_orders"], 1)
        self.assertEqual(stmt["commission_payable"], Decimal("30.00"))
        self.assertEqual(stmt["pending_orders"], ["O-2"])

    def test_self_referral_blocked(self):
        aff = self.crm.affiliates
        aff.approve("A-1")
        self.crm.customers.register(Customer("C-9", "Riya", "RIYA@example.com", "1", NOW))
        aff.track_click("RIYA10", "P-1", "fp", NOW - timedelta(hours=1))
        res = self.crm.place_order(Order("O-9", "C-9", "V-1", "P-1", "apparel", Decimal("100"), NOW,
                                         referral_code="RIYA10"))
        self.assertEqual(res["affiliate_attribution"]["reason"], "self_referral")
        self.assertEqual(self.crm.escalations.queue_for("affiliate_ops")[0].rule, "affiliate_self_referral")

    def test_tiers_and_asset_gating(self):
        self.assertEqual(tier_for(0)[0], "Bronze")
        self.assertEqual(tier_for(100), ("Gold", Decimal("0.07")))
        aff = self.crm.affiliates
        aff.approve("A-1")
        aff.add_asset(MarketingAsset("AS-1", "discount_code", "Gold code", "GOLD15", "Gold"))
        self.assertFalse(aff.distribute_asset("A-1", "AS-1", "Silver")["distributed"])
        self.assertTrue(aff.distribute_asset("A-1", "AS-1", "Platinum")["distributed"])


class RBACTests(unittest.TestCase):
    def test_role_segmentation(self):
        crm = make_engine()
        with self.assertRaises(AccessDenied):
            crm.vendor_payout_report(Role.SUPPORT_AGENT, NOW)
        with self.assertRaises(AccessDenied):
            crm.support_queue(Role.FINANCE)
        with self.assertRaises(AccessDenied):
            crm.global_analytics(Role.FINANCE, NOW)
        with self.assertRaises(AccessDenied):
            crm.task_queue(Role.FINANCE, "vendor_success")
        crm.vendor_payout_report(Role.FINANCE, NOW)
        crm.global_analytics(Role.ADMIN, NOW)

    def test_pii_masked_for_marketing(self):
        crm = make_engine()
        masked = crm.customer_profile(Role.MARKETING, "C-1", NOW)
        self.assertEqual(masked["email"], "a***@example.com")
        self.assertTrue(masked["phone"].endswith("01") and "98765" not in masked["phone"])
        self.assertEqual(crm.customer_profile(Role.SUPPORT_AGENT, "C-1", NOW)["email"], "asha@example.com")


class DemoTests(unittest.TestCase):
    def test_demo_runs(self):
        from sabar_mart_crm.__main__ import reports, seed
        out = reports(seed(NOW), NOW)
        self.assertEqual(out["admin"]["vendors"]["flagged"], ["V-200"])


if __name__ == "__main__":
    unittest.main()
