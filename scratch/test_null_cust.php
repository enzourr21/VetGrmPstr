<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

try {
    $db->prepare("INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date) VALUES (3, 3, NULL, 1, 'grooming', CURDATE())")->execute();
    echo "Inserted NULL customer_id successfully!\n";
} catch (\Throwable $e) {
    echo "Failed inserting NULL customer_id: " . $e->getMessage() . "\n";
}

