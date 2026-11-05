-- Migration 007: Veterinary Clinic default positions cleanup and role assignments alignment.
-- Reversible: see database/migrations/down/007_veterinary_clinic_positions_and_roles.down.sql
-- Existing employee records and historical references are preserved safely.

-- 1. Ensure the five approved Veterinary Clinic positions exist and are active.
INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT bu.business_unit_id, 'Veterinarian (DVM)', 'Doctor of Veterinary Medicine — examination, diagnosis, treatment planning and surgery', 'active'
FROM business_unit bu WHERE bu.business_unit_name = 'Veterinary Clinic'
AND NOT EXISTS (
    SELECT 1 FROM business_position bp WHERE bp.business_unit_id = bu.business_unit_id AND bp.position_name = 'Veterinarian (DVM)'
);

INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT bu.business_unit_id, 'Veterinary Nurse', 'Veterinary nursing care, patient monitoring, and medication administration', 'active'
FROM business_unit bu WHERE bu.business_unit_name = 'Veterinary Clinic'
AND NOT EXISTS (
    SELECT 1 FROM business_position bp WHERE bp.business_unit_id = bu.business_unit_id AND bp.position_name = 'Veterinary Nurse'
);

INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT bu.business_unit_id, 'Veterinary Assistant', 'Assisting veterinary staff with animal handling, clinic prep, and client care', 'active'
FROM business_unit bu WHERE bu.business_unit_name = 'Veterinary Clinic'
AND NOT EXISTS (
    SELECT 1 FROM business_position bp WHERE bp.business_unit_id = bu.business_unit_id AND bp.position_name = 'Veterinary Assistant'
);

INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT bu.business_unit_id, 'Veterinary Receptionist', 'Front-desk client intake, appointment scheduling, and patient check-in', 'active'
FROM business_unit bu WHERE bu.business_unit_name = 'Veterinary Clinic'
AND NOT EXISTS (
    SELECT 1 FROM business_position bp WHERE bp.business_unit_id = bu.business_unit_id AND bp.position_name = 'Veterinary Receptionist'
);

-- Ensure the 5 approved positions have active status
UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'active'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND bp.position_name IN (
    'Veterinarian (DVM)',
    'Veterinary Nurse',
    'Veterinary Technician',
    'Veterinary Assistant',
    'Veterinary Receptionist'
  );

-- 2. Retire redundant and non-standard default positions for Veterinary Clinic (never delete).
UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'inactive'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND bp.position_name IN (
    'Associate Veterinarian',
    'Lead Veterinarian',
    'Senior Veterinary Nurse',
    'Senior Vet Nurse',
    'Receptionist',
    'Vet Receptionist',
    'Vet Assistant',
    'Business Manager — Veterinary Clinic',
    'System Administrator'
  );

-- 3. Safely migrate existing employee assignments with unambiguous mappings.
UPDATE employee e
JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
SET e.position = 'Veterinarian (DVM)'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND e.position IN ('Lead Veterinarian', 'Associate Veterinarian');

UPDATE employee e
JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
SET e.position = 'Veterinary Nurse'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND e.position IN ('Senior Veterinary Nurse', 'Senior Vet Nurse');

UPDATE employee e
JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
SET e.position = 'Veterinary Receptionist'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND e.position IN ('Receptionist', 'Vet Receptionist');

UPDATE employee e
JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
SET e.position = 'Veterinary Assistant'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND e.position = 'Vet Assistant';

