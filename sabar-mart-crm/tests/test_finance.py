import unittest
from datetime import datetime, timedelta
from decimal import Decimal

from sabar_mart_crm import CRMEngine, Product, config
from sabar_mart_crm.models import Affiliate, Customer, Order, OrderStatus, RefundStatus, Vendor

NOW = datetime(2026, 10, 1, 12, 0)
D = Decimal


def make(pan=True) -> CRMEngine:
    crm = CRMEngine()
    crm.vendors.onboard(Vendor("V-1", "Store", "ops@store.example"))
    for doc in config.REQUIRED_VENDOR_DOCUMENTS:
        if doc == "pan_card" and not pan:
            continue
        crm.vendors.submit_document("V-1", doc)
        crm.vendors.review_document("V-1", doc, approved=True)
    crm.store.products["P-1"] = Product("P-1", "V-1", "Kurta", "apparel")
    crm.customers.register(Customer("C-1", "Asha", "asha@example.com", "98765", NOW - timedelta(days=90)))
    return crm


def deliver(crm, oid, amount, placed, ref=None):
    crm.place_order(Order(oid, "C-1", "V-1", "P-1", "apparel", D(amount), placed, referral_code=ref))
    crm.ship_order(oid, late=False, now=placed)
    crm.deliver_order(oid, now=placed + timedelta(days=1))


def kinds(crm, order_id):
    out: dict[str, Decimal] = {}
    for e in crm.store.ledger.values():
        if e.reference == order_id:
            out[e.kind] = out.get(e.kind, D(0)) + e.amount
    return out


class TaxTests(unittest.TestCase):
    def test_sale_posts_tax_components(self):
        crm = make()
        deliver(crm, "O-1", "1000.00", NOW - timedelta(days=30))
        # Price includes 18% GST: taxable value 1000 / 1.18 = 847.46 is the TCS/TDS base;
        # commission is charged on the GST-inclusive price.
        self.assertEqual(kinds(crm, "O-1"), {
            "sale": D("1000.00"), "commission": D("-100.00"), "commission_gst": D("-18.00"),
            "tcs": D("-4.24"), "tds": D("-0.85")})

    def test_gst_rate_is_taken_from_product_at_order_time(self):
        crm = make()
        crm.store.products["P-1"].gst_rate = D("0.05")
        deliver(crm, "O-1", "1050.00", NOW - timedelta(days=30))
        crm.store.products["P-1"].gst_rate = D("0.40")  # a later rate change does not touch past orders
        order = crm.store.orders["O-1"]
        self.assertEqual((order.gst_rate, order.taxable_value()), (D("0.05"), D("1000.00")))
        self.assertEqual(kinds(crm, "O-1")["tcs"], D("-5.00"))

    def test_zero_rated_product(self):
        crm = make()
        crm.store.products["P-1"].gst_rate = D("0")
        deliver(crm, "O-1", "1000.00", NOW - timedelta(days=30))
        self.assertEqual((kinds(crm, "O-1")["tcs"], kinds(crm, "O-1")["tds"]), (D("-5.00"), D("-1.00")))

    def test_higher_tds_without_verified_pan(self):
        crm = make(pan=False)
        deliver(crm, "O-1", "1000.00", NOW - timedelta(days=30))
        self.assertEqual(kinds(crm, "O-1")["tds"], D("-42.37"))  # 5% of 847.46

    def test_tax_report_nets_refunds(self):
        crm = make()
        deliver(crm, "O-1", "1000.00", NOW - timedelta(days=5))
        crm.request_refund("O-1", D("400.00"), "partial damage", "agent", NOW)
        report = crm.vendors.tax_report(NOW - timedelta(days=30), NOW + timedelta(days=1))
        totals = report["totals"]
        self.assertEqual((totals["gross_sales"], totals["refunds"], totals["net_sales_incl_gst"]),
                         (D("1000.00"), D("400.00"), D("600.00")))
        self.assertEqual((totals["net_taxable_value"], totals["gst_in_sales"]), (D("508.48"), D("91.52")))
        self.assertEqual((totals["tcs_collected"], totals["tds_deducted"]), (D("2.54"), D("0.51")))
        self.assertEqual(report["vendors"][0]["vendor_id"], "V-1")


