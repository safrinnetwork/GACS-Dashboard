<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['device_ids'])) {
    jsonResponse(['success' => false, 'message' => 'Invalid request parameters']);
}

$deviceIds = $input['device_ids']; // Array of device IDs

if (empty($deviceIds) || !is_array($deviceIds)) {
    jsonResponse(['success' => false, 'message' => 'No devices selected']);
}

// GenieACS API connection
$baseUrl = "http://{$credentials['host']}:{$credentials['port']}";
$auth = base64_encode("{$credentials['username']}:{$credentials['password']}");

$successCount = 0;
$failCount = 0;
$errors = [];

foreach ($deviceIds as $deviceId) {
    $deviceId = urlencode($deviceId);
    $url = "$baseUrl/devices/$deviceId";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Basic $auth"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $successCount++;
    } else {
        $failCount++;
        $errors[] = "Device $deviceId: HTTP $httpCode";
    }
}

if ($successCount > 0) {
    $message = "$successCount device(s) deleted successfully";

    if ($failCount > 0) {
        $message .= " ($failCount failed)";
    }

    jsonResponse([
        'success' => true,
        'message' => $message,
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'errors' => $errors
    ]);
} else {
    jsonResponse([
        'success' => false,
        'message' => 'Failed to delete all devices',
        'errors' => $errors
    ]);
}
