#!/usr/bin/env php
<?php
/**
 * Device Monitor - Cron Job
 *
 * Monitor device status and send Telegram notifications
 *
 * SETUP CRON JOB:
 * Run this script every 5 minutes via crontab.
 *
 * To edit crontab:
 *   crontab -e
 *
 * Add this line (replace /path/to/project with your actual project path):
 *   */5 * * * * /usr/bin/php /path/to/project/cron/device-monitor.php >> /var/log/gacs-monitor.log 2>&1
 *
 * Example:
 *   */5 * * * * /usr/bin/php /var/www/html/gacs-dashboard/cron/device-monitor.php >> /var/log/gacs-monitor.log 2>&1
 *
 * To find your project path, run this command from project root:
 *   pwd
 *
 * Alternative intervals:
 *   */1 * * * *  - Every 1 minute (not recommended, too frequent)
 *   */5 * * * *  - Every 5 minutes (recommended)
 *   */10 * * * * - Every 10 minutes
 *   */15 * * * * - Every 15 minutes
 *   0 * * * *    - Every hour
 */

require_once __DIR__ . '/../config/config.php';

use App\GenieACS;
use App\TelegramBot;

echo "[" . date('Y-m-d H:i:s') . "] Device Monitor Started\n";

// Get GenieACS credentials
$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$genieacsConfig = $result->fetch_assoc();

if (!$genieacsConfig) {
    echo "GenieACS not configured. Exiting.\n";
    exit;
}

// Get Telegram config
$telegramResult = $conn->query("SELECT * FROM telegram_config WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$telegramConfig = $telegramResult->fetch_assoc();

$telegram = null;
if ($telegramConfig) {
    $telegram = new TelegramBot($telegramConfig['bot_token'], $telegramConfig['chat_id']);
}

// Get devices from GenieACS
$genieacs = new GenieACS(
    $genieacsConfig['host'],
    $genieacsConfig['port'],
    $genieacsConfig['username'],
    $genieacsConfig['password']
);

$devicesResult = $genieacs->getDevices();

if (!$devicesResult['success']) {
    echo "Failed to fetch devices from GenieACS\n";
    exit;
}

$devices = $devicesResult['data'];
echo "Found " . count($devices) . " devices\n";

foreach ($devices as $device) {
    $parsed = $genieacs->parseDeviceData($device);
    $deviceId = $parsed['device_id'];
    $currentStatus = $parsed['status'] === 'Online' ? 'online' : 'offline';

    // Get last known status
    $stmt = $conn->prepare("SELECT status, notified FROM device_monitoring WHERE device_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("s", $deviceId);
    $stmt->execute();
    $lastRecord = $stmt->get_result()->fetch_assoc();

    $lastStatus = $lastRecord ? $lastRecord['status'] : null;
    $wasNotified = $lastRecord ? $lastRecord['notified'] : 0;

    // Check if status changed
    if ($lastStatus !== $currentStatus) {
        echo "Device {$deviceId}: {$lastStatus} -> {$currentStatus}\n";

        // Insert new record
        $stmt = $conn->prepare("INSERT INTO device_monitoring (device_id, status, notified) VALUES (?, ?, 0)");
        $stmt->bind_param("ss", $deviceId, $currentStatus);
        $stmt->execute();

        // Send notification if Telegram is configured
        if ($telegram && !$wasNotified) {
            $deviceInfo = [
                'serial_number' => $parsed['serial_number'],
                'ip_tr069' => $parsed['ip_tr069']
            ];

            // Get customer name if ONU is mapped
            $stmt = $conn->prepare("SELECT customer_name FROM onu_config WHERE genieacs_device_id = ?");
            $stmt->bind_param("s", $deviceId);
            $stmt->execute();
            $onuConfig = $stmt->get_result()->fetch_assoc();

            if ($onuConfig) {
                $deviceInfo['customer_name'] = $onuConfig['customer_name'];
            }

            $telegram->sendDeviceStatus($deviceId, $currentStatus, $deviceInfo);

            // Mark as notified
            $stmt = $conn->prepare("UPDATE device_monitoring SET notified = 1 WHERE device_id = ? AND status = ? ORDER BY created_at DESC LIMIT 1");
            $stmt->bind_param("ss", $deviceId, $currentStatus);
            $stmt->execute();

            echo "Notification sent for {$deviceId}\n";
        }
    }
}

// Cleanup old records (keep last 30 days)
$conn->query("DELETE FROM device_monitoring WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");

echo "[" . date('Y-m-d H:i:s') . "] Device Monitor Finished\n";
