<?php


function NewBot($chatID, $messaggio, $markup=null) {
    /*echo "sending message to " . $chatID . "\n";*/
    global $API_URL; // uses TOKEN from config.php
    $url = $API_URL . "sendMessage?chat_id=" . intval($chatID);


    if ($markup !== null) {
        $url .= "&text=" . urlencode($messaggio) . "&reply_markup=" . urlencode(json_encode($markup));
    } else {
        $url .= "&text=" . urlencode($messaggio);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8
    ]);
    $result = curl_exec($ch);
    usleep(50000);
    return $result;
}






function bot($data){
    global $API_URL;
    $ch = curl_init($API_URL.$data);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false  // Disable SSL verification for local testing
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        error_log('Telegram call failed: '.$API_URL.$data . ' - Error: ' . curl_error($ch));
        return null;
    }
    return json_decode($res, true);
}

function bot_message($chat_id, $msg, $markup = null) {
    // URL encode the message to handle special characters including newlines
    $msg = urlencode($msg);

    if ($markup != null) {
        $markup = json_encode($markup);
        $markup = urlencode($markup);
        return bot("sendMessage?chat_id=".$chat_id."&text=".$msg."&reply_markup=".$markup);
    } else {
        return bot("sendMessage?chat_id=".$chat_id."&text=".$msg);
    }
}


/**
 * Show main menu with inline keyboard buttons
 */
function showMainMenu($chat_id) {
    global $user_id, $db;

    // Get user stats
    $safe_user_id = intval($user_id);
    $query = "SELECT nickname, overall_points FROM users WHERE id = $safe_user_id";
    $result = mysqli_query($db, $query);

    $nickname = "Guest";
    $points = 0;

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $nickname = $row['nickname'] ? $row['nickname'] : "Guest";
        $points = $row['overall_points'] ? $row['overall_points'] : 0;
        mysqli_free_result($result);
    }

    $message = "🎯 תפריט ראשי\n\n";
    $message .= "שלום {$nickname}! 👋\n";
    $message .= "צברת עד כה  {$points} נקודות 🏆\n\n";
    $message .= "בחר פעולה:";

    // Create inline keyboard with menu options
    $keyboard = array(
        array(
            array('text' => '🎮 התחל לשחק', 'callback_data' => 'menu_start'),
        ),
        array(
            array('text' => '🏅 אוסף התגים שלי', 'callback_data' => 'menu_badges'),
        ),
        array(
            array('text' => '🏆 טבלת מובילים - כל הזמנים', 'callback_data' => 'menu_leaderboard_all'),
        ),
        array(
            array('text' => '📆 טבלת מובילים - חודשי', 'callback_data' => 'menu_leaderboard_monthly'),
        ),
        array(
            array('text' => '📅 טבלת מובילים - שבועי', 'callback_data' => 'menu_leaderboard_weekly'),
        ),
    );

    $markup = array('inline_keyboard' => $keyboard);

    // Send message (using regular bot_message for compatibility)
    bot_message($chat_id, $message, $markup);
}

/**
 * Send message with markdown parsing support
 */
function bot_message_with_markdown($chat_id, $msg, $markup = null) {
    global $API_URL;

    $msg = urlencode($msg);
    $url = "sendMessage?chat_id=".$chat_id."&text=".$msg."&parse_mode=Markdown";

    if ($markup != null) {
        $markup = json_encode($markup);
        $markup = urlencode($markup);
        $url .= "&reply_markup=".$markup;
    }

    return bot($url);
}

function forwardMessage($user_id,$message_id,$from_chat_id){

    bot("forwardMessage?chat_id=".$user_id."&from_chat_id=".$from_chat_id."&message_id=".$message_id);
}

function editMessage($chat_id,$message_id,$msg){

    bot("editMessageText?chat_id=".$chat_id."&message_id=".$message_id."&text=".$msg);
}

function photo($chat_id,$photo_link,$caption=null){
    bot("sendPhoto?chat_id=".$chat_id."&photo=".$photo_link."&caption=".$caption);
}

function video($chat_id,$video_link,$caption=null){
    bot("sendVideo?chat_id=".$chat_id."&video=".$video_link."&caption=".$caption);
}

function send_file($chat_id,$file_id,$caption=null){
    bot("sendDocument?chat_id=".$chat_id."&document=".$file_id."&caption=".$caption);
}

function action($chat_id,$action){
    bot("sendChatAction?chat_id=".$chat_id."&action=".$action);
}

function answer_query($query_id,$text,$show_alert=false){
    bot("answerCallbackQuery?callback_query_id=".$query_id."&text=".$text."&show_alert=".$show_alert);
}

function sendDocument($chat_id, $caption, $title_id) {
    bot("sendDocument?chat_id=".$chat_id."&document=".$title_id."&caption=".$caption);
}


function writeLog ($op, $additional=0) {
    global $db, $chat_id, $user_id; 
    $query="insert into log(userid,action_type,additional_value) VALUES('$user_id',$op,$additional)";
    mysqli_query($db, $query);
    
    return true;
}

/////////////////////////////////////////////////////////////////////////
///                    DB functions                                  ////
/// /////////////////////////////////////////////////////////////////////


/**
 * Helper function: Build SQL condition for success rate threshold for a given difficulty.
 * Returns SQL WHERE clause fragment to check success_rate for the specified difficulty and threshold.
 * Handles division-by-zero by checking numofanswers > 0.
 */
function buildSuccessRateCondition($difficulty, $operator, $threshold) {
    // Ensure difficulty is an integer to prevent SQL injection
    $diff = intval($difficulty);
    $thresh = floatval($threshold);

    return "difficulty = $diff AND numofanswers > 0 AND (numofcorrectanswers / numofanswers) $operator $thresh";
}

/**
 * Helper function: Build exclusion clause for already-seen questions.
 * Returns SQL fragment to exclude questions in user_q for the given user.
 */
function buildExclusionClause($user_id) {
    $uid = intval($user_id);
    return "id NOT IN (SELECT questionid FROM user_q WHERE userid = $uid)";
}

/**
 * Helper function: Execute a question query with optional exclusion.
 * Returns the result resource or null if no rows found.
 * Frees the result if empty.
 */
