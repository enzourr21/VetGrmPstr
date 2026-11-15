-- Migration 020: OD-09 Inventory Schema Foundation
-- Introduces inventory.in_transit, enforces inventory nonnegative and committed >= in_transit >= 0 invariants,
-- extends inventory_reservation.reservation_status for OD-09 lifecycle states, and adds supporting indexes.
-- Reversible: see database/migrations/down/020_od09_inventory_schema_foundation.down.sql
--
-- MySQL DDL Partial-Failure & Manual Recovery Notes:
--   1. In MySQL (InnoDB), DDL statements (ALTER TABLE) trigger implicit commits and are NOT atomic across statements.
--   2. Preflight checks (Step 0) execute BEFORE any ALTER TABLE so existing data violations abort with zero schema changes.
--   3. Do NOT auto-repair failing records; manually investigate/reconcile negative balances or nonpositive reservations first.
--   4. If execution is interrupted mid-file after some DDL statements succeed, schema_migrations will not record 020;
--      inspect SHOW CREATE TABLE inventory / inventory_reservation and re-run safely (each DDL step is guarded via information_schema)
--      or execute down/020_od09_inventory_schema_foundation.down.sql to revert back cleanly.

-- 0A. Preflight validation on inventory baseline quantities (no modifications or repairs performed)
SET @bad_inv_qty = (
    SELECT COUNT(*)
    FROM inventory
    WHERE on_hand IS NULL
       OR on_hand < 0
       OR committed IS NULL
       OR committed < 0
);
SET @sql_abort_inv_qty = IF(
    @bad_inv_qty > 0,
    'SELECT 1 FROM err_preflight_inv_negative_or_invalid_quantities',
    'DO 0'
);
PREPARE stmt_abort_inv_qty FROM @sql_abort_inv_qty;
EXECUTE stmt_abort_inv_qty;
DEALLOCATE PREPARE stmt_abort_inv_qty;

-- 0B. Preflight validation if inventory.in_transit already exists from a prior partial run
SET @bad_inv_transit = 0;
SET @col_in_transit_pre = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND COLUMN_NAME = 'in_transit'
);
SET @sql_chk_inv_transit_pre = IF(
    @col_in_transit_pre > 0,
    'SELECT COUNT(*) INTO @bad_inv_transit FROM inventory WHERE in_transit IS NULL OR in_transit < 0 OR committed < in_transit',
    'DO 0'
);
PREPARE stmt_chk_inv_transit_pre FROM @sql_chk_inv_transit_pre;
EXECUTE stmt_chk_inv_transit_pre;
DEALLOCATE PREPARE stmt_chk_inv_transit_pre;

SET @sql_abort_inv_transit = IF(
    @bad_inv_transit > 0,
    'SELECT 1 FROM err_preflight_inv_committed_lt_in_transit',
    'DO 0'
);
PREPARE stmt_abort_inv_transit FROM @sql_abort_inv_transit;
EXECUTE stmt_abort_inv_transit;
DEALLOCATE PREPARE stmt_abort_inv_transit;

-- 0C. Preflight validation on inventory_reservation.reserved_quantity before adding CHECK constraint
SET @bad_res_qty = (
    SELECT COUNT(*)
    FROM inventory_reservation
    WHERE reserved_quantity IS NULL
       OR reserved_quantity <= 0
);
SET @sql_abort_res_qty = IF(
    @bad_res_qty > 0,
    'SELECT 1 FROM err_preflight_inv_res_nonpositive_reserved_qty',
    'DO 0'
);
PREPARE stmt_abort_res_qty FROM @sql_abort_res_qty;
EXECUTE stmt_abort_res_qty;
DEALLOCATE PREPARE stmt_abort_res_qty;

-- 1. Add inventory.in_transit (DEFAULT 0) after committed if not exists
SET @col_in_transit = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND COLUMN_NAME = 'in_transit'
);
SET @sql_in_transit = IF(
    @col_in_transit = 0,
    'ALTER TABLE inventory ADD COLUMN in_transit INT NOT NULL DEFAULT 0 AFTER committed',
    'DO 0'
);
PREPARE stmt_in_transit FROM @sql_in_transit;
EXECUTE stmt_in_transit;
DEALLOCATE PREPARE stmt_in_transit;

