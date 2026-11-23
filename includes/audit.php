<?php
/**
 * includes/audit.php
 * Central helper for writing to the system-wide audit_logs table.
 *
 * This is separate from (and in addition to) the existing
 * activity_status_logs table, which staff/logs.php already uses for
 * the activity-specific status history. audit_logs is the broader,
 * Super-Admin-facing trail that also covers user/account management.
 *
 * Include this AFTER includes/auth.php (needs $pdo + currentUserId()/currentUserName()).
 */

/**
 * @param string      $action        Short description, e.g. "Approved Staff Registration"
 * @param string|null $targetType    e.g. 'user', 'activity', 'venue', 'utility'
 * @param int|null    $targetId
 * @param string|null $targetLabel   Human-readable label for the target (username, activity name, etc.)
 * @param string|null $previousValue
 * @param string|null $newValue
 */
function logAudit($action, $targetType = null, $targetId = null, $targetLabel = null, $previousValue = null, $newValue = null) {
    global $pdo;

    $actorId   = currentUserId();
    $actorName = currentUserName() ?: 'System';

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (actor_id, actor_name, action, target_type, target_id, target_label, previous_value, new_value, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$actorId, $actorName, $action, $targetType, $targetId, $targetLabel, $previousValue, $newValue]);
    } catch (Exception $e) {
        // Audit logging must never break the primary action it accompanies.
        // Silently ignore failures (e.g. audit_logs table missing on an
        // un-migrated database) rather than fatally erroring the request.
    }
}
