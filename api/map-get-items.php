<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

use App\MikroTikAPI;
use App\GenieACS;

$conn = getDBConnection();

// Helper function to get GenieACS connection
function getGenieACSConnection() {
    global $conn;
    $result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
    $creds = $result->fetch_assoc();

    if ($creds) {
        return new GenieACS($creds['host'], $creds['port']);
    }
    return null;
}

// Get all items with their configs
$result = $conn->query("SELECT * FROM map_items ORDER BY id ASC");
$items = [];
while ($row = $result->fetch_assoc()) {
    $row['properties'] = json_decode($row['properties'], true);

    // Load type-specific config
    $row['config'] = null;
    switch ($row['item_type']) {
        case 'server':
            // Get PON ports for Server
            $stmt = $conn->prepare("SELECT port_number, output_power FROM server_pon_ports WHERE map_item_id = ? ORDER BY port_number");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $ponResult = $stmt->get_result();

            $pon_ports = [];
            while ($port = $ponResult->fetch_assoc()) {
                $pon_ports[$port['port_number']] = $port['output_power'];
            }

            $row['config'] = ['pon_ports' => $pon_ports];
            break;
        case 'olt':
            $stmt = $conn->prepare("SELECT * FROM olt_config WHERE map_item_id = ?");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $configResult = $stmt->get_result();
            $row['config'] = $configResult->fetch_assoc();
            break;
        case 'odc':
            $stmt = $conn->prepare("
                SELECT oc.*, COALESCE(oc.server_id, pp.map_item_id) as server_id
                FROM odc_config oc
                LEFT JOIN server_pon_ports pp ON pp.id = oc.olt_pon_port_id
                WHERE oc.map_item_id = ?
            ");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $configResult = $stmt->get_result();
            $row['config'] = $configResult->fetch_assoc();
            break;
        case 'odp':
            $stmt = $conn->prepare("SELECT * FROM odp_config WHERE map_item_id = ?");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $configResult = $stmt->get_result();
            $row['config'] = $configResult->fetch_assoc();
            if ($row['config'] && $row['config']['port_rx_power']) {
                $row['config']['port_rx_power'] = json_decode($row['config']['port_rx_power'], true);
            }
            break;
        case 'onu':
            $stmt = $conn->prepare("SELECT * FROM onu_config WHERE map_item_id = ?");
            $stmt->bind_param("i", $row['id']);
            $stmt->execute();
            $configResult = $stmt->get_result();
            $row['config'] = $configResult->fetch_assoc();
            break;
    }

    $items[] = $row;
}

// Get Netwatch status from MikroTik
$netwatchStatus = [];
try {
    $mikrotikResult = $conn->query("SELECT * FROM mikrotik_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
    $mikrotikCreds = $mikrotikResult->fetch_assoc();

    if ($mikrotikCreds) {
        $mikrotik = new MikroTikAPI(
            $mikrotikCreds['host'],
            $mikrotikCreds['username'],
            $mikrotikCreds['password'],
            $mikrotikCreds['port']
        );

        if ($mikrotik->connect()) {
            $netwatch = $mikrotik->getNetwatch();
            foreach ($netwatch as $nw) {
                $host = $nw['host'] ?? '';
                $status = isset($nw['status']) && $nw['status'] === 'up' ? 'online' : 'offline';
                $netwatchStatus[$host] = $status;
            }
        }
    }
} catch (Exception $e) {
    // Silently fail if MikroTik not available
}

// Calculate status for each item and update database if changed
foreach ($items as &$item) {
    $oldStatus = $item['status'];
    $newStatus = calculateItemStatus($item, $items, $netwatchStatus);
    $item['status'] = $newStatus;

    // Update status in database only if changed (or was unknown and now has valid status)
    if ($oldStatus !== $newStatus) {
        // Always update if status changed, even if new status is unknown
        $stmt = $conn->prepare("UPDATE map_items SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $item['id']);
        $stmt->execute();
    }
}

// Apply cascade offline effect for OLT children
foreach ($items as &$item) {
    if ($item['item_type'] === 'olt' && $item['status'] === 'offline') {
        cascadeOfflineStatus($item['id'], $items);
    }
}

jsonResponse(['success' => true, 'items' => $items]);

/**
 * Calculate item status based on type and dependencies
 */
function calculateItemStatus($item, $allItems, $netwatchStatus) {
    switch ($item['item_type']) {
        case 'server':
            return calculateServerStatus($item, $netwatchStatus);

        case 'olt':
            return calculateOLTStatus($item, $allItems, $netwatchStatus);

        case 'odc':
            // ODC status depends on parent (for child ODC) or Server (for standalone ODC)
            if (!empty($item['parent_id'])) {
                // Child ODC - check parent status
                $parentStatus = getParentStatus($item, $allItems);
                if ($parentStatus === 'online') {
                    return 'online';
                }
                return $parentStatus;
            } else {
                // Standalone ODC - check server status via server_pon_port
                if (isset($item['config']['server_pon_port'])) {
                    // Find server that has this PON port
                    // Since we don't store server_id, we check all servers
                    foreach ($allItems as $serverItem) {
                        if ($serverItem['item_type'] === 'server') {
                            // Check if server is online
                            $serverStatus = calculateServerStatus($serverItem, $netwatchStatus);
                            if ($serverStatus === 'online') {
                                return 'online';
                            } elseif ($serverStatus === 'offline') {
                                return 'offline';
                            }
                            // If first server found, break (ODC connected to this server)
                            break;
                        }
                    }
                }
            }
            return 'unknown';

        case 'odp':
            // ODP status depends on parent
            $parentStatus = getParentStatus($item, $allItems);
            if ($parentStatus === 'online') {
                return 'online';
            }
            return $parentStatus;

        case 'onu':
            // ONU status based on GenieACS last inform (< 5 min = online)
            if (!empty($item['genieacs_device_id'])) {
                // Check parent first
                $parentStatus = getParentStatus($item, $allItems);
                if ($parentStatus === 'offline') return 'offline';

                // Check GenieACS last inform
                $genieacs = getGenieACSConnection();
                if ($genieacs) {
                    try {
                        $deviceData = $genieacs->getDevice($item['genieacs_device_id']);
                        if (isset($deviceData['data']['_lastInform'])) {
                            $lastInform = strtotime($deviceData['data']['_lastInform']);
                            $now = time();
                            $diff = $now - $lastInform;

                            // Online if last inform < 5 minutes
                            return ($diff < 300) ? 'online' : 'offline';
                        }
                    } catch (Exception $e) {
                        // GenieACS error, return unknown
                    }
                }
            }
            return 'unknown';

        default:
            return 'unknown';
    }
}

/**
 * Calculate Server status based on ISP/MikroTik/OLT links
 * Logic: Server online if ISP is online (MikroTik and OLT optional)
 */
function calculateServerStatus($item, $netwatchStatus) {
    $properties = $item['properties'] ?? [];

    $ispLink = $properties['isp_link'] ?? '';
    $mikrotikLink = $properties['mikrotik_device_id'] ?? ''; // Fixed: use mikrotik_device_id instead of mikrotik_link
    $oltLink = $properties['olt_link'] ?? '';

    // If ISP link is set and online, server is online
    if (!empty($ispLink) && isset($netwatchStatus[$ispLink])) {
        return $netwatchStatus[$ispLink];
    }

    // If MikroTik link is set and online, server is online
    if (!empty($mikrotikLink) && isset($netwatchStatus[$mikrotikLink])) {
        return $netwatchStatus[$mikrotikLink];
    }

    // If OLT link is set and online, server is online
    if (!empty($oltLink) && isset($netwatchStatus[$oltLink])) {
        return $netwatchStatus[$oltLink];
    }

    // If no links configured, status unknown
    return 'unknown';
}

/**
 * Calculate OLT status based on olt_link from properties
 */
function calculateOLTStatus($item, $allItems, $netwatchStatus) {
    $properties = $item['properties'] ?? [];
    $oltLink = $properties['olt_link'] ?? '';

    // Check if OLT has netwatch link configured
    if (!empty($oltLink) && isset($netwatchStatus[$oltLink])) {
        return $netwatchStatus[$oltLink];
    }

    // Check parent server status
    if (!empty($item['parent_id'])) {
        $parent = findItemById($item['parent_id'], $allItems);
        if ($parent && $parent['item_type'] === 'server') {
            $parentStatus = calculateServerStatus($parent, $netwatchStatus);
            if ($parentStatus === 'offline') {
                return 'offline';
            }
        }
    }

    return 'unknown';
}

/**
 * Get parent item status (recursively check if needed)
 */
function getParentStatus($item, $allItems) {
    global $netwatchStatus;

    if (empty($item['parent_id'])) {
        return 'unknown';
    }

    $parent = findItemById($item['parent_id'], $allItems);
    if (!$parent) {
        return 'unknown';
    }

    // Calculate parent status if not yet calculated
    if (!isset($parent['status']) || $parent['status'] === 'unknown') {
        $parent['status'] = calculateItemStatus($parent, $allItems, $netwatchStatus);
    }

    return $parent['status'];
}

/**
 * Find item by ID
 */
function findItemById($id, $items) {
    foreach ($items as $item) {
        if ($item['id'] == $id) {
            return $item;
        }
    }
    return null;
}

/**
 * Cascade offline status to all children when OLT is offline
 */
function cascadeOfflineStatus($oltId, &$items) {
    global $conn;
    foreach ($items as &$item) {
        if ($item['parent_id'] == $oltId) {
            $item['status'] = 'offline';

            // Update status in database
            $stmt = $conn->prepare("UPDATE map_items SET status = ? WHERE id = ?");
            $offlineStatus = 'offline';
            $stmt->bind_param("si", $offlineStatus, $item['id']);
            $stmt->execute();

            // Recursively cascade to children
            cascadeOfflineStatus($item['id'], $items);
        }
    }
}