function executeQuestionQuery($db, $query, $debug_label = "") {
    // Optional debug logging (disabled by default, uncomment to enable)
    // error_log("[$debug_label] SQL: $query");

    $result = mysqli_query($db, $query);
    $num = mysqli_num_rows($result);

    if ($num == 0) {
        mysqli_free_result($result);
        return null;
    }

    return $result;
}

function getQuestion() {
    global $db, $user_id, $chat_id;

    // Sanitize user_id to prevent SQL injection
    $safe_user_id = intval($user_id);

    // Get user level
    $query = "SELECT level FROM users WHERE id = $safe_user_id";
    $result = mysqli_query($db, $query);
    $fetch = mysqli_fetch_assoc($result);
    $level = isset($fetch['level']) ? intval($fetch['level']) : 1;
    mysqli_free_result($result);

    $query = null;
    $question_result = null;

    // Implement 4-level logic with probability buckets (IGNORING difficulty field - using only success rate)
    switch ($level) {
        case 1: {
            // Level 1: success_rate >= 0.80, CAN repeat
            $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.80 ORDER BY RAND() LIMIT 1";
            $question_result = executeQuestionQuery($db, $query, "L1-main");
        } break;

        case 2: {
            // Level 2: 70% (rate 70-80% EXCLUSIVE), 30% (rate >=80%), CAN repeat
            $rand = rand(1, 100);

            if ($rand <= 70) {
                // 70%: success_rate 70-80% (EXCLUSIVE range for Level 2)
                $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.7 AND (numofcorrectanswers / numofanswers) < 0.8 ORDER BY RAND() LIMIT 1";
                $question_result = executeQuestionQuery($db, $query, "L2-70-80-exclusive");
            } else {
                // 30%: success_rate >= 0.80 (Level 1 range)
                $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.8 ORDER BY RAND() LIMIT 1";
                $question_result = executeQuestionQuery($db, $query, "L2-80+");
            }

            // Fallback within level 2: try the other bucket
            if ($question_result === null) {
                if ($rand <= 70) {
                    // Tried 70-80%, now try ≥80%
                    $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.8 ORDER BY RAND() LIMIT 1";
                    $question_result = executeQuestionQuery($db, $query, "L2-fallback-80+");
                } else {
                    // Tried ≥80%, now try 70-80%
                    $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.7 AND (numofcorrectanswers / numofanswers) < 0.8 ORDER BY RAND() LIMIT 1";
                    $question_result = executeQuestionQuery($db, $query, "L2-fallback-70-80");
                }
            }
        } break;

        case 3: {
            // Level 3: 50% (rate 60-70% EXCLUSIVE), 50% (70-80% or >=80%), NO repeat
            $exclusion = buildExclusionClause($safe_user_id);
            $rand = rand(1, 100);

            if ($rand <= 50) {
                // 50%: success_rate 60-70% (EXCLUSIVE range for Level 3)
                $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.6 AND (numofcorrectanswers / numofanswers) < 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                $question_result = executeQuestionQuery($db, $query, "L3-60-70-exclusive");
            } else {
                // 50%: ≥70% (Level 1 or Level 2 ranges)
                $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                $question_result = executeQuestionQuery($db, $query, "L3-70+-review");
            }

            // Fallback within level 3: try the other bucket
            if ($question_result === null) {
                if ($rand <= 50) {
                    // Tried 60-70%, now try ≥70%
                    $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                    $question_result = executeQuestionQuery($db, $query, "L3-fallback-70+");
                } else {
                    // Tried ≥70%, now try 60-70%
                    $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.6 AND (numofcorrectanswers / numofanswers) < 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                    $question_result = executeQuestionQuery($db, $query, "L3-fallback-60-70");
                }
            }
        } break;

        case 4: {
            // Level 4: 50% (rate<60% EXCLUSIVE), 20% (rate 60-70%), 30% (rate ≥70%), NO repeat
            $exclusion = buildExclusionClause($safe_user_id);
            $rand = rand(1, 100);

            if ($rand <= 50) {
                // 50%: success_rate < 60% (EXCLUSIVE range for Level 4 - HARDEST)
                $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) < 0.6 AND $exclusion ORDER BY RAND() LIMIT 1";
                $question_result = executeQuestionQuery($db, $query, "L4-<60-hardest");
            } elseif ($rand <= 70) {
                // 20%: success_rate 60-70% (Level 3 range)
                $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.6 AND (numofcorrectanswers / numofanswers) < 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                $question_result = executeQuestionQuery($db, $query, "L4-60-70");
            } else {
                // 30%: success_rate ≥70% (Level 1 or Level 2 ranges)
                $query = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                $question_result = executeQuestionQuery($db, $query, "L4-70+-review");
            }

            // Fallback within level 4: try other buckets in order
            if ($question_result === null) {
                // Build array of fallback queries for remaining buckets
                $fallback_queries = array();

                if ($rand <= 50) {
                    // Tried <60%, try 60-70% then ≥70%
                    $fallback_queries[] = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.6 AND (numofcorrectanswers / numofanswers) < 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                    $fallback_queries[] = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                } elseif ($rand <= 70) {
                    // Tried 60-70%, try <60% then ≥70%
                    $fallback_queries[] = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) < 0.6 AND $exclusion ORDER BY RAND() LIMIT 1";
                    $fallback_queries[] = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                } else {
                    // Tried ≥70%, try <60% then 60-70%
                    $fallback_queries[] = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) < 0.6 AND $exclusion ORDER BY RAND() LIMIT 1";
                    $fallback_queries[] = "SELECT * FROM questions WHERE numofanswers > 0 AND (numofcorrectanswers / numofanswers) >= 0.6 AND (numofcorrectanswers / numofanswers) < 0.7 AND $exclusion ORDER BY RAND() LIMIT 1";
                }

                // Try each fallback query
                foreach ($fallback_queries as $fq) {
                    $question_result = executeQuestionQuery($db, $fq, "L4-fallback");
                    if ($question_result !== null) {
                        break;
                    }
                }
            }
        } break;

        default: {
            // Default fallback: any question
            $query = "SELECT * FROM questions ORDER BY RAND() LIMIT 1";
            $question_result = executeQuestionQuery($db, $query, "default");
        } break;
    }

    // Final fallback: if still no question, pick ANY question from the database
    if ($question_result === null) {
        $query = "SELECT * FROM questions ORDER BY RAND() LIMIT 1";
        $question_result = mysqli_query($db, $query);
    }

    // Fetch the question data
    $fetch = mysqli_fetch_assoc($question_result);
    mysqli_free_result($question_result);

    // Debug logging - uncomment to troubleshoot
    error_log("[DEBUG] Level: $level | Selected Question ID: " . $fetch['id'] . " | Difficulty: " . $fetch['difficulty']);

    $correct = $fetch['correctans'];
    $answers = array($fetch['option1'],$fetch['option2'],$fetch['option3'],$fetch['option4']);

    $ansLocations = array(1, 2, 3, 4);
    shuffle($ansLocations);
    $newCorrectAns = array_search($correct, $ansLocations)+1;
    $q_text = $fetch['question_text'];


    $rtl_start = "\u{202B}";
    $rtl_end = "\u{202C}";
    $message = "1. " . $answers[$ansLocations[0] - 1];
    $message_rtl = $rtl_start . $message . $rtl_end;
    
    $q1 = "1. ".$answers[$ansLocations[0]-1];
    $q2 = "2. ".$answers[$ansLocations[1]-1];
    $q3 = "3. ".$answers[$ansLocations[2]-1];
    $q4 = "4. ".$answers[$ansLocations[3]-1];
    $q = "$q_text \n$q1 \n$q2 \n$q3 \n$q4 \n .";
    bot_message($chat_id, $q );
    
    $ar = array();
    $qid = $fetch['id'];



    if ($newCorrectAns == 1) {
        $ans1 = "Q:" . $qid. ":A:Correct";
    } else {
        $ans1 = "Q:" . $qid. ":A:1:C:".$newCorrectAns;
    }

    if ($newCorrectAns == 2) {
        $ans2 = "Q:" . $qid. ":A:Correct";
    } else {
        $ans2 = "Q:" . $qid. ":A:2:C:".$newCorrectAns;
    }
    if ($newCorrectAns == 3) {
        $ans3 = "Q:" . $qid. ":A:Correct";
    } else {
        $ans3 = "Q:" . $qid. ":A:3:C:".$newCorrectAns;
    }
    if ($newCorrectAns == 4) {
        $ans4 = "Q:" . $qid. ":A:Correct";
    } else {
        $ans4 = "Q:" . $qid. ":A:4:C:".$newCorrectAns;
    }
    $badQ = "Bad:".$qid;

    $ar11 = array(array('text' => '4', 'callback_data' => $ans4));
    array_push($ar11, array('text' => '3', 'callback_data' => $ans3));
    array_push($ar11, array('text' => '2', 'callback_data' => $ans2));
    array_push($ar11, array('text' => '1', 'callback_data' => $ans1));
    $ar31 = array(array('text' => 'דלג שאלה', 'callback_data' => 'skip'));
    $ar21 = array(array('text' => 'סמן כשאלה לא ברורה', 'callback_data' => $badQ));


    array_push($ar, $ar11);
    array_push($ar, $ar21);
    array_push($ar, $ar31);

    $markup = array('inline_keyboard' => $ar);
    bot_message($chat_id, 'מה התשובה הכי נכונה?', $markup);

    return true;
}


