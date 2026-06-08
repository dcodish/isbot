# Feature Spec — Offline Abuse / Scraping Detection

**Status:** PHASE 1 BUILT (detection-only) · **Created:** 2026-06-08
**Pages:** `admin/abuse.php` (ranked list) + `admin/user.php` (drill-down) ·
**Scoring:** `admin/lib/behavior.php` (shared) · linked from `admin/stats.php`
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

Every timing signal derives from one thing: the gap between a user's consecutive
`log` rows. The page fetches the window **ordered by `(userid, timestamp)`** in a
single pass and computes the gap series in PHP — cheaper and clearer than a
per-row correlated subquery, and avoids window functions (not relied on, matching
`analytics.php`):

```sql
SELECT userid, action_type AS at, additional_value AS av, UNIX_TIMESTAMP(timestamp) AS ts
FROM log
WHERE timestamp >= NOW() - INTERVAL :window_days DAY
ORDER BY userid, timestamp
```

Gaps larger than `settings.session_gap_minutes` are treated as **between-session**
idle and excluded from pace/regularity stats (they're real breaks, not thinking).

> **Performance:** the ordered scan is backed by an index on `log(userid,
> timestamp)` — shipped as `migrations/2026-06-08_log_user_time_index.sql`
> (idempotent).

---

## 4. Requirements

### Functional

- **FR-DET-1** *(built)* — **Offline, read-only, silent.** The analysis runs
  outside the live message path (admin-triggered / periodic job), performs no
  writes, and produces **no** user-facing effect — no block, throttle, or
  message. This stance is the feature, not a limitation (ADR-010).
- **FR-DET-2** *(built)* — **Inter-event gap primitive.** Compute per-user
  consecutive-event gaps from `log` via an ordered single-pass scan in PHP (no
  window functions); exclude gaps > `session_gap_minutes` as idle. §3.
- **FR-DET-3** *(built)* — **Fast-skip signal** *(headline)*. Per user over
  the window: count and share of skips (action 3) preceded by a gap below a
  threshold (default ~3 s). Skipping is the cheapest harvest move (reveals the
  question, advances, no thinking, no wrong-answer risk); *fast* skips in volume
  are the loudest exfiltration tell.
- **FR-DET-4** *(built)* — **Timing-regularity signal** *(strongest, hardest
  to evade)*. Coefficient of variation `STDDEV(gap)/AVG(gap)` over within-session
  gaps. A `sleep(n)` loop is *too regular* (low CV); humans are bursty (high CV).
  A scraper can slow down to dodge a speed threshold but must also *randomise* to
  look human — most don't.
- **FR-DET-5** *(built)* — **Pace & burst signals.** Median within-session gap
  (very low = inhuman pace) and peak events in a single hour / per active day
  (marathon proxy). True longest-run reconstruction is a later refinement.
- **FR-DET-6** *(built)* — **Composite ranking + human review.** Combine the
  signals into a simple, interpretable suspicion score; present the **top-N
  accounts with the raw evidence beside each** (skip rate, median gap, timing CV,
  peak hour, total events). The professor judges — the tool never classifies
  autonomously.
- **FR-DET-7** *(built)* — **Window control.** Default last 7 days; admin can
  widen to cover a full study period. Thresholds (fast-skip seconds, suspicion
  weights) are page-level constants tuned by observation in v1; promote to
  `settings` only if/when enforcement (Phase 2) ships.
- **FR-DET-8** *(built)* — **Signed, itemised scoring with exculpatory signals.**
  The suspicion score sums *incriminating* (positive) and *exculpatory*
  (negative) contributions, clamped to 0–100, so a fast but genuine student is
  pulled back down rather than flagged on speed alone. Every contribution is
  itemised (label, points, plain-English detail). The model is the single source
  of truth in `admin/lib/behavior.php`, shared by the list and the drill-down. §5.
- **FR-DET-9** *(built)* — **Per-account drill-down.** `admin/user.php?id=…`
  (linked from every list row) shows one account's full profile: the score
  breakdown, timing fingerprint, daily rhythm, busiest days, action mix, question
  coverage, and a raw recent-activity cadence sample. Read-only, all-time.

### Non-functional
- Inherits **NFR-7** (this is its committed first phase) and **NFR-2** (read-only
  page; parametrise the window, escape any displayed nickname).
- Portable SQL — no CTEs / window functions, mirroring `analytics.php`.

---

## 5. Scoring model (what we check, and how it counts)

Implemented in `admin/lib/behavior.php` (one source of truth for both pages). The
score is a **sum of signed contributions, clamped to 0–100**. Positive =
bot-like; negative = human-like. An account needs ≥ 20 actions in scope to be
scored at all. Every signal derives from the `log` event stream (§2–3).

**Incriminating (raise the score):**

| Behaviour | Max | Fires on | Why it's bot-like |
|---|---|---|---|
| **Fast-skip harvesting** | +45 | share of actions that are skips < 3 s | the cheapest way to harvest the bank — reveal & advance, no thinking |
| **Over-regular timing** | +30 | low gap CV (needs ≥ 8 gaps) | a `sleep(n)` loop is *too even*; humans are bursty |
| **Inhuman pace** | +15 | small median gap (< ~8 s) | sustained sub-human reading speed |
| **Marathon burst** | +10 | peak actions in one hour (> ~90) | machine throughput |
| **Near-perfect accuracy** | +10 | ≥ 30 answers & > 92 % correct | likely answering from a key |
| **Near-random accuracy** | +10 | ≥ 30 answers & < 28 % correct | clicking through without reading |

**Exculpatory (lower the score):**

| Behaviour | Pts | Fires on | Why it's human |
|---|---|---|---|
| **Human-band accuracy** | −25 | ≥ 30 answers & 40–85 % correct | the sweet spot of genuine learning |
| **Long-term regular** | −20 | ≥ 14-day activity span | scrapers burst, they don't return for weeks |
| **Multi-day use** | −10 | ≥ 5-day span | spread-out use, not a one-shot dump |
| **Single-day burst** | +5 | all activity in < 1 day (≥ 100 actions) | the harvest shape |
| **Broad human engagement** | −15 | answered a survey, or ≥ 5 leaderboard checks + ≥ 3 badges | no automated client bothers with these |

**Bands:** ≥ 50 *looks automated* · 25–49 *worth a look* · < 25 *looks human*.

**Worked example — why Li123 scores ~0 despite being fast** (the case that drove
FR-DET-8). Li123: 1,291 answers at 66 % correct, **3 skips total**, active 34
days, answered 33 surveys. Contributions: marathon burst ≈ +2; human-band
accuracy −25; long-term regular −20; broad engagement −15 ⇒ sum −58 ⇒ **clamped
to 0**. Speed alone no longer flags a genuine crammer; the absence of skips plus
human accuracy, longevity, and engagement clears them. A real harvester (mostly
fast-skips, one-day burst, extreme accuracy) lands near 100.

> Thresholds are eyeballed v1 constants — retune against the real distribution
> the page surfaces. They are intentionally not in `settings` (nothing enforces
> on them; detection-only, ADR-010).

## 6. Where it lives

- **`admin/abuse.php`** — the ranked list (🕵️ Abuse Detection), linked from
  `admin/stats.php` and `admin/analytics.php`. Every row links to the drill-down.
- **`admin/user.php?id=…`** — the per-account drill-down (FR-DET-9).
- **`admin/lib/behavior.php`** — the shared scoring model (§5), used by both.

Same shape and explanation style as `analytics.php`: plain-language help boxes, an
explicit "signals, not proof — review before acting" caveat, no schema change.

---

## 7. Limitations (on the record)

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

## 8. Traceability

- System requirement → [../requirements.md](../requirements.md) **NFR-7**
- Decision / threat model → [../design.md](../design.md) **ADR-010**
- Backlog context → [../../ROADMAP.md](../../ROADMAP.md) #7
- Related: session cleanup [../design.md](../design.md) ADR-005 (FR-SES-1);
  analytics-page pattern this mirrors, ADR-009 / [gamification-analytics.md](gamification-analytics.md)
