# Database

* Design references: `ERD.md`, `BUSINESS_RULES.md`.
* Schema is created through versioned migrations (`migrations/`), not `schema.sql` (left empty on purpose to avoid a second source of truth).
* Seeds: `seeders/roles.sql`, `permissions.sql`, `sample_data.sql` (run with `php database/seed.php`; all sample rows are marked `[SAMPLE]`).
* Helpers: `migrate.php`, `seed.php`, `counts.php` (row counts), `test_connection.php` (dumps schema; masks nothing but never prints the password).

See the root `README.md` for setup.

## Employee Invitation Rollback Archive (Migration 030)

Before rolling back `migrations/030_decouple_employee_invitations.sql` with `migrations/down/030_decouple_employee_invitations.down.sql`, run:

    php scripts/cleanup_invitation_placeholders.php --archive-for-rollback
    php scripts/cleanup_invitation_placeholders.php --archive-for-rollback --detach-archived-for-down

Archive and recovery semantics:

* **`employee_invitation` is authoritative** for live invitation state (`status`, acceptance timestamps, and linked `user_account_id`/`employee_id`).
* **`employee_invitation_decoupled_archive` and `employee_invitation_role_archive` are rollback/recovery snapshots**, not synchronized live-history tables.
* **Post-archive acceptance:** An invitation archived while decoupled (`user_account_id IS NULL OR employee_id IS NULL`) may later be accepted, leaving an older snapshot (for example, `status = 'pending'`) in `employee_invitation_decoupled_archive`. Subsequent `--archive-for-rollback` / `--detach-archived-for-down` runs intentionally leave accepted live invitations and their live `employee_invitation_role` rows untouched.
* **Manual recovery:** Restoring detached decoupled invitations is a manual operation performed only after re-applying Migration `030` (earlier schemas require `NOT NULL` account/employee foreign keys).
* **Do not overwrite live records:** Before restoring any row from `employee_invitation_decoupled_archive`, check whether its `invitation_id` already exists in `employee_invitation`. Never overwrite or duplicate a subsequently accepted or otherwise existing live invitation.
* **Dependencies during recovery:** Restore matching role rows from `employee_invitation_role_archive` into `employee_invitation_role`, order or verify `replaced_invitation_id` self-referencing foreign keys, and verify nullable `position_id` foreign keys against `business_position` (`position_name` holds the fallback snapshot if a position no longer exists).


