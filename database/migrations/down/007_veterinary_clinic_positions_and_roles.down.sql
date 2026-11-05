-- Migration 007 down: Revert Veterinary Clinic position status changes.
UPDATE business_position bp
JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
SET bp.position_status = 'active'
WHERE bu.business_unit_name = 'Veterinary Clinic'
  AND bp.position_name IN (
    'Associate Veterinarian',
    'Lead Veterinarian',
    'Senior Veterinary Nurse',
    'Receptionist',
    'Business Manager — Veterinary Clinic',
    'System Administrator'
  );

