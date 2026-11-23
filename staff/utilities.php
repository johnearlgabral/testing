<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');
require_once __DIR__ . '/../includes/utility_availability.php';
require_once __DIR__ . '/../includes/utility_details.php';

$pageTitle = 'Utilities';
$activeNav = 'utilities';

$showInactive = isset($_GET['show_inactive']);

/* "Today's activity" reuses the existing scheduling system instead of a second
   booking system: activities belong to venues, so a utility's activity today
   is the first approved activity in any venue whose NAME or BUILDING matches
   the utility's location (e.g. "Conference Room A", "Main Building"). */
$sql = "SELECT u.*,
        (SELECT a.activity_name FROM activities a
         WHERE a.activity_date = CURDATE() AND a.status IN ('Approved')
           AND a.venue_id IN (SELECT v.id FROM venues v
                              WHERE v.venue_name = u.location OR v.building = u.location)
         ORDER BY a.start_time ASC LIMIT 1) AS current_activity,
        EXISTS (SELECT 1 FROM venues v
                WHERE v.venue_name = u.location OR v.building = u.location) AS has_schedule
     FROM utilities u";
if (!$showInactive) {
    $sql .= " WHERE u.is_active = 1";
}
$sql .= " ORDER BY u.utility_name ASC";
$utilities = $pdo->query($sql)->fetchAll();

