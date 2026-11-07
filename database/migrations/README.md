# Migrations

Files are `NNN_description.sql`, applied in order by `php database/migrate.php`. Applied files are recorded in `schema_migrations`, so re-running is safe. Never edit an applied migration; add a new numbered file instead.

| File | Contents |
|---|---|
| `001_initial_schema.sql` | Core, RBAC, catalog/inventory, sales/orders, appointments/services, billing skeleton, carts |
| `002_add_customer_middle_name.sql` | Adds nullable `middle_name` column to `customer` for full-name progressive registration and profile editing |
| `003_create_customer_reviews.sql` | Creates `customer_review` table for persistent customer reviews |
| `004_super_and_sysadmin_rbac.sql` | Super Administrator and System Administrator RBAC permissions and accounts |
| `005_audit_activity_and_petstore_positions.sql` | Audit logging and Pet Store position enhancements |
| `006_veterinary_clinic_system_roles.sql` | Veterinary Clinic RBAC system roles |
| `007_veterinary_clinic_positions_and_roles.sql` | Veterinary Clinic positions and role mappings |
| `008_vetclinic_common_and_clinical_positions.sql` | Veterinary Clinic clinical staff positions |
| `009_rename_senior_veterinary_nurse.sql` | Senior Veterinary Nurse position renaming |
| `010_grooming_services_positions_and_roles.sql` | Grooming Services positions, roles, and employee accounts |
| `011_petstore_business_manager_schema.sql` | Pet Store catalog, inventory, and order management schema |
| `012_refund_request_schema.sql` | Refund request tracking schema and sample records |
| `013_grooming_services_catalog_schema.sql` | Grooming Services catalog extensions: grooming_category, additional_duration_rate, additional_employee_fee |
| `014_inventory_access_keys.sql` | Shared business-unit-scoped temporary Inventory Access Key schema |
