<?php
include 'admin/backend/database.php';
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
    <div class="table-wrapper">
        <div class="table-title">
            <div class="row">
                <div class="col-sm-6">
                    <h2>בנק השאלות</h2>
                </div>
            </div>
        </div>
        <table class="table table-striped table-hover">
            <thead>
            <tr>
                <th>#</th>
                <th dir="rtl" align="center">שאלה</th>
                <th align="left">תשובה 1</th>
                <th align="right">תשובה 2</th>
                <th  align="right">תשובה 3</th>
                <th align="right">תשובה 4</th>
                <th align="right">תשובה נכונה</th>
            </tr>
            </thead>
            <tbody>

            <?php
            $result = mysqli_query($conn,"SELECT * FROM questions order by rand()");
            $i=1;
            while($row = mysqli_fetch_array($result)) {
                ?>
                <tr id="<?php echo $row["id"]; ?>">
                    <td><?php echo $i; ?></td>
                    <td><?php echo $row["question_text"]; ?></td>
                    <td><?php echo $row["option1"]; ?></td>
                    <td><?php echo $row["option2"]; ?></td>
                    <td><?php echo $row["option3"]; ?></td>
                    <td><?php echo $row["option4"]; ?></td>
                    <td><?php echo $row["correctans"]; ?></td>
                </tr>
                <?php
                $i++;
            }
            ?>
            </tbody>
        </table>

    </div>
</div>


</body>
</html>