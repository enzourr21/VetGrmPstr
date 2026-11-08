<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();
echo "--- service table columns ---\n";
foreach ($db->query('DESCRIBE service')->fetchAll() as $col) {
    echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Default']}\n";
}

echo "\n--- service categories in db ---\n";
$stmt = $db->query('SELECT service_id, service_name, service_category, grooming_category, base_price, estimated_duration_minutes, service_status FROM service');
foreach ($stmt->fetchAll() as $row) {
    echo "ID {$row['service_id']}: {$row['service_name']} | cat: {$row['service_category']} | grooming_cat: {$row['grooming_category']} | price: {$row['base_price']} | duration: {$row['estimated_duration_minutes']} | status: {$row['service_status']}\n";
}
