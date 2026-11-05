-- Permissions for VETGRPET RBAC.
-- Note: RECEPTIONIST holds ONLY appointment.view and appointment.process.
INSERT IGNORE INTO permission (permission_code, description) VALUES
 ('appointment.view', 'View appointment requests of the assigned business unit'),
 ('appointment.process', 'Move appointment requests through receptionist-allowed statuses'),
 ('admin.employees.view', 'View employees within the assigned business unit'),
 ('admin.employees.manage', 'Create and manage employee accounts within the assigned business unit'),
 ('admin.roles.manage', 'Manage employee role and permission assignments within the assigned business unit'),
 ('admin.positions.manage', 'Manage job positions within the assigned business unit'),
 ('admin.sysadmin.grant', 'Grant System Administrator access within the assigned business unit'),
 ('manager.operations.view', 'View operational metrics, inventory, and orders within the assigned business unit'),
 ('clinical.records.view', 'View clinical consultation and medical records'),
 ('clinical.records.manage', 'Create and finalize clinical medical records'),
 ('inventory.view', 'View stock levels of the assigned business unit'),
 ('inventory.manage', 'Record stock receiving and adjustments in the assigned business unit'),
 ('delivery.view', 'View customer delivery orders of the assigned business unit'),
 ('delivery.update', 'Update delivery progress of customer orders in the assigned business unit');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN ('inventory.view', 'inventory.manage')
WHERE r.role_name = 'INVENTORY_MANAGER';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN ('delivery.view', 'delivery.update')
WHERE r.role_name = 'DELIVERY_PERSONNEL';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN ('appointment.view', 'appointment.process')
WHERE r.role_name = 'RECEPTIONIST';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN (
    'admin.employees.view',
    'admin.employees.manage',
    'admin.roles.manage',
    'admin.positions.manage',
    'admin.sysadmin.grant',
    'appointment.view'
)
WHERE r.role_name = 'SYSTEM_ADMINISTRATOR';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN (
    'manager.operations.view',
    'appointment.view',
    'clinical.records.view'
)
WHERE r.role_name = 'BUSINESS_MANAGER';

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN (
    'clinical.records.view',
    'clinical.records.manage',
    'appointment.view'
)
WHERE r.role_name IN ('VETERINARIAN', 'VETERINARIAN_WORKSPACE');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN (
    'clinical.records.view',
    'appointment.view'
)
WHERE r.role_name IN ('NURSE', 'VETERINARY_NURSE_WORKSPACE');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN ('appointment.view')
WHERE r.role_name IN ('TECHNICIAN', 'VETERINARY_TECHNICIAN_WORKSPACE');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p
WHERE r.role_name = 'SUPER_ADMINISTRATOR';
