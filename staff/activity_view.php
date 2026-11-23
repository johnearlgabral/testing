<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

// Venue schedule conflict detection (moderator item 11). Staff are the only
// people who can approve an activity, so this page shows the clash here and
// the approval itself is guarded server-side in actions/update_status.php.
require_once __DIR__ . '/../includes/conflicts.php';

$pageTitle = 'Activity Details';
$activeNav = 'activities';

$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT a.*, u.full_name AS host_name, v.venue_name, v.building, v.status AS venue_status,
            o.organization_name, sy.name AS school_year_name
     FROM activities a
     JOIN users u ON u.id = a.host_id
     JOIN venues v ON v.id = a.venue_id
     LEFT JOIN organizations o ON o.id = a.organization_id
     LEFT JOIN school_years sy ON sy.id = a.school_year_id
     WHERE a.id = ?"
);
$stmt->execute([$id]);
$activity = $stmt->fetch();

if (!$activity) {
    include __DIR__ . '/../includes/header.php';
    include __DIR__ . '/../includes/sidebar.php';
    echo '<main class="main-content"><div class="empty-state">Activity not found.</div></main>';
    include __DIR__ . '/../includes/footer.php';
    exit;
}

$histStmt = $pdo->prepare(
    "SELECT l.*, u.full_name AS changed_by_name
     FROM activity_status_logs l JOIN users u ON u.id = l.changed_by
     WHERE l.activity_id = ? ORDER BY l.changed_at DESC"
);
$histStmt->execute([$id]);
$history = $histStmt->fetchAll();

