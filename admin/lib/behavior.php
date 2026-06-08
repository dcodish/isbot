<?php
/**
 * admin/lib/behavior.php
 * Shared behavioural analysis & suspicion scoring for scraping/farming detection.
 * Used by BOTH admin/abuse.php (ranked list) and admin/user.php (drill-down), so
 * the list and the profile always agree on a user's score.
 *
 * THE MODEL  (documented in docs/features/abuse-detection.md §Scoring model)
 *   Each account gets a 0–100 suspicion score = the sum of behavioural
 *   contributions, clamped to [0,100]. Contributions are signed:
 *     • POSITIVE  = incriminating (bot-like)   — fast-skip harvesting, over-regular
 *                   timing, inhuman pace, marathon bursts, extreme accuracy.
 *     • NEGATIVE  = exculpatory  (human-like)   — human-band accuracy, long-term
 *                   use, broad engagement (surveys / leaderboards / badges).
 *   Every contribution is itemised (label, points, plain-English detail) so a
 *   human can see EXACTLY why a score is what it is — the score only sorts; the
 *   breakdown is the evidence. Nothing here blocks or limits anyone.
 *
 *   Thresholds are page-level constants tuned by eye on real data. They are NOT
 *   in `settings` because nothing enforces on them yet (detection-only, ADR-010).
 *   When/if a cap ships, promote the ones it enforces on.
 */

// ── Action types (from the `actions` lookup) ──────────────────────────────────
const BP_CORRECT    = 1;
const BP_WRONG      = 2;
const BP_SKIP       = 3;
const BP_SURVEY_ANS = 12;            // S-ans — answered an optional survey
const BP_LBOARD     = [23, 24, 25];  // leaderboard views (all/weekly/monthly)
const BP_BADGE      = 40;            // badge earned

// ── Tunable thresholds ────────────────────────────────────────────────────────
const BP_FAST_SKIP_SECS = 3;   // a skip with a smaller preceding gap = "barely read it"
const BP_MIN_EVENTS     = 20;  // fewer actions than this in scope ⇒ don't score (too little to judge)
const BP_MIN_GAPS_CV    = 8;   // need this many in-session gaps before the regularity (CV) number is trustworthy

// ── Score bands (shared by both pages) ────────────────────────────────────────
const BP_BAND_RED   = 50;  // ≥ ⇒ "looks automated"
const BP_BAND_AMBER = 25;  // ≥ ⇒ "worth a look"

function bp_median(array $a) {
    if (!$a) return null;
    sort($a); $n = count($a); $m = intdiv($n, 2);
    return $n % 2 ? $a[$m] : ($a[$m - 1] + $a[$m]) / 2;
}
function bp_cv(array $a) {                       // coefficient of variation = sd / mean
    $n = count($a); if ($n < 2) return null;
    $mean = array_sum($a) / $n; if ($mean <= 0) return null;
    $var = 0; foreach ($a as $x) $var += ($x - $mean) ** 2;
    return sqrt($var / $n) / $mean;
}

/**
 * Compute behavioural metrics from one user's time-sorted event stream.
 * @param array $events  list of ['at'=>int action_type, 'av'=>int additional_value, 'ts'=>int unixtime]
 * @param int   $session_gap_secs  gaps longer than this = a real break (excluded from pace/regularity)
 */
function bp_metrics_from_stream(array $events, int $session_gap_secs): array {
    $m = ['events'=>0,'correct'=>0,'wrong'=>0,'skips'=>0,'fast_skips'=>0,
          'surveys'=>0,'lboard'=>0,'badges'=>0,
          'gaps'=>[], 'hours'=>[], 'days'=>[], 'distinct_q'=>[], 'first'=>null,'last'=>null];
    $prev = null;
    foreach ($events as $e) {
        $at = (int)$e['at']; $av = (int)$e['av']; $ts = (int)$e['ts'];
        $m['events']++;
        if ($m['first'] === null) $m['first'] = $ts;
        $m['last'] = $ts;
        if      ($at === BP_CORRECT)            { $m['correct']++; $m['distinct_q'][$av] = 1; }
        elseif  ($at === BP_WRONG)              { $m['wrong']++;   $m['distinct_q'][$av] = 1; }
        elseif  ($at === BP_SKIP)                 $m['skips']++;
        elseif  ($at === BP_SURVEY_ANS)           $m['surveys']++;
        elseif  (in_array($at, BP_LBOARD, true))  $m['lboard']++;
        elseif  ($at === BP_BADGE)                $m['badges']++;
        $m['hours'][intdiv($ts, 3600)] = ($m['hours'][intdiv($ts, 3600)] ?? 0) + 1;
        $m['days'][intdiv($ts, 86400)] = 1;
        if ($prev !== null) {
            $g = $ts - $prev;
            if ($g >= 0 && $g <= $session_gap_secs) {
                $m['gaps'][] = $g;
                if ($at === BP_SKIP && $g < BP_FAST_SKIP_SECS) $m['fast_skips']++;
            }
        }
        $prev = $ts;
    }
    $ans = $m['correct'] + $m['wrong'];
    $m['answered']           = $ans;
    $m['accuracy']           = $ans ? $m['correct'] / $ans : null;
    $m['fast_skip_share']    = $m['events'] ? $m['fast_skips'] / $m['events'] : 0;
    $m['skip_share']         = $m['events'] ? $m['skips'] / $m['events'] : 0;
    $m['median_gap']         = bp_median($m['gaps']);
    $m['cv']                 = count($m['gaps']) >= BP_MIN_GAPS_CV ? bp_cv($m['gaps']) : null;
    $m['peak_hour']          = $m['hours'] ? max($m['hours']) : 0;
    $m['active_days']        = count($m['days']);
    $m['lifespan_days']      = ($m['first'] !== null) ? ($m['last'] - $m['first']) / 86400 : 0;
    $m['distinct_questions'] = count($m['distinct_q']);
    return $m;
}

