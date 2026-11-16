-- Migration 021: E2 Checkout Attempts, Temporary 3-Minute Inventory Holds, Payment Webhook & Reconciliation Foundation
-- Reversible: see database/migrations/down/021_e2_checkout_holds_and_payment_attempts.down.sql
--
-- Design & Timestamp Notes:
--   1. All DATETIME columns in these tables store UTC timestamps written by the database/application
--      using UTC_TIMESTAMP() (database/config sets time_zone = '+00:00').
--   2. Online checkouts create a checkout_attempt + inventory_hold (expires_at = created_at + 180s)
--      without creating premature customer_order or order_item rows.
--   3. COD checkouts create a checkout_attempt with payment_channel='cod' and immediately create a
--      confirmed customer_order, order_items, active inventory_reservations, and pending COD payment
--      without creating any inventory_hold row.
--   4. payment_webhook_event and payment_reconciliation provide provider-neutral idempotency and
--      post-expiry captured payment reconciliation boundaries.

-- 1. Checkout Attempts (tracks idempotency, checkout snapshot, payment channel, and final order link)
CREATE TABLE IF NOT EXISTS checkout_attempt (
    attempt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_token CHAR(64) NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    business_unit_id INT UNSIGNED NOT NULL,
    branch_id INT UNSIGNED NOT NULL,
    cart_id INT UNSIGNED NULL,
    payment_channel ENUM('cod','online') NOT NULL,
    fulfillment_method ENUM('pickup','delivery') NOT NULL,
    delivery_address_line1 VARCHAR(255) NULL,
    delivery_address_line2 VARCHAR(255) NULL,
    delivery_city VARCHAR(100) NULL,
    delivery_province VARCHAR(100) NULL,
    delivery_postal_code VARCHAR(20) NULL,
    notes TEXT NULL,
    expected_amount DECIMAL(12,2) NOT NULL,
    attempt_status ENUM('initiated','awaiting_payment','completed','expired','failed','reconciliation_required') NOT NULL DEFAULT 'initiated',
    order_id INT UNSIGNED NULL,
    provider_name VARCHAR(50) NULL,
    provider_session_id VARCHAR(191) NULL,
    provider_payment_id VARCHAR(191) NULL,
    provider_checkout_url VARCHAR(500) NULL,
    failure_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uk_checkout_attempt_token (attempt_token),
    UNIQUE KEY uk_checkout_attempt_idempotency (customer_id, business_unit_id, idempotency_key),
    UNIQUE KEY uk_checkout_attempt_order (order_id),
    UNIQUE KEY uk_checkout_attempt_provider_payment (provider_name, provider_payment_id),
    INDEX idx_checkout_attempt_customer_bu_status (customer_id, business_unit_id, attempt_status),
    INDEX idx_checkout_attempt_provider_session (provider_name, provider_session_id),
    CONSTRAINT chk_chk_att_amount_nonneg CHECK (expected_amount >= 0),
    CONSTRAINT fk_chk_att_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id),
    CONSTRAINT fk_chk_att_bu FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    CONSTRAINT fk_chk_att_branch FOREIGN KEY (branch_id) REFERENCES branch(branch_id),
    CONSTRAINT fk_chk_att_cart FOREIGN KEY (cart_id) REFERENCES cart(cart_id) ON DELETE SET NULL,
    CONSTRAINT fk_chk_att_order FOREIGN KEY (order_id) REFERENCES customer_order(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 2. Temporary Inventory Holds (online checkout only; exactly 3-minute / 180-second server-side TTL)
CREATE TABLE IF NOT EXISTS inventory_hold (
    hold_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id BIGINT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    business_unit_id INT UNSIGNED NOT NULL,
    hold_status ENUM('active','converted','released','expired') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    converted_at DATETIME NULL,
    released_at DATETIME NULL,
    release_reason VARCHAR(100) NULL,
    converted_order_id INT UNSIGNED NULL,
    UNIQUE KEY uk_inv_hold_attempt (attempt_id),
    UNIQUE KEY uk_inv_hold_converted_order (converted_order_id),
    INDEX idx_inv_hold_status_expires (hold_status, expires_at),
    INDEX idx_inv_hold_customer_bu_status (customer_id, business_unit_id, hold_status),
    CONSTRAINT chk_inv_hold_window CHECK (expires_at > created_at),
    CONSTRAINT fk_inv_hold_attempt FOREIGN KEY (attempt_id) REFERENCES checkout_attempt(attempt_id) ON DELETE CASCADE,
    CONSTRAINT fk_inv_hold_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id),
    CONSTRAINT fk_inv_hold_bu FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    CONSTRAINT fk_inv_hold_order FOREIGN KEY (converted_order_id) REFERENCES customer_order(order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 3. Temporary Inventory Hold Line Items (snapshot of products, inventory rows, quantities, and server unit prices)
CREATE TABLE IF NOT EXISTS inventory_hold_item (
    hold_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    hold_id BIGINT UNSIGNED NOT NULL,
    inventory_id INT UNSIGNED NOT NULL,
    business_product_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_hold_item_inv (inventory_id, hold_id),
    INDEX idx_hold_item_hold (hold_id),
    CONSTRAINT chk_hold_item_qty_positive CHECK (quantity > 0),
    CONSTRAINT chk_hold_item_price_nonneg CHECK (unit_price >= 0),
    CONSTRAINT fk_hold_item_hold FOREIGN KEY (hold_id) REFERENCES inventory_hold(hold_id) ON DELETE CASCADE,
    CONSTRAINT fk_hold_item_inv FOREIGN KEY (inventory_id) REFERENCES inventory(inventory_id),
    CONSTRAINT fk_hold_item_bp FOREIGN KEY (business_product_id) REFERENCES business_product(business_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 4. Extend payment table with optional checkout_attempt link and provider-neutral reference columns
SET @col_pay_attempt = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND COLUMN_NAME = 'attempt_id'
);
SET @sql_pay_attempt = IF(
    @col_pay_attempt = 0,
    'ALTER TABLE payment ADD COLUMN attempt_id BIGINT UNSIGNED NULL AFTER order_id',
    'DO 0'
);
PREPARE stmt_pay_attempt FROM @sql_pay_attempt;
EXECUTE stmt_pay_attempt;
DEALLOCATE PREPARE stmt_pay_attempt;

SET @col_pay_prov_name = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND COLUMN_NAME = 'provider_name'
);
SET @sql_pay_prov_name = IF(
    @col_pay_prov_name = 0,
    'ALTER TABLE payment ADD COLUMN provider_name VARCHAR(50) NULL AFTER payment_status',
    'DO 0'
);
PREPARE stmt_pay_prov_name FROM @sql_pay_prov_name;
EXECUTE stmt_pay_prov_name;
DEALLOCATE PREPARE stmt_pay_prov_name;

SET @col_pay_prov_id = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND COLUMN_NAME = 'provider_payment_id'
);
SET @sql_pay_prov_id = IF(
    @col_pay_prov_id = 0,
    'ALTER TABLE payment ADD COLUMN provider_payment_id VARCHAR(191) NULL AFTER provider_name',
    'DO 0'
);
PREPARE stmt_pay_prov_id FROM @sql_pay_prov_id;
EXECUTE stmt_pay_prov_id;
DEALLOCATE PREPARE stmt_pay_prov_id;

SET @idx_pay_attempt = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND INDEX_NAME = 'idx_payment_attempt'
);
SET @sql_idx_pay_attempt = IF(
    @idx_pay_attempt = 0,
    'ALTER TABLE payment ADD INDEX idx_payment_attempt (attempt_id)',
    'DO 0'
);
PREPARE stmt_idx_pay_attempt FROM @sql_idx_pay_attempt;
EXECUTE stmt_idx_pay_attempt;
DEALLOCATE PREPARE stmt_idx_pay_attempt;

