<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../includes/venue_availability.php';
require_once __DIR__ . '/../includes/venue_details.php';

$pageTitle = 'Venues';
$activeNav = 'venues';

$showInactive = isset($_GET['show_inactive']);

$sql = "SELECT v.*,
        (SELECT a.activity_name FROM activities a
         WHERE a.venue_id = v.id AND a.activity_date = CURDATE() AND a.status IN ('Approved')
         ORDER BY a.start_time ASC LIMIT 1) AS current_activity
     FROM venues v";
if (!$showInactive) {
    $sql .= " WHERE v.is_active = 1";
}
$sql .= " ORDER BY v.venue_name ASC";
$venues = $pdo->query($sql)->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Venue Monitoring</h1>
            <p class="page-subtitle">Current condition of university venues and facilities. This is a monitoring view, not a booking system.</p>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="?<?= $showInactive ? '' : 'show_inactive=1' ?>" class="btn btn-sm"><?= $showInactive ? 'Hide Deactivated' : 'Show Deactivated' ?></a>
            <button class="btn btn-primary" onclick="openCreateVenue()">+ Add Venue</button>
        </div>
    </div>

    <?php renderVenueAvailabilityPanel(); ?>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Photo</th><th>Venue</th><th>Building / Location</th><th>Capacity</th><th>Today's Activity</th><th>Status</th><th>Update Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if (empty($venues)): ?>
                    <tr><td colspan="8" class="empty-state">No venues on record.</td></tr>
                <?php endif; ?>
                <?php foreach ($venues as $v): ?>
                    <tr id="venue-row-<?= (int)$v['id'] ?>" style="<?= $v['is_active'] ? '' : 'opacity:0.55;' ?>">
                        <td><?= venueImageHtml($v['image_path'] ?? null, $v['venue_name']) ?></td>
                        <td><?= e($v['venue_name']) ?><?= $v['is_active'] ? '' : ' <span class="small-note">(deactivated)</span>' ?></td>
                        <td><?= e($v['building']) ?><?= $v['location'] ? '<br><span class="text-muted">' . e($v['location']) . '</span>' : '' ?></td>
                        <td><?= (int)$v['capacity'] ?></td>
                        <td><?= $v['current_activity'] ? e($v['current_activity']) : '<span class="text-muted">None scheduled</span>' ?></td>
                        <td id="venue-status-<?= (int)$v['id'] ?>"><span class="badge <?= venueBadgeClass($v['status']) ?>"><?= e($v['status']) ?></span></td>
                        <td>
                            <select id="venue-select-<?= (int)$v['id'] ?>" style="width:auto; display:inline-block; padding:6px 8px; font-size:12.5px;" <?= $v['is_active'] ? '' : 'disabled' ?>>
                                <?php foreach (validVenueStatuses() as $s): ?>
                                    <option value="<?= e($s) ?>" <?= $s === $v['status'] ? 'selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary" id="venue-save-<?= (int)$v['id'] ?>" onclick="updateVenue(<?= (int)$v['id'] ?>)" <?= $v['is_active'] ? '' : 'disabled' ?>>Save</button>
                            <div id="venue-msg-<?= (int)$v['id'] ?>" class="small-note" style="margin-top:4px;"></div>
                        </td>
                        <td style="white-space:nowrap;">
                            <button class="btn btn-sm" onclick='openVenueDetails(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>View details</button>
                            <button class="btn btn-sm" onclick='openEditVenue(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                            <?php if ($v['is_active']): ?>
                                <button class="btn btn-sm btn-danger" onclick="toggleVenueActive(<?= (int)$v['id'] ?>, 0, '<?= e(addslashes($v['venue_name'])) ?>')">Deactivate</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-success" onclick="toggleVenueActive(<?= (int)$v['id'] ?>, 1, '<?= e(addslashes($v['venue_name'])) ?>')">Reactivate</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php renderVenueDetailsModal(); ?>

