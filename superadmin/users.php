<?php
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();
// CSRF helper for the recovery-review actions issued from this page
// (also brings in the notification/audit helpers used by the queue).
require_once __DIR__ . '/../includes/password_recovery.php';
// Labels + (read-only) profile helpers for the correction-request queue.
require_once __DIR__ . '/../includes/correction_requests.php';

$pageTitle = 'User Management';
$activeNav = 'users';

$users = $pdo->query(
    "SELECT u.id, u.full_name, u.username, u.email, u.role, u.status, u.created_at, u.last_login,
            u.department_id, u.course_id, u.organization_id, o.organization_name
       FROM users u
       LEFT JOIN organizations o ON o.id = u.organization_id
      ORDER BY u.created_at DESC"
)->fetchAll();

// Organizations a Super Admin can assign to an account. This is what the
// "Organization" filter on reports.php reports against.
$organizations = $pdo->query('SELECT id, organization_name FROM organizations ORDER BY organization_name')->fetchAll();

// Departments & courses for the Edit User modal (institutional records).
// ALL rows are loaded (flagged by is_active) so an account that already
// points at a deactivated entry still shows its current value and can be
// saved unchanged; newly assignable options are the active ones, and
// inactive options are labeled as such.
$departments = $pdo->query('SELECT id, department_name, is_active FROM departments ORDER BY is_active DESC, department_name')->fetchAll();
$courses     = $pdo->query('SELECT id, department_id, course_name, is_active FROM courses ORDER BY is_active DESC, course_name')->fetchAll();

// Academic correction requests awaiting Super Admin review (plus a short
// history of processed ones). Table created by
// database/migration_correction_requests.sql; on an un-migrated database
// degrade to an empty section instead of fatally erroring this page.
$correctionPending = [];
$correctionRecent  = [];
try {
    $correctionPending = $pdo->query(
        "SELECT cr.id, cr.user_id, cr.field_requested, cr.current_value, cr.requested_value, cr.created_at,
                u.full_name, u.username, u.department_id, u.course_id, u.organization_id
           FROM correction_requests cr
           JOIN users u ON u.id = cr.user_id
          WHERE cr.status = 'pending'
          ORDER BY cr.created_at ASC"
    )->fetchAll();
    $correctionRecent = $pdo->query(
        "SELECT cr.id, cr.field_requested, cr.requested_value, cr.status, cr.review_note, cr.reviewed_at,
                u.full_name, u.username, rv.full_name AS reviewer_name
           FROM correction_requests cr
           JOIN users u ON u.id = cr.user_id
           LEFT JOIN users rv ON rv.id = cr.reviewed_by
          WHERE cr.status IN ('approved', 'rejected')
          ORDER BY COALESCE(cr.reviewed_at, cr.created_at) DESC
          LIMIT 10"
    )->fetchAll();
} catch (Exception $e) {
    error_log('[UBMSConnect] correction request queue could not be loaded: ' . $e->getMessage());
}

// Count active super admins - used client-side to warn before self-lockout,
// but the REAL protection is enforced server-side in actions/manage_user.php.
$activeSuperAdminCount = 0;
foreach ($users as $u) {
    if ($u['role'] === 'super_admin' && $u['status'] === 'active') $activeSuperAdminCount++;
}

