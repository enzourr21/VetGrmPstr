-- Migration 023: Stage 3 Fulfillment & Accounting Schema Foundation
-- Reversible: see database/migrations/down/023_s3_fulfillment_accounting_foundation.down.sql
--
-- Scope (schema & invariants only):
--   1. Structured failure classification and dispute/manual-review columns on `customer_order`.
--   2. Cash-collection metadata and child-refund integrity constraints on `payment` and `refund_request`.
--   3. Courier COD remittance batch and per-payment allocation tables preventing duplicate allocation.

-- 0. Preflight checks BEFORE any DDL changes
DROP PROCEDURE IF EXISTS _p_mig_023_preflight;
CREATE PROCEDURE _p_mig_023_preflight()
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Migration 023 preflight failed: existing payment or refund_request rows violate integrity constraints.';

SET @bad_023_pay = (
    SELECT COUNT(*)
    FROM payment
    WHERE amount <= 0
       OR (payment_type = 'refund' AND original_payment_id IS NULL)
       OR (payment_type = 'payment' AND original_payment_id IS NOT NULL)
);
SET @bad_023_rr = (
    SELECT COUNT(*)
    FROM refund_request
    WHERE requested_amount <= 0
       OR (approved_amount IS NOT NULL AND (approved_amount <= 0 OR approved_amount > requested_amount))
);
SET @sql_023_abort = IF(
    (@bad_023_pay + @bad_023_rr) > 0,
    'CALL _p_mig_023_preflight()',
    'DO 0'
);
PREPARE stmt_023_abort FROM @sql_023_abort;
EXECUTE stmt_023_abort;
DEALLOCATE PREPARE stmt_023_abort;
DROP PROCEDURE IF EXISTS _p_mig_023_preflight;

-- 1. customer_order: failure classification, refund eligibility, and dispute tracking
SET @col_co_fc = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'failure_classification'
);
SET @sql_co_fc = IF(
    @col_co_fc = 0,
    "ALTER TABLE customer_order ADD COLUMN failure_classification ENUM('customer_caused','business_caused','courier_caused','disputed') NULL DEFAULT NULL AFTER rescheduled_date",
    'DO 0'
);
PREPARE stmt_co_fc FROM @sql_co_fc;
EXECUTE stmt_co_fc;
DEALLOCATE PREPARE stmt_co_fc;

SET @col_co_re = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'refund_eligibility'
);
SET @sql_co_re = IF(
    @col_co_re = 0,
    "ALTER TABLE customer_order ADD COLUMN refund_eligibility ENUM('ineligible','eligible','manual_review') NULL DEFAULT NULL AFTER failure_classification",
    'DO 0'
);
PREPARE stmt_co_re FROM @sql_co_re;
EXECUTE stmt_co_re;
DEALLOCATE PREPARE stmt_co_re;

SET @col_co_fce = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'failure_classified_by_employee_id'
);
SET @sql_co_fce = IF(
    @col_co_fce = 0,
    'ALTER TABLE customer_order ADD COLUMN failure_classified_by_employee_id INT UNSIGNED NULL DEFAULT NULL AFTER refund_eligibility',
    'DO 0'
);
PREPARE stmt_co_fce FROM @sql_co_fce;
EXECUTE stmt_co_fce;
DEALLOCATE PREPARE stmt_co_fce;

SET @col_co_fca = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'failure_classified_at'
);
SET @sql_co_fca = IF(
    @col_co_fca = 0,
    'ALTER TABLE customer_order ADD COLUMN failure_classified_at DATETIME NULL DEFAULT NULL AFTER failure_classified_by_employee_id',
    'DO 0'
);
PREPARE stmt_co_fca FROM @sql_co_fca;
EXECUTE stmt_co_fca;
DEALLOCATE PREPARE stmt_co_fca;

SET @col_co_dn = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'dispute_notes'
);
SET @sql_co_dn = IF(
    @col_co_dn = 0,
    'ALTER TABLE customer_order ADD COLUMN dispute_notes VARCHAR(500) NULL DEFAULT NULL AFTER failure_classified_at',
    'DO 0'
);
PREPARE stmt_co_dn FROM @sql_co_dn;
EXECUTE stmt_co_dn;
DEALLOCATE PREPARE stmt_co_dn;

