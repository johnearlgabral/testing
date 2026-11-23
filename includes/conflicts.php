<?php
/**
 * includes/conflicts.php
 * Venue schedule conflict detection (moderator item 11:
 * "Implement Venue Schedule Conflict Detection").
 *
 * WHAT A CONFLICT IS
 * ------------------
 * Two activity records conflict when ALL of these are true:
 *   1. they belong to the same venue,
 *   2. they are on the same date,
 *   3. their time ranges OVERLAP - i.e. one starts before the other ends
 *      and ends after the other starts. An activity that ends at exactly
 *      11:00 and another that starts at 11:00 do NOT conflict (back-to-back
 *      use is legitimate).
 *
 * HOW SERIOUS IT IS (severity)
 * ----------------------------
 *   confirmed  - both records are Approved  -> the venue is double-booked.
 *   potential  - at least one is still Pending -> approving it WOULD
 *                double-book the venue. Nothing is reserved yet.
 *   historical - at least one is Completed   -> the double booking already
 *                happened; kept for reporting, hidden by default.
 *
 * Rejected and Maintenance records never occupy a venue, so they are never
 * part of a conflict. This mirrors api/venue_availability.php, where only an
 * Approved activity makes a venue read as Occupied.
 *
 * SCOPE NOTE
 * ----------
 * This system MONITORS stored activity records - there is no booking or
 * "create activity" screen. Detection is therefore computed LIVE from what
 * is already in the database (never cached, never stale), and the only write
 * path that can introduce a new double booking is a Staff status change to
 * "Approved". That is where actions/update_status.php applies the guard and
 * where activity_conflicts rows get written.
 *
 * Include AFTER includes/auth.php (needs $pdo). Every function degrades
 * gracefully: if the optional activity_conflicts table has not been
 * migrated, detection still works and only the override trail is skipped.
 */

/* =========================================================
 * Status helpers
 * ========================================================= */

/**
 * Statuses that can occupy a venue.
 * Pending is included because it MAY occupy; Completed because it DID.
 */
function occupiableActivityStatuses() {
    return ['Approved', 'Pending', 'Completed'];
}

/**
 * The status set used when looking for conflicts.
 * Completed records are excluded unless $includeCompleted is true
 * (the page's default), so past double bookings are opt-in.
 */
function conflictStatuses($includeCompleted = false) {
    return $includeCompleted ? ['Approved', 'Pending', 'Completed'] : ['Approved', 'Pending'];
}

/** Severity of a conflicting pair, from the two records' statuses. */
function conflictSeverity($statusA, $statusB) {
    if ($statusA === 'Completed' || $statusB === 'Completed') return 'historical';
    if ($statusA === 'Approved' && $statusB === 'Approved')  return 'confirmed';
    return 'potential';
}

/** Short label for each severity. */
function conflictSeverityLabel($severity) {
    switch ($severity) {
        case 'confirmed':  return 'Confirmed';
        case 'potential':  return 'Potential';
        case 'historical': return 'Historical';
        default:            return 'Unknown';
    }
}

/** One-line description of what a severity means, shown in the legend. */
function conflictSeverityDescription($severity) {
    switch ($severity) {
        case 'confirmed':  return 'Both records are Approved - the venue is double-booked.';
        case 'potential':  return 'At least one record is still Pending - approving it would double-book the venue.';
        case 'historical': return 'Already happened (a Completed record is involved) - kept for reporting.';
        default:            return '';
    }
}

function conflictSeverityBadgeClass($severity) {
    switch ($severity) {
        case 'confirmed':  return 'badge-rejected';
        case 'potential':  return 'badge-pending';
        case 'historical': return 'badge-default';
        default:            return 'badge-default';
    }
}

/** Severity keys in the order they are presented everywhere. */
function conflictSeverities() {
    return ['confirmed', 'potential', 'historical'];
}

/** Stable key for an unordered pair of activity ids (used for acknowledgement lookup). */
function conflictPairKey($idA, $idB) {
    $a = (int) $idA;
    $b = (int) $idB;
    return $a < $b ? ($a . '-' . $b) : ($b . '-' . $a);
}

