<?php
namespace App;

/**
 * Permission Manager for Telegram Bot
 * Handles role-based access control
 */
class PermissionManager {
    private $conn;

    // Permission constants
    const DEVICE_VIEW = 'device.view';
    const DEVICE_SUMMON = 'device.summon';
    const DEVICE_EDIT_WIFI = 'device.edit_wifi';
    const DEVICE_SEARCH = 'device.search';

    const NOTIFICATION_SUBSCRIBE = 'notification.subscribe';
    const NOTIFICATION_VIEW = 'notification.view';

    const REPORT_VIEW = 'report.view';
    const REPORT_SCHEDULE = 'report.schedule';

    const MAP_VIEW = 'map.view';

    const ADMIN_USER_MANAGE = 'admin.user_manage';
    const ADMIN_CONFIG = 'admin.config';

    // Role constants
    const ROLE_ADMIN = 'admin';
    const ROLE_OPERATOR = 'operator';
    const ROLE_VIEWER = 'viewer';

    public function __construct($dbConnection) {
        $this->conn = $dbConnection;
    }

    /**
     * Get user by chat ID
     *
     * @param string $chatId Telegram chat ID
     * @return array|null User data or null if not found
     */
    public function getUser($chatId) {
        $stmt = $this->conn->prepare("
            SELECT id, chat_id, username, first_name, last_name, role, is_active, created_at, last_activity
            FROM telegram_users
            WHERE chat_id = ?
        ");
        $stmt->bind_param("s", $chatId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }

        return null;
    }

    /**
     * Create or update user
     *
     * @param string $chatId Telegram chat ID
     * @param string $username Telegram username
     * @param string $firstName First name
     * @param string $lastName Last name
     * @param string $role User role (default: viewer)
     * @return bool Success status
     */
    public function upsertUser($chatId, $username = null, $firstName = null, $lastName = null, $role = self::ROLE_VIEWER) {
        $stmt = $this->conn->prepare("
            INSERT INTO telegram_users (chat_id, username, first_name, last_name, role, is_active, last_activity)
            VALUES (?, ?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE
                username = VALUES(username),
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                last_activity = NOW()
        ");
        $stmt->bind_param("sssss", $chatId, $username, $firstName, $lastName, $role);
        return $stmt->execute();
    }

    /**
     * Update user's last activity timestamp
     *
     * @param string $chatId Telegram chat ID
     * @return bool Success status
     */
    public function updateLastActivity($chatId) {
        $stmt = $this->conn->prepare("UPDATE telegram_users SET last_activity = NOW() WHERE chat_id = ?");
        $stmt->bind_param("s", $chatId);
        return $stmt->execute();
    }

    /**
     * Check if user has specific permission
     *
     * @param string $chatId Telegram chat ID
     * @param string $permission Permission key
     * @return bool True if user has permission
     */
    public function hasPermission($chatId, $permission) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count
            FROM telegram_user_permissions
            WHERE chat_id = ? AND permission_key = ?
        ");
        $stmt->bind_param("ss", $chatId, $permission);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result['count'] > 0;
    }

    /**
     * Get all permissions for a user
     *
     * @param string $chatId Telegram chat ID
     * @return array Array of permission keys
     */
    public function getUserPermissions($chatId) {
        $stmt = $this->conn->prepare("
            SELECT permission_key
            FROM telegram_user_permissions
            WHERE chat_id = ?
        ");
        $stmt->bind_param("s", $chatId);
        $stmt->execute();
        $result = $stmt->get_result();

        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row['permission_key'];
        }

        return $permissions;
    }

    /**
     * Check if user is admin
     *
     * @param string $chatId Telegram chat ID
     * @return bool True if user is admin
     */
    public function isAdmin($chatId) {
        $user = $this->getUser($chatId);
        return $user && $user['role'] === self::ROLE_ADMIN && $user['is_active'];
    }

    /**
     * Get user's role
     *
     * @param string $chatId Telegram chat ID
     * @return string|null Role name or null if user not found
     */
    public function getUserRole($chatId) {
        $user = $this->getUser($chatId);
        return $user ? $user['role'] : null;
    }

    /**
     * Set user's role
     *
     * @param string $chatId Telegram chat ID
     * @param string $role New role
     * @return bool Success status
     */
    public function setUserRole($chatId, $role) {
        if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_VIEWER])) {
            return false;
        }

