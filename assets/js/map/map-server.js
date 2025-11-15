/**
 * Map Server Links & Chain Module
 * Handles Server chain visualization (ISP → MikroTik → OLT → ODC)
 * and Server Links modal management with PON port configuration
 */
async function loadServerChainInfo(serverItem) {
    try {
        const infoElement = document.getElementById(`server-chain-info-${serverItem.id}`);
        if (!infoElement) {
            return; // Element not found
        }

        // Check if chain info is already loaded (not showing loading spinner)
        // If it's already loaded, only update the async status elements (ISP, MikroTik, OLT status)
        const currentContent = infoElement.innerHTML;
        const isAlreadyLoaded = currentContent &&
                                !currentContent.includes('Loading chain info') &&
                                !currentContent.includes('Loading connections');

        const properties = serverItem.properties || {};
        const config = serverItem.config || {};
        const ponPorts = config.pon_ports || {};

        // Find all ODCs that are children of this server OR standalone ODCs connected via PON port
        const odcItems = allMapItems.filter(item => {
            if (item.item_type !== 'odc') return false;

            // Include ODC child (has parent_id pointing to this server)
            if (item.parent_id == serverItem.id) return true;

            // Include ODC connected via server_id in config
            if (item.config && item.config.server_id == serverItem.id) return true;

            return false;
        });

        // If already loaded, just update the async status parts without replacing whole HTML
        if (isAlreadyLoaded) {
            // Only refresh async status data without rebuilding HTML
            if (properties.isp_link) {
                loadISPStatus(properties.isp_link, serverItem.id);
            }
            if (properties.mikrotik_device_id) {
                loadMikroTikInfo(properties.mikrotik_device_id, serverItem.id);
            }
            if (properties.olt_link) {
                loadOLTStatus(properties.olt_link, serverItem.id);
            }
            return; // Exit early - don't rebuild HTML
        }

        // If not loaded yet, build full HTML structure
        let chainHtml = '';

        // ISP Info
        if (properties.isp_link) {
            chainHtml += `
                <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px; margin-bottom: 6px; background: #f8fafc;">
                    <strong style="color: #10b981;">🌐 ISP Link <span id="isp-status-${serverItem.id}">⏳</span></strong>
                    <p style="margin: 2px 0;"><small>IP / ${properties.isp_link}</small></p>
                </div>
            `;
        }

        // MikroTik Info
        if (properties.mikrotik_device_id) {
            chainHtml += `
                <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px; margin-bottom: 6px; background: #f8fafc;">
                    <strong style="color: #6366f1;">🔧 MikroTik Device <span id="mikrotik-info-${serverItem.id}">⏳</span></strong>
                    <p style="margin: 2px 0;"><small>ID / ${properties.mikrotik_device_id.split('-')[2] || properties.mikrotik_device_id}</small></p>
                </div>
            `;
        }

        // OLT Info with PON ports inside the box
        if (properties.olt_link) {
            chainHtml += `
                <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px; margin-bottom: 6px; background: #f8fafc;">
                    <strong style="color: #1cc88a;">📡 OLT Link <span id="olt-status-${serverItem.id}">⏳</span></strong>
                    <p style="margin: 2px 0;"><small>Host / ${properties.olt_link}</small></p>
            `;

            // Show PON ports if available (inside OLT box)
            if (Object.keys(ponPorts).length > 0) {
                chainHtml += `
                    <p style="margin: 4px 0 2px 0;"><small><strong>⚡ OLT PON Port</strong></small></p>
                    <div id="server-pon-ports-${serverItem.id}" style="max-height: 100px; overflow-y: auto; font-size: 10px;">
                        <div class="text-center">
                            <div class="spinner-border spinner-border-sm" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                `;
            }

            chainHtml += `</div>`;
        }

        // Show "Not configured" if nothing is set
        if (!properties.isp_link && !properties.mikrotik_device_id && !properties.olt_link && odcItems.length === 0) {
            chainHtml = '<p class="text-muted mb-0"><small><i class="bi bi-info-circle"></i> No connections configured. Click "Edit Server Links" to add.</small></p>';
        }

        infoElement.innerHTML = chainHtml;

        // NOW load async data - AFTER HTML is inserted into DOM
        if (properties.isp_link) {
            loadISPStatus(properties.isp_link, serverItem.id);
        }
        if (properties.mikrotik_device_id) {
            loadMikroTikInfo(properties.mikrotik_device_id, serverItem.id);
        }
        if (properties.olt_link) {
            loadOLTStatus(properties.olt_link, serverItem.id);
        }

    } catch (error) {
        const infoElement = document.getElementById(`server-chain-info-${serverItem.id}`);
        if (infoElement) {
            infoElement.innerHTML = '<p class="text-danger mb-0"><small>Error loading connections</small></p>';
        }
    }
}