function getSurveyQuestion() {
    global $chat_id, $user_id, $db, $lastSQ;
    $qtd = GetQsoFar();
    #bot_message($chat_id," qtd $qtd");
    // SQL query to find the appropriate question


    // get list of all survey questions that can be asked by qtd sorted by showafter
    // get list of questions that no longer need to be asked : exist in us and repeated=0
    // remove those items from the original list and no there is a list of possible questions
    // if there is a repeated = 0 question in the list, show it
    // for each question, multiply  the amount of times it was previously asked by showfter+1. 
    //      if qtd>this number, this question can be asked again

    $query = "SELECT question_id, repeated FROM survey_questions WHERE showafter < $qtd ORDER BY showafter";
    #bot_message($chat_id,"q: $query");
    
    $result = mysqli_query($db, $query);
    $goodquestion_ids = array();
    $repeated_questions = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $goodquestion_ids[] = $row['question_id'];
        // Store repeated status for potential future use
        $repeated_questions[$row['question_id']] = $row['repeated'];
    }
    

    // Fetch badquestion_ids
    $query = "SELECT question_id FROM survey_questions WHERE repeated = 0 AND question_id IN 
                (SELECT question_id FROM user_survey WHERE user_id = $user_id)";
    $result = mysqli_query($db, $query);
    $badquestion_ids = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $badquestion_ids[] = $row['question_id'];
    }
    $final_question_ids = array_diff($goodquestion_ids, $badquestion_ids);
    $first_repeated_zero = null;
    foreach ($final_question_ids as $question_id) {
        if (isset($repeated_questions[$question_id]) && $repeated_questions[$question_id] == 0) {
            $first_repeated_zero = $question_id;
            break;  // Stop after finding the first one
        }
    }
    if ($first_repeated_zero !== null) {
        $question_id_to_ask=$first_repeated_zero;
        $query = "SELECT * FROM survey_questions WHERE question_id=$question_id_to_ask";
    } else {
        // there are no new questions to ask but some can be asked that are in the goodlist
        $min_modulo = PHP_INT_MAX;
        $selected_question_id = null;
        foreach ($final_question_ids as $question_id) {
            $subquery = "SELECT sq.showafter, 
                        (SELECT COUNT(*) 
                            FROM user_survey us 
                            WHERE us.question_id = $question_id AND us.user_id = $user_id) AS times_answered
                        FROM 
                            survey_questions sq
                        WHERE 
                            sq.question_id = $question_id";
            #bot_message($chat_id, "checking $question_id");
            $subresult = mysqli_query($db, $subquery);
            $fetch = mysqli_fetch_assoc($subresult);
            $showafter = $fetch['showafter'];
            $timesanswered = $fetch['times_answered'];

            if ($showafter !== null) {
                
                $modulo = $qtd / $showafter;
                if ($showafter*$timesanswered<$qtd && $modulo < $min_modulo) {
                    $min_modulo = $modulo;
                    $selected_question_id = $question_id;
                }
            }
        }
        $query = "SELECT * FROM survey_questions WHERE question_id=$selected_question_id";
        #$query = "CALL GetSurveyQuestion($user_id, $qtd)";
    }


    $result = mysqli_query($db, $query);
    $num = mysqli_num_rows($result);
    if ($num == 0) {  // meaning there is no survey question to ask
        mysqli_free_result($result); 
        getQuestion();
        return 0;
    } 
    $fetch = mysqli_fetch_assoc($result);
    $answers = array($fetch['option1'], $fetch['option2'], $fetch['option3'], $fetch['option4'], $fetch['option5']);
    $opening_msg = "שאלה זו היא לצרכי מחקר - אשמח שתענו עליה אבל ניתן גם לדלג עליה";
    $survey_q = $fetch['question_text'];

    $ar = array();
    $qid = $fetch['question_id'];
    $options="";
    // Create the answer buttons array dynamically
    $answerButtons = array();
    for ($i = 0; $i < count($answers); $i++) {
        if (!empty($answers[$i])) {
            $answerIndex = $i + 1; // because $i is 0-based, and we need 1-based indices
            $ans = "SQ:" . $qid . ":A:" . $answerIndex;
            array_push($answerButtons, array('text' => (string)$answerIndex, 'callback_data' => $ans));
            $options = $options."\n".$answerIndex.". ".$answers[$i];
        }
    }
    $options = $options."\n .";
    $fullq = "$opening_msg \n $survey_q \n $options";
    bot_message($chat_id, $fullq);
    $anssq = "skipSQ:" . $qid;
    
    $ar31 = array(array('text' => 'מעדיף לא לענות', 'callback_data' => $anssq));

    if (!empty($answerButtons)) {
        array_push($ar, $answerButtons);
    }
    array_push($ar, $ar31);

    $lastSQ = 1;
    $markup = array('inline_keyboard' => $ar);
    bot_message($chat_id, "מה התשובה?", $markup);

}



