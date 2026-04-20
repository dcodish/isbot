# CLAUDE.md

Guidance for Claude Code when working in this repo. System architecture, message flow, DB schema, and feature behavior live in [ARCHITECTURE.md](ARCHITECTURE.md). Setup instructions live in [README.md](README.md). Read those first for context.

## Conventions

- **DB identifiers**: snake_case, lowercase (tables and columns). Example: `gamification`, `current_run`, `upgrade_at`. Do not introduce CamelCase.
- **Bootstrap**: every entrypoint (`index.php`, `bot-polling.php`, `admin/*`, `tools/*`) requires `bootstrap/app.php` directly. There is no `config.php` shim — don't recreate one.
- **Globals**: `$db`, `$API_URL`, `$user_id`, `$chat_id` are used throughout via `global`. Mirror that pattern rather than threading them as parameters.
- **Hebrew UI**: user-facing strings are in Hebrew. Keep them consistent in tone with existing messages.
- **RTL rendering**: prepend U+200F (RLM) to any line that might start with Latin or digits but needs RTL paragraph direction (question options, leaderboard rows, badge captions). Without it, Telegram flips lines starting with `GPU`/`NPU`/digits to LTR and the numbering scrambles.
- **Settings**: tunable parameters live in the `settings` table (key/value). Current keys: `current_week`, `session_gap_minutes`. When adding a new knob, prefer a `settings` row with a default rather than a PHP constant.
- **Migrations**: one-off DB changes go in `migrations/YYYY-MM-DD_description.sql`, are applied to prod via `scp` + `mysql < file`, and are committed to the repo for history. No migration runner — the files are the audit log.
- **Timezone**: PHP and MySQL are both set to `Asia/Jerusalem`. PHP via `date_default_timezone_set()` in `bootstrap/app.php`; MySQL via `SET time_zone = 'Asia/Jerusalem'` at connection. Prod has named-tz tables loaded (`mysql_tzinfo_to_sql`); a future host without that would silently fall back to UTC.

## Gotchas

- **SQL injection**: `bot_functions.php` and most handlers use raw `mysqli_query()` with string interpolation. When editing existing queries, preserve the `intval()` / `mysqli_real_escape_string()` pattern already in place. When adding new queries, prefer prepared statements (or at minimum `mysqli_real_escape_string`) — do **not** copy the raw-interpolation style.
- **Points logging**: badge bonus points must be written to both `users.overall_points` *and* `point_log` (with `question_id = NULL`). Missing the `point_log` write desyncs weekly/monthly leaderboards from the all-time one.
- **Nickname gate**: `checkNicknameRequired()` blocks all other commands until the user sets a nickname. Any new command must respect this.
- **Dedup**: `processed_updates` guards against replay. Never bypass it when adding new entry points.
- **Hybrid runtime**: prod uses webhooks, local dev uses polling. Don't assume one mode — see ARCHITECTURE.md for details.
- **Imagick only via Plesk PHP**: the webhook runs under `/opt/plesk/php/8.2/bin/php` which has Imagick loaded; the default `/usr/bin/php` does not. Trophy-closet image composition uses it. Guard with `extension_loaded('imagick')` and provide a text fallback for any code path that might run outside the webhook context.
- **Session cleanup**: every question message sent to a user is logged to `session_question_messages`. After `settings.session_gap_minutes` of inactivity, `maybeStartNewSession()` deletes those messages (or edits them to a placeholder if they're older than Telegram's 48h delete window). If you add new places where questions get rendered, make sure they log their `message_id` with `logSessionQuestionMessage()`.
- **Probation pool**: questions with `numofanswers < 5` are routed through a probation query (30% L1, 25% L2, 20% L3, 15% L4) to avoid single-sample classification stranding them at an extreme level. Don't remove the `numofanswers < 5` check without replacing it.

## Server Deployment

Full procedures (deploy, rollback, `.env` editing, webhook management, debugging, new-host setup) live in [DEPLOYMENT.md](DEPLOYMENT.md). Quick reference:

- **Server**: `themathbible.com` (SSH as `root`)
- **Deploy path**: `/var/www/vhosts/themathbible.com/httpdocs/isquestions2/`
- **Repo**: [`dcodish/isbot`](https://github.com/dcodish/isbot) (private); prod pulls via SSH deploy key, remote is `git@github-isbot:dcodish/isbot.git`
- **DB**: `isquestions_gamified` on `localhost`, user `isbot` / `isBotPass2026!`
- **Bot**: `@iemisquestionsbot`; webhook `https://themathbible.com/isquestions2/index.php`
- **PHP-FPM caveat**: The pool (`/opt/plesk/php/8.2/etc/php-fpm.d/themathbible.com.conf`) injects env vars for the old bot. `bootstrap/app.php` uses `createMutable` (not `createImmutable`) so `.env` overrides the pool. Do not change it back.

**Typical deploy**: `git push`, then on prod `git fetch origin && git reset --hard origin/main`. No build step, no restart.

**DB migrations**: `scp migrations/YYYY-MM-DD_*.sql root@themathbible.com:/tmp/m.sql` then `ssh root@themathbible.com "mysql -u isbot -p... isquestions_gamified < /tmp/m.sql"`. All migrations should be idempotent (use `IF NOT EXISTS`, `ON DUPLICATE KEY UPDATE`, etc.) so re-running is safe.

## Claude Desktop DB access (MCP)

A read-only MCP server at [`mcp/isbot-db/`](mcp/isbot-db/) lets Claude Desktop query the prod DB directly. It opens its own SSH tunnel to `themathbible.com` and connects as the SELECT-only user `isbot_ro`. Tools exposed: `execute_query`, `list_tables`, `describe_table`. Full per-machine setup and Claude Desktop config snippet in [mcp/isbot-db/README.md](mcp/isbot-db/README.md).

## Do Not

- Don't add a top-level `config.php` back. Everyone requires `bootstrap/app.php` directly.
- Don't add stub forwarders at the repo root (the old `export.php` → `tools/export.php` pattern). Call the real file.
- Don't weaken the nickname requirement or the `processed_updates` dedup without explicit ask.
- Don't commit `.env`, `runtime/`, or `tmp/`.
