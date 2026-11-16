-- Down Migration 022: Revert E2 Corrective Checkout Schema
--
-- Safety guarantee:
--   Before dropping any indexes, columns, or constraints, this rollback inspects `payment` for
--   duplicate (provider_name, provider_payment_id, payment_type) references (e.g., a failed attempt
--   followed by a completed capture or multiple failed attempts sharing a provider_payment_id).
--   If incompatible rows exist, rollback stops immediately BEFORE any destructive DDL runs,
--   preserving all payment and payment_reconciliation records untouched.
--
-- To inspect incompatible rows without modifying history:
--   SELECT provider_name, provider_payment_id, payment_type, COUNT(*) AS row_count
--   FROM payment
--   WHERE provider_name IS NOT NULL AND provider_payment_id IS NOT NULL
--   GROUP BY provider_name, provider_payment_id, payment_type
--   HAVING COUNT(*) > 1;

-- 0. Preflight check BEFORE any DDL change
SET @uk_pay_old_pre = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_provider_ref'
);
SET @dup_provider_payment_refs = (
    SELECT COUNT(*) FROM (
        SELECT provider_name, provider_payment_id, payment_type
        FROM payment
        WHERE provider_name IS NOT NULL
          AND provider_payment_id IS NOT NULL
        GROUP BY provider_name, provider_payment_id, payment_type
        HAVING COUNT(*) > 1
    ) dup_refs
);
SET @sql_abort_dup_pay_refs = IF(
    @uk_pay_old_pre = 0 AND @dup_provider_payment_refs > 0,
    'SELECT 1 FROM err_rollback_022_blocked_duplicate_provider_payment_refs_exist',
    'DO 0'
);
PREPARE stmt_abort_dup_pay_refs FROM @sql_abort_dup_pay_refs;
EXECUTE stmt_abort_dup_pay_refs;
DEALLOCATE PREPARE stmt_abort_dup_pay_refs;

-- 1. Revert payment unique/index and generated column back to uk_payment_provider_ref
SET @uk_pay_comp = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_completed_provider_ref'
);
SET @sql_drop_uk_pay_comp = IF(
    @uk_pay_comp > 0,
    'ALTER TABLE payment DROP INDEX uk_payment_completed_provider_ref',
    'DO 0'
);
PREPARE stmt_drop_uk_pay_comp FROM @sql_drop_uk_pay_comp;
EXECUTE stmt_drop_uk_pay_comp;
DEALLOCATE PREPARE stmt_drop_uk_pay_comp;

SET @col_pay_comp = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND COLUMN_NAME = 'completed_provider_ref'
);
SET @sql_drop_col_pay_comp = IF(
    @col_pay_comp > 0,
    'ALTER TABLE payment DROP COLUMN completed_provider_ref',
    'DO 0'
);
PREPARE stmt_drop_col_pay_comp FROM @sql_drop_col_pay_comp;
EXECUTE stmt_drop_col_pay_comp;
DEALLOCATE PREPARE stmt_drop_col_pay_comp;

SET @idx_pay_prov = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'idx_payment_provider_ref'
);
SET @sql_drop_idx_pay_prov = IF(
    @idx_pay_prov > 0,
    'ALTER TABLE payment DROP INDEX idx_payment_provider_ref',
    'DO 0'
);
PREPARE stmt_drop_idx_pay_prov FROM @sql_drop_idx_pay_prov;
EXECUTE stmt_drop_idx_pay_prov;
DEALLOCATE PREPARE stmt_drop_idx_pay_prov;

SET @uk_pay_old = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment' AND INDEX_NAME = 'uk_payment_provider_ref'
);
SET @sql_add_uk_pay_old = IF(
    @uk_pay_old = 0,
    'ALTER TABLE payment ADD UNIQUE KEY uk_payment_provider_ref (provider_name, provider_payment_id, payment_type)',
    'DO 0'
);
PREPARE stmt_add_uk_pay_old FROM @sql_add_uk_pay_old;
EXECUTE stmt_add_uk_pay_old;
DEALLOCATE PREPARE stmt_add_uk_pay_old;

-- 2. Drop business_settings.delivery_fee
SET @chk_bs_fee = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND CONSTRAINT_NAME = 'chk_biz_settings_delivery_fee_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_bs_fee = IF(
    @chk_bs_fee > 0,
    'ALTER TABLE business_settings DROP CHECK chk_biz_settings_delivery_fee_nonneg',
    'DO 0'
);
PREPARE stmt_drop_chk_bs_fee FROM @sql_drop_chk_bs_fee;
EXECUTE stmt_drop_chk_bs_fee;
DEALLOCATE PREPARE stmt_drop_chk_bs_fee;

SET @col_bs_fee = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_settings' AND COLUMN_NAME = 'delivery_fee'
);
SET @sql_drop_col_bs_fee = IF(
    @col_bs_fee > 0,
    'ALTER TABLE business_settings DROP COLUMN delivery_fee',
    'DO 0'
);
PREPARE stmt_drop_col_bs_fee FROM @sql_drop_col_bs_fee;
EXECUTE stmt_drop_col_bs_fee;
DEALLOCATE PREPARE stmt_drop_col_bs_fee;

