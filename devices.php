<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Devices';
$currentPage = 'devices';

$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>

<?php if (!$genieacsConfigured): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        GenieACS belum dikonfigurasi. Silakan konfigurasi terlebih dahulu di
        <a href="/configuration.php">halaman Configuration</a>.
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-router"></i> Device List
                    <span id="device-stats-badges" style="margin-left: 10px;">
                        <span class="badge bg-secondary">Total [0]</span>
                        <span class="badge bg-success">Online [0]</span>
                        <span class="badge bg-danger">Offline [0]</span>
                    </span>
                </div>
                <div>
                    <!-- Bulk Action Buttons (hidden by default, shown when devices selected) -->
                    <div id="bulk-action-buttons" style="display: none; margin-right: 10px;">
                        <button class="btn btn-sm btn-success" onclick="showBulkAddTagModal()" title="Add Tag to Selected Devices">
                            <i class="bi bi-tag-fill"></i> Add Tag
                        </button>
                        <button class="btn btn-sm btn-warning" onclick="showBulkUntagModal()" title="Remove Tag from Selected Devices" style="margin-left: 5px;">
                            <i class="bi bi-tags"></i> Untag
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="showBulkDeleteModal()" title="Delete Selected Devices" style="margin-left: 5px;">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                        <span id="selected-count" class="badge bg-primary" style="margin-left: 10px;">0 selected</span>
                    </div>

                    <button class="btn btn-sm btn-secondary" id="toggle-tags-btn" onclick="toggleTagsColumn()" style="margin-right: 10px;">
                        <i class="bi bi-tags"></i> Show Tags
                    </button>
                    <button class="btn btn-sm btn-primary" onclick="loadDevices()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <!-- Tab Navigation -->
            <ul class="nav nav-tabs mb-3" id="deviceTypeTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="onu-tab" data-bs-toggle="tab" data-bs-target="#onu" type="button" role="tab" onclick="filterByType('onu')">
                        <i class="bi bi-wifi"></i> ONU <span class="badge bg-primary ms-1" id="count-onu">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="odp-tab" data-bs-toggle="tab" data-bs-target="#odp" type="button" role="tab" onclick="filterByType('odp')">
                        <i class="bi bi-cube"></i> ODP <span class="badge bg-primary ms-1" id="count-odp">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="odc-tab" data-bs-toggle="tab" data-bs-target="#odc" type="button" role="tab" onclick="filterByType('odc')">
                        <i class="bi bi-box"></i> ODC <span class="badge bg-primary ms-1" id="count-odc">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="olt-tab" data-bs-toggle="tab" data-bs-target="#olt" type="button" role="tab" onclick="filterByType('olt')">
                        <i class="bi bi-broadcast-pin"></i> OLT <span class="badge bg-primary ms-1" id="count-olt">0</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="server-tab" data-bs-toggle="tab" data-bs-target="#server" type="button" role="tab" onclick="filterByType('server')">
                        <i class="bi bi-server"></i> Server <span class="badge bg-primary ms-1" id="count-server">0</span>
                    </button>
                </li>
            </ul>
            <!-- Search Box and Pagination Controls -->
            <div class="row mb-3">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" class="form-control" id="search-input" placeholder="Search by Serial Number, MAC Address, or Tags..." onkeyup="filterDevices()">
                        <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">
                            <i class="bi bi-x-lg"></i> Clear
                        </button>
                    </div>
                </div>
                <div class="col-md-4 text-center">
                    <div class="d-flex align-items-center justify-content-center">
                        <label class="me-2 mb-0" style="white-space: nowrap;">Show:</label>
                        <select class="form-select form-select-sm" id="items-per-page" onchange="changeItemsPerPage()" style="width: auto;">
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="0">All</option>
                        </select>
                        <span class="ms-2 text-muted" style="white-space: nowrap;">per page</span>
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <span class="text-muted" id="device-count">Loading...</span>
                </div>
            </div>

            <div class="table-responsive" style="overflow-x: auto;">
                <table class="table table-hover" id="devices-table" style="white-space: nowrap;">
                    <thead id="table-header">
                        <!-- Table header will be dynamically generated based on active tab -->
                    </thead>
                    <tbody id="devices-tbody">
                        <tr>
                            <td colspan="12" class="text-center">
                                <div class="spinner"></div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Navigation -->
            <div class="row mt-3" id="pagination-container" style="display: none;">
                <div class="col-12">
                    <nav aria-label="Device pagination">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item" id="pagination-first">
                                <button class="page-link" onclick="goToPage(1)">
                                    <i class="bi bi-chevron-double-left"></i> First
                                </button>
                            </li>
                            <li class="page-item" id="pagination-prev">
                                <button class="page-link" onclick="goToPage(currentPage - 1)">
                                    <i class="bi bi-chevron-left"></i> Prev
                                </button>
                            </li>
                            <li class="page-item active">
                                <span class="page-link" id="pagination-info">Page 1 of 1</span>
                            </li>
                            <li class="page-item" id="pagination-next">
                                <button class="page-link" onclick="goToPage(currentPage + 1)">
                                    Next <i class="bi bi-chevron-right"></i>
                                </button>
                            </li>
                            <li class="page-item" id="pagination-last">
                                <button class="page-link" onclick="goToPage(Math.ceil(totalDevices / itemsPerPage))">
                                    Last <i class="bi bi-chevron-double-right"></i>
                                </button>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/views/devices/modals.php'; ?>

<?php endif; ?>

<script>
// Global configuration
window.GENIEACS_CONFIGURED = <?php echo $genieacsConfigured ? 'true' : 'false'; ?>;
</script>
<script src="/assets/js/devices/devices-state.js"></script>
<script src="/assets/js/devices.js"></script>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
