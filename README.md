# Gamified Telegram Quiz Bot Quick Start - IEM Final Project

This repository contains a Telegram quiz bot project that we extended for our IEM final project submission. The bot is based on a previous bot made by Dr. David Codish. The project includes an admin area, leaderboard features, nickname support, and a badge system.

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
├── Docs/QUICK-START.md
├── bot-polling.php
├── admin/
├── bootstrap/
├── runtime/
├── tools/
├── config.php
├── index.php
├── variable_setup.php
├── bot_functions.php
└── BadgeService.php
```

Notes:

- `bootstrap/` contains shared environment and database bootstrapping
- `tools/` contains maintenance scripts for import/export tasks
- `runtime/` is for local debug logs and should not be shared as project output
- legacy top-level script paths were preserved where practical for compatibility

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
