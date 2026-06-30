# Exam Mode (student-facing) — Feature Plan (BUILT)

> Status: **built** — design captured 2026-06-30, shipped 2026-06-30.
> A self-assessment mode inside the bot: the student sits a short, timed,
> stratified quiz drawn live from the practice bank, gets a grade, and can track
> their grades over time and per lecture.
>
> **As built:** logic lives in [`exam_functions.php`](../../exam_functions.php)
> (loaded from `bot_functions.php`); migration
> [`migrations/2026-06-30_exam-mode.sql`](../../migrations/2026-06-30_exam-mode.sql);
> commands in `index.php`, callbacks in `variable_setup.php`; smoke tool
> `tools/exam_sample.php`. The grade-over-time graph ships as a **unicode bar
> chart** (text), not an Imagick PNG — simpler and dependency-free; the Imagick
> chart remains a possible enhancement. See ARCHITECTURE.md §Exam Mode.

This is the **student-facing** counterpart to the instructor-facing
[exam-builder.md](exam-builder.md). The exam-builder *assembles the real 40-question
final exam* for printing; this feature lets a **student practice** under exam-like
conditions inside Telegram. They are independent — this one has no dependency on
the exam-builder shipping.

## Goal

Give students a repeatable "how ready am I?" check that feels like a test, not
free practice, and surface **where they are weak** (which lectures) so revision is
targeted.

## Decisions locked (from the design conversation)

| Decision | Choice | Notes |
| --- | --- | --- |
| Question source | **Practice bank, stratified** | Pulled live each attempt; spread across lectures (`max_lecture ≤ current_week`) **and** success-rate levels. No dependency on the held exam set. |
| Length | **10 questions** (not 40) | It's *practice*, so it's short. Tunable via `settings.exam_num_questions`. |
| Timer | **20 minutes**, auto-submit at expiry | Tunable via `settings.exam_time_minutes`. Enforced lazily (webhook is stateless) — see Timer below. |
| Game-state effect | **Counts as normal practice** | Answers run through the existing `recordAnswer()` path → points, leveling, and badges all apply. Trade-off accepted: a hard exam *can* demote, by design (see ADR-012). |
| Feedback timing | **Immediate, per question** | Right/wrong shown after each answer (like practice) rather than a silent 10-then-grade flow — reviewing a batch of past questions after the fact is awkward in Telegram. A final results screen still gives the grade. |
| Session cleanup | **Keep the existing erase mechanism** | Exam question messages are logged to `session_question_messages` and cleaned by `maybeStartNewSession()` exactly like practice questions (FR-SES-1 / ADR-005). |
| Retakes | **Unlimited, fresh each time** | Each attempt re-selects questions. No repeats *within* an attempt; repeats *across* attempts are allowed (the bank may be small). |
| Start entry points | **`/מבחן` command + menu button** | A Hebrew slash command `/מבחן` (English alias `/exam`) routed in `index.php`'s `switch ($text)` — same dual-alias pattern as `/menu`+`/תפריט`, `/semester`+`/סמסטר` — **and** the **📝 מבחן תרגול** main-menu button. Both land on the intro screen. |
| Stop / abandon | **"הפסק מבחן" button — no grade, no logged result** | The student can quit mid-exam. The attempt is **not graded and not recorded** (no grade, no history/graph/per-lecture entry). The questions they already answered **still count** as normal practice (points/level/badges/question stats already written by `recordAnswer()` per answer — those stay). |
| Grade scale | **0–100, pass = 56** | 10 questions × 10 pts; 6 correct = 60 (pass), 5 = 50 (fail). Matches the existing exam-grade convention (stats card, FR-GAM-5). Tunable via `settings.exam_pass_grade`. |
| Tracking | **Persisted per attempt + per question** | Enables the grade-over-time graph, the latest-3 average, and the per-lecture strength breakdown. |

## Staged rollout (gate)

The feature ships **gated to the staff cohort** while in development. The main-menu
button is shown to everyone, but tapping it (or `/מבחן`) runs `examFeatureEnabled()`:
- `settings.exam_enabled_for_all = '1'` → everyone gets it (the GA switch).
- otherwise → only users whose `cohort_id = settings.exam_staff_cohort_id` (the
  "צוות" cohort, id 3 on prod) get the real flow; everyone else sees a
  "🚧 בפיתוח — בקרוב" notice.

