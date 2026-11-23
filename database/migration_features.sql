-- =========================================================
-- UBMSConnect - Feature Expansion Migration
-- (Dynamic Venues/Utilities, Notifications, Settings, Password Reset)
--
-- Safe to run on an existing, already-migrated UBMSConnect database
-- (i.e. after database/migration_superadmin.sql has already been
-- applied). Adds columns/tables only - no existing data is dropped
-- or destroyed.
-- =========================================================

USE ubmsconnect;

-- ---------------------------------------------------------
-- 1. Venues: make dynamic (add/edit/deactivate) + add fields
-- ---------------------------------------------------------
ALTER TABLE venues ADD COLUMN location VARCHAR(150) NULL AFTER building;
ALTER TABLE venues ADD COLUMN description TEXT NULL AFTER location;
ALTER TABLE venues ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status;
ALTER TABLE venues ADD COLUMN created_by INT NULL AFTER is_active;
ALTER TABLE venues MODIFY COLUMN status ENUM('Available','In Use','Under Maintenance','Unavailable') NOT NULL DEFAULT 'Available';
ALTER TABLE venues ADD CONSTRAINT fk_venues_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------------------------------------------------
-- 2. Utilities: make dynamic (add/edit/deactivate) + add fields
-- ---------------------------------------------------------
ALTER TABLE utilities ADD COLUMN category VARCHAR(80) NULL AFTER utility_name;
ALTER TABLE utilities ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status;
ALTER TABLE utilities ADD COLUMN created_by INT NULL AFTER is_active;
ALTER TABLE utilities ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by;
ALTER TABLE utilities ADD CONSTRAINT fk_utilities_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;

-- ---------------------------------------------------------
-- 3. Notifications
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
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
-- 4. Notification preferences (one row per user; missing row = all ON)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_preferences (
    user_id INT PRIMARY KEY,
    activity_updates TINYINT(1) NOT NULL DEFAULT 1,
    maintenance_updates TINYINT(1) NOT NULL DEFAULT 1,
    account_updates TINYINT(1) NOT NULL DEFAULT 1,
    admin_updates TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_notifpref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 5. Dashboard preferences (one row per user; missing row = all sections ON)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS dashboard_preferences (
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
-- 6. Password reset tokens (random, hashed at rest, expiring, single-use)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_tokens (
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
-- 7. Track when a password was actually last changed, so the Profile
--    page's "Account Security" section can show real data instead of
--    reusing users.updated_at (which changes on ANY profile edit, not
--    just password changes, and would be misleading).
-- ---------------------------------------------------------
ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER password;

-- ---------------------------------------------------------
-- 8. Profile picture support. NULL = no picture uploaded yet,
--    falls back to the existing initials avatar (no breakage for
--    existing users).
-- ---------------------------------------------------------
ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL AFTER email;

-- ---------------------------------------------------------
-- 9. Per-user "dismissed" audit entries for the Profile page's
--    Recent Activity widget. This does NOT delete anything from
--    audit_logs - it only records which entries a given user has
--    chosen to hide from their own personal summary view. The full
--    audit trail (superadmin/audit_logs.php, staff/logs.php) is
--    completely unaffected and remains the authoritative record.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS dismissed_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    audit_log_id INT NOT NULL,
    dismissed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dismissed_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_dismissed_audit FOREIGN KEY (audit_log_id) REFERENCES audit_logs(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_audit (user_id, audit_log_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- 10. "Remember this device for 30 days" - secure persistent login.
--     Only a SHA-256 hash of the random token is stored; the raw
--     token only ever exists in the browser's cookie. Expiring,
--     revocable, never contains or derives from the password.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_remember_token (token_hash),
    INDEX idx_remember_user (user_id)
) ENGINE=InnoDB;