// Row payload for the details modal (pre-formatted timestamp).
foreach ($utilities as $i => $u) {
    $utilities[$i]['updated_at_fmt'] = formatDateTime($u['updated_at']);
}
// Current notes per id, so an inline status save never wipes the description.
$utilityNotes = [];
foreach ($utilities as $u) {
    $utilityNotes[(int) $u['id']] = (string) ($u['description'] ?? '');
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Utility Monitoring</h1>
            <p class="page-subtitle">Condition and availability of campus utilities. This is a monitoring view, not a booking system.</p>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="?<?= $showInactive ? '' : 'show_inactive=1' ?>" class="btn btn-sm"><?= $showInactive ? 'Hide Deactivated' : 'Show Deactivated' ?></a>
            <button class="btn btn-primary" onclick="openCreateUtility()">+ Add Utility</button>
        </div>
    </div>

    <?php renderUtilityAvailabilityPanel(); ?>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Photo</th><th>Utility</th><th>Location</th><th>Quantity</th><th>Today's Activity</th><th>Status</th><th>Update Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if (empty($utilities)): ?>
                    <tr><td colspan="8" class="empty-state">No utilities on record.</td></tr>
                <?php endif; ?>
                <?php foreach ($utilities as $u): ?>
                    <tr id="util-row-<?= (int)$u['id'] ?>" style="<?= $u['is_active'] ? '' : 'opacity:0.55;' ?>">
                        <td><?= venueImageHtml($u['image_path'] ?? null, $u['utility_name'], 'utilities') ?></td>
                        <td><?= e($u['utility_name']) ?><?= $u['is_active'] ? '' : ' <span class="small-note">(deactivated)</span>' ?><br><span class="text-muted"><?= e($u['category'] ?: 'N/A') ?></span></td>
                        <td><?= e($u['location']) ?></td>
                        <td><?= $u['quantity'] !== null ? (int) $u['quantity'] : '<span class="text-muted">&mdash;</span>' ?></td>
                        <td><?php if (!$u['has_schedule']): ?><span class="text-muted">No linked schedule</span><?php else: ?><?= $u['current_activity'] ? e($u['current_activity']) : '<span class="text-muted">None scheduled</span>' ?><?php endif; ?></td>
                        <td id="util-status-<?= (int)$u['id'] ?>"><span class="badge <?= utilityBadgeClass($u['status']) ?>"><?= e($u['status']) ?></span></td>
                        <td>
                            <select id="util-select-<?= (int)$u['id'] ?>" style="width:auto; display:inline-block; padding:6px 8px; font-size:12.5px;" <?= $u['is_active'] ? '' : 'disabled' ?>>
                                <?php foreach (validUtilityStatuses() as $s): ?>
                                    <option value="<?= e($s) ?>" <?= $s === $u['status'] ? 'selected' : '' ?>><?= e($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary" id="util-save-<?= (int)$u['id'] ?>" onclick="updateUtilityStatus(<?= (int)$u['id'] ?>)" <?= $u['is_active'] ? '' : 'disabled' ?>>Save</button>
                            <div id="util-msg-<?= (int)$u['id'] ?>" class="small-note" style="margin-top:4px;"></div>
                        </td>
                        <td style="white-space:nowrap;">
                            <button class="btn btn-sm" onclick='openUtilityDetails(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>View details</button>
                            <button class="btn btn-sm" onclick='openEditUtility(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                            <?php if ($u['is_active']): ?>
                                <button class="btn btn-sm btn-danger" onclick="toggleUtilityActive(<?= (int)$u['id'] ?>, 0, '<?= e(addslashes($u['utility_name'])) ?>')">Deactivate</button>
                            <?php else: ?>
                                <button class="btn btn-sm btn-success" onclick="toggleUtilityActive(<?= (int)$u['id'] ?>, 1, '<?= e(addslashes($u['utility_name'])) ?>')">Reactivate</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php renderUtilityDetailsModal(); ?>

<!-- Create/Edit Utility Modal -->
<div class="modal-overlay" id="utilityModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="utilModalTitle">Add Utility</h3>
            <button class="modal-close" data-close-modal="utilityModal">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="utilId">
            <div class="form-group"><label>Utility Name</label><input type="text" id="utilName" placeholder="e.g. Electricity, Water, AV Equipment"></div>
            <div class="grid-2">
                <div class="form-group"><label>Category</label><input type="text" id="utilCategory" placeholder="e.g. Power, Plumbing, HVAC, IT"></div>
                <div class="form-group"><label>Location</label><input type="text" id="utilLocation" placeholder="e.g. Main Building"></div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="uQuantity">Quantity <span class="text-muted">(optional)</span></label>
                    <input type="number" id="uQuantity" min="1" step="1" placeholder="Leave blank if not applicable">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="utilStatus">
                        <?php foreach (validUtilityStatuses() as $s): ?>
                            <option value="<?= e($s) ?>"><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Description / Notes</label>
                <textarea id="utilDescription"></textarea>
            </div>
            <div class="form-group">
                <label for="uImage">Utility Photo <span class="text-muted">(optional)</span></label>
                <div class="venue-upload-control">
                    <img id="uImagePreview" class="venue-image-preview" alt="Utility photo preview" hidden>
                    <div id="uImagePlaceholder" class="venue-thumbnail venue-thumbnail-placeholder"><span>No photo yet</span></div>
                    <input type="file" id="uImage" accept="image/jpeg,image/png,image/webp">
                </div>
                <div class="small-note">JPG, PNG, or WebP. Maximum file size: 3MB.</div>
            </div>
            <div id="utilMsg"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" data-close-modal="utilityModal">Cancel</button>
            <button class="btn btn-primary" id="utilSaveBtn" onclick="saveUtility()">Save Utility</button>
        </div>
    </div>
</div>

<?php
// The note map is injected from PHP into the script below (a nowdoc cannot
// contain PHP tags, so the first line is concatenated in).
$extraScripts = utilityAvailabilityScripts() . utilityDetailsScripts()
    . '<script>const UTILITY_NOTES = '
    . json_encode($utilityNotes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';' . <<<'JS'

function openCreateUtility() {
    document.getElementById('utilModalTitle').textContent = 'Add Utility';
    document.getElementById('utilId').value = '';
    document.getElementById('utilName').value = '';
    document.getElementById('utilCategory').value = '';
    document.getElementById('utilLocation').value = '';
    document.getElementById('uQuantity').value = '';
    document.getElementById('utilStatus').value = 'Operational';
    document.getElementById('utilDescription').value = '';
    setUtilityImagePreview('');
    document.getElementById('uImage').value = '';
    document.getElementById('utilMsg').innerHTML = '';
    const saveBtn = document.getElementById('utilSaveBtn');
    saveBtn.disabled = false;
    saveBtn.textContent = 'Save Utility';
    openModal('utilityModal');
}

function openEditUtility(u) {
    document.getElementById('utilModalTitle').textContent = 'Edit Utility - ' + u.utility_name;
    document.getElementById('utilId').value = u.id;
    document.getElementById('utilName').value = u.utility_name;
    document.getElementById('utilCategory').value = u.category || '';
    document.getElementById('utilLocation').value = u.location;
    document.getElementById('uQuantity').value = (u.quantity === null || u.quantity === undefined) ? '' : u.quantity;
    document.getElementById('utilStatus').value = u.status;
    document.getElementById('utilDescription').value = u.description || '';
    setUtilityImagePreview(u.image_path ? '/UBMSConnect/assets/uploads/utilities/' + encodeURIComponent(u.image_path) : '');
    document.getElementById('uImage').value = '';
    document.getElementById('utilMsg').innerHTML = '';
    const saveBtn = document.getElementById('utilSaveBtn');
    saveBtn.disabled = false;
    saveBtn.textContent = 'Save Utility';
    openModal('utilityModal');
}

function saveUtility() {
    const id = document.getElementById('utilId').value;
    const isCreate = !id;
    const saveBtn = document.getElementById('utilSaveBtn');
    const msg = document.getElementById('utilMsg');

    if (saveBtn.disabled) return;
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving...';
    msg.innerHTML = '<span class="text-muted">Saving...</span>';

    const data = new FormData();
    data.append('action', isCreate ? 'create' : 'edit');
    data.append('id', id);
    data.append('utility_name', document.getElementById('utilName').value);
    data.append('category', document.getElementById('utilCategory').value);
    data.append('location', document.getElementById('utilLocation').value);
    data.append('quantity', document.getElementById('uQuantity').value);
    data.append('status', document.getElementById('utilStatus').value);
    data.append('description', document.getElementById('utilDescription').value);
    if (document.getElementById('uImage').files[0]) data.append('utility_image', document.getElementById('uImage').files[0]);

    fetch('/UBMSConnect/actions/manage_utility.php', { method: 'POST', body: data })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message) + ' Reloading...</div>';
                setTimeout(function () { location.reload(); }, 700);
            } else {
                msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message || 'Update failed.') + '</div>';
            }
        })
        .catch(function (err) {
            msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message || 'Something went wrong. Please try again.') + '</div>';
        })
        .then(function () {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save Utility';
        });
}

