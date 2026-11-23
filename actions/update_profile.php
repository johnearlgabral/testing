<?php
/**
 * actions/update_profile.php
 * Self-service account update, available to ANY logged-in role.
 * Operates EXCLUSIVELY on currentUserId() - never accepts or trusts a
 * target user id from the request, so there is no way for a user to
 * edit anyone else's account through this endpoint. Role is never
 * accepted from the request either, so this can never be used to
 * self-promote.
 *
 * To change the password, the current password must be supplied and
 * verified first.
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

$userId      = currentUserId();
$fullName    = trim($_POST['full_name'] ?? '');
$email       = trim($_POST['email'] ?? '');
$currentPw   = $_POST['current_password'] ?? '';
$newPw       = $_POST['new_password'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

$wantsPasswordChange = ($newPw !== '');

if ($wantsPasswordChange) {
    if ($currentPw === '' || !password_verify($currentPw, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Your current password is incorrect.']);
        exit;
    }
    if (strlen($newPw) < 6) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
        exit;
    }
}

if ($fullName === '') {
    echo json_encode(['success' => false, 'message' => 'Full name is required.']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

try {
    if ($wantsPasswordChange) {
        $hash = password_hash($newPw, PASSWORD_DEFAULT);
        $upd = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, password = ?, password_changed_at = NOW() WHERE id = ?');
        $upd->execute([$fullName, $email ?: null, $hash, $userId]);
        logAudit('Changed Own Password', 'user', $userId, $user['username']);

        // Invalidate every existing "remember this device" token for this
        // account on a password change - a stolen/old device token should
        // not remain valid past a password change. The user can simply
        // check "remember this device" again on their next login.
        $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$userId]);
        clearRememberCookie();
    } else {
        $upd = $pdo->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?');
        $upd->execute([$fullName, $email ?: null, $userId]);
    }

    if ($user['full_name'] !== $fullName || ($user['email'] ?? '') !== $email) {
        logAudit('Updated Own Profile', 'user', $userId, $user['username']);
    }

    $_SESSION['full_name'] = $fullName;

    echo json_encode(['success' => true, 'message' => $wantsPasswordChange ? 'Profile and password updated.' : 'Profile updated.']);
} catch (Exception $e) {
    http_response_code(500);
    error_log('[UBMSConnect] update_profile.php DB error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to save changes. Please try again.']);
}
