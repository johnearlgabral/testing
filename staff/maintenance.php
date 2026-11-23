<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

$pageTitle = 'Maintenance';
$activeNav = 'maintenance';

$venues     = $pdo->query("SELECT id, venue_name FROM venues WHERE is_active = 1 ORDER BY venue_name ASC")->fetchAll();
$activities = $pdo->query("SELECT id, activity_name FROM activities ORDER BY activity_date DESC LIMIT 50")->fetchAll();
$utilities  = $pdo->query("SELECT id, utility_name FROM utilities WHERE is_active = 1 ORDER BY utility_name ASC")->fetchAll();

$logs = $pdo->query(
    "SELECT m.*, u.full_name AS created_by_name, v.venue_name, a.activity_name, ut.utility_name
     FROM maintenance_logs m
     JOIN users u ON u.id = m.created_by
     LEFT JOIN venues v ON v.id = m.venue_id
     LEFT JOIN activities a ON a.id = m.activity_id
     LEFT JOIN utilities ut ON ut.id = m.utility_id
     ORDER BY m.created_at DESC"
)->fetchAll();

$errors = $_SESSION['maintenance_errors'] ?? [];
unset($_SESSION['maintenance_errors']);
$added = $_GET['added'] ?? null;

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Maintenance Logging</h1>
            <p class="page-subtitle">Record maintenance notes for venues, activities, or utilities.</p>
        </div>
    </div>

    <?php if ($added === '1'): ?>
        <div class="alert alert-success">Maintenance note added successfully.</div>
    <?php elseif (!empty($errors)): ?>
        <div class="alert alert-error"><?= e(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="card">
            <div class="card-title">Add Maintenance Note</div>
            <form method="POST" action="/UBMSConnect/actions/add_maintenance.php" class="maintenance-note-form">
                <div class="form-group">
                    <label>Related Resource <span class="text-muted">(at least one required)</span></label>
                    <p class="small-note" style="margin-top: 4px; margin-bottom: 10px;">Select the resource this maintenance note relates to. Choose one type, then pick the specific item.</p>

                    <div class="resource-type-selector" style="margin-bottom: 12px; display: flex; gap: 14px; flex-wrap: wrap;">
                        <label class="resource-option" style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 14px; color: var(--gray-700);">
                            <input type="radio" name="resource_type" value="venue" onchange="toggleResourceDropdown('venue')">
                            <span>Venue</span>
                        </label>
                        <label class="resource-option" style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 14px; color: var(--gray-700);">
                            <input type="radio" name="resource_type" value="activity" onchange="toggleResourceDropdown('activity')">
                            <span>Activity</span>
                        </label>
                        <label class="resource-option" style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 14px; color: var(--gray-700);">
                            <input type="radio" name="resource_type" value="utility" onchange="toggleResourceDropdown('utility')">
                            <span>Utility</span>
                        </label>
                    </div>

                    <div id="resource-dropdowns" style="margin-top: 8px;">
                        <div id="venue-group" style="display: none;" class="form-group">
                            <label>Select venue</label>
                            <select name="venue_id">
                                <option value="">-- Choose a venue --</option>
                                <?php foreach ($venues as $v): ?>
                                    <option value="<?= (int)$v['id'] ?>"><?= e($v['venue_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="activity-group" style="display: none;" class="form-group">
                            <label>Select activity</label>
                            <select name="activity_id">
                                <option value="">-- Choose an activity --</option>
                                <?php foreach ($activities as $a): ?>
                                    <option value="<?= (int)$a['id'] ?>"><?= e($a['activity_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="utility-group" style="display: none;" class="form-group">
                            <label>Select utility</label>
                            <select name="utility_id">
                                <option value="">-- Choose a utility --</option>
                                <?php foreach ($utilities as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= e($u['utility_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <p class="small-note" style="margin-top: 8px;">The selected resource will be linked to this note. You don't need to select all three types.</p>
                </div>
                <div class="form-group">
                    <label>Note Description</label>
                    <textarea name="note" placeholder="e.g. Air conditioning unit requires inspection." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Add Maintenance Note</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title">
                Maintenance History (<?= count($logs) ?>)
                <?php if (!empty($logs)): ?>
                    <button class="btn btn-sm btn-danger" onclick="clearAllMaintenance()">Clear All</button>
                <?php endif; ?>
            </div>
            <?php if (empty($logs)): ?>
                <div class="empty-state">No maintenance notes recorded yet.</div>
            <?php else: ?>
                <p class="small-note">"Clear" permanently deletes that record. "Clear All" permanently deletes every record shown here. Both are recorded in the audit log.</p>
                <div class="table-wrap" style="max-height:520px; overflow-y:auto;">
                    <table class="data-table">
                        <thead><tr><th>Note</th><th>Related To</th><th>By</th><th>Date</th><th></th></tr></thead>
                        <tbody id="maintenanceHistoryBody">
                        <?php foreach ($logs as $log):
                            $related = array_filter([$log['venue_name'], $log['activity_name'], $log['utility_name']]);
                        ?>
                            <tr id="maint-row-<?= (int)$log['id'] ?>">
                                <td><?= e($log['note']) ?></td>
                                <td class="text-muted"><?= $related ? e(implode(', ', $related)) : 'General' ?></td>
                                <td><?= e($log['created_by_name']) ?></td>
                                <td><?= e(formatDateTime($log['created_at'])) ?></td>
                                <td><button class="btn btn-sm" onclick="clearOneMaintenance(<?= (int)$log['id'] ?>)">Clear</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php
$extraScripts = <<<'JS'
<script>
function toggleResourceDropdown(type) {
    // Show only the dropdown for the chosen resource type;
    // hide all others and clear their values so no conflicting IDs are submitted.
    ['venue', 'activity', 'utility'].forEach(function (t) {
        var group = document.getElementById(t + '-group');
        var select = group ? group.querySelector('select') : null;
        if (group) {
            group.style.display = (t === type) ? 'block' : 'none';
        }
        if (select) {
            select.value = '';
        }
    });
}

function clearOneMaintenance(id) {
    if (!confirm('Permanently delete this maintenance record? This cannot be undone.')) return;

    postJSON('/UBMSConnect/actions/manage_maintenance.php', 'action=delete_one&id=' + encodeURIComponent(id))
        .then(function (data) {
            if (data.success) {
                const row = document.getElementById('maint-row-' + id);
                if (row) row.remove();
            } else {
                alert(data.message || 'Failed to clear the record.');
            }
        })
        .catch(function (err) { alert(err.message || 'Something went wrong.'); });
}

function clearAllMaintenance() {
    if (!confirm('This will PERMANENTLY DELETE every maintenance record shown on this page. This cannot be undone. Continue?')) return;
    const phrase = prompt('Type CLEAR ALL (in capitals) to confirm permanent deletion of all maintenance records:');
    if (phrase === null) return;

    postJSON('/UBMSConnect/actions/manage_maintenance.php', 'action=delete_all&confirm_phrase=' + encodeURIComponent(phrase))
        .then(function (data) {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert(data.message || 'Failed to clear maintenance records.');
            }
        })
        .catch(function (err) { alert(err.message || 'Something went wrong.'); });
}
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
