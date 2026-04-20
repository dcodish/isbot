# Roadmap

This document tracks what the bot currently does and what we plan to build next. For system internals see [ARCHITECTURE.md](ARCHITECTURE.md).

---

## Current Features

### Core Quiz Loop
- Users receive multiple-choice questions via Telegram inline keyboard
- Question selection is **probability-based on success rate**, not fixed difficulty
- Correct answers advance a `current_run` counter; wrong answers decrement it
- Questions already answered correctly (at higher levels) are excluded from future draws

### Levels
- 4 levels with configurable upgrade/downgrade thresholds (stored in `gamification` table)
- Level 1: easy questions (≥80% success rate)
- Level 2: medium mix
- Level 3/4: progressively harder, no repeated questions

### Points System
- Points awarded per answer based on question difficulty (derived from success rate)
- Rules stored in `point_rules` table (action type × question level)
- Full transaction log in `point_log` including badge bonus rewards

### Badge System
- 30+ badge types: streaks, milestones, level-ups, time-of-day, consistency
- Progress tracking across sessions (`badge_progress` table)
- Badge room view (`/badges`) shows earned and locked badges

### Leaderboards
- All-time leaderboard (`/leaderboard`)
- Weekly leaderboard (`/leaderboard_weekly`)
- Monthly leaderboard (`/leaderboard_monthly`)

### User Onboarding & Nickname
- New users blocked until a unique nickname is set (3–15 chars, alphanumeric + underscore)
- Nickname change supported after initial setup

### Main Menu
- Inline menu for navigation (question, leaderboard, stats, badges, level info)

### User Stats
- `/stat` shows personal stats: points, level, correct/wrong counts
- `/level` shows current level and progress toward next

### Question Reporting
- Users can flag unclear questions (`Bad:ID` callback)
- Reported questions surface at the top of the admin panel list

### Survey System
- Optional research survey questions interleaved with quiz questions
- Tracked separately in `survey_questions` / `user_survey` tables

### Admin Panel (`/admin/`)
- Session-authenticated web UI (credentials via `.env`)
- Question CRUD: add, edit, delete
- Reported questions highlighted for review

### Import / Export Tools (`tools/`)
- `import.php` — bulk import from a plain-text file (6-line format per question)
- `importnew.php` — variant import flow
- `export.php` — HTML table export of the question bank
- Institution-specific exports for BGU and Sami exam formats

### Infrastructure
- Hybrid runtime: webhook on prod, polling for local dev
- Deduplication via `processed_updates` table (replay-safe)
- Debug logging under `runtime/` (local only)

### Lecture Tagging (data only — not yet exposed)
- `questions.max_lecture` column populated for all 531 questions across 12 lectures
- Distribution: L1:32, L2:40, L3:23, L4:46, L5:23, L6:20, L7:77, L8:1, L9:116, L10:60, L11:72, L12:21
- Reference: [`runtime/lecture_topic_map.md`](runtime/lecture_topic_map.md), [`runtime/tagging_final.sql`](runtime/tagging_final.sql)

---

## Active Work

### 1. Expose Lectures 1–3 to Students
> *Let students practice only on material covered in lectures 1–3 (what's been taught so far).*

**Phase A — Mapping review (in progress)**
- Review all L1, L2, L3 tagged questions for correctness
- Reassign any miscategorized questions

**Phase B — Question coverage**
- Confirm counts per lecture (currently L1:32, L2:40, L3:23) are sufficient for practice
- Author additional questions where thin

**Phase C — Student-facing filter (code)**
- `settings` table with `current_week` key (default: 3)
- Inject `WHERE max_lecture <= current_week` into `getQuestion()` selection queries
- Admin UI: read/write `current_week`
- No user-visible UI yet — just the gate

---

## Planned

### 2. Telegram Admin Command: `/setweek N`
Let the professor update `settings.current_week` from Telegram without opening the admin panel. Checks admin status in `variable_setup.php`.
Depends on: #1 Phase C.

### 3. Bot Abuse Prevention
> *Concern: students may run their own bots/scripts against the Telegram bot to farm points or game leaderboards.*

Possible mitigations to evaluate:
- Rate limiting per `user_id` (max N answers per minute)
- Human-interaction signals (minimum think-time between question sent → answer)
- Anomaly detection on suspiciously fast/perfect runs
- Captcha / survey checkpoints at milestones
No solution committed yet — needs investigation of what's actually feasible on Telegram's side.

### 4. Timezone Fix for Time-Based Badges
> *Server time differs from Israel time, so badges like "early bird" / "night owl" / daily-streak fire on the wrong clock.*

Audit all badge logic that reads server time (`time()`, `NOW()`, `DATE()`) and convert to Asia/Jerusalem. Options:
- Set MySQL session timezone at connection (`SET time_zone = 'Asia/Jerusalem'`)
- Set PHP default (`date_default_timezone_set`)
- Explicit conversion at each read site
Pick one approach and apply consistently.

### 5. Visual Personal Dashboard
> *A rich, visual stats page per user — not just text.*

Ideas to explore:
- Progress bars per lecture
- Weekly activity heatmap
- Points trend chart
- Badges earned / locked grid
- Delivery: Telegram image render (server-side chart → PNG) vs. web link (mini web view)
Needs design pass before implementation.

### 6. Week Auto-Advance
Store `semester_start_date`; compute `current_week = FLOOR(DATEDIFF(NOW(), start_date) / 7) + 1`. Hands-off operation once started.
Depends on: #1 Phase C.

### 7. Per-Lecture Leaderboard
Rank students by points earned on questions from a specific lecture. Good for per-class engagement.
Depends on: #1 Phase C.

### 8. Lecture Progress Indicator
Tell a student how many questions they've answered from the current week's lecture. e.g. "ענית על 4 מתוך 12 שאלות מהשיעור השבועי."
Depends on: #1 Phase C.

### 9. Question Coverage Report in Admin Panel
Summary table: `Lecture | # Questions | # Answered | Avg Success Rate`. Spots thin lectures.
Depends on: #1 Phase C.

### 10. Student Unlock Code
Professor shares a weekly code in class; students enter it to unlock that week's questions. Alternative to auto-advance. Adds `users.student_week` + code in `settings`.
Depends on: #1 Phase C.

### 11. CSRF Protection in Admin Panel
Add CSRF token to session, validate on every POST to `admin/backend/save.php`.

### 12. SQL Injection Hardening
Migrate raw query interpolation in `bot_functions.php` to prepared statements. Tracked in [ARCHITECTURE.md § Known Issues](ARCHITECTURE.md#known-issues--tech-debt).

---

## Ideas Backlog

Not committed to, but worth revisiting:

- **Streak freeze / lifeline** — let students "bank" a streak-save token
- **Group/team challenges** — class-wide challenge mode with shared progress
- **Notification reminders** — Telegram message to inactive users ("haven't practiced in 3 days")
- **Exam mode** — timed session with fixed question count and no hints
- **Question generation from lecture slides** — AI-assisted authoring for thin lectures
