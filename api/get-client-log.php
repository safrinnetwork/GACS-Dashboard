<?php
/**
 * API endpoint untuk membaca client-side logs
 * Hanya bisa diakses oleh user yang sudah login
 */

require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

// Get query parameters
$lines = isset($_GET['lines']) ? (int)$_GET['lines'] : 100;
$level = isset($_GET['level']) ? strtoupper($_GET['level']) : null;

// Log file paths (try both)
$logFiles = [
    '/var/log/gacs-client.log',
    __DIR__ . '/../logs/client.log'
];

$logFile = null;
foreach ($logFiles as $file) {
    if (file_exists($file)) {
        $logFile = $file;
        break;
    }
}

if (!$logFile) {
    echo json_encode([
        'success' => false,
        'message' => 'Log file not found',
        'checked_paths' => $logFiles
    ]);
    exit;
}

// Read log file
$logContent = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

if ($logContent === false) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to read log file'
    ]);
    exit;
}

// Get last N lines
$logContent = array_slice($logContent, -$lines);

// Filter by level if specified
if ($level) {
    $logContent = array_filter($logContent, function($line) use ($level) {
        return strpos($line, "[{$level}]") !== false;
    });
}

// Parse log entries
$logs = [];
foreach ($logContent as $line) {
    // Parse log format: [timestamp] [level] [url] message
    if (preg_match('/^\[(.*?)\] \[(.*?)\] \[(.*?)\] (.*)$/', $line, $matches)) {
        $logs[] = [
            'timestamp' => $matches[1],
            'level' => $matches[2],
            'url' => $matches[3],
            'message' => $matches[4],
            'raw' => $line
        ];
    } else {
        // If line doesn't match format, add as raw
        $logs[] = [
            'timestamp' => null,
            'level' => 'UNKNOWN',
            'url' => null,
            'message' => $line,
            'raw' => $line
        ];
    }
}

echo json_encode([
    'success' => true,
    'logFile' => $logFile,
    'totalLines' => count($logs),
    'logs' => $logs
], JSON_PRETTY_PRINT);