function showKeyboard($step) {
    global $user_id, $user_id_admin, $chat_id;
    $markup = array('keyboard' => array(array('opt1', 'opt2'), array('opt3'), array('opt4')), 'resize_keyboard' => true, 'one_time_keyboard' => true, 'selective' => true);
    $msg = "בחר";
    bot_message($chat_id, urlencode($msg), $markup);
    return true;
}

/////////////////////////////////////////////////////////////////////////
///              POINTS SYSTEM FUNCTIONS                             ////
/////////////////////////////////////////////////////////////////////////

/**
 * Determine question difficulty level based on success rate
 * @param int $questionId Question ID
 * @return int Level 1-4 (1=easy, 4=very hard)
 */
function getQuestionDifficultyLevel($questionId) {
    global $db;

    $safe_qid = intval($questionId);
    $query = "SELECT numofcorrectanswers, numofanswers FROM questions WHERE id = $safe_qid";
    $result = mysqli_query($db, $query);

    if (!$result || mysqli_num_rows($result) == 0) {
        return 2; // Default to medium if no data
    }

    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    if ($row['numofanswers'] == 0) {
        return 2; // Default to medium if no answer data
    }

    $successRate = $row['numofcorrectanswers'] / $row['numofanswers'];

    // Determine level based on success rate thresholds
    if ($successRate >= 0.8) return 1;      // Easy (≥80% success)
    if ($successRate >= 0.7) return 2;      // Medium (70-80% success)
    if ($successRate >= 0.6) return 3;      // Hard (60-70% success)
    return 4;                                // Very hard (<60% success)
}

/**
 * Get points from point_rules table
 * @param int $actionType 1=correct, 2=wrong
 * @param int $questionLevel 1-4
 * @return int Points to add/deduct
 */
function getPointsFromRules($actionType, $questionLevel) {
    global $db;

    $safe_action = intval($actionType);
    $safe_level = intval($questionLevel);

    $query = "SELECT points FROM point_rules WHERE action_type = $safe_action AND question_level = $safe_level";
    $result = mysqli_query($db, $query);

    if (!$result || mysqli_num_rows($result) == 0) {
        error_log("No points rule found for action_type={$safe_action}, question_level={$safe_level}");
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    mysqli_free_result($result);

    return intval($row['points']);
}

/**
 * Award or deduct points for user answer
 * @param int $userId User Telegram ID
 * @param int $questionId Question ID
 * @param int $actionType 1=correct, 2=wrong
 * @return int Points changed
 */
function updateUserPoints($userId, $questionId, $actionType) {
    global $db;

    $safe_user_id = intval($userId);
    $safe_question_id = intval($questionId);
    $safe_action_type = intval($actionType);

    // Get question difficulty level based on success rate
    $questionLevel = getQuestionDifficultyLevel($safe_question_id);

    // Get points from rules
    $pointsChange = getPointsFromRules($safe_action_type, $questionLevel);

    if ($pointsChange == 0) {
        error_log("No points change for user={$safe_user_id}, question={$safe_question_id}");
        return 0;
    }

    // Update user's overall_points (if column exists)
    $query = "UPDATE users SET overall_points = overall_points + $pointsChange WHERE id = $safe_user_id";
    $result = mysqli_query($db, $query);

    if (!$result) {
        error_log("Failed to update user points: " . mysqli_error($db));
    }

    // Log the transaction in point_log
    $query = "INSERT INTO point_log (user_id, question_id, action_type, question_level, points_change) 
              VALUES ($safe_user_id, $safe_question_id, $safe_action_type, $questionLevel, $pointsChange)";
    $result = mysqli_query($db, $query);

    if (!$result) {
        error_log("Failed to log points: " . mysqli_error($db));
    }

    return $pointsChange;
}

/////////////////////////////////////////////////////////////////////////
///              BADGES ROOM FUNCTION                                ////
/////////////////////////////////////////////////////////////////////////

/**
 * Show user's badge collection
 */
function showBadgesRoom() {
    global $db, $user_id, $chat_id;

    $safe_user_id = intval($user_id);

    // Get user's earned badges with sticker info
    $query = "SELECT b.badge_id, b.badge_name, b.badge_title_he, b.badge_description_he, 
                     b.badge_emoji, b.badge_type, b.sticker_file_id, ub.earned_at
              FROM user_badges ub
              JOIN badges b ON ub.badge_id = b.badge_id
              WHERE ub.user_id = $safe_user_id AND b.is_active = 1
              ORDER BY ub.earned_at DESC";

    $result = mysqli_query($db, $query);
    $earned_badges = [];
    $earned_count = 0;

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $earned_badges[] = $row;
            $earned_count++;
        }
        mysqli_free_result($result);
    }

    // Get total active badges count
    $query = "SELECT COUNT(*) as total FROM badges WHERE is_active = 1";
    $result = mysqli_query($db, $query);
    $total_badges = 0;
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $total_badges = intval($row['total']);
        mysqli_free_result($result);
    }

    // Build message
    $message = "🏅 אוסף התגים שלך\n\n";
    $message .= "צברת {$earned_count} מתוך {$total_badges} תגים! 🎉\n\n";

    if ($earned_count > 0) {
        $message .= "━━━━━━━━━━━━━━━━\n";
        $message .= "✨ התגים שלך:\n\n";

        foreach ($earned_badges as $badge) {
            $emoji = $badge['badge_emoji'] ?: '🏆';
            $date = date('d/m/Y', strtotime($badge['earned_at']));
            $message .= "{$emoji} <b>{$badge['badge_title_he']}</b>\n";
            $message .= "   {$badge['badge_description_he']}\n";
            $message .= "   📅 התקבל: {$date}\n\n";
        }
    } else {
        $message .= "עדיין לא צברת תגים. 😔\n";
        $message .= "התחל לענות על שאלות כדי לזכות בתגים!\n\n";
    }

    // Get some unearned badges as goals
    $query = "SELECT b.badge_id, b.badge_name, b.badge_title_he, b.badge_description_he, 
                     b.badge_emoji, b.points_reward
              FROM badges b
              WHERE b.is_active = 1 
              AND b.badge_id NOT IN (
                  SELECT badge_id FROM user_badges WHERE user_id = $safe_user_id
              )
              ORDER BY b.points_reward ASC
              LIMIT 5";

    $result = mysqli_query($db, $query);
    $unearned_badges = [];

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $unearned_badges[] = $row;
        }
        mysqli_free_result($result);
    }

    // Add back button
    $keyboard = array(
        array(
            array('text' => '🔙 חזרה לתפריט', 'callback_data' => 'menu_back'),
        ),
    );

    $markup = array('inline_keyboard' => $keyboard);

    // Send message with HTML formatting
    $message = urlencode($message);
    $markup_json = json_encode($markup);
    $markup_encoded = urlencode($markup_json);

    bot("sendMessage?chat_id={$chat_id}&text={$message}&parse_mode=HTML&reply_markup={$markup_encoded}");
}

