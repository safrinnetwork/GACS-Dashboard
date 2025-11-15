/**
 * Map Utility Functions
 * Helper functions for map operations
 */

// Extract IP from string (used for server markers)
function extractIP(ipString) {
    if (!ipString) return null;
    const match = ipString.match(/(\d{1,3}\.){3}\d{1,3}/);
    return match ? match[0] : null;
}

// Toggle splitter ratio visibility
function toggleSplitterRatio(value) {
    console.log('toggleSplitterRatio called with value:', value);
    const splitterRatioGroup = document.getElementById('splitter-ratio-group');
    console.log('Splitter ratio group element:', splitterRatioGroup);

    if (!splitterRatioGroup) {
        console.error('Element splitter-ratio-group not found!');
        return;
    }

    // Check if value is '1' (Yes) or 1 (number)
    if (value === '1' || value === 1) {
        splitterRatioGroup.style.display = 'block';
        console.log('Splitter ratio form shown');

        // Also trigger check for custom ratio when form is shown
        const splitterRatioSelect = document.querySelector('select[name="splitter_ratio"]');
        if (splitterRatioSelect) {
            console.log('Triggering toggleCustomRatioPortSelection with initial value:', splitterRatioSelect.value);
            toggleCustomRatioPortSelection(splitterRatioSelect.value);
        }
    } else {
        splitterRatioGroup.style.display = 'none';
        console.log('Splitter ratio form hidden');

        // Hide custom ratio port selection when splitter is disabled
        const customRatioPortGroup = document.getElementById('custom-ratio-port-group');
        if (customRatioPortGroup) {
            customRatioPortGroup.style.display = 'none';
        }
    }
}

// Generate PON port fields dynamically
function generatePonPortFields(portCount) {
    console.log('generatePonPortFields called with portCount:', portCount);
    const container = document.getElementById('pon-output-power-container');
    console.log('Container found:', container);

    if (!container) {
        console.error('Container pon-output-power-container not found!');
        return;
    }

    container.innerHTML = '';

    if (!portCount || portCount < 1) {
        console.log('Invalid portCount, returning');
        return;
    }

    const title = document.createElement('div');
    title.className = 'alert alert-info mt-3';
    title.innerHTML = '<strong><i class="bi bi-info-circle"></i> Masukan RX Power</strong>';
    container.appendChild(title);

    for (let i = 1; i <= portCount; i++) {
        const formGroup = document.createElement('div');
        formGroup.className = 'form-group';
        formGroup.innerHTML = `
            <label>⚡ Port PON ${i}</label>
            <input type="number" step="0.01" name="pon_port_${i}_power" class="form-control"
                   placeholder="e.g., 2.5" value="0.00" required>
        `;
        container.appendChild(formGroup);
    }

    console.log('Generated', portCount, 'PON port fields');
}

// Update ODC PON port options based on count
function updateODCPonPortOptions(portCount) {
    const select = document.querySelector('select[name="odc_pon_port"]');
    if (!select) return;
    
    select.innerHTML = '<option value="">Select Port</option>';
    for (let i = 1; i <= portCount; i++) {
        const option = document.createElement('option');
        option.value = i;
        option.textContent = `Port ${i}`;
        select.appendChild(option);
    }
}

// Toggle ODC section visibility
function toggleODCSection(isChecked) {
    const odcSection = document.getElementById('odc-section');
    if (odcSection) {
        odcSection.style.display = isChecked ? 'block' : 'none';

        // If checked and no ODC forms exist, add the first one
        if (isChecked) {
            const container = document.getElementById('odc-list-container');
            if (container && container.children.length === 0) {
                addODCForm();
            }
        } else {
            // Clear all ODC forms when unchecked
            const container = document.getElementById('odc-list-container');
            if (container) {
                container.innerHTML = '';
            }
        }
    }
}

// Global counter for ODC forms
let odcFormCounter = 0;

