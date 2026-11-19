-- Down migration 028: Restore Veterinary Technician and retire Assistant Nurse in Veterinary Clinic.

UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'inactive'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND bp.position_name = 'Assistant Nurse';

UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'active'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND bp.position_name = 'Veterinary Technician';
