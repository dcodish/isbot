<?php

include 'config.php';
include 'bot_functions.php';
include 'admin/backend/database.php';
global $db, $lastSQ;

$input = file_get_contents('php://input');
if (DEBUG==="ON") {
    file_put_contents("result.txt", $input . PHP_EOL . PHP_EOL, FILE_APPEND);
}


$update = json_decode($input, true);
// ensure an array to avoid TypeError
if (!is_array($update)) {
    $update = [];
}

// init defaults so later code can rely on defined vars
$chat_id = $user_id = $message_id = 0;
$text = '';
$callBacktext = '';
$callback = '';

if (array_key_exists('message', $update)) {
    #$username = array_key_exists('username', $update['message']['from']) ? $update['message']['from']['username'] : null;
    $last_name = array_key_exists('last_name', $update['message']['from']) ? $update['message']['from']['last_name'] : null;
    $user_id = $update['message']['from']['id'];
    $chat_id = $update['message']['chat']['id'];
    $user_id = $chat_id; //since all bot messages are  private - chatid is the same as userid
    $message_id = $update['message']['message_id'];
    $text = $update['message']['text'];
    $first_name = $update['message']['from']['first_name'];
    //$caption = $update['message']['caption'];

}
if (array_key_exists('callback_query', $update)) {
    $callback = $update['callback_query']['id'];
    #$username = $update['callback_query']['from']['username'];
    $user_id = $update['callback_query']['message']['chat']['id'];
    $chat_id = $update['callback_query']['message']['chat']['id'];
    $message_id = $update['callback_query']['message']['message_id'];
    $first_name = $update['callback_query']['from']['first_name'];
    $callBacktext = $update['callback_query']['data'];
    $pieces = explode(":", $callBacktext);
    $cmd =  $pieces[0]; //options are skip or bad
    $text='callback';
    switch ($cmd) {
        case 'Bad' : {
            writeLog(4);
            $question = $pieces[1];
            recordAnswer($question, 3);
            // Show next question after reporting bad
            showNextQ();
        } break;

        case 'skip' : {
            writeLog(3);
            // Show next question after skip
            showNextQ();
        } break;

        case 'skipSQ' :{  //user did not want to answer SQ
            $question = $pieces[1]; //this is the user question skipped 
            
            $sql = "INSERT INTO user_survey(question_id, user_id, response) VALUES($question,$user_id, 0)";
            #bot_message($chat_id,$sql);
            $result = mysqli_query($db,$sql);
            writeLog(13);

            // Show next regular question after skipping survey
            showNextQ();
        } break;

        case 'Q' : {
            $answer = $pieces[3];
            $question = $pieces[1];
            $sql = "SELECT numofanswers, numofcorrectanswers from questions WHERE id=".$question;


            $result = mysqli_query($db,$sql);
            $row= mysqli_fetch_assoc($result);

            if ($row['numofanswers']==0) {
                $stat = "אף אחד עדיין לא ניסה לפתור את השאלה הזו";
                $percent = 0;
            } else {
                $percent = (100*$row['numofcorrectanswers']) / $row['numofanswers'];
            }

            if ($answer=="Correct") {
                writeLog(1,$pieces[1]);
                $ans = "תשובה נכונה";
                recordAnswer($pieces[1], 1);
                $stat = "אחוז התשובות הנכונות לשאלה זו הוא: ". round($percent,1)."%";
                $stat = "$ans \n $stat";

            } else {
                $ans = "טעות - התשובה הנכונה היא: ".$pieces[5];
                writeLog(2,$pieces[1]);
                recordAnswer($pieces[1], 2);
                $stat = "אחוז התשובות הנכונות לשאלה זו (לא כולל הפדיחה שלך) היה: ". round($percent,1)."%";
                $stat = "$ans \n $stat";
            }
            bot_message($chat_id,$stat);

            // Show next question automatically
            showNextQ();
        } break;

        case 'SQ' : { //user answered a survey question
            $answer = $pieces[3]; //this is the question_id answered
            $question = $pieces[1]; //this is the user response 
            
            $sql = "INSERT INTO user_survey(question_id, user_id, response) VALUES($question,$user_id,$answer)" ;
            $result = mysqli_query($db,$sql);
            $lastSQ=1;
            writeLog(12);
            $msg="תודה על התשובה הכנה - זה מאוד יעזור למחקר הנערך לגבי הבוט"; 
            bot_message($chat_id,$msg);

            // Show next regular question after survey
            showNextQ();
        } break;

        case 'menu_start': {
            // User clicked "Start Playing" from menu
            showNextQ();
        } break;


        case 'menu_leaderboard_all': {
            // User clicked "All-Time Leaderboard" from menu
            showLeaderboardAllTime();
        } break;

        case 'menu_leaderboard_monthly': {
            // User clicked "Monthly Leaderboard" from menu
            showLeaderboardMonthly();
        } break;

        case 'menu_leaderboard_weekly': {
            // User clicked "Weekly Leaderboard" from menu
            showLeaderboardWeekly();
        } break;

        case 'menu_back': {
            // User clicked "Back to Menu" button
            showMainMenu($chat_id);
        } break;


        default : {

        } break;
    }
}