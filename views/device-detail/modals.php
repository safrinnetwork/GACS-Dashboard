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

<!-- Edit WiFi Configuration Modal -->
<div class="modal fade" id="editWiFiModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-wifi"></i> Edit WiFi Configuration
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Info:</strong> Perubahan akan dikirim ke ONU melalui GenieACS. Device mungkin perlu reboot untuk menerapkan konfigurasi baru.
                </div>
                <form id="editWiFiForm">
                    <input type="hidden" id="edit-device-id" name="device_id">

                    <div class="mb-3">
                        <label for="edit-wifi-ssid" class="form-label">
                            <i class="bi bi-wifi"></i> WiFi SSID
                        </label>
                        <input type="text" class="form-control" id="edit-wifi-ssid" name="wifi_ssid"
                               required minlength="1" maxlength="32"
                               placeholder="Enter WiFi SSID (1-32 characters)">
                        <div class="form-text">SSID harus antara 1-32 karakter</div>
                    </div>

                    <div class="mb-3">
                        <label for="edit-security-mode" class="form-label">
                            <i class="bi bi-shield-lock"></i> Security Mode
                        </label>
                        <select class="form-select" id="edit-security-mode" name="security_mode" onchange="togglePasswordField()">
                            <option value="WPA2PSK">WPA2-PSK (Recommended)</option>
                            <option value="WPAPSK">WPA-PSK</option>
                            <option value="WPA2PSKWPAPSK">WPA/WPA2-PSK Mixed</option>
                            <option value="None">Open (No Security)</option>
                        </select>
                        <div class="form-text">Pilih mode keamanan WiFi</div>
                    </div>

                    <div class="mb-3" id="password-field-group">
                        <label for="edit-wifi-password" class="form-label">
                            <i class="bi bi-key"></i> WiFi Password
                        </label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="edit-wifi-password" name="wifi_password"
                                   minlength="8" maxlength="63"
                                   placeholder="Enter WiFi Password (8-63 characters)">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleEditPassword()">
                                <i id="edit-toggle-icon" class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Password harus antara 8-63 karakter untuk WPA/WPA2</div>
                    </div>

                    <div class="mb-3">
                        <label for="edit-wlan-index" class="form-label">
                            <i class="bi bi-router"></i> WLAN Interface
                        </label>
                        <select class="form-select" id="edit-wlan-index" name="wlan_index">
                            <option value="1" selected>WLAN 1 (2.4GHz - Default)</option>
                            <option value="2">WLAN 2 (5GHz)</option>
                            <option value="3">WLAN 3</option>
                            <option value="4">WLAN 4</option>
                        </select>
                        <div class="form-text">Pilih interface WLAN yang ingin diubah</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Batal
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmUpdateWiFi()">
                    <i class="bi bi-check-lg"></i> Update WiFi
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit WAN Configuration Modal -->
<div class="modal fade" id="editWANModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-globe"></i> Edit WAN Connection
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning" id="tr069-warning" style="display:none;">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>WARNING:</strong> This is a TR069 management connection. Modifying this connection may break device communication with GenieACS. Only proceed if you know what you're doing.
                </div>
                <form id="editWANForm">
                    <input type="hidden" id="edit-wan-device-id">
                    <input type="hidden" id="edit-wan-connection-index">
                    <input type="hidden" id="edit-wan-connection-type">

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-info-circle"></i> Connection Name
                        </label>
                        <input type="text" class="form-control" id="edit-wan-name" readonly>
                        <div class="form-text">Connection name is read-only</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-toggle-on"></i> Enable Connection
                        </label>
                        <select class="form-select" id="edit-wan-enable">
                            <option value="true">Enabled</option>
                            <option value="false">Disabled</option>
                        </select>
                    </div>

                    <div class="mb-3" id="edit-wan-username-group">
                        <label class="form-label">
                            <i class="bi bi-person"></i> PPPoE Username
                        </label>
                        <input type="text" class="form-control" id="edit-wan-username" placeholder="Enter PPPoE username">
                    </div>

                    <div class="mb-3" id="edit-wan-password-group">
                        <label class="form-label">
                            <i class="bi bi-key"></i> PPPoE Password
                        </label>
                        <input type="password" class="form-control" id="edit-wan-password" placeholder="Enter PPPoE password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-shield"></i> NAT Enabled
                        </label>
                        <select class="form-select" id="edit-wan-nat">
                            <option value="true">Enabled</option>
                            <option value="false">Disabled</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-tag"></i> VLAN ID
                        </label>
                        <input type="number" class="form-control" id="edit-wan-vlan" min="1" max="4094" placeholder="1-4094">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmUpdateWAN()">
                    <i class="bi bi-check-lg"></i> Update WAN
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add WAN Configuration Modal -->
<div class="modal fade" id="addWANModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-plus-lg"></i> Add New WAN Connection
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Info:</strong> A new WAN connection will be created on the device. Make sure you choose an unused connection index.
                </div>
                <form id="addWANForm">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-hash"></i> Connection Index
                        </label>
                        <select class="form-select" id="add-wan-index" required>
                            <option value="">Select connection index...</option>
                            <option value="1">Connection 1</option>
                            <option value="2">Connection 2</option>
                            <option value="3">Connection 3</option>
                            <option value="4">Connection 4</option>
                            <option value="5">Connection 5</option>
                            <option value="6">Connection 6</option>
                            <option value="7">Connection 7</option>
                            <option value="8">Connection 8</option>
                        </select>
                        <div class="form-text">Choose an unused connection slot</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-diagram-3"></i> Connection Type
                        </label>
                        <select class="form-select" id="add-wan-type" required onchange="toggleAddWANFields()">
                            <option value="">Select connection type...</option>
                            <option value="ppp">PPPoE Connection</option>
                            <option value="ip">IP Connection</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-pencil"></i> Connection Name
                        </label>
                        <input type="text" class="form-control" id="add-wan-name" placeholder="e.g., 4_INTERNET_R_VID_100">
                        <div class="form-text">Optional: Auto-generated if left empty</div>
                    </div>

                    <div class="mb-3" id="add-wan-username-group" style="display:none;">
                        <label class="form-label">
                            <i class="bi bi-person"></i> PPPoE Username
                        </label>
                        <input type="text" class="form-control" id="add-wan-username" placeholder="Enter PPPoE username">
                    </div>

                    <div class="mb-3" id="add-wan-password-group" style="display:none;">
                        <label class="form-label">
                            <i class="bi bi-key"></i> PPPoE Password
                        </label>
                        <input type="password" class="form-control" id="add-wan-password" placeholder="Enter PPPoE password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-cloud"></i> Service List
                        </label>
                        <select class="form-select" id="add-wan-service">
                            <option value="INTERNET">INTERNET</option>
                            <option value="VOIP">VOIP</option>
                            <option value="CUSTOM">Custom (enter below)</option>
                        </select>
                        <input type="text" class="form-control mt-2" id="add-wan-service-custom" placeholder="Enter custom service name" style="display:none;">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-tag"></i> VLAN ID
                        </label>
                        <input type="number" class="form-control" id="add-wan-vlan" min="1" max="4094" placeholder="1-4094" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-shield"></i> NAT Enabled
                        </label>
                        <select class="form-select" id="add-wan-nat">
                            <option value="true" selected>Enabled</option>
                            <option value="false">Disabled</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="confirmAddWAN()">
                    <i class="bi bi-plus-lg"></i> Add Connection
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete WAN Confirmation Modal -->
<div class="modal fade" id="deleteWANModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-trash"></i> Confirm Delete WAN Connection
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div id="delete-wan-normal-warning">
                    <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--warning-color);"></i>
                    <h5 class="mt-3">Delete WAN Connection?</h5>
                    <p class="text-muted mb-2">Are you sure you want to delete this WAN connection?</p>
                    <p class="mb-0"><strong>Connection:</strong> <span id="delete-wan-name"></span></p>
                    <p class="mb-0"><strong>Service:</strong> <span id="delete-wan-service"></span></p>
                    <p class="text-muted mt-2"><small>This action cannot be undone.</small></p>
                </div>
                <div id="delete-wan-tr069-warning" style="display:none;">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 3rem; color: var(--danger-color);"></i>
                    <h5 class="mt-3 text-danger">DANGER: TR069 Management Connection</h5>
                    <p class="text-muted mb-2">You are about to delete the TR069 management connection!</p>
                    <p class="mb-0"><strong>Connection:</strong> <span id="delete-wan-tr069-name"></span></p>
                    <p class="mb-0"><strong>Service:</strong> <span id="delete-wan-tr069-service"></span></p>
                    <div class="alert alert-danger mt-3 text-start">
                        <strong>Deleting this connection will:</strong>
                        <ul class="mb-0">
                            <li>✗ Break communication with GenieACS</li>
                            <li>✗ Prevent remote management</li>
                            <li>✗ Require manual factory reset to restore</li>
                        </ul>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="delete-wan-tr069-confirm">
                        <label class="form-check-label" for="delete-wan-tr069-confirm">
                            <strong>I understand the risks and want to proceed</strong>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirm-delete-wan-btn" onclick="confirmDeleteWAN()">
                    <i class="bi bi-trash"></i> Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit DHCP Configuration Modal -->
