-- =========================================================
-- UBMSConnect - Super Admin Migration
-- Run this AFTER your existing ubmsconnect database is already
-- installed, to add Super Admin / registration / audit-log support
-- WITHOUT losing any existing data.
--
-- Safe to run once. If a column/table already exists, MySQL will
-- raise a harmless "duplicate column/table" error for that
-- statement only - just skip that line and continue with the rest.
-- =========================================================

USE ubmsconnect;

-- ---------------------------------------------------------
-- 1. Expand the users table
-- ---------------------------------------------------------

-- Add email (nullable so existing rows are not broken, unique once populated)
ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER full_name;

-- Widen the role enum to include super_admin
ALTER TABLE users MODIFY COLUMN role ENUM('super_admin','staff','host') NOT NULL;

-- Add account status (existing accounts default to 'active' so nobody gets locked out)
ALTER TABLE users ADD COLUMN status ENUM('active','pending','inactive','rejected')
    NOT NULL DEFAULT 'active' AFTER role;

-- Add updated_at / last_login tracking
ALTER TABLE users ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER updated_at;

-- Make sure all pre-existing demo accounts are explicitly active
UPDATE users SET status = 'active' WHERE status IS NULL OR status = '';

-- ---------------------------------------------------------
-- 2. Audit log table (system-wide, not just activity status)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
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
-- 3. Optional: seed a DEMO Super Admin so you can log in immediately.
--    Username: superadmin   Password: SuperAdmin123
--    IMPORTANT: change this password (or delete this account and
--    create your own via database/create_superadmin.php) before
--    using this system outside a local capstone demo.
-- ---------------------------------------------------------
INSERT INTO users (username, password, full_name, email, role, status)
SELECT 'superadmin',
       '$2b$10$h6B.MrcBIdjUD1DPuwTLtewexUJaqAEdrHCvxcGI91B.f5rtMnKX6',
       'System Administrator',
       'superadmin@ubmsconnect.local',
       'super_admin',
       'active'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'superadmin');

-- ---------------------------------------------------------
-- 4. Optional: seed one PENDING staff registration so the
--    "Pending Registrations" screen has something to demo.
-- ---------------------------------------------------------
INSERT INTO users (username, password, full_name, email, role, status)
SELECT 'pstaff',
       '$2b$10$7PZ8txdta7peTHW0RoS/yeiVnNTWg6OLkxMMZe8TylmLHFRniLO0.',
       'Carlo Fernandez',
       'carlo.fernandez@ubmsconnect.local',
       'staff',
       'pending'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'pstaff');
-- NOTE: password for the pending demo account is also staff123 (inactive until approved)
