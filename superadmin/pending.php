<?php
require_once __DIR__ . '/../includes/auth.php';
requireSuperAdmin();

$pageTitle = 'Pending Registrations';
$activeNav = 'pending';

$pending = $pdo->query(
    "SELECT u.id, u.full_name, u.username, u.email, u.university_id, u.affiliation, u.office_unit, u.role, u.created_at,
            d.department_name, c.course_name, o.organization_name
     FROM users u LEFT JOIN departments d ON d.id=u.department_id LEFT JOIN courses c ON c.id=u.course_id LEFT JOIN organizations o ON o.id=u.organization_id
     WHERE u.status = 'pending' ORDER BY u.created_at ASC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Pending Registrations</h1>
            <p class="page-subtitle">Verify institutional details, then delegate the appropriate system role.</p>
        </div>
    </div>

    <div class="card">
        <?php if (empty($pending)): ?>
            <div class="empty-state">No pending registrations at this time.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr><th>Name / University ID</th><th>Student Status</th><th>Department / Course</th><th>Organization / Unit</th><th>Requested Role</th><th>Registration Date</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending as $p): ?>
                        <tr id="pending-row-<?= (int)$p['id'] ?>">
                            <td><strong><?= e($p['full_name']) ?></strong><br><span class="text-muted"><?= e(implode(' · ', array_filter([$p['university_id'], $p['email']]))) ?></span></td>
                            <td><?= $p['affiliation'] === 'student' ? 'Student' : ($p['affiliation'] === 'ubms_staff' ? 'Non-student' : '&mdash;') ?></td>
                            <td><?= e($p['department_name'] ?: 'N/A') ?><?= $p['course_name'] ? '<br><span class="text-muted">'.e($p['course_name']).'</span>' : '' ?></td>
                            <td><?= e($p['organization_name'] ?: $p['office_unit'] ?: 'N/A') ?></td>
                            <td><span class="badge <?= roleBadgeClass($p['role']) ?>"><?= e(roleLabel($p['role'])) ?></span></td>
                            <td>
                                <select id="approve-role-<?= (int)$p['id'] ?>" style="width:auto;display:inline-block;padding:6px;"><option value="host">Host / Requestor</option><option value="staff" <?= $p['role']==='staff'?'selected':'' ?>>UBMS Staff / Moderator</option></select>
                                <button class="btn btn-sm btn-success" onclick="decide(<?= (int)$p['id'] ?>, 'approve', '<?= e(addslashes($p['full_name'])) ?>')">Approve</button>
                                <button class="btn btn-sm btn-danger" onclick="decide(<?= (int)$p['id'] ?>, 'reject', '<?= e(addslashes($p['full_name'])) ?>')">Reject</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php
$extraScripts = <<<'JS'
<script>
function decide(id, decision, name) {
    const verb = decision === 'approve' ? 'approve' : 'reject';
    if (!confirm('Are you sure you want to ' + verb + ' the registration for "' + name + '"?')) return;

    const url = decision === 'approve'
        ? '/UBMSConnect/actions/approve_registration.php'
        : '/UBMSConnect/actions/reject_registration.php';

    const payload = 'id=' + encodeURIComponent(id) + (decision === 'approve' ? '&role=' + encodeURIComponent(document.getElementById('approve-role-' + id).value) : '');
    postJSON(url, payload)
        .then(function (data) {
            if (data.success) {
                document.getElementById('pending-row-' + id).remove();
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
