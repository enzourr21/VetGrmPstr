-- Down migration 027: Retire Delivery Personnel position for Pet Store (never deletes historical records).

UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'inactive'
WHERE bu.business_unit_name = 'Pet Store'
  AND bp.position_name = 'Delivery Personnel';
