-- Migration 024: Stage 3 Fulfillment & Accounting Schema Integrity Corrections
-- Reversible: see database/migrations/down/024_s3_fulfillment_accounting_integrity.down.sql
--
-- Addresses schema integrity gaps on top of migration 023 (preserving all 023 objects):
--   1. Customer-order failure_classification <-> refund_eligibility atomic pair & NULL-proof rules.
--   2. Composite unique indexes & composite foreign keys enforcing payment/order/business-unit
--      and remittance-header/business-unit consistency on courier_cod_remittance_allocation.
--   3. Coherent payment collection metadata tuples and channel/status/context invariants.

-- 0. Non-destructive preflight checks BEFORE any DDL changes
DROP PROCEDURE IF EXISTS _p_mig_024_preflight;
CREATE PROCEDURE _p_mig_024_preflight()
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Migration 024 preflight failed: existing rows violate Stage 3 integrity invariants.';

SET @bad_024_orders = (
    SELECT COUNT(*)
    FROM customer_order
    WHERE (failure_classification IS NULL AND refund_eligibility IS NOT NULL)
       OR (failure_classification IS NOT NULL AND refund_eligibility IS NULL)
       OR (failure_classification = 'customer_caused' AND (refund_eligibility IS NULL OR refund_eligibility <> 'ineligible'))
       OR (failure_classification = 'disputed' AND (refund_eligibility IS NULL OR refund_eligibility <> 'manual_review'))
);

SET @bad_024_payments = (
    SELECT COUNT(*)
    FROM payment
    WHERE (
        (collection_channel IS NOT NULL OR collected_at IS NOT NULL OR collected_by_employee_id IS NOT NULL)
        AND NOT (payment_type = 'payment' AND payment_status IN ('completed', 'refunded'))
    )
    OR (
        collection_channel IN ('courier_cod', 'in_store_pickup', 'in_store_pos')
        AND (collected_at IS NULL OR collected_by_employee_id IS NULL)
    )
    OR (
        collection_channel = 'online_gateway'
        AND (collected_at IS NULL OR collected_by_employee_id IS NOT NULL)
    )
    OR (
        collection_channel IS NULL
        AND (collected_at IS NOT NULL OR collected_by_employee_id IS NOT NULL)
    )
    OR (
        collection_channel = 'courier_cod'
        AND NOT (order_id IS NOT NULL AND sale_id IS NULL AND invoice_id IS NULL AND LOWER(payment_method) IN ('cod', 'cash'))
    )
    OR (
        collection_channel = 'in_store_pickup'
        AND NOT (order_id IS NOT NULL AND sale_id IS NULL AND invoice_id IS NULL)
    )
    OR (
        collection_channel = 'in_store_pos'
        AND NOT (sale_id IS NOT NULL AND order_id IS NULL AND invoice_id IS NULL)
    )
    OR (
        collection_channel = 'online_gateway'
        AND NOT (provider_name IS NOT NULL AND provider_payment_id IS NOT NULL)
    )
);

SET @bad_024_alloc = (
    SELECT COUNT(*)
    FROM courier_cod_remittance_allocation a
    LEFT JOIN payment p ON p.payment_id = a.payment_id AND p.order_id = a.order_id
    LEFT JOIN customer_order co ON co.order_id = a.order_id AND co.business_unit_id = a.business_unit_id
    LEFT JOIN courier_cod_remittance r ON r.remittance_id = a.remittance_id AND r.business_unit_id = a.business_unit_id
    WHERE p.payment_id IS NULL OR co.order_id IS NULL OR r.remittance_id IS NULL
);

SET @sql_024_abort = IF(
    (@bad_024_orders + @bad_024_payments + @bad_024_alloc) > 0,
    'CALL _p_mig_024_preflight()',
    'DO 0'
);
PREPARE stmt_024_abort FROM @sql_024_abort;
EXECUTE stmt_024_abort;
DEALLOCATE PREPARE stmt_024_abort;
DROP PROCEDURE IF EXISTS _p_mig_024_preflight;

-- 1. customer_order: enforce NULL-proof failure_classification <-> refund_eligibility consistency
SET @chk_co_pair = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_failure_eligibility_pair' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_co_pair = IF(
    @chk_co_pair = 0,
    'ALTER TABLE customer_order ADD CONSTRAINT chk_order_failure_eligibility_pair CHECK ((failure_classification IS NULL AND refund_eligibility IS NULL) OR (failure_classification IS NOT NULL AND refund_eligibility IS NOT NULL))',
    'DO 0'
);
PREPARE stmt_chk_co_pair FROM @sql_chk_co_pair;
EXECUTE stmt_chk_co_pair;
DEALLOCATE PREPARE stmt_chk_co_pair;

