-- Migration 009: Rename Veterinary Clinic position 'Senior Veterinary Nurse' to 'Veterinary Nurse'.
-- Reversible: see database/migrations/down/009_rename_senior_veterinary_nurse.down.sql
-- Scope: Exactly one position rename. Preserves position_id (ID 14) and business_unit_id (2).

-- 1. Rename existing 'Senior Veterinary Nurse' position to 'Veterinary Nurse' in business unit 2
UPDATE business_position
SET position_name = 'Veterinary Nurse',
    description = 'Veterinary nursing care, patient monitoring, and medication administration',
    position_status = 'active'
WHERE business_unit_id = 2
  AND position_name = 'Senior Veterinary Nurse';

-- 2. Update employee records referencing 'Senior Veterinary Nurse' to 'Veterinary Nurse'
UPDATE employee
SET position = 'Veterinary Nurse'
WHERE business_unit_id = 2
  AND position = 'Senior Veterinary Nurse';

-- 3. Ensure employee role mapping for Hannah Kim preserves VETERINARY_NURSE_WORKSPACE
INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id)
SELECT e.user_account_id, r.role_id, 2
FROM employee e
JOIN role r ON r.role_name = 'VETERINARY_NURSE_WORKSPACE'
WHERE e.business_unit_id = 2
  AND e.first_name = 'Hannah'
  AND e.last_name = 'Kim';
