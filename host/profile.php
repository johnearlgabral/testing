<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/affiliation_profile.php';
requireRole('host');

$pageTitle = 'My Profile';
$activeNav = 'profile';

$stmt = $pdo->prepare("SELECT u.username,u.full_name,u.email,u.profile_picture,u.role,u.status,u.created_at,u.last_login,u.password_changed_at,u.university_id,u.affiliation,u.office_unit,d.department_name,c.course_name,o.organization_name FROM users u LEFT JOIN departments d ON d.id=u.department_id LEFT JOIN courses c ON c.id=u.course_id LEFT JOIN organizations o ON o.id=u.organization_id WHERE u.id=?");
$stmt->execute([currentUserId()]);
$user = $stmt->fetch();

$recentActivity = $pdo->prepare(
    "SELECT al.id, al.action, al.target_label, al.previous_value, al.new_value, al.created_at
     FROM audit_logs al
     LEFT JOIN dismissed_activity da ON da.audit_log_id = al.id AND da.user_id = ?
     WHERE al.actor_id = ? AND da.id IS NULL
     ORDER BY al.created_at DESC LIMIT 5"
);
$recentActivity->execute([currentUserId(), currentUserId()]);
$recentActivity = $recentActivity->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>My Profile</h1>
            <p class="page-subtitle">Account information for the currently logged in Event Host.</p>
        </div>
    </div>

    <div class="card section-gap">
        <div class="profile-header">
            <div class="avatar-wrap">
                <?= avatarHtml($user['full_name'], $user['profile_picture']) ?>
                <button type="button" class="avatar-edit-btn" onclick="document.getElementById('photoInput').click()" title="Change photo">&#9998;</button>
            </div>
            <div class="profile-header-info">
                <div class="profile-header-name"><?= e($user['full_name']) ?></div>
                <div class="profile-header-meta">
                    <span class="badge <?= roleBadgeClass($user['role']) ?>"><?= e(roleLabel($user['role'])) ?></span>
                    <span><span class="profile-status-dot <?= accountStatusDotClass($user['status']) ?>"></span><?= e(ucfirst($user['status'])) ?></span>
                    <span>Member since <?= e(formatDate($user['created_at'])) ?></span>
                </div>
                <div class="avatar-actions">
                    <input type="file" id="photoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="uploadPhoto(this)">
                    <button class="btn btn-sm" onclick="document.getElementById('photoInput').click()">Change Photo</button>
                    <?php if (!empty($user['profile_picture'])): ?>
                        <button class="btn btn-sm btn-danger" onclick="removePhoto()">Remove Photo</button>
                    <?php endif; ?>
                </div>
                <div id="photoMsg" class="small-note" style="margin-top:6px;"></div>
            </div>
            <button class="btn btn-primary" onclick="openEditProfile()">Edit Profile</button>
        </div>
    </div>

    <div class="grid-2">
        <div class="stack">
            <div class="card">
                <div class="card-title">Personal Information</div>
                <div class="detail-row"><div class="dr-label">Full Name</div><div class="dr-value"><?= e($user['full_name']) ?></div></div>
                <div class="detail-row"><div class="dr-label">Username</div><div class="dr-value"><?= e($user['username']) ?></div></div>
                <div class="detail-row"><div class="dr-label">Email</div><div class="dr-value"><?= e($user['email'] ?: 'Not set') ?></div></div>
                <div class="detail-row"><div class="dr-label">Role</div><div class="dr-value"><span class="badge <?= roleBadgeClass($user['role']) ?>"><?= e(roleLabel($user['role'])) ?></span> <span class="small-note">(only a Super Admin can change this)</span></div></div>
                <div class="detail-row"><div class="dr-label">Account Status</div><div class="dr-value"><span class="profile-status-dot <?= accountStatusDotClass($user['status']) ?>"></span><?= e(ucfirst($user['status'])) ?></div></div>
                <div class="detail-row"><div class="dr-label">Account Created</div><div class="dr-value"><?= e(formatDateTime($user['created_at'])) ?></div></div>
                <div class="detail-row"><div class="dr-label">Last Login</div><div class="dr-value"><?= $user['last_login'] ? e(formatDateTime($user['last_login'])) : 'This is your first login' ?></div></div>
            </div>

            <div class="card">
                <div class="card-title">Account Security</div>
                <div class="detail-row"><div class="dr-label">Password</div><div class="dr-value">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</div></div>
                <div class="detail-row"><div class="dr-label">Last Password Change</div><div class="dr-value"><?= $user['password_changed_at'] ? e(formatDateTime($user['password_changed_at'])) : 'Not changed since account creation' ?></div></div>
                <button class="btn btn-primary" style="margin-top:10px;" onclick="openChangePassword()">Change Password</button>
            </div>
            <?php renderAffiliationProfile($user); ?>
        </div>

        <div class="card">
            <div class="card-title">
                Your Recent Activity
                <?php if (!empty($recentActivity)): ?>
                    <button class="btn btn-sm" onclick="clearAllActivity()">Clear All</button>
                <?php endif; ?>
            </div>
            <div id="recentActivityList">
                <?php if (empty($recentActivity)): ?>
                    <div class="empty-state" id="recentActivityEmpty">No recent activity to show.</div>
                <?php else: ?>
                    <?php foreach ($recentActivity as $a): ?>
                        <div class="activity-item" id="activity-item-<?= (int)$a['id'] ?>">
                            <div>
                                <strong><?= e($a['action']) ?></strong>
                                <?php if ($a['target_label']): ?> &mdash; <?= e($a['target_label']) ?><?php endif; ?>
                                <?php if ($a['previous_value'] && $a['new_value']): ?>
                                    <div class="text-muted" style="font-size:12px;"><?= e($a['previous_value']) ?> &rarr; <?= e($a['new_value']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div class="activity-item-time"><?= e(formatDateTime($a['created_at'])) ?></div>
                                <button class="btn btn-sm" onclick="clearOneActivity(<?= (int)$a['id'] ?>)" title="Remove from this view only">Clear</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p class="small-note" style="margin-top:8px;">"Clear" only removes items from this personal summary and never deletes official audit records. Event Hosts do not have access to the full system audit log.</p>
        </div>
    </div>
</main>

<div class="modal-overlay" id="editProfileModal">
    <div class="modal-box">
        <div class="modal-header"><h3>Edit Profile</h3><button class="modal-close" data-close-modal="editProfileModal">&times;</button></div>
        <div class="modal-body">
            <div class="form-group"><label>Full Name</label><input type="text" id="epFullName" value="<?= e($user['full_name']) ?>"></div>
            <div class="form-group"><label>Email</label><input type="text" id="epEmail" value="<?= e($user['email'] ?? '') ?>"></div>
            <div id="editProfileMsg"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" data-close-modal="editProfileModal">Cancel</button>
            <button class="btn btn-primary" id="editProfileSaveBtn" onclick="saveEditProfile()">Save Changes</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="changePasswordModal">
    <div class="modal-box">
        <div class="modal-header"><h3>Change Password</h3><button class="modal-close" data-close-modal="changePasswordModal">&times;</button></div>
        <div class="modal-body">
            <div class="form-group"><label>Current Password</label><input type="password" id="cpCurrent"></div>
            <div class="form-group"><label>New Password</label><input type="password" id="cpNew" placeholder="Minimum 6 characters"></div>
            <div class="form-group"><label>Confirm New Password</label><input type="password" id="cpConfirm"></div>
            <div id="changePasswordMsg"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" data-close-modal="changePasswordModal">Cancel</button>
            <button class="btn btn-primary" id="changePasswordSaveBtn" onclick="saveChangePassword()">Update Password</button>
        </div>
    </div>
</div>

<!-- Avatar Cropper Modal -->
<div class="modal-overlay" id="avatarCropperModal">
    <div class="modal-box">
        <div class="modal-header"><h3>Position Your Photo</h3><button class="modal-close" data-close-modal="avatarCropperModal">&times;</button></div>
        <div class="modal-body">
            <div class="cropper-viewport" id="cropperViewport">
                <img id="cropperImg" alt="">
                <div class="cropper-circle-guide"></div>
            </div>
            <div class="cropper-zoom-row">
                <button type="button" class="cropper-zoom-btn" id="cropperZoomOut">&minus;</button>
                <input type="range" id="cropperZoomRange" class="cropper-zoom-slider" min="100" max="400" value="100">
                <button type="button" class="cropper-zoom-btn" id="cropperZoomIn">+</button>
            </div>
            <p class="small-note" style="text-align:center; margin-top:10px;">Drag the image to reposition. Use the buttons or slider to zoom.</p>
            <div id="cropperMsg"></div>
        </div>
        <div class="modal-footer">
            <button class="btn" data-close-modal="avatarCropperModal">Cancel</button>
            <button class="btn btn-primary" id="cropperSaveBtn">Save Photo</button>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script src="/UBMSConnect/assets/js/avatar-cropper.js"></script>' . <<<'JS'
<script>
function openEditProfile() {
    document.getElementById('editProfileMsg').innerHTML = '';
    openModal('editProfileModal');
}

function saveEditProfile() {
    const btn = document.getElementById('editProfileSaveBtn');
    const msg = document.getElementById('editProfileMsg');
    if (btn.disabled) return;
    btn.disabled = true; btn.textContent = 'Saving...';

    const params = new URLSearchParams({
        full_name: document.getElementById('epFullName').value,
        email: document.getElementById('epEmail').value
    });

    postJSON('/UBMSConnect/actions/update_profile.php', params.toString())
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message) + ' Reloading...</div>';
                setTimeout(function () { location.reload(); }, 700);
            } else {
                msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message) + '</div>';
            }
        })
        .catch(function (err) { msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message) + '</div>'; })
        .then(function () { btn.disabled = false; btn.textContent = 'Save Changes'; });
}

