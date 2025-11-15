<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$conn = getDBConnection();

$parentId = $_GET['parent_id'] ?? null;
$parentType = $_GET['parent_type'] ?? null;

if (!$parentId || !$parentType) {
    jsonResponse(['success' => false, 'message' => 'Parent ID and type required']);
}

$usedPorts = [];

if ($parentType === 'odc') {
    // Get used ODC ports
    $stmt = $conn->prepare("
        SELECT odc_port
        FROM odp_config
        WHERE map_item_id IN (
            SELECT id FROM map_items WHERE parent_id = ? AND item_type = 'odp'
        )
        AND odc_port IS NOT NULL
    ");
    $stmt->bind_param("i", $parentId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $usedPorts[] = (int)$row['odc_port'];
    }

} elseif ($parentType === 'odp') {
    // Get used ODP custom ratio ports
    $stmt = $conn->prepare("
        SELECT parent_odp_port
        FROM odp_config
        WHERE map_item_id IN (
            SELECT id FROM map_items WHERE parent_id = ? AND item_type = 'odp'
        )
        AND parent_odp_port IS NOT NULL
    ");
    $stmt->bind_param("i", $parentId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $usedPorts[] = $row['parent_odp_port'];
    }
}

jsonResponse([
    'success' => true,
    'used_ports' => $usedPorts
]);