// Add new ODC form
function addODCForm() {
    const container = document.getElementById('odc-list-container');
    if (!container) return;

    odcFormCounter++;
    const formId = `odc-form-${odcFormCounter}`;

    // Get PON port count to populate options
    const ponPortCount = document.getElementById('pon_port_count')?.value || 0;

    // Generate PON port options
    let ponPortOptions = '<option value="">Pilih PON Port</option>';
    for (let i = 1; i <= ponPortCount; i++) {
        ponPortOptions += `<option value="${i}">Port ${i}</option>`;
    }

    const formHtml = `
        <div class="card mb-3" id="${formId}">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong>📦 ODC #${odcFormCounter}</strong>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeODCForm('${formId}')">
                    <i class="bi bi-trash"></i> Hapus
                </button>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Nama ODC <span class="text-danger">*</span></label>
                    <input type="text" name="odc_items[${odcFormCounter}][name]" class="form-control" placeholder="Contoh: ODC-${odcFormCounter}">
                </div>
                <div class="form-group">
                    <label>Port Pon OLT <span class="text-danger">*</span></label>
                    <select name="odc_items[${odcFormCounter}][pon_port]" class="form-control odc-pon-port-select">
                        ${ponPortOptions}
                    </select>
                </div>
                <div class="form-group">
                    <label>Port ODC</label>
                    <input type="number" name="odc_items[${odcFormCounter}][port_count]" class="form-control" value="4" min="1">
                </div>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', formHtml);
}

// Remove ODC form
function removeODCForm(formId) {
    const form = document.getElementById(formId);
    if (form) {
        form.remove();
    }
}

// Update all ODC PON port select dropdowns when PON port count changes
function updateAllODCPonPortOptions(portCount) {
    const selects = document.querySelectorAll('.odc-pon-port-select');
    if (!selects || selects.length === 0) return;

    let ponPortOptions = '<option value="">Pilih PON Port</option>';
    for (let i = 1; i <= portCount; i++) {
        ponPortOptions += `<option value="${i}">Port ${i}</option>`;
    }

    selects.forEach(select => {
        const currentValue = select.value;
        select.innerHTML = ponPortOptions;
        // Restore selected value if it's still valid
        if (currentValue && currentValue <= portCount) {
            select.value = currentValue;
        }
    });
}

// Update PON fields for server modal
function updatePONFields(ponCount) {
    generatePonPortFields(ponCount);
}

// Toggle custom ratio port selection visibility
function toggleCustomRatioPortSelection(ratio) {
    console.log('toggleCustomRatioPortSelection called with ratio:', ratio);
    const customRatioPortGroup = document.getElementById('custom-ratio-port-group');
    const customRatioPortSelect = document.getElementById('custom-ratio-output-port');

    if (!customRatioPortGroup || !customRatioPortSelect) {
        console.error('Custom ratio port selection elements not found!');
        return;
    }

    // Check if this is a custom ratio
    const customRatios = ['20:80', '30:70', '50:50'];

    if (customRatios.includes(ratio)) {
        // Show the port selection group
        customRatioPortGroup.style.display = 'block';
        customRatioPortSelect.required = true;

        // Populate options based on ratio
        const ratioValues = ratio.split(':');
        let options = '<option value="">Pilih port output</option>';
        options += `<option value="${ratioValues[0]}%">${ratioValues[0]}% - Port Low (Power Rendah)</option>`;
        options += `<option value="${ratioValues[1]}%">${ratioValues[1]}% - Port High (Power Tinggi)</option>`;

        customRatioPortSelect.innerHTML = options;
        console.log('Custom ratio port selection shown for ratio:', ratio);
    } else {
        // Hide for standard ratios
        customRatioPortGroup.style.display = 'none';
        customRatioPortSelect.required = false;
        console.log('Custom ratio port selection hidden (standard ratio selected)');
    }
}

// Toggle secondary splitter ratio visibility
function toggleSecondarySplitterRatio(value) {
    console.log('toggleSecondarySplitterRatio called with value:', value);
    const secondarySplitterRatioGroup = document.getElementById('secondary-splitter-ratio-group');

    if (!secondarySplitterRatioGroup) {
        console.error('Element secondary-splitter-ratio-group not found!');
        return;
    }

    // Check if value is '1' (Yes) or 1 (number)
    if (value === '1' || value === 1) {
        secondarySplitterRatioGroup.style.display = 'block';
        console.log('Secondary splitter ratio form shown');
    } else {
        secondarySplitterRatioGroup.style.display = 'none';
        console.log('Secondary splitter ratio form hidden');
    }
}
