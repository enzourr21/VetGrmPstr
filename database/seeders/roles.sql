-- Roles (position != role != permission; see BUSINESS_RULES.md 1.2).
-- Only the confirmed role names are created here. Permissions are attached in permissions.sql.
INSERT IGNORE INTO role (role_name, description) VALUES
 ('SYSTEM_ADMINISTRATOR', 'Manages accounts, roles and permissions within the assigned business unit'),
 ('BUSINESS_MANAGER', 'Operational management within the assigned business unit'),
 ('RECEPTIONIST', 'Customer-facing administration and appointment coordination'),
 ('VETERINARIAN', 'Consultation, diagnosis, treatment and medical records'),
 ('NURSE', 'Administers authorized treatments and documents nursing work'),
 ('TECHNICIAN', 'Technical tasks and diagnostic processing');