-- 2. Enforce inventory CHECK invariants (on_hand >= 0, committed >= 0, in_transit >= 0, committed >= in_transit)
SET @chk_on_hand = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND CONSTRAINT_NAME = 'chk_inventory_on_hand_nonneg'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_on_hand = IF(
    @chk_on_hand = 0,
    'ALTER TABLE inventory ADD CONSTRAINT chk_inventory_on_hand_nonneg CHECK (on_hand >= 0)',
    'DO 0'
);
PREPARE stmt_chk_on_hand FROM @sql_chk_on_hand;
EXECUTE stmt_chk_on_hand;
DEALLOCATE PREPARE stmt_chk_on_hand;

SET @chk_committed = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND CONSTRAINT_NAME = 'chk_inventory_committed_nonneg'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_committed = IF(
    @chk_committed = 0,
    'ALTER TABLE inventory ADD CONSTRAINT chk_inventory_committed_nonneg CHECK (committed >= 0)',
    'DO 0'
);
PREPARE stmt_chk_committed FROM @sql_chk_committed;
EXECUTE stmt_chk_committed;
DEALLOCATE PREPARE stmt_chk_committed;

SET @chk_in_transit = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND CONSTRAINT_NAME = 'chk_inventory_in_transit_nonneg'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_in_transit = IF(
    @chk_in_transit = 0,
    'ALTER TABLE inventory ADD CONSTRAINT chk_inventory_in_transit_nonneg CHECK (in_transit >= 0)',
    'DO 0'
);
PREPARE stmt_chk_in_transit FROM @sql_chk_in_transit;
EXECUTE stmt_chk_in_transit;
DEALLOCATE PREPARE stmt_chk_in_transit;

SET @chk_committed_ge_transit = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory'
      AND CONSTRAINT_NAME = 'chk_inventory_committed_ge_in_transit'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_committed_ge_transit = IF(
    @chk_committed_ge_transit = 0,
    'ALTER TABLE inventory ADD CONSTRAINT chk_inventory_committed_ge_in_transit CHECK (committed >= in_transit)',
    'DO 0'
);
PREPARE stmt_chk_committed_ge_transit FROM @sql_chk_committed_ge_transit;
EXECUTE stmt_chk_committed_ge_transit;
DEALLOCATE PREPARE stmt_chk_committed_ge_transit;

-- 3. Extend inventory_reservation.reservation_status ENUM for confirmed OD-09 states while preserving 'cancelled'
ALTER TABLE inventory_reservation
    MODIFY COLUMN reservation_status ENUM('active','in_transit','returned_pending_inspection','fulfilled','cancelled') NOT NULL DEFAULT 'active';

-- 4. Enforce positive reserved_quantity on inventory_reservation
SET @chk_res_qty = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_reservation'
      AND CONSTRAINT_NAME = 'chk_inv_res_qty_positive'
      AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_chk_res_qty = IF(
    @chk_res_qty = 0,
    'ALTER TABLE inventory_reservation ADD CONSTRAINT chk_inv_res_qty_positive CHECK (reserved_quantity > 0)',
    'DO 0'
);
PREPARE stmt_chk_res_qty FROM @sql_chk_res_qty;
EXECUTE stmt_chk_res_qty;
DEALLOCATE PREPARE stmt_chk_res_qty;

-- 5. Add composite indexes supporting OD-09 status lookups and invariant reconciliation
SET @idx_inv_status = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_reservation'
      AND INDEX_NAME = 'idx_inv_res_inv_status'
);
SET @sql_idx_inv_status = IF(
    @idx_inv_status = 0,
    'ALTER TABLE inventory_reservation ADD INDEX idx_inv_res_inv_status (inventory_id, reservation_status)',
    'DO 0'
);
PREPARE stmt_idx_inv_status FROM @sql_idx_inv_status;
EXECUTE stmt_idx_inv_status;
DEALLOCATE PREPARE stmt_idx_inv_status;

SET @idx_item_status = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_reservation'
      AND INDEX_NAME = 'idx_inv_res_item_status'
);
SET @sql_idx_item_status = IF(
    @idx_item_status = 0,
    'ALTER TABLE inventory_reservation ADD INDEX idx_inv_res_item_status (order_item_id, reservation_status)',
    'DO 0'
);
PREPARE stmt_idx_item_status FROM @sql_idx_item_status;
EXECUTE stmt_idx_item_status;
DEALLOCATE PREPARE stmt_idx_item_status;
