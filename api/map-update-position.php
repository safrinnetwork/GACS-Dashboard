<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

$data = json_decode(file_get_contents('php://input'), true);

$itemId = $data['item_id'] ?? 0;
$latitude = $data['latitude'] ?? 0;
$longitude = $data['longitude'] ?? 0;

if (empty($itemId)) {
    jsonResponse(['success' => false, 'message' => 'Item ID required']);
}

$conn = getDBConnection();
$stmt = $conn->prepare("UPDATE map_items SET latitude = ?, longitude = ? WHERE id = ?");
$stmt->bind_param("ddi", $latitude, $longitude, $itemId);

if ($stmt->execute()) {
    jsonResponse(['success' => true, 'message' => 'Position updated']);
} else {
    jsonResponse(['success' => false, 'message' => 'Failed to update position']);
}
