-- SAMPLE / DEVELOPMENT REFERENCE DATA. Contains no customers, orders, appointments, or fake reviews.
-- Idempotent: safe to re-run. Never overwrites existing stock.

INSERT IGNORE INTO business_unit (business_unit_name) VALUES
 ('Pet Store'), ('Veterinary Clinic'), ('Grooming Services');

INSERT IGNORE INTO branch (business_unit_id, branch_name, city, phone)
SELECT business_unit_id, CONCAT(business_unit_name, ' - Main Branch'), 'Sample City', '000-000-0000'
FROM business_unit;

INSERT IGNORE INTO appointment_status (status_name, description, sort_order) VALUES
 ('Pending', 'Request submitted by customer, awaiting receptionist', 1),
 ('Awaiting Customer Response', 'Receptionist is waiting for the customer', 2),
 ('Confirmed', 'Request accepted by receptionist', 3),
 ('Rejected', 'Request declined', 4),
 ('Cancelled', 'Cancelled', 5),
 ('In Progress', 'Service underway', 6),
 ('Completed', 'Service finished', 7),
 ('No-Show', 'Customer did not attend', 8);

-- [SAMPLE] grooming services
INSERT INTO service (service_name, service_category, description, base_price, estimated_duration_minutes)
SELECT * FROM (
  SELECT 'Basic Bath & Dry' AS n, 'grooming' AS c, '[SAMPLE] Warm oatmeal shampoo bath, conditioning rinse, towel dry, and gentle blow dry.' AS d, 25.00 AS p, 45 AS m
  UNION ALL SELECT 'Full Grooming (Bath + Haircut)', 'grooming', '[SAMPLE] Complete bath, fluff dry, breed-standard or custom scissoring, ear care, and sanitary trim.', 55.00, 90
  UNION ALL SELECT 'Nail Trim', 'grooming', '[SAMPLE] Precision nail clipping and smooth paw-pad filing for comfortable walking.', 8.00, 15
  UNION ALL SELECT 'Teeth Brushing', 'grooming', '[SAMPLE] Gentle enzymatic tooth cleaning and breath-freshening gum care.', 10.00, 15
  UNION ALL SELECT 'De-shedding Treatment', 'grooming', '[SAMPLE] Deep undercoat release bath and specialized carding brush-out.', 30.00, 40
) t WHERE NOT EXISTS (SELECT 1 FROM service s WHERE s.service_name = t.n);

-- [SAMPLE] veterinary clinic (medical) services
INSERT INTO service (service_name, service_category, description, base_price, estimated_duration_minutes)
SELECT * FROM (
  SELECT 'Comprehensive Wellness Exam' AS n, 'medical' AS c, '[SAMPLE] Full nose-to-tail physical assessment, weight and vitals check, and preventive care consultation.' AS d, 48.00 AS p, 30 AS m
  UNION ALL SELECT 'Core Vaccination Visit', 'medical', '[SAMPLE] Administration of age-appropriate core vaccines following a veterinary physical check.', 38.00, 20
  UNION ALL SELECT 'Diagnostic Blood & Wellness Panel', 'medical', '[SAMPLE] Complete blood count (CBC) and organ chemistry profile for early health screening.', 85.00, 35
  UNION ALL SELECT 'Veterinary Dental Assessment & Scaling', 'medical', '[SAMPLE] Ultrasonic dental scaling, polishing, and oral health charting under clinical supervision.', 140.00, 60
  UNION ALL SELECT 'Microchip Identification Implant', 'medical', '[SAMPLE] ISO-standard permanent microchip placement and registry verification.', 32.00, 15
) t WHERE NOT EXISTS (SELECT 1 FROM service s WHERE s.service_name = t.n);

-- [SAMPLE] catalog products
INSERT INTO product (product_name, description, product_category)
SELECT * FROM (
  SELECT 'Oatmeal Pet Shampoo 250ml' AS n, '[SAMPLE] Gentle oatmeal shampoo for sensitive skin.' AS d, 'Grooming Supplies' AS c
  UNION ALL SELECT 'Deshedding Brush', '[SAMPLE] Reduces shedding for dogs and cats.', 'Grooming Supplies'
  UNION ALL SELECT 'Nail Clippers', '[SAMPLE] Stainless steel clippers with safety guard.', 'Grooming Supplies'
  UNION ALL SELECT 'Leave-in Conditioner Spray', '[SAMPLE] Detangling spray (seeded out of stock for testing).', 'Grooming Supplies'
  UNION ALL SELECT 'Adult Dog Kibble 2kg', '[SAMPLE] Dry food for adult dogs.', 'Food'
  UNION ALL SELECT 'Cat Feather Toy', '[SAMPLE] Interactive wand toy.', 'Toys'
) t WHERE NOT EXISTS (SELECT 1 FROM product p WHERE p.product_name = t.n);

