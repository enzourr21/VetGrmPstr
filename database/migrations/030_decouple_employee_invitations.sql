-- Migration 030: Decouple Employee Invitations from Account/Employee Records
-- Reversible: see database/migrations/down/030_decouple_employee_invitations.down.sql
--
-- Design & Compatibility Notes:
--   1. Parses cleanly with database/migrate.php statement splitter (no internal semicolons inside routines).
--   2. Backfills intended employee profile snapshots and intended role assignments onto all historical
--      employee_invitation rows before adding NOT NULL / CHECK / active uniqueness constraints.
--   3. Uses MySQL 8.4 STORED generated columns + UNIQUE indexes to guarantee at most one active
--      pending invitation per normalized email (and per optional username) without blocking history.
--   4. Marks pre-existing unaccepted linked rows is_legacy_quarantined = 1 until provenance-safe
--      cleanup detaches verified placeholders, while enforcing user_account_id IS NULL AND
--      employee_id IS NULL on all new pending/expired/revoked invitations via CHECK constraints.

-- 0. Preflight checks BEFORE any DDL changes
DROP PROCEDURE IF EXISTS _p_mig_030_preflight;
CREATE PROCEDURE _p_mig_030_preflight(IN p_msg VARCHAR(128))
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = p_msg;

SET @prereq_030_tables = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN (
          'employee_invitation',
          'user_account',
          'employee',
          'business_unit',
          'business_position',
          'role',
          'account_role'
      )
);
SET @sql_preflight_030 = IF(
    @prereq_030_tables < 7,
    "CALL _p_mig_030_preflight('Mig 030 preflight failed: required tables missing.')",
    'SELECT 1'
);
PREPARE stmt_pf_030 FROM @sql_preflight_030;
EXECUTE stmt_pf_030;
DEALLOCATE PREPARE stmt_pf_030;

