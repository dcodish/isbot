# Telegram Quiz Bot Final Project

This repository contains my final project work on top of an existing Telegram quiz bot codebase. I extended the original project with new bot features, improved the quiz flow, and reorganized the repository so it is easier to review and safer to share.

## What I Added

- A badge and achievement system with progress tracking and Telegram notifications
- A menu-based user flow with leaderboard and stats features
- Nickname onboarding and profile-related improvements
- Better project organization for configuration, runtime files, and maintenance scripts

## Main Structure

```text
.
├── README.md
├── Docs/
│   └── QUICK-START.md
├── bot-polling.php
├── admin/              # Admin panel
├── bootstrap/          # Shared app bootstrap and configuration loading
├── runtime/            # Local runtime logs (not tracked)
├── tools/              # Import/export maintenance scripts
├── BadgeService.php    # Badge and achievement service
├── bot_functions.php   # Core bot helper logic
├── config.php          # Compatibility entry for shared bootstrap
├── index.php           # Main webhook/polling request entry
├── variable_setup.php  # Request parsing and callback handling
└── export*.php         # Compatibility wrappers to `tools/`
```

## How To Review

Start with these files if you want to understand the project quickly:

- `bot-polling.php` for the local polling runner
- `index.php` for the main request flow
- `bootstrap/app.php` for environment loading and database setup
- `bot_functions.php` and `BadgeService.php` for the core bot features
- `Docs/QUICK-START.md` for setup and usage notes

## Running The Project

The repository is organized to stay easy to run locally, but live Telegram usage still requires a valid bot token and local database credentials.

Setup steps are documented in `Docs/QUICK-START.md`.

## Submission Notes

- Sensitive values should live only in `.env`, which is ignored by git
- Runtime logs are written under `runtime/` and are not tracked
- Legacy file paths were preserved where reasonable so existing entry points still work
