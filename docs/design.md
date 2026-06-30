# Design & Architecture Decisions

Cross-cutting design rationale for isbot, recorded as lightweight ADRs
(Architecture Decision Records). This complements — does not duplicate —
[../ARCHITECTURE.md](../ARCHITECTURE.md), which is the living description of how
the system is built. When *what* something does matters, read ARCHITECTURE.md;
when *why it was done that way* matters, read here.

Requirements referenced below are defined in [requirements.md](requirements.md).

---

## Design principles

1. **The `settings` table is the home for tunable behaviour**, not PHP
   constants — so the professor can change behaviour without a deploy (NFR-6).
2. **Migrations are the audit log.** No migration runner; idempotent `.sql`
   files in `migrations/` are applied by hand and committed for history (NFR-3).
3. **Hebrew-first, RTL-safe.** Every rendered line is designed for RTL; numeric
   and Latin-leading lines get an RLM prefix (NFR-1).
4. **Fail safe for new data.** Unrated questions and new users get conservative
   defaults (probation pool, fallbacks) rather than extreme classifications.
5. **Back-compatible change.** New gates and columns default to preserving
   current behaviour for existing rows.

---

## Decision records

### ADR-001 — Hybrid runtime (webhook prod / polling dev) · *accepted, built*
Prod registers a Telegram webhook to `index.php`; local dev runs `bot-polling.php`
against `localhost`. Avoids needing an HTTPS tunnel for local work. **Consequence:**
no code path may assume one mode (NFR-4).

### ADR-002 — Success-rate buckets + probation pool · *accepted, built*
Difficulty is *observed*, not authored: questions are bucketed by live success
rate, and questions with `numofanswers < 5` are sampled across all levels at
fixed rates. **Why:** a single first answer would otherwise strand a question at
0% or 100% success and lock it to the wrong level (FR-Q-2, FR-Q-3).

### ADR-003 — Rolling (not calendar) leaderboard windows · *accepted, built*
Weekly/monthly boards use sliding 7/30-day windows anchored to `NOW()`.
**Trade-off:** simpler queries and continuous decay vs. no shared "reset moment".
Switching to calendar-aligned periods is a `WHERE`-clause change in
`fetchRollingEntries()` if teacher-facing comms ever need it (FR-LB-1).

### ADR-004 — Motivational hybrid leaderboard display · *accepted, built*
Instead of "top 10 + your rank", show podium ∪ local window so users outside the
top 10 see themselves among nearby peers (goal-gradient / Hamari & Koivisto).
**Why:** the standard shape demotivates the majority (FR-LB-2).

