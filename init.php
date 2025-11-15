<?php
/**
 * ============================================================================
 * GACS Dashboard - Initial Setup Wizard
 * ============================================================================
 * Standalone PHP installer that checks and configures the system
 * This file is independent and does NOT require Composer dependencies
 *
 * @version 1.1.0
 * @author Mostech
 * ============================================================================
 */

// ============================================================================
// CONFIGURATION
// ============================================================================

// Start session for authentication
session_start();

// Setup credentials (default, same as main application)
define('INIT_USER', 'user1234');
define('INIT_PASS', 'mostech');

// Get application root path
$rootPath = __DIR__;

// ============================================================================
// AUTHENTICATION HANDLERS
// ============================================================================

/**
 * Handle logout request
 */
if (isset($_GET['logout'])) {
    unset($_SESSION['init_authenticated']);
    header('Location: init.php');
    exit;
}

/**
 * Handle login submission
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username === INIT_USER && $password === INIT_PASS) {
        $_SESSION['init_authenticated'] = true;
        header('Location: init.php');
        exit;
    } else {
        $loginError = 'Username atau password salah!';
    }
}

/**
 * Check if user is authenticated
 */
$isAuthenticated = isset($_SESSION['init_authenticated']) && $_SESSION['init_authenticated'] === true;

// ============================================================================
// SYSTEM CHECKS
// ============================================================================

/**
 * Initialize checks array
 */
$checks = [];
$dbError = null;

// ============================================================================
// CHECK 1: Composer Dependencies
// ============================================================================

$composerInstalled = file_exists($rootPath . '/vendor/autoload.php');
$composerJsonExists = file_exists($rootPath . '/composer.json');

$checks['composer'] = [
    'status' => $composerInstalled,
    'label' => 'Composer Dependencies',
    'message' => $composerInstalled ? 'Vendor folder ditemukan' : 'Composer belum diinstall',
    'action' => !$composerInstalled && $composerJsonExists ? 'install' : null
];

// ============================================================================
// CHECK 2: Configuration Files
// ============================================================================

$configDirExists = is_dir($rootPath . '/config');
$configPhpExists = file_exists($rootPath . '/config/config.php');
$databasePhpExists = file_exists($rootPath . '/config/database.php');

$checks['config_dir'] = [
    'status' => $configDirExists,
    'label' => 'Config Directory',
    'message' => $configDirExists ? 'Folder config ditemukan' : 'Folder config tidak ditemukan'
];

$checks['config_php'] = [
    'status' => $configPhpExists,
    'label' => 'Config File (config.php)',
    'message' => $configPhpExists ? 'File config.php ditemukan' : 'File config.php tidak ditemukan'
];

$checks['database_php'] = [
    'status' => $databasePhpExists,
    'label' => 'Database Config (database.php)',
    'message' => $databasePhpExists ? 'File database.php ditemukan' : 'File database.php tidak ditemukan'
];

// ============================================================================
// CHECK 3: Database Configuration & Connection
// ============================================================================

$databaseConfigured = false;
$dbConnectionTest = false;

if ($databasePhpExists) {
    $dbConfigContent = file_get_contents($rootPath . '/config/database.php');

    // Check if database credentials have been configured (not using default example values)
    if (strpos($dbConfigContent, 'your_database') === false &&
        strpos($dbConfigContent, 'your_username') === false) {
        $databaseConfigured = true;

        // Test database connection (only if authenticated)
        if ($isAuthenticated) {
            try {
                require_once $rootPath . '/config/database.php';
                $testConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

                if ($testConn->connect_error) {
                    $dbConnectionTest = false;
                    $dbError = $testConn->connect_error;
                } else {
                    $dbConnectionTest = true;
                    $testConn->close();
                }
            } catch (Exception $e) {
                $dbConnectionTest = false;
                $dbError = $e->getMessage();
            }
        }
    }
}

$checks['database_config'] = [
    'status' => $databaseConfigured,
    'label' => 'Database Configuration',
    'message' => $databaseConfigured ? 'Database sudah dikonfigurasi' : 'Database belum dikonfigurasi'
];

if ($databaseConfigured && $isAuthenticated) {
    $checks['database_connection'] = [
        'status' => $dbConnectionTest,
        'label' => 'Database Connection Test',
        'message' => $dbConnectionTest
            ? 'Koneksi database berhasil'
            : 'Koneksi database gagal: ' . ($dbError ?? 'Unknown error')
    ];
}

