# Deployment & Operations

Operator's manual for deploying, updating, and debugging the bot. Server coordinates and invariants are in [CLAUDE.md](CLAUDE.md); this document covers procedures. Setup for local dev is in [README.md](README.md).

## Server Coordinates

| | |
|---|---|
| Host | `themathbible.com` |
| SSH | `ssh root@themathbible.com` (port 22) |
| Deploy path | `/var/www/vhosts/themathbible.com/httpdocs/isquestions2/` |
| Webhook URL | `https://themathbible.com/isquestions2/index.php` |
| Bot | [`@iemisquestionsbot`](https://t.me/iemisquestionsbot) |
| Repo | [`dcodish/isbot`](https://github.com/dcodish/isbot) (private) |
| PHP (CLI) | 8.1 — used by `php -l`, `php bot-polling.php` |
| PHP (web) | 8.2 via PHP-FPM pool — serves the webhook |
| DB | `isquestions_gamified` on `localhost` |
| DB user | `isbot` / `isBotPass2026!` |
| PHP-FPM error log | `/var/log/plesk-php82-fpm/error.log` |

---

## Routine Deploy

The common case: you committed locally, now you want it live.

```bash
# 1. Local
git push origin main

# 2. Prod
ssh root@themathbible.com
cd /var/www/vhosts/themathbible.com/httpdocs/isquestions2
git fetch origin
git reset --hard origin/main

# 3. Smoke test (from prod or locally)
curl -s -o /dev/null -w '%{http_code}\n' https://themathbible.com/isquestions2/index.php
# expect: 200

# 4. Watch for live activity
tail -f /var/log/plesk-php82-fpm/error.log
# DEBUG lines are normal; stack traces / "PHP Fatal" lines are not
```

**Why `reset --hard` instead of `pull`?** The prod working tree has historically been edited by hand (e.g. `apitest.php`, `menutest.php`, `tokentest.php`). `reset --hard` makes the intent — "make prod match origin/main exactly" — explicit and idempotent. Untracked files are preserved.

**No build step, no restart.** PHP-FPM picks up file changes on the next request.

---

## Rollback

If a deploy breaks the bot:

```bash
ssh root@themathbible.com
cd /var/www/vhosts/themathbible.com/httpdocs/isquestions2
git log --oneline -10              # find the last good SHA
git reset --hard <good-sha>
```

Then fix the bad commit locally, push, and redeploy. **Don't** force-push the bad commit away from `origin/main` unless you're certain nobody else has pulled it.

---

## Environment Variables (`.env`)

Prod has a `.env` at the repo root, loaded by `bootstrap/app.php`. It is **not** tracked in git (see `.gitignore`).

**Edit on prod:**

```bash
ssh root@themathbible.com
cd /var/www/vhosts/themathbible.com/httpdocs/isquestions2
nano .env
```

**Required keys** (see [README.md](README.md) for descriptions):
`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `BOT_TOKEN`, `BOT_USERNAME`, `BOT_ID`, `BOT_ADMIN_USER_ID`, `ADMIN_USERNAME`, `ADMIN_PASSWORD`.

**The PHP-FPM caveat.** The Plesk PHP-FPM pool at `/opt/plesk/php/8.2/etc/php-fpm.d/themathbible.com.conf` injects env vars for a *different* (older) bot project that shares the domain. If `bootstrap/app.php` used `Dotenv::createImmutable`, those pool-injected values would win over `.env` and the bot would talk to the wrong Telegram account. We use `createMutable` so `.env` takes precedence. **Do not revert this** — it will silently swap bot tokens.

---

## Webhook Management

The Telegram webhook is set once and persists. Check / change it:

```bash
# Check current registration
TOKEN=$(grep ^BOT_TOKEN .env | cut -d= -f2 | tr -d '"')
curl -s "https://api.telegram.org/bot${TOKEN}/getWebhookInfo"

# (Re)set to prod
curl -s "https://api.telegram.org/bot${TOKEN}/setWebhook?url=https://themathbible.com/isquestions2/index.php"

# Clear (e.g. to switch to local polling mode)
curl -s "https://api.telegram.org/bot${TOKEN}/deleteWebhook"
```

Switching between prod (webhook) and local dev (polling) mostly "just works" because `bot-polling.php` calls `getUpdates` which Telegram refuses while a webhook is set — so the local poller stays quiet until you `deleteWebhook`.

---

## Database

Direct access for inspecting / fixing data:

```bash
ssh root@themathbible.com
mysql -u isbot -p'isBotPass2026!' isquestions_gamified
```

Schema reference is in [ARCHITECTURE.md § Database Tables](ARCHITECTURE.md#database-tables). There is no migration framework — schema changes are applied by hand with `ALTER TABLE` / `CREATE TABLE` statements, and the SQL should be committed to the repo (see `runtime/tagging_final.sql` for the last example) so future-you can replay it on a fresh install.

---

## Admin Panel

Reachable at `https://themathbible.com/isquestions2/admin/`. Credentials come from `.env` (`ADMIN_USERNAME`, `ADMIN_PASSWORD`). Session-based auth. Used for question CRUD and reviewing flagged questions.

---

## First-Time Setup of a New Deploy Target

If you ever need to stand up a new server or re-create this one:

### Prerequisites on the new host
- PHP 8.1+ with `mysqli` extension
- MySQL with the `isquestions_gamified` schema imported
- Composer
- Git
- SSH access with a reachable public IP (or reverse proxy) and HTTPS (Telegram requires HTTPS for webhooks)

### Steps

```bash
# 1. On the new server, generate a dedicated deploy keypair
ssh-keygen -t ed25519 -N "" -C "<hostname>-isbot" -f ~/.ssh/id_ed25519_isbot

# 2. Add an SSH alias for this repo (so it doesn't collide with other repos' deploy keys)
cat >> ~/.ssh/config <<'EOF'

Host github-isbot
  HostName github.com
  User git
  IdentityFile ~/.ssh/id_ed25519_isbot
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config

# 3. Register the public key as a deploy key on the repo
#    Copy ~/.ssh/id_ed25519_isbot.pub and paste it at:
#    https://github.com/dcodish/isbot/settings/keys
#    (Or from an authenticated local machine:
#       gh repo deploy-key add ~/.ssh/id_ed25519_isbot.pub --repo dcodish/isbot --title "<hostname>" )

# 4. Clone
cd /var/www/vhosts/<host>/httpdocs/
git clone git@github-isbot:dcodish/isbot.git isquestions2
cd isquestions2

# 5. Install composer deps (needed for phpdotenv)
composer install --no-dev

# 6. Create .env (see README.md for required keys) and chmod 600

# 7. Import the schema if this is a fresh DB
#    (No canonical dump in the repo — export from an existing prod with
#     mysqldump -u isbot -p isquestions_gamified > schema.sql )

# 8. Register the Telegram webhook to point at the new host
TOKEN=$(grep ^BOT_TOKEN .env | cut -d= -f2 | tr -d '"')
curl -s "https://api.telegram.org/bot${TOKEN}/setWebhook?url=https://<host>/isquestions2/index.php"

# 9. Smoke test
curl -s -o /dev/null -w '%{http_code}\n' https://<host>/isquestions2/index.php
```

---

## Debugging a Broken Bot

When the bot stops responding, walk down this list top-to-bottom:

1. **Is the webhook still registered?**
   `curl "https://api.telegram.org/bot${TOKEN}/getWebhookInfo"` — check `last_error_message` and `last_error_date`. A non-empty error means Telegram is hitting the endpoint and getting a non-200 or timing out.
2. **Does the endpoint return 200?**
   `curl -s -o /dev/null -w '%{http_code}\n' https://themathbible.com/isquestions2/index.php`. A 500 means PHP is crashing before output.
3. **PHP-FPM error log**
   `tail -100 /var/log/plesk-php82-fpm/error.log`. Stack traces include file + line.
4. **Syntax check the files you just changed**
   `cd /var/www/vhosts/themathbible.com/httpdocs/isquestions2 && php -l <file>.php`.
5. **Is the DB reachable?**
   `mysql -u isbot -p'isBotPass2026!' isquestions_gamified -e 'SELECT 1'`. If this fails, the bot can't run queries — check MySQL service and credentials.
6. **Did `.env` values get clobbered?**
   `grep BOT_TOKEN .env` and compare against what [BotFather](https://t.me/BotFather) shows. See the PHP-FPM caveat above.
7. **Is `processed_updates` dedup silently dropping messages?**
   `SELECT COUNT(*) FROM processed_updates WHERE created_at > NOW() - INTERVAL 10 MINUTE;` — if this matches Telegram's pending_update_count, requests are arriving and being deduped.
8. **Rollback.** If the last deploy is the prime suspect and you can't find the issue fast, roll back (see above) and debug at leisure.

---

## Adding a New Local Dev Machine

See [README.md § One-Time Setup](README.md#one-time-setup). The short version:

```bash
git clone git@github.com:dcodish/isbot.git   # or HTTPS if your machine doesn't have a key
cd isbot
composer install
cp .env.example .env                          # then edit with local DB + test bot credentials
php -S localhost:8000                         # terminal 1
php bot-polling.php                           # terminal 2
```

Use a **separate** test bot for local dev — don't point a local poller at the prod bot token while prod's webhook is registered; Telegram will refuse `getUpdates` and local work will be silent.

---

## Gotchas Recap

(From [CLAUDE.md](CLAUDE.md) — repeated here because they bite during deploys.)

- `bootstrap/app.php` uses `createMutable`, not `createImmutable` — do not change it.
- Badge bonus points must hit both `users.overall_points` *and* `point_log`, or weekly/monthly leaderboards desync from all-time.
- DB identifiers are snake_case (`current_run`, `gamification`, `upgrade_at`); do not reintroduce the old CamelCase.
- `processed_updates` dedup is load-bearing; never bypass it.
- Don't commit `.env`, `runtime/`, or `tmp/`.
