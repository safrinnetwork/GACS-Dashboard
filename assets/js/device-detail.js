// Device ID is set via global variable in device-detail.php
const deviceId = window.DEVICE_ID || '';
let savedScrollPosition = 0;
let savedHotspotData = {}; // Store last known hotspot data

// Helper function to get active tab name
function getActiveTabName() {
    const activeTab = document.querySelector('.nav-link.active');
    if (!activeTab) return 'Unknown';

    const tabText = activeTab.textContent.trim();
    return tabText.replace(/\(\d+\)/g, '').trim(); // Remove badge counts like (2)
}

// Save current hotspot data before re-render
function saveCurrentHotspotData() {
    savedHotspotData = {};

    const userCells = document.querySelectorAll('.hotspot-user[data-mac]');
    const trafficCells = document.querySelectorAll('.hotspot-traffic[data-mac]');

    userCells.forEach(cell => {
        const mac = cell.getAttribute('data-mac');
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].userHtml = cell.innerHTML;
    });

    trafficCells.forEach(cell => {
        const mac = cell.getAttribute('data-mac');
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].trafficHtml = cell.innerHTML;
    });

    if (Object.keys(savedHotspotData).length > 0) {
        console.debug('[HOTSPOT] Saved data for', Object.keys(savedHotspotData).length, 'devices before re-render');
    }
}

// Restore saved hotspot data after re-render
function restoreSavedHotspotData() {
    let restoredCount = 0;

    Object.keys(savedHotspotData).forEach(mac => {
        const data = savedHotspotData[mac];
        const userCell = document.querySelector(`.hotspot-user[data-mac="${mac}"]`);
        const trafficCell = document.querySelector(`.hotspot-traffic[data-mac="${mac}"]`);

        if (userCell && data.userHtml) {
            userCell.innerHTML = data.userHtml;
            restoredCount++;
        }

        if (trafficCell && data.trafficHtml) {
            trafficCell.innerHTML = data.trafficHtml;
        }
    });

    if (restoredCount > 0) {
        console.debug('[HOTSPOT] Restored data for', restoredCount, 'devices after re-render');
    }
}