-- Business-unit listings: shampoo is sold by both Grooming and Pet Store at different prices.
INSERT IGNORE INTO business_product (business_unit_id, product_id, selling_price, product_status)
SELECT bu.business_unit_id, p.product_id, v.price, 'active'
FROM (
  SELECT 'Grooming Services' AS unit, 'Oatmeal Pet Shampoo 250ml' AS pname, 12.50 AS price, 40 AS stock
  UNION ALL SELECT 'Grooming Services', 'Deshedding Brush', 18.00, 15
  UNION ALL SELECT 'Grooming Services', 'Nail Clippers', 9.75, 3
  UNION ALL SELECT 'Grooming Services', 'Leave-in Conditioner Spray', 11.00, 0
  UNION ALL SELECT 'Pet Store', 'Oatmeal Pet Shampoo 250ml', 13.50, 25
  UNION ALL SELECT 'Pet Store', 'Adult Dog Kibble 2kg', 24.00, 30
  UNION ALL SELECT 'Pet Store', 'Cat Feather Toy', 5.50, 50
) v
JOIN business_unit bu ON bu.business_unit_name = v.unit
JOIN product p ON p.product_name = v.pname;

INSERT IGNORE INTO inventory (business_product_id, on_hand, committed)
SELECT bp.business_product_id, v.stock, 0
FROM (
  SELECT 'Grooming Services' AS unit, 'Oatmeal Pet Shampoo 250ml' AS pname, 40 AS stock
  UNION ALL SELECT 'Grooming Services', 'Deshedding Brush', 15
  UNION ALL SELECT 'Grooming Services', 'Nail Clippers', 3
  UNION ALL SELECT 'Grooming Services', 'Leave-in Conditioner Spray', 0
  UNION ALL SELECT 'Pet Store', 'Oatmeal Pet Shampoo 250ml', 25
  UNION ALL SELECT 'Pet Store', 'Adult Dog Kibble 2kg', 30
  UNION ALL SELECT 'Pet Store', 'Cat Feather Toy', 50
) v
JOIN business_unit bu ON bu.business_unit_name = v.unit
JOIN product p ON p.product_name = v.pname
JOIN business_product bp ON bp.business_unit_id = bu.business_unit_id AND bp.product_id = p.product_id;

-- Default Business Unit Positions
INSERT IGNORE INTO business_position (business_unit_id, position_name, description)
SELECT bu.business_unit_id, pos.position_name, pos.description
FROM (
  SELECT 'Pet Store' AS uname, 'System Administrator' AS position_name, 'Manages Pet Store accounts, roles, and positions' AS description
  UNION ALL SELECT 'Pet Store', 'Business Manager — Pet Store', 'Operational manager for Pet Store'
  UNION ALL SELECT 'Pet Store', 'Inventory Manager', 'Oversees Pet Store stock levels, receiving, and inventory accuracy'
  UNION ALL SELECT 'Pet Store', 'Receptionist', 'Front-desk customer assistance for Pet Store'
  UNION ALL SELECT 'Veterinary Clinic', 'System Administrator', 'Manages Veterinary Clinic accounts, roles, and positions'
  UNION ALL SELECT 'Veterinary Clinic', 'Business Manager — Veterinary Clinic', 'Operational manager for Veterinary Clinic'
  UNION ALL SELECT 'Veterinary Clinic', 'Lead Veterinarian', 'Senior veterinarian overseeing clinical consultations and records'
  UNION ALL SELECT 'Veterinary Clinic', 'Associate Veterinarian', 'Licensed veterinarian performing exams and treatments'
  UNION ALL SELECT 'Veterinary Clinic', 'Veterinary Nurse', 'Administers prescribed treatments and documents nursing care'
  UNION ALL SELECT 'Veterinary Clinic', 'Veterinary Technician', 'Performs diagnostic and technical clinical procedures'
  UNION ALL SELECT 'Veterinary Clinic', 'Receptionist', 'Clinical front-desk and appointment coordination'
  UNION ALL SELECT 'Grooming Services', 'System Administrator', 'Manages Grooming Services accounts, roles, and positions'
  UNION ALL SELECT 'Grooming Services', 'Business Manager — Grooming Services', 'Operational manager for Grooming Services'
  UNION ALL SELECT 'Grooming Services', 'Inventory Manager', 'Grooming stock oversight: tracks inventory levels, receiving, and supplies'
  UNION ALL SELECT 'Grooming Services', 'Master Stylist', 'Advanced styling, breed standard cuts, and specialized grooming'
  UNION ALL SELECT 'Grooming Services', 'Receptionist', 'Grooming appointment intake and scheduling'
  UNION ALL SELECT 'Grooming Services', 'Stylist', 'Pet styling, haircuts, and coat finishing'
  UNION ALL SELECT 'Grooming Services', 'Bather', 'Bathing, brushing, ear cleaning, and nail trimming'
) pos
JOIN business_unit bu ON bu.business_unit_name = pos.uname;

