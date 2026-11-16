-- Down Migration 023: Revert Stage 3 Fulfillment & Accounting Schema Foundation
-- Preflight checks abort before any DDL if active remittance allocations, non-cancelled remittances,
-- payment collection/refund-request links, or order failure classifications exist.

-- 0. Preflight check BEFORE any DDL changes
DROP PROCEDURE IF EXISTS _p_down_023_preflight;
CREATE PROCEDURE _p_down_023_preflight()
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Rollback 023 aborted: active Stage 3 accounting, failure, or remittance records exist.';

SET @cnt_023_alloc = 0;
SET @has_023_alloc = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation'
);
SET @sql_chk_023_alloc = IF(
    @has_023_alloc > 0,
    "SELECT COUNT(*) INTO @cnt_023_alloc FROM courier_cod_remittance_allocation WHERE allocation_status = 'active'",
    'DO 0'
);
PREPARE stmt_chk_023_alloc FROM @sql_chk_023_alloc;
EXECUTE stmt_chk_023_alloc;
DEALLOCATE PREPARE stmt_chk_023_alloc;

SET @cnt_023_remit = 0;
SET @has_023_remit = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance'
);
SET @sql_chk_023_remit = IF(
    @has_023_remit > 0,
    "SELECT COUNT(*) INTO @cnt_023_remit FROM courier_cod_remittance WHERE remittance_status <> 'cancelled'",
    'DO 0'
);
PREPARE stmt_chk_023_remit FROM @sql_chk_023_remit;
EXECUTE stmt_chk_023_remit;
DEALLOCATE PREPARE stmt_chk_023_remit;

SET @cnt_023_pay = 0;
SET @has_023_pay_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'collection_channel'
);
SET @sql_chk_023_pay = IF(
    @has_023_pay_col > 0,
    'SELECT COUNT(*) INTO @cnt_023_pay FROM payment WHERE collection_channel IS NOT NULL OR collected_by_employee_id IS NOT NULL OR collected_at IS NOT NULL OR refund_request_id IS NOT NULL',
    'DO 0'
);
PREPARE stmt_chk_023_pay FROM @sql_chk_023_pay;
EXECUTE stmt_chk_023_pay;
DEALLOCATE PREPARE stmt_chk_023_pay;

SET @cnt_023_co = 0;
SET @has_023_co_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'failure_classification'
);
SET @sql_chk_023_co = IF(
    @has_023_co_col > 0,
    'SELECT COUNT(*) INTO @cnt_023_co FROM customer_order WHERE failure_classification IS NOT NULL OR refund_eligibility IS NOT NULL OR failure_classified_by_employee_id IS NOT NULL OR dispute_resolved_by_employee_id IS NOT NULL',
    'DO 0'
);
PREPARE stmt_chk_023_co FROM @sql_chk_023_co;
EXECUTE stmt_chk_023_co;
DEALLOCATE PREPARE stmt_chk_023_co;

SET @sql_abort_023 = IF(
    (@cnt_023_alloc + @cnt_023_remit + @cnt_023_pay + @cnt_023_co) > 0,
    'CALL _p_down_023_preflight()',
    'DO 0'
);
PREPARE stmt_abort_023 FROM @sql_abort_023;
EXECUTE stmt_abort_023;
DEALLOCATE PREPARE stmt_abort_023;
DROP PROCEDURE IF EXISTS _p_down_023_preflight;

-- 1. Drop courier COD remittance tables in reverse FK order
DROP TABLE IF EXISTS courier_cod_remittance_allocation;
DROP TABLE IF EXISTS courier_cod_remittance;

-- 2. Drop refund_request CHECK constraints
SET @chk_rr_app = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_request' AND CONSTRAINT_NAME = 'chk_refund_req_approved_bounds' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_rr_app = IF(@chk_rr_app > 0, 'ALTER TABLE refund_request DROP CHECK chk_refund_req_approved_bounds', 'DO 0');
PREPARE stmt_drop_chk_rr_app FROM @sql_drop_chk_rr_app;
EXECUTE stmt_drop_chk_rr_app;
DEALLOCATE PREPARE stmt_drop_chk_rr_app;

SET @chk_rr_amt = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_request' AND CONSTRAINT_NAME = 'chk_refund_req_amount_positive' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_rr_amt = IF(@chk_rr_amt > 0, 'ALTER TABLE refund_request DROP CHECK chk_refund_req_amount_positive', 'DO 0');
PREPARE stmt_drop_chk_rr_amt FROM @sql_drop_chk_rr_amt;
EXECUTE stmt_drop_chk_rr_amt;
DEALLOCATE PREPARE stmt_drop_chk_rr_amt;

