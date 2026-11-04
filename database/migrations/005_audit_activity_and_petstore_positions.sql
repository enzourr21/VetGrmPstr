-- Migration 005: Administrative Activity Log columns + Pet Store default position refresh.
-- Reversible: see database/migrations/down/005_audit_activity_and_petstore_positions.down.sql
-- Existing audit rows and employee records are preserved.

ALTER TABLE audit_log
    ADD COLUMN affected_employee_id INT UNSIGNED NULL AFTER target_id,
    ADD COLUMN old_value VARCHAR(255) NULL AFTER summary,
    ADD COLUMN new_value VARCHAR(255) NULL AFTER old_value,
    ADD INDEX idx_audit_unit_created (business_unit_id, created_at),
    ADD INDEX idx_audit_affected (affected_employee_id);

-- Retire (never delete) Pet Store positions: employee.position keeps its historical text value.
UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'inactive'
WHERE bu.business_unit_name = 'Pet Store'
  AND bp.position_name IN ('Store Supervisor', 'Sales & Inventory Associate');

