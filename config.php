<?php
session_start();

define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'Admin@123');
define('LOG_FILE', __DIR__ . '/logs/process.log');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('OUTPUT_DIR', __DIR__ . '/outputs/');
define('FFMPEG_PATH', '/usr/bin/ffmpeg');

if (!file_exists(__DIR__ . '/logs')) mkdir(__DIR__ . '/logs', 0777, true);
if (!file_exists(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
if (!file_exists(OUTPUT_DIR)) mkdir(OUTPUT_DIR, 0777, true);

function writeLog($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$type] $message\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

function checkAuth() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: index.php');
        exit();
    }
}

function getRecentLogs($lines = 100) {
    if (!file_exists(LOG_FILE)) return [];
    $file = file(LOG_FILE);
    $logs = array_slice($file, -$lines);
    return array_reverse($logs);
}
?>
