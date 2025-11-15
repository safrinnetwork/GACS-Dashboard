<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Device Detail';
$currentPage = 'devices';

$genieacsConfigured = isGenieACSConfigured();

// Get device ID from URL
$deviceId = isset($_GET['id']) ? $_GET['id'] : null;

if (!$deviceId) {
    header('Location: /devices.php');
    exit;
}

include __DIR__ . '/views/layouts/header.php';
?>

<?php if (!$genieacsConfigured): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        GenieACS belum dikonfigurasi. Silakan konfigurasi terlebih dahulu di
        <a href="/configuration.php">halaman Configuration</a>.
    </div>
<?php else: ?>
    <!-- Back Button -->
    <div class="mb-3">
        <a href="/devices.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Back to Devices
        </a>
        <button class="btn btn-primary" onclick="summonDevice()">
            <i class="bi bi-lightning-charge"></i> Summon Device
        </button>
        <button class="btn btn-success" onclick="showAddTagModal()">
            <i class="bi bi-tag"></i> Add Tag
        </button>
        <button class="btn btn-warning" onclick="showRemoveTagModal()">
            <i class="bi bi-tag-fill"></i> Remove Tag
        </button>
        <button class="btn btn-info" onclick="loadDeviceDetail()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>

    <!-- Device Detail Card with Tabs -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-router"></i> Device Details
            <span id="device-id-badge" class="badge bg-secondary ms-2">Loading...</span>
            <span id="device-tags-badge"></span>
        </div>
        <div class="card-body">
            <!-- Loading Spinner (shown initially) -->
            <div id="loading-spinner" class="text-center">
                <div class="spinner"></div>
            </div>

            <!-- Tab Navigation (hidden initially) -->
            <ul class="nav nav-tabs" id="deviceTabs" role="tablist" style="display:none;">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">
                        <i class="bi bi-info-circle"></i> Overview
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="topology-tab" data-bs-toggle="tab" data-bs-target="#topology" type="button" role="tab">
                        <i class="bi bi-diagram-3"></i> Topology Location
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="wan-tab" data-bs-toggle="tab" data-bs-target="#wan" type="button" role="tab">
                        <i class="bi bi-globe"></i> WAN Connections <span id="wan-count-badge" class="badge bg-primary ms-1">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="dhcp-tab" data-bs-toggle="tab" data-bs-target="#dhcp" type="button" role="tab">
                        <i class="bi bi-router"></i> DHCP Server
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="devices-tab" data-bs-toggle="tab" data-bs-target="#devices" type="button" role="tab">
                        <i class="bi bi-hdd-network"></i> Connected Devices <span id="devices-count-badge" class="badge bg-primary ms-1">0</span>
                    </button>
                </li>
            </ul>

            <!-- Tab Content (hidden initially) -->
            <div class="tab-content mt-3" id="deviceTabContent" style="display:none;">
                <!-- Overview Tab -->
                <div class="tab-pane fade show active" id="overview" role="tabpanel">
                    <div id="overview-content"></div>
                </div>

                <!-- Topology Location Tab -->
                <div class="tab-pane fade" id="topology" role="tabpanel">
                    <div id="topology-content"></div>
                </div>

                <!-- WAN Connections Tab -->
                <div class="tab-pane fade" id="wan" role="tabpanel">
                    <div id="wan-content"></div>
                </div>

                <!-- DHCP Server Tab -->
                <div class="tab-pane fade" id="dhcp" role="tabpanel">
                    <div id="dhcp-content"></div>
                </div>

                <!-- Connected Devices Tab -->
                <div class="tab-pane fade" id="devices" role="tabpanel">
                    <div id="devices-content"></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>


<?php include __DIR__ . '/views/device-detail/modals.php'; ?>

<script>
// Global configuration for device-detail.js
window.DEVICE_ID = '<?php echo htmlspecialchars($deviceId, ENT_QUOTES, 'UTF-8'); ?>';
</script>
<script src="/assets/js/device-detail.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
