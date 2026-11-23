<?php
/**
 * actions/dismiss_activity.php
 * Self-service only - always operates on currentUserId(). Marks one or
 * all of the CURRENT user's own audit_log entries as "dismissed" from
 * their Profile > Recent Activity view.
 *
 * IMPORTANT: this NEVER deletes anything from audit_logs. It only adds
 * rows to dismissed_activity, a separate per-user visibility marker
 * table. The full audit trail remains completely intact and available
 * to Super Admin (superadmin/audit_logs.php) and Staff (staff/logs.php)
 * regardless of what a user dismisses from their own personal summary.
 *
 * A user can only ever dismiss entries where audit_logs.actor_id
 * matches their OWN currentUserId() - enforced in the SQL itself, not
 * just by hiding a button - so John can never affect Mary's view.
 */

require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLoginApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$userId = currentUserId();
$action = $_POST['action'] ?? '';

if ($action === 'dismiss_one') {
    $auditId = (int) ($_POST['audit_log_id'] ?? 0);
    if ($auditId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid activity item.']);
        exit;
    }

    // Verify this audit entry actually belongs to the current user before
    // dismissing it - prevents dismissing (or probing the existence of)
    // another user's activity.
    $check = $pdo->prepare('SELECT id FROM audit_logs WHERE id = ? AND actor_id = ?');
    $check->execute([$auditId, $userId]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Activity item not found.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO dismissed_activity (user_id, audit_log_id, dismissed_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE dismissed_at = NOW()'
        );
        $stmt->execute([$userId, $auditId]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        http_response_code(500);
        error_log('[UBMSConnect] dismiss_activity.php dismiss_one error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to clear that item. Please try again.']);
    }
    exit;
}

if ($action === 'dismiss_all') {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO dismissed_activity (user_id, audit_log_id, dismissed_at)
             SELECT ?, id, NOW() FROM audit_logs WHERE actor_id = ?
             ON DUPLICATE KEY UPDATE dismissed_at = NOW()'
        );
        $stmt->execute([$userId, $userId]);
        echo json_encode(['success' => true, 'message' => 'Your Recent Activity view has been cleared. Your official audit records are unaffected.']);
    } catch (Exception $e) {
        http_response_code(500);
        error_log('[UBMSConnect] dismiss_activity.php dismiss_all error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to clear your recent activity. Please try again.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
