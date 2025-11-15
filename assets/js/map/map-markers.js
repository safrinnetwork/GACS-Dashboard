/**
 * Map Markers & Popups Module
 *
 * This module handles marker creation, icon generation, and popup content
 * for all map items (Server, OLT, ODC, ODP, ONU, MikroTik).
 *
 * Functions:
 * - getItemIcon() - Returns icon HTML based on item type and status
 * - getItemPopupContent() - Generates popup HTML for map markers
 * - loadONUDeviceDetails() - Async function to load ONU device details
 * - loadODPPortDetails() - Async function to load ODP port details
 * - loadODCPortDetails() - Async function to load ODC port details
 */

function getItemIcon(type, status) {
    const icons = {
        server: { icon: 'bi-hdd-network', color: '#4e73df', bg: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)' },
        olt: { icon: 'bi-broadcast', color: '#1cc88a', bg: 'linear-gradient(135deg, #f093fb 0%, #f5576c 100%)' },
        odc: { icon: 'bi-box-seam', color: '#36b9cc', bg: 'linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)' },
        odp: { icon: 'bi-box', color: '#f6c23e', bg: 'linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%)' },
        onu: { icon: 'bi-router', color: '#e74a3b', bg: 'linear-gradient(135deg, #a8edea 0%, #fed6e3 100%)' },
        mikrotik: { icon: 'bi-diagram-3', color: '#5a5c69', bg: 'linear-gradient(135deg, #e0c3fc 0%, #8ec5fc 100%)' }
    };

    const config = icons[type] || { icon: 'bi-circle-fill', color: '#858796', bg: '#858796' };
    const statusColor = status === 'online' ? '#10b981' : status === 'offline' ? '#ef4444' : '#6b7280';
    const pulseClass = status === 'online' ? 'pulse-online' : '';

    return L.divIcon({
        html: `
            <div class="custom-marker ${pulseClass}" style="position: relative;">
                <div class="marker-icon" style="
                    background: ${config.bg};
                    width: 40px;
                    height: 40px;
                    border-radius: 50% 50% 50% 0;
                    transform: rotate(-45deg);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    box-shadow: 0 4px 8px rgba(0,0,0,0.3);
                    border: 3px solid ${statusColor};
                    transition: all 0.3s ease;
                ">
                    <i class="bi ${config.icon}" style="
                        font-size: 16px;
                        color: white;
                        transform: rotate(45deg);
                        text-shadow: 0 1px 2px rgba(0,0,0,0.3);
                    "></i>
                </div>
                ${status === 'online' ? `
                    <div class="status-indicator" style="
                        position: absolute;
                        top: -2px;
                        right: -2px;
                        width: 12px;
                        height: 12px;
                        background: ${statusColor};
                        border: 2px solid white;
                        border-radius: 50%;
                        box-shadow: 0 2px 4px rgba(0,0,0,0.3);
                        animation: pulse 2s infinite;
                    "></div>
                ` : ''}
            </div>
        `,
        className: '',
        iconSize: [40, 40],
        iconAnchor: [20, 40]
    });
}