-- 3. Drop payment CHECK constraints, indexes, FKs, and added columns
SET @chk_pay_cs = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_courier_cod_status' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_pay_cs = IF(@chk_pay_cs > 0, 'ALTER TABLE payment DROP CHECK chk_payment_courier_cod_status', 'DO 0');
PREPARE stmt_drop_chk_pay_cs FROM @sql_drop_chk_pay_cs;
EXECUTE stmt_drop_chk_pay_cs;
DEALLOCATE PREPARE stmt_drop_chk_pay_cs;

SET @chk_pay_colstat = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_collected_status' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_pay_colstat = IF(@chk_pay_colstat > 0, 'ALTER TABLE payment DROP CHECK chk_payment_collected_status', 'DO 0');
PREPARE stmt_drop_chk_pay_colstat FROM @sql_drop_chk_pay_colstat;
EXECUTE stmt_drop_chk_pay_colstat;
DEALLOCATE PREPARE stmt_drop_chk_pay_colstat;

SET @chk_pay_rrt = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_refund_req_type' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_pay_rrt = IF(@chk_pay_rrt > 0, 'ALTER TABLE payment DROP CHECK chk_payment_refund_req_type', 'DO 0');
PREPARE stmt_drop_chk_pay_rrt FROM @sql_drop_chk_pay_rrt;
EXECUTE stmt_drop_chk_pay_rrt;
DEALLOCATE PREPARE stmt_drop_chk_pay_rrt;

SET @chk_pay_rnp = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_regular_no_parent' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_pay_rnp = IF(@chk_pay_rnp > 0, 'ALTER TABLE payment DROP CHECK chk_payment_regular_no_parent', 'DO 0');
PREPARE stmt_drop_chk_pay_rnp FROM @sql_drop_chk_pay_rnp;
EXECUTE stmt_drop_chk_pay_rnp;
DEALLOCATE PREPARE stmt_drop_chk_pay_rnp;

SET @chk_pay_rp = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_refund_parent' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_pay_rp = IF(@chk_pay_rp > 0, 'ALTER TABLE payment DROP CHECK chk_payment_refund_parent', 'DO 0');
PREPARE stmt_drop_chk_pay_rp FROM @sql_drop_chk_pay_rp;
EXECUTE stmt_drop_chk_pay_rp;
DEALLOCATE PREPARE stmt_drop_chk_pay_rp;

SET @chk_pay_amt = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_amount_positive' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_pay_amt = IF(@chk_pay_amt > 0, 'ALTER TABLE payment DROP CHECK chk_payment_amount_positive', 'DO 0');
PREPARE stmt_drop_chk_pay_amt FROM @sql_drop_chk_pay_amt;
EXECUTE stmt_drop_chk_pay_amt;
DEALLOCATE PREPARE stmt_drop_chk_pay_amt;

SET @uk_pay_arr = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_active_refund_request'
);
SET @sql_drop_uk_pay_arr = IF(@uk_pay_arr > 0, 'ALTER TABLE payment DROP INDEX uk_payment_active_refund_request', 'DO 0');
PREPARE stmt_drop_uk_pay_arr FROM @sql_drop_uk_pay_arr;
EXECUTE stmt_drop_uk_pay_arr;
DEALLOCATE PREPARE stmt_drop_uk_pay_arr;

SET @idx_pay_col = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'idx_payment_collection'
);
SET @sql_drop_idx_pay_col = IF(@idx_pay_col > 0, 'ALTER TABLE payment DROP INDEX idx_payment_collection', 'DO 0');
PREPARE stmt_drop_idx_pay_col FROM @sql_drop_idx_pay_col;
EXECUTE stmt_drop_idx_pay_col;
DEALLOCATE PREPARE stmt_drop_idx_pay_col;

SET @idx_pay_ots = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'idx_payment_order_type_status'
);
SET @sql_drop_idx_pay_ots = IF(@idx_pay_ots > 0, 'ALTER TABLE payment DROP INDEX idx_payment_order_type_status', 'DO 0');
PREPARE stmt_drop_idx_pay_ots FROM @sql_drop_idx_pay_ots;
EXECUTE stmt_drop_idx_pay_ots;
DEALLOCATE PREPARE stmt_drop_idx_pay_ots;

