-- Migration 022: E2 Corrective Checkout Schema
-- Reversible: see database/migrations/down/022_e2_corrective_checkout_schema.down.sql
--
-- Addresses:
--   1. DB-level single active checkout attempt guard per (customer_id, business_unit_id).
--   2. Allow failed payment attempt rows to coexist with a later verified completed capture
--      for the same (provider_name, provider_payment_id) while keeping completed captures unique.
--   3. Separate subtotal_amount and delivery_fee from total_amount on checkout_attempt and customer_order,
--      plus configurable nullable delivery_fee on business_settings.

-- 0. Preflight check: abort if duplicate active checkout attempts already exist for the same customer and unit
SET @dup_active_attempts = (
    SELECT COUNT(*) FROM (
        SELECT customer_id, business_unit_id
        FROM checkout_attempt
        WHERE attempt_status IN ('initiated', 'awaiting_payment')
        GROUP BY customer_id, business_unit_id
        HAVING COUNT(*) > 1
    ) d
);
SET @sql_abort_dup_attempts = IF(
    @dup_active_attempts > 0,
    'SELECT 1 FROM err_preflight_duplicate_active_checkout_attempts',
    'DO 0'
);
PREPARE stmt_abort_dup_attempts FROM @sql_abort_dup_attempts;
EXECUTE stmt_abort_dup_attempts;
DEALLOCATE PREPARE stmt_abort_dup_attempts;

-- 1. checkout_attempt: generated active_guard_key + unique index + subtotal_amount & delivery_fee
SET @col_att_guard = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND COLUMN_NAME = 'active_guard_key'
);
SET @sql_att_guard = IF(
    @col_att_guard = 0,
    'ALTER TABLE checkout_attempt ADD COLUMN active_guard_key VARCHAR(64) GENERATED ALWAYS AS (IF(attempt_status IN (''initiated'', ''awaiting_payment''), CONCAT(customer_id, '':'', business_unit_id), NULL)) VIRTUAL AFTER attempt_status',
    'DO 0'
);
PREPARE stmt_att_guard FROM @sql_att_guard;
EXECUTE stmt_att_guard;
DEALLOCATE PREPARE stmt_att_guard;

SET @uk_att_guard = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND INDEX_NAME = 'uk_checkout_attempt_active_customer_bu'
);
SET @sql_uk_att_guard = IF(
    @uk_att_guard = 0,
    'ALTER TABLE checkout_attempt ADD UNIQUE KEY uk_checkout_attempt_active_customer_bu (active_guard_key)',
    'DO 0'
);
PREPARE stmt_uk_att_guard FROM @sql_uk_att_guard;
EXECUTE stmt_uk_att_guard;
DEALLOCATE PREPARE stmt_uk_att_guard;

SET @col_att_sub = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND COLUMN_NAME = 'subtotal_amount'
);
SET @sql_att_sub = IF(
    @col_att_sub = 0,
    'ALTER TABLE checkout_attempt ADD COLUMN subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER notes',
    'DO 0'
);
PREPARE stmt_att_sub FROM @sql_att_sub;
EXECUTE stmt_att_sub;
DEALLOCATE PREPARE stmt_att_sub;

SET @col_att_fee = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND COLUMN_NAME = 'delivery_fee'
);
SET @sql_att_fee = IF(
    @col_att_fee = 0,
    'ALTER TABLE checkout_attempt ADD COLUMN delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER subtotal_amount',
    'DO 0'
);
PREPARE stmt_att_fee FROM @sql_att_fee;
EXECUTE stmt_att_fee;
DEALLOCATE PREPARE stmt_att_fee;

UPDATE checkout_attempt
SET subtotal_amount = expected_amount
WHERE subtotal_amount = 0.00 AND delivery_fee = 0.00 AND expected_amount > 0;

SET @chk_att_sub = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND CONSTRAINT_NAME = 'chk_chk_att_subtotal_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_att_sub = IF(
    @chk_att_sub = 0,
    'ALTER TABLE checkout_attempt ADD CONSTRAINT chk_chk_att_subtotal_nonneg CHECK (subtotal_amount >= 0)',
    'DO 0'
);
PREPARE stmt_chk_att_sub FROM @sql_chk_att_sub;
EXECUTE stmt_chk_att_sub;
DEALLOCATE PREPARE stmt_chk_att_sub;

SET @chk_att_fee = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND CONSTRAINT_NAME = 'chk_chk_att_delivery_fee_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_att_fee = IF(
    @chk_att_fee = 0,
    'ALTER TABLE checkout_attempt ADD CONSTRAINT chk_chk_att_delivery_fee_nonneg CHECK (delivery_fee >= 0)',
    'DO 0'
);
PREPARE stmt_chk_att_fee FROM @sql_chk_att_fee;
EXECUTE stmt_chk_att_fee;
DEALLOCATE PREPARE stmt_chk_att_fee;

-- 2. customer_order: separate subtotal_amount and delivery_fee from total_amount
SET @col_ord_sub = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'subtotal_amount'
);
SET @sql_ord_sub = IF(
    @col_ord_sub = 0,
    'ALTER TABLE customer_order ADD COLUMN subtotal_amount DECIMAL(12,2) NULL AFTER total_amount',
    'DO 0'
);
PREPARE stmt_ord_sub FROM @sql_ord_sub;
EXECUTE stmt_ord_sub;
DEALLOCATE PREPARE stmt_ord_sub;