// ============================================================================
// CHECK 4: Database Schema File
// ============================================================================

$databaseSqlExists = file_exists($rootPath . '/database.sql');

$checks['database_sql'] = [
    'status' => $databaseSqlExists,
    'label' => 'Database Schema File',
    'message' => $databaseSqlExists ? 'File database.sql ditemukan' : 'File database.sql tidak ditemukan'
];

// ============================================================================
// CHECK 5: Database Tables Import Status
// ============================================================================

$databaseTablesImported = false;
$tableCount = 0;
$expectedTables = ['users', 'configurations', 'genieacs_credentials', 'mikrotik_credentials', 'telegram_config'];
$missingTables = [];

if ($dbConnectionTest && $isAuthenticated) {
    try {
        require_once $rootPath . '/config/database.php';
        $testConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if (!$testConn->connect_error) {
            // Count total tables
            $result = $testConn->query("SHOW TABLES");
            if ($result) {
                $tableCount = $result->num_rows;

                // Check if critical tables exist
                $allCriticalTablesExist = true;
                foreach ($expectedTables as $table) {
                    $checkTable = $testConn->query("SHOW TABLES LIKE '$table'");
                    if ($checkTable->num_rows === 0) {
                        $allCriticalTablesExist = false;
                        $missingTables[] = $table;
                    }
                }

                // Consider imported if all critical tables exist and at least 20 tables total
                $databaseTablesImported = $allCriticalTablesExist && $tableCount >= 20;
            }
            $testConn->close();
        }
    } catch (Exception $e) {
        $databaseTablesImported = false;
    }
}

// Build message for database tables check
$tableCheckMessage = 'Koneksi database diperlukan untuk pengecekan';
if ($dbConnectionTest) {
    if ($databaseTablesImported) {
        $tableCheckMessage = "Database schema sudah diimport ($tableCount tables)";
    } elseif ($tableCount > 0) {
        $tableCheckMessage = "Database tidak lengkap ($tableCount/24 tables)";
        if (!empty($missingTables)) {
            $tableCheckMessage .= ". Missing: " . implode(', ', array_slice($missingTables, 0, 3));
            if (count($missingTables) > 3) {
                $tableCheckMessage .= " (+" . (count($missingTables) - 3) . " more)";
            }
        }
    } else {
        $tableCheckMessage = 'Database masih kosong, belum diimport';
    }
}

$checks['database_tables'] = [
    'status' => $databaseTablesImported,
    'label' => 'Database Tables Imported',
    'message' => $tableCheckMessage
];

// ============================================================================
// CALCULATE OVERALL STATUS
// ============================================================================

