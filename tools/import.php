<?php
require_once dirname(__DIR__) . '/bootstrap/app.php';

$file_contents = file_get_contents(dirname(__DIR__) . '/new file.txt');
$file_contents = str_replace("A) ", "", $file_contents);
$file_contents = str_replace("B) ", "", $file_contents);
$file_contents = str_replace("C) ", "", $file_contents);
$file_contents = str_replace("D) ", "", $file_contents);
file_put_contents(dirname(__DIR__) . '/toimport.txt', $file_contents);

$handle = fopen(dirname(__DIR__) . '/toimport.txt', 'r') or die('Error reading file!');
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
