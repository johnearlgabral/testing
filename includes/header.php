<?php
/**
 * Shared header. Expects $pageTitle to be set by the including page.
 * Must be included AFTER includes/auth.php has run requireLogin()/requireRole().
 */
$pageTitle = $pageTitle ?? 'UBMSConnect';
$__profileHref = isSuperAdmin()
    ? '/UBMSConnect/superadmin/profile.php'
    : (isStaff() ? '/UBMSConnect/staff/profile.php' : '/UBMSConnect/host/profile.php');

/* Presentation only: fetch the logged-in user's profile picture for the
   topbar avatar (falls back to initials when absent). Guarded so a DB
   hiccup can never take down the page - the header still renders. */
$__topbarAvatar = null;
if (function_exists('currentUserId') && currentUserId() && isset($pdo) && $pdo instanceof PDO) {
    try {
        $__stmt = $pdo->prepare('SELECT profile_picture FROM users WHERE id = ?');
        $__stmt->execute([currentUserId()]);
        $__pic = $__stmt->fetchColumn();
        if (!empty($__pic)) {
            $__topbarAvatar = '/UBMSConnect/assets/uploads/profile/' . rawurlencode(basename($__pic));
        }
    } catch (Exception $__ex) {
        $__topbarAvatar = null; // degrade to the initials avatar
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | UBMSConnect</title>
<?php // ?v= makes the browser fetch the current stylesheet after a CSS change instead of a stale cached copy. ?>
<link rel="stylesheet" href="/UBMSConnect/assets/css/style.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<script>
// Keep the sidebar expanded from a navigation click before the next page paints.
(function () {
    try {
        var navigatingFromSidebar = sessionStorage.getItem('ubms_sidebar_navigation') === '1';
        sessionStorage.removeItem('ubms_sidebar_navigation');
        if (navigatingFromSidebar
            && window.matchMedia
            && window.matchMedia('(hover: hover) and (pointer: fine)').matches
            && window.innerWidth >= 861) {
            document.documentElement.setAttribute('data-sidebar-navigation', 'open');
        }
    } catch (err) { /* storage blocked */ }
})();
</script>
<script>
// Applied before paint to avoid a flash of the wrong theme.
(function () {
    var pref = localStorage.getItem('ubms_theme') || 'system';
    var resolved = pref;
    if (pref === 'system') {
        resolved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    document.documentElement.setAttribute('data-theme', resolved);
})();
</script>
</head>
<body>
<div class="app-shell">

<header class="topbar">
    <div class="topbar-left">
        <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">&#9776;</button>
        <span class="brand">UBMSConnect</span>
        <span class="brand-sub">UBMS Activity Monitoring</span>
    </div>
    <div class="topbar-right">
        <span class="live-clock" id="liveClock" aria-live="off"></span>
        <div class="notif-bell-wrap">
            <button class="notif-bell" id="notifBellBtn" aria-label="Notifications">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M13.73 21a2 2 0 0 1-3.46 0" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
            </button>
            <div class="notif-panel" id="notifPanel">
                <div class="notif-panel-header">
                    <strong>Notifications</strong>
                    <a href="/UBMSConnect/notifications.php">View all</a>
                </div>
                <div id="notifPanelList" class="notif-panel-list">
                    <div class="empty-state" style="padding:16px;">Loading...</div>
                </div>
            </div>
        </div>
        <a class="user-chip profile-chip" href="<?= e($__profileHref) ?>" aria-label="View your profile">
            <span class="topbar-avatar"><?php if ($__topbarAvatar): ?><img src="<?= e($__topbarAvatar) ?>" alt="Profile picture"><?php else: ?><?= e(nameInitials(currentUserName())) ?><?php endif; ?></span>
            <span class="user-chip-text">
                <span class="user-name"><?= e(currentUserName()) ?></span>
                <span class="user-role-tag"><?= e(roleLabel(currentUserRole())) ?></span>
            </span>
            <svg class="profile-chip-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
    </div>
</header>

<div class="app-body">
