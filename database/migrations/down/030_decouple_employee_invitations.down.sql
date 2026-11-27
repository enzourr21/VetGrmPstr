-- Down migration for 030_decouple_employee_invitations.sql
-- Zero-data-loss fail-closed rollback:
--   Refuses to drop columns or tables if any decoupled employee_invitation row
--   (user_account_id IS NULL OR employee_id IS NULL) has not first been archived
--   into employee_invitation_decoupled_archive and detached cleanly.
--   Run: php scripts/cleanup_invitation_placeholders.php --archive-for-rollback

DROP PROCEDURE IF EXISTS _p_mig_030_down_guard;
CREATE PROCEDURE _p_mig_030_down_guard(IN p_msg VARCHAR(128))
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = p_msg;

SET @decoupled_cnt_030 = (
    SELECT COUNT(*)
    FROM employee_invitation
    WHERE user_account_id IS NULL OR employee_id IS NULL
);
SET @archive_table_exists_030 = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'employee_invitation_decoupled_archive'
);
SET @unarchived_cnt_030 = @decoupled_cnt_030;
SET @sql_cnt_unarch_030 = IF(
    @decoupled_cnt_030 > 0 AND @archive_table_exists_030 > 0,
    'SELECT COUNT(*) INTO @unarchived_cnt_030 FROM employee_invitation ei LEFT JOIN employee_invitation_decoupled_archive arch ON arch.invitation_id = ei.invitation_id WHERE (ei.user_account_id IS NULL OR ei.employee_id IS NULL) AND arch.invitation_id IS NULL',
    'DO 0'
);
PREPARE s_cnt_unarch_030 FROM @sql_cnt_unarch_030;
EXECUTE s_cnt_unarch_030;
DEALLOCATE PREPARE s_cnt_unarch_030;

SET @sql_down_guard_030 = IF(
    @decoupled_cnt_030 > 0,
    IF(
        @unarchived_cnt_030 > 0,
        "CALL _p_mig_030_down_guard('Abort 030 down: unarchived decoupled rows exist. Archive via cleanup_invitation_placeholders.php --archive-for-rollback.')",
        "CALL _p_mig_030_down_guard('Abort 030 down: decoupled rows remain in employee_invitation; pass --detach-archived-for-down after archiving.')"
    ),
    'DO 0'
);
PREPARE s_down_guard_030 FROM @sql_down_guard_030;
EXECUTE s_down_guard_030;
DEALLOCATE PREPARE s_down_guard_030;

ALTER TABLE employee_invitation
    DROP CHECK chk_emp_inv_status_links,
    DROP CHECK chk_emp_inv_identity_pair,
    DROP INDEX uk_emp_inv_active_pending_email,
    DROP INDEX uk_emp_inv_active_pending_username,
    DROP INDEX idx_emp_inv_email_status,
    DROP INDEX idx_emp_inv_unit_status_expires,
    DROP FOREIGN KEY fk_emp_inv_position,
    DROP FOREIGN KEY fk_emp_inv_replaced,
    DROP COLUMN active_pending_email,
    DROP COLUMN active_pending_username,
    DROP COLUMN is_legacy_quarantined,
    DROP COLUMN replaced_invitation_id,
    DROP COLUMN position_name,
    DROP COLUMN position_id,
    DROP COLUMN phone,
    DROP COLUMN username,
    DROP COLUMN last_name,
    DROP COLUMN first_name;

ALTER TABLE employee_invitation
    DROP FOREIGN KEY fk_emp_inv_user,
    DROP FOREIGN KEY fk_emp_inv_employee;

ALTER TABLE employee_invitation
    MODIFY COLUMN user_account_id INT UNSIGNED NOT NULL,
    MODIFY COLUMN employee_id INT UNSIGNED NOT NULL,
    ADD CONSTRAINT fk_emp_inv_user FOREIGN KEY (user_account_id) REFERENCES user_account(user_account_id),
    ADD CONSTRAINT fk_emp_inv_employee FOREIGN KEY (employee_id) REFERENCES employee(employee_id);

DROP TABLE IF EXISTS employee_invitation_role;

DROP PROCEDURE IF EXISTS _p_mig_030_down_guard;
