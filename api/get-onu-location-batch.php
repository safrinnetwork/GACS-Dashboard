<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

// Get serial numbers from POST request
$input = json_decode(file_get_contents('php://input'), true);
$serialNumbers = $input['serial_numbers'] ?? [];

if (empty($serialNumbers) || !is_array($serialNumbers)) {
    jsonResponse(['success' => false, 'message' => 'Serial numbers array required']);
}

// Limit to reasonable batch size (max 100 at once)
if (count($serialNumbers) > 100) {
    jsonResponse(['success' => false, 'message' => 'Maximum 100 serial numbers per batch']);
}

$conn = getDBConnection();

// Build search patterns for all serial numbers
$searchPatterns = array_map(function($sn) {
    return "%{$sn}%";
}, $serialNumbers);

// Build dynamic query with multiple LIKE conditions
$placeholders = implode(' OR ', array_fill(0, count($serialNumbers), 'onu_config.genieacs_device_id LIKE ?'));

// Query for ONU devices
$query = "
    SELECT
        onu.id as onu_id,
        onu.name as onu_name,
        onu.parent_id as onu_parent_id,
        onu.latitude as onu_lat,
        onu.longitude as onu_lng,
        onu_config.odp_port as onu_port,
        onu_config.genieacs_device_id as onu_device_id,
        onu_config.customer_name,
        odp.id as odp_id,
        odp.name as odp_name,
        odp.parent_id as odp_parent_id,
        odp.latitude as odp_lat,
        odp.longitude as odp_lng,
        odc.id as odc_id,
        odc.name as odc_name,
        odc.parent_id as odc_parent_id,
        odc.latitude as odc_lat,
        odc.longitude as odc_lng,
        olt.id as olt_id,
        olt.name as olt_name
    FROM map_items onu
    INNER JOIN onu_config ON onu.id = onu_config.map_item_id
    LEFT JOIN map_items odp ON odp.id = onu.parent_id AND odp.item_type = 'odp'
    LEFT JOIN map_items odc ON odc.id = odp.parent_id AND odc.item_type = 'odc'
    LEFT JOIN map_items olt ON olt.id = odc.parent_id AND olt.item_type = 'olt'
    WHERE onu.item_type = 'onu'
    AND ({$placeholders})
";

$stmt = $conn->prepare($query);

// Bind all search patterns
$types = str_repeat('s', count($searchPatterns));
$stmt->bind_param($types, ...$searchPatterns);
$stmt->execute();
$result = $stmt->get_result();

// Build result map indexed by serial number
$locations = [];

while ($data = $result->fetch_assoc()) {
    // Extract serial number from device_id (format: OUI-ProductClass-SerialNumber)
    $deviceId = $data['onu_device_id'];
    $serial = null;

    // Try to match against provided serial numbers
    foreach ($serialNumbers as $sn) {
        if (stripos($deviceId, $sn) !== false) {
            $serial = $sn;
            break;
        }
    }

    if (!$serial) continue;

    // Build location hierarchy for ONU
    $location = [
        'found' => true,
        'item_type' => 'onu',
        'onu' => [
            'id' => $data['onu_id'],
            'name' => $data['onu_name'],
            'device_id' => $data['onu_device_id'],
            'port' => $data['onu_port'] ?? 'N/A',
            'lat' => $data['onu_lat'],
            'lng' => $data['onu_lng']
        ]
    ];

    // Add ODP info if exists
    if ($data['odp_id']) {
        $location['odp'] = [
            'id' => $data['odp_id'],
            'name' => $data['odp_name'],
            'lat' => $data['odp_lat'],
            'lng' => $data['odp_lng']
        ];
    }

    // Add ODC info if exists
    if ($data['odc_id']) {
        $location['odc'] = [
            'id' => $data['odc_id'],
            'name' => $data['odc_name'],
            'lat' => $data['odc_lat'],
            'lng' => $data['odc_lng']
        ];
    }

    // Add OLT info if exists
    if ($data['olt_id']) {
        $location['olt'] = [
            'id' => $data['olt_id'],
            'name' => $data['olt_name']
        ];
    }

    $locations[$serial] = $location;
}

// Check for MikroTik devices (Servers) for serials not found as ONU
$notFoundSerials = array_diff($serialNumbers, array_keys($locations));

if (!empty($notFoundSerials)) {
    $searchPatterns = array_map(function($sn) {
        return "%{$sn}%";
    }, $notFoundSerials);

    $placeholders = implode(' OR ', array_fill(0, count($notFoundSerials), 'properties LIKE ?'));

    $query = "
        SELECT
            id,
            name,
            latitude,
            longitude,
            properties
        FROM map_items
        WHERE item_type = 'server'
        AND ({$placeholders})
    ";

    $stmt = $conn->prepare($query);
    $types = str_repeat('s', count($searchPatterns));
    $stmt->bind_param($types, ...$searchPatterns);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($data = $result->fetch_assoc()) {
        // Find which serial matches
        foreach ($notFoundSerials as $sn) {
            if (stripos($data['properties'], $sn) !== false) {
                $locations[$sn] = [
                    'found' => true,
                    'item_type' => 'mikrotik',
                    'server' => [
                        'id' => $data['id'],
                        'name' => $data['name'],
                        'lat' => $data['latitude'],
                        'lng' => $data['longitude']
                    ]
                ];
                break;
            }
        }
    }
}

// Add 'not found' entries for remaining serials
foreach ($serialNumbers as $serial) {
    if (!isset($locations[$serial])) {
        $locations[$serial] = [
            'found' => false,
            'item_type' => null
        ];
    }
}

jsonResponse([
    'success' => true,
    'locations' => $locations,
    'count' => count($locations)
]);
