<?php
/**
 * Student-facing Exam Mode.
 *
 * A short, timed, stratified self-assessment drawn live from the practice bank.
 * See docs/features/exam-mode.md (FR-EXM-*) and docs/design.md ADR-012.
 *
 * Key behaviours:
 *  - 10 questions (settings.exam_num_questions), 20-minute timer
 *    (settings.exam_time_minutes), pass = 56 (settings.exam_pass_grade).
 *  - Answers count as NORMAL practice: routed through recordAnswer() so points,
 *    leveling, and badges apply, exactly like a regular question.
 *  - Immediate per-question feedback; a results screen at the end.
 *  - The `log` audit table records the full lifecycle (ExamStart 36,
 *    ExamCompleted 37, ExamStopped 38). "Stop" discards the graded result but the
 *    activity stays logged and the answers already given still count.
 *  - Webhook runtime has no background clock: the timer is enforced lazily on
 *    interaction; a dangling in-progress attempt is dropped when a new exam starts.
 *
 * Conventions: integer params are intval()'d; the one text insert is escaped.
 */

/* ------------------------------------------------------------------ settings */

function getExamNumQuestions() {
    global $db;
    static $cached = null;
    if ($cached !== null) return $cached;
    $res = mysqli_query($db, "SELECT setting_value FROM settings WHERE setting_key = 'exam_num_questions' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res); mysqli_free_result($res);
        $cached = max(1, intval($row['setting_value']));
    } else {
        if ($res) mysqli_free_result($res);
        $cached = 10;
    }
    return $cached;
}

function getExamTimeLimitSeconds() {
    global $db;
    static $cached = null;
    if ($cached !== null) return $cached;
    $res = mysqli_query($db, "SELECT setting_value FROM settings WHERE setting_key = 'exam_time_minutes' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res); mysqli_free_result($res);
        $cached = max(1, intval($row['setting_value'])) * 60;
    } else {
        if ($res) mysqli_free_result($res);
        $cached = 1200;
    }
    return $cached;
}

function getExamPassGrade() {
    global $db;
    static $cached = null;
    if ($cached !== null) return $cached;
    $res = mysqli_query($db, "SELECT setting_value FROM settings WHERE setting_key = 'exam_pass_grade' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $row = mysqli_fetch_assoc($res); mysqli_free_result($res);
        $cached = max(1, intval($row['setting_value']));
    } else {
        if ($res) mysqli_free_result($res);
        $cached = 56;
    }
    return $cached;
}

/* --------------------------------------------------------- feature gating    */

/** Generic settings reader (uncached — the gate is off the hot path). */
function examGetSetting($key, $default = null) {
    global $db;
    $k = mysqli_real_escape_string($db, $key);
    $res = mysqli_query($db, "SELECT setting_value FROM settings WHERE setting_key = '$k' LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $v = mysqli_fetch_assoc($res)['setting_value'];
        mysqli_free_result($res);
        return $v;
    }
    if ($res) mysqli_free_result($res);
    return $default;
}

/**
 * Staged rollout gate. While exam mode is in development it is restricted to the
 * staff cohort (settings.exam_staff_cohort_id, the "צוות" group). Flip
 * settings.exam_enabled_for_all to '1' to open it to everyone — no deploy needed.
 * Add a tester by assigning their user to the staff cohort.
 */
function examFeatureEnabled($user_id) {
    global $db;
    if (examGetSetting('exam_enabled_for_all', '0') === '1') return true;
    $staff = intval(examGetSetting('exam_staff_cohort_id', '3'));
    if ($staff <= 0) return false;
    $uid = intval($user_id);
    $res = mysqli_query($db, "SELECT 1 FROM users WHERE id = $uid AND cohort_id = $staff LIMIT 1");
    $ok = ($res && mysqli_num_rows($res) > 0);
    if ($res) mysqli_free_result($res);
    return $ok;
}

