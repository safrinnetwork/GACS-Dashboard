<?php
require_once __DIR__ . '/../config/config.php';

// Get Telegram webhook update
$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    exit;
}

// Get telegram config
$conn = getDBConnection();
$result = $conn->query("SELECT * FROM telegram_config WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$telegramConfig = $result->fetch_assoc();

if (!$telegramConfig) {
    exit;
}

use App\TelegramBot;
use App\GenieACS;

$telegram = new TelegramBot($telegramConfig['bot_token'], $telegramConfig['chat_id']);

// Check if it's a message
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';

    // Process command
    $command = $telegram->processCommand($text, $chatId);

    if (is_array($command)) {
        switch ($command['command']) {
            case 'status':
                // Get device status
                $deviceId = $command['device_id'];

                // Get GenieACS credentials
                $genieacsResult = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
                $genieacsConfig = $genieacsResult->fetch_assoc();

                if ($genieacsConfig) {
                    $genieacs = new GenieACS(
                        $genieacsConfig['host'],
                        $genieacsConfig['port'],
                        $genieacsConfig['username'],
                        $genieacsConfig['password']
                    );

                    $deviceResult = $genieacs->getDevice($deviceId);

                    if ($deviceResult['success']) {
                        $device = $genieacs->parseDeviceData($deviceResult['data']);

                        $message = "📊 <b>Device Status</b>\n\n";
                        $message .= "SN: <code>{$device['serial_number']}</code>\n";
                        $message .= "Status: " . ($device['status'] === 'Online' ? '🟢 Online' : '🔴 Offline') . "\n";
                        $message .= "IP TR069: <code>{$device['ip_tr069']}</code>\n";
                        $message .= "WiFi SSID: <code>{$device['wifi_ssid']}</code>\n";
                        $message .= "Rx Power: <code>{$device['rx_power']} dBm</code>\n";
                        $message .= "Temperature: <code>{$device['temperature']}°C</code>\n";

                        $telegram->sendMessage($message, $chatId);
                    } else {
                        $telegram->sendMessage("❌ Device tidak ditemukan", $chatId);
                    }
                } else {
                    $telegram->sendMessage("❌ GenieACS tidak terkonfigurasi", $chatId);
                }
                break;

            case 'list':
                // Get all devices
                $genieacsResult = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
                $genieacsConfig = $genieacsResult->fetch_assoc();

                if ($genieacsConfig) {
                    $genieacs = new GenieACS(
                        $genieacsConfig['host'],
                        $genieacsConfig['port'],
                        $genieacsConfig['username'],
                        $genieacsConfig['password']
                    );

                    $devicesResult = $genieacs->getDevices();

                    if ($devicesResult['success']) {
                        $devices = $devicesResult['data'];
                        $message = "📋 <b>Device List</b>\n\n";

                        $count = 0;
                        foreach ($devices as $device) {
                            $parsed = $genieacs->parseDeviceData($device);
                            $status = $parsed['status'] === 'Online' ? '🟢' : '🔴';
                            $message .= "{$status} <code>{$parsed['serial_number']}</code> - {$parsed['status']}\n";

                            $count++;
                            if ($count >= 20) {
                                $message .= "\n... dan lainnya";
                                break;
                            }
                        }

                        $telegram->sendMessage($message, $chatId);
                    } else {
                        $telegram->sendMessage("❌ Gagal mengambil daftar device", $chatId);
                    }
                } else {
                    $telegram->sendMessage("❌ GenieACS tidak terkonfigurasi", $chatId);
                }
                break;

            case 'summon':
                // Summon device
                $deviceId = $command['device_id'];

                $genieacsResult = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
                $genieacsConfig = $genieacsResult->fetch_assoc();

                if ($genieacsConfig) {
                    $genieacs = new GenieACS(
                        $genieacsConfig['host'],
                        $genieacsConfig['port'],
                        $genieacsConfig['username'],
                        $genieacsConfig['password']
                    );

                    $result = $genieacs->summonDevice($deviceId);

                    if ($result['success']) {
                        $telegram->sendMessage("✅ Device <code>{$deviceId}</code> berhasil di-summon!", $chatId);
                    } else {
                        $telegram->sendMessage("❌ Gagal summon device", $chatId);
                    }
                } else {
                    $telegram->sendMessage("❌ GenieACS tidak terkonfigurasi", $chatId);
                }
                break;
        }
    }
}

http_response_code(200);
