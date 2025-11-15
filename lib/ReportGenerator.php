<?php
namespace App;

/**
 * Report Generator for Telegram Bot
 * Generates daily and weekly network reports
 */
class ReportGenerator {
    private $conn;
    private $genieacs;

    public function __construct($dbConnection, $genieacsInstance = null) {
        $this->conn = $dbConnection;
        $this->genieacs = $genieacsInstance;
    }

    /**
     * Generate daily report
     *
     * @param string $reportDate Date in Y-m-d format (default: today)
     * @return array Report data
     */
    public function generateDailyReport($reportDate = null) {
        if (!$reportDate) {
            $reportDate = date('Y-m-d');
        }

        $report = [
            'type' => 'daily',
            'date' => $reportDate,
            'title' => '📊 Daily Network Report',
            'total_devices' => 0,
            'online_devices' => 0,
            'offline_devices' => 0,
            'new_online_count' => 0,
            'new_offline_count' => 0,
            'offline_24h_count' => 0,
            'poor_signal_count' => 0,
            'devices_by_status' => [],
            'top_issues' => []
        ];

        // Get current device statistics
        if ($this->genieacs) {
            $devicesResult = $this->genieacs->getDevices();

            if ($devicesResult['success']) {
                $devices = $devicesResult['data'];
                $report['total_devices'] = count($devices);

                $poorSignalDevices = [];

                foreach ($devices as $device) {
                    $parsed = $this->genieacs->parseDeviceData($device);

                    if ($parsed['status'] === 'online') {
                        $report['online_devices']++;
                    } else {
                        $report['offline_devices']++;
                    }

                    // Check for poor signal (Rx Power < -25 dBm)
                    if ($parsed['rx_power'] !== 'N/A' && is_numeric($parsed['rx_power'])) {
                        $rxPower = floatval($parsed['rx_power']);
                        if ($rxPower < -25) {
                            $report['poor_signal_count']++;
                            $poorSignalDevices[] = [
                                'serial' => $parsed['serial_number'],
                                'rx_power' => $rxPower
                            ];
                        }
                    }
                }

                // Sort poor signal devices by worst signal
                usort($poorSignalDevices, function($a, $b) {
                    return $a['rx_power'] <=> $b['rx_power'];
                });
                $report['top_issues'] = array_slice($poorSignalDevices, 0, 5);
            }
        }

        // Get status changes from device_monitoring table
        $startOfDay = $reportDate . ' 00:00:00';
        $endOfDay = $reportDate . ' 23:59:59';

        // Count devices that came online today
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT device_id) as count
            FROM device_monitoring
            WHERE new_status = 'online'
            AND old_status = 'offline'
            AND checked_at BETWEEN ? AND ?
        ");
        $stmt->bind_param("ss", $startOfDay, $endOfDay);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $report['new_online_count'] = $result['count'] ?? 0;