// Password recovery requests awaiting Super Admin review (plus a short
// history of already-processed ones). Table created by
// database/migration_password_recovery.sql; on an un-migrated database
// degrade to an empty section instead of fatally erroring this page.
$recoveryPending = [];
$recoveryRecent  = [];
try {
    $recoveryPending = $pdo->query(
        "SELECT r.id, r.user_id, r.identifier_submitted, r.ip_address, r.created_at,
                u.full_name, u.username, u.email
           FROM password_recovery_requests r
           JOIN users u ON u.id = r.user_id
          WHERE r.status = 'pending'
          ORDER BY r.created_at ASC"
    )->fetchAll();
    $recoveryRecent = $pdo->query(
        "SELECT r.id, r.status, r.review_note, r.reviewed_at,
                u.full_name, u.username, rv.full_name AS reviewer_name
           FROM password_recovery_requests r
           JOIN users u ON u.id = r.user_id
           LEFT JOIN users rv ON rv.id = r.reviewed_by
          WHERE r.status IN ('approved', 'rejected', 'handled')
          ORDER BY COALESCE(r.reviewed_at, r.created_at) DESC
          LIMIT 10"
    )->fetchAll();
} catch (Exception $e) {
    error_log('[UBMSConnect] password recovery queue could not be loaded: ' . $e->getMessage());
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>User Management</h1>
            <p class="page-subtitle">Create, edit, activate/deactivate, delete, and manage the role of any account.</p>
        </div>
        <button class="btn btn-primary" onclick="openCreateUser()">+ Create User</button>
    </div>

    <?php // Password recovery review queue (Super Admin-assisted recovery workflow). ?>
    <div class="card" id="recovery-requests">
        <div class="card-title">Password Recovery Requests</div>
        <p class="page-subtitle">Requests submitted from the Forgot Password page. Verify the requester's identity in person through the established university process before approving any reset.</p>
        <?php if (!$recoveryPending): ?>
            <p class="text-muted">No pending recovery requests.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>User</th><th>Username / Email</th><th>Submitted As</th><th>From IP</th><th>Requested</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recoveryPending as $r): ?>
                        <tr>
                            <td>#<?= (int) $r['id'] ?></td>
                            <td><?= e($r['full_name']) ?></td>
                            <td><?= e($r['username']) ?><br><span class="text-muted"><?= e($r['email'] ?: 'No email on file') ?></span></td>
                            <td><?= e($r['identifier_submitted']) ?></td>
                            <td><?= e($r['ip_address']) ?></td>
                            <td><?= e(formatDateTime($r['created_at'])) ?></td>
                            <td style="white-space:nowrap;">
                                <button class="btn btn-sm btn-success" onclick='openRecoveryApprove(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Approve &amp; Reset</button>
                                <button class="btn btn-sm" onclick="reviewRecovery(<?= (int) $r['id'] ?>, 'rejected', '<?= e(addslashes($r['full_name'])) ?>')">Reject</button>
                                <button class="btn btn-sm" onclick="reviewRecovery(<?= (int) $r['id'] ?>, 'handled', '<?= e(addslashes($r['full_name'])) ?>')">Mark Handled</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($recoveryRecent): ?>
            <div class="card-title" style="margin-top: 20px;">Recently Processed Requests</div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>User</th><th>Decision</th><th>Reviewed By</th><th>Reviewed At</th><th>Note</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recoveryRecent as $r): ?>
                        <?php
                            $decisionBadge = $r['status'] === 'approved' ? 'badge-completed'
                                : ($r['status'] === 'rejected' ? 'badge-rejected' : 'badge-default');
                        ?>
                        <tr>
                            <td>#<?= (int) $r['id'] ?></td>
                            <td><?= e($r['full_name']) ?> <span class="text-muted">(<?= e($r['username']) ?>)</span></td>
                            <td><span class="badge <?= $decisionBadge ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                            <td><?= $r['reviewer_name'] ? e($r['reviewer_name']) : '<span class="text-muted">System</span>' ?></td>
                            <td><?= $r['reviewed_at'] ? e(formatDateTime($r['reviewed_at'])) : '<span class="text-muted">-</span>' ?></td>
                            <td><?= $r['review_note'] ? e($r['review_note']) : '<span class="text-muted">-</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php // Academic correction request review queue (profile "Request Correction" submissions). ?>
    <div class="card" id="correction-requests">
        <div class="card-title">Academic Correction Requests</div>
        <p class="page-subtitle">Submitted from user profiles. Applying a request only updates that one academic field on the requester's account, and only to a value that already exists in Institutional Records. Role and Student Status are never changed here.</p>
        <?php if (!$correctionPending): ?>
            <p class="text-muted">No pending correction requests.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>Requested By</th><th>Field</th><th>Current Value</th><th>Requested Value</th><th>Requested</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($correctionPending as $r): ?>
                        <tr>
                            <td>#<?= (int) $r['id'] ?></td>
                            <td><?= e($r['full_name']) ?><br><span class="text-muted"><?= e($r['username']) ?></span></td>
                            <td><?= e(correctionFieldLabel($r['field_requested'])) ?></td>
                            <td><?= $r['current_value'] !== null && $r['current_value'] !== '' ? e($r['current_value']) : '<span class="text-muted">Not set</span>' ?></td>
                            <td><?= e($r['requested_value']) ?></td>
                            <td><?= e(formatDateTime($r['created_at'])) ?></td>
                            <td style="white-space:nowrap;">
                                <button class="btn btn-sm btn-success" onclick='applyCorrection(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Apply</button>
                                <button class="btn btn-sm" onclick="reviewCorrection(<?= (int) $r['id'] ?>, 'rejected', '<?= e(addslashes($r['full_name'])) ?>')">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($correctionRecent): ?>
            <div class="card-title" style="margin-top: 20px;">Recently Reviewed Requests</div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>ID</th><th>User</th><th>Field</th><th>Requested Value</th><th>Decision</th><th>Reviewed By</th><th>Reviewed At</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($correctionRecent as $r): ?>
                        <?php
                            $decisionBadge = $r['status'] === 'approved' ? 'badge-completed' : 'badge-rejected';
                        ?>
                        <tr>
                            <td>#<?= (int) $r['id'] ?></td>
                            <td><?= e($r['full_name']) ?> <span class="text-muted">(<?= e($r['username']) ?>)</span></td>
                            <td><?= e(correctionFieldLabel($r['field_requested'])) ?></td>
                            <td><?= e($r['requested_value']) ?></td>
                            <td><span class="badge <?= $decisionBadge ?>"><?= e(ucfirst($r['status'])) ?></span></td>
                            <td><?= $r['reviewer_name'] ? e($r['reviewer_name']) : '<span class="text-muted">-</span>' ?></td>
                            <td><?= $r['reviewed_at'] ? e(formatDateTime($r['reviewed_at'])) : '<span class="text-muted">-</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Username / Email</th><th>Organization</th><th>Role</th><th>Status</th><th>Registered</th><th>Last Login</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr id="user-row-<?= (int)$u['id'] ?>">
                        <td>#<?= (int)$u['id'] ?></td>
                        <td><?= e($u['full_name']) ?></td>
                        <td><?= e($u['username']) ?><br><span class="text-muted"><?= e($u['email'] ?: 'No email on file') ?></span></td>
                        <td><?= $u['organization_name'] ? e($u['organization_name']) : '<span class="text-muted">Not set</span>' ?></td>
                        <td id="user-role-<?= (int)$u['id'] ?>"><span class="badge <?= roleBadgeClass($u['role']) ?>"><?= e(roleLabel($u['role'])) ?></span></td>
                        <td id="user-status-<?= (int)$u['id'] ?>"><span class="badge <?= accountStatusBadgeClass($u['status']) ?>"><?= e(ucfirst($u['status'])) ?></span></td>
                        <td><?= e(formatDate($u['created_at'])) ?></td>
                        <td><?= $u['last_login'] ? e(formatDateTime($u['last_login'])) : '<span class="text-muted">Never</span>' ?></td>
                        <td style="white-space:nowrap;">
                            <button class="btn btn-sm" onclick='openEditUser(<?= json_encode($u, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                            <?php if ($u['status'] === 'active'): ?>
                                <button class="btn btn-sm" onclick="toggleStatus(<?= (int)$u['id'] ?>, 'inactive', '<?= e(addslashes($u['full_name'])) ?>')">Deactivate</button>
                            <?php elseif ($u['status'] === 'inactive'): ?>
                                <button class="btn btn-sm btn-success" onclick="toggleStatus(<?= (int)$u['id'] ?>, 'active', '<?= e(addslashes($u['full_name'])) ?>')">Activate</button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-danger" onclick="deleteUser(<?= (int)$u['id'] ?>, '<?= e(addslashes($u['full_name'])) ?>')">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- Create / Edit modal (shared) -->