// Helper functions to load status async
async function loadISPStatus(host, serverId) {
    const statusElement = document.getElementById(`isp-status-${serverId}`);
    if (!statusElement) return;

    try {
        const netwatchResult = await fetchAPI('/api/map-get-netwatch.php');

        if (netwatchResult && netwatchResult.success && netwatchResult.netwatch) {
            const netwatch = netwatchResult.netwatch.find(nw => nw.host === host);
            if (netwatch) {
                const status = netwatch.status === 'up' ? 'online' : 'offline';
                const statusBadge = status === 'online' ? '🟢' : '🔴';
                statusElement.innerHTML = statusBadge;
            } else {
                statusElement.innerHTML = '⚪';
            }
        } else {
            statusElement.innerHTML = '⚠️';
        }
    } catch (error) {
        statusElement.innerHTML = '⚠️';
    }
}

async function loadMikroTikInfo(deviceId, serverId) {
    const infoElement = document.getElementById(`mikrotik-info-${serverId}`);
    if (!infoElement) return;

    try {
        const devicesResult = await fetchAPI('/api/get-devices.php');

        if (devicesResult && devicesResult.success && devicesResult.devices) {
            const device = devicesResult.devices.find(d => d.device_id === deviceId);
            if (device) {
                const status = device.status === 'online' ? 'online' : 'offline';
                const statusBadge = status === 'online' ? '🟢' : '🔴';
                infoElement.innerHTML = statusBadge;
            } else {
                infoElement.innerHTML = '⚪';
            }
        } else {
            infoElement.innerHTML = '⚠️';
        }
    } catch (error) {
        infoElement.innerHTML = '⚠️';
    }
}

async function loadOLTStatus(host, serverId) {
    const statusElement = document.getElementById(`olt-status-${serverId}`);
    if (!statusElement) return;

    try {
        const netwatchResult = await fetchAPI('/api/map-get-netwatch.php');

        if (netwatchResult && netwatchResult.success && netwatchResult.netwatch) {
            const netwatch = netwatchResult.netwatch.find(nw => nw.host === host);
            if (netwatch) {
                const status = netwatch.status === 'up' ? 'online' : 'offline';
                const statusBadge = status === 'online' ? '🟢' : '🔴';
                statusElement.innerHTML = statusBadge;
            } else {
                statusElement.innerHTML = '⚪';
            }
        } else {
            statusElement.innerHTML = '⚠️';
        }
    } catch (error) {
        statusElement.innerHTML = '⚠️';
    }
}

async function loadServerPONPorts(serverItem) {
    try {
        const portsElement = document.getElementById(`server-pon-ports-${serverItem.id}`);
        if (!portsElement) return;

        // Check if PON ports are already loaded (not showing loading spinner)
        const currentContent = portsElement.innerHTML;
        const isAlreadyLoaded = currentContent &&
                                !currentContent.includes('spinner-border') &&
                                !currentContent.includes('Loading');

        // If already loaded, don't rebuild - just return
        if (isAlreadyLoaded) {
            return;
        }

        const config = serverItem.config || {};
        const ponPorts = config.pon_ports || {};

        if (Object.keys(ponPorts).length === 0) {
            portsElement.innerHTML = '<small class="text-muted">No PON ports configured</small>';
            return;
        }

        // Find all ODCs connected to this server
        const odcItems = allMapItems.filter(item => {
            if (item.item_type !== 'odc') return false;

            // Include ODC child (has parent_id pointing to this server)
            if (item.parent_id == serverItem.id) return true;

            // Include ODC connected via server_id in config
            if (item.config && item.config.server_id == serverItem.id) return true;

            return false;
        });

        // Create mapping of PON port -> ODC
        const ponPortOdcMap = {};
        odcItems.forEach(odc => {
            const ponPort = odc.config?.server_pon_port;
            if (ponPort) {
                ponPortOdcMap[ponPort] = {
                    id: odc.id,
                    name: odc.name,
                    status: odc.status
                };
            }
        });

        let portsHtml = '';
        const sortedPorts = Object.entries(ponPorts).sort((a, b) => parseInt(a[0]) - parseInt(b[0]));

        for (const [portNum, power] of sortedPorts) {
            const odc = ponPortOdcMap[portNum];
            if (odc) {
                // Format: PON 1 / 8.00 dBm / ODC-B-1 (clickable)
                portsHtml += `<div style="padding: 2px 0;">PON ${portNum} / ${power} dBm / <a href="#" onclick="showODCDetail(${odc.id}); return false;" style="color: #0d6efd; text-decoration: underline;">${odc.name}</a></div>`;
            } else {
                portsHtml += `<div style="padding: 2px 0;">PON ${portNum} / ${power} dBm</div>`;
            }
        }

        portsElement.innerHTML = portsHtml;
    } catch (error) {
        const portsElement = document.getElementById(`server-pon-ports-${serverItem.id}`);
        if (portsElement) {
            portsElement.innerHTML = '<div class="text-danger">Error loading PON ports</div>';
        }
    }
}

