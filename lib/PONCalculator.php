<?php
namespace App;

/**
 * PON Calculator
 * Based on pemudanet.com PON calculator
 */
class PONCalculator {

    // Insertion loss (applies to all splitters)
    const INSERTION_LOSS = 0.7; // dB

    // Splitter ratio losses (in dB) - WITHOUT insertion loss
    // Insertion loss will be added separately
    private static $splitterLosses = [
        '1:2' => 3.01,   // Split loss only
        '1:4' => 6.02,   // Split loss only
        '1:8' => 9.03,   // Split loss only
        '1:16' => 12.04, // Split loss only
        '1:32' => 15.05, // Split loss only
        '1:64' => 18.06, // Split loss only
    ];

    // Custom ratio losses for each port (calculated from percentage)
    // Formula: Loss (dB) = 10 × log10(1 / percentage)
    // These are SPLIT losses only, insertion loss added separately
    private static $customRatioPortLosses = [
        '20:80' => [
            '20' => 6.99,  // 10 × log10(1/0.20)
            '80' => 0.97,  // 10 × log10(1/0.80)
        ],
        '30:70' => [
            '30' => 5.23,  // 10 × log10(1/0.30)
            '70' => 1.55,  // 10 × log10(1/0.70)
        ],
        '50:50' => [
            '50' => 3.01,  // 10 × log10(1/0.50)
        ],
    ];

    /**
     * Calculate optical power budget
     *
     * @param float $inputPower Initial laser power (dBm)
     * @param string $splitterRatio Splitter ratio (e.g., '1:8')
     * @param float $fiberLoss Fiber loss (dB/km)
     * @param float $distance Distance in km
     * @param array $customRatio Custom ratio like ['20:80', '30:70', '50:50']
     * @return array Calculation results
     */
    public static function calculate($inputPower, $splitterRatio = '1:8', $fiberLoss = 0.5, $distance = 0, $customRatio = null) {
        $result = [
            'input_power' => $inputPower,
            'splitter_ratio' => $splitterRatio,
            'fiber_loss' => $fiberLoss,
            'distance' => $distance,
        ];

        // Get splitter loss
        $splitterLoss = self::$splitterLosses[$splitterRatio] ?? 10.5;
        $result['splitter_loss'] = $splitterLoss;

        // Calculate custom ratio loss if provided
        $customRatioLoss = 0;
        if ($customRatio && isset(self::$customRatioLosses[$customRatio])) {
            $customRatioLoss = self::$customRatioLosses[$customRatio];
        }
        $result['custom_ratio_loss'] = $customRatioLoss;

        // Calculate total fiber loss
        $totalFiberLoss = $fiberLoss * $distance;
        $result['total_fiber_loss'] = $totalFiberLoss;

        // Calculate total loss
        $totalLoss = $splitterLoss + $customRatioLoss + $totalFiberLoss;
        $result['total_loss'] = $totalLoss;

        // Calculate output power
        $outputPower = $inputPower - $totalLoss;
        $result['output_power'] = $outputPower;

        // Calculate next ODP power (without custom ratio loss)
        $nextOdpPower = $inputPower - $customRatioLoss - $totalFiberLoss;
        $result['next_odp_power'] = $nextOdpPower;

        // Determine signal quality
        if ($outputPower >= -20) {
            $result['signal_quality'] = 'Excellent';
            $result['signal_color'] = 'success';
        } elseif ($outputPower >= -25) {
            $result['signal_quality'] = 'Good';
            $result['signal_color'] = 'info';
        } elseif ($outputPower >= -27) {
            $result['signal_quality'] = 'Fair';
            $result['signal_color'] = 'warning';
        } else {
            $result['signal_quality'] = 'Poor';
            $result['signal_color'] = 'danger';
        }

        return $result;
    }

    /**
     * Calculate for OLT
     */
    public static function calculateOLT($attenuationDb) {
        return [
            'attenuation_db' => $attenuationDb,
            'output_power' => -$attenuationDb,
        ];
    }

    /**
     * Calculate for ODC
     */
    public static function calculateODC($parentPower, $portCount, $distance = 0, $fiberLoss = 0.5) {
        $totalFiberLoss = $fiberLoss * $distance;
        $outputPower = $parentPower - $totalFiberLoss;

        return [
            'parent_power' => $parentPower,
            'port_count' => $portCount,
            'fiber_loss' => $totalFiberLoss,
            'output_power' => $outputPower,
            'power_per_port' => $outputPower, // Same for all ports in ODC
        ];
    }

