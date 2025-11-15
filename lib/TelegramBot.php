<?php
namespace App;

/**
 * Telegram Bot Client
 */
class TelegramBot {
    private $botToken;
    private $chatId;
    private $apiUrl;

    public function __construct($botToken = null, $chatId = null) {
        $this->botToken = $botToken;
        $this->chatId = $chatId;
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    /**
     * Make API request
     */
    private function request($method, $params = []) {
        $url = $this->apiUrl . '/' . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['ok' => false, 'error' => $error];
        }

        return json_decode($response, true);
    }

    /**
     * Test connection
     */
    public function testConnection() {
        $result = $this->request('getMe');
        return isset($result['ok']) && $result['ok'] === true;
    }

    /**
     * Send message
     */
    public function sendMessage($message, $chatId = null, $replyMarkup = null) {
        $targetChatId = $chatId ?? $this->chatId;

        $params = [
            'chat_id' => $targetChatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('sendMessage', $params);
    }

    /**
     * Send location
     */
    public function sendLocation($latitude, $longitude, $chatId = null) {
        $targetChatId = $chatId ?? $this->chatId;

        $params = [
            'chat_id' => $targetChatId,
            'latitude' => $latitude,
            'longitude' => $longitude
        ];

        return $this->request('sendLocation', $params);
    }

    /**
     * Edit message with inline keyboard
     */
    public function editMessage($messageId, $chatId, $text, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($replyMarkup) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        return $this->request('editMessageText', $params);
    }

    /**
     * Answer callback query
     */
    public function answerCallbackQuery($callbackQueryId, $text = null, $showAlert = false) {
        $params = [
            'callback_query_id' => $callbackQueryId
        ];

        if ($text) {
            $params['text'] = $text;
            $params['show_alert'] = $showAlert;
        }

        return $this->request('answerCallbackQuery', $params);
    }

    /**
     * Create main menu inline keyboard
     */
    public function getMainMenuKeyboard() {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📊 Device Status', 'callback_data' => 'menu_device_status'],
                    ['text' => '📋 Device List', 'callback_data' => 'menu_device_list']
                ],
                [
                    ['text' => '⚡ Summon Device', 'callback_data' => 'menu_summon'],
                    ['text' => '📈 Statistics', 'callback_data' => 'menu_stats']
                ],
                [
                    ['text' => '🔍 Search Device', 'callback_data' => 'menu_search'],
                    ['text' => '🔔 Subscriptions', 'callback_data' => 'menu_subscriptions']
                ],
                [
                    ['text' => '⚙️ Settings', 'callback_data' => 'menu_settings'],
                    ['text' => '❓ Help', 'callback_data' => 'menu_help']
                ]
            ]
        ];
    }

    /**
     * Create device list keyboard with pagination
     */
    public function getDeviceListKeyboard($devices, $page = 1, $perPage = 10) {
        $keyboard = ['inline_keyboard' => []];
        $totalDevices = count($devices);
        $totalPages = ceil($totalDevices / $perPage);
        $offset = ($page - 1) * $perPage;
        $deviceSlice = array_slice($devices, $offset, $perPage);

        // Add device buttons
        foreach ($deviceSlice as $device) {
            $status = $device['status'] === 'online' ? '🟢' : '🔴';
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "{$status} {$device['serial_number']}",
                    'callback_data' => "device_detail_{$device['device_id']}"
                ]
            ];
        }

        // Add pagination buttons
        $paginationRow = [];
        if ($page > 1) {
            $paginationRow[] = ['text' => '⬅️ Previous', 'callback_data' => "device_list_page_" . ($page - 1)];
        }
        $paginationRow[] = ['text' => "📄 {$page}/{$totalPages}", 'callback_data' => 'noop'];
        if ($page < $totalPages) {
            $paginationRow[] = ['text' => 'Next ➡️', 'callback_data' => "device_list_page_" . ($page + 1)];
        }
        $keyboard['inline_keyboard'][] = $paginationRow;

        // Add back button
        $keyboard['inline_keyboard'][] = [
            ['text' => '🔙 Back to Menu', 'callback_data' => 'menu_main']
        ];

        return $keyboard;
    }

    /**
     * Create device detail keyboard
     */
    public function getDeviceDetailKeyboard($deviceId) {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '⚡ Summon', 'callback_data' => "action_summon_{$deviceId}"],
                    ['text' => '🔄 Refresh', 'callback_data' => "device_detail_{$deviceId}"]
                ],
                [
                    ['text' => '📶 Edit WiFi', 'callback_data' => "action_editwifi_{$deviceId}"],
                    ['text' => '🔔 Subscribe', 'callback_data' => "action_subscribe_{$deviceId}"]
                ],
                [
                    ['text' => '🗺️ View Location', 'callback_data' => "action_location_{$deviceId}"]
                ],
                [
                    ['text' => '🔙 Back to List', 'callback_data' => 'menu_device_list']
                ]
            ]
        ];
    }

    /**
     * Create confirmation keyboard
     */
    public function getConfirmationKeyboard($action, $data) {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Yes', 'callback_data' => "confirm_yes_{$action}_{$data}"],
                    ['text' => '❌ No', 'callback_data' => "confirm_no_{$action}_{$data}"]
                ]
            ]
        ];
    }

    /**
     * Create filter keyboard
     */
    public function getFilterKeyboard() {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🟢 Online Only', 'callback_data' => 'filter_online'],
                    ['text' => '🔴 Offline Only', 'callback_data' => 'filter_offline']
                ],
                [
                    ['text' => '📶 Excellent Signal', 'callback_data' => 'filter_signal_excellent'],
                    ['text' => '📶 Good Signal', 'callback_data' => 'filter_signal_good']
                ],
                [
                    ['text' => '📶 Fair Signal', 'callback_data' => 'filter_signal_fair'],
                    ['text' => '📶 Poor Signal', 'callback_data' => 'filter_signal_poor']
                ],
                [
                    ['text' => '🔄 Show All', 'callback_data' => 'filter_all']
                ],
                [
                    ['text' => '🔙 Back to Menu', 'callback_data' => 'menu_main']
                ]
            ]
        ];
    }

    /**
     * Send device status notification
     */
    public function sendDeviceStatus($deviceId, $status, $deviceInfo = []) {
        $statusEmoji = $status === 'online' ? '🟢' : '🔴';
        $statusText = $status === 'online' ? 'ONLINE' : 'OFFLINE';

        $message = "{$statusEmoji} <b>Device Status: {$statusText}</b>\n\n";
        $message .= "Device ID: <code>{$deviceId}</code>\n";

        if (!empty($deviceInfo)) {
            if (isset($deviceInfo['serial_number'])) {
                $message .= "Serial Number: <code>{$deviceInfo['serial_number']}</code>\n";
            }
            if (isset($deviceInfo['ip_tr069'])) {
                $message .= "IP TR069: <code>{$deviceInfo['ip_tr069']}</code>\n";
            }
            if (isset($deviceInfo['customer_name'])) {
                $message .= "Customer: <b>{$deviceInfo['customer_name']}</b>\n";
            }
        }

        $message .= "\nTime: " . date('Y-m-d H:i:s');

        return $this->sendMessage($message);
    }

    /**
     * Get updates (for interactive bot)
     */
    public function getUpdates($offset = 0) {
        $params = ['offset' => $offset];
        return $this->request('getUpdates', $params);
    }

    /**
     * Process command
     */
    public function processCommand($command, $chatId) {
        $parts = explode(' ', $command);
        $cmd = strtolower($parts[0]);

        switch ($cmd) {
            case '/start':
            case '/menu':
                $message = "🤖 <b>GACS Dashboard Bot</b>\n\n";
                $message .= "Welcome! Select an option from the menu below:\n\n";
                $message .= "📊 View device status and details\n";
                $message .= "📋 Browse all devices with pagination\n";
                $message .= "⚡ Summon devices for immediate check\n";
                $message .= "📈 View network statistics\n";
                $message .= "🔍 Search and filter devices\n";
                $message .= "🔔 Manage device subscriptions";

                return $this->sendMessage($message, $chatId, $this->getMainMenuKeyboard());

            case '/status':
                if (isset($parts[1])) {
                    $deviceId = $parts[1];
                    return ['command' => 'status', 'device_id' => $deviceId, 'chat_id' => $chatId];
                }
                return $this->sendMessage("Usage: /status <device_id>\n\nOr use the menu to browse devices.", $chatId, $this->getMainMenuKeyboard());

            case '/list':
                return ['command' => 'list', 'chat_id' => $chatId];

            case '/stats':
            case '/dashboard':
                return ['command' => 'stats', 'chat_id' => $chatId];

            case '/summon':
                if (isset($parts[1])) {
                    $deviceId = $parts[1];
                    return ['command' => 'summon', 'device_id' => $deviceId, 'chat_id' => $chatId];
                }
                return $this->sendMessage("Usage: /summon <device_id>\n\nOr use the menu.", $chatId, $this->getMainMenuKeyboard());

            case '/search':
                if (isset($parts[1])) {
                    $keyword = implode(' ', array_slice($parts, 1));
                    return ['command' => 'search', 'keyword' => $keyword, 'chat_id' => $chatId];
                }
                return $this->sendMessage("Usage: /search <keyword>\n\nSearch by Serial Number, MAC Address, or WiFi SSID.", $chatId);

            case '/filter':
                return ['command' => 'filter', 'chat_id' => $chatId];

            case '/subscribe':
                if (isset($parts[1])) {
                    $deviceId = $parts[1];
                    return ['command' => 'subscribe', 'device_id' => $deviceId, 'chat_id' => $chatId];
                }
                return $this->sendMessage("Usage: /subscribe <device_id>", $chatId);

            case '/unsubscribe':
                if (isset($parts[1])) {
                    $deviceId = $parts[1];
                    return ['command' => 'unsubscribe', 'device_id' => $deviceId, 'chat_id' => $chatId];
                }
                return $this->sendMessage("Usage: /unsubscribe <device_id>", $chatId);

            case '/subscriptions':
                return ['command' => 'subscriptions', 'chat_id' => $chatId];

            case '/report':
                if (isset($parts[1])) {
                    $reportType = strtolower($parts[1]);
                    if (in_array($reportType, ['daily', 'weekly'])) {
                        return ['command' => 'report', 'report_type' => $reportType, 'chat_id' => $chatId];
                    }
                }
                return $this->sendMessage("Usage: /report <type>\n\nAvailable types:\n• daily - Generate daily report\n• weekly - Generate weekly report", $chatId);

            case '/schedule':
                if (isset($parts[1])) {
                    $action = strtolower($parts[1]);

                    if ($action === 'list') {
                        return ['command' => 'schedule_list', 'chat_id' => $chatId];
                    } elseif ($action === 'disable' && isset($parts[2])) {
                        $reportType = strtolower($parts[2]);
                        return ['command' => 'schedule_disable', 'report_type' => $reportType, 'chat_id' => $chatId];
                    } elseif ($action === 'daily' && isset($parts[2])) {
                        $time = $parts[2];
                        return ['command' => 'schedule_daily', 'time' => $time, 'chat_id' => $chatId];
                    } elseif ($action === 'weekly' && isset($parts[2]) && isset($parts[3])) {
                        $day = strtolower($parts[2]);
                        $time = $parts[3];
                        return ['command' => 'schedule_weekly', 'day' => $day, 'time' => $time, 'chat_id' => $chatId];
                    }
                }
                return $this->sendMessage(
                    "Usage: /schedule <action>\n\n" .
                    "Actions:\n" .
                    "• list - View active schedules\n" .
                    "• daily HH:MM - Schedule daily report\n" .
                    "• weekly <day> HH:MM - Schedule weekly report\n" .
                    "• disable <type> - Disable schedule\n\n" .
                    "Example:\n" .
                    "/schedule daily 08:00\n" .
                    "/schedule weekly monday 09:00\n" .
                    "/schedule disable daily",
                    $chatId
                );

            case '/whoami':
            case '/myrole':
                return ['command' => 'whoami', 'chat_id' => $chatId];

            case '/users':
                return ['command' => 'users_list', 'chat_id' => $chatId];

            case '/user':
                if (isset($parts[1])) {
                    $targetChatId = $parts[1];
                    return ['command' => 'user_info', 'target_chat_id' => $targetChatId, 'chat_id' => $chatId];
                }
                return $this->sendMessage("Usage: /user <chat_id>\n\nExample: /user 123456789", $chatId);

            case '/setrole':
                if (isset($parts[1]) && isset($parts[2])) {
                    $targetChatId = $parts[1];
                    $role = strtolower($parts[2]);
                    return ['command' => 'user_setrole', 'target_chat_id' => $targetChatId, 'role' => $role, 'chat_id' => $chatId];
                }
                return $this->sendMessage(
                    "Usage: /setrole <chat_id> <role>\n\n" .
                    "Available roles:\n" .
                    "• admin - Full access\n" .
                    "• operator - Manage devices & reports\n" .
                    "• viewer - Read-only access\n\n" .
                    "Example: /setrole 123456789 operator",
                    $chatId
                );

            case '/activate':
                if (isset($parts[1])) {
                    $targetChatId = $parts[1];
                    return ['command' => 'user_activate', 'target_chat_id' => $targetChatId, 'chat_id' => $chatId];
                }
                return $this->sendMessage("Usage: /activate <chat_id>\n\nExample: /activate 123456789", $chatId);

            case '/deactivate':
                if (isset($parts[1])) {
                    $targetChatId = $parts[1];
                    return ['command' => 'user_deactivate', 'target_chat_id' => $targetChatId, 'chat_id' => $chatId];
                }
                return $this->sendMessage("Usage: /deactivate <chat_id>\n\nExample: /deactivate 123456789", $chatId);

            case '/help':
                $helpMessage = "📖 <b>Available Commands:</b>\n\n";
                $helpMessage .= "<b>Main:</b>\n";
                $helpMessage .= "/start - Show main menu\n";
                $helpMessage .= "/menu - Show main menu\n";
                $helpMessage .= "/help - Show this help\n\n";

                $helpMessage .= "<b>Devices:</b>\n";
                $helpMessage .= "/stats - Dashboard statistics\n";
                $helpMessage .= "/list - List all devices\n";
                $helpMessage .= "/status &lt;id&gt; - Device status\n";
                $helpMessage .= "/summon &lt;id&gt; - Summon device (Operator+)\n";
                $helpMessage .= "/search &lt;keyword&gt; - Search devices\n";
                $helpMessage .= "/filter - Filter devices\n\n";

                $helpMessage .= "<b>Notifications:</b>\n";
                $helpMessage .= "/subscribe &lt;id&gt; - Subscribe to device (Operator+)\n";
                $helpMessage .= "/unsubscribe &lt;id&gt; - Unsubscribe\n";
                $helpMessage .= "/subscriptions - View subscriptions\n\n";

                $helpMessage .= "<b>Reports:</b>\n";
                $helpMessage .= "/report daily - Daily report\n";
                $helpMessage .= "/report weekly - Weekly report\n";
                $helpMessage .= "/schedule list - View schedules\n";
                $helpMessage .= "/schedule daily HH:MM - Schedule daily (Operator+)\n";
                $helpMessage .= "/schedule weekly &lt;day&gt; HH:MM - Schedule weekly (Operator+)\n\n";

                $helpMessage .= "<b>User Management:</b>\n";
                $helpMessage .= "/whoami - View your role & permissions\n";
                $helpMessage .= "/users - List all users (Admin)\n";
                $helpMessage .= "/user &lt;chat_id&gt; - View user details (Admin)\n";
                $helpMessage .= "/setrole &lt;chat_id&gt; &lt;role&gt; - Change user role (Admin)\n";
                $helpMessage .= "/activate &lt;chat_id&gt; - Activate user (Admin)\n";
                $helpMessage .= "/deactivate &lt;chat_id&gt; - Deactivate user (Admin)\n\n";

                $helpMessage .= "💡 <b>Tip:</b> Use the interactive menu for easier navigation!";

                return $this->sendMessage($helpMessage, $chatId, $this->getMainMenuKeyboard());

            default:
                return $this->sendMessage("Unknown command. Type /help for available commands.", $chatId, $this->getMainMenuKeyboard());
        }
    }
}
