-- Roles (position != role != permission; see BUSINESS_RULES.md 1.2).
-- Only the confirmed role names are created here. Permissions are attached in permissions.sql.
INSERT IGNORE INTO role (role_name, description) VALUES
 ('SUPER_ADMINISTRATOR', 'Highest-level permanent account with unrestricted system-wide administrative access'),
 ('SYSTEM_ADMINISTRATOR', 'Manages accounts, roles, positions, and permissions within the assigned business unit'),
 ('BUSINESS_MANAGER', 'Operational management within the assigned business unit'),
 ('RECEPTIONIST', 'Customer-facing administration and appointment coordination'),
 ('VETERINARIAN', 'Consultation, diagnosis, treatment and medical records'),
 ('NURSE', 'Administers authorized treatments and documents nursing work'),
 ('TECHNICIAN', 'Technical tasks and diagnostic processing'),
 ('INVENTORY_MANAGER', 'Pet Store stock oversight: tracks inventory levels, receiving, and stock accuracy'),
 ('DELIVERY_PERSONNEL', 'Pet Store order delivery: views assigned delivery orders and updates delivery progress'),
 ('VETERINARIAN_WORKSPACE', 'Veterinarian Workspace — for employees assigned veterinarian access.'),
 ('VETERINARY_NURSE_WORKSPACE', 'Veterinary Nurse Workspace — for employees assigned veterinary nurse access.'),
 ('VETERINARY_TECHNICIAN_WORKSPACE', 'Veterinary Technician Workspace — for employees assigned veterinary technician access.'),
 ('STYLIST_WORKSPACE', 'Stylist Workspace — for employees assigned pet stylist access.'),
 ('BATHER_WORKSPACE', 'Bather Workspace — for employees assigned pet bather access.');