Both are `settings` rows, so opening to all (or adding a tester to the staff
cohort) needs no deploy. Gate is enforced at `showExamIntro()`, `startExam()`, and
`showExamHistory()`.

## Flow (student's perspective)

1. **`/מבחן`** (or `/exam`), or main menu → **📝 מבחן תרגול** (`menu_exam`) →
   intro screen: "10 שאלות, 20 דקות, נספר לנקודות שלך" + **התחל מבחן** button
   (`exam_start`).
2. On start: select 10 stratified questions, create an `exam_attempts` row
   (`status = in_progress`), set `users.active_exam_attempt_id`, serve Q1.
3. Each question shows a header with the **remaining time** (`⏱ נותרו MM:SS`) and
   the position (`שאלה 3/10`), the answer options, **and a "🛑 הפסק מבחן" button**
   (`exam_cancel`). The student answers → immediate ✓/✗ feedback + the correct
   answer if wrong → **next** question auto-served (or a "השאלה הבאה" button).
4. After Q10 (or when the timer lapses): **results screen** — grade /100,
   עבר/נכשל, #correct, time taken, **per-lecture breakdown for this attempt**, and
   a trend line (vs. your average of the last 3).
5. A separate **📈 התוצאות שלי במבחנים** view (from the exam menu or the stats
   card) shows the grade-over-time graph, the latest-3 average, and the
   per-lecture strength table aggregated across attempts.

### Stop / abandon (the "הפסק מבחן" button)

A student who tires of the exam can quit without a grade:

- `exam_cancel` → a confirm prompt ("בטוח? לא תקבל ציון על המבחן") with
  **כן, הפסק** / **המשך מבחן** so a stray tap doesn't kill an attempt.
- On confirm: write the **`ExamStopped` audit event** (see Audit logging below),
  then **delete** the `exam_attempts` row **and** its `exam_attempt_questions`
  rows, clear `users.active_exam_attempt_id`, and show a short "המבחן הופסק"
  message (no grade, no results screen).
- **Two different ledgers — don't conflate them:**
  - The student's **personal exam stats** (graded result, history, graph,
    latest-3 average, per-lecture breakdown) get **no trace** of an abandoned
    attempt — that's the "no grade / don't record the result" the student sees.
  - The **`log` audit table** (research trail, FR-RES-2) **still records** the
    `ExamStopped` event — "track all activity" stays true. The two are separate
    by design.
- **What also stays:** every answer already given ran through `recordAnswer()` at
  the moment it was answered, so its points, leveling, badges, `questions` /
  `user_q` success-rate updates **and** its `WrongAnswer`/`CorrectAnswer` log rows
  are already committed and are **not** rolled back. "The questions still count" —
  only the *graded exam result* is discarded.
- (Deleting the attempt row, rather than keeping an `abandoned` status row, keeps
  the student's trend graph honest. Quit-rate analytics don't need it — the
  `ExamStart` − `ExamCompleted` gap in the audit log already measures abandonment.)

### Audit logging (the `log` table records everything)

Separate from the personal exam stats, the `log` audit table (via `writeLog()`)
gets **three new event types** so the full exam lifecycle is in the research trail
(FR-RES-2). New `actions` rows (IDs 36–39 are free):

| `action_id` | name | logged when | `additional_value` |
| --- | --- | --- | --- |
| 36 | `ExamStart` | an attempt is created (`/מבחן` / `exam_start`) | `attempt_id` |
| 37 | `ExamCompleted` | all questions answered **or** timer expired → graded | `attempt_id` |
| 38 | `ExamStopped` | user confirms "הפסק מבחן" (before the row is deleted) | `attempt_id` |

Per-answer events inside an exam keep using the existing `CorrectAnswer` (1) /
`WrongAnswer` (2) codes — exam answers **are** practice answers, so leveling,
badges, and the existing answer analytics stay consistent and need no special
case. `additional_value` carries the `attempt_id` so a session can be
reconstructed end-to-end (for `ExamStopped`, log it **before** deleting the row).

