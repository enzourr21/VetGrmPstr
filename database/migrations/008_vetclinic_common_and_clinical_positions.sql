-- Migration 008: Activate common shared positions for Veterinary Clinic and rename clinical positions.
-- Reversible: see database/migrations/down/008_vetclinic_common_and_clinical_positions.down.sql

-- 1. Ensure Inventory Manager exists and is active for Veterinary Clinic (business_unit_id = 2)
INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT 2, 'Inventory Manager', 'Oversees medical supplies, pharmaceuticals, receiving, and inventory accuracy', 'active'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM business_position WHERE business_unit_id = 2 AND position_name = 'Inventory Manager'
);

UPDATE business_position
SET position_status = 'active'
WHERE business_unit_id = 2 AND position_name = 'Inventory Manager';

-- 2. Ensure common shared positions and restored clinical positions are active for Veterinary Clinic
UPDATE business_position
SET position_status = 'active'
WHERE business_unit_id = 2
  AND position_name IN (
    'System Administrator',
    'Business Manager — Veterinary Clinic',
    'Receptionist',
    'Lead Veterinarian',
    'Associate Veterinarian',
    'Veterinary Nurse',
    'Veterinary Technician'
  );

-- 3. Retire replaced position titles (Veterinarian (DVM) -> Lead Veterinarian, Veterinary Assistant -> Associate Veterinarian)
UPDATE business_position
SET position_status = 'inactive'
WHERE business_unit_id = 2
  AND position_name IN ('Veterinarian (DVM)', 'Veterinary Assistant');

-- 4. Update employee positions accordingly
UPDATE employee
SET position = 'Lead Veterinarian'
WHERE business_unit_id = 2 AND position = 'Veterinarian (DVM)';

UPDATE employee
SET position = 'Associate Veterinarian'
WHERE business_unit_id = 2 AND position = 'Veterinary Assistant';

