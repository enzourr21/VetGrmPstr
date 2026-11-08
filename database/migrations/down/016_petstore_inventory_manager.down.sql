-- Down Migration 016: Revert Pet Store Inventory Manager Purchase Order Requests and Order Delivery Status Support

DROP TABLE IF EXISTS purchase_order_request_item;
DROP TABLE IF EXISTS purchase_order_request;

-- Revert added columns from customer_order
SET @col_deid = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'delivery_employee_id');
SET @sql_deid = IF(@col_deid > 0, 'ALTER TABLE customer_order DROP FOREIGN KEY fk_order_deliv_emp, DROP COLUMN delivery_employee_id', 'SELECT 1');
PREPARE stmt_deid FROM @sql_deid;
EXECUTE stmt_deid;
DEALLOCATE PREPARE stmt_deid;

SET @col_dfr = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'delivery_failure_reason');
SET @sql_dfr = IF(@col_dfr > 0, 'ALTER TABLE customer_order DROP COLUMN delivery_failure_reason', 'SELECT 1');
PREPARE stmt_dfr FROM @sql_dfr;
EXECUTE stmt_dfr;
DEALLOCATE PREPARE stmt_dfr;

SET @col_dfat = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'delivery_failed_at');
SET @sql_dfat = IF(@col_dfat > 0, 'ALTER TABLE customer_order DROP COLUMN delivery_failed_at', 'SELECT 1');
PREPARE stmt_dfat FROM @sql_dfat;
EXECUTE stmt_dfat;
DEALLOCATE PREPARE stmt_dfat;

SET @col_resched = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'rescheduled_date');
SET @sql_resched = IF(@col_resched > 0, 'ALTER TABLE customer_order DROP COLUMN rescheduled_date', 'SELECT 1');
PREPARE stmt_resched FROM @sql_resched;
EXECUTE stmt_resched;
DEALLOCATE PREPARE stmt_resched;

ALTER TABLE customer_order MODIFY COLUMN order_status ENUM('pending','confirmed','processing','ready_for_pickup','out_for_delivery','completed','cancelled') NOT NULL DEFAULT 'pending';

