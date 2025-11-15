<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$deviceId = $_GET['device_id'] ?? '';

if (empty($deviceId)) {
    jsonResponse(['success' => false, 'message' => 'Device ID required']);
}

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

use App\GenieACS;

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

$deviceResult = $genieacs->getDevice($deviceId);

if ($deviceResult['success']) {
    $parsed = $genieacs->parseDeviceData($deviceResult['data']);
    jsonResponse(['success' => true, 'device' => $parsed]);
} else {
    jsonResponse(['success' => false, 'message' => 'Device tidak ditemukan']);
}
