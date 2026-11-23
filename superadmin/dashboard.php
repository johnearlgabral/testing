<?php
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pageTitle = 'Super Admin Dashboard';
$activeNav = 'dashboard';

// ---- User statistics (only real data from the users table) ----
$userCounts = ['super_admin' => 0, 'staff' => 0, 'host' => 0];
$statusCounts = ['active' => 0, 'pending' => 0, 'inactive' => 0, 'rejected' => 0];

$stmt = $pdo->query("SELECT role, status, COUNT(*) AS c FROM users GROUP BY role, status");
foreach ($stmt->fetchAll() as $row) {
    $userCounts[$row['role']] = ($userCounts[$row['role']] ?? 0) + (int) $row['c'];
    $statusCounts[$row['status']] = ($statusCounts[$row['status']] ?? 0) + (int) $row['c'];
}
$totalUsers = array_sum($userCounts);

// ---- Activity statistics (existing activities table) ----
$activityCounts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Maintenance' => 0, 'Completed' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) AS c FROM activities GROUP BY status");
foreach ($stmt->fetchAll() as $row) {
    $activityCounts[$row['status']] = (int) $row['c'];
}
$totalActivities = array_sum($activityCounts);

// ---- Recent audit activity (top 8) ----
$recentAudit = $pdo->query(
    "SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 8"
)->fetchAll();

// ---- Pending registrations preview ----
$pendingPreview = $pdo->query(
    "SELECT id, full_name, username, email, created_at FROM users WHERE status = 'pending' ORDER BY created_at ASC LIMIT 5"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="dash-shell">
        <header class="dash-welcome">
            <h1>Super Admin Dashboard</h1>
            <p>Welcome back. Here's what needs your attention.</p>
        </header>

        <section class="dash-kpi-grid" aria-label="Dashboard statistics">
            <div class="dash-kpi">
                <span class="dash-kpi-label">Total users</span>
                <span class="dash-kpi-num"><?= $totalUsers ?></span>
            </div>
            <div class="dash-kpi">
                <span class="dash-kpi-label">Pending registrations</span>
                <span class="dash-kpi-num"><?= $statusCounts['pending'] ?></span>
                <?php if ($statusCounts['pending'] > 0): ?><a class="stat-hint" href="/UBMSConnect/superadmin/pending.php">Review needed</a><?php endif; ?>
            </div>
            <div class="dash-kpi">
                <span class="dash-kpi-label">Total activities</span>
                <span class="dash-kpi-num"><?= $totalActivities ?></span>
            </div>
            <div class="dash-kpi">
                <span class="dash-kpi-label">Pending activities</span>
                <span class="dash-kpi-num"><?= $activityCounts['Pending'] ?></span>
            </div>
        </section>

        <div class="dash-bottom-grid">
        <section class="dash-panel">
            <div class="dash-panel-head">
                <h2 class="dash-panel-title">Pending Staff Registrations</h2>
                <?php if ($statusCounts['pending'] > 0): ?><span class="dash-count-badge"><?= (int) $statusCounts['pending'] ?> pending</span><?php endif; ?>
            </div>
            <?php if (empty($pendingPreview)): ?>
                <div class="dash-empty">No pending registrations.</div>
            <?php else: ?>
                <?php foreach ($pendingPreview as $p): ?>
                    <div class="dash-row"><?= e($p['full_name']) ?> <span class="dash-muted">&middot; <?= e($p['username']) ?></span></div>
                <?php endforeach; ?>
                <div class="dash-panel-actions">
                    <a class="btn btn-primary" href="/UBMSConnect/superadmin/pending.php">Review registrations</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="dash-panel">
            <div class="dash-panel-head">
                <h2 class="dash-panel-title">Recent Administrative Activity</h2>
                <svg class="dash-panel-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 13.8"/></svg>
            </div>
            <?php if (empty($recentAudit)): ?>
                <div class="dash-empty">No audit activity recorded yet.</div>
            <?php else: ?>
                <?php foreach ($recentAudit as $log): ?>
                    <div class="dash-row"><?= e($log['action']) ?><?= $log['target_label'] ? ' <span class="dash-muted">&middot; ' . e($log['target_label']) . '</span>' : '' ?> <span class="dash-muted">&middot; <?= date('F j', strtotime($log['created_at'])) ?></span></div>
                <?php endforeach; ?>
            <?php endif; ?>
            <a class="dash-panel-link" href="/UBMSConnect/superadmin/audit_logs.php">View full audit trail <span class="dash-link-arrow" aria-hidden="true">&rarr;</span></a>
        </section>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