        // Count devices that went offline today
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT device_id) as count
            FROM device_monitoring
            WHERE new_status = 'offline'
            AND old_status = 'online'
            AND checked_at BETWEEN ? AND ?
        ");
        $stmt->bind_param("ss", $startOfDay, $endOfDay);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $report['new_offline_count'] = $result['count'] ?? 0;

        // Count devices offline for more than 24 hours
        $yesterday = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT device_id) as count
            FROM device_monitoring
            WHERE new_status = 'offline'
            AND checked_at < ?
            AND device_id NOT IN (
                SELECT DISTINCT device_id
                FROM device_monitoring
                WHERE new_status = 'online'
                AND checked_at >= ?
            )
        ");
        $stmt->bind_param("ss", $yesterday, $yesterday);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $report['offline_24h_count'] = $result['count'] ?? 0;

        return $report;
    }

    /**
     * Generate weekly report
     *
     * @param string $endDate End date in Y-m-d format (default: today)
     * @return array Report data
     */
    public function generateWeeklyReport($endDate = null) {
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        $startDate = date('Y-m-d', strtotime($endDate . ' -6 days'));

        $report = [
            'type' => 'weekly',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'title' => '📊 Weekly Network Report',
            'total_devices' => 0,
            'online_devices' => 0,
            'offline_devices' => 0,
            'total_online_events' => 0,
            'total_offline_events' => 0,
            'offline_24h_count' => 0,
            'poor_signal_count' => 0,
            'avg_uptime_percent' => 0,
            'daily_breakdown' => []
        ];

        // Get current statistics
        if ($this->genieacs) {
            $stats = $this->genieacs->getDeviceStats();
            if ($stats['success']) {
                $report['total_devices'] = $stats['data']['total'];
                $report['online_devices'] = $stats['data']['online'];
                $report['offline_devices'] = $stats['data']['offline'];
            }
        }

        // Get weekly status change statistics
        $startDateTime = $startDate . ' 00:00:00';
        $endDateTime = $endDate . ' 23:59:59';

        // Count total online events
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count
            FROM device_monitoring
            WHERE new_status = 'online'
            AND old_status = 'offline'
            AND checked_at BETWEEN ? AND ?
        ");
        $stmt->bind_param("ss", $startDateTime, $endDateTime);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $report['total_online_events'] = $result['count'] ?? 0;

        // Count total offline events
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count
            FROM device_monitoring
            WHERE new_status = 'offline'
            AND old_status = 'online'
            AND checked_at BETWEEN ? AND ?
        ");
        $stmt->bind_param("ss", $startDateTime, $endDateTime);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $report['total_offline_events'] = $result['count'] ?? 0;

        // Get daily breakdown
        $stmt = $this->conn->prepare("
            SELECT
                DATE(checked_at) as date,
                SUM(CASE WHEN new_status = 'online' AND old_status = 'offline' THEN 1 ELSE 0 END) as online_count,
                SUM(CASE WHEN new_status = 'offline' AND old_status = 'online' THEN 1 ELSE 0 END) as offline_count
            FROM device_monitoring
            WHERE checked_at BETWEEN ? AND ?
            GROUP BY DATE(checked_at)
            ORDER BY date ASC
        ");
        $stmt->bind_param("ss", $startDateTime, $endDateTime);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $report['daily_breakdown'][] = [
                'date' => $row['date'],
                'online' => $row['online_count'],
                'offline' => $row['offline_count']
            ];
        }

        // Calculate average uptime (devices that stayed online / total devices)
        if ($report['total_devices'] > 0) {
            $report['avg_uptime_percent'] = round(($report['online_devices'] / $report['total_devices']) * 100, 1);
        }

        // Get poor signal count (current)
        if ($this->genieacs) {
            $devicesResult = $this->genieacs->getDevices();
            if ($devicesResult['success']) {
                foreach ($devicesResult['data'] as $device) {
                    $parsed = $this->genieacs->parseDeviceData($device);
                    if ($parsed['rx_power'] !== 'N/A' && is_numeric($parsed['rx_power'])) {
                        if (floatval($parsed['rx_power']) < -25) {
                            $report['poor_signal_count']++;
                        }
                    }
                }
            }
        }

        return $report;
    }

    /**
     * Format report as Telegram message
     *
     * @param array $report Report data from generateDailyReport() or generateWeeklyReport()
     * @return string Formatted message with HTML
     */
    public function formatReportMessage($report) {
        $message = "<b>{$report['title']}</b>\n\n";

        if ($report['type'] === 'daily') {
            $message .= "📅 Date: " . date('l, F j, Y', strtotime($report['date'])) . "\n\n";

            $message .= "📊 <b>Current Status:</b>\n";
            $message .= "Total Devices: <b>{$report['total_devices']}</b>\n";

            $onlinePercent = $report['total_devices'] > 0
                ? round(($report['online_devices'] / $report['total_devices']) * 100, 1)
                : 0;
            $message .= "🟢 Online: <b>{$report['online_devices']}</b> ({$onlinePercent}%)\n";
            $message .= "🔴 Offline: <b>{$report['offline_devices']}</b>\n\n";

            $message .= "📈 <b>Today's Activity:</b>\n";
            $message .= "✅ New Online: <b>{$report['new_online_count']}</b>\n";
            $message .= "❌ New Offline: <b>{$report['new_offline_count']}</b>\n\n";

            if ($report['offline_24h_count'] > 0 || $report['poor_signal_count'] > 0) {
                $message .= "⚠️ <b>Issues:</b>\n";
                if ($report['offline_24h_count'] > 0) {
                    $message .= "🔴 Offline >24h: <b>{$report['offline_24h_count']}</b> devices\n";
                }
                if ($report['poor_signal_count'] > 0) {
                    $message .= "📶 Poor Signal: <b>{$report['poor_signal_count']}</b> devices\n";
                }
                $message .= "\n";
            }

            // Show top issues
            if (!empty($report['top_issues'])) {
                $message .= "🔝 <b>Worst Signal:</b>\n";
                foreach (array_slice($report['top_issues'], 0, 3) as $issue) {
                    $message .= "• <code>{$issue['serial']}</code>: {$issue['rx_power']} dBm\n";
                }
            }

        } elseif ($report['type'] === 'weekly') {
            $message .= "📅 Period: " . date('M j', strtotime($report['start_date'])) . " - "
                     . date('M j, Y', strtotime($report['end_date'])) . "\n\n";

            $message .= "📊 <b>Current Status:</b>\n";
            $message .= "Total Devices: <b>{$report['total_devices']}</b>\n";
            $message .= "🟢 Online: <b>{$report['online_devices']}</b> ({$report['avg_uptime_percent']}%)\n";
            $message .= "🔴 Offline: <b>{$report['offline_devices']}</b>\n\n";

            $message .= "📈 <b>Weekly Activity:</b>\n";
            $message .= "✅ Total Online Events: <b>{$report['total_online_events']}</b>\n";
            $message .= "❌ Total Offline Events: <b>{$report['total_offline_events']}</b>\n\n";

            if ($report['poor_signal_count'] > 0) {
                $message .= "⚠️ <b>Current Issues:</b>\n";
                $message .= "📶 Poor Signal: <b>{$report['poor_signal_count']}</b> devices\n\n";
            }

            // Show daily breakdown (last 3 days)
            if (!empty($report['daily_breakdown'])) {
                $message .= "📅 <b>Recent Days:</b>\n";
                $recentDays = array_slice($report['daily_breakdown'], -3);
                foreach ($recentDays as $day) {
                    $dayName = date('D M j', strtotime($day['date']));
                    $message .= "• {$dayName}: +{$day['online']} / -{$day['offline']}\n";
                }
            }
        }

        $message .= "\n🕐 Generated: " . date('Y-m-d H:i:s');

        return $message;
    }

    /**
     * Log report to database
     *
     * @param string $chatId Telegram chat ID
     * @param array $report Report data
     * @return bool Success status
     */
    public function logReport($chatId, $report) {
        $reportDate = $report['type'] === 'daily' ? $report['date'] : $report['end_date'];
        $reportDataJson = json_encode($report);

        $stmt = $this->conn->prepare("
            INSERT INTO telegram_report_logs
            (chat_id, report_type, report_date, total_devices, online_devices, offline_devices,
             new_online_count, new_offline_count, offline_24h_count, poor_signal_count, report_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $newOnline = $report['new_online_count'] ?? ($report['total_online_events'] ?? 0);
        $newOffline = $report['new_offline_count'] ?? ($report['total_offline_events'] ?? 0);
        $offline24h = $report['offline_24h_count'] ?? 0;

        $stmt->bind_param(
            "sssiiiiiiis",
            $chatId,
            $report['type'],
            $reportDate,
            $report['total_devices'],
            $report['online_devices'],
            $report['offline_devices'],
            $newOnline,
            $newOffline,
            $offline24h,
            $report['poor_signal_count'],
            $reportDataJson
        );

        return $stmt->execute();
    }
}