<div class="modal-overlay" id="userModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="userModalTitle">Create User</h3>
            <button class="modal-close" data-close-modal="userModal">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="uId">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" id="uFullName">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="text" id="uEmail">
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" id="uUsername">
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label>Role</label>
                    <select id="uRole">
                        <option value="host">Event Host</option>
                        <option value="staff">UBMS Staff</option>
                        <option value="super_admin">Super Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select id="uStatus">
                        <option value="active">Active</option>
                        <option value="pending">Pending</option>
                        <option value="inactive">Inactive</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </div>
            <div class="grid-2">
                <div class="form-group">
                    <label for="uDepartment">Department</label>
                    <select id="uDepartment" onchange="departmentChanged()">
                        <option value="">Not set</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= (int) $dept['id'] ?>"<?= $dept['is_active'] ? '' : ' data-inactive="1"' ?>><?= e($dept['department_name']) ?><?= $dept['is_active'] ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="small-note">Academic detail shown on the user's profile. Leave as &ldquo;Not set&rdquo; for accounts where it does not apply.</div>
                </div>
                <div class="form-group">
                    <label for="uCourse">Course</label>
                    <select id="uCourse">
                        <option value="">Not set</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= (int) $course['id'] ?>" data-department="<?= (int) $course['department_id'] ?>"<?= $course['is_active'] ? '' : ' data-inactive="1"' ?>><?= e($course['course_name']) ?><?= $course['is_active'] ? '' : ' (inactive)' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="small-note">Filtered to the selected Department. Student Status and Role are never changed from these fields.</div>
                </div>
            </div>
            <div class="form-group">
                <label>Organization</label>
                <select id="uOrganization">
                    <option value="">Not set</option>
                    <?php foreach ($organizations as $org): ?>
                        <option value="<?= (int) $org['id'] ?>"><?= e($org['organization_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="small-note">Used by the Organization filter on Reports. Add or rename organizations in Institutional Records.</div>
            </div>
            <div class="form-group">
                <label id="uPasswordLabel">Password</label>
                <input type="password" id="uPassword" placeholder="Leave blank to keep current password (when editing)">
                <div class="small-note" id="uPasswordHint">Minimum 6 characters.</div>
            </div>
            <div id="userModalMsg"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" data-close-modal="userModal">Cancel</button>
            <button class="btn btn-primary" onclick="saveUser()">Save</button>
        </div>
    </div>
</div>

<!-- Recovery request review modal (Super Admin assisted password reset) -->
<div class="modal-overlay" id="recoveryModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Review Password Recovery Request</h3>
            <button class="modal-close" data-close-modal="recoveryModal">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="rId">
            <p id="rSummary" class="text-muted"></p>
            <div class="form-group">
                <label class="checkbox-row">
                    <input type="checkbox" id="rVerified">
                    <span>I have verified the requester's identity in person through the university's established verification process (e.g. valid university ID).</span>
                </label>
                <div class="small-note">A recovery request on its own never proves account ownership. Approval must only happen after identity verification.</div>
            </div>
            <div class="form-group">
                <label>Verification Note (optional)</label>
                <input type="text" id="rNote" maxlength="255" placeholder="e.g. University ID checked against the registrar record">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" id="rPassword" minlength="6" autocomplete="new-password">
                <div class="small-note">Minimum 6 characters. Hand this password to the verified requester in person - it is never written to notifications, logs, or responses.</div>
            </div>
            <div id="recoveryModalMsg"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" data-close-modal="recoveryModal">Cancel</button>
            <button class="btn btn-primary" id="rSubmitBtn" onclick="submitRecoveryApprove()">Approve &amp; Reset Password</button>
        </div>
    </div>
</div>

<?php
// The CSRF token is injected as a tiny standalone script because a nowdoc
// cannot interpolate PHP values; the shared page script follows.
$extraScripts = '<script>window.RECOVERY_CSRF = ' . json_encode(recoveryCsrfToken()) . ';</script>' . <<<'JS'
<script>
function openCreateUser() {
    document.getElementById('userModalTitle').textContent = 'Create User';
    document.getElementById('uId').value = '';
    document.getElementById('uFullName').value = '';
    document.getElementById('uEmail').value = '';
    document.getElementById('uUsername').value = '';
    document.getElementById('uRole').value = 'host';
    document.getElementById('uStatus').value = 'active';
    document.getElementById('uDepartment').value = '';
    document.getElementById('uCourse').value = '';
    document.getElementById('uOrganization').value = '';
    document.getElementById('uPassword').value = '';
    document.getElementById('uPasswordHint').textContent = 'Minimum 6 characters. Required for new accounts.';
    document.getElementById('userModalMsg').innerHTML = '';
    openModal('userModal');
}

/* Hide course options that belong to a different department than the one
 * selected, while NEVER hiding the course the account is already assigned
 * (its data-department may differ from a freshly changed selection - the
 * user must still be able to see/keep or deliberately clear it). */
function filterEditCourses() {
    const dept = document.getElementById('uDepartment').value;
    const courseSel = document.getElementById('uCourse');
    const current = courseSel.value;
    Array.prototype.forEach.call(courseSel.options, function (opt) {
        if (!opt.value) return; // keep the "Not set" placeholder visible
        const belongs = !dept || opt.getAttribute('data-department') === dept;
        opt.hidden = !belongs && opt.value !== current;
    });
}

/* Department changed by the Super Admin: drop a now-mismatched course so the
 * modal never submits a pair the server would refuse, then refilter. Opening
 * the modal does NOT auto-clear (filterEditCourses alone) so an existing
 * value is always preserved until the admin actually changes something. */
function departmentChanged() {
    const dept = document.getElementById('uDepartment').value;
    const courseSel = document.getElementById('uCourse');
    const selected = courseSel.options[courseSel.selectedIndex];
    if (courseSel.value && selected && selected.getAttribute('data-department') !== dept) {
        courseSel.value = '';
    }
    filterEditCourses();
}

function openEditUser(user) {
    document.getElementById('userModalTitle').textContent = 'Edit User - ' + user.full_name;
    document.getElementById('uId').value = user.id;
    document.getElementById('uFullName').value = user.full_name;
    document.getElementById('uEmail').value = user.email || '';
    document.getElementById('uUsername').value = user.username;
    document.getElementById('uRole').value = user.role;
    document.getElementById('uStatus').value = user.status;
    document.getElementById('uDepartment').value = user.department_id || '';
    document.getElementById('uCourse').value = user.course_id || '';
    document.getElementById('uOrganization').value = user.organization_id || '';
    filterEditCourses();
    document.getElementById('uPassword').value = '';
    document.getElementById('uPasswordHint').textContent = 'Leave blank to keep the current password, or enter a new one to reset it.';
    document.getElementById('userModalMsg').innerHTML = '';
    openModal('userModal');
}

function saveUser() {
    const id = document.getElementById('uId').value;
    const isCreate = !id;
    const msg = document.getElementById('userModalMsg');
    msg.innerHTML = '<span class="text-muted">Saving...</span>';

    const params = new URLSearchParams({
        action: isCreate ? 'create' : 'edit',
        id: id,
        full_name: document.getElementById('uFullName').value,
        email: document.getElementById('uEmail').value,
        username: document.getElementById('uUsername').value,
        role: document.getElementById('uRole').value,
        status: document.getElementById('uStatus').value,
        department_id: document.getElementById('uDepartment').value,
        course_id: document.getElementById('uCourse').value,
        organization_id: document.getElementById('uOrganization').value,
        password: document.getElementById('uPassword').value
    });

    postJSON('/UBMSConnect/actions/manage_user.php', params.toString())
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success">Saved successfully. Reloading...</div>';
                setTimeout(function () { location.reload(); }, 700);
            } else {
                msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message || 'Save failed.') + '</div>';
            }
        })
        .catch(function (err) {
            msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message || 'Something went wrong.') + '</div>';
        });
}

