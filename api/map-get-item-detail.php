<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

$itemId = $_GET['item_id'] ?? null;

if (!$itemId) {
    jsonResponse(['success' => false, 'message' => 'Item ID required']);
}

$conn = getDBConnection();

// Get item
$stmt = $conn->prepare("SELECT * FROM map_items WHERE id = ?");
$stmt->bind_param("i", $itemId);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();

if (!$item) {
    jsonResponse(['success' => false, 'message' => 'Item not found']);
}

// Parse properties JSON
$item['properties'] = json_decode($item['properties'], true);

// Get type-specific config
$config = null;
switch ($item['item_type']) {
    case 'server':
        // Get PON ports for Server
        $stmt = $conn->prepare("SELECT port_number, output_power FROM server_pon_ports WHERE map_item_id = ? ORDER BY port_number");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $result = $stmt->get_result();

        $pon_ports = [];
        while ($row = $result->fetch_assoc()) {
            $pon_ports[$row['port_number']] = $row['output_power'];
        }

        $config = ['pon_ports' => $pon_ports];
        break;
    case 'olt':
        $stmt = $conn->prepare("SELECT * FROM olt_config WHERE map_item_id = ?");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $config = $result->fetch_assoc();
        break;
    case 'odc':
        $stmt = $conn->prepare("SELECT * FROM odc_config WHERE map_item_id = ?");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $config = $result->fetch_assoc();

        // Get ODPs connected to this ODC per port
        if ($config) {
            $port_odp_name = [];

            // Query all ODPs that are children of this ODC
            $stmt = $conn->prepare("
                SELECT odp_cfg.odc_port, mi.name
                FROM map_items mi
                JOIN odp_config odp_cfg ON odp_cfg.map_item_id = mi.id
                WHERE mi.parent_id = ? AND mi.item_type = 'odp' AND odp_cfg.odc_port IS NOT NULL
                ORDER BY odp_cfg.odc_port
            ");
            $stmt->bind_param("i", $itemId);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($odp = $result->fetch_assoc()) {
                $port = $odp['odc_port'];
                $port_odp_name[$port] = $odp['name'];
            }

            $config['port_odp_name'] = $port_odp_name;
        }
        break;
    case 'odp':
        $stmt = $conn->prepare("SELECT * FROM odp_config WHERE map_item_id = ?");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $config = $result->fetch_assoc();

        // Get parent ODC name
        if ($config && $item['parent_id']) {
            $stmt = $conn->prepare("SELECT name FROM map_items WHERE id = ?");
            $stmt->bind_param("i", $item['parent_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($parentRow = $result->fetch_assoc()) {
                $config['parent_odc_name'] = $parentRow['name'];
            }
        }

        // Get RX Power from ONUs connected to this ODP
        if ($config) {
            $port_rx_power = [];
            $port_serial_number = [];
            $port_device_id = [];
            $port_status = [];

            // Query all ONUs connected to this ODP
            $stmt = $conn->prepare("
                SELECT onu.odp_port, onu.genieacs_device_id, mi.name
                FROM onu_config onu
                JOIN map_items mi ON mi.id = onu.map_item_id
                WHERE mi.parent_id = ?
                ORDER BY onu.odp_port
            ");
            $stmt->bind_param("i", $itemId);
            $stmt->execute();
            $result = $stmt->get_result();

            // Initialize GenieACS client if configured
            $genieacsConfigured = isGenieACSConfigured();
            $genieacs = null;

            if ($genieacsConfigured) {
                // Get GenieACS credentials
                $credStmt = $conn->prepare("SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 LIMIT 1");
                $credStmt->execute();
                $credResult = $credStmt->get_result();
                $creds = $credResult->fetch_assoc();

                if ($creds) {
                    $genieacs = new \App\GenieACS(
                        $creds['host'],
                        $creds['port'],
                        $creds['username'] ?? null,
                        $creds['password'] ?? null
                    );
                }
            }

            // Fetch RX Power and Status for each ONU
            while ($onu = $result->fetch_assoc()) {
                $port = $onu['odp_port'];
                $deviceId = $onu['genieacs_device_id'];

                if ($genieacs && $deviceId) {
                    $deviceResult = $genieacs->getDevice($deviceId);
                    if ($deviceResult['success'] && isset($deviceResult['data'])) {
                        $parsed = $genieacs->parseDeviceData($deviceResult['data']);
                        if (isset($parsed['rx_power']) && $parsed['rx_power'] !== 'N/A') {
                            $port_rx_power[$port] = $parsed['rx_power'];
                            $port_serial_number[$port] = $parsed['serial_number'];
                            $port_device_id[$port] = $deviceId; // Store device ID for linking

                            // Get ONU status from last inform time
                            if (isset($deviceResult['data']['_lastInform'])) {
                                $lastInform = strtotime($deviceResult['data']['_lastInform']);
                                $now = time();
                                $diff = $now - $lastInform;

                                // Online if last inform < 5 minutes
                                $port_status[$port] = ($diff < 300) ? 'online' : 'offline';
                            } else {
                                $port_status[$port] = 'unknown';
                            }
                        }
                    }
                }
            }

            $config['port_rx_power'] = $port_rx_power;
            $config['port_serial_number'] = $port_serial_number;
            $config['port_device_id'] = $port_device_id;
            $config['port_status'] = $port_status;
        }
        break;
    case 'onu':
        $stmt = $conn->prepare("SELECT * FROM onu_config WHERE map_item_id = ?");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $result = $stmt->get_result();
        $config = $result->fetch_assoc();
        break;
}

$item['config'] = $config;
$item['customer_name'] = $config['customer_name'] ?? null;

jsonResponse(['success' => true, 'item' => $item]);
