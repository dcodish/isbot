<?php
/**
 * admin/abuse.php — Offline scraping / farming detection (read-only, silent).
 *
 * WHAT THIS PAGE IS
 *   It looks at the activity log and flags accounts that *behave* like a script
 *   rather than a human — someone harvesting the question bank or farming points.
 *   It takes NO action: it never blocks, throttles, or messages anyone. You read
 *   the flags and decide. (Spec: docs/features/abuse-detection.md; ADR-010.)
 *
 * HOW IT DECIDES (all from the `log` table — no new data is collected)
 *   The single primitive is the GAP between a user's consecutive actions. From
 *   that we derive a few human-vs-bot tells:
 *     1. Fast-skip spam   — skipping questions in under a few seconds, in volume.
 *                           Skipping reveals the question and moves on with zero
 *                           thinking — the cheapest way to harvest the bank.
 *     2. Timing regularity— a sleep()-loop is too *even*; humans are bursty. Low
 *                           variation in the gaps is the hardest tell to fake.
 *     3. Inhuman pace     — a very low typical gap between actions.
 *     4. Marathon burst   — a huge number of actions packed into a single hour.
 *   These are combined into a 0–100 "suspicion score" purely to sort the table.
 *   The raw numbers are shown next to it so YOU judge, not the score.
 *
 *   Everything is computed offline (only when you open this page). The thresholds
 *   below are starting points, tuned by eye — adjust as the real data teaches you.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit();
}

include 'backend/database.php';

// ── Tunable thresholds (page-level; promote to `settings` only if enforcement ships) ──
$days        = isset($_GET['days']) ? max(1, min(180, intval($_GET['days']))) : 7;  // lookback window
$FAST_SKIP   = 3;     // a skip with a preceding gap below this many seconds = "barely read it"
$MIN_EVENTS  = 20;    // need at least this many actions in the window to judge an account at all
$MIN_GAPS_CV = 8;     // need at least this many in-session gaps before the regularity number is trustworthy
$SKIP        = 3;     // action_type for a regular-question Skip
$ANSWER      = [1, 2];// action_types for answered (correct / wrong)

// Session gap (seconds): anything longer than this is a real break, not "thinking", so it's
// excluded from pace/regularity. Read from the same `settings` knob the bot uses.
$gap_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT setting_value FROM settings WHERE setting_key = 'session_gap_minutes' LIMIT 1"));
$SESSION_GAP = ($gap_row ? max(1, intval($gap_row['setting_value'])) : 30) * 60;

// ── Pull every action in the window, in time order per user ───────────────────
// One sequential pass; we build the gap series in PHP (cheaper and clearer than a
// per-row correlated subquery). Benefits from an index on log(userid, timestamp) —
// see migrations/2026-06-08_log_user_time_index.sql.
$nick = [];
$nr = mysqli_query($conn, "SELECT id, nickname FROM users");
while ($n = mysqli_fetch_assoc($nr)) $nick[(string)$n['id']] = $n['nickname'];
mysqli_free_result($nr);

$stream = mysqli_query($conn, "
    SELECT userid, action_type, UNIX_TIMESTAMP(timestamp) AS ts
    FROM log
    WHERE timestamp >= NOW() - INTERVAL {$days} DAY
    ORDER BY userid, timestamp");

$U = [];            // userid => accumulator
$prev_uid = null;
$prev_ts  = null;
while ($row = mysqli_fetch_assoc($stream)) {
    $uid = (string)$row['userid'];
    $at  = (int)$row['action_type'];
    $ts  = (int)$row['ts'];

    if ($uid !== $prev_uid) { $prev_ts = null; }   // new user → no carry-over gap
    if (!isset($U[$uid])) {
        $U[$uid] = ['events'=>0,'skips'=>0,'fast_skips'=>0,'answers'=>0,
                    'gaps'=>[], 'hours'=>[], 'first'=>$ts, 'last'=>$ts];
    }
    $u =& $U[$uid];
    $u['events']++;
    $u['last'] = $ts;
    if ($at === $SKIP)               $u['skips']++;
    if (in_array($at, $ANSWER, true)) $u['answers']++;
    $u['hours'][intdiv($ts, 3600)] = ($u['hours'][intdiv($ts, 3600)] ?? 0) + 1;

    if ($prev_ts !== null) {
        $gap = $ts - $prev_ts;
        if ($gap >= 0 && $gap <= $SESSION_GAP) {           // within-session only
            $u['gaps'][] = $gap;
            if ($at === $SKIP && $gap < $FAST_SKIP) $u['fast_skips']++;
        }
    }
    unset($u);
    $prev_uid = $uid;
    $prev_ts  = $ts;
}
mysqli_free_result($stream);

// ── Score each account ────────────────────────────────────────────────────────
function _median(array $a) {
    if (!$a) return null;
    sort($a); $n = count($a); $m = intdiv($n, 2);
    return $n % 2 ? $a[$m] : ($a[$m-1] + $a[$m]) / 2;
}
function _cv(array $a) {                       // coefficient of variation = sd / mean
    $n = count($a); if ($n < 2) return null;
    $mean = array_sum($a) / $n; if ($mean <= 0) return null;
    $var = 0; foreach ($a as $x) $var += ($x - $mean) ** 2;
    return sqrt($var / $n) / $mean;
}
function _clamp($x) { return max(0, min(1, $x)); }

$rows = [];
foreach ($U as $uid => $u) {
    if ($u['events'] < $MIN_EVENTS) continue;          // too little activity to judge

    $fast_share = $u['events'] ? $u['fast_skips'] / $u['events'] : 0;
    $skip_share = $u['events'] ? $u['skips'] / $u['events'] : 0;
    $median     = _median($u['gaps']);
    $cv         = count($u['gaps']) >= $MIN_GAPS_CV ? _cv($u['gaps']) : null;
    $peak_hour  = $u['hours'] ? max($u['hours']) : 0;

    // Each sub-signal mapped to 0..1 "intensity"; weights sum to 100.
    $fs_int    = _clamp($fast_share / 0.5);                       // 50%+ fast-skips ⇒ maxed
    $reg_int   = $cv !== null ? _clamp((0.6 - $cv) / 0.6) : 0;    // very even timing ⇒ high
    $pace_int  = $median !== null ? _clamp((10 - $median) / 10) : 0; // ~0s gaps ⇒ high
    $burst_int = _clamp(($peak_hour - 60) / 120);                 // 60→0, 180+/hr ⇒ maxed
    $score = (int) round(40*$fs_int + 35*$reg_int + 15*$pace_int + 10*$burst_int);

    // Plain-English reasons (only the ones that actually fired).
    $why = [];
    if ($u['fast_skips'] >= 10 && $fast_share >= 0.25)
        $why[] = "{$u['fast_skips']} near-instant skips (".round(100*$fast_share)."% of actions)";
    if ($cv !== null && $cv < 0.35)
        $why[] = "very regular timing (CV ".round($cv,2).")";
    if ($median !== null && $median < 4)
        $why[] = "median ".round($median,1)."s between actions";
    if ($peak_hour >= 90)
        $why[] = "{$peak_hour} actions in one hour";

    $rows[] = [
        'uid'=>$uid, 'nick'=>$nick[$uid] ?? '—',
        'events'=>$u['events'], 'answers'=>$u['answers'],
        'skips'=>$u['skips'], 'skip_share'=>$skip_share,
        'fast_skips'=>$u['fast_skips'], 'fast_share'=>$fast_share,
        'median'=>$median, 'cv'=>$cv, 'peak_hour'=>$peak_hour,
        'score'=>$score, 'why'=>$why,
    ];
}
usort($rows, fn($a,$b) => $b['score'] <=> $a['score']);

$strong = array_filter($rows, fn($r) => $r['score'] >= 50);
$watch  = array_filter($rows, fn($r) => $r['score'] >= 25 && $r['score'] < 50);
$top15  = array_slice($rows, 0, 15);

// Colour helpers for the cells.
function cell($val, $amber, $red, $invert=false) {
    if ($val === null) return '';
    $hot = $invert ? ($val <= $red) : ($val >= $red);
    $warm= $invert ? ($val <= $amber) : ($val >= $amber);
    return $hot ? 'bg-red' : ($warm ? 'bg-amber' : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abuse / Scraping Detection</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Varela+Round">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        body { padding-bottom: 60px; }
        .chart-wrap, .panel-card {
            background:#fff; border-radius:4px; padding:20px 25px;
            margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,.08);
        }
        .chart-wrap h4, .panel-card h4 { margin-top:0; color:#435d7d; }
        .muted { color:#888; font-size:12px; margin-top:6px; }
        .stat-box { background:#fff; border-radius:4px; padding:16px; margin-bottom:20px;
            box-shadow:0 1px 3px rgba(0,0,0,.08); text-align:center; }
        .stat-box .num { font-size:30px; font-weight:bold; color:#435d7d; }
        .stat-box .lbl { color:#888; font-size:12px; margin-top:4px; }
        table.table th { background:#f9f9f9; }
        .bg-red   { background:#fdecea !important; color:#a3372c; font-weight:bold; }
        .bg-amber { background:#fff8e1 !important; color:#8a6d00; }
        .row-red   td { background:#fdecea; }
        .row-amber td { background:#fffaf0; }
        .score-pill { display:inline-block; min-width:34px; text-align:center; border-radius:10px;
            padding:1px 8px; font-weight:bold; color:#fff; }
        .caveat { background:#fff8e1; border-left:3px solid #f0ad4e; padding:8px 12px;
            font-size:12px; color:#6b5900; border-radius:3px; margin-bottom:16px; }
        .help { background:#eef5fb; border-left:3px solid #5b9bd5; padding:10px 14px;
            font-size:13px; color:#2c3e50; border-radius:3px; margin-bottom:14px; line-height:1.5; }
        .help b { color:#2c5f8a; }
        .takeaway { background:#eafaf1; border-left:3px solid #27ae60; padding:10px 14px;
            font-size:14px; color:#1e5631; border-radius:3px; margin-top:14px; }
        .takeaway.flat { background:#f4f6f7; border-left-color:#95a5a6; color:#555; }
        .takeaway.warn { background:#fdecea; border-left-color:#e74c3c; color:#922b21; }
        .intro { background:#fff; border-radius:4px; padding:18px 22px; margin-bottom:24px;
            box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .intro h4 { margin-top:0; color:#435d7d; }
        .intro ol, .intro ul { margin:0; padding-left:20px; }
        .intro li { margin-bottom:4px; font-size:13px; color:#444; }
        .why { font-size:12px; color:#777; }
    </style>
</head>
<body>
<div class="container" style="margin-top:30px;">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0; color:#435d7d;">🕵️ Abuse / Scraping Detection</h2>
        <div>
            <a href="stats.php" class="btn btn-default btn-sm">📊 Usage Stats</a>
            <a href="analytics.php" class="btn btn-default btn-sm">📈 Analytics</a>
            <a href="home.php" class="btn btn-default btn-sm">🏠 ראשי</a>
            <a href="logout.php" class="btn btn-default btn-sm">התנתקות</a>
        </div>
    </div>

    <!-- Period picker -->
    <form method="GET" class="form-inline" style="margin-bottom:20px;">
        <label>Window: </label>
        <?php foreach ([1, 3, 7, 14, 30, 90] as $d): ?>
            <a href="?days=<?= $d ?>" class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-default' ?>" style="margin-left:5px;"><?= $d ?>d</a>
        <?php endforeach; ?>
        <span class="muted" style="margin-left:15px;">looking at the last <?= $days ?> day<?= $days===1?'':'s' ?> of activity</span>
    </form>

    <div class="intro">
        <h4>What is this page?</h4>
        <p style="font-size:13px; color:#444; margin-bottom:10px;">
            Two things can abuse the bot: a student <b>scripting their account to farm points</b>, and anyone
            <b>scraping the question bank</b> (an automated client racing through questions to copy them out). This page
            reads the activity log and looks for accounts that <b>behave like a script, not a person</b>. It is
            <b>offline and silent</b> — it never blocks or messages anyone; it just shows you who looks suspicious so
            <b>you</b> can decide whether to look closer.
        </p>
        <p style="font-size:13px; color:#444; margin-bottom:6px;">The four human-vs-bot tells it uses:</p>
        <ol>
            <li><b>Fast-skip spam</b> — hammering "skip" in under <?= $FAST_SKIP ?> seconds, over and over. Skipping shows the
                question and moves on with no thinking, so it's the cheapest way to harvest the bank.</li>
            <li><b>Timing regularity</b> — a script that waits a fixed time between actions is <em>too even</em>. Real people
                are irregular. This is the hardest signal to fake.</li>
            <li><b>Inhuman pace</b> — a very small typical gap between actions (e.g. a couple of seconds, every time).</li>
            <li><b>Marathon burst</b> — a huge number of actions crammed into a single hour.</li>
        </ol>
    </div>

    <div class="caveat">
        ⚠️ <b>These are signals, not proof.</b> A keen student cramming before the exam can look fast too — that's exactly
        why nothing here takes action automatically. Treat a high score as <em>"worth a look"</em>, not <em>"guilty"</em>.
        Open the account, see if the pattern is genuinely robotic (e.g. <em>hundreds</em> of sub-second skips in a row),
        and only then decide. Accounts with fewer than <?= $MIN_EVENTS ?> actions in the window are skipped — too little to judge.
    </div>

    <!-- Summary tiles -->
    <div class="row">
        <div class="col-sm-3"><div class="stat-box"><div class="num"><?= count($rows) ?></div><div class="lbl">Accounts examined</div></div></div>
        <div class="col-sm-3"><div class="stat-box"><div class="num" style="color:#c0392b;"><?= count($strong) ?></div><div class="lbl">Look automated (score ≥ 50)</div></div></div>
        <div class="col-sm-3"><div class="stat-box"><div class="num" style="color:#b9770e;"><?= count($watch) ?></div><div class="lbl">Worth a look (25–49)</div></div></div>
        <div class="col-sm-3"><div class="stat-box"><div class="num"><?= $days ?>d</div><div class="lbl">Window</div></div></div>
    </div>

    <?php
    // Auto takeaway from the actual top row.
    if (!$rows): ?>
        <div class="takeaway flat">📊 No account had at least <?= $MIN_EVENTS ?> actions in the last <?= $days ?> day(s) — nothing to assess yet. Try a wider window.</div>
    <?php elseif ($strong):
        $t = $rows[0]; ?>
        <div class="takeaway warn">
            📊 <b>In plain terms:</b> <b><?= count($strong) ?></b> account<?= count($strong)===1?'':'s' ?> look automated.
            The top one (<b><?= htmlspecialchars($t['nick']) ?></b>) <?= $t['why'] ? implode('; ', array_map('htmlspecialchars',$t['why'])) : 'scored high on the timing signals' ?>.
            Worth opening before anything else.
        </div>
    <?php else: ?>
        <div class="takeaway">📊 <b>In plain terms:</b> nobody crossed the "looks automated" line in this window
            <?= $watch ? ' — but '.count($watch).' account(s) are a little fast and worth a glance.' : '. Activity looks human.' ?></div>
    <?php endif; ?>

    <!-- Top suspicious chart -->
    <?php if ($top15 && $top15[0]['score'] > 0): ?>
    <div class="chart-wrap">
        <h4>Most suspicious accounts</h4>
        <div class="help">💡 <b>How to read this.</b> Each bar is one account's <b>suspicion score (0–100)</b> — higher means
            more bot-like. The score only <em>sorts</em> the list; the table below shows the raw numbers behind it so you can
            judge for yourself. Red bars (≥ 50) are the ones to open first.</div>
        <canvas id="suspChart" height="<?= max(90, count($top15)*22) ?>"></canvas>
    </div>
    <?php endif; ?>

    <!-- Detail table -->
    <div class="panel-card">
        <h4>The numbers behind each account</h4>
        <div class="help">💡 <b>What each column means.</b>
            <b>Actions</b> = everything they did (answers, skips, menu taps). ·
            <b>Skips</b> = how many questions they skipped, and what share of their actions that was. ·
            <b>Fast-skips</b> = skips done in under <?= $FAST_SKIP ?>s (the harvesting tell). ·
            <b>Median gap</b> = the typical seconds between two actions — <em>small = inhumanly fast</em>. ·
            <b>Timing&nbsp;CV</b> = how <em>even</em> their pacing is — <em>low = robotic</em> (a person is jumpy). ·
            <b>Peak/hr</b> = the most actions they packed into a single hour. ·
            <b>Score</b> = the four combined. Red cells are the values pushing the score up.
        </div>
        <table class="table table-condensed table-hover">
            <thead><tr>
                <th>Account</th><th>Actions</th><th>Skips</th><th>Fast-skips</th>
                <th>Median gap</th><th>Timing CV</th><th>Peak/hr</th><th>Score</th><th>Why flagged</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center muted" style="padding:20px;">No accounts with enough activity in this window.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $rowcls = $r['score'] >= 50 ? 'row-red' : ($r['score'] >= 25 ? 'row-amber' : '');
                $pillbg = $r['score'] >= 50 ? '#c0392b' : ($r['score'] >= 25 ? '#e0a800' : '#95a5a6');
            ?>
                <tr class="<?= $rowcls ?>">
                    <td><b><?= htmlspecialchars($r['nick']) ?></b><br><span class="muted"><?= htmlspecialchars($r['uid']) ?></span></td>
                    <td><?= $r['events'] ?><br><span class="muted"><?= $r['answers'] ?> answered</span></td>
                    <td><?= $r['skips'] ?> <span class="muted">(<?= round(100*$r['skip_share']) ?>%)</span></td>
                    <td class="<?= cell($r['fast_share'], 0.25, 0.5) ?>"><?= $r['fast_skips'] ?> <span class="muted">(<?= round(100*$r['fast_share']) ?>%)</span></td>
                    <td class="<?= cell($r['median'], 8, 4, true) ?>"><?= $r['median']===null ? '—' : round($r['median'],1).'s' ?></td>
                    <td class="<?= $r['cv']===null ? '' : cell($r['cv'], 0.6, 0.35, true) ?>"><?= $r['cv']===null ? '<span class="muted">n/a</span>' : round($r['cv'],2) ?></td>
                    <td class="<?= cell($r['peak_hour'], 90, 150) ?>"><?= $r['peak_hour'] ?></td>
                    <td><span class="score-pill" style="background:<?= $pillbg ?>;"><?= $r['score'] ?></span></td>
                    <td class="why"><?= $r['why'] ? implode('<br>', array_map('htmlspecialchars', $r['why'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="muted">Timing CV needs at least <?= $MIN_GAPS_CV ?> in-session gaps to be meaningful — "n/a" means we didn't
            have enough. Gaps longer than the session break (<?= round($SESSION_GAP/60) ?> min) are treated as the user stepping away,
            not thinking, and don't count toward pace or regularity.</div>
    </div>

    <div class="intro">
        <h4>What this page does <em>not</em> do</h4>
        <ul>
            <li>It <b>doesn't block or limit anyone</b> — it only reports. (Deciding whether to add a real cap comes later,
                and only once this data shows a cap wouldn't hurt genuine crammers — see ROADMAP #7.)</li>
            <li>It <b>can't catch a careful team</b> — several students each going at a human pace, splitting the bank between
                them, leave no per-account anomaly. That's a known, accepted blind spot.</li>
            <li>It <b>can't say which questions a skipper saw</b> — skips don't record the question id today, only that a skip
                happened. Counts and timing are enough to flag the behaviour.</li>
        </ul>
    </div>

</div>

<?php if ($top15 && $top15[0]['score'] > 0): ?>
<script>
new Chart(document.getElementById('suspChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($r)=>$r['nick'].' ('.$r['uid'].')', $top15)) ?>,
        datasets: [{
            data: <?= json_encode(array_map(fn($r)=>$r['score'], $top15)) ?>,
            backgroundColor: <?= json_encode(array_map(fn($r)=>$r['score']>=50?'rgba(192,57,43,0.85)':($r['score']>=25?'rgba(224,168,0,0.85)':'rgba(149,165,166,0.7)'), $top15)) ?>,
        }]
    },
    options: {
        indexAxis: 'y', responsive: true, plugins: { legend: { display:false } },
        scales: { x: { beginAtZero:true, max:100, title:{display:true, text:'Suspicion score (0–100)'} } }
    }
});
</script>
<?php endif; ?>
</body>
</html>
