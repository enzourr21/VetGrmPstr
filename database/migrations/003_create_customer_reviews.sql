-- Persistent customer reviews table (no fake/mock reviews are seeded).
CREATE TABLE IF NOT EXISTS customer_review (
    review_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    business_unit_id INT UNSIGNED NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_title VARCHAR(150) NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customer(customer_id),
    FOREIGN KEY (business_unit_id) REFERENCES business_unit(business_unit_id),
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

