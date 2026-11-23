<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/school_year.php';
requireSuperAdmin();

$pageTitle = 'School Years';
$activeNav = 'school_years';

$schoolYears = getSchoolYears();

// How many activities sit in each school year (shown in the table).
$counts = [];
foreach ($pdo->query('SELECT school_year_id, COUNT(*) AS c FROM activities GROUP BY school_year_id') as $row) {
    $counts[(int) $row['school_year_id']] = (int) $row['c'];
}
$unassigned = activitiesWithoutSchoolYear();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>School Years</h1>
            <p class="page-subtitle">Define the academic years that activities, schedules, and generated reports are organised by.</p>
        </div>
    </div>

    <div class="card section-gap">
        <div class="card-title">Add a school year</div>
        <form class="filter-bar" onsubmit="addSchoolYear(event)">
            <div class="form-group">
                <label for="syName">Name</label>
                <input id="syName" placeholder="AY 2027-2028" maxlength="60" required>
            </div>
            <div class="form-group">
                <label for="syStart">Start date</label>
                <input type="date" id="syStart" required>
            </div>
            <div class="form-group">
                <label for="syEnd">End date</label>
                <input type="date" id="syEnd" required>
            </div>
            <label class="checkbox-row" style="margin-bottom:9px;">
                <input type="checkbox" id="syActive">
                <span>Make this the active school year</span>
            </label>
            <button class="btn btn-primary">Add school year</button>
        </form>
        <p class="small-note">Ranges must not overlap each other. The active school year is the default selection on the Reports page.</p>
        <div id="syMsg"></div>
    </div>

    <div class="card">
        <div class="card-title">Defined school years <span class="text-muted">(<?= count($schoolYears) ?>)</span></div>
        <?php if (!$schoolYears): ?>
            <div class="empty-state">No school years defined yet. Add one above so reports can be grouped by academic year.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>School year</th><th>Start</th><th>End</th><th>Activities</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($schoolYears as $sy): $syId = (int) $sy['id']; ?>
                        <tr>
                            <td><strong><?= e($sy['name']) ?></strong></td>
                            <td><?= e(formatDate($sy['start_date'])) ?></td>
                            <td><?= e(formatDate($sy['end_date'])) ?></td>
                            <td><?= (int) ($counts[$syId] ?? 0) ?></td>
                            <td>
                                <?php if ($sy['is_active']): ?>
                                    <span class="badge badge-approved">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-default">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <button class="btn btn-sm"
                                        onclick="editSchoolYear(<?= $syId ?>, '<?= e(addslashes($sy['name'])) ?>', '<?= e($sy['start_date']) ?>', '<?= e($sy['end_date']) ?>')">Edit</button>
                                <?php if (!$sy['is_active']): ?>
                                    <button class="btn btn-sm btn-success"
                                            onclick="activateSchoolYear(<?= $syId ?>, '<?= e(addslashes($sy['name'])) ?>')">Set active</button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-danger"
                                        onclick="deleteSchoolYear(<?= $syId ?>, '<?= e(addslashes($sy['name'])) ?>')">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card section-gap" style="margin-top:18px;">
        <div class="card-title">Re-sync activity records</div>
        <p class="small-note" style="margin:0 0 12px;">
            Recomputes which school year every activity belongs to from its date, and fills in the organization snapshot
            of any activity whose host has since been assigned one. Use this after adding or editing a school year.
        </p>
        <?php if ($unassigned > 0): ?>
            <div class="alert alert-info">
                <?= (int) $unassigned ?> activity record(s) currently fall outside every defined school year.
                Add the matching school year (or adjust the dates above), then re-sync.
            </div>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="resyncActivities()">Re-sync activities</button>
    </div>
</main>

<?php
$extraScripts = <<<'JS'
<script>
function syPost(payload) {
    return postJSON('/UBMSConnect/actions/manage_school_year.php', new URLSearchParams(payload).toString());
}
function syShow(data) {
    document.getElementById('syMsg').innerHTML = data.success
        ? '<div class="alert alert-success">' + escapeHtml(data.message || 'Saved.') + '</div>'
        : '<div class="alert alert-error">' + escapeHtml(data.message || 'Action failed.') + '</div>';
}
function syError(err) {
    syShow({ success: false, message: (err && err.message) ? err.message : 'Something went wrong.' });
}

function addSchoolYear(event) {
    event.preventDefault();
    syPost({
        action: 'add',
        name: document.getElementById('syName').value,
        start_date: document.getElementById('syStart').value,
        end_date: document.getElementById('syEnd').value,
        is_active: document.getElementById('syActive').checked ? 1 : 0
    }).then(function (data) {
        if (data.success) { location.reload(); } else { syShow(data); }
    }).catch(syError);
}

function editSchoolYear(id, name, start, end) {
    var newName = prompt('School year name:', name);
    if (newName === null) return;
    var newStart = prompt('Start date (YYYY-MM-DD):', start);
    if (newStart === null) return;
    var newEnd = prompt('End date (YYYY-MM-DD):', end);
    if (newEnd === null) return;

    syPost({ action: 'update', id: id, name: newName, start_date: newStart, end_date: newEnd })
        .then(function (data) { if (data.success) { location.reload(); } else { syShow(data); } })
        .catch(syError);
}

function activateSchoolYear(id, name) {
    if (!confirm('Make "' + name + '" the active school year?')) return;
    syPost({ action: 'activate', id: id })
        .then(function (data) { if (data.success) { location.reload(); } else { syShow(data); } })
        .catch(syError);
}

function deleteSchoolYear(id, name) {
    if (!confirm('Delete "' + name + '"? Activities in that range become unassigned until you re-sync.')) return;
    syPost({ action: 'delete', id: id })
        .then(function (data) {
            if (data.success) { alert(data.message || 'Deleted.'); location.reload(); } else { syShow(data); }
        })
        .catch(syError);
}

function resyncActivities() {
    syShow({ success: true, message: 'Re-syncing...' });
    syPost({ action: 'resync' })
        .then(function (data) { syShow(data); })
        .catch(syError);
}
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
