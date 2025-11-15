<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);

$host = $data['host'] ?? '';
$port = $data['port'] ?? 7557;
$username = $data['username'] ?? null;
$password = $data['password'] ?? null;

if (empty($host)) {
    jsonResponse(['success' => false, 'message' => 'Host harus diisi']);
}

// Save to database without testing
$conn = getDBConnection();

// Check if already exists
$result = $conn->query("SELECT id FROM genieacs_credentials LIMIT 1");
$existing = $result->fetch_assoc();

if ($existing) {
    // Update existing record (keep is_connected status)
    $stmt = $conn->prepare("UPDATE genieacs_credentials SET host = ?, port = ?, username = ?, password = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("sissi", $host, $port, $username, $password, $existing['id']);
} else {
    // Insert new record (first time setup, not connected yet)
    $stmt = $conn->prepare("INSERT INTO genieacs_credentials (host, port, username, password, is_connected) VALUES (?, ?, ?, ?, 0)");
    $stmt->bind_param("siss", $host, $port, $username, $password);
}

if ($stmt->execute()) {
    jsonResponse(['success' => true, 'message' => 'Konfigurasi GenieACS berhasil disimpan']);
} else {
    jsonResponse(['success' => false, 'message' => 'Gagal menyimpan konfigurasi']);
}