SET @col_co_dre = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'dispute_resolved_by_employee_id'
);
SET @sql_co_dre = IF(
    @col_co_dre = 0,
    'ALTER TABLE customer_order ADD COLUMN dispute_resolved_by_employee_id INT UNSIGNED NULL DEFAULT NULL AFTER dispute_notes',
    'DO 0'
);
PREPARE stmt_co_dre FROM @sql_co_dre;
EXECUTE stmt_co_dre;
DEALLOCATE PREPARE stmt_co_dre;

SET @col_co_dra = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'dispute_resolved_at'
);
SET @sql_co_dra = IF(
    @col_co_dra = 0,
    'ALTER TABLE customer_order ADD COLUMN dispute_resolved_at DATETIME NULL DEFAULT NULL AFTER dispute_resolved_by_employee_id',
    'DO 0'
);
PREPARE stmt_co_dra FROM @sql_co_dra;
EXECUTE stmt_co_dra;
DEALLOCATE PREPARE stmt_co_dra;

SET @fk_co_fce = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'fk_order_failure_classified_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_co_fce = IF(
    @fk_co_fce = 0,
    'ALTER TABLE customer_order ADD CONSTRAINT fk_order_failure_classified_emp FOREIGN KEY (failure_classified_by_employee_id) REFERENCES employee(employee_id)',
    'DO 0'
);
PREPARE stmt_fk_co_fce FROM @sql_fk_co_fce;
EXECUTE stmt_fk_co_fce;
DEALLOCATE PREPARE stmt_fk_co_fce;

SET @fk_co_dre = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'fk_order_dispute_resolved_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_co_dre = IF(
    @fk_co_dre = 0,
    'ALTER TABLE customer_order ADD CONSTRAINT fk_order_dispute_resolved_emp FOREIGN KEY (dispute_resolved_by_employee_id) REFERENCES employee(employee_id)',
    'DO 0'
);
PREPARE stmt_fk_co_dre FROM @sql_fk_co_dre;
EXECUTE stmt_fk_co_dre;
DEALLOCATE PREPARE stmt_fk_co_dre;

SET @idx_co_fc = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND INDEX_NAME = 'idx_order_failure_classification'
);
SET @sql_idx_co_fc = IF(
    @idx_co_fc = 0,
    'ALTER TABLE customer_order ADD INDEX idx_order_failure_classification (business_unit_id, failure_classification, refund_eligibility)',
    'DO 0'
);
PREPARE stmt_idx_co_fc FROM @sql_idx_co_fc;
EXECUTE stmt_idx_co_fc;
DEALLOCATE PREPARE stmt_idx_co_fc;

SET @chk_co_cust_inelig = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_customer_failure_ineligible' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_co_cust = IF(
    @chk_co_cust_inelig = 0,
    "ALTER TABLE customer_order ADD CONSTRAINT chk_order_customer_failure_ineligible CHECK (failure_classification IS NULL OR refund_eligibility IS NULL OR failure_classification <> 'customer_caused' OR refund_eligibility = 'ineligible')",
    'DO 0'
);
PREPARE stmt_chk_co_cust FROM @sql_chk_co_cust;
EXECUTE stmt_chk_co_cust;
DEALLOCATE PREPARE stmt_chk_co_cust;

SET @chk_co_disp_rev = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_disputed_manual_review' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_co_disp = IF(
    @chk_co_disp_rev = 0,
    "ALTER TABLE customer_order ADD CONSTRAINT chk_order_disputed_manual_review CHECK (failure_classification IS NULL OR refund_eligibility IS NULL OR failure_classification <> 'disputed' OR refund_eligibility = 'manual_review')",
    'DO 0'
);
PREPARE stmt_chk_co_disp FROM @sql_chk_co_disp;
EXECUTE stmt_chk_co_disp;
DEALLOCATE PREPARE stmt_chk_co_disp;

-- 2. payment: cash-collection metadata & child-refund integrity
SET @col_pay_cc = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'collection_channel'
);
SET @sql_pay_cc = IF(
    @col_pay_cc = 0,
    "ALTER TABLE payment ADD COLUMN collection_channel ENUM('courier_cod','in_store_pickup','online_gateway','in_store_pos') NULL DEFAULT NULL AFTER provider_payment_id",
    'DO 0'
);
PREPARE stmt_pay_cc FROM @sql_pay_cc;
EXECUTE stmt_pay_cc;
DEALLOCATE PREPARE stmt_pay_cc;

