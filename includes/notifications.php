<?php
/**
 * includes/notifications.php
 * Central helper for the simple in-system notification feature.
 * Include AFTER includes/auth.php (needs $pdo).
 *
 * Categories map directly to the notification_preferences columns:
 *   'activity'    -> activity_updates
 *   'maintenance' -> maintenance_updates
 *   'account'     -> account_updates
 *   'admin'       -> admin_updates
 *
 * If a user has explicitly turned a category OFF, notifications in that
 * category are simply not created for them (not just hidden), per the
 * requirement "do not generate/display that category for the user".
 * A user with no notification_preferences row yet is treated as having
 * every category ON (the table's own column defaults also reflect this).
 */

/** Returns the preference row for a user, defaulting every column to 1 (on) if no row exists yet. */
function getNotificationPreferences($userId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) return $row;
    return [
        'user_id' => $userId,
        'activity_updates' => 1,
        'maintenance_updates' => 1,
        'account_updates' => 1,
        'admin_updates' => 1,
    ];
}

/** Category -> preference column name */
function notificationPrefColumn($category) {
    switch ($category) {
        case 'activity':    return 'activity_updates';
        case 'maintenance': return 'maintenance_updates';
        case 'account':     return 'account_updates';
        case 'admin':       return 'admin_updates';
        default:             return null;
    }
}

/**
 * Create a notification for a single user, honoring their preference for
 * that category. Fails silently (logged, not fatal) if the notifications
 * table doesn't exist yet on an un-migrated database - notifications are
 * a convenience feature and must never break the action that triggered them.
 */
function notifyUser($userId, $category, $message, $link = null) {
    global $pdo;
    if (!$userId) return;

    try {
        $prefs = getNotificationPreferences($userId);
        $col = notificationPrefColumn($category);
        if ($col && isset($prefs[$col]) && (int) $prefs[$col] === 0) {
            return; // this category is turned off for this user - do not generate it
        }

        $stmt = $pdo->prepare(
            'INSERT INTO notifications (user_id, category, message, link, created_at) VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$userId, $category, $message, $link]);
    } catch (Exception $e) {
        error_log('[UBMSConnect] notifyUser() failed: ' . $e->getMessage());
    }
}

/**
 * Create the same notification for every ACTIVE user of a given role
 * (e.g. broadcast "new maintenance record added" to all staff), optionally
 * excluding the user who triggered the action so they don't notify themselves.
 */
function notifyRole($role, $category, $message, $link = null, $excludeUserId = null) {
    global $pdo;
    try {
        $sql = "SELECT id FROM users WHERE role = ? AND status = 'active'";
        $params = [$role];
        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            notifyUser($uid, $category, $message, $link);
        }
    } catch (Exception $e) {
        error_log('[UBMSConnect] notifyRole() failed: ' . $e->getMessage());
    }
}
