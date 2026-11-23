-- =========================================================
-- UBMSConnect Database
-- Real-Time Activity Monitoring Dashboard for UBMS Staff Management
-- =========================================================

CREATE DATABASE IF NOT EXISTS ubmsconnect CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ubmsconnect;

-- ---------------------------------------------------------
-- Table: users
-- ---------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    password_changed_at DATETIME NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NULL,
    profile_picture VARCHAR(255) NULL,
    university_id VARCHAR(50) NULL,
    affiliation ENUM('student','ubms_staff') NULL,
    department_id INT NULL,
    course_id INT NULL,
    organization_id INT NULL,
    office_unit VARCHAR(150) NULL,
    role ENUM('super_admin','staff','host') NOT NULL,
    status ENUM('active','pending','inactive','rejected') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(120) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    course_name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_course_department (department_id, course_name),
    CONSTRAINT fk_course_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;
CREATE TABLE organizations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NULL,
    organization_name VARCHAR(150) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_org_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: venues
-- ---------------------------------------------------------
CREATE TABLE venues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_name VARCHAR(100) NOT NULL,
    building VARCHAR(100) NOT NULL,
    location VARCHAR(150) NULL,
    description TEXT NULL,
    image_path VARCHAR(255) NULL,
    is_free TINYINT(1) NOT NULL DEFAULT 1,
    usage_fee DECIMAL(10,2) NULL,
    capacity INT NOT NULL DEFAULT 0,
    status ENUM('Available','In Use','Under Maintenance','Unavailable') NOT NULL DEFAULT 'Available',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_venues_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: school_years
