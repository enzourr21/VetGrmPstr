-- Migration 027: Add Delivery Personnel as an active default position for Pet Store.
-- Reversible: see database/migrations/down/027_petstore_delivery_personnel_position.down.sql

INSERT INTO business_position (business_unit_id, position_name, description, position_status)
SELECT bu.business_unit_id, 'Delivery Personnel', 'Handles Pet Store order dispatch, delivery tracking, and COD collection', 'active'
FROM business_unit bu
WHERE bu.business_unit_name = 'Pet Store'
  AND NOT EXISTS (
    SELECT 1 FROM business_position bp
    WHERE bp.business_unit_id = bu.business_unit_id
      AND bp.position_name = 'Delivery Personnel'
  );

UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'active'
WHERE bu.business_unit_name = 'Pet Store'
  AND bp.position_name = 'Delivery Personnel';
