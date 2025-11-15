/**
 * GACS Dashboard - Map Polylines & Waypoints Module
 *
 * This module handles editable polyline routing with custom waypoints for connection lines.
 *
 * Features:
 * - Interactive context menu for polyline operations (right-click on connection lines)
 * - Enable/disable edit mode with Leaflet.Editable plugin
 * - Drag waypoints to customize connection paths
 * - Save custom routes to database with persistence
 * - Reset polylines to default straight paths
 * - Auto-load waypoints on map initialization
 * - Three-layer polyline rendering (shadow, border, main) with synchronized editing
 * - Button-based edit mode toggle for easier UX
 *
 * Dependencies:
 * - Leaflet.js
 * - Leaflet.Editable plugin
 * - Global variables: map, waypoints, polylineData, currentEditingPolyline
 * - Global functions: showToast(), showLoading(), hideLoading(), fetchAPI()
 * - Global functions: pauseAutoRefresh(), resumeAutoRefresh()
 */

// Global edit mode state (accessible from other modules)
window.editLineModeActive = false;

// Global function to force marker visibility (accessible from other modules)
window.forceMarkerVisibility = function() {
    const vertexMarkers = document.querySelectorAll('.leaflet-vertex-icon');
    const middleMarkers = document.querySelectorAll('.leaflet-middle-icon');

    // Only log if marker count is 0 (problem detected)
    if (vertexMarkers.length === 0 && middleMarkers.length === 0) {
        console.warn('⚠️ No markers found - use zoom level 16-19 for optimal visibility');
    }

    // Force inline styles on every vertex marker
    vertexMarkers.forEach((marker) => {
        // CRITICAL: Reset transform to prevent off-screen positioning
        const currentTransform = marker.style.transform || '';

        marker.style.cssText = `
            width: 30px !important;
            height: 30px !important;
            margin-left: -15px !important;
            margin-top: -15px !important;
            background: #3B82F6 !important;
            border: 5px solid #fff !important;
            border-radius: 50% !important;
            box-shadow: 0 0 0 3px #3B82F6, 0 0 0 6px rgba(255,255,255,0.8), 0 4px 15px rgba(0,0,0,0.6) !important;
            cursor: move !important;
            display: block !important;
            opacity: 1 !important;
            visibility: visible !important;
            pointer-events: auto !important;
            z-index: 999999 !important;
            position: absolute !important;
            transform-origin: center center !important;
            transition: none !important;
            transform: ${currentTransform} !important;
        `;
    });

    // Force inline styles on every middle marker
    middleMarkers.forEach((marker) => {
        // CRITICAL: Preserve transform for positioning
        const currentTransform = marker.style.transform || '';

        marker.style.cssText = `
            width: 22px !important;
            height: 22px !important;
            margin-left: -11px !important;
            margin-top: -11px !important;
            background: #FBBF24 !important;
            border: 4px solid #fff !important;
            border-radius: 50% !important;
            box-shadow: 0 2px 8px rgba(251, 191, 36, 0.6), 0 0 0 2px rgba(251, 191, 36, 0.2) !important;
            cursor: crosshair !important;
            display: block !important;
            opacity: 0.95 !important;
            visibility: visible !important;
            pointer-events: auto !important;
            z-index: 99999 !important;
            position: absolute !important;
            transform-origin: center center !important;
            transition: none !important;
            transform: ${currentTransform} !important;
        `;
    });
};