/////////////////////////////////////////////////////////////////////////
///              LEADERBOARD FUNCTIONS                               ////
/////////////////////////////////////////////////////////////////////////

/**
 * Show all-time leaderboard (top 10 + user position)
 */
function showLeaderboardAllTime() {
    global $db, $user_id, $chat_id;

    $safe_user_id = intval($user_id);

    // Get top 10 users
    $query = "SELECT id, nickname, overall_points 
              FROM users 
              WHERE nickname IS NOT NULL AND overall_points > 0
              ORDER BY overall_points DESC, id ASC
              LIMIT 10";
    $result = mysqli_query($db, $query);

    $message = "🏆 טבלת המובילים - כל הזמנים\n\n";

    $topUsers = array();
    $userInTop10 = false;
    $position = 1;

    while ($row = mysqli_fetch_assoc($result)) {
        $topUsers[] = $row;
        if ($row['id'] == $safe_user_id) {
            $userInTop10 = true;
        }
    }
    mysqli_free_result($result);

    // Display top 10
    foreach ($topUsers as $idx => $user) {
        $rank = $idx + 1;
        $medal = '';
        if ($rank == 1) $medal = '🥇';
        elseif ($rank == 2) $medal = '🥈';
        elseif ($rank == 3) $medal = '🥉';
        else $medal = "{$rank}.";

        $isYou = ($user['id'] == $safe_user_id) ? " *← אתה!*" : "";
        $message .= "{$medal} {$user['nickname']} - {$user['overall_points']} נקודות{$isYou}\n";
    }

    // If user not in top 10, show their position
    if (!$userInTop10) {
        $query = "SELECT nickname, overall_points,
                  (SELECT COUNT(*) + 1 FROM users WHERE overall_points > u.overall_points AND nickname IS NOT NULL) as position
                  FROM users u
                  WHERE id = $safe_user_id";
        $result = mysqli_query($db, $query);

        if ($result && mysqli_num_rows($result) > 0) {
            $userRow = mysqli_fetch_assoc($result);
            mysqli_free_result($result);

            if ($userRow['nickname']) {
                $message .= "\n━━━━━━━━━━━━━━━\n\n";
                $message .= "📍 {$userRow['position']}. {$userRow['nickname']} - {$userRow['overall_points']} נקודות *← אתה!*\n";
            }
        }
    }

    // Add back button
    $keyboard = array(
        array(
            array('text' => '🔙 חזרה לתפריט', 'callback_data' => 'menu_back'),
        ),
    );
    $markup = array('inline_keyboard' => $keyboard);

    bot_message($chat_id, $message, $markup);
}

/**
 * Show weekly leaderboard (top 10 + user position)
 */
