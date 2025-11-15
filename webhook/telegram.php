<?php
require_once __DIR__ . '/../config/config.php';

use App\TelegramBot;
use App\GenieACS;
use App\ReportGenerator;
use App\PermissionManager;

// Get Telegram webhook update
$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    http_response_code(200);
    exit;
}

// Get telegram config
$conn = getDBConnection();
$result = $conn->query("SELECT * FROM telegram_config WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$telegramConfig = $result->fetch_assoc();

if (!$telegramConfig) {
    http_response_code(200);
    exit;
}

$telegram = new TelegramBot($telegramConfig['bot_token'], $telegramConfig['chat_id']);

// Initialize Permission Manager
$permissionManager = new PermissionManager($conn);

// Get GenieACS config
$genieacsResult = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$genieacsConfig = $genieacsResult->fetch_assoc();

// Initialize GenieACS if configured
$genieacs = null;
if ($genieacsConfig) {
    $genieacs = new GenieACS(
        $genieacsConfig['host'],
        $genieacsConfig['port'],
        $genieacsConfig['username'],
        $genieacsConfig['password']
    );
}

// ====================
// User Authorization Middleware
// ====================
function getUserFromUpdate($update, $permissionManager) {
    $chatId = null;
    $username = null;
    $firstName = null;
    $lastName = null;

    if (isset($update['callback_query'])) {
        $user = $update['callback_query']['from'];
        $chatId = $user['id'];
        $username = $user['username'] ?? null;
        $firstName = $user['first_name'] ?? null;
        $lastName = $user['last_name'] ?? null;
    } elseif (isset($update['message'])) {
        $user = $update['message']['from'];
        $chatId = $user['id'];
        $username = $user['username'] ?? null;
        $firstName = $user['first_name'] ?? null;
        $lastName = $user['last_name'] ?? null;
    }

    if ($chatId) {
        // Auto-register new users or update existing
        $permissionManager->upsertUser($chatId, $username, $firstName, $lastName);
        $permissionManager->updateLastActivity($chatId);

        return $permissionManager->getUser($chatId);
    }

    return null;
}

// Auto-register/update user
$currentUser = getUserFromUpdate($update, $permissionManager);

// Check if user is authorized
if ($currentUser && !$currentUser['is_active']) {
    $telegram->sendMessage(
        "❌ <b>Access Denied</b>\n\n" .
        "Your account has been deactivated.\n\n" .
        "Please contact the system administrator.",
        $currentUser['chat_id']
    );
    http_response_code(200);
    exit;
}

// ====================
// Handle Callback Query (Button Clicks)
// ====================
if (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $callbackData = $callbackQuery['data'];
    $callbackId = $callbackQuery['id'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $messageId = $callbackQuery['message']['message_id'];

    // Answer callback query immediately
    $telegram->answerCallbackQuery($callbackId);

    // Parse callback data
    $parts = explode('_', $callbackData);
    $action = $parts[0];

    switch ($action) {
        case 'menu':
            handleMenuAction($parts, $chatId, $messageId, $telegram, $genieacs, $conn);
            break;

        case 'device':
            handleDeviceAction($parts, $chatId, $messageId, $telegram, $genieacs, $conn);
            break;

        case 'action':
            handleQuickAction($parts, $chatId, $messageId, $telegram, $genieacs, $conn);
            break;

        case 'confirm':
            handleConfirmation($parts, $chatId, $messageId, $telegram, $genieacs, $conn);
            break;

        case 'filter':
            handleFilter($parts, $chatId, $messageId, $telegram, $genieacs, $conn);
            break;

        case 'noop':
            // Do nothing - just for display purposes
            break;

        default:
            $telegram->answerCallbackQuery($callbackId, "Unknown action", true);
            break;
    }

    http_response_code(200);
    exit;
}

// ====================
// Handle Regular Message
// ====================
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = $message['text'] ?? '';

    // Check if user is in a session (multi-step interaction)
    $session = getUserSession($chatId, $conn);

    if ($session) {
        handleSessionMessage($session, $text, $chatId, $telegram, $genieacs, $conn);
        http_response_code(200);
        exit;
    }

    // Process command
    $command = $telegram->processCommand($text, $chatId);

    if (is_array($command)) {
        switch ($command['command']) {
            case 'status':
                handleStatusCommand($command, $chatId, $telegram, $genieacs);
                break;

            case 'list':
                handleListCommand($chatId, $telegram, $genieacs);
                break;

            case 'stats':
                handleStatsCommand($chatId, $telegram, $genieacs);
                break;

            case 'summon':
                handleSummonCommand($command, $chatId, $telegram, $genieacs);
                break;

            case 'search':
                handleSearchCommand($command, $chatId, $telegram, $genieacs);
                break;

            case 'filter':
                handleFilterCommand($chatId, $telegram);
                break;

            case 'subscribe':
                handleSubscribeCommand($command, $chatId, $telegram, $conn);
                break;

            case 'unsubscribe':
                handleUnsubscribeCommand($command, $chatId, $telegram, $conn);
                break;

            case 'subscriptions':
                handleSubscriptionsCommand($chatId, $telegram, $conn);
                break;

            case 'report':
                handleReportCommand($command, $chatId, $telegram, $genieacs, $conn);
                break;

            case 'schedule_list':
                handleScheduleListCommand($chatId, $telegram, $conn);
                break;

            case 'schedule_daily':
                handleScheduleDailyCommand($command, $chatId, $telegram, $conn);
                break;

            case 'schedule_weekly':
                handleScheduleWeeklyCommand($command, $chatId, $telegram, $conn);
                break;

            case 'schedule_disable':
                handleScheduleDisableCommand($command, $chatId, $telegram, $conn);
                break;

            case 'whoami':
                handleWhoamiCommand($chatId, $telegram, $permissionManager);
                break;

            case 'users_list':
                handleUsersListCommand($chatId, $telegram, $permissionManager);
                break;

            case 'user_info':
                handleUserInfoCommand($command, $chatId, $telegram, $permissionManager);
                break;

            case 'user_setrole':
                handleUserSetRoleCommand($command, $chatId, $telegram, $permissionManager);
                break;

            case 'user_activate':
                handleUserActivateCommand($command, $chatId, $telegram, $permissionManager);
                break;

            case 'user_deactivate':
                handleUserDeactivateCommand($command, $chatId, $telegram, $permissionManager);
                break;
        }
    }
}

http_response_code(200);

// ====================
// Menu Action Handlers
// ====================
function handleMenuAction($parts, $chatId, $messageId, $telegram, $genieacs, $conn) {
    $menuType = $parts[1] ?? 'main';

    switch ($menuType) {
        case 'main':
            $message = "🤖 <b>GACS Dashboard Bot</b>\n\n";
            $message .= "Welcome! Select an option from the menu below:";
            $telegram->editMessage($messageId, $chatId, $message, $telegram->getMainMenuKeyboard());
            break;

        case 'device':
            if ($parts[1] === 'device' && $parts[2] === 'list') {
                handleListCommand($chatId, $telegram, $genieacs, $messageId);
            } elseif ($parts[1] === 'device' && $parts[2] === 'status') {
                $telegram->editMessage($messageId, $chatId, "Please use /status <device_id> or select from device list.", $telegram->getMainMenuKeyboard());
            }
            break;

        case 'stats':
            handleStatsCommand($chatId, $telegram, $genieacs, $messageId);
            break;

        case 'search':
            $telegram->editMessage($messageId, $chatId, "🔍 <b>Search Device</b>\n\nUse command: /search <keyword>\n\nExample:\n/search F609\n/search ZICG\n/search 192.168", $telegram->getMainMenuKeyboard());
            break;

        case 'subscriptions':
            handleSubscriptionsCommand($chatId, $telegram, $conn, $messageId);
            break;

        case 'settings':
            $telegram->editMessage($messageId, $chatId, "⚙️ <b>Settings</b>\n\nComing soon!", $telegram->getMainMenuKeyboard());
            break;

        case 'help':
            $helpMessage = "📖 <b>Available Commands:</b>\n\n";
            $helpMessage .= "/start - Show main menu\n";
            $helpMessage .= "/stats - View dashboard statistics\n";
            $helpMessage .= "/list - List all devices\n";
            $helpMessage .= "/status &lt;id&gt; - Check device status\n";
            $helpMessage .= "/summon &lt;id&gt; - Summon device\n";
            $helpMessage .= "/search &lt;keyword&gt; - Search devices\n";
            $helpMessage .= "/subscribe &lt;id&gt; - Subscribe to device\n";
            $helpMessage .= "/subscriptions - View subscriptions\n";
            $helpMessage .= "/help - Show this help";
            $telegram->editMessage($messageId, $chatId, $helpMessage, $telegram->getMainMenuKeyboard());
            break;

        case 'summon':
            $telegram->editMessage($messageId, $chatId, "⚡ <b>Summon Device</b>\n\nUse command: /summon <device_id>\n\nOr select device from the list.", $telegram->getMainMenuKeyboard());
            break;
    }
}

function handleDeviceAction($parts, $chatId, $messageId, $telegram, $genieacs, $conn) {
    $deviceAction = $parts[1] ?? '';

    if ($deviceAction === 'detail') {
        $deviceId = $parts[2] ?? '';
        if ($deviceId) {
            showDeviceDetail($deviceId, $chatId, $messageId, $telegram, $genieacs);
        }
    } elseif ($deviceAction === 'list' && isset($parts[2]) && $parts[2] === 'page') {
        $page = intval($parts[3] ?? 1);
        handleListCommand($chatId, $telegram, $genieacs, $messageId, $page);
    }
}

function handleQuickAction($parts, $chatId, $messageId, $telegram, $genieacs, $conn) {
    $quickAction = $parts[1] ?? '';
    $deviceId = $parts[2] ?? '';

    switch ($quickAction) {
        case 'summon':
            if ($deviceId) {
                $message = "⚡ <b>Summon Device</b>\n\n";
                $message .= "Device ID: <code>{$deviceId}</code>\n\n";
                $message .= "Are you sure you want to summon this device?";
                $telegram->editMessage($messageId, $chatId, $message, $telegram->getConfirmationKeyboard('summon', $deviceId));
            }
            break;

        case 'subscribe':
            handleSubscribeAction($deviceId, $chatId, $messageId, $telegram, $conn);
            break;

        case 'editwifi':
            startWiFiEditSession($deviceId, $chatId, $messageId, $telegram, $conn);
            break;

        case 'location':
            showDeviceLocation($deviceId, $chatId, $messageId, $telegram, $conn);
            break;

        case 'sendgps':
            sendDeviceGPSLocation($deviceId, $chatId, $telegram, $conn);
            break;
    }
}

function handleConfirmation($parts, $chatId, $messageId, $telegram, $genieacs, $conn) {
    $confirmed = $parts[1] === 'yes';
    $action = $parts[2] ?? '';
    $data = $parts[3] ?? '';

    if (!$confirmed) {
        $telegram->editMessage($messageId, $chatId, "❌ Action cancelled.", $telegram->getMainMenuKeyboard());
        return;
    }

    switch ($action) {
        case 'summon':
            if ($genieacs) {
                $result = $genieacs->summonDevice($data);
                if ($result['success']) {
                    $telegram->editMessage($messageId, $chatId, "✅ Device <code>{$data}</code> successfully summoned!", $telegram->getMainMenuKeyboard());
                } else {
                    $telegram->editMessage($messageId, $chatId, "❌ Failed to summon device.", $telegram->getMainMenuKeyboard());
                }
            }
            break;
    }
}

function handleFilter($parts, $chatId, $messageId, $telegram, $genieacs, $conn) {
    $filterType = $parts[1] ?? 'all';

    if ($filterType === 'online' || $filterType === 'offline') {
        handleListCommand($chatId, $telegram, $genieacs, $messageId, 1, $filterType);
    } elseif (strpos($filterType, 'signal') === 0) {
        $signalLevel = $parts[2] ?? 'all';
        $telegram->editMessage($messageId, $chatId, "📶 Filtering by signal: {$signalLevel}\n\nThis feature is coming soon!", $telegram->getFilterKeyboard());
    } elseif ($filterType === 'all') {
        handleListCommand($chatId, $telegram, $genieacs, $messageId, 1, 'all');
    }
}

// ====================
// Command Handlers
// ====================
function handleStatusCommand($command, $chatId, $telegram, $genieacs) {
    if (!$genieacs) {
        $telegram->sendMessage("❌ GenieACS not configured", $chatId);
        return;
    }

    $deviceId = $command['device_id'];
    $deviceResult = $genieacs->getDevice($deviceId);

    if ($deviceResult['success']) {
        $device = $genieacs->parseDeviceData($deviceResult['data']);
        showDeviceInfo($device, $chatId, null, $telegram, $genieacs);
    } else {
        $telegram->sendMessage("❌ Device not found", $chatId);
    }
}

function handleListCommand($chatId, $telegram, $genieacs, $messageId = null, $page = 1, $filter = 'all') {
    if (!$genieacs) {
        $msg = "❌ GenieACS not configured";
        if ($messageId) {
            $telegram->editMessage($messageId, $chatId, $msg, $telegram->getMainMenuKeyboard());
        } else {
            $telegram->sendMessage($msg, $chatId);
        }
        return;
    }

    $devicesResult = $genieacs->getDevices();

    if (!$devicesResult['success']) {
        $msg = "❌ Failed to fetch devices";
        if ($messageId) {
            $telegram->editMessage($messageId, $chatId, $msg, $telegram->getMainMenuKeyboard());
        } else {
            $telegram->sendMessage($msg, $chatId);
        }
        return;
    }

    $devices = [];
    foreach ($devicesResult['data'] as $device) {
        $parsed = $genieacs->parseDeviceData($device);

        // Apply filter
        if ($filter === 'online' && $parsed['status'] !== 'online') continue;
        if ($filter === 'offline' && $parsed['status'] !== 'offline') continue;

        $devices[] = $parsed;
    }

    if (count($devices) === 0) {
        $msg = "📋 No devices found";
        if ($messageId) {
            $telegram->editMessage($messageId, $chatId, $msg, $telegram->getMainMenuKeyboard());
        } else {
            $telegram->sendMessage($msg, $chatId);
        }
        return;
    }

    $totalDevices = count($devices);
    $perPage = 10;
    $totalPages = ceil($totalDevices / $perPage);

    $filterText = $filter === 'all' ? 'All Devices' : ucfirst($filter) . ' Devices';
    $message = "📋 <b>{$filterText}</b>\n\n";
    $message .= "Total: {$totalDevices} devices\n";
    $message .= "Page: {$page}/{$totalPages}\n\n";
    $message .= "Select a device to view details:";

    $keyboard = $telegram->getDeviceListKeyboard($devices, $page, $perPage);

    if ($messageId) {
        $telegram->editMessage($messageId, $chatId, $message, $keyboard);
    } else {
        $telegram->sendMessage($message, $chatId, $keyboard);
    }
}

function handleStatsCommand($chatId, $telegram, $genieacs, $messageId = null) {
    if (!$genieacs) {
        $msg = "❌ GenieACS not configured";
        if ($messageId) {
            $telegram->editMessage($messageId, $chatId, $msg, $telegram->getMainMenuKeyboard());
        } else {
            $telegram->sendMessage($msg, $chatId);
        }
        return;
    }

    $stats = $genieacs->getDeviceStats();

    if (!$stats['success']) {
        $msg = "❌ Failed to fetch statistics";
        if ($messageId) {
            $telegram->editMessage($messageId, $chatId, $msg, $telegram->getMainMenuKeyboard());
        } else {
            $telegram->sendMessage($msg, $chatId);
        }
        return;
    }

    $data = $stats['data'];
    $onlinePercent = $data['total'] > 0 ? round(($data['online'] / $data['total']) * 100) : 0;

    $message = "📊 <b>Dashboard Statistics</b>\n\n";
    $message .= "Total Devices: <b>{$data['total']}</b>\n";
    $message .= "🟢 Online: <b>{$data['online']}</b> ({$onlinePercent}%)\n";
    $message .= "🔴 Offline: <b>{$data['offline']}</b>\n\n";
    $message .= "Last Updated: " . date('Y-m-d H:i:s');

    if ($messageId) {
        $telegram->editMessage($messageId, $chatId, $message, $telegram->getMainMenuKeyboard());
    } else {
        $telegram->sendMessage($message, $chatId, $telegram->getMainMenuKeyboard());
    }
}

function handleSummonCommand($command, $chatId, $telegram, $genieacs) {
    global $permissionManager;

    // Check permission
    if (!$permissionManager->hasPermission($chatId, \App\PermissionManager::DEVICE_SUMMON)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::DEVICE_SUMMON), $chatId);
        return;
    }

    if (!$genieacs) {
        $telegram->sendMessage("❌ GenieACS not configured", $chatId);
        return;
    }

    $deviceId = $command['device_id'];
    $result = $genieacs->summonDevice($deviceId);

    if ($result['success']) {
        $telegram->sendMessage("✅ Device <code>{$deviceId}</code> successfully summoned!", $chatId);
    } else {
        $telegram->sendMessage("❌ Failed to summon device", $chatId);
    }
}