async function loadDeviceDetail(isAutoRefresh = false) {
    // SKIP auto-refresh if hotspot monitoring is active to prevent conflicts
    if (isAutoRefresh && hotspotMonitoringActive) {
        console.debug('[AUTO-REFRESH] Skipping device detail refresh while hotspot monitoring is active');
        return; // Skip refresh completely
    }

    // Save hotspot data BEFORE re-render (if auto-refresh)
    if (isAutoRefresh) {
        saveCurrentHotspotData();
    }

    // Save current scroll position if this is an auto-refresh
    if (isAutoRefresh) {
        savedScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
        const activeTab = getActiveTabName();
        console.debug(`[AUTO-REFRESH] Saving scroll position: ${savedScrollPosition}px (Active tab: ${activeTab})`);
    } else {
        // Manual refresh - reset scroll to top
        savedScrollPosition = 0;
    }

    // For auto-refresh: NO loading spinner at all (silent refresh)
    // For manual refresh: Show full loading spinner
    if (!isAutoRefresh) {
        // Full loading for manual refresh only
        document.getElementById('loading-spinner').innerHTML = '<div class="spinner"></div>';
        document.getElementById('loading-spinner').style.display = 'block';
        document.getElementById('deviceTabs').style.display = 'none';
        document.getElementById('deviceTabContent').style.display = 'none';
    }

    const result = await fetchAPI('/api/get-device-detail.php?device_id=' + encodeURIComponent(deviceId));

    if (result && result.success) {
        const device = result.device;

        // Fetch ONU location from map
        const locationResult = await fetchAPI('/api/get-onu-location.php?serial_number=' + encodeURIComponent(device.serial_number));

        // Update badge
        document.getElementById('device-id-badge').textContent = device.serial_number;

        // Update tags badge
        updateTagsBadge(device.tags || []);

        // Update badge counts
        document.getElementById('wan-count-badge').textContent = device.wan_details ? device.wan_details.length : 0;
        document.getElementById('devices-count-badge').textContent = device.connected_devices ? device.connected_devices.length : 0;

        // Populate Overview Tab
        document.getElementById('overview-content').innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <h6><i class="bi bi-info-circle"></i> Basic Information</h6>
                    <table class="table table-sm table-bordered">
                        <tr><th width="40%">Device ID</th><td>${device.device_id}</td></tr>
                        <tr><th>Serial Number</th><td>${device.serial_number}</td></tr>
                        <tr><th>MAC Address</th><td>${device.mac_address}</td></tr>
                        <tr><th>Last Inform</th><td>${device.last_inform}</td></tr>
                        <tr><th>Status</th><td><span class="badge ${device.status === 'online' ? 'online' : 'offline'}">${device.status}</span></td></tr>
                        <tr><th>Manufacturer</th><td>${device.manufacturer}</td></tr>
                        <tr><th>Product Class</th><td>${device.product_class}</td></tr>
                        <tr><th>OUI</th><td>${device.oui}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6><i class="bi bi-cpu"></i> Hardware/Software</h6>
                    <table class="table table-sm table-bordered">
                        <tr><th width="40%">Hardware Version</th><td>${device.hardware_version}</td></tr>
                        <tr><th>Software Version</th><td>${device.software_version}</td></tr>
                        <tr><th>Uptime</th><td>${formatUptime(device.uptime)}</td></tr>
                    </table>

                    <h6 class="mt-4"><i class="bi bi-broadcast"></i> Optical Information</h6>
                    <table class="table table-sm table-bordered">
                        <tr><th width="40%">Rx Power</th><td>${device.rx_power} dBm</td></tr>
                        <tr><th>Temperature</th><td>${device.temperature}°C</td></tr>
                    </table>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-12">
                    <h6><i class="bi bi-ethernet"></i> Network Information</h6>
                    <table class="table table-sm table-bordered">
                        <tr>
                            <th width="20%">IP TR069</th>
                            <td>${makeIPClickable(extractIP(device.ip_tr069))}</td>
                        </tr>
                        <tr>
                            <th>WiFi SSID</th>
                            <td>
                                ${device.wifi_ssid}
                                <button class="btn btn-sm btn-warning ms-2" onclick="openEditWiFiModal('${device.device_id}', '${device.wifi_ssid.replace(/'/g, "\\'")}', '${device.wifi_password.replace(/'/g, "\\'")}')">
                                    <i class="bi bi-pencil"></i> Edit WiFi
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th>WiFi Password</th>
                            <td>
                                <span id="wifi-pass-hidden">********</span>
                                <span id="wifi-pass-shown" style="display:none;">${device.wifi_password}</span>
                                <button class="btn btn-sm btn-link" onclick="togglePassword()">
                                    <i id="toggle-icon" class="bi bi-eye"></i>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th>Full TR069 URL</th>
                            <td><small>${device.ip_tr069}</small></td>
                        </tr>
                    </table>

                    <h6 class="mt-4">
                        <i class="bi bi-shield-lock"></i> Admin Web Access
                        ${(device.admin_user === 'N/A' || !device.admin_user || device.admin_user === '' || device.admin_user === null || device.admin_user === undefined) ?
                            '<button id="get-credentials-btn" class="btn btn-sm btn-warning ms-2" onclick="summonForAdminCredentials()" title="Summon device to get admin credentials"><i class="bi bi-lightning-charge"></i> Get Credentials</button>' :
                            ''}
                    </h6>
                    <div id="credentials-status" class="alert alert-info" style="display:none;">
                        <i class="bi bi-info-circle"></i> <span id="credentials-status-text"></span>
                    </div>
                    <table class="table table-sm table-bordered">
                        <tr>
                            <th width="20%">Super Admin User</th>
                            <td>
                                <code>${device.admin_user || 'N/A'}</code>
                            </td>
                        </tr>
                        <tr>
                            <th>Super Admin Password</th>
                            <td>
                                <span id="admin-pass-hidden">********</span>
                                <span id="admin-pass-shown" style="display:none;"><code>${device.admin_password || 'N/A'}</code></span>
                                <button class="btn btn-sm btn-link" onclick="toggleAdminPassword()">
                                    <i id="admin-toggle-icon" class="bi bi-eye"></i>
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <th>Telecom Password</th>
                            <td>
                                <span id="telecom-pass-hidden">********</span>
                                <span id="telecom-pass-shown" style="display:none;"><code>${device.telecom_password || 'N/A'}</code></span>
                                <button class="btn btn-sm btn-link" onclick="toggleTelecomPassword()">
                                    <i id="telecom-toggle-icon" class="bi bi-eye"></i>
                                </button>
                            </td>
                        </tr>
                    </table>
                    ${(device.admin_user === 'N/A' || !device.admin_user || device.admin_user === '' || device.admin_user === null || device.admin_user === undefined) ?
                        '<div class="alert alert-info mt-2"><i class="bi bi-info-circle"></i> <strong>Admin credentials belum tersedia.</strong><br><br>Klik tombol <strong>"Get Credentials"</strong> untuk mengambil username dan password dari device.<br><br>⏱️ <em>Proses membutuhkan waktu ~20 detik (otomatis summon 2x untuk device baru)</em></div>' :
                        ''}
                </div>
            </div>
        `;

        // Populate Topology Location Tab
        document.getElementById('topology-content').innerHTML = renderTopologyLocationTab(locationResult);

        // Populate WAN Connections Tab
        document.getElementById('wan-content').innerHTML = renderWANDetailsTab(device.wan_details);

        // Populate DHCP Server Tab
        document.getElementById('dhcp-content').innerHTML = renderDHCPServerTab(device.dhcp_server);

        // Populate Connected Devices Tab
        document.getElementById('devices-content').innerHTML = renderConnectedDevicesTab(device.connected_devices);

        // Restore hotspot data after re-render (if available)
        if (isAutoRefresh && Object.keys(savedHotspotData).length > 0) {
            setTimeout(() => {
                restoreSavedHotspotData();
                console.debug('[AUTO-REFRESH] Restored hotspot data for', Object.keys(savedHotspotData).length, 'devices');
            }, 50);
        }

        // Hide loading, show tabs (only if they were hidden)
        document.getElementById('loading-spinner').style.display = 'none';
        if (!isAutoRefresh) {
            document.getElementById('deviceTabs').style.display = 'flex';
            document.getElementById('deviceTabContent').style.display = 'block';
        }

        // Restore scroll position after refresh (if saved)
        if (isAutoRefresh && savedScrollPosition > 0) {
            setTimeout(() => {
                window.scrollTo(0, savedScrollPosition);
                const activeTab = getActiveTabName();
                console.debug(`[AUTO-REFRESH] ✓ Restored scroll position: ${savedScrollPosition}px (Active tab: ${activeTab})`);
            }, 50);
        }

        // Restore scroll position after Get Credentials (from sessionStorage)
        const credentialsScrollPos = sessionStorage.getItem('credentialsScrollPosition');
        if (credentialsScrollPos) {
            setTimeout(() => {
                window.scrollTo(0, parseInt(credentialsScrollPos));
                console.log('[GET-CREDENTIALS] ✓ Restored scroll position:', credentialsScrollPos);
                // Clear sessionStorage after use
                sessionStorage.removeItem('credentialsScrollPosition');
            }, 50);
        }

        // Restart hotspot monitoring if it was active before refresh
        if (hotspotMonitoringActive) {
            // Add delay to prevent immediate fetch after refresh (reduce load spikes)
            setTimeout(() => {
                const loadBtn = document.getElementById('load-hotspot-btn');
                if (loadBtn) {
                    console.log('[AUTO-REFRESH] Restarting hotspot monitoring (with 3s delay to reduce load)...');
                    // Don't call startHotspotTrafficMonitoring() directly - it fetches immediately
                    // Instead, just restart the interval without immediate fetch
                    const stopBtn = document.getElementById('stop-hotspot-btn');
                    const statusEl = document.getElementById('hotspot-status');

                    if (loadBtn) loadBtn.style.display = 'none';
                    if (stopBtn) stopBtn.style.display = 'inline-block';
                    if (statusEl) statusEl.innerHTML = '<span class="text-muted">⏱ Waiting for next update...</span>';

                    // 5 second interval - balanced between smooth updates and MikroTik load
                    hotspotTrafficInterval = setInterval(fetchHotspotTraffic, 5000);
                    console.log('[HOTSPOT] Monitoring restarted (5s interval)');
                }
            }, 3000); // 3 second delay before restarting
        }
    } else {
        // Show error in loading area
        document.getElementById('loading-spinner').innerHTML = '<div class="alert alert-danger">Failed to load device details</div>';
    }
}

function extractIP(ipString) {
    if (!ipString || ipString === 'N/A') return 'N/A';
    const match = ipString.match(/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/);
    return match ? match[1] : 'N/A';
}

function makeIPClickable(ip) {
    if (!ip || ip === 'N/A' || ip === '0.0.0.0') {
        return ip;
    }

    // Check if it's a valid IP address
    const ipRegex = /^(\d{1,3}\.){3}\d{1,3}$/;
    if (!ipRegex.test(ip)) {
        return ip;
    }

    return `<a href="http://${ip}" target="_blank" rel="noopener noreferrer" title="Open http://${ip}">${ip}</a>`;
}

function renderTopologyLocationTab(locationResult) {
    if (!locationResult || !locationResult.success) {
        return '<div class="alert alert-info"><i class="bi bi-info-circle"></i> Unable to load topology location</div>';
    }

    // ONU not found in map
    if (!locationResult.location || !locationResult.location.found) {
        return `
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>Topology Location:</strong> This device has not been added to the network map yet.
                <a href="/map.php" class="alert-link">Add to map</a>
            </div>
        `;
    }

    const loc = locationResult.location;
    let html = '';

    // Build hierarchy path
    let pathParts = [];
    if (loc.server && loc.server.name) {
        pathParts.push(`<span class="badge bg-secondary">${loc.server.name}</span>`);
    }
    if (loc.olt && loc.olt.name) {
        pathParts.push(`<span class="badge bg-info">${loc.olt.name}</span>`);
    }
    if (loc.odc && loc.odc.name) {
        pathParts.push(`<span class="badge bg-warning text-dark">${loc.odc.name}</span>`);
    }
    if (loc.odp && loc.odp.name) {
        pathParts.push(`<span class="badge bg-success">${loc.odp.name}</span>`);
    }
    if (loc.onu && loc.onu.name) {
        pathParts.push(`<span class="badge bg-primary">${loc.onu.name}</span>`);
    }

    if (pathParts.length > 0) {
        html += '<div class="mb-3">';
        html += '<h6><i class="bi bi-diagram-3"></i> Hierarchy Path</h6>';
        html += '<div class="p-3 bg-light rounded">' + pathParts.join(' <i class="bi bi-arrow-right"></i> ') + '</div>';
        html += '</div>';
    }

    html += '<table class="table table-bordered">';

    // ODP Information
    if (loc.odp && loc.odp.name) {
        html += `
            <tr>
                <th width="30%"><i class="bi bi-box"></i> ODP</th>
                <td>
                    <strong>${loc.odp.name}</strong>
                    <a href="/map.php?focus_type=odp&focus_id=${loc.odp.id}" class="btn btn-sm btn-outline-primary ms-2" target="_blank">
                        <i class="bi bi-map"></i> View on Map
                    </a>
                </td>
            </tr>
        `;
    }

    // Port Number
    if (loc.onu && loc.onu.port && loc.onu.port !== 'N/A') {
        html += `
            <tr>
                <th><i class="bi bi-plug"></i> Port Number</th>
                <td><span class="badge bg-info">Port ${loc.onu.port}</span></td>
            </tr>
        `;
    }

    // ODC Information
    if (loc.odc && loc.odc.name) {
        html += `
            <tr>
                <th><i class="bi bi-building"></i> ODC</th>
                <td>
                    <strong>${loc.odc.name}</strong>
                    <a href="/map.php?focus_type=odc&focus_id=${loc.odc.id}" class="btn btn-sm btn-outline-warning ms-2" target="_blank">
                        <i class="bi bi-map"></i> View on Map
                    </a>
                </td>
            </tr>
        `;
    }

    // OLT Information
    if (loc.olt && loc.olt.name) {
        html += `
            <tr>
                <th><i class="bi bi-hdd-network"></i> OLT</th>
                <td><strong>${loc.olt.name}</strong></td>
            </tr>
        `;
    }

    // Coordinates and Google Maps
    if (loc.onu && loc.onu.lat && loc.onu.lng) {
        const lat = parseFloat(loc.onu.lat);
        const lng = parseFloat(loc.onu.lng);

        // Only show if coordinates are not 0,0
        if (lat !== 0 && lng !== 0) {
            const googleMapsUrl = `https://www.google.com/maps?q=${lat},${lng}`;

            html += `
                <tr>
                    <th><i class="bi bi-geo-alt"></i> Coordinates</th>
                    <td>
                        <code>${lat.toFixed(6)}, ${lng.toFixed(6)}</code>
                        <a href="${googleMapsUrl}" class="btn btn-sm btn-success ms-2" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-globe"></i> View on Google Maps
                        </a>
                        <a href="/map.php?focus_type=onu&focus_id=${loc.onu.id}" class="btn btn-sm btn-outline-primary ms-1" target="_blank">
                            <i class="bi bi-map"></i> View on Network Map
                        </a>
                    </td>
                </tr>
            `;
        }
    }

    html += '</table>';
    return html;
}