<!-- Create/Edit Venue Modal -->
<div class="modal-overlay" id="venueModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="venueModalTitle">Add Venue</h3>
            <button class="modal-close" data-close-modal="venueModal">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="vId">
            <div class="form-group"><label>Venue Name</label><input type="text" id="vName"></div>
            <div class="grid-2">
                <div class="form-group"><label>Building</label><input type="text" id="vBuilding"></div>
                <div class="form-group"><label>Location / Floor</label><input type="text" id="vLocation" placeholder="e.g. 2nd Floor, East Wing"></div>
            </div>
            <div class="grid-2">
                <div class="form-group"><label>Capacity</label><input type="text" id="vCapacity" placeholder="e.g. 50"></div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="vStatus">
                        <?php foreach (validVenueStatuses() as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Description</label><textarea id="vDescription"></textarea></div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="vIsFree">Venue Use</label>
                    <select id="vIsFree" onchange="toggleVenueFeeAmount()">
                        <option value="1">Free of charge</option>
                        <option value="0">Requires payment</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="vUsageFee">Fee Amount (PHP)</label>
                    <input type="number" id="vUsageFee" min="0.01" step="0.01" placeholder="e.g. 500.00">
                </div>
            </div>
            <div class="form-group">
                <label for="vImage">Venue Photo <span class="text-muted">(optional)</span></label>
                <div class="venue-upload-control">
                    <img id="vImagePreview" class="venue-image-preview" alt="Venue photo preview" hidden>
                    <div id="vImagePlaceholder" class="venue-thumbnail venue-thumbnail-placeholder"><span>No photo yet</span></div>
                    <input type="file" id="vImage" accept="image/jpeg,image/png,image/webp">
                </div>
                <div class="small-note">JPG, PNG, or WebP. Maximum file size: 3MB.</div>
            </div>
            <div id="venueModalMsg"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" data-close-modal="venueModal">Cancel</button>
            <button class="btn btn-primary" id="venueSaveBtn" onclick="saveVenue()">Save Venue</button>
        </div>
    </div>
</div>

<?php
$extraScripts = venueAvailabilityScripts() . venueDetailsScripts() . <<<'JS'
<script>
function openCreateVenue() {
    document.getElementById('venueModalTitle').textContent = 'Add Venue';
    document.getElementById('vId').value = '';
    document.getElementById('vName').value = '';
    document.getElementById('vBuilding').value = '';
    document.getElementById('vLocation').value = '';
    document.getElementById('vCapacity').value = '';
    document.getElementById('vStatus').value = 'Available';
    document.getElementById('vDescription').value = '';
    document.getElementById('vIsFree').value = '1';
    document.getElementById('vUsageFee').value = '';
    toggleVenueFeeAmount();
    setVenueImagePreview('');
    document.getElementById('vImage').value = '';
    document.getElementById('venueModalMsg').innerHTML = '';
    openModal('venueModal');
}

function openEditVenue(v) {
    document.getElementById('venueModalTitle').textContent = 'Edit Venue - ' + v.venue_name;
    document.getElementById('vId').value = v.id;
    document.getElementById('vName').value = v.venue_name;
    document.getElementById('vBuilding').value = v.building;
    document.getElementById('vLocation').value = v.location || '';
    document.getElementById('vCapacity').value = v.capacity;
    document.getElementById('vStatus').value = v.status;
    document.getElementById('vDescription').value = v.description || '';
    document.getElementById('vIsFree').value = String(v.is_free) === '0' ? '0' : '1';
    document.getElementById('vUsageFee').value = v.usage_fee || '';
    toggleVenueFeeAmount();
    setVenueImagePreview(v.image_path ? '/UBMSConnect/assets/uploads/venues/' + encodeURIComponent(v.image_path) : '');
    document.getElementById('vImage').value = '';
    document.getElementById('venueModalMsg').innerHTML = '';
    openModal('venueModal');
}

function saveVenue() {
    const id = document.getElementById('vId').value;
    const isCreate = !id;
    const btn = document.getElementById('venueSaveBtn');
    const msg = document.getElementById('venueModalMsg');
    if (btn.disabled) return;
    btn.disabled = true; btn.textContent = 'Saving...';

    const data = new FormData();
    data.append('action', isCreate ? 'create' : 'edit');
    data.append('id', id);
    data.append('venue_name', document.getElementById('vName').value);
    data.append('building', document.getElementById('vBuilding').value);
    data.append('location', document.getElementById('vLocation').value);
    data.append('capacity', document.getElementById('vCapacity').value);
    data.append('status', document.getElementById('vStatus').value);
    data.append('description', document.getElementById('vDescription').value);
    data.append('is_free', document.getElementById('vIsFree').value);
    data.append('usage_fee', document.getElementById('vUsageFee').value);
    if (document.getElementById('vImage').files[0]) data.append('venue_image', document.getElementById('vImage').files[0]);

    fetch('/UBMSConnect/actions/manage_venue.php', { method: 'POST', body: data })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message) + ' Reloading...</div>';
                setTimeout(function () { location.reload(); }, 700);
            } else {
                msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message) + '</div>';
            }
        })
        .catch(function (err) { msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message) + '</div>'; })
        .then(function () { btn.disabled = false; btn.textContent = 'Save Venue'; });
}