function handleSearchCommand($command, $chatId, $telegram, $genieacs) {
    if (!$genieacs) {
        $telegram->sendMessage("❌ GenieACS not configured", $chatId);
        return;
    }

    $keyword = strtolower($command['keyword']);
    $devicesResult = $genieacs->getDevices();

    if (!$devicesResult['success']) {
        $telegram->sendMessage("❌ Failed to search devices", $chatId);
        return;
    }

    $matchedDevices = [];
    foreach ($devicesResult['data'] as $device) {
        $parsed = $genieacs->parseDeviceData($device);

        // Search in serial number, MAC address, SSID, IP
        $searchFields = [
            strtolower($parsed['serial_number']),
            strtolower($parsed['mac_address']),
            strtolower($parsed['wifi_ssid']),
            strtolower($parsed['ip_address']),
            strtolower($parsed['product_class'])
        ];

        foreach ($searchFields as $field) {
            if (strpos($field, $keyword) !== false) {
                $matchedDevices[] = $parsed;
                break;
            }
        }
    }

    if (count($matchedDevices) === 0) {
        $telegram->sendMessage("🔍 No devices found matching: <code>{$keyword}</code>", $chatId);
        return;
    }

    $message = "🔍 <b>Search Results</b>\n\n";
    $message .= "Keyword: <code>{$keyword}</code>\n";
    $message .= "Found: <b>" . count($matchedDevices) . "</b> device(s)\n\n";
    $message .= "Select a device:";

    $keyboard = $telegram->getDeviceListKeyboard($matchedDevices, 1, 10);
    $telegram->sendMessage($message, $chatId, $keyboard);
}

