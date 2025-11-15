/**
 * Map Core Module
 *
 * This module handles the core functionality of the network topology map:
 * - Map initialization with Leaflet and Leaflet.Editable
 * - Loading and rendering map items (Server, OLT, ODC, ODP, ONU)
 * - Drawing connection polylines with 3-layer rendering (shadow, border, main)
 * - Layer toggling and visibility management
 * - Item counter updates
 * - Auto-refresh functionality (30-second intervals)
 * - Focus/pan-to-item functionality for external navigation
 *
 * Global Variables:
 * - map: Leaflet map instance
 * - markers: Object storing all map markers by item ID
 * - polylines: Array of all polyline layers
 * - polylineData: Object storing polyline references with connection info
 * - allMapItems: Array of all map items for reference
 * - visibleLayers: Object tracking which layer types are visible
 * - autoRefreshInterval: Interval ID for auto-refresh timer
 * - autoRefreshEnabled: Boolean flag for auto-refresh state
 * - locationPointer: Temporary marker for location selection
 * - pointerVisible: Boolean tracking pointer visibility state
 * - currentEditingPolyline: Reference to polyline currently being edited
 * - waypoints: Object storing waypoints per connection (key: parentId-childId)
 */

// ============================================================================
// GLOBAL VARIABLES
// ============================================================================

let map = null;
let markers = {};
let polylines = [];
let polylineData = {}; // Store polyline data with connection info
let allMapItems = []; // Global variable to store all items for displaying chain info in popup
let visibleLayers = {
    server: true,
    olt: true,
    odc: true,
    odp: true,
    onu: true
};
let autoRefreshInterval = null;
let autoRefreshEnabled = true;
let locationPointer = null; // Temporary marker for location selection
let pointerVisible = false; // Track pointer visibility state
let currentEditingPolyline = null; // Track currently editing polyline
let waypoints = {}; // Store waypoints per connection
let popupOpenedFromRefresh = false; // Flag to prevent duplicate loading from popupopen event
let isEditingPolyline = false; // Track if in polyline edit mode

// ============================================================================
// MAP INITIALIZATION
// ============================================================================

function initMap() {
    // Default center (Indonesia)
    map = L.map('map').setView([-6.2088, 106.8456], 13);

    // Initialize Leaflet.Editable with retry mechanism
    let editableRetries = 0;
    const maxRetries = 20; // Increased retries

    const initEditable = () => {
        if (typeof L.Editable !== 'undefined' && typeof L.Editable === 'function') {
            try {
                // Override default iconSize - USE CSS FOR STYLING
                // Don't use html parameter as it may conflict with Leaflet.Editable
                map.editTools = new L.Editable(map, {
                    vertexIcon: L.divIcon({
                        iconSize: [30, 30],
                        iconAnchor: [15, 15],
                        className: 'leaflet-div-icon leaflet-vertex-icon'
                    }),
                    middleIcon: L.divIcon({
                        iconSize: [22, 22],
                        iconAnchor: [11, 11],
                        className: 'leaflet-div-icon leaflet-middle-icon'
                    })
                });
                return true;
            } catch (error) {
                editableRetries++;
                if (editableRetries < maxRetries) {
                    setTimeout(initEditable, 150);
                }
                return false;
            }
        } else {
            editableRetries++;
            if (editableRetries < maxRetries) {
                setTimeout(initEditable, 150);
            }
            return false;
        }
    };

    // Start initialization with short delay to allow script loading
    setTimeout(initEditable, 200);

    // Base layers
    const osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    });

    const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community',
        maxZoom: 19
    });

    const hybrid = L.layerGroup([
        L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 19
        }),
        L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager_only_labels/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
            subdomains: 'abcd',
            maxZoom: 19
        })
    ]);

    // Add default layer
    hybrid.addTo(map);

    // Layer control
    const baseLayers = {
        "OpenStreetMap": osm,
        "Satelit": satellite,
        "Hybrid (Satelit + Jalan)": hybrid
    };

    L.control.layers(baseLayers, null, { position: 'topright' }).addTo(map);

    // Click to set location pointer (always show on map click, EXCEPT when editing polyline)
    map.on('click', function(e) {
        // Don't show pointer if editing polyline OR edit line mode is active
        if (isEditingPolyline || window.editLineModeActive) {
            console.log('🚫 Blocked location pointer - edit mode active');
            return;
        }

        // Always show pointer when clicking on empty map area
        setLocationPointer(e.latlng);
        pointerVisible = true;
    });

    // Add zoom event listener to adjust polyline styles
    map.on('zoomend', function() {
        // CRITICAL: Don't update styles while editing polyline to prevent marker loss
        if (!window.isEditingPolyline && !window.editLineModeActive) {
            updatePolylineStyles();
        }
        updateZoomIndicator();

        // CRITICAL: Re-apply marker visibility after zoom completes (Leaflet may have removed them)
        if (window.editLineModeActive && window.currentEditingPolyline) {
            console.log('🔄 Re-applying marker visibility after zoom');
            // Force markers to reappear after zoom
            setTimeout(() => {
                if (typeof window.forceMarkerVisibility === 'function') {
                    window.forceMarkerVisibility();
                }
            }, 50);
            setTimeout(() => {
                if (typeof window.forceMarkerVisibility === 'function') {
                    window.forceMarkerVisibility();
                }
            }, 200);
        }
    });

    // Update zoom indicator on zoom animation
    map.on('zoom', function() {
        updateZoomIndicator();

        // CRITICAL: Keep markers visible during zoom animation
        if (window.editLineModeActive && window.currentEditingPolyline) {
            // Re-apply styles during zoom animation
            const vertexMarkers = document.querySelectorAll('.leaflet-vertex-icon');
            const middleMarkers = document.querySelectorAll('.leaflet-middle-icon');

            vertexMarkers.forEach((marker) => {
                marker.style.opacity = '1';
                marker.style.visibility = 'visible';
                marker.style.display = 'block';
            });

            middleMarkers.forEach((marker) => {
                marker.style.opacity = '0.95';
                marker.style.visibility = 'visible';
                marker.style.display = 'block';
            });
        }
    });

    loadMap();
}

