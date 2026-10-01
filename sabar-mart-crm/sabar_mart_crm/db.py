"""SQL persistence for the CRM engine (MySQL/MariaDB, PostgreSQL, SQLite).

Set ``DATABASE_URL`` to switch the API from in-memory to database storage:

    mysql+pymysql://user:password@localhost/dbname?charset=utf8mb4   (MilesWeb / cPanel)
    postgresql+psycopg://user:password@localhost/dbname
    sqlite:////home/user/crm.sqlite3

Each collection is one table (``crm_<name>``) holding the record as JSON plus a
few indexed lookup columns. A request runs as a unit of work: it takes a
database-wide lock, loads only the rows it touches, and on success writes back
the rows that changed, in one transaction. The lock makes it safe to run several
worker processes (as Passenger does on shared hosting).

    python -m sabar_mart_crm.db init     # create tables
    python -m sabar_mart_crm.db seed     # load the demo marketplace (empty database only)
    python -m sabar_mart_crm.db check    # test the connection and print row counts
    python -m sabar_mart_crm.db purge    # delete expired idempotency keys (run daily from cron)
    python -m sabar_mart_crm.db backup DIR [--keep 14]   # consistent snapshot, keeps the newest 14
    python -m sabar_mart_crm.db restore FILE             # into an empty database only
    python -m sabar_mart_crm.db user-add alice finance   # prints alice's API key (shown once)
    python -m sabar_mart_crm.db user-list
    python -m sabar_mart_crm.db user-disable alice
    python -m sabar_mart_crm.db user-rotate alice        # new key, old one stops working
"""

from __future__ import annotations

import dataclasses
import hashlib
import json
import os
import sys
import threading
import time
import types
from collections.abc import Iterator, MutableMapping
from contextlib import contextmanager
from datetime import datetime, timezone
from decimal import Decimal
from enum import Enum
from functools import cache
from typing import Any, Union, get_args, get_origin, get_type_hints

from sqlalchemy import (
    BigInteger,
    Column,
    DateTime,
    MetaData,
    String,
    Table,
    Text,
    create_engine,
    delete,
    func,
    insert,
    select,
    text,
    update,
)
from sqlalchemy.dialects import mysql
from sqlalchemy.engine import Connection, Engine, make_url

from . import users
from .engine import CRMEngine
from .models import ApiUser, to_jsonable
from .store import COLLECTIONS, Store

TABLE_PREFIX = "crm_"
LOCK_TIMEOUT_SECONDS = 30


class StorageBusy(RuntimeError):
    """The database lock could not be acquired in time."""


# --- JSON <-> dataclass codec ---------------------------------------------------

def encode(obj: Any) -> str:
    return json.dumps(to_jsonable(obj), sort_keys=True, separators=(",", ":"), ensure_ascii=False)


@cache
def _hints(cls: type) -> dict[str, Any]:
    return get_type_hints(cls)


def decode(tp: Any, value: Any) -> Any:
    if value is None or tp is Any:
        return value
    origin = get_origin(tp)
    if origin in (Union, types.UnionType):
        inner = [a for a in get_args(tp) if a is not type(None)]
        return decode(inner[0], value)
    if origin is list:
        (item,) = get_args(tp)
        return [decode(item, v) for v in value]
    if origin is dict:
        _, item = get_args(tp)
        return {k: decode(item, v) for k, v in value.items()}
    if dataclasses.is_dataclass(tp):
        hints = _hints(tp)
        return tp(**{f.name: decode(hints[f.name], value[f.name])
                     for f in dataclasses.fields(tp) if f.name in value})
    if isinstance(tp, type) and issubclass(tp, Enum):
        return tp(value)
    if tp is Decimal:
        return Decimal(value)
    if tp is datetime:
        return datetime.fromisoformat(value)
    return value


def _scalar(value: Any) -> Any:
    value = to_jsonable(value)
    return None if value is None else str(value)


# --- Schema ---------------------------------------------------------------------

def build_metadata() -> tuple[MetaData, dict[str, Table], Table]:
    md = MetaData()
    opts = {"mysql_engine": "InnoDB", "mysql_charset": "utf8mb4", "mariadb_engine": "InnoDB",
            "mariadb_charset": "utf8mb4"}
    body = Text().with_variant(mysql.LONGTEXT(), "mysql", "mariadb")
    tables = {
        name: Table(
            f"{TABLE_PREFIX}{name}", md,
            Column("id", String(64), primary_key=True),
            *(Column(col, String(128), index=True) for col in indexed),
            Column("data", body, nullable=False),
            Column("updated_at", DateTime, nullable=False),
            **opts,
        )
        for name, (_, _, indexed) in COLLECTIONS.items()
    }
    sequences = Table(
        f"{TABLE_PREFIX}sequences", md,
        Column("name", String(64), primary_key=True),
        Column("value", BigInteger, nullable=False),
        **opts,
    )
    return md, tables, sequences


