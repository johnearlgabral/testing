<?php
/**
 * actions/manage_venue.php
 * Staff only. Makes venues fully dynamic: create, edit,
 * change status, and deactivate/reactivate (soft delete via is_active,
 * so existing activities that reference a venue are never orphaned).
 */

require_once __DIR__ . '/../includes/auth.php';
// Shared photo-upload helpers (also used by manage_utility.php) - extracted
// from this file so venues and utilities store photos the exact same way.
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
        $name = trim($_POST['venue_name'] ?? '');
        $building = trim($_POST['building'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $capacity = (int) ($_POST['capacity'] ?? 0);
        $status = trim($_POST['status'] ?? 'Available');
        $isFree = ($_POST['is_free'] ?? '1') === '1' ? 1 : 0;
        $usageFee = trim($_POST['usage_fee'] ?? '');
        $imageFile = $_FILES['venue_image'] ?? null;

        if ($name === '' || $building === '') {
            echo json_encode(['success' => false, 'message' => 'Venue name and building are required.']);
            exit;
        }
        if (!in_array($status, validVenueStatuses(), true)) {
            $status = 'Available';
        }
        if ($capacity < 0) $capacity = 0;
        if (!$isFree && ($usageFee === '' || !is_numeric($usageFee) || (float) $usageFee <= 0)) {
            echo json_encode(['success' => false, 'message' => 'Enter a fee greater than zero for a paid venue.']);
            exit;
        }
        $usageFee = $isFree ? null : number_format((float) $usageFee, 2, '.', '');
        if ($imageFile && ($imageError = imageUploadError($imageFile, 'venue image'))) {
            echo json_encode(['success' => false, 'message' => $imageError]);
            exit;
        }

        $savedImage = null;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO venues (venue_name, building, location, description, capacity, status, is_free, usage_fee, is_active, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())'
            );
            $stmt->execute([$name, $building, $location ?: null, $description ?: null, $capacity, $status, $isFree, $usageFee, currentUserId()]);
            $newId = (int) $pdo->lastInsertId();
            if ($imageFile && $imageFile['error'] !== UPLOAD_ERR_NO_FILE) {
                $savedImage = saveUploadedImage($imageFile, 'venues', 'venue', $newId, 'venue image');
                $pdo->prepare('UPDATE venues SET image_path = ? WHERE id = ?')->execute([$savedImage, $newId]);
            }
            $pdo->commit();

            logAudit('Added Venue', 'venue', $newId, $name, null, $status);
            notifyRole('staff', 'admin', "New venue added: \"$name\".", '/UBMSConnect/staff/venues.php', currentUserId());
            notifyRole('super_admin', 'admin', "New venue added: \"$name\".", '/UBMSConnect/superadmin/audit_logs.php?action_filter=Added%20Venue', currentUserId());

            echo json_encode(['success' => true, 'message' => 'Venue added successfully.', 'venue_id' => $newId]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            deleteUploadedImage('venues', $savedImage);
            http_response_code(500);
            error_log('[UBMSConnect] manage_venue.php create error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to add venue. Please try again.']);
        }
        break;
    }

    case 'edit': {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['venue_name'] ?? '');
        $building = trim($_POST['building'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $capacity = (int) ($_POST['capacity'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $isFree = ($_POST['is_free'] ?? '1') === '1' ? 1 : 0;
        $usageFee = trim($_POST['usage_fee'] ?? '');

        if ($id <= 0 || $name === '' || $building === '') {
            echo json_encode(['success' => false, 'message' => 'Venue name and building are required.']);
            exit;
        }
        if (!in_array($status, validVenueStatuses(), true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status value.']);
            exit;
        }
        if (!$isFree && ($usageFee === '' || !is_numeric($usageFee) || (float) $usageFee <= 0)) {
            echo json_encode(['success' => false, 'message' => 'Enter a fee greater than zero for a paid venue.']);
            exit;
        }
        $usageFee = $isFree ? null : number_format((float) $usageFee, 2, '.', '');

        $imageFile = $_FILES['venue_image'] ?? null;
        if ($imageFile && ($imageError = imageUploadError($imageFile, 'venue image'))) {
            echo json_encode(['success' => false, 'message' => $imageError]);
            exit;
        }

        $existing = $pdo->prepare('SELECT venue_name, status, image_path FROM venues WHERE id = ?');
        $existing->execute([$id]);
        $venue = $existing->fetch();
        if (!$venue) {
            echo json_encode(['success' => false, 'message' => 'Venue not found.']);
            exit;
        }

        $savedImage = null;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE venues SET venue_name=?, building=?, location=?, description=?, capacity=?, status=?, is_free=?, usage_fee=? WHERE id=?'
            );
            $stmt->execute([$name, $building, $location ?: null, $description ?: null, $capacity, $status, $isFree, $usageFee, $id]);
            if ($imageFile && $imageFile['error'] !== UPLOAD_ERR_NO_FILE) {
                $savedImage = saveUploadedImage($imageFile, 'venues', 'venue', $id, 'venue image');
                $pdo->prepare('UPDATE venues SET image_path = ? WHERE id = ?')->execute([$savedImage, $id]);
            }
            $pdo->commit();
            if ($savedImage && !empty($venue['image_path'])) {
                deleteUploadedImage('venues', $venue['image_path']);
            }

            logAudit('Edited Venue', 'venue', $id, $name);
            if ($venue['status'] !== $status) {
                logAudit('Updated Venue Status', 'venue', $id, $name, $venue['status'], $status);
            }

            echo json_encode(['success' => true, 'message' => 'Venue updated successfully.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            deleteUploadedImage('venues', $savedImage);
            http_response_code(500);
            error_log('[UBMSConnect] manage_venue.php edit error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to update venue. Please try again.']);
        }
        break;
    }

    case 'set_active': {
        $id = (int) ($_POST['id'] ?? 0);
        $active = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;

        $existing = $pdo->prepare('SELECT venue_name FROM venues WHERE id = ?');
        $existing->execute([$id]);
        $venue = $existing->fetch();
        if (!$venue) {
            echo json_encode(['success' => false, 'message' => 'Venue not found.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare('UPDATE venues SET is_active = ? WHERE id = ?');
            $stmt->execute([$active, $id]);

            logAudit($active ? 'Reactivated Venue' : 'Deactivated Venue', 'venue', $id, $venue['venue_name']);

            echo json_encode(['success' => true, 'message' => $active ? 'Venue reactivated.' : 'Venue deactivated.']);
        } catch (Exception $e) {
            http_response_code(500);
            error_log('[UBMSConnect] manage_venue.php set_active error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to update venue. Please try again.']);
        }
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