### ADR-005 — Session-scoped question cleanup · *accepted, built*
Question messages are logged and wiped after an inactivity gap (delete, or edit
to a placeholder past Telegram's 48h window). **Why:** mitigates content theft
while keeping feedback/leaderboards/badges visible for review (FR-SES-1).
**Known limits (not bulletproof, by design):** cleanup is **trigger-based, not
timed** — it fires only when the *same user returns and acts* after the gap, so
(a) an active or just-finished session is never wiped (mid-session export
captures it intact) and (b) a user who never returns leaves their session
standing indefinitely. And it does nothing against **live capture** (a Bot-API /
userbot scraper stores each question the instant it arrives; later deletion
can't un-capture it). It raises the bar against the realistic threat — a
built-in Telegram "export chat history" done days later — not against a
deliberate scraper. The latter is NFR-7 / ADR-010 territory.

### ADR-006 — Per-cohort week of progress · *accepted, planned*
Replace the single global `settings.current_week` with a per-group week so the
same bot can serve multiple cohorts each at a different point in the syllabus.
`users.cohort_id` resolves the lecture filter; unassigned users fall back to the
global setting. **Why / alternatives / details:**
[features/cohorts.md](features/cohorts.md). Supersedes the global behaviour of
FR-Q-4 (now FR-COH-5).

### ADR-007 — Mandatory cohort onboarding gate · *accepted, planned*
Group selection is required (a second gate after the nickname gate) rather than
optional, so every active user has a correct week and clean cohort data.
**Trade-off:** more onboarding friction and a second gate to maintain, accepted
for data correctness (FR-COH-3). See [features/cohorts.md](features/cohorts.md).

### ADR-008 — Central admin hub · *accepted, planned*
Consolidate the currently-separate admin entry points (Questions, Stats, and the
new Cohorts page) behind one landing that links to each section, rather than
growing more standalone pages. Aligns with the unified-settings direction in
ROADMAP #3a (FR-ADM-4).

### ADR-009 — Within-user event study for gamification impact · *accepted, built*
The analytics dashboard (`admin/analytics.php`) measures whether gamification
drives usage from **observational** log data. The naive cross-section
("leaderboard users answer more") is mostly selection bias — engaged users do
both. **Decision:** lead with a **within-user event study** (a user's answer
volume before vs after their *own* badge / level-up / leaderboard check), which
differences out the per-user engagement level, and treat cross-sections (reach,
retention splits) as suggestive context wrapped in an explicit "observational, not
causal" caveat. **Two constraints forced into the design:** (1) recent events are
**censored** out (their after-window hasn't elapsed) to avoid undercounting
"after"; (2) `users` has **no signup column**, so retention derives first-seen
from `MIN(log.timestamp)` and uses a lifespan-based (active-across-≥N-days) D1/7/30
rather than sparse day-N retention. **Trade-off:** the event study still carries a
streak confound (a reward fires mid-streak) — footnoted, not hidden — and honesty
about limits is preferred over a cleaner-looking but misleading causal claim
(FR-AN-1/2/3/8). Details: [features/gamification-analytics.md](features/gamification-analytics.md).

### ADR-010 — Tiered anti-abuse / anti-scraping controls · *proposed*
Two distinct adversaries, not one:
- **Farmer (T1):** an enrolled student scripting *their own* account for
  points/rank. Harms leaderboard integrity and research validity. Bounded to one
  account and *correctness-seeking* (wants points) — so detectable via
  perfect/fast-run anomalies.
- **Exfiltrator (T2):** any client harvesting the ~530-question bank. Harms the
  exam-prep value and future reuse of the questions. Points are irrelevant; it
  may answer wrong on purpose and only needs each question *delivered once*.

**Key realization:** a question's text is exposed the instant it is **sent**,
before any answer. So throttling *answers* (the ROADMAP's first instinct) blunts
farming but barely touches exfiltration. The anti-exfiltration lever is
throttling **delivery** — questions/minute and distinct-questions/day per user —
which dovetails with the daily-question-cap knob already foreshadowed in
ROADMAP #3a.

**Decision — detection first, enforcement deferred (revised):** we will *not*
ship a cap or any user-visible throttle yet. A blind daily/rate cap risks
penalising the exact student we most want to serve — the one cramming 3–4 days
before the exam, who legitimately needs the whole bank fast — and we have **no
data** on the real behaviour distribution to place that threshold safely.
Instead:
- **Phase 1 (committed) — offline detection.** A read-only, batch analysis over
  the `log` table flags accounts whose *behaviour* looks automated (fast-skip
  spam, over-regular event timing, marathon runs). It takes **no action** and is
  **invisible to users** — deliberately, so a scraper isn't tipped off to adapt
  and no honest student is ever blocked. It runs offline (admin-triggered /
  periodic), never in the live message path. Spec:
  [features/abuse-detection.md](features/abuse-detection.md) (FR-DET-*).
- **Phase 2 (contingent) — enforcement, only if the data justifies it.** Phase 1
  *is the measurement instrument*: it yields the distribution (real skip-rates,
  typical inter-question time, honest marathon size) that tells us whether a cap
  could ever sit above all legitimate use. Only then, and only if scraping is
  actually observed, do we consider a delivery throttle / distinct-question cap
  and, last, milestone challenges — each a fail-safe `settings` knob.

**Trade-offs:** detection is *not* prevention — an offline flag won't stop a grab
in progress. Accepted: the realistic threat is a slow harvest or a post-hoc
Telegram export, and the cost of a false-positive lockout (blocking a real
crammer) is judged worse than a delayed, human-reviewed catch. The cap is not
abandoned, only **gated on evidence**. None of this stops a patient, distributed,
slow scraper (a student team each sipping under any threshold); the goal stays:
raise the cost of *bulk* harvest and give the professor *visibility*, not perfect
prevention. Complements — does not replace — the session cleanup of ADR-005
(NFR-7).

### ADR-011 — Bounded top-level demotion · *accepted, built*
Leveling (1–4) runs on the `current_run` counter vs. per-level `upgrade_at` /
`downgrade_at` thresholds in `gamification` — deliberately **decoupled from
`overall_points`**, since points only ever grow and so can't gate up/down moves.
For levels 1–3 `current_run` resets to 0 on a level-up and is bounded by
`upgrade_at` above, so it stays in a small band. **Level 4 (the cap) had no such
bound:** correct answers kept incrementing `current_run` with no reset and no
cap, so it drifted unboundedly (a real user reached **323**) and demotion — which
fires when `current_run < downgrade_at` — became unreachable. A second, independent
bug: L4 `downgrade_at` was `-5`, but the wrong-answer branch floors `current_run`
at `-4`, so it could never cross `-5` even at `current_run = 0`.

**Decision — keep the top level demotable via a bounded wrong-streak:**
- **Cap `current_run` at 0 for level-4 correct answers.** Points are still
  awarded; the in-level run just can't bank a positive cushion. A correct answer
  therefore offsets exactly one prior wrong (and no more), so it doesn't *fully*
  reset a streak but does reward recovery.
- **Set L4 `downgrade_at = -3`** (above the `-4` floor so it actually fires):
  **4 wrong answers in a row** (no correct in between) demote to level 3; a
  correct mid-streak pulls back toward 0 and buys one more. Single-value tunable
  (`-2`⇒3, `-3`⇒4, `-4`⇒5).
- **One-off state reset** of existing inflated L4 `current_run` values to 0 so the
  rule applies immediately, not after hundreds of wrongs.

**Trade-off:** with no positive cushion a level-4 player is always exactly four
consecutive wrongs from demotion, even right after a long correct run — but that
is the intended "stay sharp at the top" pressure, and the correct-offset keeps
isolated mistakes harmless. Migration:
`migrations/2026-06-10_l4_downgrade_threshold.sql`.

### ADR-012 — Student-facing practice exam mode · *accepted, built*
A short, timed, stratified self-assessment inside the bot (FR-EXM-*). Full spec:
[features/exam-mode.md](features/exam-mode.md). The decisions worth recording:

- **10 questions, not 40.** The real final exam is 40; this is *practice*, so it's
  deliberately short and repeatable. Length/timer/pass are `settings` knobs, not
  constants (NFR-6), so 40-question "full mock" runs are a config change, not code.
- **Counts as normal practice** (routed through `recordAnswer()`) rather than a
  sandboxed run. **Trade-off:** the simplest, most consistent path — points,
  badges, and leveling all "just work" and exam answers improve the bank's
  success-rate signal — but a hard exam *can* demote a player (and inflates the
  leaderboard). Chosen knowingly: the alternative (a parallel, isolated answer
  path) duplicates `recordAnswer()` and risks diverging from the L4-cap invariant
  (ADR-011). If exam-driven demotion proves unpopular, isolating *just* the
  leveling side is a smaller follow-up than building a separate path now.
- **Immediate per-question feedback**, not silent-then-grade. Reviewing a batch of
  past questions after the fact is awkward in Telegram's linear chat, and the
  session-erase mechanism (ADR-005) may already have wiped earlier stems by the
  time results show. Immediate ✓/✗ keeps the learning loop tight; the final
  screen still gives the grade and per-lecture breakdown.
- **Lazy, interaction-driven timer.** The prod webhook has no background process
  to fire a countdown (ADR-001), so expiry is evaluated when the next interaction
  arrives, and dangling `in_progress` attempts are auto-finalized when a new exam
  starts. A pushed "time's up" message would need a scheduled job — deferred.
- **Snapshot `max_lecture` per answered question** in `exam_attempt_questions`, so
  the per-lecture history (FR-EXM-4) stays correct even if a question is later
  re-tagged. The aggregate is *what lecture this counted toward at exam time*, not
  the question's current tag.
- **Start via a `/מבחן` command + a menu button**, mirroring the existing
  dual-alias text commands (`/menu`+`/תפריט`) routed in `index.php`. Hebrew slash
  commands can't be registered in BotFather's menu (ASCII-only) but still arrive
  as message text, so the `switch ($text)` match works; the English `/exam` alias
  covers the menu listing.
- **Stop = discard the grade, not the activity (FR-EXM-6/7).** Two ledgers are
  kept deliberately separate: the student's **personal exam stats** and the **`log`
  audit table**. Quitting mid-exam **deletes** the `exam_attempts` row and its
  per-question rows so no partial/zero grade pollutes the trend graph or
  per-lecture stats (an abandoned run isn't a real performance signal) — but the
  `log` table still gets an `ExamStopped` event, so the research trail (FR-RES-2)
  "tracks all activity." The answers already counted as practice stay (incl. their
  `CorrectAnswer`/`WrongAnswer` log rows), because `recordAnswer()` fires per
  answer, independently of the exam record. Quit-rate is therefore still
  measurable from the `ExamStart`−`ExamCompleted` gap without keeping an
  `abandoned` stats row.
- **Exam lifecycle is fully audited.** Three new `log` action types — `ExamStart`
  (36), `ExamCompleted` (37), `ExamStopped` (38), `additional_value = attempt_id` —
  seeded `INSERT IGNORE` like `migrations/2026-04-20_log_actions_expansion.sql`.
  In-exam answers reuse `CorrectAnswer`/`WrongAnswer` so existing answer analytics
  and badge/level logic need no exam special-case.

---

## Open / deferred

- **Per-cohort leaderboard scoping (leagues).** Deferred — no teams/competitions
  yet, and names are largely anonymous. Only a colour indicator is proposed
  (FR-LB-3). Revisit if the user base grows past ~50 (ROADMAP "Leagues").
- **Week auto-advance.** *Rejected* (ROADMAP "Rejected / Won't Do"). Computing a
  cohort's week from `semester_start_date` would override the professor's ability
  to hold/skip/roll back a week to match real class pacing. Week advancement stays
  a manual admin action. The `semester_start_date` column is kept as informational
  metadata only, not wired to any auto-advance logic.
