# Database

* Design references: `ERD.md`, `BUSINESS_RULES.md`.
* Schema is created through versioned migrations (`migrations/`), not `schema.sql` (left empty on purpose to avoid a second source of truth).
* Seeds: `seeders/roles.sql`, `permissions.sql`, `sample_data.sql` (run with `php database/seed.php`; all sample rows are marked `[SAMPLE]`).
* Helpers: `migrate.php`, `seed.php`, `counts.php` (row counts), `test_connection.php` (dumps schema; masks nothing but never prints the password).

See the root `README.md` for setup.

