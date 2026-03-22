<?php

require_once dirname(__DIR__, 2) . '/config.php';

$conn = $db;

if (!$conn) {
    die('Connection failed.');
}
?>