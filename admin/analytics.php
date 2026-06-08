<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit();
}

include 'backend/database.php';

// ── Params ────────────────────────────────────────────────────────────────────
$days = isset($_GET['days']) ? max(7, min(180, intval($_GET['days']))) : 30;   // reach + event-study lookback
$win  = isset($_GET['win'])  ? max(1, min(14, intval($_GET['win'])))   : 3;     // event-study before/after window

// Action-type groupings reused throughout.
$ANSWER  = '(1,2)';        // correct or wrong = an answered question
$LBOARD  = '(23,24,25)';   // leaderboard views (all-time / weekly / monthly)

// ── 1. EVENT STUDY ────────────────────────────────────────────────────────────
// For each gamification event, measure the same user's answer volume in the
// `$win` days BEFORE vs AFTER the event. Within-user comparison ⇒ controls for
// the obvious selection bias (engaged users both play more and trigger events).
// Only events whose full after-window has elapsed are counted, so "after" isn't
// censored low for very recent events.
$event_defs = [
    'badge'   => ['label' => '🎖 Badge earned',      'types' => '(40)'],
    'levelup' => ['label' => '⬆ Level up',          'types' => '(9)'],
    'lboard'  => ['label' => '🏆 Leaderboard check', 'types' => $LBOARD],
];
$event_study = [];
foreach ($event_defs as $key => $def) {
    $sql = "
        SELECT COUNT(*) AS events,
               COALESCE(SUM(before_cnt),0) AS sum_before,
               COALESCE(SUM(after_cnt),0)  AS sum_after,
               COALESCE(SUM(CASE WHEN after_cnt > before_cnt THEN 1 ELSE 0 END),0) AS up_users
        FROM (
            SELECT e.userid, e.timestamp AS et,
                (SELECT COUNT(*) FROM log a
                   WHERE a.userid = e.userid AND a.action_type IN {$ANSWER}
                     AND a.timestamp >= e.timestamp - INTERVAL {$win} DAY
                     AND a.timestamp <  e.timestamp) AS before_cnt,
                (SELECT COUNT(*) FROM log a
                   WHERE a.userid = e.userid AND a.action_type IN {$ANSWER}
                     AND a.timestamp >  e.timestamp
                     AND a.timestamp <= e.timestamp + INTERVAL {$win} DAY) AS after_cnt
            FROM log e
            WHERE e.action_type IN {$def['types']}
              AND e.timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
              AND e.timestamp <= DATE_SUB(NOW(), INTERVAL {$win} DAY)
        ) x";
    $r = mysqli_fetch_assoc(mysqli_query($conn, $sql));
    $events = (int)$r['events'];
    $event_study[$key] = [
        'label'      => $def['label'],
        'events'     => $events,
        'avg_before' => $events ? round($r['sum_before'] / $events, 2) : 0,
        'avg_after'  => $events ? round($r['sum_after']  / $events, 2) : 0,
        'up_pct'     => $events ? round(100 * $r['up_users'] / $events) : 0,
    ];
}

// ── 2. RETENTION ──────────────────────────────────────────────────────────────
// No signup column on `users`, so first-seen is derived from the log. Retention
// is lifespan-based: a user is "retained at DN" if their activity spans ≥ N days
// from first-seen. Denominator for DN excludes users too new to have had N days.
$user_res = mysqli_query($conn, "
    SELECT f.userid,
           f.first_seen,
           DATEDIFF(f.last_seen, f.first_seen) AS lifespan,
           DATEDIFF(NOW(), f.first_seen)       AS age_days,
           CASE WHEN EXISTS (
                SELECT 1 FROM log g
                 WHERE g.userid = f.userid
                   AND g.action_type IN (40,23,24,25)
                   AND g.timestamp <= f.first_seen + INTERVAL 1 DAY
           ) THEN 1 ELSE 0 END AS early_gamified
    FROM (
        SELECT userid, MIN(timestamp) AS first_seen, MAX(timestamp) AS last_seen
        FROM log GROUP BY userid
    ) f");

$Ns = [1, 7, 30];
// buckets: 'all', 'gam' (engaged with gamification in first 24h), 'nogam'
$ret = [];
foreach (['all','gam','nogam'] as $g) foreach ($Ns as $n) $ret[$g][$n] = ['elig'=>0,'kept'=>0];
$cohorts = []; // week => ['count'=>, N=>['elig','kept']]

while ($u = mysqli_fetch_assoc($user_res)) {
    $age = (int)$u['age_days'];
    $life = (int)$u['lifespan'];
    $grp = $u['early_gamified'] ? 'gam' : 'nogam';
    $week = date('o-\WW', strtotime($u['first_seen']));
    if (!isset($cohorts[$week])) {
        $cohorts[$week] = ['count'=>0];
        foreach ($Ns as $n) $cohorts[$week][$n] = ['elig'=>0,'kept'=>0];
    }
    $cohorts[$week]['count']++;
    foreach ($Ns as $n) {
        if ($age >= $n) {
            $kept = $life >= $n ? 1 : 0;
            foreach (['all', $grp] as $b) { $ret[$b][$n]['elig']++; $ret[$b][$n]['kept'] += $kept; }
            $cohorts[$week][$n]['elig']++; $cohorts[$week][$n]['kept'] += $kept;
        }
    }
}
mysqli_free_result($user_res);
ksort($cohorts);

function ret_pct($cell) { return $cell['elig'] ? round(100 * $cell['kept'] / $cell['elig']) : null; }

// ── 3. LIFECYCLE FUNNEL (all-time, roughly monotonic acquisition stages) ───────
$f_started   = (int)mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT userid) n FROM log"))['n'];
$f_nickname  = (int)mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) n FROM users WHERE nickname IS NOT NULL AND nickname <> ''"))['n'];
$f_answered  = (int)mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT userid) n FROM log WHERE action_type IN {$ANSWER}"))['n'];
$f_returned  = (int)mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) n FROM (SELECT userid FROM log GROUP BY userid
       HAVING COUNT(DISTINCT DATE(timestamp)) >= 2) t"))['n'];
