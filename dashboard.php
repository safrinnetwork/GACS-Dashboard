<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

// Check if GenieACS is configured
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
    <!-- Stats Cards -->
    <div class="stats-grid" id="stats-container">
        <a href="/devices.php" class="stat-card primary" style="text-decoration: none; color: inherit; cursor: pointer;">
            <div class="stat-info">
                <h3 id="stat-total">-</h3>
                <p>Total Devices</p>
            </div>
            <div class="stat-icon">
                <i class="bi bi-router"></i>
            </div>
        </a>

        <a href="/devices.php" class="stat-card success" style="text-decoration: none; color: inherit; cursor: pointer;">
            <div class="stat-info">
                <h3 id="stat-online">-</h3>
                <p>Online</p>
            </div>
            <div class="stat-icon">
                <i class="bi bi-check-circle"></i>
            </div>
        </a>

        <a href="/devices.php" class="stat-card danger" style="text-decoration: none; color: inherit; cursor: pointer;">
            <div class="stat-info">
                <h3 id="stat-offline">-</h3>
                <p>Offline</p>
            </div>
            <div class="stat-icon">
                <i class="bi bi-x-circle"></i>
            </div>
        </a>

        <div class="stat-card warning">
            <div class="stat-info">
                <h3 id="stat-uptime">-</h3>
                <p>Avg Uptime</p>
            </div>
            <div class="stat-icon">
                <i class="bi bi-clock-history"></i>
            </div>
        </div>
    </div>

    <!-- Device Overview & Uplink -->
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-bar-chart"></i> Device Overview
                    <button class="btn btn-sm btn-primary float-end" onclick="loadDashboardData()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div style="max-width: 300px; margin: 0 auto;">
                        <canvas id="deviceChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-reception-4"></i> Uplink Signal Strength
                    <button class="btn btn-sm btn-primary float-end" onclick="loadUplinkData()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div style="max-width: 300px; margin: 0 auto;">
                        <canvas id="uplinkChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Devices -->
    <div class="row mt-4">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-activity"></i> Recent Device Activity
                </div>
                <div class="card-body">
                    <div id="recent-devices">
                        <div class="spinner"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Summon Confirmation Modal -->
<div class="modal fade" id="summonModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-lightning-charge"></i> Konfirmasi Summon Device
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--warning-color);"></i>
                <h5 class="mt-3">Summon Device?</h5>
                <p class="text-muted mb-0">Apakah Anda yakin ingin melakukan connection request ke device ini?</p>
                <p class="text-muted mb-0"><small>Device ID: <strong id="summon-device-id"></strong></small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Batal
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmSummon()">
                    <i class="bi bi-lightning-charge"></i> Ya, Summon
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Not In Map Alert Modal -->
<div class="modal fade" id="notInMapModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-circle"></i> ONU Belum Terdaftar
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="bi bi-map" style="font-size: 3rem; color: var(--secondary-color);"></i>
                <h5 class="mt-3">ONU Belum Terdaftar di Map</h5>
                <p class="text-muted mb-2">Device dengan Serial Number <strong id="not-in-map-serial"></strong> belum terdaftar di Network Map.</p>
                <p class="text-muted mb-0"><small>Silakan tambahkan ONU ini ke map terlebih dahulu untuk melihat lokasi topologi.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Tutup
                </button>
                <button type="button" class="btn btn-primary" onclick="window.open('/map.php', '_blank')">
                    <i class="bi bi-map"></i> Buka Network Map
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
let deviceChart = null;
let uplinkChart = null;
let dashboardFetchInProgress = false;
let uplinkFetchInProgress = false;
let recentDevicesFetchInProgress = false;

