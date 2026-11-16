-- Down Migration 025: Revert Stage 3 COD Remittance Lifecycle
-- Preserves all migration 023 and 024 objects and runs non-destructive preflight checks
-- before any DDL to refuse rollback whenever 025 lifecycle state or settings data would be lost.

-- 0. Preflight checks BEFORE any destructive DDL
DROP PROCEDURE IF EXISTS _p_down_025_preflight;
CREATE PROCEDURE _p_down_025_preflight(IN p_msg VARCHAR(128))
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = p_msg;

-- 0a. Check business_settings.auto_verify_cod_remittance
SET @cnt_down_025_av = 0;
SET @has_down_025_av_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'auto_verify_cod_remittance'
);
SET @sql_chk_down_025_av = IF(
    @has_down_025_av_col > 0,
    'SELECT COUNT(*) INTO @cnt_down_025_av FROM business_settings WHERE auto_verify_cod_remittance <> 0',
    'DO 0'
);
PREPARE stmt_chk_down_025_av FROM @sql_chk_down_025_av;
EXECUTE stmt_chk_down_025_av;
DEALLOCATE PREPARE stmt_chk_down_025_av;

-- 0b. Check courier_cod_remittance rows in 'completed' status
SET @cnt_down_025_remit_comp = 0;
SET @ids_down_025_remit_comp = '';
SET @has_down_025_remit_tbl = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance'
);
SET @sql_chk_down_025_remit = IF(
    @has_down_025_remit_tbl > 0,
    "SELECT COUNT(*), COALESCE(GROUP_CONCAT(remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')
     INTO @cnt_down_025_remit_comp, @ids_down_025_remit_comp
     FROM courier_cod_remittance
     WHERE remittance_status = 'completed'",
    'DO 0'
);
PREPARE stmt_chk_down_025_remit FROM @sql_chk_down_025_remit;
EXECUTE stmt_chk_down_025_remit;
DEALLOCATE PREPARE stmt_chk_down_025_remit;

-- 0c. Check courier_cod_remittance_allocation rows with non-default lifecycle state or metadata
SET @cnt_down_025_alloc_lc = 0;
SET @ids_down_025_alloc_lc = '';
SET @has_down_025_alloc_hs = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'handover_status'
);
SET @sql_chk_down_025_alloc = IF(
    @has_down_025_alloc_hs > 0,
    "SELECT COUNT(*), COALESCE(GROUP_CONCAT(DISTINCT remittance_id ORDER BY remittance_id ASC SEPARATOR ','), '')
     INTO @cnt_down_025_alloc_lc, @ids_down_025_alloc_lc
     FROM courier_cod_remittance_allocation
     WHERE handover_status <> 'submitted'
        OR completed_by_employee_id IS NOT NULL
        OR completed_at IS NOT NULL
        OR verification_status <> 'pending'
        OR verified_by_employee_id IS NOT NULL
        OR verified_at IS NOT NULL
        OR verification_mode IS NOT NULL",
    'DO 0'
);
PREPARE stmt_chk_down_025_alloc FROM @sql_chk_down_025_alloc;
EXECUTE stmt_chk_down_025_alloc;
DEALLOCATE PREPARE stmt_chk_down_025_alloc;

SET @cnt_down_025_total = (@cnt_down_025_av + @cnt_down_025_remit_comp + @cnt_down_025_alloc_lc);
SET @ids_down_025_all = TRIM(BOTH ',' FROM CONCAT_WS(
    ',',
    IF(@ids_down_025_remit_comp <> '', @ids_down_025_remit_comp, NULL),
    IF(@ids_down_025_alloc_lc <> '', @ids_down_025_alloc_lc, NULL)
));
SET @msg_down_025 = LEFT(
    CONCAT(
        'Rollback 025 aborted (av=', @cnt_down_025_av,
        ',comp=', @cnt_down_025_remit_comp,
        ',alloc=', @cnt_down_025_alloc_lc,
        ') batches:', IF(@ids_down_025_all = '', 'none', @ids_down_025_all)
    ),
    128
);
SET @sql_abort_down_025 = IF(
    @cnt_down_025_total > 0,
    'CALL _p_down_025_preflight(@msg_down_025)',
    'DO 0'
);
PREPARE stmt_abort_down_025 FROM @sql_abort_down_025;
EXECUTE stmt_abort_down_025;
DEALLOCATE PREPARE stmt_abort_down_025;
DROP PROCEDURE IF EXISTS _p_down_025_preflight;

