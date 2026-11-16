-- Down Migration 024: Revert Stage 3 Fulfillment & Accounting Schema Integrity Corrections
-- Preserves all migration 023 objects and runs comprehensive non-destructive preflight checks
-- across every data-bearing field introduced in migration 023 before dropping 024 constraints/FKs/indexes.

-- 0. Preflight checks BEFORE any destructive DDL
DROP PROCEDURE IF EXISTS _p_down_024_preflight;
CREATE PROCEDURE _p_down_024_preflight()
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Rollback 024 aborted: Stage 3 order failure, dispute, payment collection, or remittance data exists.';

SET @cnt_024_alloc = 0;
SET @has_024_alloc = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation'
);
SET @sql_chk_024_alloc = IF(
    @has_024_alloc > 0,
    'SELECT COUNT(*) INTO @cnt_024_alloc FROM courier_cod_remittance_allocation',
    'DO 0'
);
PREPARE stmt_chk_024_alloc FROM @sql_chk_024_alloc;
EXECUTE stmt_chk_024_alloc;
DEALLOCATE PREPARE stmt_chk_024_alloc;

SET @cnt_024_remit = 0;
SET @has_024_remit = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance'
);
SET @sql_chk_024_remit = IF(
    @has_024_remit > 0,
    'SELECT COUNT(*) INTO @cnt_024_remit FROM courier_cod_remittance',
    'DO 0'
);
PREPARE stmt_chk_024_remit FROM @sql_chk_024_remit;
EXECUTE stmt_chk_024_remit;
DEALLOCATE PREPARE stmt_chk_024_remit;

SET @cnt_024_pay = 0;
SET @has_024_pay_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'collection_channel'
);
SET @sql_chk_024_pay = IF(
    @has_024_pay_col > 0,
    'SELECT COUNT(*) INTO @cnt_024_pay FROM payment WHERE collection_channel IS NOT NULL OR collected_by_employee_id IS NOT NULL OR collected_at IS NOT NULL OR refund_request_id IS NOT NULL OR refund_execution_notes IS NOT NULL',
    'DO 0'
);
PREPARE stmt_chk_024_pay FROM @sql_chk_024_pay;
EXECUTE stmt_chk_024_pay;
DEALLOCATE PREPARE stmt_chk_024_pay;

SET @cnt_024_co = 0;
SET @has_024_co_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'failure_classification'
);
SET @sql_chk_024_co = IF(
    @has_024_co_col > 0,
    'SELECT COUNT(*) INTO @cnt_024_co FROM customer_order WHERE failure_classification IS NOT NULL OR refund_eligibility IS NOT NULL OR failure_classified_by_employee_id IS NOT NULL OR failure_classified_at IS NOT NULL OR dispute_notes IS NOT NULL OR dispute_resolved_by_employee_id IS NOT NULL OR dispute_resolved_at IS NOT NULL',
    'DO 0'
);
PREPARE stmt_chk_024_co FROM @sql_chk_024_co;
EXECUTE stmt_chk_024_co;
DEALLOCATE PREPARE stmt_chk_024_co;

SET @sql_abort_024 = IF(
    (@cnt_024_alloc + @cnt_024_remit + @cnt_024_pay + @cnt_024_co) > 0,
    'CALL _p_down_024_preflight()',
    'DO 0'
);
PREPARE stmt_abort_024 FROM @sql_abort_024;
EXECUTE stmt_abort_024;
DEALLOCATE PREPARE stmt_abort_024;
DROP PROCEDURE IF EXISTS _p_down_024_preflight;

-- 1. Drop 024 payment CHECK constraints
SET @chk_pay_col_ctx = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_channel_context' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_pay_ctx = IF(@chk_pay_col_ctx > 0, 'ALTER TABLE payment DROP CHECK chk_payment_channel_context', 'DO 0');
PREPARE stmt_drop_pay_ctx FROM @sql_drop_pay_ctx;
EXECUTE stmt_drop_pay_ctx;
DEALLOCATE PREPARE stmt_drop_pay_ctx;

SET @chk_pay_col_tuple = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_collection_tuple' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_pay_tuple = IF(@chk_pay_col_tuple > 0, 'ALTER TABLE payment DROP CHECK chk_payment_collection_tuple', 'DO 0');
PREPARE stmt_drop_pay_tuple FROM @sql_drop_pay_tuple;
EXECUTE stmt_drop_pay_tuple;
DEALLOCATE PREPARE stmt_drop_pay_tuple;

SET @chk_pay_col_state = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_collection_requires_collected_state' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_pay_state = IF(@chk_pay_col_state > 0, 'ALTER TABLE payment DROP CHECK chk_payment_collection_requires_collected_state', 'DO 0');
PREPARE stmt_drop_pay_state FROM @sql_drop_pay_state;
EXECUTE stmt_drop_pay_state;
DEALLOCATE PREPARE stmt_drop_pay_state;

-- 2. Drop 024 composite foreign keys, child helper indexes, and parent composite unique indexes in dependency-safe order
SET @fk_alloc_rb = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_remittance_bu' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_alloc_rb = IF(@fk_alloc_rb > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP FOREIGN KEY fk_cod_alloc_remittance_bu', 'DO 0');
PREPARE stmt_drop_fk_alloc_rb FROM @sql_drop_fk_alloc_rb;
EXECUTE stmt_drop_fk_alloc_rb;
DEALLOCATE PREPARE stmt_drop_fk_alloc_rb;

