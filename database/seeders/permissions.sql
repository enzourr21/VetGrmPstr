-- Minimal permissions for the first module. Only RECEPTIONIST receives them.
-- No admin/manager permissions are granted here (not part of this scope).
INSERT IGNORE INTO permission (permission_code, description) VALUES
 ('appointment.view', 'View appointment requests of the assigned business unit'),
 ('appointment.process', 'Move appointment requests through receptionist-allowed statuses');

INSERT IGNORE INTO role_permission (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM role r JOIN permission p ON p.permission_code IN ('appointment.view', 'appointment.process')
WHERE r.role_name = 'RECEPTIONIST';

