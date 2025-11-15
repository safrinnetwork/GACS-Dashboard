<?php
require_once __DIR__ . '/../config/config.php';

// Set reasonable timeout for MikroTik connection
set_time_limit(15); // 15 seconds max execution time

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);
$macAddresses = $data['mac_addresses'] ?? [];

if (empty($macAddresses) || !is_array($macAddresses)) {
    jsonResponse(['success' => false, 'message' => 'MAC addresses array required']);
}

// Get MikroTik credentials
$conn = getDBConnection();
$result = $conn->query("SELECT * FROM mikrotik_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'MikroTik not configured or not connected']);
}

use App\MikroTikAPI;

// ============================================================================
// CACHING STRATEGY to reduce MikroTik API load
// Cache hotspot active users for 5 seconds to match frontend interval
// This ensures cache is always valid for consecutive requests
// ============================================================================

$cacheFile = sys_get_temp_dir() . '/mikrotik_hotspot_cache_' . md5($credentials['host']);
$cacheLifetime = 5; // seconds (matches frontend 5s interval)
$activeUsers = null;
$fromCache = false;
$cacheAge = 0;

// Try to use cache first
if (file_exists($cacheFile)) {
    $cacheAge = time() - filemtime($cacheFile);
    if ($cacheAge < $cacheLifetime) {
        $cachedData = json_decode(file_get_contents($cacheFile), true);
        if ($cachedData && isset($cachedData['active_users'])) {
            $activeUsers = $cachedData['active_users'];
            $fromCache = true;
            error_log('[HOTSPOT] Using cached data (' . $cacheAge . 's old, ' . count($activeUsers) . ' users)');
        }
    }
}

// If no cache, fetch from MikroTik
if (!$activeUsers) {
    try {
        $mikrotik = new MikroTikAPI(
            $credentials['host'],
            $credentials['username'],
            $credentials['password'],
            $credentials['port']
        );

        $activeResult = $mikrotik->getHotspotActiveUsers();

        if (!$activeResult['success']) {
            jsonResponse(['success' => false, 'message' => 'MikroTik connection failed: ' . ($activeResult['error'] ?? 'Unknown error')]);
        }

        $activeUsers = $activeResult['data'];

        // Save to cache
        file_put_contents($cacheFile, json_encode(['active_users' => $activeUsers, 'timestamp' => time()]));
        chmod($cacheFile, 0600); // Secure cache file
        error_log('[HOTSPOT] Fetched from MikroTik (' . count($activeUsers) . ' users) and cached');

    } catch (Exception $e) {
        error_log('Hotspot Traffic API Error: ' . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'MikroTik temporarily unavailable']);
    }
}

// Now match MAC addresses (same logic as MikroTikAPI::getHotspotTrafficByMAC)
$matchedUsers = [];

// Normalize input MAC addresses
$normalizedInput = [];
foreach ($macAddresses as $mac) {
    $normalized = strtoupper(str_replace([':', '-', '.'], '', $mac));
    $normalizedInput[$normalized] = $mac;
}

// Match and get traffic info
foreach ($activeUsers as $user) {
    $userMac = $user['mac-address'] ?? '';
    $userMacNormalized = strtoupper(str_replace([':', '-', '.'], '', $userMac));

    if (isset($normalizedInput[$userMacNormalized])) {
        $originalMac = $normalizedInput[$userMacNormalized];

        $matchedUsers[$originalMac] = [
            'found' => true,
            'username' => $user['user'] ?? 'N/A',
            'ip' => $user['address'] ?? 'N/A',
            'mac' => $userMac,
            'uptime' => $user['uptime'] ?? 'N/A',
            'bytes_in' => $user['bytes-in'] ?? 0,
            'bytes_out' => $user['bytes-out'] ?? 0,
            'id' => $user['.id'] ?? null,
        ];
    }
}

// Fill in non-matched MACs
foreach ($macAddresses as $mac) {
    if (!isset($matchedUsers[$mac])) {
        $matchedUsers[$mac] = [
            'found' => false,
            'username' => 'N/A',
            'ip' => 'N/A',
            'mac' => $mac,
        ];
    }
}

// Add debug info for troubleshooting
jsonResponse([
    'success' => true,
    'data' => $matchedUsers,
    'timestamp' => time(),
    'from_cache' => $fromCache,
    'cache_age' => $cacheAge,
    'total_matched' => count($matchedUsers)
]);