// ============================================================================
// MAP DATA LOADING
// ============================================================================

async function loadMap() {
    // CRITICAL: Don't reload map if editing polyline to prevent marker loss
    if (window.isEditingPolyline || window.editLineModeActive) {
        console.log('⛔ loadMap() blocked - edit mode active, preventing marker loss');
        return;
    }

    // CRITICAL: Load waypoints FIRST before drawing polylines
    await loadConnectionWaypoints();

    // Track which popup is currently open before clearing markers
    let openPopupItemId = null;
    let openPopupItemType = null;
    Object.keys(markers).forEach(itemId => {
        const marker = markers[itemId];
        if (marker && marker.getPopup() && marker.getPopup().isOpen()) {
            openPopupItemId = itemId;
            openPopupItemType = marker.itemData ? marker.itemData.item_type : null;
        }
    });

    const itemsResult = await fetchAPI('/api/map-get-items.php');

    if (itemsResult && itemsResult.success) {
        // Clear existing polylines EXCEPT the one being edited
        console.log('🔄 loadMap - Clearing polylines...');
        if (window.currentEditingPolyline) {
            console.log('⚠️ KEEPING editing polyline in place - NOT removing it');
        }

        polylines.forEach(polyline => {
            // Skip removing polyline if it's currently being edited
            if (window.currentEditingPolyline && polyline === window.currentEditingPolyline) {
                console.log('✓ Skipping removal of editing polyline');
                return;
            }
            // Also skip shadow and border polylines of the editing connection
            if (window.currentEditingPolyline && window.currentEditingPolyline.connectionData) {
                const connKey = window.currentEditingPolyline.connectionData.connectionKey;
                if (polylineData[connKey]) {
                    const { shadowPolyline, borderPolyline, mainPolyline } = polylineData[connKey];
                    if (polyline === shadowPolyline || polyline === borderPolyline || polyline === mainPolyline) {
                        console.log('✓ Skipping removal of editing polyline layer (shadow/border/main)');
                        return;
                    }
                }
            }
            polyline.remove();
        });

        // Filter out removed polylines from the array, keep the editing one
        if (window.currentEditingPolyline && window.currentEditingPolyline.connectionData) {
            const connKey = window.currentEditingPolyline.connectionData.connectionKey;
            const editingPolylines = polylineData[connKey];
            if (editingPolylines) {
                // Keep only the editing polylines
                polylines = [editingPolylines.shadowPolyline, editingPolylines.borderPolyline, editingPolylines.mainPolyline].filter(p => p);
            } else {
                polylines = [];
            }
        } else {
            polylines = [];
        }

        // Remove markers that don't have open popups
        // Keep markers with open popups to prevent content reset
        Object.keys(markers).forEach(itemId => {
            const marker = markers[itemId];
            const hasOpenPopup = marker && marker.getPopup() && marker.getPopup().isOpen();

            if (!hasOpenPopup) {
                marker.remove();
                delete markers[itemId];
            }
        });

        const items = itemsResult.items;
        allMapItems = items; // Store globally for displaying chain info in popup

        // Add or update markers
        items.forEach(item => {
            if (!visibleLayers[item.item_type]) return;

            // Skip ODC items that are hidden (created with Server)
            const properties = item.properties || {};
            if (item.item_type === 'odc' && properties.hidden_marker) {
                return; // Don't create marker, but item exists in database for ODP parent
            }

            // Check if marker already exists (with open popup)
            const existingMarker = markers[item.id];

            if (existingMarker) {
                // Marker exists - just update item data and icon (don't recreate popup)
                existingMarker.itemData = item;
                const icon = getItemIcon(item.item_type, item.status);
                existingMarker.setIcon(icon);
                // Update marker position if changed
                existingMarker.setLatLng([item.latitude, item.longitude]);
                return; // Skip creating new marker
            }

            // Create new marker if doesn't exist
            const icon = getItemIcon(item.item_type, item.status);
            const marker = L.marker([item.latitude, item.longitude], {
                icon: icon,
                draggable: true
            })
                .addTo(map);

            // Bind popup with sync content and prevent auto-close
            marker.bindPopup(getItemPopupContent(item), {
                autoClose: false,
                closeOnClick: false,
                closeOnEscapeKey: false,
                maxWidth: 350,
                keepInView: true
            });

            // Prevent map click event when clicking on marker
            marker.on('click', function(e) {
                L.DomEvent.stopPropagation(e);
            });

            // Update popup when opened for ONU, ODP, ODC, and Server (async details)
            marker.on('popupopen', function(e) {
                // Skip if popup was opened from auto-refresh (already loaded in setTimeout below)
                if (popupOpenedFromRefresh) {
                    return;
                }

                if (item.item_type === 'onu' && item.genieacs_device_id) {
                    loadONUDeviceDetails(item);
                } else if (item.item_type === 'odp') {
                    loadODPPortDetails(item);
                } else if (item.item_type === 'odc') {
                    loadODCPortDetails(item);
                } else if (item.item_type === 'server') {
                    loadServerChainInfo(item);
                    loadServerPONPorts(item);
                }
            });

            // Don't auto-hide chain on popupclose to allow clicking chain items

            marker.on('dragend', function(e) {
                const newPos = e.target.getLatLng();
                updateItemPosition(item.id, newPos.lat, newPos.lng);
            });

            // Store item data in marker
            marker.itemData = item;
            markers[item.id] = marker;
        });

        // Draw connections between parent-child items
        items.forEach(item => {
            if (item.parent_id && markers[item.id]) {
                // Skip drawing this connection if it's currently being edited
                const potentialConnKey = `${item.parent_id}-${item.id}`;
                if (window.currentEditingPolyline &&
                    window.currentEditingPolyline.connectionData &&
                    window.currentEditingPolyline.connectionData.connectionKey === potentialConnKey) {
                    console.log(`✓ Skipping redraw of editing connection ${potentialConnKey}`);
                    // Keep the existing polylineData entry
                    return;
                }

                let parentMarker = markers[item.parent_id];
                const childMarker = markers[item.id];

                // If parent doesn't have marker (hidden ODC), find grandparent (Server)
                if (!parentMarker) {
                    const parentItem = items.find(i => i.id === item.parent_id);
                    if (parentItem && parentItem.parent_id) {
                        parentMarker = markers[parentItem.parent_id]; // Use grandparent (Server)
                    }
                }

                // Draw polyline if we have both markers
                if (parentMarker) {
                    // Determine connection status based on both parent and child
                    let connectionStatus = 'unknown';
                    let polylineColor, polylineWeight, polylineDashArray, polylineOpacity;

                    if (parentMarker.itemData.status === 'online' && childMarker.itemData.status === 'online') {
                        connectionStatus = 'online';
                        polylineColor = '#10b981';
                        polylineWeight = 10;
                        polylineDashArray = '18, 18'; // Garis 18px, spasi 18px - sangat jelas
                        polylineOpacity = 0.9;
                    } else if (parentMarker.itemData.status === 'offline' || childMarker.itemData.status === 'offline') {
                        connectionStatus = 'offline';
                        polylineColor = '#ef4444';
                        polylineWeight = 10;
                        polylineDashArray = '18, 18'; // Garis 18px, spasi 18px - sangat jelas
                        polylineOpacity = 0.9;
                    } else {
                        connectionStatus = 'unknown';
                        polylineColor = '#6b7280';
                        polylineWeight = 10;
                        polylineDashArray = '12, 20'; // Garis pendek, spasi sangat lebar
                        polylineOpacity = 0.4;
                    }

                    // Load waypoints for this connection if exists
                    const connectionKey = `${item.parent_id}-${item.id}`;
                    let coords = [parentMarker.getLatLng(), childMarker.getLatLng()];

                    // Check if waypoints exist for this connection
                    if (waypoints[connectionKey] && waypoints[connectionKey].length > 0) {
                        // Insert waypoints between start and end
                        const waypointLatLngs = waypoints[connectionKey].map(wp => L.latLng(wp.lat, wp.lng));
                        coords = [parentMarker.getLatLng(), ...waypointLatLngs, childMarker.getLatLng()];
                    }

                    // Layer 1: Black shadow (bottom layer)
                    const shadowPolyline = L.polyline(coords, {
                        color: '#000000',
                        weight: polylineWeight + 2,
                        opacity: 0.3,
                        dashArray: polylineDashArray,
                        smoothFactor: 0,
                        className: `connection-shadow-${connectionStatus}`
                    }).addTo(map);

                    // Prevent pointer on shadow layer
                    shadowPolyline.on('click', function(e) {
                        L.DomEvent.stopPropagation(e);
                    });
                    shadowPolyline.on('contextmenu', function(e) {
                        L.DomEvent.stopPropagation(e);
                    });

                    polylines.push(shadowPolyline);

                    // Layer 2: White border (middle layer)
                    const borderPolyline = L.polyline(coords, {
                        color: '#ffffff',
                        weight: polylineWeight + 1,
                        opacity: 0.8,
                        dashArray: polylineDashArray,
                        smoothFactor: 0,
                        className: `connection-border-${connectionStatus}`
                    }).addTo(map);

                    // Prevent pointer on border layer
                    borderPolyline.on('click', function(e) {
                        L.DomEvent.stopPropagation(e);
                    });
                    borderPolyline.on('contextmenu', function(e) {
                        L.DomEvent.stopPropagation(e);
                    });

                    polylines.push(borderPolyline);

                    // Layer 3: Colored line (top layer) - Make it editable
                    const polyline = L.polyline(coords, {
                        color: polylineColor,
                        weight: polylineWeight,
                        opacity: polylineOpacity,
                        dashArray: polylineDashArray,
                        smoothFactor: 0,
                        className: `connection-${connectionStatus} leaflet-editable-polyline`
                    }).addTo(map);

                    // Store connection info in polyline
                    polyline.connectionData = {
                        parentId: item.parent_id,
                        childId: item.id,
                        connectionKey: connectionKey,
                        parentMarker: parentMarker,
                        childMarker: childMarker
                    };

                    // Click handler for polyline - enter edit mode if button mode is active
                    polyline.on('click', function(e) {
                        L.DomEvent.stopPropagation(e);

                        // If edit line mode is active, enable editing on clicked polyline
                        if (window.editLineModeActive) {
                            // CRITICAL: If this polyline is already being edited, do nothing (prevent double-click)
                            if (window.currentEditingPolyline === polyline) {
                                console.log('⏭️ Polyline already being edited - ignoring click');
                                return;
                            }

                            // Disable any currently editing polyline first
                            if (window.currentEditingPolyline && window.currentEditingPolyline !== polyline) {
                                if (window.currentEditingPolyline.editEnabled && window.currentEditingPolyline.editEnabled()) {
                                    console.log('🔄 Disabling previous polyline before editing new one');
                                    window.currentEditingPolyline.disableEdit();
                                }
                            }

                            // Set this polyline as current editing
                            window.currentEditingPolyline = polyline;

                            // Call enablePolylineEdit directly
                            console.log('🎯 Polyline clicked - calling enablePolylineEdit()');
                            enablePolylineEdit();
                        }
                    });

                    // Add right-click context menu for polyline editing
                    polyline.on('contextmenu', function(e) {
                        L.DomEvent.stopPropagation(e);
                        showPolylineContextMenu(e, polyline);
                    });

                    // Apply animation directly to the path element
                    if (connectionStatus === 'online') {
                        const pathElement = polyline.getElement();
                        if (pathElement) {
                            pathElement.style.animation = 'dash-flow-online 1.5s linear infinite, pulse-glow-online 2s ease-in-out infinite';
                        }
                    } else if (connectionStatus === 'offline') {
                        const pathElement = polyline.getElement();
                        if (pathElement) {
                            pathElement.style.animation = 'dash-flow-offline 1s linear infinite, pulse-glow-offline 1.5s ease-in-out infinite';
                        }
                    }

                    polylines.push(polyline);

                    // Store polyline reference for later access
                    polylineData[connectionKey] = {
                        mainPolyline: polyline,
                        shadowPolyline: shadowPolyline,
                        borderPolyline: borderPolyline,
                        parentId: item.parent_id,
                        childId: item.id
                    };
                }
            }
        });

        // Draw connections for standalone ODC (no parent_id, but has server_pon_port)
        items.forEach(item => {
            if (item.item_type === 'odc' && !item.parent_id && item.config && item.config.server_pon_port && markers[item.id]) {
                // ODC standalone - find server by querying server_pon_ports table
                // The server_pon_port in config stores the port NUMBER, we need to find which server has this PON port

                // Find all servers
                const servers = items.filter(i => i.item_type === 'server');

                // Check if this connection is being edited (we need to check against all servers)
                let isEditingThisConnection = false;
                if (window.currentEditingPolyline && window.currentEditingPolyline.connectionData) {
                    const editingChildId = window.currentEditingPolyline.connectionData.childId;
                    if (editingChildId == item.id) {
                        console.log(`✓ Skipping redraw of editing standalone ODC connection for ODC ${item.id}`);
                        isEditingThisConnection = true;
                        return; // Skip this entire forEach iteration
                    }
                }


                // For each server, check if it has the PON port that ODC is connected to
                for (const server of servers) {
                    // Check if this server was selected when creating ODC
                    // We need to match by looking at the PON port configuration
                    // Since we can't directly match, we'll connect to the first server found
                    // (In practice, user selected this server when creating ODC)

                    const serverMarker = markers[server.id];
                    const odcMarker = markers[item.id];

                    if (serverMarker && odcMarker) {
                        // Determine connection status
                        let connectionStatus = 'unknown';
                        let polylineColor, polylineWeight, polylineDashArray, polylineOpacity;

                        if (serverMarker.itemData.status === 'online' && odcMarker.itemData.status === 'online') {
                            connectionStatus = 'online';
                            polylineColor = '#10b981';
                            polylineWeight = 10;
                            polylineDashArray = '18, 18';
                            polylineOpacity = 0.9;
                        } else if (serverMarker.itemData.status === 'offline' || odcMarker.itemData.status === 'offline') {
                            connectionStatus = 'offline';
                            polylineColor = '#ef4444';
                            polylineWeight = 10;
                            polylineDashArray = '18, 18';
                            polylineOpacity = 0.9;
                        } else {
                            connectionStatus = 'unknown';
                            polylineColor = '#6b7280';
                            polylineWeight = 10;
                            polylineDashArray = '12, 20';
                            polylineOpacity = 0.4;
                        }

                        // Use server ID as "parent" for connection key (virtual connection)
                        const connectionKey = `${server.id}-${item.id}`;
                        let coords = [serverMarker.getLatLng(), odcMarker.getLatLng()];

                        // Check if waypoints exist for this connection
                        if (waypoints[connectionKey] && waypoints[connectionKey].length > 0) {
                            const waypointLatLngs = waypoints[connectionKey].map(wp => L.latLng(wp.lat, wp.lng));
                            coords = [serverMarker.getLatLng(), ...waypointLatLngs, odcMarker.getLatLng()];
                        }

                        // Layer 1: Black shadow
                        const shadowPolyline = L.polyline(coords, {
                            color: '#000000',
                            weight: polylineWeight + 2,
                            opacity: 0.3,
                            dashArray: polylineDashArray,
                            smoothFactor: 0,
                            className: `connection-shadow-${connectionStatus}`
                        }).addTo(map);

                        shadowPolyline.on('click', function(e) { L.DomEvent.stopPropagation(e); });
                        shadowPolyline.on('contextmenu', function(e) { L.DomEvent.stopPropagation(e); });
                        polylines.push(shadowPolyline);

                        // Layer 2: White border
                        const borderPolyline = L.polyline(coords, {
                            color: '#ffffff',
                            weight: polylineWeight + 1,
                            opacity: 0.8,
                            dashArray: polylineDashArray,
                            smoothFactor: 0,
                            className: `connection-border-${connectionStatus}`
                        }).addTo(map);

                        borderPolyline.on('click', function(e) { L.DomEvent.stopPropagation(e); });
                        borderPolyline.on('contextmenu', function(e) { L.DomEvent.stopPropagation(e); });
                        polylines.push(borderPolyline);

                        // Layer 3: Colored line (editable)
                        const polyline = L.polyline(coords, {
                            color: polylineColor,
                            weight: polylineWeight,
                            opacity: polylineOpacity,
                            dashArray: polylineDashArray,
                            smoothFactor: 0,
                            className: `connection-${connectionStatus} leaflet-editable-polyline`
                        }).addTo(map);

                        polyline.connectionData = {
                            parentId: server.id,
                            childId: item.id,
                            connectionKey: connectionKey,
                            parentMarker: serverMarker,
                            childMarker: odcMarker
                        };

                        // Click handler for standalone ODC polyline
                        polyline.on('click', function(e) {
                            L.DomEvent.stopPropagation(e);

                            // If edit line mode is active, enable editing on clicked polyline
                            if (window.editLineModeActive) {
                                // CRITICAL: If this polyline is already being edited, do nothing (prevent double-click)
                                if (window.currentEditingPolyline === polyline) {
                                    console.log('⏭️ Polyline already being edited - ignoring click');
                                    return;
                                }

                                // Disable any currently editing polyline first
                                if (window.currentEditingPolyline && window.currentEditingPolyline !== polyline) {
                                    if (window.currentEditingPolyline.editEnabled && window.currentEditingPolyline.editEnabled()) {
                                        console.log('🔄 Disabling previous polyline before editing new one');
                                        window.currentEditingPolyline.disableEdit();
                                    }
                                }

                                // Set this polyline as current editing
                                window.currentEditingPolyline = polyline;

                                // Call enablePolylineEdit directly
                                console.log('🎯 Polyline clicked - calling enablePolylineEdit()');
                                enablePolylineEdit();
                            }
                        });

                        polyline.on('contextmenu', function(e) {
                            L.DomEvent.stopPropagation(e);
                            showPolylineContextMenu(e, polyline);
                        });

                        // Apply animation
                        if (connectionStatus === 'online') {
                            const pathElement = polyline.getElement();
                            if (pathElement) {
                                pathElement.style.animation = 'dash-flow-online 1.5s linear infinite, pulse-glow-online 2s ease-in-out infinite';
                            }
                        } else if (connectionStatus === 'offline') {
                            const pathElement = polyline.getElement();
                            if (pathElement) {
                                pathElement.style.animation = 'dash-flow-offline 1s linear infinite, pulse-glow-offline 1.5s ease-in-out infinite';
                            }
                        }

                        polylines.push(polyline);

                        // Store polyline reference
                        polylineData[connectionKey] = {
                            mainPolyline: polyline,
                            shadowPolyline: shadowPolyline,
                            borderPolyline: borderPolyline,
                            parentId: server.id,
                            childId: item.id
                        };

                        // Only connect to first server found (break after first match)
                        break;
                    }
                }
            }
        });
    }

    // Update item counters after loading map
    updateItemCounters();

    // Update polyline styles based on current zoom level
    updatePolylineStyles();

    // Update zoom indicator
    updateZoomIndicator();

    // Refresh async data for popup that was kept open (no need to reopen since marker wasn't removed)
    if (openPopupItemId && markers[openPopupItemId]) {
        const marker = markers[openPopupItemId];
        const item = marker.itemData;

        // Popup is still open since we didn't remove/recreate the marker
        // Just refresh the async data parts
        setTimeout(() => {
            if (openPopupItemType === 'onu' && item && item.genieacs_device_id) {
                loadONUDeviceDetails(item);
            } else if (openPopupItemType === 'odp') {
                loadODPPortDetails(item);
            } else if (openPopupItemType === 'odc') {
                loadODCPortDetails(item);
            } else if (openPopupItemType === 'server') {
                // For server, only refresh async status parts, don't rebuild HTML
                loadServerChainInfo(item);
                loadServerPONPorts(item);
            }
        }, 100);
    }
}

