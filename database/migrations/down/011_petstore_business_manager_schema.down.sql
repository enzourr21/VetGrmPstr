-- Rollback for Migration 011: Pet Store Business Manager Schema and Operational Tables.

DROP TABLE IF EXISTS otp_verification;
DROP TABLE IF EXISTS employee_work_status;
DROP TABLE IF EXISTS operating_hours;
DROP TABLE IF EXISTS business_settings;
DROP TABLE IF EXISTS stock_adjustment;
DROP TABLE IF EXISTS stock_receiving_item;
DROP TABLE IF EXISTS stock_receiving;
DROP TABLE IF EXISTS purchase_order_item;
DROP TABLE IF EXISTS purchase_order;
