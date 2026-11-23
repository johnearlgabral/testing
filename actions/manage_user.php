<?php
/**
 * actions/manage_user.php
 * Super-Admin-only. Handles create / edit / activate / deactivate / delete
 * / role change / password reset for any user account.
 *
 * Safety rails enforced here (not just in the UI):
 *   - The role submitted is always validated against validRoles().
 *   - A Super Admin can never deactivate, delete, or demote the LAST
 *     remaining active Super Admin account (including themselves), so the
 *     system can never be left with zero administrators.
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
// CSRF helper + constants for the NEW recovery-review actions below.
require_once __DIR__ . '/../includes/password_recovery.php';
// Labels + institutional-record resolution for academic field edits and the
// NEW correction-review actions below (fail-soft table reads in the helpers).
require_once __DIR__ . '/../includes/correction_requests.php';
header('Content-Type: application/json');

requireRoleApi('super_admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$action = $_POST['action'] ?? '';

/** Count currently-active super admins (optionally excluding one user id) */
function countActiveSuperAdmins(PDO $pdo, $excludeId = null) {
    $sql = "SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND status = 'active'";
    $params = [];
    if ($excludeId) {
        $sql .= " AND id != ?";
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

switch ($action) {

    case 'create': {
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role     = trim($_POST['role'] ?? '');
        $status   = trim($_POST['status'] ?? 'active');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || $username === '' || strlen($password) < 6) {
            echo json_encode(['success' => false, 'message' => 'Full name, username, and a password of at least 6 characters are required.']);
            exit;
        }
        if (!in_array($role, validRoles(), true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid role selected.']);
            exit;
        }
        if (!in_array($status, validAccountStatuses(), true)) {
            $status = 'active';
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'That username is already taken.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Optional organization assignment. Validated against the organizations
        // table so a tampered or stale value is never stored silently - this is
        // the column the Organization filter on reports.php groups by.
        $organizationId = (int) ($_POST['organization_id'] ?? 0);
        if ($organizationId) {
            $orgCheck = $pdo->prepare('SELECT id FROM organizations WHERE id = ?');
            $orgCheck->execute([$organizationId]);
            if (!$orgCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'That organization does not exist. Add it in Institutional Records first.']);
                exit;
            }
        }

        // Optional academic assignments, validated exactly like the edit path.
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        $courseId     = (int) ($_POST['course_id'] ?? 0);
        if ($departmentId) {
            $deptCheck = $pdo->prepare('SELECT id FROM departments WHERE id = ?');
            $deptCheck->execute([$departmentId]);
            if (!$deptCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'That department does not exist. Add it in Institutional Records first.']);
                exit;
            }
        }
        if ($courseId) {
            $courseCheck = $pdo->prepare('SELECT id, department_id FROM courses WHERE id = ?');
            $courseCheck->execute([$courseId]);
            $courseRow = $courseCheck->fetch();
            if (!$courseRow) {
                echo json_encode(['success' => false, 'message' => 'That course does not exist. Add it in Institutional Records first.']);
                exit;
            }
            if ($departmentId && (int) $courseRow['department_id'] !== $departmentId) {
                echo json_encode(['success' => false, 'message' => 'That course does not belong to the selected department.']);
                exit;
            }
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password, full_name, email, role, status, department_id, course_id, organization_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$username, $hash, $fullName, $email ?: null, $role, $status, $departmentId ?: null, $courseId ?: null, $organizationId ?: null]);
        $newId = (int) $pdo->lastInsertId();

        logAudit('Created User', 'user', $newId, "$username ($fullName)", null, roleLabel($role));
        echo json_encode(['success' => true]);
        break;
    }

    case 'edit': {
        $id       = (int) ($_POST['id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role     = trim($_POST['role'] ?? '');
        $status   = trim($_POST['status'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($id <= 0 || $fullName === '' || $username === '') {
            echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
            exit;
        }
        if (!in_array($role, validRoles(), true) || !in_array($status, validAccountStatuses(), true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid role or status value.']);
            exit;
        }

        $existingStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $existingStmt->execute([$id]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        // Prevent demoting/deactivating the last active Super Admin via edit.
        $wasActiveSuperAdmin = ($existing['role'] === 'super_admin' && $existing['status'] === 'active');
        $willStayActiveSuperAdmin = ($role === 'super_admin' && $status === 'active');
        if ($wasActiveSuperAdmin && !$willStayActiveSuperAdmin) {
            if (countActiveSuperAdmins($pdo, $id) === 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot change this account: it is the last active Super Admin. Promote another account to Super Admin first.']);
                exit;
            }
        }

        $usernameCheck = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $usernameCheck->execute([$username, $id]);
        if ($usernameCheck->fetch()) {
            echo json_encode(['success' => false, 'message' => 'That username is already taken by another account.']);
            exit;
        }

        // Optional organization assignment (blank = "not set"). Validated against
        // the organizations table so an invalid value is never stored - this is
        // the column the Organization filter on reports.php groups by. A
        // completely ABSENT key (stale client) keeps the stored value, exactly
        // like department_id/course_id below.
        $organizationId = array_key_exists('organization_id', $_POST)
            ? (int) $_POST['organization_id']
            : (int) ($existing['organization_id'] ?? 0);
        if ($organizationId) {
            $orgCheck = $pdo->prepare('SELECT id FROM organizations WHERE id = ?');
            $orgCheck->execute([$organizationId]);
            if (!$orgCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'That organization does not exist. Add it in Institutional Records first.']);
                exit;
            }
        }

        // Optional academic assignments (blank = "not set", e.g. non-student
        // accounts where Department/Course do not apply). Each id is validated
        // against its own table; role/status/affiliation are never touched by
        // these fields. A completely ABSENT key (stale client) keeps the stored
        // value instead of silently clearing it.
        $departmentId = array_key_exists('department_id', $_POST)
            ? (int) $_POST['department_id']
            : (int) ($existing['department_id'] ?? 0);
        $courseId = array_key_exists('course_id', $_POST)
            ? (int) $_POST['course_id']
            : (int) ($existing['course_id'] ?? 0);
        if ($departmentId) {
            $deptCheck = $pdo->prepare('SELECT id FROM departments WHERE id = ?');
            $deptCheck->execute([$departmentId]);
            if (!$deptCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => 'That department does not exist. Add it in Institutional Records first.']);
                exit;
            }
        }
        if ($courseId) {
            $courseCheck = $pdo->prepare('SELECT id, department_id FROM courses WHERE id = ?');
            $courseCheck->execute([$courseId]);
            $courseRow = $courseCheck->fetch();
            if (!$courseRow) {
                echo json_encode(['success' => false, 'message' => 'That course does not exist. Add it in Institutional Records first.']);
                exit;
            }
            // A course belongs to a department; refuse a mismatched pair rather
            // than storing an internally inconsistent profile. Course alone
            // (no department selected) is allowed - registration allows the
            // same shape via optional department for non-students.
            if ($departmentId && (int) $courseRow['department_id'] !== $departmentId) {
                echo json_encode(['success' => false, 'message' => 'That course does not belong to the selected department. Pick a course from the chosen department, or clear the department.']);
                exit;
            }
        }

        if ($password !== '') {
            if (strlen($password) < 6) {
                echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
                exit;
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                'UPDATE users SET full_name=?, email=?, username=?, role=?, status=?, department_id=?, course_id=?, organization_id=?, password=?, password_changed_at=NOW() WHERE id=?'
            );
            $stmt->execute([$fullName, $email ?: null, $username, $role, $status, $departmentId ?: null, $courseId ?: null, $organizationId ?: null, $hash, $id]);
            logAudit('Reset Password', 'user', $id, "$username ($fullName)");

            // A Super Admin resetting someone's password should invalidate
            // that account's existing "remember this device" tokens too,
            // for the same reason a self-service reset does.
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')->execute([$id]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE users SET full_name=?, email=?, username=?, role=?, status=?, department_id=?, course_id=?, organization_id=? WHERE id=?'
            );
            $stmt->execute([$fullName, $email ?: null, $username, $role, $status, $departmentId ?: null, $courseId ?: null, $organizationId ?: null, $id]);
        }

        // Record an organization change separately - "which organization was this
        // student under" is exactly what the reports are filtered by.
        if ((int) ($existing['organization_id'] ?? 0) !== $organizationId) {
            logAudit(
                'Updated User Organization',
                'user', $id, "$username ($fullName)",
                $existing['organization_id'] ? ('Organization #' . (int) $existing['organization_id']) : 'Not set',
                $organizationId ? ('Organization #' . $organizationId) : 'Not set'
            );
        }

        // Department / Course changes are recorded the same way, with the
        // institutional names when resolvable (audit columns are VARCHAR(150),
        // so labels are truncated defensively).
        if ((int) ($existing['department_id'] ?? 0) !== $departmentId) {
            logAudit(
                'Updated User Department',
                'user', $id, "$username ($fullName)",
                mb_substr(institutionalRecordLabel($pdo, 'department', $existing['department_id'] ?? 0) ?: 'Not set', 0, 150),
                mb_substr($departmentId ? (institutionalRecordLabel($pdo, 'department', $departmentId) ?: ('Department #' . $departmentId)) : 'Not set', 0, 150)
            );
        }
        if ((int) ($existing['course_id'] ?? 0) !== $courseId) {
            logAudit(
                'Updated User Course',
                'user', $id, "$username ($fullName)",
                mb_substr(institutionalRecordLabel($pdo, 'course', $existing['course_id'] ?? 0) ?: 'Not set', 0, 150),
                mb_substr($courseId ? (institutionalRecordLabel($pdo, 'course', $courseId) ?: ('Course #' . $courseId)) : 'Not set', 0, 150)
            );
        }

        if ($existing['role'] !== $role) {
            logAudit('Changed Role', 'user', $id, "$username ($fullName)", roleLabel($existing['role']), roleLabel($role));
        }
        if ($existing['status'] !== $status) {
            logAudit('Changed Account Status', 'user', $id, "$username ($fullName)", ucfirst($existing['status']), ucfirst($status));
        }

        echo json_encode(['success' => true]);
        break;
    }

    case 'set_status': {
        $id     = (int) ($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');

        if ($id <= 0 || !in_array($status, validAccountStatuses(), true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT username, full_name, role, status FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        if ($user['role'] === 'super_admin' && $user['status'] === 'active' && $status !== 'active') {
            if (countActiveSuperAdmins($pdo, $id) === 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot deactivate the last active Super Admin account.']);
                exit;
            }
        }

        $update = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
        $update->execute([$status, $id]);

        logAudit(
            $status === 'active' ? 'Activated User' : 'Deactivated User',
            'user', $id, $user['username'] . ' (' . $user['full_name'] . ')',
            ucfirst($user['status']), ucfirst($status)
        );

        notifyUser($id, 'account', $status === 'active'
            ? 'Your account has been activated. You can now log in.'
            : 'Your account has been deactivated. Please contact a Super Admin.');

        echo json_encode(['success' => true]);
        break;
    }

    case 'delete': {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid user id.']);
            exit;
        }
        if ($id === currentUserId()) {
            echo json_encode(['success' => false, 'message' => 'You cannot delete your own account while logged in as it.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT username, full_name, role, status FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        if ($user['role'] === 'super_admin' && $user['status'] === 'active' && countActiveSuperAdmins($pdo, $id) === 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete the last active Super Admin account.']);
            exit;
        }

        // Deleting a Host/Staff who owns activities or maintenance notes would
        // cascade-delete those records (existing schema uses ON DELETE CASCADE).
        // Warn rather than silently destroying monitoring history.
        $activityCheck = $pdo->prepare('SELECT COUNT(*) FROM activities WHERE host_id = ?');
        $activityCheck->execute([$id]);
        if ((int) $activityCheck->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'This user has existing activities on record. Deactivate the account instead of deleting it, to preserve monitoring history.']);
            exit;
        }

        $del = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $del->execute([$id]);

        logAudit('Deleted User', 'user', $id, $user['username'] . ' (' . $user['full_name'] . ')');

        echo json_encode(['success' => true]);
        break;
    }

    case 'recovery_review': {
        // Super Admin assisted password-recovery queue: approve / reject /
        // mark handled. Guarded by requireRoleApi('super_admin') above plus
        // a per-session CSRF token (the project has no CSRF framework; this
        // is the smallest scoped implementation for these NEW actions).
        //
        // Everything is keyed on the REQUEST row's own id. No account id is
        // read from the POST at all, so tampering with user ids can never
        // redirect this action at another account - the only account ever
        // affected is r.user_id of the reviewed request.
        $decision = $_POST['decision'] ?? '';
        $id       = (int) ($_POST['id'] ?? 0);

        if ($id <= 0 || !in_array($decision, ['approved', 'rejected', 'handled'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }
        if (!verifyRecoveryCsrf($_POST['csrf'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT r.id, r.user_id, r.status, u.username, u.full_name, u.status AS account_status
               FROM password_recovery_requests r
               JOIN users u ON u.id = r.user_id
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $request = $stmt->fetch();
        if (!$request) {
            echo json_encode(['success' => false, 'message' => 'Recovery request not found.']);
            exit;
        }
        if ($request['status'] !== 'pending') {
            echo json_encode(['success' => false, 'message' => 'This recovery request has already been processed.']);
            exit;
        }

        $reviewerId  = currentUserId();
        $note        = mb_substr(trim($_POST['review_note'] ?? ''), 0, 255);
        $noteOrNull  = $note !== '' ? $note : null;
        $label       = $request['username'] . ' (' . $request['full_name'] . ')';
        // The ONLY account this action can ever affect:
        $targetUserId = (int) $request['user_id'];

        if ($decision === 'approved') {
            // A request on its own never proves ownership - the reviewer
            // must explicitly confirm the university identity check.
            if (($_POST['identity_verified'] ?? '') !== '1') {
                echo json_encode(['success' => false, 'message' => 'Identity verification must be confirmed before a recovery request can be approved.']);
                exit;
            }
            $password = $_POST['password'] ?? '';
            if (strlen($password) < 6) {
                echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters.']);
                exit;
            }
            if ($request['account_status'] !== 'active') {
                echo json_encode(['success' => false, 'message' => 'This account is not active. Activate it from User Management before resetting its password.']);
                exit;
            }

            // Claim the request atomically FIRST so two admins can never
            // process the same request twice (a concurrent claim sees
            // status != 'pending' and aborts).
            $claim = $pdo->prepare(
                "UPDATE password_recovery_requests
                    SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
                  WHERE id = ? AND status = 'pending'"
            );
            $claim->execute([$reviewerId, $noteOrNull, $id]);
            if ($claim->rowCount() === 0) {
                echo json_encode(['success' => false, 'message' => 'This recovery request has already been processed.']);
                exit;
            }

            // Reuse the SAME secure reset mechanics the existing Super Admin
            // password-reset path uses (edit with a new password): bcrypt via
            // password_hash()/PASSWORD_DEFAULT, password_changed_at, and
            // remember-token revocation. $password is NEVER written to the
            // audit trail, notifications, error logs, or any response.
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?')
                ->execute([$hash, $targetUserId]);
            $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')
                ->execute([$targetUserId]);
            // Any stale self-service reset token for this account dies with
            // the old password (the flow that created them was removed and
            // outstanding ones were bulk-invalidated by the migration).
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
                ->execute([$targetUserId]);
            logAudit('Reset Password', 'user', $targetUserId, $label);
            logAudit('Approved Password Recovery Request', 'user', $targetUserId, $label, 'Pending', 'Approved (identity verified)');
            notifyUser(
                $targetUserId,
                'account',
                'Your password has been reset by a Super Admin after identity verification. Your new password was provided to you directly - log in and change it as soon as possible.',
                '/UBMSConnect/login.php'
            );

            // Authenticated admin-only response: contains no password, no
            // token, and no other secret.
            echo json_encode(['success' => true, 'message' => 'Request approved and the password has been reset. Provide the new password to the verified requester in person.']);
            exit;
        }

        // reject / handled - no password change and no identity checkbox.
        $update = $pdo->prepare(
            'UPDATE password_recovery_requests
                SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ?
              WHERE id = ? AND status = ?'
        );
        $update->execute([$decision, $reviewerId, $noteOrNull, $id, 'pending']);
        if ($update->rowCount() === 0) {
            echo json_encode(['success' => false, 'message' => 'This recovery request has already been processed.']);
            exit;
        }

        if ($decision === 'rejected') {
            logAudit('Rejected Password Recovery Request', 'user', $targetUserId, $label, 'Pending', 'Rejected');
            notifyUser($targetUserId, 'account',
                'Your password recovery request was rejected. Please contact a Super Admin if you believe this is a mistake.');
        } else {
            logAudit('Marked Password Recovery Request Handled', 'user', $targetUserId, $label, 'Pending', 'Handled');
            notifyUser($targetUserId, 'account',
                'Your password recovery request has been closed by a Super Admin. Contact a Super Admin if you still need help signing in.');
        }

        echo json_encode(['success' => true]);
        break;
    }

    case 'correction_review': {
        // Super Admin reviews ONE academic correction request submitted from a
        // user profile. Guarded by requireRoleApi('super_admin') above plus the
        // same per-session CSRF token the recovery actions use.
        //
        // The account being changed is taken from the request's OWN user_id
        // column (read inside the transaction) - a user id supplied by the
        // client is never used to authorize a change to another account.
        if (!verifyRecoveryCsrf($_POST['csrf'] ?? '')) {
            echo json_encode(['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
            exit;
        }

        $id       = (int) ($_POST['id'] ?? 0);
        $decision = trim($_POST['decision'] ?? '');
        if ($id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Lock the request row; it must still be pending.
            $stmt = $pdo->prepare(
                "SELECT cr.*, u.username AS target_username, u.full_name AS target_name
                   FROM correction_requests cr
                   JOIN users u ON u.id = cr.user_id
                  WHERE cr.id = ?
                  FOR UPDATE"
            );
            $stmt->execute([$id]);
            $request = $stmt->fetch();
            if (!$request) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Correction request not found.']);
                exit;
            }
            if ($request['status'] !== 'pending') {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'This correction request has already been reviewed.']);
                exit;
            }

            $field      = $request['field_requested'];
            $fieldLabel = correctionFieldLabel($field);
            $targetId   = (int) $request['user_id'];
            $label      = $request['target_username'] . ' (' . $request['target_name'] . ')';

            if ($decision === 'approved') {
                // Map the requested free text to an EXISTING institutional
                // record. If it does not exist (or is ambiguous) the approval
                // is refused with a clear reason - nothing is invented and the
                // request stays pending until the Super Admin resolves it.
                $profile = loadAcademicProfile($pdo, $targetId);
                if (!$profile) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => 'The requesting account no longer exists.']);
                    exit;
                }

                $resolved = resolveCorrectionTarget($pdo, $field, $request['requested_value'], $profile['department_id']);
                if (isset($resolved['error'])) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    echo json_encode(['success' => false, 'message' => $resolved['error']]);
                    exit;
                }

                $column = $field === 'department' ? 'department_id'
                        : ($field === 'course' ? 'course_id' : 'organization_id');
                $previousLabel = academicProfileValue($profile, $field) ?: 'Not set';

                // Cross-field consistency, mirroring the edit path exactly:
                // applying this single column must not produce a
                // department/course pair the Edit User modal would refuse.
                if ($field === 'department' && !empty($profile['course_id'])) {
                    $courseDeptStmt = $pdo->prepare('SELECT department_id FROM courses WHERE id = ?');
                    $courseDeptStmt->execute([(int) $profile['course_id']]);
                    $courseDeptId = (int) $courseDeptStmt->fetchColumn();
                    if ($courseDeptId && $courseDeptId !== (int) $resolved['id']) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        echo json_encode(['success' => false, 'message' =>
                            'This account\'s course "' . academicProfileValue($profile, 'course') . '" belongs to a different department. Use Edit User to change or clear the course first, then approve this request.']);
                        exit;
                    }
                }
                // Cross-department course change: the requested course belongs to
                // a different department than the account's current department.
                // Requires explicit confirmation before both fields are updated.
                $isCrossDepartment = $field === 'course'
                    && !empty($profile['department_id'])
                    && (int) $resolved['department_id'] !== (int) $profile['department_id'];

                if ($isCrossDepartment && ($_POST['confirm'] ?? '') !== 'true') {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $proposedDept = institutionalRecordLabel($pdo, 'department', (int) $resolved['department_id']);
                    echo json_encode([
                        'success' => false,
                        'needs_confirmation' => true,
                        'message' => 'Cross-department course change requires confirmation.',
                        'current_department' => $profile['department_name'] ?: 'Not set',
                        'current_course' => $profile['course_name'] ?: 'Not set',
                        'proposed_department' => $proposedDept,
                        'proposed_course' => $resolved['name'],
                    ]);
                    exit;
                }

                if ($isCrossDepartment && ($_POST['confirm'] ?? '') === 'true') {
                    // Confirmed cross-department course change: update both
                    // department_id and course_id together.
                    $proposedDept = institutionalRecordLabel($pdo, 'department', (int) $resolved['department_id']);
                    $update = $pdo->prepare("UPDATE users SET department_id = ?, course_id = ? WHERE id = ?");
                    $update->execute([(int) $resolved['department_id'], (int) $resolved['id'], $targetId]);

                    logAudit(
                        'Approved Academic Correction',
                        'user', $targetId, $label,
                        mb_substr('Department, Course: ' . ($previousLabel ?: 'Not set'), 0, 150),
                        mb_substr('Department, Course: ' . $proposedDept . ', ' . $resolved['name'], 0, 150)
                    );
                } else {
                    // Single-column update on the request's own user. role, status
                    // and affiliation are deliberately absent from this statement.
                    $update = $pdo->prepare("UPDATE users SET {$column} = ? WHERE id = ?");
                    $update->execute([(int) $resolved['id'], $targetId]);

                    logAudit(
                        'Approved Academic Correction',
                        'user', $targetId, $label,
                        mb_substr($fieldLabel . ': ' . $previousLabel, 0, 150),
                        mb_substr($fieldLabel . ': ' . $resolved['name'], 0, 150)
                    );
                }
            } else {
                logAudit(
                    'Rejected Academic Correction',
                    'user', $targetId, $label,
                    mb_substr($fieldLabel . ': ' . ($request['current_value'] ?: 'Not set'), 0, 150),
                    'Rejected (requested: ' . mb_substr($request['requested_value'], 0, 120) . ')'
                );
            }

            // Mark the request reviewed. The WHERE status='pending' clause makes
            // the review idempotent under concurrent clicks.
            $update = $pdo->prepare(
                "UPDATE correction_requests
                    SET status = ?, reviewed_by = ?, reviewed_at = NOW()
                  WHERE id = ? AND status = 'pending'"
            );
            $update->execute([$decision, currentUserId(), $id]);
            if ($update->rowCount() === 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'This correction request has already been reviewed.']);
                exit;
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[UBMSConnect] correction_review error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not process the correction request.']);
            exit;
        }

        // Tell the requester what happened (their profile either changed or
        // deliberately stayed the same).
        if ($decision === 'approved') {
            notifyUser($targetId, 'account',
                'Your ' . strtolower($fieldLabel) . ' correction request was approved and your profile has been updated.');
        } else {
            notifyUser($targetId, 'account',
                'Your ' . strtolower($fieldLabel) . ' correction request was reviewed and rejected. Your saved information was not changed.');
        }

        echo json_encode(['success' => true]);
        break;
    }

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
}
