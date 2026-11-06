-- Migration 010: Grooming Services modern positions, workspace roles, and Owen Bennett configuration.
-- Reversible: see database/migrations/down/010_grooming_services_positions_and_roles.down.sql

-- 1. Create workspace roles for Grooming Services
INSERT IGNORE INTO role (role_name, description) VALUES
  ('STYLIST_WORKSPACE', 'Stylist Workspace — for employees assigned pet stylist access.'),
  ('BATHER_WORKSPACE', 'Bather Workspace — for employees assigned pet bather access.');

-- 2. Attach permissions for STYLIST_WORKSPACE and BATHER_WORKSPACE (appointment.view)
INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r
JOIN permission p ON p.permission_code = 'appointment.view'
WHERE r.role_name IN ('STYLIST_WORKSPACE', 'BATHER_WORKSPACE');

-- 3. Replace obsolete combined positions with approved single positions for Grooming Services (business_unit_id = 3)
UPDATE business_position
SET position_name = 'Master Stylist',
    description = 'Advanced styling, breed standard cuts, and specialized grooming',
    position_status = 'active'
WHERE business_unit_id = 3
  AND position_name = 'Master Pet Stylist & Reception Coordinator';

UPDATE business_position
SET position_name = 'Stylist',
    description = 'Pet styling, haircuts, and coat finishing',
    position_status = 'active'
WHERE business_unit_id = 3
  AND position_name = 'Pet Groomer & Stylist';

-- 4. Insert Bather position if not exists
INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT 3, 'Bather', 'Bathing, brushing, ear cleaning, and nail trimming', 'active'
WHERE NOT EXISTS (
    SELECT 1 FROM business_position WHERE business_unit_id = 3 AND position_name = 'Bather'
);

-- 5. Insert Inventory Manager position for Grooming Services if not exists
INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT 3, 'Inventory Manager', 'Grooming stock oversight: tracks inventory levels, receiving, and supplies', 'active'
WHERE NOT EXISTS (
    SELECT 1 FROM business_position WHERE business_unit_id = 3 AND position_name = 'Inventory Manager'
);

-- 6. Ensure exactly the 7 approved positions are active for business_unit_id = 3
UPDATE business_position
SET position_status = 'active'
WHERE business_unit_id = 3
  AND position_name IN (
    'Bather',
    'Business Manager — Grooming Services',
    'Inventory Manager',
    'Master Stylist',
    'Receptionist',
    'Stylist',
    'System Administrator'
  );

-- 7. Update employee records referencing old combined positions
UPDATE employee
SET position = 'Master Stylist'
WHERE business_unit_id = 3
  AND position = 'Master Pet Stylist & Reception Coordinator';

UPDATE employee
SET position = 'Stylist'
WHERE business_unit_id = 3
  AND position = 'Pet Groomer & Stylist';

-- 8. Configure Owen Bennett: Master Stylist with STYLIST_WORKSPACE, BATHER_WORKSPACE, RECEPTIONIST
INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id)
SELECT e.user_account_id, r.role_id, 3
FROM employee e
JOIN role r ON r.role_name IN ('STYLIST_WORKSPACE', 'BATHER_WORKSPACE', 'RECEPTIONIST')
WHERE e.first_name = 'Owen'
  AND e.last_name = 'Bennett'
  AND e.business_unit_id = 3;
