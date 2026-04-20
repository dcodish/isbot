<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$projectRoot = dirname(__DIR__);
$vendorAutoload = $projectRoot . '/vendor/autoload.php';

if (file_exists($vendorAutoload)) {
    require_once $vendorAutoload;
}

if (class_exists(\Dotenv\Dotenv::class) && file_exists($projectRoot . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createMutable($projectRoot);
    $dotenv->safeLoad();
}

if (!function_exists('env_value')) {
    function env_value($key, $default = null) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }
}

if (!function_exists('env_flag')) {
    function env_flag($key, $default = false) {
        $value = env_value($key, $default ? '1' : '0');
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}

$runtimeDir = $projectRoot . '/runtime';
if (!is_dir($runtimeDir)) {
    mkdir($runtimeDir, 0777, true);
}

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', $projectRoot);
}

if (!defined('RUNTIME_DIR')) {
    define('RUNTIME_DIR', $runtimeDir);
}

if (!defined('RESULT_LOG_PATH')) {
    define('RESULT_LOG_PATH', RUNTIME_DIR . '/updates.log');
}

$db = mysqli_connect(
    env_value('DB_HOST', 'localhost'),
    env_value('DB_USER', 'root'),
    env_value('DB_PASS', ''),
    env_value('DB_NAME', 'bot')
);

mysqli_set_charset($db, 'utf8mb4');

$lastSQ = 0;

if (!defined('TOKEN')) {
    define('TOKEN', env_value('BOT_TOKEN', ''));
}

if (!defined('DEBUG')) {
    define('DEBUG', env_flag('DEBUG', false) ? 'ON' : 'OFF');
}

$API_URL = TOKEN !== '' ? 'https://api.telegram.org/bot' . TOKEN . '/' : '';

require_once $projectRoot . '/BadgeService.php';

$channel_id = env_value('BOT_CHANNEL_ID', '');
$username_bot = env_value('BOT_USERNAME', '');
$user_id_bot = env_value('BOT_ID', '');
$user_id_admin = env_value('BOT_ADMIN_USER_ID', '');