function renderTopologyLocation(locationResult) {
    if (!locationResult || !locationResult.success) {
        return '';
    }

    // ONU not found in map
    if (!locationResult.location || !locationResult.location.found) {
        return `
            <div class="alert alert-info mb-3">
                <i class="bi bi-info-circle"></i>
                <strong>Topology Location:</strong> This device has not been added to the network map yet.
                <a href="/map.php" class="alert-link">Add to map</a>
            </div>
        `;
    }

    const loc = locationResult.location;
    let html = '<div class="card mb-3 border-primary">';
    html += '<div class="card-header bg-primary text-white">';
    html += '<i class="bi bi-diagram-3"></i> <strong>Network Topology Location</strong>';
    html += '</div>';
    html += '<div class="card-body">';

    // Build hierarchy path
    let pathParts = [];
    if (loc.server && loc.server.name) {
        pathParts.push(`<span class="badge bg-secondary">${loc.server.name}</span>`);
    }
    if (loc.olt && loc.olt.name) {
        pathParts.push(`<span class="badge bg-info">${loc.olt.name}</span>`);
    }
    if (loc.odc && loc.odc.name) {
        pathParts.push(`<span class="badge bg-warning text-dark">${loc.odc.name}</span>`);
    }
    if (loc.odp && loc.odp.name) {
        pathParts.push(`<span class="badge bg-success">${loc.odp.name}</span>`);
    }
    if (loc.onu && loc.onu.name) {
        pathParts.push(`<span class="badge bg-primary">${loc.onu.name}</span>`);
    }

    if (pathParts.length > 0) {
        html += '<div class="mb-3">';
        html += '<strong>Hierarchy Path:</strong><br>';
        html += pathParts.join(' <i class="bi bi-arrow-right"></i> ');
        html += '</div>';
    }

    html += '<table class="table table-sm table-bordered mb-0">';

    // ODP Information
    if (loc.odp && loc.odp.name) {
        html += `
            <tr>
                <th width="30%"><i class="bi bi-box"></i> ODP</th>
                <td>
                    <strong>${loc.odp.name}</strong>
                    <a href="/map.php?focus_type=odp&focus_id=${loc.odp.id}" class="btn btn-sm btn-outline-primary ms-2" target="_blank">
                        <i class="bi bi-map"></i> View on Map
                    </a>
                </td>
            </tr>
        `;
    }

    // Port Number
    if (loc.onu && loc.onu.port && loc.onu.port !== 'N/A') {
        html += `
            <tr>
                <th><i class="bi bi-plug"></i> Port Number</th>
                <td><span class="badge bg-info">Port ${loc.onu.port}</span></td>
            </tr>
        `;
    }

    // ODC Information
    if (loc.odc && loc.odc.name) {
        html += `
            <tr>
                <th><i class="bi bi-building"></i> ODC</th>
                <td>
                    <strong>${loc.odc.name}</strong>
                    <a href="/map.php?focus_type=odc&focus_id=${loc.odc.id}" class="btn btn-sm btn-outline-warning ms-2" target="_blank">
                        <i class="bi bi-map"></i> View on Map
                    </a>
                </td>
            </tr>
        `;
    }

    // OLT Information
    if (loc.olt && loc.olt.name) {
        html += `
            <tr>
                <th><i class="bi bi-hdd-network"></i> OLT</th>
                <td><strong>${loc.olt.name}</strong></td>
            </tr>
        `;
    }

    // Coordinates and Google Maps
    if (loc.onu && loc.onu.lat && loc.onu.lng) {
        const lat = parseFloat(loc.onu.lat);
        const lng = parseFloat(loc.onu.lng);

        // Only show if coordinates are not 0,0
        if (lat !== 0 && lng !== 0) {
            const googleMapsUrl = `https://www.google.com/maps?q=${lat},${lng}`;

            html += `
                <tr>
                    <th><i class="bi bi-geo-alt"></i> Coordinates</th>
                    <td>
                        <code>${lat.toFixed(6)}, ${lng.toFixed(6)}</code>
                        <a href="${googleMapsUrl}" class="btn btn-sm btn-success ms-2" target="_blank" rel="noopener noreferrer">
                            <i class="bi bi-globe"></i> View on Google Maps
                        </a>
                        <a href="/map.php?focus_type=onu&focus_id=${loc.onu.id}" class="btn btn-sm btn-outline-primary ms-1" target="_blank">
                            <i class="bi bi-map"></i> View on Network Map
                        </a>
                    </td>
                </tr>
            `;
        }
    }

    html += '</table>';
    html += '</div>';
    html += '</div>';

    return html;
}

function renderWANDetailsTab(wanDetails) {
    if (!wanDetails || wanDetails.length === 0) {
        return `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6><i class="bi bi-globe"></i> WAN Connection Details</h6>
                <button class="btn btn-sm btn-success" onclick="openAddWANModal()"><i class="bi bi-plus-lg"></i> Add WAN Connection</button>
            </div>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No WAN connections configured on this device.
                <button class="btn btn-sm btn-success ms-2" onclick="openAddWANModal()">
                    <i class="bi bi-plus-lg"></i> Add First Connection
                </button>
            </div>
        `;
    }

    let html = '<div class="d-flex justify-content-between align-items-center mb-3">';
    html += '<h6><i class="bi bi-globe"></i> WAN Connection Details</h6>';
    html += '<button class="btn btn-sm btn-success" onclick="openAddWANModal()"><i class="bi bi-plus-lg"></i> Add WAN Connection</button>';
    html += '</div>';

    wanDetails.forEach((wan, index) => {
        const statusBadge = wan.status === 'Connected' ?
            '<span class="badge online">Connected</span>' :
            '<span class="badge offline">Disconnected</span>';

        // Check if this is a bridge connection
        const isBridge = wan.connection_type && (
            wan.connection_type.includes('Bridge') ||
            wan.connection_type.includes('Bridged')
        );

        // Extract VLAN ID from connection name
        const vlanMatch = wan.name.match(/VID[_-]?(\d+)/i);
        const vlanId = vlanMatch ? vlanMatch[1] : null;

        // Check if this is TR069 connection
        const isTR069 = (wan.service_list && (wan.service_list.toUpperCase().includes('TR069') || wan.service_list.toUpperCase().includes('CWMP'))) ||
                        (wan.name && (wan.name.toUpperCase().includes('TR069') || wan.name.toUpperCase().includes('CWMP')));

        html += `
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${wan.name}</strong>
                            <span class="badge bg-info ms-2">${wan.type}</span>
                            ${statusBadge}
                            ${isBridge ? '<span class="badge bg-secondary ms-2">Bridge Mode</span>' : ''}
                            ${isTR069 ? '<span class="badge bg-danger ms-2"><i class="bi bi-exclamation-triangle"></i> TR069</span>' : ''}
                        </div>
                        <div>
                            <button class="btn btn-sm btn-warning" onclick='openEditWANModal(${JSON.stringify(wan)})'>
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-danger" onclick='openDeleteWANModal(${JSON.stringify(wan)})'>
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">`;

        // Build table content (same as before)
        if (isBridge) {
            html += `
                    <table class="table table-sm table-bordered mb-0">
                        <tr>
                            <th width="30%">Connection Type</th>
                            <td>${wan.connection_type}</td>
                        </tr>`;

            if (vlanId) {
                html += `
                        <tr>
                            <th>VLAN ID</th>
                            <td>${vlanId}</td>
                        </tr>`;
            }

            if (wan.binding && wan.binding !== 'N/A') {
                html += `
                        <tr>
                            <th>Bound to</th>
                            <td><span class="badge bg-primary">${wan.binding}</span></td>
                        </tr>`;
            }

            html += `
                    </table>`;
        } else {
            html += `
                    <table class="table table-sm table-bordered mb-0">
                        <tr>
                            <th width="30%">Connection Type</th>
                            <td>${wan.connection_type}</td>
                        </tr>`;

            if (vlanId) {
                html += `
                        <tr>
                            <th>VLAN ID</th>
                            <td>${vlanId}</td>
                        </tr>`;
            }

            if (wan.binding && wan.binding !== 'N/A') {
                html += `
                        <tr>
                            <th>Bound to</th>
                            <td><span class="badge bg-primary">${wan.binding}</span></td>
                        </tr>`;
            }

            if (wan.external_ip && wan.external_ip !== 'N/A' && wan.external_ip !== '0.0.0.0') {
                html += `
                        <tr>
                            <th>External IP</th>
                            <td>${makeIPClickable(wan.external_ip)}</td>
                        </tr>`;
            }

            if (wan.gateway && wan.gateway !== 'N/A' && wan.gateway !== '0.0.0.0') {
                html += `
                        <tr>
                            <th>Gateway</th>
                            <td>${makeIPClickable(wan.gateway)}</td>
                        </tr>`;
            }

            if (wan.subnet_mask && wan.subnet_mask !== 'N/A') {
                html += `
                        <tr>
                            <th>Subnet Mask</th>
                            <td>${wan.subnet_mask}</td>
                        </tr>`;
            }

            if (wan.dns_servers && wan.dns_servers !== 'N/A' && wan.dns_servers !== '') {
                html += `
                        <tr>
                            <th>DNS Servers</th>
                            <td>${wan.dns_servers}</td>
                        </tr>`;
            }

            if (wan.mac_address && wan.mac_address !== 'N/A' && wan.mac_address !== '00:00:00:00:00:00') {
                html += `
                        <tr>
                            <th>MAC Address</th>
                            <td>${wan.mac_address}</td>
                        </tr>`;
            }

            if (wan.type === 'PPPoE') {
                if (wan.username && wan.username !== 'N/A' && wan.username !== '') {
                    html += `
                        <tr>
                            <th>Username</th>
                            <td>${wan.username}</td>
                        </tr>`;
                }

                if (wan.last_error && wan.last_error !== 'N/A') {
                    html += `
                        <tr>
                            <th>Last Error</th>
                            <td>${wan.last_error}</td>
                        </tr>`;
                }

                if (wan.mru_size && wan.mru_size !== 'N/A' && wan.mru_size !== '0' && wan.mru_size !== 0) {
                    html += `
                        <tr>
                            <th>MRU Size</th>
                            <td>${wan.mru_size}</td>
                        </tr>`;
                }
            }

            if (wan.type === 'IP') {
                if (wan.addressing_type && wan.addressing_type !== 'N/A') {
                    html += `
                        <tr>
                            <th>Addressing Type</th>
                            <td>${wan.addressing_type}</td>
                        </tr>`;
                }
            }

            if (wan.uptime && wan.uptime !== 'N/A' && wan.uptime !== '0' && wan.uptime !== 0) {
                html += `
                        <tr>
                            <th>Uptime</th>
                            <td>${formatUptime(wan.uptime)}</td>
                        </tr>`;
            }

            html += `
                    </table>`;
        }

        html += `
                </div>
            </div>
        `;
    });

    return html;
}