SET @uk_pay_provider_ref = (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND INDEX_NAME = 'uk_payment_provider_ref'
);
SET @sql_uk_pay_provider_ref = IF(
    @uk_pay_provider_ref = 0,
    'ALTER TABLE payment ADD UNIQUE KEY uk_payment_provider_ref (provider_name, provider_payment_id, payment_type)',
    'DO 0'
);
PREPARE stmt_uk_pay_provider_ref FROM @sql_uk_pay_provider_ref;
EXECUTE stmt_uk_pay_provider_ref;
DEALLOCATE PREPARE stmt_uk_pay_provider_ref;

SET @fk_pay_attempt = (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payment'
      AND CONSTRAINT_NAME = 'fk_payment_attempt'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_fk_pay_attempt = IF(
    @fk_pay_attempt = 0,
    'ALTER TABLE payment ADD CONSTRAINT fk_payment_attempt FOREIGN KEY (attempt_id) REFERENCES checkout_attempt(attempt_id)',
    'DO 0'
);
PREPARE stmt_fk_pay_attempt FROM @sql_fk_pay_attempt;
EXECUTE stmt_fk_pay_attempt;
DEALLOCATE PREPARE stmt_fk_pay_attempt;

-- 5. Provider-neutral webhook event log (deduplicates delayed or repeated webhook deliveries)
CREATE TABLE IF NOT EXISTS payment_webhook_event (
    event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_name VARCHAR(50) NOT NULL,
    provider_event_id VARCHAR(191) NOT NULL,
    provider_payment_id VARCHAR(191) NULL,
    attempt_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    processing_status ENUM('received','processed','ignored_duplicate','reconciliation_required','failed') NOT NULL DEFAULT 'received',
    payload_json MEDIUMTEXT NULL,
    processing_notes VARCHAR(255) NULL,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uk_pay_webhook_provider_event (provider_name, provider_event_id),
    INDEX idx_pay_webhook_attempt (attempt_id),
    INDEX idx_pay_webhook_payment (provider_name, provider_payment_id),
    CONSTRAINT fk_pay_webhook_attempt FOREIGN KEY (attempt_id) REFERENCES checkout_attempt(attempt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 6. Post-expiration payment reconciliation queue (for captured payments that cannot be auto-fulfilled)
CREATE TABLE IF NOT EXISTS payment_reconciliation (
    reconciliation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id BIGINT UNSIGNED NOT NULL,
    hold_id BIGINT UNSIGNED NULL,
    payment_id INT UNSIGNED NULL,
    business_unit_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    provider_name VARCHAR(50) NULL,
    provider_payment_id VARCHAR(191) NULL,
    captured_amount DECIMAL(12,2) NOT NULL,
    reconciliation_status ENUM('pending_review','resolved_order_created','resolved_refunded','dismissed') NOT NULL DEFAULT 'pending_review',
    reason VARCHAR(255) NOT NULL,
    resolved_order_id INT UNSIGNED NULL,
    resolved_by_user_id INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    resolution_notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uk_pay_recon_attempt (attempt_id),
    INDEX idx_pay_recon_bu_status (business_unit_id, reconciliation_status),
    CONSTRAINT fk_pay_recon_attempt FOREIGN KEY (attempt_id) REFERENCES checkout_attempt(attempt_id),
    CONSTRAINT fk_pay_recon_hold FOREIGN KEY (hold_id) REFERENCES inventory_hold(hold_id),
    CONSTRAINT fk_pay_recon_payment FOREIGN KEY (payment_id) REFERENCES payment(payment_id),
    CONSTRAINT fk_pay_recon_bu FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    CONSTRAINT fk_pay_recon_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id),
    CONSTRAINT fk_pay_recon_order FOREIGN KEY (resolved_order_id) REFERENCES customer_order(order_id),
    CONSTRAINT fk_pay_recon_user FOREIGN KEY (resolved_by_user_id) REFERENCES user_account(user_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
