<?php
// General Configuration
define('APP_NAME', 'GACS Dashboard');
define('APP_URL', 'https://gacs.example.com');
define('ASSETS_URL', APP_URL . '/assets');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_start();

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Error Reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disabled to prevent breaking JSON responses
ini_set('log_errors', 1);

// Autoload Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Load Database Config
require_once __DIR__ . '/database.php';

// Helper Functions
require_once __DIR__ . '/../lib/helpers.php';
