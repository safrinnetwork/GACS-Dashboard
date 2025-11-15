/**
 * Map Items Module - CRUD Operations for Map Items
 *
 * This module handles all item management operations on the network topology map:
 * - Adding new items (Server, OLT, ODC, ODP, ONU) with location pointer
 * - Editing existing items with type-specific form fields
 * - Deleting items with cascade confirmation
 * - Form management and dynamic field generation based on item type
 * - Parent-child relationships and port availability checking
 * - Location pointer management for precise coordinate selection
 *
 * Item Types:
 * - Server: Root node with ISP, MikroTik, OLT links and PON ports
 * - OLT: Optical Line Terminal with PON ports
 * - ODC: Optical Distribution Cabinet with ports
 * - ODP: Optical Distribution Point with splitter configuration
 * - ONU: Customer device linked to GenieACS device ID
 *
 * Dependencies:
 * - Global variables: locationPointer, pointerVisible, map, bootstrap
 * - API endpoints: /api/map-*.php
 * - Helper functions: fetchAPI(), showLoading(), hideLoading(), showToast(), loadMap()
 */

// ============================================================
// ADD ITEM FUNCTIONS
// ============================================================

function showAddItemModal() {
    // Update form with current pointer coordinates if pointer exists
    if (locationPointer) {
        updateFormCoordinates(locationPointer.getLatLng());
    }

    const modal = new bootstrap.Modal(document.getElementById('addItemModal'));
    modal.show();
}

function setLocationPointer(latlng) {
    // Remove existing pointer if any
    if (locationPointer) {
        map.removeLayer(locationPointer);
    }

    // Create custom pointer icon
    const pointerIcon = L.divIcon({
        html: `
            <div style="position: relative;">
                <div style="
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    width: 48px;
                    height: 48px;
                    border-radius: 50% 50% 50% 0;
                    transform: rotate(-45deg);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.5);
                    border: 3px solid #fff;
                    animation: pointer-pulse 1.5s infinite;
                ">
                    <i class="bi bi-geo-alt" style="
                        font-size: 20px;
                        color: white;
                        transform: rotate(45deg);
                    "></i>
                </div>
                <div style="
                    position: absolute;
                    top: -8px;
                    right: -8px;
                    background: #10b981;
                    color: white;
                    padding: 2px 6px;
                    border-radius: 8px;
                    font-size: 10px;
                    font-weight: bold;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                ">POINTER</div>
            </div>
        `,
        className: 'location-pointer',
        iconSize: [48, 48],
        iconAnchor: [24, 48]
    });

    // Create draggable marker
    locationPointer = L.marker(latlng, {
        icon: pointerIcon,
        draggable: true,
        zIndexOffset: 1000 // Keep it on top
    }).addTo(map);

    // Update form coordinates
    updateFormCoordinates(latlng);

    // Update coordinates on drag
    locationPointer.on('dragend', function(e) {
        const newPos = e.target.getLatLng();
        updateFormCoordinates(newPos);
    });

    // Show popup with instructions
    locationPointer.bindPopup(`
        <div style="text-align: center;">
            <strong>📍 Pilih Lokasi</strong><br>
            <small>Klik peta atau drag marker ini<br>untuk memilih lokasi item</small>
        </div>
    `).openPopup();
}

function updateFormCoordinates(latlng) {
    const form = document.querySelector('#form-add-item');
    if (form) {
        form.latitude.value = latlng.lat.toFixed(8);
        form.longitude.value = latlng.lng.toFixed(8);
    }
}

function removeLocationPointer() {
    if (locationPointer) {
        map.removeLayer(locationPointer);
        locationPointer = null;
    }
    pointerVisible = false;
}

// ============================================================
// DYNAMIC FORM GENERATION
// ============================================================

