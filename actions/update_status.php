<?php
/**
 * actions/update_status.php
 * Staff-only. Changes an activity's status and records the change in
 * activity_status_logs. Enforces role server-side - a Host manually
 * POSTing here will be rejected regardless of what the UI shows.
 */

require_once __DIR__ . '/../includes/auth.php';
// Defensive include: if includes/audit.php was not deployed correctly,
// degrade gracefully (log to PHP's error log, skip the audit entry)
// instead of fatally crashing this entire action. Audit logging is
// important but must never be able to take down a core feature.
$__auditHelper = __DIR__ . '/../includes/audit.php';
if (file_exists($__auditHelper)) {
    require_once $__auditHelper;
} elseif (!function_exists('logAudit')) {
    function logAudit($action, $targetType = null, $targetId = null, $targetLabel = null, $previousValue = null, $newValue = null) {
        error_log('[UBMSConnect] logAudit() called but includes/audit.php is missing on this deployment - action "' . $action . '" was NOT recorded to the audit trail.');
    }
}
$__notifHelper = __DIR__ . '/../includes/notifications.php';
if (file_exists($__notifHelper)) {
    require_once $__notifHelper;
} elseif (!function_exists('notifyUser')) {
    function notifyUser($userId, $category, $message, $link = null) {}
}
// Venue schedule conflict detection (moderator item 11). Core to this action:
// approving an activity is the only write path that can double-book a venue.
require_once __DIR__ . '/../includes/conflicts.php';
header('Content-Type: application/json');

requireRoleApi('staff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$activityId = (int) ($_POST['activity_id'] ?? 0);
$newStatus  = trim($_POST['new_status'] ?? '');

// Set only after the user has been shown the conflicting record(s) and has
// explicitly chosen to approve anyway.
$confirmConflict = !empty($_POST['confirm_conflict']);
$overrideReason  = trim((string) ($_POST['override_reason'] ?? ''));

if ($activityId <= 0 || !in_array($newStatus, validActivityStatuses(), true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid activity or status value.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT status, venue_id, activity_date, activity_name, host_id
           FROM activities WHERE id = ? FOR UPDATE"
    );
    $stmt->execute([$activityId]);
    $activity = $stmt->fetch();

    if (!$activity) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Activity not found.']);
        exit;
    }

    $previousStatus = $activity['status'];

    if ($previousStatus === $newStatus) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Activity is already in that status.']);
        exit;
    }

    /* ---------------------------------------------------------
     * Venue schedule conflict guard (moderator item 11)
     *
     * Approving is the only way a new double booking can enter the system.
     * If this activity overlaps one that is ALREADY Approved at the same
     * venue on the same date, stop here, show the person the conflicting
     * record, and require an explicit second confirmation.
     * --------------------------------------------------------- */
    $blockingConflicts = [];
    if ($newStatus === 'Approved') {
        $blockingConflicts = blockingConflictsFor($activityId);

        if ($blockingConflicts && !$confirmConflict) {
            $pdo->rollBack();

            foreach ($blockingConflicts as &$c) {
                $c['start_fmt']         = formatTime($c['start']);
                $c['end_fmt']           = formatTime($c['end']);
                $c['overlap_start_fmt'] = formatTime($c['overlap_start']);
                $c['overlap_end_fmt']   = formatTime($c['overlap_end']);
                $c['overlap_fmt']       = formatOverlapMinutes($c['overlap_minutes']);
                $c['severity_label']    = conflictSeverityLabel($c['severity']);
            }
            unset($c);

            $first = $blockingConflicts[0];
            echo json_encode([
                'success'     => false,
                'conflict'    => true,
                'message'     => count($blockingConflicts) === 1
                    ? 'Approving this would double-book ' . $first['venue_name'] . ': "'
                        . $first['name'] . '" (' . $first['start_fmt'] . ' - ' . $first['end_fmt']
                        . ') is already approved there on the same date.'
                    : 'Approving this would double-book ' . $first['venue_name'] . ' against '
                        . count($blockingConflicts) . ' already-approved activities on the same date.',
                'venue_name'  => $first['venue_name'],
                'activity_id' => $activityId,
                'new_status'  => $newStatus,
                'conflicts'   => $blockingConflicts,
            ]);
            exit;
        }
    }

    $update = $pdo->prepare("UPDATE activities SET status = ? WHERE id = ?");
    $update->execute([$newStatus, $activityId]);

    $log = $pdo->prepare(
        "INSERT INTO activity_status_logs (activity_id, previous_status, new_status, changed_by, changed_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $log->execute([$activityId, $previousStatus, $newStatus, currentUserId()]);

    $pdo->commit();

    // Also record in the system-wide audit log (in addition to the
    // existing activity_status_logs table used by staff/logs.php).
    // The activity's name/host were already selected above, so no re-query.
    $activityName = $activity['activity_name'] ?? ("Activity #$activityId");
    logAudit('Status Changed', 'activity', $activityId, $activityName, $previousStatus, $newStatus);

    if (!empty($activity['host_id'])) {
        notifyUser(
            $activity['host_id'], 'activity',
            "Your activity \"$activityName\" status changed to $newStatus.",
            '/UBMSConnect/host/activity_view.php?id=' . $activityId
        );
    }

    /* ---------------------------------------------------------
     * A double booking was approved anyway: record who allowed it and why,
     * then tell everyone affected. This is what makes "prevent double
     * booking" mean "never silent" rather than merely "blocked".
     * --------------------------------------------------------- */
    if ($blockingConflicts) {
        $dateLabel = formatDate($activity['activity_date']);

        foreach ($blockingConflicts as $conflict) {
            recordConflictOverride(
                $activityId, $conflict['id'], $activity['venue_id'], $activity['activity_date'],
                $conflict['overlap_start'], $conflict['overlap_end'],
                $overrideReason, currentUserId()
            );

            $notice = 'Schedule conflict: "' . $activityName . '" in '
                . $conflict['venue_name'] . ' on ' . $dateLabel
                . ' overlaps "' . $conflict['name'] . '" ('
                . formatTime($conflict['start']) . ' - ' . formatTime($conflict['end'])
                . '). The venue is now double-booked from '
                . formatTime($conflict['overlap_start']) . ' to ' . formatTime($conflict['overlap_end']) . '.';

            // The other Event Host involved...
            $hostStmt = $pdo->prepare('SELECT host_id FROM activities WHERE id = ?');
            $hostStmt->execute([$conflict['id']]);
            $otherHostId = (int) $hostStmt->fetchColumn();
            if ($otherHostId) {
                notifyUser($otherHostId, 'activity', $notice,
                    '/UBMSConnect/host/activity_view.php?id=' . $conflict['id']);
            }

            // ...and everyone who manages venues.
            notifyRole('staff', 'activity', $notice, '/UBMSConnect/conflicts.php', currentUserId());
            notifyRole('super_admin', 'admin', $notice, '/UBMSConnect/conflicts.php', currentUserId());
        }

        logAudit(
            'Conflict Overridden', 'activity', $activityId, $activityName,
            'No conflict recorded',
            count($blockingConflicts) . ' conflicting activity record(s) approved anyway'
                . ($overrideReason !== '' ? ' - reason: ' . $overrideReason : '')
        );
    }

    echo json_encode([
        'success' => true,
        'message' => "Status updated from $previousStatus to $newStatus."
            . ($blockingConflicts ? ' The venue conflict was recorded and the affected users were notified.' : ''),
        'previous_status' => $previousStatus,
        'new_status' => $newStatus,
        'conflicts_recorded' => count($blockingConflicts),
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error while updating status.']);
}