function showLeaderboardWeekly() {
    global $db, $user_id, $chat_id;

    $safe_user_id = intval($user_id);

    // Get top 10 users for this week
    $query = "SELECT pl.user_id, u.nickname, SUM(pl.points_change) as weekly_points
              FROM point_log pl
              JOIN users u ON pl.user_id = u.id
              WHERE pl.timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND u.nickname IS NOT NULL
              GROUP BY pl.user_id, u.nickname
              HAVING weekly_points > 0
              ORDER BY weekly_points DESC, u.id ASC
              LIMIT 10";
    $result = mysqli_query($db, $query);

    $message = "📅 טבלת המובילים - שבועי\n";
    $message .= "_(7 ימים אחרונים)_\n\n";

    $topUsers = array();
    $userInTop10 = false;

    while ($row = mysqli_fetch_assoc($result)) {
        $topUsers[] = $row;
        if ($row['user_id'] == $safe_user_id) {
            $userInTop10 = true;
        }
    }
    mysqli_free_result($result);

    if (empty($topUsers)) {
        bot_message($chat_id, "אין עדיין נתונים לשבוע האחרון.");
        return;
    }

    // Display top 10
    foreach ($topUsers as $idx => $user) {
        $rank = $idx + 1;
        $medal = '';
        if ($rank == 1) $medal = '🥇';
        elseif ($rank == 2) $medal = '🥈';
        elseif ($rank == 3) $medal = '🥉';
        else $medal = "{$rank}.";

        $isYou = ($user['user_id'] == $safe_user_id) ? " *← אתה!*" : "";
        $message .= "{$medal} {$user['nickname']} - {$user['weekly_points']} נקודות{$isYou}\n";
    }

    // If user not in top 10, show their position
    if (!$userInTop10) {
        $query = "SELECT u.nickname, weekly.points as weekly_points, weekly.position
                  FROM users u
                  JOIN (
                      SELECT user_id, SUM(points_change) as points,
                      (SELECT COUNT(DISTINCT user_id) + 1 
                       FROM point_log pl2
                       WHERE pl2.timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                       AND (SELECT SUM(points_change) FROM point_log WHERE user_id = pl2.user_id AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)) > 
                           (SELECT SUM(points_change) FROM point_log WHERE user_id = pl.user_id AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                      ) as position
                      FROM point_log pl
                      WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      AND user_id = $safe_user_id
                      GROUP BY user_id
                  ) weekly ON u.id = weekly.user_id
                  WHERE u.id = $safe_user_id";
        $result = mysqli_query($db, $query);

        if ($result && mysqli_num_rows($result) > 0) {
            $userRow = mysqli_fetch_assoc($result);
            mysqli_free_result($result);

            if ($userRow['nickname'] && $userRow['weekly_points'] > 0) {
                $message .= "\n━━━━━━━━━━━━━━━\n\n";
                $message .= "📍 {$userRow['position']}. {$userRow['nickname']} - {$userRow['weekly_points']} נקודות *← אתה!*\n";
            }
        }
    }

    // Add back button
    $keyboard = array(
        array(
            array('text' => '🔙 חזרה לתפריט', 'callback_data' => 'menu_back'),
        ),
    );
    $markup = array('inline_keyboard' => $keyboard);

    bot_message($chat_id, $message, $markup);
}

/**
 * Show monthly leaderboard (top 10 + user position)
 */
function showLeaderboardMonthly() {
    global $db, $user_id, $chat_id;

    $safe_user_id = intval($user_id);

    // Get top 10 users for this month
    $query = "SELECT pl.user_id, u.nickname, SUM(pl.points_change) as monthly_points
              FROM point_log pl
              JOIN users u ON pl.user_id = u.id
              WHERE pl.timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              AND u.nickname IS NOT NULL
              GROUP BY pl.user_id, u.nickname
              HAVING monthly_points > 0
              ORDER BY monthly_points DESC, u.id ASC
              LIMIT 10";
    $result = mysqli_query($db, $query);

    $message = "📆 טבלת המובילים - חודשי\n";
    $message .= "_(30 ימים אחרונים)_\n\n";

    $topUsers = array();
    $userInTop10 = false;

    while ($row = mysqli_fetch_assoc($result)) {
        $topUsers[] = $row;
        if ($row['user_id'] == $safe_user_id) {
            $userInTop10 = true;
        }
    }
    mysqli_free_result($result);

    if (empty($topUsers)) {
        bot_message($chat_id, "אין עדיין נתונים לחודש האחרון.");
        return;
    }

    // Display top 10
    foreach ($topUsers as $idx => $user) {
        $rank = $idx + 1;
        $medal = '';
        if ($rank == 1) $medal = '🥇';
        elseif ($rank == 2) $medal = '🥈';
        elseif ($rank == 3) $medal = '🥉';
        else $medal = "{$rank}.";

        $isYou = ($user['user_id'] == $safe_user_id) ? " *← אתה!*" : "";
        $message .= "{$medal} {$user['nickname']} - {$user['monthly_points']} נקודות{$isYou}\n";
    }

    // If user not in top 10, show their position
    if (!$userInTop10) {
        $query = "SELECT u.nickname, monthly.points as monthly_points, monthly.position
                  FROM users u
                  JOIN (
                      SELECT user_id, SUM(points_change) as points,
                      (SELECT COUNT(DISTINCT user_id) + 1 
                       FROM point_log pl2
                       WHERE pl2.timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                       AND (SELECT SUM(points_change) FROM point_log WHERE user_id = pl2.user_id AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)) > 
                           (SELECT SUM(points_change) FROM point_log WHERE user_id = pl.user_id AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY))
                      ) as position
                      FROM point_log pl
                      WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      AND user_id = $safe_user_id
                      GROUP BY user_id
                  ) monthly ON u.id = monthly.user_id
                  WHERE u.id = $safe_user_id";
        $result = mysqli_query($db, $query);

        if ($result && mysqli_num_rows($result) > 0) {
            $userRow = mysqli_fetch_assoc($result);
            mysqli_free_result($result);

            if ($userRow['nickname'] && $userRow['monthly_points'] > 0) {
                $message .= "\n━━━━━━━━━━━━━━━\n\n";
                $message .= "📍 {$userRow['position']}. {$userRow['nickname']} - {$userRow['monthly_points']} נקודות *← אתה!*\n";
            }
        }
    }

    // Add back button
    $keyboard = array(
        array(
            array('text' => '🔙 חזרה לתפריט', 'callback_data' => 'menu_back'),
        ),
    );
    $markup = array('inline_keyboard' => $keyboard);

    bot_message($chat_id, $message, $markup);
}



