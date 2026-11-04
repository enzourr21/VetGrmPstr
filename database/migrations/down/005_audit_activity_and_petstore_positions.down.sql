-- Manual rollback for migration 005 (not auto-run by migrate.php).
ALTER TABLE audit_log
    DROP INDEX idx_audit_affected,
    DROP INDEX idx_audit_unit_created,
    DROP COLUMN new_value,
    DROP COLUMN old_value,
    DROP COLUMN affected_employee_id;

UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'active'
WHERE bu.business_unit_name = 'Pet Store'
  AND bp.position_name IN ('Store Supervisor', 'Sales & Inventory Associate');

DELETE FROM schema_migrations WHERE filename = '005_audit_activity_and_petstore_positions.sql';

