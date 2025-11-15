<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$data = json_decode(file_get_contents('php://input'), true);
$itemId = $data['item_id'] ?? null;

if (!$itemId) {
    jsonResponse(['success' => false, 'message' => 'Item ID required']);
}

$conn = getDBConnection();

// Get item type
$stmt = $conn->prepare("SELECT item_type FROM map_items WHERE id = ?");
$stmt->bind_param("i", $itemId);
$stmt->execute();
$resultSet = $stmt->get_result();
$result = $resultSet->fetch_assoc();

if (!$result) {
    jsonResponse(['success' => false, 'message' => 'Item not found']);
}

$itemType = $result['item_type'];

// Recursive delete function - now returns array of deleted item IDs
function deleteItemRecursive($conn, $itemId, $itemType, &$deletedItems = []) {
    // Add current item to deleted list
    $deletedItems[] = (int)$itemId;

    // Special handling for Server: delete standalone ODCs connected via olt_pon_port_id
    if ($itemType === 'server') {
        // Find all ODCs that use PON ports from this server (regardless of parent_id)
        // This includes both child ODCs (parent_id = server_id) and standalone ODCs (parent_id = NULL)
        $stmt = $conn->prepare("
            SELECT mi.id, mi.item_type
            FROM map_items mi
            JOIN odc_config oc ON oc.map_item_id = mi.id
            JOIN server_pon_ports spp ON spp.id = oc.olt_pon_port_id AND spp.map_item_id = ?
            WHERE mi.item_type = 'odc'
        ");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $connectedODCs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Delete all connected ODCs (both child and standalone)
        foreach ($connectedODCs as $odc) {
            deleteItemRecursive($conn, $odc['id'], $odc['item_type'], $deletedItems);
        }

        // Delete server PON ports
        $stmtDel = $conn->prepare("DELETE FROM server_pon_ports WHERE map_item_id = ?");
        $stmtDel->bind_param("i", $itemId);
        $stmtDel->execute();
    }

    // Find and delete all children first (standard parent-child relationship)
    $stmt = $conn->prepare("SELECT id, item_type FROM map_items WHERE parent_id = ?");
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $children = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($children as $child) {
        deleteItemRecursive($conn, $child['id'], $child['item_type'], $deletedItems);
    }

    // Delete type-specific config using prepared statements
    switch ($itemType) {
        case 'olt':
            $stmtDel = $conn->prepare("DELETE FROM olt_config WHERE map_item_id = ?");
            $stmtDel->bind_param("i", $itemId);
            $stmtDel->execute();
            break;
        case 'odc':
            $stmtDel = $conn->prepare("DELETE FROM odc_config WHERE map_item_id = ?");
            $stmtDel->bind_param("i", $itemId);
            $stmtDel->execute();
            break;
        case 'odp':
            $stmtDel = $conn->prepare("DELETE FROM odp_config WHERE map_item_id = ?");
            $stmtDel->bind_param("i", $itemId);
            $stmtDel->execute();
            break;
        case 'onu':
            $stmtDel = $conn->prepare("DELETE FROM onu_config WHERE map_item_id = ?");
            $stmtDel->bind_param("i", $itemId);
            $stmtDel->execute();
            break;
    }

    // Delete from map_items using prepared statement
    $stmtDel = $conn->prepare("DELETE FROM map_items WHERE id = ?");
    $stmtDel->bind_param("i", $itemId);
    $stmtDel->execute();

    return $deletedItems;
}

// Start deletion and track deleted items
$deletedItems = [];
deleteItemRecursive($conn, $itemId, $itemType, $deletedItems);

jsonResponse([
    'success' => true,
    'message' => 'Item and all children deleted successfully',
    'deleted_items' => $deletedItems
]);