async function loadDashboardData() {
    // Prevent concurrent requests
    if (dashboardFetchInProgress) {
        console.debug('[DASHBOARD] Stats fetch already in progress, skipping...');
        return;
    }

    dashboardFetchInProgress = true;
    try {
        const result = await fetchAPI('/api/dashboard-stats.php', { timeout: 25000 });

        if (result && result.success) {
            const stats = result.stats;

            document.getElementById('stat-total').textContent = stats.total;
            document.getElementById('stat-online').textContent = stats.online;
            document.getElementById('stat-offline').textContent = stats.offline;

            // Calculate percentage
            const onlinePercentage = stats.total > 0 ? Math.round((stats.online / stats.total) * 100) : 0;
            document.getElementById('stat-uptime').textContent = onlinePercentage + '%';

            // Update chart
            updateChart(stats);
        } else {
            if (result && result.error !== 'timeout') {
                showToast('Gagal memuat data dashboard', 'danger');
            }
        }
    } catch (error) {
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('Error loading dashboard:', error);
        }
    } finally {
        dashboardFetchInProgress = false;
    }
}

function updateChart(stats) {
    const ctx = document.getElementById('deviceChart').getContext('2d');

    if (deviceChart) {
        deviceChart.destroy();
    }

    deviceChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Online', 'Offline'],
            datasets: [{
                data: [stats.online, stats.offline],
                backgroundColor: [
                    'rgba(28, 200, 138, 0.8)',
                    'rgba(231, 74, 59, 0.8)'
                ],
                borderColor: [
                    'rgba(28, 200, 138, 1)',
                    'rgba(231, 74, 59, 1)'
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                title: {
                    display: true,
                    text: 'Device Status Distribution'
                }
            }
        }
    });
}

async function loadUplinkData() {
    // Prevent concurrent requests
    if (uplinkFetchInProgress) {
        console.debug('[DASHBOARD] Uplink fetch already in progress, skipping...');
        return;
    }

    uplinkFetchInProgress = true;
    try {
        const result = await fetchAPI('/api/uplink-stats.php', { timeout: 25000 });

        if (result && result.success) {
            updateUplinkChart(result.data);
        } else {
            if (result && result.error !== 'timeout') {
                showToast('Gagal memuat data uplink', 'danger');
            }
        }
    } catch (error) {
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('Error loading uplink:', error);
        }
    } finally {
        uplinkFetchInProgress = false;
    }
}

function updateUplinkChart(data) {
    const ctx = document.getElementById('uplinkChart').getContext('2d');

    if (uplinkChart) {
        uplinkChart.destroy();
    }

    uplinkChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Excellent', 'Good', 'Fair', 'Poor', 'No Signal'],
            datasets: [{
                data: [data.excellent, data.good, data.fair, data.poor, data.no_signal],
                backgroundColor: [
                    'rgba(28, 200, 138, 0.8)',  // Excellent - green
                    'rgba(52, 152, 219, 0.8)',  // Good - blue
                    'rgba(241, 196, 15, 0.8)',  // Fair - yellow
                    'rgba(231, 76, 60, 0.8)',   // Poor - red
                    'rgba(149, 165, 166, 0.8)'  // No signal - gray
                ],
                borderColor: [
                    'rgba(28, 200, 138, 1)',
                    'rgba(52, 152, 219, 1)',
                    'rgba(241, 196, 15, 1)',
                    'rgba(231, 76, 60, 1)',
                    'rgba(149, 165, 166, 1)'
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 10,
                        padding: 5,
                        font: {
                            size: 9
                        }
                    }
                },
                title: {
                    display: true,
                    text: 'PON Signal Distribution'
                }
            }
        }
    });
}

function extractIP(ipString) {
    if (!ipString || ipString === 'N/A') return 'N/A';
    const match = ipString.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/);
    return match ? match[1] : 'N/A';
}

