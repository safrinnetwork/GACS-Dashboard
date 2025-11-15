<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$conn = getDBConnection();

// Count map items by type
$counts = [
    'server' => 0,
    'olt' => 0,
    'odc' => 0,
    'odp' => 0,
    'onu' => 0
];

// Get counts from database
$result = $conn->query("
    SELECT item_type, COUNT(*) as count
    FROM map_items
    WHERE item_type IN ('server', 'olt', 'odc', 'odp', 'onu')
    GROUP BY item_type
");

while ($row = $result->fetch_assoc()) {
    $counts[$row['item_type']] = (int)$row['count'];
}

// Count OLT from server properties (items with olt_link configured)
$oltResult = $conn->query("
    SELECT COUNT(*) as olt_count
    FROM map_items
    WHERE item_type = 'server'
    AND properties IS NOT NULL
    AND JSON_EXTRACT(properties, '$.olt_link') IS NOT NULL
    AND JSON_EXTRACT(properties, '$.olt_link') != ''
");

if ($oltRow = $oltResult->fetch_assoc()) {
    $counts['olt'] = (int)$oltRow['olt_count'];
}

jsonResponse([
    'success' => true,
    'counts' => $counts
]);
