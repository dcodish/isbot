# Roadmap

This document tracks what the bot currently does and what we plan to build next. For system internals see [ARCHITECTURE.md](ARCHITECTURE.md).

---

## Current Features

### Core Quiz Loop
- Multiple-choice questions via Telegram inline keyboard
- Question selection **probability-based on success rate**, not fixed difficulty
- New questions (< 5 answers) routed through a "probation" pool that every level samples, so they accumulate data before classification
- Correct answers advance `current_run`; wrong answers decrement it
- Questions already answered correctly (at higher levels) are excluded from future draws
- RTL-safe rendering for mixed Hebrew/Latin option text
- "First answer" message instead of misleading "0% correct" for previously unanswered questions

### Levels
- 4 levels with configurable upgrade/downgrade thresholds (`gamification` table)
- Level 1: easy (≥80% success rate)
- Level 2: 70–80% / 80%+ mix
- Level 3/4: progressively harder, no repeated questions

### Points System
- Points per answer based on inferred question difficulty
- Rules in `point_rules` (action_type × question_level)
- Full audit log in `point_log` including badge bonuses

### Badge System
- 20 active badges: streaks, milestones, level-ups, time-of-day, consistency
- Progress tracking in `badge_progress`
- **Trophy closet view** (`/menu` → 🏅): 4×5 composite image with earned badges in color, locked in grayscale; text caption lists titles + dates

