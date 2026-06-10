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

Progression controlled by the `current_run` counter in the `users` table vs. per-level `upgrade_at` / `downgrade_at` thresholds in the `gamification` table — **independent of `overall_points`**. Correct answers increment `current_run`, wrong answers decrement; hitting `upgrade_at` promotes (and resets `current_run` to 0), dropping below `downgrade_at` demotes. **Level 4 is the cap:** there is no promotion, and correct answers are capped at `current_run = 0` so the run can't bank an unbounded cushion — that keeps demotion reachable. With L4 `downgrade_at = -3`, **four wrong answers in a row** (a correct one offsets a single wrong) demote back to level 3. See ADR-011 in [docs/design.md](docs/design.md).

**Probation pool for new questions.** Questions with `numofanswers < 5` are treated as "unrated" and are sampled by every level at fixed rates (L1 30% / L2 25% / L3 20% / L4 15%) before the normal success-rate buckets are consulted. Without this, a single wrong first answer would put the question at 0% success rate and strand it in L4 forever; a single right answer would lock it to L1. After 5 answers accumulate, the regular bands classify it naturally. See `getQuestion()` in `bot_functions.php`.

**Lecture filter.** Every selection query is gated by `(max_lecture IS NULL OR max_lecture <= $current_week)` where `$current_week` comes from `settings.current_week`. NULL means "always visible". Students see only material covered in lectures 1..current_week.

### Points System
Points awarded per answer based on question difficulty inferred from success rate. Rules stored in `point_rules` table (action_type × question_level → points). All transactions logged in `point_log`, including badge bonus rewards (stored with `question_id IS NULL`).

### Badge System (`BadgeService.php`)
Badges awarded for streaks, milestones, level-ups, time-of-day, consistency, and more. Tracked in `badges`, `user_badges`, and `badge_progress` tables. Call `BadgeService` methods after each answer in `variable_setup.php`.

