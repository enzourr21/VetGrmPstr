-- 018_add_max_appointment_overlap_to_business_settings.sql
-- Add configurable maximum appointment overlap (in minutes) to business_settings
-- Defaults to 15 minutes for each business unit.

ALTER TABLE business_settings
ADD COLUMN max_appointment_overlap_minutes INT UNSIGNED NOT NULL DEFAULT 15
AFTER contact_number;

