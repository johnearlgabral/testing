-- =========================================================
-- UBMSConnect - Password Recovery Requests Migration
-- (Super Admin-assisted password recovery workflow)
--
-- Safe to run on an existing, already-migrated UBMSConnect database.
-- Adds ONE new table and invalidates outstanding Development-Mode
-- self-service reset LINK tokens. No existing user, account, or
-- application data is dropped, altered, or deleted.
-- =========================================================

USE ubmsconnect;

-- ---------------------------------------------------------
-- Password recovery requests: public submission ledger +
-- Super Admin review queue.
--
--   user_id IS NULL  -> the submitted identifier did not match an
--                       active account. The row exists only for rate
--                       limiting / abuse forensics (status 'ignored')
--                       and is never presented as an actionable
--                       request.
--   status 'pending' -> awaiting Super Admin review (the queue).
--   'duplicate'      -> extra submission while a request was already
--                       pending (recorded, nobody re-notified).
--   'approved'/'rejected'/'handled' -> terminal review outcomes.
--
-- No passwords, reset tokens, or other secrets are ever stored here.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_recovery_requests (
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
-- The Development-Mode flow that printed raw reset links in the
-- browser has been removed. Any still-unused token created under
-- that flow is a usable credential handed out under the old rules,
-- so invalidate every outstanding one. This only touches reset
-- LINK tokens - passwords and all user records are untouched.
-- ---------------------------------------------------------
UPDATE password_reset_tokens SET used_at = NOW() WHERE used_at IS NULL;