SET @col_pay_ce = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'collected_by_employee_id'
);
SET @sql_pay_ce = IF(
    @col_pay_ce = 0,
    'ALTER TABLE payment ADD COLUMN collected_by_employee_id INT UNSIGNED NULL DEFAULT NULL AFTER collection_channel',
    'DO 0'
);
PREPARE stmt_pay_ce FROM @sql_pay_ce;
EXECUTE stmt_pay_ce;
DEALLOCATE PREPARE stmt_pay_ce;

SET @col_pay_ca = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'collected_at'
);
SET @sql_pay_ca = IF(
    @col_pay_ca = 0,
    'ALTER TABLE payment ADD COLUMN collected_at DATETIME NULL DEFAULT NULL AFTER collected_by_employee_id',
    'DO 0'
);
PREPARE stmt_pay_ca FROM @sql_pay_ca;
EXECUTE stmt_pay_ca;
DEALLOCATE PREPARE stmt_pay_ca;

SET @col_pay_rr = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'refund_request_id'
);
SET @sql_pay_rr = IF(
    @col_pay_rr = 0,
    'ALTER TABLE payment ADD COLUMN refund_request_id INT UNSIGNED NULL DEFAULT NULL AFTER collected_at',
    'DO 0'
);
PREPARE stmt_pay_rr FROM @sql_pay_rr;
EXECUTE stmt_pay_rr;
DEALLOCATE PREPARE stmt_pay_rr;

SET @col_pay_rn = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'refund_execution_notes'
);
SET @sql_pay_rn = IF(
    @col_pay_rn = 0,
    'ALTER TABLE payment ADD COLUMN refund_execution_notes VARCHAR(255) NULL DEFAULT NULL AFTER refund_request_id',
    'DO 0'
);
PREPARE stmt_pay_rn FROM @sql_pay_rn;
EXECUTE stmt_pay_rn;
DEALLOCATE PREPARE stmt_pay_rn;

SET @col_pay_arr = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'active_refund_request_key'
);
SET @sql_pay_arr = IF(
    @col_pay_arr = 0,
    "ALTER TABLE payment ADD COLUMN active_refund_request_key INT UNSIGNED GENERATED ALWAYS AS (IF(payment_type = 'refund' AND payment_status IN ('pending','completed'), refund_request_id, NULL)) VIRTUAL AFTER refund_execution_notes",
    'DO 0'
);
PREPARE stmt_pay_arr FROM @sql_pay_arr;
EXECUTE stmt_pay_arr;
DEALLOCATE PREPARE stmt_pay_arr;

SET @fk_pay_ce = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'fk_payment_collected_emp' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_pay_ce = IF(
    @fk_pay_ce = 0,
    'ALTER TABLE payment ADD CONSTRAINT fk_payment_collected_emp FOREIGN KEY (collected_by_employee_id) REFERENCES employee(employee_id)',
    'DO 0'
);
PREPARE stmt_fk_pay_ce FROM @sql_fk_pay_ce;
EXECUTE stmt_fk_pay_ce;
DEALLOCATE PREPARE stmt_fk_pay_ce;

SET @fk_pay_rr = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'fk_payment_refund_request' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_pay_rr = IF(
    @fk_pay_rr = 0,
    'ALTER TABLE payment ADD CONSTRAINT fk_payment_refund_request FOREIGN KEY (refund_request_id) REFERENCES refund_request(refund_request_id)',
    'DO 0'
);
PREPARE stmt_fk_pay_rr FROM @sql_fk_pay_rr;
EXECUTE stmt_fk_pay_rr;
DEALLOCATE PREPARE stmt_fk_pay_rr;

SET @uk_pay_arr = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_active_refund_request'
);
SET @sql_uk_pay_arr = IF(
    @uk_pay_arr = 0,
    'ALTER TABLE payment ADD UNIQUE KEY uk_payment_active_refund_request (active_refund_request_key)',
    'DO 0'
);
PREPARE stmt_uk_pay_arr FROM @sql_uk_pay_arr;
EXECUTE stmt_uk_pay_arr;
DEALLOCATE PREPARE stmt_uk_pay_arr;