    /**
     * Calculate for ODP
     */
    public static function calculateODP($parentPower, $portCount, $useSplitter = false, $splitterRatio = '1:8', $distance = 0, $fiberLoss = 0.5) {
        $totalFiberLoss = $fiberLoss * $distance;
        $powerAfterFiber = $parentPower - $totalFiberLoss;

        $splitterLoss = 0;
        if ($useSplitter) {
            $splitterLoss = self::$splitterLosses[$splitterRatio] ?? 10.5;
        }

        $outputPower = $powerAfterFiber - $splitterLoss;

        return [
            'parent_power' => $parentPower,
            'port_count' => $portCount,
            'use_splitter' => $useSplitter,
            'splitter_ratio' => $splitterRatio,
            'splitter_loss' => $splitterLoss,
            'fiber_loss' => $totalFiberLoss,
            'output_power' => $outputPower,
            'power_per_port' => $outputPower / $portCount,
        ];
    }

    /**
     * Get available splitter ratios
     */
    public static function getSplitterRatios() {
        return array_keys(self::$splitterLosses);
    }

    /**
     * Get available custom ratios
     */
    public static function getCustomRatios() {
        return array_keys(self::$customRatioLosses);
    }

    /**
     * Calculate ODC Power (simplified for map)
     * ODC power based on OLT output power with fixed loss
     * Formula from pemudanet.com PON calculator
     *
     * Typical losses from OLT to ODC:
     * - Connector losses: 2x 0.5dB = 1dB
     * - Fiber loss: ~0.3dB/km × distance
     * - Splitter 1:4 at OLT: ~7dB (distributed to 4 ODCs)
     * - Additional margin: ~1dB
     *
     * Total typical loss: ~5.8dB (matches pemudanet.com)
     * Example: 9 dBm - 5.8 dB = 3.2 dBm
     */
    public function calculateODCPower($parentAttenuation, $oltOutputPower = 2.0) {
        // Fixed loss from OLT to ODC (based on pemudanet.com calculation)
        // This includes connector loss, fiber loss, and OLT-side splitter
        $fixedLoss = 5.8; // dB

        $calculatedPower = $oltOutputPower - $fixedLoss;

        return $calculatedPower;
    }

    /**
     * Calculate ODP Power (simplified for map)
     *
     * For standard splitters (1:2, 1:4, 1:8, etc):
     * - Total loss = Split loss + Insertion loss (0.7 dB)
     * - Example: 1:8 = 9.03 dB + 0.7 dB = 9.73 dB total
     *
     * For custom ratio splitters (20:80, 30:70, 50:50):
     * - These use asymmetric split, already include ratio calculation
     * - Still need to add insertion loss (0.7 dB)
     * - Example: 20:80 ratio uses 20% port = 6.99 dB + 0.7 dB = 7.69 dB total
     *
     * IMPORTANT: Always add insertion loss for ANY splitter configuration
     */
    public function calculateODPPower($parentPower, $splitterRatio = null, $customRatioOutputPort = null) {
        if (!$splitterRatio) {
            // No splitter - just pass through power
            return $parentPower;
        }

        // Check if this is a custom ratio splitter (20:80, 30:70, 50:50)
        if (in_array($splitterRatio, ['20:80', '30:70', '50:50']) && $customRatioOutputPort) {
            // Use the dedicated custom ratio method
            return $this->calculateCustomRatioPort($parentPower, $splitterRatio, $customRatioOutputPort);
        }

        // Get split loss (WITHOUT insertion loss yet)
        $splitLoss = 0;
        if (isset(self::$splitterLosses[$splitterRatio])) {
            // Standard splitter (1:2, 1:4, 1:8, etc.)
            $splitLoss = self::$splitterLosses[$splitterRatio];
        }

        // Add insertion loss (ALWAYS added for any splitter)
        $totalLoss = $splitLoss + self::INSERTION_LOSS;

        $calculatedPower = $parentPower - $totalLoss;

        return $calculatedPower;
    }

    /**
     * Calculate power from specific custom ratio port
     *
     * @param float $basePower Base power from parent ODP (before splitter)
     * @param string $ratio Custom ratio (e.g., "20:80")
     * @param string $selectedPort Selected port (e.g., "20%" or "80%")
     * @return float Calculated power for the selected port
     */
    public function calculateCustomRatioPort($basePower, $ratio, $selectedPort) {
        // Remove percentage sign from selected port if present
        $selectedPort = str_replace('%', '', $selectedPort);

        // Get port loss from lookup table
        if (isset(self::$customRatioPortLosses[$ratio][$selectedPort])) {
            $portLoss = self::$customRatioPortLosses[$ratio][$selectedPort];
            // Add insertion loss
            $totalLoss = $portLoss + self::INSERTION_LOSS;
            return $basePower - $totalLoss;
        }

        // Fallback: if ratio not found, return base power
        return $basePower;
    }

