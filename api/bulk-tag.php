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

if (!$input || !isset($input['action']) || !isset($input['device_ids']) || !isset($input['tag'])) {
    jsonResponse(['success' => false, 'message' => 'Invalid request parameters']);
}

$action = $input['action']; // 'add' or 'remove'
$deviceIds = $input['device_ids']; // Array of device IDs
$tagName = trim($input['tag']);

if (empty($deviceIds) || !is_array($deviceIds)) {
    jsonResponse(['success' => false, 'message' => 'No devices selected']);
}

if (empty($tagName)) {
    jsonResponse(['success' => false, 'message' => 'Tag name cannot be empty']);
}

// GenieACS API connection
$baseUrl = "http://{$credentials['host']}:{$credentials['port']}";
$auth = base64_encode("{$credentials['username']}:{$credentials['password']}");

$successCount = 0;
$failCount = 0;
$errors = [];
$debugInfo = []; // For debugging

foreach ($deviceIds as $deviceId) {
    $originalDeviceId = $deviceId;
    $deviceId = urlencode($deviceId);

    if ($action === 'add') {
        // Add tag to device
        $url = "$baseUrl/devices/$deviceId/tags/$tagName";
        $method = 'POST';
    } else if ($action === 'remove') {
        // Remove tag from device
        $url = "$baseUrl/devices/$deviceId/tags/$tagName";
        $method = 'DELETE';
    } else {
        jsonResponse(['success' => false, 'message' => 'Invalid action. Use "add" or "remove"']);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Basic $auth",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Debug info
    $debugInfo[] = [
        'device_id' => $originalDeviceId,
        'url' => $url,
        'method' => $method,
        'http_code' => $httpCode,
        'response' => $response,
        'curl_error' => $curlError
    ];

    if ($httpCode >= 200 && $httpCode < 300) {
        $successCount++;
    } else {
        $failCount++;
        $errors[] = "Device $originalDeviceId: HTTP $httpCode" . ($curlError ? " ($curlError)" : "");
    }
}

if ($successCount > 0) {
    $message = $action === 'add'
        ? "Tag '$tagName' added to $successCount device(s)"
        : "Tag '$tagName' removed from $successCount device(s)";

    if ($failCount > 0) {
        $message .= " ($failCount failed)";
    }

    jsonResponse([
        'success' => true,
        'message' => $message,
        'success_count' => $successCount,
        'fail_count' => $failCount,
        'errors' => $errors,
        'debug' => $debugInfo // Include debug info in response
    ]);
} else {
    jsonResponse([
        'success' => false,
        'message' => 'Failed to update tags for all devices',
        'errors' => $errors,
        'debug' => $debugInfo // Include debug info in response
    ]);
}
