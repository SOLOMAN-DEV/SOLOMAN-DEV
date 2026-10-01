import unittest
from datetime import datetime, timedelta

try:
    from fastapi.testclient import TestClient
except ImportError:  # API extras not installed
    TestClient = None

from sabar_mart_crm import config
from sabar_mart_crm.rbac import Role

KEYS = {
    "k-system": Role.SYSTEM,
    "k-admin": Role.ADMIN,
    "k-support": Role.SUPPORT_AGENT,
    "k-finance": Role.FINANCE,
    "k-vendor": Role.VENDOR_MANAGER,
    "k-affiliate": Role.AFFILIATE_MANAGER,
    "k-marketing": Role.MARKETING,
}


class Clock:
    def __init__(self) -> None:
        self.now = datetime(2026, 10, 1, 12, 0)

    def __call__(self) -> datetime:
        return self.now

    def advance(self, **kw) -> None:
        self.now += timedelta(**kw)


def auth(key: str) -> dict[str, str]:
    return {"Authorization": f"Bearer {key}"}


@unittest.skipIf(TestClient is None, "fastapi not installed")
class APITests(unittest.TestCase):
    def setUp(self):
        from sabar_mart_crm.api import create_app

        self.clock = Clock()
        self.client = TestClient(create_app(api_keys=KEYS, clock=self.clock))

    def call(self, method, path, key, expect=200, **kw):
        res = self.client.request(method, path, headers=auth(key), **kw)
        self.assertEqual(res.status_code, expect, res.text)
        return res.json() if res.content else None

    def seed_vendor(self, vid="V-1", verified=True):
        self.call("POST", "/vendors", "k-vendor", 201,
                  json={"vendor_id": vid, "store_name": "Kiran Textiles", "contact_email": "ops@kiran.example"})
        if verified:
            for doc in config.REQUIRED_VENDOR_DOCUMENTS:
                self.call("PUT", f"/vendors/{vid}/documents/{doc}", "k-vendor", 204)
                self.call("POST", f"/vendors/{vid}/documents/{doc}/review", "k-vendor", 204, json={"approved": True})
        self.call("POST", "/products", "k-system", 201,
                  json={"product_id": "P-1", "vendor_id": vid, "name": "Kurta", "category": "apparel",
                        "gst_rate": "0.05"})

    def seed_customer(self, cid="C-1", email="asha@example.com"):
        self.call("POST", "/customers", "k-system", 201,
                  json={"customer_id": cid, "name": "Asha", "email": email, "phone": "9876500001"})

    def complete_order(self, oid, amount="1000.00", ref=None):
        self.call("POST", "/orders", "k-system", 201,
                  json={"order_id": oid, "customer_id": "C-1", "product_id": "P-1", "amount": amount,
                        "referral_code": ref})
        self.call("POST", f"/orders/{oid}/ship", "k-system", 204, json={"late": False})
        return self.call("POST", f"/orders/{oid}/deliver", "k-system")

    # --- auth ------------------------------------------------------------------

    def test_auth_required(self):
        self.assertEqual(self.client.get("/health").status_code, 200)
        self.assertEqual(self.client.get("/analytics").status_code, 401)
        self.assertEqual(self.client.get("/analytics", headers=auth("wrong")).status_code, 401)
        self.assertEqual(self.call("GET", "/me", "k-finance")["role"], "finance")

    def test_rbac_enforced(self):
        self.seed_vendor()
        self.seed_customer()
        self.call("GET", "/analytics", "k-finance", 403)
        self.call("GET", "/vendors/payouts", "k-support", 403)
        self.call("GET", "/tickets", "k-finance", 403)
        self.call("POST", "/customers", "k-marketing", 403,
                  json={"customer_id": "C-2", "name": "X", "email": "x@example.com", "phone": "12345"})
        self.call("GET", "/tasks", "k-finance", 403, params={"team": "vendor_success"})
        # Permission is checked before existence, so unauthorized callers cannot probe IDs.
        self.call("GET", "/customers/NOPE", "k-finance", 403)
        self.call("GET", "/customers/NOPE", "k-support", 404)

    def test_pii_masking(self):
        self.seed_customer()
        self.assertEqual(self.call("GET", "/customers/C-1", "k-support")["email"], "asha@example.com")
        self.assertEqual(self.call("GET", "/customers/C-1", "k-marketing")["email"], "a***@example.com")

    # --- validation ------------------------------------------------------------

    def test_input_validation_and_conflicts(self):
        self.seed_vendor()
        self.seed_customer()
        self.call("POST", "/customers", "k-system", 409,
                  json={"customer_id": "C-1", "name": "A", "email": "a@example.com", "phone": "12345"})
        self.call("POST", "/customers", "k-system", 422,
                  json={"customer_id": "C-2", "name": "A", "email": "not-an-email", "phone": "12345"})
        self.call("POST", "/orders", "k-system", 422,
                  json={"order_id": "O-1", "customer_id": "C-1", "product_id": "P-1", "amount": "-5"})
        self.call("POST", "/orders", "k-system", 404,
                  json={"order_id": "O-1", "customer_id": "C-1", "product_id": "P-404", "amount": "5"})
        self.call("POST", "/vendors/V-1/reviews", "k-system", 422, json={"score": 7})
        self.call("POST", "/products", "k-system", 422,  # GST rate is mandatory
                  json={"product_id": "P-9", "vendor_id": "V-1", "name": "X", "category": "c"})
        self.call("PUT", "/vendors/V-1/documents/passport_selfie", "k-vendor", 409)

    def test_order_state_machine(self):
        self.seed_vendor()
        self.seed_customer()
        self.call("POST", "/orders", "k-system", 201,
                  json={"order_id": "O-1", "customer_id": "C-1", "product_id": "P-1", "amount": "100"})
        self.call("POST", "/orders/O-1/deliver", "k-system", 409)  # not shipped yet
        self.call("POST", "/orders/O-1/cancel", "k-system", 204)
        self.call("POST", "/orders/O-1/ship", "k-system", 409, json={})

    # --- end-to-end flows -------------------------------------------------------

    def test_order_to_vendor_payout(self):
        self.seed_vendor()
        self.seed_customer()
        self.complete_order("O-1", "2000.00")
        ledger = self.call("GET", "/vendors/V-1/ledger", "k-finance")
        self.assertEqual((ledger["payable_now"], ledger["held_in_return_window"]), ("0.00", "1752.58"))
        self.clock.advance(days=config.RETURN_WINDOW_DAYS + 1)
        self.call("POST", "/orders/O-1/return", "k-system", 409)  # window closed
        payout = self.call("POST", "/vendors/V-1/payouts", "k-finance")
        self.assertEqual((payout["status"], payout["amount"]), ("paid", "1752.58"))
        self.call("POST", "/vendors/V-1/payouts", "k-vendor", 403)

    def test_tickets_refunds_and_tasks(self):
        self.seed_vendor()
        self.seed_customer()
        self.complete_order("O-1", "15000.00")
        routed = self.call("POST", "/tickets", "k-system", 201,
                           json={"ticket_id": "T-1", "customer_id": "C-1", "subject": "Unauthorized charge",
                                 "body": "My account was hacked", "order_id": "O-1"})
        self.assertEqual(routed["priority"], "critical")
        self.assertEqual(self.call("GET", "/tickets", "k-support")[0]["ticket_id"], "T-1")

        refund = self.call("POST", "/orders/O-1/refunds", "k-support", 201,
                           json={"amount": "15000", "reason": "damaged on arrival"})
        self.assertEqual(refund["status"], "pending_review")
        tasks = self.call("GET", "/tasks", "k-finance", params={"team": "finance"})
        self.assertEqual(tasks[0]["rule"], "refund_exceeds_limit")
        self.call("POST", f"/tasks/{tasks[0]['task_id']}/resolve", "k-support", 403)  # wrong team
        self.call("POST", f"/refunds/{refund['refund_id']}/approve", "k-support", 403)
        approved = self.call("POST", f"/refunds/{refund['refund_id']}/approve", "k-finance")
        self.assertEqual((approved["status"], approved["decided_by"]), ("approved", "env-finance-4"))
        self.assertEqual(self.call("GET", "/tasks", "k-finance", params={"team": "finance"}), [])
        self.assertEqual(self.call("GET", "/vendors/V-1/ledger", "k-finance")["refunds"], "15000.00")

    def test_low_reviews_flag_vendor(self):
        self.seed_vendor()
        for score in (3, 2):
            self.call("POST", "/vendors/V-1/reviews", "k-system", 204, json={"score": score})
        self.assertTrue(self.call("GET", "/vendors/V-1", "k-vendor")["flagged"])
        tasks = self.call("GET", "/tasks", "k-vendor", params={"team": "vendor_success"})
        self.assertEqual(tasks[0]["rule"], "vendor_low_review_score")

    def test_affiliate_flow(self):
        self.seed_vendor()
        self.seed_customer()
        self.call("POST", "/affiliates", "k-affiliate", 201,
                  json={"affiliate_id": "A-1", "name": "Riya", "email": "riya@example.com", "referral_code": "RIYA10"})
        click = {"referral_code": "RIYA10", "product_id": "P-1", "visitor_fingerprint": "fp"}
        self.assertEqual(self.call("POST", "/referrals/clicks", "k-system", json=click)["reason"],
                         "affiliate_not_approved")
        self.call("POST", "/affiliates/A-1/approve", "k-affiliate", 204)
        self.assertTrue(self.call("POST", "/referrals/clicks", "k-system", json=click)["valid"])
        self.complete_order("O-1", "1000.00", ref="RIYA10")

        stmt = self.call("GET", "/affiliates/A-1/commission", "k-finance")
        self.assertEqual((stmt["commission_payable"], stmt["pending_orders"]), ("0.00", ["O-1"]))
        self.clock.advance(days=config.RETURN_WINDOW_DAYS + 1)
        stmt = self.call("GET", "/affiliates/A-1/commission", "k-finance")
        self.assertEqual(stmt["commission_payable"], "30.00")

        self.call("POST", "/affiliate-assets", "k-affiliate", 201,
                  json={"asset_id": "AS-1", "kind": "discount_code", "title": "Gold", "payload": "GOLD15",
                        "restricted_to_tier": "Gold"})
        self.call("POST", "/affiliates/A-1/assets/AS-1", "k-affiliate", 409)  # Bronze affiliate
        self.assertEqual(self.call("GET", "/affiliates/A-1", "k-marketing")["email"], "r***@example.com")

    def test_users_and_audit(self):
        created = self.call("POST", "/users", "k-admin", 201, json={"username": "priya", "role": "finance"})
        key = created["api_key"]
        self.call("POST", "/users", "k-admin", 409, json={"username": "priya", "role": "finance"})
        self.call("POST", "/users", "k-finance", 403, json={"username": "eve", "role": "admin"})
        self.assertNotIn("key_hash", self.call("GET", "/users", "k-admin")[0])
        me = self.call("GET", "/me", key)
        self.assertEqual((me["user"], me["role"]), ("priya", "finance"))

        self.seed_vendor()
        self.call("GET", "/vendors/V-1/ledger", key)          # sensitive read: audited
        self.call("GET", "/vendors/V-1", key)                 # ordinary read: not audited
        self.call("GET", "/analytics", key, 403)              # denied: audited

        rotated = self.call("POST", "/users/priya/rotate-key", "k-admin")["api_key"]
        self.assertEqual(self.client.get("/me", headers=auth(key)).status_code, 401)
        self.call("POST", "/users/priya/deactivate", "k-admin")
        self.assertEqual(self.client.get("/me", headers=auth(rotated)).status_code, 401)

        log = self.call("GET", "/audit", "k-admin", params={"actor": "priya"})
        self.assertEqual([(e["method"], e["path"], e["outcome"]) for e in log],
                         [("GET", "/analytics", "denied"), ("GET", "/vendors/V-1/ledger", "success")])
        recent = self.call("GET", "/audit", "k-admin", params={"limit": 3})
        self.assertEqual(recent[0]["path"], "/audit")  # reading the audit log is itself audited
        self.assertEqual((recent[1]["path"], recent[1]["actor"]), ("/users/priya/deactivate", "env-admin-2"))
        self.call("GET", "/audit", "k-finance", 403)

    def test_failed_change_is_audited(self):
        self.seed_customer()
        self.call("POST", "/orders/NOPE/ship", "k-system", 404, json={})
        log = self.call("GET", "/audit", "k-admin", params={"limit": 2})
        self.assertEqual((log[0]["path"], log[0]["outcome"]), ("/orders/NOPE/ship", "not_found"))

    def test_affiliate_payout_api(self):
        self.seed_vendor()
        self.seed_customer()
        self.call("POST", "/affiliates", "k-affiliate", 201,
                  json={"affiliate_id": "A-1", "name": "Riya", "email": "riya@example.com", "referral_code": "RIYA10"})
        self.call("POST", "/affiliates/A-1/approve", "k-affiliate", 204)
        self.call("POST", "/referrals/clicks", "k-system",
                  json={"referral_code": "RIYA10", "product_id": "P-1", "visitor_fingerprint": "fp"})
        self.complete_order("O-1", "1000.00", ref="RIYA10")
        self.clock.advance(days=config.RETURN_WINDOW_DAYS + 1)
        self.assertEqual(self.call("GET", "/affiliates/A-1/payout-preview", "k-finance")["amount"], "30.00")
        self.call("POST", "/affiliates/A-1/payouts", "k-affiliate", 403)
        paid = self.call("POST", "/affiliates/A-1/payouts", "k-finance")
        self.assertEqual((paid["status"], paid["amount"]), ("paid", "30.00"))
        self.assertEqual(self.call("POST", "/affiliates/A-1/payouts", "k-finance")["status"], "nothing_payable")
        history = self.call("GET", "/affiliates/A-1/payouts", "k-finance")
        self.assertEqual([(p["amount"], p["run_by"]) for p in history], [("30.00", "env-finance-4")])
        tax = self.call("GET", "/finance/tax-report", "k-finance")
        self.assertEqual(tax["totals"]["tcs_collected"], "4.76")

    def test_refund_listing_and_validation(self):
        self.seed_vendor()
        self.seed_customer()
        self.complete_order("O-1", "500.00")
        self.call("POST", "/orders/O-1/refunds", "k-support", 409, json={"amount": "600", "reason": "too much"})
        r = self.call("POST", "/orders/O-1/refunds", "k-support", 201, json={"amount": "100", "reason": "scuffed"})
        self.assertEqual((r["status"], r["decided_by"], r["requested_by"]), ("approved", "auto", "env-support_agent-3"))
        listed = self.call("GET", "/refunds", "k-finance", params={"status": "approved"})
        self.assertEqual([x["refund_id"] for x in listed], [r["refund_id"]])
        self.call("GET", "/refunds", "k-marketing", 403)

    def test_analytics(self):
        self.seed_vendor()
        self.seed_customer()
        self.complete_order("O-1")
        data = self.call("GET", "/analytics", "k-admin")
        self.assertEqual(data["revenue"]["gmv_delivered_net_of_refunds"], "1000.00")
        self.assertEqual(data["revenue"]["tcs_withheld"], "4.76")  # 0.5% of 1000 / 1.05
        self.assertEqual(data["vendors"]["verified"], 1)


if __name__ == "__main__":
    unittest.main()
