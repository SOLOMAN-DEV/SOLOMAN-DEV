import json
import unittest
from datetime import datetime, timedelta
from decimal import Decimal

from sabar_mart_crm.models import Affiliate, Order, SupportTicket

from test_finance import NOW, deliver, make


class PrivacyTests(unittest.TestCase):
    def setUp(self):
        self.crm = make()
        deliver(self.crm, "O-1", "1000.00", NOW - timedelta(days=30))
        self.crm.submit_ticket(SupportTicket("T-1", "C-1", "Where is my parcel", "Call me on 98765", NOW, "O-1"), NOW)
        self.crm.request_refund("O-1", Decimal("100.00"), "box crushed, call 98765", "agent", NOW)

    def test_export_contains_everything_about_the_customer(self):
        data = self.crm.privacy.export_customer("C-1", NOW)
        self.assertEqual(data["profile"]["email"], "asha@example.com")
        self.assertEqual([o["order_id"] for o in data["orders"]], ["O-1"])
        self.assertEqual(data["support_tickets"][0]["body"], "Call me on 98765")
        self.assertEqual(data["refunds"][0]["amount"], "100.00")
        self.assertEqual(data["consent"]["marketing"], False)

    def test_erasure_anonymises_but_keeps_tax_records(self):
        balance = self.crm.vendors.ledger_summary("V-1", NOW)
        result = self.crm.privacy.erase_customer("C-1", "dpo", NOW)
        self.assertEqual(result["retained"]["orders"], 1)
        dump = json.dumps(self.crm.privacy.export_customer("C-1", NOW))
        for personal in ("Asha", "asha@example.com", "98765"):
            self.assertNotIn(personal, dump)
        self.assertEqual(self.crm.store.orders["O-1"].amount, Decimal("1000.00"))
        self.assertEqual(self.crm.vendors.ledger_summary("V-1", NOW), balance)  # money untouched
        with self.assertRaises(ValueError):
            self.crm.privacy.erase_customer("C-1", "dpo", NOW)  # only once
        with self.assertRaises(ValueError):
            self.crm.privacy.set_marketing_consent("C-1", True, "web", NOW)

    def test_erasure_waits_for_open_obligations(self):
        self.crm.place_order(Order("O-2", "C-1", "V-1", "P-1", "apparel", Decimal("50"), NOW))
        with self.assertRaisesRegex(ValueError, "open orders"):
            self.crm.privacy.erase_customer("C-1", "dpo", NOW)
        self.crm.cancel_order("O-2", NOW)
        self.crm.request_refund("O-1", Decimal("800.00"), "x", "agent", NOW)
        self.crm.privacy.erase_customer("C-1", "dpo", NOW)  # refund under the limit was applied: nothing pending

    def test_consent_gates_marketing(self):
        self.crm.customers.update_cart("C-1", "P-1", Decimal("500"), NOW - timedelta(days=2))
        self.assertEqual(self.crm.customers.abandoned_carts(NOW), [])
        self.assertEqual(len(self.crm.customers.abandoned_carts(NOW, consented_only=False)), 1)
        self.crm.privacy.set_marketing_consent("C-1", True, "account_settings", NOW)
        self.assertEqual(self.crm.customers.abandoned_carts(NOW)[0]["customer_id"], "C-1")

    def test_affiliate_erasure(self):
        aff = self.crm.affiliates
        aff.register(Affiliate("A-1", "Riya Sharma", "riya@example.com", "RIYA10"))
        aff.approve("A-1")
        aff.track_click("RIYA10", "P-1", "fp", NOW - timedelta(days=31))
        deliver(self.crm, "O-9", "1000.00", NOW - timedelta(days=30), ref="RIYA10")
        with self.assertRaisesRegex(ValueError, "still owed"):
            self.crm.privacy.erase_affiliate("A-1", "dpo", NOW)
        aff.run_payout("A-1", "fin", NOW)
        self.crm.privacy.erase_affiliate("A-1", "dpo", NOW)
        dump = json.dumps(self.crm.privacy.export_affiliate("A-1", NOW))
        self.assertNotIn("Riya", dump)
        self.assertNotIn("RIYA10", json.dumps([vars(c) for c in self.crm.store.clicks.values()], default=str))
        self.assertEqual(self.crm.store.orders["O-9"].referral_code, self.crm.store.affiliates["A-1"].referral_code)
        self.assertEqual(aff.track_click("RIYA10", "P-1", "fp2", NOW)["reason"], "unknown_referral_code")


if __name__ == "__main__":
    unittest.main()
