-- Down migration for 019_create_customer_registration_pending.sql
-- The three tables have no foreign keys between them or to other tables, so order is not enforced by the database.
-- Reverse creation order is used by convention.

DROP TABLE IF EXISTS email_otp_failure_log;

DROP TABLE IF EXISTS email_otp_send_log;

DROP TABLE IF EXISTS customer_registration_pending;

