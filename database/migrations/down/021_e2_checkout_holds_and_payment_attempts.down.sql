-- Down Migration 021: Revert E2 Checkout Attempts, Temporary Holds, Payment Webhook & Reconciliation Tables
-- Preflight checks abort before DDL if active holds or pending reconciliations exist.

-- 0A. Preflight check: refuse rollback if active inventory holds exist
SET @active_holds = 0;
SET @has_inv_hold = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_hold'
);
SET @sql_chk_holds = IF(
    @has_inv_hold > 0,
    'SELECT COUNT(*) INTO @active_holds FROM inventory_hold WHERE hold_status = ''active'' AND expires_at > UTC_TIMESTAMP()',
    'DO 0'
);
PREPARE stmt_chk_holds FROM @sql_chk_holds;
EXECUTE stmt_chk_holds;
DEALLOCATE PREPARE stmt_chk_holds;

SET @sql_abort_holds = IF(
    @active_holds > 0,
    'SELECT 1 FROM err_rollback_blocked_active_inventory_holds',
    'DO 0'
);
PREPARE stmt_abort_holds FROM @sql_abort_holds;
EXECUTE stmt_abort_holds;
DEALLOCATE PREPARE stmt_abort_holds;

-- 0B. Preflight check: refuse rollback if pending payment reconciliations exist
SET @pending_recon = 0;
SET @has_recon = (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment_reconciliation'
);
SET @sql_chk_recon = IF(
    @has_recon > 0,
    'SELECT COUNT(*) INTO @pending_recon FROM payment_reconciliation WHERE reconciliation_status = ''pending_review''',
    'DO 0'
);
PREPARE stmt_chk_recon FROM @sql_chk_recon;
EXECUTE stmt_chk_recon;
DEALLOCATE PREPARE stmt_chk_recon;

SET @sql_abort_recon = IF(
    @pending_recon > 0,
    'SELECT 1 FROM err_rollback_blocked_pending_payment_reconciliations',
    'DO 0'
);
PREPARE stmt_abort_recon FROM @sql_abort_recon;
EXECUTE stmt_abort_recon;
DEALLOCATE PREPARE stmt_abort_recon;

-- 1. Drop dependent tables in reverse dependency order
DROP TABLE IF EXISTS payment_reconciliation;
DROP TABLE IF EXISTS payment_webhook_event;

-- 2. Drop payment FK, indexes, and added columns
SET @fk_pay_attempt = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND CONSTRAINT_NAME = 'fk_payment_attempt'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_drop_fk_pay_attempt = IF(
    @fk_pay_attempt > 0,
    'ALTER TABLE payment DROP FOREIGN KEY fk_payment_attempt',
    'DO 0'
);
PREPARE stmt_drop_fk_pay_attempt FROM @sql_drop_fk_pay_attempt;
EXECUTE stmt_drop_fk_pay_attempt;
DEALLOCATE PREPARE stmt_drop_fk_pay_attempt;

SET @uk_pay_provider_ref = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND INDEX_NAME = 'uk_payment_provider_ref'
);
SET @sql_drop_uk_pay = IF(
    @uk_pay_provider_ref > 0,
    'ALTER TABLE payment DROP INDEX uk_payment_provider_ref',
    'DO 0'
);
PREPARE stmt_drop_uk_pay FROM @sql_drop_uk_pay;
EXECUTE stmt_drop_uk_pay;
DEALLOCATE PREPARE stmt_drop_uk_pay;

SET @idx_pay_attempt = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND INDEX_NAME = 'idx_payment_attempt'
);
SET @sql_drop_idx_pay = IF(
    @idx_pay_attempt > 0,
    'ALTER TABLE payment DROP INDEX idx_payment_attempt',
    'DO 0'
);
PREPARE stmt_drop_idx_pay FROM @sql_drop_idx_pay;
EXECUTE stmt_drop_idx_pay;
DEALLOCATE PREPARE stmt_drop_idx_pay;

SET @col_pay_prov_id = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND COLUMN_NAME = 'provider_payment_id'
);
SET @sql_drop_col_prov_id = IF(
    @col_pay_prov_id > 0,
    'ALTER TABLE payment DROP COLUMN provider_payment_id',
    'DO 0'
);
PREPARE stmt_drop_col_prov_id FROM @sql_drop_col_prov_id;
EXECUTE stmt_drop_col_prov_id;
DEALLOCATE PREPARE stmt_drop_col_prov_id;

SET @col_pay_prov_name = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND COLUMN_NAME = 'provider_name'
);
SET @sql_drop_col_prov_name = IF(
    @col_pay_prov_name > 0,
    'ALTER TABLE payment DROP COLUMN provider_name',
    'DO 0'
);
PREPARE stmt_drop_col_prov_name FROM @sql_drop_col_prov_name;
EXECUTE stmt_drop_col_prov_name;
DEALLOCATE PREPARE stmt_drop_col_prov_name;

SET @col_pay_attempt = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND COLUMN_NAME = 'attempt_id'
);
SET @sql_drop_col_attempt = IF(
    @col_pay_attempt > 0,
    'ALTER TABLE payment DROP COLUMN attempt_id',
    'DO 0'
);
PREPARE stmt_drop_col_attempt FROM @sql_drop_col_attempt;
EXECUTE stmt_drop_col_attempt;
DEALLOCATE PREPARE stmt_drop_col_attempt;

-- 3. Drop hold items, holds, and checkout attempts
DROP TABLE IF EXISTS inventory_hold_item;
DROP TABLE IF EXISTS inventory_hold;
DROP TABLE IF EXISTS checkout_attempt;
