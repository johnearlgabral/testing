<?php
/**
 * actions/add_maintenance.php
 * Staff-only. Adds a maintenance note linked to a venue, activity, and/or
 * utility (at least one target must be supplied).
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
requireRole('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Invalid request method.');
}

$note       = trim($_POST['note'] ?? '');
$venueId    = !empty($_POST['venue_id']) ? (int) $_POST['venue_id'] : null;
$activityId = !empty($_POST['activity_id']) ? (int) $_POST['activity_id'] : null;
$utilityId  = !empty($_POST['utility_id']) ? (int) $_POST['utility_id'] : null;

$errors = [];
if ($note === '') {
    $errors[] = 'Note description is required.';
}
if (!$venueId && !$activityId && !$utilityId) {
    $errors[] = 'Please associate the note with a venue, activity, and/or utility.';
}

if (empty($errors)) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO maintenance_logs (venue_id, activity_id, utility_id, note, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$venueId, $activityId, $utilityId, $note, currentUserId()]);
        $newLogId = (int) $pdo->lastInsertId();

        $label = mb_strlen($note) > 60 ? (mb_substr($note, 0, 57) . '...') : $note;
        logAudit('Added Maintenance Note', 'maintenance_log', $newLogId, $label);
        notifyRole('staff', 'maintenance', 'New maintenance record added: ' . $label, '/UBMSConnect/staff/maintenance.php', currentUserId());
        notifyRole('super_admin', 'maintenance', 'New maintenance record added: ' . $label, '/UBMSConnect/superadmin/audit_logs.php?action_filter=Added%20Maintenance%20Note', currentUserId());

        redirect('/UBMSConnect/staff/maintenance.php?added=1');
    } catch (Exception $e) {
        error_log('[UBMSConnect] add_maintenance.php DB error: ' . $e->getMessage());
        $_SESSION['maintenance_errors'] = ['The maintenance record could not be saved due to a database error. Please verify the selected venue/activity/utility still exists and try again.'];
        redirect('/UBMSConnect/staff/maintenance.php?added=0');
    }
} else {
    $_SESSION['maintenance_errors'] = $errors;
    redirect('/UBMSConnect/staff/maintenance.php?added=0');
}