function handleFilterCommand($chatId, $telegram) {
    $message = "🔍 <b>Filter Devices</b>\n\n";
    $message .= "Select filter criteria:";
    $telegram->sendMessage($message, $chatId, $telegram->getFilterKeyboard());
}

function handleSubscribeCommand($command, $chatId, $telegram, $conn) {
    global $permissionManager;

    // Check permission
    if (!$permissionManager->hasPermission($chatId, \App\PermissionManager::NOTIFICATION_SUBSCRIBE)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::NOTIFICATION_SUBSCRIBE), $chatId);
        return;
    }

    $deviceId = $command['device_id'];

    // Check if already subscribed
    $stmt = $conn->prepare("SELECT id FROM telegram_subscriptions WHERE chat_id = ? AND device_id = ? AND is_active = 1");
    $stmt->bind_param("ss", $chatId, $deviceId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $telegram->sendMessage("ℹ️ You are already subscribed to <code>{$deviceId}</code>", $chatId);
        return;
    }

    // Add subscription
    $stmt = $conn->prepare("INSERT INTO telegram_subscriptions (chat_id, device_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_active = 1");
    $stmt->bind_param("ss", $chatId, $deviceId);

    if ($stmt->execute()) {
        $telegram->sendMessage("✅ Successfully subscribed to <code>{$deviceId}</code>\n\nYou will receive notifications when this device status changes.", $chatId);
    } else {
        $telegram->sendMessage("❌ Failed to subscribe to device", $chatId);
    }
}

function handleUnsubscribeCommand($command, $chatId, $telegram, $conn) {
    $deviceId = $command['device_id'];

    $stmt = $conn->prepare("UPDATE telegram_subscriptions SET is_active = 0 WHERE chat_id = ? AND device_id = ?");
    $stmt->bind_param("ss", $chatId, $deviceId);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $telegram->sendMessage("✅ Successfully unsubscribed from <code>{$deviceId}</code>", $chatId);
    } else {
        $telegram->sendMessage("❌ You are not subscribed to this device", $chatId);
    }
}

