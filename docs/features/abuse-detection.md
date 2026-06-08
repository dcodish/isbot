# Feature Spec — Offline Abuse / Scraping Detection

**Status:** DESIGN (not built) · **Created:** 2026-06-08
**Page (proposed):** `admin/abuse.php` (read-only; linked from `admin/stats.php`)
**Extends:** [../requirements.md](../requirements.md) (NFR-7, FR-RES-2) ·
**Decisions:** [../design.md](../design.md) ADR-010

A read-only, **offline** analysis that flags accounts whose *behaviour* looks
automated — a scraper harvesting the question bank or a script farming points. It
**takes no action** and is **invisible to users**: it neither blocks, throttles,
nor messages anyone. The professor reviews the flags and decides.

---

## 1. Why detection-first (not a cap)

Setting a delivery cap or rate limit blind risks penalising the exact student we
most want to serve: the one cramming 3–4 days before the exam, who legitimately
needs to cover the whole bank quickly. We have **no data** on the real behaviour
distribution to place such a threshold safely (ADR-010).

So this feature is the **measurement instrument first, defence second**. It
surfaces who is behaving abnormally *and* produces the distribution (real
skip-rates, typical inter-question time, honest marathon size) that a later cap
decision would need. Until that data exists, no enforcement ships.

Two design constraints, both deliberate:

1. **Offline, not in the live path.** The analysis runs when the admin opens the
   page (or as a periodic job) — never inside the Telegram message handler. Zero
   added latency, zero risk to the running bot.
2. **Silent.** Taking no visible action is a feature, not a gap: it avoids
   false-positive lockouts of real crammers, and it does not tip off a scraper
   to adapt its pacing. We watch before we act.

---

## 2. Data available (and its gaps)

Everything is reconstructed from the existing `log` audit table (FR-RES-2). No
new write path.

`log(userid, action_type, additional_value, timestamp)` — relevant action types:

| action_type | meaning | `additional_value` |
|---|---|---|
| 1 | Correct answer | question_id |
| 2 | Wrong answer | question_id |
| 3 | **Skip** (regular question) | 0 *(qid not recorded)* |
| 4 | Report-as-unclear | 0 |

