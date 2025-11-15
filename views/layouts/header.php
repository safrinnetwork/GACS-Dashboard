<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? APP_NAME; ?></title>
    <!-- Google Fonts - Inter (Professional UI Font) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- CSS Libraries -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo time(); ?>">
    <!-- Client-Side Logger -->
    <script src="/assets/js/client-logger.js?v=<?php echo time(); ?>"></script>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="bi bi-hdd-network" style="font-size: 2.5rem; margin-bottom: 0.5rem;"></i>
            <h3 style="font-size: 1rem; line-height: 1.3;"><?php echo APP_NAME; ?></h3>
        </div>

        <ul class="sidebar-menu">
            <li>
                <a href="/dashboard.php" class="<?php echo ($currentPage ?? '') === 'dashboard' ? 'active' : ''; ?>" data-tooltip="Dashboard">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="/devices.php" class="<?php echo ($currentPage ?? '') === 'devices' ? 'active' : ''; ?>" data-tooltip="Devices">
                    <i class="bi bi-router"></i>
                    <span>Devices</span>
                </a>
            </li>
            <li>
                <a href="/map.php" class="<?php echo ($currentPage ?? '') === 'map' ? 'active' : ''; ?>" data-tooltip="Map">
                    <i class="bi bi-diagram-3"></i>
                    <span>Map</span>
                </a>
            </li>
            <li>
                <a href="/configuration.php" class="<?php echo ($currentPage ?? '') === 'configuration' ? 'active' : ''; ?>" data-tooltip="Configuration">
                    <i class="bi bi-gear"></i>
                    <span>Configuration</span>
                </a>
            </li>
            <li>
                <a href="/logout.php" data-tooltip="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>

        <!-- Toggle Button -->
        <button class="sidebar-toggle" id="sidebarToggle" type="button">
            <i class="bi bi-chevron-left"></i>
        </button>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <!-- Topbar -->
        <div class="topbar">
            <h4><?php echo $pageTitle ?? APP_NAME; ?></h4>
            <div class="user-info">
                <span><i class="bi bi-person-circle"></i> <?php echo $_SESSION['username'] ?? 'User'; ?></span>
            </div>
        </div>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