        $stmt = $this->conn->prepare("UPDATE telegram_users SET role = ? WHERE chat_id = ?");
        $stmt->bind_param("ss", $role, $chatId);
        return $stmt->execute();
    }

    /**
     * Activate user
     *
     * @param string $chatId Telegram chat ID
     * @return bool Success status
     */
    public function activateUser($chatId) {
        $stmt = $this->conn->prepare("UPDATE telegram_users SET is_active = 1 WHERE chat_id = ?");
        $stmt->bind_param("s", $chatId);
        return $stmt->execute();
    }

    /**
     * Deactivate user
     *
     * @param string $chatId Telegram chat ID
     * @return bool Success status
     */
    public function deactivateUser($chatId) {
        $stmt = $this->conn->prepare("UPDATE telegram_users SET is_active = 0 WHERE chat_id = ?");
        $stmt->bind_param("s", $chatId);
        return $stmt->execute();
    }

    /**
     * Get all users
     *
     * @param bool $activeOnly Only return active users
     * @return array Array of users
     */
    public function getAllUsers($activeOnly = false) {
        if ($activeOnly) {
            $query = "SELECT * FROM telegram_users WHERE is_active = 1 ORDER BY created_at DESC";
        } else {
            $query = "SELECT * FROM telegram_users ORDER BY created_at DESC";
        }

        $result = $this->conn->query($query);
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        return $users;
    }

    /**
     * Check if user is authorized (registered and active)
     *
     * @param string $chatId Telegram chat ID
     * @return bool True if user is authorized
     */
    public function isAuthorized($chatId) {
        $user = $this->getUser($chatId);
        return $user && $user['is_active'];
    }

    /**
     * Get permission denial message
     *
     * @param string $permission Permission key
     * @return string Denial message
     */
    public function getDenialMessage($permission) {
        $messages = [
            self::DEVICE_SUMMON => "❌ You don't have permission to summon devices.\n\nRequired role: Operator or Admin",
            self::DEVICE_EDIT_WIFI => "❌ You don't have permission to edit WiFi configuration.\n\nRequired role: Admin",
            self::NOTIFICATION_SUBSCRIBE => "❌ You don't have permission to manage subscriptions.\n\nRequired role: Operator or Admin",
            self::REPORT_SCHEDULE => "❌ You don't have permission to schedule reports.\n\nRequired role: Operator or Admin",
            self::ADMIN_USER_MANAGE => "❌ You don't have permission to manage users.\n\nRequired role: Admin",
            self::ADMIN_CONFIG => "❌ You don't have permission to access system configuration.\n\nRequired role: Admin",
        ];

        return $messages[$permission] ?? "❌ Access denied. You don't have permission to perform this action.";
    }

    /**
     * Get role display name with emoji
     *
     * @param string $role Role name
     * @return string Display name
     */
    public function getRoleDisplay($role) {
        $displays = [
            self::ROLE_ADMIN => '👑 Admin',
            self::ROLE_OPERATOR => '⚙️ Operator',
            self::ROLE_VIEWER => '👁️ Viewer'
        ];

        return $displays[$role] ?? $role;
    }

    /**
     * Get role permissions list
     *
     * @param string $role Role name
     * @return array Array of permission keys
     */
    public function getRolePermissions($role) {
        $stmt = $this->conn->prepare("
            SELECT permission_key
            FROM telegram_role_permissions
            WHERE role = ?
        ");
        $stmt->bind_param("s", $role);
        $stmt->execute();
        $result = $stmt->get_result();

        $permissions = [];
        while ($row = $result->fetch_assoc()) {
            $permissions[] = $row['permission_key'];
        }

        return $permissions;
    }
}
