<?php
/**
 * actions/update_venue.php
 * Staff-only. Updates a venue's monitoring status.
 * This is NOT a booking engine - it simply reflects current condition.
 */

require_once __DIR__ . '/../includes/auth.php';
// Defensive include: if includes/audit.php was not deployed correctly,
// degrade gracefully (log to PHP's error log, skip the audit entry)
// instead of fatally crashing this entire action. Audit logging is
// important but must never be able to take down a core feature.
$__auditHelper = __DIR__ . '/../includes/audit.php';
if (file_exists($__auditHelper)) {
    require_once $__auditHelper;
} elseif (!function_exists('logAudit')) {
    function logAudit($action, $targetType = null, $targetId = null, $targetLabel = null, $previousValue = null, $newValue = null) {
        error_log('[UBMSConnect] logAudit() called but includes/audit.php is missing on this deployment - action "' . $action . '" was NOT recorded to the audit trail.');
    }
}
$__notifHelper = __DIR__ . '/../includes/notifications.php';
if (file_exists($__notifHelper)) {
    require_once $__notifHelper;
} elseif (!function_exists('notifyRole')) {
    function notifyRole($role, $category, $message, $link = null, $excludeUserId = null) {}
}
header('Content-Type: application/json');
requireRoleApi('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$venueId = (int) ($_POST['venue_id'] ?? 0);
$status  = trim($_POST['status'] ?? '');

if ($venueId <= 0 || !in_array($status, validVenueStatuses(), true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid venue or status value.']);
    exit;
}

$existing = $pdo->prepare("SELECT venue_name, status FROM venues WHERE id = ?");
$existing->execute([$venueId]);
$venue = $existing->fetch();

if (!$venue) {
    echo json_encode(['success' => false, 'message' => 'Venue not found.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE venues SET status = ? WHERE id = ?");
    $stmt->execute([$status, $venueId]);

    if ($venue['status'] !== $status) {
        logAudit('Updated Venue Status', 'venue', $venueId, $venue['venue_name'], $venue['status'], $status);
        notifyRole('staff', 'admin', 'Venue "' . $venue['venue_name'] . '" status changed to ' . $status . '.', '/UBMSConnect/staff/venues.php', currentUserId());
        notifyRole('super_admin', 'admin', 'Venue "' . $venue['venue_name'] . '" status changed to ' . $status . '.', '/UBMSConnect/superadmin/audit_logs.php?action_filter=Updated%20Venue%20Status', currentUserId());
    }

    echo json_encode(['success' => true, 'message' => 'Venue status updated.', 'status' => $status]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('[UBMSConnect] update_venue.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save venue status. Please try again or contact a Super Admin if the problem persists.']);
}
