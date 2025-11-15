<?php
require_once __DIR__ . '/../config/config.php';

// Ensure we always return JSON
header('Content-Type: application/json');

try {
    requireLogin();

    $data = json_decode(file_get_contents('php://input'), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['success' => false, 'message' => 'Invalid JSON data: ' . json_last_error_msg()]);
        exit;
    }

    $itemId = $data['item_id'] ?? null;
    $ispLink = $data['isp_link'] ?? '';
    $mikrotikDeviceId = $data['mikrotik_device_id'] ?? '';
    $oltLink = $data['olt_link'] ?? '';

    if (!$itemId) {
        jsonResponse(['success' => false, 'message' => 'Item ID required']);
        exit;
    }

    $conn = getDBConnection();

    // Get current properties (no config column in map_items)
    $stmt = $conn->prepare("SELECT properties FROM map_items WHERE id = ? AND item_type = 'server'");
    $stmt->bind_param("i", $itemId);
    $stmt->execute();
    $resultSet = $stmt->get_result();
    $result = $resultSet->fetch_assoc();

    if (!$result) {
        jsonResponse(['success' => false, 'message' => 'Server not found']);
        exit;
    }

    // Update properties (ISP, MikroTik, OLT links)
    $properties = $result['properties'] ? json_decode($result['properties'], true) : [];
    $properties['isp_link'] = $ispLink;
    $properties['mikrotik_device_id'] = $mikrotikDeviceId;
    $properties['olt_link'] = $oltLink;

    $propertiesJson = json_encode($properties);

    // Update map_items properties
    $stmtUpdate = $conn->prepare("UPDATE map_items SET properties = ? WHERE id = ?");
    $stmtUpdate->bind_param("si", $propertiesJson, $itemId);

    if (!$stmtUpdate->execute()) {
        jsonResponse(['success' => false, 'message' => 'Database error: ' . $stmtUpdate->error]);
        exit;
    }

    // Extract PON ports power and save to server_pon_ports table
    $ponPorts = [];
    foreach ($data as $key => $value) {
        if (preg_match('/^pon_port_(\d+)_power$/', $key, $matches)) {
            $portNumber = (int)$matches[1];
            $ponPorts[$portNumber] = (float)$value;
        }
    }

    // Delete existing PON ports and insert new ones
    if (!empty($ponPorts)) {
        // Delete old ports
        $stmtDelete = $conn->prepare("DELETE FROM server_pon_ports WHERE map_item_id = ?");
        $stmtDelete->bind_param("i", $itemId);
        $stmtDelete->execute();

        // Insert new ports
        $stmtInsert = $conn->prepare("INSERT INTO server_pon_ports (map_item_id, port_number, output_power) VALUES (?, ?, ?)");
        foreach ($ponPorts as $portNum => $power) {
            $stmtInsert->bind_param("iid", $itemId, $portNum, $power);
            if (!$stmtInsert->execute()) {
                jsonResponse(['success' => false, 'message' => 'Error saving PON port ' . $portNum . ': ' . $stmtInsert->error]);
                exit;
            }
        }
    }

    jsonResponse(['success' => true, 'message' => 'Server links updated successfully']);

} catch (Exception $e) {
    // Catch any unexpected errors and return JSON
    jsonResponse(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
