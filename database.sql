-- ============================================================================
-- GACS Dashboard - Complete Database Schema
-- ============================================================================
-- Version: 1.0.1
-- Updated: October 25, 2025
-- Description: Unified database schema including all core and Telegram features
--
-- This file contains:
-- 1. Core system tables (authentication, configuration)
-- 2. GenieACS, MikroTik, Telegram integration tables
-- 3. Network topology map tables
-- 4. PON configuration tables (Server, OLT, ODC, ODP, ONU)
-- 5. Device monitoring tables (monitoring, MAC vendor cache)
-- 6. Telegram bot tables (subscriptions, sessions, permissions, reports)
--
-- Total: 23 tables + 1 view
-- ============================================================================
--
-- ⚠️ IMPORTANT INSTRUCTIONS:
-- 1. Create your database first using phpMyAdmin or command line
-- 2. Import this file INTO your created database
-- 3. This file does NOT create database automatically to allow custom naming
--
-- Example commands:
--   # Create database (choose your own name):
--   CREATE DATABASE your_database_name CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
--
--   # Import this file:
--   mysql -u username -p your_database_name < database.sql
--
-- ============================================================================

-- ============================================================================
-- SECTION 1: CORE SYSTEM TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABLE: users (Web Dashboard Authentication)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Web dashboard user authentication';

-- Insert default user
-- Username: user1234
-- Password: mostech (hashed with bcrypt)
-- ⚠️ IMPORTANT: Change this password after first login!
INSERT INTO `users` (`username`, `password`) VALUES
('user1234', '$2y$12$PUTPynxAVyLJxonzsO/TWeGwdyahOve5kJrbdEaddI32p.ZXifESe');