function handleSubscriptionsCommand($chatId, $telegram, $conn, $messageId = null) {
    $stmt = $conn->prepare("SELECT device_id, subscribed_at FROM telegram_subscriptions WHERE chat_id = ? AND is_active = 1 ORDER BY subscribed_at DESC");
    $stmt->bind_param("s", $chatId);
    $stmt->execute();
    $result = $stmt->get_result();

    $subscriptions = [];
    while ($row = $result->fetch_assoc()) {
        $subscriptions[] = $row;
    }

    if (count($subscriptions) === 0) {
        $msg = "🔔 <b>Your Subscriptions</b>\n\nYou have no active subscriptions.\n\nUse /subscribe <device_id> to subscribe to device notifications.";
        if ($messageId) {
            $telegram->editMessage($messageId, $chatId, $msg, $telegram->getMainMenuKeyboard());
        } else {
            $telegram->sendMessage($msg, $chatId, $telegram->getMainMenuKeyboard());
        }
        return;
    }

    $message = "🔔 <b>Your Subscriptions</b>\n\n";
    $message .= "You are subscribed to <b>" . count($subscriptions) . "</b> device(s):\n\n";

    foreach ($subscriptions as $sub) {
        $message .= "• <code>{$sub['device_id']}</code>\n";
    }

    $message .= "\n💡 Use /unsubscribe <device_id> to unsubscribe";

    if ($messageId) {
        $telegram->editMessage($messageId, $chatId, $message, $telegram->getMainMenuKeyboard());
    } else {
        $telegram->sendMessage($message, $chatId, $telegram->getMainMenuKeyboard());
    }
}

// ====================
// Helper Functions
// ====================
function showDeviceDetail($deviceId, $chatId, $messageId, $telegram, $genieacs) {
    if (!$genieacs) {
        $telegram->editMessage($messageId, $chatId, "❌ GenieACS not configured", $telegram->getMainMenuKeyboard());
        return;
    }

    $deviceResult = $genieacs->getDevice($deviceId);

    if (!$deviceResult['success']) {
        $telegram->editMessage($messageId, $chatId, "❌ Device not found", $telegram->getMainMenuKeyboard());
        return;
    }

    $device = $genieacs->parseDeviceData($deviceResult['data']);
    showDeviceInfo($device, $chatId, $messageId, $telegram, $genieacs);
}

function showDeviceInfo($device, $chatId, $messageId, $telegram, $genieacs) {
    $status = $device['status'] === 'online' ? '🟢 Online' : '🔴 Offline';

    $message = "📊 <b>Device Details</b>\n\n";
    $message .= "Serial Number: <code>{$device['serial_number']}</code>\n";
    $message .= "Product Class: <code>{$device['product_class']}</code>\n";
    $message .= "Status: {$status}\n";
    $message .= "MAC Address: <code>{$device['mac_address']}</code>\n";
    $message .= "IP Address: <code>{$device['ip_address']}</code>\n";
    $message .= "WiFi SSID: <code>{$device['wifi_ssid']}</code>\n";
    $message .= "Rx Power: <code>{$device['rx_power']} dBm</code>\n";
    $message .= "Temperature: <code>{$device['temperature']}°C</code>\n";
    $message .= "Last Inform: <code>{$device['last_inform']}</code>";

    $keyboard = $telegram->getDeviceDetailKeyboard($device['device_id']);

    if ($messageId) {
        $telegram->editMessage($messageId, $chatId, $message, $keyboard);
    } else {
        $telegram->sendMessage($message, $chatId, $keyboard);
    }
}

function handleSubscribeAction($deviceId, $chatId, $messageId, $telegram, $conn) {
    // Check if already subscribed
    $stmt = $conn->prepare("SELECT id FROM telegram_subscriptions WHERE chat_id = ? AND device_id = ? AND is_active = 1");
    $stmt->bind_param("ss", $chatId, $deviceId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $telegram->answerCallbackQuery($messageId, "Already subscribed to this device", true);
        return;
    }

    // Add subscription
    $stmt = $conn->prepare("INSERT INTO telegram_subscriptions (chat_id, device_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE is_active = 1");
    $stmt->bind_param("ss", $chatId, $deviceId);

    if ($stmt->execute()) {
        showDeviceDetail($deviceId, $chatId, $messageId, $telegram, null);
        $telegram->sendMessage("✅ Successfully subscribed to <code>{$deviceId}</code>", $chatId);
    }
}

function startWiFiEditSession($deviceId, $chatId, $messageId, $telegram, $conn) {
    global $permissionManager;

    // Check permission
    if (!$permissionManager->hasPermission($chatId, \App\PermissionManager::DEVICE_EDIT_WIFI)) {
        $telegram->editMessage($messageId, $chatId, $permissionManager->getDenialMessage(\App\PermissionManager::DEVICE_EDIT_WIFI));
        return;
    }

    // Create session
    $sessionData = json_encode(['device_id' => $deviceId, 'step' => 'ssid']);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    $stmt = $conn->prepare("INSERT INTO telegram_user_sessions (chat_id, session_type, session_data, current_step, expires_at) VALUES (?, 'editwifi', ?, 'ssid', ?) ON DUPLICATE KEY UPDATE session_data = ?, current_step = 'ssid', expires_at = ?");
    $stmt->bind_param("sssss", $chatId, $sessionData, $expiresAt, $sessionData, $expiresAt);
    $stmt->execute();

    $message = "📶 <b>Edit WiFi Configuration</b>\n\n";
    $message .= "Device ID: <code>{$deviceId}</code>\n\n";
    $message .= "Please enter new WiFi SSID:\n";
    $message .= "(or send /cancel to cancel)";

    $telegram->editMessage($messageId, $chatId, $message);
}