<div class="modal fade" id="editDHCPModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-router"></i> Edit DHCP Server Configuration
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Info:</strong> Changes will be sent to the ONU via GenieACS. Device may need reboot to apply changes.
                </div>
                <form id="editDHCPForm">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="bi bi-toggle-on"></i> DHCP Server
                        </label>
                        <select class="form-select" id="edit-dhcp-enable" onchange="toggleDHCPFields()">
                            <option value="true">Enabled</option>
                            <option value="false">Disabled</option>
                        </select>
                    </div>

                    <div id="dhcp-config-fields">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-arrow-down-circle"></i> IP Address Pool Start
                            </label>
                            <input type="text" class="form-control" id="edit-dhcp-min-address" placeholder="e.g., 192.168.1.10" pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$">
                            <div class="form-text">First IP address in DHCP pool</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-arrow-up-circle"></i> IP Address Pool End
                            </label>
                            <input type="text" class="form-control" id="edit-dhcp-max-address" placeholder="e.g., 192.168.1.200" pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$">
                            <div class="form-text">Last IP address in DHCP pool</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-diagram-2"></i> Subnet Mask
                            </label>
                            <input type="text" class="form-control" id="edit-dhcp-subnet-mask" placeholder="e.g., 255.255.255.0" pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-router-fill"></i> Default Gateway
                            </label>
                            <input type="text" class="form-control" id="edit-dhcp-gateway" placeholder="e.g., 192.168.1.1" pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-server"></i> DNS Servers
                            </label>
                            <input type="text" class="form-control" id="edit-dhcp-dns" placeholder="e.g., 8.8.8.8,8.8.4.4">
                            <div class="form-text">Comma-separated IP addresses</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                <i class="bi bi-clock"></i> Lease Time (seconds)
                            </label>
                            <input type="number" class="form-control" id="edit-dhcp-lease-time" placeholder="e.g., 86400" min="60">
                            <div class="form-text">Minimum 60 seconds (86400 = 24 hours)</div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="confirmUpdateDHCP()">
                    <i class="bi bi-check-lg"></i> Update DHCP
                </button>
            </div>
        </div>
    </div>
</div>



<!-- Add Tag Modal -->
<div class="modal fade" id="addTagModal" tabindex="-1" data-bs-backdrop="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-tag"></i> Add Tag to Device
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addTagForm">
                    <div class="mb-3">
                        <label class="form-label">Tag Name</label>
                        <input type="text" class="form-control" id="addTagName" placeholder="Enter tag name" required>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i> Tag akan ditambahkan ke device ini
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="addTagToDevice()">
                    <i class="bi bi-check-lg"></i> Add Tag
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Remove Tag Modal -->
<div class="modal fade" id="removeTagModal" tabindex="-1" data-bs-backdrop="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-tag-fill"></i> Remove Tag from Device
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="removeTagContent">
                    <p>Select tag to remove:</p>
                    <div id="deviceTagsList"></div>
                </div>
                <div id="noTagsMessage" style="display: none;">
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle"></i> Device ini belum memiliki tag
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