// Toggle edit line mode on/off
async function toggleEditLineMode() {
    window.editLineModeActive = !window.editLineModeActive;

    const toggleBtn = document.getElementById('edit-line-mode-toggle');
    const toggleText = document.getElementById('edit-line-mode-text');

    if (window.editLineModeActive) {
        // Entering edit mode
        // CRITICAL: Set flags FIRST before anything else to block loadMap()
        window.isEditingPolyline = true;

        toggleBtn.classList.remove('btn-warning');
        toggleBtn.classList.add('btn-success');
        toggleText.innerHTML = '<i class="bi bi-floppy"></i> Simpan & Keluar';

        // CRITICAL: Auto-zoom to level 16 when entering edit mode
        const currentZoom = map.getZoom();
        if (currentZoom !== 16) {
            map.setZoom(16, { animate: true });
            console.log(`🔍 Auto-zoom to level 16 (from ${currentZoom})`);
        }

        // CRITICAL: Set min/max zoom limits for edit mode (16-19 only)
        map.setMinZoom(16);
        map.setMaxZoom(19);

        // Re-enable zoom controls but limit to 16-19 range
        map.touchZoom.enable();
        map.doubleClickZoom.enable();
        map.scrollWheelZoom.enable();
        map.boxZoom.enable();
        map.keyboard.enable();
        if (map.tap) map.tap.enable();

        // Pause auto-refresh AFTER setting flag
        pauseAutoRefresh();

        // CRITICAL: Remove location pointer when entering edit mode
        if (window.locationPointer) {
            map.removeLayer(window.locationPointer);
            window.locationPointer = null;
            window.pointerVisible = false;
            console.log('🗑️ Location pointer removed for edit mode');
        }

        // CRITICAL: Close all open popups to prevent interference
        map.closePopup();

        // CRITICAL: Disable popup opening during edit mode
        map.on('popupopen', function(e) {
            if (window.editLineModeActive) {
                console.log('🚫 Blocked popup open during edit mode');
                map.closePopup();
            }
        });

        showToast('Mode Edit Garis Aktif! Zoom di-set ke level 16. Klik garis yang ingin diedit, drag marker untuk customize jalur.', 'info', 15000);
        console.log('✏️ Edit Line Mode ACTIVATED');

        // Change cursor to crosshair on polylines
        Object.values(polylineData).forEach(data => {
            if (data.mainPolyline) {
                data.mainPolyline.getElement().style.cursor = 'pointer';
            }
        });
    } else {
        // Exiting edit mode - SAVE changes first if polyline is being edited
        if (window.currentEditingPolyline) {
            const polyline = window.currentEditingPolyline;
            const connectionData = polyline.connectionData;

            if (connectionData && polyline.editEnabled && polyline.editEnabled()) {
                console.log('💾 Saving polyline changes before exit...');

                // Get all latlngs from polyline (excluding first and last which are markers)
                const latlngs = polyline.getLatLngs();
                const waypointsToSave = [];

                // Skip first (parent marker) and last (child marker) points
                for (let i = 1; i < latlngs.length - 1; i++) {
                    waypointsToSave.push({
                        lat: latlngs[i].lat,
                        lng: latlngs[i].lng
                    });
                }

                // Save to database
                showLoading();
                const result = await fetchAPI('/api/map-save-waypoints.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        parent_id: connectionData.parentId,
                        child_id: connectionData.childId,
                        waypoints: waypointsToSave
                    })
                });
                hideLoading();

                if (result && result.success) {
                    showToast(`✅ Perubahan disimpan (${waypointsToSave.length} titik waypoint)`, 'success', 15000);

                    // Update global waypoints storage
                    waypoints[connectionData.connectionKey] = waypointsToSave;

                    // Update polyline display immediately
                    const newCoords = [
                        connectionData.parentMarker.getLatLng(),
                        ...latlngs.slice(1, -1),
                        connectionData.childMarker.getLatLng()
                    ];

                    if (polylineData[connectionData.connectionKey]) {
                        const { shadowPolyline, borderPolyline, mainPolyline } = polylineData[connectionData.connectionKey];
                        if (shadowPolyline) shadowPolyline.setLatLngs(newCoords);
                        if (borderPolyline) borderPolyline.setLatLngs(newCoords);
                        if (mainPolyline) mainPolyline.setLatLngs(newCoords);
                    }
                } else {
                    showToast('⚠️ Gagal menyimpan perubahan', 'danger', 15000);
                }
            }

            // Restore original clearLayers method if it was overridden
            if (polyline.editor && polyline.editor.editLayer && polyline.editor.editLayer._originalClearLayers) {
                polyline.editor.editLayer.clearLayers = polyline.editor.editLayer._originalClearLayers;
            }

            // Disable edit mode
            if (polyline.editEnabled && polyline.editEnabled()) {
                polyline.disableEdit();
            }
            window.currentEditingPolyline = null;
        }

        // Update button appearance
        toggleBtn.classList.remove('btn-success');
        toggleBtn.classList.add('btn-warning');
        toggleText.innerHTML = '<i class="bi bi-pencil"></i> Edit Garis';

        // CRITICAL: Restore original zoom limits
        map.setMinZoom(1);  // Default Leaflet minimum
        map.setMaxZoom(19); // Default from map initialization

        // Re-enable zoom controls with default limits
        map.touchZoom.enable();
        map.doubleClickZoom.enable();
        map.scrollWheelZoom.enable();
        map.boxZoom.enable();
        map.keyboard.enable();
        if (map.tap) map.tap.enable();

        // Resume auto-refresh
        resumeAutoRefresh();
        window.isEditingPolyline = false;

        showToast('Mode Edit Garis Dinonaktifkan & Perubahan Disimpan', 'info', 15000);
        console.log('❌ Edit Line Mode DEACTIVATED');

        // Restore default cursor
        Object.values(polylineData).forEach(data => {
            if (data.mainPolyline) {
                data.mainPolyline.getElement().style.cursor = '';
            }
        });
    }
}