### Leaderboards (hybrid motivational design)
- Three views: all-time, weekly (rolling 7 days), monthly (rolling 30 days)
- **Display rule:** podium (ranks 1–3) ∪ local window (3 above + 3 below the viewer), with separator line when podium and window aren't contiguous
- **Next-goal delta:** "X points to overtake {user above}" for everyone except rank 1 and not-on-board
- 👑 message for rank 1; onboarding nudge for users not yet on the board
- See [ARCHITECTURE.md § Leaderboards](ARCHITECTURE.md#leaderboards) for full rule table

### Lecture-Based Question Filtering
- `questions.max_lecture` populated for all 531 questions across 12 lectures
- `settings.current_week` setting gates which lectures students see (via `max_lecture ≤ current_week`)
- Injected into all 20 question-selection queries in `getQuestion()`
- Admin panel: `current_week` form + per-lecture column + filter dropdown

### User Onboarding & Nickname
- New users blocked until unique nickname set (3–15 chars, `[a-zA-Z0-9_]`)
- Nickname change supported after initial setup

### Main Menu
- 3-item inline menu: Play / Badges / Leaderboards (sub-menu)

### User Stats & Commands
- `/stat` — points, level, correct/wrong counts
- `/level` — current level + progress to next
- Slash-command shortcuts for leaderboards (`/leaderboard`, `/leaderboard_weekly`, `/leaderboard_monthly`) kept as hidden power-user aliases

### Question Reporting
- Users flag unclear questions (`Bad:ID` callback); reported questions surface at top of admin list

### Survey System
- Optional research survey questions interleaved with quiz
- Tracked in `survey_questions` / `user_survey`

### Admin Panel (`/admin/`)
- Session-authenticated web UI
- Question CRUD, lecture column, per-lecture filter
- `current_week` setting form
- Reported questions highlighted

### Import / Content Tools (`tools/`)
- `import.php` / `importnew.php` — legacy 6-line text-file imports
- `insert_questions.php` — JSON-to-DB via prepared statements (used by the question-writer agent)
- `export.php` and institution-specific exports (BGU, Sami)

### Authoring — question-writer subagent
- Project-level Claude Code agent (`.claude/agents/question-writer.md`)
- Drafts Hebrew questions against the lecture topic map + transcripts, iterates with the user, writes approved questions straight to the DB

### Infrastructure
- Hybrid runtime: webhook on prod, polling for local dev
- Deduplication via `processed_updates` table
- Debug logging under `runtime/` (local only)
- **Timezone:** PHP and MySQL session both set to Asia/Jerusalem so badges/streaks/leaderboards agree on clock

---

## Planned

Ordered roughly by priority / dependency. Items that were blocked on lecture filtering are now unblocked.

### 1. Telegram Admin Command: `/setweek N`
Let the professor update `settings.current_week` from Telegram without opening the admin panel. Check admin user in `variable_setup.php`; validate 1–12.

### 2. Week Auto-Advance
Store `semester_start_date` in `settings`. Bot computes `current_week = FLOOR(DATEDIFF(NOW(), start_date) / 7) + 1`. Hands-off operation once the semester starts.

### 3. max_lecture in Admin Add/Edit Forms
The admin panel shows a `שיעור` column and lets you filter by it, but the Add/Edit modals don't include a field for it. Authors have to use the question-writer agent or SQL to set/change `max_lecture`. Fill in the modal fields.

### 3a. Unified Settings Admin Page
Consolidate all tunable parameters into one admin UI so the professor doesn't need SQL to adjust the bot's behaviour. Currently scattered:
- `settings` table: `current_week`, `session_gap_minutes` (and future keys)
- `gamification` table: per-level `upgrade_at` / `downgrade_at` thresholds
- `point_rules` table: points per (action × question level)
- Hardcoded constants in PHP worth exposing: probation threshold (`numofanswers < 5`), probation percentages per level (30/25/20/15), leaderboard window size (3 above/below in `renderLeaderboard`), badge thresholds in `BadgeService`
- Future: daily question cap, rate-limit windows, skip-rate thresholds (once those land)

Work split naturally:
- Move hardcoded values into the `settings` table with sensible keys and defaults
- Build a single admin page that auto-renders a form row for every `settings` row plus edit forms for `gamification` / `point_rules` tables
- Validate ranges on update (e.g. percentages 0–100, minutes 1–1440)

### 4. Per-Lecture Leaderboard
Rank students by points earned on questions within a specific lecture. Good for per-class engagement and highlighting recent-material mastery.

### 5. Lecture Progress Indicator
Show a student how many questions they've answered from the current week's lecture. e.g. "ענית על 4 מתוך 12 שאלות מהשיעור השבועי."

### 6. Question Coverage Report in Admin Panel
Summary table: `Lecture | # Questions | # Answered | Avg Success Rate`. Spots thin lectures at a glance.

### 7. Bot Abuse Prevention
Concern: students running scripts against the bot to farm points / game leaderboards. Mitigations to evaluate:
- Rate limiting per `user_id` (max N answers per minute)
- Minimum think-time between question sent → answer
- Anomaly detection on suspiciously fast/perfect runs
- Captcha / survey checkpoints at milestones

### 8. Student Learning Dashboard
A rich, pedagogically-oriented stats view — not just numbers, but **actionable insight into what a student has mastered and where their gaps are**. Should answer: "how am I doing, which lectures should I review, where should I focus next?"

Content:
- **Per-lecture breakdown**: for each lecture the student has engaged with — questions attempted, success rate, average time-to-answer, last practised date. Colour-coded bars (e.g. green ≥80%, yellow 60–79%, red <60%).
- **Gap analysis**: lectures with success rate below a threshold, surfaced as "שיעורים שכדאי לחזור עליהם" with direct recommendations.
- **Trajectory**: points / correct rate over the last 7 and 30 days — is the student improving, plateauing, slipping?
- **Engagement**: weekly activity heatmap / streak calendar.
- **Achievements panel**: badges earned + next-closest unearned badges with progress bars (tie in to the trophy closet).
- **Comparison row (optional)**: student's per-lecture success rate vs class average. Careful framing so it informs rather than discourages — e.g. show only as "you vs median of active students."

Data model: most of this is already queryable — `point_log`, `user_q`, `questions.max_lecture`, `user_badges`, `badge_progress` together give everything we need. Per-question history via `user_q` enables accurate per-lecture success rate computation.

Delivery options:
- **Telegram image**: one composite PNG per request (server-side chart via GD/Imagick). Heavy but self-contained.
- **Mini web view**: a dedicated `/dashboard/<token>` page rendered in the Telegram in-app browser. Richer interaction (hover, drill-down), cheaper server-side (no image generation), but adds an auth story.
- **Hybrid**: text summary in Telegram + a link to the web view for drill-down.

Needs design pass first — decide on delivery channel, wireframe the layout, pick the 5–7 most useful widgets before building. Replaces the current `/stat` and `/level` commands' narrow views.

### 9. Student Unlock Code
Professor shares a weekly code in class; students enter it to unlock that week's questions. Alternative to auto-advance. Adds `users.student_week` + code in `settings`.

### 10. CSRF Protection in Admin Panel
Add CSRF token to session, validate on every POST to `admin/backend/save.php`.

### 11. SQL Injection Hardening
Migrate raw query interpolation in `bot_functions.php` to prepared statements. Tracked in [ARCHITECTURE.md § Known Issues](ARCHITECTURE.md#known-issues--tech-debt).

### 12. Historical DATETIME Backfill (minor)
After the timezone switch to Asia/Jerusalem, rows previously written with UTC `NOW()` into DATETIME columns display as 3 hours "earlier" than they actually occurred. Low urgency since it only affects historic audit-log reads. If and when it matters, run a one-shot `UPDATE ... = DATE_ADD(col, INTERVAL 3 HOUR)` per affected column (and handle the DST 2-hour period separately).

---

## Ideas Backlog

Not committed to, but worth revisiting:

- **Streak freeze / lifeline** — let students "bank" a streak-save token
- **Group/team challenges** — class-wide challenge mode with shared progress
- **Notification reminders** — Telegram DM to inactive users ("haven't practiced in 3 days")
- **Exam mode** — timed session with fixed question count and no hints
- **Question generation from lecture slides** — AI-assisted authoring for thin lectures (the question-writer agent already does this interactively; automate as a batch?)
- **Calendar-aligned leaderboards** — switch from rolling 7/30-day windows to calendar weeks/months. `WHERE`-clause change in `fetchRollingEntries()`. See [ARCHITECTURE.md § Leaderboards](ARCHITECTURE.md#leaderboards).
- **Leagues** (cohorts of ~20 users) — if user base grows past ~50, global leaderboards become noise. Cohort-based leagues à la Duolingo would keep competition proximal.
- **First-time question reveal** — if someone answers a probation question, surface "אתה הראשון" style framing more prominently (we hint at it now in the stat message, but could be celebrated).
