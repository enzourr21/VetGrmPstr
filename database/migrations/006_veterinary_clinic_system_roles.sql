-- Migration 006: Veterinary Clinic System Roles and Positions

-- 1. Insert the three veterinary-specific workspace roles
INSERT IGNORE INTO role (role_name, description) VALUES
 ('VETERINARIAN_WORKSPACE', 'Veterinarian Workspace — for employees assigned veterinarian access.'),
 ('VETERINARY_NURSE_WORKSPACE', 'Veterinary Nurse Workspace — for employees assigned veterinary nurse access.'),
 ('VETERINARY_TECHNICIAN_WORKSPACE', 'Veterinary Technician Workspace — for employees assigned veterinary technician access.');

-- 2. Attach permissions to the three veterinary workspace roles
-- Veterinarian Workspace: consultation, clinical records management, viewing, and appointment viewing
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code IN (
    'clinical.records.view',
    'clinical.records.manage',
    'appointment.view'
)
WHERE r.role_name = 'VETERINARIAN_WORKSPACE';

-- Veterinary Nurse Workspace: nursing care, viewing clinical records, and appointment viewing
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code IN (
    'clinical.records.view',
    'appointment.view'
)
WHERE r.role_name = 'VETERINARIAN_NURSE_WORKSPACE' OR r.role_name = 'VETERINARY_NURSE_WORKSPACE';

-- Veterinary Technician Workspace: diagnostic/technical tasks and appointment viewing
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code IN (
    'appointment.view'
)
WHERE r.role_name = 'VETERINARY_TECHNICIAN_WORKSPACE';

-- 3. Add explicit Veterinary Clinic default positions to business_position
INSERT IGNORE INTO business_position (business_unit_id, position_name, description, position_status) VALUES
 (2, 'Veterinarian (DVM)', 'Doctor of Veterinary Medicine — examination, diagnosis, treatment planning and surgery', 'active'),
 (2, 'Veterinary Nurse', 'Veterinary nursing care, patient monitoring, and medication administration', 'active'),
 (2, 'Veterinary Assistant', 'Assisting veterinary staff with animal handling, clinic prep, and client care', 'active'),
 (2, 'Veterinary Receptionist', 'Front-desk client intake, appointment scheduling, and patient check-in', 'active');

-- 4. Update existing Veterinary Clinic employees to also hold their corresponding workspace roles
INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id)
SELECT e.user_account_id, r.role_id, 2
FROM employee e
JOIN role r ON r.role_name = 'VETERINARIAN_WORKSPACE'
WHERE e.business_unit_id = 2 AND e.position IN ('Lead Veterinarian', 'Associate Veterinarian');

INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id)
SELECT e.user_account_id, r.role_id, 2
FROM employee e
JOIN role r ON r.role_name = 'VETERINARY_NURSE_WORKSPACE'
WHERE e.business_unit_id = 2 AND e.position IN ('Senior Veterinary Nurse');

