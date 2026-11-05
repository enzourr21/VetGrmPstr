-- Migration 008 down: Revert position status changes
UPDATE business_position
SET position_status = 'inactive'
WHERE business_unit_id = 2
  AND position_name IN (
    'System Administrator',
    'Business Manager — Veterinary Clinic',
    'Receptionist',
    'Lead Veterinarian',
    'Associate Veterinarian',
    'Inventory Manager'
  );

UPDATE business_position
SET position_status = 'active'
WHERE business_unit_id = 2
  AND position_name IN ('Veterinarian (DVM)', 'Veterinary Assistant');

UPDATE employee
SET position = 'Veterinarian (DVM)'
WHERE business_unit_id = 2 AND position = 'Lead Veterinarian';

