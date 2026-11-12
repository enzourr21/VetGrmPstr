-- Migration 019: Customer email OTP verification (pending registrations, send log, failure log)
-- Reversible: see database/migrations/down/019_create_customer_registration_pending.down.sql
-- All DATETIME values are written by the application in UTC (no database defaults on purpose).
-- No foreign keys: pending rows must never reserve or reference permanent account rows.
-- No password is stored here: the password is collected only after successful OTP verification.

CREATE TABLE IF NOT EXISTS customer_registration_pending (
    pending_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_token_hash CHAR(64) NOT NULL,
    email VARCHAR(255) NOT NULL,
    username VARCHAR(100) NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NULL,
    address_line1 VARCHAR(255) NULL,
    city VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    postal_code VARCHAR(20) NULL,
    otp_hash CHAR(64) NULL,
    otp_expires_at DATETIME NULL,
    otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    cumulative_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NULL DEFAULT NULL,
    otp_verified_at DATETIME NULL DEFAULT NULL,
    conflict_field VARCHAR(50) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uk_pending_session_token (session_token_hash),
    INDEX idx_pending_email (email),
    INDEX idx_pending_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS email_otp_send_log (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    sent_at DATETIME NOT NULL,
    INDEX idx_send_email_sent (email, sent_at),
    INDEX idx_send_ip_sent (ip_address, sent_at),
    INDEX idx_send_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS email_otp_failure_log (
    failure_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    failed_at DATETIME NOT NULL,
    INDEX idx_failure_email_failed (email, failed_at),
    INDEX idx_failure_failed_at (failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