/* =========================================================
 * Detection
 * ========================================================= */

/**
 * All venue conflicts matching the given filters.
 *
 * Supported filters (all optional):
 *   school_year_id    int    - only conflicts whose date falls in this year
 *   month             int    - 1..12
 *   venue_id          int
 *   organization_id   int    - matches EITHER side of the pair
 *   date              string - exact date (YYYY-MM-DD), used by the API
 *   date_from/to      string - inclusive date range
 *   severity          string - 'all' | 'confirmed' | 'potential' | 'historical'
 *   include_completed bool   - consider Completed records too
 *
 * Each returned row is normalized so callers (page, CSV, API) never have to
 * know the SQL shape:
 *   venue_id, venue_name, building, capacity,
 *   activity_date, overlap_start, overlap_end, overlap_minutes, severity,
 *   school_year_id, school_year_name, pair_key,
 *   a => [id, name, status, start, end, host, organization],
 *   b => [id, name, status, start, end, host, organization],
 *   acknowledged => null | [reason, override_count, detected_at, detected_by_name]
 */
function findVenueConflicts(array $filters = []) {
    global $pdo;

    $includeCompleted = !empty($filters['include_completed']);
    $severityFilter   = (string) ($filters['severity'] ?? 'all');
    // Asking for historical conflicts only makes sense if Completed records
    // are considered at all, so that filter implies include_completed.
    if ($severityFilter === 'historical') $includeCompleted = true;

    $statuses     = conflictStatuses($includeCompleted);
    $placeholders = implode(', ', array_fill(0, count($statuses), '?'));

    /* b.id > a.id keeps each pair once (A-B but never also B-A) and can never
       pair a record with itself. The overlap test is the standard
       "one starts before the other ends, and ends after the other starts". */
    $sql = "SELECT
                a.id AS activity_a_id, a.activity_name AS activity_a_name,
                a.status AS activity_a_status,
                a.start_time AS activity_a_start, a.end_time AS activity_a_end,
                b.id AS activity_b_id, b.activity_name AS activity_b_name,
                b.status AS activity_b_status,
                b.start_time AS activity_b_start, b.end_time AS activity_b_end,
                a.activity_date,
                a.school_year_id,
                v.id AS venue_id, v.venue_name, v.building, v.capacity,
                GREATEST(a.start_time, b.start_time) AS overlap_start,
                LEAST(a.end_time, b.end_time)        AS overlap_end,
                TIMESTAMPDIFF(MINUTE, GREATEST(a.start_time, b.start_time),
                                      LEAST(a.end_time, b.end_time)) AS overlap_minutes,
                ua.full_name AS activity_a_host, ub.full_name AS activity_b_host,
                oa.organization_name AS activity_a_organization,
                ob.organization_name AS activity_b_organization,
                sy.name AS school_year_name
           FROM activities a
           JOIN activities b
             ON b.venue_id      = a.venue_id
            AND b.activity_date = a.activity_date
            AND b.id            > a.id
            AND b.start_time    < a.end_time
            AND b.end_time      > a.start_time
           JOIN venues v  ON v.id  = a.venue_id
           JOIN users  ua ON ua.id = a.host_id
           JOIN users  ub ON ub.id = b.host_id
           LEFT JOIN organizations oa ON oa.id = a.organization_id
           LEFT JOIN organizations ob ON ob.id = b.organization_id
           LEFT JOIN school_years  sy ON sy.id = a.school_year_id
          WHERE a.status IN ($placeholders)
            AND b.status IN ($placeholders)";

    $params = array_merge($statuses, $statuses);

    if (!empty($filters['school_year_id'])) {
        $sql .= ' AND a.school_year_id = ?';
        $params[] = (int) $filters['school_year_id'];
    }
    if (!empty($filters['month'])) {
        $sql .= ' AND MONTH(a.activity_date) = ?';
        $params[] = (int) $filters['month'];
    }
    if (!empty($filters['venue_id'])) {
        $sql .= ' AND a.venue_id = ?';
        $params[] = (int) $filters['venue_id'];
    }
    if (!empty($filters['organization_id'])) {
        // Either side of the pair can belong to the organization being filtered on.
        $sql .= ' AND (a.organization_id = ? OR b.organization_id = ?)';
        $params[] = (int) $filters['organization_id'];
        $params[] = (int) $filters['organization_id'];
    }
    if (!empty($filters['date'])) {
        $sql .= ' AND a.activity_date = ?';
        $params[] = $filters['date'];
    }
    if (!empty($filters['date_from'])) {
        $sql .= ' AND a.activity_date >= ?';
        $params[] = $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $sql .= ' AND a.activity_date <= ?';
        $params[] = $filters['date_to'];
    }

    $sql .= ' ORDER BY a.activity_date ASC, v.venue_name ASC, overlap_start ASC, a.start_time ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $acknowledged = acknowledgedConflicts();

    $conflicts = [];
    foreach ($stmt->fetchAll() as $row) {
        $severity = conflictSeverity($row['activity_a_status'], $row['activity_b_status']);
        if ($severityFilter !== 'all' && $severityFilter !== $severity) continue;

        $pairKey = conflictPairKey($row['activity_a_id'], $row['activity_b_id']);

        $conflicts[] = [
            'venue_id'         => (int) $row['venue_id'],
            'venue_name'       => $row['venue_name'],
            'building'         => $row['building'],
            'capacity'         => (int) $row['capacity'],
            'activity_date'    => $row['activity_date'],
            'overlap_start'    => $row['overlap_start'],
            'overlap_end'      => $row['overlap_end'],
            'overlap_minutes'  => (int) $row['overlap_minutes'],
            'severity'         => $severity,
            'school_year_id'   => $row['school_year_id'] !== null ? (int) $row['school_year_id'] : null,
            'school_year_name' => $row['school_year_name'],
            'pair_key'         => $pairKey,
            'a' => [
                'id'           => (int) $row['activity_a_id'],
                'name'         => $row['activity_a_name'],
                'status'       => $row['activity_a_status'],
                'start'        => $row['activity_a_start'],
                'end'          => $row['activity_a_end'],
                'host'         => $row['activity_a_host'],
                'organization' => $row['activity_a_organization'],
            ],
            'b' => [
                'id'           => (int) $row['activity_b_id'],
                'name'         => $row['activity_b_name'],
                'status'       => $row['activity_b_status'],
                'start'        => $row['activity_b_start'],
                'end'          => $row['activity_b_end'],
                'host'         => $row['activity_b_host'],
                'organization' => $row['activity_b_organization'],
            ],
            'acknowledged' => $acknowledged[$pairKey] ?? null,
        ];
    }

    return $conflicts;
}

