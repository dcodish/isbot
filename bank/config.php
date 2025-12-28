<?php
//////////////////////////CONNECT TO DATABASE ////////////////////
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

//$db = mysqli_connect(
//    getenv('DB_HOST'),
//    getenv('DB_USER'),
//    getenv('DB_PASS'),
//    getenv('DB_NAME')
//);

$db = mysqli_connect(
    "localhost",
    "root",
    "5400",
    "bot"
);

mysqli_set_charset($db, 'utf8mb4');

$lastSQ = 0;
///////////////////////////////////////////////////////////////////
///
///
define('TOKEN', '8210054669:AAGiinrx5q8Yoqgv6jheae6XGCgUf_5d4dM');
define('DEBUG','ON');
$API_URL = 'https://api.telegram.org/bot'.TOKEN."/";

/////////////////////////INFORMATION///////////////////////////////////
$channel_id = "@ISQ_devA_bot";
$username_bot = "ISQuestions_devA";
$user_id_bot = "8210054669";
$user_id_admin = "1671626997";