-- Migration 016: Pet Store Inventory Manager Purchase Order Requests and Order Delivery Status Support
-- Reversible: see database/migrations/down/016_petstore_inventory_manager.down.sql

-- 1. Create purchase_order_request table
CREATE TABLE IF NOT EXISTS purchase_order_request (
    request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(50) NOT NULL UNIQUE,
    business_unit_id INT UNSIGNED NOT NULL,
    requested_by_employee_id INT UNSIGNED NOT NULL,
    request_date DATETIME NOT NULL,
    reason VARCHAR(255) NOT NULL,
    priority ENUM('Low', 'Medium', 'High', 'Urgent') NOT NULL DEFAULT 'Medium',
    needed_by_date DATE NULL,
    notes TEXT NULL,
    request_status ENUM('Pending', 'Approved', 'Rejected', 'Fulfilled') NOT NULL DEFAULT 'Pending',
    reviewed_by_employee_id INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    FOREIGN KEY (requested_by_employee_id) REFERENCES employee(employee_id),
    FOREIGN KEY (reviewed_by_employee_id) REFERENCES employee(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create purchase_order_request_item table
CREATE TABLE IF NOT EXISTS purchase_order_request_item (
    request_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    business_product_id INT UNSIGNED NOT NULL,
    quantity_requested INT NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES purchase_order_request(request_id) ON DELETE CASCADE,
    FOREIGN KEY (business_product_id) REFERENCES business_product(business_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add delivery_failed to customer_order order_status enum
ALTER TABLE customer_order MODIFY COLUMN order_status ENUM('pending','confirmed','processing','ready_for_pickup','out_for_delivery','completed','cancelled','delivery_failed') NOT NULL DEFAULT 'pending';

-- 4. Add delivery detail columns to customer_order if not existing
SET @col_dfr = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'delivery_failure_reason');
SET @sql_dfr = IF(@col_dfr = 0, 'ALTER TABLE customer_order ADD COLUMN delivery_failure_reason VARCHAR(255) NULL AFTER notes', 'SELECT 1');
PREPARE stmt_dfr FROM @sql_dfr;
EXECUTE stmt_dfr;
DEALLOCATE PREPARE stmt_dfr;

SET @col_deid = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'delivery_employee_id');
SET @sql_deid = IF(@col_deid = 0, 'ALTER TABLE customer_order ADD COLUMN delivery_employee_id INT UNSIGNED NULL AFTER delivery_failure_reason, ADD CONSTRAINT fk_order_deliv_emp FOREIGN KEY (delivery_employee_id) REFERENCES employee(employee_id)', 'SELECT 1');
PREPARE stmt_deid FROM @sql_deid;
EXECUTE stmt_deid;
DEALLOCATE PREPARE stmt_deid;

SET @col_dfat = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'delivery_failed_at');
SET @sql_dfat = IF(@col_dfat = 0, 'ALTER TABLE customer_order ADD COLUMN delivery_failed_at DATETIME NULL AFTER delivery_employee_id', 'SELECT 1');
PREPARE stmt_dfat FROM @sql_dfat;
EXECUTE stmt_dfat;
DEALLOCATE PREPARE stmt_dfat;

SET @col_resched = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_order' AND COLUMN_NAME = 'rescheduled_date');
SET @sql_resched = IF(@col_resched = 0, 'ALTER TABLE customer_order ADD COLUMN rescheduled_date DATE NULL AFTER delivery_failed_at', 'SELECT 1');
PREPARE stmt_resched FROM @sql_resched;
EXECUTE stmt_resched;
DEALLOCATE PREPARE stmt_resched;

