<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Network Map';
$currentPage = 'map';

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
    <div class="row mb-3" id="map-container-fullscreen">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <button class="btn btn-primary" onclick="showAddItemModal()">
                        <i class="bi bi-plus-lg"></i> Add
                    </button>
                    <button class="btn btn-warning" id="edit-line-mode-toggle" onclick="toggleEditLineMode()">
                        <span id="edit-line-mode-text"><i class="bi bi-pencil"></i> Edit Garis</span>
                    </button>
                    <button class="btn btn-secondary" id="fullscreen-toggle" onclick="toggleFullscreen()" title="Toggle Fullscreen">
                        <i class="bi bi-arrows-fullscreen" id="fullscreen-icon"></i>
                    </button>
                    <button class="btn btn-warning text-dark">
                        <i class="bi bi-zoom-in"></i> Zoom <strong id="zoom-level-indicator">13</strong>
                    </button>
                    <div class="d-flex gap-2 float-end">
                        <!-- Server Indicator (clickable) -->
                        <div class="badge bg-primary d-flex align-items-center gap-1 px-3 py-2" style="font-size: 0.875rem; cursor: pointer;" onclick="showServerListModal()" title="Click to view servers">
                            <i class="bi bi-server"></i>
                            <span>Server <strong id="server-count">0</strong></span>
                        </div>

                        <!-- Layer Toggle Buttons with List Modal -->
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-secondary" onclick="showItemListModal('olt')" title="Click to view OLT list">
                                <i class="bi bi-broadcast-pin"></i> OLT <span class="badge bg-secondary" id="olt-count">0</span>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="showItemListModal('odc')" title="Click to view ODC list">
                                <i class="bi bi-box"></i> ODC <span class="badge bg-secondary" id="odc-count">0</span>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="showItemListModal('odp')" title="Click to view ODP list">
                                <i class="bi bi-cube"></i> ODP <span class="badge bg-secondary" id="odp-count">0</span>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" onclick="showItemListModal('onu')" title="Click to view ONU list">
                                <i class="bi bi-wifi"></i> ONU <span class="badge bg-secondary" id="onu-count">0</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card" id="map-card">
                <div class="card-body p-0">
                    <div id="map"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Context Menu untuk Polylines -->
    <div id="polyline-context-menu" class="context-menu" style="display: none; position: absolute; z-index: 9999; background: white; border: 1px solid #ccc; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.2); min-width: 150px;">
        <div class="context-menu-item" onclick="enablePolylineEdit()">
            <i class="bi bi-pencil"></i> Edit Jalur
        </div>
        <div class="context-menu-item" onclick="savePolylineWaypoints()">
            <i class="bi bi-save"></i> Simpan Waypoints
        </div>
        <div class="context-menu-item" onclick="resetPolylineToStraight()">
            <i class="bi bi-arrow-counterclockwise"></i> Reset ke Garis Lurus
        </div>
        <div class="context-menu-item" onclick="closeContextMenu()">
            <i class="bi bi-x-lg"></i> Tutup
        </div>
    </div>
<?php endif; ?>
<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle"></i> Add Network Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="form-add-item">
                    <div class="form-group">
                        <label>Item Type</label>
                        <select name="item_type" class="form-control" required onchange="updateItemForm(this.value)">
                            <option value="">Select Type</option>
                            <option value="server">Server</option>
                            <option value="olt">OLT</option>
                            <option value="odc">ODC</option>
                            <option value="odp">ODP</option>
                            <option value="onu">ONU</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Latitude</label>
                        <input type="number" step="0.00000001" name="latitude" class="form-control" required readonly>
                    </div>
                    <div class="form-group">
                        <label>Longitude</label>
                        <input type="number" step="0.00000001" name="longitude" class="form-control" required readonly>
                    </div>
                    <div id="dynamic-fields"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="addItem()">Add Item</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Item Modal -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square"></i> Edit Network Item
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="form-edit-item">
                    <input type="hidden" name="item_id">
                    <div class="form-group">
                        <label>Item Type</label>
                        <input type="text" name="item_type" class="form-control" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                        <small class="text-muted">Item type tidak bisa diubah</small>
                    </div>
                    <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Latitude</label>
                        <input type="number" step="0.00000001" name="latitude" class="form-control" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                        <small class="text-muted">Koordinat tidak bisa diubah - hapus dan buat ulang jika perlu memindahkan lokasi</small>
                    </div>
                    <div class="form-group">
                        <label>Longitude</label>
                        <input type="number" step="0.00000001" name="longitude" class="form-control" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                        <small class="text-muted">Koordinat tidak bisa diubah - hapus dan buat ulang jika perlu memindahkan lokasi</small>
                    </div>
                    <div id="edit-dynamic-fields"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="updateItem()">Update Item</button>
            </div>
        </div>
    </div>
