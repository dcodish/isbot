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
    curl_close($ch);
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
        curl_close($ch);
        return null;
    }
    curl_close($ch);
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


function getQuestion() {
    global $db, $user_id, $chat_id;
    $query = "SELECT * FROM users WHERE id=".$user_id;

    $result = mysqli_query($db, $query);	
    $fetch = mysqli_fetch_assoc($result);
    $level = $fetch['level'];


    switch ($level) {
        case 1: {
            $query = "SELECT * FROM `questions` Q WHERE numofcorrectanswers/numofanswers >0.8 and difficulty=1 ORDER BY RAND() LIMIT 1";
        } break;
        case 2: {
            $query = "SELECT * FROM `questions` Q WHERE numofcorrectanswers/numofanswers >0.6  and difficulty=1 ORDER BY RAND() LIMIT 1";
        } break;
        case 3: {
            $query = "SELECT * FROM `questions` Q WHERE numofcorrectanswers/numofanswers >0.6 AND difficulty=1 AND id not in(select questionid from user_q where userid='".$user_id."') ORDER BY RAND() LIMIT 1 ";
        } break;
        case 4: {
            $query = "SELECT * FROM `questions` Q WHERE numofcorrectanswers/numofanswers <0.61 and difficulty=1 AND id not in(select questionid from user_q where userid='".$user_id."') ORDER BY RAND() LIMIT 1";
        } break;
        default: {
            $query = "SELECT * FROM `questions` Q WHERE difficulty=1 ORDER BY RAND() LIMIT 1";

        } break;
    }
    #bot_message($chat_id,$query);
    $result=mysqli_query($db,$query);
    $num = mysqli_num_rows($result);
        if ($num == 0) {
            mysqli_free_result($result);  // Free the result set
            $query = "SELECT * FROM `questions` Q ORDER BY RAND() LIMIT 1";
            $result=mysqli_query($db,$query);
        }
    $fetch = mysqli_fetch_assoc($result);
    mysqli_free_result($result);  // Free the result set


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

            } else {
                $query = "Update users set CurrentRun=".$currentRun. " WHERE id=".$user_id ;
                $result = mysqli_query($db, $query);	
            }

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
        } break;
  
    }
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

