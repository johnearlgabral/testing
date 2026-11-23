<?php
/**
 * Shared sidebar. Navigation differs by role.
 * Expects $activeNav to be set by the including page (e.g. 'dashboard').
 */
$activeNav = $activeNav ?? '';

if (isSuperAdmin()) {
    $base = '/UBMSConnect/superadmin';
} elseif (isStaff()) {
    $base = '/UBMSConnect/staff';
} else {
    $base = '/UBMSConnect/host';
}

function navItem($href, $label, $key, $activeNav) {
    $active = ($key === $activeNav) ? ' active' : '';
    /* Presentation only: a small inline SVG icon per nav key (feather-style,
       stroke: currentColor) so the navy sidebar matches the reference design.
       Keys without an entry simply render without an icon. */
    static $icons = [
        'dashboard'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'users'          => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'pending'        => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15.5 13.8"/>',
        'audit_logs'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        'conflicts'      => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        'reports'        => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        'school_years'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'reference_data' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'notifications'  => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'settings'       => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'profile'        => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'calendar'       => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'activities'     => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'venues'         => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'utilities'      => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'maintenance'    => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'logs'           => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
    ];
    $icon = '';
    if (isset($icons[$key])) {
        $icon = '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[$key] . '</svg>';
    }
    echo '<a href="' . e($href) . '" class="nav-item' . $active . '" data-label="' . e($label) . '">' . $icon . '<span class="nav-label">' . e($label) . '</span></a>';
}
?>
<aside class="sidebar" id="sidebar">

    <a class="sidebar-brand" href="/UBMSConnect/index.php">
        <img src="/UBMSConnect/assets/img/lcup-logo.png" alt="LCUP seal">
        <span class="sidebar-brand-copy">UBMSConnect<small>LA CONSOLACION UNIVERSITY</small></span>
    </a>
    <nav class="sidebar-nav">
        <?php if (isSuperAdmin()): ?>
            <?php navItem("$base/dashboard.php", 'Dashboard', 'dashboard', $activeNav); ?>
            <?php navItem("$base/users.php", 'User Management', 'users', $activeNav); ?>
            <?php navItem("$base/pending.php", 'Pending Registrations', 'pending', $activeNav); ?>
            <?php /* The four "* Overview" pages were Staff-only duplicates; Super Admin
                     now oversees accounts, reference data and system records instead. */ ?>
            <?php navItem("$base/audit_logs.php", 'Audit Logs', 'audit_logs', $activeNav); ?>
            <?php navItem('/UBMSConnect/conflicts.php', 'Schedule Conflicts', 'conflicts', $activeNav); ?>
            <?php navItem('/UBMSConnect/reports.php', 'Reports', 'reports', $activeNav); ?>
            <?php navItem("$base/school_years.php", 'School Years', 'school_years', $activeNav); ?>
            <?php navItem("$base/reference_data.php", 'Institutional Records', 'reference_data', $activeNav); ?>
            <?php navItem('/UBMSConnect/notifications.php', 'Notifications', 'notifications', $activeNav); ?>
            <?php navItem('/UBMSConnect/settings.php', 'Settings', 'settings', $activeNav); ?>
            <?php navItem("$base/profile.php", 'Profile', 'profile', $activeNav); ?>
        <?php else: ?>
            <?php navItem("$base/dashboard.php", 'Dashboard', 'dashboard', $activeNav); ?>
            <?php navItem("$base/calendar.php", 'Calendar', 'calendar', $activeNav); ?>
            <?php navItem("$base/activities.php", 'Activities', 'activities', $activeNav); ?>
            <?php navItem("$base/venues.php", 'Venues', 'venues', $activeNav); ?>
            <?php navItem("$base/utilities.php", 'Utilities', 'utilities', $activeNav); ?>
            <?php if (isStaff()): ?>
                <?php navItem("$base/maintenance.php", 'Maintenance', 'maintenance', $activeNav); ?>
                <?php navItem("$base/logs.php", 'Activity Logs', 'logs', $activeNav); ?>
                <?php navItem('/UBMSConnect/conflicts.php', 'Schedule Conflicts', 'conflicts', $activeNav); ?>
                <?php navItem('/UBMSConnect/reports.php', 'Reports', 'reports', $activeNav); ?>
            <?php endif; ?>
            <?php navItem('/UBMSConnect/notifications.php', 'Notifications', 'notifications', $activeNav); ?>
            <?php navItem('/UBMSConnect/settings.php', 'Settings', 'settings', $activeNav); ?>
            <?php navItem("$base/profile.php", 'Profile', 'profile', $activeNav); ?>
        <?php endif; ?>
        <div class="nav-divider"></div>
        <a href="/UBMSConnect/logout.php" class="nav-item nav-item-logout" data-label="Logout"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg><span class="nav-label">Logout</span></a>
    </nav>
</aside>
<div class="modal-overlay logout-confirm-overlay" id="logoutConfirmModal" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" aria-describedby="logoutConfirmMessage">
    <div class="modal-box logout-confirm-box">
        <div class="login-icon logout-confirm-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M12 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/></svg>
        </div>
        <div class="logout-confirm-brand">
            <h2 id="logoutConfirmTitle">Sign out?</h2>
            <span>UBMSCONNECT ACCOUNT</span>
        </div>
        <p id="logoutConfirmMessage">Are you sure you want to log out of your account?</p>
        <div class="logout-confirm-actions">
            <button type="button" class="btn btn-primary logout-confirm-btn" id="confirmLogoutBtn">Yes, log out</button>
            <button type="button" class="btn logout-cancel-btn" data-close-modal="logoutConfirmModal">Cancel</button>
        </div>
    </div>
</div>