// Overlaps with other activities at this venue on this date. Completed records
// are included so past clashes are visible too.
$conflicts = activityConflicts($id, true, true);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1><?= e($activity['activity_name']) ?></h1>
            <p class="page-subtitle">Full activity record and status history.</p>
        </div>
        <a href="/UBMSConnect/staff/activities.php" class="btn btn-sm">&larr; Back to Activities</a>
    </div>

    <?php if ($conflicts): ?>
        <div class="alert alert-error conflict-warning">
            <strong>Schedule conflict.</strong>
            This activity shares <strong><?= e($activity['venue_name']) ?></strong> with
            <?= count($conflicts) === 1 ? 'another record' : count($conflicts) . ' other records' ?>
            at an overlapping time on <?= e(formatDate($activity['activity_date'])) ?>.
            <?php if ($activity['status'] !== 'Approved'): ?>
                Approving it would double-book the venue.
            <?php endif; ?>
            <ul class="conflict-confirm-list">
                <?php foreach ($conflicts as $conflict): ?>
                    <li>
                        <a href="/UBMSConnect/staff/activity_view.php?id=<?= (int) $conflict['id'] ?>"><?= e($conflict['name']) ?></a>
                        <span class="badge <?= statusBadgeClass($conflict['status']) ?>"><?= e($conflict['status']) ?></span>
                        <div class="small-note">
                            <?= e(formatTime($conflict['start'])) ?> &ndash; <?= e(formatTime($conflict['end'])) ?>
                            &middot; host <?= e($conflict['host']) ?>
                            &middot; overlaps by <?= e(formatOverlapMinutes($conflict['overlap_minutes'])) ?>
                            (<?= e(formatTime($conflict['overlap_start'])) ?> &ndash; <?= e(formatTime($conflict['overlap_end'])) ?>)
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="small-note">
                Changing this record to <em>Approved</em> will be refused until you confirm, because the venue would
                be double-booked. See <a href="/UBMSConnect/conflicts.php">Schedule Conflicts</a> for every detected clash.
            </p>
        </div>
    <?php endif; ?>

    <div class="grid-2">
        <div class="card">
            <div class="card-title">Activity Information</div>
            <div class="detail-row"><div class="dr-label">Activity ID</div><div class="dr-value">#<?= (int)$activity['id'] ?></div></div>
            <div class="detail-row"><div class="dr-label">Description</div><div class="dr-value"><?= e($activity['description'] ?: 'N/A') ?></div></div>
            <div class="detail-row"><div class="dr-label">Event Host</div><div class="dr-value"><?= e($activity['host_name']) ?></div></div>
            <div class="detail-row"><div class="dr-label">Venue</div><div class="dr-value"><?= e($activity['venue_name']) ?> (<?= e($activity['building']) ?>)</div></div>
            <div class="detail-row"><div class="dr-label">Date</div><div class="dr-value"><?= e(formatDate($activity['activity_date'])) ?></div></div>
            <div class="detail-row"><div class="dr-label">Time</div><div class="dr-value"><?= e(formatTime($activity['start_time'])) ?> - <?= e(formatTime($activity['end_time'])) ?></div></div>
            <div class="detail-row"><div class="dr-label">School Year</div><div class="dr-value"><?= e($activity['school_year_name'] ?: 'Not assigned to a school year') ?></div></div>
            <div class="detail-row"><div class="dr-label">Organization</div><div class="dr-value"><?= e($activity['organization_name'] ?: 'Not recorded') ?></div></div>
            <div class="detail-row"><div class="dr-label">Current Status</div><div class="dr-value"><span class="badge <?= statusBadgeClass($activity['status']) ?>"><?= e($activity['status']) ?></span></div></div>
            <div class="detail-row"><div class="dr-label">Venue Condition</div><div class="dr-value"><span class="badge <?= venueBadgeClass($activity['venue_status']) ?>"><?= e($activity['venue_status']) ?></span></div></div>
            <div class="detail-row"><div class="dr-label">Last Updated</div><div class="dr-value"><?= e(formatDateTime($activity['updated_at'])) ?></div></div>

            <h4 style="margin-top:18px;">Change Status</h4>
            <div id="viewStatusMsg"></div>
            <form style="display:flex; gap:8px; align-items:flex-end;" onsubmit="return false;">
                <div style="flex:1;">
                    <select id="viewNewStatus">
                        <?php foreach (validActivityStatuses() as $s): ?>
                            <option value="<?= e($s) ?>" <?= $s === $activity['status'] ? 'selected' : '' ?>><?= e($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-primary" id="viewUpdateBtn" onclick="updateStatusInline(<?= (int)$activity['id'] ?>)">Update Status</button>
            </form>
        </div>

        <div class="card">
            <div class="card-title">Status History</div>
            <?php if (empty($history)): ?>
                <div class="empty-state">No status changes recorded yet.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Previous</th><th>New</th><th>Changed By</th><th>Date/Time</th></tr></thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td><span class="badge <?= statusBadgeClass($h['previous_status']) ?>"><?= e($h['previous_status']) ?></span></td>
                                <td><span class="badge <?= statusBadgeClass($h['new_status']) ?>"><?= e($h['new_status']) ?></span></td>
                                <td><?= e($h['changed_by_name']) ?></td>
                                <td><?= e(formatDateTime($h['changed_at'])) ?></td>
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
$extraScripts = <<<JS
<script>
function updateStatusInline(activityId, forceConfirm) {
    const select = document.getElementById('viewNewStatus');
    const msg = document.getElementById('viewStatusMsg');
    const btn = document.getElementById('viewUpdateBtn');

    if (btn.disabled) return; // ignore duplicate clicks while a save is in flight
    btn.disabled = true;
    btn.textContent = 'Updating...';
    msg.innerHTML = '<span class="text-muted">Updating...</span>';

    var body = 'activity_id=' + encodeURIComponent(activityId) + '&new_status=' + encodeURIComponent(select.value);
    // Sent only on the second attempt, after the conflict has been shown.
    if (forceConfirm) body += '&confirm_conflict=1';

    postJSON('/UBMSConnect/actions/update_status.php', body)
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message) + ' Reloading...</div>';
                setTimeout(function () { location.reload(); }, 900);
                return;
            }

            // Venue schedule conflict: the approval was refused. Show what it
            // clashes with, then let the user deliberately override it.
            if (data.conflict && !forceConfirm) {
                btn.disabled = false;
                btn.textContent = 'Update Status';
                renderConflictConfirm(msg, data, function () { updateStatusInline(activityId, true); });
                return;
            }

            msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message) + '</div>';
            btn.disabled = false;
            btn.textContent = 'Update Status';
        })
        .catch(function (err) {
            msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message || 'Something went wrong.') + '</div>';
            btn.disabled = false;
            btn.textContent = 'Update Status';
        });
}
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