SET @idx_pay_ots = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'idx_payment_order_type_status'
);
SET @sql_idx_pay_ots = IF(
    @idx_pay_ots = 0,
    'ALTER TABLE payment ADD INDEX idx_payment_order_type_status (order_id, payment_type, payment_status)',
    'DO 0'
);
PREPARE stmt_idx_pay_ots FROM @sql_idx_pay_ots;
EXECUTE stmt_idx_pay_ots;
DEALLOCATE PREPARE stmt_idx_pay_ots;

SET @idx_pay_col = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'idx_payment_collection'
);
SET @sql_idx_pay_col = IF(
    @idx_pay_col = 0,
    'ALTER TABLE payment ADD INDEX idx_payment_collection (collection_channel, collected_at)',
    'DO 0'
);
PREPARE stmt_idx_pay_col FROM @sql_idx_pay_col;
EXECUTE stmt_idx_pay_col;
DEALLOCATE PREPARE stmt_idx_pay_col;

SET @chk_pay_amt = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_amount_positive' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_amt = IF(
    @chk_pay_amt = 0,
    'ALTER TABLE payment ADD CONSTRAINT chk_payment_amount_positive CHECK (amount > 0)',
    'DO 0'
);
PREPARE stmt_chk_pay_amt FROM @sql_chk_pay_amt;
EXECUTE stmt_chk_pay_amt;
DEALLOCATE PREPARE stmt_chk_pay_amt;

SET @chk_pay_ref_parent = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_refund_parent' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_ref_parent = IF(
    @chk_pay_ref_parent = 0,
    "ALTER TABLE payment ADD CONSTRAINT chk_payment_refund_parent CHECK (payment_type <> 'refund' OR original_payment_id IS NOT NULL)",
    'DO 0'
);
PREPARE stmt_chk_pay_ref_parent FROM @sql_chk_pay_ref_parent;
EXECUTE stmt_chk_pay_ref_parent;
DEALLOCATE PREPARE stmt_chk_pay_ref_parent;

SET @chk_pay_reg_noparent = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_regular_no_parent' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_reg_noparent = IF(
    @chk_pay_reg_noparent = 0,
    "ALTER TABLE payment ADD CONSTRAINT chk_payment_regular_no_parent CHECK (payment_type <> 'payment' OR original_payment_id IS NULL)",
    'DO 0'
);
PREPARE stmt_chk_pay_reg_noparent FROM @sql_chk_pay_reg_noparent;
EXECUTE stmt_chk_pay_reg_noparent;
DEALLOCATE PREPARE stmt_chk_pay_reg_noparent;

SET @chk_pay_rr_type = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_refund_req_type' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_rr_type = IF(
    @chk_pay_rr_type = 0,
    "ALTER TABLE payment ADD CONSTRAINT chk_payment_refund_req_type CHECK (refund_request_id IS NULL OR payment_type = 'refund')",
    'DO 0'
);
PREPARE stmt_chk_pay_rr_type FROM @sql_chk_pay_rr_type;
EXECUTE stmt_chk_pay_rr_type;
DEALLOCATE PREPARE stmt_chk_pay_rr_type;

SET @chk_pay_coll_stat = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_collected_status' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_coll_stat = IF(
    @chk_pay_coll_stat = 0,
    "ALTER TABLE payment ADD CONSTRAINT chk_payment_collected_status CHECK (collected_at IS NULL OR (payment_type = 'payment' AND payment_status IN ('completed','refunded')))",
    'DO 0'
);
PREPARE stmt_chk_pay_coll_stat FROM @sql_chk_pay_coll_stat;
EXECUTE stmt_chk_pay_coll_stat;
DEALLOCATE PREPARE stmt_chk_pay_coll_stat;

SET @chk_pay_courier_stat = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_courier_cod_status' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_courier_stat = IF(
    @chk_pay_courier_stat = 0,
    "ALTER TABLE payment ADD CONSTRAINT chk_payment_courier_cod_status CHECK (collection_channel IS NULL OR collection_channel <> 'courier_cod' OR (payment_type = 'payment' AND payment_status IN ('completed','refunded')))",
    'DO 0'
);
PREPARE stmt_chk_pay_courier_stat FROM @sql_chk_pay_courier_stat;
EXECUTE stmt_chk_pay_courier_stat;
DEALLOCATE PREPARE stmt_chk_pay_courier_stat;

