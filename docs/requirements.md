# System Requirements Specification (SRS)

**System:** isbot — a Telegram-based gamified quiz bot for an IE&M (Information
Systems) course at Ben-Gurion University.
**Status:** as-built baseline (reverse-engineered from the running system) +
planned items. Last updated 2026-06-08.

This is the system-wide requirements baseline. Per-feature specifications live
in [features/](features/) and extend the IDs defined here. The living technical
reference is [../ARCHITECTURE.md](../ARCHITECTURE.md); the backlog is
[../ROADMAP.md](../ROADMAP.md).

Each requirement is tagged **built** (in production), **planned** (agreed, not
built), or **proposed** (idea, undecided).

---

## 1. Purpose & Scope

Deliver short quiz questions to students through Telegram, adapt difficulty to
each student's demonstrated ability, and motivate continued engagement through
points, levels, badges, and leaderboards — while restricting the question pool
to material already taught. The bot also doubles as a data source for research
on gamified learning.

## 2. Actors

| Actor | Description |
|---|---|
| **Student** | Course participant interacting via Telegram. Anonymous-ish (nickname only). |
| **Professor / Admin** | Manages questions, controls how much material is exposed, monitors stats. Uses the web admin panel. |
| **Researcher** | Consumes the audit logs / survey responses for analysis. Read-only. |

---

## 3. Functional Requirements

### 3.1 Onboarding & Identity (FR-ONB)
- **FR-ONB-1** *(built)* — A new user must set a unique nickname (3–15 chars,
  `[a-zA-Z0-9_]`) before any other command is accepted. Enforced by
  `checkNicknameRequired()` via the `users.awaiting_nickname` flag.
- **FR-ONB-2** *(planned)* — After setting a nickname, a new user must select a
  cohort/group before playing. See [features/cohorts.md](features/cohorts.md)
  (FR-COH-3). This adds a second mandatory onboarding gate.

### 3.2 Question Delivery (FR-Q)
- **FR-Q-1** *(built)* — The bot serves one question at a time as a Telegram
  message with inline-keyboard answer options.
- **FR-Q-2** *(built)* — Question selection is probability-based on each
  question's observed success rate, bucketed by the user's level (L1: ≥80%
  success … L3/L4: hardest, no repeats).
- **FR-Q-3** *(built)* — New/unrated questions (`numofanswers < 5`) are sampled
  by every level at fixed rates (probation pool) to avoid single-sample
  misclassification.
- **FR-Q-4** *(built, superseded by FR-COH-5)* — The question pool is gated by a
  lecture filter `(max_lecture IS NULL OR max_lecture <= current_week)` so
  students only see material taught so far. Currently `current_week` is a single
  global value in `settings`; the cohorts feature makes it per-group.
- **FR-Q-5** *(built)* — Users can skip a question and report a question as
  unclear (`reportedbad`).

### 3.3 Gamification (FR-GAM)
- **FR-GAM-1** *(built)* — Users progress through 4 levels via a `current_run`
  counter measured against per-level thresholds in `gamification`, **independent
  of `overall_points`**. Level 4 (the cap) holds `current_run ≤ 0` so demotion
  stays reachable: four wrong answers in a row drop back to level 3 (a correct
  offsets one). See design.md ADR-011.
- **FR-GAM-2** *(built)* — Points are awarded per answer by (action × question
  level) rules in `point_rules`; every transaction is logged in `point_log`.
- **FR-GAM-3** *(built)* — 30+ badges awarded for streaks, milestones,
  level-ups, time-of-day, consistency, etc. Badge bonus points must be written
  to **both** `users.overall_points` and `point_log` (with `question_id = NULL`).
- **FR-GAM-4** *(built)* — A trophy-closet composite image shows earned (color)
  vs locked (grayscale) badges.
- **FR-GAM-5** *(built)* — A player **stats card** (`/stats` command + main-menu
  button) consolidates the gamification picture in one message: level + progress
  to next (or top-level demotion risk), points + all-time rank, weekly standing,
  accuracy, coverage of the cohort-available pool, current streak, badge count,
  and an **estimated exam grade** — % correct over the most recent 40 answers
  (the exam is 40 questions), shown only at a full 40-answer window; pass = 56.
  See ARCHITECTURE.md §Player Stats Card.

### 3.4 Leaderboards (FR-LB)
- **FR-LB-1** *(built)* — Three leaderboards: all-time, weekly (rolling 7-day),
  monthly (rolling 30-day). Rolling windows anchored to `NOW()`, by design.
- **FR-LB-2** *(built)* — Motivational hybrid display: aspirational podium
  (ranks 1–3) ∪ local window around the viewer (±3), with a next-goal delta, so
  users outside the top 10 still see themselves among nearby peers.
- **FR-LB-3** *(proposed)* — Optional small per-cohort colour indicator next to
  names. Non-critical; see [features/cohorts.md](features/cohorts.md) (FR-COH-6).
  Per-cohort *scoping* of leaderboards is explicitly **out of scope** for now
  (no teams/competitions yet).