function showDeviceLocation($deviceId, $chatId, $messageId, $telegram, $conn) {
    // Get ONU location from database using serial number
    // First, we need to extract serial number from device_id
    $parts = explode('-', $deviceId);
    $serialNumber = end($parts);

    // Query to get ONU location through the hierarchy
    $query = "
        SELECT
            onu.id as onu_id,
            onu.name as onu_name,
            onu.latitude as onu_lat,
            onu.longitude as onu_lng,
            odp.name as odp_name,
            odc.name as odc_name,
            olt.name as olt_name,
            onu_config.odp_port
        FROM map_items onu
        LEFT JOIN onu_config ON onu.id = onu_config.onu_id
        LEFT JOIN map_items odp ON onu.parent_id = odp.id
        LEFT JOIN map_items odc ON odp.parent_id = odc.id
        LEFT JOIN map_items olt ON odc.parent_id = olt.id
        WHERE onu.item_type = 'onu'
        AND (onu_config.genieacs_device_id = ? OR onu.name LIKE ?)
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $serialLike = "%{$serialNumber}%";
    $stmt->bind_param("ss", $deviceId, $serialLike);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $message = "🗺️ <b>Device Location</b>\n\n";
        $message .= "❌ This device is not mapped in the network topology.\n\n";
        $message .= "To view location:\n";
        $message .= "1. Add device to Network Map in dashboard\n";
        $message .= "2. Assign GPS coordinates\n";
        $message .= "3. Link to ODP/ODC/OLT hierarchy";

        $telegram->editMessage($messageId, $chatId, $message, $telegram->getDeviceDetailKeyboard($deviceId));
        return;
    }

    $location = $result->fetch_assoc();

    // Check if GPS coordinates are available
    if (empty($location['onu_lat']) || empty($location['onu_lng']) ||
        $location['onu_lat'] == 0 || $location['onu_lng'] == 0) {
        $message = "🗺️ <b>Device Location</b>\n\n";
        $message .= "📍 <b>Topology Location:</b>\n";
        $message .= "ONU: {$location['onu_name']}\n";
        if ($location['odp_name']) $message .= "↳ ODP: {$location['odp_name']} (Port {$location['odp_port']})\n";
        if ($location['odc_name']) $message .= "  ↳ ODC: {$location['odc_name']}\n";
        if ($location['olt_name']) $message .= "    ↳ OLT: {$location['olt_name']}\n\n";
        $message .= "❌ GPS coordinates not set.\n\n";
        $message .= "Please set coordinates in Network Map.";

        $telegram->editMessage($messageId, $chatId, $message, $telegram->getDeviceDetailKeyboard($deviceId));
        return;
    }

    // Format coordinates (6 decimal places)
    $lat = number_format($location['onu_lat'], 6, '.', '');
    $lng = number_format($location['onu_lng'], 6, '.', '');

    // Build message with location info
    $message = "🗺️ <b>Device Location</b>\n\n";
    $message .= "📍 <b>GPS Coordinates:</b>\n";
    $message .= "Latitude: <code>{$lat}</code>\n";
    $message .= "Longitude: <code>{$lng}</code>\n\n";

    $message .= "🔗 <b>Topology Path:</b>\n";
    $message .= "ONU: {$location['onu_name']}\n";
    if ($location['odp_name']) $message .= "↳ ODP: {$location['odp_name']} (Port {$location['odp_port']})\n";
    if ($location['odc_name']) $message .= "  ↳ ODC: {$location['odc_name']}\n";
    if ($location['olt_name']) $message .= "    ↳ OLT: {$location['olt_name']}\n\n";

    // Create Google Maps URL
    $googleMapsUrl = "https://www.google.com/maps?q={$lat},{$lng}";
    $message .= "🌐 <a href=\"{$googleMapsUrl}\">Open in Google Maps</a>\n";

    // Create Network Map URL
    global $config;
    $appUrl = defined('APP_URL') ? APP_URL : 'http://localhost';
    $networkMapUrl = "{$appUrl}/map.php?focus_type=onu&focus_id={$location['onu_id']}";
    $message .= "🗺️ <a href=\"{$networkMapUrl}\">View on Network Map</a>";

    // Create keyboard with location sharing option
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📍 Send GPS Location', 'callback_data' => "action_sendgps_{$deviceId}"]
            ],
            [
                ['text' => '🔙 Back to Device', 'callback_data' => "device_detail_{$deviceId}"]
            ]
        ]
    ];

    $telegram->editMessage($messageId, $chatId, $message, $keyboard);
}

function sendDeviceGPSLocation($deviceId, $chatId, $telegram, $conn) {
    // Extract serial number from device_id
    $parts = explode('-', $deviceId);
    $serialNumber = end($parts);

    // Query to get GPS coordinates
    $query = "
        SELECT
            onu.id as onu_id,
            onu.name as onu_name,
            onu.latitude as onu_lat,
            onu.longitude as onu_lng
        FROM map_items onu
        LEFT JOIN onu_config ON onu.id = onu_config.onu_id
        WHERE onu.item_type = 'onu'
        AND (onu_config.genieacs_device_id = ? OR onu.name LIKE ?)
        LIMIT 1
    ";

    $stmt = $conn->prepare($query);
    $serialLike = "%{$serialNumber}%";
    $stmt->bind_param("ss", $deviceId, $serialLike);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $telegram->sendMessage("❌ Device location not found in map database.", $chatId);
        return;
    }

    $location = $result->fetch_assoc();

    // Check if GPS coordinates are valid
    if (empty($location['onu_lat']) || empty($location['onu_lng']) ||
        $location['onu_lat'] == 0 || $location['onu_lng'] == 0) {
        $telegram->sendMessage("❌ GPS coordinates not set for this device.", $chatId);
        return;
    }

    // Send GPS location
    $lat = floatval($location['onu_lat']);
    $lng = floatval($location['onu_lng']);

    $result = $telegram->sendLocation($lat, $lng, $chatId);

    if (isset($result['ok']) && $result['ok']) {
        // Send success message
        $telegram->sendMessage("✅ GPS location sent!\n\nDevice: <code>{$location['onu_name']}</code>", $chatId);
    } else {
        $telegram->sendMessage("❌ Failed to send GPS location.", $chatId);
    }
}