METADATA, TABLES, SEQUENCES = build_metadata()


def _utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


# --- Unit-of-work store -----------------------------------------------------------

class DBMapping(MutableMapping):
    """Dict-like view of one table: lazy row loading, identity map, change tracking."""

    def __init__(self, conn: Connection, name: str) -> None:
        self._conn = conn
        self._table = TABLES[name]
        self._cls, _, self._indexed = COLLECTIONS[name]
        self._loaded: dict[str, Any] = {}
        self._persisted: dict[str, str] = {}  # id -> JSON as last read from / written to the DB
        self._deleted: set[str] = set()
        self._all_loaded = False

    def _adopt(self, row: Any) -> None:
        if row.id in self._loaded or row.id in self._deleted:
            return  # in-session version wins
        self._loaded[row.id] = decode(self._cls, json.loads(row.data))
        self._persisted[row.id] = row.data

    def _load_all(self) -> None:
        if not self._all_loaded:
            for row in self._conn.execute(select(self._table.c.id, self._table.c.data).order_by(self._table.c.id)):
                self._adopt(row)
            self._all_loaded = True

    def __getitem__(self, key: str) -> Any:
        if key in self._loaded:
            return self._loaded[key]
        if key not in self._deleted and not self._all_loaded:
            row = self._conn.execute(
                select(self._table.c.id, self._table.c.data).where(self._table.c.id == key)).first()
            if row is not None:
                self._adopt(row)
                return self._loaded[key]
        raise KeyError(key)

    def __setitem__(self, key: str, value: Any) -> None:
        self._deleted.discard(key)
        self._loaded[key] = value

    def __delitem__(self, key: str) -> None:
        self[key]  # raises KeyError if absent
        del self._loaded[key]
        self._deleted.add(key)

    def __iter__(self) -> Iterator[str]:
        self._load_all()
        return iter(list(self._loaded))

    def __len__(self) -> int:
        self._load_all()
        return len(self._loaded)

    def where(self, **criteria: Any) -> list[Any]:
        if not self._all_loaded and criteria and all(k in self._indexed for k in criteria):
            query = select(self._table.c.id, self._table.c.data)
            for col, value in criteria.items():
                query = query.where(self._table.c[col] == _scalar(value))
            for row in self._conn.execute(query):
                self._adopt(row)
            # Rows changed in this session are already loaded, so scanning loaded rows is complete.
        else:
            self._load_all()
        return [r for r in self._loaded.values() if all(getattr(r, k) == v for k, v in criteria.items())]

    def flush(self) -> None:
        now = _utcnow()
        for key in self._deleted:
            if key in self._persisted:
                self._conn.execute(delete(self._table).where(self._table.c.id == key))
                del self._persisted[key]
        self._deleted.clear()
        for key, obj in self._loaded.items():
            data = encode(obj)
            if self._persisted.get(key) == data:
                continue
            values = {"data": data, "updated_at": now, **{c: _scalar(getattr(obj, c)) for c in self._indexed}}
            if key in self._persisted:
                self._conn.execute(update(self._table).where(self._table.c.id == key).values(**values))
            else:
                self._conn.execute(insert(self._table).values(id=key, **values))
            self._persisted[key] = data


class DBStore(Store):
    """Store backed by an open connection. Call ``flush()`` before committing."""

    def __init__(self, conn: Connection) -> None:  # deliberately not calling the dataclass __init__
        self._conn = conn
        for name in COLLECTIONS:
            setattr(self, name, DBMapping(conn, name))

    def next_id(self, name: str) -> int:
        current = self._conn.execute(select(SEQUENCES.c.value).where(SEQUENCES.c.name == name)).scalar()
        if current is None:
            self._conn.execute(insert(SEQUENCES).values(name=name, value=1))
            return 1
        self._conn.execute(update(SEQUENCES).where(SEQUENCES.c.name == name).values(value=current + 1))
        return current + 1

    def find(self, collection: str, **criteria: Any) -> list[Any]:
        return getattr(self, collection).where(**criteria)

    def recent(self, collection: str, limit: int) -> list[Any]:
        mapping: DBMapping = getattr(self, collection)
        table = TABLES[collection]
        for row in self._conn.execute(select(table.c.id, table.c.data).order_by(table.c.id.desc()).limit(limit)):
            mapping._adopt(row)
        keys = sorted(mapping._loaded, reverse=True)[:limit]  # includes rows added in this session
        return [mapping._loaded[k] for k in keys]

    def purge_idempotency(self, older_than: datetime) -> int:
        # Rows are never updated, so updated_at is the creation time: purge in SQL without loading them.
        table = TABLES["idempotency"]
        return self._conn.execute(delete(table).where(table.c.updated_at < older_than)).rowcount

    def flush(self) -> None:
        for name in COLLECTIONS:
            getattr(self, name).flush()


