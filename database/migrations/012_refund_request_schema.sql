-- Migration 012: Refund Request Schema and Sample Pet Store Records
-- Reversible: see database/migrations/down/012_refund_request_schema.down.sql

CREATE TABLE IF NOT EXISTS refund_request (
    refund_request_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_reference VARCHAR(50) NOT NULL UNIQUE,
    business_unit_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NULL,
    sale_id INT UNSIGNED NULL,
    payment_id INT UNSIGNED NULL,
    channel ENUM('online', 'walkin') NOT NULL DEFAULT 'online',
    requested_amount DECIMAL(12,2) NOT NULL,
    approved_amount DECIMAL(12,2) NULL,
    customer_reason TEXT NOT NULL,
    supporting_evidence TEXT NULL,
    request_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    decided_by_employee_id INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    rejection_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    FOREIGN KEY (customer_id) REFERENCES customer(customer_id),
    FOREIGN KEY (order_id) REFERENCES customer_order(order_id),
    FOREIGN KEY (sale_id) REFERENCES sale(sale_id),
    FOREIGN KEY (payment_id) REFERENCES payment(payment_id),
    FOREIGN KEY (decided_by_employee_id) REFERENCES employee(employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO refund_request (
    refund_reference, business_unit_id, customer_id, order_id, sale_id, payment_id, channel,
    requested_amount, approved_amount, customer_reason, supporting_evidence,
    request_status, decided_by_employee_id, decided_at, rejection_reason, created_at
) VALUES (
    'REF-REQ-1001', 1, 1, 9002, NULL, 9002, 'online',
    48.00, NULL, 'Wrong formulation received; dog has specific dietary restrictions and cannot consume this kibble.',
    'Unopened bag with intact factory seal and packaging batch photo.',
    'pending', NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 4 HOUR)
);

INSERT IGNORE INTO refund_request (
    refund_reference, business_unit_id, customer_id, order_id, sale_id, payment_id, channel,
    requested_amount, approved_amount, customer_reason, supporting_evidence,
    request_status, decided_by_employee_id, decided_at, rejection_reason, created_at
) VALUES (
    'REF-REQ-1002', 1, 1, NULL, 9001, 9001, 'walkin',
    13.50, NULL, 'Accidental duplicate shampoo purchase during walk-in visit.',
    'Unopened bottle with intact foil seal and original register receipt #REC-S-9001.',
    'pending', NULL, NULL, NULL, DATE_SUB(NOW(), INTERVAL 2 HOUR)
);

INSERT IGNORE INTO refund_request (
    refund_reference, business_unit_id, customer_id, order_id, sale_id, payment_id, channel,
    requested_amount, approved_amount, customer_reason, supporting_evidence,
    request_status, decided_by_employee_id, decided_at, rejection_reason, created_at
) VALUES (
    'REF-REQ-1000', 1, 1, 9002, NULL, 9002, 'online',
    24.00, 24.00, 'Damaged packaging on one item during transit.',
    'Courier inspection note and delivery box photograph.',
    'approved', 5, DATE_SUB(NOW(), INTERVAL 1 DAY), NULL, DATE_SUB(NOW(), INTERVAL 2 DAY)
);

