<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- appointment columns ---\n";
$stmt = $db->query("DESCRIBE appointment");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

echo "\n--- appointment_status rows ---\n";
$stmt = $db->query("SELECT * FROM appointment_status");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID {$row['appointment_status_id']}: {$row['status_name']}\n";
}

echo "\n--- appointment_pet columns ---\n";
$stmt = $db->query("DESCRIBE appointment_pet");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

echo "\n--- requested_pet_service columns ---\n";
$stmt = $db->query("DESCRIBE requested_pet_service");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

echo "\n--- performed_service columns ---\n";
$stmt = $db->query("DESCRIBE performed_service");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

echo "\n--- service_invoice columns ---\n";
$stmt = $db->query("DESCRIBE service_invoice");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

echo "\n--- invoice_item columns ---\n";
$stmt = $db->query("DESCRIBE invoice_item");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

echo "\n--- payment columns ---\n";
$stmt = $db->query("DESCRIBE payment");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

