<?php
/**
 * Register the bot's slash-command menu with Telegram (setMyCommands), so the "/"
 * autocomplete and the in-chat Menu button list these commands and tapping works.
 *
 * Usage: /opt/plesk/php/8.2/bin/php tools/set_bot_commands.php
 *
 * Telegram command names must be lowercase a–z, digits, or underscore (1–32 chars)
 * — no hyphens — so exam mode is registered as `exam_mode` (the `/exam-mode` and
 * `/exam` aliases still work when typed; see index.php). Descriptions are the
 * Hebrew labels students see.
 *
 * Re-run after changing the command set. Idempotent — setMyCommands replaces the
 * whole list each call.
 */

require_once dirname(__DIR__) . '/bootstrap/app.php';

global $API_URL;
if (empty($API_URL)) {
    fwrite(STDERR, "No API_URL / BOT_TOKEN configured — aborting.\n");
    exit(1);
}

// Order here is the order shown in the Telegram menu.
$commands = [
    ['command' => 'exam_mode',   'description' => '📝 מבחן תרגול'],
    ['command' => 'menu',        'description' => '🎯 תפריט ראשי'],
    ['command' => 'stats',       'description' => '📊 הסטטיסטיקות שלי'],
    ['command' => 'leaderboard', 'description' => '🏆 טבלת מובילים'],
    ['command' => 'semester',    'description' => '🗓️ בחירת סמסטר'],
    ['command' => 'start',       'description' => '🔄 התחלה מחדש'],
];

$payload = json_encode(['commands' => $commands], JSON_UNESCAPED_UNICODE);

$ch = curl_init($API_URL . 'setMyCommands');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res = curl_exec($ch);
if ($res === false) {
    fwrite(STDERR, "setMyCommands request failed: " . curl_error($ch) . "\n");
    exit(1);
}
curl_close($ch);

echo "Sent " . count($commands) . " commands.\nTelegram response: $res\n";
