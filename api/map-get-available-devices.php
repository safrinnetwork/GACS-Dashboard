<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

$conn = getDBConnection();

// Get GenieACS credentials
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

// Get all devices from GenieACS
$devicesResult = $genieacs->getDevices();

if (!$devicesResult['success']) {
    jsonResponse(['success' => false, 'message' => 'Gagal mengambil data devices']);
}

// Get already assigned devices
$assignedDevices = [];
$stmt = $conn->prepare("SELECT genieacs_device_id FROM map_items WHERE genieacs_device_id IS NOT NULL");
$stmt->execute();
$assignedResult = $stmt->get_result();
while ($row = $assignedResult->fetch_assoc()) {
    $assignedDevices[] = $row['genieacs_device_id'];
}

// Filter available devices (not assigned yet)
$availableDevices = [];
foreach ($devicesResult['data'] as $device) {
    $deviceId = $device['_id'] ?? null;
    if ($deviceId && !in_array($deviceId, $assignedDevices)) {
        $parsed = $genieacs->parseDeviceData($device);
        $availableDevices[] = [
            'device_id' => $deviceId,
            'serial_number' => $parsed['serial_number'],
            'status' => $parsed['status']
        ];
    }
}

jsonResponse([
    'success' => true,
    'devices' => $availableDevices
]);
