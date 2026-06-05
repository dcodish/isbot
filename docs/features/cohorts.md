# Feature Spec — Cohorts / Groups

**Status:** in design (not built) · **Created:** 2026-06-05
**Extends:** [../requirements.md](../requirements.md) · **Decisions:**
[../design.md](../design.md) ADR-006/007/008

Run the same bot for multiple groups simultaneously, each on its own week of
progress (e.g. *סתיו 2026*, *אביב 2026*, *קבוצה מיוחדת א*). Students pick their
group; the professor manages groups and each group's week from the admin panel.

---

## 1. Background & Problem

Today a single global `settings.current_week` gates the question pool for
everyone via the lecture filter `(max_lecture IS NULL OR max_lecture <= week)`
(SRS **FR-Q-4**). That can't serve two groups at different points in the
syllabus at once. We need "current week" to be **per group**, with each user
attached to a group.

Everything else — question selection, levels, points, badges — is unchanged.

---

## 2. Requirements

All names (cohorts, semesters) are Hebrew (SRS **NFR-1**); cohorts are created in
admin and users only pick from the existing list.

### Functional
- **FR-COH-1** — Admin can **create, edit, and deactivate** cohorts. A cohort
  has a Hebrew name, a `current_week` (1–12), an optional colour, and an active
  flag. Deactivate (not delete) to preserve history. *(extends FR-ADM-5)*
- **FR-COH-2** — Each cohort carries its **own `current_week`**, with the same
  semantics as the legacy global setting. Editing a cohort's week is the
  day-to-day operation ("advance *סתיו 2026* to week 5").
- **FR-COH-3** — Group selection is **mandatory onboarding**: after the nickname
  gate, a user with no cohort is shown the group picker and blocked from all
  other commands until they choose one. *(realises FR-ONB-2; a second gate
  alongside `checkNicknameRequired()`)*
- **FR-COH-4** — A user can **change their group later** via a `/group` command
  and a menu entry.
- **FR-COH-5** — The lecture filter resolves `current_week` from the **user's
  cohort**; if the user has no cohort, it falls back to the global
  `settings.current_week`. *(supersedes the global behaviour of FR-Q-4)*
- **FR-COH-6** *(optional, non-critical)* — Leaderboard rows may show a small
  per-cohort colour indicator (🔴/🔵/🟢). *(realises FR-LB-3)* Leaderboards are
  otherwise **unchanged** — no per-cohort scoping.
- **FR-COH-7** — Admin gains a **central hub** landing that links Questions /
  Stats / Cohorts (and leaves room for a future settings page). *(realises
  FR-ADM-4)*

### Non-functional
- **NFR-COH-1** — Backward compatible: a migration assigns all existing users to
  a default cohort seeded from today's global week, so no current user is
  blocked and behaviour is unchanged for them.
- **NFR-COH-2** — RTL-safe: cohort names may start with Latin/digits ("2026") →
  RLM prefix on buttons, confirmations, and any leaderboard indicator (NFR-1).
- **NFR-COH-3** — The schema migration is idempotent and committed (NFR-3); the
  default-cohort name/value is a data decision applied the same way.

---

## 3. Design

### 3.1 Data model

**New table `cohorts`:**
```sql
CREATE TABLE IF NOT EXISTS cohorts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64) NOT NULL,          -- Hebrew: "סתיו 2026"
    current_week  TINYINT NOT NULL DEFAULT 12,   -- 1..12, same semantics as settings.current_week
    color         VARCHAR(16) NULL,              -- optional leaderboard indicator
    active        TINYINT(1) NOT NULL DEFAULT 1, -- inactive cohorts hidden from the picker
    semester_start_date DATE NULL,               -- reserved for week auto-advance (ROADMAP #2)
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**`users` — add column:**
```sql
ALTER TABLE users ADD COLUMN cohort_id INT NULL;
ALTER TABLE users ADD CONSTRAINT fk_users_cohort
    FOREIGN KEY (cohort_id) REFERENCES cohorts(id) ON DELETE SET NULL;