### 3.5 Sessions (FR-SES)
- **FR-SES-1** *(built)* — Every question message sent is logged to
  `session_question_messages`. After `settings.session_gap_minutes` of
  inactivity, prior-session question messages are deleted (or edited to a
  placeholder if older than Telegram's 48h delete window). Non-question messages
  persist for review value.

### 3.6 Administration (FR-ADM)
- **FR-ADM-1** *(built)* — Session-authenticated web admin panel for question
  CRUD; reported-unclear questions surface at the top.
- **FR-ADM-2** *(built)* — Admin can set the global `current_week`.
- **FR-ADM-3** *(built)* — Descriptive stats dashboard (`admin/stats.php`):
  summary counts, daily activity chart, per-user engagement table.
- **FR-ADM-6** *(built)* — Gamification **analytics** dashboard
  (`admin/analytics.php`): measures the impact of gamification elements on usage
  via a within-user event study, retention splits, a lifecycle funnel, reach, and
  dead-badge detection. Read-only; observational-data caveats made explicit. See
  [features/gamification-analytics.md](features/gamification-analytics.md)
  (FR-AN-*) and design.md ADR-009.
- **FR-ADM-4** *(planned)* — A central admin hub linking the sections
  (Questions, Stats, Cohorts, future settings) instead of separate entry pages.
  See [features/cohorts.md](features/cohorts.md) (FR-COH-7).
- **FR-ADM-5** *(planned)* — Admin manages cohorts (create/edit/deactivate,
  per-group week). See FR-COH-1/2.

### 3.7 Research & Survey (FR-RES)
- **FR-RES-1** *(built)* — Optional survey questions interleaved with the quiz,
  stored in `user_survey`.
- **FR-RES-2** *(built)* — Every user-facing event is recorded in the `log`
  audit table; point transactions in `point_log` — both available for research
  export.
- **FR-RES-3** *(built)* — The `log` audit table is analysed in-product by the
  gamification analytics dashboard (FR-ADM-6) to study the effect of badges,
  levels, and leaderboards on engagement. See
  [features/gamification-analytics.md](features/gamification-analytics.md).

### 3.8 Question Authoring & Tooling (FR-TOOL)
- **FR-TOOL-1** *(built)* — A `question-writer` Claude subagent drafts Hebrew
  questions from lecture transcripts; approved drafts insert via
  `tools/insert_questions.php` (prepared statements). New questions seed at
  `difficulty = 1`; the bot reclassifies from answer success-rate.
- **FR-TOOL-2** *(built)* — Filtered exam exports (BGU, Sami) under `tools/`.

### 3.9 Exam Mode (FR-EXM)
- **FR-EXM-1** *(built)* — A student-facing **practice exam**, started by the
  **`/exam-mode` command** (alias `/exam`) or the **📝 מבחן תרגול** menu
  button: 10 questions pulled live from the bank, **stratified** across lectures
  (`max_lecture ≤ current_week`) and success-rate levels, under a **20-minute**
  timer with auto-submit at expiry. Grade is 0–100 (10 pts/question); pass = 56.
  Counts and timer length are `settings` knobs (`exam_num_questions`,
  `exam_time_minutes`, `exam_pass_grade`). See
  [features/exam-mode.md](features/exam-mode.md).
- **FR-EXM-2** *(built)* — Exam answers **count as normal practice**: routed
  through `recordAnswer()` so points, leveling (incl. the L4 cap, FR-GAM-1), and
  badges all apply. Exam question messages are logged and session-cleaned exactly
  like practice questions (FR-SES-1). Feedback is **immediate per question**
  (✓/✗ + correct answer), with a final results screen.
- **FR-EXM-3** *(built)* — Each attempt and every answered question is
  persisted (`exam_attempts` / `exam_attempt_questions`, with a snapshot of each
  question's `max_lecture`) so grades and per-lecture performance survive later
  re-tagging.
- **FR-EXM-4** *(built)* — Student feedback views: a **grade-over-time graph**,
  the **average of the latest 3 attempts**, and a **per-lecture strength
  breakdown** (weakest first) so revision can be targeted.
- **FR-EXM-5** *(built)* — **Unlimited retakes**, fresh question selection each
  attempt (no repeats within an attempt). Dangling `in_progress` attempts are
  finalized defensively (lazy expiry on the timer; auto-expired when a new exam
  starts), since the webhook runtime has no background clock.
- **FR-EXM-6** *(built)* — A **"הפסק מבחן" (stop)** control lets the student
  abandon an exam mid-way (behind a confirm). No **graded result** is kept — the
  attempt leaves no trace in the student's grade history, graph, latest-3 average,
  or per-lecture stats — but the answers already given **still count** as normal
  practice (the per-answer `recordAnswer()` writes are not rolled back, FR-EXM-2).
- **FR-EXM-7** *(built)* — The full exam lifecycle is recorded in the **`log`
  audit table** for research (FR-RES-2), independent of the personal-stats record:
  new events `ExamStart` (36), `ExamCompleted` (37, on finish **or** timer
  expiry), `ExamStopped` (38, on abandon — logged before the attempt is deleted),
  each with the `attempt_id` in `additional_value`. Per-answer events keep the
  existing `CorrectAnswer`/`WrongAnswer` codes (exam answers are practice
  answers). "Stop" suppresses the *grade*, not the *activity trail*.
- **FR-EXM-8** *(built)* — An **instructor-facing exam-usage dashboard**
  (`admin/exam.php`, read-only) surfaces how the feature is used over a lookback
  window: adoption (started attempts, distinct students, % of active students),
  the start→finish **funnel** (finished vs. stopped vs. abandoned, reconstructed
  from `log` 36/37/38 by `additional_value = attempt_id`, since stopped/abandoned
  attempts leave no `exam_attempts` row), grade **distribution** + pass rate +
  average time, **per-lecture difficulty** (weakest first, from
  `exam_attempt_questions.max_lecture`), and **retake/improvement** (attempts per
  student, first-vs-latest grade). Portable SQL, no schema change. Linked from the
  admin hub and the stats/analytics/abuse nav.

---

## 4. Non-Functional Requirements (NFR)

- **NFR-1 (Localization)** *(built)* — All user-facing text is Hebrew. Lines
  that may start with Latin/digits and need RTL must be prefixed with U+200F
  (RLM) or Telegram scrambles direction.
- **NFR-2 (Security)** *(partial)* — New queries use prepared statements /
  `mysqli_real_escape_string`. Legacy raw-interpolation queries in
  `bot_functions.php` are known tech debt (ARCHITECTURE.md §Known Issues). Admin
  panel currently lacks CSRF protection (tech debt).
- **NFR-3 (Idempotency)** *(built)* — Telegram updates are de-duplicated via
  `processed_updates`. Schema migrations must be idempotent (`IF NOT EXISTS`,
  `ON DUPLICATE KEY UPDATE`).
- **NFR-4 (Deployment)** *(built)* — Hybrid runtime: prod = webhook, local dev =
  polling. Code must not assume a single mode. No build step; deploy via
  `git fetch && git reset --hard origin/main`.
- **NFR-5 (Data integrity)** *(built)* — Leaderboard consistency depends on the
  dual write in FR-GAM-3. Timezone is `Asia/Jerusalem` in both PHP and MySQL.
- **NFR-6 (Conventions)** *(built)* — snake_case DB identifiers; tunable knobs
  live in the `settings` table, not PHP constants; every entrypoint requires
  `bootstrap/app.php` directly (no `config.php` shim).
- **NFR-7 (Abuse resistance / anti-scraping)** *(proposed)* — The bot must
  resist two automated-abuse threats: **(a) point-farming** — an enrolled user
  scripting their own account to inflate leaderboard rank and pollute research
  data; and **(b) content exfiltration** — any client driving the bot to harvest
  the ~530-question bank. Question text is exposed at *send* time (before any
  answer), so the anti-exfiltration lever is throttling **delivery**, not
  answers. Required controls, all fail-safe (generous defaults no real student
  reaches). The approach is **detection-first, enforcement-deferred** (ADR-010):
  - **Committed — offline behavioural detection.** A read-only batch analysis
    over the `log` table flags accounts whose behaviour looks automated
    (fast-skip spam, over-regular event timing, marathon runs). It takes **no
    action** and is **invisible to users** — to avoid false-positive lockouts and
    to not tip off a scraper. See
    [features/abuse-detection.md](features/abuse-detection.md) (FR-DET-*).
  - **Deferred, contingent on the data detection surfaces** — and only if abuse
    is observed: a per-`user_id` throttle on question *delivery* (questions/min +
    daily distinct cap, the cap foreshadowed in ROADMAP #3a), a lenient minimum
    think-time, and last, milestone captcha/survey challenges — each a fail-safe
    `settings` knob. A cap is **not** set blind: the crammer who legitimately
    needs the whole bank in 3–4 days must never hit it.
  Existing partial mitigations: session-scoped message cleanup (FR-SES-1 /
  ADR-005) limits the *standing* visible corpus but is **trigger-based, not
  timed** — it never wipes an active/just-finished session (mid-session export
  captures it) and does nothing against live capture; the nickname gate
  (FR-ONB-1) is one-time and not a real barrier. Design rationale,
  threat model, and tiered rollout: design.md **ADR-010**.

---

## 5. Traceability

- Architecture realising these requirements → [../ARCHITECTURE.md](../ARCHITECTURE.md)
- Design rationale / decisions → [design.md](design.md)
- Feature-level requirements that extend this SRS → [features/](features/)
- Unscheduled ideas → [../ROADMAP.md](../ROADMAP.md)
