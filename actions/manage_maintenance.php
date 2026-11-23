<?php
/**
 * actions/manage_maintenance.php
 * Staff only. Provides the "Clear" (delete one record)
 * and "Clear All" (delete every maintenance_logs row) controls.
 *
 * This performs REAL deletion of maintenance_logs rows - it does not
 * merely hide them in the UI - because the person requesting this
 * explicitly asked for delete behavior with confirmation + permissions
 * + audit logging, rather than a display-only clear. To preserve
 * accountability even after deletion, every delete is written to the
 * general audit_logs table with the note content captured in
 * target_label BEFORE the row is removed, so who deleted what remains
 * traceable even though the original row is gone.
 */

require_once __DIR__ . '/../includes/auth.php';
$__auditHelper = __DIR__ . '/../includes/audit.php';
if (file_exists($__auditHelper)) {
    require_once $__auditHelper;
} elseif (!function_exists('logAudit')) {
    function logAudit($action, $targetType = null, $targetId = null, $targetLabel = null, $previousValue = null, $newValue = null) {
        error_log('[UBMSConnect] logAudit() called but includes/audit.php is missing - action not recorded: ' . $action);
    }
}
header('Content-Type: application/json');
requireRoleApi('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'delete_one') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid maintenance record id.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT note FROM maintenance_logs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Maintenance record not found (it may have already been cleared).']);
        exit;
    }

    try {
        $label = mb_strlen($row['note']) > 80 ? (mb_substr($row['note'], 0, 77) . '...') : $row['note'];
        $del = $pdo->prepare('DELETE FROM maintenance_logs WHERE id = ?');
        $del->execute([$id]);

        logAudit('Deleted Maintenance Record', 'maintenance_log', $id, $label);

        echo json_encode(['success' => true, 'message' => 'Maintenance record cleared.']);
    } catch (Exception $e) {
        http_response_code(500);
        error_log('[UBMSConnect] manage_maintenance.php delete_one error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to clear the record. Please try again.']);
    }
    exit;
}

if ($action === 'delete_all') {
    // Require the confirmation phrase server-side too, not just a JS confirm() -
    // a determined client can bypass a JS dialog, but not this.
    $confirmPhrase = trim($_POST['confirm_phrase'] ?? '');
    if (strtoupper($confirmPhrase) !== 'CLEAR ALL') {
        echo json_encode(['success' => false, 'message' => 'Confirmation phrase did not match. Nothing was deleted.']);
        exit;
    }

    try {
        $countStmt = $pdo->query('SELECT COUNT(*) FROM maintenance_logs');
        $count = (int) $countStmt->fetchColumn();

        if ($count === 0) {
            echo json_encode(['success' => true, 'message' => 'There were no maintenance records to clear.', 'deleted' => 0]);
            exit;
        }

        $pdo->exec('DELETE FROM maintenance_logs');

        logAudit('Cleared All Maintenance Records', 'maintenance_log', null, "$count record(s) permanently deleted");

        echo json_encode(['success' => true, 'message' => "Cleared $count maintenance record(s).", 'deleted' => $count]);
    } catch (Exception $e) {
        http_response_code(500);
        error_log('[UBMSConnect] manage_maintenance.php delete_all error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to clear maintenance records. Please try again.']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