/** Shown to non-staff users who tap the (still visible) exam entry points. */
function examUnderDevelopmentNotice($chat_id) {
    $rlm = examRlm();
    $msg = $rlm . "🚧 מצב מבחן עדיין בפיתוח — בקרוב!\n"
         . $rlm . "התכונה תיפתח לכולם ממש בקרוב. תודה על הסבלנות 🙏";
    $markup = ['inline_keyboard' => [[['text' => '⬅️ חזרה לתפריט', 'callback_data' => 'menu_back']]]];
    bot_message($chat_id, $msg, $markup);
}

/* ------------------------------------------------------------- RTL helpers   */

function examRlm() { return "\u{200F}"; }                 // force RTL base direction
function examIso($s) { return "\u{2066}" . $s . "\u{2069}"; } // LRI…PDI isolate (keep digits/latin ordered)

/* --------------------------------------------------------- question selection */

/**
 * Pick `$limit` questions stratified across lectures (≤ the user's cohort week)
 * and success-rate levels. Density-weighted breadth: a breadth pass guarantees
 * each lecture is represented before any lecture gets a second slot; the fill
 * pass then favours denser lectures and level variety. No repeats within the set.
 *
 * Returns an array of question rows (id, question_text, option1..4, correctans,
 * max_lecture, qlevel) in randomised order, or [] if the pool is empty.
 */
function selectExamQuestions($user_id, $limit) {
    global $db;
    $limit = max(1, intval($limit));
    $week  = intval(getCurrentWeek($user_id));
    $lectureFilter = "(max_lecture IS NULL OR max_lecture <= $week)";
    $maxBad = 2; // skip frequently flagged ("not clear") questions

    // Computed level mirrors the bot's live success-rate bands (probation → 0).
    $sql = "SELECT id, question_text, option1, option2, option3, option4, correctans,
                   max_lecture,
                   CASE
                     WHEN numofanswers < 5 THEN 0
                     WHEN numofcorrectanswers / numofanswers >= 0.8 THEN 1
                     WHEN numofcorrectanswers / numofanswers >= 0.7 THEN 2
                     WHEN numofcorrectanswers / numofanswers >= 0.6 THEN 3
                     ELSE 4
                   END AS qlevel
              FROM questions
             WHERE $lectureFilter AND reportedbad <= $maxBad
             ORDER BY RAND()";
    $res = mysqli_query($db, $sql);
    if (!$res) return [];

    // Bucket the (already shuffled) pool by lecture.
    $buckets = [];          // lectureKey => [rows]
    while ($row = mysqli_fetch_assoc($res)) {
        $key = ($row['max_lecture'] === null) ? 'gen' : intval($row['max_lecture']);
        $buckets[$key][] = $row;
    }
    mysqli_free_result($res);
    if (!$buckets) return [];

    $chosen = [];
    $levelCount = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0];

    // Pull one question from $bucketKey, preferring the least-represented level
    // so the set isn't all-easy or all-hard. Removes it from the bucket.
    $pullFromBucket = function ($bucketKey) use (&$buckets, &$levelCount) {
        $rows =& $buckets[$bucketKey];
        if (empty($rows)) return null;
        $bestIdx = 0; $bestSeen = PHP_INT_MAX;
        foreach ($rows as $i => $r) {
            $lvl = intval($r['qlevel']);
            if ($levelCount[$lvl] < $bestSeen) { $bestSeen = $levelCount[$lvl]; $bestIdx = $i; }
        }
        $picked = $rows[$bestIdx];
        array_splice($rows, $bestIdx, 1);
        $levelCount[intval($picked['qlevel'])]++;
        return $picked;
    };

    // Breadth pass: at most one per lecture (lectures already in random order).
    foreach (array_keys($buckets) as $key) {
        if (count($chosen) >= $limit) break;
        $q = $pullFromBucket($key);
        if ($q !== null) $chosen[] = $q;
    }

    // Fill pass: weighted by remaining density — draw a lecture with probability
    // proportional to how many questions it still has, then pull one.
    while (count($chosen) < $limit) {
        $weights = [];
        foreach ($buckets as $key => $rows) {
            if (!empty($rows)) $weights[$key] = count($rows);
        }
        if (!$weights) break; // pool exhausted (thin bank / early week)
        $total = array_sum($weights);
        $r = rand(1, $total);
        $acc = 0; $pick = null;
        foreach ($weights as $key => $w) { $acc += $w; if ($r <= $acc) { $pick = $key; break; } }
        $q = $pullFromBucket($pick);
        if ($q !== null) $chosen[] = $q;
    }

    shuffle($chosen); // don't serve lecture-sorted
    return $chosen;
}

