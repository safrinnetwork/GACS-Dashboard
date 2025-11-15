<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, but log
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/map-add-item-error.log');

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

requireLogin();

use App\PONCalculator;
use App\GenieACS;

function getGenieACSConnection($conn) {
    $result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
    $creds = $result->fetch_assoc();
    if ($creds) return new GenieACS($creds['host'], $creds['port']);
    return null;
}

try {
    $rawInput = file_get_contents('php://input');
    error_log("Raw input: " . $rawInput);

    $data = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON decode error: " . json_last_error_msg());
        jsonResponse(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
    }

    error_log("Decoded data: " . print_r($data, true));

} catch (Exception $e) {
    error_log("Exception reading input: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Error reading input: ' . $e->getMessage()]);
}

$itemType = $data['item_type'] ?? '';
$parentId = $data['parent_id'] ?? null;
$name = $data['name'] ?? '';
$latitude = $data['latitude'] ?? 0;
$longitude = $data['longitude'] ?? 0;
$genieacsDeviceId = $data['genieacs_device_id'] ?? null;

if (empty($itemType) || empty($name)) {
    jsonResponse(['success' => false, 'message' => 'Item type dan name harus diisi']);
}

// Validate ONU: check if genieacs_device_id is unique
if ($itemType === 'onu' && !empty($genieacsDeviceId)) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id FROM map_items WHERE genieacs_device_id = ? AND item_type = 'onu'");
    $stmt->bind_param("s", $genieacsDeviceId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(['success' => false, 'message' => 'GenieACS device sudah digunakan oleh ONU lain']);
    }
}

// For ONU, get parent_id from parent_odp field
if ($itemType === 'onu') {
    if (empty($data['parent_odp'])) {
        jsonResponse(['success' => false, 'message' => 'Parent ODP harus dipilih untuk ONU']);
    }
    if (empty($data['odp_port'])) {
        jsonResponse(['success' => false, 'message' => 'ODP Port harus dipilih untuk ONU']);
    }
    $parentId = $data['parent_odp'];
}

// Store additional properties
$properties = [];
if ($itemType === 'server') {
    $properties['isp_link'] = $data['isp_link'] ?? '';
    $properties['mikrotik_device_id'] = $data['mikrotik_device_id'] ?? '';
    $properties['olt_link'] = $data['olt_link'] ?? '';
    $properties['pon_output_power'] = $data['pon_output_power'] ?? 2;
}
if ($itemType === 'olt' && isset($data['olt_link'])) {
    $properties['olt_link'] = $data['olt_link'];
}

$conn = getDBConnection();

