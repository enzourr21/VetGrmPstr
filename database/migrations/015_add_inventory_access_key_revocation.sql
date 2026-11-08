-- Migration 015: Add Revocation Tracking to Inventory Access Keys
-- Adds revoked_at and revoked_by_employee_id to support explicit revocation
-- and distinguish revoked keys from naturally expired keys.

ALTER TABLE inventory_access_key
    ADD COLUMN revoked_at DATETIME NULL DEFAULT NULL AFTER expires_at,
    ADD COLUMN revoked_by_employee_id INT UNSIGNED NULL DEFAULT NULL AFTER revoked_at,
    ADD CONSTRAINT fk_inv_key_revoked_by FOREIGN KEY (revoked_by_employee_id) REFERENCES employee(employee_id) ON DELETE SET NULL;

