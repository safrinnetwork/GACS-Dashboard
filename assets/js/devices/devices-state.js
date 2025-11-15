/**
 * devices-state.js
 * Global state management for devices page
 */

// Global variables for devices data and sorting
let allDevices = [];
let allMapItems = []; // Store map items
let currentSortColumn = null;
let currentSortDirection = 'asc';
let currentFilterType = 'onu'; // Track current filter type (default: onu)
let savedScrollPosition = 0; // Store scroll position for auto-refresh

// Pagination variables
let currentPage = 1;
let itemsPerPage = 20; // Default: 20 items per page
let totalDevices = 0;

// Auto-refresh timer ID for cleanup
let autoRefreshTimer = null;

// Tags column visibility state
let tagsColumnVisible = false;

// Current summon device ID
let currentSummonDeviceId = null;
