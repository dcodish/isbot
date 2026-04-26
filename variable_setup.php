<?php

require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/bot_functions.php';
require_once __DIR__ . '/admin/backend/database.php';
global $db, $lastSQ;

$input = file_get_contents('php://input');
if (DEBUG==="ON") {
    file_put_contents(RESULT_LOG_PATH, $input . PHP_EOL . PHP_EOL, FILE_APPEND);
}


$update = json_decode($input, true);
// ensure an array to avoid TypeError
if (!is_array($update)) {
    $update = [];
}

// Extract update_id for deduplication
$update_id = isset($update['update_id']) ? intval($update['update_id']) : 0;

// Check if we already processed this update
if ($update_id > 0) {
    $safe_update_id = intval($update_id);
    $check_query = "SELECT 1 FROM processed_updates WHERE update_id = $safe_update_id LIMIT 1";
    $check_result = mysqli_query($db, $check_query);

    if ($check_result && mysqli_num_rows($check_result) > 0) {
        // Already processed this update
        mysqli_free_result($check_result);
        http_response_code(200);
        echo 'OK';
        mysqli_close($db);
        exit;
    }
    if ($check_result) mysqli_free_result($check_result);

    // Mark this update as processed
    $insert_query = "INSERT INTO processed_updates (update_id, processed_at) VALUES ($safe_update_id, NOW())";
    mysqli_query($db, $insert_query);
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
    $text = $update['message']['text'] ?? '';
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
}

// Make sure the user has a row in `users` before any handler runs. Stale
// buttons in the chat can deliver callbacks before the user ever sends /start;
// without this, recordAnswer / writeLog / etc. would hit a foreign-key fatal
// (user_q.fk_user_userq, badge_progress.fk_user_badge, …). Idempotent.
if ($user_id > 0 && $chat_id > 0) {
    ensureUserExists($user_id, $first_name ?? '', $last_name ?? '');
}

// Session boundary check: if the user returns after a gap, wipe the question
// messages from their previous session before handling the new interaction.
if ($user_id > 0 && $chat_id > 0) {
    maybeStartNewSession($user_id, $chat_id);
}

if (isset($update['callback_query'])) {
    $pieces = explode(":", $callBacktext);
    $cmd = $pieces[0];
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

            $was_first_answer = ($row['numofanswers'] == 0);
            $percent = $was_first_answer ? 0 : (100 * $row['numofcorrectanswers']) / $row['numofanswers'];

            if ($answer=="Correct") {
                writeLog(1,$pieces[1]);
                $ans = "תשובה נכונה";
                $badgeCheck = recordAnswer($pieces[1], 1);
                if ($was_first_answer) {
                    $stat = "$ans \n אתה הראשון לענות על השאלה הזו!";
                } else {
                    $stat = "$ans \n אחוז התשובות הנכונות לשאלה זו הוא: ". round($percent,1)."%";
                }
            } else {
                $ans = "טעות - התשובה הנכונה היא: ".$pieces[5];
                writeLog(2,$pieces[1]);
                $badgeCheck = recordAnswer($pieces[1], 2);
                if ($was_first_answer) {
                    $stat = "$ans \n אתה הראשון לענות על השאלה הזו";
                } else {
                    $stat = "$ans \n אחוז התשובות הנכונות לשאלה זו (לא כולל הפדיחה שלך) היה: ". round($percent,1)."%";
                }
            }

            // Send answer feedback FIRST
            bot_message($chat_id,$stat);

            // NOW check badges AFTER answer message
            if ($badgeCheck === 'check_correct_badges') {
                $badgeService = new BadgeService($db, $user_id, $chat_id);
                $badgeService->checkCorrectAnswerBadges();

                // Also check level badges if user leveled up
                $levelUpQuery = "SELECT level FROM users WHERE id = $user_id";
                $levelResult = mysqli_query($db, $levelUpQuery);
                if ($levelResult && mysqli_num_rows($levelResult) > 0) {
                    $levelRow = mysqli_fetch_assoc($levelResult);
                    $currentLevel = $levelRow['level'];
                    mysqli_free_result($levelResult);

                    // Check if this was a level up moment (current_run would be 0)
                    $runQuery = "SELECT current_run FROM users WHERE id = $user_id";
                    $runResult = mysqli_query($db, $runQuery);
                    if ($runResult && mysqli_num_rows($runResult) > 0) {
                        $runRow = mysqli_fetch_assoc($runResult);
                        if ($runRow['current_run'] == 0) {
                            // Just leveled up, check level badges
                            $badgeService->checkLevelBadge($currentLevel);
                            $badgeService->checkComebackBadge();
                        }
                        mysqli_free_result($runResult);
                    }
                }
            } elseif ($badgeCheck === 'check_wrong_badges') {
                $badgeService = new BadgeService($db, $user_id, $chat_id);
                $badgeService->checkWrongAnswerBadges();
            }

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
            writeLog(20); // MenuStart
            showNextQ();
        } break;


        case 'menu_leaderboard': {
            writeLog(22); // MenuLeaderboardRoot
            showLeaderboardMenu();
        } break;

        case 'menu_leaderboard_all': {
            writeLog(23); // MenuLeaderboardAll
            showLeaderboardAllTime();
        } break;

        case 'menu_leaderboard_monthly': {
            writeLog(25); // MenuLeaderboardMonthly
            showLeaderboardMonthly();
        } break;

        case 'menu_leaderboard_weekly': {
            writeLog(24); // MenuLeaderboardWeekly
            showLeaderboardWeekly();
        } break;

        case 'menu_badges': {
            writeLog(21); // MenuBadges
            showBadgesRoom();
        } break;

        case 'menu_back': {
            writeLog(26); // MenuBack
            showMainMenu($chat_id);
        } break;

        case 'clearstats_confirm': {
            writeLog(32); // ClearStatsConfirm
            $safe_uid = intval($user_id);
            mysqli_query($db, "DELETE FROM user_q WHERE userid = $safe_uid");
            mysqli_query($db, "UPDATE users SET level = 1, current_run = 0 WHERE id = $safe_uid");
            $rlm = "\u{200F}";
            bot_message($chat_id, $rlm . "✅ ההיסטוריה אופסה. חזרת לשלב 1 — התגים והנקודות שלך נשמרו.");
        } break;

        case 'clearstats_cancel': {
            writeLog(33); // ClearStatsCancel
            $rlm = "\u{200F}";
            bot_message($chat_id, $rlm . "הפעולה בוטלה. ההיסטוריה נשמרה.");
        } break;


        default : {

        } break;
    }
}