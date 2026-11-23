-- =========================================================
-- UBMSConnect - Migration: School Years + Report Filters
-- (moderator items 8 "School Year Information", 7 "Automated Report
--  Generation", 9 "Report Filtering Options")
--
-- Safe to run on an EXISTING database: it only ADDS one table and two
-- nullable columns, then backfills them from data that is already there.
-- Nothing is dropped, renamed, or rewritten. Every step is guarded, so
-- running this file twice does not error.
--
-- How to run: phpMyAdmin -> Import -> choose this file -> Go
--        or:  mysql -u root < database/migration_reports.sql
-- =========================================================

USE ubmsconnect;

-- ---------------------------------------------------------
-- 1. school_years - the academic-year boundaries the Super Admin defines.
--    Activities and reports are organised by these.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed two defaults so reports work immediately. Edit the dates in
-- Super Admin -> School Years to match your institution's real calendar.
INSERT IGNORE INTO school_years (name, start_date, end_date, is_active) VALUES
('AY 2025-2026', '2025-08-01', '2026-07-31', 0),
('AY 2026-2027', '2026-08-01', '2027-07-31', 1);

-- ---------------------------------------------------------
-- 2. Two new columns on activities (added only if they are missing, so
--    this migration can be re-run safely):
--      school_year_id   - which academic year the activity belongs to
--      organization_id  - snapshot of the host's organization at the time
--                         the record was created, so a report never
--                         silently changes when a student edits their profile
-- ---------------------------------------------------------
SET @db := DATABASE();

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'activities'
                  AND COLUMN_NAME = 'school_year_id') = 0,
               'ALTER TABLE activities ADD COLUMN school_year_id INT NULL AFTER activity_date',
               'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'activities'
                  AND COLUMN_NAME = 'organization_id') = 0,
               'ALTER TABLE activities ADD COLUMN organization_id INT NULL AFTER host_id',
               'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'activities'
                  AND CONSTRAINT_NAME = 'fk_activities_school_year') = 0,
               'ALTER TABLE activities ADD CONSTRAINT fk_activities_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE SET NULL',
               'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'activities'
                  AND CONSTRAINT_NAME = 'fk_activities_organization') = 0,
               'ALTER TABLE activities ADD CONSTRAINT fk_activities_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL',
               'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------
-- 2b. Sample organizations + assigning the demo hosts to them.
--
--     Why: reports filter by organization name, and that value comes from
--     the EVENT HOST's account (activities.organization_id is a snapshot of
--     it). The organizations table is empty on a fresh install and the demo
--     host accounts have no organization, so without this the Organization
--     filter would have nothing to show.
--
--     INSERT IGNORE + the UNIQUE organization name makes this re-runnable,
--     and the UPDATEs only touch rows that are still NULL - your own
--     accounts are never overwritten. Manage the real list in
--     Super Admin -> Institutional Records, and assign organizations in
--     Super Admin -> User Management.
-- ---------------------------------------------------------
INSERT IGNORE INTO organizations (department_id, organization_name)
SELECT d.id, s.org
  FROM (SELECT 'Computer Science Society' AS org, 'CITE' AS dept
        UNION ALL SELECT 'Information Technology Guild', 'CITE'
        UNION ALL SELECT 'Business Administration Society', 'CBEA'
        UNION ALL SELECT 'Junior Marketing Association', 'CBEA'
        UNION ALL SELECT 'Supreme Student Government', NULL
        UNION ALL SELECT 'Office of Student Affairs', NULL) s
  LEFT JOIN departments d ON d.department_name = s.dept;

UPDATE users u JOIN organizations o ON o.organization_name = 'Computer Science Society'
   SET u.organization_id = o.id
 WHERE u.username = 'host' AND u.organization_id IS NULL;

UPDATE users u JOIN organizations o ON o.organization_name = 'Business Administration Society'
   SET u.organization_id = o.id
 WHERE u.username = 'host2' AND u.organization_id IS NULL;

-- ---------------------------------------------------------
-- 3. Backfill from the data that is already in the database.
--    (Super Admin -> School Years has a "Re-sync" button that does the
--     same thing again after you add or edit a school year.)
-- ---------------------------------------------------------
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
-- 4. Report (informational - what ran above)
-- ---------------------------------------------------------
SELECT (SELECT COUNT(*) FROM school_years)                                  AS school_years_defined,
       (SELECT COUNT(*) FROM organizations)                                 AS organizations_defined,
       (SELECT COUNT(*) FROM activities WHERE school_year_id IS NOT NULL)   AS activities_with_school_year,
       (SELECT COUNT(*) FROM activities WHERE school_year_id IS NULL)       AS activities_without_school_year,
       (SELECT COUNT(*) FROM activities WHERE organization_id IS NOT NULL)  AS activities_with_organization,
       (SELECT COUNT(*) FROM activities WHERE organization_id IS NULL)      AS activities_without_organization;
