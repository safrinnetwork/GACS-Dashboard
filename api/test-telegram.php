<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['bot_token'])) {
    jsonResponse(['success' => false, 'message' => 'Bot Token harus diisi']);
}

$botToken = $data['bot_token'];
$chatId = $data['chat_id'] ?? '';

// Test bot token by calling getMe API
$url = "https://api.telegram.org/bot{$botToken}/getMe";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    jsonResponse(['success' => false, 'message' => "Connection error: {$error}"]);
}

$result = json_decode($response, true);

if (!$result || !$result['ok']) {
    $errorMsg = $result['description'] ?? 'Invalid bot token';
    jsonResponse(['success' => false, 'message' => $errorMsg]);
}

// Test sending message if chat_id provided
if (!empty($chatId)) {
    $testMessage = "✅ Test message from GACS Dashboard";
    $sendUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";

    $ch = curl_init($sendUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'chat_id' => $chatId,
        'text' => $testMessage
    ]));

    $sendResponse = curl_exec($ch);
    $sendResult = json_decode($sendResponse, true);
    curl_close($ch);

    if (!$sendResult || !$sendResult['ok']) {
        jsonResponse(['success' => false, 'message' => 'Bot token valid but failed to send message. Check Chat ID.']);
    }
}

// Save credentials if test successful
$conn = getDBConnection();

// Check if record exists
$result = $conn->query("SELECT id FROM telegram_config LIMIT 1");
$existing = $result->fetch_assoc();

if ($existing) {
    // Update existing record
    $stmt = $conn->prepare("UPDATE telegram_config SET bot_token = ?, chat_id = ?, is_connected = 1, last_test = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $botToken, $chatId, $existing['id']);
} else {
    // Insert new record (first time setup)
    $stmt = $conn->prepare("INSERT INTO telegram_config (bot_token, chat_id, is_connected, last_test) VALUES (?, ?, 1, NOW())");
    $stmt->bind_param("ss", $botToken, $chatId);
}

$stmt->execute();

$message = !empty($chatId) ? 'Connected to Telegram and test message sent!' : 'Bot token is valid!';
jsonResponse(['success' => true, 'message' => $message]);
