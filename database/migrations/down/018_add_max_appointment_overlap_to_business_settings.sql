-- Down migration for 018_add_max_appointment_overlap_to_business_settings.sql
ALTER TABLE business_settings
DROP COLUMN max_appointment_overlap_minutes;

