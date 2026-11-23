<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('host');

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Maintenance' => 0, 'Completed' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) AS c FROM activities GROUP BY status");
foreach ($stmt->fetchAll() as $row) { $counts[$row['status']] = (int) $row['c']; }
$total = array_sum($counts);

$dashPrefStmt = $pdo->prepare('SELECT * FROM dashboard_preferences WHERE user_id = ?');
$dashPrefStmt->execute([currentUserId()]);
$dp = $dashPrefStmt->fetch() ?: ['show_stats' => 1, 'show_venues' => 1, 'show_utilities' => 1];

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="dash-shell">
        <header class="dash-welcome">
            <h1>Event Host Dashboard</h1>
            <p>Welcome back &middot; <span class="live-dot"></span>last refreshed <span id="lastRefreshTime">just now</span> &middot; read-only view</p>
        </header>

        <?php if ($dp['show_stats']): ?>
        <section class="dash-kpi-grid" id="statGrid" aria-label="Activity status summary">
            <div class="dash-kpi"><span class="dash-kpi-label">Total activities</span><span class="dash-kpi-num" id="statTotal"><?= $total ?></span></div>
            <div class="dash-kpi"><span class="dash-kpi-label">Pending</span><span class="dash-kpi-num" id="statPending"><?= $counts['Pending'] ?></span></div>
            <div class="dash-kpi"><span class="dash-kpi-label">Approved</span><span class="dash-kpi-num" id="statApproved"><?= $counts['Approved'] ?></span></div>
            <div class="dash-kpi"><span class="dash-kpi-label">Maintenance</span><span class="dash-kpi-num" id="statMaintenance"><?= $counts['Maintenance'] ?></span></div>
        </section>
        <?php endif; ?>

        <section class="dash-panel dash-panel-full">
            <div class="dash-panel-head"><h2 class="dash-panel-title">Today's Activities</h2></div>
            <div class="dash-card-scroll">
                <table class="data-table" id="todayTable">
                    <thead><tr><th>Activity</th><th>Venue</th><th>Time</th><th>Host</th><th>Status</th></tr></thead>
                    <tbody><tr><td colspan="5" class="empty-state">Loading...</td></tr></tbody>
                </table>
            </div>
            <a class="dash-panel-link" href="/UBMSConnect/host/activities.php">View all activities <span class="dash-link-arrow" aria-hidden="true">&rarr;</span></a>
        </section>
        <div class="dash-bottom-grid">
        <?php if ($dp['show_venues']): ?>
        <section class="dash-panel">
            <div class="dash-panel-head"><h2 class="dash-panel-title">Venue / Facility Status</h2></div>
            <div class="dash-card-scroll">
                <table class="data-table" id="venueTable">
                    <thead><tr><th>Venue</th><th>Building</th><th>Status</th></tr></thead>
                    <tbody><tr><td colspan="3" class="empty-state">Loading...</td></tr></tbody>
                </table>
            </div>
            <a class="dash-panel-link" href="/UBMSConnect/host/venues.php">View all venues <span class="dash-link-arrow" aria-hidden="true">&rarr;</span></a>
        </section>
        <?php endif; ?>

        <?php if ($dp['show_utilities']): ?>
        <section class="dash-panel">
            <div class="dash-panel-head"><h2 class="dash-panel-title">Utility Status</h2></div>
            <div class="dash-card-scroll">
                <table class="data-table" id="utilityTable">
                    <thead><tr><th>Utility</th><th>Location</th><th>Status</th></tr></thead>
                    <tbody><tr><td colspan="3" class="empty-state">Loading...</td></tr></tbody>
                </table>
            </div>
            <a class="dash-panel-link" href="/UBMSConnect/host/utilities.php">View all utilities <span class="dash-link-arrow" aria-hidden="true">&rarr;</span></a>
        </section>
        <?php endif; ?>
        </div>
    </div>
</main>

<div class="modal-overlay" id="activityModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Activity Details</h3>
            <button class="modal-close" data-close-modal="activityModal">&times;</button>
        </div>
        <div class="modal-body" id="activityModalBody"></div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
function badgeHtml(status) {
    const cls = {'Pending':'badge-pending','Approved':'badge-approved','Rejected':'badge-rejected',
        'Maintenance':'badge-maintenance','Completed':'badge-completed',
        'Available':'badge-approved','In Use':'badge-pending','Under Maintenance':'badge-maintenance',
        'Operational':'badge-approved','Unavailable':'badge-rejected','Maintenance':'badge-maintenance'}[status] || 'badge-default';
    return '<span class="badge ' + cls + '">' + escapeHtml(status) + '</span>';
}

function refreshDashboard() {
    fetch('/UBMSConnect/api/dashboard.php')
        .then(function (r) { return r.json(); })
        .catch(function () { return null; })
        .then(function (data) {
            if (data && data.auth_error) {
                setTimeout(function () { window.location.href = '/UBMSConnect/login.php'; }, 1500);
                return;
            }
            if (!data || !data.success) return;

            const setText = function (id, val) { const el = document.getElementById(id); if (el) el.textContent = val; };
            setText('statTotal', data.total);
            setText('statPending', data.counts.Pending);
            setText('statApproved', data.counts.Approved);
            setText('statMaintenance', data.counts.Maintenance);
            setText('statCompleted', data.counts.Completed);
            setText('statRejected', data.counts.Rejected);
            setText('lastRefreshTime', data.server_time);

            const todayBody = document.querySelector('#todayTable tbody');
            if (todayBody) todayBody.innerHTML = data.today.length ? data.today.map(t =>
                '<tr class="row-link" onclick="loadActivityDetail(' + t.id + ', false)">'
                + '<td>' + escapeHtml(t.activity_name) + '</td>'
                + '<td>' + escapeHtml(t.venue_name) + '</td>'
                + '<td>' + t.start_time_fmt + ' - ' + t.end_time_fmt + '</td>'
                + '<td>' + escapeHtml(t.host_name) + '</td>'
                + '<td>' + badgeHtml(t.status) + '</td></tr>'
            ).join('') : '<tr><td colspan="5" class="empty-state">No activities scheduled today.</td></tr>';

            const venueBody = document.querySelector('#venueTable tbody');
            if (venueBody) venueBody.innerHTML = data.venues.map(v =>
                '<tr><td>' + escapeHtml(v.venue_name) + '</td>'
                + '<td>' + escapeHtml(v.building) + '</td>'
                + '<td>' + badgeHtml(v.status) + '</td></tr>'
            ).join('');

            const utilBody = document.querySelector('#utilityTable tbody');
            if (utilBody) utilBody.innerHTML = data.utilities.map(u =>
                '<tr><td>' + escapeHtml(u.utility_name) + '</td>'
                + '<td>' + escapeHtml(u.location) + '</td>'
                + '<td>' + badgeHtml(u.status) + '</td></tr>'
            ).join('');
        });
}

document.addEventListener('DOMContentLoaded', function () {
    startPolling(refreshDashboard, 15000);
});
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
