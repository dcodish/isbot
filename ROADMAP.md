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
- **Research audit log** — every user-facing event (commands, menu clicks, answers, badge awards, survey prompts, clearstats flow) is persisted to the `log` table via `writeLog()`. See the `actions` table for the full event taxonomy.
- **Claude Desktop DB access** — read-only MCP server at `mcp/isbot-db/` tunnels via SSH and exposes `execute_query` / `list_tables` / `describe_table` to Claude Desktop. Uses SELECT-only user `isbot_ro`. Full setup in `mcp/isbot-db/README.md`.

---

## Planned

Ordered roughly by priority / dependency. Items that were blocked on lecture filtering are now unblocked.

### 1. Telegram Admin Command: `/setweek N`
Let the professor update `settings.current_week` from Telegram without opening the admin panel. Check admin user in `variable_setup.php`; validate 1–12.

### 2. ~~Week Auto-Advance~~ — rejected
**Won't do.** See [Rejected](#rejected--wont-do) below. Week advancement stays a manual, per-semester admin action by design.

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

### 6a. Topic-Level Coverage Report (management view)
Deeper drill-down per lecture: break each lecture into its key topics (from the presentation) and show how many questions cover each topic, at which complexity level. Answers "where am I over-covered? where are there blind spots? is my complexity mix balanced across topics?"

Shape:
```
Lecture 3 — Hardware & CPU
  Topic                              Basic  Applied  Analytical  Integrative  Total
  ─────────────────────────────────────────────────────────────────────────────────
  Transistors / Moore's Law            2       1         0            0         3
  Bit/Byte/Encoding                    3       2         1            0         6
  CPU architecture (ALU/registers)     2       1         1            1         5
  Memory hierarchy                     1       0         0            0         1   ⚠ thin
  GPU / NPU                            0       2         0            0         2
  ...
```

Dependencies (not trivial):
- New columns: `questions.topic` (string, free-form or FK to a topics table) and `questions.complexity` (enum: basic / applied / analytical / integrative). The question-writer agent already drafts with these labels — they just aren't persisted yet.
- Canonical topic list per lecture — `runtime/lecture_topic_map.md` is a starting point but needs a normalised form (e.g. `lecture_topics(id, lecture, topic_he)`).
- Backfill for the existing 531 questions — either bulk-classify via an LLM pass or progressively as questions get edited. For Lectures 1–3 (the ones we're exposing now) manual/assisted classification is feasible.
- Admin UI: lecture selector → topic × complexity matrix; highlight empty cells and cells below a threshold.

### 7. Bot Abuse Prevention
Concern: two threats — students scripting their account to **farm points / game leaderboards**, and any client **scraping the question bank**. Specced as **detection-first, enforcement-deferred** (no cap set blind): see **NFR-7** (requirements.md), **ADR-010** (design.md), and the feature spec [docs/features/abuse-detection.md](docs/features/abuse-detection.md).
- **Phase 1 — committed:** offline, read-only `admin/abuse.php` that flags automated-looking accounts (fast-skip spam, over-regular event timing, marathon runs) for the professor to review. Silent — no block, no message — so it doesn't tip off a scraper or lock out a real crammer. Doubles as the *measurement* that a later cap would need.
- **Phase 2 — contingent on that data, and only if abuse is seen:** delivery throttle / daily distinct-question cap (anti-exfiltration lever: text is exposed at send time, so throttle delivery not answers; cap also = ROADMAP #3a's daily-question cap) + lenient min think-time; milestone captcha/survey challenges as a last resort.

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
Professor shares a weekly code in class; students enter it to unlock that week's questions. A pull-based alternative to manual week advancement. Adds `users.student_week` + code in `settings`.

### 10. CSRF Protection in Admin Panel
Add CSRF token to session, validate on every POST to `admin/backend/save.php`.

### 11. SQL Injection Hardening
Migrate raw query interpolation in `bot_functions.php` to prepared statements. Tracked in [ARCHITECTURE.md § Known Issues](ARCHITECTURE.md#known-issues--tech-debt).

### 12. Historical DATETIME Backfill (minor)
After the timezone switch to Asia/Jerusalem, rows previously written with UTC `NOW()` into DATETIME columns display as 3 hours "earlier" than they actually occurred. Low urgency since it only affects historic audit-log reads. If and when it matters, run a one-shot `UPDATE ... = DATE_ADD(col, INTERVAL 3 HOUR)` per affected column (and handle the DST 2-hour period separately).

### 13. Admin Dashboard
A landing page for the professor at `/admin/` that surfaces the state of the system at a glance, rather than requiring SQL or drilling through separate CRUD pages. Consolidates several of the individual items below into one overview:
- **Engagement**: total active users, new users this week, sessions this week, answers per day (sparkline), median answers per student
- **Content health**: question bank size by lecture (feeds #6), thin-coverage warnings, reported-bad queue count
- **Difficulty distribution**: count of questions in each success-rate band, probation-pool size (`numofanswers < 5`)
- **Student progress snapshot**: distribution of students across levels 1–4, average `current_run`, leaderboard top-10 preview
- **Badge distribution**: earned counts per badge type (which are chased, which are dormant)
- **Research audit**: recent log entries (last 50 rows from the `log` table with action names), CSV export link for the full log + point_log for research
- **Settings summary**: current_week, session_gap_minutes, with links to the unified settings page (#3a)
- Primary widgets link to their detail pages (e.g. coverage report, question list filtered by lecture, leaderboard detail).
Eventually this becomes the default admin landing — the existing questions-CRUD moves behind a "Manage Questions" link.

---

## Rejected / Won't Do

- **Week Auto-Advance** (was #2) — computing a cohort's `current_week` automatically from `semester_start_date` (`FLOOR(DATEDIFF(NOW(), start_date)/7)+1`). **Rejected to preserve flexibility:** the professor needs to hold, skip, or roll back a semester's week to match how a class is actually pacing (holidays, makeup weeks, a lecture that ran long). An automatic clock would override that judgement. Week advancement stays a deliberate per-semester admin action in `admin/cohorts.php`. The `cohorts.semester_start_date` column already exists and is harmless — it is left in place as informational metadata, **not** wired to any auto-advance logic. The manual alternative #9 (Student Unlock Code) remains open if a pull-based model is ever wanted.

---

## Ideas Backlog

Not committed to, but worth revisiting:

- **Streak freeze / lifeline** — let students "bank" a streak-save token
- **Group/team challenges** — class-wide challenge mode with shared progress
- **Notification reminders** — Telegram DM to inactive users ("haven't practiced in 3 days")
- **Exam mode** — student-facing timed practice exam (10 Q, 20 min, stratified,
  graded, per-lecture feedback). **Now specced** → [docs/features/exam-mode.md](docs/features/exam-mode.md)
  (FR-EXM-*, ADR-012); ready to build.
- **Question generation from lecture slides** — AI-assisted authoring for thin lectures (the question-writer agent already does this interactively; automate as a batch?)
- **Calendar-aligned leaderboards** — switch from rolling 7/30-day windows to calendar weeks/months. `WHERE`-clause change in `fetchRollingEntries()`. See [ARCHITECTURE.md § Leaderboards](ARCHITECTURE.md#leaderboards).
- **Leagues** (cohorts of ~20 users) — if user base grows past ~50, global leaderboards become noise. Cohort-based leagues à la Duolingo would keep competition proximal.
- **First-time question reveal** — if someone answers a probation question, surface "אתה הראשון" style framing more prominently (we hint at it now in the stat message, but could be celebrated).