async function updateItemForm(type) {
    const dynamicFields = document.getElementById('dynamic-fields');

    // Show minimal loading indicator
    dynamicFields.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm" role="status"></div></div>';

    switch(type) {
        case 'server':
            // Load netwatch, devices, and items in parallel for faster loading
            const [netwatchResultServer, genieacsDevicesResult, itemsResultServer] = await Promise.all([
                fetchAPI('/api/map-get-netwatch.php'),
                fetchAPI('/api/get-devices.php'),
                fetchAPI('/api/map-get-items.php')
            ]);

            // Get all existing servers to check for used links
            const allItemsServer = itemsResultServer?.success ? itemsResultServer.items : [];
            const existingServers = allItemsServer.filter(item => item.item_type === 'server');

            // Collect used ISP links, MikroTik devices, and OLT links
            const usedIspLinks = new Set();
            const usedMikrotikDevices = new Set();
            const usedOltLinks = new Set();

            existingServers.forEach(server => {
                const props = server.properties || {};
                if (props.isp_link) usedIspLinks.add(props.isp_link);
                if (props.mikrotik_device_id) usedMikrotikDevices.add(props.mikrotik_device_id);
                if (props.olt_link) usedOltLinks.add(props.olt_link);
            });

            // Process netwatch options with duplicate check for ISP Link
            let netwatchOptionsIspLink = '<option value="">No Link</option>';
            if (netwatchResultServer && netwatchResultServer.success && netwatchResultServer.netwatch) {
                netwatchResultServer.netwatch.forEach(nw => {
                    const isUsed = usedIspLinks.has(nw.host);
                    const disabledAttr = isUsed ? 'disabled' : '';
                    const usedLabel = isUsed ? ' (Sudah digunakan)' : '';
                    netwatchOptionsIspLink += `<option value="${nw.host}" ${disabledAttr}>${nw.host} - ${nw.comment || 'No comment'}${usedLabel}</option>`;
                });
            }

            // Process netwatch options with duplicate check for OLT Link
            let netwatchOptionsOltLink = '<option value="">No Link</option>';
            if (netwatchResultServer && netwatchResultServer.success && netwatchResultServer.netwatch) {
                netwatchResultServer.netwatch.forEach(nw => {
                    const isUsed = usedOltLinks.has(nw.host);
                    const disabledAttr = isUsed ? 'disabled' : '';
                    const usedLabel = isUsed ? ' (Sudah digunakan)' : '';
                    netwatchOptionsOltLink += `<option value="${nw.host}" ${disabledAttr}>${nw.host} - ${nw.comment || 'No comment'}${usedLabel}</option>`;
                });
            }

            // Process GenieACS devices options with duplicate check
            let genieacsOptionsServer = '<option value="">No Device</option>';
            if (genieacsDevicesResult && genieacsDevicesResult.success && genieacsDevicesResult.devices) {
                genieacsDevicesResult.devices.forEach(device => {
                    const statusIcon = device.status === 'online' ? '🟢' : '🔴';
                    const serialNumber = device.serial_number || device.device_id;
                    const isUsed = usedMikrotikDevices.has(device.device_id);
                    const disabledAttr = isUsed ? 'disabled' : '';
                    const usedLabel = isUsed ? ' (Sudah digunakan)' : '';
                    genieacsOptionsServer += `<option value="${device.device_id}" ${disabledAttr}>${statusIcon} ${serialNumber} - ${device.ip_address || 'N/A'}${usedLabel}</option>`;
                });
            }

            dynamicFields.innerHTML = `
                <div class="form-group">
                    <label>🌐 ISP</label>
                    <select name="isp_link" class="form-control">
                        ${netwatchOptionsIspLink}
                    </select>
                </div>
                <div class="form-group">
                    <label>🔧 MikroTik</label>
                    <select name="mikrotik_device_id" class="form-control">
                        ${genieacsOptionsServer}
                    </select>
                </div>
                <div class="form-group">
                    <label>📡 OLT</label>
                    <select name="olt_link" class="form-control">
                        ${netwatchOptionsOltLink}
                    </select>
                </div>
                <div class="form-group">
                    <label>🔢 PON Ports</label>
                    <input type="number" id="pon_port_count" name="pon_port_count" class="form-control" value="0" min="0" max="16" onchange="generatePonPortFields(this.value); updateAllODCPonPortOptions(this.value);">
                </div>
                <div id="pon-output-power-container"></div>

                <hr class="my-3">
                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="add_odc_checkbox" onchange="toggleODCSection(this.checked)">
                    <label class="form-check-label" for="add_odc_checkbox">
                        <strong>➕ Tambahkan ODC</strong>
                    </label>
                </div>

                <div id="odc-section" style="display: none;">
                    <div id="odc-list-container"></div>
                    <button type="button" class="btn btn-success btn-sm mb-3" onclick="addODCForm()">
                        <i class="bi bi-plus-circle"></i> Tambah ODC Lainnya
                    </button>
                </div>
            `;

            // Don't generate PON port fields by default (user must set count first)
            // generatePonPortFields(0) will show nothing since count = 0
            setTimeout(() => {
                generatePonPortFields(0);
                updateAllODCPonPortOptions(0);

                // Add cross-validation between ISP Link and OLT Link
                setupServerLinkCrossValidation();
            }, 100);
            break;

        case 'olt':
            // Load netwatch and items in parallel
            const [netwatchResult, itemsResultOlt] = await Promise.all([
                fetchAPI('/api/map-get-netwatch.php'),
                fetchAPI('/api/map-get-items.php')
            ]);

            let netwatchOptions = '<option value="">No Link</option>';
            if (netwatchResult && netwatchResult.success && netwatchResult.netwatch) {
                netwatchResult.netwatch.forEach(nw => {
                    netwatchOptions += `<option value="${nw.host}">${nw.host} - ${nw.comment || 'No comment'}</option>`;
                });
            }

            // Get all servers for parent selection
            const allItemsOlt = itemsResultOlt?.success ? itemsResultOlt.items : [];
            let serverOptions = '<option value="">No Parent (Standalone)</option>';
            allItemsOlt.filter(item => item.item_type === 'server').forEach(item => {
                const statusBadge = item.status === 'online' ? '🟢' : item.status === 'offline' ? '🔴' : '⚪';
                serverOptions += `<option value="${item.id}">${statusBadge} ${item.name}</option>`;
            });

            dynamicFields.innerHTML = `
                <div class="form-group">
                    <label>Parent Server</label>
                    <select name="parent_id" class="form-control">
                        ${serverOptions}
                    </select>
                    <small class="text-muted">Pilih Server sebagai parent (opsional)</small>
                </div>
                <div class="form-group">
                    <label>📡 Jumlah PON Port</label>
                    <select name="pon_count" class="form-control" onchange="updatePONFields(this.value)">
                        <option value="1">1 PON</option>
                        <option value="2">2 PON</option>
                        <option value="4">4 PON</option>
                        <option value="8">8 PON</option>
                        <option value="16">16 PON</option>
                    </select>
                    <small class="text-muted">Jumlah PON port pada OLT</small>
                </div>
                <div id="pon-power-fields">
                    <div class="form-group">
                        <label>⚡ PON 1 - Output Power (dBm)</label>
                        <input type="number" step="0.01" name="pon_power_1" class="form-control" value="9" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>🔗 OLT Link (dari MikroTik Netwatch)</label>
                    <select name="olt_link" class="form-control">
                        ${netwatchOptions}
                    </select>
                    <small class="text-muted">Link untuk monitoring status OLT</small>
                </div>
            `;
            break;

        case 'odc':
            const ponPortsResult = await fetchAPI('/api/map-get-pon-ports.php');
            let ponPortOptions = '<option value="">Pilih OLT PON Port</option>';
            let availableCount = 0;
            let usedCount = 0;

            if (ponPortsResult && ponPortsResult.success && ponPortsResult.ports) {
                ponPortsResult.ports.forEach(port => {
                    if (port.is_used) {
                        usedCount++;
                        // Port is used - show as disabled with "Digunakan" label
                        ponPortOptions += `<option value="${port.id}" disabled>${port.olt_name} - PON ${port.pon_number} (${port.output_power} dBm) - Digunakan oleh ${port.connected_odc_name}</option>`;
                    } else {
                        availableCount++;
                        // Port is available
                        ponPortOptions += `<option value="${port.id}">${port.olt_name} - PON ${port.pon_number} (${port.output_power} dBm) - Tersedia</option>`;
                    }
                });
            }

            dynamicFields.innerHTML = `
                ${!ponPortsResult || !ponPortsResult.ports || ponPortsResult.ports.length === 0 ? `
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> Belum ada Server PON tersedia. Buat Server terlebih dahulu.
                    </div>
                ` : availableCount === 0 ? `
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> Semua PON port sudah digunakan. Total ${usedCount} port terpakai.
                    </div>
                ` : `
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> ${availableCount} PON port tersedia, ${usedCount} port sudah digunakan
                    </div>
                `}
                <div class="form-group">
                    <label>Parent Server PON Port <span class="text-danger">*</span></label>
                    <select name="olt_pon_port_id" id="olt-pon-port-select" class="form-control" required>
                        ${ponPortOptions}
                    </select>
                    <small class="text-muted">Pilih PON port dari Server sebagai parent (Port yang digunakan tidak bisa dipilih)</small>
                </div>
                <div class="form-group">
                    <label>Port Count</label>
                    <input type="number" name="port_count" class="form-control" value="4" required min="1">
                </div>
            `;
            break;

        case 'odp':
            // Load items for parent selection
            const itemsResultOdp = await fetchAPI('/api/map-get-items.php');
            const allItemsOdp = itemsResultOdp?.success ? itemsResultOdp.items : [];

            // Get all ODCs and ODPs with custom ratio for parent selection
            const odcItems = allItemsOdp.filter(item => item.item_type === 'odc');
            const odpItemsWithCustomRatio = allItemsOdp.filter(item =>
                item.item_type === 'odp' &&
                item.config?.splitter_ratio &&
                ['20:80', '30:70', '50:50'].includes(item.config.splitter_ratio)
            );

            let parentOptions = '<option value="">Pilih Parent (ODC atau ODP)</option>';

            // Add ODC group
            if (odcItems.length > 0) {
                parentOptions += '<optgroup label="ODC (Optical Distribution Cabinet)">';
                odcItems.forEach(item => {
                    const statusBadge = item.status === 'online' ? '🟢' : item.status === 'offline' ? '🔴' : '⚪';
                    parentOptions += `<option value="${item.id}" data-type="odc">${statusBadge} ${item.name}</option>`;
                });
                parentOptions += '</optgroup>';
            }

            // Add ODP group with custom ratio - check port availability for each
            if (odpItemsWithCustomRatio.length > 0) {
                // Fetch used ports for all ODPs
                const odpPortAvailability = await Promise.all(
                    odpItemsWithCustomRatio.map(async item => {
                        const ratio = item.config.splitter_ratio;
                        const ratioValues = ratio.split(':');
                        const availablePort = `${ratioValues[1]}%`;

                        // Fetch used ports for this ODP
                        const result = await fetchAPI(`/api/map-get-used-ports.php?parent_id=${item.id}&parent_type=odp`);
                        const usedPorts = result && result.success ? result.used_ports : [];
                        const isPortUsed = usedPorts.includes(availablePort);

                        return {
                            item: item,
                            ratio: ratio,
                            availablePort: availablePort,
                            isPortUsed: isPortUsed
                        };
                    })
                );

                parentOptions += '<optgroup label="ODP (Splitter Custom Ratio tersedia)">';
                odpPortAvailability.forEach(({ item, ratio, availablePort, isPortUsed }) => {
                    let statusBadge, disabledAttr, label;

                    if (isPortUsed) {
                        // Port sudah penuh - tampilkan dengan indikator abu-abu dan disabled
                        statusBadge = '⚫';
                        disabledAttr = 'disabled';
                        label = `${item.name} (Port ${availablePort} - Sudah penuh)`;
                    } else {
                        // Port masih tersedia - tampilkan dengan status online/offline
                        statusBadge = item.status === 'online' ? '🟢' : item.status === 'offline' ? '🔴' : '⚪';
                        disabledAttr = '';
                        label = `${item.name} (Port ${availablePort} tersisa)`;
                    }

                    parentOptions += `<option value="${item.id}" data-type="odp" data-ratio="${ratio}" ${disabledAttr}>${statusBadge} ${label}</option>`;
                });
                parentOptions += '</optgroup>';
            }

            const noParentAvailable = odcItems.length === 0 && odpItemsWithCustomRatio.length === 0;

            dynamicFields.innerHTML = `
                ${noParentAvailable ? `
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> Belum ada ODC atau ODP dengan custom ratio tersedia. Silakan buat ODC atau ODP dengan ratio 20:80, 30:70, atau 50:50 terlebih dahulu.
                    </div>
                ` : ''}
                <div class="form-group">
                    <label>Parent (ODC/ODP) <span class="text-danger">*</span></label>
                    <select name="parent_id" id="odp-parent-select" class="form-control" required ${noParentAvailable ? 'disabled' : ''} onchange="handleODPParentChange(this)">
                        ${parentOptions}
                    </select>
                </div>
                <div class="form-group" id="odc-port-group" style="display: none;">
                    <label>ODC Port <span class="text-danger">*</span></label>
                    <select name="odc_port" id="odc-port-select" class="form-control">
                        <option value="">Pilih ODC terlebih dahulu</option>
                    </select>
                    <small class="text-muted">Port ODC yang tersedia (belum terpakai)</small>
                </div>
                <div class="form-group" id="odp-port-group" style="display: none;">
                    <label>Parent ODP Port <span class="text-danger">*</span></label>
                    <select name="parent_odp_port" class="form-control">
                        <option value="">Pilih port parent ODP</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jumlah Port ODP <span class="text-danger">*</span></label>
                    <select name="port_count" class="form-control" required>
                        <option value="2">2 Port / 1:2</option>
                        <option value="4">4 Port / 1:4</option>
                        <option value="8" selected>8 Port / 1:8</option>
                        <option value="16">16 Port / 1:16</option>
                        <option value="32">32 Port / 1:32</option>
                        <option value="64">64 Port / 1:64</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Pakai Splitter</label>
                    <select name="use_splitter" class="form-control" onchange="toggleSplitterRatio(this.value)">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>
                <div class="form-group" id="splitter-ratio-group" style="display: none;">
                    <label>Splitter Ratio</label>
                    <select name="splitter_ratio" class="form-control" onchange="toggleCustomRatioPortSelection(this.value)">
                        <option value="1:2">1:2 (3.5 dB)</option>
                        <option value="1:4">1:4 (7.0 dB)</option>
                        <option value="1:8" selected>1:8 (10.5 dB)</option>
                        <option value="1:16">1:16 (14.0 dB)</option>
                        <option value="1:32">1:32 (17.5 dB)</option>
                        <option value="20:80">20:80 (7.0 dB)</option>
                        <option value="30:70">30:70 (5.2 dB)</option>
                        <option value="50:50">50:50 (3.0 dB)</option>
                    </select>
                </div>
                <div class="form-group" id="custom-ratio-port-group" style="display: none;">
                    <label>Pilih In Port <span class="text-danger">*</span></label>
                    <select name="custom_ratio_output_port" id="custom-ratio-output-port" class="form-control">
                        <option value="">Pilih port output</option>
                    </select>
                </div>
            `;
            break;

        case 'onu':
            // Load available GenieACS devices and ODP list
            const devicesResult = await fetchAPI('/api/map-get-available-devices.php');
            const odpResult = await fetchAPI('/api/map-get-odp-ports.php');

            let deviceOptions = '<option value="">Pilih Device GenieACS</option>';
            if (devicesResult && devicesResult.success) {
                devicesResult.devices.forEach(dev => {
                    const statusBadge = dev.status === 'online' ? '🟢' : '🔴';
                    deviceOptions += `<option value="${dev.device_id}" data-sn="${dev.serial_number.toLowerCase()}" data-device-id="${dev.device_id.toLowerCase()}">${statusBadge} ${dev.serial_number} (${dev.device_id})</option>`;
                });
            }

            let odpOptions = '<option value="">Pilih ODP</option>';
            if (odpResult && odpResult.success) {
                odpResult.odp_list.forEach(odp => {
                    const statusBadge = odp.status === 'online' ? '🟢' : odp.status === 'offline' ? '🔴' : '⚪';
                    odpOptions += `<option value="${odp.id}">${statusBadge} ${odp.name} (${odp.available_ports.length}/${odp.port_count} ports available)</option>`;
                });
            }

            dynamicFields.innerHTML = `
                <div class="form-group">
                    <label>Customer Name <span class="text-danger">*</span></label>
                    <input type="text" name="customer_name" class="form-control" required>
                    <small class="text-muted">Nama pelanggan</small>
                </div>
                <div class="form-group">
                    <label>GenieACS Device <span class="text-danger">*</span></label>
                    <div class="input-group mb-2">
                        <span class="input-group-text">
                            <i class="bi bi-search"></i>
                        </span>
                        <input type="text" id="onu-device-search" class="form-control" placeholder="Cari berdasarkan Serial Number atau Device ID..." onkeyup="filterONUDevices()">
                        <button class="btn btn-outline-secondary" type="button" onclick="clearONUSearch()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <select name="genieacs_device_id" id="onu-device-select" class="form-control" required size="8" style="font-family: monospace; font-size: 0.875rem;">
                        ${deviceOptions}
                    </select>
                </div>
                <div class="form-group">
                    <label>Parent ODP <span class="text-danger">*</span></label>
                    <select name="parent_odp" class="form-control" required onchange="loadODPPorts(this.value)">
                        ${odpOptions}
                    </select>
                </div>
                <div class="form-group" id="odp-port-group">
                    <label>ODP Port <span class="text-danger">*</span></label>
                    <select name="odp_port" class="form-control" required>
                        <option value="">Pilih ODP dulu</option>
                    </select>
                </div>
            `;
            break;

        default:
            dynamicFields.innerHTML = '';
    }
}