function recordAnswer($qid, $type){
    // type can be: 1-correct, 2-wrong, 3-bad
    global $db, $user_id, $chat_id;
    

    $query = "SELECT * FROM questions WHERE id=".$qid;
    $result = mysqli_query($db, $query);
    $num = mysqli_num_rows($result);
    if ($num == 0) {
        $totalCorrectAns = 1;
        $numOfAns=1;
        $reportedBad=1;
    } else {
        $fetch = mysqli_fetch_assoc($result);
        $totalCorrectAns = $fetch['numofcorrectanswers'];
        if ($type==1) {
            $totalCorrectAns ++;
        }
        $numOfAns = $fetch['numofanswers'];
        $numOfAns++;
        $reportedBad = $fetch['reportedbad'];
        if ($type==3) {
            $reportedBad++;
        }
    }
    mysqli_free_result($result);  // Free the result set

    $query = "SELECT * FROM user_q WHERE questionid=".$qid." AND userid='".$user_id."'";
    $result = mysqli_query($db, $query);
    $num = mysqli_num_rows($result);

    //update user_question statitics
    if ($num == 0) {
        if ($type==1) {
            $query = "INSERT INTO user_q (userid,questionid,numofsuccess) VALUES('" . $user_id . "'," . $qid . ",1)";
            $numOfSuccess = 1;
        } elseif ($type == 2) {
            $query = "INSERT INTO user_q (userid,questionid,numoffailure) VALUES('" . $user_id . "'," . $qid . ",1)";
            $numOfFailure = 1;
        }
        mysqli_free_result($result);  // Free the result set
        mysqli_query($db, $query);
    } else {
        $fetch = mysqli_fetch_assoc($result);
        $numOfSuccess = $fetch['numofsuccess'];
        $numOfFailure = $fetch['numoffailure'];
        if ($type==1) {
            $numOfSuccess++;
        } elseif ($type==2) {
            $numOfFailure++;
        }
        mysqli_free_result($result);  // Free the result set

    }

     // update the questions table with statistics
    switch ($type) {
        case 1: {

            $query = "UPDATE questions SET numofcorrectanswers =".$totalCorrectAns." WHERE id=".$qid;
            mysqli_query($db, $query);
            $query ="UPDATE questions SET numofanswers = ".$numOfAns." WHERE id=".$qid;
            mysqli_query($db, $query);
            $query ="UPDATE user_q SET numofsuccess = ".$numOfSuccess." WHERE questionid=".$qid." AND userid='".$user_id."'";
            mysqli_query($db, $query);
        } break;

        case 2: {
            $query ="UPDATE questions SET numofanswers = ".$numOfAns." WHERE id=".$qid;
            mysqli_query($db, $query);
            $query ="UPDATE user_q SET numoffailure = ".$numOfFailure." WHERE questionid=".$qid." AND userid='".$user_id."'";
            mysqli_query($db, $query);
        } break;

        case 3: {
            $query ="UPDATE questions SET reportedbad = ".$reportedBad." WHERE id=".$qid;
            mysqli_query($db, $query);
        } break;
    }

    // Award/deduct points for correct or wrong answers
    if ($type == 1 || $type == 2) {
        $pointsChange = updateUserPoints($user_id, $qid, $type);

        // Optional: Log points change for debugging
        error_log("User {$user_id} answered question {$qid}, type={$type}, points change={$pointsChange}");
    }

    $query = "SELECT * FROM users WHERE id=".$user_id;
    $result = mysqli_query($db, $query);	
    $fetch = mysqli_fetch_assoc($result);
    $currentRun = $fetch['CurrentRun'];
    $level = $fetch['level'];
    mysqli_free_result($result);  // Free the result set



    $query = "SELECT * FROM Gamification WHERE level=".$level;
    $result = mysqli_query($db, $query);	
    $fetch = mysqli_fetch_assoc($result);
    $up = $fetch['Upgrade_at'];
    $down = $fetch['Downgrade_at'];
    mysqli_free_result($result);  // Free the result set



    switch ($type) {
	    case 1: { //correct answer
            $currentRun++;
            if ($currentRun==$up && $level<4) {
                $level++;
                writeLog(9,$level);
                $msgtxt = "כל הכבוד עלית לשלב ".$level;
                bot_message($chat_id, $msgtxt);
                $query = "Update users set level=".$level. " WHERE id=".$user_id ;
                $result = mysqli_query($db, $query);	
                $query = "Update users set CurrentRun=0 WHERE id=".$user_id ;
                $result = mysqli_query($db, $query);

                // Badge check will happen after answer message
            } else {
                $query = "Update users set CurrentRun=".$currentRun. " WHERE id=".$user_id ;
                $result = mysqli_query($db, $query);	
            }

            // Return flag to check badges after answer message is sent
            return 'check_correct_badges';

        } break;
	    case 2: { //wrong answer 
            $currentRun--;
            $query = "Update users set CurrentRun=".$currentRun. " WHERE id=".$user_id ;
            $result = mysqli_query($db, $query);
            if ($currentRun<$down && $level>1) {
	           $level--;
                $msgtxt = "אופסי - ירדת לשלב  ".$level;
                bot_message($chat_id, $msgtxt);
                writeLog(10,$level);

                $query = "Update users set level=".$level. " WHERE id=".$user_id ;
                $result = mysqli_query($db, $query);	
                $query = "Update users set CurrentRun=0 WHERE id=".$user_id ;
                $result = mysqli_query($db, $query);	
            } else {
                if ($currentRun>-4) {
                    $query = "Update users set CurrentRun=".$currentRun. " WHERE id=".$user_id ;
                    $result = mysqli_query($db, $query);	
                } else { //already in level 1 but poor shape
                    $query = "Update users set CurrentRun=-4  WHERE id=".$user_id ;
                    $result = mysqli_query($db, $query);
                }
            }

            // Return flag to check badges after answer message is sent
            return 'check_wrong_badges';

        } break;
  
    }

    // Default return if no badge checks needed
    return null;
}

function GetQsoFar() {
    global $db, $user_id, $chat_id;
    $query = "select count(*) as qtodate from log where userid=". $user_id;
    $result = mysqli_query($db, $query);
    $fetch = mysqli_fetch_assoc($result);
    $QsoFar = $fetch['qtodate'];
    return $QsoFar;
}

function doesUserExist(){
    global $db, $user_id, $chat_id;
    $query = "select count(*) as yes from users where id=". $user_id;
    $result = mysqli_query($db, $query);
    $num = mysqli_num_rows($result);
    if ($num == 0) {
        bot_message($chat_id,"מספר משתמש לא תקין - יש לפנות לדוד");
        return 0;
    } else {
        return 1;
    }
}


function showNextQ() { //this function should determine what kind of question to ask next
    global $chat_id, $user_id, $lastSQ;
    $numQ=GetQsoFar();
    if ($numQ != 0 && $numQ % 40 == 0) {
        getSurveyQuestion();
    } else {
        getQuestion();
    }
}

