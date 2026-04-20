# mcp-isbot-db

Read-only MCP server for the isbot question-bank database. Runs locally
(Claude Desktop stdio transport), opens an SSH tunnel to `themathbible.com`,
and talks to MySQL through the tunnel as a SELECT-only user (`isbot_ro`).

## Quick setup (per machine)

Each PC needs its own Python venv; the repo itself syncs via Dropbox so
`server.py` / `.env` are shared, but `.venv/` is machine-specific.

```bash
cd mcp/isbot-db

# 1. Create and activate a venv
python -m venv .venv
# Windows:  .venv\Scripts\activate
# macOS/Linux:  source .venv/bin/activate

# 2. Install dependencies
pip install -r requirements.txt

# 3. Confirm .env exists and is filled in (it's synced via Dropbox, not git).
#    First-time setup on a brand-new machine: copy .env.example → .env and fill.

# 4. Smoke-test: the process should print nothing and wait on stdin.
python server.py
# Ctrl+C to exit.
```

## Wire it into Claude Desktop

Add an entry to your Claude Desktop config (`%APPDATA%\Claude\claude_desktop_config.json`
on Windows, `~/Library/Application Support/Claude/claude_desktop_config.json` on macOS):

```json
{
  "mcpServers": {
    "isbot-db": {
      "command": "C:\\Users\\<you>\\Dropbox\\projects\\isbot\\mcp\\isbot-db\\.venv\\Scripts\\python.exe",
      "args": ["C:\\Users\\<you>\\Dropbox\\projects\\isbot\\mcp\\isbot-db\\server.py"]
    }
  }
}
```

(macOS equivalent: `.venv/bin/python` and a POSIX path to `server.py`.)

Restart Claude Desktop. The `isbot-db` server should appear in the MCP tools
menu with three tools: `execute_query`, `list_tables`, `describe_table`.

## Tools exposed

| Tool | Args | Notes |
|------|------|-------|
| `execute_query` | `sql: str, limit: int = 100` | Only `SELECT` / `SHOW` / `DESCRIBE` / `EXPLAIN` / `WITH` statements are accepted; anything else is rejected before reaching MySQL. `limit` capped at 1000. |
| `list_tables` | — | `SHOW TABLES` |
| `describe_table` | `table: str` | `DESCRIBE <table>` — table name validated as identifier |

## Safety model

1. **DB-side:** `isbot_ro` has `SELECT`-only GRANTs on `isquestions_gamified.*`. Any destructive query fails at the MySQL layer with `ERROR 1142 ... command denied to user`.
2. **App-side:** `execute_query` rejects anything whose first non-comment token isn't in the allowlist. Belt-and-suspenders.
3. **Transport:** SSH tunnel — MySQL port 3306 is not reachable from the public internet, only via SSH to `themathbible.com`.

## Troubleshooting

- **"Missing env var"** — `.env` not found next to `server.py`. Check Dropbox is synced and the file exists.
- **"Unable to connect to SSH gateway"** — your SSH key isn't loaded. On Windows, start `ssh-agent` and `ssh-add` your key, or set `SSH_KEY_PATH=C:/Users/<you>/.ssh/id_ed25519` in `.env`.
- **"Access denied for user 'isbot_ro'"** — password drift. Reset on prod: `mysql -u root -e "ALTER USER 'isbot_ro'@'localhost' IDENTIFIED BY '<new>'"` and update `.env`.
- **Query returns nothing / stale** — MCP reopens the tunnel on every call, so there's no connection-pool staleness; check your `WHERE` clause.

## Schema pointer

The full schema lives under **Database Tables** in [`../../ARCHITECTURE.md`](../../ARCHITECTURE.md#database-tables). Key tables for research queries:

- `users`, `user_q`, `point_log`, `log` (event audit trail), `user_badges`
- `badges`, `actions`, `gamification`, `point_rules`, `questions`, `settings`
- `processed_updates`, `session_question_messages`, `survey_questions`, `user_survey`