SET @col_ord_fee = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'delivery_fee'
);
SET @sql_ord_fee = IF(
    @col_ord_fee = 0,
    'ALTER TABLE customer_order ADD COLUMN delivery_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER subtotal_amount',
    'DO 0'
);
PREPARE stmt_ord_fee FROM @sql_ord_fee;
EXECUTE stmt_ord_fee;
DEALLOCATE PREPARE stmt_ord_fee;

UPDATE customer_order
SET subtotal_amount = total_amount
WHERE subtotal_amount IS NULL;

SET @chk_ord_sub = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_subtotal_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_ord_sub = IF(
    @chk_ord_sub = 0,
    'ALTER TABLE customer_order ADD CONSTRAINT chk_order_subtotal_nonneg CHECK (subtotal_amount IS NULL OR subtotal_amount >= 0)',
    'DO 0'
);
PREPARE stmt_chk_ord_sub FROM @sql_chk_ord_sub;
EXECUTE stmt_chk_ord_sub;
DEALLOCATE PREPARE stmt_chk_ord_sub;

SET @chk_ord_fee = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_delivery_fee_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_ord_fee = IF(
    @chk_ord_fee = 0,
    'ALTER TABLE customer_order ADD CONSTRAINT chk_order_delivery_fee_nonneg CHECK (delivery_fee >= 0)',
    'DO 0'
);
PREPARE stmt_chk_ord_fee FROM @sql_chk_ord_fee;
EXECUTE stmt_chk_ord_fee;
DEALLOCATE PREPARE stmt_chk_ord_fee;

-- 3. business_settings: configurable nullable delivery_fee (NULL = delivery-fee rule not yet configured)
SET @col_bs_fee = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'delivery_fee'
);
SET @sql_bs_fee = IF(
    @col_bs_fee = 0,
    'ALTER TABLE business_settings ADD COLUMN delivery_fee DECIMAL(10,2) NULL DEFAULT NULL AFTER cod_enabled',
    'DO 0'
);
PREPARE stmt_bs_fee FROM @sql_bs_fee;
EXECUTE stmt_bs_fee;
DEALLOCATE PREPARE stmt_bs_fee;

SET @chk_bs_fee = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND CONSTRAINT_NAME = 'chk_biz_settings_delivery_fee_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_bs_fee = IF(
    @chk_bs_fee = 0,
    'ALTER TABLE business_settings ADD CONSTRAINT chk_biz_settings_delivery_fee_nonneg CHECK (delivery_fee IS NULL OR delivery_fee >= 0)',
    'DO 0'
);
PREPARE stmt_chk_bs_fee FROM @sql_chk_bs_fee;
EXECUTE stmt_chk_bs_fee;
DEALLOCATE PREPARE stmt_chk_bs_fee;

-- 4. payment: allow failed attempts to coexist with a single unique completed capture per provider_payment_id
SET @uk_pay_old = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_provider_ref'
);
SET @sql_drop_uk_pay_old = IF(
    @uk_pay_old > 0,
    'ALTER TABLE payment DROP INDEX uk_payment_provider_ref',
    'DO 0'
);
PREPARE stmt_drop_uk_pay_old FROM @sql_drop_uk_pay_old;
EXECUTE stmt_drop_uk_pay_old;
DEALLOCATE PREPARE stmt_drop_uk_pay_old;

SET @idx_pay_prov = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'idx_payment_provider_ref'
);
SET @sql_idx_pay_prov = IF(
    @idx_pay_prov = 0,
    'ALTER TABLE payment ADD INDEX idx_payment_provider_ref (provider_name, provider_payment_id, payment_type)',
    'DO 0'
);
PREPARE stmt_idx_pay_prov FROM @sql_idx_pay_prov;
EXECUTE stmt_idx_pay_prov;
DEALLOCATE PREPARE stmt_idx_pay_prov;

SET @col_pay_comp_ref = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'completed_provider_ref'
);
SET @sql_pay_comp_ref = IF(
    @col_pay_comp_ref = 0,
    'ALTER TABLE payment ADD COLUMN completed_provider_ref VARCHAR(255) GENERATED ALWAYS AS (IF(payment_type = ''payment'' AND payment_status IN (''completed'', ''refunded'') AND provider_name IS NOT NULL AND provider_payment_id IS NOT NULL, CONCAT(provider_name, '':'', provider_payment_id), NULL)) VIRTUAL AFTER provider_payment_id',
    'DO 0'
);
PREPARE stmt_pay_comp_ref FROM @sql_pay_comp_ref;
EXECUTE stmt_pay_comp_ref;
DEALLOCATE PREPARE stmt_pay_comp_ref;

SET @uk_pay_comp_ref = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_completed_provider_ref'
);
SET @sql_uk_pay_comp_ref = IF(
    @uk_pay_comp_ref = 0,
    'ALTER TABLE payment ADD UNIQUE KEY uk_payment_completed_provider_ref (completed_provider_ref)',
    'DO 0'
);
PREPARE stmt_uk_pay_comp_ref FROM @sql_uk_pay_comp_ref;
EXECUTE stmt_uk_pay_comp_ref;
DEALLOCATE PREPARE stmt_uk_pay_comp_ref;