-- ----------------------------------------------------------------------------
-- TABLE: configurations (Generic System Configuration Storage)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `configurations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Generic key-value configuration storage';

-- ============================================================================
-- SECTION 2: INTEGRATION CONFIGURATION TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABLE: genieacs_credentials (GenieACS API Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `genieacs_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host` varchar(255) NOT NULL,
  `port` int(11) DEFAULT 7557,
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `is_connected` tinyint(1) DEFAULT 0,
  `last_test` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_host_port` (`host`, `port`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='GenieACS TR-069 ACS connection settings (single active config only)';

-- ----------------------------------------------------------------------------
-- TABLE: mikrotik_credentials (MikroTik RouterOS API Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `mikrotik_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `host` varchar(255) NOT NULL,
  `port` int(11) DEFAULT 8728,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_connected` tinyint(1) DEFAULT 0,
  `last_test` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_host_port` (`host`, `port`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='MikroTik RouterOS API connection settings (single active config only)';

-- ----------------------------------------------------------------------------
-- TABLE: telegram_config (Telegram Bot Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bot_token` varchar(255) NOT NULL,
  `chat_id` varchar(100) NOT NULL,
  `is_connected` tinyint(1) DEFAULT 0,
  `last_test` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_bot_token` (`bot_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Telegram bot API configuration (single active config only)';

-- ============================================================================
-- SECTION 3: NETWORK TOPOLOGY MAP TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABLE: map_items (Network Topology Map Items)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `map_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_type` enum('server','isp','mikrotik','olt','odc','odp','onu') NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `genieacs_device_id` varchar(255) DEFAULT NULL,
  `status` enum('online','offline','unknown') DEFAULT 'unknown',
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `idx_genieacs_device_id` (`genieacs_device_id`),
  CONSTRAINT `map_items_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Network topology items with GPS coordinates';

-- ----------------------------------------------------------------------------
-- TABLE: map_connections (Network Topology Connections/Lines)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `map_connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_item_id` int(11) NOT NULL,
  `to_item_id` int(11) NOT NULL,
  `connection_type` enum('online','offline') DEFAULT 'online',
  `path_coordinates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`path_coordinates`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `from_item_id` (`from_item_id`),
  KEY `to_item_id` (`to_item_id`),
  CONSTRAINT `map_connections_ibfk_1` FOREIGN KEY (`from_item_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `map_connections_ibfk_2` FOREIGN KEY (`to_item_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Connections between topology items with optional custom routing';

-- ============================================================================
-- SECTION 4: PON NETWORK CONFIGURATION TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABLE: server_pon_ports (Server PON Port Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `server_pon_ports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `map_item_id` int(11) NOT NULL,
  `port_number` int(11) NOT NULL,
  `output_power` decimal(5,2) DEFAULT 2.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_server_port` (`map_item_id`,`port_number`),
  CONSTRAINT `server_pon_ports_ibfk_1` FOREIGN KEY (`map_item_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Server PON port output power configuration';

-- ----------------------------------------------------------------------------
-- TABLE: olt_config (OLT - Optical Line Terminal Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `olt_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `map_item_id` int(11) NOT NULL,
  `output_power` decimal(5,2) DEFAULT 2.00,
  `pon_count` int(11) DEFAULT 1,
  `attenuation_db` decimal(5,2) DEFAULT 0.00,
  `olt_link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `map_item_id` (`map_item_id`),
  CONSTRAINT `olt_config_ibfk_1` FOREIGN KEY (`map_item_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='OLT configuration with PON ports';

-- ----------------------------------------------------------------------------
-- TABLE: olt_pon_ports (OLT PON Port Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `olt_pon_ports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `olt_item_id` int(11) NOT NULL,
  `pon_number` int(11) NOT NULL,
  `output_power` decimal(5,2) DEFAULT 9.00,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_olt_pon` (`olt_item_id`,`pon_number`),
  CONSTRAINT `olt_pon_ports_ibfk_1` FOREIGN KEY (`olt_item_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Individual OLT PON port power settings';

-- ----------------------------------------------------------------------------
-- TABLE: odc_config (ODC - Optical Distribution Cabinet Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `odc_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `map_item_id` int(11) NOT NULL,
  `olt_pon_port_id` int(11) DEFAULT NULL,
  `server_id` int(11) DEFAULT NULL COMMENT 'Reference to parent Server for child ODCs',
  `server_pon_port` int(11) DEFAULT NULL,
  `port_count` int(11) NOT NULL,
  `parent_attenuation_db` decimal(5,2) DEFAULT 0.00,
  `calculated_power` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `map_item_id` (`map_item_id`),
  CONSTRAINT `odc_config_ibfk_1` FOREIGN KEY (`map_item_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='ODC configuration with port management';

-- ----------------------------------------------------------------------------
-- TABLE: odp_config (ODP - Optical Distribution Point Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `odp_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `map_item_id` int(11) NOT NULL,
  `odc_port` int(11) DEFAULT NULL,
  `input_power` decimal(5,2) DEFAULT NULL,
  `parent_odp_port` varchar(10) DEFAULT NULL COMMENT 'For cascading ODPs: port from parent ODP (e.g., "20%", "80%")',
  `port_count` int(11) NOT NULL,
  `use_splitter` tinyint(1) DEFAULT 0,
  `use_secondary_splitter` tinyint(1) DEFAULT 0 COMMENT 'Enable secondary/cascading splitter',
  `secondary_splitter_ratio` varchar(20) DEFAULT NULL COMMENT 'Secondary splitter ratio (e.g., "1:2", "1:8")',
  `custom_secondary_ratio_output_port` varchar(10) DEFAULT NULL COMMENT 'For custom secondary ratios: which port user selected',
  `splitter_ratio` varchar(20) DEFAULT NULL COMMENT 'e.g., "1:2", "1:8", "20:80", "30:70", "50:50"',
  `custom_ratio_output_port` varchar(10) DEFAULT NULL COMMENT 'For custom ratios (20:80, 30:70, 50:50): which port user selected for output',
  `calculated_power` decimal(5,2) DEFAULT NULL COMMENT 'Power AFTER splitter (user-facing value)',
  `port_rx_power` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`port_rx_power`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `map_item_id` (`map_item_id`),
  CONSTRAINT `odp_config_ibfk_1` FOREIGN KEY (`map_item_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='ODP configuration with splitter support and cascading capability';

-- Note: input_power stores power BEFORE splitter (used for cascading ODP calculations)
--       calculated_power stores power AFTER splitter (displayed to user)

-- ----------------------------------------------------------------------------
-- TABLE: onu_config (ONU - Optical Network Unit Configuration)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `onu_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `map_item_id` int(11) NOT NULL,
  `odp_port` int(11) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `genieacs_device_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `genieacs_device_id` (`genieacs_device_id`),
  KEY `map_item_id` (`map_item_id`),
  CONSTRAINT `onu_config_ibfk_1` FOREIGN KEY (`map_item_id`) REFERENCES `map_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='ONU/ONT customer premises equipment configuration';

-- ============================================================================
-- SECTION 5: DEVICE MONITORING TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABLE: mac_vendor_cache (MAC Address Vendor Lookup Cache)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `mac_vendor_cache` (
  `oui` varchar(6) NOT NULL COMMENT 'First 6 characters of MAC address (OUI)',
  `vendor_name` varchar(255) NOT NULL,
  `cached_at` datetime NOT NULL,
  PRIMARY KEY (`oui`),
  KEY `idx_cached_at` (`cached_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Cache for MAC address vendor lookups to reduce API calls';

-- ----------------------------------------------------------------------------
-- TABLE: device_monitoring (Device Status Monitoring History)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `device_monitoring` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(255) NOT NULL,
  `status` enum('online','offline') NOT NULL,
  `notified` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Device status change history for monitoring and notifications';

-- ============================================================================
-- SECTION 6: TELEGRAM BOT TABLES
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABLE: telegram_users (Telegram Bot Users with Role-Based Access)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(255) NOT NULL,
  `username` varchar(255) DEFAULT NULL COMMENT 'Telegram username',
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `role` enum('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_activity` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_chat_id` (`chat_id`),
  KEY `idx_role` (`role`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Telegram bot users with role-based access control';

-- ----------------------------------------------------------------------------
-- TABLE: telegram_permissions (Permission Definitions)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(100) NOT NULL COMMENT 'Unique permission identifier',
  `permission_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL COMMENT 'device, report, notification, map, admin',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_permission_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Available permissions for role-based access control';

-- ----------------------------------------------------------------------------
-- TABLE: telegram_role_permissions (Role-Permission Mappings)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` enum('admin','operator','viewer') NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_role_permission` (`role`, `permission_key`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Maps permissions to roles for access control';

-- ----------------------------------------------------------------------------
-- TABLE: telegram_subscriptions (Device Notification Subscriptions)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(255) NOT NULL,
  `device_id` varchar(255) NOT NULL,
  `device_serial` varchar(255) DEFAULT NULL,
  `subscribed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_subscription` (`chat_id`, `device_id`),
  KEY `idx_chat_id` (`chat_id`),
  KEY `idx_device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='User subscriptions for device status notifications';

-- ----------------------------------------------------------------------------
-- TABLE: telegram_user_sessions (Multi-Step Interaction Sessions)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(255) NOT NULL,
  `session_type` varchar(50) NOT NULL COMMENT 'editwifi, search, etc',
  `session_data` text DEFAULT NULL COMMENT 'JSON data for the session',
  `current_step` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_id` (`chat_id`),
  KEY `idx_session_type` (`session_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Stores multi-step wizard sessions (WiFi edit, etc)';

-- ----------------------------------------------------------------------------
-- TABLE: telegram_callback_cache (Button Callback State Storage)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_callback_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cache_key` varchar(255) NOT NULL,
  `cache_data` text NOT NULL COMMENT 'Serialized or JSON data',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cache_key` (`cache_key`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Stores pagination and button state for inline keyboards';

-- ----------------------------------------------------------------------------
-- TABLE: telegram_report_schedules (Scheduled Report Configurations)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_report_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(255) NOT NULL,
  `report_type` enum('daily','weekly') NOT NULL DEFAULT 'daily',
  `schedule_time` time NOT NULL DEFAULT '08:00:00' COMMENT 'Time to send report (HH:MM:SS)',
  `schedule_day` tinyint(1) DEFAULT NULL COMMENT 'Day of week for weekly reports (0=Sunday, 6=Saturday)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_schedule` (`chat_id`, `report_type`),
  KEY `idx_chat_id` (`chat_id`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='User preferences for automated daily/weekly reports';

-- ----------------------------------------------------------------------------
-- TABLE: telegram_report_logs (Report Delivery History)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `telegram_report_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(255) NOT NULL,
  `report_type` enum('daily','weekly') NOT NULL,
  `report_date` date NOT NULL COMMENT 'Date the report covers',
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_devices` int(11) NOT NULL DEFAULT '0',
  `online_devices` int(11) NOT NULL DEFAULT '0',
  `offline_devices` int(11) NOT NULL DEFAULT '0',
  `new_online_count` int(11) NOT NULL DEFAULT '0' COMMENT 'Devices that came online',
  `new_offline_count` int(11) NOT NULL DEFAULT '0' COMMENT 'Devices that went offline',
  `offline_24h_count` int(11) NOT NULL DEFAULT '0' COMMENT 'Devices offline > 24 hours',
  `poor_signal_count` int(11) NOT NULL DEFAULT '0' COMMENT 'Devices with poor signal (<-25 dBm)',
  `report_data` text DEFAULT NULL COMMENT 'JSON data with detailed statistics',
  PRIMARY KEY (`id`),
  KEY `idx_chat_id` (`chat_id`),
  KEY `idx_report_date` (`report_date`),
  KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='History of all sent reports for analytics';

-- ============================================================================
-- SECTION 7: DEFAULT DATA INSERTS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Insert Default Permissions
-- ----------------------------------------------------------------------------

INSERT INTO `telegram_permissions` (`permission_key`, `permission_name`, `description`, `category`) VALUES
-- Device permissions
('device.view', 'View Devices', 'View device list and details', 'device'),
('device.summon', 'Summon Devices', 'Trigger device connection request', 'device'),
('device.edit_wifi', 'Edit WiFi', 'Change device WiFi configuration', 'device'),
('device.search', 'Search Devices', 'Search and filter devices', 'device'),

-- Notification permissions
('notification.subscribe', 'Subscribe Notifications', 'Subscribe to device notifications', 'notification'),
('notification.view', 'View Subscriptions', 'View own subscriptions', 'notification'),

-- Report permissions
('report.view', 'View Reports', 'Generate on-demand reports', 'report'),
('report.schedule', 'Schedule Reports', 'Create and manage report schedules', 'report'),

-- Map permissions
('map.view', 'View Map', 'View device locations and GPS', 'map'),

-- Admin permissions
('admin.user_manage', 'Manage Users', 'Add, edit, remove users and roles', 'admin'),
('admin.config', 'System Configuration', 'Access system configuration', 'admin')
ON DUPLICATE KEY UPDATE permission_name = VALUES(permission_name);

-- ----------------------------------------------------------------------------
-- Insert Default Role Permissions
-- ----------------------------------------------------------------------------

-- VIEWER role (read-only: 4 permissions)
INSERT INTO `telegram_role_permissions` (`role`, `permission_key`) VALUES
('viewer', 'device.view'),
('viewer', 'device.search'),
('viewer', 'notification.view'),
('viewer', 'report.view'),
('viewer', 'map.view')
ON DUPLICATE KEY UPDATE role = role;

-- OPERATOR role (device management: 8 permissions)
INSERT INTO `telegram_role_permissions` (`role`, `permission_key`) VALUES
('operator', 'device.view'),
('operator', 'device.summon'),
('operator', 'device.search'),
('operator', 'notification.subscribe'),
('operator', 'notification.view'),
('operator', 'report.view'),
('operator', 'report.schedule'),
('operator', 'map.view')
ON DUPLICATE KEY UPDATE role = role;

-- ADMIN role (full access: 11 permissions)
INSERT INTO `telegram_role_permissions` (`role`, `permission_key`) VALUES
('admin', 'device.view'),
('admin', 'device.summon'),
('admin', 'device.edit_wifi'),
('admin', 'device.search'),
('admin', 'notification.subscribe'),
('admin', 'notification.view'),
('admin', 'report.view'),
('admin', 'report.schedule'),
('admin', 'map.view'),
('admin', 'admin.user_manage'),
('admin', 'admin.config')
ON DUPLICATE KEY UPDATE role = role;

-- ============================================================================
-- SECTION 8: VIEWS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- VIEW: telegram_user_permissions (Easy Permission Lookup)
-- ----------------------------------------------------------------------------

CREATE OR REPLACE VIEW `telegram_user_permissions` AS
SELECT
    tu.chat_id,
    tu.username,
    tu.role,
    tu.is_active,
    tp.permission_key,
    tp.permission_name,
    tp.category
FROM telegram_users tu
JOIN telegram_role_permissions trp ON tu.role = trp.role
JOIN telegram_permissions tp ON trp.permission_key = tp.permission_key
WHERE tu.is_active = 1;

-- ============================================================================
-- DATABASE SCHEMA COMPLETE
-- ============================================================================
--
-- This database schema is production-ready and includes:
-- ✓ Core system authentication and configuration
-- ✓ GenieACS, MikroTik, Telegram integrations
-- ✓ Network topology mapping with GPS
-- ✓ PON network configuration (Server→OLT→ODC→ODP→ONU)
-- ✓ Device monitoring and status tracking
-- ✓ Telegram bot with multi-user support
-- ✓ Role-based access control (Admin/Operator/Viewer)
-- ✓ Device subscriptions and notifications
-- ✓ Multi-step wizard sessions (WiFi edit)
-- ✓ Scheduled reports (daily/weekly)
--
-- Default Credentials:
--   Web Dashboard: user1234 / mostech
--   Telegram Bot: No default user (create admin via SQL)
--
-- ⚠️ SECURITY REMINDERS:
-- 1. Change the default web dashboard password after first login
-- 2. Create first Telegram admin user with your chat_id:
--    INSERT INTO telegram_users (chat_id, username, first_name, role, is_active)
--    VALUES ('YOUR_CHAT_ID', 'username', 'First Name', 'admin', 1);
-- 3. Configure integrations via Configuration page
-- 4. Keep database credentials secure
-- 5. Enable HTTPS in production
-- 6. Set proper file permissions (chmod 600 config/*.php)
-- 7. Setup cron jobs for monitoring and reports
--
-- For deployment to new hosting:
-- 1. Create your database with any name you want
-- 2. Import this database.sql file into your database
-- 3. Update config/database.php with your credentials
-- 4. config/config.php will AUTO-DETECT your domain (no manual edit needed!)
-- 5. Set file permissions (chmod 600 config/*.php)
-- 6. Configure .htaccess for Apache or nginx config
-- 7. Setup SSL certificate
-- 8. Setup cron jobs (device-monitor.php, webhook-monitor.php, backup.sh)
--
-- Version History:
-- v1.0.1 (2025-10-25) - GenieACS Role Detection Feature
--   - Added: `role` VARCHAR(50) column to genieacs_credentials table
--   - Purpose: Store GenieACS user role (admin/user) from role detection feature
--
-- v1.0.0-beta (2025-10-19) - Universal schema (no hardcoded database name)
--   - Added: mac_vendor_cache table for MAC address vendor lookups
--   - Updated: odc_config with server_id column for child ODC support
--
-- ============================================================================
