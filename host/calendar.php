<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('host');

$pageTitle = 'Calendar';
$activeNav = 'calendar';

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthTimestamp = strtotime($month . '-01');
$monthLabel = date('F Y', $monthTimestamp);
$daysInMonth = (int) date('t', $monthTimestamp);
$firstDayOfWeek = (int) date('w', $monthTimestamp);

$prevMonth = date('Y-m', strtotime('-1 month', $monthTimestamp));
$nextMonth = date('Y-m', strtotime('+1 month', $monthTimestamp));
$today = date('Y-m-d');

$stmt = $pdo->prepare(
    "SELECT a.id, a.activity_name, a.activity_date, a.start_time, a.status, v.venue_name, u.full_name AS host_name
     FROM activities a
     JOIN venues v ON v.id = a.venue_id
     JOIN users u ON u.id = a.host_id
     WHERE DATE_FORMAT(a.activity_date, '%Y-%m') = ?
     ORDER BY a.start_time ASC"
);
$stmt->execute([$month]);
$monthActivities = $stmt->fetchAll();

$byDay = [];
foreach ($monthActivities as $act) {
    $day = (int) date('j', strtotime($act['activity_date']));
    $byDay[$day][] = $act;
}

$statusBadgeMap = [
    'Pending' => 'badge-pending', 'Approved' => 'badge-approved', 'Rejected' => 'badge-rejected',
    'Maintenance' => 'badge-maintenance', 'Completed' => 'badge-completed',
];

$dashPrefStmt = $pdo->prepare('SELECT calendar_default_view FROM dashboard_preferences WHERE user_id = ?');
$dashPrefStmt->execute([currentUserId()]);
$calendarView = $dashPrefStmt->fetchColumn() ?: 'month';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Activity Calendar</h1>
            <p class="page-subtitle">View scheduled university activities and their current status (read-only).</p>
        </div>
        <div style="display:flex; gap:6px;">
            <button class="btn btn-sm" id="viewMonthBtn" onclick="showCalendarView('month')">Month</button>
            <button class="btn btn-sm" id="viewListBtn" onclick="showCalendarView('list')">List</button>
        </div>
    </div>

    <div class="pill-status-legend">
        <span class="badge badge-pending">Pending</span>
        <span class="badge badge-approved">Approved</span>
        <span class="badge badge-rejected">Rejected</span>
        <span class="badge badge-maintenance">Maintenance</span>
        <span class="badge badge-completed">Completed</span>
    </div>

    <div class="card" id="calendarGridCard">
        <div class="calendar-nav">
            <a href="?month=<?= e($prevMonth) ?>" class="btn btn-sm">&larr; Prev</a>
            <div class="month-label"><?= e($monthLabel) ?></div>
            <a href="?month=<?= e($nextMonth) ?>" class="btn btn-sm">Next &rarr;</a>
        </div>

        <div class="calendar-grid">
            <div class="calendar-dow">Sun</div><div class="calendar-dow">Mon</div><div class="calendar-dow">Tue</div>
            <div class="calendar-dow">Wed</div><div class="calendar-dow">Thu</div><div class="calendar-dow">Fri</div><div class="calendar-dow">Sat</div>

            <?php for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                <div class="calendar-cell empty"></div>
            <?php endfor; ?>

            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $cellDate = sprintf('%s-%02d', $month, $d);
                $isToday = ($cellDate === $today);
                $events = $byDay[$d] ?? [];
            ?>
                <div class="calendar-cell<?= $isToday ? ' today' : '' ?>">
                    <div class="calendar-date"><?= $d ?></div>
                    <?php foreach (array_slice($events, 0, 3) as $ev): ?>
                        <div class="calendar-event <?= $statusBadgeMap[$ev['status']] ?? 'badge-default' ?>"
                             data-activity-id="<?= (int)$ev['id'] ?>"
                             onclick="loadActivityDetail(<?= (int)$ev['id'] ?>, false)"
                             title="<?= e($ev['activity_name']) ?>">
                            <?= e($ev['activity_name']) ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($events) > 3): ?>
                        <div class="calendar-more">+<?= count($events) - 3 ?> more</div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="card section-gap" id="calendarListCard" style="margin-top:18px;">
        <div class="card-title">All Activities This Month</div>
        <?php if (empty($monthActivities)): ?>
            <div class="empty-state">No activities recorded for <?= e($monthLabel) ?>.</div>
        <?php else: ?>
            <div class="day-list">
                <?php foreach ($monthActivities as $ev): ?>
                    <div class="day-list-item" onclick="loadActivityDetail(<?= (int)$ev['id'] ?>, false)">
                        <div class="dli-left">
                            <span class="dli-title"><?= e($ev['activity_name']) ?></span>
                            <span class="dli-meta"><?= e(formatDate($ev['activity_date'])) ?> &middot; <?= e(formatTime($ev['start_time'])) ?> &middot; <?= e($ev['venue_name']) ?> &middot; Host: <?= e($ev['host_name']) ?></span>
                        </div>
                        <span class="badge day-list-badge <?= statusBadgeClass($ev['status']) ?>" data-activity-id="<?= (int)$ev['id'] ?>"><?= e($ev['status']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
$extraScripts = '
<script>
var CALENDAR_MONTH = ' . json_encode($month) . ';

function showCalendarView(view) {
    var gridCard = document.getElementById("calendarGridCard");
    var listCard = document.getElementById("calendarListCard");
    var monthBtn = document.getElementById("viewMonthBtn");
    var listBtn = document.getElementById("viewListBtn");
    if (view === "list") {
        gridCard.style.display = "none";
        listCard.style.display = "";
        listBtn.classList.add("btn-primary");
        monthBtn.classList.remove("btn-primary");
    } else {
        gridCard.style.display = "";
        listCard.style.display = "none";
        monthBtn.classList.add("btn-primary");
        listBtn.classList.remove("btn-primary");
    }
}
document.addEventListener("DOMContentLoaded", function () {
    showCalendarView(' . json_encode($calendarView) . ');
});

function refreshCurrentView() {
    fetch("/UBMSConnect/api/activities.php?action=list&month=" + encodeURIComponent(CALENDAR_MONTH))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) return;
            var badgeClassMap = {
                "Pending": "badge-pending", "Approved": "badge-approved", "Rejected": "badge-rejected",
                "Maintenance": "badge-maintenance", "Completed": "badge-completed"
            };
            data.activities.forEach(function (a) {
                var cls = badgeClassMap[a.status] || "badge-default";

                document.querySelectorAll(\'.calendar-event[data-activity-id="\' + a.id + \'"]\').forEach(function (el) {
                    el.className = "calendar-event " + cls;
                });

                var badgeEl = document.querySelector(\'.day-list-badge[data-activity-id="\' + a.id + \'"]\');
                if (badgeEl) {
                    badgeEl.className = "badge day-list-badge " + cls;
                    badgeEl.textContent = a.status;
                }
            });
        })
        .catch(function () {});
}
</script>
';
include __DIR__ . '/../includes/footer.php';
?>
