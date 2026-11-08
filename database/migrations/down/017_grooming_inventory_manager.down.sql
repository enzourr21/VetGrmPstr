-- Down Migration 017: Revert Grooming Services Inventory Manager employee and account provisioning

DELETE ar FROM account_role ar
JOIN user_account ua ON ua.user_account_id = ar.user_account_id
WHERE ua.email = 'maya.lin@staff.sample.vetgrpet' AND ar.business_unit_id = 3;

DELETE e FROM employee e
JOIN user_account ua ON ua.user_account_id = e.user_account_id
WHERE ua.email = 'maya.lin@staff.sample.vetgrpet' AND e.business_unit_id = 3;

DELETE FROM user_account WHERE email = 'maya.lin@staff.sample.vetgrpet';

