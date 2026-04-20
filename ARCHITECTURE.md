# Architecture

A Telegram-based gamified quiz bot for an IEM (Information Systems) course. Students answer quiz questions through Telegram; performance is tracked via levels, points, and badges. The UI is primarily in Hebrew.

For setup and day-to-day running instructions, see [README.md](README.md).

## Deployment Modes

The bot runs in a **hybrid mode** depending on environment:

- **Production** (`themathbible.com/isquestions2/`): webhook — Telegram POSTs updates directly to `index.php` via Apache. No long-running process needed.
- **Local dev**: polling — `bot-polling.php` calls Telegram's `getUpdates` and forwards each update to `http://localhost:8000/index.php`. Avoids needing an HTTPS tunnel (e.g. ngrok) for local work.

To check the current webhook registration: `curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"`.

## Message Flow

```
Prod:  Telegram → Apache → index.php → variable_setup.php → bot_functions.php / BadgeService.php
Local: Telegram → bot-polling.php → index.php → variable_setup.php → bot_functions.php / BadgeService.php
```

1. **`bot-polling.php`** — Long-running poller used for **local dev only**; forwards raw updates to `index.php` via HTTP POST.
2. **`index.php`** — Deduplicates updates (via `processed_updates` DB table), then includes `variable_setup.php`.
3. **`variable_setup.php`** — Extracts user/chat IDs, enforces nickname requirement, routes commands and callback queries.
4. **`bot_functions.php`** — ~1,450 lines of core game logic: question selection, leaderboards, levels, points, nicknames.
5. **`BadgeService.php`** — OOP class managing 30+ badge types, progress tracking, and awards.
6. **`bootstrap/app.php`** — Bootstraps phpdotenv, MySQL connection (`$db` global), defines `TOKEN`, `API_URL`, `DEBUG` constants, loads `BadgeService.php`. Every entrypoint requires this file directly.

## Key Subsystems

### Levels & Question Selection
Users progress through 4 levels. Question selection is **probability-based on success rate** (not hardcoded difficulty):
- Level 1: questions with ≥80% success rate
- Level 2: mix of 70–80% and 80%+ questions
- Level 3/4: increasingly harder questions, no repeats allowed

Progression controlled by `current_run` counter in `users` table vs. thresholds in `gamification` table. Correct answers increment, wrong answers decrement.

### Points System
Points awarded per answer based on question difficulty inferred from success rate. Rules stored in `point_rules` table (action_type × question_level → points). All transactions logged in `point_log`, including badge bonus rewards (stored with `question_id IS NULL`).

### Badge System (`BadgeService.php`)
Badges awarded for streaks, milestones, level-ups, time-of-day, consistency, and more. Tracked in `badges`, `user_badges`, and `badge_progress` tables. Call `BadgeService` methods after each answer in `variable_setup.php`.

### Nickname System
New users are blocked from playing until a unique nickname is set (3–15 chars, `[a-zA-Z0-9_]`). The `awaiting_nickname` flag in `users` table gates all other commands in `checkNicknameRequired()`.

### Admin Panel (`/admin/`)
Session-authenticated web UI for question CRUD. Credentials from `.env`. Questions reported as unclear (`reportedbad` counter) appear at top of list. All DB operations in `admin/backend/save.php`.

## Database Tables

| Table | Purpose |
|---|---|
| `users` | User state: level, current_run, overall_points, nickname, awaiting_nickname |
| `questions` | Question bank with success tracking (numofanswers, numofcorrectanswers, reportedbad) |
| `user_q` | Per-user question history (numofsuccess, numoffailure) |
| `gamification` | Level up/down thresholds per level (columns: level, upgrade_at, downgrade_at) |
| `point_rules` | Points awarded per (action_type, question_level) |
| `point_log` | Full audit log of point transactions (includes badge bonuses with `question_id IS NULL`) |
| `badges` / `user_badges` / `badge_progress` | Badge definitions, earned badges, progress |
| `survey_questions` / `user_survey` | Optional research survey interleaved with quiz |
| `processed_updates` | Deduplication of Telegram update_ids |
| `actions` | Action-type lookup (CorrectAnswer, WrongAnswer, Skip, etc.) |
| `log` | General action audit log |

## Callback Query Routing

Handled in `variable_setup.php`. Key formats:
- `Q:ID:A:*` — answer a quiz question
- `SQ:ID:A:*` — answer a survey question
- `Bad:ID` — report question as unclear
- `skip` / `skipSQ:ID` — skip question
- `menu_*` — navigate main menu

## Tools & Export Scripts

Located under `tools/` — see [tools/README.md](tools/README.md).

- `tools/import.php` — Import questions from `new file.txt` (6-line format: question, A, B, C, D, answer)
- `tools/export.php` — HTML table view of question bank
- `tools/exportforexam*.php` — Institution-specific filtered exports (BGU, Sami)

## Known Issues / Tech Debt

- `bot_functions.php` uses raw `mysqli_query()` with string interpolation — vulnerable to SQL injection. Use `mysqli_real_escape_string()` at minimum, or migrate to prepared statements.
- No CSRF protection in admin panel.
- Global `$db` variable is used throughout; passed implicitly via `include`.