SET @fk_pay_rr = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'fk_payment_refund_request' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_pay_rr = IF(@fk_pay_rr > 0, 'ALTER TABLE payment DROP FOREIGN KEY fk_payment_refund_request', 'DO 0');
PREPARE stmt_drop_fk_pay_rr FROM @sql_drop_fk_pay_rr;
EXECUTE stmt_drop_fk_pay_rr;
DEALLOCATE PREPARE stmt_drop_fk_pay_rr;

SET @fk_pay_ce = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'fk_payment_collected_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_pay_ce = IF(@fk_pay_ce > 0, 'ALTER TABLE payment DROP FOREIGN KEY fk_payment_collected_emp', 'DO 0');
PREPARE stmt_drop_fk_pay_ce FROM @sql_drop_fk_pay_ce;
EXECUTE stmt_drop_fk_pay_ce;
DEALLOCATE PREPARE stmt_drop_fk_pay_ce;

SET @col_pay_arr = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'active_refund_request_key'
);
SET @sql_drop_col_pay_arr = IF(@col_pay_arr > 0, 'ALTER TABLE payment DROP COLUMN active_refund_request_key', 'DO 0');
PREPARE stmt_drop_col_pay_arr FROM @sql_drop_col_pay_arr;
EXECUTE stmt_drop_col_pay_arr;
DEALLOCATE PREPARE stmt_drop_col_pay_arr;

SET @col_pay_rn = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'refund_execution_notes'
);
SET @sql_drop_col_pay_rn = IF(@col_pay_rn > 0, 'ALTER TABLE payment DROP COLUMN refund_execution_notes', 'DO 0');
PREPARE stmt_drop_col_pay_rn FROM @sql_drop_col_pay_rn;
EXECUTE stmt_drop_col_pay_rn;
DEALLOCATE PREPARE stmt_drop_col_pay_rn;

SET @col_pay_rr = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'refund_request_id'
);
SET @sql_drop_col_pay_rr = IF(@col_pay_rr > 0, 'ALTER TABLE payment DROP COLUMN refund_request_id', 'DO 0');
PREPARE stmt_drop_col_pay_rr FROM @sql_drop_col_pay_rr;
EXECUTE stmt_drop_col_pay_rr;
DEALLOCATE PREPARE stmt_drop_col_pay_rr;

SET @col_pay_ca = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'collected_at'
);
SET @sql_drop_col_pay_ca = IF(@col_pay_ca > 0, 'ALTER TABLE payment DROP COLUMN collected_at', 'DO 0');
PREPARE stmt_drop_col_pay_ca FROM @sql_drop_col_pay_ca;
EXECUTE stmt_drop_col_pay_ca;
DEALLOCATE PREPARE stmt_drop_col_pay_ca;

SET @col_pay_ce = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'collected_by_employee_id'
);
SET @sql_drop_col_pay_ce = IF(@col_pay_ce > 0, 'ALTER TABLE payment DROP COLUMN collected_by_employee_id', 'DO 0');
PREPARE stmt_drop_col_pay_ce FROM @sql_drop_col_pay_ce;
EXECUTE stmt_drop_col_pay_ce;
DEALLOCATE PREPARE stmt_drop_col_pay_ce;

SET @col_pay_cc = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'collection_channel'
);
SET @sql_drop_col_pay_cc = IF(@col_pay_cc > 0, 'ALTER TABLE payment DROP COLUMN collection_channel', 'DO 0');
PREPARE stmt_drop_col_pay_cc FROM @sql_drop_col_pay_cc;
EXECUTE stmt_drop_col_pay_cc;
DEALLOCATE PREPARE stmt_drop_col_pay_cc;

-- 4. Drop customer_order CHECK constraints, index, FKs, and added columns
SET @chk_co_disp = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_disputed_manual_review' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_co_disp = IF(@chk_co_disp > 0, 'ALTER TABLE customer_order DROP CHECK chk_order_disputed_manual_review', 'DO 0');
PREPARE stmt_drop_chk_co_disp FROM @sql_drop_chk_co_disp;
EXECUTE stmt_drop_chk_co_disp;
DEALLOCATE PREPARE stmt_drop_chk_co_disp;

SET @chk_co_cust = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_customer_failure_ineligible' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_co_cust = IF(@chk_co_cust > 0, 'ALTER TABLE customer_order DROP CHECK chk_order_customer_failure_ineligible', 'DO 0');
PREPARE stmt_drop_chk_co_cust FROM @sql_drop_chk_co_cust;
EXECUTE stmt_drop_chk_co_cust;
DEALLOCATE PREPARE stmt_drop_chk_co_cust;