/**
 * Turn metrics into a signed, itemised suspicion score.
 * @return array ['score'=>int|null, 'contributions'=>[[label,points,detail,type], …], 'verdict'=>string]
 */
function bp_score(array $m): array {
    if ($m['events'] < BP_MIN_EVENTS) {
        return ['score'=>null, 'contributions'=>[],
                'verdict'=>'insufficient data ('.$m['events'].' actions)'];
    }
    $clamp = fn($x) => max(0, min(1, $x));
    $c = [];

    // ── INCRIMINATING (positive) ──────────────────────────────────────────────
    $p = (int) round(45 * $clamp($m['fast_skip_share'] / 0.5));            // headline harvest tell
    if ($p > 0) $c[] = ['Fast-skip harvesting', +$p,
        "{$m['fast_skips']} skips under ".BP_FAST_SKIP_SECS."s (".round(100*$m['fast_skip_share'])."% of all actions)", 'bad'];

    $p = $m['cv'] !== null ? (int) round(30 * $clamp((0.6 - $m['cv']) / 0.6)) : 0;  // even pacing = a sleep() loop
    if ($p > 0) $c[] = ['Over-regular timing', +$p, "gap CV ".round($m['cv'],2)." — low means robotic, even spacing", 'bad'];

    $p = $m['median_gap'] !== null ? (int) round(15 * $clamp((8 - $m['median_gap']) / 8)) : 0;
    if ($p > 0) $c[] = ['Inhuman pace', +$p, "median ".round($m['median_gap'],1)."s between actions", 'bad'];

    $p = (int) round(10 * $clamp(($m['peak_hour'] - 90) / 120));
    if ($p > 0) $c[] = ['Marathon burst', +$p, "{$m['peak_hour']} actions packed into a single hour", 'bad'];

    if ($m['answered'] >= 30 && $m['accuracy'] !== null) {
        if      ($m['accuracy'] > 0.92) $c[] = ['Near-perfect accuracy', +10, round(100*$m['accuracy'])."% correct — possible answer key", 'bad'];
        elseif  ($m['accuracy'] < 0.28) $c[] = ['Near-random accuracy', +10, round(100*$m['accuracy'])."% correct — clicking through without reading?", 'bad'];
    }

    // ── EXCULPATORY (negative) ────────────────────────────────────────────────
    if ($m['answered'] >= 30 && $m['accuracy'] !== null && $m['accuracy'] >= 0.40 && $m['accuracy'] <= 0.85)
        $c[] = ['Human-band accuracy', -25, round(100*$m['accuracy'])."% correct — the sweet spot of genuine learning", 'good'];

    if      ($m['lifespan_days'] >= 14) $c[] = ['Long-term regular', -20, round($m['lifespan_days'])." days between first and last activity", 'good'];
    elseif  ($m['lifespan_days'] >= 5)  $c[] = ['Multi-day use', -10, round($m['lifespan_days'])." days of activity span", 'good'];
    elseif  ($m['lifespan_days'] < 1 && $m['events'] >= 100) $c[] = ['Single-day burst', +5, "all activity within one day", 'bad'];

    if ($m['surveys'] >= 1 || ($m['lboard'] >= 5 && $m['badges'] >= 3))
        $c[] = ['Broad human engagement', -15,
            "{$m['surveys']} surveys answered · {$m['lboard']} leaderboard checks · {$m['badges']} badges", 'good'];

    $score = max(0, min(100, array_sum(array_map(fn($x) => $x[1], $c))));
    $verdict = $score >= BP_BAND_RED ? 'looks automated'
             : ($score >= BP_BAND_AMBER ? 'worth a look' : 'looks human');
    return ['score'=>$score, 'contributions'=>$c, 'verdict'=>$verdict];
}

/** Top N incriminating reasons, for the "why" column on the list page. */
function bp_why(array $contributions, int $n = 2): array {
    $bad = array_values(array_filter($contributions, fn($x) => $x[1] > 0));
    usort($bad, fn($a, $b) => $b[1] <=> $a[1]);
    return array_map(fn($x) => $x[0].' ('.$x[2].')', array_slice($bad, 0, $n));
}
