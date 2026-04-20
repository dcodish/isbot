#!/usr/bin/env python3
"""
Read-only MCP server for the isbot question-bank database.

Runs locally (Claude Desktop stdio transport), opens its own SSH tunnel to the
prod server, and talks to MySQL through it using a SELECT-only DB user. All
config lives in a sibling .env file.
"""

from __future__ import annotations

import os
import re
from contextlib import contextmanager
from pathlib import Path

from dotenv import load_dotenv
from mcp.server.fastmcp import FastMCP
import pymysql
import pymysql.cursors
from sshtunnel import SSHTunnelForwarder


_HERE = Path(__file__).resolve().parent
load_dotenv(_HERE / ".env")


def _cfg(key: str, default: str | None = None, required: bool = False) -> str:
    v = os.environ.get(key, default)
    if required and not v:
        raise RuntimeError(f"Missing env var: {key} (set it in {_HERE / '.env'})")
    return v  # type: ignore[return-value]


SSH_HOST = _cfg("SSH_HOST", required=True)
SSH_PORT = int(_cfg("SSH_PORT", "22"))
SSH_USER = _cfg("SSH_USER", "root")
SSH_KEY_PATH = _cfg("SSH_KEY_PATH", "")
DB_REMOTE_HOST = _cfg("DB_HOST", "127.0.0.1")
DB_REMOTE_PORT = int(_cfg("DB_PORT", "3306"))
DB_USER = _cfg("DB_USER", required=True)
DB_PASSWORD = _cfg("DB_PASSWORD", required=True)
DB_NAME = _cfg("DB_NAME", "isquestions_gamified")


ALLOWED_PREFIXES = ("SELECT", "SHOW", "DESCRIBE", "DESC", "EXPLAIN", "WITH")
_LEADING_COMMENT = re.compile(r"^(--[^\n]*\n|/\*.*?\*/|\s)+", re.DOTALL)


def _is_safe_query(sql: str) -> bool:
    stripped = _LEADING_COMMENT.sub("", sql).strip().upper()
    return any(stripped.startswith(p) for p in ALLOWED_PREFIXES)


@contextmanager
def _tunneled_conn():
    tunnel_kwargs = {
        "ssh_address_or_host": (SSH_HOST, SSH_PORT),
        "ssh_username": SSH_USER,
        "remote_bind_address": (DB_REMOTE_HOST, DB_REMOTE_PORT),
    }
    if SSH_KEY_PATH:
        tunnel_kwargs["ssh_pkey"] = os.path.expanduser(SSH_KEY_PATH)
    with SSHTunnelForwarder(**tunnel_kwargs) as tunnel:
        conn = pymysql.connect(
            host="127.0.0.1",
            port=tunnel.local_bind_port,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME,
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=10,
        )
        try:
            yield conn
        finally:
            conn.close()


def _format_rows(rows: list[dict], truncated: bool = False) -> str:
    if not rows:
        return "(no rows)"
    cols = list(rows[0].keys())
    widths = {c: max(len(c), *(len(str(r[c])) for r in rows)) for c in cols}
    header = " | ".join(c.ljust(widths[c]) for c in cols)
    sep = "-+-".join("-" * widths[c] for c in cols)
    lines = [header, sep]
    lines.extend(" | ".join(str(r[c]).ljust(widths[c]) for c in cols) for r in rows)
    if truncated:
        lines.append(f"(truncated — {len(rows)} rows shown, increase `limit` for more)")
    return "\n".join(lines)


mcp = FastMCP("isbot-db")


@mcp.tool()
def execute_query(sql: str, limit: int = 100) -> str:
    """Run a read-only SQL query against the isbot database.

    Only SELECT / SHOW / DESCRIBE / EXPLAIN / WITH are allowed; other statements
    are rejected. Results are returned as an aligned text table up to `limit`
    rows (default 100, max 1000).
    """
    limit = max(1, min(limit, 1000))
    if not _is_safe_query(sql):
        return (
            f"Rejected: only SELECT/SHOW/DESCRIBE/EXPLAIN/WITH queries are allowed. "
            f"Got: {sql.strip()[:80]}..."
        )
    try:
        with _tunneled_conn() as conn, conn.cursor() as cur:
            cur.execute(sql)
            rows = cur.fetchmany(limit)
            truncated = len(rows) == limit
            return _format_rows(rows, truncated=truncated)
    except Exception as e:
        return f"Query error: {e}"


@mcp.tool()
def list_tables() -> str:
    """List every table in the isbot database."""
    try:
        with _tunneled_conn() as conn, conn.cursor() as cur:
            cur.execute("SHOW TABLES")
            rows = cur.fetchall()
            if not rows:
                return "(no tables)"
            key = next(iter(rows[0].keys()))
            return "\n".join(str(r[key]) for r in rows)
    except Exception as e:
        return f"Error: {e}"


@mcp.tool()
def describe_table(table: str) -> str:
    """Return the column definitions for a single table (DESCRIBE)."""
    if not re.match(r"^[a-zA-Z_][a-zA-Z0-9_]*$", table):
        return f"Invalid table name: {table}"
    try:
        with _tunneled_conn() as conn, conn.cursor() as cur:
            cur.execute(f"DESCRIBE `{table}`")
            rows = cur.fetchall()
            if not rows:
                return f"(table {table} not found)"
            return _format_rows(rows)
    except Exception as e:
        return f"Error: {e}"


if __name__ == "__main__":
    mcp.run()