// ============================================================
// PARENT-CHILD RELATIONSHIP HANDLERS
// ============================================================

async function handleODPParentChange(selectElement) {
    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const parentType = selectedOption.getAttribute('data-type');
    const parentId = selectElement.value;
    const odcPortGroup = document.getElementById('odc-port-group');
    const odpPortGroup = document.getElementById('odp-port-group');
    const odcPortSelect = document.getElementById('odc-port-select');
    const odpPortSelect = document.querySelector('select[name="parent_odp_port"]');

    if (parentType === 'odc') {
        // Show ODC port field, hide ODP port field
        odcPortGroup.style.display = 'block';
        odpPortGroup.style.display = 'none';
        odcPortSelect.required = true;
        odpPortSelect.required = false;

        // Fetch used ODC ports and populate available ports
        if (parentId) {
            const result = await fetchAPI(`/api/map-get-used-ports.php?parent_id=${parentId}&parent_type=odc`);

            // Get ODC config to know total port count
            const itemsResult = await fetchAPI('/api/map-get-items.php');
            const odcItem = itemsResult.items.find(item => item.id == parentId);
            const portCount = odcItem?.config?.port_count || 4;

            const usedPorts = (result && result.success) ? result.used_ports : [];

            // Generate available ports
            let portOptions = '<option value="">Pilih Port ODC</option>';
            for (let i = 1; i <= portCount; i++) {
                const isUsed = usedPorts.includes(i);
                if (!isUsed) {
                    portOptions += `<option value="${i}">Port ${i} (Tersedia)</option>`;
                } else {
                    portOptions += `<option value="${i}" disabled>Port ${i} (Sudah digunakan)</option>`;
                }
            }

            odcPortSelect.innerHTML = portOptions;

            // Show info about available ports
            const availableCount = portCount - usedPorts.length;
            const warningDiv = document.getElementById('odc-port-warning') || document.createElement('div');
            warningDiv.id = 'odc-port-warning';

            if (availableCount === 0) {
                warningDiv.className = 'alert alert-danger mt-2';
                warningDiv.innerHTML = `<small><i class="bi bi-exclamation-circle"></i> <b>Semua port sudah digunakan!</b> Pilih ODC lain atau tambah ODC baru.</small>`;
            } else if (usedPorts.length > 0) {
                warningDiv.className = 'alert alert-info mt-2';
                warningDiv.innerHTML = `<small><i class="bi bi-info-circle"></i> <b>${availableCount} port tersedia</b> dari ${portCount} total port.</small>`;
            } else {
                warningDiv.className = 'alert alert-success mt-2';
                warningDiv.innerHTML = `<small><i class="bi bi-check-circle"></i> <b>Semua ${portCount} port tersedia!</b></small>`;
            }

            if (!document.getElementById('odc-port-warning')) {
                odcPortSelect.parentElement.appendChild(warningDiv);
            }
        }

    } else if (parentType === 'odp') {
        // Show ODP port field, hide ODC port field
        odcPortGroup.style.display = 'none';
        odpPortGroup.style.display = 'block';
        odcPortSelect.required = false;
        odpPortSelect.required = true;

        // Fetch used ODP ports
        let usedPorts = [];
        if (parentId) {
            const result = await fetchAPI(`/api/map-get-used-ports.php?parent_id=${parentId}&parent_type=odp`);
            if (result && result.success) {
                usedPorts = result.used_ports;
            }
        }

        // Populate ODP port options based on ratio
        // IMPORTANT: Port kecil (20%, 30%, 50%) OTOMATIS masuk ke internal splitter ODP
        // Yang bisa dipilih untuk cascading HANYA port besar (80%, 70%, 50%)
        const ratio = selectedOption.getAttribute('data-ratio');
        const ratioValues = ratio.split(':');

        // Build options - ONLY show large percentage port for cascading
        let portOptions = '<option value="">Pilih port parent ODP</option>';

        // Port 1 (smaller percentage - ALWAYS DISABLED karena untuk internal splitter)
        const port1Value = `${ratioValues[0]}%`;
        portOptions += `<option value="${port1Value}" disabled>${ratioValues[0]}% - Port Low (Digunakan untuk internal splitter ODP)</option>`;

        // Port 2 (larger percentage - ini yang tersedia untuk cascading)
        const port2Value = `${ratioValues[1]}%`;
        const port2Used = usedPorts.includes(port2Value);
        const port2Available = !port2Used ? '✅ Tersedia untuk cascading' : '❌ Sudah digunakan';
        portOptions += `<option value="${port2Value}" ${port2Used ? 'disabled' : ''}>${ratioValues[1]}% - Port High ${port2Available}</option>`;

        odpPortSelect.innerHTML = portOptions;

        // Show info about port availability
        const infoDiv = document.getElementById('odp-port-warning') || document.createElement('div');
        infoDiv.id = 'odp-port-warning';

        if (port2Used) {
            // Port besar sudah digunakan - ODP penuh
            infoDiv.className = 'alert alert-danger mt-2';
            infoDiv.innerHTML = `<small><i class="bi bi-exclamation-circle"></i> <b>Port cascading sudah digunakan!</b> Port ${ratioValues[0]}% digunakan internal splitter, port ${ratioValues[1]}% sudah terpakai. Pilih ODP lain.</small>`;
            showToast('Port cascading ODP sudah penuh. Pilih parent lain.', 'warning');
        } else {
            // Port besar masih tersedia
            infoDiv.className = 'alert alert-success mt-2';
            infoDiv.innerHTML = `<small><i class="bi bi-check-circle"></i> <b>Port ${ratioValues[1]}% tersedia untuk cascading!</b> Port ${ratioValues[0]}% otomatis digunakan untuk internal secondary splitter (1:${document.querySelector('[name="port_count"]')?.value || 8}).</small>`;
        }

        if (!document.getElementById('odp-port-warning')) {
            odpPortSelect.parentElement.appendChild(infoDiv);
        }

    } else {
        // No parent selected
        odcPortGroup.style.display = 'none';
        odpPortGroup.style.display = 'none';
        odcPortSelect.required = false;
        odpPortSelect.required = false;

        // Remove warning if exists
        const warning = document.getElementById('odc-port-warning');
        if (warning) warning.remove();
    }
}