/////////////////////////////////////////////////////////////////////////
///              NICKNAME SYSTEM FUNCTIONS                           ////
/////////////////////////////////////////////////////////////////////////

/**
 * Check if user has a nickname set
 * @return bool True if nickname exists, false otherwise
 */
function hasNickname($user_id) {
    global $db;
    $query = "SELECT nickname FROM users WHERE id = $user_id";
    $result = mysqli_query($db, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $fetch = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return !empty($fetch['nickname']) && !is_null($fetch['nickname']);
    }
    return false;
}

/**
 * Check if user is awaiting nickname input
 * @return bool True if awaiting, false otherwise
 */
function isAwaitingNickname($user_id) {
    global $db;
    $query = "SELECT awaiting_nickname FROM users WHERE id = $user_id";
    $result = mysqli_query($db, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $fetch = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
        return $fetch['awaiting_nickname'] == 1;
    }
    return false;
}

/**
 * Set user's awaiting_nickname flag
 * @return bool Success status
 */
function setAwaitingNickname($user_id, $awaiting = true) {
    global $db;
    $flag = $awaiting ? 1 : 0;
    $query = "UPDATE users SET awaiting_nickname = $flag WHERE id = $user_id";
    return mysqli_query($db, $query);
}

/**
 * Ask user for nickname
 */
function askForNickname($chat_id) {
    $message = "🎮 ברוכים הבאים לבוט הקורס יסודות מערכות מידע!\n\n";
    $message .= "כדי להצטרף, אנא בחר כינוי ייחודי.\n\n";
    $message .= "📋 *כללים:*\n";
    $message .= "• 3-15 תווים\n";
    $message .= "• אותיות באנגלית, מספרים וקו תחתון בלבד\n";
    $message .= "אנא שלח את הכינוי שלך:";

    bot_message($chat_id, $message);
}

/**
 * Validate nickname format
 * @return bool True if valid, false otherwise
 */
function validateNickname($nickname) {
    // Only letters, numbers, underscore, 3-20 characters
    return preg_match('/^[a-zA-Z0-9_]{3,15}$/', $nickname);
}

/**
 * Check if nickname is already taken by another user
 * @return bool True if taken, false if available
 */
function isNicknameTaken($nickname, $exclude_user_id = null) {
    global $db;
    $nickname = mysqli_real_escape_string($db, $nickname);

    $query = "SELECT id FROM users WHERE nickname = '$nickname'";
    if ($exclude_user_id !== null) {
        $query .= " AND id != $exclude_user_id";
    }
    $query .= " LIMIT 1";

    $result = mysqli_query($db, $query);
    $taken = ($result && mysqli_num_rows($result) > 0);

    if ($result) {
        mysqli_free_result($result);
    }

    return $taken;
}

/**
 * Update user's nickname and set timestamp
 * @return array ['success' => bool, 'error' => string|null]
 */
function updateNickname($user_id, $nickname) {
    global $db;

    // Validate format
    if (!validateNickname($nickname)) {
        return [
            'success' => false,
            'error' => 'invalid_format'
        ];
    }

    // Check if taken by another user
    if (isNicknameTaken($nickname, $user_id)) {
        return [
            'success' => false,
            'error' => 'already_taken'
        ];
    }

    // Update with unique constraint handling
    $nickname = mysqli_real_escape_string($db, $nickname);

    $query = "UPDATE users 
              SET nickname = '$nickname', 
                  awaiting_nickname = 0,
                  nickname_set_at = NOW()
              WHERE id = $user_id";

    $result = mysqli_query($db, $query);

    if ($result) {
        return ['success' => true, 'error' => null];
    } else {
        // Check if it's a duplicate key error
        if (mysqli_errno($db) == 1062) { // Duplicate entry
            return [
                'success' => false,
                'error' => 'already_taken'
            ];
        }
        return [
            'success' => false,
            'error' => 'database_error'
        ];
    }
}

/**
 * Handle nickname input from user
 * Processes the nickname and sends appropriate response
 */
function handleNicknameInput($user_id, $chat_id, $text) {
    global $db;
    $proposed_nickname = trim($text);

    // Validate and update
    $result = updateNickname($user_id, $proposed_nickname);

    if ($result['success']) {
        // Success!
        $message = "✅ *הכינוי נקבע בהצלחה!*\n\n";
        $message .= "הכינוי שלך: `$proposed_nickname`\n\n";
        $message .= "עכשיו תוכל להשתמש בבוט. שלח /start כדי להתחיל!";
        bot_message($chat_id, $message);
        writeLog(14, 0); // Log nickname set action

        // Award nickname_chosen badge
        $badgeService = new BadgeService($db, $user_id, $chat_id);
        $badgeService->checkWelcomeBadge();
    } else {
        // Error handling
        if ($result['error'] == 'invalid_format') {
            $message = "❌ *פורמט כינוי לא תקין!*\n\n";
            $message .= "הכינוי שלך חייב:\n";
            $message .= "• להיות באורך 3-15 תווים\n";
            $message .= "• להכיל רק אותיות אנגליות, מספרים וקו תחתון\n\n";
            $message .= "דוגמאות: `player123`, `quiz_master`\n\n";
            $message .= "אנא נסה שוב:";
            bot_message($chat_id, $message);
        } elseif ($result['error'] == 'already_taken') {
            $message = "❌ *הכינוי כבר תפוס!*\n\n";
            $message .= "מישהו אחר כבר משתמש בכינוי `$proposed_nickname`.\n\n";
            $message .= "אנא בחר כינוי אחר:";
            bot_message($chat_id, $message);
        } else {
            $message = "❌ אירעה שגיאה. אנא נסה שוב או פנה לתמיכה.";
            bot_message($chat_id, $message);
        }
    }

    return $result['success'];
}

/**
 * Check and handle nickname requirement for user
 * Returns true if user can proceed, false if waiting for nickname
 */
function checkNicknameRequired($user_id, $chat_id) {
    // Check if user already has a nickname
    if (hasNickname($user_id)) {
        return true; // User has nickname, can proceed
    }

    // Check if already waiting for nickname
    if (!isAwaitingNickname($user_id)) {
        // First time - ask for nickname
        setAwaitingNickname($user_id, true);
        askForNickname($chat_id);
    }

    return false; // User needs to set nickname
}

