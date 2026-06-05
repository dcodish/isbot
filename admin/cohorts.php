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

// Allowed colour tokens for the (optional) leaderboard indicator.
$ALLOWED_COLORS = ['' => '—', 'red' => 'אדום', 'blue' => 'כחול', 'green' => 'ירוק', 'orange' => 'כתום', 'purple' => 'סגול'];
$COLOR_SWATCH   = ['red' => '#e53935', 'blue' => '#1e88e5', 'green' => '#43a047', 'orange' => '#fb8c00', 'purple' => '#8e24aa'];

$message = '';
$message_type = 'info';

// ── POST router ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create') {
        $name  = trim($_POST['name'] ?? '');
        $week  = intval($_POST['current_week'] ?? 0);
        $color = $_POST['color'] ?? '';
        if (!array_key_exists($color, $ALLOWED_COLORS)) $color = '';

        if ($name === '') {
            $message = 'שם הסמסטר לא יכול להיות ריק.'; $message_type = 'danger';
        } elseif (mb_strlen($name) > 64) {
            $message = 'שם הסמסטר ארוך מדי (עד 64 תווים).'; $message_type = 'danger';
        } elseif ($week < 1 || $week > 12) {
            $message = 'שבוע לא תקין: חייב להיות בטווח 1–12.'; $message_type = 'danger';
        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO cohorts (name, current_week, color, active) VALUES (?, ?, ?, 1)");
            $color_val = ($color === '') ? null : $color;
            mysqli_stmt_bind_param($stmt, 'sis', $name, $week, $color_val);
            if (mysqli_stmt_execute($stmt)) {
                $message = "הסמסטר \"$name\" נוצר (שבוע $week)."; $message_type = 'success';
            } elseif (mysqli_errno($conn) === 1062) {
                $message = "כבר קיים סמסטר בשם \"$name\"."; $message_type = 'danger';
            } else {
                $message = 'שגיאה ביצירת הסמסטר: ' . mysqli_error($conn); $message_type = 'danger';
            }
            mysqli_stmt_close($stmt);
        }
    } elseif ($action === 'update') {
        $id     = intval($_POST['id'] ?? 0);
        $week   = intval($_POST['current_week'] ?? 0);
        $color  = $_POST['color'] ?? '';
        $active = (isset($_POST['active']) && $_POST['active'] === '1') ? 1 : 0;
        if (!array_key_exists($color, $ALLOWED_COLORS)) $color = '';

        if ($id <= 0) {
            $message = 'מזהה סמסטר לא תקין.'; $message_type = 'danger';
        } elseif ($week < 1 || $week > 12) {
            $message = 'שבוע לא תקין: חייב להיות בטווח 1–12.'; $message_type = 'danger';
        } else {
            $stmt = mysqli_prepare($conn,
                "UPDATE cohorts SET current_week = ?, color = ?, active = ? WHERE id = ?");
            $color_val = ($color === '') ? null : $color;
            // bind order: current_week(i), color(s), active(i), id(i)
            mysqli_stmt_bind_param($stmt, 'isii', $week, $color_val, $active, $id);
            if (mysqli_stmt_execute($stmt)) {
                $message = "הסמסטר עודכן (שבוע $week)."; $message_type = 'success';
            } else {
                $message = 'שגיאה בעדכון: ' . mysqli_error($conn); $message_type = 'danger';
            }
            mysqli_stmt_close($stmt);
        }
    } elseif ($action === 'toggle_gate') {
        $enable = (isset($_POST['enable']) && $_POST['enable'] === '1') ? '1' : '0';
        $stmt = mysqli_prepare($conn,
            "INSERT INTO settings (setting_key, setting_value) VALUES ('cohort_gate_enabled', ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        mysqli_stmt_bind_param($stmt, 's', $enable);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $message = ($enable === '1')
            ? 'שער בחירת הסמסטר הופעל — משתמשים חדשים יחויבו לבחור סמסטר אחרי קביעת כינוי.'
            : 'שער בחירת הסמסטר כובה — משתמשים חדשים לא יתבקשו לבחור סמסטר.';
        $message_type = 'success';
    }
}

// Read the onboarding-gate flag (settings.cohort_gate_enabled).
$gate_enabled = false;
$gres = mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key='cohort_gate_enabled' LIMIT 1");
if ($gres && mysqli_num_rows($gres) > 0) {
    $grow = mysqli_fetch_assoc($gres);
    $gate_enabled = (intval($grow['setting_value']) === 1);
    mysqli_free_result($gres);
}

