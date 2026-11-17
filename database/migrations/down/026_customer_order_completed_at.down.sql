-- Down Migration 026: Revert customer_order.order_completed_at and supporting index

-- 1. Drop idx_order_bu_completed_at if present
SET @idx_down_026 = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND INDEX_NAME = 'idx_order_bu_completed_at'
);
SET @sql_idx_down_026 = IF(
    @idx_down_026 > 0,
    'ALTER TABLE customer_order DROP INDEX idx_order_bu_completed_at',
    'DO 0'
);
PREPARE stmt_idx_down_026 FROM @sql_idx_down_026;
EXECUTE stmt_idx_down_026;
DEALLOCATE PREPARE stmt_idx_down_026;

-- 2. Drop order_completed_at column if present
SET @col_down_026 = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'order_completed_at'
);
SET @sql_col_down_026 = IF(
    @col_down_026 > 0,
    'ALTER TABLE customer_order DROP COLUMN order_completed_at',
    'DO 0'
);
PREPARE stmt_col_down_026 FROM @sql_col_down_026;
EXECUTE stmt_col_down_026;
DEALLOCATE PREPARE stmt_col_down_026;
