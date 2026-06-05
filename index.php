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

    // Cohort onboarding gate: after nickname, require a group selection.
    // Text path only — callbacks are gated in variable_setup.php. No-op while
    // settings.cohort_gate_enabled is off, and a no-op for any user who already
    // has a cohort (i.e. every existing user), so they are never blocked.
    if ($text !== 'callback' && !checkCohortRequired($user_id, $chat_id)) {
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
        writeLog(6); // Start
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
        writeLog(19); // MenuCommand
        showMainMenu($chat_id);
    } break;

    case '/group':
    case '/קבוצה': {
        writeLog(35); // MenuChangeGroup
        showCohortPicker($chat_id, false);
    } break;

    case '/stats' : {
        writeLog(5); // Stat
        $safe_uid = intval($user_id);

        $query = "SELECT COUNT(*) AS totalAnswered, COALESCE(SUM(numofsuccess),0) AS okCount, COALESCE(SUM(numoffailure),0) AS wrongCount FROM user_q WHERE userid = $safe_uid";
        $result = mysqli_query($db, $query);
        $row = mysqli_fetch_assoc($result);
        $uniqueQuestionsSeen = intval($row['totalAnswered']);
        $ok = intval($row['okCount']);
        $wrong = intval($row['wrongCount']);
        $totalAttempts = $ok + $wrong;

        $rlm = "\u{200F}";
        $lri = "\u{2066}"; $pdi = "\u{2069}";

        $msg  = $rlm . "📊 הסטטיסטיקות שלך\n\n";
        $msg .= $rlm . "📝 שאלות שנחשפת אליהן: {$lri}{$uniqueQuestionsSeen}{$pdi}\n";
        if ($totalAttempts > 0) {
            $percent = round(100 * $ok / $totalAttempts, 0);
            $msg .= $rlm . "🎯 אחוז הצלחה: {$lri}{$percent}%{$pdi}\n";
        } else {
            $msg .= $rlm . "🎯 אחוז הצלחה: —\n";
        }
        $msg .= $rlm . "🆔 מספר משתמש בטלגרם: {$lri}{$safe_uid}{$pdi}\n\n";
        $msg .= $rlm . "━━━━━━━━━━━━━━\n";
        $msg .= $rlm . "💡 רוצה להתחיל מחדש? שלח /clearstats — ההיסטוריה תאופס (התגים והנקודות יישמרו).";

        bot_message($chat_id, $msg);
        showNextQ();
    } break;

    case '/clearstats' : {
        writeLog(31); // ClearStatsRequest
        // Show confirmation prompt rather than acting immediately — destructive action.
        $rlm = "\u{200F}";
        $msg  = $rlm . "⚠️ האם אתה בטוח שברצונך לאפס את ההיסטוריה?\n\n";
        $msg .= $rlm . "יאופס:\n";
        $msg .= $rlm . "• היסטוריית התשובות לכל שאלה\n";
        $msg .= $rlm . "• הרמה הנוכחית (חזרה לשלב 1)\n";
        $msg .= $rlm . "• הציון בתוך השלב (חזרה ל-0)\n\n";
        $msg .= $rlm . "יישמרו:\n";
        $msg .= $rlm . "• התגים שצברת 🏅\n";
        $msg .= $rlm . "• הנקודות הכלליות 🏆";

        $markup = ['inline_keyboard' => [[
            ['text' => '✅ כן, אפס',  'callback_data' => 'clearstats_confirm'],
            ['text' => '❌ ביטול',      'callback_data' => 'clearstats_cancel'],
        ]]];

        bot_message($chat_id, $msg, $markup);
    } break;

    case '/level' : {
        writeLog(8); // Level
        $safe_uid = intval($user_id);
        $query = "select level,current_run from users where id=$safe_uid";
        $result = mysqli_query($db, $query);
        $fetch = mysqli_fetch_assoc($result);
        $level = intval($fetch['level']);
        $currentRun = intval($fetch['current_run']);

        // Threshold for next-level progress line
        $upgradeAt = null;
        $gRes = mysqli_query($db, "SELECT upgrade_at FROM gamification WHERE level = " . intval($level));
        if ($gRes && mysqli_num_rows($gRes) > 0) {
            $gRow = mysqli_fetch_assoc($gRes);
            $upgradeAt = intval($gRow['upgrade_at']);
            mysqli_free_result($gRes);
        }

        $rlm = "\u{200F}";
        // LRI/PDI isolate numeric tokens so minus + digits stay as "-1", not "1-"
        $lri = "\u{2066}"; $pdi = "\u{2069}";
        $msg  = $rlm . "📊 הרמה שלך\n\n";
        $msg .= $rlm . "🎯 רמה נוכחית: {$lri}{$level}{$pdi}\n";
        $msg .= $rlm . "📈 ציון בתוך השלב: {$lri}{$currentRun}{$pdi}\n";

        // Progress-to-next-level line. Level 4 is the cap (upgrade_at is set to a
        // large sentinel value in the gamification table), so treat it as "maxed".
        if ($level >= 4) {
            $msg .= $rlm . "🏆 אתה ברמה הגבוהה ביותר!\n\n";
        } elseif ($upgradeAt !== null) {
            $needed = max(0, $upgradeAt - $currentRun);
            $next = $level + 1;
            $msg .= $rlm . "🚀 עוד {$lri}{$needed}{$pdi} תשובות נכונות לעלייה לשלב {$lri}{$next}{$pdi}\n\n";
        } else {
            $msg .= "\n";
        }

        $msg .= $rlm . "━━━━━━━━━━━━━━\n";
        $msg .= $rlm . "הסבר על הרמות:\n";
        $msg .= $rlm . "1️⃣ כל השאלות רנדומליות וקלות\n";
        $msg .= $rlm . "2️⃣ כל השאלות רנדומליות ורמת קושי בינונית\n";
        $msg .= $rlm . "3️⃣ שאלות חדשות ברמת קושי בינונית\n";
        $msg .= $rlm . "4️⃣ שאלות חדשות ברמת קושי גבוהה";
        bot_message($chat_id, $msg);
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
        writeLog(30); // NicknameChangeRequest
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