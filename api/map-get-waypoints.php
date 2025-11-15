<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $conn = getDBConnection();

    // Get all connections with waypoints
    $stmt = $conn->prepare("
        SELECT from_item_id, to_item_id, path_coordinates
        FROM map_connections
        WHERE path_coordinates IS NOT NULL
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $waypoints = [];

    while ($row = $result->fetch_assoc()) {
        $waypoints[] = [
            'from_item_id' => (int)$row['from_item_id'],
            'to_item_id' => (int)$row['to_item_id'],
            'path_coordinates' => json_decode($row['path_coordinates'], true) ?: []
        ];
    }

    jsonResponse([
        'success' => true,
        'waypoints' => $waypoints,
        'count' => count($waypoints)
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
