<?php
/**
 * api/activities.php
 * JSON endpoint used by both Staff and Host views for:
 *   - action=list   -> filtered/searched activity list (calendar + activities page)
 *   - action=detail -> single activity detail + status history (modal)
 *
 * Read-only endpoint: available to any authenticated user (staff or host).
 * Role restriction is enforced on the WRITE side (actions/update_status.php).
 */

require_once __DIR__ . '/../includes/auth.php';
requireLoginApi();

header('Content-Type: application/json');

$action = $_GET['action'] ?? 'list';

if ($action === 'detail') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid activity id.']);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT a.*, u.full_name AS host_name, v.venue_name, v.building, v.status AS venue_status
         FROM activities a
         JOIN users u ON u.id = a.host_id
         JOIN venues v ON v.id = a.venue_id
         WHERE a.id = ?"
    );
    $stmt->execute([$id]);
    $activity = $stmt->fetch();

    if (!$activity) {
        echo json_encode(['success' => false, 'message' => 'Activity not found.']);
        exit;
    }

    $activity['activity_date_fmt'] = formatDate($activity['activity_date']);
    $activity['start_time_fmt']    = formatTime($activity['start_time']);
    $activity['end_time_fmt']      = formatTime($activity['end_time']);
    $activity['updated_at_fmt']    = formatDateTime($activity['updated_at']);

    $histStmt = $pdo->prepare(
        "SELECT l.*, u.full_name AS changed_by_name
         FROM activity_status_logs l
         JOIN users u ON u.id = l.changed_by
         WHERE l.activity_id = ?
         ORDER BY l.changed_at DESC"
    );
    $histStmt->execute([$id]);
    $history = $histStmt->fetchAll();
    foreach ($history as &$h) {
        $h['changed_at_fmt'] = formatDateTime($h['changed_at']);
    }

    echo json_encode(['success' => true, 'activity' => $activity, 'history' => $history]);
    exit;
}

if ($action === 'list') {
    $status = trim($_GET['status'] ?? '');
    $date   = trim($_GET['date'] ?? '');
    $venue  = trim($_GET['venue'] ?? '');
    $host   = trim($_GET['host'] ?? '');
    $q      = trim($_GET['q'] ?? '');
    $month  = trim($_GET['month'] ?? ''); // format YYYY-MM, used by calendar

    $sql = "SELECT a.id, a.activity_name, a.description, a.activity_date, a.start_time, a.end_time,
                   a.status, a.updated_at, u.full_name AS host_name, v.venue_name, v.status AS venue_status
            FROM activities a
            JOIN users u ON u.id = a.host_id
            JOIN venues v ON v.id = a.venue_id
            WHERE 1=1";
    $params = [];

    if ($status !== '') {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }
    if ($date !== '') {
        $sql .= " AND a.activity_date = ?";
        $params[] = $date;
    }
    if ($venue !== '') {
        $sql .= " AND a.venue_id = ?";
        $params[] = $venue;
    }
    if ($host !== '') {
        $sql .= " AND a.host_id = ?";
        $params[] = $host;
    }
    if ($month !== '') {
        $sql .= " AND DATE_FORMAT(a.activity_date, '%Y-%m') = ?";
        $params[] = $month;
    }
    if ($q !== '') {
        $sql .= " AND (a.activity_name LIKE ? OR v.venue_name LIKE ? OR u.full_name LIKE ?)";
        $like = "%$q%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= " ORDER BY a.activity_date ASC, a.start_time ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['activity_date_fmt'] = formatDate($r['activity_date']);
        $r['start_time_fmt']    = formatTime($r['start_time']);
        $r['end_time_fmt']      = formatTime($r['end_time']);
    }

    echo json_encode(['success' => true, 'activities' => $rows]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
