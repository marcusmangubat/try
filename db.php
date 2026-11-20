<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

define('DB_HOST', 'localhost:3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'lala');

date_default_timezone_set('Asia/Manila');
$database = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($database->connect_error) {
    die("Connection failed: " . $database->connect_error);
}
$database->set_charset('utf8mb4');
