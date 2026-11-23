<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Notifications';
$activeNav = 'notifications';

$stmt = $pdo->prepare(
    'SELECT id, category, message, link, is_read, created_at
     FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
);
$stmt->execute([currentUserId()]);
$notifications = $stmt->fetchAll();

$categoryLabels = [
    'activity' => 'Activity', 'maintenance' => 'Maintenance',
    'account' => 'Account', 'admin' => 'Administrative',
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Notifications</h1>
            <p class="page-subtitle">In-system updates relevant to your account.</p>
        </div>
        <div style="display:flex; gap:8px;">
            <button class="btn btn-sm" onclick="markAllRead()">Mark All Read</button>
            <?php if (!empty($notifications)): ?>
                <button class="btn btn-sm btn-danger" onclick="clearAllNotifications()">Clear All</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <?php if (empty($notifications)): ?>
            <div class="empty-state" id="notificationsEmptyState">You have no notifications yet.</div>
        <?php else: ?>
            <div class="table-wrap" id="notificationsTableWrap">
                <table class="data-table">
                    <thead><tr><th></th><th>Category</th><th>Message</th><th>When</th><th></th></tr></thead>
                    <tbody id="notificationsTbody">
                    <?php foreach ($notifications as $n): ?>
                        <tr id="notif-row-<?= (int)$n['id'] ?>" style="<?= $n['is_read'] ? 'opacity:0.6;' : 'font-weight:600;' ?>">
                            <td><?= $n['is_read'] ? '' : '<span class="live-dot" style="animation:none;"></span>' ?></td>
                            <td><span class="badge badge-default"><?= e($categoryLabels[$n['category']] ?? ucfirst($n['category'])) ?></span></td>
                            <td>
                                <?php if ($n['link']): ?><a href="<?= e($n['link']) ?>"><?= e($n['message']) ?></a>
                                <?php else: ?><?= e($n['message']) ?><?php endif; ?>
                            </td>
                            <td class="text-muted"><?= e(formatDateTime($n['created_at'])) ?></td>
                            <td>
                                <?php if (!$n['is_read']): ?>
                                    <button class="btn btn-sm" onclick="markRead(<?= (int)$n['id'] ?>)">Mark Read</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php
$extraScripts = <<<'JS'
<script>
function markRead(id) {
    postJSON('/UBMSConnect/api/notifications.php', 'action=mark_read&id=' + encodeURIComponent(id))
        .then(function (data) {
            if (data.success) {
                const row = document.getElementById('notif-row-' + id);
                if (row) { row.style.opacity = '0.6'; row.style.fontWeight = 'normal'; }
                const btn = row ? row.querySelector('button') : null;
                if (btn) btn.remove();
                if (typeof refreshNotificationBell === 'function') refreshNotificationBell();
            }
        })
        .catch(function () {});
}
function markAllRead() {
    postJSON('/UBMSConnect/api/notifications.php', 'action=mark_all_read')
        .then(function (data) { if (data.success) location.reload(); })
        .catch(function () {});
}
function clearAllNotifications() {
    if (!confirm('Clear all notifications?\n\nYou won\'t see these notifications in your notification list anymore.')) return;

    postJSON('/UBMSConnect/api/notifications.php', 'action=clear_all')
        .then(function (data) {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Failed to clear notifications.');
            }
        })
        .catch(function (err) { alert(err.message || 'Something went wrong.'); });
}
</script>
JS;
include __DIR__ . '/includes/footer.php';
?>
