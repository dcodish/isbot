<?php
/**
 * Smoke test for the exam-mode question selector. Prints a sample stratified
 * exam (no Telegram, no DB writes) so you can eyeball lecture/level spread.
 *
 * Usage: php tools/exam_sample.php [num_questions] [runs]
 *   num_questions  override the count (default: settings.exam_num_questions)
 *   runs           how many independent samples to draw (default: 1)
 *
 * Selection is gated by the GLOBAL settings.current_week (user_id 0 → no cohort).
 */

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once dirname(__DIR__) . '/bot_functions.php';

$num  = isset($argv[1]) ? max(1, intval($argv[1])) : getExamNumQuestions();
$runs = isset($argv[2]) ? max(1, intval($argv[2])) : 1;

$week = getCurrentWeek(0);
fwrite(STDOUT, "Global current_week = $week  |  requesting $num questions  |  $runs run(s)\n");
fwrite(STDOUT, str_repeat('=', 70) . "\n");

$levelName = [0 => 'probation', 1 => 'L1', 2 => 'L2', 3 => 'L3', 4 => 'L4'];

for ($r = 1; $r <= $runs; $r++) {
    $qs = selectExamQuestions(0, $num);
    fwrite(STDOUT, "\nRun $r — got " . count($qs) . " question(s):\n");

    $byLec = [];
    $byLvl = [];
    foreach ($qs as $i => $q) {
        $lec = ($q['max_lecture'] === null) ? 'gen' : intval($q['max_lecture']);
        $lvl = intval($q['qlevel']);
        $byLec[$lec] = ($byLec[$lec] ?? 0) + 1;
        $byLvl[$lvl] = ($byLvl[$lvl] ?? 0) + 1;

        $stem = preg_replace('/\s+/u', ' ', trim($q['question_text']));
        if (mb_strlen($stem) > 60) $stem = mb_substr($stem, 0, 60) . '…';
        $n = $i + 1;
        fwrite(STDOUT, sprintf("  %2d. id=%-5d lec=%-3s %-9s  %s\n",
            $n, intval($q['id']), $lec, $levelName[$lvl] ?? '?', $stem));
    }

    ksort($byLec);
    ksort($byLvl);
    $lecStr = implode(', ', array_map(fn($k, $v) => "$k:$v", array_keys($byLec), array_values($byLec)));
    $lvlStr = implode(', ', array_map(fn($k, $v) => ($levelName[$k] ?? $k) . ":$v", array_keys($byLvl), array_values($byLvl)));
    fwrite(STDOUT, "     spread by lecture: [$lecStr]\n");
    fwrite(STDOUT, "     spread by level:   [$lvlStr]\n");
}

mysqli_close($db);
