-- Migration 014: Temporary Inventory Access Keys (Business-Unit Scoped)
-- Supports Pet Store (unit 1) and Grooming Services (unit 3) Business Manager
-- temporary inventory key generation with strict one-valid-key-at-a-time per unit.

CREATE TABLE IF NOT EXISTS inventory_access_key (
    key_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id INT UNSIGNED NOT NULL,
    key_code VARCHAR(32) NOT NULL UNIQUE,
    created_by_employee_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    INDEX idx_inv_key_unit_active_exp (business_unit_id, is_active, expires_at),
    CONSTRAINT fk_inv_key_unit FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_key_emp FOREIGN KEY (created_by_employee_id) REFERENCES employee(employee_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