-- 1. Create normalized invitation-role junction table
CREATE TABLE IF NOT EXISTS employee_invitation_role (
    invitation_id BIGINT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (invitation_id, role_id),
    CONSTRAINT fk_emp_inv_role_invitation FOREIGN KEY (invitation_id)
        REFERENCES employee_invitation(invitation_id) ON DELETE CASCADE,
    CONSTRAINT fk_emp_inv_role_role FOREIGN KEY (role_id)
        REFERENCES role(role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2. Make user_account_id and employee_id nullable on employee_invitation
SET @has_fk_user_030 = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employee_invitation'
      AND CONSTRAINT_NAME = 'fk_emp_inv_user'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_user_030 = IF(@has_fk_user_030 > 0, 'ALTER TABLE employee_invitation DROP FOREIGN KEY fk_emp_inv_user', 'SELECT 1');
PREPARE s_drop_fk_user_030 FROM @sql_drop_fk_user_030;
EXECUTE s_drop_fk_user_030;
DEALLOCATE PREPARE s_drop_fk_user_030;

SET @has_fk_emp_030 = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employee_invitation'
      AND CONSTRAINT_NAME = 'fk_emp_inv_employee'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_emp_030 = IF(@has_fk_emp_030 > 0, 'ALTER TABLE employee_invitation DROP FOREIGN KEY fk_emp_inv_employee', 'SELECT 1');
PREPARE s_drop_fk_emp_030 FROM @sql_drop_fk_emp_030;
EXECUTE s_drop_fk_emp_030;
DEALLOCATE PREPARE s_drop_fk_emp_030;

ALTER TABLE employee_invitation
    MODIFY COLUMN user_account_id INT UNSIGNED NULL DEFAULT NULL,
    MODIFY COLUMN employee_id INT UNSIGNED NULL DEFAULT NULL,
    ADD CONSTRAINT fk_emp_inv_user FOREIGN KEY (user_account_id) REFERENCES user_account(user_account_id),
    ADD CONSTRAINT fk_emp_inv_employee FOREIGN KEY (employee_id) REFERENCES employee(employee_id);

-- 3. Add intended employee profile snapshot, position snapshot, lineage, and quarantine columns
SET @col_fn_030 = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employee_invitation' AND COLUMN_NAME = 'first_name');
SET @sql_add_cols_030 = IF(
    @col_fn_030 = 0,
    'ALTER TABLE employee_invitation
        ADD COLUMN first_name VARCHAR(100) NULL DEFAULT NULL AFTER invited_email,
        ADD COLUMN last_name VARCHAR(100) NULL DEFAULT NULL AFTER first_name,
        ADD COLUMN username VARCHAR(100) NULL DEFAULT NULL AFTER last_name,
        ADD COLUMN phone VARCHAR(30) NULL DEFAULT NULL AFTER username,
        ADD COLUMN position_id INT UNSIGNED NULL DEFAULT NULL AFTER phone,
        ADD COLUMN position_name VARCHAR(100) NULL DEFAULT NULL AFTER position_id,
        ADD COLUMN replaced_invitation_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER revoke_reason,
        ADD COLUMN is_legacy_quarantined TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by_user_id',
    'SELECT 1'
);
PREPARE s_add_cols_030 FROM @sql_add_cols_030;
EXECUTE s_add_cols_030;
DEALLOCATE PREPARE s_add_cols_030;

-- 4. Backfill intended profile & role snapshots for all existing historical invitations
UPDATE employee_invitation ei
LEFT JOIN employee e ON e.employee_id = ei.employee_id
LEFT JOIN user_account ua ON ua.user_account_id = ei.user_account_id
LEFT JOIN business_position bp ON bp.business_unit_id = ei.business_unit_id AND bp.position_name = e.position
SET ei.first_name = COALESCE(NULLIF(TRIM(ei.first_name), ''), e.first_name, 'Invited'),
    ei.last_name = COALESCE(NULLIF(TRIM(ei.last_name), ''), e.last_name, 'Employee'),
    ei.username = COALESCE(ei.username, ua.username),
    ei.phone = COALESCE(ei.phone, e.phone),
    ei.position_id = COALESCE(ei.position_id, bp.position_id),
    ei.position_name = COALESCE(NULLIF(TRIM(ei.position_name), ''), e.position, 'Staff');

INSERT IGNORE INTO employee_invitation_role (invitation_id, role_id)
SELECT ei.invitation_id, ar.role_id
FROM employee_invitation ei
JOIN account_role ar ON ar.user_account_id = ei.user_account_id AND ar.business_unit_id = ei.business_unit_id
JOIN role r ON r.role_id = ar.role_id
WHERE ei.user_account_id IS NOT NULL
  AND r.role_name <> 'SUPER_ADMINISTRATOR';

-- Sweep any past-due pending rows to expired before enforcing active uniqueness
UPDATE employee_invitation
SET status = 'expired',
    updated_at = UTC_TIMESTAMP()
WHERE status = 'pending'
  AND expires_at <= UTC_TIMESTAMP();

-- Deduplicate any pre-existing multiple pending invitations for the same email (keep newest)
UPDATE employee_invitation ei
JOIN (
    SELECT invited_email, MAX(invitation_id) AS keep_id
    FROM employee_invitation
    WHERE status = 'pending'
    GROUP BY invited_email
    HAVING COUNT(*) > 1
) dup ON dup.invited_email = ei.invited_email
SET ei.status = 'revoked',
    ei.revoked_at = COALESCE(ei.revoked_at, UTC_TIMESTAMP()),
    ei.revoke_reason = COALESCE(ei.revoke_reason, 'superseded_migration'),
    ei.updated_at = UTC_TIMESTAMP()
WHERE ei.status = 'pending'
  AND ei.invitation_id <> dup.keep_id;

-- Deduplicate any pre-existing multiple pending invitations sharing the same non-null username (keep newest)
UPDATE employee_invitation ei
JOIN (
    SELECT username, MAX(invitation_id) AS keep_id
    FROM employee_invitation
    WHERE status = 'pending' AND username IS NOT NULL
    GROUP BY username
    HAVING COUNT(*) > 1
) dup ON dup.username = ei.username
SET ei.status = 'revoked',
    ei.revoked_at = COALESCE(ei.revoked_at, UTC_TIMESTAMP()),
    ei.revoke_reason = COALESCE(ei.revoke_reason, 'superseded_migration'),
    ei.updated_at = UTC_TIMESTAMP()
WHERE ei.status = 'pending'
  AND ei.invitation_id <> dup.keep_id;

-- Mark pre-existing unaccepted linked rows so CHECK constraints pass until provenance cleanup detaches them
UPDATE employee_invitation
SET is_legacy_quarantined = 1
WHERE status <> 'accepted'
  AND (user_account_id IS NOT NULL OR employee_id IS NOT NULL);

-- 5. Enforce NOT NULL snapshot fields, foreign keys, generated active reservation keys, indexes, and CHECK constraints
ALTER TABLE employee_invitation
    MODIFY COLUMN first_name VARCHAR(100) NOT NULL,
    MODIFY COLUMN last_name VARCHAR(100) NOT NULL,
    MODIFY COLUMN position_name VARCHAR(100) NOT NULL;

SET @has_fk_pos_030 = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employee_invitation'
      AND CONSTRAINT_NAME = 'fk_emp_inv_position'
);
SET @sql_fk_pos_030 = IF(
    @has_fk_pos_030 = 0,
    'ALTER TABLE employee_invitation
        ADD CONSTRAINT fk_emp_inv_position FOREIGN KEY (position_id) REFERENCES business_position(position_id) ON DELETE SET NULL,
        ADD CONSTRAINT fk_emp_inv_replaced FOREIGN KEY (replaced_invitation_id) REFERENCES employee_invitation(invitation_id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE s_fk_pos_030 FROM @sql_fk_pos_030;
EXECUTE s_fk_pos_030;
DEALLOCATE PREPARE s_fk_pos_030;

SET @has_gen_email_030 = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employee_invitation'
      AND COLUMN_NAME = 'active_pending_email'
);
SET @sql_gen_030 = IF(
    @has_gen_email_030 = 0,
    "ALTER TABLE employee_invitation
        ADD COLUMN active_pending_email VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN invited_email ELSE NULL END) STORED AFTER invited_email,
        ADD COLUMN active_pending_username VARCHAR(100) GENERATED ALWAYS AS (CASE WHEN status = 'pending' AND username IS NOT NULL THEN username ELSE NULL END) STORED AFTER username,
        ADD UNIQUE KEY uk_emp_inv_active_pending_email (active_pending_email),
        ADD UNIQUE KEY uk_emp_inv_active_pending_username (active_pending_username),
        ADD INDEX idx_emp_inv_email_status (invited_email, status, invitation_id),
        ADD INDEX idx_emp_inv_unit_status_expires (business_unit_id, status, expires_at, created_at)",
    'SELECT 1'
);
PREPARE s_gen_030 FROM @sql_gen_030;
EXECUTE s_gen_030;
DEALLOCATE PREPARE s_gen_030;

SET @has_chk_pair_030 = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employee_invitation'
      AND CONSTRAINT_NAME = 'chk_emp_inv_identity_pair'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_030 = IF(
    @has_chk_pair_030 = 0,
    "ALTER TABLE employee_invitation
        ADD CONSTRAINT chk_emp_inv_identity_pair CHECK (
            (user_account_id IS NULL AND employee_id IS NULL)
            OR (user_account_id IS NOT NULL AND employee_id IS NOT NULL)
        ),
        ADD CONSTRAINT chk_emp_inv_status_links CHECK (
            (status = 'accepted' AND user_account_id IS NOT NULL AND employee_id IS NOT NULL AND accepted_at IS NOT NULL)
            OR (
                status <> 'accepted'
                AND accepted_at IS NULL
                AND ((user_account_id IS NULL AND employee_id IS NULL) OR is_legacy_quarantined = 1)
            )
        )",
    'SELECT 1'
);
PREPARE s_chk_030 FROM @sql_chk_030;
EXECUTE s_chk_030;
DEALLOCATE PREPARE s_chk_030;

DROP PROCEDURE IF EXISTS _p_mig_030_preflight;
