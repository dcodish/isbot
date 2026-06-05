<?php
/**
 * Registers the bot's Telegram command menu (the "/" list) via setMyCommands.
 *
 * Run on prod from the project root:
 *   /opt/plesk/php/8.2/bin/php tools/set_commands.php
 *
 * Telegram command names must be lowercase [a-z0-9_] (≤32 chars) — that's why
 * the user-facing commands are English; descriptions can be Hebrew. Commands
 * still work even if not listed here; this just controls the menu. Re-running
 * is safe (it replaces the default-scope list).
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../bot_functions.php';

// Mirrors the previously-curated menu, with /semester inserted. Keep existing
// wording; only add/remove deliberately.
$commands = [
    ['command' => 'start',          'description' => 'התחל / חזור לבוט'],
    ['command' => 'menu',           'description' => 'תפריט ראשי - לוח מובילים, תגים ועוד'],
    ['command' => 'semester',       'description' => 'בחירת או החלפת סמסטר'],
    ['command' => 'stats',          'description' => 'הסטטיסטיקות שלי'],
    ['command' => 'level',          'description' => 'הרמה הנוכחית שלי'],
    ['command' => 'changenickname', 'description' => 'שנה כינוי'],
    ['command' => 'clearstats',     'description' => 'אפס את ההתקדמות שלי'],
];

echo "--- existing commands (before) ---\n";
var_export(bot('getMyCommands'));
echo "\n\n--- setMyCommands ---\n";
$json = json_encode($commands, JSON_UNESCAPED_UNICODE);
var_export(bot('setMyCommands?commands=' . urlencode($json)));
echo "\n\n--- registered commands (after) ---\n";
var_export(bot('getMyCommands'));
echo "\n";