-- 3. refund_request: amount bounds CHECK constraints
SET @chk_rr_amt = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_request' AND CONSTRAINT_NAME = 'chk_refund_req_amount_positive' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_rr_amt = IF(
    @chk_rr_amt = 0,
    'ALTER TABLE refund_request ADD CONSTRAINT chk_refund_req_amount_positive CHECK (requested_amount > 0)',
    'DO 0'
);
PREPARE stmt_chk_rr_amt FROM @sql_chk_rr_amt;
EXECUTE stmt_chk_rr_amt;
DEALLOCATE PREPARE stmt_chk_rr_amt;

SET @chk_rr_app = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'refund_request' AND CONSTRAINT_NAME = 'chk_refund_req_approved_bounds' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_rr_app = IF(
    @chk_rr_app = 0,
    'ALTER TABLE refund_request ADD CONSTRAINT chk_refund_req_approved_bounds CHECK (approved_amount IS NULL OR (approved_amount > 0 AND approved_amount <= requested_amount))',
    'DO 0'
);
PREPARE stmt_chk_rr_app FROM @sql_chk_rr_app;
EXECUTE stmt_chk_rr_app;
DEALLOCATE PREPARE stmt_chk_rr_app;

-- 4. Courier COD remittance batch header
CREATE TABLE IF NOT EXISTS courier_cod_remittance (
    remittance_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    remittance_reference VARCHAR(50) NOT NULL,
    business_unit_id INT UNSIGNED NOT NULL,
    courier_employee_id INT UNSIGNED NOT NULL,
    expected_total DECIMAL(12,2) NOT NULL,
    remitted_total DECIMAL(12,2) NOT NULL,
    verified_total DECIMAL(12,2) NULL DEFAULT NULL,
    remittance_status ENUM('submitted','verified','discrepancy','cancelled') NOT NULL DEFAULT 'submitted',
    submitted_at DATETIME NOT NULL,
    verified_by_employee_id INT UNSIGNED NULL DEFAULT NULL,
    verified_at DATETIME NULL DEFAULT NULL,
    notes VARCHAR(500) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cod_remittance_ref (remittance_reference),
    INDEX idx_cod_remit_bu_status (business_unit_id, remittance_status),
    INDEX idx_cod_remit_courier (courier_employee_id, remittance_status),
    CONSTRAINT chk_cod_remit_expected_pos CHECK (expected_total > 0),
    CONSTRAINT chk_cod_remit_remitted_nonneg CHECK (remitted_total >= 0),
    CONSTRAINT chk_cod_remit_verified_nonneg CHECK (verified_total IS NULL OR verified_total >= 0),
    CONSTRAINT fk_cod_remit_bu FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    CONSTRAINT fk_cod_remit_courier FOREIGN KEY (courier_employee_id) REFERENCES employee(employee_id),
    CONSTRAINT fk_cod_remit_verifier FOREIGN KEY (verified_by_employee_id) REFERENCES employee(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 5. Per-payment COD remittance allocations (prevents duplicate active remittance allocation per payment)
CREATE TABLE IF NOT EXISTS courier_cod_remittance_allocation (
    allocation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    remittance_id BIGINT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    business_unit_id INT UNSIGNED NOT NULL,
    expected_amount DECIMAL(12,2) NOT NULL,
    allocated_amount DECIMAL(12,2) NOT NULL,
    allocation_status ENUM('active','released') NOT NULL DEFAULT 'active',
    active_payment_id INT UNSIGNED GENERATED ALWAYS AS (IF(allocation_status = 'active', payment_id, NULL)) VIRTUAL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cod_alloc_active_payment (active_payment_id),
    UNIQUE KEY uk_cod_alloc_remit_payment (remittance_id, payment_id),
    INDEX idx_cod_alloc_order (order_id),
    INDEX idx_cod_alloc_bu_status (business_unit_id, allocation_status),
    CONSTRAINT chk_cod_alloc_expected_pos CHECK (expected_amount > 0),
    CONSTRAINT chk_cod_alloc_amount_bounds CHECK (allocated_amount >= 0 AND allocated_amount <= expected_amount),
    CONSTRAINT fk_cod_alloc_remittance FOREIGN KEY (remittance_id) REFERENCES courier_cod_remittance(remittance_id) ON DELETE RESTRICT,
    CONSTRAINT fk_cod_alloc_payment FOREIGN KEY (payment_id) REFERENCES payment(payment_id),
    CONSTRAINT fk_cod_alloc_order FOREIGN KEY (order_id) REFERENCES customer_order(order_id),
    CONSTRAINT fk_cod_alloc_bu FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