function getUserSession($chatId, $conn) {
    $stmt = $conn->prepare("SELECT * FROM telegram_user_sessions WHERE chat_id = ? AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("s", $chatId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }

    return null;
}

function handleSessionMessage($session, $text, $chatId, $telegram, $genieacs, $conn) {
    if ($text === '/cancel') {
        clearUserSession($chatId, $conn);
        $telegram->sendMessage("❌ Operation cancelled", $chatId, $telegram->getMainMenuKeyboard());
        return;
    }

    $sessionType = $session['session_type'];

    if ($sessionType === 'editwifi') {
        handleWiFiEditSession($session, $text, $chatId, $telegram, $genieacs, $conn);
    }
}

function handleWiFiEditSession($session, $text, $chatId, $telegram, $genieacs, $conn) {
    $sessionData = json_decode($session['session_data'], true);
    $deviceId = $sessionData['device_id'];
    $currentStep = $session['current_step'];

    if ($currentStep === 'ssid') {
        // Validate SSID
        if (strlen($text) < 1 || strlen($text) > 32) {
            $telegram->sendMessage("❌ SSID must be between 1-32 characters. Please try again:", $chatId);
            return;
        }

        // Save SSID and ask for password
        $sessionData['ssid'] = $text;
        updateSessionStep($chatId, 'editwifi', $sessionData, 'password', $conn);

        $message = "✅ SSID: <code>{$text}</code>\n\n";
        $message .= "Now enter WiFi password:\n";
        $message .= "(8-63 characters, or send /skip for open network)";
        $telegram->sendMessage($message, $chatId);
    } elseif ($currentStep === 'password') {
        $password = $text;

        // Validate password
        if ($text !== '/skip' && (strlen($text) < 8 || strlen($text) > 63)) {
            $telegram->sendMessage("❌ Password must be between 8-63 characters. Please try again:\n(or send /skip for open network)", $chatId);
            return;
        }

        $securityMode = $text === '/skip' ? 'None' : 'WPA2PSK';
        $ssid = $sessionData['ssid'];

        // Update WiFi via GenieACS
        if ($genieacs) {
            $result = $genieacs->setWiFiConfig($deviceId, $ssid, $password === '/skip' ? '' : $password, 1, $securityMode);

            if ($result['success']) {
                $httpCode = $result['http_code'] ?? 0;

                if ($httpCode === 200) {
                    $telegram->sendMessage("✅ WiFi configuration updated successfully!\n\nSSID: <code>{$ssid}</code>\nSecurity: {$securityMode}", $chatId, $telegram->getMainMenuKeyboard());
                } elseif ($httpCode === 202) {
                    $telegram->sendMessage("✅ WiFi configuration task queued!\n\nSSID: <code>{$ssid}</code>\nSecurity: {$securityMode}\n\nDevice will update on next inform cycle.", $chatId, $telegram->getMainMenuKeyboard());
                } else {
                    $telegram->sendMessage("✅ WiFi configuration task sent to device.\n\nSSID: <code>{$ssid}</code>\nSecurity: {$securityMode}", $chatId, $telegram->getMainMenuKeyboard());
                }
            } else {
                $telegram->sendMessage("❌ Failed to update WiFi configuration", $chatId, $telegram->getMainMenuKeyboard());
            }
        } else {
            $telegram->sendMessage("❌ GenieACS not configured", $chatId);
        }

        // Clear session
        clearUserSession($chatId, $conn);
    }
}

function updateSessionStep($chatId, $sessionType, $sessionData, $newStep, $conn) {
    $sessionDataJson = json_encode($sessionData);
    $stmt = $conn->prepare("UPDATE telegram_user_sessions SET session_data = ?, current_step = ? WHERE chat_id = ? AND session_type = ?");
    $stmt->bind_param("ssss", $sessionDataJson, $newStep, $chatId, $sessionType);
    $stmt->execute();
}

function clearUserSession($chatId, $conn) {
    $stmt = $conn->prepare("DELETE FROM telegram_user_sessions WHERE chat_id = ?");
    $stmt->bind_param("s", $chatId);
    $stmt->execute();
}

// ====================
// Report Command Handlers
// ====================
function handleReportCommand($command, $chatId, $telegram, $genieacs, $conn) {
    if (!$genieacs) {
        $telegram->sendMessage("❌ GenieACS not configured", $chatId);
        return;
    }

    $reportType = $command['report_type'];
    $reportGen = new ReportGenerator($conn, $genieacs);

    $telegram->sendMessage("⏳ Generating {$reportType} report...", $chatId);

    try {
        if ($reportType === 'daily') {
            $report = $reportGen->generateDailyReport();
        } else {
            $report = $reportGen->generateWeeklyReport();
        }

        $message = $reportGen->formatReportMessage($report);
        $telegram->sendMessage($message, $chatId);

        // Log the report
        $reportGen->logReport($chatId, $report);

    } catch (Exception $e) {
        $telegram->sendMessage("❌ Failed to generate report: " . $e->getMessage(), $chatId);
    }
}

function handleScheduleListCommand($chatId, $telegram, $conn) {
    $stmt = $conn->prepare("
        SELECT report_type, schedule_time, schedule_day, is_active, last_sent_at
        FROM telegram_report_schedules
        WHERE chat_id = ?
        ORDER BY report_type
    ");
    $stmt->bind_param("s", $chatId);
    $stmt->execute();
    $result = $stmt->get_result();

    $schedules = [];
    while ($row = $result->fetch_assoc()) {
        $schedules[] = $row;
    }

    if (count($schedules) === 0) {
        $message = "📅 <b>Scheduled Reports</b>\n\n";
        $message .= "You have no scheduled reports.\n\n";
        $message .= "Use /schedule to create one:\n";
        $message .= "• /schedule daily 08:00\n";
        $message .= "• /schedule weekly monday 09:00";

        $telegram->sendMessage($message, $chatId);
        return;
    }

    $message = "📅 <b>Scheduled Reports</b>\n\n";

    foreach ($schedules as $schedule) {
        $status = $schedule['is_active'] ? '✅ Active' : '❌ Disabled';
        $type = ucfirst($schedule['report_type']);

        $message .= "<b>{$type} Report</b> - {$status}\n";
        $message .= "Time: {$schedule['schedule_time']}\n";

        if ($schedule['report_type'] === 'weekly') {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $dayName = $days[$schedule['schedule_day']] ?? 'Unknown';
            $message .= "Day: {$dayName}\n";
        }

        if ($schedule['last_sent_at']) {
            $lastSent = date('M j, Y H:i', strtotime($schedule['last_sent_at']));
            $message .= "Last sent: {$lastSent}\n";
        } else {
            $message .= "Last sent: Never\n";
        }

        $message .= "\n";
    }

    $message .= "💡 Use /schedule disable &lt;type&gt; to disable a schedule";

    $telegram->sendMessage($message, $chatId);
}

function handleScheduleDailyCommand($command, $chatId, $telegram, $conn) {
    global $permissionManager;

    // Check permission
    if (!$permissionManager->hasPermission($chatId, \App\PermissionManager::REPORT_SCHEDULE)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::REPORT_SCHEDULE), $chatId);
        return;
    }

    $time = $command['time'];

    // Validate time format (HH:MM)
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time)) {
        $telegram->sendMessage("❌ Invalid time format. Use HH:MM (e.g., 08:00)", $chatId);
        return;
    }

    // Add :00 seconds
    $scheduleTime = $time . ':00';

    // Insert or update schedule
    $stmt = $conn->prepare("
        INSERT INTO telegram_report_schedules (chat_id, report_type, schedule_time, is_active)
        VALUES (?, 'daily', ?, 1)
        ON DUPLICATE KEY UPDATE schedule_time = ?, is_active = 1
    ");
    $stmt->bind_param("sss", $chatId, $scheduleTime, $scheduleTime);

    if ($stmt->execute()) {
        $message = "✅ <b>Daily Report Scheduled</b>\n\n";
        $message .= "Time: <code>{$time}</code>\n";
        $message .= "Timezone: Asia/Jakarta\n\n";
        $message .= "You will receive a daily report at this time every day.\n\n";
        $message .= "Use /schedule list to view all schedules.";

        $telegram->sendMessage($message, $chatId);
    } else {
        $telegram->sendMessage("❌ Failed to schedule daily report", $chatId);
    }
}

