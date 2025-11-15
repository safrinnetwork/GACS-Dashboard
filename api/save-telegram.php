<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);

$botToken = $data['bot_token'] ?? '';
$chatId = $data['chat_id'] ?? '';

if (empty($botToken) || empty($chatId)) {
    jsonResponse(['success' => false, 'message' => 'Bot Token dan Chat ID harus diisi']);
}

// Save to database without testing
$conn = getDBConnection();

// Check if already exists
$result = $conn->query("SELECT id FROM telegram_config LIMIT 1");
$existing = $result->fetch_assoc();

if ($existing) {
    // Update existing record (keep is_connected status)
    $stmt = $conn->prepare("UPDATE telegram_config SET bot_token = ?, chat_id = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $botToken, $chatId, $existing['id']);
} else {
    // Insert new record (first time setup, not connected yet)
    $stmt = $conn->prepare("INSERT INTO telegram_config (bot_token, chat_id, is_connected) VALUES (?, ?, 0)");
    $stmt->bind_param("ss", $botToken, $chatId);
}

if ($stmt->execute()) {
    jsonResponse(['success' => true, 'message' => 'Konfigurasi Telegram Bot berhasil disimpan']);
} else {
    jsonResponse(['success' => false, 'message' => 'Gagal menyimpan konfigurasi']);
}
