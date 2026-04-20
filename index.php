<?php

// variable_setup.php already includes bootstrap/app.php, bot_functions.php, and admin/backend/database.php
include 'variable_setup.php';
global $db, $chat_id, $user_id;

#$user_id="871736308";

/////////////////////////////////////////////////////////////////////////////////////
///                    NICKNAME CHECK - MUST HAPPEN FIRST                       /////
/// /////////////////////////////////////////////////////////////////////////////////

// Check if user is awaiting nickname input
if (isAwaitingNickname($user_id)) {
    // User is in "waiting for nickname" state
    // Process their message as nickname input
    handleNicknameInput($user_id, $chat_id, $text);

    // Exit - don't process any commands until nickname is set
    http_response_code(200);
    echo 'OK';
    mysqli_close($db);
    exit;
}

// Check if user needs to set a nickname (only for existing users)
$query = "SELECT * FROM users WHERE id=" . $user_id;
$result = mysqli_query($db, $query);
$num = mysqli_num_rows($result);

if ($num > 0) {
    // User exists - check if they have a nickname
    if (!checkNicknameRequired($user_id, $chat_id)) {
        // User doesn't have nickname and was just asked for it
        // Exit - wait for nickname input
        http_response_code(200);
        echo 'OK';
        mysqli_close($db);
        exit;
    }
}
// If user doesn't exist yet, they'll be created in /start command
// and will be asked for nickname on next interaction

/////////////////////////////////////////////////////////////////////////////////////
///                             BOT logic                                       /////
/// /////////////////////////////////////////////////////////////////////////////////

switch ($text) {

    case '/start': {
        $query = "SELECT * FROM users WHERE id=" . $user_id;
        $result = mysqli_query($db, $query);
        $num = mysqli_num_rows($result);

        if ($num == 0) {
            // New user - create account
            $query = "INSERT INTO users (id,first_name,last_name, level, current_run) VALUES ('" . $user_id . "','" . $first_name . "','" . $last_name . "', 1,1)";
            mysqli_query($db, $query);

            // Ask for nickname immediately for new users
            setAwaitingNickname($user_id, true);
            askForNickname($chat_id);

            // Exit and wait for nickname input
            http_response_code(200);
            echo 'OK';
            mysqli_close($db);
            exit;
        }

        // Existing user - check if they have nickname
        if (!checkNicknameRequired($user_id, $chat_id)) {
            // User doesn't have nickname yet
            http_response_code(200);
            echo 'OK';
            mysqli_close($db);
            exit;
        }

        // User has nickname - show menu
        showMainMenu($chat_id);

    } break;

    case '/menu':
    case '/תפריט': {
        // Show main menu
        showMainMenu($chat_id);
    } break;

    case '/stats' : {

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

    case '/clearstats' : {
        $query = "delete from user_q where userid=" . $user_id;
        $result = mysqli_query($db, $query);
        $query = "update users set level=1 where id=" . $user_id;
        $result = mysqli_query($db, $query);
        $query = "update users set current_run=0 where id=" . $user_id;
        $result = mysqli_query($db, $query);
        bot_message($chat_id,"כל הסטוריית התשובות שלך נמחקה וחזרת לשלב 1");
    } break;

    case '/level' : {
        $query = "select level,current_run from users where id=" . $user_id;
        $result = mysqli_query($db, $query);
        $fetch = mysqli_fetch_assoc($result);
        $level = $fetch['level'];
        $currentRun = $fetch['current_run'];
        bot_message($chat_id,"השלב הנוכחי הוא : ".$level." והציון בתוך השלב הוא: ".$currentRun);

        bot_message($chat_id,"שלב 1 - כל השאלות רנדומליות וקלות");
        bot_message($chat_id,"שלב 2 - כל השאלות רנדומליות ורמת קושי בינונית");
        bot_message($chat_id,"שלב 3 - שאלות חדשות רמת קושי בינונית");
        bot_message($chat_id,"שלב 4 - שאלות חדשות רמת קושי גבוהה");
        showNextQ();
    } break;

    case '/leaderboard':
    case '/leaderboard_all':
    case '/טבלה': {
        // Show all-time leaderboard
        showLeaderboardAllTime();
    } break;

    case '/leaderboard_weekly':
    case '/טבלה_שבועי': {
        // Show weekly leaderboard
        showLeaderboardWeekly();
    } break;

    case '/leaderboard_monthly':
    case '/טבלה_חודשי': {
        // Show monthly leaderboard
        showLeaderboardMonthly();
    } break;

    case '/changenickname' : {
        // Allow user to change their nickname
        setAwaitingNickname($user_id, true);
        $message = "🔄 *שינוי כינוי*\n\n";
        $message .= "אנא שלח את הכינוי החדש שלך:\n";
        $message .= "(3-20 תווים, אותיות אנגליות, מספרים וקו תחתון בלבד)";
        bot_message($chat_id, $message);
    } break;

   
    default :{
        // Only show question if this is NOT a callback
        // Callbacks are already handled in variable_setup.php
        if ($text !== 'callback') {
            showNextQ();
        }
    }
 }
http_response_code(200);
echo 'OK';
mysqli_close($db);

?>