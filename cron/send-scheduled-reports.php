<?php
/**
 * Send Scheduled Reports via Telegram Bot
 *
 * This script should be run every hour via cron:
 * 0 * * * * /usr/bin/php /path/to/htdocs/cron/send-scheduled-reports.php
 *
 * It checks for scheduled reports that should be sent at the current time
 * and sends them to subscribed users.
 */

require_once __DIR__ . '/../config/config.php';

use App\TelegramBot;
use App\GenieACS;
use App\ReportGenerator;

$conn = getDBConnection();

// Get current time and day of week
$currentTime = date('H:i:00'); // Format: HH:MM:00
$currentDay = date('w'); // 0 (Sunday) to 6 (Saturday)
$today = date('Y-m-d');

echo "[" . date('Y-m-d H:i:s') . "] Checking for scheduled reports...\n";

// Get GenieACS configuration
$genieacsResult = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$genieacsConfig = $genieacsResult->fetch_assoc();

$genieacs = null;
if ($genieacsConfig) {
    $genieacs = new GenieACS(
        $genieacsConfig['host'],
        $genieacsConfig['port'],
        $genieacsConfig['username'],
        $genieacsConfig['password']
    );
    echo "✓ GenieACS configured\n";
} else {
    echo "✗ GenieACS not configured - reports will have limited data\n";
}

// Initialize report generator
$reportGen = new ReportGenerator($conn, $genieacs);

// Find daily reports scheduled for current time
$stmt = $conn->prepare("
    SELECT rs.*, tc.bot_token, tc.chat_id as default_chat_id
    FROM telegram_report_schedules rs
    LEFT JOIN telegram_config tc ON tc.is_connected = 1
    WHERE rs.is_active = 1
    AND rs.report_type = 'daily'
    AND TIME(rs.schedule_time) = ?
    AND (rs.last_sent_at IS NULL OR DATE(rs.last_sent_at) < ?)
    ORDER BY rs.id
");
$stmt->bind_param("ss", $currentTime, $today);
$stmt->execute();
$dailySchedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo "Found " . count($dailySchedules) . " daily report(s) to send\n";

foreach ($dailySchedules as $schedule) {
    $chatId = $schedule['chat_id'];
    $botToken = $schedule['bot_token'];

    if (!$botToken) {
        echo "✗ No bot token found for chat_id: {$chatId}\n";
        continue;
    }

    echo "Generating daily report for chat_id: {$chatId}...\n";

    try {
        // Generate daily report
        $report = $reportGen->generateDailyReport($today);

        // Format message
        $message = $reportGen->formatReportMessage($report);

        // Send via Telegram
        $telegram = new TelegramBot($botToken, $chatId);
        $result = $telegram->sendMessage($message, $chatId);

        if (isset($result['ok']) && $result['ok']) {
            echo "✓ Daily report sent successfully to {$chatId}\n";

            // Log report
            $reportGen->logReport($chatId, $report);

            // Update last_sent_at
            $now = date('Y-m-d H:i:s');
            $updateStmt = $conn->prepare("UPDATE telegram_report_schedules SET last_sent_at = ? WHERE id = ?");
            $updateStmt->bind_param("si", $now, $schedule['id']);
            $updateStmt->execute();
        } else {
            echo "✗ Failed to send daily report to {$chatId}\n";
            echo "Response: " . json_encode($result) . "\n";
        }
    } catch (Exception $e) {
        echo "✗ Error generating/sending daily report: " . $e->getMessage() . "\n";
    }
}

// Find weekly reports scheduled for current time and day
$stmt = $conn->prepare("
    SELECT rs.*, tc.bot_token, tc.chat_id as default_chat_id
    FROM telegram_report_schedules rs
    LEFT JOIN telegram_config tc ON tc.is_connected = 1
    WHERE rs.is_active = 1
    AND rs.report_type = 'weekly'
    AND TIME(rs.schedule_time) = ?
    AND rs.schedule_day = ?
    AND (rs.last_sent_at IS NULL OR DATE(rs.last_sent_at) < ?)
    ORDER BY rs.id
");
$stmt->bind_param("sis", $currentTime, $currentDay, $today);
$stmt->execute();
$weeklySchedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

echo "Found " . count($weeklySchedules) . " weekly report(s) to send\n";

foreach ($weeklySchedules as $schedule) {
    $chatId = $schedule['chat_id'];
    $botToken = $schedule['bot_token'];

    if (!$botToken) {
        echo "✗ No bot token found for chat_id: {$chatId}\n";
        continue;
    }

    echo "Generating weekly report for chat_id: {$chatId}...\n";

    try {
        // Generate weekly report
        $report = $reportGen->generateWeeklyReport($today);

        // Format message
        $message = $reportGen->formatReportMessage($report);

        // Send via Telegram
        $telegram = new TelegramBot($botToken, $chatId);
        $result = $telegram->sendMessage($message, $chatId);

        if (isset($result['ok']) && $result['ok']) {
            echo "✓ Weekly report sent successfully to {$chatId}\n";

            // Log report
            $reportGen->logReport($chatId, $report);

            // Update last_sent_at
            $now = date('Y-m-d H:i:s');
            $updateStmt = $conn->prepare("UPDATE telegram_report_schedules SET last_sent_at = ? WHERE id = ?");
            $updateStmt->bind_param("si", $now, $schedule['id']);
            $updateStmt->execute();
        } else {
            echo "✗ Failed to send weekly report to {$chatId}\n";
            echo "Response: " . json_encode($result) . "\n";
        }
    } catch (Exception $e) {
        echo "✗ Error generating/sending weekly report: " . $e->getMessage() . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Scheduled reports check complete\n";
echo str_repeat('-', 60) . "\n";

$conn->close();