# --- Backends ----------------------------------------------------------------------

class MemoryBackend:
    """Keeps one engine in process memory. Data is lost on restart."""

    def __init__(self, engine: CRMEngine | None = None) -> None:
        self.engine = engine or CRMEngine()
        self._lock = threading.Lock()

    @contextmanager
    def session(self) -> Iterator[CRMEngine]:
        with self._lock:
            yield self.engine

    def lookup_user(self, api_key: str) -> ApiUser | None:
        with self._lock:
            return users.find_by_key(self.engine.store, api_key)

    def ping(self) -> dict[str, Any]:
        return {"database": "memory (data is lost on restart)"}


class SQLBackend:
    """Opens a locked transaction per session and persists changes on success."""

    def __init__(self, url: str, **engine_kwargs: Any) -> None:
        parsed = make_url(url)
        self.dialect = parsed.get_backend_name()
        kwargs: dict[str, Any] = {"pool_pre_ping": True}
        if self.dialect == "sqlite":
            kwargs["connect_args"] = {"timeout": LOCK_TIMEOUT_SECONDS}
        else:
            kwargs["pool_recycle"] = 280  # shared MySQL hosts close idle connections quickly
        kwargs.update(engine_kwargs)
        self.engine: Engine = create_engine(parsed, **kwargs)
        # MySQL named locks are server-wide; include the database name so tenants on a shared server don't collide.
        self._lock_name = f"{parsed.database or 'default'}.sabar_crm"[:64]
        self._lock_file = f"{parsed.database}.lock" if self.dialect == "sqlite" and parsed.database not in (None, "", ":memory:") else None
        self._thread_lock = threading.Lock()

    def create_schema(self) -> None:
        """Create missing tables. Safe when several worker processes start at once: the
        check-then-create runs under the CRM lock, so only one process creates each table."""
        with self._thread_lock, self.engine.connect() as conn, self._process_lock(conn):
            METADATA.create_all(conn)
            conn.commit()

    def drop_schema(self) -> None:
        METADATA.drop_all(self.engine)

    @staticmethod
    def _release(conn: Connection, sql: str, params: dict[str, Any]) -> None:
        try:
            conn.execute(text(sql), params)
            conn.commit()
        except Exception:
            # Closing the connection makes the server drop the lock; never return a lock-holding connection to the pool.
            conn.invalidate()
            raise

    @contextmanager
    def _process_lock(self, conn: Connection) -> Iterator[None]:
        if self.dialect in ("mysql", "mariadb"):
            got = conn.execute(text("SELECT GET_LOCK(:n, :t)"), {"n": self._lock_name, "t": LOCK_TIMEOUT_SECONDS}).scalar()
            conn.commit()
            if got != 1:
                raise StorageBusy("timed out waiting for the CRM database lock")
            try:
                yield
            finally:
                self._release(conn, "SELECT RELEASE_LOCK(:n)", {"n": self._lock_name})
        elif self.dialect == "postgresql":
            key = int.from_bytes(hashlib.sha256(self._lock_name.encode()).digest()[:8], "big", signed=True)
            conn.execute(text("SELECT pg_advisory_lock(:k)"), {"k": key})
            conn.commit()
            try:
                yield
            finally:
                self._release(conn, "SELECT pg_advisory_unlock(:k)", {"k": key})
        elif self._lock_file:
            try:
                import fcntl
            except ImportError:  # Windows: only the in-process lock applies
                yield
                return
            with open(self._lock_file, "a") as fh:
                fcntl.flock(fh, fcntl.LOCK_EX)
                try:
                    yield
                finally:
                    fcntl.flock(fh, fcntl.LOCK_UN)
        else:
            yield

    @contextmanager
    def locked_connection(self) -> Iterator[Connection]:
        """A connection inside one transaction, holding the CRM lock (consistent reads and writes)."""
        with self._thread_lock, self.engine.connect() as conn, self._process_lock(conn):
            # The transaction starts only after the lock is held, so it reads the latest committed data,
            # and it commits before the lock is released.
            with conn.begin():
                yield conn

    @contextmanager
    def session(self) -> Iterator[CRMEngine]:
        with self.locked_connection() as conn:
            store = DBStore(conn)
            yield CRMEngine(store)
            store.flush()

    def ping(self) -> dict[str, Any]:
        """Connectivity check for /health (does not take the CRM lock)."""
        start = time.perf_counter()
        with self.engine.connect() as conn:
            conn.execute(select(func.count()).select_from(SEQUENCES)).scalar()
        return {"database": self.dialect, "latency_ms": round((time.perf_counter() - start) * 1000, 1)}

    def lookup_user(self, api_key: str) -> ApiUser | None:
        """Read-only key lookup; it skips the global lock so authentication never queues behind writes."""
        with self.engine.connect() as conn:
            return users.find_by_key(DBStore(conn), api_key)

    def is_empty(self) -> bool:
        with self.engine.connect() as conn:
            return not conn.execute(select(func.count()).select_from(TABLES["customers"])).scalar() \
                and not conn.execute(select(func.count()).select_from(TABLES["vendors"])).scalar()