function renderWANDetails(wanDetails) {
    if (!wanDetails || wanDetails.length === 0) {
        return '';
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += '<div class="d-flex justify-content-between align-items-center mb-2">';
    html += '<h6><i class="bi bi-globe"></i> WAN Connection Details</h6>';
    html += '<button class="btn btn-sm btn-success" onclick="openAddWANModal()"><i class="bi bi-plus-lg"></i> Add WAN Connection</button>';
    html += '</div>';

    wanDetails.forEach((wan, index) => {
        const statusBadge = wan.status === 'Connected' ?
            '<span class="badge online">Connected</span>' :
            '<span class="badge offline">Disconnected</span>';

        // Check if this is a bridge connection
        const isBridge = wan.connection_type && (
            wan.connection_type.includes('Bridge') ||
            wan.connection_type.includes('Bridged')
        );

        // Extract VLAN ID from connection name (e.g., "2_INTERNET_B_VID_20" -> "20")
        const vlanMatch = wan.name.match(/VID[_-]?(\d+)/i);
        const vlanId = vlanMatch ? vlanMatch[1] : null;

        // Check if this is TR069 connection
        const isTR069 = (wan.service_list && (wan.service_list.toUpperCase().includes('TR069') || wan.service_list.toUpperCase().includes('CWMP'))) ||
                        (wan.name && (wan.name.toUpperCase().includes('TR069') || wan.name.toUpperCase().includes('CWMP')));

        html += `
            <div class="card mb-3">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${wan.name}</strong>
                            <span class="badge bg-info ms-2">${wan.type}</span>
                            ${statusBadge}
                            ${isBridge ? '<span class="badge bg-secondary ms-2">Bridge Mode</span>' : ''}
                            ${isTR069 ? '<span class="badge bg-danger ms-2"><i class="bi bi-exclamation-triangle"></i> TR069</span>' : ''}
                        </div>
                        <div>
                            <button class="btn btn-sm btn-warning" onclick='openEditWANModal(${JSON.stringify(wan)})'>
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <button class="btn btn-sm btn-danger" onclick='openDeleteWANModal(${JSON.stringify(wan)})'>
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body">`;

        // For bridge connections, show minimal info
        if (isBridge) {
            html += `
                    <table class="table table-sm table-bordered mb-0">
                        <tr>
                            <th width="30%">Connection Type</th>
                            <td>${wan.connection_type}</td>
                        </tr>`;

            if (vlanId) {
                html += `
                        <tr>
                            <th>VLAN ID</th>
                            <td>${vlanId}</td>
                        </tr>`;
            }

            if (wan.binding && wan.binding !== 'N/A') {
                html += `
                        <tr>
                            <th>Bound to</th>
                            <td><span class="badge bg-primary">${wan.binding}</span></td>
                        </tr>`;
            }

            html += `
                    </table>`;
        } else {
            // For routed connections, show all available info
            html += `
                    <table class="table table-sm table-bordered mb-0">
                        <tr>
                            <th width="30%">Connection Type</th>
                            <td>${wan.connection_type}</td>
                        </tr>`;

            if (vlanId) {
                html += `
                        <tr>
                            <th>VLAN ID</th>
                            <td>${vlanId}</td>
                        </tr>`;
            }

            if (wan.binding && wan.binding !== 'N/A') {
                html += `
                        <tr>
                            <th>Bound to</th>
                            <td><span class="badge bg-primary">${wan.binding}</span></td>
                        </tr>`;
            }

            // Show External IP if available
            if (wan.external_ip && wan.external_ip !== 'N/A' && wan.external_ip !== '0.0.0.0') {
                html += `
                        <tr>
                            <th>External IP</th>
                            <td>${makeIPClickable(wan.external_ip)}</td>
                        </tr>`;
            }

            // Show Gateway if available
            if (wan.gateway && wan.gateway !== 'N/A' && wan.gateway !== '0.0.0.0') {
                html += `
                        <tr>
                            <th>Gateway</th>
                            <td>${makeIPClickable(wan.gateway)}</td>
                        </tr>`;
            }

            // Show Subnet Mask if available
            if (wan.subnet_mask && wan.subnet_mask !== 'N/A') {
                html += `
                        <tr>
                            <th>Subnet Mask</th>
                            <td>${wan.subnet_mask}</td>
                        </tr>`;
            }

            // Show DNS Servers if available
            if (wan.dns_servers && wan.dns_servers !== 'N/A' && wan.dns_servers !== '') {
                html += `
                        <tr>
                            <th>DNS Servers</th>
                            <td>${wan.dns_servers}</td>
                        </tr>`;
            }

            // Show MAC Address if available
            if (wan.mac_address && wan.mac_address !== 'N/A' && wan.mac_address !== '00:00:00:00:00:00') {
                html += `
                        <tr>
                            <th>MAC Address</th>
                            <td>${wan.mac_address}</td>
                        </tr>`;
            }

            // PPPoE specific fields
            if (wan.type === 'PPPoE') {
                if (wan.username && wan.username !== 'N/A' && wan.username !== '') {
                    html += `
                        <tr>
                            <th>Username</th>
                            <td>${wan.username}</td>
                        </tr>`;
                }

                if (wan.last_error && wan.last_error !== 'N/A') {
                    html += `
                        <tr>
                            <th>Last Error</th>
                            <td>${wan.last_error}</td>
                        </tr>`;
                }

                if (wan.mru_size && wan.mru_size !== 'N/A' && wan.mru_size !== '0' && wan.mru_size !== 0) {
                    html += `
                        <tr>
                            <th>MRU Size</th>
                            <td>${wan.mru_size}</td>
                        </tr>`;
                }
            }

            // IP Connection specific fields
            if (wan.type === 'IP') {
                if (wan.addressing_type && wan.addressing_type !== 'N/A') {
                    html += `
                        <tr>
                            <th>Addressing Type</th>
                            <td>${wan.addressing_type}</td>
                        </tr>`;
                }
            }

            // Show uptime if available and not 0
            if (wan.uptime && wan.uptime !== 'N/A' && wan.uptime !== '0' && wan.uptime !== 0) {
                html += `
                        <tr>
                            <th>Uptime</th>
                            <td>${formatUptime(wan.uptime)}</td>
                        </tr>`;
            }

            html += `
                    </table>`;
        }

        html += `
                </div>
            </div>
        `;
    });

    html += '</div></div>';
    return html;
}

function renderDHCPServerTab(dhcpServer) {
    if (!dhcpServer) {
        return '<div class="alert alert-info"><i class="bi bi-info-circle"></i> DHCP server not supported on this device</div>';
    }

    let html = '<div class="d-flex justify-content-between align-items-center mb-3">';
    html += '<h6><i class="bi bi-router"></i> DHCP Server Configuration</h6>';
    html += `<button class="btn btn-sm btn-primary" onclick='openEditDHCPModal(${JSON.stringify(dhcpServer)})'><i class="bi bi-pencil"></i> Edit DHCP</button>`;
    html += '</div>';

    const dhcpEnabled = dhcpServer.enabled === true || dhcpServer.enabled === 'true';
    const statusBadge = dhcpEnabled ?
        '<span class="badge online">Enabled</span>' :
        '<span class="badge offline">Disabled</span>';

    html += `
        <div class="card">
            <div class="card-header bg-light">
                <strong>DHCP Server Status</strong>
                ${statusBadge}
            </div>
            <div class="card-body">
                <table class="table table-sm table-bordered mb-0">
                    <tr>
                        <th width="30%">DHCP Server</th>
                        <td>${dhcpEnabled ? 'Enabled' : 'Disabled'}</td>
                    </tr>`;

    if (dhcpEnabled || dhcpServer.min_address !== 'N/A') {
        html += `
                    <tr>
                        <th>IP Address Pool Start</th>
                        <td>${dhcpServer.min_address}</td>
                    </tr>
                    <tr>
                        <th>IP Address Pool End</th>
                        <td>${dhcpServer.max_address || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Subnet Mask</th>
                        <td>${dhcpServer.subnet_mask || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Default Gateway</th>
                        <td>${makeIPClickable(dhcpServer.gateway || 'N/A')}</td>
                    </tr>
                    <tr>
                        <th>DNS Servers</th>
                        <td>${dhcpServer.dns_servers || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Lease Time</th>
                        <td>${dhcpServer.lease_time ? formatUptime(dhcpServer.lease_time) : 'N/A'}</td>
                    </tr>`;
    }

    html += `
                </table>
            </div>
        </div>
    `;

    return html;
}

function renderDHCPServer(dhcpServer) {
    if (!dhcpServer) {
        return '';
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += '<div class="d-flex justify-content-between align-items-center mb-2">';
    html += '<h6><i class="bi bi-router"></i> DHCP Server Configuration</h6>';
    html += `<button class="btn btn-sm btn-primary" onclick='openEditDHCPModal(${JSON.stringify(dhcpServer)})'><i class="bi bi-pencil"></i> Edit DHCP</button>`;
    html += '</div>';

    const dhcpEnabled = dhcpServer.enabled === true || dhcpServer.enabled === 'true';
    const statusBadge = dhcpEnabled ?
        '<span class="badge online">Enabled</span>' :
        '<span class="badge offline">Disabled</span>';

    html += `
        <div class="card">
            <div class="card-header bg-light">
                <strong>DHCP Server Status</strong>
                ${statusBadge}
            </div>
            <div class="card-body">
                <table class="table table-sm table-bordered mb-0">
                    <tr>
                        <th width="30%">DHCP Server</th>
                        <td>${dhcpEnabled ? 'Enabled' : 'Disabled'}</td>
                    </tr>`;

    if (dhcpEnabled && dhcpServer.min_address) {
        html += `
                    <tr>
                        <th>IP Address Pool Start</th>
                        <td>${dhcpServer.min_address}</td>
                    </tr>
                    <tr>
                        <th>IP Address Pool End</th>
                        <td>${dhcpServer.max_address || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Subnet Mask</th>
                        <td>${dhcpServer.subnet_mask || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Default Gateway</th>
                        <td>${makeIPClickable(dhcpServer.gateway || 'N/A')}</td>
                    </tr>
                    <tr>
                        <th>DNS Servers</th>
                        <td>${dhcpServer.dns_servers || 'N/A'}</td>
                    </tr>
                    <tr>
                        <th>Lease Time</th>
                        <td>${dhcpServer.lease_time ? formatUptime(dhcpServer.lease_time) : 'N/A'}</td>
                    </tr>`;
    }

    html += `
                </table>
            </div>
        </div>
    `;

    html += '</div></div>';
    return html;
}

function renderConnectedDevicesTab(connectedDevices) {
    if (!connectedDevices || connectedDevices.length === 0) {
        return `
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No devices currently connected to this ONU
            </div>
        `;
    }

    let html = '<h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-primary">' + connectedDevices.length + '</span></h6>';

    html += `
        <div class="mb-3">
            <small class="text-muted" id="hotspot-status"><i class="bi bi-router"></i> Hotspot monitoring will start automatically...</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover" id="connected-devices-table">
                <thead class="table-light">
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th width="18%"><i class="bi bi-pc-display"></i> Device Name</th>
                        <th width="12%"><i class="bi bi-hdd-network"></i> IP Address</th>
                        <th width="15%"><i class="bi bi-ethernet"></i> MAC Address</th>
                        <th width="10%" class="text-center"><i class="bi bi-wifi"></i> Connection</th>
                        <th width="10%" class="text-center"><i class="bi bi-check-circle"></i> Status</th>
                        <th width="13%"><i class="bi bi-person-badge"></i> Hotspot User</th>
                        <th width="18%"><i class="bi bi-speedometer2"></i> Traffic (RX / TX)</th>
                    </tr>
                </thead>
                <tbody>
    `;

    connectedDevices.forEach((device, index) => {
        // Determine connection type icon
        let connectionIcon = 'bi-ethernet';
        let connectionBadge = 'bg-secondary';
        if (device.interface_type === 'WiFi') {
            connectionIcon = 'bi-wifi';
            connectionBadge = 'bg-info';
        } else if (device.interface_type === 'Ethernet') {
            connectionIcon = 'bi-ethernet';
            connectionBadge = 'bg-success';
        }

        // Determine status badge
        const statusBadge = device.active ?
            '<span class="badge online">Active</span>' :
            '<span class="badge offline">Inactive</span>';

        // Display vendor name if available, otherwise use hostname
        const displayName = device.vendor || device.hostname || 'Unknown Device';

        html += `
            <tr data-mac="${device.mac_address}">
                <td class="text-center">${index + 1}</td>
                <td>
                    <i class="bi bi-pc-display text-primary"></i> <strong>${displayName}</strong>
                    ${device.hostname && device.vendor && device.hostname !== device.vendor ? `<br><small class="text-muted">Hostname: ${device.hostname}</small>` : ''}
                </td>
                <td>${makeIPClickable(device.ip_address)}</td>
                <td><code>${device.mac_address}</code></td>
                <td class="text-center">
                    <span class="badge ${connectionBadge}">
                        <i class="${connectionIcon}"></i> ${device.interface_type}
                    </span>
                </td>
                <td class="text-center">${statusBadge}</td>
                <td class="hotspot-user" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
                <td class="hotspot-traffic" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    return html;
}

function renderConnectedDevices(connectedDevices) {
    if (!connectedDevices || connectedDevices.length === 0) {
        return `
            <div class="row mt-3">
                <div class="col-md-12">
                    <h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-secondary">0</span></h6>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> No devices currently connected to this ONU
                    </div>
                </div>
            </div>
        `;
    }

    let html = '<div class="row mt-3"><div class="col-md-12">';
    html += `<h6><i class="bi bi-hdd-network"></i> Connected Devices <span class="badge bg-primary">${connectedDevices.length}</span></h6>`;

    html += `
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover" id="connected-devices-table">
                <thead class="table-light">
                    <tr>
                        <th width="4%" class="text-center">#</th>
                        <th width="18%"><i class="bi bi-pc-display"></i> Device Name</th>
                        <th width="12%"><i class="bi bi-hdd-network"></i> IP Address</th>
                        <th width="15%"><i class="bi bi-ethernet"></i> MAC Address</th>
                        <th width="10%" class="text-center"><i class="bi bi-wifi"></i> Connection</th>
                        <th width="10%" class="text-center"><i class="bi bi-check-circle"></i> Status</th>
                        <th width="13%"><i class="bi bi-person-badge"></i> Hotspot User</th>
                        <th width="18%"><i class="bi bi-speedometer2"></i> Traffic (RX / TX)</th>
                    </tr>
                </thead>
                <tbody>
    `;

    connectedDevices.forEach((device, index) => {
        // Determine connection type icon
        let connectionIcon = 'bi-ethernet';
        let connectionBadge = 'bg-secondary';
        if (device.interface_type === 'WiFi') {
            connectionIcon = 'bi-wifi';
            connectionBadge = 'bg-info';
        } else if (device.interface_type === 'Ethernet') {
            connectionIcon = 'bi-ethernet';
            connectionBadge = 'bg-success';
        }

        // Determine status badge
        const statusBadge = device.active ?
            '<span class="badge online">Active</span>' :
            '<span class="badge offline">Inactive</span>';

        // Display vendor name if available, otherwise use hostname
        const displayName = device.vendor || device.hostname || 'Unknown Device';

        html += `
            <tr data-mac="${device.mac_address}">
                <td class="text-center">${index + 1}</td>
                <td>
                    <i class="bi bi-pc-display text-primary"></i> <strong>${displayName}</strong>
                    ${device.hostname && device.vendor && device.hostname !== device.vendor ? `<br><small class="text-muted">Hostname: ${device.hostname}</small>` : ''}
                </td>
                <td>${makeIPClickable(device.ip_address)}</td>
                <td><code>${device.mac_address}</code></td>
                <td class="text-center">
                    <span class="badge ${connectionBadge}">
                        <i class="${connectionIcon}"></i> ${device.interface_type}
                    </span>
                </td>
                <td class="text-center">${statusBadge}</td>
                <td class="hotspot-user" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
                <td class="hotspot-traffic" data-mac="${device.mac_address}">
                    <span class="text-muted">-</span>
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    html += '</div></div>';
    return html;
}

function togglePassword() {
    const hidden = document.getElementById('wifi-pass-hidden');
    const shown = document.getElementById('wifi-pass-shown');
    const icon = document.getElementById('toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function toggleAdminPassword() {
    const hidden = document.getElementById('admin-pass-hidden');
    const shown = document.getElementById('admin-pass-shown');
    const icon = document.getElementById('admin-toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function toggleTelecomPassword() {
    const hidden = document.getElementById('telecom-pass-hidden');
    const shown = document.getElementById('telecom-pass-shown');
    const icon = document.getElementById('telecom-toggle-icon');

    if (hidden.style.display === 'none') {
        hidden.style.display = 'inline';
        shown.style.display = 'none';
        icon.className = 'bi bi-eye';
    } else {
        hidden.style.display = 'none';
        shown.style.display = 'inline';
        icon.className = 'bi bi-eye-slash';
    }
}

function summonDevice() {
    document.getElementById('summon-device-id').textContent = deviceId;
    const modal = new bootstrap.Modal(document.getElementById('summonModal'), {
        backdrop: false
    });
    modal.show();
}

// Summon device specifically to get admin credentials
// Uses GenieACS task to fetch VirtualParameters (superAdmin, superPassword)
async function summonForAdminCredentials() {
    const btn = document.getElementById('get-credentials-btn');
    const statusDiv = document.getElementById('credentials-status');
    const statusText = document.getElementById('credentials-status-text');

    // Disable button and show status
    if (btn) btn.disabled = true;
    if (statusDiv) statusDiv.style.display = 'block';
    if (statusText) statusText.textContent = 'Summoning device...';

    // Summon device and request VirtualParameters for admin credentials
    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: deviceId })
    });

    if (result && result.success) {
        // Single toast notification with longer duration (5 seconds)
        showToast('Device summon berhasil, mengambil credentials...', 'success', 5000);

        // Show countdown in status div only (not in toast)
        let countdown = 10;
        const countdownInterval = setInterval(() => {
            countdown--;
            if (countdown > 0) {
                if (statusText) statusText.textContent = `Menunggu credentials dari device (${countdown}s)...`;
            }
        }, 1000);

        setTimeout(() => {
            clearInterval(countdownInterval);
            if (statusText) statusText.textContent = 'Refreshing device data...';

            // Save scroll position before refresh
            const currentScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
            console.log('[GET-CREDENTIALS] Saving scroll position:', currentScrollPosition);

            // Store in sessionStorage to persist across reload
            sessionStorage.setItem('credentialsScrollPosition', currentScrollPosition);

            // Reload device detail
            loadDeviceDetail();
        }, 10000);
    } else {
        // Hide status and show error (longer duration for error messages)
        if (statusDiv) statusDiv.style.display = 'none';
        if (btn) btn.disabled = false;
        showToast(result.message || 'Gagal summon device', 'danger', 5000);
    }
}

async function confirmSummon() {
    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('summonModal'));
    modal.hide();

    showLoading();

    const result = await fetchAPI('/api/summon-device.php', {
        method: 'POST',
        body: JSON.stringify({ device_id: deviceId })
    });

    hideLoading();

    if (result && result.success) {
        showToast('🚀 Device summon berhasil! Menunggu device response...', 'success');

        // Wait longer for device to respond and GenieACS to fetch all parameters
        // This is especially important for admin credentials (VirtualParameters)
        let countdown = 15;
        const countdownInterval = setInterval(() => {
            countdown--;
            if (countdown > 0) {
                showToast(`⏳ Auto-refresh dalam ${countdown} detik...`, 'info');
            }
        }, 1000);

        setTimeout(() => {
            clearInterval(countdownInterval);
            showToast('🔄 Refreshing device data...', 'info');
            loadDeviceDetail();
        }, 15000);
    } else {
        showToast(result.message || 'Gagal summon device', 'danger');
    }
}

function openEditWiFiModal(deviceId, currentSsid, currentPassword) {
    // Set form values
    document.getElementById('edit-device-id').value = deviceId;
    document.getElementById('edit-wifi-ssid').value = currentSsid;
    document.getElementById('edit-wifi-password').value = currentPassword;
    document.getElementById('edit-wlan-index').value = '1'; // Default to WLAN 1

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editWiFiModal'), {
        backdrop: false
    });
    modal.show();
}

