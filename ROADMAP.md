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

---

## Planned Features

Items are roughly ordered by priority. Each item links to a detailed plan if one exists.

### 1. Lecture-Based Question Filtering *(tagging done — mapping needs review before continuing)*
> *Filter questions to only those covered in lectures so far in the semester.*

Students currently see all questions regardless of course progress. This feature tags each question with the lecture it belongs to, and a "current week" setting controls which questions are visible.

**Plan:** [`C:\Users\david\.claude\plans\polymorphic-chasing-marshmallow.md`](C:\Users\david\.claude\plans\polymorphic-chasing-marshmallow.md)

**Status:** The `max_lecture` column has been added and all 531 questions have been tagged (values 1–9). The tagging SQL is in `runtime/tagging_confident.sql` and has been applied to production.

> ⚠️ **STOP — mapping must be reviewed before going further.**
>
> The tagging process only read lectures 1–9. The course has lectures 1–12 (lectures 10–12 exist but were not read). As a result, all questions were force-mapped to lectures 1–9, which is almost certainly wrong — questions that actually belong to L10, L11, or L12 have been misassigned to L9 (the last lecture processed). The `max_lecture` values in production should not be used for filtering until the lecture 10–12 PDFs have been read and the affected questions re-mapped correctly.
>
> **Before continuing:** obtain the L10, L11, L12 lecture materials, re-run the tagging analysis for those lectures, and issue corrective UPDATE statements to move questions from L9 to their true lecture.

Scope:
- `ALTER TABLE questions ADD COLUMN max_lecture` — NULL = always visible ✅ done
- One-time tagging SQL — ✅ applied (needs revision for L10–12)
- New `settings` table with `current_week` key
- Filter injected into all question-selection queries in `getQuestion()`
- Admin panel: lecture column, lecture field in add/edit forms, current week control
- Import tool: optional 7th line or `#lecture:N` file header

### 2. Telegram Admin Command: `/setweek N`
> *Let the professor update the current week from Telegram without opening the admin panel.*

Add a check in `variable_setup.php` for admin users sending `/setweek N`. Writes to `settings.current_week`.

Depends on: feature #1.

### 3. Week Auto-Advance
> *Calculate the current week automatically from the semester start date.*

Store `semester_start_date` in `settings`. Bot computes `current_week = FLOOR(DATEDIFF(NOW(), start_date) / 7) + 1`. Useful for hands-off operation mid-semester.

Depends on: feature #1.

### 4. Per-Lecture Leaderboard
> *Show ranking filtered to questions from a specific lecture.*

Good for per-class engagement and highlighting students who mastered recent material. New leaderboard query joining `point_log` through `questions.max_lecture`.

Depends on: feature #1 (lecture tagging).

### 5. Lecture Progress Indicator
> *Tell a student how many questions they've answered from the current week's lecture.*

Message like: "ענית על 4 מתוך 12 שאלות מהשיעור השבועי." Shown after each answer or on `/stat`.

Depends on: feature #1.

### 6. Question Coverage Report in Admin Panel
> *Show how many questions exist per lecture so the professor can spot thin lectures.*

A summary table in the admin panel: `Lecture | # Questions | # Answered (total) | Avg Success Rate`.

Depends on: feature #1 (lecture tagging).

### 7. Student Unlock Code
> *Professor shares a weekly code in class; students enter it to unlock that week's questions.*

Alternative to auto-advance — blends student agency with controlled access. Adds a `student_week` column to `users`, unlocked by a code stored in `settings`.

Depends on: feature #1.

### 8. Question Generation from Lecture Slides
> *Generate new questions from lecture PDFs for lectures with thin coverage.*

Content task (not purely code): read lecture PDFs, generate 10–20 questions per lecture in import format with `#lecture:N` header, import via updated tool.

Depends on: feature #1 (import tool with lecture header support).

### 9. CSRF Protection in Admin Panel
> *Add a CSRF token to all admin forms.*

Currently there is no CSRF protection. Add a token to session, validate on every POST to `admin/backend/save.php`.

### 10. SQL Injection Hardening
> *Migrate raw query interpolation in `bot_functions.php` to prepared statements.*

Tracked in [ARCHITECTURE.md § Known Issues](ARCHITECTURE.md#known-issues--tech-debt). Low urgency (internal tool, controlled user base) but should be addressed before any public deployment.

---

## Ideas Backlog

Not committed to, but worth revisiting:

- **Streak freeze / lifeline** — let students "bank" a streak-save token
- **Group/team challenges** — class-wide challenge mode with shared progress
- **Notification reminders** — Telegram message to inactive users ("haven't practiced in 3 days")
- **Exam mode** — timed session with fixed question count and no hints