$allChecksPass = true;
foreach ($checks as $check) {
    if (!$check['status']) {
        $allChecksPass = false;
        break;
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GACS Dashboard - Initial Setup</title>
    <!-- Google Fonts - Inter (Professional UI Font) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-color: #4F46E5;
            --primary-dark: #3730A3;
            --secondary-color: #64748B;
            --success-color: #10B981;
            --danger-color: #EF4444;
            --warning-color: #F59E0B;
            --info-color: #3B82F6;
            --dark-bg: #1E293B;
            --darker-bg: #0F172A;
            --card-bg: #ffffff;
            --text-primary: #1E293B;
            --text-secondary: #64748B;
            --border-color: #E2E8F0;
            --gray-100: #F1F5F9;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--dark-bg);
            min-height: 100vh;
            padding: 20px;
            zoom: 80%;
        }

        .container {
            max-width: 900px;
        }

        .setup-card {
            background: var(--card-bg);
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-top: 20px;
        }

        .card-header {
            background: var(--dark-bg);
            color: white;
            padding: 30px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logo-container {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .logo-img {
            width: 120px;
            height: auto;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .card-header h1 {
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .card-header p {
            opacity: 0.8;
            margin: 0;
            color: var(--gray-100);
        }

        .card-body {
            padding: 30px;
        }

        .login-form {
            max-width: 400px;
            margin: 0 auto;
        }

        .form-control {
            border-radius: 8px;
            border: 2px solid var(--border-color);
            padding: 12px 15px;
            font-size: 0.95rem;
        }

        .form-control:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }

        .btn {
            border-radius: 8px;
            padding: 12px 30px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
            border: none;
            color: white;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1D4ED8 0%, #1E40AF 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            border: none;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            border: none;
            color: white;
        }

        .btn-secondary {
            background: var(--secondary-color);
            border: none;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
            color: white;
        }

        .check-item {
            background: white;
            border-left: 4px solid var(--border-color);
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .check-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }

        .check-item.success {
            border-left-color: var(--success-color);
            background: #F0FDF4;
            border-color: #D1FAE5;
        }

        .check-item.failed {
            border-left-color: var(--danger-color);
            background: #FEF2F2;
            border-color: #FECACA;
        }

        .check-icon {
            font-size: 1.5rem;
            margin-right: 15px;
        }

        .check-icon.success {
            color: var(--success-color);
        }

        .check-icon.failed {
            color: var(--danger-color);
        }

        .command-box {
            background: var(--darker-bg);
            color: #22D3EE;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Courier New', 'Monaco', monospace;
            margin: 20px 0;
            position: relative;
            border: 1px solid rgba(34, 211, 238, 0.2);
        }

        .command-box pre {
            margin: 0;
            color: #22D3EE;
            white-space: pre;
            overflow-x: auto;
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .copy-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 5px 10px;
            font-size: 0.8rem;
        }

        .alert {
            border-radius: 8px;
            border: none;
            font-weight: 500;
        }

        .alert-info {
            background: #EFF6FF;
            color: #1E40AF;
            border-left: 4px solid var(--info-color);
        }

        .alert-info a {
            color: #1E40AF;
            text-decoration: underline;
        }

        .alert-info a:hover {
            color: #1E3A8A;
            text-decoration: underline;
        }

        .alert-warning {
            background: #FFFBEB;
            color: #92400E;
            border-left: 4px solid var(--warning-color);
        }

        .alert-success {
            background: #F0FDF4;
            color: #065F46;
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background: #FEF2F2;
            color: #991B1B;
            border-left: 4px solid var(--danger-color);
        }

        .progress {
            height: 10px;
            border-radius: 10px;
            background: var(--gray-100);
            margin-top: 20px;
            overflow: hidden;
        }

        .progress-bar {
            background: var(--primary-color);
            transition: width 0.6s ease;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-badge.success {
            background: #D1FAE5;
            color: #065F46;
        }

        .status-badge.failed {
            background: #FECACA;
            color: #991B1B;
        }

        .logout-link {
            color: white;
            opacity: 0.9;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            display: inline-block;
            padding: 8px 16px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.1);
        }

        .logout-link:hover {
            opacity: 1;
            color: white;
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="setup-card">
            <div class="card-header">
                <div class="logo-container">
                    <img src="assets/img/logo.png" alt="GACS Logo" class="logo-img">
                </div>
                <h1>GACS Dashboard Setup</h1>
                <p>Initial Configuration & System Check</p>
                <?php if ($isAuthenticated): ?>
                    <a href="?logout" class="logout-link"><i class="bi bi-box-arrow-right"></i> Logout</a>
                <?php endif; ?>
            </div>

            <div class="card-body">
                <?php if (!$isAuthenticated): ?>
                    <!-- Login Form -->
                    <div class="login-form">
                        <h4 class="text-center mb-4">Authentication Required</h4>

                        <?php if (isset($loginError)): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle"></i> <?php echo $loginError; ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                            <button type="submit" name="login" class="btn btn-primary w-100">
                                <i class="bi bi-lock-fill"></i> Login
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- System Checks -->
                    <div class="mb-4">
                        <h4><i class="bi bi-list-check"></i> System Status</h4>
                        <p class="text-muted">Checking system requirements and configuration...</p>
                    </div>

                    <!-- Root Path Info -->
                    <div class="alert alert-info">
                        <strong><i class="bi bi-folder"></i> Root Path:</strong><br>
                        <code><?php echo $rootPath; ?></code>
                    </div>

                    <!-- Progress Bar -->
                    <?php
                    $totalChecks = count($checks);
                    $passedChecks = 0;
                    foreach ($checks as $check) {
                        if ($check['status']) $passedChecks++;
                    }
                    $progressPercent = ($passedChecks / $totalChecks) * 100;
                    ?>
                    <div class="progress">
                        <div class="progress-bar" role="progressbar" style="width: <?php echo $progressPercent; ?>%"
                             aria-valuenow="<?php echo $progressPercent; ?>" aria-valuemin="0" aria-valuemax="100">
                        </div>
                    </div>
                    <p class="text-center mt-2 text-muted">
                        <strong><?php echo $passedChecks; ?> / <?php echo $totalChecks; ?></strong> checks passed
                    </p>

                    <!-- Check Items -->
                    <div class="mt-4">
                        <?php foreach ($checks as $key => $check): ?>
                            <div class="check-item <?php echo $check['status'] ? 'success' : 'failed'; ?>">
                                <div class="d-flex align-items-center">
                                    <div class="check-icon <?php echo $check['status'] ? 'success' : 'failed'; ?>">
                                        <i class="bi bi-<?php echo $check['status'] ? 'check-circle-fill' : 'x-circle-fill'; ?>"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1"><?php echo $check['label']; ?></h6>
                                        <p class="mb-0 text-muted"><?php echo $check['message']; ?></p>
                                    </div>
                                    <div>
                                        <span class="status-badge <?php echo $check['status'] ? 'success' : 'failed'; ?>">
                                            <?php echo $check['status'] ? 'OK' : 'FAILED'; ?>
                                        </span>
                                    </div>
                                </div>

                                <?php if (isset($check['action']) && $check['action'] === 'install'): ?>
                                    <div class="command-box mt-3">
                                        <button class="btn btn-sm btn-success copy-btn" onclick="copyCommand('composer-install', event)">
                                            <i class="bi bi-clipboard"></i> Copy
                                        </button>
                                        <pre id="composer-install">cd <?php echo $rootPath; ?>&#10;composer install</pre>
                                    </div>
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle"></i> Jalankan perintah di atas melalui SSH/Terminal untuk menginstall dependencies
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Installation Instructions -->
                    <?php if (!$allChecksPass): ?>
                        <div class="alert alert-warning mt-4">
                            <h5><i class="bi bi-exclamation-triangle"></i> Setup Instructions</h5>

                            <?php if (!$composerInstalled && $composerJsonExists): ?>
                                <p><strong>1. Install Composer Dependencies:</strong></p>
                                <div class="command-box">
                                    <button class="btn btn-sm btn-success copy-btn" onclick="copyCommand('cmd1', event)">
                                        <i class="bi bi-clipboard"></i> Copy
                                    </button>
                                    <pre id="cmd1">cd <?php echo $rootPath; ?>&#10;composer install</pre>
                                </div>
                            <?php endif; ?>

                            <?php if (!$databaseConfigured): ?>
                                <p><strong>2. Configure Database:</strong></p>
                                <ul>
                                    <li>Edit file: <code><?php echo $rootPath; ?>/config/database.php</code></li>
                                    <li>Update database credentials (DB_HOST, DB_NAME, DB_USER, DB_PASS)</li>
                                </ul>
                            <?php endif; ?>

                            <?php if ($databaseConfigured && !$dbConnectionTest): ?>
                                <p><strong>3. Import Database Schema:</strong></p>
                                <div class="command-box">
                                    <button class="btn btn-sm btn-success copy-btn" onclick="copyCommand('cmd2', event)">
                                        <i class="bi bi-clipboard"></i> Copy
                                    </button>
                                    <pre id="cmd2">cd <?php echo $rootPath; ?>&#10;mysql -u <?php echo defined('DB_USER') ? DB_USER : '[username]'; ?> -p<?php echo defined('DB_PASS') ? DB_PASS : '[password]'; ?> -D <?php echo defined('DB_NAME') ? DB_NAME : '[database]'; ?> < database.sql&#10;&#10;# Atau gunakan mariadb:&#10;mariadb -u <?php echo defined('DB_USER') ? DB_USER : '[username]'; ?> -p<?php echo defined('DB_PASS') ? DB_PASS : '[password]'; ?> -D <?php echo defined('DB_NAME') ? DB_NAME : '[database]'; ?> < database.sql</pre>
                                </div>
                                <div class="alert alert-danger mt-2 mb-0" style="font-size: 0.85rem;">
                                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>Perhatian:</strong> Password ditampilkan untuk kemudahan setup. <strong>Hapus file init.php setelah instalasi selesai!</strong>
                                </div>
                            <?php endif; ?>

                            <?php if ($dbConnectionTest && !$databaseTablesImported): ?>
                                <p><strong>3. Import Database Schema:</strong></p>
                                <div class="alert alert-warning mb-3">
                                    <i class="bi bi-exclamation-circle"></i> <strong>Database kosong atau tidak lengkap!</strong><br>
                                    Koneksi database berhasil, namun tables belum diimport atau tidak lengkap.<br>
                                    Tables ditemukan: <strong><?php echo $tableCount; ?> / 24</strong>
                                </div>
                                <div class="command-box">
                                    <button class="btn btn-sm btn-success copy-btn" onclick="copyCommand('cmd3', event)">
                                        <i class="bi bi-clipboard"></i> Copy
                                    </button>
                                    <pre id="cmd3">cd <?php echo $rootPath; ?>&#10;mysql -u <?php echo defined('DB_USER') ? DB_USER : '[username]'; ?> -p<?php echo defined('DB_PASS') ? DB_PASS : '[password]'; ?> -D <?php echo defined('DB_NAME') ? DB_NAME : '[database]'; ?> < database.sql&#10;&#10;# Atau gunakan mariadb:&#10;mariadb -u <?php echo defined('DB_USER') ? DB_USER : '[username]'; ?> -p<?php echo defined('DB_PASS') ? DB_PASS : '[password]'; ?> -D <?php echo defined('DB_NAME') ? DB_NAME : '[database]'; ?> < database.sql</pre>
                                </div>
                                <div class="alert alert-danger mt-2 mb-0" style="font-size: 0.85rem;">
                                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>Perhatian:</strong> Password ditampilkan untuk kemudahan setup. <strong>Hapus file init.php setelah instalasi selesai!</strong>
                                </div>
                            <?php endif; ?>

                            <p class="mt-3"><strong>4. Refresh halaman ini setelah menyelesaikan langkah-langkah di atas.</strong></p>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success mt-4">
                            <h5><i class="bi bi-check-circle-fill"></i> Setup Complete!</h5>
                            <p class="mb-3">Semua pengecekan berhasil. Sistem siap digunakan!</p>
                            <a href="index.php" class="btn btn-success">
                                <i class="bi bi-box-arrow-in-right"></i> Go to Dashboard
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- GenieACS Installation Info -->
                    <div class="alert alert-info mt-4">
                        <h5><i class="bi bi-hdd-network"></i> Butuh GenieACS?</h5>
                        <p class="mb-2">GACS Dashboard membutuhkan GenieACS (TR-069 ACS) untuk mengelola perangkat ONU.</p>
                        <p class="mb-0">
                            <i class="bi bi-github"></i>
                            <strong>Panduan instalasi lengkap GenieACS di Ubuntu 22.04:</strong><br>
                            <a href="https://github.com/safrinnetwork/GACS-Ubuntu-22.04" target="_blank" class="text-decoration-none" style="font-weight: 600;">
                                https://github.com/safrinnetwork/GACS-Ubuntu-22.04
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </p>
                    </div>

                    <!-- Quick Links -->
                    <div class="mt-4 text-center">
                        <small class="text-muted">
                            <a href="?refresh=1" class="text-decoration-none">
                                <i class="bi bi-arrow-clockwise"></i> Refresh Status
                            </a>
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center mt-4 text-white">
            <small>Made by <a href="https://github.com/safrinnetwork/" target="_blank" class="text-white text-decoration-none" style="font-weight: 600; border-bottom: 1px dotted white;">Mostech</a></small>
        </div>
    </div>

    <script>
        function copyCommand(elementId, event) {
            const element = document.getElementById(elementId);
            const text = element.textContent;

            navigator.clipboard.writeText(text).then(() => {
                // Show toast notification
                const btn = event.target.closest('button');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');

                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-success');
                }, 2000);
            }).catch(err => {
                alert('Gagal menyalin: ' + err);
            });
        }

        // Auto refresh if refresh parameter is set
        <?php if (isset($_GET['refresh'])): ?>
            setTimeout(() => {
                window.location.href = 'init.php';
            }, 100);
        <?php endif; ?>
    </script>
</body>
</html>