**Known data gaps (record, don't silently work around):**

- **Skips don't log the question_id** — `writeLog(3)` writes `additional_value = 0`.
  So we can count and time skips, but cannot reconstruct *which* questions a
  skip-harvester saw. Counts + timing are enough for detection; identifying the
  exposed set would need a one-line change (pass the qid into the skip handler →
  `writeLog(3, $qid)`). Deferred — not needed for v1.
- **Regular "question shown" is not a `log` event** — only `session_question_messages.sent_at`
  records a send, and it carries `message_id`, not `question_id`. So precise
  per-question think-time isn't joinable. We use the **inter-event gap** (time
  between a user's consecutive `log` rows) as the think-time proxy instead — it
  needs only `log` and is the universal timing primitive below.

---

## 3. The core primitive: inter-event gap

For each `log` row, the gap to that user's previous row. MySQL on prod is used
without window functions / CTEs (portable style, as in `analytics.php`), so the
previous timestamp comes from a correlated subquery:

```sql
SELECT l1.userid, l1.action_type, l1.timestamp,
       TIMESTAMPDIFF(SECOND,
         (SELECT MAX(l2.timestamp) FROM log l2
           WHERE l2.userid = l1.userid AND l2.timestamp < l1.timestamp),
         l1.timestamp) AS gap_secs
FROM log l1
WHERE l1.timestamp >= NOW() - INTERVAL :window_days DAY
```

Gaps larger than `settings.session_gap_minutes` are treated as **between-session**
idle and excluded from pace/regularity stats (they're real breaks, not thinking).
Every timing signal below derives from this gap series.

> **Performance:** the correlated subquery needs an index on `log(userid,
> timestamp)`. If absent, add it as an idempotent migration before this page goes
> live (`CREATE INDEX idx_log_user_time ON log (userid, timestamp)`).

---

## 4. Requirements

### Functional

- **FR-DET-1** *(proposed)* — **Offline, read-only, silent.** The analysis runs
  outside the live message path (admin-triggered / periodic job), performs no
  writes, and produces **no** user-facing effect — no block, throttle, or
  message. This stance is the feature, not a limitation (ADR-010).
- **FR-DET-2** *(proposed)* — **Inter-event gap primitive.** Compute per-user
  consecutive-event gaps from `log` via correlated subquery (no window
  functions); exclude gaps > `session_gap_minutes` as idle. §3.
- **FR-DET-3** *(proposed)* — **Fast-skip signal** *(headline)*. Per user over
  the window: count and share of skips (action 3) preceded by a gap below a
  threshold (default ~3 s). Skipping is the cheapest harvest move (reveals the
  question, advances, no thinking, no wrong-answer risk); *fast* skips in volume
  are the loudest exfiltration tell.
- **FR-DET-4** *(proposed)* — **Timing-regularity signal** *(strongest, hardest
  to evade)*. Coefficient of variation `STDDEV(gap)/AVG(gap)` over within-session
  gaps. A `sleep(n)` loop is *too regular* (low CV); humans are bursty (high CV).
  A scraper can slow down to dodge a speed threshold but must also *randomise* to
  look human — most don't.
- **FR-DET-5** *(proposed)* — **Pace & burst signals.** Median within-session gap
  (very low = inhuman pace) and peak events in a single hour / per active day
  (marathon proxy). True longest-run reconstruction is a later refinement.
- **FR-DET-6** *(proposed)* — **Composite ranking + human review.** Combine the
  signals into a simple, interpretable suspicion score; present the **top-N
  accounts with the raw evidence beside each** (skip rate, median gap, timing CV,
  peak hour, total events). The professor judges — the tool never classifies
  autonomously.
- **FR-DET-7** *(proposed)* — **Window control.** Default last 7 days; admin can
  widen to cover a full study period. Thresholds (fast-skip seconds, suspicion
  weights) are page-level constants tuned by observation in v1; promote to
  `settings` only if/when enforcement (Phase 2) ships.

### Non-functional
- Inherits **NFR-7** (this is its committed first phase) and **NFR-2** (read-only
  page; parametrise the window, escape any displayed nickname).
- Portable SQL — no CTEs / window functions, mirroring `analytics.php`.

---

## 5. Where it lives

A new read-only admin page **`admin/abuse.php`**, Hebrew title
**"חשד לפעילות אוטומטית"**, linked from `admin/stats.php` (and the future admin
hub, FR-ADM-4). Same shape as `analytics.php`: one set of queries, a ranked
table, an explicit caveat banner ("signals of automation, not proof — review
before acting").

---

## 6. Limitations (on the record)

- **Detection ≠ prevention.** An offline flag won't stop a grab in progress. We
  accept this: the realistic threat is a *slow* harvest or a post-hoc Telegram
  export, and a delayed human-reviewed catch beats a false-positive lockout of a
  real crammer.
- **Evadable by a careful adversary.** A deliberately human-paced, randomised
  scraper defeats every timing signal. The aim is to catch the fast/lazy
  majority and to *measure* behaviour — not perfect detection.
- **Team-up is invisible here.** N students each behaving humanly, dividing the
  bank between them, leaves no per-account anomaly. Out of scope; noted in
  ADR-010 as accepted residual risk.
- **Skip target unknown** (§2) — counts/timing only, not the exposed question
  set, until the skip handler logs the qid.

---

## 7. Traceability

- System requirement → [../requirements.md](../requirements.md) **NFR-7**
- Decision / threat model → [../design.md](../design.md) **ADR-010**
- Backlog context → [../../ROADMAP.md](../../ROADMAP.md) #7
- Related: session cleanup [../design.md](../design.md) ADR-005 (FR-SES-1);
  analytics-page pattern this mirrors, ADR-009 / [gamification-analytics.md](gamification-analytics.md)