def backend_from_env(engine: CRMEngine | None = None) -> MemoryBackend | SQLBackend:
    url = os.environ.get("DATABASE_URL", "").strip()
    if not url:
        return MemoryBackend(engine)
    backend = SQLBackend(url)
    if os.environ.get("SABAR_CRM_AUTO_MIGRATE", "1") == "1":
        backend.create_schema()
    return backend


# --- CLI --------------------------------------------------------------------------

def main(argv: list[str]) -> int:
    url = os.environ.get("DATABASE_URL")
    commands = ("init", "seed", "check", "purge", "backup", "restore",
                "user-add", "user-list", "user-disable", "user-rotate")
    if not url or not argv or argv[0] not in commands:
        print(__doc__)
        return 2
    backend = SQLBackend(url)
    backend.create_schema()
    if argv[0].startswith("user-"):
        return _user_command(backend, argv)
    if argv[0] in ("backup", "restore"):
        return _backup_command(backend, argv)
    if argv[0] == "purge":
        from datetime import timedelta

        from . import config
        with backend.session() as crm:
            n = crm.store.purge_idempotency(_utcnow() - timedelta(hours=config.IDEMPOTENCY_TTL_HOURS))
        print(f"purged {n} expired idempotency keys")
        return 0
    if argv[0] == "seed":
        if not backend.is_empty():
            print("database already has data; refusing to seed")
            return 1
        from .__main__ import seed
        with backend.session() as crm:
            seed(_utcnow(), crm)
        print("demo data loaded")
    if argv[0] in ("init", "check"):
        with backend.engine.connect() as conn:
            for name, table in TABLES.items():
                print(f"{table.name:<20} {conn.execute(select(func.count()).select_from(table)).scalar()}")
    return 0


def _backup_command(backend: SQLBackend, argv: list[str]) -> int:
    from . import backup
    from .monitoring import Alerter

    try:
        if argv[0] == "backup" and len(argv) in (2, 4) and (len(argv) == 2 or argv[2] == "--keep"):
            info = backup.create_backup(backend, argv[1], keep=int(argv[3]) if len(argv) == 4 else 14)
            backup.read_backup(info["file"])  # verify the file just written
            print(f"backup ok: {info['file']} ({info['bytes']} bytes, sha256 {info['sha256'][:16]}..., "
                  f"{sum(info['rows'].values())} rows, pruned {len(info['pruned'])})")
            return 0
        if argv[0] == "restore" and len(argv) == 2:
            counts = backup.restore_backup(backend, argv[1])
            print(f"restored {sum(counts.values())} rows into {len(counts)} tables")
            return 0
    except Exception as exc:
        Alerter.from_env().send("backup", f"CRM {argv[0]} failed", f"{type(exc).__name__}: {exc}", wait=True)
        print(f"{argv[0]} FAILED: {type(exc).__name__}: {exc}")
        return 1
    print(__doc__)
    return 2


def _user_command(backend: SQLBackend, argv: list[str]) -> int:
    from .rbac import Role

    cmd, args = argv[0], argv[1:]
    try:
        with backend.session() as crm:
            if cmd == "user-add" and len(args) == 2:
                key = users.create_user(crm.store, args[0], Role(args[1]), _utcnow())
                print(f"created {args[0]} ({args[1]}). API key (store it now, it is not shown again):\n{key}")
            elif cmd == "user-list" and not args:
                for u in sorted(crm.store.users.values(), key=lambda u: u.username):
                    print(f"{u.username:<24} {u.role:<18} {'active' if u.active else 'disabled'}")
            elif cmd == "user-disable" and len(args) == 1:
                users.deactivate(crm.store, args[0])
                print(f"disabled {args[0]}")
            elif cmd == "user-rotate" and len(args) == 1:
                print(f"new API key for {args[0]}:\n{users.rotate_key(crm.store, args[0])}")
            else:
                print(__doc__)
                return 2
    except KeyError as exc:
        print(f"no such user: {exc}")
        return 1
    except ValueError as exc:
        print(f"error: {exc}")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
