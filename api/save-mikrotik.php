<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);

$host = $data['host'] ?? '';
$port = $data['port'] ?? 8728;
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

if (empty($host) || empty($username) || empty($password)) {
    jsonResponse(['success' => false, 'message' => 'Semua field harus diisi']);
}

// Save to database without testing
$conn = getDBConnection();

// Check if already exists
$result = $conn->query("SELECT id FROM mikrotik_credentials LIMIT 1");
$existing = $result->fetch_assoc();

if ($existing) {
    // Update existing record (keep is_connected status)
    $stmt = $conn->prepare("UPDATE mikrotik_credentials SET host = ?, port = ?, username = ?, password = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("sissi", $host, $port, $username, $password, $existing['id']);
} else {
    // Insert new record (first time setup, not connected yet)
    $stmt = $conn->prepare("INSERT INTO mikrotik_credentials (host, port, username, password, is_connected) VALUES (?, ?, ?, ?, 0)");
    $stmt->bind_param("siss", $host, $port, $username, $password);
}

if ($stmt->execute()) {
    jsonResponse(['success' => true, 'message' => 'Konfigurasi MikroTik berhasil disimpan']);
} else {
    jsonResponse(['success' => false, 'message' => 'Gagal menyimpan konfigurasi']);
}