function toggleEditPassword() {
    const passwordField = document.getElementById('edit-wifi-password');
    const icon = document.getElementById('edit-toggle-icon');

    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        passwordField.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function togglePasswordField() {
    const securityMode = document.getElementById('edit-security-mode').value;
    const passwordFieldGroup = document.getElementById('password-field-group');
    const passwordField = document.getElementById('edit-wifi-password');

    if (securityMode === 'None') {
        // Hide password field for Open network
        passwordFieldGroup.style.display = 'none';
        passwordField.removeAttribute('required');
        passwordField.value = ''; // Clear password value
    } else {
        // Show password field for secured network
        passwordFieldGroup.style.display = 'block';
        passwordField.setAttribute('required', 'required');
    }
}

async function confirmUpdateWiFi() {
    const form = document.getElementById('editWiFiForm');

    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Get form values
    const deviceId = document.getElementById('edit-device-id').value;
    const wifiSsid = document.getElementById('edit-wifi-ssid').value.trim();
    const securityMode = document.getElementById('edit-security-mode').value;
    const wifiPassword = document.getElementById('edit-wifi-password').value;
    const wlanIndex = document.getElementById('edit-wlan-index').value;

    // Validate SSID length
    if (wifiSsid.length < 1 || wifiSsid.length > 32) {
        showToast('WiFi SSID harus antara 1-32 karakter', 'danger');
        return;
    }

    // Validate password length only if security mode is not Open
    if (securityMode !== 'None') {
        if (wifiPassword.length < 8 || wifiPassword.length > 63) {
            showToast('WiFi Password harus antara 8-63 karakter', 'danger');
            return;
        }
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editWiFiModal'));
    modal.hide();

    // Show loading
    showLoading('Updating WiFi configuration...');

    try {
        const requestData = {
            device_id: deviceId,
            wifi_ssid: wifiSsid,
            security_mode: securityMode,
            wlan_index: parseInt(wlanIndex)
        };

        // Only include password if security mode is not Open
        if (securityMode !== 'None') {
            requestData.wifi_password = wifiPassword;
        }

        const result = await fetchAPI('/api/update-wifi-config.php', {
            method: 'POST',
            body: JSON.stringify(requestData)
        });

        hideLoading();

        if (result && result.success) {
            showToast(result.message || 'WiFi configuration updated successfully!', 'success');

            // Reload device detail after a short delay
            setTimeout(() => {
                loadDeviceDetail();
            }, 2000);
        } else {
            const errorMessage = result && result.message ? result.message : 'Failed to update WiFi configuration';
            showToast(errorMessage, 'danger');
            if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
                console.error('WiFi update failed:', result);
            }
        }
    } catch (error) {
        hideLoading();
        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
            console.error('WiFi update error:', error);
        }
        showToast('Connection error: ' + error.message, 'danger');
    }
}