-- Academic years are defined by the Super Admin (Super Admin -> School
-- Years) and are what activities and generated reports are organised by.
-- ---------------------------------------------------------
CREATE TABLE school_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: activities
-- ---------------------------------------------------------
CREATE TABLE activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_name VARCHAR(150) NOT NULL,
    description TEXT,
    host_id INT NOT NULL,
    organization_id INT NULL,
    venue_id INT NOT NULL,
    activity_date DATE NOT NULL,
    school_year_id INT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('Pending','Approved','Rejected','Maintenance','Completed') NOT NULL DEFAULT 'Pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_activities_host FOREIGN KEY (host_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_activities_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    CONSTRAINT fk_activities_school_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE SET NULL,
    CONSTRAINT fk_activities_organization FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL,
    INDEX idx_activity_date (activity_date),
    INDEX idx_status (status),
    INDEX idx_venue_id (venue_id),
    INDEX idx_host_id (host_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: activity_conflicts
-- Venue schedule conflict detection (moderator item 11).
--
-- Conflicts themselves are DETECTED LIVE by comparing activity records
-- (same venue + same date + overlapping times) - see includes/conflicts.php.
-- This table only stores the case where a Staff member deliberately
-- APPROVED an activity that would double-book a venue, so a double booking
-- can never happen silently and there is a record of who allowed it and why.
-- ---------------------------------------------------------
CREATE TABLE activity_conflicts (
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
-- Table: utilities
-- ---------------------------------------------------------
CREATE TABLE utilities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utility_name VARCHAR(100) NOT NULL,
    category VARCHAR(80) NULL,
    location VARCHAR(100) NOT NULL,
    quantity INT NULL,
    status ENUM('Operational','Maintenance','Unavailable') NOT NULL DEFAULT 'Operational',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    description TEXT,
    image_path VARCHAR(255) NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_utilities_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: maintenance_logs
-- ---------------------------------------------------------
CREATE TABLE maintenance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venue_id INT NULL,
    activity_id INT NULL,
    utility_id INT NULL,
    note TEXT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_maint_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_utility FOREIGN KEY (utility_id) REFERENCES utilities(id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: activity_status_logs
-- ---------------------------------------------------------
CREATE TABLE activity_status_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    previous_status VARCHAR(20) NOT NULL,
    new_status VARCHAR(20) NOT NULL,
    changed_by INT NOT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_log_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_activity_id (activity_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: audit_logs
-- ---------------------------------------------------------
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    actor_name VARCHAR(100) NOT NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50) NULL,
    target_id INT NULL,
    target_label VARCHAR(150) NULL,
    previous_value VARCHAR(150) NULL,
    new_value VARCHAR(150) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_target (target_type, target_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: dismissed_activity
-- Per-user "hidden from my Profile view" markers. Does NOT delete
-- anything from audit_logs - the full audit trail is unaffected.
-- ---------------------------------------------------------
CREATE TABLE dismissed_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    audit_log_id INT NOT NULL,
    dismissed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dismissed_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dismissed_audit FOREIGN KEY (audit_log_id) REFERENCES audit_logs(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_audit (user_id, audit_log_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: notifications
-- ---------------------------------------------------------
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category ENUM('activity','maintenance','account','admin') NOT NULL,
    message VARCHAR(255) NOT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_notif_user_unread (user_id, is_read),
    INDEX idx_notif_created (created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: notification_preferences (missing row = all categories ON)
-- ---------------------------------------------------------
CREATE TABLE notification_preferences (
    user_id INT PRIMARY KEY,
    activity_updates TINYINT(1) NOT NULL DEFAULT 1,
    maintenance_updates TINYINT(1) NOT NULL DEFAULT 1,
    account_updates TINYINT(1) NOT NULL DEFAULT 1,
    admin_updates TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_notifpref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: dashboard_preferences (missing row = all sections ON)
-- ---------------------------------------------------------
CREATE TABLE dashboard_preferences (
    user_id INT PRIMARY KEY,
    show_stats TINYINT(1) NOT NULL DEFAULT 1,
    show_venues TINYINT(1) NOT NULL DEFAULT 1,
    show_utilities TINYINT(1) NOT NULL DEFAULT 1,
    show_maintenance TINYINT(1) NOT NULL DEFAULT 1,
    show_recent TINYINT(1) NOT NULL DEFAULT 1,
    calendar_default_view ENUM('month','list') NOT NULL DEFAULT 'month',
    CONSTRAINT fk_dashpref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: password_reset_tokens (random, hashed at rest, expiring, single-use)
-- ---------------------------------------------------------
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_reset_token (token_hash),
    INDEX idx_reset_user (user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: remember_tokens ("Remember this device for 30 days")
-- ---------------------------------------------------------
CREATE TABLE remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_remember_token (token_hash),
    INDEX idx_remember_user (user_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: password_recovery_requests (Super Admin-assisted password
-- recovery workflow - public submission ledger + admin review queue).
-- user_id IS NULL when the submitted identifier did not match an
-- active account (rate-limit/forensics ledger row only). No
-- passwords or reset tokens are ever stored in this table.
-- Existing installs get this via database/migration_password_recovery.sql.
-- ---------------------------------------------------------
CREATE TABLE password_recovery_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    identifier_submitted VARCHAR(190) NOT NULL,
    status ENUM('pending','approved','rejected','handled','duplicate','ignored') NOT NULL DEFAULT 'pending',
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(255) NULL,
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_recovery_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_recovery_status (status, created_at),
    INDEX idx_recovery_user_status (user_id, status),
    INDEX idx_recovery_ip (ip_address, created_at),
    INDEX idx_recovery_identifier (identifier_submitted, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Table: correction_requests (Academic / organizational
-- correction requests - user submissions + Super Admin review
-- queue). A submission never modifies the users table; only an
-- approved review does.
-- Existing installs get this via database/migration_correction_requests.sql.
-- ---------------------------------------------------------
CREATE TABLE correction_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    field_requested ENUM('department','course','organization') NOT NULL,
    current_value VARCHAR(150) NULL,
    requested_value VARCHAR(150) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    reviewed_by INT NULL,
    reviewed_at DATETIME NULL,
    review_note VARCHAR(255) NULL,
    CONSTRAINT fk_correction_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_correction_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_correction_status (status, created_at),
    INDEX idx_correction_user_status (user_id, status)
) ENGINE=InnoDB;

-- =========================================================
-- SAMPLE DATA
-- =========================================================

-- Users
-- DEMO Super Admin  -> username: superadmin | password: SuperAdmin123
-- DEMO Staff        -> username: staff / staff2 | password: staff123
-- DEMO Event Host   -> username: host / host2 | password: host123
-- DEMO Pending Staff-> username: pstaff | password: staff123 (status = pending, cannot log in until approved)
-- (hashes generated with PHP-compatible bcrypt)
-- IMPORTANT: change/remove the superadmin demo password before real-world use.
INSERT INTO users (username, password, full_name, email, role, status) VALUES
('superadmin', '$2b$10$h6B.MrcBIdjUD1DPuwTLtewexUJaqAEdrHCvxcGI91B.f5rtMnKX6', 'System Administrator', 'superadmin@ubmsconnect.local', 'super_admin', 'active'),
('staff', '$2b$10$7PZ8txdta7peTHW0RoS/yeiVnNTWg6OLkxMMZe8TylmLHFRniLO0.', 'John Doe', 'john.doe@ubmsconnect.local', 'staff', 'active'),
('staff2', '$2b$10$7PZ8txdta7peTHW0RoS/yeiVnNTWg6OLkxMMZe8TylmLHFRniLO0.', 'Maria Santos', 'maria.santos@ubmsconnect.local', 'staff', 'active'),
('host', '$2b$10$kEVkX//jR/weA1CwkJx7d.Ss3fpEDRbiAo1TTXUtHOhFiM8C/3VOu', 'Engr. Ramon Cruz', 'ramon.cruz@ubmsconnect.local', 'host', 'active'),
('host2', '$2b$10$kEVkX//jR/weA1CwkJx7d.Ss3fpEDRbiAo1TTXUtHOhFiM8C/3VOu', 'Dr. Angela Reyes', 'angela.reyes@ubmsconnect.local', 'host', 'active'),
('pstaff', '$2b$10$7PZ8txdta7peTHW0RoS/yeiVnNTWg6OLkxMMZe8TylmLHFRniLO0.', 'Carlo Fernandez', 'carlo.fernandez@ubmsconnect.local', 'staff', 'pending');

-- Venues
INSERT INTO venues (venue_name, building, location, description, capacity, status, is_active, created_by) VALUES
('Conference Room A', 'Administration Building', '2nd Floor, East Wing', 'Mid-sized meeting room with video conferencing equipment.', 40, 'Available', 1, (SELECT id FROM users WHERE username='superadmin')),
('Grand Auditorium', 'Main Building', 'Ground Floor', 'Main venue for large university-wide events.', 500, 'In Use', 1, (SELECT id FROM users WHERE username='superadmin')),
('Seminar Hall 1', 'Academic Building', '3rd Floor', 'Standard seminar hall with projector and sound system.', 100, 'Available', 1, (SELECT id FROM users WHERE username='superadmin')),
('Seminar Hall 2', 'Academic Building', '3rd Floor', 'Currently undergoing AC repair.', 100, 'Under Maintenance', 1, (SELECT id FROM users WHERE username='superadmin')),
('Multi-Purpose Gym', 'Sports Complex', 'Ground Floor', 'Used for intramurals, assemblies, and large gatherings.', 800, 'Available', 1, (SELECT id FROM users WHERE username='superadmin')),
('Training Room B', 'IT Building', '1st Floor', 'Computer lab configured for hands-on training sessions.', 60, 'Available', 1, (SELECT id FROM users WHERE username='superadmin')),
('Board Room', 'Administration Building', '4th Floor', 'Executive meeting room for the board of trustees.', 20, 'Available', 1, (SELECT id FROM users WHERE username='superadmin'));

-- Utilities
INSERT INTO utilities (utility_name, category, location, status, is_active, created_by, description) VALUES
('Electricity', 'Power', 'Main Building', 'Operational', 1, (SELECT id FROM users WHERE username='superadmin'), 'Main power supply for the campus core buildings.'),
('Water Supply', 'Plumbing', 'Main Building', 'Operational', 1, (SELECT id FROM users WHERE username='superadmin'), 'Domestic water line for restrooms and pantry.'),
('Air Conditioning', 'HVAC', 'Seminar Hall 2', 'Maintenance', 1, (SELECT id FROM users WHERE username='superadmin'), 'AC unit undergoing compressor repair.'),
('AV Equipment', 'Audio/Visual', 'Grand Auditorium', 'Operational', 1, (SELECT id FROM users WHERE username='superadmin'), 'Projector, mixer, and speaker system.'),
('Internet/Network', 'IT', 'IT Building', 'Operational', 1, (SELECT id FROM users WHERE username='superadmin'), 'Campus-wide LAN and Wi-Fi access point.'),
('Electricity', 'Power', 'Sports Complex', 'Unavailable', 1, (SELECT id FROM users WHERE username='superadmin'), 'Circuit breaker tripped, awaiting electrician.'),
('AV Equipment', 'Audio/Visual', 'Conference Room A', 'Operational', 1, (SELECT id FROM users WHERE username='superadmin'), 'Smart TV and video conferencing unit.');

-- School Years (academic years - edit these in Super Admin -> School Years)
INSERT INTO school_years (name, start_date, end_date, is_active) VALUES
('AY 2025-2026', '2025-08-01', '2026-07-31', 0),
('AY 2026-2027', '2026-08-01', '2027-07-31', 1);

-- Activities (sample, pre-existing records that UBMS monitors)
-- NOTE: host_id/venue_id are resolved by username/name via subqueries so this
-- script does not depend on fixed auto-increment IDs (safe regardless of
-- how many user rows precede these, e.g. after the Super Admin was added).
INSERT INTO activities (activity_name, description, host_id, venue_id, activity_date, start_time, end_time, status) VALUES
('Faculty Meeting', 'Monthly meeting for all department chairs.', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Conference Room A'), CURDATE(), '09:00:00', '11:00:00', 'Approved'),
('Student Orientation', 'Orientation for incoming freshmen.', (SELECT id FROM users WHERE username='host2'), (SELECT id FROM venues WHERE venue_name='Grand Auditorium'), CURDATE(), '13:00:00', '17:00:00', 'Approved'),
('Seminar on Cybersecurity', 'Guest lecture on network security fundamentals.', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Seminar Hall 1'), CURDATE(), '14:00:00', '16:00:00', 'Pending'),
('Department Meeting - CS', 'Curriculum review meeting.', (SELECT id FROM users WHERE username='host2'), (SELECT id FROM venues WHERE venue_name='Training Room B'), DATE_ADD(CURDATE(), INTERVAL 1 DAY), '10:00:00', '12:00:00', 'Pending'),
('Research Presentation', 'Thesis defense presentation for graduate students.', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Board Room'), DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:00:00', '10:30:00', 'Approved'),
('University Assembly', 'General assembly for all students and faculty.', (SELECT id FROM users WHERE username='host2'), (SELECT id FROM venues WHERE venue_name='Grand Auditorium'), DATE_ADD(CURDATE(), INTERVAL 2 DAY), '08:00:00', '10:00:00', 'Pending'),
('Training Session - New Hires', 'HR orientation and system training.', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Training Room B'), DATE_ADD(CURDATE(), INTERVAL 2 DAY), '13:00:00', '15:00:00', 'Approved'),
('Capstone Defense - Batch A', 'Final defense for capstone research groups.', (SELECT id FROM users WHERE username='host2'), (SELECT id FROM venues WHERE venue_name='Seminar Hall 1'), DATE_ADD(CURDATE(), INTERVAL 3 DAY), '08:00:00', '12:00:00', 'Approved'),
('Organization Meeting - CS Society', 'Weekly officer meeting.', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Conference Room A'), DATE_ADD(CURDATE(), INTERVAL 3 DAY), '16:00:00', '17:00:00', 'Pending'),
('Sports Fest Opening Program', 'Opening ceremony for intramurals.', (SELECT id FROM users WHERE username='host2'), (SELECT id FROM venues WHERE venue_name='Multi-Purpose Gym'), DATE_ADD(CURDATE(), INTERVAL 4 DAY), '07:00:00', '09:00:00', 'Maintenance'),
('Alumni Homecoming Program', 'Annual alumni gathering event.', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Grand Auditorium'), DATE_SUB(CURDATE(), INTERVAL 1 DAY), '13:00:00', '18:00:00', 'Completed'),
('Faculty Development Workshop', 'Workshop on outcomes-based teaching.', (SELECT id FROM users WHERE username='host2'), (SELECT id FROM venues WHERE venue_name='Seminar Hall 1'), DATE_SUB(CURDATE(), INTERVAL 2 DAY), '09:00:00', '16:00:00', 'Completed'),
('Board Meeting', 'Quarterly board of trustees meeting.', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Board Room'), DATE_SUB(CURDATE(), INTERVAL 3 DAY), '10:00:00', '12:00:00', 'Rejected'),
('Recognition Day Rehearsal', 'Rehearsal for graduation recognition rites.', (SELECT id FROM users WHERE username='host2'), (SELECT id FROM venues WHERE venue_name='Multi-Purpose Gym'), DATE_SUB(CURDATE(), INTERVAL 4 DAY), '08:00:00', '11:00:00', 'Completed'),
('IT Infrastructure Briefing', 'Briefing on new network rollout.', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Training Room B'), DATE_ADD(CURDATE(), INTERVAL 5 DAY), '10:00:00', '11:30:00', 'Pending');

-- ---------------------------------------------------------
-- Sample VENUE SCHEDULE CONFLICT (moderator item 11)
--
-- The sample records above contain no overlaps at all, and this system
-- monitors pre-existing activity records (there is no "create activity"
-- screen), so without this pair the conflict detector would have nothing to
-- find and Super Admin / Staff -> Schedule Conflicts would look broken.
--
-- Two Approved activities both claim Conference Room A at the same time:
-- 09:00-11:30 and 10:00-12:00 overlap between 10:00 and 11:30. Both are
-- dated 8 days ahead so they never disturb the "Today's Activities" counts
-- on the dashboards.
--
-- These are ordinary activity rows - change their times or delete them and
-- the detected conflict simply disappears.
-- ---------------------------------------------------------
INSERT INTO activities (activity_name, description, host_id, venue_id, activity_date, start_time, end_time, status) VALUES
('Robotics Club Orientation', 'Sample record used to demonstrate venue schedule conflict detection (moderator item 11).', (SELECT id FROM users WHERE username='host'), (SELECT id FROM venues WHERE venue_name='Conference Room A'), DATE_ADD(CURDATE(), INTERVAL 8 DAY), '09:00:00', '11:30:00', 'Approved'),
('Marketing Summit Briefing', 'Sample record used to demonstrate venue schedule conflict detection (moderator item 11).', (SELECT id FROM users WHERE username='host2'), (SELECT id FROM venues WHERE venue_name='Conference Room A'), DATE_ADD(CURDATE(), INTERVAL 8 DAY), '10:00:00', '12:00:00', 'Approved');

-- Maintenance Logs
INSERT INTO maintenance_logs (venue_id, activity_id, utility_id, note, created_by) VALUES
((SELECT id FROM venues WHERE venue_name='Seminar Hall 2'), NULL, (SELECT id FROM utilities WHERE utility_name='Air Conditioning'), 'Air conditioning unit requires inspection. Compressor making unusual noise.', (SELECT id FROM users WHERE username='staff')),
((SELECT id FROM venues WHERE venue_name='Grand Auditorium'), NULL, (SELECT id FROM utilities WHERE utility_name='AV Equipment' AND location='Grand Auditorium'), 'Projector in Grand Auditorium is currently unavailable. Replacement bulb ordered.', (SELECT id FROM users WHERE username='staff')),
(NULL, NULL, (SELECT id FROM utilities WHERE utility_name='Electricity' AND location='Sports Complex'), 'Electrical maintenance ongoing in Sports Complex. Breaker replacement scheduled.', (SELECT id FROM users WHERE username='staff2')),
((SELECT id FROM venues WHERE venue_name='Multi-Purpose Gym'), (SELECT id FROM activities WHERE activity_name='Sports Fest Opening Program'), NULL, 'Gym floor being resurfaced ahead of Sports Fest opening program.', (SELECT id FROM users WHERE username='staff')),
((SELECT id FROM venues WHERE venue_name='Conference Room A'), NULL, NULL, 'Conference Room A chairs cleaned and rearranged.', (SELECT id FROM users WHERE username='staff2')),
(NULL, NULL, (SELECT id FROM utilities WHERE utility_name='Internet/Network'), 'Router firmware updated for improved Wi-Fi stability.', (SELECT id FROM users WHERE username='staff'));

-- Activity Status Logs (history)
INSERT INTO activity_status_logs (activity_id, previous_status, new_status, changed_by, changed_at) VALUES
((SELECT id FROM activities WHERE activity_name='Faculty Meeting'), 'Pending', 'Approved', (SELECT id FROM users WHERE username='staff'), DATE_SUB(NOW(), INTERVAL 2 DAY)),
((SELECT id FROM activities WHERE activity_name='Student Orientation'), 'Pending', 'Approved', (SELECT id FROM users WHERE username='staff2'), DATE_SUB(NOW(), INTERVAL 2 DAY)),
((SELECT id FROM activities WHERE activity_name='Research Presentation'), 'Pending', 'Approved', (SELECT id FROM users WHERE username='staff'), DATE_SUB(NOW(), INTERVAL 1 DAY)),
((SELECT id FROM activities WHERE activity_name='Training Session - New Hires'), 'Pending', 'Approved', (SELECT id FROM users WHERE username='staff2'), DATE_SUB(NOW(), INTERVAL 1 DAY)),
((SELECT id FROM activities WHERE activity_name='Capstone Defense - Batch A'), 'Pending', 'Approved', (SELECT id FROM users WHERE username='staff'), NOW()),
((SELECT id FROM activities WHERE activity_name='Sports Fest Opening Program'), 'Approved', 'Maintenance', (SELECT id FROM users WHERE username='staff'), DATE_SUB(NOW(), INTERVAL 12 HOUR)),
((SELECT id FROM activities WHERE activity_name='Alumni Homecoming Program'), 'Approved', 'Completed', (SELECT id FROM users WHERE username='staff2'), DATE_SUB(NOW(), INTERVAL 1 DAY)),
((SELECT id FROM activities WHERE activity_name='Faculty Development Workshop'), 'Approved', 'Completed', (SELECT id FROM users WHERE username='staff'), DATE_SUB(NOW(), INTERVAL 2 DAY)),
((SELECT id FROM activities WHERE activity_name='Board Meeting'), 'Pending', 'Rejected', (SELECT id FROM users WHERE username='staff2'), DATE_SUB(NOW(), INTERVAL 3 DAY)),
((SELECT id FROM activities WHERE activity_name='Recognition Day Rehearsal'), 'Approved', 'Completed', (SELECT id FROM users WHERE username='staff'), DATE_SUB(NOW(), INTERVAL 4 DAY));

-- Seed audit log entries reflecting the above administrative/status actions
INSERT INTO audit_logs (actor_id, actor_name, action, target_type, target_id, target_label, previous_value, new_value, created_at) VALUES
((SELECT id FROM users WHERE username='superadmin'), 'System Administrator', 'Created Initial Accounts', 'user', NULL, 'System Setup', NULL, NULL, DATE_SUB(NOW(), INTERVAL 5 DAY)),
((SELECT id FROM users WHERE username='staff'), 'John Doe', 'Status Changed', 'activity', (SELECT id FROM activities WHERE activity_name='Faculty Meeting'), 'Faculty Meeting', 'Pending', 'Approved', DATE_SUB(NOW(), INTERVAL 2 DAY)),
((SELECT id FROM users WHERE username='staff2'), 'Maria Santos', 'Status Changed', 'activity', (SELECT id FROM activities WHERE activity_name='Board Meeting'), 'Board Meeting', 'Pending', 'Rejected', DATE_SUB(NOW(), INTERVAL 3 DAY)),
((SELECT id FROM users WHERE username='superadmin'), 'System Administrator', 'Registered', 'user', (SELECT id FROM users WHERE username='pstaff'), 'pstaff (Carlo Fernandez)', NULL, 'pending', DATE_SUB(NOW(), INTERVAL 1 DAY));

-- ---------------------------------------------------------
-- Sample organizations + assigning the demo hosts to them, so the
-- Organization filter on reports has data to show. INSERT IGNORE (the
-- organization name is UNIQUE) makes this safe to re-run, and the WHERE
-- clauses only touch rows that are still NULL.
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
-- Assign every activity to its school year (by date) and snapshot the
-- host's organization. The same statements run in
-- database/migration_reports.sql for existing installs, and the
-- "Re-sync" button on Super Admin -> School Years can re-run them later.
-- ---------------------------------------------------------
UPDATE activities a
  JOIN school_years sy ON a.activity_date BETWEEN sy.start_date AND sy.end_date
   SET a.school_year_id = sy.id;

UPDATE activities a
  JOIN users u ON u.id = a.host_id
   SET a.organization_id = u.organization_id;
