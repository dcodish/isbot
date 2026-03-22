<?php
require_once dirname(__DIR__) . '/admin/backend/database.php';

function randOrder($res) {
    global $db;
    $query = "SELECT telegramid FROM img WHERE id=" . $photo_id;
    $result = mysqli_query($db, $query);
    $res = mysqli_fetch_assoc($result);
    return $res['telegramid'];
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
    <link rel="stylesheet" href="../admin/css/style.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <script src="../admin/ajax/ajax.js"></script>
    <style>
        th {
            text-align: right;
        }
    </style>
</head>
<body>
            <?php
            $result = mysqli_query($conn, "SELECT * FROM questions order by rand()");
            $i = 1;
            while ($row = mysqli_fetch_array($result)) {

                echo "<br>";
                echo $i . ". ";
                echo $row["question_text"];

                $numbers = range(1, 4);
                shuffle($numbers);


                echo "<br> א. ";
                echo $row["option" . $numbers[0]];
                echo "<br> ב. ";
                echo $row["option" . $numbers[1]];
                echo "<br> ג. ";
                echo $row["option" . $numbers[2]];
                echo "<br> ד. ";
                echo $row["option" . $numbers[3]];
                echo "<br>";
                $i++;
            }
            ?>
</body>
</html>
