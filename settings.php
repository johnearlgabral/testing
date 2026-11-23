<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$pageTitle = 'Settings';
$activeNav = 'settings';

$dashStmt = $pdo->prepare('SELECT * FROM dashboard_preferences WHERE user_id = ?');
$dashStmt->execute([currentUserId()]);
$dashPrefs = $dashStmt->fetch() ?: ['show_stats' => 1, 'show_venues' => 1, 'show_utilities' => 1, 'show_maintenance' => 1, 'show_recent' => 1, 'calendar_default_view' => 'month'];

$notifStmt = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = ?');
$notifStmt->execute([currentUserId()]);
$notifPrefs = $notifStmt->fetch() ?: ['activity_updates' => 1, 'maintenance_updates' => 1, 'account_updates' => 1, 'admin_updates' => 1];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1>Settings</h1>
            <p class="page-subtitle">Manage your appearance, dashboard, and notification preferences. Account and security settings have moved to your <a href="/UBMSConnect/<?= isSuperAdmin() ? 'superadmin' : (isStaff() ? 'staff' : 'host') ?>/profile.php">Profile</a> page.</p>
        </div>
    </div>

    <div class="stack">
        <!-- Appearance -->
        <div class="card">
            <div class="card-title">Appearance</div>
            <div class="theme-options" id="themeOptions">
                <div class="theme-option" data-theme-value="light" onclick="setTheme('light')">
                    <div class="theme-option-icon">&#9728;</div>
                    <div>Light Mode</div>
                </div>
                <div class="theme-option" data-theme-value="dark" onclick="setTheme('dark')">
                    <div class="theme-option-icon">&#9790;</div>
                    <div>Dark Mode</div>
                </div>
                <div class="theme-option" data-theme-value="system" onclick="setTheme('system')">
                    <div class="theme-option-icon">&#9881;</div>
                    <div>System Default</div>
                </div>
            </div>
        </div>

        <!-- Dashboard Preferences -->
        <div class="card">
            <div class="card-title">Dashboard Preferences</div>
            <div id="dashMsg"></div>
            <div class="toggle-row">
                <span>Show activity statistics</span>
                <label class="switch"><input type="checkbox" id="prefShowStats" <?= $dashPrefs['show_stats'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
            </div>
            <div class="toggle-row">
                <span>Show venue status</span>
                <label class="switch"><input type="checkbox" id="prefShowVenues" <?= $dashPrefs['show_venues'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
            </div>
            <div class="toggle-row">
                <span>Show utility status</span>
                <label class="switch"><input type="checkbox" id="prefShowUtilities" <?= $dashPrefs['show_utilities'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
            </div>
            <?php if (isStaff()): ?>
            <div class="toggle-row">
                <span>Show recent status changes</span>
                <label class="switch"><input type="checkbox" id="prefShowRecent" <?= $dashPrefs['show_recent'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
            </div>
            <?php endif; ?>
            <button class="btn btn-primary" style="margin-top:10px;" onclick="saveDashboardPrefs()">Save Dashboard Preferences</button>
        </div>

        <!-- Notification Preferences -->
        <div class="card">
            <div class="card-title">Notification Preferences</div>
            <div id="notifPrefMsg"></div>
            <div class="toggle-row">
                <span>Activity status changes</span>
                <label class="switch"><input type="checkbox" id="prefActivity" <?= $notifPrefs['activity_updates'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
            </div>
            <div class="toggle-row">
                <span>Maintenance updates</span>
                <label class="switch"><input type="checkbox" id="prefMaintenance" <?= $notifPrefs['maintenance_updates'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
            </div>
            <div class="toggle-row">
                <span>Account updates</span>
                <label class="switch"><input type="checkbox" id="prefAccount" <?= $notifPrefs['account_updates'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
            </div>
            <div class="toggle-row">
                <span>Administrative updates</span>
                <label class="switch"><input type="checkbox" id="prefAdmin" <?= $notifPrefs['admin_updates'] ? 'checked' : '' ?>><span class="switch-slider"></span></label>
            </div>
            <button class="btn btn-primary" style="margin-top:10px;" onclick="saveNotificationPrefs()">Save Notification Preferences</button>
        </div>
    </div>
</main>

<?php
$extraScripts = <<<'JS'
<script>
function applySelectedThemeUI() {
    const current = localStorage.getItem('ubms_theme') || 'system';
    document.querySelectorAll('.theme-option').forEach(function (el) {
        el.classList.toggle('selected', el.getAttribute('data-theme-value') === current);
    });
}

function setTheme(value) {
    localStorage.setItem('ubms_theme', value);
    let resolved = value;
    if (value === 'system') {
        resolved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', resolved);
    applySelectedThemeUI();
}

document.addEventListener('DOMContentLoaded', applySelectedThemeUI);

function saveDashboardPrefs() {
    const msg = document.getElementById('dashMsg');
    const showRecent = document.getElementById('prefShowRecent');
    const params = new URLSearchParams({
        type: 'dashboard',
        show_stats: document.getElementById('prefShowStats').checked ? '1' : '0',
        show_venues: document.getElementById('prefShowVenues').checked ? '1' : '0',
        show_utilities: document.getElementById('prefShowUtilities').checked ? '1' : '0',
        show_maintenance: '1',
        show_recent: showRecent ? (showRecent.checked ? '1' : '0') : '1'
    });
    msg.innerHTML = '<span class="text-muted">Saving...</span>';
    postJSON('/UBMSConnect/actions/update_preferences.php', params.toString())
        .then(function (data) {
            msg.innerHTML = data.success
                ? '<div class="alert alert-success">' + escapeHtml(data.message) + '</div>'
                : '<div class="alert alert-error">' + escapeHtml(data.message) + '</div>';
        })
        .catch(function (err) { msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message) + '</div>'; });
}

function saveNotificationPrefs() {
    const msg = document.getElementById('notifPrefMsg');
    const params = new URLSearchParams({
        type: 'notifications',
        activity_updates: document.getElementById('prefActivity').checked ? '1' : '0',
        maintenance_updates: document.getElementById('prefMaintenance').checked ? '1' : '0',
        account_updates: document.getElementById('prefAccount').checked ? '1' : '0',
        admin_updates: document.getElementById('prefAdmin').checked ? '1' : '0'
    });
    msg.innerHTML = '<span class="text-muted">Saving...</span>';
    postJSON('/UBMSConnect/actions/update_preferences.php', params.toString())
        .then(function (data) {
            msg.innerHTML = data.success
                ? '<div class="alert alert-success">' + escapeHtml(data.message) + '</div>'
                : '<div class="alert alert-error">' + escapeHtml(data.message) + '</div>';
        })
        .catch(function (err) { msg.innerHTML = '<div class="alert alert-error">' + escapeHtml(err.message) + '</div>'; });
}
</script>
JS;
include __DIR__ . '/includes/footer.php';
?>