</div>

<!-- Server Links Modal -->
<div class="modal fade" id="serverLinksModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-diagram-3"></i> Manage Server Links
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="form-server-links">
                    <input type="hidden" name="item_id">
                    <div class="form-group">
                        <label>🌐 ISP</label>
                        <select name="isp_link" class="form-control" id="isp-link-select">
                            <option value="">No Link</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>🔧 MikroTik Device</label>
                        <select name="mikrotik_device_id" class="form-control" id="mikrotik-device-select">
                            <option value="">No Device</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>📡 OLT</label>
                        <select name="olt_link" class="form-control" id="olt-link-select">
                            <option value="">No Link</option>
                        </select>
                    </div>
                    <div id="pon-output-power-container">
                        <!-- PON output power fields will be dynamically generated here -->
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveServerLinks()">Save Links</button>
            </div>
        </div>
    </div>
</div>

<!-- Server List Modal -->
<div class="modal fade" id="serverListModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-server"></i> Server List
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="server-list-container">
                    <!-- Server list will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- OLT List Modal -->
<div class="modal fade" id="oltListModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-broadcast-pin"></i> OLT List
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="olt-list-container">
                    <!-- OLT list will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ODC List Modal -->
<div class="modal fade" id="odcListModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-box"></i> ODC List
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="odc-list-container">
                    <!-- ODC list will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ODP List Modal -->
<div class="modal fade" id="odpListModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-cube"></i> ODP List
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="odp-list-container">
                    <!-- ODP list will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ONU List Modal -->
<div class="modal fade" id="onuListModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-wifi"></i> ONU List
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="onu-list-container">
                    <!-- ONU list will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Map Styles -->
<link rel="stylesheet" href="/assets/css/map.css">

<?php include __DIR__ . '/views/layouts/footer.php'; ?>

<!-- Load Leaflet.Editable after Leaflet.js is loaded (from footer) -->
<script src="/assets/js/Leaflet.Editable.js"></script>

<!-- Load Map JavaScript Modules in correct order -->
<?php $jsVersion = time(); // Cache busting ?>
<script src="/assets/js/map/map-utils.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-core.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-markers.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-polylines.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-items.js?v=<?php echo $jsVersion; ?>"></script>
<script src="/assets/js/map/map-server.js?v=<?php echo $jsVersion; ?>"></script>

<!-- Initialize map when page loads -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    initMap();
    startAutoRefresh();

    // Check for focus parameters in URL
    const urlParams = new URLSearchParams(window.location.search);
    const focusType = urlParams.get('focus_type');
    const focusId = urlParams.get('focus_id');
    const focusSerial = urlParams.get('focus_serial');

    // Wait for all map items to load before focusing
    if ((focusType && focusId) || (focusType === 'onu' && focusSerial)) {
        // Function to check if map is ready
        const waitForMapReady = () => {
            // Check if allMapItems is populated and has items
            if (typeof allMapItems !== 'undefined' && allMapItems.length > 0) {
                console.log('✓ Map items loaded, ready to focus');

                // Give extra time for markers to render
                setTimeout(() => {
                    let focused = false;

                    if (focusType === 'onu' && focusSerial) {
                        // Focus by serial number for ONU
                        focused = focusOnONUBySerial(decodeURIComponent(focusSerial), 17);
                    } else if (focusType && focusId) {
                        // Focus by type and ID for other items
                        focused = focusOnMapItem(focusType, parseInt(focusId), 17);
                    }

                    if (focused) {
                        console.log('✓ Successfully focused on item');
                    } else {
                        console.log('✗ Failed to focus - item not found');
                        showToast('Device tidak ditemukan di map', 'warning');
                    }
                }, 500); // Wait 500ms for markers to fully render
            } else {
                // Map not ready yet, try again
                console.log('⏳ Waiting for map items to load...');
                setTimeout(waitForMapReady, 200); // Check again in 200ms
            }
        };

        // Start waiting for map to be ready
        waitForMapReady();
    }
});

