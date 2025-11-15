<?php
/**
 * Recalculate ODP Power
 * Script untuk menghitung ulang power pada semua ODP dengan benar
 * Menambahkan insertion loss 0.7 dB yang sebelumnya hilang
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

use App\PONCalculator;

$conn = getDBConnection();
$calculator = new PONCalculator();

// Get all ODP items
$stmt = $conn->prepare("
    SELECT mi.id, mi.name, mi.parent_id, mi.item_type,
           odp.input_power, odp.use_splitter, odp.splitter_ratio, odp.calculated_power, odp.parent_odp_port
    FROM map_items mi
    JOIN odp_config odp ON odp.map_item_id = mi.id
    WHERE mi.item_type = 'odp'
    ORDER BY mi.id
");
$stmt->execute();
$result = $stmt->get_result();

$updated = [];
$errors = [];

while ($odp = $result->fetch_assoc()) {
    $odpId = $odp['id'];
    $odpName = $odp['name'];
    $parentId = $odp['parent_id'];
    $inputPower = $odp['input_power'];
    $useSplitter = $odp['use_splitter'];
    $splitterRatio = $odp['splitter_ratio'];
    $oldCalculatedPower = $odp['calculated_power'];
    $parentOdpPort = $odp['parent_odp_port'];

    try {
        // Re-calculate input power if parent is ODP with custom ratio
        if ($parentId && $parentOdpPort) {
            // Check if parent is ODP
            $parentStmt = $conn->prepare("SELECT item_type FROM map_items WHERE id = ?");
            $parentStmt->bind_param("i", $parentId);
            $parentStmt->execute();
            $parentResult = $parentStmt->get_result();
            $parentItem = $parentResult->fetch_assoc();

            if ($parentItem && $parentItem['item_type'] === 'odp') {
                // Get parent ODP config
                $parentConfigStmt = $conn->prepare("SELECT input_power, splitter_ratio FROM odp_config WHERE map_item_id = ?");
                $parentConfigStmt->bind_param("i", $parentId);
                $parentConfigStmt->execute();
                $parentConfigResult = $parentConfigStmt->get_result();
                $parentConfig = $parentConfigResult->fetch_assoc();

                if ($parentConfig) {
                    $parentInputPower = $parentConfig['input_power'];
                    $parentRatio = $parentConfig['splitter_ratio'];

                    // Recalculate input power from parent custom ratio port
                    if ($parentOdpPort && $parentRatio) {
                        $inputPower = $calculator->calculateCustomRatioPort($parentInputPower, $parentRatio, $parentOdpPort);
                    }
                }
            }
        }

        // Recalculate ODP power dengan insertion loss yang benar
        $newCalculatedPower = $calculator->calculateODPPower($inputPower, $useSplitter ? $splitterRatio : null);

        // Update database
        $updateStmt = $conn->prepare("UPDATE odp_config SET input_power = ?, calculated_power = ? WHERE map_item_id = ?");
        $updateStmt->bind_param("ddi", $inputPower, $newCalculatedPower, $odpId);
        $updateStmt->execute();

        $updated[] = [
            'id' => $odpId,
            'name' => $odpName,
            'old_calculated_power' => round($oldCalculatedPower, 2),
            'new_calculated_power' => round($newCalculatedPower, 2),
            'input_power' => round($inputPower, 2),
            'splitter_ratio' => $splitterRatio,
            'difference' => round($newCalculatedPower - $oldCalculatedPower, 2)
        ];

    } catch (Exception $e) {
        $errors[] = [
            'id' => $odpId,
            'name' => $odpName,
            'error' => $e->getMessage()
        ];
    }
}

jsonResponse([
    'success' => true,
    'message' => 'Recalculation completed',
    'total_updated' => count($updated),
    'total_errors' => count($errors),
    'updated' => $updated,
    'errors' => $errors
]);
