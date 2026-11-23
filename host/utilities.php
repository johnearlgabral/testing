<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('host');
require_once __DIR__ . '/../includes/utility_availability.php';
require_once __DIR__ . '/../includes/utility_details.php';

$pageTitle = 'Utilities';
$activeNav = 'utilities';

// Same read-only monitoring data as the staff page, minus every control:
// activities belong to venues, so the utility's activity today comes from any
// venue whose name or building matches the utility's location.
$utilities = $pdo->query(
    "SELECT u.*,
        (SELECT a.activity_name FROM activities a
         WHERE a.activity_date = CURDATE() AND a.status IN ('Approved')
           AND a.venue_id IN (SELECT v.id FROM venues v
                              WHERE v.venue_name = u.location OR v.building = u.location)
         ORDER BY a.start_time ASC LIMIT 1) AS current_activity,
        EXISTS (SELECT 1 FROM venues v
                WHERE v.venue_name = u.location OR v.building = u.location) AS has_schedule
     FROM utilities u WHERE u.is_active = 1 ORDER BY u.utility_name ASC"
)->fetchAll();

foreach ($utilities as $i => $u) {
    $utilities[$i]['updated_at_fmt'] = formatDateTime($u['updated_at']);
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Utility Monitoring</h1>
            <p class="page-subtitle">Operational condition of core building utilities. Read-only view.</p>
        </div>
    </div>

    <?php renderUtilityAvailabilityPanel(); ?>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Photo</th><th>Utility</th><th>Location</th><th>Quantity</th><th>Today's Activity</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php if (empty($utilities)): ?>
                    <tr><td colspan="7" class="empty-state">No utilities on record.</td></tr>
                <?php endif; ?>
                <?php foreach ($utilities as $u): ?>
                    <tr>
                        <td><?= venueImageHtml($u['image_path'] ?? null, $u['utility_name'], 'utilities') ?></td>
                        <td><?= e($u['utility_name']) ?><br><span class="text-muted"><?= e($u['category'] ?: 'N/A') ?></span></td>
                        <td><?= e($u['location']) ?></td>
                        <td><?= $u['quantity'] !== null ? (int) $u['quantity'] : '<span class="text-muted">&mdash;</span>' ?></td>
                        <td><?php if (!$u['has_schedule']): ?><span class="text-muted">No linked schedule</span><?php else: ?><?= $u['current_activity'] ? e($u['current_activity']) : '<span class="text-muted">None scheduled</span>' ?><?php endif; ?></td>
                        <td><span class="badge <?= utilityBadgeClass($u['status']) ?>"><?= e($u['status']) ?></span></td>
                        <td><button class="btn btn-sm" onclick='openUtilityDetails(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>View details</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php renderUtilityDetailsModal(); ?>
<?php $extraScripts = utilityAvailabilityScripts() . utilityDetailsScripts(); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