SET @fk_alloc_ob = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_order_bu' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_alloc_ob = IF(@fk_alloc_ob > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP FOREIGN KEY fk_cod_alloc_order_bu', 'DO 0');
PREPARE stmt_drop_fk_alloc_ob FROM @sql_drop_fk_alloc_ob;
EXECUTE stmt_drop_fk_alloc_ob;
DEALLOCATE PREPARE stmt_drop_fk_alloc_ob;

SET @fk_alloc_po = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_payment_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_alloc_po = IF(@fk_alloc_po > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP FOREIGN KEY fk_cod_alloc_payment_order', 'DO 0');
PREPARE stmt_drop_fk_alloc_po FROM @sql_drop_fk_alloc_po;
EXECUTE stmt_drop_fk_alloc_po;
DEALLOCATE PREPARE stmt_drop_fk_alloc_po;

SET @idx_alloc_rb = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_remit_bu'
);
SET @sql_drop_idx_alloc_rb = IF(@idx_alloc_rb > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP INDEX idx_cod_alloc_remit_bu', 'DO 0');
PREPARE stmt_drop_idx_alloc_rb FROM @sql_drop_idx_alloc_rb;
EXECUTE stmt_drop_idx_alloc_rb;
DEALLOCATE PREPARE stmt_drop_idx_alloc_rb;

SET @idx_alloc_ob = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_order_bu'
);
SET @sql_drop_idx_alloc_ob = IF(@idx_alloc_ob > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP INDEX idx_cod_alloc_order_bu', 'DO 0');
PREPARE stmt_drop_idx_alloc_ob FROM @sql_drop_idx_alloc_ob;
EXECUTE stmt_drop_idx_alloc_ob;
DEALLOCATE PREPARE stmt_drop_idx_alloc_ob;

SET @idx_alloc_po = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_payment_order'
);
SET @sql_drop_idx_alloc_po = IF(@idx_alloc_po > 0, 'ALTER TABLE courier_cod_remittance_allocation DROP INDEX idx_cod_alloc_payment_order', 'DO 0');
PREPARE stmt_drop_idx_alloc_po FROM @sql_drop_idx_alloc_po;
EXECUTE stmt_drop_idx_alloc_po;
DEALLOCATE PREPARE stmt_drop_idx_alloc_po;

SET @uk_remit_bu = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance' AND INDEX_NAME = 'uk_cod_remit_id_bu'
);
SET @sql_drop_uk_remit_bu = IF(@uk_remit_bu > 0, 'ALTER TABLE courier_cod_remittance DROP INDEX uk_cod_remit_id_bu', 'DO 0');
PREPARE stmt_drop_uk_remit_bu FROM @sql_drop_uk_remit_bu;
EXECUTE stmt_drop_uk_remit_bu;
DEALLOCATE PREPARE stmt_drop_uk_remit_bu;

SET @uk_ord_bu = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND INDEX_NAME = 'uk_order_id_bu'
);
SET @sql_drop_uk_ord_bu = IF(@uk_ord_bu > 0, 'ALTER TABLE customer_order DROP INDEX uk_order_id_bu', 'DO 0');
PREPARE stmt_drop_uk_ord_bu FROM @sql_drop_uk_ord_bu;
EXECUTE stmt_drop_uk_ord_bu;
DEALLOCATE PREPARE stmt_drop_uk_ord_bu;

SET @uk_pay_ord = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_id_order'
);
SET @sql_drop_uk_pay_ord = IF(@uk_pay_ord > 0, 'ALTER TABLE payment DROP INDEX uk_payment_id_order', 'DO 0');
PREPARE stmt_drop_uk_pay_ord FROM @sql_drop_uk_pay_ord;
EXECUTE stmt_drop_uk_pay_ord;
DEALLOCATE PREPARE stmt_drop_uk_pay_ord;

-- 3. Drop 024 strict customer_order CHECK constraints (preserving 023 CHECK constraints)
SET @chk_co_disp_strict = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_disputed_strict' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_co_disp_strict = IF(@chk_co_disp_strict > 0, 'ALTER TABLE customer_order DROP CHECK chk_order_disputed_strict', 'DO 0');
PREPARE stmt_drop_co_disp_strict FROM @sql_drop_co_disp_strict;
EXECUTE stmt_drop_co_disp_strict;
DEALLOCATE PREPARE stmt_drop_co_disp_strict;

SET @chk_co_cust_strict = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_customer_failure_strict' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_co_cust_strict = IF(@chk_co_cust_strict > 0, 'ALTER TABLE customer_order DROP CHECK chk_order_customer_failure_strict', 'DO 0');
PREPARE stmt_drop_co_cust_strict FROM @sql_drop_co_cust_strict;
EXECUTE stmt_drop_co_cust_strict;
DEALLOCATE PREPARE stmt_drop_co_cust_strict;

SET @chk_co_pair = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_failure_eligibility_pair' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_co_pair = IF(@chk_co_pair > 0, 'ALTER TABLE customer_order DROP CHECK chk_order_failure_eligibility_pair', 'DO 0');
PREPARE stmt_drop_co_pair FROM @sql_drop_co_pair;
EXECUTE stmt_drop_co_pair;
DEALLOCATE PREPARE stmt_drop_co_pair;
