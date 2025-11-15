<?php
require_once __DIR__ . '/../config/config.php';

// Increase timeout for large dataset
set_time_limit(20);

header('Content-Type: application/json');
requireLogin();

// Check if GenieACS is configured
if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

// Get GenieACS credentials
$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

use App\GenieACS;
use App\GenieACS_Fast;

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

$devicesResult = $genieacs->getDevices();

if (!$devicesResult['success']) {
    jsonResponse(['success' => false, 'message' => 'Gagal mengambil data devices']);
}

// Categorize devices by RX Power signal strength
$excellent = 0; // > -20 dBm
$good = 0;      // -20 to -25 dBm
$fair = 0;      // -25 to -28 dBm
$poor = 0;      // < -28 dBm
$noSignal = 0;  // No data

// Use fast parser for better performance
foreach ($devicesResult['data'] as $device) {
    // Extract RX power directly using fast method
    $parsed = GenieACS_Fast::parseDeviceDataFast($device);
    $rxPower = $parsed['rx_power'];

    // Categorize by signal strength
    if ($rxPower === 'N/A' || $rxPower === '' || $rxPower === null) {
        $noSignal++;
    } else {
        $rxPower = floatval($rxPower);

        if ($rxPower > -20) {
            $excellent++;
        } elseif ($rxPower >= -25) {
            $good++;
        } elseif ($rxPower >= -28) {
            $fair++;
        } else {
            $poor++;
        }
    }
}

jsonResponse([
    'success' => true,
    'data' => [
        'excellent' => $excellent,
        'good' => $good,
        'fair' => $fair,
        'poor' => $poor,
        'no_signal' => $noSignal,
        'total' => count($devicesResult['data'])
    ]
]);