// Show context menu on polyline right-click
function showPolylineContextMenu(e, polyline) {
    const contextMenu = document.getElementById('polyline-context-menu');
    contextMenu.style.display = 'block';
    contextMenu.style.left = e.originalEvent.pageX + 'px';
    contextMenu.style.top = e.originalEvent.pageY + 'px';

    // Store reference to current polyline
    window.currentEditingPolyline = polyline;
}

// Close context menu
function closeContextMenu(skipDisableEdit = false) {
    console.log(`🚪 closeContextMenu called - skipDisableEdit: ${skipDisableEdit}`);

    const contextMenu = document.getElementById('polyline-context-menu');
    contextMenu.style.display = 'none';

    // If there's an editing polyline, disable edit mode and resume auto-refresh
    // UNLESS we're just closing the menu after enabling edit (skipDisableEdit = true)
    if (window.currentEditingPolyline && !skipDisableEdit) {
        const polyline = window.currentEditingPolyline;
        if (polyline.editEnabled && polyline.editEnabled()) {
            console.log('⚠️ DISABLING EDIT MODE from closeContextMenu');
            console.trace(); // Show stack trace to see who called this
            polyline.disableEdit();
            resumeAutoRefresh();
            // Clear editing state
            window.isEditingPolyline = false;
            // DON'T reset _hasInterpolated - keep interpolated points
        }
    } else if (skipDisableEdit) {
        console.log('✓ Skipping disable edit - menu closed but editor still active');
    }

    // Only clear currentEditingPolyline if we're not in skipDisableEdit mode
    if (!skipDisableEdit) {
        window.currentEditingPolyline = null;
    }
}

// Hide context menu when clicking elsewhere
document.addEventListener('click', function(e) {
    const contextMenu = document.getElementById('polyline-context-menu');

    // CRITICAL: In button edit mode, only handle context menu, don't block all clicks
    if (window.editLineModeActive) {
        // Check if clicking on a polyline (SVG path element)
        const isPolylineClick = e.target.tagName === 'path' ||
                               e.target.classList.contains('leaflet-interactive') ||
                               e.target.closest('.leaflet-overlay-pane');

        if (isPolylineClick) {
            console.log('✅ Polyline click detected - allowing through');
            // Don't block polyline clicks
            return;
        }

        console.log('🔒 Button edit mode active - ignoring non-polyline click');
        return;
    }

    // Don't auto-close if context menu is already hidden (it was closed by a menu action)
    if (!contextMenu || contextMenu.style.display === 'none') {
        return;
    }

    // Close menu if clicking outside of it
    if (!contextMenu.contains(e.target)) {
        // If we're in edit mode, pass skipDisableEdit=true to keep editor active
        if (window.isEditingPolyline && window.currentEditingPolyline) {
            console.log('🔒 Menu closed by click outside, but keeping editor active');
            closeContextMenu(true);
        } else {
            closeContextMenu();
        }
    }
});

