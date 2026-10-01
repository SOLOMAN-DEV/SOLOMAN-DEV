"""Database backend tests.

SQLite always runs. To also test MySQL/MariaDB (what MilesWeb cPanel hosting provides), point
TEST_MYSQL_URL at an empty scratch database, e.g.
    TEST_MYSQL_URL="mysql+pymysql://crm:pass@localhost/sabar_crm_test?charset=utf8mb4"
"""

import json
import os
import tempfile
import threading
import unittest
from datetime import datetime
from decimal import Decimal

try:
    from sabar_mart_crm.db import SQLBackend
except ImportError:  # sqlalchemy not installed
    SQLBackend = None

from sabar_mart_crm.__main__ import reports, seed
from sabar_mart_crm.models import Customer

from test_api import KEYS, APITests, Clock, TestClient

NOW = datetime(2026, 10, 1, 12, 0)
MYSQL_URL = os.environ.get("TEST_MYSQL_URL")


def sqlite_url(tmpdir: str) -> str:
    return f"sqlite:///{os.path.join(tmpdir, 'crm.sqlite3')}"


class BackendMixin:
    url: str = ""

    def make_backend(self):
        backend = SQLBackend(self.url)
        backend.drop_schema()
        backend.create_schema()
        self.addCleanup(backend.engine.dispose)
        return backend

    def test_seed_roundtrip_matches_memory(self):
        expected = reports(seed(NOW), NOW)
        backend = self.make_backend()
        with backend.session() as crm:
            seed(NOW, crm)
        with backend.session() as crm:  # fresh session: everything comes back from the database
            actual = reports(crm, NOW)
        self.assertEqual(json.dumps(expected, sort_keys=True, default=str),
                         json.dumps(actual, sort_keys=True, default=str))

    def test_failed_session_rolls_back(self):
        backend = self.make_backend()
        with self.assertRaises(RuntimeError):
            with backend.session() as crm:
                crm.customers.register(Customer("C-1", "Asha", "a@example.com", "123", NOW))
                crm.store.next_id("task")
                raise RuntimeError("boom")
        with backend.session() as crm:
            self.assertNotIn("C-1", crm.store.customers)
            self.assertEqual(crm.store.next_id("task"), 1)

    def test_nested_changes_persist(self):
        backend = self.make_backend()
        with backend.session() as crm:
            crm.customers.register(Customer("C-1", "Asha", "a@example.com", "123", NOW))
        with backend.session() as crm:
            crm.customers.update_cart("C-1", "P-1", Decimal("99.50"), NOW)
            crm.customers.record_browse("C-1", "apparel")
        with backend.session() as crm:
            c = crm.store.customers["C-1"]
            self.assertEqual((c.cart, c.browsed_categories, c.cart_updated_at),
                             ({"P-1": Decimal("99.50")}, {"apparel": 1}, NOW))

    def test_purge_expired_idempotency_keys(self):
        from sabar_mart_crm.models import IdempotencyRecord
        backend = self.make_backend()
        with backend.session() as crm:
            crm.store.idempotency["a" * 64] = IdempotencyRecord("a" * 64, "x", "f", "null", NOW)
        with backend.session() as crm:
            self.assertEqual(crm.store.purge_idempotency(datetime(2000, 1, 1)), 0)  # nothing that old
            self.assertEqual(crm.store.purge_idempotency(datetime(2999, 1, 1)), 1)
        with backend.session() as crm:
            self.assertEqual(len(crm.store.idempotency), 0)

    def test_backup_and_restore_roundtrip(self):
        from sabar_mart_crm import backup
        backend = self.make_backend()
        with backend.session() as crm:
            seed(NOW, crm)
        with backend.session() as crm:
            expected = json.dumps(reports(crm, NOW), sort_keys=True, default=str)
        with tempfile.TemporaryDirectory() as tmp:
            info = backup.create_backup(backend, tmp)
            self.assertEqual(os.stat(info["file"]).st_mode & 0o777, 0o600)  # contains personal data
            self.assertGreater(info["rows"]["crm_ledger"], 0)

            # Restore into a fresh SQLite database (also proves backups move between database types).
            target = SQLBackend(sqlite_url(tmp))
            self.addCleanup(target.engine.dispose)
            counts = backup.restore_backup(target, info["file"])
            self.assertEqual(counts, {k: v for k, v in info["rows"].items() if v} | {
                k: 0 for k, v in info["rows"].items() if not v and k in counts})
            with target.session() as crm:
                self.assertEqual(json.dumps(reports(crm, NOW), sort_keys=True, default=str), expected)
                self.assertEqual(crm.store.next_id("task"), 6)  # sequences restored too
            with self.assertRaisesRegex(ValueError, "not empty"):
                backup.restore_backup(target, info["file"])

    def test_backup_rejects_damaged_files_and_prunes(self):
        import gzip

        from sabar_mart_crm import backup
        backend = self.make_backend()
        with tempfile.TemporaryDirectory() as tmp:
            info = backup.create_backup(backend, tmp)
            with gzip.open(info["file"], "rt") as fh:
                lines = fh.readlines()
            damaged = os.path.join(tmp, "damaged.jsonl.gz")
            with gzip.open(damaged, "wt") as fh:
                fh.writelines(lines[:-1])  # no end marker
            with self.assertRaisesRegex(ValueError, "truncated"):
                backup.read_backup(damaged)
            for stamp in ("20260101-000000", "20260102-000000", "20260103-000000"):
                open(os.path.join(tmp, f"sabar-crm-{stamp}.jsonl.gz"), "wb").close()
            self.assertEqual(len(backup.prune(__import__("pathlib").Path(tmp), keep=2)), 2)
            self.assertTrue(os.path.exists(info["file"]))  # the newest are kept

    def test_concurrent_startup_creates_schema_once(self):
        # Several worker processes booting at once on an empty database (Passenger, gunicorn -w N).
        self.make_backend().drop_schema()
        backends = [SQLBackend(self.url) for _ in range(4)]
        for b in backends:
            self.addCleanup(b.engine.dispose)
        errors = []
        barrier = threading.Barrier(len(backends))

        def boot(backend):
            try:
                barrier.wait()
                backend.create_schema()
            except Exception as exc:  # pragma: no cover - surfaced below
                errors.append(exc)

        threads = [threading.Thread(target=boot, args=(b,)) for b in backends]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        self.assertEqual(errors, [])
        with backends[0].session() as crm:
            self.assertEqual(len(crm.store.customers), 0)

    def test_concurrent_writers_lose_no_updates(self):
        # Two backends on one database stand in for two worker processes.
        backends = [self.make_backend(), SQLBackend(self.url)]
        self.addCleanup(backends[1].engine.dispose)
        with backends[0].session() as crm:
            crm.customers.register(Customer("C-1", "Asha", "a@example.com", "123", NOW))
        errors = []

        def worker(backend):
            try:
                for _ in range(10):
                    with backend.session() as crm:
                        crm.store.customers["C-1"].loyalty_points += 1
                        crm.store.next_id("task")
            except Exception as exc:  # pragma: no cover - surfaced below
                errors.append(exc)

        threads = [threading.Thread(target=worker, args=(backends[i % 2],)) for i in range(4)]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
        self.assertEqual(errors, [])
        with backends[0].session() as crm:
            self.assertEqual(crm.store.customers["C-1"].loyalty_points, 40)
            self.assertEqual(crm.store.next_id("task"), 41)


