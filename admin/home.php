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

// ── Quick at-a-glance numbers (read-only) ────────────────────────────────────
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

$total_users     = scalar($conn, "SELECT COUNT(*) FROM users");
$total_questions = scalar($conn, "SELECT COUNT(*) FROM questions");
$active_cohorts  = scalar($conn, "SELECT COUNT(*) FROM cohorts WHERE active = 1");

// Colour swatches for the (optional) per-semester indicator.
$COLOR_SWATCH = ['red' => '#e53935', 'blue' => '#1e88e5', 'green' => '#43a047', 'orange' => '#fb8c00', 'purple' => '#8e24aa'];

// ── Active semesters with their current week + user counts ───────────────────
$cohorts = [];
$res = mysqli_query($conn,
    "SELECT c.id, c.name, c.current_week, c.color,
            (SELECT COUNT(*) FROM users u WHERE u.cohort_id = c.id) AS user_count
       FROM cohorts c
      WHERE c.active = 1
   ORDER BY c.id ASC");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) $cohorts[] = $row;
    mysqli_free_result($res);
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>פאנל ניהול</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <style>
        body { padding-bottom: 60px; background: #f6f8fa; }
        .hub-card { display:block; border:1px solid #ddd; border-radius:8px; background:#fff;
                    padding:24px; margin-bottom:20px; text-decoration:none; color:#333;
                    transition:box-shadow .15s, transform .15s; height:100%; }
        .hub-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.12); transform:translateY(-2px); text-decoration:none; }
        .hub-card .icon { font-size:34px; }
        .hub-card h3 { margin:8px 0 6px; }
        .hub-card p { color:#888; margin:0; }
        .stat { display:inline-block; min-width:120px; margin:0 6px 10px 0; padding:12px 16px;
                background:#fff; border:1px solid #e3e3e3; border-radius:8px; text-align:center; }
        .stat .num { font-size:24px; font-weight:bold; color:#435d7d; }
        .stat .lbl { color:#999; font-size:12px; }
        .swatch { display:inline-block; width:14px; height:14px; border-radius:3px; vertical-align:middle; margin-left:6px; border:1px solid #ccc; }
        .num { font-variant-numeric: tabular-nums; }
        .wk { font-size:20px; font-weight:bold; color:#435d7d; }
    </style>
</head>
<body>
<div class="container" style="margin-top:30px;">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0; color:#435d7d;">🛠️ פאנל ניהול — בוט יסודות מערכות מידע</h2>
        <a href="logout.php" class="btn btn-default btn-sm">התנתקות</a>
    </div>

    <!-- quick stats -->
    <div style="margin-bottom:24px;">
        <div class="stat"><div class="num"><?php echo intval($total_users); ?></div><div class="lbl">משתמשים</div></div>
        <div class="stat"><div class="num"><?php echo intval($total_questions); ?></div><div class="lbl">שאלות</div></div>
        <div class="stat"><div class="num"><?php echo intval($active_cohorts); ?></div><div class="lbl">סמסטרים פעילים</div></div>
    </div>

    <!-- active semesters + their week -->
    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>סמסטרים פעילים והשבוע הנוכחי שלהם</strong>
            <a href="cohorts.php" class="btn btn-primary btn-xs pull-left">ניהול סמסטרים</a>
        </div>
        <div class="panel-body">
            <?php if (!$cohorts): ?>
                <p class="text-muted">אין סמסטרים פעילים. <a href="cohorts.php">צור סמסטר</a>.</p>
            <?php else: ?>
            <table class="table table-striped" style="margin-bottom:0;">
                <thead>
                    <tr>
                        <th>שם הסמסטר</th>
                        <th>שבוע נוכחי</th>
                        <th>סטודנטים</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cohorts as $c): ?>
                    <tr>
                        <td>
                            <?php $cur = $c['color'] ?? ''; ?>
                            <?php if ($cur && isset($COLOR_SWATCH[$cur])): ?>
                                <span class="swatch" style="background:<?php echo $COLOR_SWATCH[$cur]; ?>"></span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($c['name']); ?>
                        </td>
                        <td><span class="wk">שבוע <?php echo intval($c['current_week']); ?></span></td>
                        <td class="num"><?php echo intval($c['user_count']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- section cards -->
    <div class="row">
        <div class="col-sm-4">
            <a class="hub-card" href="index.php">
                <div class="icon">📝</div>
                <h3>ניהול שאלות</h3>
                <p>הוספה, עריכה וסינון שאלות; שאלות שדווחו.</p>
            </a>
        </div>
        <div class="col-sm-4">
            <a class="hub-card" href="cohorts.php">
                <div class="icon">👥</div>
                <h3>ניהול סמסטרים</h3>
                <p>יצירה ועריכה של סמסטרים, ושבוע נפרד לכל סמסטר.</p>
            </a>
        </div>
        <div class="col-sm-4">
            <a class="hub-card" href="stats.php">
                <div class="icon">📊</div>
                <h3>לוח נתונים</h3>
                <p>פעילות משתמשים, תשובות, מובילים ותגים לאורך זמן.</p>
            </a>
        </div>
        <div class="col-sm-4">
            <a class="hub-card" href="exam.php">
                <div class="icon">📝</div>
                <h3>מצב מבחן</h3>
                <p>שימוש במבחני התרגול: אימוץ, סיום, ציונים וקושי לפי הרצאה.</p>
            </a>
        </div>
    </div>

</div>
</body>
</html>
