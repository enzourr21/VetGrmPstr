-- Down Migration 020: Revert OD-09 Inventory Schema Foundation
--
-- Rollback Safeguards & Ordering:
--   Step 0 checks run BEFORE any ALTER TABLE so failing preconditions abort cleanly with zero schema changes:
--     0A. Refuses rollback if any inventory row has in_transit <> 0 (would silently lose in-transit stock balances).
--     0B. Refuses rollback if any inventory_reservation row has reservation_status IN ('in_transit', 'returned_pending_inspection').
--
-- MySQL DDL Partial-Failure & Manual Recovery Notes:
--   1. In MySQL (InnoDB), DDL statements issue implicit commits and cannot be wrapped in an atomic transaction.
--   2. Once preconditions pass, narrowing reservation_status ENUM executes first (Step 1), followed by dropping
--      added CHECK constraints, composite indexes, and inventory.in_transit.
--   3. If rollback stops mid-file due to an environmental error, inspect SHOW CREATE TABLE inventory / inventory_reservation
--      and re-run this script after resolving the error (all DDL steps use information_schema guards).

-- 0A. Precondition check: abort before any DDL if inventory.in_transit contains non-zero balances
SET @nonzero_transit = 0;
SET @col_in_transit_pre = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND COLUMN_NAME = 'in_transit'
);
SET @sql_chk_nonzero_transit = IF(
    @col_in_transit_pre > 0,
    'SELECT COUNT(*) INTO @nonzero_transit FROM inventory WHERE in_transit <> 0',
    'SELECT 1'
);
PREPARE stmt_chk_nonzero_transit FROM @sql_chk_nonzero_transit;
EXECUTE stmt_chk_nonzero_transit;
DEALLOCATE PREPARE stmt_chk_nonzero_transit;

SET @sql_abort_nonzero_transit = IF(
    @nonzero_transit > 0,
    'SELECT 1 FROM err_rollback_blocked_nonzero_inventory_in_transit',
    'SELECT 1'
);
PREPARE stmt_abort_nonzero_transit FROM @sql_abort_nonzero_transit;
EXECUTE stmt_abort_nonzero_transit;
DEALLOCATE PREPARE stmt_abort_nonzero_transit;

-- 0B. Precondition check: abort before any DDL if OD-09 reservation_status values are in use
SET @od09_res_count = 0;
SET @has_od09_enum = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_reservation'
      AND COLUMN_NAME = 'reservation_status'
      AND (COLUMN_TYPE LIKE '%in_transit%' OR COLUMN_TYPE LIKE '%returned_pending_inspection%')
);
SET @sql_chk_od09_res = IF(
    @has_od09_enum > 0,
    'SELECT COUNT(*) INTO @od09_res_count FROM inventory_reservation WHERE reservation_status IN (''in_transit'', ''returned_pending_inspection'')',
    'SELECT 1'
);
PREPARE stmt_chk_od09_res FROM @sql_chk_od09_res;
EXECUTE stmt_chk_od09_res;
DEALLOCATE PREPARE stmt_chk_od09_res;

SET @sql_abort_od09_res = IF(
    @od09_res_count > 0,
    'SELECT 1 FROM err_rollback_blocked_active_od09_res_statuses',
    'SELECT 1'
);
PREPARE stmt_abort_od09_res FROM @sql_abort_od09_res;
EXECUTE stmt_abort_od09_res;
DEALLOCATE PREPARE stmt_abort_od09_res;

-- 1. Revert inventory_reservation.reservation_status ENUM back to original values first among DDL changes
ALTER TABLE inventory_reservation
    MODIFY COLUMN reservation_status ENUM('active','fulfilled','cancelled') NOT NULL DEFAULT 'active';