class RefundTests(unittest.TestCase):
    def test_partial_refunds_reverse_exactly(self):
        crm = make()
        deliver(crm, "O-1", "999.99", NOW - timedelta(days=5))
        for amount in ("333.33", "333.33", "333.33"):
            crm.request_refund("O-1", D(amount), "goodwill", "agent", NOW)
        net = {k: v for k, v in kinds(crm, "O-1").items()}
        self.assertEqual(net["refund"], D("-999.99"))
        self.assertEqual(sum(net.values()), D("0.00"))
        for kind in ("commission", "commission_gst", "tcs", "tds"):
            self.assertEqual(net[kind] + net[f"{kind}_reversal"], D("0.00"), kind)
        self.assertEqual(crm.store.orders["O-1"].net_amount, D("0.00"))
        with self.assertRaises(ValueError):
            crm.request_refund("O-1", D("0.01"), "too much", "agent", NOW)

    def test_refund_reduces_vendor_balance_and_loyalty(self):
        crm = make()
        deliver(crm, "O-1", "1000.00", NOW - timedelta(days=30))
        self.assertEqual(crm.store.customers["C-1"].loyalty_points, 10)
        before = crm.vendors.ledger_summary("V-1", NOW)["outstanding_balance"]
        crm.request_refund("O-1", D("250.00"), "late delivery", "agent", NOW)
        after = crm.vendors.ledger_summary("V-1", NOW)["outstanding_balance"]
        self.assertEqual(before - after, D("219.23"))  # 250 less its share of commission, GST, TCS, TDS
        self.assertEqual(crm.store.customers["C-1"].loyalty_points, 7)  # points for 1000 (10) -> 750 (7)

    def test_large_refund_needs_finance_approval(self):
        crm = make()
        deliver(crm, "O-1", "20000.00", NOW - timedelta(days=3))
        refund = crm.request_refund("O-1", D("15000.00"), "wrong item", "agent", NOW)
        self.assertEqual(refund.status, RefundStatus.PENDING)
        self.assertEqual(crm.store.orders["O-1"].refunded, D("0.00"))  # nothing moves while pending
        with self.assertRaises(ValueError):
            crm.request_refund("O-1", D("6000.00"), "and more", "agent", NOW)  # pending counts as reserved
        with self.assertRaises(ValueError):
            crm.decide_refund(refund.refund_id, True, "agent", NOW)  # requester cannot approve own
        crm.decide_refund(refund.refund_id, True, "fin", NOW)
        self.assertEqual((refund.status, refund.decided_by), (RefundStatus.APPROVED, "fin"))
        self.assertEqual(crm.store.orders["O-1"].refunded, D("15000.00"))
        self.assertTrue(all(t.status.value == "resolved" for t in crm.store.tasks.values()))
        with self.assertRaises(ValueError):
            crm.decide_refund(refund.refund_id, False, "fin", NOW)  # already decided

    def test_rejected_refund_moves_no_money(self):
        crm = make()
        deliver(crm, "O-1", "20000.00", NOW - timedelta(days=3))
        refund = crm.request_refund("O-1", D("15000.00"), "wrong item", "agent", NOW)
        crm.decide_refund(refund.refund_id, False, "fin", NOW)
        self.assertEqual(refund.status, RefundStatus.REJECTED)
        self.assertNotIn("refund", kinds(crm, "O-1"))

    def test_refund_requires_delivered_order(self):
        crm = make()
        crm.place_order(Order("O-1", "C-1", "V-1", "P-1", "apparel", D("100"), NOW))
        with self.assertRaises(ValueError):
            crm.request_refund("O-1", D("50"), "not arrived", "agent", NOW)

    def test_return_after_partial_refund_refunds_remainder(self):
        crm = make()
        deliver(crm, "O-1", "20000.00", NOW - timedelta(days=3))
        crm.request_refund("O-1", D("1000.00"), "scratch", "agent", NOW)
        pending = crm.request_refund("O-1", D("12000.00"), "big claim", "agent", NOW)
        crm.return_order("O-1", NOW, actor="warehouse")
        order = crm.store.orders["O-1"]
        self.assertEqual((order.status, order.net_amount), (OrderStatus.RETURNED, D("0.00")))
        self.assertEqual(pending.status, RefundStatus.REJECTED)  # superseded by the return
        self.assertEqual(sum(kinds(crm, "O-1").values()), D("0.00"))


