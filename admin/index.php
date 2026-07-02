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

// The global default-week control now lives on the admin home page (home.php).

// Parse lecture filter from GET
$lecture_filter_raw = isset($_GET['lecture']) ? $_GET['lecture'] : 'all';
$lecture_where = "";
if ($lecture_filter_raw === 'null') {
    $lecture_where = "WHERE max_lecture IS NULL";
} elseif (is_numeric($lecture_filter_raw) && $lecture_filter_raw >= 1 && $lecture_filter_raw <= 12) {
    $lecture_where = "WHERE max_lecture = " . intval($lecture_filter_raw);
}

// Build per-lecture counts for the dropdown
$lecture_counts = array_fill(0, 13, 0); // index 0 = NULL, 1..12 = lectures
$ccres = mysqli_query($conn, "SELECT COALESCE(max_lecture, 0) AS lec, COUNT(*) AS n FROM questions GROUP BY lec");
if ($ccres) {
    while ($crow = mysqli_fetch_assoc($ccres)) {
        $lecture_counts[intval($crow['lec'])] = intval($crow['n']);
    }
    mysqli_free_result($ccres);
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
	<meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>User Data</title>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto|Varela+Round">
	<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css">
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
	<link rel="stylesheet" href="css/style.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
	<script src="ajax/ajax.js"></script>
    <style>
        th {
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="container">
	<p id="success"></p>

        <nav style="margin-top:18px; margin-bottom:10px;">
            <a href="home.php" class="btn btn-default btn-sm">🏠 ראשי</a>
            <a href="index.php" class="btn btn-primary btn-sm">ניהול שאלות</a>
            <a href="stats.php" class="btn btn-default btn-sm">לוח נתונים</a>
            <a href="exam.php" class="btn btn-default btn-sm">📝 מצב מבחן</a>
            <a href="cohorts.php" class="btn btn-default btn-sm">ניהול סמסטרים</a>
        </nav>

        <div class="table-wrapper">
            <div class="table-title">
                <div class="row">
                    <div class="col-sm-6">
						<h2>Manage <b>Questions</b></h2>
					</div>
					<div class="col-sm-6">
						<a href="#addQModal" class="btn btn-success" data-toggle="modal"><i class="material-icons">&#xE147;</i> <span>Add New Question</span></a>
						<a href="JavaScript:void(0);" class="btn btn-danger" id="delete_multiple"><i class="material-icons">&#xE15C;</i> <span>Delete</span></a>
						<a href="stats.php" class="btn btn-info"><i class="material-icons">&#xE88A;</i> <span>Stats</span></a>
						<a href="logout.php" class="btn btn-warning"><i class="material-icons">&#xE8AC;</i> <span>Logout</span></a>
					</div>
                </div>
                <div class="row" style="margin-top: 10px;">
                    <div class="col-sm-12">
                        <form method="GET" class="form-inline">
                            <label>Filter by lecture:</label>
                            <select name="lecture" class="form-control" onchange="this.form.submit()">
                                <option value="all" <?php echo $lecture_filter_raw === 'all' ? 'selected' : ''; ?>>All lectures</option>
                                <?php for ($L = 1; $L <= 12; $L++): ?>
                                    <option value="<?php echo $L; ?>" <?php echo ((string)$lecture_filter_raw === (string)$L) ? 'selected' : ''; ?>>
                                        L<?php echo $L; ?> (<?php echo $lecture_counts[$L]; ?>)
                                    </option>
                                <?php endfor; ?>
                                <option value="null" <?php echo $lecture_filter_raw === 'null' ? 'selected' : ''; ?>>Untagged (<?php echo $lecture_counts[0]; ?>)</option>
                            </select>
                            <noscript><button type="submit" class="btn btn-default">Apply</button></noscript>
                        </form>
                    </div>
                </div>
            </div>
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
						<th>
							<span class="custom-checkbox">
								<input type="checkbox" id="selectAll">
								<label for="selectAll"></label>
							</span>
						</th>
                        <th dir="rtl" align="right"> #</th>
                        <th dir="rtl" align="center">שאלה</th>
                        <th align="left">תשובה א</th>
                        <th align="right">תשובה ב</th>
						<th  align="right">תשובה ג</th>
                        <th align="right">תשובה ד</th>
                        <th align="right">תשובה נכונה</th>
                        <th align="right">שיעור</th>
                    </tr>
                </thead>
				<tbody>
				    
				<?php
				$result = mysqli_query($conn,"SELECT * FROM questions $lecture_where ORDER BY reportedbad DESC");
					$i=1;
					while($row = mysqli_fetch_array($result)) {
				?>
				<tr id="<?php echo $row["id"]; ?>">
				<td>
							<span class="custom-checkbox">
								<input type="checkbox" class="user_checkbox" data-user-id="<?php echo $row["id"]; ?>">
								<label for="checkbox2"></label>
							</span>
						</td>
					<td><?php echo $i; ?></td>
					<td><?php echo $row["question_text"]; ?></td>
					<td><?php echo $row["option1"]; ?></td>
					<td><?php echo $row["option2"]; ?></td>
					<td><?php echo $row["option3"]; ?></td>
                    <td><?php echo $row["option4"]; ?></td>
                    <td><?php echo $row["correctans"]; ?></td>
                    <td><?php echo $row["max_lecture"] !== null ? 'L' . $row["max_lecture"] : '—'; ?></td>
					<td>
                        <a href="update-process.php?id=<?php echo $row["id"]; ?>">Update</a>
						<a href="#deleteEmployeeModal" class="delete" data-id="<?php echo $row["id"]; ?>" data-toggle="modal"><i class="material-icons" data-toggle="tooltip" 
						 title="Delete">&#xE872;</i></a>
                    </td>
				</tr>
				<?php
				$i++;
				}
				?>
				</tbody>
			</table>
			
        </div>
    </div>
	<!-- Add Modal HTML -->
	<div id="addQModal" class="modal fade">
		<div class="modal-dialog">
			<div class="modal-content">
				<form id="user_form">
					<div class="modal-header">						
						<h4 class="modal-title">Add Question</h4>
						<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					</div>
					<div class="modal-body">					
						<div class="form-group">
							<label>שאלה</label>
							<input type="text" id="question" name="question" class="form-control" required>
						</div>
						<div class="form-group">
							<label>אפשרות א</label>
							<input type="text" id="data-o1" name="option1" class="form-control" required>
						</div>
						<div class="form-group">
							<label>אפשרות ב</label>
							<input type="text" id="option2" name="option2" class="form-control" required>
						</div>
						<div class="form-group">
							<label>אפשרות ג</label>
							<input type="text" id="option3" name="option3" class="form-control" required>
						</div>
                        <div class="form-group">
                            <label>אפשרות ד</label>
                            <input type="text" id="option4" name="option4" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>תשובה נכונה</label>
                            <input type="text" id="ans" name="ans" class="form-control" required>
                        </div>
                    </div>
					<div class="modal-footer">
					    <input type="hidden" value="1" name="type">
						<input type="button" class="btn btn-default" data-dismiss="modal" value="Cancel">
						<button type="button" class="btn btn-success" id="btn-add">Add</button>
					</div>
				</form>
			</div>
		</div>
	</div>
	<!-- Edit Modal HTML -->
	<div id="editEmployeeModal" class="modal fade">
		<div class="modal-dialog">
			<div class="modal-content">
				<form id="update_form">
					<div class="modal-header">						
						<h4 class="modal-title">Edit User</h4>
						<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					</div>
					<div class="modal-body">
						<input type="hidden" id="id_u" name="id" class="form-control" required>
						<div class="form-group">
							<label>שאלה</label>
							<input type="text" id="q_u" name="question" value=data-q  class="form-control" required>
						</div>
						<div class="form-group">
							<label>אפשרות א</label>
							<input type="data-o1" id="o1_u" name="data-o1" class="form-control" required>
						</div>
						<div class="form-group">
							<label>אפשרות ב</label>
							<input type="phone" id="o2_u" name="option2" class="form-control" required>
						</div>
						<div class="form-group">
							<label>אפשרות ג</label>
							<input type="city" id="o3_u" name="option3" class="form-control" required>
						</div>
                        <div class="form-group">
                            <label>אפשרות ד</label>
                            <input type="city" id="o4_u" name="option4" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>תשובה נכונה</label>
                            <input type="city" id="ans_u" name="ans" class="form-control" required>
                        </div>


                    </div>
					<div class="modal-footer">
					<input type="hidden" value="2" name="type">
						<input type="button" class="btn btn-default" data-dismiss="modal" value="Cancel">
						<button type="button" class="btn btn-info" id="update">Update</button>
					</div>
				</form>
			</div>
		</div>
	</div>
	<!-- Delete Modal HTML -->
	<div id="deleteEmployeeModal" class="modal fade">
		<div class="modal-dialog">
			<div class="modal-content">
				<form>
						
					<div class="modal-header">						
						<h4 class="modal-title">Delete Question</h4>
						<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
					</div>
					<div class="modal-body">
						<input type="hidden" id="id_d" name="id" class="form-control">					
						<p>Are you sure you want to delete these Records?</p>
						<p class="text-warning"><small>This action cannot be undone.</small></p>
					</div>
					<div class="modal-footer">
						<input type="button" class="btn btn-default" data-dismiss="modal" value="Cancel">
						<button type="button" class="btn btn-danger" id="delete">Delete</button>
					</div>
				</form>
			</div>
		</div>
	</div>

</body>
</html>