function toggleStatus(id, newStatus, name) {
    const verb = newStatus === 'active' ? 'activate' : 'deactivate';
    if (!confirm('Are you sure you want to ' + verb + ' the account for "' + name + '"?')) return;

    postJSON('/UBMSConnect/actions/manage_user.php',
        'action=set_status&id=' + encodeURIComponent(id) + '&status=' + encodeURIComponent(newStatus))
        .then(function (data) {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Action failed.');
            }
        })
        .catch(function (err) {
            alert(err.message || 'Something went wrong. Please try again.');
        });
}

function deleteUser(id, name) {
    if (!confirm('Permanently delete the account for "' + name + '"? This cannot be undone.')) return;
    if (!confirm('This is your final confirmation. Delete "' + name + '" now?')) return;

    postJSON('/UBMSConnect/actions/manage_user.php', 'action=delete&id=' + encodeURIComponent(id))
        .then(function (data) {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Delete failed.');
            }
        })
        .catch(function (err) {
            alert(err.message || 'Something went wrong. Please try again.');
        });
}

/* ---------- Password recovery review queue ---------- */
function openRecoveryApprove(r) {
    document.getElementById('rId').value = r.id;
    document.getElementById('rSummary').textContent =
        'Request #' + r.id + ' - ' + r.full_name + ' (' + r.username + '), submitted as "' +
        r.identifier_submitted + '" on ' + r.created_at + ' from ' + r.ip_address + '.';
    document.getElementById('rVerified').checked = false;
    document.getElementById('rNote').value = '';
    document.getElementById('rPassword').value = '';
    document.getElementById('recoveryModalMsg').innerHTML = '';
    openModal('recoveryModal');
}

