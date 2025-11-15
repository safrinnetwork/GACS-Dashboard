<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

use App\PONCalculator;

$data = json_decode(file_get_contents('php://input'), true);

$itemId = $data['item_id'] ?? null;
$name = $data['name'] ?? null;
$latitude = $data['latitude'] ?? null;
$longitude = $data['longitude'] ?? null;

if (!$itemId || !$name || !$latitude || !$longitude) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields']);
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

// Update map_items
$stmt = $conn->prepare("UPDATE map_items SET name = ?, latitude = ?, longitude = ? WHERE id = ?");
$stmt->bind_param("sddi", $name, $latitude, $longitude, $itemId);
$stmt->execute();

// Update type-specific config
switch ($itemType) {
    case 'olt':
        $outputPower = $data['output_power'] ?? 2;
        $attenuationDb = $data['attenuation_db'] ?? 0;
        $oltLink = $data['olt_link'] ?? null;

        $stmt = $conn->prepare("UPDATE olt_config SET output_power = ?, attenuation_db = ?, olt_link = ? WHERE map_item_id = ?");
        $stmt->bind_param("ddsi", $outputPower, $attenuationDb, $oltLink, $itemId);
        $stmt->execute();

        // Recalculate all child ODC power when OLT power changes
        $calculator = new PONCalculator();

        // Find all child ODCs
        $stmt = $conn->prepare("SELECT id FROM map_items WHERE parent_id = ? AND item_type = 'odc'");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $odcResult = $stmt->get_result();

        while ($odc = $odcResult->fetch_assoc()) {
            $newOdcPower = $calculator->calculateODCPower($attenuationDb, $outputPower);

            $updateStmt = $conn->prepare("UPDATE odc_config SET calculated_power = ?, parent_attenuation_db = ? WHERE map_item_id = ?");
            $updateStmt->bind_param("ddi", $newOdcPower, $attenuationDb, $odc['id']);
            $updateStmt->execute();
        }
        break;

    case 'odc':
        $portCount = $data['port_count'] ?? 4;

        $stmt = $conn->prepare("UPDATE odc_config SET port_count = ? WHERE map_item_id = ?");
        $stmt->bind_param("ii", $portCount, $itemId);
        $stmt->execute();
        break;

    case 'odp':
        $portCount = $data['port_count'] ?? 8;
        $odcPort = $data['odc_port'] ?? null;
        $parentOdpPort = $data['parent_odp_port'] ?? null;
        $useSplitter = $data['use_splitter'] ?? 0;
        $splitterRatio = $data['splitter_ratio'] ?? '1:8';
        $customRatioOutputPort = $data['custom_ratio_output_port'] ?? null;
        $useSecondarySplitter = $data['use_secondary_splitter'] ?? 0;
        $secondarySplitterRatio = $data['secondary_splitter_ratio'] ?? null;
        $customSecondaryRatioOutputPort = $data['custom_secondary_ratio_output_port'] ?? null;

        // Recalculate power based on updated splitter configuration
        $calculator = new PONCalculator();

        // Get current input_power from database
        $stmt = $conn->prepare("SELECT input_power FROM odp_config WHERE map_item_id = ?");
        $stmt->bind_param("i", $itemId);
        $stmt->execute();
        $odpResult = $stmt->get_result();
        $odpConfig = $odpResult->fetch_assoc();
        $inputPower = $odpConfig['input_power'] ?? 0;

        // Calculate power after primary splitter
        $powerAfterPrimarySplitter = $inputPower;
        if ($useSplitter && $splitterRatio) {
            $powerAfterPrimarySplitter = $calculator->calculateODPPower($inputPower, $splitterRatio, $customRatioOutputPort);
        }

        // Calculate power after secondary splitter (if used)
        $calculatedPower = $powerAfterPrimarySplitter;
        if ($useSecondarySplitter && $secondarySplitterRatio) {
            $calculatedPower = $calculator->calculateODPPower($powerAfterPrimarySplitter, $secondarySplitterRatio, $customSecondaryRatioOutputPort);
        }

        $stmt = $conn->prepare("UPDATE odp_config SET port_count = ?, odc_port = ?, parent_odp_port = ?, use_splitter = ?, splitter_ratio = ?, custom_ratio_output_port = ?, use_secondary_splitter = ?, secondary_splitter_ratio = ?, custom_secondary_ratio_output_port = ?, calculated_power = ? WHERE map_item_id = ?");
        $stmt->bind_param("iisississsdi", $portCount, $odcPort, $parentOdpPort, $useSplitter, $splitterRatio, $customRatioOutputPort, $useSecondarySplitter, $secondarySplitterRatio, $customSecondaryRatioOutputPort, $calculatedPower, $itemId);
        $stmt->execute();
        break;

    case 'onu':
        $customerName = $data['customer_name'] ?? '';

        $stmt = $conn->prepare("UPDATE onu_config SET customer_name = ? WHERE map_item_id = ?");
        $stmt->bind_param("si", $customerName, $itemId);
        $stmt->execute();
        break;
}

jsonResponse(['success' => true, 'message' => 'Item updated successfully']);
