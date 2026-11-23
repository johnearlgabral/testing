<?php
/**
 * actions/request_correction.php
 * Authenticated users ask a Super Admin to correct their academic /
 * organizational information (Department, Course, or Organization).
 *
 * SCOPE / SECURITY
 * - The target account is ALWAYS the session user (currentUserId()); no
 *   user id from the client is ever read, so a requester can never point
 *   a request at someone else's account.
 * - This action NEVER writes to the users table - it only INSERTs a
 *   correction_requests row and notifies Super Admins. The single write
 *   path for approved corrections is actions/manage_user.php.
 * - Per-session CSRF token (the project's scoped recovery-token pattern
 *   is reused - it is a generic session token, not recovery-specific).
 * - Duplicate suppression: one pending request per user per field.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_recovery.php'; // CSRF helpers + notify/audit fallbacks
require_once __DIR__ . '/../includes/correction_requests.php';
header('Content-Type: application/json');
requireLoginApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!verifyRecoveryCsrf($_POST['csrf'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
    exit;
}

$userId         = currentUserId(); // session identity only - never from POST
$field          = trim($_POST['field'] ?? '');
$requestedValue = trim($_POST['requested_value'] ?? '');

if (!in_array($field, ['department', 'course', 'organization'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid field selected.']);
    exit;
}
if ($requestedValue === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter the correct value or explain the requested change.']);
    exit;
}
if (mb_strlen($requestedValue) > 150) {
    echo json_encode(['success' => false, 'message' => 'Please keep the requested value to 150 characters or fewer.']);
    exit;
}

// Load the requester's profile values (read-only) for the reviewer's
// "current vs requested" context snapshot.
$profile = loadAcademicProfile($pdo, $userId);
if (!$profile) {
    echo json_encode(['success' => false, 'message' => 'Account not found.']);
    exit;
}

// One open request per user per field - prevents duplicate submissions
// where practical without ever touching the profile itself.
try {
    $dup = $pdo->prepare(
        "SELECT id FROM correction_requests
          WHERE user_id = ? AND field_requested = ? AND status = 'pending'"
    );
    $dup->execute([$userId, $field]);
    if ($dup->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'You already have a pending ' . correctionFieldLabel($field) . ' correction request. A Super Admin will review it.'
        ]);
        exit;
    }

    $currentValue = academicProfileValue($profile, $field);

    $stmt = $pdo->prepare(
        'INSERT INTO correction_requests (user_id, field_requested, current_value, requested_value, status, created_at)
         VALUES (?, ?, ?, ?, \'pending\', NOW())'
    );
    $stmt->execute([$userId, $field, $currentValue, $requestedValue]);
    $requestId = (int) $pdo->lastInsertId();
} catch (Exception $e) {
    // An un-migrated database (table missing) must not break the profile
    // page either - the user gets a clear, non-fatal message.
    error_log('[UBMSConnect] correction request insert failed: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Correction requests are not available yet on this installation (the migration has not been applied).'
    ]);
    exit;
}

logAudit(
    'Submitted Correction Request',
    'user', $userId, $profile['username'] . ' (' . $profile['full_name'] . ')',
    null,
    correctionFieldLabel($field) . ': ' . mb_substr($requestedValue, 0, 120)
);

// Notify every active Super Admin, linking straight to the review queue.
// The requester themself is excluded (they just saw the confirmation).
notifyRole(
    'super_admin',
    'admin',
    'Correction request from ' . $profile['full_name'] . ' (' . $profile['username'] . '): ' .
        correctionFieldLabel($field) . ' should be "' . mb_substr($requestedValue, 0, 80) . '".',
    '/UBMSConnect/superadmin/users.php#correction-requests',
    $userId
);

echo json_encode([
    'success' => true,
    'message' => 'Your correction request has been submitted for Super Admin review. Your saved information has not changed yet.'
]);