SET @idx_co_fc = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND INDEX_NAME = 'idx_order_failure_classification'
);
SET @sql_drop_idx_co_fc = IF(@idx_co_fc > 0, 'ALTER TABLE customer_order DROP INDEX idx_order_failure_classification', 'DO 0');
PREPARE stmt_drop_idx_co_fc FROM @sql_drop_idx_co_fc;
EXECUTE stmt_drop_idx_co_fc;
DEALLOCATE PREPARE stmt_drop_idx_co_fc;

SET @fk_co_dre = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'fk_order_dispute_resolved_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_co_dre = IF(@fk_co_dre > 0, 'ALTER TABLE customer_order DROP FOREIGN KEY fk_order_dispute_resolved_emp', 'DO 0');
PREPARE stmt_drop_fk_co_dre FROM @sql_drop_fk_co_dre;
EXECUTE stmt_drop_fk_co_dre;
DEALLOCATE PREPARE stmt_drop_fk_co_dre;

SET @fk_co_fce = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'fk_order_failure_classified_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_co_fce = IF(@fk_co_fce > 0, 'ALTER TABLE customer_order DROP FOREIGN KEY fk_order_failure_classified_emp', 'DO 0');
PREPARE stmt_drop_fk_co_fce FROM @sql_drop_fk_co_fce;
EXECUTE stmt_drop_fk_co_fce;
DEALLOCATE PREPARE stmt_drop_fk_co_fce;

SET @col_co_dra = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'dispute_resolved_at'
);
SET @sql_drop_col_co_dra = IF(@col_co_dra > 0, 'ALTER TABLE customer_order DROP COLUMN dispute_resolved_at', 'DO 0');
PREPARE stmt_drop_col_co_dra FROM @sql_drop_col_co_dra;
EXECUTE stmt_drop_col_co_dra;
DEALLOCATE PREPARE stmt_drop_col_co_dra;

SET @col_co_dre = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'dispute_resolved_by_employee_id'
);
SET @sql_drop_col_co_dre = IF(@col_co_dre > 0, 'ALTER TABLE customer_order DROP COLUMN dispute_resolved_by_employee_id', 'DO 0');
PREPARE stmt_drop_col_co_dre FROM @sql_drop_col_co_dre;
EXECUTE stmt_drop_col_co_dre;
DEALLOCATE PREPARE stmt_drop_col_co_dre;

SET @col_co_dn = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'dispute_notes'
);
SET @sql_drop_col_co_dn = IF(@col_co_dn > 0, 'ALTER TABLE customer_order DROP COLUMN dispute_notes', 'DO 0');
PREPARE stmt_drop_col_co_dn FROM @sql_drop_col_co_dn;
EXECUTE stmt_drop_col_co_dn;
DEALLOCATE PREPARE stmt_drop_col_co_dn;

SET @col_co_fca = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'failure_classified_at'
);
SET @sql_drop_col_co_fca = IF(@col_co_fca > 0, 'ALTER TABLE customer_order DROP COLUMN failure_classified_at', 'DO 0');
PREPARE stmt_drop_col_co_fca FROM @sql_drop_col_co_fca;
EXECUTE stmt_drop_col_co_fca;
DEALLOCATE PREPARE stmt_drop_col_co_fca;

SET @col_co_fce = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'failure_classified_by_employee_id'
);
SET @sql_drop_col_co_fce = IF(@col_co_fce > 0, 'ALTER TABLE customer_order DROP COLUMN failure_classified_by_employee_id', 'DO 0');
PREPARE stmt_drop_col_co_fce FROM @sql_drop_col_co_fce;
EXECUTE stmt_drop_col_co_fce;
DEALLOCATE PREPARE stmt_drop_col_co_fce;

SET @col_co_re = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'refund_eligibility'
);
SET @sql_drop_col_co_re = IF(@col_co_re > 0, 'ALTER TABLE customer_order DROP COLUMN refund_eligibility', 'DO 0');
PREPARE stmt_drop_col_co_re FROM @sql_drop_col_co_re;
EXECUTE stmt_drop_col_co_re;
DEALLOCATE PREPARE stmt_drop_col_co_re;

SET @col_co_fc = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'failure_classification'
);
SET @sql_drop_col_co_fc = IF(@col_co_fc > 0, 'ALTER TABLE customer_order DROP COLUMN failure_classification', 'DO 0');
PREPARE stmt_drop_col_co_fc FROM @sql_drop_col_co_fc;
EXECUTE stmt_drop_col_co_fc;
DEALLOCATE PREPARE stmt_drop_col_co_fc;