// Global variable to store WAN data for delete confirmation
let currentWANDelete = null;

// WAN Modal Functions
function openEditWANModal(wanData) {
    // Populate form
    document.getElementById('edit-wan-device-id').value = deviceId;
    document.getElementById('edit-wan-connection-index').value = wanData.connection_index || '';
    document.getElementById('edit-wan-connection-type').value = wanData.type === 'PPPoE' ? 'ppp' : 'ip';
    document.getElementById('edit-wan-name').value = wanData.name || '';
    document.getElementById('edit-wan-enable').value = wanData.status === 'Connected' ? 'true' : 'false';

    // Show/hide PPPoE fields
    const isPPPoE = wanData.type === 'PPPoE';
    document.getElementById('edit-wan-username-group').style.display = isPPPoE ? 'block' : 'none';
    document.getElementById('edit-wan-password-group').style.display = isPPPoE ? 'block' : 'none';

    if (isPPPoE) {
        document.getElementById('edit-wan-username').value = wanData.username || '';
        document.getElementById('edit-wan-password').value = '';  // Don't prefill password
    }

    // NATEnabled may not be available in all WAN data
    if (wanData.nat_enabled !== undefined) {
        document.getElementById('edit-wan-nat').value = wanData.nat_enabled ? 'true' : 'false';
    }

    // Extract VLAN from name
    const vlanMatch = wanData.name.match(/VID[_-]?(\d+)/i);
    if (vlanMatch) {
        document.getElementById('edit-wan-vlan').value = vlanMatch[1];
    }

    // Check if TR069
    const isTR069 = (wanData.service_list && (wanData.service_list.toUpperCase().includes('TR069') || wanData.service_list.toUpperCase().includes('CWMP'))) ||
                    (wanData.name && (wanData.name.toUpperCase().includes('TR069') || wanData.name.toUpperCase().includes('CWMP')));

    document.getElementById('tr069-warning').style.display = isTR069 ? 'block' : 'none';

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editWANModal'), { backdrop: false });
    modal.show();
}

async function confirmUpdateWAN() {
    const connectionIndex = document.getElementById('edit-wan-connection-index').value;
    const connectionType = document.getElementById('edit-wan-connection-type').value;
    const enable = document.getElementById('edit-wan-enable').value === 'true';
    const username = document.getElementById('edit-wan-username').value.trim();
    const password = document.getElementById('edit-wan-password').value;
    const natEnabled = document.getElementById('edit-wan-nat').value === 'true';
    const vlanId = document.getElementById('edit-wan-vlan').value;

    const parameters = {
        Enable: enable,
        NATEnabled: natEnabled
    };

    if (connectionType === 'ppp' && username) {
        parameters.Username = username;
        if (password) {
            parameters.Password = password;
        }
    }

    if (vlanId) {
        parameters['X_CT-COM_VLANID'] = parseInt(vlanId);
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editWANModal'));
    modal.hide();

    showLoading('Updating WAN configuration...');

    const result = await fetchAPI('/api/update-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: parseInt(connectionIndex),
            connection_type: connectionType,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN configuration updated successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to update WAN configuration', 'danger');
    }
}

function openAddWANModal() {
    // Reset form
    document.getElementById('addWANForm').reset();
    document.getElementById('add-wan-username-group').style.display = 'none';
    document.getElementById('add-wan-password-group').style.display = 'none';
    document.getElementById('add-wan-service-custom').style.display = 'none';

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('addWANModal'), { backdrop: false });
    modal.show();
}

function toggleAddWANFields() {
    const connectionType = document.getElementById('add-wan-type').value;
    const usernameGroup = document.getElementById('add-wan-username-group');
    const passwordGroup = document.getElementById('add-wan-password-group');

    if (connectionType === 'ppp') {
        usernameGroup.style.display = 'block';
        passwordGroup.style.display = 'block';
    } else {
        usernameGroup.style.display = 'none';
        passwordGroup.style.display = 'none';
    }
}

async function confirmAddWAN() {
    const form = document.getElementById('addWANForm');

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const connectionIndex = parseInt(document.getElementById('add-wan-index').value);
    const connectionType = document.getElementById('add-wan-type').value;
    const connectionName = document.getElementById('add-wan-name').value.trim();
    const username = document.getElementById('add-wan-username').value.trim();
    const password = document.getElementById('add-wan-password').value;
    const serviceList = document.getElementById('add-wan-service').value;
    const vlanId = parseInt(document.getElementById('add-wan-vlan').value);
    const natEnabled = document.getElementById('add-wan-nat').value === 'true';

    // Build parameters
    const parameters = {
        Enable: true,
        ConnectionType: connectionType === 'ppp' ? 'IP_Routed' : 'IP_Routed',
        NATEnabled: natEnabled,
        'X_CT-COM_VLANID': vlanId,
        'X_CT-COM_ServiceList': serviceList === 'CUSTOM' ? document.getElementById('add-wan-service-custom').value : serviceList
    };

    if (connectionType === 'ppp') {
        if (!username || !password) {
            showToast('Username and password are required for PPPoE connections', 'danger');
            return;
        }
        parameters.Username = username;
        parameters.Password = password;
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('addWANModal'));
    modal.hide();

    showLoading('Creating WAN connection...');

    const result = await fetchAPI('/api/add-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: connectionIndex,
            connection_type: connectionType,
            name: connectionName,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN connection created successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to create WAN connection', 'danger');
    }
}

