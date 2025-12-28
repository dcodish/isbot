<?php

// variable_setup.php already includes config.php, bot_functions.php, and admin/backend/database.php
include 'variable_setup.php';
global $db, $chat_id, $user_id;

#$user_id="871736308";

/////////////////////////////////////////////////////////////////////////////////////
///                             BOT logic                                       /////
/// /////////////////////////////////////////////////////////////////////////////////

switch ($text) {

    case '/start': {
        $query = "SELECT * FROM users WHERE id=" . $user_id;
        $result = mysqli_query($db, $query);
        $num = mysqli_num_rows($result);
        if ($num == 0) {
            $query = "INSERT INTO users (id,first_name,last_name, level, CurrentRun) VALUES ('" . $user_id . "','" . $first_name . "','" . $last_name . "', 1,1)";
            bot_message($chat_id,$query);
            mysqli_query($db, $query);
        }
        showNextQ();
        

    } break;

    case '/stat' : {

        //get total number of questions in bank

        $query = "SELECT count(id) as totalQ FROM questions";
        $result = mysqli_query($db, $query);
        $row= mysqli_fetch_assoc($result);
        $totalQuestionsInBank = $row['totalQ'];

        //get total number of questions the user tried to answer, success and failures
        $query = "SELECT count(*) as totalAnswered, sum(numofsuccess) as successfullAnswers, sum(numoffailure) as failedAnswers FROM user_q WHERE userid=" . $user_id;
        $result = mysqli_query($db, $query);
        $row= mysqli_fetch_assoc($result);
        $totalQuestionsAsked = 0;
        $totalSuccess =  0;
        $totalFailure =  0;
        if ($row['totalAnswered'] == 0) {
            $totalQuestionsAsked = 1;
            $totalSuccess =  0;
            $totalFailure =  0;
        }
        else {
            $totalQuestionsAsked = $row['totalAnswered'];
            $totalSuccess =  $row['successfullAnswers'];
            $totalFailure =  $row['failedAnswers'];
        }


        //get total number of questions
        bot_message($chat_id,'מספר שאלות שנחשפת אליהם עד כה הוא: '.$totalQuestionsAsked);
        $percent = 100*$totalSuccess/($totalFailure+$totalSuccess);
        bot_message($chat_id,'אחוז ההצלחה שלך הוא: '.round($percent,0)."%");
        bot_message($chat_id,'מספר משתמש בטלגרם: '.$user_id);
        showNextQ();
    } break;

    case '/clearstat' : {
        $query = "delete from user_q where userid=" . $user_id;
        $result = mysqli_query($db, $query);
        $query = "update users set level=1 where id=" . $user_id;
        $result = mysqli_query($db, $query);
        $query = "update users set CurrentRun=0 where id=" . $user_id;
        $result = mysqli_query($db, $query);
        bot_message($chat_id,"כל הסטוריית התשובות שלך נמחקה וחזרת לשלב 1");
    } break;

    case '/level' : {
        $query = "select level,CurrentRun from users where id=" . $user_id;
        $result = mysqli_query($db, $query);
        $fetch = mysqli_fetch_assoc($result);
        $level = $fetch['level'];
        $currentRun = $fetch['CurrentRun'];
        bot_message($chat_id,"השלב הנוכחי הוא : ".$level." והציון בתוך השלב הוא: ".$currentRun);

        bot_message($chat_id,"שלב 1 - כל השאלות רנדומליות וקלות");
        bot_message($chat_id,"שלב 2 - כל השאלות רנדומליות ורמת קושי בינונית");
        bot_message($chat_id,"שלב 3 - שאלות חדשות רמת קושי בינונית");
        bot_message($chat_id,"שלב 4 - שאלות חדשות רמת קושי גבוהה");
        showNextQ();
    } break;

   
    default :{
        showNextQ();
    }
 }
http_response_code(200);
echo 'OK';
mysqli_close($db);

?>