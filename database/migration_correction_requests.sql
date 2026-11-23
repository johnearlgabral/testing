-- =========================================================
-- UBMSConnect - Academic Correction Requests Migration
-- (Super Admin review queue for profile academic information)
--
-- Safe to run on an existing, already-migrated UBMSConnect database.
-- Adds ONE new table only. No existing user, account, or application
-- data is dropped, altered, or deleted - in particular the users
-- table itself is never altered by this migration (a correction
-- request only modifies a user's academic column after a Super
-- Admin explicitly approves it).
-- =========================================================

USE ubmsconnect;

-- ---------------------------------------------------------
-- Academic / organizational correction requests.
--
-- A logged-in user asks a Super Admin to correct their Department,
-- Course, or Organization. Submitting a request only INSERTs a row
-- here and notifies Super Admins; it NEVER writes to the users
-- table. actions/manage_user.php (case 'correction_review') is the
-- single code path that may apply an approved correction.
--
--   field_requested -> which profile column the user wants fixed
--   current_value   -> snapshot of what their profile showed at
--                      submission time (context for the reviewer)
--   requested_value -> the correct value / explanation as typed by
--                      the requester (free text - it is NEVER
--                      auto-trusted; the Super Admin confirms the
--                      exact institutional record to apply)
--   status 'pending'-> awaiting Super Admin review (the queue)
--   'approved'/'rejected' -> terminal review outcomes
--
-- No passwords or other secrets are stored here.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS correction_requests (
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