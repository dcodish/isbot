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
            <?php
            $result = mysqli_query($conn,"SELECT * FROM questions ORDER BY numofcorrectanswers / numofanswers  ASC");
            $i=1;
            while($row = mysqli_fetch_array($result)) {
                ?>
                <BR>
                <?php echo $i.". "; ?>
                    <?php echo $row["question_text"]; ?>
                <?php
                switch ($row["correctans"]) {
                    case 1: {
                        ?>
                        <BR><?php echo $row["option1"]; ?>
                        <BR><?php echo $row["option2"]; ?>
                        <BR><?php echo $row["option3"]; ?>
                        <BR><?php echo $row["option4"]; ?>
                        <BR>
                        <?php
                    } break;
                    case 2: {
                        ?>
                        <BR><?php echo $row["option2"]; ?>
                        <BR><?php echo $row["option1"]; ?>
                        <BR><?php echo $row["option3"]; ?>
                        <BR><?php echo $row["option4"]; ?>
                        <BR>
                        <?php
                    } break;
                    case 3: {
                        ?>
                        <BR><?php echo $row["option3"]; ?>
                        <BR><?php echo $row["option1"]; ?>
                        <BR><?php echo $row["option2"]; ?>
                        <BR><?php echo $row["option4"]; ?>
                        <BR>
                        <?php
                    } break;
                    case 4: {
                        ?>
                        <BR><?php echo $row["option4"]; ?>
                        <BR><?php echo $row["option1"]; ?>
                        <BR><?php echo $row["option2"]; ?>
                        <BR><?php echo $row["option3"]; ?>
                        <BR>
                        <?php
                    } break;
                    default: {

                    } break;
                }
                $i++;
            }
            ?>
</body>
</html>