-- [SAMPLE] Staff members & Business Managers for directory display (non-interactive sample accounts, status inactive for login safety)
INSERT IGNORE INTO user_account (email, password_hash, account_status) VALUES
 ('elena.vance@staff.sample.vetgrpet', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'inactive'),
 ('marcus.chen@staff.sample.vetgrpet', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'inactive'),
 ('claire.dubois@staff.sample.vetgrpet', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'inactive'),
 ('david.reyes@staff.sample.vetgrpet', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'inactive'),
 ('hannah.kim@staff.sample.vetgrpet', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'inactive'),
 ('owen.bennett@staff.sample.vetgrpet', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'inactive');

INSERT IGNORE INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status)
SELECT ua.user_account_id, bu.business_unit_id, s.first_name, s.last_name, s.position, 'active'
FROM (
  SELECT 'elena.vance@staff.sample.vetgrpet' AS email, 'Veterinary Clinic' AS uname, 'Dr. Elena' AS first_name, 'Vance' AS last_name, 'Lead Veterinarian' AS position
  UNION ALL SELECT 'marcus.chen@staff.sample.vetgrpet', 'Grooming Services', 'Marcus', 'Chen', 'Business Manager — Grooming Services'
  UNION ALL SELECT 'claire.dubois@staff.sample.vetgrpet', 'Veterinary Clinic', 'Claire', 'Dubois', 'Business Manager — Veterinary Clinic'
  UNION ALL SELECT 'david.reyes@staff.sample.vetgrpet', 'Pet Store', 'David', 'Reyes', 'Business Manager — Pet Store'
  UNION ALL SELECT 'hannah.kim@staff.sample.vetgrpet', 'Veterinary Clinic', 'Hannah', 'Kim', 'Veterinary Nurse'
  UNION ALL SELECT 'owen.bennett@staff.sample.vetgrpet', 'Grooming Services', 'Owen', 'Bennett', 'Master Stylist'
) s
JOIN user_account ua ON ua.email = s.email
JOIN business_unit bu ON bu.business_unit_name = s.uname;

INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id)
SELECT ua.user_account_id, r.role_id, bu.business_unit_id
FROM (
  SELECT 'elena.vance@staff.sample.vetgrpet' AS email, 'VETERINARIAN' AS rname, 'Veterinary Clinic' AS uname
  UNION ALL SELECT 'marcus.chen@staff.sample.vetgrpet', 'BUSINESS_MANAGER', 'Grooming Services'
  UNION ALL SELECT 'claire.dubois@staff.sample.vetgrpet', 'BUSINESS_MANAGER', 'Veterinary Clinic'
  UNION ALL SELECT 'david.reyes@staff.sample.vetgrpet', 'BUSINESS_MANAGER', 'Pet Store'
  UNION ALL SELECT 'hannah.kim@staff.sample.vetgrpet', 'NURSE', 'Veterinary Clinic'
  UNION ALL SELECT 'owen.bennett@staff.sample.vetgrpet', 'RECEPTIONIST', 'Grooming Services'
  UNION ALL SELECT 'owen.bennett@staff.sample.vetgrpet', 'STYLIST_WORKSPACE', 'Grooming Services'
  UNION ALL SELECT 'owen.bennett@staff.sample.vetgrpet', 'BATHER_WORKSPACE', 'Grooming Services'
) m
JOIN user_account ua ON ua.email = m.email
JOIN role r ON r.role_name = m.rname
JOIN business_unit bu ON bu.business_unit_name = m.uname;

UPDATE employee e
JOIN account_role ar ON ar.user_account_id = e.user_account_id
SET e.business_unit_id = ar.business_unit_id
WHERE e.business_unit_id IS NULL;