function setUtilityImagePreview(url) {
    const preview = document.getElementById('uImagePreview');
    const placeholder = document.getElementById('uImagePlaceholder');
    preview.src = url || '';
    preview.hidden = !url;
    placeholder.hidden = !!url;
}

document.getElementById('uImage').addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    setUtilityImagePreview(URL.createObjectURL(file));
});

function updateUtilityStatus(utilityId) {
    const select = document.getElementById('util-select-' + utilityId);
    const btn = document.getElementById('util-save-' + utilityId);
    const msg = document.getElementById('util-msg-' + utilityId);
    const status = select.value;

    if (btn.disabled) return;
    btn.disabled = true;
    btn.textContent = 'Saving...';
    msg.textContent = '';

    // The endpoint also stores the description; send the row's current one so
    // an inline status save never wipes the notes.
    postJSON('/UBMSConnect/actions/update_utility.php',
        'utility_id=' + encodeURIComponent(utilityId)
        + '&status=' + encodeURIComponent(status)
        + '&description=' + encodeURIComponent(UTILITY_NOTES[utilityId] || ''))
        .then(function (data) {
            if (data.success) {
                document.getElementById('util-status-' + utilityId).innerHTML = '<span class="badge ' + utilityStatusBadgeClass(status) + '">' + escapeHtml(status) + '</span>';
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

function toggleUtilityActive(id, active, name) {
    const verb = active ? 'reactivate' : 'deactivate';
    if (!confirm('Are you sure you want to ' + verb + ' "' + name + '"?')) return;

    postJSON('/UBMSConnect/actions/manage_utility.php', 'action=set_active&id=' + encodeURIComponent(id) + '&is_active=' + active)
        .then(function (data) {
            if (data.success) { location.reload(); } else { alert(data.message || 'Action failed.'); }
        })
        .catch(function (err) { alert(err.message || 'Something went wrong.'); });
}
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