function setVenueImagePreview(url) {
    const preview = document.getElementById('vImagePreview');
    const placeholder = document.getElementById('vImagePlaceholder');
    preview.src = url || '';
    preview.hidden = !url;
    placeholder.hidden = !!url;
}

function toggleVenueFeeAmount() {
    const input = document.getElementById('vUsageFee');
    const isFree = document.getElementById('vIsFree').value === '1';
    input.disabled = isFree;
    input.required = !isFree;
    if (isFree) input.value = '';
}

document.getElementById('vImage').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    setVenueImagePreview(URL.createObjectURL(file));
});

function toggleVenueActive(id, active, name) {
    const verb = active ? 'reactivate' : 'deactivate';
    if (!confirm('Are you sure you want to ' + verb + ' "' + name + '"?')) return;

    postJSON('/UBMSConnect/actions/manage_venue.php', 'action=set_active&id=' + encodeURIComponent(id) + '&is_active=' + active)
        .then(function (data) {
            if (data.success) { location.reload(); } else { alert(data.message || 'Action failed.'); }
        })
        .catch(function (err) { alert(err.message || 'Something went wrong.'); });
}

function updateVenue(venueId) {
    const select = document.getElementById('venue-select-' + venueId);
    const btn = document.getElementById('venue-save-' + venueId);
    const msg = document.getElementById('venue-msg-' + venueId);
    const status = select.value;

    if (btn.disabled) return;
    btn.disabled = true;
    btn.textContent = 'Saving...';
    msg.textContent = '';

    postJSON('/UBMSConnect/actions/update_venue.php',
        'venue_id=' + encodeURIComponent(venueId) + '&status=' + encodeURIComponent(status))
        .then(function (data) {
            if (data.success) {
                const cls = {'Available':'badge-approved','In Use':'badge-pending','Under Maintenance':'badge-maintenance','Unavailable':'badge-rejected'}[status] || 'badge-default';
                document.getElementById('venue-status-' + venueId).innerHTML = '<span class="badge ' + cls + '">' + escapeHtml(status) + '</span>';
                msg.innerHTML = '<span style="color:var(--green);">Saved.</span>';
                setTimeout(function () { msg.textContent = ''; }, 2500);
            } else {
                msg.innerHTML = '<span style="color:var(--red);">' + escapeHtml(data.message || 'Update failed.') + '</span>';
            }
        })
        .catch(function (err) {
            msg.innerHTML = '<span style="color:var(--red);">' + escapeHtml(err.message || 'Something went wrong.') + '</span>';
        })
        .then(function () {
            btn.disabled = false;
            btn.textContent = 'Save';
        });
}
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
