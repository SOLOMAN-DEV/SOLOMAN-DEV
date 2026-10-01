"""Portable backups of the CRM database.

    python -m sabar_mart_crm.db backup /home/cpuser/crm-backups --keep 14
    python -m sabar_mart_crm.db restore /home/cpuser/crm-backups/sabar-crm-20261001-020000.jsonl.gz

A backup is one gzip-compressed JSON-lines file holding every CRM table (``crm_*``),
taken while holding the CRM's database lock so it is a consistent snapshot (API writes
pause for the few seconds it takes). It does not depend on mysqldump, so it works the same
on shared hosting, a VPS, PostgreSQL or SQLite, and a backup can be restored into a
different database type.

Files are created with owner-only permissions (0600) because they contain personal data.
"""

from __future__ import annotations

import gzip
import hashlib
import json
import os
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from sqlalchemy import insert, select

from .db import SEQUENCES, TABLES, SQLBackend

FORMAT = "sabar-crm-backup"
VERSION = 1
NAME_RE = re.compile(r"^sabar-crm-\d{8}-\d{6}\.jsonl\.gz$")


def _utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def _all_tables() -> dict[str, Any]:
    return {**{t.name: t for t in TABLES.values()}, SEQUENCES.name: SEQUENCES}


def _row_to_json(row: dict[str, Any]) -> dict[str, Any]:
    return {k: (v.isoformat() if isinstance(v, datetime) else v) for k, v in row.items()}


def _row_from_json(table: Any, row: dict[str, Any]) -> dict[str, Any]:
    out = dict(row)
    for col in table.columns:
        if col.name in out and out[col.name] is not None and col.type.python_type is datetime:
            out[col.name] = datetime.fromisoformat(out[col.name])
    return out


def create_backup(backend: SQLBackend, out_dir: str | os.PathLike[str], keep: int = 14) -> dict[str, Any]:
    """Write a consistent snapshot to ``out_dir`` and prune all but the newest ``keep`` backups."""
    directory = Path(out_dir)
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    now = _utcnow()
    final = directory / f"sabar-crm-{now:%Y%m%d-%H%M%S}.jsonl.gz"
    partial = final.with_suffix(".partial")
    counts: dict[str, int] = {}
    tables = _all_tables()

    fd = os.open(partial, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    try:
        with os.fdopen(fd, "wb") as raw, gzip.GzipFile(fileobj=raw, mode="wb") as gz:
            def write(obj: dict[str, Any]) -> None:
                gz.write((json.dumps(obj, ensure_ascii=False, separators=(",", ":")) + "\n").encode())

            write({"format": FORMAT, "version": VERSION, "created_at": now.isoformat(), "tables": sorted(tables)})
            with backend.locked_connection() as conn:
                for name, table in sorted(tables.items()):
                    counts[name] = 0
                    for row in conn.execute(select(table)).mappings():
                        write({"t": name, "r": _row_to_json(dict(row))})
                        counts[name] += 1
            write({"end": True, "counts": counts})
        os.replace(partial, final)
    except BaseException:
        partial.unlink(missing_ok=True)
        raise

    digest = hashlib.sha256(final.read_bytes()).hexdigest()
    removed = prune(directory, keep)
    return {"file": str(final), "bytes": final.stat().st_size, "sha256": digest, "rows": counts, "pruned": removed}


def prune(directory: Path, keep: int) -> list[str]:
    backups = sorted(p for p in directory.iterdir() if NAME_RE.match(p.name))
    stale = backups[:-keep] if keep > 0 else []
    for p in stale:
        p.unlink()
    return [p.name for p in stale]


def read_backup(path: str | os.PathLike[str]) -> tuple[dict[str, Any], dict[str, list[dict[str, Any]]]]:
    """Parse and validate a backup file; raises ValueError if it is incomplete or not a backup."""
    rows: dict[str, list[dict[str, Any]]] = {}
    header: dict[str, Any] | None = None
    footer: dict[str, Any] | None = None
    with gzip.open(path, "rt", encoding="utf-8") as fh:
        for n, line in enumerate(fh):
            obj = json.loads(line)
            if n == 0:
                if obj.get("format") != FORMAT or obj.get("version") != VERSION:
                    raise ValueError("not a Sabar CRM backup (or an unsupported version)")
                header = obj
            elif obj.get("end"):
                footer = obj
            else:
                rows.setdefault(obj["t"], []).append(obj["r"])
    if header is None or footer is None:
        raise ValueError("backup file is truncated (no end marker)")
    actual = {t: len(rows.get(t, [])) for t in footer["counts"]}
    if actual != footer["counts"]:
        raise ValueError(f"backup row counts do not match its footer: {actual} != {footer['counts']}")
    return header, rows


def restore_backup(backend: SQLBackend, path: str | os.PathLike[str]) -> dict[str, int]:
    """Load a backup into an empty CRM database (refuses to overwrite existing data)."""
    _, rows = read_backup(path)
    tables = _all_tables()
    unknown = set(rows) - set(tables)
    if unknown:
        raise ValueError(f"backup contains tables this version does not know: {sorted(unknown)}")
    backend.create_schema()
    with backend.locked_connection() as conn:
        for table in tables.values():
            if conn.execute(select(table).limit(1)).first() is not None:
                raise ValueError(f"refusing to restore: table {table.name} is not empty")
        for name, table_rows in rows.items():
            table = tables[name]
            for i in range(0, len(table_rows), 500):
                conn.execute(insert(table), [_row_from_json(table, r) for r in table_rows[i:i + 500]])
    return {name: len(r) for name, r in rows.items()}