// Enable polyline editing mode
function enablePolylineEdit() {
    console.log('📝 enablePolylineEdit() called');
    console.log('  editLineModeActive:', window.editLineModeActive);
    console.log('  currentEditingPolyline:', window.currentEditingPolyline);

    if (!window.currentEditingPolyline) {
        showToast('Tidak ada polyline yang dipilih', 'warning');
        if (!window.editLineModeActive) {
            closeContextMenu();
        }
        return;
    }

    const polyline = window.currentEditingPolyline;
    const connectionData = polyline.connectionData;

    if (!connectionData) {
        showToast('Data koneksi tidak ditemukan', 'error');
        if (!window.editLineModeActive) {
            closeContextMenu();
        }
        return;
    }

    // Check if Leaflet.Editable is available
    if (!map || !map.editTools) {
        showToast('Edit mode belum siap. Silakan refresh halaman.', 'error');
        if (!window.editLineModeActive) {
            closeContextMenu();
        }
        return;
    }

    // Check if already in edit mode (from right-click menu)
    if (polyline.editEnabled && polyline.editEnabled()) {
        // Only disable if NOT in button mode (right-click menu toggle behavior)
        if (!window.editLineModeActive) {
            console.log('⚠️ Polyline already in edit mode - disabling (right-click toggle)');
            polyline.disableEdit();

            // Disconnect marker observer
            if (window.markerObserver) {
                window.markerObserver.disconnect();
                window.markerObserver = null;
            }

            // Resume auto-refresh when exiting edit mode
            resumeAutoRefresh();

            // Clear editing state
            window.isEditingPolyline = false;
            // DON'T reset _hasInterpolated flag - keep interpolated points

            showToast('Edit mode dinonaktifkan.', 'info');
        } else {
            // In button mode, polyline already edited - do nothing
            console.log('✓ Polyline already in edit mode (button mode) - keeping it active');
        }
    } else {
        // Enable editing using Leaflet.Editable
        try {
            // PAUSE auto-refresh to prevent interruption during editing
            pauseAutoRefresh();

            // Set editing state to prevent location pointer from appearing
            window.isEditingPolyline = true;

            // Verify map.editTools exists
            if (!map.editTools) {
                console.error('Leaflet.Editable not initialized');
                showToast('Edit mode belum siap. Silakan refresh halaman dan coba lagi.', 'error');
                closeContextMenu();
                return;
            }

            // Verify polyline has enableEdit method
            if (typeof polyline.enableEdit !== 'function') {
                console.error('Polyline does not have enableEdit method', polyline);
                showToast('Polyline tidak mendukung edit mode.', 'error');
                closeContextMenu();
                return;
            }

            // CRITICAL: Always add interpolation points if polyline has < 10 points
            // This ensures markers appear even for previously edited lines
            const currentPointCount = polyline.getLatLngs().length;
            const needsInterpolation = currentPointCount < 10;

            if (needsInterpolation) {
                console.log(`📊 Polyline has ${currentPointCount} points - adding interpolation for better marker visibility`);
                const latlngs = polyline.getLatLngs();

                // Calculate total distance of polyline
                let totalDistance = 0;
                for (let i = 0; i < latlngs.length - 1; i++) {
                    totalDistance += latlngs[i].distanceTo(latlngs[i + 1]);
                }

                // Determine optimal marker count based on distance
                let targetMarkerCount;
                if (totalDistance < 200) {
                    targetMarkerCount = Math.max(6, Math.ceil(totalDistance / 30));
                } else if (totalDistance < 500) {
                    targetMarkerCount = Math.max(8, Math.ceil(totalDistance / 50));
                } else {
                    targetMarkerCount = Math.max(10, Math.min(15, Math.ceil(totalDistance / 60)));
                }

                const segmentDistance = totalDistance / (targetMarkerCount - 1);

                // Create array of evenly-spaced points along the polyline
                const interpolatedPoints = [];
                interpolatedPoints.push(latlngs[0]); // Start point

                let currentDistance = 0;
                let nextTargetDistance = segmentDistance;

                for (let i = 0; i < latlngs.length - 1; i++) {
                    const start = latlngs[i];
                    const end = latlngs[i + 1];
                    const segmentLength = start.distanceTo(end);

                    // Add interpolated points within this segment
                    while (nextTargetDistance <= currentDistance + segmentLength && nextTargetDistance < totalDistance) {
                        const ratio = (nextTargetDistance - currentDistance) / segmentLength;
                        const lat = start.lat + (end.lat - start.lat) * ratio;
                        const lng = start.lng + (end.lng - start.lng) * ratio;
                        interpolatedPoints.push(L.latLng(lat, lng));
                        nextTargetDistance += segmentDistance;
                    }

                    currentDistance += segmentLength;
                }

                interpolatedPoints.push(latlngs[latlngs.length - 1]); // End point

                // Store interpolated points in polyline object
                polyline._interpolatedPoints = interpolatedPoints;
                polyline._hasInterpolated = true;

                // Update polyline with interpolated points
                polyline.setLatLngs(interpolatedPoints);

                // Sync shadow and border polylines
                if (connectionData && polylineData[connectionData.connectionKey]) {
                    const shadowPolyline = polylineData[connectionData.connectionKey].shadowPolyline;
                    const borderPolyline = polylineData[connectionData.connectionKey].borderPolyline;

                    if (shadowPolyline) shadowPolyline.setLatLngs(interpolatedPoints);
                    if (borderPolyline) borderPolyline.setLatLngs(interpolatedPoints);
                }
            } else {
                console.log(`✓ Polyline has ${currentPointCount} points - sufficient for marker visibility, skipping interpolation`);
            }

            // Enable edit mode on polyline to show vertex markers
            console.log('🔧 Enabling edit mode on polyline...');
            console.log('Polyline has enableEdit:', typeof polyline.enableEdit === 'function');
            console.log('Polyline latlngs count:', polyline.getLatLngs().length);
            console.log('Polyline coordinates sample:', polyline.getLatLngs().slice(0, 3));

            // CRITICAL: If polyline was already in edit mode, disable it first to force refresh
            if (polyline.editEnabled && polyline.editEnabled()) {
                console.log('⚠️ Polyline already in edit mode - disabling first to refresh');
                polyline.disableEdit();
            }

            polyline.enableEdit();

            console.log('✓ enableEdit() called');
            console.log('Edit enabled?', polyline.editEnabled ? polyline.editEnabled() : 'no editEnabled method');

            // Check if editor was created
            if (polyline.editor) {
                console.log('✓ Polyline editor exists:', polyline.editor);
                console.log('Editor has editLayer?', !!polyline.editor.editLayer);
                if (polyline.editor.editLayer) {
                    const layers = polyline.editor.editLayer.getLayers ? polyline.editor.editLayer.getLayers() : [];
                    console.log('EditLayer children count:', layers.length);
                    if (layers.length > 0) {
                        // Log first few marker positions
                        const firstMarkers = layers.slice(0, 3);
                        console.log('First 3 marker positions:', firstMarkers.map(m => m.getLatLng ? m.getLatLng() : 'no position'));
                    }
                }
            } else {
                console.warn('⚠️ Polyline editor NOT created after enableEdit()');
            }

            // Apply custom styling to vertex markers for better visibility
            // Use global forceMarkerVisibility function defined at top of file

            // Apply immediately
            setTimeout(window.forceMarkerVisibility, 100);
            // Apply again to catch any delayed creation
            setTimeout(window.forceMarkerVisibility, 500);
            // Apply one more time to be absolutely sure
            setTimeout(window.forceMarkerVisibility, 1000);
            // Add extra long delay to catch very late creation
            setTimeout(window.forceMarkerVisibility, 2000);
            // CRITICAL: Keep-alive to maintain marker visibility
            const keepAliveInterval = setInterval(() => {
                if (window.editLineModeActive && polyline.editEnabled && polyline.editEnabled()) {
                    const currentVertexCount = document.querySelectorAll('.leaflet-vertex-icon').length;
                    const currentMiddleCount = document.querySelectorAll('.leaflet-middle-icon').length;
                    const totalMarkers = currentVertexCount + currentMiddleCount;

                    if (totalMarkers === 0) {
                        console.warn('⚠️ Markers disappeared - adjust zoom to range 16-19');
                        // Try to recreate editor
                        if (polyline.editor && polyline.editor.editLayer) {
                            polyline.editor.editLayer.clearLayers();
                            polyline.disableEdit();
                            setTimeout(() => {
                                polyline.enableEdit();
                                setTimeout(() => window.forceMarkerVisibility(), 100);
                            }, 50);
                        }
                    } else {
                        // Normal keep-alive - just re-apply styles silently
                        window.forceMarkerVisibility();
                    }
                } else {
                    clearInterval(keepAliveInterval);
                }
            }, 100);

            // Final diagnostic check after all delays
            setTimeout(() => {
                const finalVertexCount = document.querySelectorAll('.leaflet-vertex-icon').length;
                const finalMiddleCount = document.querySelectorAll('.leaflet-middle-icon').length;

                if (finalVertexCount === 0 && finalMiddleCount === 0) {
                    console.warn('⚠️ No markers visible - zoom range 16-19 recommended. Current zoom:', map.getZoom());
                } else {
                    console.log(`✅ Edit mode ready (${finalVertexCount} vertex, ${finalMiddleCount} middle markers)`);
                }
            }, 3000);

            // CRITICAL: Use MutationObserver to watch for style/attribute changes that hide markers
            // Store globally so we can disconnect it later
            if (window.markerObserver) {
                window.markerObserver.disconnect();
            }

            window.markerObserver = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    // Watch for new markers being added
                    mutation.addedNodes.forEach((node) => {
                        if (node.classList && (
                            node.classList.contains('leaflet-vertex-icon') ||
                            node.classList.contains('leaflet-middle-icon')
                        )) {
                            // Immediately force visibility on new marker (silent)
                            if (node.classList.contains('leaflet-vertex-icon')) {
                                node.style.cssText = `
                                    width: 30px !important;
                                    height: 30px !important;
                                    margin-left: -15px !important;
                                    margin-top: -15px !important;
                                    background: #3B82F6 !important;
                                    border: 5px solid #fff !important;
                                    border-radius: 50% !important;
                                    box-shadow: 0 0 0 3px #3B82F6, 0 0 0 6px rgba(255,255,255,0.8), 0 4px 15px rgba(0,0,0,0.6) !important;
                                    cursor: move !important;
                                    display: block !important;
                                    opacity: 1 !important;
                                    visibility: visible !important;
                                    pointer-events: auto !important;
                                    z-index: 999999 !important;
                                    position: absolute !important;
                                    transform-origin: center center !important;
                                    transition: none !important;
                                `;
                            } else {
                                node.style.cssText = `
                                    width: 22px !important;
                                    height: 22px !important;
                                    margin-left: -11px !important;
                                    margin-top: -11px !important;
                                    background: #FBBF24 !important;
                                    border: 4px solid #fff !important;
                                    border-radius: 50% !important;
                                    box-shadow: 0 2px 8px rgba(251, 191, 36, 0.6), 0 0 0 2px rgba(251, 191, 36, 0.2) !important;
                                    cursor: crosshair !important;
                                    display: block !important;
                                    opacity: 0.95 !important;
                                    visibility: visible !important;
                                    pointer-events: auto !important;
                                    z-index: 99999 !important;
                                    position: absolute !important;
                                    transform-origin: center center !important;
                                    transition: none !important;
                                `;
                            }
                        }
                    });

                    // CRITICAL: Watch for STYLE and ATTRIBUTE changes that might hide markers
                    if (mutation.type === 'attributes' && (mutation.attributeName === 'style' || mutation.attributeName === 'class')) {
                        const node = mutation.target;
                        if (node.classList && (
                            node.classList.contains('leaflet-vertex-icon') ||
                            node.classList.contains('leaflet-middle-icon')
                        )) {
                            // Check if marker was hidden
                            const computedStyle = window.getComputedStyle(node);
                            const isHidden = computedStyle.display === 'none' ||
                                           computedStyle.opacity === '0' ||
                                           computedStyle.visibility === 'hidden' ||
                                           node.style.display === 'none' ||
                                           node.style.opacity === '0' ||
                                           node.style.visibility === 'hidden';

                            if (isHidden) {
                                console.error('⚠️ MARKER HIDDEN by style change!', node.className);
                                console.log('  Display:', computedStyle.display, '→ forcing block');
                                console.log('  Opacity:', computedStyle.opacity, '→ forcing 1');
                                console.log('  Visibility:', computedStyle.visibility, '→ forcing visible');

                                // Force visibility immediately
                                window.forceMarkerVisibility();
                            }
                        }
                    }

                    // Watch for markers being REMOVED (silent - normal behavior during save)
                    mutation.removedNodes.forEach((node) => {
                        if (node.classList && (
                            node.classList.contains('leaflet-vertex-icon') ||
                            node.classList.contains('leaflet-middle-icon')
                        )) {
                            // Marker removal is normal when saving - don't log unless unexpected
                            if (window.editLineModeActive && polyline.editEnabled && polyline.editEnabled()) {
                                // Only log if markers removed while still in active edit mode (unexpected)
                                console.warn('⚠️ Marker removed during active edit - may need zoom adjustment');
                            }
                        }
                    });
                });
            });

            // Observe the map pane for new markers AND style changes
            const mapPane = document.querySelector('.leaflet-map-pane');
            if (mapPane) {
                // CRITICAL: Watch for childList, attributes (style/class changes), and subtree
                window.markerObserver.observe(mapPane, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['style', 'class']
                });
            }

            // CRITICAL: Protect marker pane from being cleared by Leaflet
            // Override the marker pane's layer management to prevent clearing
            if (polyline.editor && polyline.editor.editLayer) {
                const editLayer = polyline.editor.editLayer;

                // Store original clearLayers method
                const originalClearLayers = editLayer.clearLayers;

                // Override clearLayers to prevent accidental clearing
                editLayer.clearLayers = function() {
                    // Silently block clearLayers() to protect markers
                    // Don't call original - just block it
                };

                // Store original method for later restoration
                editLayer._originalClearLayers = originalClearLayers;
            }

            showToast('Edit mode aktif! Drag titik-titik untuk mengubah jalur. Klik garis untuk menambah titik waypoint. Auto-refresh dinonaktifkan sementara.', 'success');

            // Add event listeners to sync shadow and border polylines when main polyline is edited
            const syncPolylineLayers = function() {
                if (connectionData && polylineData[connectionData.connectionKey]) {
                    const latlngs = polyline.getLatLngs();
                    const shadowPolyline = polylineData[connectionData.connectionKey].shadowPolyline;
                    const borderPolyline = polylineData[connectionData.connectionKey].borderPolyline;

                    if (shadowPolyline) {
                        shadowPolyline.setLatLngs(latlngs);
                    }
                    if (borderPolyline) {
                        borderPolyline.setLatLngs(latlngs);
                    }
                }
            };

            // Remove old event listeners first to avoid duplicates
            polyline.off('editable:vertex:drag');
            polyline.off('editable:vertex:dragend');
            polyline.off('editable:vertex:new');
            polyline.off('editable:vertex:deleted');

            // Sync on vertex drag
            polyline.on('editable:vertex:drag', syncPolylineLayers);
            polyline.on('editable:vertex:dragend', syncPolylineLayers);

            // Sync on new vertex added
            polyline.on('editable:vertex:new', syncPolylineLayers);

            // Sync on vertex deleted
            polyline.on('editable:vertex:deleted', syncPolylineLayers);

            // CRITICAL: Only close context menu if NOT in button mode
            // In button mode, context menu is already hidden
            if (!window.editLineModeActive) {
                // Pass skipDisableEdit=true to prevent disabling the editor we just created
                closeContextMenu(true);
            } else {
                console.log('✓ Button mode - skipping closeContextMenu()');
            }
        } catch (error) {
            console.error('❌ Error enabling edit mode:', error);
            showToast('Gagal mengaktifkan edit mode', 'error');
            if (!window.editLineModeActive) {
                closeContextMenu();
            }
        }
    }

    // DON'T close context menu here - it's already closed inside the if/else blocks
    // closeContextMenu();
}