function openChangePassword() {
    document.getElementById('cpCurrent').value = '';
    document.getElementById('cpNew').value = '';
    document.getElementById('cpConfirm').value = '';
    document.getElementById('changePasswordMsg').innerHTML = '';
    openModal('changePasswordModal');
}

function saveChangePassword() {
    const btn = document.getElementById('changePasswordSaveBtn');
    const msg = document.getElementById('changePasswordMsg');
    const newPw = document.getElementById('cpNew').value;
    const confirmPw = document.getElementById('cpConfirm').value;

    if (newPw.length < 6) {
        msg.innerHTML = '<div class="alert alert-error">New password must be at least 6 characters.</div>';
        return;
    }
    if (newPw !== confirmPw) {
        msg.innerHTML = '<div class="alert alert-error">New password and confirmation do not match.</div>';
        return;
    }

    if (btn.disabled) return;
    btn.disabled = true; btn.textContent = 'Updating...';

    const params = new URLSearchParams({
        full_name: document.getElementById('epFullName') ? document.getElementById('epFullName').value : '',
        current_password: document.getElementById('cpCurrent').value,
        new_password: newPw
    });

    postJSON('/UBMSConnect/actions/update_profile.php', params.toString())
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message) + ' Reloading...</div>';
                setTimeout(function () { location.reload(); }, 900);
            } else {
                msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message) + '</div>';
            }
        })
        .catch(function (err) { msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message) + '</div>'; })
        .then(function () { btn.disabled = false; btn.textContent = 'Update Password'; });
}