/**
 * Every activity that overlaps one specific activity at the same venue on the
 * same date. Used by the approval guard, by the detail pages, and by
 * api/conflicts.php.
 *
 * @param int  $activityId              the activity being examined
 * @param bool $includeCompleted        consider Completed counterparts too
 * @param bool $requireOccupyingSelf    when true, $activityId itself must be
 *                                      Approved/Pending/Completed to count
 *                                      (a Rejected or Maintenance record never
 *                                      conflicts with anything)
 * @return array<int,array> rows sorted by when the overlap starts
 */
function activityConflicts($activityId, $includeCompleted = false, $requireOccupyingSelf = true) {
    global $pdo;

    $activityId = (int) $activityId;
    if ($activityId <= 0) return [];

    $statuses     = conflictStatuses($includeCompleted);
    $placeholders = implode(', ', array_fill(0, count($statuses), '?'));

    $sql = "SELECT b.id AS other_id, b.activity_name AS other_name, b.status AS other_status,
                   b.start_time AS other_start, b.end_time AS other_end,
                   u.full_name AS other_host, o.organization_name AS other_organization,
                   a.id AS self_id, a.status AS self_status,
                   a.activity_date, a.venue_id, v.venue_name, v.building,
                   GREATEST(a.start_time, b.start_time) AS overlap_start,
                   LEAST(a.end_time, b.end_time)        AS overlap_end,
                   TIMESTAMPDIFF(MINUTE, GREATEST(a.start_time, b.start_time),
                                         LEAST(a.end_time, b.end_time)) AS overlap_minutes
              FROM activities a
              JOIN activities b
                ON b.venue_id      = a.venue_id
               AND b.activity_date = a.activity_date
               AND b.id           <> a.id
               AND b.start_time    < a.end_time
               AND b.end_time      > a.start_time
               AND b.status IN ($placeholders)
              JOIN venues v ON v.id = a.venue_id
              JOIN users  u ON u.id = b.host_id
              LEFT JOIN organizations o ON o.id = b.organization_id
             WHERE a.id = ?";

    $params = array_merge($statuses, [$activityId]);

    if ($requireOccupyingSelf) {
        $selfPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));
        $sql .= " AND a.status IN ($selfPlaceholders)";
        $params = array_merge($params, $statuses);
    }

    $sql .= ' ORDER BY overlap_start ASC, b.start_time ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $conflicts = [];
    foreach ($stmt->fetchAll() as $row) {
        $conflicts[] = [
            'id'              => (int) $row['other_id'],
            'name'            => $row['other_name'],
            'status'          => $row['other_status'],
            'host'            => $row['other_host'],
            'organization'    => $row['other_organization'],
            'start'           => $row['other_start'],
            'end'             => $row['other_end'],
            'activity_date'   => $row['activity_date'],
            'venue_id'        => (int) $row['venue_id'],
            'venue_name'      => $row['venue_name'],
            'building'        => $row['building'],
            'overlap_start'   => $row['overlap_start'],
            'overlap_end'     => $row['overlap_end'],
            'overlap_minutes' => (int) $row['overlap_minutes'],
            'severity'        => conflictSeverity($row['self_status'], $row['other_status']),
            'pair_key'        => conflictPairKey($row['self_id'], $row['other_id']),
        ];
    }

    return $conflicts;
}

