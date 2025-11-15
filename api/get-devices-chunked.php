<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

// Set time limit for this script to handle large datasets
set_time_limit(300);
ini_set('max_execution_time', 300);

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

// Chunked loading parameters
$chunkSize = 50; // Load 50 devices at a time
$skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;

try {
    // Get devices with limit and skip for pagination
    $devicesResult = $genieacs->getDevices([], $chunkSize, $skip);

    if ($devicesResult['success']) {
        $devices = [];

        foreach ($devicesResult['data'] as $device) {
            $parsed = $genieacs->parseDeviceData($device);
            $devices[] = $parsed;
        }

        // Check if there are more devices to load
        $hasMore = count($devices) === $chunkSize;

        jsonResponse([
            'success' => true,
            'devices' => $devices,
            'count' => count($devices),
            'skip' => $skip,
            'chunkSize' => $chunkSize,
            'hasMore' => $hasMore,
            'nextSkip' => $skip + $chunkSize
        ]);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Gagal mengambil data devices',
            'error' => $devicesResult['error'] ?? 'Unknown error'
        ]);
    }
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}
