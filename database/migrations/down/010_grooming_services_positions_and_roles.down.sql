-- Rollback for Migration 010: Revert Grooming Services modern positions and workspace roles.

-- 1. Revert Owen Bennett's position and roles
DELETE ar FROM account_role ar
JOIN employee e ON e.user_account_id = ar.user_account_id
JOIN role r ON r.role_id = ar.role_id
WHERE e.first_name = 'Owen'
  AND e.last_name = 'Bennett'
  AND ar.business_unit_id = 3
  AND r.role_name IN ('STYLIST_WORKSPACE', 'BATHER_WORKSPACE');

UPDATE employee
SET position = 'Master Pet Stylist & Reception Coordinator'
WHERE business_unit_id = 3
  AND position = 'Master Stylist';

-- 2. Revert positions
UPDATE business_position
SET position_name = 'Master Pet Stylist & Reception Coordinator',
    description = 'Pet styling, haircuts, and coat finishing',
    position_status = 'active'
WHERE business_unit_id = 3
  AND position_name = 'Master Stylist';

UPDATE business_position
SET position_name = 'Pet Groomer & Stylist',
    description = 'Bathing, brushing, and basic hygiene for grooming clients',
    position_status = 'active'
WHERE business_unit_id = 3
  AND position_name = 'Stylist';

DELETE FROM business_position
WHERE business_unit_id = 3
  AND position_name IN ('Bather', 'Inventory Manager');

-- 3. Delete role permissions and roles
DELETE rp FROM role_permission rp
JOIN role r ON r.role_id = rp.role_id
WHERE r.role_name IN ('STYLIST_WORKSPACE', 'BATHER_WORKSPACE');

DELETE FROM role
WHERE role_name IN ('STYLIST_WORKSPACE', 'BATHER_WORKSPACE');
