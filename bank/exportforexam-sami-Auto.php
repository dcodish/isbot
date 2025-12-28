    <?php
include 'admin/backend/database.php';


    function randOrder($res) {
        global $db;
        $query= "SELECT telegramid FROM img WHERE id=".$photo_id;
        $result=mysqli_query($db,$query);
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
            <?php
            $result = mysqli_query($conn,"SELECT * FROM questions order by rand()");
            $i=1;
            while($row = mysqli_fetch_array($result)) {

                echo "<BR>";
                echo $i.". ";
                echo $row["question_text"];

                $numbers = range(1, 4);
                shuffle($numbers);


                echo "<BR> א. ";
                echo $row["option".$numbers[0]];
                echo "<BR> ב. ";
                echo $row["option".$numbers[1]];
                echo "<BR> ג. ";
                echo $row["option".$numbers[2]];
                echo "<BR> ד. ";
                echo $row["option".$numbers[3]];
                echo "<BR>";
                $i++;
            }
            ?>
</body>
</html>