<?php
/**
 * Dumps all questions to runtime/questions_export.txt in pipe-delimited format.
 * Used for lecture-tagging: one-time data-prep task.
 *
 * Usage: php tools/export_questions_text.php
 * Reads DB credentials from .env in the project root.
 */

$root = dirname(__DIR__);

// Parse .env manually (no Composer needed)
$env = [];
foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$key, $val] = explode('=', $line, 2);
    $env[trim($key)] = trim($val, " \t\n\r\0\x0B\"'");
}

$host = $env['DB_HOST'] ?? 'localhost';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';
$name = $env['DB_NAME'] ?? 'bot';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage() . "\n");
}

$rows = $pdo->query("SELECT id, question_text, option1, option2, option3, option4 FROM questions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$lines = [];
foreach ($rows as $r) {
    $lines[] = $r['id'] . ' | ' . $r['question_text'] . ' | ' . $r['option1'] . ' | ' . $r['option2'] . ' | ' . $r['option3'] . ' | ' . $r['option4'];
}

$outPath = $root . '/runtime/questions_export.txt';
if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0755, true);
}
file_put_contents($outPath, implode("\n", $lines) . "\n");

echo "Exported " . count($lines) . " questions to runtime/questions_export.txt\n";
