-- Migration 028: Replace Veterinary Technician default position with Assistant Nurse in Veterinary Clinic.
-- Reversible: see database/migrations/down/028_vetclinic_assistant_nurse_position.down.sql

INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT bu.business_unit_id, 'Assistant Nurse', 'Assists veterinary nurses and doctors with patient care and clinical preparation', 'active'
FROM business_unit bu
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND NOT EXISTS (
    SELECT 1 FROM business_position bp
    WHERE bp.business_unit_id = bu.business_unit_id
      AND bp.position_name = 'Assistant Nurse'
  );

UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'active'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND bp.position_name = 'Assistant Nurse';

UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'inactive'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND bp.position_name = 'Veterinary Technician';