async function loadODPPorts(odpId) {
    if (!odpId) {
        document.querySelector('[name="odp_port"]').innerHTML = '<option value="">Pilih ODP dulu</option>';
        return;
    }

    const odpResult = await fetchAPI('/api/map-get-odp-ports.php');
    if (odpResult && odpResult.success) {
        const odp = odpResult.odp_list.find(o => o.id == odpId);
        if (odp) {
            let portOptions = '';
            if (odp.available_ports.length === 0) {
                portOptions = '<option value="">Tidak ada port tersedia</option>';
            } else {
                odp.available_ports.forEach(port => {
                    portOptions += `<option value="${port}">Port ${port}</option>`;
                });
            }
            document.querySelector('[name="odp_port"]').innerHTML = portOptions;
        }
    }
}

// ============================================================
// ADD ITEM SUBMIT
// ============================================================

async function addItem() {
    const form = document.getElementById('form-add-item');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/map-add-item.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast('Item berhasil ditambahkan', 'success');
        bootstrap.Modal.getInstance(document.getElementById('addItemModal')).hide();
        form.reset();
        // Remove pointer after adding item
        removeLocationPointer();
        loadMap();
    } else {
        showToast(result.message || 'Gagal menambahkan item', 'danger');
    }
}

// ============================================================
// EDIT ITEM FUNCTIONS
// ============================================================

async function updateItemPosition(itemId, lat, lng) {
    // Update marker position immediately for instant feedback
    const marker = markers[itemId];
    if (marker) {
        marker.setLatLng([lat, lng]);
        console.log(`📍 Marker ${itemId} position updated immediately to ${lat}, ${lng}`);
    }

    // CRITICAL: Reset polylines to straight line (remove custom waypoints)
    // This is expected behavior when user drags item to new position
    await resetConnectedPolylines(itemId);

    // Send position update to backend
    const result = await fetchAPI('/api/map-update-position.php', {
        method: 'POST',
        body: JSON.stringify({
            item_id: itemId,
            latitude: lat,
            longitude: lng
        })
    });

    if (result && result.success) {
        showToast('Posisi berhasil diperbarui', 'success');

        // Update marker item data with new coordinates
        if (marker && marker.itemData) {
            marker.itemData.latitude = lat;
            marker.itemData.longitude = lng;
        }

        // IMPORTANT: Don't reload map to prevent item jumping back to old position
        // The marker and polylines are already updated visually
        console.log('✅ Position update complete - map not reloaded to preserve new position');
    } else {
        showToast(result?.message || 'Gagal memperbarui posisi', 'danger');

        // If update failed, reload map to restore correct state
        loadMap();
    }
}

