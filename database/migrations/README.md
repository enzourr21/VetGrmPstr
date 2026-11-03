# Migrations

Files are `NNN_description.sql`, applied in order by `php database/migrate.php`. Applied files are recorded in `schema_migrations`, so re-running is safe. Never edit an applied migration; add a new numbered file instead.

| File | Contents |
|---|---|
| `001_initial_schema.sql` | Core, RBAC, catalog/inventory, sales/orders, appointments/services, billing skeleton, carts |
| `002_add_customer_middle_name.sql` | Adds nullable `middle_name` column to `customer` for full-name progressive registration and profile editing |
| `003_create_customer_reviews.sql` | Creates `customer_review` table for persistent customer reviews |
