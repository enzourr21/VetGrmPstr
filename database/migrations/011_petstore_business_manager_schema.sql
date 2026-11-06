-- Migration 011: Pet Store Business Manager Schema and Operational Tables.
-- Reversible: see database/migrations/down/011_petstore_business_manager_schema.down.sql

-- 1. Add SKU and image_url to product if not exists
SET @col_sku = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'sku');
SET @sql_sku = IF(@col_sku = 0, 'ALTER TABLE product ADD COLUMN sku VARCHAR(64) NULL AFTER product_category', 'SELECT 1');
PREPARE stmt_sku FROM @sql_sku;
EXECUTE stmt_sku;
DEALLOCATE PREPARE stmt_sku;

SET @col_img = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product' AND COLUMN_NAME = 'image_url');
SET @sql_img = IF(@col_img = 0, 'ALTER TABLE product ADD COLUMN image_url VARCHAR(255) NULL AFTER sku', 'SELECT 1');
PREPARE stmt_img FROM @sql_img;
EXECUTE stmt_img;
DEALLOCATE PREPARE stmt_img;

-- Populate initial SKUs for sample products
UPDATE product SET sku = 'SKU-PS-SHP-001' WHERE product_name = 'Oatmeal Pet Shampoo 250ml' AND (sku IS NULL OR sku = '');
UPDATE product SET sku = 'SKU-PS-KBL-002' WHERE product_name = 'Adult Dog Kibble 2kg' AND (sku IS NULL OR sku = '');
UPDATE product SET sku = 'SKU-PS-TOY-003' WHERE product_name = 'Cat Feather Toy' AND (sku IS NULL OR sku = '');
UPDATE product SET sku = 'SKU-PS-BRS-004' WHERE product_name = 'Deshedding Brush' AND (sku IS NULL OR sku = '');
UPDATE product SET sku = 'SKU-PS-CLP-005' WHERE product_name = 'Nail Clippers' AND (sku IS NULL OR sku = '');
UPDATE product SET sku = 'SKU-PS-CND-006' WHERE product_name = 'Leave-in Conditioner Spray' AND (sku IS NULL OR sku = '');

-- 2. Purchase Orders
CREATE TABLE IF NOT EXISTS purchase_order (
    purchase_order_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(50) NOT NULL UNIQUE,
    business_unit_id INT UNSIGNED NOT NULL,
    supplier_name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(150) NOT NULL,
    supplier_email VARCHAR(255) NULL,
    supplier_phone VARCHAR(50) NULL,
    order_date DATETIME NOT NULL,
    po_status ENUM('Draft', 'Ordered', 'Partially Received', 'Fully Received', 'Cancelled') NOT NULL DEFAULT 'Ordered',
    created_by_employee_id INT UNSIGNED NOT NULL,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    FOREIGN KEY (created_by_employee_id) REFERENCES employee(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Purchase Order Items
CREATE TABLE IF NOT EXISTS purchase_order_item (
    po_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT UNSIGNED NOT NULL,
    business_product_id INT UNSIGNED NOT NULL,
    quantity_ordered INT NOT NULL,
    quantity_received INT NOT NULL DEFAULT 0,
    unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_order(purchase_order_id) ON DELETE CASCADE,
    FOREIGN KEY (business_product_id) REFERENCES business_product(business_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Stock Receiving Records
CREATE TABLE IF NOT EXISTS stock_receiving (
    receiving_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receiving_reference VARCHAR(50) NOT NULL UNIQUE,
    purchase_order_id INT UNSIGNED NOT NULL,
    business_unit_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    receiving_date DATETIME NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_order(purchase_order_id),
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    FOREIGN KEY (employee_id) REFERENCES employee(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Stock Receiving Items
CREATE TABLE IF NOT EXISTS stock_receiving_item (
    receiving_item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receiving_id INT UNSIGNED NOT NULL,
    po_item_id INT UNSIGNED NOT NULL,
    business_product_id INT UNSIGNED NOT NULL,
    quantity_received INT NOT NULL,
    quantity_accepted INT NOT NULL,
    quantity_rejected INT NOT NULL DEFAULT 0,
    rejection_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (receiving_id) REFERENCES stock_receiving(receiving_id) ON DELETE CASCADE,
    FOREIGN KEY (po_item_id) REFERENCES purchase_order_item(po_item_id),
    FOREIGN KEY (business_product_id) REFERENCES business_product(business_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Stock Adjustments Audit Trail
CREATE TABLE IF NOT EXISTS stock_adjustment (
    adjustment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id INT UNSIGNED NOT NULL,
    business_product_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    adjustment_type ENUM('increase', 'decrease') NOT NULL,
    quantity_change INT NOT NULL,
    previous_on_hand INT NOT NULL,
    new_on_hand INT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    related_reference VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    FOREIGN KEY (business_product_id) REFERENCES business_product(business_product_id),
    FOREIGN KEY (employee_id) REFERENCES employee(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Business Settings (Profile & Payment configuration)
CREATE TABLE IF NOT EXISTS business_settings (
    setting_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id INT UNSIGNED NOT NULL UNIQUE,
    business_name VARCHAR(150) NOT NULL,
    business_email VARCHAR(255) NOT NULL,
    contact_number VARCHAR(30) NOT NULL,
    cod_enabled TINYINT(1) NOT NULL DEFAULT 1,
    gcash_mobile VARCHAR(30) NULL,
    gcash_verified TINYINT(1) NOT NULL DEFAULT 0,
    maya_mobile VARCHAR(30) NULL,
    maya_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Operating Hours
CREATE TABLE IF NOT EXISTS operating_hours (
    hours_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    day_name VARCHAR(20) NOT NULL,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    open_time TIME NULL DEFAULT '08:00:00',
    close_time TIME NULL DEFAULT '19:00:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_unit_day (business_unit_id, day_of_week),
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Employee Work Status
CREATE TABLE IF NOT EXISTS employee_work_status (
    status_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id INT UNSIGNED NOT NULL UNIQUE,
    work_status ENUM('On Duty', 'Free', 'Not Present') NOT NULL DEFAULT 'Free',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employee(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. OTP Verification
CREATE TABLE IF NOT EXISTS otp_verification (
    otp_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id INT UNSIGNED NOT NULL,
    service_type ENUM('gcash', 'maya') NOT NULL,
    mobile_number VARCHAR(30) NOT NULL,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings for Pet Store (business_unit_id = 1)
INSERT IGNORE INTO business_settings (business_unit_id, business_name, business_email, contact_number, cod_enabled)
VALUES (1, 'VETGRPET Pet Store', 'petstore@vetgrpet.local', '09175550100', 1);

INSERT IGNORE INTO operating_hours (business_unit_id, day_of_week, day_name, is_open, open_time, close_time) VALUES
(1, 1, 'Monday', 1, '08:00:00', '19:00:00'),
(1, 2, 'Tuesday', 1, '08:00:00', '19:00:00'),
(1, 3, 'Wednesday', 1, '08:00:00', '19:00:00'),
(1, 4, 'Thursday', 1, '08:00:00', '19:00:00'),
(1, 5, 'Friday', 1, '08:00:00', '19:00:00'),
(1, 6, 'Saturday', 1, '09:00:00', '18:00:00'),
(1, 7, 'Sunday', 1, '09:00:00', '17:00:00');

-- Initialize employee work status
INSERT IGNORE INTO employee_work_status (employee_id, work_status)
SELECT e.employee_id, 'Free'
FROM employee e
WHERE e.business_unit_id = 1;
