-- =========================================================
-- UBMSConnect - Migration: Venue Schedule Conflict Detection
-- (moderator item 11 "Implement Venue Schedule Conflict Detection")
--
-- Safe to run on an EXISTING database. It only ADDS one table and some
-- clearly-labelled sample rows. Nothing is dropped, renamed, or rewritten,
-- and every step is guarded so running this file twice does not error.
--
-- How to run: phpMyAdmin -> Import -> choose this file -> Go
--        or:  mysql -u root < database/migration_conflicts.sql
--
-- What this adds
-- --------------
-- Conflicts are DETECTED LIVE (see includes/conflicts.php) by comparing
-- stored activity records - two activities at the same venue, on the same
-- date, whose time ranges overlap. No table is needed for that.
--
-- The one table added here, `activity_conflicts`, only records the case
-- where a staff member deliberately APPROVES an activity that would
-- double-book a venue. It is the "who allowed this, and why" trail, so a
-- double booking can never happen silently.
-- =========================================================

USE ubmsconnect;

-- ---------------------------------------------------------
-- 1. activity_conflicts - a record of deliberately-allowed double bookings
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_conflicts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NOT NULL,
    activity_date DATE NOT NULL,
    primary_activity_id INT NOT NULL,
    conflicting_activity_id INT NOT NULL,
    overlap_start TIME NOT NULL,
    overlap_end TIME NOT NULL,
    reason VARCHAR(255) NULL,
    override_count INT NOT NULL DEFAULT 1,
    detected_by INT NULL,
    detected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_conflict_pair (primary_activity_id, conflicting_activity_id),
    INDEX idx_conflict_date (activity_date),
    INDEX idx_conflict_venue (venue_id),
    CONSTRAINT fk_conflicts_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    CONSTRAINT fk_conflicts_primary FOREIGN KEY (primary_activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_conflicts_other FOREIGN KEY (conflicting_activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_conflicts_by FOREIGN KEY (detected_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 2. Sample data: one deliberate venue double booking.
--
--    Why: the seed data contains no overlaps at all, and this system
--    MONITORS pre-existing activity records - there is no "create
--    activity" screen - so without a sample there would be nothing for
--    the detector to find and the page would look broken.
--
--    Two Approved activities claim Conference Room A at the same time
--    (09:00-11:30 and 10:00-12:00 overlap between 10:00 and 11:30).
--    Both are dated 8 days from install so they never disturb the
--    "Today's Activities" counts on the dashboards.
--
--    These are ordinary activity rows: delete them in the database (or
--    change their times) and the conflict simply disappears. The
--    "NOT EXISTS" guards make this file re-runnable.
-- ---------------------------------------------------------
INSERT INTO activities (activity_name, description, host_id, venue_id, activity_date, start_time, end_time, status)
SELECT 'Robotics Club Orientation',
       'Sample record used to demonstrate venue schedule conflict detection (moderator item 11).',
       u.id, v.id, DATE_ADD(CURDATE(), INTERVAL 8 DAY), '09:00:00', '11:30:00', 'Approved'
  FROM users u
  JOIN venues v ON v.venue_name = 'Conference Room A'
 WHERE u.username = 'host'
   AND NOT EXISTS (SELECT 1 FROM activities WHERE activity_name = 'Robotics Club Orientation');

INSERT INTO activities (activity_name, description, host_id, venue_id, activity_date, start_time, end_time, status)
SELECT 'Marketing Summit Briefing',
       'Sample record used to demonstrate venue schedule conflict detection (moderator item 11).',
       u.id, v.id, DATE_ADD(CURDATE(), INTERVAL 8 DAY), '10:00:00', '12:00:00', 'Approved'
  FROM users u
  JOIN venues v ON v.venue_name = 'Conference Room A'
 WHERE u.username = 'host2'
   AND NOT EXISTS (SELECT 1 FROM activities WHERE activity_name = 'Marketing Summit Briefing');

-- Give the sample rows (and any other unassigned ones) their school year
-- and organization snapshot, exactly like the reports migration does.
UPDATE activities a
  JOIN school_years sy
    ON a.activity_date BETWEEN sy.start_date AND sy.end_date
   SET a.school_year_id = sy.id
 WHERE a.school_year_id IS NULL;

UPDATE activities a
  JOIN users u ON u.id = a.host_id
   SET a.organization_id = u.organization_id
 WHERE a.organization_id IS NULL;

-- ---------------------------------------------------------
-- 3. Report (informational - what ran above)
-- ---------------------------------------------------------
SELECT (SELECT COUNT(*) FROM activity_conflicts) AS recorded_overrides,
       (SELECT COUNT(*) FROM activities
         WHERE venue_id = (SELECT id FROM venues WHERE venue_name = 'Conference Room A')
           AND start_time < '12:00:00')          AS conference_room_a_morning_records,
       (SELECT COUNT(*) FROM activities)          AS total_activities;