-- [SAMPLE] Registered Customers and Registered Pets for Development & Testing
-- Idempotent: safe to run repeatedly; uses NOT EXISTS / UNIQUE email checks.
INSERT IGNORE INTO user_account (email, password_hash, account_status) VALUES
 ('oliver.vance.sample@vetgrpet.local', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'active'),
 ('sophia.martinez.sample@vetgrpet.local', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'active'),
 ('liam.gallagher.sample@vetgrpet.local', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'active'),
 ('emma.watson.sample@vetgrpet.local', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'active'),
 ('noah.bennett.sample@vetgrpet.local', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 'active');

INSERT INTO customer (user_account_id, first_name, last_name, phone, address_line1, city, province, postal_code, customer_status)
SELECT ua.user_account_id, c.first_name, c.last_name, c.phone, c.address_line1, c.city, c.province, c.postal_code, 'active'
FROM (
  SELECT 'oliver.vance.sample@vetgrpet.local' AS email, 'Oliver' AS first_name, 'Vance' AS last_name, '555-0101' AS phone, '123 Pinecrest Ave' AS address_line1, 'Sample City' AS city, 'Metro Province' AS province, '1001' AS postal_code
  UNION ALL SELECT 'sophia.martinez.sample@vetgrpet.local', 'Sophia', 'Martinez', '555-0102', '456 Oakwood Blvd', 'Sample City', 'Metro Province', '1002'
  UNION ALL SELECT 'liam.gallagher.sample@vetgrpet.local', 'Liam', 'Gallagher', '555-0103', '789 Birch Lane', 'Sample City', 'Metro Province', '1003'
  UNION ALL SELECT 'emma.watson.sample@vetgrpet.local', 'Emma', 'Watson', '555-0104', '321 Maple St', 'Sample City', 'Metro Province', '1004'
  UNION ALL SELECT 'noah.bennett.sample@vetgrpet.local', 'Noah', 'Bennett', '555-0105', '654 Cedar Way', 'Sample City', 'Metro Province', '1005'
) c
JOIN user_account ua ON ua.email = c.email
WHERE NOT EXISTS (
  SELECT 1 FROM customer cust WHERE cust.user_account_id = ua.user_account_id
);

INSERT INTO pet (customer_id, pet_name, species, breed, sex, date_of_birth, weight_kg, color_markings, notes, pet_status)
SELECT cust.customer_id, p.pet_name, p.species, p.breed, p.sex, p.date_of_birth, p.weight_kg, p.color_markings, p.notes, 'active'
FROM (
  SELECT 'oliver.vance.sample@vetgrpet.local' AS email, 'Barnaby' AS pet_name, 'Dog' AS species, 'Golden Retriever' AS breed, 'male' AS sex, '2021-04-12' AS date_of_birth, 28.50 AS weight_kg, 'Golden cream' AS color_markings, '[SAMPLE] Friendly retriever.' AS notes
  UNION ALL SELECT 'oliver.vance.sample@vetgrpet.local', 'Whiskers', 'Cat', 'Domestic Shorthair', 'female', '2023-01-10', 4.20, 'Calico', '[SAMPLE] Quiet and curious.'
  UNION ALL SELECT 'sophia.martinez.sample@vetgrpet.local', 'Bella', 'Dog', 'French Bulldog', 'female', '2022-08-19', 11.00, 'Brindle', '[SAMPLE] Playful frenchie.'
  UNION ALL SELECT 'liam.gallagher.sample@vetgrpet.local', 'Milo', 'Cat', 'Maine Coon', 'male', '2020-11-05', 7.80, 'Tabby grey', '[SAMPLE] Large gentle giant.'
  UNION ALL SELECT 'liam.gallagher.sample@vetgrpet.local', 'Pip', 'Bird', 'Canary', 'unknown', '2023-05-14', 0.05, 'Yellow feathering', '[SAMPLE] Healthy canary.'
  UNION ALL SELECT 'emma.watson.sample@vetgrpet.local', 'Daisy', 'Rabbit', 'Holland Lop', 'female', '2022-03-22', 1.80, 'White & brown patches', '[SAMPLE] Sweet pet rabbit.'
  UNION ALL SELECT 'noah.bennett.sample@vetgrpet.local', 'Rocky', 'Dog', 'German Shepherd', 'male', '2021-09-30', 34.00, 'Black & tan', '[SAMPLE] Active working dog.'
) p
JOIN user_account ua ON ua.email = p.email
JOIN customer cust ON cust.user_account_id = ua.user_account_id
WHERE NOT EXISTS (
  SELECT 1 FROM pet existing WHERE existing.customer_id = cust.customer_id AND existing.pet_name = p.pet_name
);
