<?php
/**
 * actions/reject_registration.php
 * Super-Admin-only. Rejects a pending UBMS Staff registration:
 * status pending -> rejected. The account row is kept (not deleted) so
 * it remains visible for audit purposes; a rejected account can never
 * log in. A Super Admin may permanently delete it later from User
 * Management if desired.
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
} elseif (!function_exists('notifyUser')) {
    function notifyUser($userId, $category, $message, $link = null) {}
}
header('Content-Type: application/json');

requireRoleApi('super_admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid user id.']);
    exit;
}

$stmt = $pdo->prepare("SELECT username, full_name, role, status FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}
if ($user['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'This account is not awaiting approval.']);
    exit;
}

$update = $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
$update->execute([$id]);

logAudit('Rejected Staff Registration', 'user', $id, $user['username'] . ' (' . $user['full_name'] . ')', 'pending', 'rejected');
notifyUser($id, 'account', 'Your UBMS Staff registration was not approved. Please contact a Super Admin for details.');

echo json_encode(['success' => true]);