SET @chk_co_cust_strict = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_customer_failure_strict' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_co_cust_strict = IF(
    @chk_co_cust_strict = 0,
    "ALTER TABLE customer_order ADD CONSTRAINT chk_order_customer_failure_strict CHECK (failure_classification IS NULL OR failure_classification <> 'customer_caused' OR (refund_eligibility IS NOT NULL AND refund_eligibility = 'ineligible'))",
    'DO 0'
);
PREPARE stmt_chk_co_cust_strict FROM @sql_chk_co_cust_strict;
EXECUTE stmt_chk_co_cust_strict;
DEALLOCATE PREPARE stmt_chk_co_cust_strict;

SET @chk_co_disp_strict = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_disputed_strict' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_co_disp_strict = IF(
    @chk_co_disp_strict = 0,
    "ALTER TABLE customer_order ADD CONSTRAINT chk_order_disputed_strict CHECK (failure_classification IS NULL OR failure_classification <> 'disputed' OR (refund_eligibility IS NOT NULL AND refund_eligibility = 'manual_review'))",
    'DO 0'
);
PREPARE stmt_chk_co_disp_strict FROM @sql_chk_co_disp_strict;
EXECUTE stmt_chk_co_disp_strict;
DEALLOCATE PREPARE stmt_chk_co_disp_strict;

-- 2. Supporting composite unique indexes on parent tables and composite FKs on courier_cod_remittance_allocation
SET @uk_pay_ord = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_id_order'
);
SET @sql_uk_pay_ord = IF(
    @uk_pay_ord = 0,
    'ALTER TABLE payment ADD UNIQUE KEY uk_payment_id_order (payment_id, order_id)',
    'DO 0'
);
PREPARE stmt_uk_pay_ord FROM @sql_uk_pay_ord;
EXECUTE stmt_uk_pay_ord;
DEALLOCATE PREPARE stmt_uk_pay_ord;

SET @uk_ord_bu = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND INDEX_NAME = 'uk_order_id_bu'
);
SET @sql_uk_ord_bu = IF(
    @uk_ord_bu = 0,
    'ALTER TABLE customer_order ADD UNIQUE KEY uk_order_id_bu (order_id, business_unit_id)',
    'DO 0'
);
PREPARE stmt_uk_ord_bu FROM @sql_uk_ord_bu;
EXECUTE stmt_uk_ord_bu;
DEALLOCATE PREPARE stmt_uk_ord_bu;

SET @uk_remit_bu = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance' AND INDEX_NAME = 'uk_cod_remit_id_bu'
);
SET @sql_uk_remit_bu = IF(
    @uk_remit_bu = 0,
    'ALTER TABLE courier_cod_remittance ADD UNIQUE KEY uk_cod_remit_id_bu (remittance_id, business_unit_id)',
    'DO 0'
);
PREPARE stmt_uk_remit_bu FROM @sql_uk_remit_bu;
EXECUTE stmt_uk_remit_bu;
DEALLOCATE PREPARE stmt_uk_remit_bu;

SET @idx_alloc_po = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_payment_order'
);
SET @sql_idx_alloc_po = IF(
    @idx_alloc_po = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD INDEX idx_cod_alloc_payment_order (payment_id, order_id)',
    'DO 0'
);
PREPARE stmt_idx_alloc_po FROM @sql_idx_alloc_po;
EXECUTE stmt_idx_alloc_po;
DEALLOCATE PREPARE stmt_idx_alloc_po;

SET @idx_alloc_ob = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_order_bu'
);
SET @sql_idx_alloc_ob = IF(
    @idx_alloc_ob = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD INDEX idx_cod_alloc_order_bu (order_id, business_unit_id)',
    'DO 0'
);
PREPARE stmt_idx_alloc_ob FROM @sql_idx_alloc_ob;
EXECUTE stmt_idx_alloc_ob;
DEALLOCATE PREPARE stmt_idx_alloc_ob;

SET @idx_alloc_rb = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND INDEX_NAME = 'idx_cod_alloc_remit_bu'
);
SET @sql_idx_alloc_rb = IF(
    @idx_alloc_rb = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD INDEX idx_cod_alloc_remit_bu (remittance_id, business_unit_id)',
    'DO 0'
);
PREPARE stmt_idx_alloc_rb FROM @sql_idx_alloc_rb;
EXECUTE stmt_idx_alloc_rb;
DEALLOCATE PREPARE stmt_idx_alloc_rb;

