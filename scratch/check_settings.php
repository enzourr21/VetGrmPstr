<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- operating_hours schema ---\n";
$stmt = $db->query("DESCRIBE operating_hours");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Key']}\n";
}

echo "\n--- business_settings schema ---\n";
$stmt = $db->query("DESCRIBE business_settings");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Key']}\n";
}

echo "\n--- business_settings data for unit 3 ---\n";
$stmt = $db->query("SELECT * FROM business_settings WHERE business_unit_id = 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- operating_hours data for unit 3 ---\n";
$stmt = $db->query("SELECT * FROM operating_hours WHERE business_unit_id = 3");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

