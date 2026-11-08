-- Migration 017: Grooming Services Inventory Manager employee and account provisioning
-- Reversible: see database/migrations/down/017_grooming_inventory_manager.down.sql

-- 1. Create user_account for Grooming Services Inventory Manager
INSERT INTO user_account (email, password_hash, account_status)
SELECT 'maya.lin@staff.sample.vetgrpet', '$2y$10$GQHImahPTzoVsbLPYk8YL.u.QjdW.b6DfZEZJ7hmosTxj75GWWrwe', 'active'
WHERE NOT EXISTS (
    SELECT 1 FROM user_account WHERE email = 'maya.lin@staff.sample.vetgrpet'
);

-- 2. Create employee record for Maya Lin under Grooming Services (business_unit_id = 3)
INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, phone, employee_status)
SELECT ua.user_account_id, 3, 'Maya', 'Lin', 'Inventory Manager', '0917-555-0304', 'active'
FROM user_account ua
WHERE ua.email = 'maya.lin@staff.sample.vetgrpet'
  AND NOT EXISTS (
      SELECT 1 FROM employee WHERE user_account_id = ua.user_account_id AND business_unit_id = 3
  );

-- 3. Assign INVENTORY_MANAGER role for business_unit_id = 3
INSERT INTO account_role (user_account_id, role_id, business_unit_id)
SELECT ua.user_account_id, r.role_id, 3
FROM user_account ua
JOIN role r ON r.role_name = 'INVENTORY_MANAGER'
WHERE ua.email = 'maya.lin@staff.sample.vetgrpet'
  AND NOT EXISTS (
      SELECT 1 FROM account_role ar
      WHERE ar.user_account_id = ua.user_account_id
        AND ar.role_id = r.role_id
        AND ar.business_unit_id = 3
  );