function openDeleteWANModal(wanData) {
    currentWANDelete = wanData;

    // Check if TR069
    const isTR069 = (wanData.service_list && (wanData.service_list.toUpperCase().includes('TR069') || wanData.service_list.toUpperCase().includes('CWMP'))) ||
                    (wanData.name && (wanData.name.toUpperCase().includes('TR069') || wanData.name.toUpperCase().includes('CWMP')));

    if (isTR069) {
        document.getElementById('delete-wan-normal-warning').style.display = 'none';
        document.getElementById('delete-wan-tr069-warning').style.display = 'block';
        document.getElementById('delete-wan-tr069-name').textContent = wanData.name;
        document.getElementById('delete-wan-tr069-service').textContent = wanData.service_list || 'N/A';
        document.getElementById('delete-wan-tr069-confirm').checked = false;
    } else {
        document.getElementById('delete-wan-normal-warning').style.display = 'block';
        document.getElementById('delete-wan-tr069-warning').style.display = 'none';
        document.getElementById('delete-wan-name').textContent = wanData.name;
        document.getElementById('delete-wan-service').textContent = wanData.service_list || 'N/A';
    }

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('deleteWANModal'), { backdrop: false });
    modal.show();
}

async function confirmDeleteWAN() {
    if (!currentWANDelete) return;

    // Check if TR069 and needs confirmation
    const isTR069 = (currentWANDelete.service_list && (currentWANDelete.service_list.toUpperCase().includes('TR069') || currentWANDelete.service_list.toUpperCase().includes('CWMP'))) ||
                    (currentWANDelete.name && (currentWANDelete.name.toUpperCase().includes('TR069') || currentWANDelete.name.toUpperCase().includes('CWMP')));

    if (isTR069 && !document.getElementById('delete-wan-tr069-confirm').checked) {
        showToast('Please confirm that you understand the risks before deleting TR069 connection', 'warning');
        return;
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteWANModal'));
    modal.hide();

    showLoading('Deleting WAN connection...');

    const result = await fetchAPI('/api/delete-wan-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            connection_index: currentWANDelete.connection_index,
            connection_type: currentWANDelete.type === 'PPPoE' ? 'ppp' : 'ip',
            connection_name: currentWANDelete.name,
            service_list: currentWANDelete.service_list || '',
            confirm_tr069_delete: isTR069
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'WAN connection deleted successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else if (result && result.requires_confirmation) {
        showToast('TR069 connection deletion blocked - please confirm deletion', 'warning');
    } else {
        showToast(result.message || 'Failed to delete WAN connection', 'danger');
    }

    currentWANDelete = null;
}

// DHCP Modal Functions
function openEditDHCPModal(dhcpData) {
    // Populate form
    const dhcpEnabled = dhcpData.enabled === true || dhcpData.enabled === 'true';
    document.getElementById('edit-dhcp-enable').value = dhcpEnabled ? 'true' : 'false';
    document.getElementById('edit-dhcp-min-address').value = dhcpData.min_address || '';
    document.getElementById('edit-dhcp-max-address').value = dhcpData.max_address || '';
    document.getElementById('edit-dhcp-subnet-mask').value = dhcpData.subnet_mask || '';
    document.getElementById('edit-dhcp-gateway').value = dhcpData.gateway || '';
    document.getElementById('edit-dhcp-dns').value = dhcpData.dns_servers || '';
    document.getElementById('edit-dhcp-lease-time').value = dhcpData.lease_time || '';

    // Show/hide fields
    toggleDHCPFields();

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('editDHCPModal'), { backdrop: false });
    modal.show();
}

function toggleDHCPFields() {
    const dhcpEnabled = document.getElementById('edit-dhcp-enable').value === 'true';
    document.getElementById('dhcp-config-fields').style.display = dhcpEnabled ? 'block' : 'none';
}

async function confirmUpdateDHCP() {
    const dhcpEnabled = document.getElementById('edit-dhcp-enable').value === 'true';

    const parameters = {
        DHCPServerEnable: dhcpEnabled
    };

    if (dhcpEnabled) {
        parameters.MinAddress = document.getElementById('edit-dhcp-min-address').value.trim();
        parameters.MaxAddress = document.getElementById('edit-dhcp-max-address').value.trim();
        parameters.SubnetMask = document.getElementById('edit-dhcp-subnet-mask').value.trim();
        parameters.IPRouters = document.getElementById('edit-dhcp-gateway').value.trim();
        parameters.DNSServers = document.getElementById('edit-dhcp-dns').value.trim();

        const leaseTime = document.getElementById('edit-dhcp-lease-time').value.trim();
        if (leaseTime) {
            parameters.DHCPLeaseTime = parseInt(leaseTime);
        }
    }

    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('editDHCPModal'));
    modal.hide();

    showLoading('Updating DHCP configuration...');

    const result = await fetchAPI('/api/update-dhcp-config.php', {
        method: 'POST',
        body: JSON.stringify({
            device_id: deviceId,
            parameters: parameters
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(result.message || 'DHCP configuration updated successfully!', 'success');
        setTimeout(loadDeviceDetail, 2000);
    } else {
        showToast(result.message || 'Failed to update DHCP configuration', 'danger');
    }
}

// Tags Management Functions
function updateTagsBadge(tags) {
    const badgeElement = document.getElementById('device-tags-badge');
    if (tags && tags.length > 0) {
        const tagBadges = tags.map(tag => `<span class="badge bg-info ms-1">${tag}</span>`).join('');
        badgeElement.innerHTML = tagBadges;
    } else {
        badgeElement.innerHTML = '';
    }
}

function showAddTagModal() {
    const modal = new bootstrap.Modal(document.getElementById('addTagModal'));
    document.getElementById('addTagName').value = '';
    modal.show();
}

async function addTagToDevice() {
    const tagName = document.getElementById('addTagName').value.trim();

    if (!tagName) {
        showToast('Please enter a tag name', 'warning');
        return;
    }

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'add',
            device_ids: [deviceId],
            tag: tagName
        })
    });

    if (result && result.success) {
        showToast(`Tag "${tagName}" added successfully`, 'success');
        bootstrap.Modal.getInstance(document.getElementById('addTagModal')).hide();
        loadDeviceDetail(); // Reload to show new tag
    } else {
        showToast(result.message || 'Failed to add tag', 'danger');
    }
}

function showRemoveTagModal() {
    fetchAPI('/api/get-device-detail.php?device_id=' + encodeURIComponent(deviceId))
        .then(result => {
            if (result && result.success && result.device.tags && result.device.tags.length > 0) {
                const tagsList = document.getElementById('deviceTagsList');
                tagsList.innerHTML = result.device.tags.map(tag =>
                    `<button class="btn btn-sm btn-outline-warning me-2 mb-2" onclick="removeTagFromDevice('${tag}')">
                        <i class="bi bi-x-circle"></i> ${tag}
                    </button>`
                ).join('');

                document.getElementById('removeTagContent').style.display = 'block';
                document.getElementById('noTagsMessage').style.display = 'none';
            } else {
                document.getElementById('removeTagContent').style.display = 'none';
                document.getElementById('noTagsMessage').style.display = 'block';
            }

            const modal = new bootstrap.Modal(document.getElementById('removeTagModal'));
            modal.show();
        });
}

async function removeTagFromDevice(tagName) {
    if (!confirm(`Remove tag "${tagName}"?`)) {
        return;
    }

    const result = await fetchAPI('/api/bulk-tag.php', {
        method: 'POST',
        body: JSON.stringify({
            action: 'remove',
            device_ids: [deviceId],
            tag: tagName
        })
    });

    if (result && result.success) {
        showToast(`Tag "${tagName}" removed successfully`, 'success');
        bootstrap.Modal.getInstance(document.getElementById('removeTagModal')).hide();
        loadDeviceDetail(); // Reload to update tags
    } else {
        showToast(result.message || 'Failed to remove tag', 'danger');
    }
}

// Hotspot Traffic Management
let hotspotTrafficInterval = null;
let previousBytesData = {}; // Store previous bytes for rate calculation
let hotspotMonitoringActive = false; // Track if monitoring is active
let hotspotFetchInProgress = false; // Prevent concurrent requests
let hotspotAbortController = null; // AbortController to cancel pending requests

