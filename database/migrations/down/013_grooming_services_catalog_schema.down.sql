-- Rollback for Migration 013: Grooming Services Catalog Schema Extensions
ALTER TABLE service
    DROP COLUMN IF EXISTS grooming_category,
    DROP COLUMN IF EXISTS additional_duration_rate,
    DROP COLUMN IF EXISTS additional_employee_fee;

