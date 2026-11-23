<?php
/**
 * actions/update_utility.php
 * Staff-only. Updates a utility's monitoring status/description.
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

$utilityId   = (int) ($_POST['utility_id'] ?? 0);
$status      = trim($_POST['status'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($utilityId <= 0 || !in_array($status, validUtilityStatuses(), true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid utility or status value.']);
    exit;
}

$check = $pdo->prepare("SELECT utility_name, status FROM utilities WHERE id = ?");
$check->execute([$utilityId]);
$utility = $check->fetch();
if (!$utility) {
    echo json_encode(['success' => false, 'message' => 'Utility not found.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE utilities SET status = ?, description = ? WHERE id = ?");
    $stmt->execute([$status, $description, $utilityId]);

    if ($utility['status'] !== $status) {
        logAudit('Updated Utility Status', 'utility', $utilityId, $utility['utility_name'], $utility['status'], $status);
        notifyRole('staff', 'admin', 'Utility "' . $utility['utility_name'] . '" status changed to ' . $status . '.', '/UBMSConnect/staff/utilities.php', currentUserId());
        notifyRole('super_admin', 'admin', 'Utility "' . $utility['utility_name'] . '" status changed to ' . $status . '.', '/UBMSConnect/superadmin/audit_logs.php?action_filter=Updated%20Utility%20Status', currentUserId());
    }

    echo json_encode(['success' => true, 'message' => 'Utility status updated.', 'status' => $status, 'description' => $description]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('[UBMSConnect] update_utility.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save utility status. Please try again or contact a Super Admin if the problem persists.']);
}