// Reset all polylines connected to the moved item to straight lines
async function resetConnectedPolylines(itemId) {
    console.log(`🔄 Resetting polylines connected to item ${itemId} to straight lines...`);

    // Find all polylines where this item is either parent or child
    const resetPromises = [];

    Object.keys(polylineData).forEach(connectionKey => {
        const data = polylineData[connectionKey];
        const { parentId, childId, mainPolyline, shadowPolyline, borderPolyline } = data;

        // Check if this connection involves the moved item
        if (parentId == itemId || childId == itemId) {
            console.log(`  ✓ Resetting connection ${connectionKey} to straight line`);

            // Get current coordinates from markers
            const parentMarker = markers[parentId];
            const childMarker = markers[childId];

            if (!parentMarker || !childMarker) {
                console.warn(`  ⚠️ Marker not found for connection ${connectionKey}`);
                return;
            }

            // CRITICAL: Create straight line (NO waypoints)
            // This resets any custom routing when item is moved
            const newCoords = [
                parentMarker.getLatLng(),
                childMarker.getLatLng()
            ];

            // Update all three polyline layers
            if (mainPolyline) mainPolyline.setLatLngs(newCoords);
            if (shadowPolyline) shadowPolyline.setLatLngs(newCoords);
            if (borderPolyline) borderPolyline.setLatLngs(newCoords);

            // Clear waypoints from global waypoints object
            delete waypoints[connectionKey];

            // Save to database (delete waypoints)
            const savePromise = fetchAPI('/api/map-save-waypoints.php', {
                method: 'POST',
                body: JSON.stringify({
                    parent_id: parentId,
                    child_id: childId,
                    waypoints: [] // Empty array = delete waypoints
                })
            }).then(result => {
                if (result && result.success) {
                    console.log(`  💾 Database updated: waypoints cleared for ${connectionKey}`);
                } else {
                    console.warn(`  ⚠️ Failed to update database for ${connectionKey}`);
                }
            });

            resetPromises.push(savePromise);

            console.log(`  ✅ Connection ${connectionKey} reset to straight line`);
        }
    });

    // Wait for all database updates to complete
    await Promise.all(resetPromises);

    console.log('✅ All connected polylines reset to straight lines and saved to database');
}

async function editItem(itemId) {
    const result = await fetchAPI(`/api/map-get-item-detail.php?item_id=${itemId}`);

    if (result && result.success) {
        const item = result.item;
        const form = document.getElementById('form-edit-item');

        form.item_id.value = item.id;
        form.item_type.value = item.item_type.toUpperCase();
        form.name.value = item.name;
        form.latitude.value = item.latitude;
        form.longitude.value = item.longitude;

        // Check if ODC has server parent to determine if coordinates are editable
        let coordinatesEditable = false;

        if (item.item_type === 'odc') {
            // Check if parent_id is truly empty (null, undefined, 0, or empty string)
            if (!item.parent_id || item.parent_id === 0 || item.parent_id === '0') {
                // No parent - standalone ODC
                coordinatesEditable = true;
            } else {
                // Has parent - check if it's a server
                const parentResult = await fetchAPI(`/api/map-get-item-detail.php?item_id=${item.parent_id}`);

                if (parentResult && parentResult.success && parentResult.item) {
                    if (parentResult.item.item_type !== 'server') {
                        // Parent is NOT a server - coordinates can be edited
                        coordinatesEditable = true;
                    }
                    // If parent is server, coordinatesEditable stays false
                }
            }
        }

        // Make coordinates editable/readonly based on item type and parent
        const latInput = form.latitude;
        const lngInput = form.longitude;
        const latHelper = latInput.parentElement.querySelector('small');
        const lngHelper = lngInput.parentElement.querySelector('small');

        if (item.item_type === 'odc' && coordinatesEditable) {
            // Standalone ODC or ODC with non-server parent - make coordinates editable
            latInput.readOnly = false;
            lngInput.readOnly = false;
            latInput.style.backgroundColor = '';
            latInput.style.cursor = '';
            lngInput.style.backgroundColor = '';
            lngInput.style.cursor = '';

            // Update helper text
            if (latHelper) latHelper.textContent = 'Koordinat bisa diubah untuk ODC standalone';
            if (lngHelper) lngHelper.textContent = 'Koordinat bisa diubah untuk ODC standalone';
        } else {
            // Keep readonly for others and server-child ODCs
            latInput.readOnly = true;
            lngInput.readOnly = true;
            latInput.style.backgroundColor = '#f8f9fa';
            latInput.style.cursor = 'not-allowed';
            lngInput.style.backgroundColor = '#f8f9fa';
            lngInput.style.cursor = 'not-allowed';

            // Keep original helper text
            if (latHelper) latHelper.textContent = 'Koordinat tidak bisa diubah - hapus dan buat ulang jika perlu memindahkan lokasi';
            if (lngHelper) lngHelper.textContent = 'Koordinat tidak bisa diubah - hapus dan buat ulang jika perlu memindahkan lokasi';
        }

        // Load type-specific fields
        await updateEditItemForm(item);

        const modal = new bootstrap.Modal(document.getElementById('editItemModal'));
        modal.show();
    } else {
        showToast('Failed to load item details', 'danger');
    }
}

async function updateEditItemForm(item) {
    const dynamicFields = document.getElementById('edit-dynamic-fields');

    switch(item.item_type) {
        case 'olt':
            dynamicFields.innerHTML = `
                <div class="form-group">
                    <label>OLT Output Power (dBm)</label>
                    <input type="number" step="0.01" name="output_power" class="form-control" value="${item.config?.output_power || 2}" required>
                    <small class="text-muted">Default: 2 dBm</small>
                </div>
                <div class="form-group">
                    <label>Attenuation (dB)</label>
                    <input type="number" step="0.01" name="attenuation_db" class="form-control" value="${item.config?.attenuation_db || 0}" required>
                </div>
                <div class="form-group">
                    <label>OLT Link</label>
                    <input type="text" name="olt_link" class="form-control" value="${item.config?.olt_link || ''}">
                </div>
            `;
            break;
        case 'odc':
            dynamicFields.innerHTML = `
                <div class="form-group">
                    <label>Port Count</label>
                    <input type="number" name="port_count" class="form-control" value="${item.config?.port_count || 4}" required>
                </div>
            `;
            break;
        case 'odp':
            dynamicFields.innerHTML = `
                <div class="form-group">
                    <label>ODC Port</label>
                    <input type="number" name="odc_port" class="form-control" value="${item.config?.odc_port || ''}" min="1" placeholder="Nomor port di ODC">
                    <small class="text-muted">Port ODC yang terhubung ke ODP ini</small>
                </div>
                <div class="form-group">
                    <label>Port Count</label>
                    <input type="number" name="port_count" class="form-control" value="${item.config?.port_count || 8}" required>
                </div>
                <div class="form-group">
                    <label>Use Splitter</label>
                    <select name="use_splitter" class="form-control">
                        <option value="0" ${item.config?.use_splitter == 0 ? 'selected' : ''}>No</option>
                        <option value="1" ${item.config?.use_splitter == 1 ? 'selected' : ''}>Yes</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Splitter Ratio</label>
                    <input type="text" name="splitter_ratio" class="form-control" value="${item.config?.splitter_ratio || '1:8'}">
                </div>
            `;
            break;
        case 'onu':
            dynamicFields.innerHTML = `
                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" name="customer_name" class="form-control" value="${item.customer_name || ''}" required>
                </div>
                <div class="alert alert-info">
                    <small><i class="bi bi-info-circle"></i> GenieACS device dan ODP port tidak bisa diubah. Hapus dan buat ulang jika perlu mengubah.</small>
                </div>
            `;
            break;
        default:
            dynamicFields.innerHTML = '';
    }
}

