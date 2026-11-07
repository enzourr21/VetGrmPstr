-- Migration 013: Grooming Services Catalog Schema Extensions
-- Adds grooming_category, additional_duration_rate, and additional_employee_fee to service table.
-- Reversible: see database/migrations/down/013_grooming_services_catalog_schema.down.sql

ALTER TABLE service
    ADD COLUMN grooming_category ENUM('Bathing & Brushing', 'Styling', 'Wellness', 'Specialized') NULL DEFAULT NULL AFTER service_category,
    ADD COLUMN additional_duration_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER estimated_duration_minutes,
    ADD COLUMN additional_employee_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER additional_duration_rate;

-- Map existing Grooming Services (IDs 1-5) to their established categories and rates
UPDATE service SET
    grooming_category = 'Bathing & Brushing',
    additional_duration_rate = 5.00,
    additional_employee_fee = 10.00
WHERE service_id = 1;

UPDATE service SET
    grooming_category = 'Styling',
    additional_duration_rate = 10.00,
    additional_employee_fee = 15.00
WHERE service_id = 2;

UPDATE service SET
    grooming_category = 'Specialized',
    additional_duration_rate = 0.00,
    additional_employee_fee = 0.00
WHERE service_id = 3;

UPDATE service SET
    grooming_category = 'Wellness',
    additional_duration_rate = 0.00,
    additional_employee_fee = 0.00
WHERE service_id = 4;

UPDATE service SET
    grooming_category = 'Specialized',
    additional_duration_rate = 5.00,
    additional_employee_fee = 10.00
WHERE service_id = 5;