async function loadRecentDevices() {
    // Prevent concurrent requests
    if (recentDevicesFetchInProgress) {
        console.debug('[DASHBOARD] Recent devices fetch already in progress, skipping...');
        return;
    }

    const container = document.getElementById('recent-devices');
    container.innerHTML = '<div class="spinner"></div>';

    recentDevicesFetchInProgress = true;
    try {
        const result = await fetchAPI('/api/recent-devices.php', { timeout: 25000 });

        if (result && result.success) {
            const devices = result.devices;

            if (devices.length === 0) {
                container.innerHTML = '<p class="text-center text-muted">No recent device activity</p>';
                return;
            }

            // Fetch map status for all devices in parallel
            const mapStatusPromises = devices.map(device =>
                fetchAPI('/api/get-onu-location.php?serial_number=' + encodeURIComponent(device.serial_number))
                    .then(result => ({
                        serial: device.serial_number,
                        inMap: result && result.success && result.location && result.location.found,
                        itemType: result?.location?.item_type || 'onu',
                        itemId: result?.location?.onu?.id || result?.location?.server?.id || null
                    }))
                    .catch(() => ({ serial: device.serial_number, inMap: false, itemType: 'onu', itemId: null }))
            );

            const mapStatuses = await Promise.all(mapStatusPromises);
            const mapStatusMap = {};
            mapStatuses.forEach(status => {
                mapStatusMap[status.serial] = {
                    inMap: status.inMap,
                    itemType: status.itemType,
                    itemId: status.itemId
                };
            });

            let html = '<div class="table-responsive"><table class="table table-hover"><thead><tr>';
            html += '<th>SN</th>';
            html += '<th>MAC</th>';
            html += '<th>Tipe</th>';
            html += '<th>IP</th>';
            html += '<th>SSID</th>';
            html += '<th>PPPoE</th>';
            html += '<th>Rx</th>';
            html += '<th>Temp</th>';
            html += '<th>Client</th>';
            html += '<th>Status</th>';
            html += '<th>Action</th>';
            html += '</tr></thead><tbody>';

            devices.forEach(device => {
                const mapInfo = mapStatusMap[device.serial_number] || { inMap: false, itemType: 'onu', itemId: null };
                const isInMap = mapInfo.inMap;
                const ipAddress = extractIP(device.ip_tr069);

                // Create clickable IP link if IP is valid
                let ipDisplay;
                if (ipAddress !== 'N/A' && ipAddress !== '') {
                    ipDisplay = `<a href="http://${ipAddress}" target="_blank" rel="noopener noreferrer" title="Open ${ipAddress} in new tab">${ipAddress}</a>`;
                } else {
                    ipDisplay = ipAddress;
                }

                // Connected clients count with badge
                const clientsCount = device.connected_devices_count || 0;
                let clientsBadge = '';
                if (clientsCount > 0) {
                    clientsBadge = `<span class="badge bg-primary">${clientsCount}</span>`;
                } else {
                    clientsBadge = `<span class="badge bg-secondary">0</span>`;
                }

                // RX Power badge with color based on signal strength
                const rxPower = parseFloat(device.rx_power);
                let rxBadgeClass = 'bg-secondary'; // Default for N/A
                let rxDisplay = device.rx_power;

                if (!isNaN(rxPower) && rxPower !== -999) {
                    if (rxPower > -20.00) {
                        rxBadgeClass = 'bg-success'; // Green: Good signal (above -20 dBm)
                    } else if (rxPower >= -23.00) {
                        rxBadgeClass = 'bg-warning'; // Yellow: Moderate signal (-20 to -23 dBm)
                    } else {
                        rxBadgeClass = 'bg-danger'; // Red: Weak signal (below -23 dBm)
                    }
                    rxDisplay = `<span class="badge ${rxBadgeClass}">${device.rx_power} dBm</span>`;
                } else {
                    rxDisplay = `<span class="badge ${rxBadgeClass}">N/A</span>`;
                }

                // Status badge with ping
                let statusBadge;
                if (device.status === 'online') {
                    const ping = device.ping || '-';
                    statusBadge = `<span class="badge online">ON [${ping}ms]</span>`;
                } else {
                    statusBadge = `<span class="badge offline">OFF [-]</span>`;
                }

                // Map button - conditional based on registration status
                let mapButton;
                if (isInMap) {
                    // Green button - opens map in new tab
                    let mapUrl;
                    if (mapInfo.itemType === 'mikrotik') {
                        // For MikroTik devices, focus on server
                        mapUrl = `/map.php?focus_type=server&focus_id=${mapInfo.itemId}`;
                    } else {
                        // For ONU devices, focus on ONU
                        mapUrl = `/map.php?focus_type=onu&focus_serial=${encodeURIComponent(device.serial_number)}`;
                    }
                    mapButton = `<button class="btn btn-sm btn-success me-1" onclick="window.open('${mapUrl}', '_blank')" title="View on Map"><i class="bi bi-map"></i></button>`;
                } else {
                    // Gray button - shows alert
                    mapButton = `<button class="btn btn-sm btn-secondary me-1" onclick="showNotInMapAlert('${encodeURIComponent(device.serial_number)}')" title="Not Registered in Map"><i class="bi bi-map"></i></button>`;
                }

                html += '<tr>';
                html += `<td><a href="/device-detail.php?id=${encodeURIComponent(device.device_id)}">${device.serial_number}</a></td>`;
                html += `<td>${device.mac_address}</td>`;
                html += `<td>${device.product_class || 'N/A'}</td>`;
                html += `<td>${ipDisplay}</td>`;
                html += `<td>${device.wifi_ssid}</td>`;
                html += `<td>${device.pppoe_username || 'N/A'}</td>`;
                html += `<td>${rxDisplay}</td>`;
                html += `<td>${device.temperature}°C</td>`;
                html += `<td class="text-center">${clientsBadge}</td>`;
                html += `<td>${statusBadge}</td>`;
                html += `<td>`;
                html += mapButton;
                html += `<button class="btn btn-sm btn-primary" onclick="summonDeviceQuick('${device.device_id}')" title="Summon Device"><i class="bi bi-lightning-charge"></i></button>`;
                html += `</td>`;
                html += '</tr>';
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;
        } else {
            if (result && result.error !== 'timeout') {
                container.innerHTML = '<p class="text-center text-danger">Failed to load recent devices</p>';
            } else {
                container.innerHTML = '<p class="text-center text-warning">Request timeout - please refresh</p>';
            }
        }
    } catch (error) {
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('Error loading recent devices:', error);
        }
        container.innerHTML = '<p class="text-center text-danger">Error loading data</p>';
    } finally {
        recentDevicesFetchInProgress = false;
    }
}

let currentSummonDeviceId = null;

function summonDeviceQuick(deviceId) {
    currentSummonDeviceId = deviceId;
    document.getElementById('summon-device-id').textContent = deviceId;
    const modal = new bootstrap.Modal(document.getElementById('summonModal'), {
        backdrop: false
    });
    modal.show();
}

function showNotInMapAlert(serialNumber) {
    document.getElementById('not-in-map-serial').textContent = decodeURIComponent(serialNumber);
    const modal = new bootstrap.Modal(document.getElementById('notInMapModal'), {
        backdrop: false
    });
    modal.show();
}

async function confirmSummon() {
    if (!currentSummonDeviceId) return;

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('summonModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: currentSummonDeviceId })
    });

    hideLoading();

    if (result && result.success) {
        showToast('Device summon berhasil!', 'success');
    } else {
        showToast(result.message || 'Gagal summon device', 'danger');
    }

    currentSummonDeviceId = null;
}

// Load data on page load
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($genieacsConfigured): ?>
        loadDashboardData();
        loadUplinkData();
        loadRecentDevices();
        // Auto refresh every 30 seconds
        setInterval(() => {
            loadDashboardData();
            loadUplinkData();
            loadRecentDevices();
        }, 30000);
    <?php endif; ?>
});
</script>

<?php include __DIR__ . '/views/layouts/footer.php'; ?>
