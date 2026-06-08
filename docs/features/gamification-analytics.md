# Feature Spec — Gamification Analytics Dashboard

**Status:** LIVE · **Created:** 2026-06-08 · **Deployed:** 2026-06-08
**Page:** `admin/analytics.php` (linked from `admin/stats.php`)
**Extends:** [../requirements.md](../requirements.md) (FR-ADM-6, FR-RES-3) ·
**Decisions:** [../design.md](../design.md) ADR-009

A read-only admin analytics page that answers a single question the existing
descriptive stats page (`stats.php`) can't: **what impact do the gamification
elements (badges, levels, leaderboards) actually have on usage?**

---

## 1. Background & Problem

`admin/stats.php` (FR-ADM-3) is **descriptive** — counts, a daily activity
chart, and a per-user table. It shows *how much* is happening but nothing about
*whether the gamification drives it*.

The naive cross-section ("users who check the leaderboard answer 3× more") is
mostly **selection bias** — engaged users both play more *and* check
leaderboards; the correlation says little about causation. The data is
observational (the `log` audit table, FR-RES-2), so the spec deliberately favours
analyses that are defensible under that constraint over ones that merely look
impressive. See ADR-009.

A second constraint shapes the design: **`users` has no signup/created_at
column.** Any lifecycle analysis must derive "first seen" from the log.

---

## 2. Requirements

### Functional
- **FR-AN-1** *(built)* — **Event study.** For each gamification event — badge
  earned (action 40), level-up (9), leaderboard check (23/24/25) — show the same
  user's answer volume (actions 1,2) in the `win` days **before** vs **after**
  the event, averaged across events. Within-user comparison ⇒ controls for the
  cross-sectional selection bias. *(the primary impact signal)*
- **FR-AN-2** *(built)* — **Censoring guard.** Events whose full after-window has
  not yet elapsed are **excluded** (`event.timestamp <= NOW() - win`), so "after"
  is never undercounted for recent events.
- **FR-AN-3** *(built)* — **Retention.** Lifespan-based D1/D7/D30 (a user is
  retained at DN if their activity spans ≥ N days from first-seen), split into
  *gamified-in-first-24h* (earned a badge or checked a leaderboard on day 0) vs
  *not*, plus an **All** baseline. DN denominators exclude users too new to have
  reached day N.
- **FR-AN-4** *(built)* — **Cohort retention table.** Same D1/D7/D30 broken down
  by signup week (`o-\WW` of first-seen), with eligible-count annotations and a
  "young" marker for cohorts not yet N days old.
- **FR-AN-5** *(built)* — **Lifecycle funnel** (all-time, roughly monotonic):
  started (/start) → set nickname → answered ≥1 → returned (2+ active days) →
  still active after 7d, with stage-to-stage conversion %.
- **FR-AN-6** *(built)* — **Reach.** % of users active in the period who touched
  each element (answered, levelled up, viewed badges, earned a badge, checked the
  leaderboard).
- **FR-AN-7** *(built)* — **Dead-badge detection.** All-time earn count per active
  badge, ascending, flagging badges with **0 earns** (unreachable or too hard —
  design candidates to fix or retire).
- **FR-AN-8** *(built)* — **Honest framing.** The page leads with a visible
  caveat that the data is observational and cross-sections are selection, not
  causation; each panel footnotes its own interpretation limits.

### Non-functional
- **NFR-AN-1** *(built)* — **Read-only.** Only `SELECT`s; no writes, no schema
  change. Reuses the `stats.php` auth/session guard.
- **NFR-AN-2** *(built)* — **Portable SQL.** No CTEs (prod MariaDB version
  unconfirmed); derived tables + correlated subqueries only. Smoke-tested on prod
  at ~0.2 s for the whole page at current data scale (~72 users).
- **NFR-AN-3** *(built)* — Period (`days`, 7–180) and event window (`win`, 1–14)
  are URL params, `intval`-clamped.

---

## 3. Design

### 3.1 Data sources
All from existing tables — no migration.
- `log(userid, action_type, additional_value, timestamp)` — the spine of every
  panel. First-seen = `MIN(timestamp)` per `userid`.
- `users(id, nickname)` — funnel nickname stage.
- `badges(badge_id, badge_emoji, badge_title_he, is_active)` LEFT JOIN
  `user_badges(user_id, badge_id, earned_at)` — dead-badge counts.

Action-type meanings are the same set documented in `stats.php` and ARCHITECTURE:
1 correct, 2 wrong, 6 /start, 9 level-up, 14 nickname set, 21 badge view,
23/24/25 leaderboard (all-time/weekly/monthly), 40 badge earned.

