-- Migration 029: Employee Invitations (System Administrator Provisioning Foundation)
-- Reversible: see database/migrations/down/029_employee_invitations.down.sql
--
-- Design & Timestamp Notes:
--   1. All DATETIME columns store UTC timestamps written by the database/application
--      using UTC_TIMESTAMP() (config/database.php sets time_zone = '+00:00').
--   2. Only a 64-character SHA-256 hex digest (token_hash) is stored; raw invitation
--      tokens are never persisted in the database or audit logs.
--   3. Supports the 48-hour invitation validity period via expires_at (> created_at).
--   4. Invitation lifecycle status (pending, accepted, revoked, expired) is strictly
--      independent of SMTP email submission status (pending, submitted, failed).
--
-- Cross-Table Ownership & Transaction Validation Requirements (for Invitation Service):
--   Because single-column foreign keys cannot enforce cross-table equality between
--   user_account, employee, business_unit, and invited_email, the invitation service MUST
--   enforce the following invariants inside its transaction/row-locking (SELECT ... FOR UPDATE)
--   boundary whenever invitations are created, resent, revoked, or accepted:
--     a) Referenced employee.user_account_id === employee_invitation.user_account_id
--        (and matches the intended user_account row).
--     b) Referenced employee.business_unit_id === employee_invitation.business_unit_id
--        (and matches the System Administrator's authorized business unit on creation/management).
--     c) Normalized employee_invitation.invited_email === normalized user_account.email
--        at both creation and acceptance time (changing a pending account's email invalidates
--        prior pending invitations).

CREATE TABLE IF NOT EXISTS employee_invitation (
    invitation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_account_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    business_unit_id INT UNSIGNED NOT NULL,
    invited_email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    status ENUM('pending', 'accepted', 'revoked', 'expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL DEFAULT NULL,
    revoked_at DATETIME NULL DEFAULT NULL,
    revoke_reason VARCHAR(100) NULL DEFAULT NULL,
    email_submission_status ENUM('pending', 'submitted', 'failed') NOT NULL DEFAULT 'pending',
    email_submitted_at DATETIME NULL DEFAULT NULL,
    email_failed_at DATETIME NULL DEFAULT NULL,
    email_error VARCHAR(255) NULL DEFAULT NULL,
    created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_emp_inv_token_hash (token_hash),
    INDEX idx_emp_inv_employee_status (employee_id, status),
    INDEX idx_emp_inv_user_status (user_account_id, status),
    INDEX idx_emp_inv_unit_status_created (business_unit_id, status, created_at),
    INDEX idx_emp_inv_status_expires (status, expires_at),
    CONSTRAINT chk_emp_inv_token_hash_len CHECK (CHAR_LENGTH(token_hash) = 64),
    CONSTRAINT chk_emp_inv_expiry_window CHECK (expires_at > created_at),
    CONSTRAINT chk_emp_inv_terminal_ts CHECK (
        (status <> 'accepted' OR accepted_at IS NOT NULL)
        AND (status <> 'revoked' OR revoked_at IS NOT NULL)
    ),
    CONSTRAINT chk_emp_inv_mutually_exclusive CHECK (
        NOT (accepted_at IS NOT NULL AND revoked_at IS NOT NULL)
    ),
    CONSTRAINT chk_emp_inv_email_ts CHECK (
        (email_submission_status <> 'submitted' OR email_submitted_at IS NOT NULL)
        AND (email_submission_status <> 'failed' OR email_failed_at IS NOT NULL)
    ),
    CONSTRAINT fk_emp_inv_user FOREIGN KEY (user_account_id) REFERENCES user_account(user_account_id),
    CONSTRAINT fk_emp_inv_employee FOREIGN KEY (employee_id) REFERENCES employee(employee_id),
    CONSTRAINT fk_emp_inv_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    CONSTRAINT fk_emp_inv_creator FOREIGN KEY (created_by_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
