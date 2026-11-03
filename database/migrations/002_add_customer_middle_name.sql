-- Add optional middle_name to customer profile for full-name progressive registration and profile management.
ALTER TABLE customer
    ADD COLUMN middle_name VARCHAR(100) NULL AFTER first_name;