/**
 * The subset of activityConflicts() that genuinely PREVENTS a double booking:
 * overlaps with activities that are already Approved. This is exactly what
 * the approval guard in actions/update_status.php blocks on.
 */
function blockingConflictsFor($activityId) {
    $blocking = [];
    foreach (activityConflicts($activityId, false, false) as $conflict) {
        if ($conflict['status'] === 'Approved') $blocking[] = $conflict;
    }
    return $blocking;
}

/* =========================================================
 * Totals and groupings (for the stat strip, the tables and the CSV)
 * ========================================================= */

/** Aggregate a conflict list into the numbers shown on the page. */
function summarizeConflicts(array $conflicts) {
    $summary = [
        'total'      => count($conflicts),
        'confirmed'  => 0,
        'potential'  => 0,
        'historical' => 0,
        'minutes'    => 0,
    ];

    $venues = [];
    $dates  = [];

    foreach ($conflicts as $conflict) {
        $severity = $conflict['severity'];
        if (isset($summary[$severity])) $summary[$severity]++;
        $venues[$conflict['venue_id']] = true;
        $dates[$conflict['activity_date']] = true;
        $summary['minutes'] += max(0, (int) $conflict['overlap_minutes']);
    }

    $summary['venue_count'] = count($venues);
    $summary['date_count']  = count($dates);

    return $summary;
}

/**
 * Conflicts grouped per venue, worst first - the "hotspot" table. Useful for
 * deciding which rooms actually need a tighter schedule.
 */
function conflictHotspots(array $conflicts) {
    $hotspots = [];
    foreach ($conflicts as $conflict) {
        $id = $conflict['venue_id'];
        if (!isset($hotspots[$id])) {
            $hotspots[$id] = [
                'venue_id'   => $id,
                'venue_name' => $conflict['venue_name'],
                'building'   => $conflict['building'],
                'total'      => 0,
                'confirmed'  => 0,
                'potential'  => 0,
                'historical' => 0,
                'dates'      => [],
                'minutes'    => 0,
                'last_date'  => null,
            ];
        }
        $hotspots[$id]['total']++;
        $hotspots[$id][$conflict['severity']]++;
        $hotspots[$id]['dates'][$conflict['activity_date']] = true;
        $hotspots[$id]['minutes'] += max(0, (int) $conflict['overlap_minutes']);
        if ($hotspots[$id]['last_date'] === null || $conflict['activity_date'] > $hotspots[$id]['last_date']) {
            $hotspots[$id]['last_date'] = $conflict['activity_date'];
        }
    }

    foreach ($hotspots as &$hotspot) {
        $hotspot['day_count'] = count($hotspot['dates']);
        unset($hotspot['dates']);
    }
    unset($hotspot);

    usort($hotspots, function ($x, $y) {
        if ($x['total'] !== $y['total']) return $y['total'] - $x['total'];
        return strcmp($x['venue_name'], $y['venue_name']);
    });

    return $hotspots;
}