    /**
     * Calculate cascading power through ODP chain
     *
     * Example usage from your logic:
     * PON OLT > ODC (1:4) > ODP (20%:80%) > ODP (1:8)
     *
     * @param float $inputPower Starting RX power (e.g., 8.00 dBm from OLT)
     * @param array $cascade Array of splitter configurations
     *   Example: [
     *     ['type' => 'standard', 'ratio' => '1:4'],
     *     ['type' => 'custom', 'ratio' => '20:80', 'port' => '20'],
     *     ['type' => 'standard', 'ratio' => '1:8']
     *   ]
     * @return array Calculation steps and final result
     */
    public static function calculateCascade($inputPower, $cascade) {
        $currentPower = $inputPower;
        $steps = [];

        foreach ($cascade as $index => $config) {
            $stepNum = $index + 1;
            $stepPower = $currentPower;

            if ($config['type'] === 'standard') {
                // Standard splitter (1:2, 1:4, 1:8, etc.)
                $ratio = $config['ratio'];
                $splitLoss = self::$splitterLosses[$ratio] ?? 9.03;
                $totalLoss = $splitLoss + self::INSERTION_LOSS;
                $outputPower = $currentPower - $totalLoss;

                $steps[] = [
                    'step' => $stepNum,
                    'type' => 'Standard Splitter',
                    'ratio' => $ratio,
                    'input_power' => round($currentPower, 2),
                    'split_loss' => round($splitLoss, 2),
                    'insertion_loss' => self::INSERTION_LOSS,
                    'total_loss' => round($totalLoss, 2),
                    'output_power' => round($outputPower, 2),
                    'formula' => "{$currentPower} dBm - ({$splitLoss} dB + " . self::INSERTION_LOSS . " dB) = {$outputPower} dBm"
                ];

                $currentPower = $outputPower;

            } elseif ($config['type'] === 'custom') {
                // Custom ratio splitter (20:80, 30:70, 50:50)
                $ratio = $config['ratio'];
                $port = $config['port'];

                // Get port loss
                $portLoss = self::$customRatioPortLosses[$ratio][$port] ?? 3.01;
                $totalLoss = $portLoss + self::INSERTION_LOSS;
                $outputPower = $currentPower - $totalLoss;

                // Calculate the OTHER port power (for reference)
                list($port1, $port2) = explode(':', $ratio);
                $otherPort = ($port == $port1) ? $port2 : $port1;
                $otherPortLoss = self::$customRatioPortLosses[$ratio][$otherPort] ?? 3.01;
                $otherPortPower = $currentPower - $otherPortLoss - self::INSERTION_LOSS;

                $steps[] = [
                    'step' => $stepNum,
                    'type' => 'Custom Ratio Splitter',
                    'ratio' => $ratio,
                    'selected_port' => $port . '%',
                    'input_power' => round($currentPower, 2),
                    'port_loss' => round($portLoss, 2),
                    'insertion_loss' => self::INSERTION_LOSS,
                    'total_loss' => round($totalLoss, 2),
                    'output_power' => round($outputPower, 2),
                    'other_port' => $otherPort . '%',
                    'other_port_power' => round($otherPortPower, 2),
                    'formula' => "{$currentPower} dBm - ({$portLoss} dB + " . self::INSERTION_LOSS . " dB) = {$outputPower} dBm"
                ];

                $currentPower = $outputPower;
            }
        }

        return [
            'input_power' => round($inputPower, 2),
            'final_power' => round($currentPower, 2),
            'total_steps' => count($steps),
            'steps' => $steps
        ];
    }

    /**
     * Get total loss for a standard splitter (including insertion loss)
     */
    public static function getSplitterTotalLoss($ratio) {
        $splitLoss = self::$splitterLosses[$ratio] ?? 9.03;
        return $splitLoss + self::INSERTION_LOSS;
    }

    /**
     * Get total loss for a custom ratio port (including insertion loss)
     */
    public static function getCustomPortTotalLoss($ratio, $port) {
        $port = str_replace('%', '', $port);
        $portLoss = self::$customRatioPortLosses[$ratio][$port] ?? 3.01;
        return $portLoss + self::INSERTION_LOSS;
    }
}
