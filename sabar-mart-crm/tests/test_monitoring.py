import unittest

try:
    from fastapi.testclient import TestClient
except ImportError:
    TestClient = None

from sabar_mart_crm.monitoring import Alerter

from test_api import KEYS, Clock, auth


class RecordingAlerter(Alerter):
    def _deliver(self, subject, body):  # no network in tests
        pass


@unittest.skipIf(TestClient is None, "fastapi not installed")
class MonitoringTests(unittest.TestCase):
    def setUp(self):
        from sabar_mart_crm.api import create_app
        from sabar_mart_crm.models import Customer
        self.alerter = RecordingAlerter(webhook="https://chat.example/hook")
        self.app = create_app(api_keys=KEYS, clock=Clock(), alerter=self.alerter)
        self.client = TestClient(self.app, raise_server_exceptions=False)
        with self.app.state.backend.session() as crm:
            crm.customers.register(Customer("C-1", "Asha", "a@example.com", "98765", Clock()()))

    def test_crash_returns_error_id_and_alerts_once(self):
        crm = self.app.state.backend.engine
        crm.customers.lifecycle_stage = lambda *a: 1 / 0  # simulate a bug
        first = self.client.get("/customers/C-1", headers=auth("k-support"))
        self.assertEqual(first.status_code, 500)
        self.assertEqual(first.json()["detail"], "internal error")
        self.assertEqual(first.json()["error_id"], first.headers["X-Request-ID"])
        self.client.get("/customers/C-1", headers=auth("k-support"))  # same bug again
        self.assertEqual(len(self.alerter.sent), 1, "repeat alerts are rate-limited")
        subject, body = self.alerter.sent[0]
        self.assertIn("ZeroDivisionError on GET /customers/{customer_id}", subject)
        self.assertIn(first.json()["error_id"], body)
        self.assertIn("env-support_agent-3", body)

    def test_request_ids(self):
        res = self.client.get("/health")
        self.assertRegex(res.headers["X-Request-ID"], r"^[0-9a-f]{16}$")
        res = self.client.get("/health", headers={"X-Request-ID": "storefront-req-123"})
        self.assertEqual(res.headers["X-Request-ID"], "storefront-req-123")
        res = self.client.get("/health", headers={"X-Request-ID": "bad id with spaces"})
        self.assertNotEqual(res.headers["X-Request-ID"], "bad id with spaces")

    def test_deep_health(self):
        self.assertEqual(self.client.get("/health?deep=true").json()["status"], "ok")

    def test_deep_health_reports_database_down(self):
        from sabar_mart_crm.api import create_app
        from sabar_mart_crm.db import SQLBackend
        broken = SQLBackend("sqlite:////nonexistent-dir/crm.sqlite3")
        client = TestClient(create_app(api_keys=KEYS, backend=broken, alerter=self.alerter))
        res = client.get("/health?deep=true")
        self.assertEqual((res.status_code, res.json()["database"]), (503, "unreachable"))
        self.assertEqual(client.get("/health").status_code, 200)  # liveness still fine
        self.assertIn("database unreachable", self.alerter.sent[-1][0])

    def test_email_alert_is_sent_over_smtp_ssl(self):
        from unittest import mock
        alerter = Alerter(emails=["ops@sabarmart.com"], smtp_host="mail.sabarmart.com", smtp_port=465,
                          smtp_user="alerts@sabarmart.com", smtp_password="pw")
        with mock.patch("smtplib.SMTP_SSL") as smtp:
            self.assertTrue(alerter.send("k", "disk full", "details", wait=True))
        server = smtp.return_value
        server.login.assert_called_once_with("alerts@sabarmart.com", "pw")
        msg = server.send_message.call_args.args[0]
        self.assertEqual((msg["To"], msg["Subject"]), ("ops@sabarmart.com", "[Sabar CRM] disk full"))

    def test_alerts_disabled_without_configuration(self):
        quiet = RecordingAlerter()
        self.assertFalse(quiet.enabled)
        self.assertFalse(quiet.send("k", "s", "b"))


if __name__ == "__main__":
    unittest.main()
