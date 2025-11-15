/**
 * devices-core.js
 * Core functionality for loading and rendering devices
 */

async function loadDevices(isAutoRefresh = false) {
    // Save scroll position before refresh (for auto-refresh)
    if (isAutoRefresh) {
        savedScrollPosition = window.pageYOffset || document.documentElement.scrollTop;
    }

    const tbody = document.getElementById('devices-tbody');

    // Don't show spinner on auto-refresh to avoid flickering
    if (!isAutoRefresh) {
        tbody.innerHTML = '<tr><td colspan="12" class="text-center"><div class="spinner"></div><div style="margin-top: 10px;">Loading devices...</div></td></tr>';
    }

    // Progressive loading: Load devices in chunks
    allDevices = [];
    let skip = 0;
    const chunkSize = 100;
    let hasMore = true;

    try {
        // Load first chunk with map data in parallel
        const [firstChunk, mapCountsResult, mapItemsResult] = await Promise.all([
            fetchAPI(`/api/get-devices.php?limit=${chunkSize}&skip=${skip}`),
            fetchAPI('/api/get-map-counts.php'),
            fetchAPI('/api/map-get-items.php')
        ]);

        if (!firstChunk || !firstChunk.success) {
            throw new Error(firstChunk?.message || 'Failed to load devices');
        }

        allDevices = firstChunk.devices || [];
        hasMore = firstChunk.hasMore;
        skip += chunkSize;

        // Update UI with first chunk immediately
        if (!isAutoRefresh) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center"><div class="spinner"></div><div style="margin-top: 10px;">Loading devices... (' + allDevices.length + ' loaded)</div></td></tr>';
        }

        // Load remaining chunks
        while (hasMore) {
            const chunk = await fetchAPI(`/api/get-devices.php?limit=${chunkSize}&skip=${skip}`);

            if (!chunk || !chunk.success) break;

            allDevices = allDevices.concat(chunk.devices || []);
            hasMore = chunk.hasMore;
            skip += chunkSize;

            // Update loading indicator
            if (!isAutoRefresh) {
                tbody.innerHTML = '<tr><td colspan="12" class="text-center"><div class="spinner"></div><div style="margin-top: 10px;">Loading devices... (' + allDevices.length + ' loaded)</div></td></tr>';
            }
        }

        // Process loaded devices
        if (allDevices.length === 0) {
            tbody.innerHTML = '<tr><td colspan="12" class="text-center">No devices found</td></tr>';
            updateDeviceCount(0, 0);
            return;
        }

        // Only reset sort state on manual refresh, maintain on auto-refresh
        if (!isAutoRefresh) {
            currentSortColumn = null;
            currentSortDirection = 'asc';
            resetSortIcons();

            // Generate initial table header for "ONU" tab (default)
            generateTableHeader('onu');

            // Update search placeholder for ONU tab (default)
            updateSearchPlaceholder('onu');
        }

        // Re-apply current filter and sorting
        if (currentFilterType === 'onu') {
            // Show ALL devices from GenieACS (no filtering by product_class)
            let devicesToRender = allDevices;

            // Re-apply search filter if active
            const searchTerm = document.getElementById('search-input')?.value.toLowerCase().trim() || '';
            if (searchTerm !== '') {
                devicesToRender = allDevices.filter(device => {
                    const serialNumber = (device.serial_number || '').toLowerCase();
                    const macAddress = (device.mac_address || '').toLowerCase();

                    // Search in tags array
                    let tagsMatch = false;
                    if (device.tags && Array.isArray(device.tags) && device.tags.length > 0) {
                        tagsMatch = device.tags.some(tag => tag.toLowerCase().includes(searchTerm));
                    }

                    return serialNumber.includes(searchTerm) || macAddress.includes(searchTerm) || tagsMatch;
                });
            }

            // Re-apply sorting if active
            if (currentSortColumn) {
                devicesToRender = applySorting(devicesToRender, currentSortColumn, currentSortDirection);
            }

            renderDevices(devicesToRender);
            updateDeviceCount(devicesToRender.length, allDevices.length);
            updateDeviceStats(allDevices);
        } else {
            // For infrastructure tabs, re-render map items
            renderMapItems(currentFilterType);
            updateDeviceStats([], false);
        }

        // Update tab counts using map data
        if (mapCountsResult && mapCountsResult.success) {
            updateDeviceTypeCountsFromMap(allDevices, mapCountsResult.counts);
        } else {
            updateDeviceTypeCountsFromMap(allDevices, {});
        }

        // Restore scroll position and sort icons after auto-refresh
        if (isAutoRefresh) {
            if (savedScrollPosition > 0) {
                setTimeout(() => {
                    window.scrollTo(0, savedScrollPosition);
                }, 100); // Small delay to ensure DOM is updated
            }

            // Restore sort icons if sorting is active
            if (currentSortColumn) {
                setTimeout(() => {
                    updateSortIcons(currentSortColumn, currentSortDirection);
                }, 50);
            }
        }
    } catch (error) {
        console.error('Error loading devices:', error);
        tbody.innerHTML = '<tr><td colspan="12" class="text-center text-danger">Failed to load devices: ' + error.message + '</td></tr>';
        updateDeviceCount(0, 0);
        updateDeviceStats([]);
        updateDeviceTypeCountsFromMap([], {}); // Reset counts
    }
}
async function renderDevices(devices) {
    const tbody = document.getElementById('devices-tbody');
    tbody.innerHTML = '';

    // Determine appropriate colspan based on current filter type
    const colspan = (currentFilterType === 'onu') ? 12 : 6;

    if (devices.length === 0) {
        // If showing infrastructure items, show map items instead
        if (currentFilterType !== 'onu') {
            renderMapItems(currentFilterType);
            return;
        }
        tbody.innerHTML = `<tr><td colspan="${colspan}" class="text-center">No devices found</td></tr>`;
        updatePaginationUI(0);
        return;
    }

    // Store total for pagination
    totalDevices = devices.length;

    // Apply pagination (slice devices array)
    let devicesToRender = devices;
    if (itemsPerPage > 0) {
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = startIndex + itemsPerPage;
        devicesToRender = devices.slice(startIndex, endIndex);
    }

    // Update pagination UI
    updatePaginationUI(totalDevices);

    // Fetch map status for all devices on current page using BATCH API
    const serialNumbers = devicesToRender.map(device => device.serial_number);

    let mapStatusMap = {};

    try {
        const batchResult = await fetchAPI('/api/get-onu-location-batch.php', {
            method: 'POST',
            body: JSON.stringify({ serial_numbers: serialNumbers })
        });

        if (batchResult && batchResult.success && batchResult.locations) {
            // Convert batch result to map status format
            Object.keys(batchResult.locations).forEach(serial => {
                const location = batchResult.locations[serial];
                mapStatusMap[serial] = {
                    inMap: location.found || false,
                    itemType: location.item_type || 'onu',
                    itemId: location.onu?.id || location.server?.id || null
                };
            });
        }
    } catch (error) {
        console.error('Batch map status fetch failed:', error);
        // Fallback: all devices marked as not in map
        devicesToRender.forEach(device => {
            mapStatusMap[device.serial_number] = {
                inMap: false,
                itemType: 'onu',
                itemId: null
            };
        });
    }

    devicesToRender.forEach(device => {
        const row = document.createElement('tr');
        const ipAddress = extractIP(device.ip_tr069);
        const mapInfo = mapStatusMap[device.serial_number] || { inMap: false, itemType: 'onu', itemId: null };
        const isInMap = mapInfo.inMap;

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
            mapButton = `<button class="btn btn-sm btn-success me-1" onclick="window.open('${mapUrl}', '_blank')" title="View on Map">
                <i class="bi bi-map"></i>
            </button>`;
        } else {
            // Gray button - shows alert
            mapButton = `<button class="btn btn-sm btn-secondary me-1" onclick="showNotInMapAlert('${encodeURIComponent(device.serial_number)}')" title="Not Registered in Map">
                <i class="bi bi-map"></i>
            </button>`;
        }

        // Status badge with ping
        let statusDisplay;
        if (device.status === 'online') {
            const ping = device.ping || '-';
            statusDisplay = `<span class="badge online">ON [${ping}ms]</span>`;
        } else {
            statusDisplay = `<span class="badge offline">OFF [-]</span>`;
        }

        // Tags display - show as badges
        let tagsDisplay = '';
        let tagsSortValue = '';
        if (device.tags && Array.isArray(device.tags) && device.tags.length > 0) {
            tagsDisplay = device.tags.map(tag => `<span class="badge bg-info me-1">${tag}</span>`).join('');
            tagsSortValue = device.tags.join(', '); // For sorting: join tags as string
        } else {
            tagsDisplay = '<span class="text-muted">-</span>';
            tagsSortValue = ''; // Empty for sorting (will be sorted to bottom)
        }

        // Check tags column visibility state for consistent display
        const tagsColumnDisplay = tagsColumnVisible ? '' : 'none';

        row.innerHTML = `
            <td>
                <input type="checkbox" class="device-checkbox" value="${encodeURIComponent(device.device_id)}" onchange="updateBulkActionButtons()">
            </td>
            <td><a href="/device-detail.php?id=${encodeURIComponent(device.device_id)}">${device.serial_number}</a></td>
            <td>${device.mac_address}</td>
            <td data-sort-value="${device.product_class || ''}">${device.product_class || 'N/A'}</td>
            <td data-sort-value="${ipAddress}">${ipDisplay}</td>
            <td data-sort-value="${device.wifi_ssid}">${device.wifi_ssid}</td>
            <td data-sort-value="${device.pppoe_username || ''}">${device.pppoe_username || 'N/A'}</td>
            <td data-sort-value="${parseFloat(device.rx_power) || -999}">${rxDisplay}</td>
            <td data-sort-value="${parseFloat(device.temperature) || -999}">${device.temperature}°C</td>
            <td data-sort-value="${clientsCount}" class="text-center">${clientsBadge}</td>
            <td data-sort-value="${device.status}">${statusDisplay}</td>
            <td class="tags-column" data-sort-value="${tagsSortValue}" style="display: ${tagsColumnDisplay};">${tagsDisplay}</td>
            <td>
                ${mapButton}
                <button class="btn btn-sm btn-primary" onclick="summonDeviceQuick('${device.device_id}')" title="Summon Device">
                    <i class="bi bi-lightning-charge"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

// Render map items (for infrastructure: Server, OLT, ODC, ODP)

// Core module loaded successfully