// ============================================================================
// ITEM COUNTERS
// ============================================================================

// Update item count badges for each layer type
function updateItemCounters() {
    const counts = {
        server: 0,
        olt: 0,
        odc: 0,
        odp: 0,
        onu: 0
    };

    // Count items by type
    allMapItems.forEach(item => {
        if (item.item_type === 'server') {
            counts.server++;
            // Count OLT if server has olt_link configured
            if (item.properties && item.properties.olt_link && item.properties.olt_link.trim() !== '') {
                counts.olt++;
            }
        } else if (counts.hasOwnProperty(item.item_type)) {
            counts[item.item_type]++;
        }
    });

    // Update counter elements
    document.getElementById('server-count').textContent = counts.server;
    document.getElementById('olt-count').textContent = counts.olt;
    document.getElementById('odc-count').textContent = counts.odc;
    document.getElementById('odp-count').textContent = counts.odp;
    document.getElementById('onu-count').textContent = counts.onu;
}

// ============================================================================
// LAYER TOGGLING
// ============================================================================

function toggleLayer(type) {
    visibleLayers[type] = !visibleLayers[type];
    loadMap();
}

// ============================================================================
// AUTO-REFRESH FUNCTIONALITY
// ============================================================================

function toggleAutoRefresh() {
    autoRefreshEnabled = !autoRefreshEnabled;
    const statusSpan = document.getElementById('auto-refresh-status');
    const toggleBtn = document.getElementById('auto-refresh-toggle');

    if (autoRefreshEnabled) {
        statusSpan.textContent = 'ON';
        toggleBtn.classList.remove('btn-secondary');
        toggleBtn.classList.add('btn-info');
        startAutoRefresh();
    } else {
        statusSpan.textContent = 'OFF';
        toggleBtn.classList.remove('btn-info');
        toggleBtn.classList.add('btn-secondary');
        stopAutoRefresh();
    }
}

function startAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
    autoRefreshInterval = setInterval(() => {
        // CRITICAL: Don't refresh if editing polyline or edit mode active
        if (autoRefreshEnabled && !window.isEditingPolyline && !window.editLineModeActive) {
            loadMap();
        } else if (window.isEditingPolyline || window.editLineModeActive) {
            console.log('⏸️ Auto-refresh skipped - edit mode active');
        }
    }, 30000); // 30 seconds
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// Pause auto-refresh temporarily (for editing)
function pauseAutoRefresh() {
    if (autoRefreshEnabled) {
        stopAutoRefresh();
        // Store that we paused it (not user disabled it)
        window.autoRefreshWasPaused = true;
    }
}

// Resume auto-refresh after editing
function resumeAutoRefresh() {
    // Only resume if it was paused by pauseAutoRefresh(), not if user manually disabled it
    if (window.autoRefreshWasPaused && autoRefreshEnabled) {
        startAutoRefresh();
        window.autoRefreshWasPaused = false;
    }
}

// ============================================================================
// FOCUS/PAN TO ITEM
// ============================================================================

// Focus on a specific map item with custom zoom level
function focusOnMapItem(itemType, itemId, zoomLevel = 17) {
    // Find the item in allMapItems
    const item = allMapItems.find(i => i.id == itemId && i.item_type === itemType);

    if (!item) {
        console.log(`Item not found: ${itemType} with ID ${itemId}`);
        return false;
    }

    // Pan and zoom to the item
    const lat = parseFloat(item.latitude);
    const lng = parseFloat(item.longitude);

    if (!isNaN(lat) && !isNaN(lng)) {
        // Smooth pan to location with custom zoom
        map.flyTo([lat, lng], zoomLevel, {
            duration: 1.5
        });

        // Find and open popup for this item after animation
        setTimeout(() => {
            if (markers[item.id]) {
                markers[item.id].openPopup();
            }
        }, 1600);

        return true;
    }

    return false;
}