**Trophy closet view.** `showBadgesRoom()` renders a single 4×5 composite image (`buildBadgeClosetImage()` in `bot_functions.php`) with earned badges in full color and locked ones grayscaled. Badge assets live in `/badges/*.webp` (one-time extracted from each badge's Telegram `sticker_file_id`). Compositing uses Imagick (Plesk PHP 8.2); animated webp badges render their first frame via the `[0]` suffix. Because Telegram photo captions don't reliably honor RTL paragraph alignment, the image is sent with no caption and a separate text message with the earned list follows — two messages total.

### Settings & Tunable Parameters
The `settings` table (`setting_key VARCHAR(64) PRIMARY KEY, setting_value VARCHAR(255), updated_at TIMESTAMP`) is the central key/value store for runtime-tunable behaviour. Current keys:
- `current_week` (1–12) — the **global default** lecture-filter gate, used only as a fallback for users with no `cohort_id`; cohort-assigned users resolve their week from `cohorts.current_week` instead (see Cohorts). Students see questions with `max_lecture ≤ week`
- `session_gap_minutes` (integer) — inactivity threshold before a new session begins and previous-session questions are wiped (default 30)
- `cohort_gate_enabled` (0/1) — when 1, new users must pick a semester after setting a nickname

Readers: `getCurrentWeek()` / `getSessionGapMinutes()` in `bot_functions.php`, each caches the value statically within one request. Writers: `admin/cohorts.php` (ניהול סמסטרים) writes per-cohort weeks plus the global fallback `current_week` and the `cohort_gate_enabled` toggle; `admin/home.php` is a read-only dashboard that surfaces active semesters and their weeks. Other keys are still SQL-only until the unified settings admin page ships (roadmap #3a).

### Session Management & Content-Theft Mitigation
Every question message sent to a user (stem + "what's the correct answer?" prompt) is logged to `session_question_messages` with its `message_id`. On every interaction, `maybeStartNewSession()` in `bot_functions.php` checks `users.last_interaction_at` against `settings.session_gap_minutes`. If the gap is exceeded, uncleaned rows for that user are cleaned via `deleteMessage` (for messages ≤ 48h old) or `editMessageText` to "שאלה זו הוסרה" as fallback. The current interaction then proceeds; `last_interaction_at` is updated to `NOW()`.

Only question messages are tracked — feedback, stats, leaderboards, badges, and menus stay visible across sessions for review value.

### Question Authoring (`question-writer` subagent)
A project-level Claude Code subagent at `.claude/agents/question-writer.md` drafts new Hebrew questions against `runtime/lecture_topic_map.md` and the lecture transcripts in `…/מבוא למערכות מידע/הרצאות/2026/2026-2/complete lessons/תמלולים/`. Approved drafts are inserted into the DB via `tools/insert_questions.php` (prepared statements). Default batch size is 10 questions; integrative questions are tagged with the highest lecture number involved so they respect the `max_lecture <= current_week` filter correctly.

### Leaderboards
Three views — all-time, weekly, monthly. All three share the same renderer
(`renderLeaderboard()` in `bot_functions.php`); the three `show*` functions are
thin wrappers that just fetch the eligible rows and pass a title.

**Data sources (tie-break: `id ASC` throughout):**
- **All-time:** reads `users.overall_points` directly. Filter: `nickname IS NOT NULL AND overall_points > 0`.
- **Weekly:** `SUM(point_log.points_change) WHERE timestamp >= NOW() - INTERVAL 7 DAY`, `HAVING sum > 0`.
- **Monthly:** same, with `INTERVAL 30 DAY`.

**Rolling windows (by design).** Weekly/monthly use sliding 7/30-day intervals
anchored to `NOW()`, not calendar-aligned periods (Sun–Sat, 1st–end-of-month).
A student who earned points on Sunday will start dropping off the weekly board
next Sunday. If this ever needs to become calendar-aligned for teacher-facing
comms or reset rituals, it's a `WHERE` clause change in `fetchRollingEntries()`.

#### Display rules (motivational hybrid)

The standard leaderboard shape "top 10 + user's position" demotivates everyone
outside the top 10 (Landers 2017; Hamari & Koivisto; goal-gradient literature).
We instead blend an aspirational podium with a local window so every user sees
themselves surrounded by nearby peers.

**Baseline:** podium (ranks 1–3) ∪ local window (`user_rank − 3` to
`user_rank + 3`), clamped to `[1, total]`. If the union leaves a gap between
the podium and the window, a separator line (`━━━`) is inserted.

**Rank-by-rank behaviour** (with window = 3 above + 3 below):

| Viewer's rank | Displayed ranks | Separator | Footer |
|---|---|---|---|
| 1 | 1..min(4, total) | none | 👑 "אתה בראש הטבלה" |
| 2 or 3 | 1..min(user+3, total) | none | "נותרו X נקודות לעקוף את Y" |
| 4..7 | 1..min(user+3, total) — contiguous with podium | none | next-goal delta |
| 8+ | 1..3 + `━━━` + (user−3)..min(user+3, total) | yes | next-goal delta |
| Last in non-empty board | same as above, but window clamps at `total` | as above | next-goal delta (to rank above) |
| Not on the board (0 pts or no activity in window) | just the podium | implicit | "עדיין לא הופעת בטבלה — ענה על שאלות כדי להיכנס" |

**Small-pool edge cases:**
- **0 entries:** "אין עדיין משתתפים בטבלה."
- **1–3 entries:** podium only; no window, no separator.
- **4+ entries:** rules above. At 4–10 total the union naturally covers
  everyone, so no separator appears.

**Next-goal delta** (`נותרו X נקודות לעקוף את Y`): `X` is the gap to the
user immediately above plus 1 (so the user knows exactly how many points
breaks the tie and overtakes). Shown for every rank except 1 and "not on
board".

**"You" marker:** `← אתה!` suffix on the user's row. Medals 🥇🥈🥉 for ranks
1–3; numeric `N.` prefix for ranks 4+.

### Nickname System
New users are blocked from playing until a unique nickname is set (3–15 chars, `[a-zA-Z0-9_]`). The `awaiting_nickname` flag in `users` table gates all other commands in `checkNicknameRequired()`.

### Admin Panel (`/admin/`)
Session-authenticated web UI for question CRUD. Credentials from `.env`. Questions reported as unclear (`reportedbad` counter) appear at top of list. All DB operations in `admin/backend/save.php`.

**Stats vs. analytics (two pages, deliberately split).**
- `admin/stats.php` — *descriptive*: summary counts, a daily answers/active-users chart, and a per-user engagement table. Answers "how much is happening".
- `admin/analytics.php` — *analytical*: measures the **impact of gamification on usage** from the `log` audit table. Five panels: (1) a **within-user event study** — answer volume in the `win` days before vs after each badge/level-up/leaderboard check, excluding events whose after-window hasn't elapsed; (2) **lifespan-based D1/D7/D30 retention** split by whether the user hit a gamification element in their first 24h, plus a per-signup-week cohort table; (3) a lifecycle **funnel** (start → nickname → answer → return → 7-day); (4) **reach** (% of active users per element); (5) **dead-badge** detection (badges with 0 earns). Read-only, no schema change; portable SQL (no CTEs — derived tables + correlated subqueries). Because `users` has no signup column, "first seen" is derived from `MIN(log.timestamp)`. The page leads with an explicit observational-data caveat — cross-sections are selection, the event study is the defensible within-user signal. Design rationale in [docs/design.md](docs/design.md) ADR-009; full spec in [docs/features/gamification-analytics.md](docs/features/gamification-analytics.md).

## Database Tables

| Table | Purpose |
|---|---|
| `users` | User state: level, current_run, overall_points, nickname, awaiting_nickname, last_interaction_at |
| `questions` | Question bank with success tracking (numofanswers, numofcorrectanswers, reportedbad, max_lecture) |
| `user_q` | Per-user question history (numofsuccess, numoffailure) |
| `gamification` | Level up/down thresholds per level (columns: level, upgrade_at, downgrade_at) |
| `point_rules` | Points awarded per (action_type, question_level) |
| `point_log` | Full audit log of point transactions (includes badge bonuses with `question_id IS NULL`) |
| `badges` / `user_badges` / `badge_progress` | Badge definitions, earned badges, progress |
| `survey_questions` / `user_survey` | Optional research survey interleaved with quiz |
| `processed_updates` | Deduplication of Telegram update_ids |
| `session_question_messages` | Per-user log of sent question message_ids for session-boundary cleanup |
| `settings` | Key/value store for tunable parameters (`current_week`, `session_gap_minutes`, …) |
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
- `tools/insert_questions.php` — Prepared-statement batch insert from a JSON file; used by the question-writer subagent
- `tools/export.php` — HTML table view of question bank
- `tools/exportforexam*.php` — Institution-specific filtered exports (BGU, Sami)

One-off DB changes live in `migrations/YYYY-MM-DD_description.sql`. Apply via `scp` + `mysql < file` on prod. All migrations are idempotent (`IF NOT EXISTS`, `ON DUPLICATE KEY UPDATE`) so re-running is safe.

## Known Issues / Tech Debt

- `bot_functions.php` uses raw `mysqli_query()` with string interpolation — vulnerable to SQL injection. Use `mysqli_real_escape_string()` at minimum, or migrate to prepared statements.
- No CSRF protection in admin panel.
- Global `$db` variable is used throughout; passed implicitly via `include`.
