<?php
require_once dirname(__DIR__) . '/bootstrap/app.php';

$handle = fopen(dirname(__DIR__) . '/toimportnew.txt', 'r') or die('Error reading file!');
$i = 1;
$question = fgets($handle);
$option1 = fgets($handle);
$option2 = fgets($handle);
$option3 = fgets($handle);
$option4 = fgets($handle);
$answer = fgets($handle);
$query = "INSERT INTO questions(question_text,option1, option2,option3,option4,correctans) VALUES ('" .
        $question . "','" . $option1 . "','" . $option2 . "','" . $option3 . "','" . $option4 . "','" . $answer . "')";
mysqli_query($db, $query);

while (($line = fgets($handle)) !== false) {
    $i++;
    $question = $line;
    $option1 = fgets($handle);
    $option2 = fgets($handle);
    $option3 = fgets($handle);
    $option4 = fgets($handle);
    $answer = fgets($handle);

    $query = "INSERT INTO questions(question_text,option1, option2,option3,option4,correctans) VALUES ('" .
        $question . "','" . $option1 . "','" . $option2 . "','" . $option3 . "','" . $option4 . "','" . $answer . "')";

    mysqli_query($db, $query);
}

fclose($handle);
