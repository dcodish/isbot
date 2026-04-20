# CLAUDE.md

Guidance for Claude Code when working in this repo. System architecture, message flow, DB schema, and feature behavior live in [ARCHITECTURE.md](ARCHITECTURE.md). Setup instructions live in [README.md](README.md). Read those first for context.

## Conventions

- **DB identifiers**: snake_case, lowercase (tables and columns). Example: `gamification`, `current_run`, `upgrade_at`. Do not introduce CamelCase.
- **Bootstrap**: every entrypoint (`index.php`, `bot-polling.php`, `admin/*`, `tools/*`) requires `bootstrap/app.php` directly. There is no `config.php` shim — don't recreate one.
- **Globals**: `$db`, `$API_URL`, `$user_id`, `$chat_id` are used throughout via `global`. Mirror that pattern rather than threading them as parameters.
- **Hebrew UI**: user-facing strings are in Hebrew. Keep them consistent in tone with existing messages.

## Gotchas

- **SQL injection**: `bot_functions.php` and most handlers use raw `mysqli_query()` with string interpolation. When editing existing queries, preserve the `intval()` / `mysqli_real_escape_string()` pattern already in place. When adding new queries, prefer prepared statements (or at minimum `mysqli_real_escape_string`) — do **not** copy the raw-interpolation style.
- **Points logging**: badge bonus points must be written to both `users.overall_points` *and* `point_log` (with `question_id = NULL`). Missing the `point_log` write desyncs weekly/monthly leaderboards from the all-time one.
- **Nickname gate**: `checkNicknameRequired()` blocks all other commands until the user sets a nickname. Any new command must respect this.
- **Dedup**: `processed_updates` guards against replay. Never bypass it when adding new entry points.
- **Hybrid runtime**: prod uses webhooks, local dev uses polling. Don't assume one mode — see ARCHITECTURE.md for details.

## Server Deployment

- **Server**: `themathbible.com` (SSH access as `root`)
- **Deploy path**: `/var/www/vhosts/themathbible.com/httpdocs/isquestions2/`
- **Database**: `isquestions_gamified` on `localhost`
- **DB user**: `isbot` / `isBotPass2026!`
- **Bot**: `@iemisquestionsbot` (Telegram)
- **Webhook**: `https://themathbible.com/isquestions2/index.php`
- **GitHub repo**: [`dcodish/isbot`](https://github.com/dcodish/isbot) (private). Prod pulls via SSH deploy key at `~/.ssh/id_ed25519_isbot`, aliased as `github-isbot` in `~/.ssh/config`. Remote is `git@github-isbot:dcodish/isbot.git`.
- **PHP-FPM caveat**: The domain's PHP-FPM pool (`/opt/plesk/php/8.2/etc/php-fpm.d/themathbible.com.conf`) injects env vars for the old bot. `bootstrap/app.php` uses `createMutable` (not `createImmutable`) so the `.env` file overrides those. Do not change it back to `createImmutable`.

### Deploy procedure

1. Commit + push locally: `git push origin main`.
2. SSH to prod: `ssh root@themathbible.com`.
3. `cd /var/www/vhosts/themathbible.com/httpdocs/isquestions2`.
4. `git fetch origin && git reset --hard origin/main`. (Prefer `reset --hard` over `pull` — the working tree on prod occasionally has hand-edits; `reset --hard` makes intent explicit.)
5. Smoke test: `curl -s -o /dev/null -w '%{http_code}\n' https://themathbible.com/isquestions2/index.php` should return `200`.
6. Watch for live activity in `/var/log/plesk-php82-fpm/error.log` (DEBUG lines are normal; stack traces are not).

No build step, no service restart — PHP-FPM picks up file changes on the next request.

## Do Not

- Don't add a top-level `config.php` back. Everyone requires `bootstrap/app.php` directly.
- Don't add stub forwarders at the repo root (the old `export.php` → `tools/export.php` pattern). Call the real file.
- Don't weaken the nickname requirement or the `processed_updates` dedup without explicit ask.
- Don't commit `.env`, `runtime/`, or `tmp/`.
