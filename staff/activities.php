<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

$pageTitle = 'Activities';
$activeNav = 'activities';

$venues = $pdo->query("SELECT id, venue_name FROM venues ORDER BY venue_name ASC")->fetchAll();
$hosts  = $pdo->query("SELECT id, full_name FROM users WHERE role = 'host' ORDER BY full_name ASC")->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>All Activities</h1>
            <p class="page-subtitle">Monitor every university activity currently on record. Click a row to view full details and update status.</p>
        </div>
    </div>

    <div class="card">
        <form class="filter-bar" id="filterForm" onsubmit="return false;">
            <div class="form-group search-box">
                <label>Search</label>
                <input type="text" id="fSearch" placeholder="Activity, venue, or host name">
            </div>
            <div class="form-group">
                <label>Status</label>
                <select id="fStatus">
                    <option value="">All</option>
                    <option>Pending</option>
                    <option>Approved</option>
                    <option>Rejected</option>
                    <option>Maintenance</option>
                    <option>Completed</option>
                </select>
            </div>
            <div class="form-group">
                <label>Date</label>
                <input type="date" id="fDate">
            </div>
            <div class="form-group">
                <label>Venue</label>
                <select id="fVenue">
                    <option value="">All</option>
                    <?php foreach ($venues as $v): ?>
                        <option value="<?= (int)$v['id'] ?>"><?= e($v['venue_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Host</label>
                <select id="fHost">
                    <option value="">All</option>
                    <?php foreach ($hosts as $h): ?>
                        <option value="<?= (int)$h['id'] ?>"><?= e($h['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <button type="button" class="btn btn-outline" style="color:var(--text); border-color: var(--gray-200);" onclick="resetFilters()">Reset</button>
            </div>
        </form>

        <div class="table-wrap">
            <table class="data-table" id="activitiesTable">
                <thead>
                    <tr><th>Activity</th><th>Venue</th><th>Date</th><th>Time</th><th>Host</th><th>Status</th></tr>
                </thead>
                <tbody><tr><td colspan="6" class="empty-state">Loading...</td></tr></tbody>
            </table>
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
        'Maintenance':'badge-maintenance','Completed':'badge-completed'}[status] || 'badge-default';
    return '<span class="badge ' + cls + '">' + escapeHtml(status) + '</span>';
}

function refreshCurrentView() { loadActivities(); }

function loadActivities() {
    const params = new URLSearchParams({
        action: 'list',
        q: document.getElementById('fSearch').value,
        status: document.getElementById('fStatus').value,
        date: document.getElementById('fDate').value,
        venue: document.getElementById('fVenue').value,
        host: document.getElementById('fHost').value
    });

    fetch('/UBMSConnect/api/activities.php?' + params.toString())
        .then(function (r) { return r.json(); })
        .catch(function () { return { success: false, network_error: true }; })
        .then(function (data) {
            const body = document.querySelector('#activitiesTable tbody');
            if (data.auth_error) {
                body.innerHTML = '<tr><td colspan="6" class="empty-state">' + escapeHtml(data.message) + ' Redirecting to login...</td></tr>';
                setTimeout(function () { window.location.href = '/UBMSConnect/login.php'; }, 1500);
                return;
            }
            if (data.network_error) {
                body.innerHTML = '<tr><td colspan="6" class="empty-state">Unable to reach the server. Please check your connection and try again.</td></tr>';
                return;
            }
            if (!data.success || !data.activities.length) {
                body.innerHTML = '<tr><td colspan="6" class="empty-state">No matching activities found.</td></tr>';
                return;
            }
            body.innerHTML = data.activities.map(a =>
                '<tr class="row-link" onclick="loadActivityDetail(' + a.id + ', true)">'
                + '<td>' + escapeHtml(a.activity_name) + '</td>'
                + '<td>' + escapeHtml(a.venue_name) + '</td>'
                + '<td>' + a.activity_date_fmt + '</td>'
                + '<td>' + a.start_time_fmt + ' - ' + a.end_time_fmt + '</td>'
                + '<td>' + escapeHtml(a.host_name) + '</td>'
                + '<td>' + badgeHtml(a.status) + '</td></tr>'
            ).join('');
        });
}

function resetFilters() {
    document.getElementById('fSearch').value = '';
    document.getElementById('fStatus').value = '';
    document.getElementById('fDate').value = '';
    document.getElementById('fVenue').value = '';
    document.getElementById('fHost').value = '';
    loadActivities();
}

document.addEventListener('DOMContentLoaded', function () {
    ['fSearch', 'fStatus', 'fDate', 'fVenue', 'fHost'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', debounce(loadActivities, 300));
        document.getElementById(id).addEventListener('change', loadActivities);
    });
    loadActivities();
});

function debounce(fn, wait) {
    let t;
    return function () {
        clearTimeout(t);
        t = setTimeout(fn, wait);
    };
}
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