function submitRecoveryApprove() {
    const msg = document.getElementById('recoveryModalMsg');
    const password = document.getElementById('rPassword').value;

    if (!document.getElementById('rVerified').checked) {
        msg.innerHTML = '<div class="alert alert-error">Confirm that the requester\'s identity was verified before approving.</div>';
        return;
    }
    if (password.length < 6) {
        msg.innerHTML = '<div class="alert alert-error">New password must be at least 6 characters.</div>';
        return;
    }
    msg.innerHTML = '<span class="text-muted">Processing...</span>';

    const params = new URLSearchParams({
        action: 'recovery_review',
        decision: 'approved',
        id: String(document.getElementById('rId').value),
        csrf: window.RECOVERY_CSRF || '',
        identity_verified: '1',
        review_note: document.getElementById('rNote').value,
        password: password
    });

    postJSON('/UBMSConnect/actions/manage_user.php', params.toString())
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message || 'Request approved.') + ' Reloading...</div>';
                setTimeout(function () { location.reload(); }, 1100);
            } else {
                msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message || 'Action failed.') + '</div>';
            }
        })
        .catch(function (err) {
            msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message || 'Something went wrong.') + '</div>';
        });
}

function reviewRecovery(id, decision, name) {
    const verb = decision === 'rejected' ? 'reject' : 'mark as handled';
    if (!confirm('Are you sure you want to ' + verb + ' the recovery request for "' + name + '"? This does not reset any password.')) return;

    const params = new URLSearchParams({
        action: 'recovery_review',
        decision: decision,
        id: String(id),
        csrf: window.RECOVERY_CSRF || ''
    });

    postJSON('/UBMSConnect/actions/manage_user.php', params.toString())
        .then(function (data) {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Action failed.');
            }
        })
        .catch(function (err) {
            alert(err.message || 'Something went wrong. Please try again.');
        });
}

