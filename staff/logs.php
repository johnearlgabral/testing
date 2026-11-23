<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

$pageTitle = 'Activity Logs';
$activeNav = 'logs';

$logs = $pdo->query(
    "SELECT l.*, a.activity_name, u.full_name AS changed_by_name
     FROM activity_status_logs l
     JOIN activities a ON a.id = l.activity_id
     JOIN users u ON u.id = l.changed_by
     ORDER BY l.changed_at DESC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Activity Logs &amp; Audit Trail</h1>
            <p class="page-subtitle">Complete history of activity status changes, recorded for accountability.</p>
        </div>
    </div>

    <div class="card">
        <?php if (empty($logs)): ?>
            <div class="empty-state">No status changes have been recorded yet.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Activity</th><th>Previous Status</th><th>New Status</th><th>Changed By</th><th>Date</th><th>Time</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><a href="/UBMSConnect/staff/activity_view.php?id=<?= (int)$log['activity_id'] ?>"><?= e($log['activity_name']) ?></a></td>
                            <td><span class="badge <?= statusBadgeClass($log['previous_status']) ?>"><?= e($log['previous_status']) ?></span></td>
                            <td><span class="badge <?= statusBadgeClass($log['new_status']) ?>"><?= e($log['new_status']) ?></span></td>
                            <td><?= e($log['changed_by_name']) ?></td>
                            <td><?= e(formatDate($log['changed_at'])) ?></td>
                            <td><?= e(date('g:i A', strtotime($log['changed_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
