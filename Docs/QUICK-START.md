# Telegram Quiz Bot Quick Start

This repository contains a Telegram quiz bot project that I extended for my final project submission. The project can run locally in polling mode and includes an admin area, leaderboard features, nickname support, and a badge system.

## Prerequisites

- PHP with `mysqli`
- MySQL with a local database for the bot
- Composer dependencies available under `bank/vendor/`
- A Telegram bot token for live bot testing

## One-Time Setup

1. Create a local environment file:

```powershell
Copy-Item bank/.env.example bank/.env
```

2. Edit `bank/.env` with your local values:

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

3. Make sure your MySQL schema and seed data are available locally.

## Start The Project

Run both commands from the repository root.

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
    -> bank/index.php
    -> bank/variable_setup.php
    -> bank/bot_functions.php / bank/BadgeService.php
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

## Submission-Oriented Structure

```text
.
├── README.md
├── Docs/QUICK-START.md
├── bot-polling.php
└── bank/
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

- `bank/bootstrap/` contains shared environment and database bootstrapping
- `bank/tools/` contains maintenance scripts for import/export tasks
- `bank/runtime/` is for local debug logs and should not be shared as project output
- legacy top-level script paths were preserved where practical for compatibility

## What I Added

- Badge and achievement support
- Menu-based navigation and leaderboard views
- Nickname onboarding and related user-flow improvements
- Better repository hygiene and project organization for submission

## Troubleshooting

If the bot does not respond:

1. Confirm the PHP server is running on port `8000`
2. Confirm the polling script is still running
3. Confirm `bank/.env` contains a valid `BOT_TOKEN`
4. Confirm the database credentials in `bank/.env` match your local MySQL setup
5. If debug mode is enabled, inspect logs under `bank/runtime/`

## Important Notes

- The repository no longer stores live secrets in tracked documentation or config files
- Full live Telegram testing still requires a valid local bot token
- The admin login should be configured through environment variables, not hardcoded in source files
