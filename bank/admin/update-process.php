<?php
include 'backend/database.php';

if(count($_POST)>0) {
    $id=$_POST['id'];
    $q=$_POST['question'];
    $o1=$_POST['option1'];
    $o2=$_POST['option2'];
    $o3=$_POST['option3'];
    $o4=$_POST['option4'];
    $ans=$_POST['correctans'];
    $sql = "UPDATE `questions` SET `question_text`='$q',`option1`='$o1',`option2`='$o2',`option3`='$o3'
            ,`option4`='$o4' ,`correctans`='$ans' WHERE id=$id";
    mysqli_query($conn,$sql);
    $sql = "UPDATE questions SET reportedbad=0 where id=".$id;
    mysqli_query($conn,$sql);
    $message = "Record Modified Successfully";
}
$sql = "SELECT * FROM questions WHERE id='" . $_GET['id'] . "'";
$result = mysqli_query($conn,$sql);
$row= mysqli_fetch_array($result);
?>


<html>
<head>
    <title>Update Employee Data</title>
    <link rel="stylesheet" href="css/updatestyle.css">
</head>
<body dir="rtl">

<form name="frmUser" method="post" action="">
    <div><?php if(isset($message)) { echo $message; } ?>
    </div>
    <div style="padding-bottom:5px;">
        <a href="index.php">Employee List</a>
    </div>
    השאלה: <br>
    <input type="hidden" name="id" class="txtField" value="<?php echo $row['id']; ?>">
    <textarea cols="100" name="question"  rows="2"><?php echo $row['question_text']; ?></textarea>

    <br>
    אפשרות א : <textarea name="option1" cols="100"> <?php echo $row['option1']; ?> </textarea>
    <br>
    אפשרות ב : <textarea name="option2" cols="100"> <?php echo $row['option2']; ?> </textarea>
    <br>
    אפשרות ג : <textarea name="option3" cols="100"> <?php echo $row['option3']; ?> </textarea>
    <br>
    אפשרות ד : <textarea name="option4" cols="100"> <?php echo $row['option4']; ?> </textarea>
    <br>
    תשובה נכונה<br>
    <input type="text" name="correctans"  value="<?php echo $row['correctans']; ?>">

    <br>
    <input type="submit" name="submit" value="Submit" class="buttom">

</form>
</body>
</html>