-- 2. Drop CHECK constraint on inventory_reservation
SET @chk_res_qty = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_reservation'
      AND CONSTRAINT_NAME = 'chk_inv_res_qty_positive'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_res_qty = IF(
    @chk_res_qty > 0,
    'ALTER TABLE inventory_reservation DROP CHECK chk_inv_res_qty_positive',
    'SELECT 1'
);
PREPARE stmt_chk_res_qty FROM @sql_chk_res_qty;
EXECUTE stmt_chk_res_qty;
DEALLOCATE PREPARE stmt_chk_res_qty;

-- 3. Drop composite indexes on inventory_reservation (original FK indexes remain intact)
SET @idx_item_status = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_reservation'
      AND INDEX_NAME = 'idx_inv_res_item_status'
);
SET @sql_idx_item_status = IF(
    @idx_item_status > 0,
    'ALTER TABLE inventory_reservation DROP INDEX idx_inv_res_item_status',
    'SELECT 1'
);
PREPARE stmt_idx_item_status FROM @sql_idx_item_status;
EXECUTE stmt_idx_item_status;
DEALLOCATE PREPARE stmt_idx_item_status;

SET @idx_inv_status = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_reservation'
      AND INDEX_NAME = 'idx_inv_res_inv_status'
);
SET @sql_idx_inv_status = IF(
    @idx_inv_status > 0,
    'ALTER TABLE inventory_reservation DROP INDEX idx_inv_res_inv_status',
    'SELECT 1'
);
PREPARE stmt_idx_inv_status FROM @sql_idx_inv_status;
EXECUTE stmt_idx_inv_status;
DEALLOCATE PREPARE stmt_idx_inv_status;

-- 4. Drop CHECK constraints on inventory before dropping in_transit
SET @chk_committed_ge_transit = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND CONSTRAINT_NAME = 'chk_inventory_committed_ge_in_transit'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_committed_ge_transit = IF(
    @chk_committed_ge_transit > 0,
    'ALTER TABLE inventory DROP CHECK chk_inventory_committed_ge_in_transit',
    'SELECT 1'
);
PREPARE stmt_chk_committed_ge_transit FROM @sql_chk_committed_ge_transit;
EXECUTE stmt_chk_committed_ge_transit;
DEALLOCATE PREPARE stmt_chk_committed_ge_transit;

SET @chk_in_transit = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND CONSTRAINT_NAME = 'chk_inventory_in_transit_nonneg'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_in_transit = IF(
    @chk_in_transit > 0,
    'ALTER TABLE inventory DROP CHECK chk_inventory_in_transit_nonneg',
    'SELECT 1'
);
PREPARE stmt_chk_in_transit FROM @sql_chk_in_transit;
EXECUTE stmt_chk_in_transit;
DEALLOCATE PREPARE stmt_chk_in_transit;

SET @chk_committed = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND CONSTRAINT_NAME = 'chk_inventory_committed_nonneg'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_committed = IF(
    @chk_committed > 0,
    'ALTER TABLE inventory DROP CHECK chk_inventory_committed_nonneg',
    'SELECT 1'
);
PREPARE stmt_chk_committed FROM @sql_chk_committed;
EXECUTE stmt_chk_committed;
DEALLOCATE PREPARE stmt_chk_committed;

SET @chk_on_hand = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND CONSTRAINT_NAME = 'chk_inventory_on_hand_nonneg'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_on_hand = IF(
    @chk_on_hand > 0,
    'ALTER TABLE inventory DROP CHECK chk_inventory_on_hand_nonneg',
    'SELECT 1'
);
PREPARE stmt_chk_on_hand FROM @sql_chk_on_hand;
EXECUTE stmt_chk_on_hand;
DEALLOCATE PREPARE stmt_chk_on_hand;

-- 5. Drop inventory.in_transit column
SET @col_in_transit = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND COLUMN_NAME = 'in_transit'
);
SET @sql_in_transit = IF(
    @col_in_transit > 0,
    'ALTER TABLE inventory DROP COLUMN in_transit',
    'SELECT 1'
);
PREPARE stmt_in_transit FROM @sql_in_transit;
EXECUTE stmt_in_transit;
DEALLOCATE PREPARE stmt_in_transit;
