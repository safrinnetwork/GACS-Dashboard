#!/usr/bin/env php
<?php
/**
 * Telegram Webhook Monitor
 * Checks webhook status every 5 minutes and auto-resets if empty
 *
 * Add to crontab:
 * */5 * * * * /usr/bin/php /path/to/your/project/cron/webhook-monitor.php
 */

require_once __DIR__ . '/../config/config.php';

// Get database connection
$db = getDBConnection();

// Get Telegram bot token from database
$stmt = $db->prepare("SELECT bot_token FROM telegram_config LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    error_log("[Webhook Monitor] No Telegram bot token configured");
    exit(1);
}

$config = $result->fetch_assoc();
$botToken = $config['bot_token'];

if (empty($botToken)) {
    error_log("[Webhook Monitor] Telegram bot token is empty");
    exit(1);
}

// Check webhook status
$url = "https://api.telegram.org/bot{$botToken}/getWebhookInfo";
$response = file_get_contents($url);
$data = json_decode($response, true);

if (!$data || !isset($data['result'])) {
    error_log("[Webhook Monitor] Failed to get webhook info");
    exit(1);
}

$webhookUrl = $data['result']['url'] ?? '';

// Get expected webhook URL from APP_URL constant
$expectedUrl = APP_URL . "/webhook/telegram.php";

// Check if webhook URL is empty or incorrect
if (empty($webhookUrl) || $webhookUrl !== $expectedUrl) {
    error_log("[Webhook Monitor] Webhook URL is empty or incorrect. Resetting...");
    error_log("[Webhook Monitor] Current: '{$webhookUrl}' | Expected: '{$expectedUrl}'");

    // Reset webhook
    $setUrl = "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($expectedUrl);
    $setResponse = file_get_contents($setUrl);
    $setData = json_decode($setResponse, true);

    if ($setData && $setData['ok']) {
        error_log("[Webhook Monitor] ✓ Webhook successfully reset to {$expectedUrl}");

        // Log to database
        $stmt = $db->prepare("INSERT INTO device_monitoring (device_id, old_status, new_status, checked_at) VALUES (?, ?, ?, NOW())");
        $device_id = 'telegram_webhook';
        $old_status = 'empty';
        $new_status = 'reset';
        $stmt->bind_param("sss", $device_id, $old_status, $new_status);
        $stmt->execute();
    } else {
        error_log("[Webhook Monitor] ✗ Failed to reset webhook");
        exit(1);
    }
} else {
    // Webhook is OK
    $pendingUpdates = $data['result']['pending_update_count'] ?? 0;
    $lastError = $data['result']['last_error_message'] ?? 'none';

    error_log("[Webhook Monitor] ✓ Webhook OK | Pending: {$pendingUpdates} | Last error: {$lastError}");
}

exit(0);