/* ------------------------------------------------------------- attempt state */

/** Load an attempt with a live `elapsed` seconds field, or null. */
function examLoadAttempt($attempt_id) {
    global $db;
    $aid = intval($attempt_id);
    if ($aid <= 0) return null;
    $res = mysqli_query($db, "SELECT *, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed
                                FROM exam_attempts WHERE id = $aid LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { if ($res) mysqli_free_result($res); return null; }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    return $row;
}

function examRemainingSeconds($attempt) {
    return intval($attempt['time_limit_seconds']) - intval($attempt['elapsed']);
}

/** The user's current in-progress attempt id, or 0. Source of truth = status. */
function examActiveAttemptId($user_id) {
    global $db;
    $uid = intval($user_id);
    $res = mysqli_query($db, "SELECT id FROM exam_attempts
                               WHERE user_id = $uid AND status = 'in_progress'
                            ORDER BY id DESC LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { if ($res) mysqli_free_result($res); return 0; }
    $id = intval(mysqli_fetch_assoc($res)['id']);
    mysqli_free_result($res);
    return $id;
}

/* ------------------------------------------------------------------- start   */

/** Intro screen (from /מבחן, /exam, or the menu button). */
function showExamIntro($chat_id) {
    global $user_id;
    if (!examFeatureEnabled($user_id)) { examUnderDevelopmentNotice($chat_id); return; }

    $rlm = examRlm();
    $n   = getExamNumQuestions();
    $min = intval(getExamTimeLimitSeconds() / 60);
    $pass = getExamPassGrade();

    $msg  = $rlm . "📝 מבחן תרגול\n\n";
    $msg .= $rlm . "מבחן קצר שמדמה את המבחן האמיתי:\n";
    $msg .= $rlm . "• " . examIso($n) . " שאלות מכל החומר שנלמד עד כה\n";
    $msg .= $rlm . "• זמן: " . examIso($min) . " דקות\n";
    $msg .= $rlm . "• ציון עובר: " . examIso($pass) . "\n";
    $msg .= $rlm . "• תקבל משוב על כל שאלה, וציון בסוף\n";
    $msg .= $rlm . "• השאלות נספרות לנקודות ולסטטיסטיקה שלך (כמו תרגול רגיל)\n\n";
    $msg .= $rlm . "אפשר להפסיק באמצע — אז לא יירשם ציון.";

    $markup = ['inline_keyboard' => [
        [['text' => '▶️ התחל מבחן', 'callback_data' => 'exam_start']],
        [['text' => '📈 התוצאות שלי במבחנים', 'callback_data' => 'menu_exam_results']],
        [['text' => '⬅️ חזרה לתפריט', 'callback_data' => 'menu_back']],
    ]];
    bot_message($chat_id, $msg, $markup);
}

/** Create a fresh attempt and serve its first question. */
function startExam() {
    global $db, $user_id, $chat_id;
    if (!examFeatureEnabled($user_id)) { examUnderDevelopmentNotice($chat_id); return; }
    $uid = intval($user_id);

    // Drop any dangling in-progress attempt for this user (cascade clears its
    // question rows). No log/grade — the missing ExamCompleted marks abandonment.
    $res = mysqli_query($db, "SELECT id FROM exam_attempts WHERE user_id = $uid AND status = 'in_progress'");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $old = intval($row['id']);
            mysqli_query($db, "DELETE FROM exam_attempts WHERE id = $old");
        }
        mysqli_free_result($res);
    }
    mysqli_query($db, "UPDATE users SET active_exam_attempt_id = NULL WHERE id = $uid");

    $limit     = getExamNumQuestions();
    $questions = selectExamQuestions($user_id, $limit);
    if (empty($questions)) {
        bot_message($chat_id, examRlm() . "אין כרגע מספיק שאלות זמינות למבחן. נסה שוב מאוחר יותר.");
        return;
    }

    $n        = count($questions);
    $timeLim  = getExamTimeLimitSeconds();
    mysqli_query($db, "INSERT INTO exam_attempts (user_id, status, num_questions, time_limit_seconds)
                       VALUES ($uid, 'in_progress', $n, " . intval($timeLim) . ")");
    $attempt_id = intval(mysqli_insert_id($db));
    if ($attempt_id <= 0) {
        bot_message($chat_id, examRlm() . "אירעה שגיאה בפתיחת המבחן. נסה שוב.");
        return;
    }

    $pos = 1;
    foreach ($questions as $q) {
        $qid = intval($q['id']);
        $correctans = intval($q['correctans']);            // 1..4 → which option is correct
        $correctText = $q['option' . $correctans] ?? '';
        $correctText = mysqli_real_escape_string($db, rtrim($correctText));
        $maxLec = ($q['max_lecture'] === null) ? 'NULL' : intval($q['max_lecture']);
        mysqli_query($db, "INSERT INTO exam_attempt_questions
                              (attempt_id, question_id, position, max_lecture, correct_answer)
                           VALUES ($attempt_id, $qid, $pos, $maxLec, '$correctText')");
        $pos++;
    }

    mysqli_query($db, "UPDATE users SET active_exam_attempt_id = $attempt_id WHERE id = $uid");
    writeLog(36, $attempt_id); // ExamStart

    serveExamQuestion($attempt_id);
}

/* ------------------------------------------------------------------- serve   */

/** Serve the next unanswered question of an attempt, or finalize if done/expired. */
function serveExamQuestion($attempt_id) {
    global $db, $user_id, $chat_id;
    $attempt_id = intval($attempt_id);
    $a = examLoadAttempt($attempt_id);
    if (!$a || $a['status'] !== 'in_progress') return;

    // Lazy timer enforcement.
    if (examRemainingSeconds($a) <= 0) { finalizeExam($attempt_id, 'expired'); return; }

    $total    = intval($a['num_questions']);
    $answered = 0;
    $res = mysqli_query($db, "SELECT COUNT(*) AS c FROM exam_attempt_questions
                               WHERE attempt_id = $attempt_id AND is_correct IS NOT NULL");
    if ($res) { $answered = intval(mysqli_fetch_assoc($res)['c']); mysqli_free_result($res); }

    $pos = $answered + 1;
    if ($pos > $total) { finalizeExam($attempt_id, 'completed'); return; }

    // The question for this position.
    $res = mysqli_query($db, "SELECT eaq.id AS eaq_id, eaq.question_id, eaq.correct_answer,
                                     q.question_text, q.option1, q.option2, q.option3, q.option4, q.correctans
                                FROM exam_attempt_questions eaq
                                LEFT JOIN questions q ON q.id = eaq.question_id
                               WHERE eaq.attempt_id = $attempt_id AND eaq.position = $pos LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { if ($res) mysqli_free_result($res); finalizeExam($attempt_id, 'completed'); return; }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);

    // Question was deleted by admin mid-exam: mark it wrong and move on.
    if ($row['question_text'] === null) {
        $eaqId = intval($row['eaq_id']);
        mysqli_query($db, "UPDATE exam_attempt_questions SET is_correct = 0, answered_at = NOW() WHERE id = $eaqId");
        serveExamQuestion($attempt_id);
        return;
    }

    $qid = intval($row['question_id']);
    $correctText = rtrim($row['correct_answer'] ?? '');

    // Shuffle the four options; find where the correct text landed.
    $answers = [rtrim($row['option1']), rtrim($row['option2']), rtrim($row['option3']), rtrim($row['option4'])];
    $order = [0, 1, 2, 3];
    shuffle($order);
    $correctNum = 0;
    $lines = [];
    foreach ($order as $display => $origIdx) {
        $n = $display + 1;
        if ($answers[$origIdx] === $correctText && $correctNum === 0) $correctNum = $n;
        $lines[$n] = $answers[$origIdx];
    }
    if ($correctNum === 0) $correctNum = 1; // defensive (text mismatch) — shouldn't happen

    // Header with remaining time + position.
    $rem = max(0, examRemainingSeconds($a));
    $clock = examIso(sprintf('%02d:%02d', intdiv($rem, 60), $rem % 60));
    $rlm = examRlm();

    $msg  = $rlm . "📝 שאלה " . examIso("$pos/$total") . "   ⏱ " . $clock . "\n\n";
    $msg .= $rlm . rtrim($row['question_text']) . "\n";
    for ($i = 1; $i <= 4; $i++) $msg .= $rlm . "$i. " . $lines[$i] . "\n";
    $msg .= $rlm . ".";

    // Answer buttons carry attempt, qid, chosen number, correct number.
    $btns = [];
    for ($i = 4; $i >= 1; $i--) {
        $btns[] = ['text' => (string)$i, 'callback_data' => "EXQ:$attempt_id:$qid:$i:$correctNum"];
    }
    $markup = ['inline_keyboard' => [
        $btns,
        [['text' => '🛑 הפסק מבחן', 'callback_data' => 'exam_cancel']],
    ]];

    $resp = bot_message($chat_id, $msg, $markup);
    if (isset($resp['result']['message_id'])) {
        logSessionQuestionMessage($user_id, $resp['result']['message_id']);
    }
}

/* ------------------------------------------------------------------ answer   */

/** Handle an EXQ answer callback: record it, show feedback, serve the next. */
function handleExamAnswer($attempt_id, $qid, $chosen, $correct) {
    global $db, $user_id, $chat_id;
    $attempt_id = intval($attempt_id);
    $qid = intval($qid); $chosen = intval($chosen); $correct = intval($correct);

    $a = examLoadAttempt($attempt_id);
    if (!$a || $a['status'] !== 'in_progress' || intval($a['user_id']) !== intval($user_id)) {
        return; // stale / foreign button
    }

    // Out of time: the attempt is over; this late tap doesn't score.
    if (examRemainingSeconds($a) <= 0) { finalizeExam($attempt_id, 'expired'); return; }

    // The matching, still-unanswered question row.
    $res = mysqli_query($db, "SELECT id, correct_answer FROM exam_attempt_questions
                               WHERE attempt_id = $attempt_id AND question_id = $qid AND is_correct IS NULL LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) {
        if ($res) mysqli_free_result($res);
        serveExamQuestion($attempt_id); // double-tap / already answered — just continue
        return;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    $eaqId = intval($row['id']);
    $correctText = rtrim($row['correct_answer'] ?? '');

    $isCorrect = ($chosen === $correct) ? 1 : 0;
    mysqli_query($db, "UPDATE exam_attempt_questions
                          SET user_answer = $chosen, is_correct = $isCorrect, answered_at = NOW()
                        WHERE id = $eaqId");

    // Audit-log the answer exactly like the practice 'Q' handler does (recordAnswer
    // does NOT writeLog itself) so the `log` trail and answer analytics stay whole.
    writeLog($isCorrect ? 1 : 2, $qid); // CorrectAnswer / WrongAnswer

    // Counts as normal practice: points / leveling / badges via the same path.
    $badgeCheck = recordAnswer($qid, $isCorrect ? 1 : 2);

    // Immediate feedback.
    $rlm = examRlm();
    if ($isCorrect) {
        bot_message($chat_id, $rlm . "✅ תשובה נכונה");
    } else {
        bot_message($chat_id, $rlm . "❌ טעות. התשובה הנכונה: " . $correctText);
    }

    runAnswerBadgeChecks($badgeCheck);

    serveExamQuestion($attempt_id);
}

/* ---------------------------------------------------------------- finalize   */

function finalizeExam($attempt_id, $status) {
    global $db, $user_id, $chat_id;
    $attempt_id = intval($attempt_id);
    $a = examLoadAttempt($attempt_id);
    if (!$a || $a['status'] !== 'in_progress') return; // guard double-finalize
    $status = ($status === 'expired') ? 'expired' : 'completed';

    $total = max(1, intval($a['num_questions']));
    $res = mysqli_query($db, "SELECT COUNT(*) AS c FROM exam_attempt_questions
                               WHERE attempt_id = $attempt_id AND is_correct = 1");
    $correct = $res ? intval(mysqli_fetch_assoc($res)['c']) : 0;
    if ($res) mysqli_free_result($res);

    $grade = (int) round($correct / $total * 100);

    mysqli_query($db, "UPDATE exam_attempts
                          SET status = '$status', num_correct = $correct, grade = $grade, finished_at = NOW()
                        WHERE id = $attempt_id");
    mysqli_query($db, "UPDATE users SET active_exam_attempt_id = NULL WHERE id = " . intval($user_id));
    writeLog(37, $attempt_id); // ExamCompleted (finish or expiry)

    showExamResults($attempt_id, $status);
}

/** Discard an attempt (the "stop" button confirmed). Result is NOT recorded. */
function cancelExam($attempt_id) {
    global $db, $user_id, $chat_id;
    $attempt_id = intval($attempt_id);
    $a = examLoadAttempt($attempt_id);
    if (!$a || $a['status'] !== 'in_progress' || intval($a['user_id']) !== intval($user_id)) {
        showExamIntro($chat_id);
        return;
    }

    writeLog(38, $attempt_id); // ExamStopped — log BEFORE deleting the row
    mysqli_query($db, "UPDATE users SET active_exam_attempt_id = NULL WHERE id = " . intval($user_id));
    mysqli_query($db, "DELETE FROM exam_attempts WHERE id = $attempt_id"); // cascade clears eaq

    $rlm = examRlm();
    $msg = $rlm . "🛑 המבחן הופסק. לא נרשם ציון.\n" . $rlm . "השאלות שכבר ענית עליהן נספרו כרגיל.";
    $markup = ['inline_keyboard' => [
        [['text' => '📝 מבחן חדש', 'callback_data' => 'exam_start']],
        [['text' => '⬅️ חזרה לתפריט', 'callback_data' => 'menu_back']],
    ]];
    bot_message($chat_id, $msg, $markup);
}

/* ----------------------------------------------------------- cancel confirm  */

function showExamCancelConfirm($chat_id) {
    $rlm = examRlm();
    $msg = $rlm . "להפסיק את המבחן?\n" . $rlm . "לא תקבל ציון, אבל השאלות שכבר ענית עליהן יישמרו.";
    $markup = ['inline_keyboard' => [[
        ['text' => '🛑 כן, הפסק', 'callback_data' => 'exam_cancel_confirm'],
        ['text' => '↩️ המשך מבחן', 'callback_data' => 'exam_cancel_dismiss'],
    ]]];
    bot_message($chat_id, $msg, $markup);
}

/* ------------------------------------------------------------ results screen */

/** Per-lecture label for an exam breakdown row. */
function examLectureLabel($max_lecture) {
    if ($max_lecture === null) return "כללי";
    return "הרצאה " . intval($max_lecture);
}

/** Results for one attempt: grade, pass/fail, per-lecture, trend vs. last 3. */
function showExamResults($attempt_id, $status = 'completed') {
    global $db, $user_id, $chat_id;
    $attempt_id = intval($attempt_id);

    $res = mysqli_query($db, "SELECT num_questions, num_correct, grade,
                                     TIMESTAMPDIFF(SECOND, started_at, finished_at) AS secs
                                FROM exam_attempts WHERE id = $attempt_id LIMIT 1");
    if (!$res || mysqli_num_rows($res) === 0) { if ($res) mysqli_free_result($res); return; }
    $a = mysqli_fetch_assoc($res);
    mysqli_free_result($res);

    $total   = intval($a['num_questions']);
    $correct = intval($a['num_correct']);
    $grade   = intval($a['grade']);
    $secs    = max(0, intval($a['secs']));
    $pass    = getExamPassGrade();
    $rlm = examRlm();

    $msg  = $rlm . "🏁 סיום מבחן\n\n";
    if ($status === 'expired') $msg .= $rlm . "⏱ תם הזמן.\n";
    $msg .= $rlm . "ציון: " . examIso($grade) . " " . ($grade >= $pass ? "✅ עובר" : "❌ לא עובר") . "\n";
    $msg .= $rlm . examIso("$correct/$total") . " תשובות נכונות\n";
    $msg .= $rlm . "זמן: " . examIso(sprintf('%d:%02d', intdiv($secs, 60), $secs % 60)) . " דקות\n";
    $msg .= $rlm . examGradeComment($grade) . "\n";

    // Per-lecture breakdown for this attempt.
    $res = mysqli_query($db, "SELECT max_lecture, COUNT(*) AS total,
                                     SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct
                                FROM exam_attempt_questions
                               WHERE attempt_id = $attempt_id
                            GROUP BY max_lecture
                            ORDER BY max_lecture");
    if ($res && mysqli_num_rows($res) > 0) {
        $msg .= "\n" . $rlm . "לפי הרצאה:\n";
        while ($r = mysqli_fetch_assoc($res)) {
            $lbl = examLectureLabel($r['max_lecture']);
            $c = intval($r['correct']); $t = intval($r['total']);
            $msg .= $rlm . "• $lbl: " . examIso("$c/$t") . "\n";
        }
    }
    if ($res) mysqli_free_result($res);

    // Trend: average of the latest 3 graded attempts.
    $avg = examLatestAverage($user_id, 3);
    if ($avg !== null) {
        $msg .= "\n" . $rlm . "ממוצע 3 המבחנים האחרונים: " . examIso($avg);
    }

    $markup = ['inline_keyboard' => [
        [['text' => '🔁 מבחן נוסף', 'callback_data' => 'exam_start']],
        [['text' => '📈 כל התוצאות שלי', 'callback_data' => 'menu_exam_results']],
        [['text' => '⬅️ חזרה לתפריט', 'callback_data' => 'menu_back']],
    ]];
    bot_message($chat_id, $msg, $markup);
}

/* --------------------------------------------------------- history / feedback */

/** Average grade over the latest $n graded attempts, or null if none. */
function examLatestAverage($user_id, $n = 3) {
    global $db;
    $uid = intval($user_id); $n = max(1, intval($n));
    $res = mysqli_query($db, "SELECT ROUND(AVG(grade)) AS avg_grade FROM (
                                  SELECT grade FROM exam_attempts
                                   WHERE user_id = $uid AND grade IS NOT NULL
                                     AND status IN ('completed','expired')
                                ORDER BY finished_at DESC LIMIT $n) t");
    if (!$res || mysqli_num_rows($res) === 0) { if ($res) mysqli_free_result($res); return null; }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    return ($row['avg_grade'] === null) ? null : intval($row['avg_grade']);
}

/** A 10-cell unicode bar for a 0..100 grade. */
function examGradeBar($grade) {
    $filled = (int) round(intval($grade) / 10);
    $filled = max(0, min(10, $filled));
    return str_repeat("▰", $filled) . str_repeat("▱", 10 - $filled);
}

/**
 * The student's personal exam-results view: grade trend over time, the latest-3
 * average, and a per-lecture strength table (weakest first) aggregated across
 * all graded attempts. Text/unicode rendering — no Imagick dependency.
 */
function showExamHistory() {
    global $db, $user_id, $chat_id;
    if (!examFeatureEnabled($user_id)) { examUnderDevelopmentNotice($chat_id); return; }
    $uid = intval($user_id);
    $rlm = examRlm();

    // Count graded attempts.
    $res = mysqli_query($db, "SELECT COUNT(*) AS c FROM exam_attempts
                               WHERE user_id = $uid AND grade IS NOT NULL AND status IN ('completed','expired')");
    $count = $res ? intval(mysqli_fetch_assoc($res)['c']) : 0;
    if ($res) mysqli_free_result($res);

    if ($count === 0) {
        $markup = ['inline_keyboard' => [
            [['text' => '▶️ התחל מבחן', 'callback_data' => 'exam_start']],
            [['text' => '⬅️ חזרה לתפריט', 'callback_data' => 'menu_back']],
        ]];
        bot_message($chat_id, $rlm . "📈 עדיין לא עשית מבחן תרגול.\n" . $rlm . "התחל מבחן כדי לראות כאן את ההתקדמות שלך.", $markup);
        return;
    }

    $msg = $rlm . "📈 התוצאות שלי במבחנים\n\n";

    $avg = examLatestAverage($uid, 3);
    if ($avg !== null) {
        $pass = getExamPassGrade();
        $msg .= $rlm . "ממוצע 3 האחרונים: " . examIso($avg) . " " . ($avg >= $pass ? "✅" : "") . "\n";
    }
    $msg .= $rlm . "סה\"כ מבחנים: " . examIso($count) . "\n\n";

    // Trend over time (oldest → newest), last 10.
    $res = mysqli_query($db, "SELECT grade, DATE_FORMAT(finished_at, '%d/%m') AS d FROM (
                                  SELECT grade, finished_at FROM exam_attempts
                                   WHERE user_id = $uid AND grade IS NOT NULL AND status IN ('completed','expired')
                                ORDER BY finished_at DESC LIMIT 10) t
                            ORDER BY finished_at ASC");
    if ($res && mysqli_num_rows($res) > 0) {
        $msg .= $rlm . "מגמה (10 אחרונים):\n";
        while ($r = mysqli_fetch_assoc($res)) {
            $g = intval($r['grade']);
            $msg .= $rlm . examIso(str_pad($g, 3, ' ', STR_PAD_LEFT)) . " " . examGradeBar($g) . " " . examIso($r['d']) . "\n";
        }
    }
    if ($res) mysqli_free_result($res);

    // Per-lecture strength across all attempts, weakest first.
    $res = mysqli_query($db, "SELECT eaq.max_lecture,
                                     COUNT(*) AS total,
                                     SUM(CASE WHEN eaq.is_correct = 1 THEN 1 ELSE 0 END) AS correct
                                FROM exam_attempt_questions eaq
                                JOIN exam_attempts a ON a.id = eaq.attempt_id
                               WHERE a.user_id = $uid AND a.status IN ('completed','expired')
                            GROUP BY eaq.max_lecture
                            ORDER BY (SUM(CASE WHEN eaq.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*)) ASC");
    if ($res && mysqli_num_rows($res) > 0) {
        $msg .= "\n" . $rlm . "לפי הרצאה (מהחלש לחזק):\n";
        while ($r = mysqli_fetch_assoc($res)) {
            $lbl = examLectureLabel($r['max_lecture']);
            $c = intval($r['correct']); $t = max(1, intval($r['total']));
            $pct = (int) round($c / $t * 100);
            $msg .= $rlm . "• $lbl: " . examIso($pct . "%") . " " . examIso("($c/$t)") . "\n";
        }
    }
    if ($res) mysqli_free_result($res);

    $markup = ['inline_keyboard' => [
        [['text' => '🔁 מבחן נוסף', 'callback_data' => 'exam_start']],
        [['text' => '⬅️ חזרה לתפריט', 'callback_data' => 'menu_back']],
    ]];
    bot_message($chat_id, $msg, $markup);
}