/** "45 m" / "1 h 30 m" for an overlap length in minutes. */
function formatOverlapMinutes($minutes) {
    $minutes = (int) $minutes;
    if ($minutes <= 0) return '-';
    $hours = intdiv($minutes, 60);
    $mins  = $minutes % 60;
    if ($hours && $mins) return $hours . ' h ' . $mins . ' m';
    if ($hours) return $hours . ' h';
    return $mins . ' m';
}

/* =========================================================
 * The acknowledgement trail (activity_conflicts)
 * ========================================================= */

/**
 * Conflicts that a staff member deliberately approved anyway.
 * Returns [pair_key => row]. Returns [] if the table has not been migrated,
 * so every caller keeps working on an older database.
 *
 * @param bool $refresh re-read from the database. recordConflictOverride()
 *                      uses this to invalidate the cache right after it
 *                      writes, otherwise a caller that read the trail earlier
 *                      in the same request would still see the old value.
 */
function acknowledgedConflicts($refresh = false) {
    global $pdo;
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;

    $cache = [];
    try {
        $rows = $pdo->query(
            'SELECT c.*, u.full_name AS detected_by_name
               FROM activity_conflicts c
               LEFT JOIN users u ON u.id = c.detected_by
              ORDER BY c.detected_at DESC'
        )->fetchAll();
        foreach ($rows as $row) {
            $cache[conflictPairKey($row['primary_activity_id'], $row['conflicting_activity_id'])] = [
                'reason'                  => $row['reason'],
                'override_count'          => (int) $row['override_count'],
                'detected_at'             => $row['detected_at'],
                'detected_by_name'        => $row['detected_by_name'],
                'primary_activity_id'     => (int) $row['primary_activity_id'],
                'conflicting_activity_id' => (int) $row['conflicting_activity_id'],
            ];
        }
    } catch (Exception $e) {
        $cache = []; // not migrated yet - the acknowledgement trail is simply unavailable
    }

    return $cache;
}

/**
 * Records that a detected double booking was approved anyway. Called ONLY
 * from actions/update_status.php, and only after the user explicitly
 * confirmed. Bumps override_count when the same pair is confirmed again, so a
 * repeated override is visible rather than silently overwritten.
 *
 * @return bool true when the trail was written (false when unavailable)
 */
function recordConflictOverride($primaryActivityId, $conflictingActivityId, $venueId, $activityDate,
                                $overlapStart, $overlapEnd, $reason = null, $userId = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO activity_conflicts
                 (venue_id, activity_date, primary_activity_id, conflicting_activity_id,
                  overlap_start, overlap_end, reason, detected_by, detected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                 reason         = VALUES(reason),
                 detected_by    = VALUES(detected_by),
                 detected_at    = NOW(),
                 override_count = override_count + 1'
        );
        $stmt->execute([
            (int) $venueId,
            $activityDate,
            (int) $primaryActivityId,
            (int) $conflictingActivityId,
            $overlapStart,
            $overlapEnd,
            $reason ? mb_substr(trim((string) $reason), 0, 255) : null,
            $userId ? (int) $userId : null,
        ]);

        // Invalidate the acknowledgement cache so anything that already read
        // the trail in this same request sees the row we just wrote.
        acknowledgedConflicts(true);

        return true;
    } catch (Exception $e) {
        // Never let the acknowledgement trail break the status change itself.
        error_log('[UBMSConnect] recordConflictOverride() failed: ' . $e->getMessage());
        return false;
    }
}
