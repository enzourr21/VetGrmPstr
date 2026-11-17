-- Migration 026: Add dedicated completion timestamp to customer_order
-- Reversible: see database/migrations/down/026_customer_order_completed_at.down.sql
-- Leaves existing historical completed orders with order_completed_at = NULL (never backfills from updated_at).

-- 1. Add nullable order_completed_at DATETIME column if absent
SET @col_co_completed_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'order_completed_at'
);
SET @sql_co_completed_at = IF(
    @col_co_completed_at = 0,
    'ALTER TABLE customer_order ADD COLUMN order_completed_at DATETIME NULL DEFAULT NULL AFTER delivery_failed_at',
    'DO 0'
);
PREPARE stmt_co_completed_at FROM @sql_co_completed_at;
EXECUTE stmt_co_completed_at;
DEALLOCATE PREPARE stmt_co_completed_at;

-- 2. Add supporting index for (business_unit_id, order_status, order_completed_at) if absent
SET @idx_co_completed_at = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND INDEX_NAME = 'idx_order_bu_completed_at'
);
SET @sql_idx_co_completed_at = IF(
    @idx_co_completed_at = 0,
    'ALTER TABLE customer_order ADD INDEX idx_order_bu_completed_at (business_unit_id, order_status, order_completed_at)',
    'DO 0'
);
PREPARE stmt_idx_co_completed_at FROM @sql_idx_co_completed_at;
EXECUTE stmt_idx_co_completed_at;
DEALLOCATE PREPARE stmt_idx_co_completed_at;