function getItemPopupContent(item) {
    // For ONU, Server, ODC, and ODP, show status as emoji badge in title
    let statusEmoji = '';
    if (item.item_type === 'onu' || item.item_type === 'server' || item.item_type === 'odc' || item.item_type === 'odp') {
        statusEmoji = item.status === 'online' ? ' 🟢' : item.status === 'offline' ? ' 🔴' : ' ⚪';
    }

    let content = `
        <div style="min-width: 220px;">
            <h6 style="margin-bottom: 4px;"><strong>${item.name}${statusEmoji}</strong></h6>
    `;

    // Don't show Type and Status for ONU, Server, ODC, and ODP (status already in title)
    // For others, show Type and Status normally
    if (item.item_type !== 'onu' && item.item_type !== 'server' && item.item_type !== 'odc' && item.item_type !== 'odp') {
        content += `<p class="mb-1"><small>Type: <strong>${item.item_type.toUpperCase()}</strong></small></p>`;
        content += `<p class="mb-1"><small>Status: <span class="badge ${item.status === 'online' ? 'online' : 'offline'}">${item.status}</span></small></p>`;
    }

    // For Server, show child items management and PON ports
    if (item.item_type === 'server') {
        // Properties already parsed by API, no need to JSON.parse again
        const properties = item.properties || {};
        const config = item.config || {};
        const ponPorts = config.pon_ports || {};

        content += `<hr style="margin: 8px 0;">`;

        // Chain info section (will be loaded asynchronously)
        content += `
            <div id="server-chain-info-${item.id}" style="margin: 8px 0;">
                <div class="text-center">
                    <div class="spinner-border spinner-border-sm" role="status">
                        <span class="visually-hidden">Loading chain info...</span>
                    </div>
                    <small class="d-block text-muted">Loading connections...</small>
                </div>
            </div>
        `;

        // PON ports are now inside OLT Link box (handled in map-server.js)

        content += `
            <hr style="margin: 6px 0;">
            <button class="btn btn-sm btn-info w-100 mb-2" onclick="manageServerLinks(${item.id})">
                <i class="bi bi-gear"></i> Edit Server Links
            </button>
        `;
    }

    // For ODC, show PON calculator info
    if (item.item_type === 'odc' && item.config) {
        const config = item.config;
        content += `<hr style="margin: 6px 0;">`;
        content += `
            <p style="margin: 2px 0;"><small>🔌 PON Port / ${config.server_pon_port || 'N/A'}</small></p>
            <p style="margin: 2px 0;"><small>📊 Jumlah Port / ${config.port_count || 'N/A'}</small></p>
            <p style="margin: 2px 0;"><small>⚡ RX Input / ${config.calculated_power || 'N/A'} dBm</small></p>
            <hr style="margin: 6px 0;">
            <p style="margin: 2px 0;"><small><strong>🔌 Port Power</strong></small></p>
        `;

        // Show output power per port with ODP names (async loading)
        content += `
            <div id="odc-ports-${item.id}" style="max-height: 100px; overflow-y: auto; font-size: 10px; margin-top: 4px;">
                <div class="text-center">
                    <div class="spinner-border spinner-border-sm" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        `;
    }

    // For ODP, show PON calculator info with splitter
    if (item.item_type === 'odp' && item.config) {
        const config = item.config;

        // Prepare parent info and cascading child info
        let parentInfo = 'N/A';
        let cascadingChildName = null;

        // Check if parent is ODC or ODP
        if (item.parent_id) {
            const parentItem = allMapItems.find(i => i.id == item.parent_id);
            if (parentItem) {
                if (parentItem.item_type === 'odc') {
                    // Parent is ODC
                    parentInfo = `${parentItem.name}/${config.odc_port || 'N/A'}`;
                } else if (parentItem.item_type === 'odp') {
                    // Parent is ODP - show parent ODP name and port percentage
                    parentInfo = `${parentItem.name}/${config.parent_odp_port || 'N/A'}`;
                }
            }
        }

        // Find cascading child ODP (if any)
        if (config.use_splitter == 1 && ['20:80', '30:70', '50:50'].includes(config.splitter_ratio)) {
            const ratio = config.splitter_ratio.split(':');
            const usedPort = config.custom_ratio_output_port; // e.g., "20%"
            const cascadingPort = usedPort === `${ratio[0]}%` ? `${ratio[1]}%` : `${ratio[0]}%`; // e.g., "80%"

            // Find child ODP using this cascading port
            const childODP = allMapItems.find(child =>
                child.item_type === 'odp' &&
                child.parent_id == item.id &&
                child.config?.parent_odp_port === cascadingPort
            );

            if (childODP) {
                cascadingChildName = childODP.name;
            }
        }

        // Count available ports
        const portRxPower = config.port_rx_power || {};
        const occupiedPorts = Object.keys(portRxPower).length;
        const totalPorts = parseInt(config.port_count) || 8;
        const availablePorts = totalPorts - occupiedPorts;

        content += `
            <p style="margin: 2px 0;"><small>📦 Parent / ${parentInfo}</small></p>
            <p style="margin: 2px 0;"><small>📊 Port / ${totalPorts} (${availablePorts} Tersedia)</small></p>
        `;

        // Show splitter info (compact format)
        if (config.use_splitter == 1) {
            content += `<p style="margin: 2px 0;"><small><strong>🔌 Splitter Info</strong></small></p>`;
            content += `<p style="margin: 2px 0; padding-left: 8px;"><small>📐 Splitter / ${config.splitter_ratio || 'N/A'}</small></p>`;

            if (config.custom_ratio_output_port && ['20:80', '30:70', '50:50'].includes(config.splitter_ratio)) {
                const ratio = config.splitter_ratio.split(':');
                const usedPort = config.custom_ratio_output_port;
                const cascadingPort = usedPort === `${ratio[0]}%` ? `${ratio[1]}%` : `${ratio[0]}%`;

                content += `<p style="margin: 2px 0; padding-left: 8px;"><small>✅ ${usedPort} / In</small></p>`;
                content += `<p style="margin: 2px 0; padding-left: 8px;"><small>⚡ ${cascadingPort} / ${cascadingChildName || 'Available'}</small></p>`;
            }
        }

        // Show power per port (real RX from ONU or calculated)
        if (config.calculated_power || config.port_rx_power) {
            const defaultPower = parseFloat(config.calculated_power).toFixed(2);
            const portRxPower = config.port_rx_power || {};
            const portSerialNumber = config.port_serial_number || {};
            const portDeviceId = config.port_device_id || {};
            const portStatus = config.port_status || {}; // Get ONU status per port

            content += `
                <p style="margin: 4px 0 2px 0;"><small><strong>🔌 ODP Port Info</strong></small></p>
                <div style="max-height: 120px; overflow-y: auto; font-size: 10px;">
            `;

            for (let i = 1; i <= (config.port_count || 8); i++) {
                let rxPower, badge, serialInfo;

                if (portRxPower[i]) {
                    // Port has ONU connected - show real RX power
                    rxPower = `${portRxPower[i]} dBm`;

                    // Use status from port_status if available, otherwise default to green
                    const onuStatus = portStatus[i] || 'online'; // Default to online if status not available
                    badge = onuStatus === 'online' ? '🟢' : '🔴';

                    // Create clickable link for serial number
                    if (portSerialNumber[i] && portDeviceId[i]) {
                        const deviceLink = `/device-detail.php?id=${encodeURIComponent(portDeviceId[i])}`;
                        serialInfo = ` / <a href="${deviceLink}" target="_blank" style="color: #0d6efd; text-decoration: underline;">${portSerialNumber[i]}</a>`;
                    } else if (portSerialNumber[i]) {
                        serialInfo = ` / ${portSerialNumber[i]}`;
                    } else {
                        serialInfo = '';
                    }
                } else {
                    // Port empty - show calculated power
                    rxPower = `${defaultPower} dBm (calc)`;
                    badge = '⚪';
                    serialInfo = '';
                }

                content += `<div style="padding: 2px 0;">${badge} ${i} / ${rxPower}${serialInfo}</div>`;
            }

            content += `</div>`;
        }
    }

    // For OLT, show output power and attenuation
    if (item.item_type === 'olt' && item.config) {
        const config = item.config;
        content += `<hr style="margin: 8px 0;">`;
        content += `
            <p class="mb-1"><small><strong>⚡ Output Power:</strong> ${config.output_power || '2'} dBm</small></p>
            <p class="mb-1"><small><strong>📉 Attenuation:</strong> ${config.attenuation_db || '0'} dB</small></p>
            <p class="mb-1"><small><strong>🔗 OLT Link:</strong> ${config.olt_link || 'Not set'}</small></p>
        `;
    }

    // For ONU, show loading placeholder (will be filled when popup opens)
    if (item.item_type === 'onu' && item.genieacs_device_id) {
        // Remove the horizontal line and status badge from ONU section
        // Status will be shown in the header instead
        content += `<div id="onu-details-${item.id}"><small><i class="bi bi-arrow-repeat"></i> Loading device info...</small></div>`;
    }

    // Add action buttons (escape name to prevent JavaScript errors)
    const escapedName = item.name.replace(/'/g, "\\'").replace(/"/g, '\\"');
    content += `
        <hr style="margin: 8px 0;">
        <div class="btn-group btn-group-sm w-100">
            <button class="btn btn-sm btn-primary" onclick="editItem(${item.id})">
                <i class="bi bi-pencil"></i> Edit
            </button>
            <button class="btn btn-sm btn-danger" onclick="confirmDeleteItem(${item.id}, '${escapedName}')">
                <i class="bi bi-trash"></i> Delete
            </button>
        </div>
    `;

    content += `</div>`;
    return content;
}