async function manageServerLinks(itemId) {
    // Load current server data
    const itemResult = await fetchAPI(`/api/map-get-item-detail.php?item_id=${itemId}`);
    if (!itemResult || !itemResult.success) {
        showToast('Failed to load server data', 'danger');
        return;
    }

    const item = itemResult.item;
    // Properties already parsed by API, no need to JSON.parse again
    const properties = item.properties || {};
    const config = item.config || {};
    const ponPorts = config.pon_ports || {};

    // Load netwatch data for ISP and OLT links
    const netwatchResult = await fetchAPI('/api/map-get-netwatch.php');
    let netwatchOptions = '<option value="">No Link</option>';
    if (netwatchResult && netwatchResult.success && netwatchResult.netwatch) {
        netwatchResult.netwatch.forEach(nw => {
            netwatchOptions += `<option value="${nw.host}">${nw.host} - ${nw.comment || 'No comment'}</option>`;
        });
    }

    // Load GenieACS devices for MikroTik
    const genieacsDevicesResult = await fetchAPI('/api/get-devices.php');
    let genieacsOptions = '<option value="">No Device</option>';
    if (genieacsDevicesResult && genieacsDevicesResult.success && genieacsDevicesResult.devices) {
        genieacsDevicesResult.devices.forEach(device => {
            const statusIcon = device.status === 'online' ? '🟢' : '🔴';
            const serialNumber = device.serial_number || device.device_id;
            genieacsOptions += `<option value="${device.device_id}">${statusIcon} ${serialNumber} - ${device.ip_address || 'N/A'}</option>`;
        });
    }

    // Populate form
    const form = document.getElementById('form-server-links');
    form.item_id.value = itemId;

    document.getElementById('isp-link-select').innerHTML = netwatchOptions;
    document.getElementById('mikrotik-device-select').innerHTML = genieacsOptions;
    document.getElementById('olt-link-select').innerHTML = netwatchOptions;

    // Set current values
    if (properties.isp_link) {
        document.querySelector('[name="isp_link"]').value = properties.isp_link;
    }
    if (properties.mikrotik_device_id) {
        document.querySelector('[name="mikrotik_device_id"]').value = properties.mikrotik_device_id;
    }
    if (properties.olt_link) {
        document.querySelector('[name="olt_link"]').value = properties.olt_link;
    }

    // Generate PON output power fields
    const ponOutputContainer = document.getElementById('pon-output-power-container');
    let ponHtml = '<div class="form-group"><label>⚡ PON Output Power per Port (dBm)</label>';

    const ponPortCount = Object.keys(ponPorts).length || 4; // Default 4 if no ports
    for (let i = 1; i <= ponPortCount; i++) {
        const currentPower = ponPorts[i] || '2.00';
        ponHtml += `
            <div class="input-group mb-2">
                <span class="input-group-text" style="width: 80px;">Port ${i}</span>
                <input type="number" step="0.01" name="pon_port_${i}_power" class="form-control" value="${currentPower}" placeholder="2.00">
                <span class="input-group-text">dBm</span>
            </div>
        `;
    }
    ponHtml += '</div>';
    ponOutputContainer.innerHTML = ponHtml;

    const modal = new bootstrap.Modal(document.getElementById('serverLinksModal'));
    modal.show();
}

async function saveServerLinks() {
    const form = document.getElementById('form-server-links');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);

    showLoading();

    const result = await fetchAPI('/api/map-update-server-links.php', {
        method: 'POST',
        body: JSON.stringify(data)
    });

    hideLoading();

    if (result && result.success) {
        showToast('Server links updated', 'success');
        bootstrap.Modal.getInstance(document.getElementById('serverLinksModal')).hide();
        loadMap();
    } else {
        // Handle null result or error result
        const errorMessage = result && result.message ? result.message : 'Failed to update links. Server returned an error.';
        showToast(errorMessage, 'danger');
        console.error('Save server links error:', result);
    }
}