SET @fk_alloc_po = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_payment_order' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_alloc_po = IF(
    @fk_alloc_po = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD CONSTRAINT fk_cod_alloc_payment_order FOREIGN KEY (payment_id, order_id) REFERENCES payment (payment_id, order_id)',
    'DO 0'
);
PREPARE stmt_fk_alloc_po FROM @sql_fk_alloc_po;
EXECUTE stmt_fk_alloc_po;
DEALLOCATE PREPARE stmt_fk_alloc_po;

SET @fk_alloc_ob = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_order_bu' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_alloc_ob = IF(
    @fk_alloc_ob = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD CONSTRAINT fk_cod_alloc_order_bu FOREIGN KEY (order_id, business_unit_id) REFERENCES customer_order (order_id, business_unit_id)',
    'DO 0'
);
PREPARE stmt_fk_alloc_ob FROM @sql_fk_alloc_ob;
EXECUTE stmt_fk_alloc_ob;
DEALLOCATE PREPARE stmt_fk_alloc_ob;

SET @fk_alloc_rb = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation' AND CONSTRAINT_NAME = 'fk_cod_alloc_remittance_bu' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_alloc_rb = IF(
    @fk_alloc_rb = 0,
    'ALTER TABLE courier_cod_remittance_allocation ADD CONSTRAINT fk_cod_alloc_remittance_bu FOREIGN KEY (remittance_id, business_unit_id) REFERENCES courier_cod_remittance (remittance_id, business_unit_id) ON DELETE RESTRICT',
    'DO 0'
);
PREPARE stmt_fk_alloc_rb FROM @sql_fk_alloc_rb;
EXECUTE stmt_fk_alloc_rb;
DEALLOCATE PREPARE stmt_fk_alloc_rb;

-- 3. payment: enforce coherent collection metadata tuples and channel/status/context combinations
SET @chk_pay_col_state = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_collection_requires_collected_state' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_col_state = IF(
    @chk_pay_col_state = 0,
    "ALTER TABLE payment ADD CONSTRAINT chk_payment_collection_requires_collected_state CHECK ((collection_channel IS NULL AND collected_at IS NULL AND collected_by_employee_id IS NULL) OR (payment_type = 'payment' AND payment_status IN ('completed','refunded')))",
    'DO 0'
);
PREPARE stmt_chk_pay_col_state FROM @sql_chk_pay_col_state;
EXECUTE stmt_chk_pay_col_state;
DEALLOCATE PREPARE stmt_chk_pay_col_state;

SET @chk_pay_col_tuple = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_collection_tuple' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_col_tuple = IF(
    @chk_pay_col_tuple = 0,
    "ALTER TABLE payment ADD CONSTRAINT chk_payment_collection_tuple CHECK ((collection_channel IS NULL AND collected_at IS NULL AND collected_by_employee_id IS NULL) OR (collection_channel IN ('courier_cod','in_store_pickup','in_store_pos') AND collected_at IS NOT NULL AND collected_by_employee_id IS NOT NULL) OR (collection_channel = 'online_gateway' AND collected_at IS NOT NULL AND collected_by_employee_id IS NULL))",
    'DO 0'
);
PREPARE stmt_chk_pay_col_tuple FROM @sql_chk_pay_col_tuple;
EXECUTE stmt_chk_pay_col_tuple;
DEALLOCATE PREPARE stmt_chk_pay_col_tuple;

SET @chk_pay_col_ctx = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND CONSTRAINT_NAME = 'chk_payment_channel_context' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_pay_col_ctx = IF(
    @chk_pay_col_ctx = 0,
    "ALTER TABLE payment ADD CONSTRAINT chk_payment_channel_context CHECK (collection_channel IS NULL OR (collection_channel = 'courier_cod' AND order_id IS NOT NULL AND sale_id IS NULL AND invoice_id IS NULL AND LOWER(payment_method) IN ('cod','cash')) OR (collection_channel = 'in_store_pickup' AND order_id IS NOT NULL AND sale_id IS NULL AND invoice_id IS NULL) OR (collection_channel = 'in_store_pos' AND sale_id IS NOT NULL AND order_id IS NULL AND invoice_id IS NULL) OR (collection_channel = 'online_gateway' AND provider_name IS NOT NULL AND provider_payment_id IS NOT NULL))",
    'DO 0'
);
PREPARE stmt_chk_pay_col_ctx FROM @sql_chk_pay_col_ctx;
EXECUTE stmt_chk_pay_col_ctx;
DEALLOCATE PREPARE stmt_chk_pay_col_ctx;
