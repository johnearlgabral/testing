<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('host');
require_once __DIR__ . '/../includes/venue_availability.php';
require_once __DIR__ . '/../includes/venue_details.php';

$pageTitle = 'Venues';
$activeNav = 'venues';

$venues = $pdo->query(
    "SELECT v.*,
        (SELECT a.activity_name FROM activities a
         WHERE a.venue_id = v.id AND a.activity_date = CURDATE() AND a.status IN ('Approved')
         ORDER BY a.start_time ASC LIMIT 1) AS current_activity
     FROM venues v WHERE v.is_active = 1 ORDER BY v.venue_name ASC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Venue Monitoring</h1>
            <p class="page-subtitle">Current condition of university venues and facilities. Read-only view.</p>
        </div>
    </div>

    <?php renderVenueAvailabilityPanel(); ?>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Photo</th><th>Venue</th><th>Building</th><th>Capacity</th><th>Today's Activity</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($venues as $v): ?>
                    <tr>
                        <td><?= venueImageHtml($v['image_path'] ?? null, $v['venue_name']) ?></td>
                        <td><?= e($v['venue_name']) ?></td>
                        <td><?= e($v['building']) ?></td>
                        <td><?= (int)$v['capacity'] ?></td>
                        <td><?= $v['current_activity'] ? e($v['current_activity']) : '<span class="text-muted">None scheduled</span>' ?></td>
                        <td><span class="badge <?= venueBadgeClass($v['status']) ?>"><?= e($v['status']) ?></span></td>
                        <td><button class="btn btn-sm" onclick='openVenueDetails(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>View details</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php renderVenueDetailsModal(); ?>
<?php $extraScripts = venueAvailabilityScripts() . venueDetailsScripts(); ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