function uploadPhoto(input) {
    if (!input.files || !input.files[0]) return;
    openAvatarCropper(input.files[0], '/UBMSConnect/actions/upload_profile_picture.php', function () {
        setTimeout(function () { location.reload(); }, 500);
    });
    input.value = ''; // allow selecting the same file again later
}

function removePhoto() {
    if (!confirm('Remove your profile picture? You will return to the default initials avatar.')) return;
    const msg = document.getElementById('photoMsg');
    msg.innerHTML = '<span class="text-muted">Removing...</span>';

    postJSON('/UBMSConnect/actions/remove_profile_picture.php', '')
        .then(function (data) {
            if (data.success) {
                msg.innerHTML = '<span style="color:var(--green);">' + escapeHtml(data.message) + '</span>';
                setTimeout(function () { location.reload(); }, 500);
            } else {
                msg.innerHTML = '<span style="color:var(--red);">' + escapeHtml(data.message || 'Failed to remove.') + '</span>';
            }
        })
        .catch(function (err) { msg.innerHTML = '<span style="color:var(--red);">' + escapeHtml(err.message || 'Something went wrong.') + '</span>'; });
}

function clearOneActivity(id) {
    postJSON('/UBMSConnect/actions/dismiss_activity.php', 'action=dismiss_one&audit_log_id=' + encodeURIComponent(id))
        .then(function (data) {
            if (data.success) {
                const row = document.getElementById('activity-item-' + id);
                if (row) row.remove();
                const list = document.getElementById('recentActivityList');
                if (list && !list.querySelector('.activity-item')) {
                    list.innerHTML = '<div class="empty-state">No recent activity to show.</div>';
                }
            } else {
                alert(data.message || 'Failed to clear that item.');
            }
        })
        .catch(function (err) { alert(err.message || 'Something went wrong.'); });
}

function clearAllActivity() {
    if (!confirm('Clear all recent activity from your profile?\n\nThis will remove these items from your Recent Activity view. Your official audit records will remain available.')) return;

    postJSON('/UBMSConnect/actions/dismiss_activity.php', 'action=dismiss_all')
        .then(function (data) {
            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Failed to clear your recent activity.');
            }
        })
        .catch(function (err) { alert(err.message || 'Something went wrong.'); });
}
</script>
JS;
include __DIR__ . '/../includes/footer.php';
?>