### 3.2 Event study (FR-AN-1/2)
Per event type, a derived table computes per-event before/after answer counts via
two correlated subqueries over `log`, then aggregates:
```sql
SELECT COUNT(*) events, SUM(before_cnt) sum_before, SUM(after_cnt) sum_after,
       SUM(after_cnt > before_cnt) up_users
FROM (
  SELECT e.userid,
    (SELECT COUNT(*) FROM log a WHERE a.userid=e.userid AND a.action_type IN (1,2)
       AND a.timestamp >= e.timestamp - INTERVAL :win DAY AND a.timestamp < e.timestamp) before_cnt,
    (SELECT COUNT(*) FROM log a WHERE a.userid=e.userid AND a.action_type IN (1,2)
       AND a.timestamp > e.timestamp AND a.timestamp <= e.timestamp + INTERVAL :win DAY) after_cnt
  FROM log e
  WHERE e.action_type IN (:types)
    AND e.timestamp >= NOW() - INTERVAL :days DAY
    AND e.timestamp <= NOW() - INTERVAL :win DAY   -- censoring guard (FR-AN-2)
) x
```
Reported per event type: `n`, avg before, avg after, Δ (abs + %), and `up_users`%
(share of events the user answered more afterwards). Rendered as a grouped
before/after bar + table.

**Known confound (footnoted, not hidden):** a badge/level-up fires *during* an
active streak, so part of the "after" lift is the streak, not the reward.

### 3.3 Retention (FR-AN-3/4)
One pass over a per-user derived table:
```sql
SELECT f.userid, f.first_seen,
       DATEDIFF(f.last_seen, f.first_seen) AS lifespan,
       DATEDIFF(NOW(), f.first_seen)       AS age_days,
       EXISTS(SELECT 1 FROM log g WHERE g.userid=f.userid
              AND g.action_type IN (40,23,24,25)
              AND g.timestamp <= f.first_seen + INTERVAL 1 DAY) AS early_gamified
FROM (SELECT userid, MIN(timestamp) first_seen, MAX(timestamp) last_seen
      FROM log GROUP BY userid) f
```
PHP buckets each user into all / gam / nogam and per-signup-week cohort, counting
for each N∈{1,7,30}: `elig` (age ≥ N) and `kept` (lifespan ≥ N). Rate =
kept/elig; cohorts with `elig=0` for an N render "young".

**Lifespan-based, not active-on-day-N:** "active across ≥ N days" is monotonic and
stable at course scale; classic day-N retention is too sparse here. Reads slightly
higher than a strict day-N definition — documented inline.

### 3.4 Funnel (FR-AN-5)
Five independent all-time counts (distinct users meeting each milestone);
conversion % computed client-side against the previous stage. Stages are *roughly*
monotonic (a user who returned almost certainly answered) but counted
independently, not as strict nested gates — stated in the footnote.

### 3.5 Reach + dead badges (FR-AN-6/7)
Reach: denominator = distinct `userid` active in the period; one
`COUNT(DISTINCT userid)` per element. Dead badges: `badges LEFT JOIN user_badges`
grouped by badge, ordered by earn count ascending, `earns = 0` rows flagged.

### 3.6 Presentation
Mirrors `stats.php` (Bootstrap 3 + Chart.js 4 from the same CDNs, same colour
palette). Charts: grouped bar (event study, retention), horizontal bar (funnel,
reach); badge counts as an RTL table. A bordered caveat banner sits at the top
(FR-AN-8).

---

## 4. Touch points
- `admin/analytics.php` *(new)* — the whole feature.
- `admin/stats.php` — one nav link added (`📈 Gamification Analytics`).
- No DB change. No bot-runtime change.

## 5. Open items / future
- **Goal-gradient check** (ROADMAP) — do users just below a rank boundary answer
  more right after viewing the leaderboard? Directly tests competitive framing.
- **Matched cohorts** — harden the causal claim beyond the within-user event
  study (compare similar users who did vs didn't hit an element).
- **True acquisition dates** — add `users.created_at` so cohorts key on real
  registration instead of first-logged action.
- **Per-badge views** — current badge-view signal (action 21) is page-level, not
  per badge; can't yet say *which* locked badges users covet.

## 6. Traceability

| Requirement | Design § | ADR |
|---|---|---|
| FR-AN-1, FR-AN-2 | 3.2 | ADR-009 |
| FR-AN-3, FR-AN-4 | 3.3 | ADR-009 |
| FR-AN-5 | 3.4 | — |
| FR-AN-6, FR-AN-7 | 3.5 | — |
| FR-AN-8 | 3.6 | ADR-009 |
| FR-ADM-6, FR-RES-3 (SRS) | — | ADR-009 |