// ── Load cohorts with user counts ────────────────────────────────────────────
$cohorts = [];
$res = mysqli_query($conn,
    "SELECT c.id, c.name, c.current_week, c.color, c.active, c.created_at,
            (SELECT COUNT(*) FROM users u WHERE u.cohort_id = c.id) AS user_count
       FROM cohorts c
   ORDER BY c.active DESC, c.id ASC");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) $cohorts[] = $row;
    mysqli_free_result($res);
}
$total_assigned = 0;
foreach ($cohorts as $c) $total_assigned += intval($c['user_count']);
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ניהול קבוצות</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <style>
        body { padding-bottom: 60px; }
        th { text-align: right; }
        .swatch { display:inline-block; width:14px; height:14px; border-radius:3px; vertical-align:middle; margin-left:6px; border:1px solid #ccc; }
        .inactive-row td { opacity: 0.55; }
        .num { font-variant-numeric: tabular-nums; }
    </style>
</head>
<body>
<div class="container">

    <nav style="margin-top:18px; margin-bottom:10px;">
        <a href="home.php" class="btn btn-default btn-sm">🏠 ראשי</a>
        <a href="index.php" class="btn btn-default btn-sm">ניהול שאלות</a>
        <a href="stats.php" class="btn btn-default btn-sm">לוח נתונים</a>
        <a href="cohorts.php" class="btn btn-primary btn-sm">ניהול סמסטרים</a>
        <a href="logout.php" class="btn btn-link btn-sm pull-left">התנתקות</a>
    </nav>

    <h2>ניהול סמסטרים (Cohorts)</h2>
    <p class="text-muted">
        כל סמסטר מתקדם בשבוע משלו. סטודנט רואה שאלות עם <code>max_lecture ≤ שבוע הסמסטר שלו</code>.
        סטודנט שאינו משויך לסמסטר נופל חזרה להגדרה הגלובלית.
    </p>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <!-- Onboarding gate toggle -->
    <div class="panel <?php echo $gate_enabled ? 'panel-success' : 'panel-warning'; ?>">
        <div class="panel-heading"><strong>שער בחירת סמסטר (חיוב בהצטרפות)</strong></div>
        <div class="panel-body">
            <p style="margin-bottom:12px;">
                מצב נוכחי:
                <?php if ($gate_enabled): ?>
                    <span class="label label-success">מופעל</span>
                    — משתמש חדש חייב לבחור סמסטר אחרי קביעת כינוי.
                <?php else: ?>
                    <span class="label label-default">כבוי</span>
                    — משתמשים חדשים אינם מחויבים לבחור סמסטר.
                <?php endif; ?>
            </p>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="toggle_gate">
                <input type="hidden" name="enable" value="<?php echo $gate_enabled ? '0' : '1'; ?>">
                <button type="submit" class="btn <?php echo $gate_enabled ? 'btn-warning' : 'btn-success'; ?>">
                    <?php echo $gate_enabled ? 'כבה את השער' : 'הפעל את השער'; ?>
                </button>
            </form>
            <span class="text-muted" style="margin-right:10px;">
                משתמשים קיימים לעולם אינם מושפעים — כולם כבר משויכים לסמסטר. זהו מתג כיבוי מיידי.
            </span>
        </div>
    </div>

    <!-- Existing cohorts -->
    <div class="panel panel-default">
        <div class="panel-heading">
            <strong>סמסטרים קיימים</strong>
            <span class="text-muted">(<?php echo count($cohorts); ?> סמסטרים, <?php echo $total_assigned; ?> סטודנטים משויכים)</span>
        </div>
        <div class="panel-body">
            <?php if (!$cohorts): ?>
                <p class="text-muted">אין עדיין סמסטרים.</p>
            <?php else: ?>
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>שם הסמסטר</th>
                        <th>שבוע נוכחי</th>
                        <th>צבע</th>
                        <th>פעילה</th>
                        <th>סטודנטים</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cohorts as $c): ?>
                    <tr class="<?php echo $c['active'] ? '' : 'inactive-row'; ?>">
                        <form method="POST">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo intval($c['id']); ?>">
                            <td class="num"><?php echo intval($c['id']); ?></td>
                            <td><?php echo htmlspecialchars($c['name']); ?></td>
                            <td>
                                <input type="number" name="current_week" min="1" max="12"
                                       value="<?php echo intval($c['current_week']); ?>"
                                       class="form-control input-sm" style="width:70px;">
                            </td>
                            <td>
                                <?php $cur = $c['color'] ?? ''; ?>
                                <?php if ($cur && isset($COLOR_SWATCH[$cur])): ?>
                                    <span class="swatch" style="background:<?php echo $COLOR_SWATCH[$cur]; ?>"></span>
                                <?php endif; ?>
                                <select name="color" class="form-control input-sm" style="width:90px; display:inline-block;">
                                    <?php foreach ($ALLOWED_COLORS as $tok => $label): ?>
                                        <option value="<?php echo $tok; ?>" <?php echo ($cur === $tok ? 'selected' : ''); ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="active" class="form-control input-sm" style="width:90px;">
                                    <option value="1" <?php echo $c['active'] ? 'selected' : ''; ?>>פעילה</option>
                                    <option value="0" <?php echo $c['active'] ? '' : 'selected'; ?>>לא פעילה</option>
                                </select>
                            </td>
                            <td class="num"><?php echo intval($c['user_count']); ?></td>
                            <td><button type="submit" class="btn btn-primary btn-sm">שמירה</button></td>
                        </form>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-muted small">
                סמסטר "לא פעיל" לא יוצג לסטודנטים בבחירת סמסטר, אך נשמר לצרכי היסטוריה.
                שינוי השבוע משפיע מיידית על השאלות שהסטודנטים בסמסטר רואים.
            </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Create new cohort -->
    <div class="panel panel-success">
        <div class="panel-heading"><strong>יצירת סמסטר חדש</strong></div>
        <div class="panel-body">
            <form method="POST" class="form-inline">
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label>שם:</label>
                    <input type="text" name="name" maxlength="64" placeholder="למשל: סמסטר ב 2026"
                           class="form-control" style="width:220px;" required>
                </div>
                <div class="form-group" style="margin-right:10px;">
                    <label>שבוע התחלתי:</label>
                    <input type="number" name="current_week" min="1" max="12" value="1"
                           class="form-control" style="width:70px;" required>
                </div>
                <div class="form-group" style="margin-right:10px;">
                    <label>צבע:</label>
                    <select name="color" class="form-control" style="width:110px;">
                        <?php foreach ($ALLOWED_COLORS as $tok => $label): ?>
                            <option value="<?php echo $tok; ?>"><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-success" style="margin-right:10px;">יצירה</button>
            </form>
            <p class="text-muted small" style="margin-top:10px;">
                שמות בעברית. סמסטר חדש מתחיל ריק — סטודנטים יצטרפו אליו כשיבחרו אותו.
            </p>
        </div>
    </div>

</div>
</body>
</html>
