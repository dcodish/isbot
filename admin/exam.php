<?php
/**
 * admin/exam.php — Exam Mode usage dashboard (read-only, instructor-facing).
 *
 * How is the student-facing practice exam (exam_functions.php, FR-EXM-*) actually
 * being used? This page reads three sources and never writes:
 *   - `log` actions 36/37/38 (ExamStart / ExamCompleted / ExamStopped) — the ONLY
 *     complete record of the start→finish funnel, because a stopped or abandoned
 *     attempt has its `exam_attempts` row DELETED (see docs/features/exam-mode.md).
 *     Started/completed/stopped/abandoned counts therefore come from `log`.
 *   - `exam_attempts` (status completed/expired) — grades, pass rate, timing.
 *   - `exam_attempt_questions` — per-lecture difficulty (snapshot `max_lecture`).
 *
 * All panels are bound to the same lookback window (the period picker). Portable
 * SQL (no window functions / CTEs) to match the other admin pages; the repeat-use
 * and improvement aggregation is done in PHP.
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

function scalar($conn, $sql, $default = 0) {
    $res = mysqli_query($conn, $sql);
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_row($res);
        mysqli_free_result($res);
        return $row[0];
    }
    if ($res) mysqli_free_result($res);
    return $default;
}

// ── Params ────────────────────────────────────────────────────────────────────
$days = isset($_GET['days']) ? max(7, min(180, intval($_GET['days']))) : 30;

// Pass grade is a tunable settings knob (default 56) — mirror the bot.
$pass_row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT setting_value FROM settings WHERE setting_key = 'exam_pass_grade' LIMIT 1"));
$pass = $pass_row ? max(1, intval($pass_row['setting_value'])) : 56;

// ── 1. FUNNEL (per attempt, from the audit log) ───────────────────────────────
// One row per attempt STARTED in the window; then look up (any time) whether that
// attempt_id was later Completed (37) or Stopped (38). Attempts with neither were
// silently abandoned (student walked away; the row was auto-dropped on next start).
$funnel_sql = "
    SELECT s.attempt_id,
           MAX(c.done)    AS completed,
           MAX(st.stopped) AS stopped
    FROM (SELECT additional_value AS attempt_id
            FROM log
           WHERE action_type = 36
             AND timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
           GROUP BY additional_value) s
    LEFT JOIN (SELECT DISTINCT additional_value AS attempt_id, 1 AS done
                 FROM log WHERE action_type = 37) c  ON c.attempt_id  = s.attempt_id
    LEFT JOIN (SELECT DISTINCT additional_value AS attempt_id, 1 AS stopped
                 FROM log WHERE action_type = 38) st ON st.attempt_id = s.attempt_id
    GROUP BY s.attempt_id";
$started = $completed = $stopped = 0;
$fres = mysqli_query($conn, $funnel_sql);
if ($fres) {
    while ($r = mysqli_fetch_assoc($fres)) {
        $started++;
        if ($r['completed'])   $completed++;
        elseif ($r['stopped']) $stopped++;
    }
    mysqli_free_result($fres);
}
$abandoned = max(0, $started - $completed - $stopped);

// Distinct adopters + how big a slice of active students that is.
$adopters      = (int)scalar($conn,
    "SELECT COUNT(DISTINCT userid) FROM log
      WHERE action_type = 36 AND timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)");
$active_period = (int)scalar($conn,
    "SELECT COUNT(DISTINCT userid) FROM log
      WHERE timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)");
$adoption_pct  = $active_period ? round(100 * $adopters / $active_period) : 0;

$completion_pct = $started ? round(100 * $completed / $started) : 0;

// ── 2. GRADE STATS (graded attempts only) ─────────────────────────────────────
$g = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(*) AS n,
           AVG(grade) AS avg_grade,
           SUM(CASE WHEN grade >= {$pass} THEN 1 ELSE 0 END) AS passed,
           AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)) AS avg_secs
    FROM exam_attempts
    WHERE status IN ('completed','expired') AND grade IS NOT NULL
      AND finished_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"));
$graded_n  = (int)$g['n'];
$avg_grade = $graded_n ? round($g['avg_grade']) : null;
$pass_pct  = $graded_n ? round(100 * $g['passed'] / $graded_n) : null;
$avg_secs  = $graded_n ? (int)round($g['avg_secs']) : null;

// Grade distribution → ten 10-point bins; median computed in PHP.
$dist = array_fill(0, 10, 0);
$grades = [];
$dres = mysqli_query($conn, "
    SELECT grade FROM exam_attempts
    WHERE status IN ('completed','expired') AND grade IS NOT NULL
      AND finished_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)");
if ($dres) {
    while ($r = mysqli_fetch_assoc($dres)) {
        $gr = (int)$r['grade'];
        $grades[] = $gr;
        $bin = min(9, intdiv(max(0, $gr), 10));
        $dist[$bin]++;
    }
    mysqli_free_result($dres);
}
sort($grades);
$median_grade = null;
if ($grades) {
    $m = count($grades);
    $median_grade = ($m % 2) ? $grades[intdiv($m, 2)]
                             : (int)round(($grades[$m/2 - 1] + $grades[$m/2]) / 2);
}
$dist_labels = ['0–9','10–19','20–29','30–39','40–49','50–59','60–69','70–79','80–89','90–100'];

// ── 3. PER-LECTURE DIFFICULTY (weakest first) ─────────────────────────────────
$lecture_rows = [];
$lres = mysqli_query($conn, "
    SELECT eaq.max_lecture AS lec,
           COUNT(*) AS total,
           SUM(CASE WHEN eaq.is_correct = 1 THEN 1 ELSE 0 END) AS correct
    FROM exam_attempt_questions eaq
    JOIN exam_attempts a ON a.id = eaq.attempt_id
    WHERE a.status IN ('completed','expired')
      AND a.finished_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY eaq.max_lecture
    ORDER BY (SUM(CASE WHEN eaq.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*)) ASC");
if ($lres) {
    while ($r = mysqli_fetch_assoc($lres)) {
        $t = max(1, (int)$r['total']); $c = (int)$r['correct'];
        $lecture_rows[] = [
            'label' => ($r['lec'] === null) ? 'כללי' : ('הרצאה ' . (int)$r['lec']),
            'total' => (int)$r['total'], 'correct' => $c,
            'pct'   => (int)round(100 * $c / $t),
        ];
    }
    mysqli_free_result($lres);
}

// ── 4. ADOPTION OVER TIME (daily) ─────────────────────────────────────────────
$day_labels = $day_started = $day_completed = $day_stopped = [];
$ares = mysqli_query($conn, "
    SELECT DATE(timestamp) AS day,
           COUNT(CASE WHEN action_type = 36 THEN 1 END) AS started,
           COUNT(CASE WHEN action_type = 37 THEN 1 END) AS completed,
           COUNT(CASE WHEN action_type = 38 THEN 1 END) AS stopped
    FROM log
    WHERE action_type IN (36,37,38)
      AND timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    GROUP BY day ORDER BY day ASC");
if ($ares) {
    while ($r = mysqli_fetch_assoc($ares)) {
        $day_labels[]    = $r['day'];
        $day_started[]   = (int)$r['started'];
        $day_completed[] = (int)$r['completed'];
        $day_stopped[]   = (int)$r['stopped'];
    }
    mysqli_free_result($ares);
}

// ── 5. REPEAT USE + IMPROVEMENT (PHP-aggregated) ──────────────────────────────
// Per student: how many graded attempts, and did their latest beat their first?
$per_user = []; // user_id => [grades in chronological order]
$rres = mysqli_query($conn, "
    SELECT user_id, grade FROM exam_attempts
    WHERE status IN ('completed','expired') AND grade IS NOT NULL
      AND finished_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    ORDER BY user_id ASC, finished_at ASC, id ASC");
if ($rres) {
    while ($r = mysqli_fetch_assoc($rres)) $per_user[$r['user_id']][] = (int)$r['grade'];
    mysqli_free_result($rres);
}
$attempt_hist = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0]; // key 5 = "5+"
$repeat_users = 0; $sum_first = 0; $sum_latest = 0; $improved = 0;
foreach ($per_user as $grades_u) {
    $cnt = count($grades_u);
    $attempt_hist[min(5, $cnt)]++;
    if ($cnt >= 2) {
        $first = $grades_u[0]; $latest = $grades_u[$cnt - 1];
        $repeat_users++; $sum_first += $first; $sum_latest += $latest;
        if ($latest > $first) $improved++;
    }
}
$graded_students = count($per_user);
$avg_first  = $repeat_users ? round($sum_first  / $repeat_users) : null;
$avg_latest = $repeat_users ? round($sum_latest / $repeat_users) : null;
$improved_pct = $repeat_users ? round(100 * $improved / $repeat_users) : null;
$hist_labels = ['1','2','3','4','5+'];
$hist_data   = [$attempt_hist[1],$attempt_hist[2],$attempt_hist[3],$attempt_hist[4],$attempt_hist[5]];

$fmt_time = function ($secs) {
    if ($secs === null) return '—';
    return sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
};

$any_data = ($started > 0 || $graded_n > 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exam Mode Usage</title>
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
        .stat-box.good .num { color:#27ae60; }
        .stat-box.warn .num { color:#e67e22; }
        .stat-box.bad  .num { color:#e74c3c; }
        table.table th { background:#f9f9f9; }
        .lec-weak td { background:#fdecea; }
        .help { background:#eef5fb; border-left:3px solid #5b9bd5; padding:10px 14px;
            font-size:13px; color:#2c3e50; border-radius:3px; margin-bottom:14px; line-height:1.5; }
        .help b { color:#2c5f8a; }
        .takeaway { background:#eafaf1; border-left:3px solid #27ae60; padding:10px 14px;
            font-size:14px; color:#1e5631; border-radius:3px; margin-top:14px; }
        .takeaway.flat { background:#f4f6f7; border-left-color:#95a5a6; color:#555; }
        .caveat { background:#fff8e1; border-left:3px solid #f0ad4e; padding:8px 12px;
            font-size:12px; color:#6b5900; border-radius:3px; margin-bottom:16px; }
        .intro { background:#fff; border-radius:4px; padding:18px 22px; margin-bottom:24px;
            box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .intro h4 { margin-top:0; color:#435d7d; }
        .intro ol { margin:0; padding-left:20px; }
        .intro li { margin-bottom:4px; font-size:13px; color:#444; }
        .bar { height:14px; border-radius:3px; background:#e74c3c; display:inline-block; vertical-align:middle; }
    </style>
</head>
<body>
<div class="container" style="margin-top:30px;">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0; color:#435d7d;">📝 Exam Mode Usage</h2>
        <div>
            <a href="stats.php" class="btn btn-default btn-sm">📊 Usage Stats</a>
            <a href="analytics.php" class="btn btn-default btn-sm">📈 Gamification Analytics</a>
            <a href="abuse.php" class="btn btn-default btn-sm">🕵️ Abuse Detection</a>
            <a href="home.php" class="btn btn-default btn-sm">🏠 ראשי</a>
            <a href="logout.php" class="btn btn-default btn-sm">התנתקות</a>
        </div>
    </div>

    <!-- Period picker -->
    <form method="GET" class="form-inline" style="margin-bottom:20px;">
        <label>Period: </label>
        <?php foreach ([7, 14, 30, 60, 90, 180] as $d): ?>
            <a href="?days=<?= $d ?>" class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-default' ?>" style="margin-left:5px;"><?= $d ?>d</a>
        <?php endforeach; ?>
    </form>

    <div class="intro">
        <h4>What is this page?</h4>
        <p style="font-size:13px; color:#444; margin-bottom:10px;">
            Students can take a short, timed <b>practice exam</b> inside the bot (📝 מבחן תרגול). This page shows
            <b>how much it's used and how students do on it</b>, over the last <b><?= $days ?> days</b>:
        </p>
        <ol>
            <li><b>Adoption</b> — how many students try it, and what share of active students that is.</li>
            <li><b>Funnel</b> — of the exams started, how many are finished vs. stopped vs. abandoned.</li>
            <li><b>Grades</b> — the spread of scores and the pass rate.</li>
            <li><b>By lecture</b> — which lectures students get wrong most on the exam (revision targets).</li>
            <li><b>Retakes</b> — how often students come back, and whether their scores improve.</li>
        </ol>
    </div>

    <div class="caveat">
        ⚠️ <b>Two record-keeping notes.</b> A <em>stopped</em> or <em>abandoned</em> exam keeps no graded result
        (by design), so it appears in the <b>Funnel</b> (from the activity log) but not in <b>Grades</b> or
        <b>By lecture</b>, which only count finished/expired attempts. Also, exam mode is new — a wider period may be
        needed before the trends mean much.
    </div>

    <?php if (!$any_data): ?>
        <div class="panel-card">
            <h4>No exam activity in this window</h4>
            <p class="text-muted">No exams were started in the last <?= $days ?> days. Try a wider period above,
                or check that exam mode is open to students (<code>settings.exam_enabled_for_all</code>).</p>
        </div>
    <?php else: ?>

    <!-- Summary cards -->
    <div class="row">
        <div class="col-sm-2"><div class="stat-box"><div class="num"><?= $started ?></div><div class="lbl">Exams started</div></div></div>
        <div class="col-sm-2"><div class="stat-box"><div class="num"><?= $adopters ?></div><div class="lbl">Students (<?= $adoption_pct ?>% of active)</div></div></div>
        <div class="col-sm-2"><div class="stat-box <?= $completion_pct >= 60 ? 'good' : ($completion_pct < 40 ? 'bad' : 'warn') ?>"><div class="num"><?= $completion_pct ?>%</div><div class="lbl">Completed</div></div></div>
        <div class="col-sm-2"><div class="stat-box <?= $pass_pct === null ? '' : ($pass_pct >= 50 ? 'good' : 'bad') ?>"><div class="num"><?= $pass_pct === null ? '—' : $pass_pct.'%' ?></div><div class="lbl">Pass rate (≥<?= $pass ?>)</div></div></div>
        <div class="col-sm-2"><div class="stat-box"><div class="num"><?= $avg_grade === null ? '—' : $avg_grade ?></div><div class="lbl">Avg grade</div></div></div>
        <div class="col-sm-2"><div class="stat-box"><div class="num"><?= $fmt_time($avg_secs) ?></div><div class="lbl">Avg time (min:sec)</div></div></div>
    </div>

    <!-- 1. Adoption over time -->
    <div class="chart-wrap">
        <h4>1. Adoption over time</h4>
        <div class="help">💡 Each day: how many exams were <b>started</b>, <b>finished</b> (graded, incl. timer
            expiry), and <b>stopped</b> early. A growing "started" line means the feature is catching on.</div>
        <canvas id="adoptChart" height="90"></canvas>
    </div>

    <!-- 2. Funnel -->
    <div class="row">
        <div class="col-sm-7">
            <div class="chart-wrap">
                <h4>2. What happens to a started exam?</h4>
                <div class="help">💡 Of the <b><?= $started ?></b> exams started, how many students saw it through.
                    <b>Finished</b> = answered all questions or ran out of time (graded). <b>Stopped</b> = tapped
                    "הפסק מבחן". <b>Abandoned</b> = walked away without finishing or stopping.</div>
                <canvas id="funnelChart" height="150"></canvas>
                <?php
                $drop = $started ? round(100 * ($stopped + $abandoned) / $started) : 0;
                ?>
                <div class="takeaway <?= $drop >= 40 ? '' : 'flat' ?>">
                    📊 <b>In plain terms:</b> <b><?= $completion_pct ?>%</b> of started exams get finished.
                    <?php if ($stopped + $abandoned > 0): ?>
                        <?= $stopped ?> were stopped early and <?= $abandoned ?> were abandoned
                        (<?= $drop ?>% dropped out) — <?= $drop >= 40 ? 'worth a look at whether the exam is too long or too hard.' : 'a healthy completion level.' ?>
                    <?php else: ?>
                        every started exam was finished.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-sm-5">
            <div class="chart-wrap">
                <h4>3. Grade distribution</h4>
                <div class="help">💡 The spread of the <b><?= $graded_n ?></b> graded exams. The red line is the
                    pass mark (<?= $pass ?>). A pile-up on the left means the exam is hard for most students.</div>
                <canvas id="gradeChart" height="170"></canvas>
                <div class="muted" style="margin-top:8px;">
                    Avg <b><?= $avg_grade === null ? '—' : $avg_grade ?></b> ·
                    Median <b><?= $median_grade === null ? '—' : $median_grade ?></b> ·
                    Pass <b><?= $pass_pct === null ? '—' : $pass_pct.'%' ?></b>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. Per-lecture difficulty -->
    <div class="panel-card">
        <h4>4. Hardest lectures on the exam <span class="muted">— weakest first</span></h4>
        <div class="help">💡 Across every graded exam, the share of questions students got <b>right</b>, grouped by
            the lecture each question belongs to. The lectures at the top (red) are where students struggle most on
            the exam — the best candidates for review in class. "כללי" = questions not tied to a specific lecture.</div>
        <?php if ($lecture_rows): ?>
        <table class="table table-condensed">
            <thead><tr><th>Lecture</th><th style="width:45%">% correct</th><th>Correct / total</th></tr></thead>
            <tbody>
            <?php foreach ($lecture_rows as $lr): ?>
                <tr class="<?= $lr['pct'] < 50 ? 'lec-weak' : '' ?>">
                    <td dir="rtl"><?= htmlspecialchars($lr['label']) ?></td>
                    <td>
                        <span class="bar" style="width:<?= max(2, $lr['pct']) ?>%; background:<?= $lr['pct'] < 50 ? '#e74c3c' : ($lr['pct'] < 70 ? '#e67e22' : '#27ae60') ?>;"></span>
                        <strong><?= $lr['pct'] ?>%</strong>
                    </td>
                    <td><?= $lr['correct'] ?> / <?= $lr['total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="text-muted">No graded exam questions in this window yet.</p>
        <?php endif; ?>
    </div>

    <!-- 5. Retakes & improvement -->
    <div class="row">
        <div class="col-sm-6">
            <div class="chart-wrap">
                <h4>5. Retakes — how many exams per student?</h4>
                <div class="help">💡 Of the <b><?= $graded_students ?></b> students with a graded exam, how many
                    took it once, twice, and so on. Retakes at all are a sign students find it useful.</div>
                <canvas id="retakeChart" height="150"></canvas>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="chart-wrap">
                <h4>6. Do scores improve on retakes?</h4>
                <div class="help">💡 For the <b><?= $repeat_users ?></b> students who took the exam <b>2+ times</b>,
                    their <b>first</b> grade vs. their <b>latest</b> grade (averaged). Higher latest = the practice
                    is paying off.</div>
                <?php if ($repeat_users): ?>
                    <canvas id="improveChart" height="150"></canvas>
                    <div class="takeaway <?= ($avg_latest > $avg_first) ? '' : 'flat' ?>">
                        📊 <b>In plain terms:</b> retakers averaged <b><?= $avg_first ?></b> on their first exam and
                        <b><?= $avg_latest ?></b> on their latest
                        (<?= ($avg_latest - $avg_first) >= 0 ? '+' : '' ?><?= $avg_latest - $avg_first ?>).
                        <b><?= $improved_pct ?>%</b> of them improved.
                    </div>
                <?php else: ?>
                    <p class="text-muted">No student has taken the exam twice yet in this window.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; // any_data ?>

</div>

<?php if ($any_data): ?>
<script>
// 1. Adoption over time
new Chart(document.getElementById('adoptChart'), {
    data: {
        labels: <?= json_encode($day_labels) ?>,
        datasets: [
            { type:'bar',  label:'Started',   data:<?= json_encode($day_started) ?>,   backgroundColor:'rgba(67,93,125,0.75)' },
            { type:'line', label:'Finished',  data:<?= json_encode($day_completed) ?>, borderColor:'#27ae60', backgroundColor:'rgba(39,174,96,0.1)', tension:0.3, pointRadius:3 },
            { type:'line', label:'Stopped',   data:<?= json_encode($day_stopped) ?>,   borderColor:'#e74c3c', backgroundColor:'rgba(231,76,60,0.1)', tension:0.3, pointRadius:3 },
        ]
    },
    options: { responsive:true, interaction:{mode:'index'},
        scales:{ y:{ beginAtZero:true, title:{display:true,text:'Exams'} } } }
});

// 2. Funnel
new Chart(document.getElementById('funnelChart'), {
    type: 'bar',
    data: {
        labels: ['Finished','Stopped','Abandoned'],
        datasets: [{ data: [<?= $completed ?>, <?= $stopped ?>, <?= $abandoned ?>],
            backgroundColor: ['rgba(39,174,96,0.8)','rgba(230,126,34,0.8)','rgba(231,76,60,0.8)'] }]
    },
    options: { indexAxis:'y', responsive:true, plugins:{ legend:{display:false} },
        scales:{ x:{ beginAtZero:true, title:{display:true,text:'Attempts'} } } }
});

// 3. Grade distribution (with pass-mark line)
new Chart(document.getElementById('gradeChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($dist_labels) ?>,
        datasets: [{ data: <?= json_encode(array_values($dist)) ?>,
            backgroundColor: <?= json_encode(array_map(function($i) use ($pass) {
                // colour bins below the pass mark red-ish, at/above green-ish
                return (($i * 10 + 9) < $pass) ? 'rgba(231,76,60,0.7)' : 'rgba(39,174,96,0.7)';
            }, range(0, 9))) ?> }]
    },
    options: { responsive:true, plugins:{ legend:{display:false} },
        scales:{ y:{ beginAtZero:true, title:{display:true,text:'Exams'} },
                 x:{ title:{display:true,text:'Grade'} } } }
});

// 5. Retakes histogram
new Chart(document.getElementById('retakeChart'), {
    type: 'bar',
    data: { labels: <?= json_encode($hist_labels) ?>,
        datasets: [{ data: <?= json_encode($hist_data) ?>, backgroundColor:'rgba(67,93,125,0.75)' }] },
    options: { responsive:true, plugins:{ legend:{display:false} },
        scales:{ y:{ beginAtZero:true, title:{display:true,text:'Students'} },
                 x:{ title:{display:true,text:'Exams taken'} } } }
});

<?php if ($repeat_users): ?>
// 6. First vs latest grade (retakers)
new Chart(document.getElementById('improveChart'), {
    type: 'bar',
    data: { labels: ['First exam','Latest exam'],
        datasets: [{ data: [<?= $avg_first ?>, <?= $avg_latest ?>],
            backgroundColor: ['rgba(149,165,166,0.8)','rgba(39,174,96,0.8)'] }] },
    options: { responsive:true, plugins:{ legend:{display:false} },
        scales:{ y:{ beginAtZero:true, max:100, title:{display:true,text:'Avg grade'} } } }
});
<?php endif; ?>
</script>
<?php endif; ?>
</body>
</html>
