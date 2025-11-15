<?php
namespace App;

/**
 * Centralized Logger for GACS Dashboard
 * Provides structured logging to files with rotation
 */
class Logger {
    private $logDir;
    private $maxFileSize = 10485760; // 10MB
    private $maxFiles = 5;

    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_CRITICAL = 'CRITICAL';

    public function __construct($logDir = '/var/log/gacs') {
        $this->logDir = $logDir;

        // Create log directory if not exists
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     * @param string $channel Log channel (default: 'app')
     * @return bool Success status
     */
    public function log($level, $message, $context = [], $channel = 'app') {
        $logFile = $this->logDir . '/' . $channel . '.log';

        // Check file size and rotate if needed
        if (file_exists($logFile) && filesize($logFile) > $this->maxFileSize) {
            $this->rotateLog($logFile);
        }

        // Format log entry
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context) : '';
        $logEntry = "[{$timestamp}] [{$level}] {$message}";

        if ($contextStr) {
            $logEntry .= " | Context: {$contextStr}";
        }

        $logEntry .= PHP_EOL;

        // Write to file
        return file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Rotate log file
     *
     * @param string $logFile Log file path
     * @return void
     */
    private function rotateLog($logFile) {
        // Delete oldest log if max files reached
        $oldestLog = $logFile . '.' . $this->maxFiles;
        if (file_exists($oldestLog)) {
            unlink($oldestLog);
        }

        // Shift existing logs
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $old = $logFile . '.' . $i;
            $new = $logFile . '.' . ($i + 1);
            if (file_exists($old)) {
                rename($old, $new);
            }
        }

        // Rename current log to .1
        rename($logFile, $logFile . '.1');
    }

    // Convenience methods
    public function debug($message, $context = [], $channel = 'app') {
        return $this->log(self::LEVEL_DEBUG, $message, $context, $channel);
    }

    public function info($message, $context = [], $channel = 'app') {
        return $this->log(self::LEVEL_INFO, $message, $context, $channel);
    }

    public function warning($message, $context = [], $channel = 'app') {
        return $this->log(self::LEVEL_WARNING, $message, $context, $channel);
    }

    public function error($message, $context = [], $channel = 'app') {
        return $this->log(self::LEVEL_ERROR, $message, $context, $channel);
    }

    public function critical($message, $context = [], $channel = 'app') {
        return $this->log(self::LEVEL_CRITICAL, $message, $context, $channel);
    }

    /**
     * Log telegram bot activity
     *
     * @param string $chatId User chat ID
     * @param string $command Command executed
     * @param bool $success Success status
     * @param string $error Error message if failed
     * @return bool
     */
    public function logTelegramActivity($chatId, $command, $success = true, $error = '') {
        $level = $success ? self::LEVEL_INFO : self::LEVEL_ERROR;
        $message = $success ? "Command executed: {$command}" : "Command failed: {$command}";
        $context = [
            'chat_id' => $chatId,
            'command' => $command,
            'success' => $success,
            'error' => $error
        ];

        return $this->log($level, $message, $context, 'telegram');
    }

    /**
     * Log webhook activity
     *
     * @param string $action Webhook action
     * @param bool $success Success status
     * @param string $details Additional details
     * @return bool
     */
    public function logWebhook($action, $success = true, $details = '') {
        $level = $success ? self::LEVEL_INFO : self::LEVEL_WARNING;
        $message = "Webhook {$action}";
        $context = [
            'action' => $action,
            'success' => $success,
            'details' => $details
        ];

        return $this->log($level, $message, $context, 'webhook');
    }

    /**
     * Log GenieACS API calls
     *
     * @param string $method API method
     * @param string $endpoint Endpoint called
     * @param bool $success Success status
     * @param int $responseCode HTTP response code
     * @return bool
     */
    public function logGenieACS($method, $endpoint, $success = true, $responseCode = 200) {
        $level = $success ? self::LEVEL_INFO : self::LEVEL_ERROR;
        $message = "{$method} {$endpoint}";
        $context = [
            'method' => $method,
            'endpoint' => $endpoint,
            'success' => $success,
            'response_code' => $responseCode
        ];

        return $this->log($level, $message, $context, 'genieacs');
    }
}
