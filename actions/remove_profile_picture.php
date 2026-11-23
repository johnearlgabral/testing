<?php
/**
 * actions/remove_profile_picture.php
 * Self-service only - always operates on currentUserId(). Clears the
 * DB reference first, then removes the stored file, and returns the
 * user to the default initials avatar.
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
requireLoginApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$userId = currentUserId();

$stmt = $pdo->prepare('SELECT profile_picture, username FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (empty($user['profile_picture'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have a profile picture to remove.']);
    exit;
}

try {
    $pdo->prepare('UPDATE users SET profile_picture = NULL WHERE id = ?')->execute([$userId]);
} catch (Exception $e) {
    http_response_code(500);
    error_log('[UBMSConnect] remove_profile_picture.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to remove your profile picture. Please try again.']);
    exit;
}

// Only delete the file after the DB update is confirmed successful.
$path = __DIR__ . '/../assets/uploads/profile/' . basename($user['profile_picture']);
if (is_file($path)) {
    @unlink($path);
}

logAudit('Removed Profile Picture', 'user', $userId, $user['username']);

echo json_encode(['success' => true, 'message' => 'Profile picture removed.']);