async function loadONUDeviceDetails(item) {
    try {
        console.log('Loading ONU details for:', item);
        console.log('GenieACS Device ID:', item.genieacs_device_id);

        const result = await fetchAPI(`/api/get-device-detail.php?device_id=${encodeURIComponent(item.genieacs_device_id)}`);
        console.log('API result:', result);

        const detailsDiv = document.getElementById(`onu-details-${item.id}`);
        console.log('Details div found:', detailsDiv ? 'yes' : 'no');

        if (result && result.success && detailsDiv) {
            const device = result.device;
            const config = item.config || {};

            // Get parent hierarchy info
            let odpInfo = 'N/A', odcInfo = 'N/A';
            const itemsResult = await fetchAPI('/api/map-get-items.php');
            if (itemsResult && itemsResult.success) {
                const parentODP = itemsResult.items.find(i => i.id == item.parent_id);
                if (parentODP) {
                    odpInfo = parentODP.name;
                    const parentODC = itemsResult.items.find(i => i.id == parentODP.parent_id);
                    if (parentODC) odcInfo = parentODC.name;
                }
            }

            const serialNumberLink = device.serial_number && device.serial_number !== 'N/A'
                ? `<a href="/device-detail.php?id=${encodeURIComponent(item.genieacs_device_id)}" target="_blank" style="color: #0d6efd; text-decoration: underline;">${device.serial_number}</a>`
                : 'N/A';

            // Get connected devices count
            const clientCount = device.connected_devices_count || 0;

            // Extract IP and create link
            const ipAddress = extractIP(device.ip_tr069);
            const ipLink = ipAddress !== 'N/A' && ipAddress !== ''
                ? `<a href="http://${ipAddress}" target="_blank" style="color: #0d6efd; text-decoration: underline;">${ipAddress}</a>`
                : ipAddress;

            detailsDiv.innerHTML = `
                <p style="margin: 2px 0;"><small>🔢 SN : ${serialNumberLink}</small></p>
                <p style="margin: 2px 0;"><small>🔌 IP : ${ipLink}</small></p>
                <p style="margin: 2px 0;"><small>📶 RX : ${device.rx_power} dBm</small></p>
                <p style="margin: 2px 0;"><small>🌡️ Temp : ${device.temperature}°C</small></p>
                <p style="margin: 2px 0;"><small>📡 SSID : ${device.wifi_ssid}</small></p>
                <p style="margin: 2px 0;"><small>🙂 Client : ${clientCount}</small></p>
                <p style="margin: 2px 0;"><small>📍 ODP : ${odpInfo}</small></p>
                <p style="margin: 2px 0;"><small>🔌 ODP Port : ${config.odp_port || 'N/A'}</small></p>
                <p style="margin: 2px 0;"><small>📦 ODC : ${odcInfo}</small></p>
                <p style="margin: 2px 0;"><small>👤 Customer : ${config.customer_name || 'N/A'}</small></p>
            `;
            console.log('ONU details loaded successfully');
        } else if (detailsDiv) {
            console.error('Failed to load device info. Result:', result);
            detailsDiv.innerHTML = '<small class="text-danger">Failed to load device info</small>';
        }
    } catch (error) {
        console.error('Error in loadONUDeviceDetails:', error);
        const detailsDiv = document.getElementById(`onu-details-${item.id}`);
        if (detailsDiv) {
            detailsDiv.innerHTML = `<small class="text-danger">Error: ${error.message}</small>`;
        }
    }
}

