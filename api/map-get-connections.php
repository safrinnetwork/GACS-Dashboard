<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM map_connections ORDER BY id ASC");

$connections = [];
while ($row = $result->fetch_assoc()) {
    $row['path_coordinates'] = json_decode($row['path_coordinates'], true);
    $connections[] = $row;
}

jsonResponse(['success' => true, 'connections' => $connections]);