// Toggle fullscreen mode
function toggleFullscreen() {
    const mapContainer = document.getElementById('map-container-fullscreen');
    const fullscreenBtn = document.getElementById('fullscreen-toggle');
    const fullscreenIcon = document.getElementById('fullscreen-icon');

    if (!document.fullscreenElement) {
        // Enter fullscreen
        if (mapContainer.requestFullscreen) {
            mapContainer.requestFullscreen();
        } else if (mapContainer.webkitRequestFullscreen) {
            mapContainer.webkitRequestFullscreen(); // Safari
        } else if (mapContainer.msRequestFullscreen) {
            mapContainer.msRequestFullscreen(); // IE11
        }

        // Update button icon
        fullscreenBtn.classList.remove('btn-secondary');
        fullscreenBtn.classList.add('btn-danger');
        fullscreenIcon.classList.remove('bi-arrows-fullscreen');
        fullscreenIcon.classList.add('bi-fullscreen-exit');

        // Force modals to appear above fullscreen
        fixModalsZIndex();

        showToast('Mode Full Screen Aktif! Tekan ESC atau klik tombol untuk keluar.', 'info', 5000);
    } else {
        // Exit fullscreen
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen(); // Safari
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen(); // IE11
        }

        // Update button icon
        fullscreenBtn.classList.remove('btn-danger');
        fullscreenBtn.classList.add('btn-secondary');
        fullscreenIcon.classList.remove('bi-fullscreen-exit');
        fullscreenIcon.classList.add('bi-arrows-fullscreen');

        // Restore modals back to body
        restoreModalsToBody();

        showToast('Mode Full Screen Dinonaktifkan', 'info', 3000);
    }

    // Invalidate map size after fullscreen change (Leaflet needs this)
    setTimeout(() => {
        if (map) {
            map.invalidateSize();
        }
    }, 100);
}

// Fix modal visibility for fullscreen mode by moving them into fullscreen container
function fixModalsZIndex() {
    console.log('🔧 Moving modals into fullscreen container...');

    const fullscreenContainer = document.getElementById('map-container-fullscreen');

    // Move all modals into fullscreen container
    const allModals = document.querySelectorAll('.modal');
    allModals.forEach(modal => {
        fullscreenContainer.appendChild(modal);
        console.log('  ✓ Moved modal into fullscreen container:', modal.id);
    });

    // Watch for modal backdrops being added and move them too
    const bodyObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1 && node.classList && node.classList.contains('modal-backdrop')) {
                    fullscreenContainer.appendChild(node);
                    console.log('  ✓ Moved backdrop into fullscreen container');
                }
            });
        });
    });

    // Observe body for new backdrops
    bodyObserver.observe(document.body, {
        childList: true,
        subtree: false
    });

    console.log('✅ Modals moved - they should now be visible in fullscreen');
}

// Restore modals back to body when exiting fullscreen
function restoreModalsToBody() {
    console.log('🔄 Restoring modals back to body...');

    const allModals = document.querySelectorAll('#map-container-fullscreen .modal');
    allModals.forEach(modal => {
        document.body.appendChild(modal);
        console.log('  ✓ Restored modal to body:', modal.id);
    });

    const allBackdrops = document.querySelectorAll('#map-container-fullscreen .modal-backdrop');
    allBackdrops.forEach(backdrop => {
        document.body.appendChild(backdrop);
        console.log('  ✓ Restored backdrop to body');
    });
}

// Listen for fullscreen change events (when user presses ESC)
document.addEventListener('fullscreenchange', function() {
    if (!document.fullscreenElement) {
        const fullscreenBtn = document.getElementById('fullscreen-toggle');
        const fullscreenIcon = document.getElementById('fullscreen-icon');

        // User exited fullscreen with ESC - update button
        fullscreenBtn.classList.remove('btn-danger');
        fullscreenBtn.classList.add('btn-secondary');
        fullscreenIcon.classList.remove('bi-fullscreen-exit');
        fullscreenIcon.classList.add('bi-arrows-fullscreen');

        // Restore modals back to body
        restoreModalsToBody();

        // Invalidate map size
        setTimeout(() => {
            if (map) {
                map.invalidateSize();
            }
        }, 100);
    }
});

// Also listen for webkit/moz fullscreen changes
document.addEventListener('webkitfullscreenchange', function() {
    document.dispatchEvent(new Event('fullscreenchange'));
});
document.addEventListener('mozfullscreenchange', function() {
    document.dispatchEvent(new Event('fullscreenchange'));
});
</script>
