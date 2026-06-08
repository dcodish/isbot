<?php
/**
 * admin/abuse.php — Offline scraping / farming detection (read-only, silent).
 *
 * Ranks every active account by a 0–100 suspicion score and shows the evidence.
 * The scoring model (which behaviours count, and how) lives in lib/behavior.php
 * and is shared with the per-account drill-down (user.php), so the list and the
 * profile always agree. It takes NO action: it never blocks, throttles, or
 * messages anyone. (Spec: docs/features/abuse-detection.md; ADR-010.)
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

$days = isset($_GET['days']) ? max(1, min(180, intval($_GET['days']))) : 7;   // lookback window

// Session gap (seconds) — the same `settings` knob the bot uses; longer gaps are
// real breaks, excluded from pace/regularity.
$gap_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT setting_value FROM settings WHERE setting_key = 'session_gap_minutes' LIMIT 1"));
$SESSION_GAP = ($gap_row ? max(1, intval($gap_row['setting_value'])) : 30) * 60;

// Nicknames for display.
$nick = [];
$nr = mysqli_query($conn, "SELECT id, nickname FROM users");
while ($n = mysqli_fetch_assoc($nr)) $nick[(string)$n['id']] = $n['nickname'];
mysqli_free_result($nr);

// One time-ordered pass over the window; build each user's event stream, then
// score via the shared library. (Benefits from idx_log_user_time.)
$stream = mysqli_query($conn, "
    SELECT userid, action_type AS at, additional_value AS av, UNIX_TIMESTAMP(timestamp) AS ts
    FROM log
    WHERE timestamp >= NOW() - INTERVAL {$days} DAY
    ORDER BY userid, timestamp");

$by_user = [];
while ($row = mysqli_fetch_assoc($stream)) $by_user[(string)$row['userid']][] = $row;
mysqli_free_result($stream);

$rows = [];
foreach ($by_user as $uid => $events) {
    $m = bp_metrics_from_stream($events, $SESSION_GAP);
    $s = bp_score($m);
    if ($s['score'] === null) continue;                 // too little activity to judge
    $rows[] = [
        'uid'=>$uid, 'nick'=>$nick[$uid] ?? '—',
        'events'=>$m['events'], 'answered'=>$m['answered'],
        'skips'=>$m['skips'], 'skip_share'=>$m['skip_share'],
        'fast_skips'=>$m['fast_skips'], 'fast_share'=>$m['fast_skip_share'],
        'median'=>$m['median_gap'], 'cv'=>$m['cv'], 'peak_hour'=>$m['peak_hour'],
        'accuracy'=>$m['accuracy'], 'score'=>$s['score'], 'why'=>bp_why($s['contributions']),
    ];
}
usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);

$strong = array_filter($rows, fn($r) => $r['score'] >= BP_BAND_RED);
$watch  = array_filter($rows, fn($r) => $r['score'] >= BP_BAND_AMBER && $r['score'] < BP_BAND_RED);
$top15  = array_slice($rows, 0, 15);

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
            <b>scraping the question bank</b>. This page reads the activity log and scores each account on how
            <b>script-like vs human-like</b> it behaves. It is <b>offline and silent</b> — it never blocks or messages
            anyone; it just ranks who's worth a closer look. <b>Click any account</b> to open its full profile.
        </p>
        <p style="font-size:13px; color:#444; margin-bottom:6px;">The score adds up <b>incriminating</b> behaviours and subtracts <b>reassuring</b> ones:</p>
        <ul>
            <li>🔴 <b>Incriminating</b> — fast-skip spam (harvesting), too-even timing (a script), inhuman pace, marathon bursts, suspiciously perfect/random accuracy.</li>
            <li>🟢 <b>Reassuring</b> — human-band accuracy (real learning), weeks of regular use, broad engagement (surveys, leaderboards, badges).</li>
        </ul>
        <p class="muted" style="margin-top:6px;">So a <em>fast crammer</em> who answers thoughtfully, returns for weeks, and barely skips lands low — speed alone isn't enough to flag.</p>
    </div>

    <div class="caveat">
        ⚠️ <b>These are signals, not proof.</b> Treat a high score as <em>"open the profile and check"</em>, not <em>"guilty"</em>.
        Accounts with fewer than <?= BP_MIN_EVENTS ?> actions in the window are skipped — too little to judge.
    </div>

    <div class="row">
        <div class="col-sm-3"><div class="stat-box"><div class="num"><?= count($rows) ?></div><div class="lbl">Accounts examined</div></div></div>
        <div class="col-sm-3"><div class="stat-box"><div class="num" style="color:#c0392b;"><?= count($strong) ?></div><div class="lbl">Look automated (≥ <?= BP_BAND_RED ?>)</div></div></div>
        <div class="col-sm-3"><div class="stat-box"><div class="num" style="color:#b9770e;"><?= count($watch) ?></div><div class="lbl">Worth a look (<?= BP_BAND_AMBER ?>–<?= BP_BAND_RED-1 ?>)</div></div></div>
        <div class="col-sm-3"><div class="stat-box"><div class="num"><?= $days ?>d</div><div class="lbl">Window</div></div></div>
    </div>

    <?php if (!$rows): ?>
        <div class="takeaway flat">📊 No account had at least <?= BP_MIN_EVENTS ?> actions in the last <?= $days ?> day(s) — nothing to assess yet. Try a wider window.</div>
    <?php elseif ($strong): $t = $rows[0]; ?>
        <div class="takeaway warn">📊 <b>In plain terms:</b> <b><?= count($strong) ?></b> account<?= count($strong)===1?'':'s' ?> look automated.
            The top one (<b><?= htmlspecialchars($t['nick']) ?></b>) — <?= $t['why'] ? htmlspecialchars(implode('; ', $t['why'])) : 'high on the timing signals' ?>.
            Click it to investigate.</div>
    <?php else: ?>
        <div class="takeaway">📊 <b>In plain terms:</b> nobody crossed the "looks automated" line
            <?= $watch ? ' — but '.count($watch).' account(s) are worth a glance.' : '. Activity looks human.' ?></div>
    <?php endif; ?>

    <?php if ($top15 && $top15[0]['score'] > 0): ?>
    <div class="chart-wrap">
        <h4>Most suspicious accounts</h4>
        <div class="help">💡 Each bar is one account's <b>suspicion score (0–100)</b>. The score only <em>sorts</em> the list;
            open a profile to see the full breakdown. Red bars (≥ <?= BP_BAND_RED ?>) first.</div>
        <canvas id="suspChart" height="<?= max(90, count($top15)*22) ?>"></canvas>
    </div>
    <?php endif; ?>

    <div class="panel-card">
        <h4>The numbers behind each account</h4>
        <div class="help">💡 <b>Columns.</b> <b>Actions</b> = everything they did · <b>Skips</b> = questions skipped ·
            <b>Fast-skips</b> = skips under <?= BP_FAST_SKIP_SECS ?>s (the harvest tell) · <b>Median gap</b> = typical seconds
            between actions (small = fast) · <b>CV</b> = how even the pacing is (low = robotic) · <b>Peak/hr</b> = busiest hour ·
            <b>Acc.</b> = % correct · <b>Score</b> = all signals combined. Red cells push the score up. <b>Click a row to drill in.</b></div>
        <table class="table table-condensed table-hover">
            <thead><tr>
                <th>Account</th><th>Actions</th><th>Skips</th><th>Fast-skips</th>
                <th>Median gap</th><th>CV</th><th>Peak/hr</th><th>Acc.</th><th>Score</th><th>Why</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="text-center muted" style="padding:20px;">No accounts with enough activity in this window.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $rowcls = $r['score'] >= BP_BAND_RED ? 'row-red' : ($r['score'] >= BP_BAND_AMBER ? 'row-amber' : '');
                $pillbg = $r['score'] >= BP_BAND_RED ? '#c0392b' : ($r['score'] >= BP_BAND_AMBER ? '#e0a800' : '#95a5a6');
            ?>
                <tr class="<?= $rowcls ?>" style="cursor:pointer;" onclick="location.href='user.php?id=<?= urlencode($r['uid']) ?>'">
                    <td><b><?= htmlspecialchars($r['nick']) ?></b> <span class="muted">↗</span><br><span class="muted"><?= htmlspecialchars($r['uid']) ?></span></td>
                    <td><?= $r['events'] ?><br><span class="muted"><?= $r['answered'] ?> answered</span></td>
                    <td><?= $r['skips'] ?> <span class="muted">(<?= round(100*$r['skip_share']) ?>%)</span></td>
                    <td class="<?= cell($r['fast_share'], 0.25, 0.5) ?>"><?= $r['fast_skips'] ?> <span class="muted">(<?= round(100*$r['fast_share']) ?>%)</span></td>
                    <td class="<?= cell($r['median'], 8, 4, true) ?>"><?= $r['median']===null ? '—' : round($r['median'],1).'s' ?></td>
                    <td class="<?= $r['cv']===null ? '' : cell($r['cv'], 0.6, 0.35, true) ?>"><?= $r['cv']===null ? '<span class="muted">n/a</span>' : round($r['cv'],2) ?></td>
                    <td class="<?= cell($r['peak_hour'], 90, 150) ?>"><?= $r['peak_hour'] ?></td>
                    <td><?= $r['accuracy']===null ? '—' : round(100*$r['accuracy']).'%' ?></td>
                    <td><span class="score-pill" style="background:<?= $pillbg ?>;"><?= $r['score'] ?></span></td>
                    <td class="why"><?= $r['why'] ? htmlspecialchars(implode('; ', $r['why'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="muted">CV needs ≥ <?= BP_MIN_GAPS_CV ?> in-session gaps to mean anything ("n/a" = too few). Gaps longer than the
            session break (<?= round($SESSION_GAP/60) ?> min) count as stepping away, not thinking.</div>
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
        scales: { x: { beginAtZero:true, max:100, title:{display:true, text:'Suspicion score (0–100)'} } },
        onClick: (e, els) => { if (els.length) location.href = 'user.php?id=' + <?= json_encode(array_map(fn($r)=>$r['uid'], $top15)) ?>[els[0].index]; }
    }
});
</script>
<?php endif; ?>
</body>
</html>
