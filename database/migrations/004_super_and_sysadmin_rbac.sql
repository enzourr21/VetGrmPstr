-- Migration 004: Super Administrator, Business-Unit System Administrators, Positions, Usernames, and Audit Log

ALTER TABLE user_account
    ADD COLUMN username VARCHAR(100) NULL UNIQUE AFTER email,
    ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER account_status;

ALTER TABLE employee
    ADD COLUMN business_unit_id INT UNSIGNED NULL AFTER user_account_id,
    ADD CONSTRAINT fk_employee_business_unit FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id);

CREATE TABLE IF NOT EXISTS business_position (
    position_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    business_unit_id INT UNSIGNED NOT NULL,
    position_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    position_status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_unit_position (business_unit_id, position_name),
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NULL,
    business_unit_id INT UNSIGNED NULL,
    action_code VARCHAR(100) NOT NULL,
    target_type VARCHAR(60) NULL,
    target_id INT UNSIGNED NULL,
    summary VARCHAR(500) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id) REFERENCES user_account(user_account_id) ON DELETE SET NULL,
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