// Focus on ONU by serial number
function focusOnONUBySerial(serialNumber, zoomLevel = 17) {
    // Find the ONU in allMapItems by matching serial number in genieacs_device_id
    const onu = allMapItems.find(item => {
        if (item.item_type !== 'onu') return false;
        const deviceId = item.genieacs_device_id || '';
        return deviceId.includes(serialNumber);
    });

    if (!onu) {
        console.log(`ONU not found with serial number: ${serialNumber}`);
        return false;
    }

    // Use focusOnMapItem to pan and zoom
    return focusOnMapItem('onu', onu.id, zoomLevel);
}

// ============================================================================
// POLYLINE STYLE UPDATES (ZOOM-DEPENDENT)
// ============================================================================

// Update all polyline styles based on current zoom level
function updatePolylineStyles() {
    if (!map) return;

    const currentZoom = map.getZoom();

    // FIXED APPROACH based on user feedback and screenshots:
    // Zoom 0-11: Garis melewati item karena Leaflet render terlalu lebar
    // Zoom 12+: Garis teratur (BAGUS)
    // Solusi: SEMBUNYIKAN garis di zoom < 12, tampilkan di zoom >= 12

    let baseWeight, baseDash, baseGap, hidePolylines;

    if (currentZoom < 12) {
        // Zoom 0-11: SEMBUNYIKAN garis (opacity 0)
        hidePolylines = true;
        baseWeight = 0;
        baseDash = 0;
        baseGap = 0;
    } else if (currentZoom <= 13) {
        // Zoom 12-13: mulai normal
        hidePolylines = false;
        baseWeight = 3;
        baseDash = 6;
        baseGap = 6;
    } else if (currentZoom <= 15) {
        // Zoom 14-15: sedang
        hidePolylines = false;
        baseWeight = 5;
        baseDash = 10;
        baseGap = 10;
    } else if (currentZoom <= 17) {
        // Zoom 16-17: normal
        hidePolylines = false;
        baseWeight = 8;
        baseDash = 15;
        baseGap = 15;
    } else {
        // Zoom 18+: besar agar tetap terlihat
        hidePolylines = false;
        baseWeight = 10;
        baseDash = 18;
        baseGap = 18;
    }

    // Update each connection's polylines
    Object.values(polylineData).forEach(data => {
        const { mainPolyline, shadowPolyline, borderPolyline } = data;

        if (!mainPolyline || !shadowPolyline || !borderPolyline) return;

        // CRITICAL: Skip ALL layers (main, shadow, border) of editing polyline
        if (window.currentEditingPolyline &&
            (mainPolyline === window.currentEditingPolyline ||
             shadowPolyline === window.currentEditingPolyline ||
             borderPolyline === window.currentEditingPolyline)) {
            console.log('⏭️ Skipping style update for editing polyline layers during zoom');
            return;
        }

        // DOUBLE CHECK: Also skip if editLineModeActive and this is the current connection
        if (window.editLineModeActive && window.currentEditingPolyline &&
            window.currentEditingPolyline.connectionData &&
            data.mainPolyline === window.currentEditingPolyline) {
            console.log('⏭️ Skipping style update - edit mode active');
            return;
        }

        if (hidePolylines) {
            // SEMBUNYIKAN polylines di zoom rendah
            mainPolyline.setStyle({ opacity: 0 });
            borderPolyline.setStyle({ opacity: 0 });
            shadowPolyline.setStyle({ opacity: 0 });
        } else {
            // TAMPILKAN polylines dengan styling normal
            // Get connection status from className
            const className = mainPolyline.options.className || '';
            let connectionStatus = 'unknown';
            if (className.includes('connection-online')) {
                connectionStatus = 'online';
            } else if (className.includes('connection-offline')) {
                connectionStatus = 'offline';
            }

            // Adjust dashArray for unknown status
            let dashArray;
            if (connectionStatus === 'unknown') {
                dashArray = `${baseDash * 0.6}, ${baseGap * 1.2}`;
            } else {
                dashArray = `${baseDash}, ${baseGap}`;
            }

            // Restore original opacity values
            const mainOpacity = connectionStatus === 'unknown' ? 0.4 : 0.9;

            // Update main polyline
            mainPolyline.setStyle({
                weight: baseWeight,
                dashArray: dashArray,
                opacity: mainOpacity
            });

            // Update border polyline
            borderPolyline.setStyle({
                weight: baseWeight + 1,
                dashArray: dashArray,
                opacity: 0.8
            });

            // Update shadow polyline
            shadowPolyline.setStyle({
                weight: baseWeight + 2,
                dashArray: dashArray,
                opacity: 0.3
            });
        }
    });
}

// Update zoom level indicator in UI
function updateZoomIndicator() {
    const zoomIndicator = document.getElementById('zoom-level-indicator');
    if (zoomIndicator && map) {
        const currentZoom = map.getZoom();
        zoomIndicator.textContent = currentZoom.toFixed(1);
    }
}
