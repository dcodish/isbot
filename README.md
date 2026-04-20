# Gamified Telegram Quiz Bot Quick Start - IEM Final Project

This repository contains a Telegram quiz bot project that we extended for our IEM final project submission. The bot is based on a previous bot made by Dr. David Codish. The project includes an admin area, leaderboard features, nickname support, and a badge system.

For system design, message flow, DB schema, and feature details, see [ARCHITECTURE.md](ARCHITECTURE.md).

## Prerequisites

- PHP with `mysqli` extension
- MySQL with the bots database and tables set up
- Composer dependencies available under `vendor/`
- A Telegram bot token for live bot testing

## One-Time Setup

1. Clone the repository and navigate to the project root.
2. Install project dependencies with Composer if not already done:

```powershell
composer install
``` 

3. Create a local environment file:

```powershell
Copy-Item .env.example .env
```

4. Edit `.env` with your local values:

- `DB_HOST`
- `DB_USER`
- `DB_PASS`
- `DB_NAME`
- `BOT_TOKEN`
- `BOT_USERNAME`
- `BOT_ID`
- `BOT_ADMIN_USER_ID`
- `ADMIN_USERNAME`
- `ADMIN_PASSWORD`

## Start The Project

Run both commands from the repository root on two different terminal windows:

Terminal 1:

```powershell
php -S localhost:8000
```

Terminal 2:

```powershell
php bot-polling.php
```

## Main Flow

```text
Telegram user message
    -> bot-polling.php
    -> index.php
    -> variable_setup.php
    -> bot_functions.php / BadgeService.php
    -> MySQL + Telegram API response
```

## Main Commands

| Command | Description |
| --- | --- |
| `/start` | Start the bot and open the main flow |
| `/menu` | Show the main menu |
| `/stat` | Show user statistics |
| `/level` | Show the current level |
| `/clearstat` | Reset user progress |
| `/leaderboard` | Show the all-time leaderboard |
| `/leaderboard_weekly` | Show the weekly leaderboard |
| `/leaderboard_monthly` | Show the monthly leaderboard |

## File Structure

```text
.
├── README.md
├── CLAUDE.md
├── ARCHITECTURE.md
├── ROADMAP.md
├── DEPLOYMENT.md
├── bot-polling.php
├── index.php
├── variable_setup.php
├── bot_functions.php
├── BadgeService.php
├── admin/
├── bootstrap/
├── tools/
├── migrations/
├── badges/
├── mcp/
│   └── isbot-db/       # read-only MCP for Claude Desktop
└── runtime/
```

Notes:

- `bootstrap/` — shared environment and database bootstrapping
- `tools/` — maintenance scripts for import/export/insertion tasks
- `migrations/` — one-off DB change scripts (`YYYY-MM-DD_*.sql`), applied to prod via `scp` + `mysql < file`. See [DEPLOYMENT.md](DEPLOYMENT.md).
- `badges/` — webp assets for the trophy-closet composite image (one per row in the `badges` DB table)
- `mcp/isbot-db/` — local MCP server that gives Claude Desktop read-only access to the prod DB via SSH tunnel. Per-machine setup in [`mcp/isbot-db/README.md`](mcp/isbot-db/README.md).
- `runtime/` — local debug logs and one-off analysis artifacts (gitignored)

## Claude Desktop DB access

The MCP at `mcp/isbot-db/` exposes three read-only tools (`execute_query`, `list_tables`, `describe_table`) to Claude Desktop so you can analyse the prod DB from any conversation without copy-pasting SQL output. It tunnels through SSH and connects as the `isbot_ro` MySQL user (SELECT-only grants).

Per-PC setup (~2 min, repeat once per machine):

```bash
cd mcp/isbot-db
python -m venv .venv
.venv\Scripts\activate        # macOS/Linux: source .venv/bin/activate
pip install -r requirements.txt
```

The `.env` file travels via Dropbox (intentionally gitignored), so it's already filled in on a synced machine. If you set up from a fresh clone instead, copy `.env.example → .env` and fill in values.

Then add an entry to your Claude Desktop config (`%APPDATA%\Claude\claude_desktop_config.json` on Windows, `~/Library/Application Support/Claude/claude_desktop_config.json` on macOS):

```json
"isbot-db": {
  "command": "<abs-path>/mcp/isbot-db/.venv/Scripts/python.exe",
  "args": ["<abs-path>/mcp/isbot-db/server.py"]
}
```

Restart Claude Desktop. See [`mcp/isbot-db/README.md`](mcp/isbot-db/README.md) for troubleshooting.

## Gamification Features Added:

- Badge and achievement support
- Menu-based navigation and leaderboard views
- Nickname onboarding and related user-flow improvements

## Troubleshooting

If the bot does not respond:

1. Confirm the PHP server is running on port `8000`
2. Confirm the polling script is still running
3. Confirm `.env` contains a valid `BOT_TOKEN`
4. Confirm the database credentials in `.env` match your local MySQL setup
5. If debug mode is enabled, inspect logs under `runtime/`

## Important Notes

- The admin login should be configured through environment variables, not hardcoded in source files