class AffiliatePayoutTests(unittest.TestCase):
    def setUp(self):
        self.crm = make()
        self.crm.affiliates.register(Affiliate("A-1", "Riya", "riya@example.com", "RIYA10"))
        self.crm.affiliates.approve("A-1")

    def referred(self, oid, amount, days_ago):
        placed = NOW - timedelta(days=days_ago)
        self.crm.affiliates.track_click("RIYA10", "P-1", f"fp-{oid}", placed - timedelta(minutes=5))
        deliver(self.crm, oid, amount, placed, ref="RIYA10")

    def test_paid_once_only(self):
        self.referred("O-1", "1000.00", 30)
        self.referred("O-2", "2000.00", 3)  # still inside the return window
        aff = self.crm.affiliates
        first = aff.run_payout("A-1", "fin", NOW)
        self.assertEqual((first["status"], first["amount"]), ("paid", D("30.00")))
        self.assertEqual(aff.run_payout("A-1", "fin", NOW)["status"], "nothing_payable")
        later = aff.run_payout("A-1", "fin", NOW + timedelta(days=20))
        self.assertEqual((later["amount"], [l["order_id"] for l in later["lines"]]), (D("60.00"), ["O-2"]))
        stmt = aff.commission_statement("A-1", datetime.min, datetime.max, NOW + timedelta(days=20))
        self.assertEqual((stmt["commission_paid"], stmt["commission_outstanding"]), (D("90.00"), D("0.00")))

    def test_refund_after_payout_is_clawed_back(self):
        self.referred("O-1", "1000.00", 30)
        aff = self.crm.affiliates
        aff.run_payout("A-1", "fin", NOW)
        self.crm.request_refund("O-1", D("500.00"), "goodwill", "agent", NOW)
        preview = aff.payout_preview("A-1", NOW)
        self.assertEqual((preview["amount"], preview["lines"][0]["kind"]), (D("-15.00"), "clawback"))
        self.assertEqual(aff.run_payout("A-1", "fin", NOW)["status"], "nothing_payable")  # carried forward
        self.referred("O-2", "2000.00", 20)
        payout = aff.run_payout("A-1", "fin", NOW)
        self.assertEqual(payout["amount"], D("45.00"))  # 60 new commission - 15 clawback
        self.assertEqual(self.crm.store.attributions["O-1"].commission_paid, D("15.00"))

    def test_rate_locked_at_first_payout(self):
        self.referred("O-1", "1000.00", 30)
        aff = self.crm.affiliates
        aff.run_payout("A-1", "fin", NOW)
        original = config.AFFILIATE_TIERS
        try:
            config.AFFILIATE_TIERS = (("Bronze", 0, D("0.20")),)  # rates change later
            self.assertEqual(aff.payout_preview("A-1", NOW)["lines"], [])
        finally:
            config.AFFILIATE_TIERS = original

    def test_unapproved_affiliate_blocked(self):
        self.crm.store.affiliates["A-1"].approved = False
        self.assertEqual(self.crm.affiliates.run_payout("A-1", "fin", NOW)["status"], "blocked")


if __name__ == "__main__":
    unittest.main()