async function loadODPPortDetails(item) {
    // Refresh ODP item details to get latest port RX power data
    const result = await fetchAPI(`/api/map-get-item-detail.php?item_id=${item.id}`);

    if (result && result.success && result.item) {
        const updatedItem = result.item;

        // Update marker's stored item data
        const marker = markers[item.id];
        if (marker) {
            marker.itemData = updatedItem;

            // Get the popup and check if it's currently open
            const popup = marker.getPopup();
            const isOpen = popup && popup.isOpen();

            // Update popup content
            marker.setPopupContent(getItemPopupContent(updatedItem));

            // If popup was open, reopen it immediately to maintain visible state
            if (isOpen) {
                marker.openPopup();
            }
        }
    }
}

async function loadODCPortDetails(item) {
    try {
        const portsElement = document.getElementById(`odc-ports-${item.id}`);
        if (!portsElement) return;

        const config = item.config || {};
        const portCount = parseInt(config.port_count) || 4;
        const calculatedPower = parseFloat(config.calculated_power) || 0;
        const powerPerPort = calculatedPower.toFixed(2);

        // Find all ODPs that are children of this ODC
        const odpItems = allMapItems.filter(odpItem =>
            odpItem.item_type === 'odp' &&
            odpItem.parent_id == item.id
        );

        // Create port mapping: ODP port -> ODP name
        const portOdpMap = {};
        odpItems.forEach(odp => {
            if (odp.config && odp.config.odc_port) {
                portOdpMap[odp.config.odc_port] = odp.name;
            }
        });

        // Generate HTML
        let portsHtml = '';
        for (let i = 1; i <= portCount; i++) {
            const odpName = portOdpMap[i];
            if (odpName) {
                portsHtml += `<div style="padding: 2px 0;">${i} / ${powerPerPort} dBm / ${odpName}</div>`;
            } else {
                portsHtml += `<div style="padding: 2px 0;">${i} / ${powerPerPort} dBm</div>`;
            }
        }

        portsElement.innerHTML = portsHtml;
    } catch (error) {
        const portsElement = document.getElementById(`odc-ports-${item.id}`);
        if (portsElement) {
            portsElement.innerHTML = '<div class="text-danger">Error loading ports</div>';
        }
    }
}
