<?php
/**
 * API endpoint untuk menyimpan client-side logs dari browser
 * Logs akan disimpan ke file /var/log/gacs-client.log
 */

header('Content-Type: application/json');

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['level']) || !isset($data['message'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid log data'
    ]);
    exit;
}

$level = strtoupper($data['level']);
$message = $data['message'];
$timestamp = date('Y-m-d H:i:s');
$url = $data['url'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// Format log entry
$logEntry = sprintf(
    "[%s] [%s] [%s] %s\n",
    $timestamp,
    $level,
    $url,
    $message
);

// Log file path
$logFile = '/var/log/gacs-client.log';

// Try to write to log file
$result = @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

if ($result === false) {
    // Fallback to local directory if /var/log is not writable
    $logFile = __DIR__ . '/../logs/client.log';

    // Create logs directory if not exists
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $result = @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

if ($result !== false) {
    echo json_encode([
        'success' => true,
        'message' => 'Log saved',
        'logFile' => $logFile
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to write log file'
    ]);
}
