<?php
// CSRF token helpers (session token used by the correction-request submit).
require_once __DIR__ . '/password_recovery.php';

function renderAffiliationProfile($user) {
    // `affiliation` stores only the student status now: 'student' = student,
    // 'ubms_staff' = non-student (a legacy enum value kept from when it also
    // doubled as the role). The system role itself is shown separately on the
    // profile via roleLabel(), so nothing here derives a role from it.
    $affiliation   = $user['affiliation'] ?? null;
    $studentStatus = $affiliation === 'student' ? 'Student' : ($affiliation === 'ubms_staff' ? 'Non-student' : 'Not set');
    ?>
    <div class="card">
        <div class="card-title" style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
            <span>Academic / Organizational Information</span>
            <button type="button" class="btn btn-sm" onclick="openCorrectionModal()">Request Correction</button>
        </div>
        <?php // Only shown when the account actually has one - registration no longer asks for a University ID. ?>
        <?php if (!empty($user['university_id'])): ?>
            <div class="detail-row"><div class="dr-label">University ID</div><div class="dr-value"><?= e($user['university_id']) ?></div></div>
        <?php endif; ?>
        <div class="detail-row"><div class="dr-label">Student Status</div><div class="dr-value"><?= e($studentStatus) ?></div></div>
        <div class="detail-row"><div class="dr-label">Department</div><div class="dr-value"><?= e($user['department_name'] ?: 'Not set') ?></div></div>
        <?php if ($user['affiliation'] === 'student'): ?>
            <div class="detail-row"><div class="dr-label">Course</div><div class="dr-value"><?= e($user['course_name'] ?: 'Not set') ?></div></div>
            <div class="detail-row"><div class="dr-label">Organization</div><div class="dr-value"><?= e($user['organization_name'] ?: 'None') ?></div></div>
        <?php elseif ($user['affiliation'] === 'ubms_staff'): ?>
            <div class="detail-row"><div class="dr-label">UBMS Office / Unit</div><div class="dr-value"><?= e($user['office_unit'] ?: 'Not set') ?></div></div>
        <?php endif; ?>
        <p class="small-note" style="margin-top:12px;">Identity details are verified during registration. If something is incorrect, use <strong>Request Correction</strong> and a Super Admin will review it.</p>
    </div>

    <?php // Correction-request modal: files a request for Super Admin review.
          // It NEVER edits the profile - submission only creates a queue item. ?>
    <div class="modal-overlay" id="correctionModal">
        <div class="modal-box">
            <div class="modal-header">
                <h3>Request Correction</h3>
                <button class="modal-close" data-close-modal="correctionModal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="text-muted">Tell us which detail is wrong and what it should be instead. Your saved information will not change until a Super Admin reviews and approves the request.</p>
                <div class="form-group">
                    <label for="corrField">Which information needs correction?</label>
                    <select id="corrField">
                        <option value="department">Department</option>
                        <option value="course">Course</option>
                        <option value="organization">Organization</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="corrValue">Correct value / explanation</label>
                    <textarea id="corrValue" rows="3" maxlength="150" placeholder="e.g. BS Information Technology, or a short explanation of the change"></textarea>
                    <div class="small-note">150 characters maximum. Include the exact official name when you know it.</div>
                </div>
                <div id="correctionModalMsg"></div>
            </div>
            <div class="modal-footer">
                <button class="btn" data-close-modal="correctionModal">Cancel</button>
                <button class="btn btn-primary" id="corrSubmitBtn" onclick="submitCorrectionRequest()">Submit Request</button>
            </div>
        </div>
    </div>
    <script>
    window.CORRECTION_CSRF = <?= json_encode(recoveryCsrfToken()) ?>;

    function openCorrectionModal() {
        document.getElementById('corrValue').value = '';
        document.getElementById('correctionModalMsg').innerHTML = '';
        const btn = document.getElementById('corrSubmitBtn');
        btn.disabled = false;
        btn.textContent = 'Submit Request';
        openModal('correctionModal');
    }

    function submitCorrectionRequest() {
        const msg = document.getElementById('correctionModalMsg');
        const btn = document.getElementById('corrSubmitBtn');
        const value = document.getElementById('corrValue').value.trim();

        if (!value) {
            msg.innerHTML = '<div class="alert alert-error">Please enter the correct value or a short explanation.</div>';
            return;
        }

        btn.disabled = true; // practical duplicate-submission guard on the client
        btn.textContent = 'Submitting...';
        msg.innerHTML = '';

        const params = new URLSearchParams({
            field: document.getElementById('corrField').value,
            requested_value: value,
            csrf: window.CORRECTION_CSRF || ''
        });

        postJSON('/UBMSConnect/actions/request_correction.php', params.toString())
            .then(function (data) {
                if (data.success) {
                    msg.innerHTML = '<div class="alert alert-success">' + escapeHtml(data.message || 'Request submitted.') + '</div>';
                    btn.textContent = 'Submitted';
                } else {
                    msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(data.message || 'Could not submit the request.') + '</div>';
                    btn.disabled = false;
                    btn.textContent = 'Submit Request';
                }
            })
            .catch(function (err) {
                msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message || 'Something went wrong. Please try again.') + '</div>';
                btn.disabled = false;
                btn.textContent = 'Submit Request';
            });
    }
    </script>
    <?php
}