function handleScheduleWeeklyCommand($command, $chatId, $telegram, $conn) {
    global $permissionManager;

    // Check permission
    if (!$permissionManager->hasPermission($chatId, \App\PermissionManager::REPORT_SCHEDULE)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::REPORT_SCHEDULE), $chatId);
        return;
    }

    $day = $command['day'];
    $time = $command['time'];

    // Validate time format
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time)) {
        $telegram->sendMessage("❌ Invalid time format. Use HH:MM (e.g., 08:00)", $chatId);
        return;
    }

    // Map day name to day number (0 = Sunday, 6 = Saturday)
    $dayMap = [
        'sunday' => 0, 'sun' => 0,
        'monday' => 1, 'mon' => 1,
        'tuesday' => 2, 'tue' => 2,
        'wednesday' => 3, 'wed' => 3,
        'thursday' => 4, 'thu' => 4,
        'friday' => 5, 'fri' => 5,
        'saturday' => 6, 'sat' => 6
    ];

    if (!isset($dayMap[$day])) {
        $telegram->sendMessage(
            "❌ Invalid day. Use:\n" .
            "sunday, monday, tuesday, wednesday, thursday, friday, saturday\n" .
            "Or short forms: sun, mon, tue, wed, thu, fri, sat",
            $chatId
        );
        return;
    }

    $scheduleDay = $dayMap[$day];
    $scheduleTime = $time . ':00';

    // Insert or update schedule
    $stmt = $conn->prepare("
        INSERT INTO telegram_report_schedules (chat_id, report_type, schedule_time, schedule_day, is_active)
        VALUES (?, 'weekly', ?, ?, 1)
        ON DUPLICATE KEY UPDATE schedule_time = ?, schedule_day = ?, is_active = 1
    ");
    $stmt->bind_param("ssiis", $chatId, $scheduleTime, $scheduleDay, $scheduleTime, $scheduleDay);

    if ($stmt->execute()) {
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $dayName = $days[$scheduleDay];

        $message = "✅ <b>Weekly Report Scheduled</b>\n\n";
        $message .= "Day: <code>{$dayName}</code>\n";
        $message .= "Time: <code>{$time}</code>\n";
        $message .= "Timezone: Asia/Jakarta\n\n";
        $message .= "You will receive a weekly report every {$dayName} at this time.\n\n";
        $message .= "Use /schedule list to view all schedules.";

        $telegram->sendMessage($message, $chatId);
    } else {
        $telegram->sendMessage("❌ Failed to schedule weekly report", $chatId);
    }
}

function handleScheduleDisableCommand($command, $chatId, $telegram, $conn) {
    $reportType = $command['report_type'];

    if (!in_array($reportType, ['daily', 'weekly'])) {
        $telegram->sendMessage("❌ Invalid report type. Use 'daily' or 'weekly'", $chatId);
        return;
    }

    $stmt = $conn->prepare("
        UPDATE telegram_report_schedules
        SET is_active = 0
        WHERE chat_id = ? AND report_type = ?
    ");
    $stmt->bind_param("ss", $chatId, $reportType);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $message = "✅ <b>" . ucfirst($reportType) . " Report Schedule Disabled</b>\n\n";
        $message .= "You will no longer receive automated {$reportType} reports.\n\n";
        $message .= "Use /schedule {$reportType} to re-enable it.";

        $telegram->sendMessage($message, $chatId);
    } else {
        $telegram->sendMessage("❌ No active {$reportType} schedule found", $chatId);
    }
}

// ====================
// User Management Handlers
// ====================
function handleWhoamiCommand($chatId, $telegram, $permissionManager) {
    $user = $permissionManager->getUser($chatId);

    if (!$user) {
        $telegram->sendMessage("❌ User not found in database", $chatId);
        return;
    }

    $roleDisplay = $permissionManager->getRoleDisplay($user['role']);
    $permissions = $permissionManager->getUserPermissions($chatId);

    $message = "👤 <b>Your Account Information</b>\n\n";
    $message .= "Name: <b>{$user['first_name']}" . ($user['last_name'] ? ' ' . $user['last_name'] : '') . "</b>\n";
    if ($user['username']) {
        $message .= "Username: @{$user['username']}\n";
    }
    $message .= "Chat ID: <code>{$chatId}</code>\n";
    $message .= "Role: {$roleDisplay}\n";
    $message .= "Status: " . ($user['is_active'] ? '✅ Active' : '❌ Inactive') . "\n\n";

    $message .= "📋 <b>Your Permissions:</b>\n";
    if (count($permissions) > 0) {
        foreach ($permissions as $perm) {
            $permName = str_replace('_', ' ', $perm);
            $permName = ucwords(str_replace('.', ' - ', $permName));
            $message .= "• {$permName}\n";
        }
    } else {
        $message .= "No permissions assigned\n";
    }

    $telegram->sendMessage($message, $chatId);
}

function handleUsersListCommand($chatId, $telegram, $permissionManager) {
    // Check admin permission
    if (!$permissionManager->isAdmin($chatId)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::ADMIN_USER_MANAGE), $chatId);
        return;
    }

    $users = $permissionManager->getAllUsers();

    if (count($users) === 0) {
        $telegram->sendMessage("📋 No users found in database", $chatId);
        return;
    }

    $message = "👥 <b>User List</b>\n\n";
    $message .= "Total users: <b>" . count($users) . "</b>\n\n";

    foreach ($users as $user) {
        $status = $user['is_active'] ? '✅' : '❌';
        $roleDisplay = $permissionManager->getRoleDisplay($user['role']);
        $name = $user['first_name'] . ($user['last_name'] ? ' ' . $user['last_name'] : '');
        $username = $user['username'] ? ' (@' . $user['username'] . ')' : '';

        $message .= "{$status} <b>{$name}</b>{$username}\n";
        $message .= "   Chat ID: <code>{$user['chat_id']}</code>\n";
        $message .= "   Role: {$roleDisplay}\n";
        $message .= "   Last active: " . ($user['last_activity'] ? date('M j, H:i', strtotime($user['last_activity'])) : 'Never') . "\n\n";
    }

    $message .= "💡 Use /user &lt;chat_id&gt; to view details";

    $telegram->sendMessage($message, $chatId);
}

function handleUserInfoCommand($command, $chatId, $telegram, $permissionManager) {
    // Check admin permission
    if (!$permissionManager->isAdmin($chatId)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::ADMIN_USER_MANAGE), $chatId);
        return;
    }

    $targetChatId = $command['target_chat_id'];
    $user = $permissionManager->getUser($targetChatId);

    if (!$user) {
        $telegram->sendMessage("❌ User not found with Chat ID: <code>{$targetChatId}</code>", $chatId);
        return;
    }

    $roleDisplay = $permissionManager->getRoleDisplay($user['role']);
    $permissions = $permissionManager->getUserPermissions($targetChatId);

    $message = "👤 <b>User Information</b>\n\n";
    $message .= "Name: <b>{$user['first_name']}" . ($user['last_name'] ? ' ' . $user['last_name'] : '') . "</b>\n";
    if ($user['username']) {
        $message .= "Username: @{$user['username']}\n";
    }
    $message .= "Chat ID: <code>{$targetChatId}</code>\n";
    $message .= "Role: {$roleDisplay}\n";
    $message .= "Status: " . ($user['is_active'] ? '✅ Active' : '❌ Inactive') . "\n";
    $message .= "Registered: " . date('M j, Y H:i', strtotime($user['created_at'])) . "\n";
    $message .= "Last Activity: " . ($user['last_activity'] ? date('M j, Y H:i', strtotime($user['last_activity'])) : 'Never') . "\n\n";

    $message .= "📋 <b>Permissions:</b>\n";
    if (count($permissions) > 0) {
        foreach ($permissions as $perm) {
            $permName = str_replace('_', ' ', $perm);
            $permName = ucwords(str_replace('.', ' - ', $permName));
            $message .= "• {$permName}\n";
        }
    } else {
        $message .= "No permissions assigned\n";
    }

    $message .= "\n💡 <b>Management Commands:</b>\n";
    $message .= "/setrole {$targetChatId} &lt;role&gt;\n";
    $message .= "/activate {$targetChatId}\n";
    $message .= "/deactivate {$targetChatId}";

    $telegram->sendMessage($message, $chatId);
}

