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

<!-- Not In Map Alert Modal -->
<div class="modal fade" id="notInMapModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-circle"></i> ONU Belum Terdaftar
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="bi bi-map" style="font-size: 3rem; color: var(--secondary-color);"></i>
                <h5 class="mt-3">ONU Belum Terdaftar di Map</h5>
                <p class="text-muted mb-2">Device dengan Serial Number <strong id="not-in-map-serial"></strong> belum terdaftar di Network Map.</p>
                <p class="text-muted mb-0"><small>Silakan tambahkan ONU ini ke map terlebih dahulu untuk melihat lokasi topologi.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Tutup
                </button>
                <button type="button" class="btn btn-primary" onclick="window.open('/map.php', '_blank')">
                    <i class="bi bi-map"></i> Buka Network Map
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Add Tag Modal -->
<div class="modal fade" id="bulkAddTagModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-tag-fill"></i> Add Tag to Selected Devices
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Add tag to <strong id="add-tag-count">0</strong> selected device(s)</p>
                <div class="mb-3">
                    <label for="new-tag-name" class="form-label">Tag Name</label>
                    <input type="text" class="form-control" id="new-tag-name" placeholder="Enter tag name (e.g., Dumara, VIP, etc.)">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="confirmBulkAddTag()">
                    <i class="bi bi-tag-fill"></i> Add Tag
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Untag Modal -->
<div class="modal fade" id="bulkUntagModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-tags"></i> Remove Tag from Selected Devices
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Remove tag from <strong id="untag-count">0</strong> selected device(s)</p>
                <div class="mb-3">
                    <label for="remove-tag-name" class="form-label">Tag Name to Remove</label>
                    <input type="text" class="form-control" id="remove-tag-name" placeholder="Enter tag name to remove">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="button" class="btn btn-warning" onclick="confirmBulkUntag()">
                    <i class="bi bi-tags"></i> Remove Tag
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Delete Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-trash"></i> Delete Selected Devices
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: var(--danger-color);"></i>
                <h5 class="mt-3 text-danger">WARNING: This action cannot be undone!</h5>
                <p class="text-muted mb-2">You are about to delete <strong id="delete-count">0</strong> device(s) from GenieACS.</p>
                <p class="text-muted mb-0"><small>This will permanently remove the devices from the ACS database.</small></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <button type="button" class="btn btn-danger" onclick="confirmBulkDelete()">
                    <i class="bi bi-trash"></i> Yes, Delete
                </button>
            </div>
        </div>
    </div>
</div>
