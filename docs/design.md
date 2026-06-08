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