function handleUserSetRoleCommand($command, $chatId, $telegram, $permissionManager) {
    // Check admin permission
    if (!$permissionManager->isAdmin($chatId)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::ADMIN_USER_MANAGE), $chatId);
        return;
    }

    $targetChatId = $command['target_chat_id'];
    $role = $command['role'];

    // Validate role
    $validRoles = ['admin', 'operator', 'viewer'];
    if (!in_array($role, $validRoles)) {
        $telegram->sendMessage(
            "❌ Invalid role: <code>{$role}</code>\n\n" .
            "Valid roles:\n" .
            "• <b>admin</b> - Full access\n" .
            "• <b>operator</b> - Manage devices & reports\n" .
            "• <b>viewer</b> - Read-only access",
            $chatId
        );
        return;
    }

    // Check if user exists
    $user = $permissionManager->getUser($targetChatId);
    if (!$user) {
        $telegram->sendMessage("❌ User not found with Chat ID: <code>{$targetChatId}</code>", $chatId);
        return;
    }

    // Prevent user from changing their own role
    if ($targetChatId == $chatId) {
        $telegram->sendMessage("❌ You cannot change your own role.\n\nPlease ask another admin to do this.", $chatId);
        return;
    }

    // Set role
    $success = $permissionManager->setUserRole($targetChatId, $role);

    if ($success) {
        $roleDisplay = $permissionManager->getRoleDisplay($role);
        $userName = $user['first_name'] . ($user['last_name'] ? ' ' . $user['last_name'] : '');

        $message = "✅ <b>Role Updated</b>\n\n";
        $message .= "User: <b>{$userName}</b>\n";
        $message .= "Chat ID: <code>{$targetChatId}</code>\n";
        $message .= "New Role: {$roleDisplay}\n\n";

        // Get new permissions
        $permissions = $permissionManager->getUserPermissions($targetChatId);
        $permCount = count($permissions);
        $message .= "📋 <b>Updated Permissions ({$permCount}):</b>\n";
        foreach ($permissions as $perm) {
            $permName = str_replace('_', ' ', $perm);
            $permName = ucwords(str_replace('.', ' - ', $permName));
            $message .= "• {$permName}\n";
        }

        $telegram->sendMessage($message, $chatId);

        // Notify target user
        $notifyMessage = "👑 <b>Your role has been updated</b>\n\n";
        $notifyMessage .= "New Role: {$roleDisplay}\n\n";
        $notifyMessage .= "Use /whoami to see your new permissions.";
        $telegram->sendMessage($notifyMessage, $targetChatId);
    } else {
        $telegram->sendMessage("❌ Failed to update user role", $chatId);
    }
}

function handleUserActivateCommand($command, $chatId, $telegram, $permissionManager) {
    // Check admin permission
    if (!$permissionManager->isAdmin($chatId)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::ADMIN_USER_MANAGE), $chatId);
        return;
    }

    $targetChatId = $command['target_chat_id'];

    // Check if user exists
    $user = $permissionManager->getUser($targetChatId);
    if (!$user) {
        $telegram->sendMessage("❌ User not found with Chat ID: <code>{$targetChatId}</code>", $chatId);
        return;
    }

    if ($user['is_active']) {
        $telegram->sendMessage("ℹ️ User is already active", $chatId);
        return;
    }

    // Activate user
    $success = $permissionManager->activateUser($targetChatId);

    if ($success) {
        $userName = $user['first_name'] . ($user['last_name'] ? ' ' . $user['last_name'] : '');

        $message = "✅ <b>User Activated</b>\n\n";
        $message .= "User: <b>{$userName}</b>\n";
        $message .= "Chat ID: <code>{$targetChatId}</code>\n";
        $message .= "Role: " . $permissionManager->getRoleDisplay($user['role']) . "\n\n";
        $message .= "User can now use the bot.";

        $telegram->sendMessage($message, $chatId);

        // Notify target user
        $notifyMessage = "✅ <b>Your account has been activated</b>\n\n";
        $notifyMessage .= "You can now use the bot.\n";
        $notifyMessage .= "Use /start to get started.";
        $telegram->sendMessage($notifyMessage, $targetChatId);
    } else {
        $telegram->sendMessage("❌ Failed to activate user", $chatId);
    }
}

function handleUserDeactivateCommand($command, $chatId, $telegram, $permissionManager) {
    // Check admin permission
    if (!$permissionManager->isAdmin($chatId)) {
        $telegram->sendMessage($permissionManager->getDenialMessage(\App\PermissionManager::ADMIN_USER_MANAGE), $chatId);
        return;
    }

    $targetChatId = $command['target_chat_id'];

    // Check if user exists
    $user = $permissionManager->getUser($targetChatId);
    if (!$user) {
        $telegram->sendMessage("❌ User not found with Chat ID: <code>{$targetChatId}</code>", $chatId);
        return;
    }

    // Prevent user from deactivating themselves
    if ($targetChatId == $chatId) {
        $telegram->sendMessage("❌ You cannot deactivate your own account.\n\nPlease ask another admin to do this.", $chatId);
        return;
    }

    if (!$user['is_active']) {
        $telegram->sendMessage("ℹ️ User is already inactive", $chatId);
        return;
    }

    // Deactivate user
    $success = $permissionManager->deactivateUser($targetChatId);

    if ($success) {
        $userName = $user['first_name'] . ($user['last_name'] ? ' ' . $user['last_name'] : '');

        $message = "✅ <b>User Deactivated</b>\n\n";
        $message .= "User: <b>{$userName}</b>\n";
        $message .= "Chat ID: <code>{$targetChatId}</code>\n\n";
        $message .= "User can no longer use the bot.";

        $telegram->sendMessage($message, $chatId);

        // Notify target user
        $notifyMessage = "❌ <b>Your account has been deactivated</b>\n\n";
        $notifyMessage .= "Please contact the system administrator for assistance.";
        $telegram->sendMessage($notifyMessage, $targetChatId);
    } else {
        $telegram->sendMessage("❌ Failed to deactivate user", $chatId);
    }
}