## Timer (webhook is stateless — enforce lazily)

There is no long-running process to fire a countdown, so the timer is enforced
**on interaction**, not by a background clock:

- `started_at` + `time_limit_seconds` are stored on the attempt.
- Each question header recomputes remaining time at send.
- When an answer arrives **after** expiry, the attempt is finalized: remaining
  unanswered questions count as wrong, grade is computed, results are shown.
- **Dangling attempts** (student abandons and never returns) are finalized
  defensively: starting a *new* exam auto-expires any prior `in_progress` attempt
  for that user. A `tools/`/cron sweep of stale `in_progress` rows older than the
  time limit is an **optional** enhancement (keeps history clean without waiting
  for the user to return) — not required for MVP.
- A real pushed "time's up" message at 0:00 would need a scheduled job → **out of
  scope** for MVP; the lazy model is sufficient.

## Stratified selection (10 questions)

Reuse the bot's live success-rate → level logic; don't trust the legacy
`questions.difficulty` column.

1. Eligible pool = `(max_lecture IS NULL OR max_lecture ≤ current_week)` for the
   user's cohort week (`getCurrentWeek($user_id)`), excluding high-`reportedbad`
   questions and (if exam-builder ships) `release_status = 'exam_held'` rows.
2. **Lecture spread:** distribute the 10 slots across the distinct `max_lecture`
   values present, weighted by question density (more-covered lectures get more
   slots), guaranteeing coverage breadth rather than clustering on one lecture.
   With more lectures than slots, sample 10 distinct lectures weighted by density;
   with fewer, allow multiple per lecture.
3. **Level mix:** within the chosen slots, vary the success-rate band (a mix of
   L1–L4 / probation) so the exam isn't all-easy or all-hard.
4. No repeats within the attempt.
5. **Short pool:** if fewer than 10 eligible questions exist (small
   `current_week` or thin bank), serve as many as available and scale the grade to
   questions actually served (`correct / served × 100`), noting it on the results
   screen.

Keep the algorithm simple — at 10 questions the heavy concept-density mapping of
the exam-builder is unnecessary. Tuning lives in `settings`.

## Data model

Two new tables (schema = **code**, idempotent migration, committed):

**`exam_attempts`**

| Column | Notes |
| --- | --- |
| `id` | PK |
| `user_id` | FK → `users.id` |
| `started_at` / `finished_at` | timestamps |
| `status` | `in_progress` / `completed` / `expired` |
| `num_questions` | served count (usually 10) |
| `num_correct` | filled at finalize |
| `grade` | 0–100, filled at finalize |
| `time_limit_seconds` | snapshot of the limit at start (default 1200) |

Index `(user_id, started_at)` for history/trend queries.

**`exam_attempt_questions`**

| Column | Notes |
| --- | --- |
| `id` | PK |
| `attempt_id` | FK → `exam_attempts.id` |
| `question_id` | FK → `questions.id` |
| `position` | 1..N order served |
| `max_lecture` | **snapshot** of the question's lecture at exam time (so per-lecture history is stable even if the question is later re-tagged) |
| `user_answer` / `correct_answer` | chosen vs. correct option |
| `is_correct` | 1 / 0 / NULL (unanswered at expiry) |
| `served_at` / `answered_at` | timestamps |

Index `(attempt_id)`. Per-lecture aggregation = `GROUP BY max_lecture`.

**`users.active_exam_attempt_id`** (nullable FK) — fast "is this user mid-exam?"
check on the answer hot path; cleared at finalize.

**`settings` rows** (per the tunable-knob convention, not PHP constants):
`exam_num_questions` (10), `exam_time_minutes` (20), `exam_pass_grade` (56).

## Components to build

1. **Schema migration — `migrations/YYYY-MM-DD_exam-mode.sql`**
   `exam_attempts`, `exam_attempt_questions`, `users.active_exam_attempt_id`, the
   three `settings` rows, **and the three `actions` rows** (36 `ExamStart`, 37
   `ExamCompleted`, 38 `ExamStopped`) via `INSERT IGNORE` — same pattern as
   `migrations/2026-04-20_log_actions_expansion.sql`. Idempotent (`IF NOT EXISTS`,
   `ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`).

