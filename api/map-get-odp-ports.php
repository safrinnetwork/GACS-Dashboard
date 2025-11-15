<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$conn = getDBConnection();

// Get all ODP items with their port configurations
$query = "
    SELECT mi.id, mi.name, oc.port_count, mi.status
    FROM map_items mi
    LEFT JOIN odp_config oc ON mi.id = oc.map_item_id
    WHERE mi.item_type = 'odp'
    ORDER BY mi.name
";

$result = $conn->query($query);
$odpList = [];

while ($row = $result->fetch_assoc()) {
    // Get occupied ports for this ODP
    $stmt = $conn->prepare("
        SELECT odp_port
        FROM onu_config
        WHERE map_item_id IN (
            SELECT id FROM map_items WHERE parent_id = ?
        )
    ");
    $stmt->bind_param('i', $row['id']);
    $stmt->execute();
    $occupiedResult = $stmt->get_result();

    $occupiedPorts = [];
    while ($portRow = $occupiedResult->fetch_assoc()) {
        if ($portRow['odp_port']) {
            $occupiedPorts[] = (int)$portRow['odp_port'];
        }
    }

    // Generate available ports
    $portCount = (int)$row['port_count'];
    $availablePorts = [];
    for ($i = 1; $i <= $portCount; $i++) {
        if (!in_array($i, $occupiedPorts)) {
            $availablePorts[] = $i;
        }
    }

    $odpList[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'port_count' => $portCount,
        'available_ports' => $availablePorts,
        'status' => $row['status']
    ];
}

jsonResponse([
    'success' => true,
    'odp_list' => $odpList
]);
