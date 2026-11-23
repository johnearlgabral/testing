<?php
/**
 * actions/manage_utility.php
 * Staff only. Makes utilities fully dynamic: create,
 * edit, change status, and deactivate/reactivate (soft delete via
 * is_active).
 */

require_once __DIR__ . '/../includes/auth.php';
// Shared photo-upload helpers (also used by manage_venue.php) - utilities store
// their photos exactly the same way venues do, just in another folder.
require_once __DIR__ . '/../includes/image_uploads.php';
$__auditHelper = __DIR__ . '/../includes/audit.php';
if (file_exists($__auditHelper)) {
    require_once $__auditHelper;
} elseif (!function_exists('logAudit')) {
    function logAudit($action, $targetType = null, $targetId = null, $targetLabel = null, $previousValue = null, $newValue = null) {
        error_log('[UBMSConnect] logAudit() called but includes/audit.php is missing - action not recorded: ' . $action);
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

$action = $_POST['action'] ?? '';

switch ($action) {

    case 'create': {
        $name = trim($_POST['utility_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = trim($_POST['status'] ?? 'Operational');
        $quantityRaw = trim($_POST['quantity'] ?? '');
        $imageFile = $_FILES['utility_image'] ?? null;

        if ($name === '' || $location === '') {
            echo json_encode(['success' => false, 'message' => 'Utility name and location are required.']);
            exit;
        }
        if (!in_array($status, validUtilityStatuses(), true)) {
            $status = 'Operational';
        }
        // Quantity is optional: blank means "this utility has no meaningful
        // count" and stays NULL so the UI never shows a fake number.
        $quantity = null;
        if ($quantityRaw !== '') {
            if (!ctype_digit($quantityRaw) || (int) $quantityRaw < 1) {
                echo json_encode(['success' => false, 'message' => 'Quantity must be a whole number of 1 or more, or left blank.']);
                exit;
            }
            $quantity = (int) $quantityRaw;
        }
        if ($imageFile && ($imageError = imageUploadError($imageFile, 'utility image'))) {
            echo json_encode(['success' => false, 'message' => $imageError]);
            exit;
        }

        $savedImage = null;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO utilities (utility_name, category, location, quantity, status, is_active, created_by, created_at, description)
                 VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), ?)'
            );
            $stmt->execute([$name, $category ?: null, $location, $quantity, $status, currentUserId(), $description ?: null]);
            $newId = (int) $pdo->lastInsertId();
            if ($imageFile && $imageFile['error'] !== UPLOAD_ERR_NO_FILE) {
                $savedImage = saveUploadedImage($imageFile, 'utilities', 'utility', $newId, 'utility image');
                $pdo->prepare('UPDATE utilities SET image_path = ? WHERE id = ?')->execute([$savedImage, $newId]);
            }
            $pdo->commit();

            logAudit('Added Utility', 'utility', $newId, $name, null, $status);
            notifyRole('staff', 'admin', "New utility added: \"$name\".", '/UBMSConnect/staff/utilities.php', currentUserId());
            notifyRole('super_admin', 'admin', "New utility added: \"$name\".", '/UBMSConnect/superadmin/audit_logs.php?action_filter=Added%20Utility', currentUserId());

            echo json_encode(['success' => true, 'message' => 'Utility added successfully.', 'utility_id' => $newId]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            deleteUploadedImage('utilities', $savedImage);
            http_response_code(500);
            error_log('[UBMSConnect] manage_utility.php create error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to add utility. Please try again.']);
        }
        break;
    }

    case 'edit': {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['utility_name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $quantityRaw = trim($_POST['quantity'] ?? '');
        $imageFile = $_FILES['utility_image'] ?? null;

        if ($id <= 0 || $name === '' || $location === '') {
            echo json_encode(['success' => false, 'message' => 'Utility name and location are required.']);
            exit;
        }
        if (!in_array($status, validUtilityStatuses(), true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
            exit;
        }
        // Blank quantity clears the value (back to "not applicable").
        $quantity = null;
        if ($quantityRaw !== '') {
            if (!ctype_digit($quantityRaw) || (int) $quantityRaw < 1) {
                echo json_encode(['success' => false, 'message' => 'Quantity must be a whole number of 1 or more, or left blank.']);
                exit;
            }
            $quantity = (int) $quantityRaw;
        }
        if ($imageFile && ($imageError = imageUploadError($imageFile, 'utility image'))) {
            echo json_encode(['success' => false, 'message' => $imageError]);
            exit;
        }

        $existing = $pdo->prepare('SELECT utility_name, status, image_path FROM utilities WHERE id = ?');
        $existing->execute([$id]);
        $utility = $existing->fetch();
        if (!$utility) {
            echo json_encode(['success' => false, 'message' => 'Utility not found.']);
            exit;
        }

        $savedImage = null;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE utilities SET utility_name=?, category=?, location=?, quantity=?, description=?, status=? WHERE id=?'
            );
            $stmt->execute([$name, $category ?: null, $location, $quantity, $description ?: null, $status, $id]);
            if ($imageFile && $imageFile['error'] !== UPLOAD_ERR_NO_FILE) {
                $savedImage = saveUploadedImage($imageFile, 'utilities', 'utility', $id, 'utility image');
                $pdo->prepare('UPDATE utilities SET image_path = ? WHERE id = ?')->execute([$savedImage, $id]);
            }
            $pdo->commit();
            if ($savedImage && !empty($utility['image_path'])) {
                deleteUploadedImage('utilities', $utility['image_path']);
            }

            logAudit('Edited Utility', 'utility', $id, $name);
            if ($utility['status'] !== $status) {
                logAudit('Updated Utility Status', 'utility', $id, $name, $utility['status'], $status);
            }

            echo json_encode(['success' => true, 'message' => 'Utility updated successfully.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            deleteUploadedImage('utilities', $savedImage);
            http_response_code(500);
            error_log('[UBMSConnect] manage_utility.php edit error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to update utility. Please try again.']);
        }
        break;
    }

    case 'set_active': {
        $id = (int) ($_POST['id'] ?? 0);
        $active = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;

        $existing = $pdo->prepare('SELECT utility_name FROM utilities WHERE id = ?');
        $existing->execute([$id]);
        $utility = $existing->fetch();
        if (!$utility) {
            echo json_encode(['success' => false, 'message' => 'Utility not found.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare('UPDATE utilities SET is_active = ? WHERE id = ?');
            $stmt->execute([$active, $id]);

            logAudit($active ? 'Reactivated Utility' : 'Deactivated Utility', 'utility', $id, $utility['utility_name']);

            echo json_encode(['success' => true, 'message' => $active ? 'Utility reactivated.' : 'Utility deactivated.']);
        } catch (Exception $e) {
            http_response_code(500);
            error_log('[UBMSConnect] manage_utility.php set_active error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to update utility. Please try again.']);
        }
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
