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

$days = isset($_GET['days']) ? max(7, min(90, intval($_GET['days']))) : 30;

// ── Summary counts ────────────────────────────────────────────────────────────

$total_users = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS n FROM users"))['n'];

$active_7 = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT userid) AS n FROM log
     WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)"))['n'];

$active_30 = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT userid) AS n FROM log
     WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)"))['n'];

$answers_7 = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS n FROM log
     WHERE action_type IN (1,2) AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)"))['n'];

$correct_7 = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(*) AS n FROM log
     WHERE action_type = 1 AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)"))['n'];

$leaderboard_30 = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT userid) AS n FROM log
     WHERE action_type IN (23,24,25) AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)"))['n'];

$badges_30 = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT userid) AS n FROM log
     WHERE action_type = 21 AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)"))['n'];

// ── Daily activity (answers + distinct active users) ─────────────────────────

$daily_res = mysqli_query($conn,
    "SELECT DATE(timestamp) AS day,
            COUNT(CASE WHEN action_type IN (1,2) THEN 1 END) AS answers,
            COUNT(CASE WHEN action_type = 1    THEN 1 END) AS correct,
            COUNT(DISTINCT userid) AS active_users
     FROM log
     WHERE timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
     GROUP BY day
     ORDER BY day ASC");

$daily_labels = [];
$daily_answers = [];
$daily_correct = [];
$daily_users   = [];
while ($row = mysqli_fetch_assoc($daily_res)) {
    $daily_labels[]  = $row['day'];
    $daily_answers[] = (int)$row['answers'];
    $daily_correct[] = (int)$row['correct'];
    $daily_users[]   = (int)$row['active_users'];
}
mysqli_free_result($daily_res);

// ── Per-user engagement table ─────────────────────────────────────────────────

$user_res = mysqli_query($conn,
    "SELECT u.id, u.nickname,
            MAX(l.timestamp) AS last_seen,
            COUNT(CASE WHEN l.action_type = 1  THEN 1 END) AS correct,
            COUNT(CASE WHEN l.action_type = 2  THEN 1 END) AS wrong,
            COUNT(CASE WHEN l.action_type IN (23,24,25) THEN 1 END) AS leaderboard_views,
            COUNT(CASE WHEN l.action_type = 21 THEN 1 END) AS badge_views,
            COUNT(CASE WHEN l.action_type = 40 THEN 1 END) AS badges_earned,
            COUNT(CASE WHEN l.action_type = 9  THEN 1 END) AS level_ups
     FROM log l
     JOIN users u ON l.userid = u.id
     WHERE l.timestamp >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
     GROUP BY u.id, u.nickname
     ORDER BY last_seen DESC");

$user_rows = [];
while ($row = mysqli_fetch_assoc($user_res)) {
    $user_rows[] = $row;
}
mysqli_free_result($user_res);

// ── Action type breakdown (for reference) ─────────────────────────────────────

$action_labels = [
    1  => 'Correct answer',
    2  => 'Wrong answer',
    3  => 'Skip',
    4  => 'Report bad',
    6  => '/start',
    8  => '/level',
    9  => 'Level up',
    10 => 'Level down',
    12 => 'Survey answer',
    13 => 'Next question',
    14 => 'Nickname set',
    15 => 'Survey shown',
    19 => 'Menu command',
    20 => 'Start answering',
    21 => 'Badges view',
    22 => 'Leaderboard (root)',
    23 => 'Leaderboard (all-time)',
    24 => 'Leaderboard (weekly)',
    25 => 'Leaderboard (monthly)',
    26 => 'Back to menu',
    30 => 'Nickname change req',
    31 => 'Clear stats req',
    32 => 'Clear stats confirm',
    33 => 'Clear stats cancel',
    36 => 'Exam started',
    37 => 'Exam completed',
    38 => 'Exam stopped',
    40 => 'Badge earned',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usage Stats</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Varela+Round">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        body { padding-bottom: 40px; }
        .stat-box {
            background: #fff;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
            text-align: center;
        }
        .stat-box .num { font-size: 36px; font-weight: bold; color: #435d7d; }
        .stat-box .lbl { color: #888; font-size: 13px; margin-top: 4px; }
        .stat-box.highlight .num { color: #27ae60; }
        .stat-box.warn .num { color: #e67e22; }
        .chart-wrap {
            background: #fff;
            border-radius: 4px;
            padding: 20px 25px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        .chart-wrap h4 { margin-top: 0; color: #435d7d; }
        table.table th { background: #f9f9f9; }
        .tag { display:inline-block; padding:2px 7px; border-radius:10px; font-size:11px; }
        .tag-yes  { background:#d4edda; color:#155724; }
        .tag-no   { background:#f8d7da; color:#721c24; }
        .tag-some { background:#fff3cd; color:#856404; }
        .nav-link { margin-bottom: 20px; display:inline-block; }
    </style>
</head>
<body>
<div class="container" style="margin-top:30px;">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0; color:#435d7d;">📊 Usage Stats</h2>
        <div>
            <a href="analytics.php" class="btn btn-primary btn-sm">📈 Gamification Analytics</a>
            <a href="exam.php" class="btn btn-default btn-sm">📝 Exam Mode</a>
            <a href="abuse.php" class="btn btn-default btn-sm">🕵️ Abuse Detection</a>
            <a href="home.php" class="btn btn-default btn-sm">🏠 ראשי</a>
            <a href="index.php" class="btn btn-default btn-sm">ניהול שאלות</a>
            <a href="cohorts.php" class="btn btn-default btn-sm">ניהול סמסטרים</a>
            <a href="logout.php" class="btn btn-default btn-sm">התנתקות</a>
        </div>
    </div>

    <!-- Period picker -->
    <form method="GET" class="form-inline" style="margin-bottom:20px;">
        <label>Show last: </label>
        <?php foreach ([7, 14, 30, 60, 90] as $d): ?>
            <a href="?days=<?= $d ?>" class="btn btn-sm <?= $days === $d ? 'btn-primary' : 'btn-default' ?>" style="margin-left:5px;">
                <?= $d ?>d
            </a>
        <?php endforeach; ?>
    </form>

    <!-- Summary boxes -->
    <div class="row">
        <div class="col-sm-2">
            <div class="stat-box">
                <div class="num"><?= $total_users ?></div>
                <div class="lbl">Total users</div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="stat-box highlight">
                <div class="num"><?= $active_7 ?></div>
                <div class="lbl">Active last 7d</div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="stat-box">
                <div class="num"><?= $active_30 ?></div>
                <div class="lbl">Active last 30d</div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="stat-box">
                <div class="num"><?= $answers_7 ?></div>
                <div class="lbl">Answers last 7d</div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="stat-box highlight">
                <div class="num"><?= $leaderboard_30 ?></div>
                <div class="lbl">Checked leaderboard (30d)</div>
            </div>
        </div>
        <div class="col-sm-2">
            <div class="stat-box highlight">
                <div class="num"><?= $badges_30 ?></div>
                <div class="lbl">Checked badges (30d)</div>
            </div>
        </div>
    </div>

    <!-- Daily chart -->
    <div class="chart-wrap">
        <h4>Daily activity — last <?= $days ?> days</h4>
        <canvas id="dailyChart" height="80"></canvas>
    </div>

    <!-- Per-user engagement table -->
    <div class="table-wrapper">
        <div class="table-title">
            <h2>Per-user engagement — last <?= $days ?> days</h2>
        </div>
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>Nickname</th>
                    <th>Last seen</th>
                    <th>✅ Correct</th>
                    <th>❌ Wrong</th>
                    <th>🏆 Leaderboard views</th>
                    <th>🎖 Badge views</th>
                    <th>🎖 Badges earned</th>
                    <th>⬆ Level ups</th>
                    <th>Gamification only?</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($user_rows as $u):
                $answered   = $u['correct'] + $u['wrong'];
                $gamified   = $u['leaderboard_views'] + $u['badge_views'];
                $checkin_only = ($answered === 0 && $gamified > 0);
                $days_ago   = floor((time() - strtotime($u['last_seen'])) / 86400);
                $last_seen_str = $days_ago === 0 ? 'today' : ($days_ago === 1 ? 'yesterday' : "{$days_ago}d ago");
            ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['nickname'] ?? '(no nickname)') ?></strong></td>
                    <td><?= $last_seen_str ?></td>
                    <td><?= $u['correct'] ?></td>
                    <td><?= $u['wrong'] ?></td>
                    <td><?= $u['leaderboard_views'] ?: '—' ?></td>
                    <td><?= $u['badge_views'] ?: '—' ?></td>
                    <td><?= $u['badges_earned'] ?: '—' ?></td>
                    <td><?= $u['level_ups'] ?: '—' ?></td>
                    <td>
                        <?php if ($checkin_only): ?>
                            <span class="tag tag-some">check-in only</span>
                        <?php elseif ($gamified > 0 && $answered > 0): ?>
                            <span class="tag tag-yes">answers + gamification</span>
                        <?php else: ?>
                            <span class="tag tag-no">answers only</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
const labels  = <?= json_encode($daily_labels) ?>;
const answers = <?= json_encode($daily_answers) ?>;
const correct = <?= json_encode($daily_correct) ?>;
const users   = <?= json_encode($daily_users) ?>;

new Chart(document.getElementById('dailyChart'), {
    data: {
        labels: labels,
        datasets: [
            {
                type: 'bar',
                label: 'Wrong answers',
                data: answers.map((a, i) => a - correct[i]),
                backgroundColor: 'rgba(231,76,60,0.6)',
                stack: 'answers',
            },
            {
                type: 'bar',
                label: 'Correct answers',
                data: correct,
                backgroundColor: 'rgba(39,174,96,0.7)',
                stack: 'answers',
            },
            {
                type: 'line',
                label: 'Active users',
                data: users,
                borderColor: '#435d7d',
                backgroundColor: 'rgba(67,93,125,0.1)',
                tension: 0.3,
                yAxisID: 'y2',
                pointRadius: 4,
            },
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index' },
        scales: {
            y:  { stacked: true, title: { display: true, text: 'Answers' }, beginAtZero: true },
            y2: { position: 'right', title: { display: true, text: 'Active users' }, beginAtZero: true, grid: { drawOnChartArea: false } },
        }
    }
});
</script>
</body>
</html>