async function fetchHotspotTraffic() {
    // Prevent concurrent requests - check FIRST before any cancellation
    if (hotspotFetchInProgress) {
        console.debug('[HOTSPOT] Fetch already in progress, skipping...');
        return;
    }

    // Get all MAC addresses from connected devices table
    const macElements = document.querySelectorAll('.hotspot-user[data-mac]');
    if (macElements.length === 0) {
        console.debug('[HOTSPOT] No MAC addresses found in table');
        return;
    }

    const macAddresses = Array.from(macElements).map(el => el.dataset.mac);
    console.log(`[HOTSPOT] Starting fetch for ${macAddresses.length} devices`);

    // Mark as in progress BEFORE creating AbortController
    hotspotFetchInProgress = true;

    // Create new AbortController for this request only
    hotspotAbortController = new AbortController();

    // Update status to show fetching (but keep it subtle)
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) {
        statusEl.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> <small class="text-muted">Updating...</small>';
    }

    try {
        const result = await fetchAPI('/api/get-hotspot-traffic.php', {
            method: 'POST',
            body: JSON.stringify({ mac_addresses: macAddresses }),
            timeout: 15000, // 15 second timeout (MikroTik may be slow)
            signal: hotspotAbortController.signal
        });

        console.log('[HOTSPOT] Fetch completed successfully', {
            from_cache: result.from_cache,
            cache_age: result.cache_age,
            total_matched: result.total_matched
        });

        if (result && result.success && result.data) {
            updateHotspotTrafficDisplay(result.data, result.timestamp);

            // Update status with last update time and cache info
            if (statusEl) {
                const now = new Date();
                const timeStr = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                const cacheInfo = result.from_cache ? ` <small class="text-info">[cached ${result.cache_age}s]</small>` : '';
                statusEl.innerHTML = `<span class="text-success">✓ ${timeStr}</span>${cacheInfo}`;
            }
        } else if (result && result.error === 'timeout') {
            // Request timeout (15s)
            console.warn('[HOTSPOT] Request timeout after 15s');
            const errorMsg = 'Request timeout. Retrying in 5s...';
            updateHotspotTrafficError(errorMsg);

            if (statusEl) {
                statusEl.innerHTML = '<i class="bi bi-clock text-warning"></i> <small>Timeout - retrying...</small>';
            }
        } else {
            // MikroTik offline or other error
            console.warn('[HOTSPOT] Error response:', result);
            const errorMsg = (result && result.message) ? result.message : 'MikroTik Offline';
            updateHotspotTrafficError(errorMsg);

            if (statusEl) {
                statusEl.innerHTML = '<i class="bi bi-exclamation-triangle text-warning"></i> ' + errorMsg;
            }
        }
    } catch (error) {
        // Check if it was aborted
        if (error.name === 'AbortError') {
            console.warn('[HOTSPOT] Request was aborted (likely due to page refresh)');
        } else {
            console.error('[HOTSPOT] Fetch failed:', error.message);
        }

        // Don't show error to user on every fetch, just keep last known data
        // Only update status, not the actual traffic data
        if (statusEl) {
            statusEl.innerHTML = '<i class="bi bi-x-circle text-danger"></i> <small>Connection error - showing last known data</small>';
        }
    } finally {
        // Always reset the flag when done (CRITICAL for preventing stuck state)
        console.debug('[HOTSPOT] Resetting fetch flag');
        hotspotFetchInProgress = false;
        hotspotAbortController = null;
    }
}

function updateHotspotTrafficDisplay(data, timestamp) {
    Object.keys(data).forEach(mac => {
        const userInfo = data[mac];
        const userCell = document.querySelector(`.hotspot-user[data-mac="${mac}"]`);
        const trafficCell = document.querySelector(`.hotspot-traffic[data-mac="${mac}"]`);

        if (!userCell || !trafficCell) return;

        // Update username
        if (userInfo.found) {
            userCell.innerHTML = `<strong>${userInfo.username}</strong>`;

            // Calculate RX/TX rate from bytes difference
            const currentBytesIn = userInfo.bytes_in || 0;
            const currentBytesOut = userInfo.bytes_out || 0;

            let rxRate = 0;
            let txRate = 0;

            if (previousBytesData[mac]) {
                const timeDiff = timestamp - previousBytesData[mac].timestamp;
                if (timeDiff > 0) {
                    const bytesDiffIn = Math.max(0, currentBytesIn - previousBytesData[mac].bytes_in);
                    const bytesDiffOut = Math.max(0, currentBytesOut - previousBytesData[mac].bytes_out);

                    // Calculate rate in bytes per second, then convert to bits per second
                    const rawRxRate = (bytesDiffIn / timeDiff) * 8; // bits per second
                    const rawTxRate = (bytesDiffOut / timeDiff) * 8; // bits per second

                    // Apply simple smoothing: 30% new value, 70% old value (reduces jitter)
                    const smoothingFactor = 0.3;
                    if (previousBytesData[mac].rxRate !== undefined) {
                        rxRate = (smoothingFactor * rawRxRate) + ((1 - smoothingFactor) * previousBytesData[mac].rxRate);
                        txRate = (smoothingFactor * rawTxRate) + ((1 - smoothingFactor) * previousBytesData[mac].txRate);
                    } else {
                        rxRate = rawRxRate;
                        txRate = rawTxRate;
                    }
                } else {
                    // Time diff is 0, use previous rate if available
                    rxRate = previousBytesData[mac].rxRate || 0;
                    txRate = previousBytesData[mac].txRate || 0;
                }
            }

            // Store current bytes and calculated rate for next iteration
            previousBytesData[mac] = {
                bytes_in: currentBytesIn,
                bytes_out: currentBytesOut,
                timestamp: timestamp,
                rxRate: rxRate,
                txRate: txRate
            };

            // Format and display traffic
            const rxFormatted = formatBitrate(rxRate);
            const txFormatted = formatBitrate(txRate);

            // Debug log for first device only (to avoid console spam)
            if (mac === Object.keys(data)[0]) {
                console.debug('[HOTSPOT] Traffic update:', {
                    mac: mac,
                    username: userInfo.username,
                    rx: rxFormatted,
                    tx: txFormatted,
                    bytes_in: currentBytesIn,
                    bytes_out: currentBytesOut
                });
            }

            trafficCell.innerHTML = `<span class="text-success">↓ ${rxFormatted}</span> / <span class="text-danger">↑ ${txFormatted}</span>`;
        } else {
            userCell.innerHTML = '<span class="text-muted">N/A</span>';
            trafficCell.innerHTML = '<span class="text-muted">N/A</span>';
        }

        // Also save to savedHotspotData for persistence across refreshes
        if (!savedHotspotData[mac]) savedHotspotData[mac] = {};
        savedHotspotData[mac].userHtml = userCell.innerHTML;
        savedHotspotData[mac].trafficHtml = trafficCell.innerHTML;
    });
}

function updateHotspotTrafficError(message) {
    // Only show error in status indicator, keep existing data in cells (don't overwrite)
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) {
        statusEl.innerHTML = `<span class="text-danger">⚠ ${message}</span>`;
    }

    // DON'T clear existing data - let users see last known good data
    // This prevents the annoying "all N/A" experience when timeout occurs
    console.warn('[HOTSPOT] Error:', message, '- Keeping last known data visible');
}

function formatBitrate(bps) {
    if (bps === 0 || isNaN(bps)) return '0 bps';

    const units = ['bps', 'Kbps', 'Mbps', 'Gbps'];
    let value = Math.abs(bps);
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return value.toFixed(2) + ' ' + units[unitIndex];
}

function startHotspotTrafficMonitoring() {
    // Check if table exists (tab must be active)
    const table = document.getElementById('connected-devices-table');
    if (!table) {
        console.debug('[HOTSPOT] Table not found, skipping monitoring start');
        return;
    }

    // Update status
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) statusEl.innerHTML = '<i class="bi bi-arrow-repeat spin"></i> Starting...';

    // Clear existing interval if any
    if (hotspotTrafficInterval) {
        clearInterval(hotspotTrafficInterval);
    }

    // Set monitoring as active
    hotspotMonitoringActive = true;

    console.log('[HOTSPOT] Monitoring started (auto-mode)');

    // Fetch immediately
    fetchHotspotTraffic();

    // Then fetch every 5 seconds - balanced between smooth updates and stability
    // 5s interval with 15s timeout allows enough time for slow MikroTik response
    hotspotTrafficInterval = setInterval(fetchHotspotTraffic, 5000);
}

function stopHotspotTrafficMonitoring() {
    console.log('[HOTSPOT] Stopping monitoring...');

    // Clear status
    const statusEl = document.getElementById('hotspot-status');
    if (statusEl) statusEl.innerHTML = '<i class="bi bi-router text-muted"></i> <span class="text-muted">Monitoring stopped</span>';

    // Clear interval first
    if (hotspotTrafficInterval) {
        clearInterval(hotspotTrafficInterval);
        hotspotTrafficInterval = null;
    }

    // Abort any pending request
    if (hotspotAbortController) {
        console.log('[HOTSPOT] Aborting pending request...');
        hotspotAbortController.abort();
        hotspotAbortController = null;
    }

    // Reset flags
    hotspotFetchInProgress = false;
    previousBytesData = {};

    // Set monitoring as inactive
    hotspotMonitoringActive = false;

    console.log('[HOTSPOT] Monitoring stopped successfully');
}

document.addEventListener('DOMContentLoaded', function() {
    loadDeviceDetail(); // Initial load (manual, scroll to top)
    // Auto refresh every 30 seconds (preserve scroll position)
    setInterval(() => loadDeviceDetail(true), 30000);

    // Auto-start/stop hotspot monitoring based on Connected Devices tab visibility
    const allTabs = document.querySelectorAll('[data-bs-toggle="tab"]');
    allTabs.forEach(tab => {
        tab.addEventListener('shown.bs.tab', function(event) {
            if (tab.id === 'devices-tab') {
                // Start monitoring when Connected Devices tab is opened
                console.log('[TAB] Connected Devices tab opened - starting hotspot monitoring...');
                // Small delay to ensure table is rendered
                setTimeout(() => {
                    startHotspotTrafficMonitoring();
                }, 200);
            } else {
                // Stop monitoring when switching to other tabs
                if (hotspotTrafficInterval) {
                    console.log('[TAB] Switched away from Connected Devices - stopping hotspot monitoring...');
                    stopHotspotTrafficMonitoring();
                }
            }
        });
    });
});