@unittest.skipIf(SQLBackend is None, "sqlalchemy not installed")
class SQLiteBackendTests(BackendMixin, unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.url = sqlite_url(tmp.name)


@unittest.skipIf(SQLBackend is None or not MYSQL_URL, "TEST_MYSQL_URL not set")
class MySQLBackendTests(BackendMixin, unittest.TestCase):
    url = MYSQL_URL or ""


class _DBAPITests(APITests):
    """Re-run every API test with each request in its own database transaction."""

    url = ""

    def setUp(self):
        from sabar_mart_crm.api import create_app

        backend = SQLBackend(self.url)
        backend.drop_schema()
        backend.create_schema()
        self.addCleanup(backend.engine.dispose)
        self.clock = Clock()
        self.client = TestClient(create_app(api_keys=KEYS, clock=self.clock, backend=backend))


@unittest.skipIf(SQLBackend is None or TestClient is None, "sqlalchemy/fastapi not installed")
class SQLiteAPITests(_DBAPITests):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.url = sqlite_url(tmp.name)
        super().setUp()


@unittest.skipIf(SQLBackend is None or TestClient is None or not MYSQL_URL, "TEST_MYSQL_URL not set")
class MySQLAPITests(_DBAPITests):
    url = MYSQL_URL or ""


del APITests, _DBAPITests  # only the concrete backend suites run from this module

if __name__ == "__main__":
    unittest.main()