/* ---------- Academic correction request review queue ---------- */
function applyCorrection(r) {
    const fieldLabels = { department: 'Department', course: 'Course', organization: 'Organization' };
    const field = fieldLabels[r.field_requested] || r.field_requested;
    if (!confirm(
        'Apply this correction to ' + r.full_name + '\'s account?\n\n' +
        field + ':\n  Current:    ' + (r.current_value || 'Not set') + '\n' +
        '  Requested:  ' + r.requested_value + '\n\n' +
        'Only this ' + field + ' will change (it must already exist in Institutional Records). Role and Student Status are not affected.'
    )) return;

    const sendApply = function (confirmed) {
        const params = new URLSearchParams({
            action: 'correction_review',
            decision: 'approved',
            id: String(r.id),
            csrf: window.RECOVERY_CSRF || ''
        });
        if (confirmed) params.set('confirm', 'true');

        postJSON('/UBMSConnect/actions/manage_user.php', params.toString())
            .then(function (data) {
                if (data.success) {
                    location.reload();
                } else if (data.needs_confirmation) {
                    // Cross-department course change: ask for explicit confirmation
                    // showing current vs proposed Department and Course.
                    const msg =
                        'This course belongs to a different department. Applying will change both:\n\n' +
                        'Department: ' + data.current_department + ' → ' + data.proposed_department + '\n' +
                        'Course:     ' + data.current_course + ' → ' + data.proposed_course + '\n\n' +
                        'Confirm to update both fields together.';
                    if (!confirm(msg)) return;
                    sendApply(true);
                } else {
                    alert(data.message || 'Could not apply the correction.');
                }
            })
            .catch(function (err) {
                alert(err.message || 'Something went wrong. Please try again.');
            });
    };

    sendApply(false);
}

function reviewCorrection(id, decision, name) {
    if (!confirm('Reject the correction request from "' + name + '"? Their saved information will NOT change.')) return;

    const params = new URLSearchParams({
        action: 'correction_review',
        decision: decision,
        id: String(id),
        csrf: window.RECOVERY_CSRF || ''
    });

    postJSON('/UBMSConnect/actions/manage_user.php', params.toString())
        .then(function (data) {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Action failed.');
            }
        })
        .catch(function (err) {
            alert(err.message || 'Something went wrong. Please try again.');
        });
}
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>