-- 1. Drop 025 CHECK constraints on courier_cod_remittance_allocation
SET @chk_alloc_vma = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'chk_cod_alloc_verification_mode_actor' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_alloc_vma = IF(@chk_alloc_vma > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP CHECK chk_cod_alloc_verification_mode_actor', 'DO 0');
PREPARE stmt_drop_chk_alloc_vma FROM @sql_drop_chk_alloc_vma;
EXECUTE stmt_drop_chk_alloc_vma;
DEALLOCATE PREPARE stmt_drop_chk_alloc_vma;

SET @chk_alloc_vt = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'chk_cod_alloc_verification_tuple' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_alloc_vt = IF(@chk_alloc_vt > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP CHECK chk_cod_alloc_verification_tuple', 'DO 0');
PREPARE stmt_drop_chk_alloc_vt FROM @sql_drop_chk_alloc_vt;
EXECUTE stmt_drop_chk_alloc_vt;
DEALLOCATE PREPARE stmt_drop_chk_alloc_vt;

SET @chk_alloc_ht = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'chk_cod_alloc_handover_tuple' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_alloc_ht = IF(@chk_alloc_ht > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP CHECK chk_cod_alloc_handover_tuple', 'DO 0');
PREPARE stmt_drop_chk_alloc_ht FROM @sql_drop_chk_alloc_ht;
EXECUTE stmt_drop_chk_alloc_ht;
DEALLOCATE PREPARE stmt_drop_chk_alloc_ht;

-- 2. Drop 025 foreign keys and indexes on courier_cod_remittance_allocation
SET @fk_alloc_vbe = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_verified_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_alloc_vbe = IF(@fk_alloc_vbe > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP FOREIGN KEY fk_cod_alloc_verified_emp', 'DO 0');
PREPARE stmt_drop_fk_alloc_vbe FROM @sql_drop_fk_alloc_vbe;
EXECUTE stmt_drop_fk_alloc_vbe;
DEALLOCATE PREPARE stmt_drop_fk_alloc_vbe;

SET @fk_alloc_cbe = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_completed_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_alloc_cbe = IF(@fk_alloc_cbe > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP FOREIGN KEY fk_cod_alloc_completed_emp', 'DO 0');
PREPARE stmt_drop_fk_alloc_cbe FROM @sql_drop_fk_alloc_cbe;
EXECUTE stmt_drop_fk_alloc_cbe;
DEALLOCATE PREPARE stmt_drop_fk_alloc_cbe;

SET @idx_alloc_vbe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_verified_emp'
);
SET @sql_drop_idx_alloc_vbe = IF(@idx_alloc_vbe > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP INDEX idx_cod_alloc_verified_emp', 'DO 0');
PREPARE stmt_drop_idx_alloc_vbe FROM @sql_drop_idx_alloc_vbe;
EXECUTE stmt_drop_idx_alloc_vbe;
DEALLOCATE PREPARE stmt_drop_idx_alloc_vbe;

SET @idx_alloc_cbe = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_completed_emp'
);
SET @sql_drop_idx_alloc_cbe = IF(@idx_alloc_cbe > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP INDEX idx_cod_alloc_completed_emp', 'DO 0');
PREPARE stmt_drop_idx_alloc_cbe FROM @sql_drop_idx_alloc_cbe;
EXECUTE stmt_drop_idx_alloc_cbe;
DEALLOCATE PREPARE stmt_drop_idx_alloc_cbe;

SET @idx_alloc_lc = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_remit_lifecycle'
);
SET @sql_drop_idx_alloc_lc = IF(@idx_alloc_lc > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP INDEX idx_cod_alloc_remit_lifecycle', 'DO 0');
PREPARE stmt_drop_idx_alloc_lc FROM @sql_drop_idx_alloc_lc;
EXECUTE stmt_drop_idx_alloc_lc;
DEALLOCATE PREPARE stmt_drop_idx_alloc_lc;

-- 3. Drop 025 columns on courier_cod_remittance_allocation (preserving allocation_status and active_payment_id)
SET @col_alloc_vm = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verification_mode'
);
SET @sql_drop_col_alloc_vm = IF(@col_alloc_vm > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP COLUMN verification_mode', 'DO 0');
PREPARE stmt_drop_col_alloc_vm FROM @sql_drop_col_alloc_vm;
EXECUTE stmt_drop_col_alloc_vm;
DEALLOCATE PREPARE stmt_drop_col_alloc_vm;

SET @col_alloc_va = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verified_at'
);
SET @sql_drop_col_alloc_va = IF(@col_alloc_va > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP COLUMN verified_at', 'DO 0');
PREPARE stmt_drop_col_alloc_va FROM @sql_drop_col_alloc_va;
EXECUTE stmt_drop_col_alloc_va;
DEALLOCATE PREPARE stmt_drop_col_alloc_va;

SET @col_alloc_vbe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verified_by_employee_id'
);
SET @sql_drop_col_alloc_vbe = IF(@col_alloc_vbe > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP COLUMN verified_by_employee_id', 'DO 0');
PREPARE stmt_drop_col_alloc_vbe FROM @sql_drop_col_alloc_vbe;
EXECUTE stmt_drop_col_alloc_vbe;
DEALLOCATE PREPARE stmt_drop_col_alloc_vbe;

SET @col_alloc_vs = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'verification_status'
);
SET @sql_drop_col_alloc_vs = IF(@col_alloc_vs > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP COLUMN verification_status', 'DO 0');
PREPARE stmt_drop_col_alloc_vs FROM @sql_drop_col_alloc_vs;
EXECUTE stmt_drop_col_alloc_vs;
DEALLOCATE PREPARE stmt_drop_col_alloc_vs;

SET @col_alloc_ca = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'completed_at'
);
SET @sql_drop_col_alloc_ca = IF(@col_alloc_ca > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP COLUMN completed_at', 'DO 0');
PREPARE stmt_drop_col_alloc_ca FROM @sql_drop_col_alloc_ca;
EXECUTE stmt_drop_col_alloc_ca;
DEALLOCATE PREPARE stmt_drop_col_alloc_ca;

SET @col_alloc_cbe = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'completed_by_employee_id'
);
SET @sql_drop_col_alloc_cbe = IF(@col_alloc_cbe > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP COLUMN completed_by_employee_id', 'DO 0');
PREPARE stmt_drop_col_alloc_cbe FROM @sql_drop_col_alloc_cbe;
EXECUTE stmt_drop_col_alloc_cbe;
DEALLOCATE PREPARE stmt_drop_col_alloc_cbe;

SET @col_alloc_hs = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND COLUMN_NAME = 'handover_status'
);
SET @sql_drop_col_alloc_hs = IF(@col_alloc_hs > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP COLUMN handover_status', 'DO 0');
PREPARE stmt_drop_col_alloc_hs FROM @sql_drop_col_alloc_hs;
EXECUTE stmt_drop_col_alloc_hs;
DEALLOCATE PREPARE stmt_drop_col_alloc_hs;

-- 4. Drop 025 CHECK constraints on courier_cod_remittance and restore remittance_status ENUM
SET @chk_remit_noself = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance' AND CONSTRAINT_NAME = 'chk_cod_remit_no_self_verify' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_remit_noself = IF(@chk_remit_noself > 0, 'ALTER TABLE courier_cod_remittance DROP CHECK chk_cod_remit_no_self_verify', 'DO 0');
PREPARE stmt_drop_chk_remit_noself FROM @sql_drop_chk_remit_noself;
EXECUTE stmt_drop_chk_remit_noself;
DEALLOCATE PREPARE stmt_drop_chk_remit_noself;

SET @chk_remit_hdr_tuple = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance' AND CONSTRAINT_NAME = 'chk_cod_remit_header_verified_tuple' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_remit_hdr_tuple = IF(@chk_remit_hdr_tuple > 0, 'ALTER TABLE courier_cod_remittance DROP CHECK chk_cod_remit_header_verified_tuple', 'DO 0');
PREPARE stmt_drop_chk_remit_hdr_tuple FROM @sql_drop_chk_remit_hdr_tuple;
EXECUTE stmt_drop_chk_remit_hdr_tuple;
DEALLOCATE PREPARE stmt_drop_chk_remit_hdr_tuple;

SET @has_remit_completed_enum = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'courier_cod_remittance'
      AND COLUMN_NAME = 'remittance_status'
      AND COLUMN_TYPE LIKE "%'completed'%"
);
SET @sql_restore_remit_status = IF(
    @has_remit_completed_enum > 0,
    "ALTER TABLE courier_cod_remittance MODIFY COLUMN remittance_status ENUM('submitted','verified','discrepancy','cancelled') NOT NULL DEFAULT 'submitted'",
    'DO 0'
);
PREPARE stmt_restore_remit_status FROM @sql_restore_remit_status;
EXECUTE stmt_restore_remit_status;
DEALLOCATE PREPARE stmt_restore_remit_status;

-- 5. Drop business_settings CHECK constraint and auto_verify_cod_remittance column
SET @chk_bs_av = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND CONSTRAINT_NAME = 'chk_biz_settings_auto_verify_bool' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_bs_av = IF(@chk_bs_av > 0, 'ALTER TABLE business_settings DROP CHECK chk_biz_settings_auto_verify_bool', 'DO 0');
PREPARE stmt_drop_chk_bs_av FROM @sql_drop_chk_bs_av;
EXECUTE stmt_drop_chk_bs_av;
DEALLOCATE PREPARE stmt_drop_chk_bs_av;

SET @col_bs_av = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'auto_verify_cod_remittance'
);
SET @sql_drop_col_bs_av = IF(@col_bs_av > 0, 'ALTER TABLE business_settings DROP COLUMN auto_verify_cod_remittance', 'DO 0');
PREPARE stmt_drop_col_bs_av FROM @sql_drop_col_bs_av;
EXECUTE stmt_drop_col_bs_av;
DEALLOCATE PREPARE stmt_drop_col_bs_av;