```
`cohort_id IS NULL` → fall back to the global `settings.current_week`.

### 3.2 Per-user week resolution
`getCurrentWeek()` in `bot_functions.php` (currently global + static cache)
becomes user-aware:
- New signature `getCurrentWeek($user_id = null)`.
- If the user has a `cohort_id`, return that cohort's `current_week` (validated
  1–12); else fall back to the existing `settings.current_week` lookup.
- Replace the single static cache with a per-`cohort_id` cache (read once per
  `getQuestion()` call anyway).
- `getQuestion()` (~line 393) passes `$user_id`:
  ```php
  $current_week = getCurrentWeek($user_id);
  $lectureFilter = "(max_lecture IS NULL OR max_lecture <= $current_week)";
  ```
  This single line feeds all ~20 selection queries — the only question-pool
  touch point. Verify no other caller of `getCurrentWeek()` regresses.

### 3.3 Telegram UX (FR-COH-3, FR-COH-4)
Mirror the existing inline-keyboard / `cmd:arg` callback pattern parsed by
`explode(":")` in `variable_setup.php`:
- **`/group`** renders an inline keyboard of **active** cohorts, one button each,
  `callback_data = "setgroup:<cohort_id>"`; shows the current group at the top.
- **`setgroup:<id>`** callback validates the cohort is active, sets
  `users.cohort_id`, confirms in Hebrew ("הקבוצה שלך עודכנה ל: …").
- A **"החלף קבוצה"** entry in the existing `menu_*` keyboard.
- **Mandatory gate:** add `checkCohortRequired()` after `checkNicknameRequired()`
  in the routing path — order is nickname → cohort. A nicknamed user with
  `cohort_id IS NULL` is shown the picker and blocked from other commands until
  `setgroup:` clears it. Reuses the nickname-gate shape; no free-text input
  needed (selection is via keyboard).

### 3.4 Admin (FR-COH-1, FR-COH-7)
- **Central hub** landing linking Questions / Stats / Cohorts (+ future
  settings). Existing question-CRUD moves behind a "ניהול שאלות" link.
- **Cohort management page:** list (name, week inline-editable 1–12, colour,
  active toggle, #users), create, edit-week, deactivate. Migrate the legacy
  global `current_week` form into per-cohort week editing and retire it.

### 3.5 Migration (NFR-COH-1/3) — `migrations/2026-06-05_cohorts.sql`
1. Create `cohorts`; add `users.cohort_id` + FK.
2. Seed a default cohort from the current global week:
   `INSERT INTO cohorts (name, current_week) VALUES ('סמסטר א 2026', <settings.current_week>)`.
3. `UPDATE users SET cohort_id = <default_id> WHERE cohort_id IS NULL`.
4. Keep `settings.current_week` as the fallback. *(committed schema migration)*

---

## 4. Implementation & Rollout Plan (do-no-harm)

**Hard constraint: existing, active users must not be harmed at any phase.**

### Two safety invariants the whole plan rests on
1. **Backfill equivalence.** The Phase-0 migration assigns every existing user
   to a default cohort whose `current_week` is copied from the *current* global
   `settings.current_week`. So when the read path becomes per-user (Phase 1), the
   computed lecture filter is **byte-for-byte identical** to today for every
   existing user — a provable no-op, not "should be fine".
2. **Gate fires only on NULL.** The mandatory onboarding gate (Phase 4) triggers
   **only** when `users.cohort_id IS NULL`. After Phase 0, **no existing user is
   NULL**, so the gate can never block them. It only ever catches genuinely-new
   users created after Phase 0. A `settings` feature flag is the kill switch.

Each phase is independently deployable, independently revertable, and ordered so
the risky piece (the gate) ships last and only after the rest is proven.

### Pre-flight
- No DB backup (per user); rely instead on careful, verification-driven steps:
  every phase has an explicit **Verify** check that must pass before proceeding,
  and the migration is additive + idempotent (nullable column, `IF NOT EXISTS`),
  so it changes nothing destructively and can be re-run safely.
- Have a **test Telegram account** that is *not* an existing user, to exercise
  the new-user path without touching real students.

### Phase 0 — Migration (additive data only, zero behaviour change)
`migrations/2026-06-05_cohorts.sql`, idempotent:
1. `CREATE TABLE IF NOT EXISTS cohorts (...)` (§3.1).
2. `ALTER TABLE users ADD COLUMN cohort_id INT NULL` + FK `ON DELETE SET NULL`.
3. Seed default cohort: `INSERT ... VALUES ('סמסטר א 2026', <current settings.current_week>)`.
4. Backfill: `UPDATE users SET cohort_id = <default_id> WHERE cohort_id IS NULL`.
- **No code deployed in this phase.** The bot still calls the global
  `getCurrentWeek()`; nothing reads `cohort_id` yet.
- **Verify:** `SELECT COUNT(*) FROM users WHERE cohort_id IS NULL` → must be `0`.
  Default cohort's `current_week` == old global value.
- **Rollback:** drop column + table (only loses cohort assignments; harmless).

### Phase 1 — Per-user read path, with safe fallback
- `getCurrentWeek($user_id = null)`: if the user has a `cohort_id`, return that
  cohort's week (validated 1–12); **else fall back to the existing global
  lookup** (today's exact behaviour). Optional param ⇒ the lone other-context
  call style stays valid. Replace the single static cache with a per-`cohort_id`
  cache.
- `getQuestion()` passes `$user_id` (one line, ~393).
- **Why safe:** by invariant #1 every existing user computes the same week as
  before; if a lookup ever returns NULL/missing, it falls back to global.
- **Verify:** existing test user gets the same question pool as before; a
  NULL-cohort user (temporarily) still works via fallback.
- **Rollback:** `git revert` (returns to global-only resolution).

### Phase 2 — Admin cohort management (admin-only, no student impact)
- Cohort CRUD + per-cohort week editing so real cohorts (e.g. *אביב 2026*) exist
  before any user-facing change. The professor renames the default cohort to the
  real current semester here.
- **Keep the legacy global `current_week` form working** for now (belt and
  suspenders — it remains the documented fallback). Retire it only in Phase 6.
- **Rollback:** revert admin changes; no student-facing surface touched.

### Phase 3 — User self-service group switch (OPTIONAL, no gate yet)
- `/group` command + `setgroup:<id>` callback + "החלף קבוצה" menu entry. Users
  *may* switch; nobody is forced. Validate target cohort is active.
- **Why safe:** purely additive capability; no one is blocked, default
  assignment unchanged unless the user opts to switch.
- **Rollback:** revert; the command/menu entry simply disappear.

### Phase 4 — Mandatory onboarding gate (the only risky step) — flagged
- Add `checkCohortRequired($user_id, $chat_id)` invoked **after** the nickname
  gate (text path in `index.php` ~line 40; callback path in `variable_setup.php`
  before the switch, always allowing `setgroup:`). Order: nickname → cohort.
- **Fires only when `cohort_id IS NULL`** → by invariant #2, never for existing
  users; only for users created after Phase 0 who haven't picked.
- **Feature flag:** `settings.cohort_gate_enabled` (default `0`). Gate is a no-op
  while `0`. Flip to `1` only after verifying with the test account. Instant kill
  switch with no deploy.
- **Verify (flag on):** test account (new user) is forced to pick a group and
  blocked from other commands until it does; an existing test user is **never**
  prompted and plays normally.
- **Rollback:** set `cohort_gate_enabled = 0` (instant) or `git revert`.

### Phase 5 — Optional leaderboard colour indicator (FR-COH-6)
- Only if cheap; mind RTL/RLM on rows. Skippable.

### Phase 6 — Central admin hub + cleanup (after everything proven)
- Central hub linking Questions / Stats / Cohorts (FR-COH-7). Move question-CRUD
  behind a link.
- Retire the legacy global-week form (now redundant).
- Fold design into `ARCHITECTURE.md`; flip these requirements to **built** in the
  SRS; mark "Leagues (cohorts)" partially delivered in `ROADMAP.md`.

### What could still harm existing users — and why it won't
| Risk | Mitigation |
|---|---|
| Gate blocks current students | Gate keyed on `cohort_id IS NULL`; Phase-0 backfill makes all existing users non-NULL. Plus `cohort_gate_enabled` kill switch. |
| Week resolution changes their question pool | Default cohort week = old global week ⇒ identical filter (invariant #1); NULL falls back to global. |
| `getCurrentWeek()` signature breaks a caller | Only one caller exists; new param is optional/defaulted. |
| Migration locks/fails on `users` | `users` is small; additive nullable column; idempotent; DB backup first. |
| New user created mid-rollout | NULL cohort → falls back to global week (Phase 1); gets gated once flag is on (intended). |

## 5. Touch points

- `migrations/2026-06-05_cohorts.sql` *(new, committed)*
- `bot_functions.php` — `getCurrentWeek()`, `getQuestion()` (~393),
  `checkCohortRequired()`, `/group` rendering, optional leaderboard colour
- `variable_setup.php` — gate order (nickname → cohort), `/group` routing,
  `setgroup:` callback, menu entry
- `admin/index.php` + new admin pages — cohort management + central hub
- `ARCHITECTURE.md` / `CLAUDE.md` — document per-cohort week + cohort gate
- `ROADMAP.md` — mark "Leagues (cohorts)" partially delivered

## 6. Open items

- **Default cohort Hebrew name** — DECIDED: `סמסטר א 2026` (all existing users
  are backfilled into it; renameable later in the admin UI).
- **Week auto-advance** (ROADMAP #2) — deferred; `semester_start_date` column
  left in place so it needs no further migration.

## 7. Traceability

| Requirement | Design §| ADR |
|---|---|---|
| FR-COH-1, FR-COH-7 | 3.4 | ADR-008 |
| FR-COH-2, FR-COH-5 | 3.1, 3.2 | ADR-006 |
| FR-COH-3, FR-COH-4 | 3.3 | ADR-007 |
| FR-COH-6 | 3.4 / SRS FR-LB-3 | — |
| NFR-COH-1/3 | 3.5 | — |