$f_retained7 = (int)mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) n FROM (SELECT userid FROM log GROUP BY userid
       HAVING DATEDIFF(MAX(timestamp), MIN(timestamp)) >= 7) t"))['n'];
$funnel = [
    ['🚀 Started (/start)',          $f_started],
    ['✏️ Set nickname',              $f_nickname],
    ['❓ Answered ≥1 question',       $f_answered],
    ['🔁 Returned (2+ active days)',  $f_returned],
    ['📅 Still active after 7d',      $f_retained7],
];

// ── 4. REACH (within period) ──────────────────────────────────────────────────
$active_period = (int)mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT userid) n FROM log
     WHERE timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"))['n'];
$reach_defs = [
    'Answered a question'  => $ANSWER,
    'Leveled up'           => '(9)',
    'Viewed badges room'   => '(21)',
    'Earned a badge'       => '(40)',
    'Checked leaderboard'  => $LBOARD,
];
$reach = [];
foreach ($reach_defs as $label => $types) {
    $n = (int)mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COUNT(DISTINCT userid) n FROM log
         WHERE action_type IN {$types}
           AND timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"))['n'];
    $reach[$label] = ['n' => $n, 'pct' => $active_period ? round(100 * $n / $active_period) : 0];
}

// ── 4b. DEAD BADGES (all-time earn counts) ────────────────────────────────────
$badge_res = mysqli_query($conn, "
    SELECT b.badge_id, b.badge_emoji, b.badge_title_he,
           COUNT(ub.user_id) AS earns
    FROM badges b
    LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id
    WHERE b.is_active = 1
    GROUP BY b.badge_id, b.badge_emoji, b.badge_title_he
    ORDER BY earns ASC, b.badge_id ASC");
$badges = [];
while ($b = mysqli_fetch_assoc($badge_res)) $badges[] = $b;
mysqli_free_result($badge_res);
$dead_badges = count(array_filter($badges, fn($b) => (int)$b['earns'] === 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gamification Analytics</title>
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
        .delta-up   { color:#27ae60; font-weight:bold; }
        .delta-down { color:#e74c3c; font-weight:bold; }
        .delta-flat { color:#888; }
        table.table th { background:#f9f9f9; }
        .badge-dead td { background:#fdecea; }
        .nav-link { margin-bottom:20px; display:inline-block; }
        .caveat { background:#fff8e1; border-left:3px solid #f0ad4e; padding:8px 12px;
            font-size:12px; color:#6b5900; border-radius:3px; margin-bottom:16px; }
    </style>
</head>
<body>
<div class="container" style="margin-top:30px;">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0; color:#435d7d;">📈 Gamification Analytics</h2>
        <div>
            <a href="stats.php" class="btn btn-default btn-sm">📊 Usage Stats</a>
            <a href="home.php" class="btn btn-default btn-sm">🏠 ראשי</a>
            <a href="index.php" class="btn btn-default btn-sm">ניהול שאלות</a>
            <a href="logout.php" class="btn btn-default btn-sm">התנתקות</a>
        </div>
    </div>

    <!-- Period picker -->
    <form method="GET" class="form-inline" style="margin-bottom:20px;">
        <label>Period: </label>
        <?php foreach ([7, 14, 30, 60, 90, 180] as $d): ?>
            <a href="?days=<?= $d ?>&win=<?= $win ?>" class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-default' ?>" style="margin-left:5px;"><?= $d ?>d</a>
        <?php endforeach; ?>
        <span style="margin-left:20px;"></span>
        <label>Event window: </label>
        <?php foreach ([1, 3, 7] as $w): ?>
            <a href="?days=<?= $days ?>&win=<?= $w ?>" class="btn btn-sm <?= $win === $w ? 'btn-primary' : 'btn-default' ?>" style="margin-left:5px;">±<?= $w ?>d</a>
        <?php endforeach; ?>
    </form>

    <div class="caveat">
        ⚠️ This is observational data. Cross-sectional gaps (e.g. "leaderboard users answer more") are mostly
        <em>selection</em>, not causation. The event-study below compares each user to themselves around an event —
        the most defensible within-user signal of impact.
    </div>

    <!-- ── 1. Event study ───────────────────────────────────────────── -->
    <div class="chart-wrap">
        <h4>1. Event impact — answers per user, ±<?= $win ?> days around each event (last <?= $days ?>d)</h4>
        <div class="row">
            <div class="col-sm-7"><canvas id="eventChart" height="120"></canvas></div>
            <div class="col-sm-5">
                <table class="table table-condensed" style="margin-top:10px;">
                    <thead><tr><th>Event</th><th>n</th><th>Before</th><th>After</th><th>Δ</th><th>↑ users</th></tr></thead>
                    <tbody>
                    <?php foreach ($event_study as $e):
                        $delta = $e['avg_after'] - $e['avg_before'];
                        $pct   = $e['avg_before'] > 0 ? round(100 * $delta / $e['avg_before']) : null;
                        $cls   = $delta > 0.05 ? 'delta-up' : ($delta < -0.05 ? 'delta-down' : 'delta-flat');
                    ?>
                        <tr>
                            <td><?= $e['label'] ?></td>
                            <td><?= $e['events'] ?></td>
                            <td><?= $e['avg_before'] ?></td>
                            <td><?= $e['avg_after'] ?></td>
                            <td class="<?= $cls ?>"><?= ($delta>=0?'+':'').round($delta,2) ?><?= $pct!==null ? " ({$pct}%)" : '' ?></td>
                            <td><?= $e['up_pct'] ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="muted">"↑ users" = share of events after which that user answered more than before. Events whose full
            after-window hasn't elapsed yet are excluded so "after" isn't undercounted.</div>
    </div>

    <!-- ── 2. Retention ─────────────────────────────────────────────── -->
    <div class="chart-wrap">
        <h4>2. Retention — by early gamification engagement</h4>
        <div class="row">
            <div class="col-sm-7"><canvas id="retChart" height="120"></canvas></div>
            <div class="col-sm-5">
                <table class="table table-condensed" style="margin-top:10px;">
                    <thead><tr><th>Group</th><th>D1</th><th>D7</th><th>D30</th></tr></thead>
                    <tbody>
                        <?php
                        $grp_labels = ['all'=>'All users','gam'=>'Gamified in first 24h','nogam'=>'Not gamified'];
                        foreach ($grp_labels as $g => $gl):
                            $eligN = $ret[$g][1]['elig']; ?>
                        <tr>
                            <td><?= $gl ?> <span class="muted">(n=<?= $eligN ?>)</span></td>
                            <?php foreach ($Ns as $n): $p = ret_pct($ret[$g][$n]); ?>
                                <td><?= $p===null ? '—' : $p.'%' ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="muted">DN = share of users active across ≥ N days from first contact. "Gamified in first 24h" =
            earned a badge or checked the leaderboard on day 0. Denominators exclude users too new to have reached day N.
            Divergence is suggestive, not causal — early gamification may be a marker of motivated users.</div>
    </div>

    <!-- Cohort breakdown -->
    <?php if ($cohorts): ?>
    <div class="panel-card">
        <h4>Retention by signup week (cohort)</h4>
        <table class="table table-bordered table-condensed">
            <thead><tr><th>Week (first seen)</th><th>Users</th><th>D1</th><th>D7</th><th>D30</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($cohorts, true) as $week => $c): ?>
                <tr>
                    <td><?= htmlspecialchars($week) ?></td>
                    <td><?= $c['count'] ?></td>
                    <?php foreach ($Ns as $n): $p = ret_pct($c[$n]); ?>
                        <td><?= $p===null ? '<span class="muted">young</span>' : $p.'% <span class="muted">('.$c[$n]['elig'].')</span>' ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="muted">Parenthetical = eligible users (old enough for that horizon). "young" = cohort hasn't aged N days.</div>
    </div>
    <?php endif; ?>

    <!-- ── 3. Funnel ────────────────────────────────────────────────── -->
    <div class="chart-wrap">
        <h4>3. Lifecycle funnel (all-time)</h4>
        <canvas id="funnelChart" height="90"></canvas>
        <div class="muted">Milestones ever reached. % is conversion from the previous stage.</div>
    </div>

    <!-- ── 4. Reach ─────────────────────────────────────────────────── -->
    <div class="row">
        <div class="col-sm-6">
            <div class="chart-wrap">
                <h4>4. Gamification reach — last <?= $days ?>d</h4>
                <canvas id="reachChart" height="160"></canvas>
                <div class="muted">% of the <?= $active_period ?> users active in the period who touched each element.</div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="panel-card">
                <h4>Badge earn counts <?php if ($dead_badges): ?><span class="delta-down">— <?= $dead_badges ?> never earned</span><?php endif; ?></h4>
                <table class="table table-condensed" style="max-height:340px; display:block; overflow-y:auto;">
                    <thead><tr><th></th><th>Badge</th><th>Earned by</th></tr></thead>
                    <tbody>
                    <?php foreach ($badges as $b): ?>
                        <tr class="<?= (int)$b['earns']===0 ? 'badge-dead' : '' ?>">
                            <td><?= htmlspecialchars($b['badge_emoji'] ?? '') ?></td>
                            <td dir="rtl"><?= htmlspecialchars($b['badge_title_he'] ?? '') ?></td>
                            <td><?= (int)$b['earns'] ?: '<strong>0 — dead</strong>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="muted">All-time. Dead badges (0 earns) are unreachable or too hard — design candidates to fix or retire.</div>
            </div>
        </div>
    </div>

</div>

<script>
// 1. Event study
new Chart(document.getElementById('eventChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($e)=>$e['label'], $event_study)) ?>,
        datasets: [
            { label: 'Before', data: <?= json_encode(array_map(fn($e)=>$e['avg_before'], $event_study)) ?>, backgroundColor: 'rgba(149,165,166,0.7)' },
            { label: 'After',  data: <?= json_encode(array_map(fn($e)=>$e['avg_after'],  $event_study)) ?>, backgroundColor: 'rgba(39,174,96,0.75)' },
        ]
    },
    options: { responsive:true, scales:{ y:{ beginAtZero:true, title:{display:true,text:'Avg answers / user'} } } }
});

// 2. Retention grouped bar
new Chart(document.getElementById('retChart'), {
    type: 'bar',
    data: {
        labels: ['D1','D7','D30'],
        datasets: [
            { label:'Gamified 24h', data: <?= json_encode(array_map(fn($n)=>ret_pct($ret['gam'][$n]),   $Ns)) ?>, backgroundColor:'rgba(39,174,96,0.75)' },
            { label:'Not gamified', data: <?= json_encode(array_map(fn($n)=>ret_pct($ret['nogam'][$n]), $Ns)) ?>, backgroundColor:'rgba(231,76,60,0.7)' },
            { label:'All',          data: <?= json_encode(array_map(fn($n)=>ret_pct($ret['all'][$n]),   $Ns)) ?>, backgroundColor:'rgba(67,93,125,0.6)' },
        ]
    },
    options: { responsive:true, scales:{ y:{ beginAtZero:true, max:100, title:{display:true,text:'% retained'} } } }
});

// 3. Funnel
const fLabels = <?= json_encode(array_map(fn($r)=>$r[0], $funnel)) ?>;
const fData   = <?= json_encode(array_map(fn($r)=>$r[1], $funnel)) ?>;
new Chart(document.getElementById('funnelChart'), {
    type: 'bar',
    data: { labels: fLabels, datasets: [{ data: fData, backgroundColor: 'rgba(67,93,125,0.75)' }] },
    options: {
        indexAxis: 'y', responsive:true, plugins:{ legend:{display:false},
            tooltip:{ callbacks:{ afterLabel: (ctx) => {
                const prev = ctx.dataIndex>0 ? fData[ctx.dataIndex-1] : null;
                return prev ? (Math.round(100*ctx.parsed.x/prev)+'% of previous') : '';
            }}}},
        scales:{ x:{ beginAtZero:true } }
    }
});

// 4. Reach
new Chart(document.getElementById('reachChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_keys($reach)) ?>,
        datasets: [{ data: <?= json_encode(array_map(fn($r)=>$r['pct'], $reach)) ?>,
                     backgroundColor: 'rgba(67,93,125,0.75)' }]
    },
    options: { indexAxis:'y', responsive:true, plugins:{ legend:{display:false} },
        scales:{ x:{ beginAtZero:true, max:100, title:{display:true,text:'% of active users'} } } }
});
</script>
</body>
</html>
