<?php
/**
 * UBMSConnect - Shared helper functions
 */

/** Escape output for safe HTML display */
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Format a date like "August 29, 2026" */
function formatDate($date) {
    if (!$date) return '';
    return date('F j, Y', strtotime($date));
}

/** Format a time like "9:00 AM" */
function formatTime($time) {
    if (!$time) return '';
    return date('g:i A', strtotime($time));
}

/** Format a full datetime like "August 29, 2026 10:42 AM" */
function formatDateTime($datetime) {
    if (!$datetime) return '';
    return date('F j, Y g:i A', strtotime($datetime));
}

/** Return a Bootstrap-ish CSS class suffix for an activity status */
function statusBadgeClass($status) {
    switch ($status) {
        case 'Pending':     return 'badge-pending';
        case 'Approved':    return 'badge-approved';
        case 'Rejected':    return 'badge-rejected';
        case 'Maintenance': return 'badge-maintenance';
        case 'Completed':   return 'badge-completed';
        default:            return 'badge-default';
    }
}

/** Return a CSS class suffix for a venue status */
function venueBadgeClass($status) {
    switch ($status) {
        case 'Available':          return 'badge-approved';
        case 'In Use':             return 'badge-pending';
        case 'Under Maintenance':  return 'badge-maintenance';
        case 'Unavailable':        return 'badge-rejected';
        default:                   return 'badge-default';
    }
}

/**
 * Render a safe photo thumbnail, or a consistent empty placeholder.
 * Shared by venues (default) and utilities (pass 'utilities' as $uploadDir);
 * both use the same CSS classes so the two tables look identical.
 */
function venueImageHtml($imagePath, $venueName, $uploadDir = 'venues') {
    if (!empty($imagePath)) {
        $url = '/UBMSConnect/assets/uploads/' . $uploadDir . '/' . rawurlencode(basename($imagePath));
        return '<img class="venue-thumbnail" src="' . e($url) . '" alt="Photo of ' . e($venueName) . '">';
    }
    return '<div class="venue-thumbnail venue-thumbnail-placeholder" role="img" aria-label="No photo yet for '
        . e($venueName) . '"><span>No photo yet</span></div>';
}

/** Return a CSS class suffix for a utility status */
function utilityBadgeClass($status) {
    switch ($status) {
        case 'Operational':  return 'badge-approved';
        case 'Maintenance':  return 'badge-maintenance';
        case 'Unavailable':  return 'badge-rejected';
        default:             return 'badge-default';
    }
}

/** Valid activity status transitions/values enforced server-side too */
function validActivityStatuses() {
    return ['Pending', 'Approved', 'Rejected', 'Maintenance', 'Completed'];
}

function validVenueStatuses() {
    return ['Available', 'In Use', 'Under Maintenance', 'Unavailable'];
}

function validUtilityStatuses() {
    return ['Operational', 'Maintenance', 'Unavailable'];
}

/** Redirect helper */
function redirect($url) {
    header("Location: $url");
    exit;
}

/* =========================================================
 * Super Admin / role & account-status helpers
 * (added for the Super Admin / registration / audit-log feature)
 * ========================================================= */

/** All roles the system recognizes. Super Admin is NEVER selectable at public registration. */
function validRoles() {
    return ['super_admin', 'staff', 'host'];
}

/** Roles a member of the public may choose on the registration form. */
function publicRegistrationRoles() {
    return ['staff', 'host'];
}

function validAccountStatuses() {
    return ['active', 'pending', 'inactive', 'rejected'];
}

/** Human-friendly label for a role value */
function roleLabel($role) {
    switch ($role) {
        case 'super_admin': return 'Super Admin';
        case 'staff':        return 'UBMS Staff';
        case 'host':         return 'Event Host';
        default:              return e($role);
    }
}

/** Badge CSS class for an account status */
function accountStatusBadgeClass($status) {
    switch ($status) {
        case 'active':   return 'badge-approved';
        case 'pending':  return 'badge-pending';
        case 'inactive': return 'badge-default';
        case 'rejected': return 'badge-rejected';
        default:          return 'badge-default';
    }
}

/** Badge CSS class for a role, used in the User Management table */
function roleBadgeClass($role) {
    switch ($role) {
        case 'super_admin': return 'badge-maintenance';
        case 'staff':        return 'badge-approved';
        case 'host':          return 'badge-pending';
        default:              return 'badge-default';
    }
}

/** Compute up to 2 initials from a full name, for the avatar circle. */
function nameInitials($fullName) {
    $parts = preg_split('/\s+/', trim($fullName));
    $parts = array_filter($parts);
    if (empty($parts)) return '?';
    if (count($parts) === 1) return mb_strtoupper(mb_substr(reset($parts), 0, 2));
    $first = mb_substr(reset($parts), 0, 1);
    $last = mb_substr(end($parts), 0, 1);
    return mb_strtoupper($first . $last);
}

/** CSS class for the small colored status dot next to a role/status label */
function accountStatusDotClass($status) {
    switch ($status) {
        case 'active':   return 'active';
        case 'pending':  return 'pending';
        case 'inactive': return 'inactive';
        case 'rejected': return 'rejected';
        default:          return 'inactive';
    }
}

/**
 * Renders the avatar circle: the uploaded profile picture if one exists,
 * otherwise the existing initials-based avatar. Used on all three
 * Profile pages so the fallback behavior stays identical everywhere.
 */
function avatarHtml($fullName, $profilePicture) {
    if (!empty($profilePicture)) {
        $url = '/UBMSConnect/assets/uploads/profile/' . rawurlencode($profilePicture);
        return '<div class="avatar-circle avatar-has-photo"><img src="' . e($url) . '" alt="Profile picture"></div>';
    }
    return '<div class="avatar-circle">' . e(nameInitials($fullName)) . '</div>';
}
