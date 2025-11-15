<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$serialNumber = $_GET['serial_number'] ?? '';

if (empty($serialNumber)) {
    jsonResponse(['success' => false, 'message' => 'Serial number required']);
}

$conn = getDBConnection();

// First, try to find ONU in map_items by searching genieacs_device_id which contains serial number
// Use parent_id hierarchy: ONU -> ODP -> ODC -> OLT
$stmt = $conn->prepare("
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
    AND onu_config.genieacs_device_id LIKE ?
    LIMIT 1
");

// Use LIKE to match serial number anywhere in device_id (format: OUI-ProductClass-SerialNumber)
$searchPattern = "%{$serialNumber}%";
$stmt->bind_param("s", $searchPattern);
$stmt->execute();
$result = $stmt->get_result();

// If found as ONU, return the data
if ($result->num_rows > 0) {
    $data = $result->fetch_assoc();

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

    jsonResponse([
        'success' => true,
        'location' => $location
    ]);
}

// If not found as ONU, check if it's a MikroTik device in Server properties
$stmt = $conn->prepare("
    SELECT
        id,
        name,
        latitude,
        longitude,
        properties
    FROM map_items
    WHERE item_type = 'server'
    AND properties LIKE ?
");

$stmt->bind_param("s", $searchPattern);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    jsonResponse([
        'success' => true,
        'found' => false,
        'message' => 'Device not found in map'
    ]);
}

$data = $result->fetch_assoc();

// Device found as MikroTik in Server properties
$location = [
    'found' => true,
    'item_type' => 'mikrotik',
    'server' => [
        'id' => $data['id'],
        'name' => $data['name'],
        'lat' => $data['latitude'],
        'lng' => $data['longitude']
    ]
];

jsonResponse([
    'success' => true,
    'location' => $location
]);
