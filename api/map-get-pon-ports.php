<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$conn = getDBConnection();

// Get ALL PON ports from Server items with usage status
// Check BOTH:
// 1. ODC connected via olt_pon_port_id (standalone ODC)
// 2. ODC child items using server_pon_port (created with Server)
$result = $conn->query("
    SELECT
        pp.id,
        pp.port_number,
        pp.output_power,
        mi.id as server_id,
        mi.name as server_name,
        oc.id as odc_config_id,
        odc_item.name as connected_odc_name,
        oc_child.id as child_odc_config_id,
        odc_child_item.name as child_odc_name
    FROM server_pon_ports pp
    JOIN map_items mi ON pp.map_item_id = mi.id
    LEFT JOIN odc_config oc ON oc.olt_pon_port_id = pp.id
    LEFT JOIN map_items odc_item ON oc.map_item_id = odc_item.id
    LEFT JOIN odc_config oc_child ON oc_child.server_id = mi.id AND oc_child.server_pon_port = pp.port_number
    LEFT JOIN map_items odc_child_item ON oc_child.map_item_id = odc_child_item.id AND odc_child_item.parent_id = mi.id
    WHERE mi.item_type = 'server'
    ORDER BY mi.name, pp.port_number
");

$ports = [];
while ($row = $result->fetch_assoc()) {
    // Port is used if EITHER standalone ODC OR child ODC is connected
    $isUsed = !is_null($row['odc_config_id']) || !is_null($row['child_odc_config_id']);

    // Determine which ODC name to show (prefer standalone, then child)
    $connectedOdcName = null;
    if (!is_null($row['connected_odc_name'])) {
        $connectedOdcName = $row['connected_odc_name'];
    } elseif (!is_null($row['child_odc_name'])) {
        $connectedOdcName = $row['child_odc_name'] . ' (Child)';
    }

    $ports[] = [
        'id' => $row['id'],
        'pon_number' => $row['port_number'],
        'output_power' => $row['output_power'],
        'server_id' => $row['server_id'],
        'olt_name' => $row['server_name'], // Keep 'olt_name' for backward compatibility
        'is_used' => $isUsed,
        'connected_odc_name' => $connectedOdcName
    ];
}

jsonResponse(['success' => true, 'ports' => $ports]);