function updatePONFields(ponCount) {
    const container = document.getElementById('pon-power-fields');
    let html = '';

    for (let i = 1; i <= parseInt(ponCount); i++) {
        html += `
            <div class="form-group">
                <label>⚡ PON ${i} - Output Power (dBm)</label>
                <input type="number" step="0.01" name="pon_power_${i}" class="form-control" value="9" required>
            </div>
        `;
    }

    container.innerHTML = html;
}

/**
 * Show Server List Modal
 * Displays all servers in a clickable list
 */
function showServerListModal() {
    const servers = allMapItems.filter(item => item.item_type === 'server');
    const container = document.getElementById('server-list-container');

    if (servers.length === 0) {
        container.innerHTML = '<p class="text-muted mb-0"><i class="bi bi-info-circle"></i> No servers found</p>';
    } else {
        let html = '<div class="list-group">';
        servers.forEach(server => {
            const statusBadge = server.status === 'online' ? '🟢' :
                               server.status === 'offline' ? '🔴' : '⚪';

            // Count ODCs connected to this server (both child and standalone via pon port)
            const odcCount = allMapItems.filter(odc => {
                if (odc.item_type !== 'odc') return false;
                // Count child ODCs (parent_id = server) OR standalone ODCs connected via server_id in config
                return odc.parent_id == server.id ||
                       (odc.config?.server_id && odc.config.server_id == server.id);
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
                    const isConnected = odc.parent_id == server.id ||
                                      (odc.config?.server_id && odc.config.server_id == server.id);
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
                return odc.parent_id == server.id ||
                       (odc.config?.server_id && odc.config.server_id == server.id);
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

            // Use data attribute instead of inline onclick
            html += `
                <a href="#" class="list-group-item list-group-item-action server-item" data-server-id="${server.id}">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <strong><i class="bi bi-server"></i> ${server.name} ${statusBadge}</strong>
                            <div class="text-muted" style="font-size: 0.875rem;">
                                <small>
                                    ODC ${odcBadge} | ODP ${odpBadge} | ONU ${onuBadge} | ${parseFloat(server.latitude).toFixed(6)}, ${parseFloat(server.longitude).toFixed(6)}
                                </small>
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
            const serverItems = container.querySelectorAll('.server-item');
            serverItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    const serverId = this.getAttribute('data-server-id');
                    zoomToServer(serverId);
                });
            });
        }, 100);
    }

    const modal = new bootstrap.Modal(document.getElementById('serverListModal'));
    modal.show();
}

/**
 * Zoom to Server
 * Flies to selected server at zoom level 17.0
 */
function zoomToServer(serverId) {
    // Close modal first
    const modalElement = document.getElementById('serverListModal');
    const modalInstance = bootstrap.Modal.getInstance(modalElement);
    if (modalInstance) {
        modalInstance.hide();
    }

    // Find the server item in allMapItems
    const item = allMapItems.find(i => i.id == serverId && i.item_type === 'server');

    if (!item) {
        showToast('Server tidak ditemukan', 'danger');
        return;
    }

    // Pan and zoom to the server at level 17.0
    const lat = parseFloat(item.latitude);
    const lng = parseFloat(item.longitude);

    if (!isNaN(lat) && !isNaN(lng)) {
        // Smooth pan to location at zoom level 17.0
        map.flyTo([lat, lng], 17, {
            duration: 1.5
        });

        // Find and open popup for this server after animation
        setTimeout(() => {
            if (markers[item.id]) {
                markers[item.id].openPopup();
            }
        }, 1600);
    }
}

/**
 * Show ODC Detail
 * Opens the existing map marker popup for the selected ODC child item
 */
function showODCDetail(odcId) {
    console.log('showODCDetail called with ID:', odcId);
    console.log('allMapItems:', allMapItems);

    // Find the ODC item in allMapItems
    const odcItem = allMapItems.find(i => i.id == odcId && i.item_type === 'odc');
    console.log('Found ODC item:', odcItem);

    if (!odcItem) {
        showToast('ODC tidak ditemukan', 'danger');
        console.error('ODC not found with ID:', odcId);
        return;
    }

    console.log('ODC parent_id:', odcItem.parent_id);
    console.log('ODC config:', odcItem.config);

    // Check if ODC is a child item (parent_id is not null)
    // Child ODCs don't have markers on the map (they're hidden)
    if (odcItem.parent_id) {
        console.log('ODC is a child item - showing modal');

        // ODC is a child item - show info in a modal
        const config = odcItem.config || {};
        const statusEmoji = odcItem.status === 'online' ? '🟢' : odcItem.status === 'offline' ? '🔴' : '⚪';

        // Calculate output power per port
        const inputPower = parseFloat(config.calculated_power) || 0;
        const portCount = parseInt(config.port_count) || 4;
        const outputPowerPerPort = inputPower.toFixed(2);

        // Find all ODPs connected to this ODC
        const odpItems = allMapItems.filter(odp =>
            odp.item_type === 'odp' && odp.parent_id == odcItem.id
        );
        console.log('Found ODPs connected to ODC:', odpItems);

        // Create port mapping
        const portOdpMap = {};
        odpItems.forEach(odp => {
            if (odp.config && odp.config.odc_port) {
                portOdpMap[odp.config.odc_port] = odp.name;
            }
        });
        console.log('Port ODP map:', portOdpMap);

        // Build detail message
        let detailHtml = `
            <div style="min-width: 250px;">
                <h6 style="margin-bottom: 8px;"><strong>${odcItem.name} ${statusEmoji}</strong></h6>
                <p style="margin: 2px 0;"><small>🔌 PON Port / ${config.server_pon_port || 'N/A'}</small></p>
                <p style="margin: 2px 0;"><small>📊 Jumlah Port / ${config.port_count || 'N/A'}</small></p>
                <p style="margin: 2px 0;"><small>⚡ RX Input / ${config.calculated_power || 'N/A'} dBm</small></p>
                <hr style="margin: 6px 0;">
                <p style="margin: 2px 0;"><small><strong>🔌 Port Power</strong></small></p>
                <div style="max-height: 120px; overflow-y: auto; font-size: 11px; margin-top: 4px;">
        `;

        for (let i = 1; i <= portCount; i++) {
            const odpName = portOdpMap[i];
            if (odpName) {
                detailHtml += `<div style="padding: 2px 0;">${i} / ${outputPowerPerPort} dBm / ${odpName}</div>`;
            } else {
                detailHtml += `<div style="padding: 2px 0;">${i} / ${outputPowerPerPort} dBm</div>`;
            }
        }

        detailHtml += `
                </div>
            </div>
        `;

        console.log('Detail HTML:', detailHtml);

        // Show in a Bootstrap modal using the Edit Item modal structure
        const modalElement = document.getElementById('editItemModal');
        const modalTitle = modalElement.querySelector('.modal-title');
        const modalBody = modalElement.querySelector('.modal-body');

        console.log('Modal elements:', {
            modalElement: modalElement,
            modalTitle: modalTitle,
            modalBody: modalBody
        });

        if (modalTitle && modalBody && modalElement) {
            modalTitle.innerHTML = '<i class="bi bi-box-seam"></i> ODC Detail';
            modalBody.innerHTML = detailHtml;

            // Hide the save/cancel buttons
            const modalFooter = modalElement.querySelector('.modal-footer');
            if (modalFooter) {
                modalFooter.style.display = 'none';
            }

            const modal = new bootstrap.Modal(modalElement);
            modal.show();
            console.log('Modal shown');

            // Reset footer visibility when modal is closed
            modalElement.addEventListener('hidden.bs.modal', function() {
                if (modalFooter) {
                    modalFooter.style.display = 'flex';
                }
                // Restore original title
                modalTitle.innerHTML = '<i class="bi bi-pencil-square"></i> Edit Network Item';
            }, { once: true });
        } else {
            console.error('Modal elements not found');
            showToast('Error: Modal elements not found', 'danger');
        }
    } else {
        console.log('ODC is standalone - opening marker popup');

        // ODC is standalone - has a marker, just open its popup
        const marker = markers[odcItem.id];
        if (marker) {
            // Pan to marker and open popup
            const lat = parseFloat(odcItem.latitude);
            const lng = parseFloat(odcItem.longitude);

            if (!isNaN(lat) && !isNaN(lng)) {
                map.flyTo([lat, lng], 17, {
                    duration: 1.0
                });

                setTimeout(() => {
                    marker.openPopup();
                }, 1100);
            }
        } else {
            console.error('Marker not found for standalone ODC');
            showToast('Error: Marker not found', 'danger');
        }
    }
}