<?php
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pageTitle = 'Audit Logs';
$activeNav = 'audit_logs';

$actionFilter = trim($_GET['action_filter'] ?? '');

$sql = "SELECT * FROM audit_logs";
$params = [];
if ($actionFilter !== '') {
    $sql .= " WHERE action = ?";
    $params[] = $actionFilter;
}
$sql .= " ORDER BY created_at DESC LIMIT 300";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$distinctActions = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Audit Logs</h1>
            <p class="page-subtitle">System-wide record of administrative and monitoring actions, for accountability. Only Super Admin can view this page; no one can edit or delete entries.</p>
        </div>
    </div>

    <div class="card">
        <form class="filter-bar" method="GET">
            <div class="form-group">
                <label>Filter by Action</label>
                <select name="action_filter" onchange="this.form.submit()">
                    <option value="">All Actions</option>
                    <?php foreach ($distinctActions as $a): ?>
                        <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($actionFilter !== ''): ?>
                <div class="form-group"><a href="/UBMSConnect/superadmin/audit_logs.php" class="btn btn-sm">Clear Filter</a></div>
            <?php endif; ?>
        </form>

        <?php if (empty($logs)): ?>
            <div class="empty-state">No audit log entries<?= $actionFilter !== '' ? ' for that action' : ' yet' ?>.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Action</th><th>Target</th><th>Previous</th><th>New</th><th>Performed By</th><th>Timestamp</th></tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= e($log['action']) ?></td>
                            <td class="text-muted"><?= e($log['target_label'] ?: ($log['target_type'] ?: '—')) ?></td>
                            <td><?= $log['previous_value'] ? e($log['previous_value']) : '<span class="text-muted">—</span>' ?></td>
                            <td><?= $log['new_value'] ? e($log['new_value']) : '<span class="text-muted">—</span>' ?></td>
                            <td><?= e($log['actor_name']) ?></td>
                            <td><?= e(formatDateTime($log['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small-note" style="margin-top:10px;">Showing the most recent <?= count($logs) ?> entries.</p>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