// Save polyline waypoints to database
async function savePolylineWaypoints() {
    if (!window.currentEditingPolyline) {
        showToast('Tidak ada polyline yang dipilih', 'warning');
        closeContextMenu();
        return;
    }

    const polyline = window.currentEditingPolyline;
    const connectionData = polyline.connectionData;

    if (!connectionData) {
        showToast('Data koneksi tidak ditemukan', 'danger');
        closeContextMenu();
        return;
    }

    // Get all latlngs from polyline (excluding first and last which are markers)
    const latlngs = polyline.getLatLngs();
    const waypoints = [];

    // Skip first (parent marker) and last (child marker) points
    for (let i = 1; i < latlngs.length - 1; i++) {
        waypoints.push({
            lat: latlngs[i].lat,
            lng: latlngs[i].lng
        });
    }

    showLoading();

    const result = await fetchAPI('/api/map-save-waypoints.php', {
        method: 'POST',
        body: JSON.stringify({
            parent_id: connectionData.parentId,
            child_id: connectionData.childId,
            waypoints: waypoints
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast(`Waypoints berhasil disimpan (${waypoints.length} titik)`, 'success');

        // Update global waypoints storage
        waypoints[connectionData.connectionKey] = waypoints;

        // Update polyline display immediately without reload
        const newCoords = [
            connectionData.parentMarker.getLatLng(),
            ...latlngs.slice(1, -1), // Keep the waypoints
            connectionData.childMarker.getLatLng()
        ];

        // Update all three layers (shadow, border, main)
        if (polylineData[connectionData.connectionKey]) {
            const { shadowPolyline, borderPolyline, mainPolyline } = polylineData[connectionData.connectionKey];
            if (shadowPolyline) shadowPolyline.setLatLngs(newCoords);
            if (borderPolyline) borderPolyline.setLatLngs(newCoords);
            if (mainPolyline) mainPolyline.setLatLngs(newCoords);
        }

        // Disable edit mode after saving
        if (polyline.editEnabled && polyline.editEnabled()) {
            polyline.disableEdit();
        }

        // Resume auto-refresh after saving
        resumeAutoRefresh();

        // Clear editing state
        window.isEditingPolyline = false;
        // DON'T reset _hasInterpolated - keep interpolated points for next edit
    } else {
        showToast(result.message || 'Gagal menyimpan waypoints', 'danger');
    }

    closeContextMenu();
}

// Reset polyline to straight line (remove all waypoints)
async function resetPolylineToStraight() {
    if (!window.currentEditingPolyline) {
        showToast('Tidak ada polyline yang dipilih', 'warning');
        closeContextMenu();
        return;
    }

    if (!confirm('Apakah Anda yakin ingin menghapus semua waypoints dan kembali ke garis lurus?')) {
        closeContextMenu();
        return;
    }

    const polyline = window.currentEditingPolyline;
    const connectionData = polyline.connectionData;

    if (!connectionData) {
        showToast('Data koneksi tidak ditemukan', 'danger');
        closeContextMenu();
        return;
    }

    showLoading();

    const result = await fetchAPI('/api/map-save-waypoints.php', {
        method: 'POST',
        body: JSON.stringify({
            parent_id: connectionData.parentId,
            child_id: connectionData.childId,
            waypoints: [] // Empty array = reset to straight line
        })
    });

    hideLoading();

    if (result && result.success) {
        showToast('Waypoints berhasil dihapus, garis dikembalikan ke bentuk lurus', 'success');

        // Clear waypoints from global storage
        delete waypoints[connectionData.connectionKey];

        // Reset polyline to straight line immediately
        const straightCoords = [
            connectionData.parentMarker.getLatLng(),
            connectionData.childMarker.getLatLng()
        ];

        // Update all three layers (shadow, border, main)
        if (polylineData[connectionData.connectionKey]) {
            const { shadowPolyline, borderPolyline, mainPolyline } = polylineData[connectionData.connectionKey];
            if (shadowPolyline) shadowPolyline.setLatLngs(straightCoords);
            if (borderPolyline) borderPolyline.setLatLngs(straightCoords);
            if (mainPolyline) mainPolyline.setLatLngs(straightCoords);
        }

        // Disable edit mode after reset
        if (polyline.editEnabled && polyline.editEnabled()) {
            polyline.disableEdit();
        }

        // Resume auto-refresh after reset
        resumeAutoRefresh();

        // Clear editing state AND interpolation flag (since we reset to straight line)
        window.isEditingPolyline = false;
        polyline._hasInterpolated = false; // Reset flag so it can be re-interpolated
    } else {
        showToast(result.message || 'Gagal menghapus waypoints', 'danger');
    }

    closeContextMenu();
}

// Load waypoints from database when map loads
async function loadConnectionWaypoints() {
    try {
        const result = await fetchAPI('/api/map-get-waypoints.php');

        if (result && result.success && result.waypoints) {
            // Store waypoints in global variable
            waypoints = {};

            result.waypoints.forEach(conn => {
                const connectionKey = `${conn.from_item_id}-${conn.to_item_id}`;
                waypoints[connectionKey] = conn.path_coordinates || [];

            });
        }
    } catch (error) {
        // Silent fail - waypoints are optional
    }
}