async function updateItem() {
    const form = document.getElementById('form-edit-item');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/map-update-item.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast('Item berhasil diupdate', 'success');
        bootstrap.Modal.getInstance(document.getElementById('editItemModal')).hide();
        loadMap();
    } else {
        showToast(result.message || 'Gagal mengupdate item', 'danger');
    }
}

// ============================================================
// DELETE ITEM FUNCTIONS
// ============================================================

function confirmDeleteItem(itemId, itemName) {
    if (confirm(`Apakah Anda yakin ingin menghapus "${itemName}"?\n\nPeringatan: Semua child items akan ikut terhapus!`)) {
        deleteItem(itemId);
    }
}

async function deleteItem(itemId) {
    showLoading();

    const result = await fetchAPI('/api/map-delete-item.php', {
        method: 'POST',
        body: JSON.stringify({ item_id: itemId })
    });

    hideLoading();

    if (result && result.success) {
        showToast('Item berhasil dihapus', 'success');

        // CRITICAL: Immediately remove marker and connected items from map for instant feedback
        // Instead of waiting for loadMap(), remove items directly
        removeItemFromMap(itemId, result.deleted_items || []);

        // Reload map to sync with backend (but visual removal already happened)
        loadMap();
    } else {
        showToast(result.message || 'Gagal menghapus item', 'danger');
    }
}

/**
 * Remove item and its children from map immediately
 * @param {number} itemId - ID of item that was deleted
 * @param {array} deletedItems - Array of all deleted item IDs from cascade delete
 */
function removeItemFromMap(itemId, deletedItems) {
    console.log(`🗑️ Removing item ${itemId} and ${deletedItems.length} related items from map...`);

    // Add main item to deletion list if not already included
    const itemsToRemove = deletedItems.includes(itemId) ? deletedItems : [itemId, ...deletedItems];

    itemsToRemove.forEach(id => {
        // Remove marker from map
        if (markers[id]) {
            console.log(`  ✓ Removing marker ${id} from map`);
            map.removeLayer(markers[id]);
            delete markers[id];
        }

        // Remove all polylines where this item is parent or child
        Object.keys(polylineData).forEach(connectionKey => {
            const data = polylineData[connectionKey];
            const [parentId, childId] = connectionKey.split('-').map(Number);

            if (parentId === id || childId === id) {
                console.log(`  ✓ Removing polyline ${connectionKey}`);

                // Remove all three polyline layers from map
                if (data.shadowPolyline) map.removeLayer(data.shadowPolyline);
                if (data.borderPolyline) map.removeLayer(data.borderPolyline);
                if (data.mainPolyline) map.removeLayer(data.mainPolyline);

                // Delete from polylineData object
                delete polylineData[connectionKey];

                // Delete waypoints if exist
                delete waypoints[connectionKey];
            }
        });
    });

    console.log(`✅ Removed ${itemsToRemove.length} items from map instantly`);
}

// ============================================================
// ONU DEVICE SEARCH FUNCTIONS
// ============================================================

function filterONUDevices() {
    const searchInput = document.getElementById('onu-device-search');
    const deviceSelect = document.getElementById('onu-device-select');

    if (!searchInput || !deviceSelect) {
        console.log('filterONUDevices: Elements not found', { searchInput, deviceSelect });
        return;
    }

    const searchTerm = searchInput.value.toLowerCase().trim();
    const options = deviceSelect.options;

    let visibleCount = 0;

    for (let i = 0; i < options.length; i++) {
        const option = options[i];

        // Skip the placeholder option
        if (option.value === '') {
            option.style.display = '';
            continue;
        }

        const serialNumber = option.getAttribute('data-sn') || '';
        const deviceId = option.getAttribute('data-device-id') || '';

        // Show option if search term matches serial number or device ID
        if (searchTerm === '' || serialNumber.includes(searchTerm) || deviceId.includes(searchTerm)) {
            option.style.display = '';
            visibleCount++;
        } else {
            option.style.display = 'none';
        }
    }

    // Auto-select first visible device if only one match
    if (visibleCount === 1 && searchTerm !== '') {
        for (let i = 0; i < options.length; i++) {
            if (options[i].value !== '' && options[i].style.display !== 'none') {
                deviceSelect.value = options[i].value;
                break;
            }
        }
    }
}

function clearONUSearch() {
    const searchInput = document.getElementById('onu-device-search');
    const deviceSelect = document.getElementById('onu-device-select');

    if (searchInput) {
        searchInput.value = '';
    }

    if (deviceSelect) {
        deviceSelect.value = '';
        // Show all options
        const options = deviceSelect.options;
        for (let i = 0; i < options.length; i++) {
            options[i].style.display = '';
        }
    }

    // Focus back to search input
    if (searchInput) {
        searchInput.focus();
    }
}

// ============================================================
// ITEM LIST MODAL FUNCTIONS
// ============================================================

/**
 * Show Item List Modal
 * Displays all items of specified type in a clickable list
 * @param {string} itemType - Type of items to show (olt, odc, odp, onu)
 */
