-- Rollback for Migration 009: Revert 'Veterinary Nurse' back to 'Senior Veterinary Nurse' in business unit 2.

UPDATE business_position
SET position_name = 'Senior Veterinary Nurse',
    description = 'Administers prescribed treatments and documents nursing care',
    position_status = 'active'
WHERE business_unit_id = 2
  AND position_name = 'Veterinary Nurse';

UPDATE employee
SET position = 'Senior Veterinary Nurse'
WHERE business_unit_id = 2
  AND position = 'Veterinary Nurse';
