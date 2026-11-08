-- Migration 015 Down: Revert Revocation Tracking on Inventory Access Keys

ALTER TABLE inventory_access_key
    DROP FOREIGN KEY fk_inv_key_revoked_by,
    DROP COLUMN revoked_by_employee_id,
    DROP COLUMN revoked_at;