function showItemListModal(itemType) {
    let items = [];

    // Special handling for OLT - OLT is stored as properties in Server items
    if (itemType === 'olt') {
        // Filter servers that have olt_link configured
        items = allMapItems.filter(item => {
            return item.item_type === 'server' &&
                   item.properties &&
                   item.properties.olt_link &&
                   item.properties.olt_link.trim() !== '';
        });
    } else {
        // For other types, filter normally by item_type
        items = allMapItems.filter(item => item.item_type === itemType);
    }

    // Determine modal and container IDs based on item type
    const modalId = `${itemType}ListModal`;
    const containerId = `${itemType}-list-container`;

    const container = document.getElementById(containerId);

    if (!container) {
        console.error(`Container ${containerId} not found`);
        return;
    }

    if (items.length === 0) {
        container.innerHTML = `<p class="text-muted mb-0"><i class="bi bi-info-circle"></i> No ${itemType.toUpperCase()} items found</p>`;
    } else {
        let html = '';

        // Add search field for ONU only
        if (itemType === 'onu') {
            html += `
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" id="onu-list-search" class="form-control" placeholder="Cari berdasarkan Serial Number atau IP..." oninput="filterONUList()">
                        <button class="btn btn-outline-secondary" type="button" onclick="clearONUListSearch()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <small class="text-muted"><i class="bi bi-info-circle"></i> Ketik Serial Number atau IP Address untuk mencari ONU</small>
                </div>
            `;
        }

        html += '<div class="list-group" id="onu-list-items">';
        items.forEach(item => {
            const statusBadge = item.status === 'online' ? '🟢' :
                               item.status === 'offline' ? '🔴' : '⚪';

            // Get icon based on item type
            let icon = '';
            let displayName = item.name;
            let additionalInfo = '';

            // Special handling for OLT display
            if (itemType === 'olt') {
                icon = 'bi-broadcast-pin';
                // Get OLT IP and PON port count
                const oltLink = item.properties?.olt_link || 'N/A';
                const ponPorts = item.config?.pon_ports || {};
                const ponPortCount = Object.keys(ponPorts).length;
                // Status emoji will be added to displayName
                displayName = `${item.name} ${statusBadge}`;
                additionalInfo = `${oltLink} | ${ponPortCount} PON | ${parseFloat(item.latitude).toFixed(6)}, ${parseFloat(item.longitude).toFixed(6)}`;
            } else if (itemType === 'onu') {
                // Special display for ONU with Serial Number and IP
                icon = 'bi-wifi';
                // Extract short SN from genieacs_device_id (last part after last dash)
                const deviceId = item.genieacs_device_id || 'N/A';
                const serialNumber = deviceId.split('-').pop() || deviceId;
                // Use placeholder for now - will be loaded async
                const ipAddress = 'Loading...';
                // Status emoji will be added to displayName, additionalInfo without "SN:" label
                displayName = `${item.name} ${statusBadge}`;
                additionalInfo = `${serialNumber} | ${ipAddress} | ${parseFloat(item.latitude).toFixed(6)}, ${parseFloat(item.longitude).toFixed(6)}`;
            } else if (itemType === 'odp') {
                // ODP display with ONU count
                icon = 'bi-cube';
                // Count ONUs connected to this ODP
                const onuCount = allMapItems.filter(onu => onu.item_type === 'onu' && onu.parent_id == item.id).length;
                // Badge color based on ONU count: red if has ONUs, gray if empty
                const onuBadgeClass = onuCount > 0 ? 'bg-danger' : 'bg-secondary';
                const onuBadge = `<span class="badge ${onuBadgeClass}">${onuCount}</span>`;
                // Status emoji will be added to displayName
                displayName = `${item.name} ${statusBadge}`;
                additionalInfo = `ONU ${onuBadge} | ${parseFloat(item.latitude).toFixed(6)}, ${parseFloat(item.longitude).toFixed(6)}`;
            } else if (itemType === 'odc') {
                // ODC display with ODP count
                icon = 'bi-box';
                // Count ODPs connected to this ODC
                const odpCount = allMapItems.filter(odp => odp.item_type === 'odp' && odp.parent_id == item.id).length;
                // Badge color based on ODP count: red if has ODPs, gray if empty
                const odpBadgeClass = odpCount > 0 ? 'bg-danger' : 'bg-secondary';
                const odpBadge = `<span class="badge ${odpBadgeClass}">${odpCount}</span>`;
                // Status emoji will be added to displayName
                displayName = `${item.name} ${statusBadge}`;
                additionalInfo = `ODP ${odpBadge} | ${parseFloat(item.latitude).toFixed(6)}, ${parseFloat(item.longitude).toFixed(6)}`;
            } else if (itemType === 'server') {
                // Server display with ODC, ODP, and ONU counts
                icon = 'bi-server';

                // Count ODCs connected to this server (both child and standalone via pon port)
                const odcCount = allMapItems.filter(odc => {
                    if (odc.item_type !== 'odc') return false;
                    // Count child ODCs (parent_id = server) OR standalone ODCs connected via server_id in config
                    return odc.parent_id == item.id ||
                           (odc.config?.server_id && odc.config.server_id == item.id);
                }).length;

                // Count all ODPs in the hierarchy (including cascading ODPs)
                let odpCount = 0;
                const countODPsRecursive = (parentId, parentType) => {
                    let count = 0;
                    allMapItems.forEach(odp => {
                        if (odp.item_type === 'odp' && odp.parent_id == parentId) {
                            count++;
                            // Recursively count child ODPs (cascading)
                            count += countODPsRecursive(odp.id, 'odp');
                        }
                    });
                    return count;
                };

                allMapItems.forEach(odc => {
                    if (odc.item_type === 'odc') {
                        // Check if ODC is connected to this server
                        const isConnected = odc.parent_id == item.id ||
                                          (odc.config?.server_id && odc.config.server_id == item.id);
                        if (isConnected) {
                            // Count all ODPs under this ODC (including cascading)
                            odpCount += countODPsRecursive(odc.id, 'odc');
                        }
                    }
                });

                // Count all ONUs in the hierarchy (from all ODPs including cascading)
                let onuCount = 0;
                const countONUsRecursive = (odpId) => {
                    let count = 0;
                    // Count ONUs directly connected to this ODP
                    count += allMapItems.filter(onu =>
                        onu.item_type === 'onu' && onu.parent_id == odpId
                    ).length;

                    // Find child ODPs and recursively count their ONUs
                    allMapItems.forEach(childOdp => {
                        if (childOdp.item_type === 'odp' && childOdp.parent_id == odpId) {
                            count += countONUsRecursive(childOdp.id);
                        }
                    });

                    return count;
                };

                // Get all ODCs connected to this server
                const serverODCs = allMapItems.filter(odc => {
                    if (odc.item_type !== 'odc') return false;
                    return odc.parent_id == item.id ||
                           (odc.config?.server_id && odc.config.server_id == item.id);
                });

                // For each ODC, count ONUs from all its ODPs (including cascading)
                serverODCs.forEach(odc => {
                    // Find all ODPs directly under this ODC
                    allMapItems.forEach(odp => {
                        if (odp.item_type === 'odp' && odp.parent_id == odc.id) {
                            // Count ONUs from this ODP and all its children
                            onuCount += countONUsRecursive(odp.id);
                        }
                    });
                });

                // Badge colors based on counts
                const odcBadgeClass = odcCount > 0 ? 'bg-danger' : 'bg-secondary';
                const odpBadgeClass = odpCount > 0 ? 'bg-danger' : 'bg-secondary';
                const onuBadgeClass = onuCount > 0 ? 'bg-danger' : 'bg-secondary';

                const odcBadge = `<span class="badge ${odcBadgeClass}">${odcCount}</span>`;
                const odpBadge = `<span class="badge ${odpBadgeClass}">${odpCount}</span>`;
                const onuBadge = `<span class="badge ${onuBadgeClass}">${onuCount}</span>`;

                // Status emoji will be added to displayName
                displayName = `${item.name} ${statusBadge}`;
                additionalInfo = `ODC ${odcBadge} | ODP ${odpBadge} | ONU ${onuBadge} | ${parseFloat(item.latitude).toFixed(6)}, ${parseFloat(item.longitude).toFixed(6)}`;
            } else {
                // Default display for other types
                const statusText = item.status ? item.status.toUpperCase() : 'UNKNOWN';
                additionalInfo = `${statusBadge} ${statusText} | Lat: ${parseFloat(item.latitude).toFixed(6)}, Lng: ${parseFloat(item.longitude).toFixed(6)}`;
            }

            // Use data attribute instead of inline onclick
            // For ONU, use short serial number for search
            const dataSn = itemType === 'onu' ? (item.genieacs_device_id?.split('-').pop() || '') : (item.config?.serial_number || '');
            const dataIp = item.config?.ip_address || '';

            html += `
                <a href="#" class="list-group-item list-group-item-action ${itemType}-item" data-item-id="${item.id}" data-sn="${dataSn}" data-ip="${dataIp}" data-device-id="${item.genieacs_device_id || ''}">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><i class="bi ${icon}"></i> ${displayName}</strong>
                            <div class="text-muted" style="font-size: 0.875rem;" id="onu-info-${item.id}">
                                <small>${additionalInfo}</small>
                            </div>
                        </div>
                        <i class="bi bi-geo-alt-fill text-primary" style="font-size: 1.25rem;"></i>
                    </div>
                </a>
            `;
        });
        html += '</div>';
        container.innerHTML = html;

        // Add event listeners after HTML is inserted
        setTimeout(() => {
            const itemElements = container.querySelectorAll(`.${itemType}-item`);
            itemElements.forEach(element => {
                element.addEventListener('click', function(e) {
                    e.preventDefault();
                    const itemId = this.getAttribute('data-item-id');
                    // For OLT, we're actually zooming to the Server item
                    const actualType = itemType === 'olt' ? 'server' : itemType;
                    zoomToItem(itemId, actualType);
                });
            });
        }, 100);

        // For ONU list, load IP addresses asynchronously
        if (itemType === 'onu') {
            loadONUListIPAddresses(items);
        }
    }

    const modal = new bootstrap.Modal(document.getElementById(modalId));
    modal.show();
}

