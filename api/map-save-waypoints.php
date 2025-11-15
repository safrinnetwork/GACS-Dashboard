<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['parent_id']) || !isset($data['child_id'])) {
    jsonResponse(['success' => false, 'message' => 'Parent ID and Child ID are required'], 400);
}

$parentId = (int)$data['parent_id'];
$childId = (int)$data['child_id'];
$waypoints = isset($data['waypoints']) && is_array($data['waypoints']) ? $data['waypoints'] : [];

try {
    $conn = getDBConnection();

    // Check if connection exists in map_connections table
    $checkStmt = $conn->prepare("
        SELECT id FROM map_connections
        WHERE from_item_id = ? AND to_item_id = ?
    ");
    $checkStmt->bind_param("ii", $parentId, $childId);
    $checkStmt->execute();
    $result = $checkStmt->get_result();

    $waypointsJson = json_encode($waypoints);

    if ($result->num_rows > 0) {
        // Update existing connection
        $row = $result->fetch_assoc();
        $connectionId = $row['id'];

        $updateStmt = $conn->prepare("
            UPDATE map_connections
            SET path_coordinates = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->bind_param("si", $waypointsJson, $connectionId);
        $success = $updateStmt->execute();
    } else {
        // Insert new connection record
        $insertStmt = $conn->prepare("
            INSERT INTO map_connections (from_item_id, to_item_id, path_coordinates, connection_type)
            VALUES (?, ?, ?, 'online')
        ");
        $insertStmt->bind_param("iis", $parentId, $childId, $waypointsJson);
        $success = $insertStmt->execute();
    }

    if ($success) {
        jsonResponse([
            'success' => true,
            'message' => count($waypoints) > 0
                ? 'Waypoints berhasil disimpan'
                : 'Waypoints berhasil dihapus',
            'waypoints_count' => count($waypoints)
        ]);
    } else {
        jsonResponse(['success' => false, 'message' => 'Gagal menyimpan waypoints'], 500);
    }

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
