<?php
/**
 * includes/school_year.php
 * School-year helpers (moderator item 8: "School Year Information").
 *
 * A school year is a row in `school_years` that the Super Admin defines at
 * Super Admin -> School Years (e.g. "AY 2026-2027", 2026-08-01 to 2027-07-31).
 * Each activity carries activities.school_year_id, and that is what generated
 * reports are grouped and filtered by (items 7 and 9).
 *
 * Include AFTER includes/auth.php (needs $pdo). Every function degrades
 * gracefully if the school_years table has not been migrated yet.
 */

/** All school years, newest first. Pass true for only the active ones. */
function getSchoolYears($activeOnly = false) {
    global $pdo;
    $sql = 'SELECT * FROM school_years';
    if ($activeOnly) $sql .= ' WHERE is_active = 1';
    $sql .= ' ORDER BY start_date DESC';
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Exception $e) {
        return []; // not migrated yet - callers fall back to "no school year"
    }
}

/** id => name map, for resolving an activity's stored school_year_id. */
function schoolYearNames() {
    $map = [];
    foreach (getSchoolYears() as $sy) {
        $map[(int) $sy['id']] = $sy['name'];
    }
    return $map;
}

/** The school year marked active (falling back to the newest one), or null. */
function getActiveSchoolYear() {
    global $pdo;
    try {
        $row = $pdo->query('SELECT * FROM school_years WHERE is_active = 1 ORDER BY start_date DESC LIMIT 1')->fetch();
        if ($row) return $row;
        $row = $pdo->query('SELECT * FROM school_years ORDER BY start_date DESC LIMIT 1')->fetch();
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** Which defined school year a date falls into (null when it falls into none). */
function schoolYearForDate($date) {
    global $pdo;
    if (!$date) return null;
    try {
        $stmt = $pdo->prepare('SELECT * FROM school_years WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1');
        $stmt->execute([$date]);
        return $stmt->fetch() ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/** Readable school year name for a stored id. */
function schoolYearNameById($id, $fallback = 'Not assigned to a school year') {
    if (!$id) return $fallback;
    $names = schoolYearNames();
    return $names[(int) $id] ?? $fallback;
}

/** How many activities still have no school year (dates outside every defined range). */
function activitiesWithoutSchoolYear() {
    global $pdo;
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM activities WHERE school_year_id IS NULL')->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Re-derives the snapshot columns on activities:
 *
 *  - school_year_id  : FULL recompute from activity_date, because it is purely
 *                      a function of the date. If the Super Admin edits a
 *                      school year's dates, re-syncing fixes every record.
 *                      A date that falls into no defined range becomes NULL.
 *  - organization_id : only fills rows that are still empty. Deliberately not
 *                      a full recompute - a report should keep showing the
 *                      organization that ran the event at the time, even if
 *                      that student later changes organization.
 *
 * @return array{SchoolYear:int, Organization:int} rows changed per step
 */
function resyncActivitySnapshots() {
    global $pdo;
    $schoolYear = $pdo->exec(
        'UPDATE activities a
            SET a.school_year_id = (SELECT sy.id FROM school_years sy
                                     WHERE a.activity_date BETWEEN sy.start_date AND sy.end_date
                                     ORDER BY sy.start_date DESC LIMIT 1)'
    );
    $organization = $pdo->exec(
        'UPDATE activities a JOIN users u ON u.id = a.host_id
            SET a.organization_id = u.organization_id
          WHERE a.organization_id IS NULL'
    );
    return ['SchoolYear' => (int) $schoolYear, 'Organization' => (int) $organization];
}

/** "September" / "All months" label for a month number. */
function monthLabel($month) {
    $month = (int) $month;
    if ($month < 1 || $month > 12) return 'All months';
    return date('F', mktime(0, 0, 0, $month, 1, 2000));
}

/** Two-digit month list for <select> menus: [1 => 'January', ...] */
function monthOptions() {
    $months = [];
    for ($m = 1; $m <= 12; $m++) $months[$m] = monthLabel($m);
    return $months;
}