try {
    $stmt = $conn->prepare("INSERT INTO map_items (item_type, parent_id, name, latitude, longitude, genieacs_device_id, properties, status)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'unknown')");
    $propertiesJson = json_encode($properties);
    $stmt->bind_param("sisddss", $itemType, $parentId, $name, $latitude, $longitude, $genieacsDeviceId, $propertiesJson);

    if (!$stmt->execute()) {
        error_log("Insert error: " . $conn->error);
        jsonResponse(['success' => false, 'message' => 'Gagal menambahkan item: ' . $conn->error]);
    }

    $itemId = $conn->insert_id;
} catch (Exception $e) {
    error_log("Exception on insert: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

// Handle specific item type configurations
try {
    switch ($itemType) {
        case 'server':
            // Save PON ports configuration
            $ponPortCount = $data['pon_port_count'] ?? 4;

            // Insert PON ports
            for ($i = 1; $i <= $ponPortCount; $i++) {
                $ponPower = $data["pon_port_{$i}_power"] ?? 2.00;
                $stmt = $conn->prepare("INSERT INTO server_pon_ports (map_item_id, port_number, output_power) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $itemId, $i, $ponPower);
                $stmt->execute();
            }

            // Create multiple ODCs as real database items (for ODP parent relation) but mark as hidden
            // Support both old format (single ODC) and new format (array of ODCs)
            $odcItems = [];

            // Check for old format (single ODC) for backward compatibility
            if (!empty($data['odc_name']) && !empty($data['odc_pon_port'])) {
                $odcItems[] = [
                    'name' => $data['odc_name'],
                    'pon_port' => $data['odc_pon_port'],
                    'port_count' => $data['odc_port_count'] ?? 4
                ];
            }

            // Check for new format (array of ODCs)
            if (!empty($data['odc_items']) && is_array($data['odc_items'])) {
                foreach ($data['odc_items'] as $odcItem) {
                    if (!empty($odcItem['name']) && !empty($odcItem['pon_port'])) {
                        $odcItems[] = [
                            'name' => $odcItem['name'],
                            'pon_port' => $odcItem['pon_port'],
                            'port_count' => $odcItem['port_count'] ?? 4
                        ];
                    }
                }
            }

            // Check for flat format (odc_items[1][name], odc_items[1][pon_port], etc.)
            // This format appears when form data is JSON-encoded with flat keys
            $flatOdcItems = [];
            foreach ($data as $key => $value) {
                // Match keys like "odc_items[1][name]", "odc_items[2][pon_port]", etc.
                if (preg_match('/^odc_items\[(\d+)\]\[(name|pon_port|port_count)\]$/', $key, $matches)) {
                    $index = $matches[1];
                    $field = $matches[2];
                    if (!isset($flatOdcItems[$index])) {
                        $flatOdcItems[$index] = [];
                    }
                    $flatOdcItems[$index][$field] = $value;
                }
            }

            // Add flat ODC items to the main array
            foreach ($flatOdcItems as $odcItem) {
                if (!empty($odcItem['name']) && !empty($odcItem['pon_port'])) {
                    $odcItems[] = [
                        'name' => $odcItem['name'],
                        'pon_port' => $odcItem['pon_port'],
                        'port_count' => $odcItem['port_count'] ?? 4
                    ];
                }
            }

            // Create all ODC items
            $createdOdcIds = [];
            foreach ($odcItems as $odcData) {
                $odcName = $odcData['name'];
                $odcPonPort = $odcData['pon_port'];
                $odcPortCount = $odcData['port_count'];

                // Get PON power and ID from the selected server PON port
                $stmt = $conn->prepare("SELECT id, output_power FROM server_pon_ports WHERE map_item_id = ? AND port_number = ?");
                $stmt->bind_param("ii", $itemId, $odcPonPort);
                $stmt->execute();
                $result = $stmt->get_result();
                $ponPowerRow = $result->fetch_assoc();
                $ponPower = $ponPowerRow['output_power'] ?? 2.0;
                $oltPonPortId = $ponPowerRow['id'] ?? null;

                // Calculate ODC power = PON power - attenuation
                $attenuationOltToOdc = 5.8;
                $odcCalculatedPower = $ponPower - $attenuationOltToOdc;

                // Insert ODC as real item (same coordinates as Server for simplicity)
                $odcProperties = json_encode(['hidden_marker' => true]); // Mark as hidden from map
                $stmt = $conn->prepare("INSERT INTO map_items (item_type, parent_id, name, latitude, longitude, properties, status) VALUES ('odc', ?, ?, ?, ?, ?, 'unknown')");
                $stmt->bind_param("isdds", $itemId, $odcName, $latitude, $longitude, $odcProperties);
                $stmt->execute();
                $odcItemId = $conn->insert_id;

                // Insert ODC config with server_id reference AND olt_pon_port_id
                // CRITICAL: olt_pon_port_id must be set for PON port tracking (prevents duplicate usage)
                $stmt = $conn->prepare("INSERT INTO odc_config (map_item_id, olt_pon_port_id, server_id, server_pon_port, port_count, parent_attenuation_db, calculated_power) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiiddd", $odcItemId, $oltPonPortId, $itemId, $odcPonPort, $odcPortCount, $attenuationOltToOdc, $odcCalculatedPower);
                $stmt->execute();

                $createdOdcIds[] = [
                    'id' => $odcItemId,
                    'name' => $odcName,
                    'pon_port' => $odcPonPort
                ];
            }

            // Store all created ODCs in server properties for chain visualization reference
            if (!empty($createdOdcIds)) {
                $properties['odc_items'] = $createdOdcIds;

                // Update map_items with new properties
                $propertiesJson = json_encode($properties);
                $stmt = $conn->prepare("UPDATE map_items SET properties = ? WHERE id = ?");
                $stmt->bind_param("si", $propertiesJson, $itemId);
                $stmt->execute();
            }
            break;

        case 'olt':
            $ponCount = $data['pon_count'] ?? 1;
            $oltLink = $data['olt_link'] ?? null;

            $stmt = $conn->prepare("INSERT INTO olt_config (map_item_id, output_power, pon_count, attenuation_db, olt_link) VALUES (?, 0, ?, 0, ?)");
            $stmt->bind_param("iis", $itemId, $ponCount, $oltLink);
            $stmt->execute();

            // Insert PON ports
            for ($i = 1; $i <= $ponCount; $i++) {
                $ponPower = $data["pon_power_$i"] ?? 9;
                $stmt = $conn->prepare("INSERT INTO olt_pon_ports (olt_item_id, pon_number, output_power) VALUES (?, ?, ?)");
                $stmt->bind_param("iid", $itemId, $i, $ponPower);
                $stmt->execute();
            }
            break;

        case 'odc':
            $portCount = $data['port_count'] ?? 4;
            $oltPonPortId = $data['olt_pon_port_id'] ?? null;

            $ponPower = 0;
            $serverPonPort = null;
            $serverId = null;
            if ($oltPonPortId) {
                // Get PON power and port number from server_pon_ports (not olt_pon_ports)
                $stmt = $conn->prepare("SELECT output_power, port_number, map_item_id FROM server_pon_ports WHERE id = ?");
                $stmt->bind_param("i", $oltPonPortId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $ponPower = $row['output_power'];
                    $serverPonPort = $row['port_number'];
                    $serverId = $row['map_item_id'];

                    // DO NOT UPDATE parent_id - ODC standalone should have NULL parent_id
                    // parent_id only set for ODC created via Server form (in lines 144-145)
                    // ODC created via "Add Item" -> "ODC" form should remain standalone (parent_id = NULL)
                }
            }

            // Calculate ODC power = PON power - attenuation (5.8 dB)
            $attenuationOltToOdc = 5.8;
            $calculatedPower = $ponPower - $attenuationOltToOdc;

            // Save olt_pon_port_id (server_pon_ports.id) to track which server PON port is used
            $stmt = $conn->prepare("INSERT INTO odc_config (map_item_id, olt_pon_port_id, server_pon_port, port_count, parent_attenuation_db, calculated_power) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiiddd", $itemId, $oltPonPortId, $serverPonPort, $portCount, $attenuationOltToOdc, $calculatedPower);
            $stmt->execute();
            break;

        case 'odp':
            $portCount = $data['port_count'] ?? 8;
            $odcPort = $data['odc_port'] ?? null;
            $parentOdpPort = $data['parent_odp_port'] ?? null;
            $useSplitter = $data['use_splitter'] ?? 0;
            $splitterRatio = $data['splitter_ratio'] ?? '1:8';
            $customRatioOutputPort = $data['custom_ratio_output_port'] ?? null;

            // AUTO-CALCULATE secondary splitter from Port Count
            // Port Count directly determines secondary splitter ratio
            $useSecondarySplitter = 1; // Always use secondary splitter
            $secondarySplitterRatio = "1:{$portCount}"; // e.g., Port Count 8 = 1:8, Port Count 32 = 1:32
            $customSecondaryRatioOutputPort = $data['custom_secondary_ratio_output_port'] ?? null;

            // Determine parent type (ODC or ODP)
            $inputPower = 0; // Power before splitter (untuk cascading)
            if ($parentId) {
                // Check if parent is ODC
                $stmt = $conn->prepare("SELECT item_type FROM map_items WHERE id = ?");
                $stmt->bind_param("i", $parentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $parentItem = $result->fetch_assoc();

                if ($parentItem['item_type'] === 'odc') {
                    // Validate ODC port not already used
                    if ($odcPort) {
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as count FROM odp_config
                            WHERE map_item_id IN (SELECT id FROM map_items WHERE parent_id = ?)
                            AND odc_port = ?
                        ");
                        $stmt->bind_param("ii", $parentId, $odcPort);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result->fetch_assoc();
                        if ($row['count'] > 0) {
                            $conn->query("DELETE FROM map_items WHERE id = $itemId");
                            jsonResponse(['success' => false, 'message' => "ODC Port $odcPort sudah digunakan oleh ODP lain"]);
                        }
                    }

                    // Parent is ODC - use calculated_power from ODC
                    $stmt = $conn->prepare("SELECT calculated_power FROM odc_config WHERE map_item_id = ?");
                    $stmt->bind_param("i", $parentId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $inputPower = $row['calculated_power'];
                    }
                } elseif ($parentItem['item_type'] === 'odp') {
                    // Validate ODP port not already used
                    if ($parentOdpPort) {
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as count FROM odp_config
                            WHERE map_item_id IN (SELECT id FROM map_items WHERE parent_id = ?)
                            AND parent_odp_port = ?
                        ");
                        $stmt->bind_param("is", $parentId, $parentOdpPort);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $row = $result->fetch_assoc();
                        if ($row['count'] > 0) {
                            $conn->query("DELETE FROM map_items WHERE id = $itemId");
                            jsonResponse(['success' => false, 'message' => "Parent ODP port $parentOdpPort sudah digunakan oleh ODP lain"]);
                        }
                    }

                    // Parent is ODP with custom ratio
                    // IMPORTANT: Use input_power (before splitter), NOT calculated_power (after splitter)
                    $stmt = $conn->prepare("SELECT input_power, splitter_ratio FROM odp_config WHERE map_item_id = ?");
                    $stmt->bind_param("i", $parentId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($row = $result->fetch_assoc()) {
                        $parentInputPower = $row['input_power']; // Power BEFORE parent's splitter
                        $parentRatio = $row['splitter_ratio'];

                        // Calculate power from selected port (e.g., "80%" from 20:80 ratio)
                        if ($parentOdpPort && $parentRatio) {
                            $calculator = new PONCalculator();
                            $inputPower = $calculator->calculateCustomRatioPort($parentInputPower, $parentRatio, $parentOdpPort);
                        }
                    }
                }
            }

            $calculator = new PONCalculator();

            // Calculate power through cascade splitters
            $powerAfterPrimarySplitter = $inputPower;

            // Step 1: Calculate power after primary splitter (if used)
            if ($useSplitter && $customRatioOutputPort) {
                // Custom ratio splitter - calculate power for specific output port
                $powerAfterPrimarySplitter = $calculator->calculateCustomRatioPort($inputPower, $splitterRatio, $customRatioOutputPort);
            } elseif ($useSplitter) {
                // Standard splitter (shouldn't happen with new UI but keep for compatibility)
                $powerAfterPrimarySplitter = $calculator->calculateODPPower($inputPower, $splitterRatio);
            }

            // Step 2: Calculate power after secondary splitter (if used)
            $calculatedPower = $powerAfterPrimarySplitter;
            if ($useSecondarySplitter && $secondarySplitterRatio) {
                // Apply secondary splitter loss
                $calculatedPower = $calculator->calculateODPPower($powerAfterPrimarySplitter, $secondarySplitterRatio);
            }

            // Store both input_power (before splitter) and calculated_power (after all splitters)
            $stmt = $conn->prepare("INSERT INTO odp_config (map_item_id, port_count, odc_port, input_power, parent_odp_port, use_splitter, splitter_ratio, custom_ratio_output_port, use_secondary_splitter, secondary_splitter_ratio, custom_secondary_ratio_output_port, calculated_power) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiidsississd", $itemId, $portCount, $odcPort, $inputPower, $parentOdpPort, $useSplitter, $splitterRatio, $customRatioOutputPort, $useSecondarySplitter, $secondarySplitterRatio, $customSecondaryRatioOutputPort, $calculatedPower);
            $stmt->execute();
            break;

        case 'onu':
            $odpPort = $data['odp_port'] ?? null;
            $customerName = $data['customer_name'] ?? '';

            if (empty($odpPort)) {
                $conn->query("DELETE FROM map_items WHERE id = $itemId");
                jsonResponse(['success' => false, 'message' => 'ODP Port harus dipilih']);
            }

            // Check if port is already occupied
            $stmt = $conn->prepare("SELECT id FROM onu_config WHERE map_item_id IN (SELECT id FROM map_items WHERE parent_id = ?) AND odp_port = ?");
            $stmt->bind_param("ii", $parentId, $odpPort);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $conn->query("DELETE FROM map_items WHERE id = $itemId");
                jsonResponse(['success' => false, 'message' => 'Port ODP sudah terpakai']);
            }

            $stmt = $conn->prepare("INSERT INTO onu_config (map_item_id, odp_port, customer_name, genieacs_device_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $itemId, $odpPort, $customerName, $genieacsDeviceId);
            $stmt->execute();

            // Update ODP port RX power from GenieACS
            if (!empty($genieacsDeviceId)) {
                $genieacs = getGenieACSConnection($conn);
                if ($genieacs) {
                    try {
                        $deviceData = $genieacs->getDevice($genieacsDeviceId);
                        $parsed = $genieacs->parseDeviceData($deviceData);
                        $rxPower = $parsed['VirtualParameters.RXPower'] ?? $parsed['InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower'] ?? null;

                        if ($rxPower !== null) {
                            // Convert raw to dBm if needed
                            if ($rxPower > 100) {
                                $rxPower = ($rxPower / 100) - 40;
                            }

                            // Get current port_rx_power
                            $stmt = $conn->prepare("SELECT port_rx_power FROM odp_config WHERE map_item_id = ?");
                            $stmt->bind_param("i", $parentId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $odpConfig = $result->fetch_assoc();

                            $portRxPower = $odpConfig['port_rx_power'] ? json_decode($odpConfig['port_rx_power'], true) : [];
                            $portRxPower[$odpPort] = round($rxPower, 2);

                            // Update ODP config
                            $portRxPowerJson = json_encode($portRxPower);
                            $stmt = $conn->prepare("UPDATE odp_config SET port_rx_power = ? WHERE map_item_id = ?");
                            $stmt->bind_param("si", $portRxPowerJson, $parentId);
                            $stmt->execute();
                        }
                    } catch (Exception $e) {
                        // Silently fail if GenieACS error
                    }
                }
            }
            break;
    }

    jsonResponse(['success' => true, 'message' => 'Item berhasil ditambahkan', 'item_id' => $itemId]);

} catch (Exception $e) {
    // Rollback on error
    $conn->query("DELETE FROM map_items WHERE id = $itemId");
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
