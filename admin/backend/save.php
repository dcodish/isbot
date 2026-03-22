<?php
include 'database.php';

if(count($_POST)>0){
	if($_POST['type']==1){
		$q=$_POST['question'];
		$o1=$_POST['option1'];
		$o2=$_POST['option2'];
		$o3=$_POST['option3'];
        $o4=$_POST['option4'];
        $ans=$_POST['ans'];
		$sql = "INSERT INTO `questions`( `question_text`, `option1`, `option2`, `option3`, `option4`, `correctans`) 
		VALUES ('$q','$o1','$o2','$o3','$o4','$ans')";
		if (mysqli_query($conn, $sql)) {
			echo json_encode(array("statusCode"=>200));
		} 
		else {
			echo "Error: " . $sql . "<br>" . mysqli_error($conn);
		}
		mysqli_close($conn);
	}
}

if(count($_POST)>0){
	if($_POST['type']==2){
        $id=$_POST['id'];
        $q=$_POST['question'];
        $o1=$_POST['option1'];
        $o2=$_POST['option2'];
        $o3=$_POST['option3'];
        $o4=$_POST['option4'];
        $ans=$_POST['ans'];
		$sql = "UPDATE `questions` SET `question_text`='$q',`option1`='$o1',`option2`='$o2',`option3`='$o3'
            ,`option4`='$o4' ,`correctans`='$ans' WHERE id=$id";
		if (mysqli_query($conn, $sql)) {
			echo json_encode(array("statusCode"=>200));
		} 
		else {
			echo "Error: " . $sql . "<br>" . mysqli_error($conn);
		}
		mysqli_close($conn);
	}
}
if(count($_POST)>0){
	if($_POST['type']==3){
		$id=$_POST['id'];
		$sql = "DELETE FROM `questions` WHERE id=$id ";
		if (mysqli_query($conn, $sql)) {
			echo $id;
		} 
		else {
			echo "Error: " . $sql . "<br>" . mysqli_error($conn);
		}
		mysqli_close($conn);
	}
}
if(count($_POST)>0){
	if($_POST['type']==4){
		$id=$_POST['id'];
		$sql = "DELETE FROM questions WHERE id in ($id)";
		if (mysqli_query($conn, $sql)) {
			echo $id;
		} 
		else {
			echo "Error: " . $sql . "<br>" . mysqli_error($conn);
		}
		mysqli_close($conn);
	}
}

?>