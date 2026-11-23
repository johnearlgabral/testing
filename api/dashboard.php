<?php
/**
 * api/dashboard.php
 * Returns the full dashboard data bundle as JSON so the dashboard pages
 * (staff + host) can refresh themselves periodically via AJAX polling
 * instead of a full page reload ("simulated real-time").
 */

require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();

header('Content-Type: application/json');

// ---- Summary counts ----
$counts = ['Pending' => 0, 'Approved' => 0, 'Rejected' => 0, 'Maintenance' => 0, 'Completed' => 0];
$stmt = $pdo->query("SELECT status, COUNT(*) AS c FROM activities GROUP BY status");
foreach ($stmt->fetchAll() as $row) {
    $counts[$row['status']] = (int) $row['c'];
}
$total = array_sum($counts);

// ---- Today's activities ----
$todayStmt = $pdo->prepare(
    "SELECT a.id, a.activity_name, a.status, a.start_time, a.end_time,
            v.venue_name, u.full_name AS host_name
     FROM activities a
     JOIN venues v ON v.id = a.venue_id
     JOIN users u ON u.id = a.host_id
     WHERE a.activity_date = CURDATE()
     ORDER BY a.start_time ASC"
);
$todayStmt->execute();
$today = $todayStmt->fetchAll();
foreach ($today as &$t) {
    $t['start_time_fmt'] = formatTime($t['start_time']);
    $t['end_time_fmt']   = formatTime($t['end_time']);
}

// ---- Recent status changes ----
$recentStmt = $pdo->query(
    "SELECT l.id, a.activity_name, l.previous_status, l.new_status, l.changed_at, u.full_name AS changed_by_name
     FROM activity_status_logs l
     JOIN activities a ON a.id = l.activity_id
     JOIN users u ON u.id = l.changed_by
     ORDER BY l.changed_at DESC
     LIMIT 8"
);
$recent = $recentStmt->fetchAll();
foreach ($recent as &$r) {
    $r['changed_at_fmt'] = formatDateTime($r['changed_at']);
}

// ---- Venue status ----
$venueStmt = $pdo->query("SELECT id, venue_name, building, status FROM venues ORDER BY venue_name ASC");
$venues = $venueStmt->fetchAll();

// ---- Utility status ----
$utilStmt = $pdo->query("SELECT id, utility_name, location, status, updated_at FROM utilities ORDER BY utility_name ASC");
$utilities = $utilStmt->fetchAll();
foreach ($utilities as &$u) {
    $u['updated_at_fmt'] = formatDateTime($u['updated_at']);
}

echo json_encode([
    'success'    => true,
    'counts'     => $counts,
    'total'      => $total,
    'today'      => $today,
    'recent'     => $recent,
    'venues'     => $venues,
    'utilities'  => $utilities,
    'server_time'=> date('F j, Y g:i:s A'),
]);