/**
 * Zoom to Item
 * Flies to selected item at zoom level 17.0 and opens its popup
 * @param {string} itemId - ID of the item to zoom to
 * @param {string} itemType - Type of the item
 */
function zoomToItem(itemId, itemType) {
    // Close modal first
    const modalId = `${itemType}ListModal`;
    const modalElement = document.getElementById(modalId);
    const modalInstance = bootstrap.Modal.getInstance(modalElement);
    if (modalInstance) {
        modalInstance.hide();
    }

    // Find the item in allMapItems
    const item = allMapItems.find(i => i.id == itemId && i.item_type === itemType);

    if (!item) {
        showToast(`${itemType.toUpperCase()} tidak ditemukan`, 'danger');
        return;
    }

    // Pan and zoom to the item at level 17.0
    const lat = parseFloat(item.latitude);
    const lng = parseFloat(item.longitude);

    if (!isNaN(lat) && !isNaN(lng)) {
        // Smooth pan to location at zoom level 17.0
        map.flyTo([lat, lng], 17, {
            duration: 1.5
        });

        // Find and open popup for this item after animation
        setTimeout(() => {
            if (markers[item.id]) {
                markers[item.id].openPopup();
            }
        }, 1600);
    }
}

// ============================================================
// ONU LIST IP ADDRESS LOADING
// ============================================================

/**
 * Load ONU List IP Addresses
 * Asynchronously loads IP addresses for all ONUs in the list from GenieACS
 * @param {Array} items - Array of ONU items to load IP addresses for
 */
async function loadONUListIPAddresses(items) {
    // Load IP addresses for each ONU item
    for (const item of items) {
        if (!item.genieacs_device_id) continue;

        try {
            // Fetch device details from GenieACS
            const result = await fetchAPI(`/api/get-device-detail.php?device_id=${encodeURIComponent(item.genieacs_device_id)}`);

            if (result && result.success && result.device) {
                const device = result.device;

                // Extract IP address using same method as popup
                const ipAddress = extractIP(device.ip_tr069);

                // Extract short serial number (last part after dash)
                const serialNumber = item.genieacs_device_id.split('-').pop();

                // Update the display in the list item
                const infoDiv = document.getElementById(`onu-info-${item.id}`);
                if (infoDiv) {
                    const lat = parseFloat(item.latitude).toFixed(6);
                    const lng = parseFloat(item.longitude).toFixed(6);

                    infoDiv.innerHTML = `<small>${serialNumber} | ${ipAddress} | ${lat}, ${lng}</small>`;
                }

                // Update data-ip attribute for search functionality
                const listItem = document.querySelector(`.onu-item[data-item-id="${item.id}"]`);
                if (listItem) {
                    listItem.setAttribute('data-ip', ipAddress);
                }
            }
        } catch (error) {
            console.error(`Failed to load IP for ONU ${item.id}:`, error);
            // Keep "Loading..." if failed - don't show error to user
        }
    }
}

// ============================================================
// ONU LIST SEARCH FUNCTIONS
// ============================================================

/**
 * Filter ONU List
 * Filters ONU items in the modal list based on Serial Number or IP Address
 */
function filterONUList() {
    const searchInput = document.getElementById('onu-list-search');
    const listContainer = document.getElementById('onu-list-items');

    if (!searchInput || !listContainer) {
        return;
    }

    const searchTerm = searchInput.value.toLowerCase().trim();
    const items = listContainer.querySelectorAll('.onu-item');

    let visibleCount = 0;

    items.forEach(item => {
        const serialNumber = (item.getAttribute('data-sn') || '').toLowerCase();
        const ipAddress = (item.getAttribute('data-ip') || '').toLowerCase();

        // Show item if search term matches serial number or IP address
        if (searchTerm === '' || serialNumber.includes(searchTerm) || ipAddress.includes(searchTerm)) {
            item.style.display = '';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });

    // Show message if no results
    const existingMessage = document.getElementById('onu-no-results');
    if (existingMessage) {
        existingMessage.remove();
    }

    if (visibleCount === 0 && searchTerm !== '') {
        const noResultsMsg = document.createElement('p');
        noResultsMsg.id = 'onu-no-results';
        noResultsMsg.className = 'text-muted text-center mt-3';
        noResultsMsg.innerHTML = '<i class="bi bi-search"></i> Tidak ada hasil untuk pencarian "' + searchTerm + '"';
        listContainer.appendChild(noResultsMsg);
    }
}

/**
 * Clear ONU List Search
 * Clears the search input and shows all ONU items
 */
function clearONUListSearch() {
    const searchInput = document.getElementById('onu-list-search');
    const listContainer = document.getElementById('onu-list-items');

    if (searchInput) {
        searchInput.value = '';
    }

    if (listContainer) {
        // Show all items
        const items = listContainer.querySelectorAll('.onu-item');
        items.forEach(item => {
            item.style.display = '';
        });

        // Remove no results message if exists
        const noResultsMsg = document.getElementById('onu-no-results');
        if (noResultsMsg) {
            noResultsMsg.remove();
        }
    }

    // Focus back to search input
    if (searchInput) {
        searchInput.focus();
    }
}

// ============================================================
// SERVER LINK CROSS-VALIDATION
// ============================================================

/**
 * Setup Server Link Cross-Validation
 * Ensures that ISP Link and OLT Link cannot use the same netwatch link
 * Prevents duplicate selection between the two dropdowns while preserving
 * existing validation for links already used by other servers
 */
function setupServerLinkCrossValidation() {
    const ispLinkSelect = document.querySelector('select[name="isp_link"]');
    const oltLinkSelect = document.querySelector('select[name="olt_link"]');

    if (!ispLinkSelect || !oltLinkSelect) {
        console.log('ISP Link or OLT Link select not found - cross-validation not setup');
        return;
    }

    console.log('✓ Setting up cross-validation between ISP Link and OLT Link');

    // When ISP Link changes, disable that option in OLT Link
    ispLinkSelect.addEventListener('change', function() {
        const selectedIspLink = this.value;

        // Re-enable all options in OLT Link first (except those already used by other servers)
        Array.from(oltLinkSelect.options).forEach(option => {
            if (option.value && !option.textContent.includes('(Sudah digunakan)')) {
                option.disabled = false;
            }
        });

        // If ISP Link has a value selected, disable that value in OLT Link
        if (selectedIspLink) {
            Array.from(oltLinkSelect.options).forEach(option => {
                if (option.value === selectedIspLink) {
                    option.disabled = true;
                    // If OLT Link currently has this value selected, clear it
                    if (oltLinkSelect.value === selectedIspLink) {
                        oltLinkSelect.value = '';
                        showToast('OLT Link dikosongkan karena link yang sama sudah dipilih di ISP Link', 'warning');
                    }
                }
            });
        }
    });

    // When OLT Link changes, disable that option in ISP Link
    oltLinkSelect.addEventListener('change', function() {
        const selectedOltLink = this.value;

        // Re-enable all options in ISP Link first (except those already used by other servers)
        Array.from(ispLinkSelect.options).forEach(option => {
            if (option.value && !option.textContent.includes('(Sudah digunakan)')) {
                option.disabled = false;
            }
        });

        // If OLT Link has a value selected, disable that value in ISP Link
        if (selectedOltLink) {
            Array.from(ispLinkSelect.options).forEach(option => {
                if (option.value === selectedOltLink) {
                    option.disabled = true;
                    // If ISP Link currently has this value selected, clear it
                    if (ispLinkSelect.value === selectedOltLink) {
                        ispLinkSelect.value = '';
                        showToast('ISP Link dikosongkan karena link yang sama sudah dipilih di OLT Link', 'warning');
                    }
                }
            });
        }
    });

    console.log('✓ Cross-validation setup complete');
}
