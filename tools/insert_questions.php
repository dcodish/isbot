<?php
/**
 * Insert questions into the `questions` table from a JSON file.
 *
 * Usage: php tools/insert_questions.php <path-to-json>
 *
 * JSON format: array of objects, each with:
 *   - question_text (string)
 *   - option1..option4 (string)
 *   - correctans (int 1-4)
 *   - max_lecture (int 1-12, or null)
 *
 * Uses prepared statements (per CLAUDE.md convention for new queries).
 */

require_once dirname(__DIR__) . '/bootstrap/app.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/insert_questions.php <path-to-json>\n");
    exit(1);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "File not found: $path\n");
    exit(1);
}

$raw = file_get_contents($path);
$items = json_decode($raw, true);
if (!is_array($items)) {
    fwrite(STDERR, "Invalid JSON: " . json_last_error_msg() . "\n");
    exit(1);
}

$stmt = mysqli_prepare(
    $db,
    "INSERT INTO questions (question_text, option1, option2, option3, option4, correctans, max_lecture)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);
if (!$stmt) {
    fwrite(STDERR, "Prepare failed: " . mysqli_error($db) . "\n");
    exit(1);
}

$ok = 0;
$failed = [];
foreach ($items as $i => $q) {
    foreach (['question_text','option1','option2','option3','option4','correctans'] as $required) {
        if (!isset($q[$required])) {
            $failed[] = "item $i: missing '$required'";
            continue 2;
        }
    }
    $correctans = (int)$q['correctans'];
    if ($correctans < 1 || $correctans > 4) {
        $failed[] = "item $i: correctans must be 1-4, got $correctans";
        continue;
    }
    $max_lecture = isset($q['max_lecture']) && $q['max_lecture'] !== null ? (int)$q['max_lecture'] : null;

    mysqli_stmt_bind_param(
        $stmt,
        'sssssii',
        $q['question_text'],
        $q['option1'],
        $q['option2'],
        $q['option3'],
        $q['option4'],
        $correctans,
        $max_lecture
    );
    if (mysqli_stmt_execute($stmt)) {
        $id = mysqli_insert_id($db);
        echo "inserted id=$id  (L{$max_lecture})  " . mb_substr($q['question_text'], 0, 60) . "\n";
        $ok++;
    } else {
        $failed[] = "item $i: " . mysqli_stmt_error($stmt);
    }
}

mysqli_stmt_close($stmt);

echo "\n---\nInserted: $ok\n";
if ($failed) {
    echo "Failed: " . count($failed) . "\n";
    foreach ($failed as $f) echo "  - $f\n";
    exit(1);
}