-- 3. Drop customer_order subtotal_amount and delivery_fee
SET @chk_ord_fee = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_delivery_fee_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_ord_fee = IF(
    @chk_ord_fee > 0,
    'ALTER TABLE customer_order DROP CHECK chk_order_delivery_fee_nonneg',
    'DO 0'
);
PREPARE stmt_drop_chk_ord_fee FROM @sql_drop_chk_ord_fee;
EXECUTE stmt_drop_chk_ord_fee;
DEALLOCATE PREPARE stmt_drop_chk_ord_fee;

SET @chk_ord_sub = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND CONSTRAINT_NAME = 'chk_order_subtotal_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_ord_sub = IF(
    @chk_ord_sub > 0,
    'ALTER TABLE customer_order DROP CHECK chk_order_subtotal_nonneg',
    'DO 0'
);
PREPARE stmt_drop_chk_ord_sub FROM @sql_drop_chk_ord_sub;
EXECUTE stmt_drop_chk_ord_sub;
DEALLOCATE PREPARE stmt_drop_chk_ord_sub;

SET @col_ord_fee = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'delivery_fee'
);
SET @sql_drop_col_ord_fee = IF(
    @col_ord_fee > 0,
    'ALTER TABLE customer_order DROP COLUMN delivery_fee',
    'DO 0'
);
PREPARE stmt_drop_col_ord_fee FROM @sql_drop_col_ord_fee;
EXECUTE stmt_drop_col_ord_fee;
DEALLOCATE PREPARE stmt_drop_col_ord_fee;

SET @col_ord_sub = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'subtotal_amount'
);
SET @sql_drop_col_ord_sub = IF(
    @col_ord_sub > 0,
    'ALTER TABLE customer_order DROP COLUMN subtotal_amount',
    'DO 0'
);
PREPARE stmt_drop_col_ord_sub FROM @sql_drop_col_ord_sub;
EXECUTE stmt_drop_col_ord_sub;
DEALLOCATE PREPARE stmt_drop_col_ord_sub;

-- 4. Drop checkout_attempt active guard, subtotal_amount, and delivery_fee
SET @chk_att_fee = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND CONSTRAINT_NAME = 'chk_chk_att_delivery_fee_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_att_fee = IF(
    @chk_att_fee > 0,
    'ALTER TABLE checkout_attempt DROP CHECK chk_chk_att_delivery_fee_nonneg',
    'DO 0'
);
PREPARE stmt_drop_chk_att_fee FROM @sql_drop_chk_att_fee;
EXECUTE stmt_drop_chk_att_fee;
DEALLOCATE PREPARE stmt_drop_chk_att_fee;

SET @chk_att_sub = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND CONSTRAINT_NAME = 'chk_chk_att_subtotal_nonneg' AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop_chk_att_sub = IF(
    @chk_att_sub > 0,
    'ALTER TABLE checkout_attempt DROP CHECK chk_chk_att_subtotal_nonneg',
    'DO 0'
);
PREPARE stmt_drop_chk_att_sub FROM @sql_drop_chk_att_sub;
EXECUTE stmt_drop_chk_att_sub;
DEALLOCATE PREPARE stmt_drop_chk_att_sub;

SET @col_att_fee = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND COLUMN_NAME = 'delivery_fee'
);
SET @sql_drop_col_att_fee = IF(
    @col_att_fee > 0,
    'ALTER TABLE checkout_attempt DROP COLUMN delivery_fee',
    'DO 0'
);
PREPARE stmt_drop_col_att_fee FROM @sql_drop_col_att_fee;
EXECUTE stmt_drop_col_att_fee;
DEALLOCATE PREPARE stmt_drop_col_att_fee;

SET @col_att_sub = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND COLUMN_NAME = 'subtotal_amount'
);
SET @sql_drop_col_att_sub = IF(
    @col_att_sub > 0,
    'ALTER TABLE checkout_attempt DROP COLUMN subtotal_amount',
    'DO 0'
);
PREPARE stmt_drop_col_att_sub FROM @sql_drop_col_att_sub;
EXECUTE stmt_drop_col_att_sub;
DEALLOCATE PREPARE stmt_drop_col_att_sub;

SET @uk_att_guard = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND INDEX_NAME = 'uk_checkout_attempt_active_customer_bu'
);
SET @sql_drop_uk_att_guard = IF(
    @uk_att_guard > 0,
    'ALTER TABLE checkout_attempt DROP INDEX uk_checkout_attempt_active_customer_bu',
    'DO 0'
);
PREPARE stmt_drop_uk_att_guard FROM @sql_drop_uk_att_guard;
EXECUTE stmt_drop_uk_att_guard;
DEALLOCATE PREPARE stmt_drop_uk_att_guard;

SET @col_att_guard = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkout_attempt' AND COLUMN_NAME = 'active_guard_key'
);
SET @sql_drop_col_att_guard = IF(
    @col_att_guard > 0,
    'ALTER TABLE checkout_attempt DROP COLUMN active_guard_key',
    'DO 0'
);
PREPARE stmt_drop_col_att_guard FROM @sql_drop_col_att_guard;
EXECUTE stmt_drop_col_att_guard;
DEALLOCATE PREPARE stmt_drop_col_att_guard;
