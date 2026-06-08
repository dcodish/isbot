<?php
/**
 * admin/user.php — Per-account behavioural drill-down (read-only).
 *
 * Opens the full behavioural profile of one account: the same analysis the
 * abuse.php list does, but expanded with the evidence behind every number —
 * score breakdown, gap fingerprint, daily rhythm, accuracy, coverage, and a raw
 * activity sample. Scoring comes from the shared lib/behavior.php so this page
 * and the list always agree. Takes no action.
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
require_once 'lib/behavior.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Session gap from settings (same knob the bot uses).
$gap_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT setting_value FROM settings WHERE setting_key = 'session_gap_minutes' LIMIT 1"));
$SESSION_GAP = ($gap_row ? max(1, intval($gap_row['setting_value'])) : 30) * 60;

// Core profile.
$user = $id ? mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT id, nickname, level, current_run, overall_points, last_interaction_at
     FROM users WHERE id = {$id}")) : null;

// Full event stream (all-time) + action-name map.
$events = [];
if ($user) {
    $res = mysqli_query($conn,
        "SELECT action_type AS at, additional_value AS av, UNIX_TIMESTAMP(timestamp) AS ts
         FROM log WHERE userid = {$id} ORDER BY timestamp");
    while ($r = mysqli_fetch_assoc($res)) $events[] = $r;
    mysqli_free_result($res);
}
$anames = [];
$ar = mysqli_query($conn, "SELECT action_id, action FROM actions");
while ($a = mysqli_fetch_assoc($ar)) $anames[(int)$a['action_id']] = $a['action'];
mysqli_free_result($ar);

$bank = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n FROM questions"))['n'];

$m = $events ? bp_metrics_from_stream($events, $SESSION_GAP) : null;
$s = $m ? bp_score($m) : null;

// ── Derived views for display ────────────────────────────────────────────────
// Gap fingerprint histogram (within-session gaps only, already in $m['gaps']).
$ghist = ['< 2s'=>0, '2–5s'=>0, '5–15s'=>0, '15–60s'=>0, '60s+'=>0];
if ($m) foreach ($m['gaps'] as $g) {
    if ($g < 2) $ghist['< 2s']++; elseif ($g < 5) $ghist['2–5s']++;
    elseif ($g < 15) $ghist['5–15s']++; elseif ($g < 60) $ghist['15–60s']++; else $ghist['60s+']++;
}
// Action breakdown (counts per action type, all-time).
$abreak = [];
if ($m) { foreach ($events as $e) { $at=(int)$e['at']; $abreak[$at]=($abreak[$at]??0)+1; } arsort($abreak); }
// Hour-of-day (correct local hours via SQL).
$hours = array_fill(0, 24, 0);
if ($user) { $hr = mysqli_query($conn, "SELECT HOUR(timestamp) h, COUNT(*) n FROM log WHERE userid={$id} GROUP BY HOUR(timestamp)");
    while ($x = mysqli_fetch_assoc($hr)) $hours[(int)$x['h']] = (int)$x['n']; }
// Top active days.
$topdays = [];
if ($user) { $dr = mysqli_query($conn,
    "SELECT DATE(timestamp) d, COUNT(*) n, MIN(TIME(timestamp)) f, MAX(TIME(timestamp)) l
     FROM log WHERE userid={$id} GROUP BY DATE(timestamp) ORDER BY n DESC LIMIT 8");
    while ($x = mysqli_fetch_assoc($dr)) $topdays[] = $x; }
// Recent activity sample (last 30 actions, with the gap before each).
$sample = [];
if ($m) {
    $tail = array_slice($events, -30);
    $prev = null;
    foreach ($tail as $e) {
        $ts = (int)$e['ts'];
        $sample[] = ['at'=>(int)$e['at'], 'ts'=>$ts, 'gap'=>($prev===null?null:$ts-$prev)];
        $prev = $ts;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User profile <?= $user ? htmlspecialchars($user['nickname']) : '' ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Varela+Round">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        body { padding-bottom: 60px; }
        .panel-card { background:#fff; border-radius:4px; padding:20px 25px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .panel-card h4 { margin-top:0; color:#435d7d; }
        .muted { color:#888; font-size:12px; }
        .stat-box { background:#fff; border-radius:4px; padding:14px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.08); text-align:center; }
        .stat-box .num { font-size:24px; font-weight:bold; color:#435d7d; }
        .stat-box .lbl { color:#888; font-size:12px; margin-top:4px; }
        .verdict { font-size:22px; font-weight:bold; }
        .score-big { font-size:46px; font-weight:bold; line-height:1; }
        .contrib-bad  td:first-child { border-left:4px solid #e74c3c; }
        .contrib-good td:first-child { border-left:4px solid #27ae60; }
        .pts-bad  { color:#c0392b; font-weight:bold; }
        .pts-good { color:#1e7e34; font-weight:bold; }
        .help { background:#eef5fb; border-left:3px solid #5b9bd5; padding:10px 14px; font-size:13px; color:#2c3e50; border-radius:3px; margin-bottom:14px; line-height:1.5; }
        .help b { color:#2c5f8a; }
        table.table th { background:#f9f9f9; }
        .gap-fast td { background:#fdecea; }
    </style>
</head>
<body>
<div class="container" style="margin-top:30px;">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0; color:#435d7d;">👤 <?= $user ? htmlspecialchars($user['nickname']) : 'User not found' ?>
            <?php if ($user): ?><span class="muted">· id <?= htmlspecialchars($user['id']) ?></span><?php endif; ?></h2>
        <div>
            <a href="abuse.php" class="btn btn-default btn-sm">← Detection list</a>
            <a href="stats.php" class="btn btn-default btn-sm">📊 Stats</a>
            <a href="home.php" class="btn btn-default btn-sm">🏠 ראשי</a>
        </div>
    </div>

    <?php if (!$user): ?>
        <div class="panel-card"><p>No user with id <b><?= htmlspecialchars((string)$id) ?></b>. Open this page from a row in the
            <a href="abuse.php">detection list</a>.</p></div>
        </div></body></html>
        <?php exit(); endif; ?>

    <?php if (!$m): ?>
        <div class="panel-card"><p>This account has no logged activity yet.</p></div>
    <?php else: ?>

    <!-- Verdict + score breakdown -->
    <div class="row">
        <div class="col-sm-4">
            <div class="panel-card" style="text-align:center;">
                <?php
                $col = $s['score']===null ? '#95a5a6' : ($s['score']>=BP_BAND_RED ? '#c0392b' : ($s['score']>=BP_BAND_AMBER ? '#e0a800' : '#27ae60'));
                ?>
                <div class="score-big" style="color:<?= $col ?>;"><?= $s['score']===null ? '—' : $s['score'] ?></div>
                <div class="muted">suspicion score (0–100)</div>
                <div class="verdict" style="color:<?= $col ?>; margin-top:10px;"><?= htmlspecialchars(ucfirst($s['verdict'])) ?></div>
                <div class="muted" style="margin-top:10px;">L<?= (int)$user['level'] ?> · <?= (int)$user['overall_points'] ?> pts · last seen <?= htmlspecialchars($user['last_interaction_at'] ?? '—') ?></div>
            </div>
        </div>
        <div class="col-sm-8">
            <div class="panel-card">
                <h4>Why this score — the analysis, itemised</h4>
                <div class="help">💡 Every behaviour we check, and how many points it added (🔴 incriminating) or subtracted
                    (🟢 reassuring). The score is just the sum, clamped to 0–100. <b>Read the details, not just the number.</b></div>
                <?php if ($s['score']===null): ?>
                    <p class="muted"><?= htmlspecialchars($s['verdict']) ?> — need at least <?= BP_MIN_EVENTS ?> actions to score.</p>
                <?php elseif (!$s['contributions']): ?>
                    <p class="muted">No signal fired in either direction — neutral behaviour.</p>
                <?php else: ?>
                <table class="table table-condensed">
                    <thead><tr><th>Behaviour</th><th style="text-align:right;">Points</th><th>What we saw</th></tr></thead>
                    <tbody>
                    <?php foreach ($s['contributions'] as $cc): ?>
                        <tr class="<?= $cc[1]>0 ? 'contrib-bad':'contrib-good' ?>">
                            <td><?= $cc[1]>0 ? '🔴':'🟢' ?> <?= htmlspecialchars($cc[0]) ?></td>
                            <td style="text-align:right;" class="<?= $cc[1]>0 ? 'pts-bad':'pts-good' ?>"><?= ($cc[1]>0?'+':'').$cc[1] ?></td>
                            <td class="muted"><?= htmlspecialchars($cc[2]) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Key metrics tiles -->
    <div class="row">
        <div class="col-sm-2 col-xs-4"><div class="stat-box"><div class="num"><?= $m['events'] ?></div><div class="lbl">total actions</div></div></div>
        <div class="col-sm-2 col-xs-4"><div class="stat-box"><div class="num"><?= $m['answered'] ?></div><div class="lbl">answered</div></div></div>
        <div class="col-sm-2 col-xs-4"><div class="stat-box"><div class="num"><?= $m['skips'] ?></div><div class="lbl">skips (<?= $m['fast_skips'] ?> fast)</div></div></div>
        <div class="col-sm-2 col-xs-4"><div class="stat-box"><div class="num"><?= $m['accuracy']===null?'—':round(100*$m['accuracy']).'%' ?></div><div class="lbl">accuracy</div></div></div>
        <div class="col-sm-2 col-xs-4"><div class="stat-box"><div class="num"><?= round($m['lifespan_days']) ?>d</div><div class="lbl"><?= $m['active_days'] ?> active days</div></div></div>
        <div class="col-sm-2 col-xs-4"><div class="stat-box"><div class="num"><?= $m['distinct_questions'] ?>/<?= $bank ?></div><div class="lbl">bank covered</div></div></div>
    </div>

    <div class="row">
        <!-- Gap fingerprint -->
        <div class="col-sm-6">
            <div class="panel-card">
                <h4>Timing fingerprint</h4>
                <div class="help">💡 How long, in seconds, between one action and the next (within a session). A <b>human</b>
                    spreads across the buckets and centres on a few seconds of reading; a <b>bot</b> piles up in one narrow
                    bucket. Median gap <b><?= $m['median_gap']===null?'—':round($m['median_gap'],1).'s' ?></b>,
                    regularity CV <b><?= $m['cv']===null?'n/a':round($m['cv'],2) ?></b> (low = robotic).</div>
                <canvas id="gapChart" height="150"></canvas>
            </div>
        </div>
        <!-- Hour of day -->
        <div class="col-sm-6">
            <div class="panel-card">
                <h4>Daily rhythm (by hour)</h4>
                <div class="help">💡 When in the day this account is active. A normal student shows a human rhythm with an
                    evening peak; round-the-clock or dead-of-night bursts are a red flag.</div>
                <canvas id="hourChart" height="150"></canvas>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Top days -->
        <div class="col-sm-6">
            <div class="panel-card">
                <h4>Busiest days</h4>
                <div class="help">💡 A scraper's activity collapses into one or two marathon days; a real student's spreads
                    out. <b>First/last</b> show how long each day's session ran.</div>
                <table class="table table-condensed">
                    <thead><tr><th>Date</th><th>Actions</th><th>First</th><th>Last</th></tr></thead>
                    <tbody>
                    <?php foreach ($topdays as $d): ?>
                        <tr><td><?= htmlspecialchars($d['d']) ?></td><td><?= $d['n'] ?></td>
                            <td><?= htmlspecialchars($d['f']) ?></td><td><?= htmlspecialchars($d['l']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <!-- Action breakdown -->
        <div class="col-sm-6">
            <div class="panel-card">
                <h4>What they actually did</h4>
                <div class="help">💡 The mix of actions. Lots of <b>answers</b>, <b>leaderboard</b> checks, <b>surveys</b> and
                    <b>badges</b> = a human playing the game. Almost all <b>skips</b> = harvesting.</div>
                <table class="table table-condensed" style="max-height:300px; display:block; overflow-y:auto;">
                    <thead><tr><th>Action</th><th>Count</th></tr></thead>
                    <tbody>
                    <?php foreach ($abreak as $at => $n): ?>
                        <tr><td><?= htmlspecialchars($anames[$at] ?? ('type '.$at)) ?> <span class="muted">(<?= $at ?>)</span></td><td><?= $n ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Raw sample -->
    <div class="panel-card">
        <h4>Recent activity — the raw cadence</h4>
        <div class="help">💡 The last <?= count($sample) ?> actions with the <b>gap</b> before each. This is the ground truth:
            if you see a long run of sub-second gaps, that's a script. Rows under <?= BP_FAST_SKIP_SECS ?>s are highlighted.</div>
        <table class="table table-condensed">
            <thead><tr><th>#</th><th>Time</th><th>Action</th><th>Gap before</th></tr></thead>
            <tbody>
            <?php foreach ($sample as $i => $e):
                $fast = $e['gap'] !== null && $e['gap'] < BP_FAST_SKIP_SECS; ?>
                <tr class="<?= $fast ? 'gap-fast':'' ?>">
                    <td class="muted"><?= $i+1 ?></td>
                    <td><?= date('Y-m-d H:i:s', $e['ts']) ?></td>
                    <td><?= htmlspecialchars($anames[$e['at']] ?? ('type '.$e['at'])) ?></td>
                    <td><?= $e['gap']===null ? '—' : $e['gap'].'s' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="panel-card">
        <h4>How to read this profile</h4>
        <div class="help" style="margin-bottom:0;">
            The <b>score</b> sums the behaviours in the breakdown above — it's a triage aid, not a verdict. To confirm a
            real scraper, look for: a wall of <b>fast-skips</b>, a timing fingerprint <b>spiked in one narrow bucket</b> with
            a <b>low CV</b>, activity <b>crammed into one day</b>, and accuracy that's <b>near-perfect</b> (has a key) or
            <b>near-random</b> (clicking blind). To clear someone, look for the opposite: <b>human-band accuracy</b>,
            <b>weeks</b> of return visits, <b>surveys/leaderboards/badges</b>, and a <b>broad</b> timing spread.
            Detection is silent — deciding what to do about a confirmed case is a separate, manual step.
        </div>
    </div>

    <?php endif; ?>
</div>

<?php if ($m): ?>
<script>
new Chart(document.getElementById('gapChart'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_keys($ghist)) ?>,
            datasets: [{ data: <?= json_encode(array_values($ghist)) ?>,
                backgroundColor: ['rgba(192,57,43,0.85)','rgba(230,126,34,0.8)','rgba(39,174,96,0.75)','rgba(52,152,219,0.7)','rgba(149,165,166,0.7)'] }] },
    options: { responsive:true, plugins:{legend:{display:false}}, scales:{ y:{ beginAtZero:true, title:{display:true,text:'# of gaps'} } } }
});
new Chart(document.getElementById('hourChart'), {
    type: 'bar',
    data: { labels: <?= json_encode(range(0,23)) ?>,
            datasets: [{ data: <?= json_encode(array_values($hours)) ?>, backgroundColor:'rgba(67,93,125,0.75)' }] },
    options: { responsive:true, plugins:{legend:{display:false}}, scales:{ x:{title:{display:true,text:'hour of day'}}, y:{ beginAtZero:true } } }
});
</script>
<?php endif; ?>
</body>
</html>
