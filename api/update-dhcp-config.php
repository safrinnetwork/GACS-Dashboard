<?php
/**
 * Update DHCP Server Configuration
 *
 * Updates DHCP server parameters on ONU via GenieACS TR-069
 *
 * Input (POST JSON):
 * {
 *     "device_id": "A4F33B-ZX%2DF663NV3a%20XPON-ZICG295C078F",
 *     "parameters": {
 *         "DHCPServerEnable": true,
 *         "MinAddress": "192.168.1.10",
 *         "MaxAddress": "192.168.1.200",
 *         "SubnetMask": "255.255.255.0",
 *         "IPRouters": "192.168.1.1",
 *         "DNSServers": "8.8.8.8,8.8.4.4",
 *         "DHCPLeaseTime": 86400
 *     }
 * }
 *
 * Output:
 * {
 *     "success": true,
 *     "message": "DHCP server configuration updated successfully",
 *     "task_status": "queued"
 * }
 */

require_once __DIR__ . '/../config/config.php';
use App\GenieACS;

header('Content-Type: application/json');

// Require login
requireLogin();

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['device_id']) || !isset($input['parameters'])) {
    jsonResponse(false, 'Missing required fields: device_id, parameters');
}

$deviceId = $input['device_id'];
$parameters = $input['parameters'];

// Validate that we have at least one parameter to update
if (empty($parameters)) {
    jsonResponse(false, 'No parameters provided for update');
}

// Base path for DHCP configuration
$basePath = "InternetGatewayDevice.LANDevice.1.LANHostConfigManagement";

// Map of allowed parameters to their TR-069 paths
$allowedParams = [
    'DHCPServerEnable' => 'DHCPServerEnable',
    'DHCPServerConfigurable' => 'DHCPServerConfigurable',
    'MinAddress' => 'MinAddress',
    'MaxAddress' => 'MaxAddress',
    'SubnetMask' => 'SubnetMask',
    'DNSServers' => 'DNSServers',
    'IPRouters' => 'IPRouters',
    'DHCPLeaseTime' => 'DHCPLeaseTime',
];

// Build parameter array for GenieACS
$genieParams = [];
foreach ($parameters as $key => $value) {
    if (!isset($allowedParams[$key])) {
        jsonResponse(false, "Invalid parameter: {$key}");
    }

    $fullPath = $basePath . '.' . $allowedParams[$key];
    $genieParams[$fullPath] = $value;
}

// Validate IP addresses if provided
if (isset($parameters['MinAddress']) && !filter_var($parameters['MinAddress'], FILTER_VALIDATE_IP)) {
    jsonResponse(false, 'Invalid MinAddress IP format');
}

if (isset($parameters['MaxAddress']) && !filter_var($parameters['MaxAddress'], FILTER_VALIDATE_IP)) {
    jsonResponse(false, 'Invalid MaxAddress IP format');
}

if (isset($parameters['SubnetMask']) && !filter_var($parameters['SubnetMask'], FILTER_VALIDATE_IP)) {
    jsonResponse(false, 'Invalid SubnetMask format');
}

if (isset($parameters['IPRouters']) && !filter_var($parameters['IPRouters'], FILTER_VALIDATE_IP)) {
    jsonResponse(false, 'Invalid IPRouters (gateway) IP format');
}

// Validate DNS servers (can be comma-separated)
if (isset($parameters['DNSServers'])) {
    $dnsServers = explode(',', $parameters['DNSServers']);
    foreach ($dnsServers as $dns) {
        $dns = trim($dns);
        if (!empty($dns) && !filter_var($dns, FILTER_VALIDATE_IP)) {
            jsonResponse(false, "Invalid DNS server IP format: {$dns}");
        }
    }
}

// Validate lease time (must be positive integer)
if (isset($parameters['DHCPLeaseTime'])) {
    $leaseTime = intval($parameters['DHCPLeaseTime']);
    if ($leaseTime < 60) {
        jsonResponse(false, 'DHCPLeaseTime must be at least 60 seconds');
    }
}

// Validate IP range (MinAddress < MaxAddress)
if (isset($parameters['MinAddress']) && isset($parameters['MaxAddress'])) {
    $minIP = ip2long($parameters['MinAddress']);
    $maxIP = ip2long($parameters['MaxAddress']);

    if ($minIP === false || $maxIP === false) {
        jsonResponse(false, 'Invalid IP address format for MinAddress or MaxAddress');
    }

    if ($minIP >= $maxIP) {
        jsonResponse(false, 'MinAddress must be less than MaxAddress');
    }
}

// Get GenieACS credentials
$db = getDBConnection();
$stmt = $db->prepare("SELECT host, port, username, password FROM genieacs_credentials LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();
$genieConfig = $result->fetch_assoc();

if (!$genieConfig) {
    jsonResponse(false, 'GenieACS credentials not configured');
}

// Initialize GenieACS client
$genieacs = new GenieACS(
    $genieConfig['host'],
    $genieConfig['port'],
    $genieConfig['username'],
    $genieConfig['password']
);

// Send setParameterValues task to GenieACS
$result = $genieacs->setParameterValues($deviceId, $genieParams);

if ($result['success']) {
    // Determine task status based on HTTP code
    $taskStatus = isset($result['http_code']) && $result['http_code'] == 200 ? 'immediate' : 'queued';

    jsonResponse(true, 'DHCP server configuration updated successfully', [
        'task_status' => $taskStatus,
        'parameters_updated' => count($genieParams),
        'dhcp_enabled' => $parameters['DHCPServerEnable'] ?? null
    ]);
} else {
    jsonResponse(false, 'Failed to update DHCP configuration: ' . ($result['error'] ?? 'Unknown error'));
}