2. **Selection — `selectExamQuestions()` in `bot_functions.php`**
   The stratified 10-question picker above. Reuses the success-rate band logic and
   the `getCurrentWeek()` lecture filter.

3. **Exam lifecycle in `bot_functions.php`**
   `startExam()` (creates the attempt, `writeLog(36, attempt_id)`),
   `serveExamQuestion()` (logs to `session_question_messages` via
   `logSessionQuestionMessage()`, renders the timer header, RTL-safe),
   `recordExamAnswer()` (calls existing `recordAnswer()` for points/level/badges +
   its `writeLog(1|2)` **and** writes `exam_attempt_questions`, shows immediate
   feedback), `finalizeExam()` (grade, status, `writeLog(37, attempt_id)`, clear
   `active_exam_attempt_id`, results screen), `cancelExam()`
   (`writeLog(38, attempt_id)` **then** delete the attempt rows), plus lazy-expiry
   checks.

4. **Routing**
   - **`index.php` `switch ($text)`** — add `case '/exam':` / `case '/מבחן':`
     (dual-alias, like `/menu`+`/תפריט`) → the exam intro. Respect the nickname
     and cohort gates already enforced ahead of the switch.
   - **`variable_setup.php` callback switch** — new callbacks: `menu_exam`
     (intro), `exam_start`, `EXQ:<attempt>:<qid>:<ans>` (answer — kept distinct
     from practice `Q:` so stale buttons don't collide), `exam_next`,
     `exam_cancel` (→ confirm) + `exam_cancel_confirm` / `exam_cancel_dismiss`,
     and `menu_exam_results`. Respect the nickname gate and `processed_updates`
     dedup.

5. **Results & feedback rendering**
   - Per-attempt results screen (grade, pass/fail Hebrew, per-lecture breakdown,
     latest-3 average comparison).
   - `📈 התוצאות שלי במבחנים` view: grade-over-time **graph** + latest-3 average +
     per-lecture strength table (ascending, weakest first). Render the chart as a
     PNG via Imagick (same Plesk-PHP capability as the trophy closet) **with a
     text/Unicode-bar fallback** guarded by `extension_loaded('imagick')`.

6. **Menu integration** — add the **📝 מבחן תרגול** button to `showMainMenu()`;
   optionally surface a link to exam results from the stats card (FR-GAM-5).

7. **Docs (per the documentation workflow)**
   - This spec → flip to `built` and fold into `ARCHITECTURE.md` when shipped.
   - SRS `FR-EXM-*` in `docs/requirements.md` (already added, `proposed`).
   - ADR-012 in `docs/design.md` (already added).

## To verify when building (do NOT assume)

- The exact answer-callback flow for practice questions in `variable_setup.php`
  (the `Q:` handler) and how `recordAnswer()` is invoked, to mirror it cleanly.
- That `serveExamQuestion()` logs every message id with `logSessionQuestionMessage()`
  so the session-erase mechanism still covers exam questions (CLAUDE.md gotcha).
- That counting exam answers as practice does **not** double-count or break the
  L4 `current_run` cap (ADR-011) — reuse `recordAnswer()`, don't reimplement.
- Whether Imagick is acceptable for the chart, or a Unicode-bar text chart is
  enough for MVP (avoids the Plesk-PHP-only dependency entirely).
- Behaviour when a student opens a menu/command mid-exam (allow navigation +
  cancel button vs. soft-lock). MVP: lenient — dangling attempt auto-finalized.

## Suggested build order

1. Migration (the two tables + `active_exam_attempt_id` + settings rows).
2. `selectExamQuestions()` + a `tools/` smoke script to eyeball a sample exam.
3. Exam lifecycle + routing (start via `/מבחן` + `menu_exam`, serve →
   answer+feedback → finalize; **plus the `exam_cancel` confirm→discard path**),
   reusing `recordAnswer()` and `logSessionQuestionMessage()`.
4. Results screen (per-attempt) — get a full attempt working end to end.
5. History view: latest-3 average + per-lecture table; then the grade graph.
6. Menu button + stats-card link.
7. Docs: flip this spec to `built`, fold into `ARCHITECTURE.md`, mark FR-EXM-*
   `built`.
