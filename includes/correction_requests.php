<?php
/**
 * includes/correction_requests.php
 * Small shared helpers for the academic correction-request workflow
 * (profile "Request Correction" button -> Super Admin review queue).
 *
 * Include AFTER includes/auth.php (needs $pdo). Safe on an
 * un-migrated database: loadAcademicProfile() only reads the users
 * table (which always exists); the correction_requests table is only
 * touched by the action/page files, each of which degrades gracefully.
 */

/** Whitelisted requestable fields -> label shown everywhere. */
function correctionFieldLabel($field) {
    switch ($field) {
        case 'department':   return 'Department';
        case 'course':       return 'Course';
        case 'organization': return 'Organization';
        default:             return 'Unknown field';
    }
}

/**
 * Read-only snapshot of one user's identity/academic profile plus the
 * joined display names. Returns null when the user does not exist.
 * NEVER writes anything.
 */
function loadAcademicProfile(PDO $pdo, $userId) {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.full_name, u.affiliation, u.office_unit,
                u.department_id, u.course_id, u.organization_id,
                d.department_name, c.course_name, o.organization_name
           FROM users u
           LEFT JOIN departments   d ON d.id = u.department_id
           LEFT JOIN courses       c ON c.id = u.course_id
           LEFT JOIN organizations o ON o.id = u.organization_id
          WHERE u.id = ?"
    );
    $stmt->execute([(int) $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * The user's current value for one requestable field, formatted for the
 * reviewer's "current vs requested" comparison (or the pre-filled
 * profile display). NULL-able fields render as a clear placeholder,
 * never as an invented value.
 */
function academicProfileValue($profile, $field) {
    switch ($field) {
        case 'department':
            return $profile['department_name'] ?? null;
        case 'course':
            return $profile['course_name'] ?? null;
        case 'organization':
            return $profile['organization_name'] ?? null;
        default:
            return null;
    }
}

/** Resolve an institutional record id to its display name (audit labels). */
function institutionalRecordLabel(PDO $pdo, $type, $id) {
    $id = (int) $id;
    if ($id <= 0) return null;
    switch ($type) {
        case 'department':   $sql = 'SELECT department_name FROM departments WHERE id = ?';   break;
        case 'course':       $sql = 'SELECT course_name FROM courses WHERE id = ?';           break;
        case 'organization': $sql = 'SELECT organization_name FROM organizations WHERE id = ?'; break;
        default:             return null;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn();
    return $name === false ? null : $name;
}

/**
 * Map a requested correction (free text typed by the user) to an EXISTING
 * institutional record id for that field. Never invents records.
 *
 * Returns ['id' => int, 'name' => string] on an unambiguous match, or
 * ['error' => human-readable reason] when the value does not exist or is
 * ambiguous - the caller must then refuse the approval with that reason
 * (the Super Admin can add the record in Institutional Records first).
 *
 * Courses are unique per department, so a course name that exists in
 * several departments is disambiguated by the user's current department;
 * if still ambiguous, an error is returned rather than guessing.
 */
function resolveCorrectionTarget(PDO $pdo, $field, $requestedValue, $userDepartmentId = null) {
    $name = trim((string) $requestedValue);
    if ($name === '') {
        return ['error' => 'The requested value is empty.'];
    }

    switch ($field) {
        case 'department':
            $stmt = $pdo->prepare(
                'SELECT id, department_name FROM departments
                  WHERE LOWER(TRIM(department_name)) = LOWER(?)
                  ORDER BY is_active DESC, id ASC'
            );
            $stmt->execute([$name]);
            $rows = $stmt->fetchAll();
            if (!$rows) {
                return ['error' => 'No Department named "' . $name . '" exists. Add it in Institutional Records first, then approve again.'];
            }
            return ['id' => (int) $rows[0]['id'], 'name' => $rows[0]['department_name']];

        case 'course':
            $stmt = $pdo->prepare(
                'SELECT id, course_name, department_id FROM courses
                  WHERE LOWER(TRIM(course_name)) = LOWER(?)
                  ORDER BY is_active DESC, id ASC'
            );
            $stmt->execute([$name]);
            $rows = $stmt->fetchAll();
            if (!$rows) {
                return ['error' => 'No Course named "' . $name . '" exists. Add it in Institutional Records first, then approve again.'];
            }
            if (count($rows) === 1) {
                return ['id' => (int) $rows[0]['id'], 'name' => $rows[0]['course_name'],
                        'department_id' => (int) $rows[0]['department_id']];
            }
            // Same course name in several departments: prefer the one in the
            // user's own department; otherwise refuse rather than guess.
            if ($userDepartmentId) {
                foreach ($rows as $row) {
                    if ((int) $row['department_id'] === (int) $userDepartmentId) {
                        return ['id' => (int) $row['id'], 'name' => $row['course_name'],
                                'department_id' => (int) $row['department_id']];
                    }
                }
            }
            return ['error' => 'The Course "' . $name . '" exists in more than one department. Use Edit User to set this account\'s course directly.'];

        case 'organization':
            $stmt = $pdo->prepare(
                'SELECT id, organization_name FROM organizations
                  WHERE LOWER(TRIM(organization_name)) = LOWER(?)
                  ORDER BY is_active DESC, id ASC'
            );
            $stmt->execute([$name]);
            $rows = $stmt->fetchAll();
            if (!$rows) {
                return ['error' => 'No Organization named "' . $name . '" exists. Add it in Institutional Records first, then approve again.'];
            }
            return ['id' => (int) $rows[0]['id'], 'name' => $rows[0]['organization_name']];

        default:
            return ['error' => 'Invalid field.'];
    }
}