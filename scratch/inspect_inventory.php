<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- inventory_batch table columns ---\n";
foreach ($db->query('DESCRIBE inventory_batch')->fetchAll() as $col) {
    echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Default']}\n";
}

echo "\n--- sample inventory_batch rows ---\n";
$stmt = $db->query(
    "SELECT ib.*, p.product_name, bp.business_unit_id
     FROM inventory_batch ib
     JOIN inventory i ON i.inventory_id = ib.inventory_id
     JOIN business_product bp ON bp.business_product_id = i.business_product_id
     JOIN product p ON p.product_id = bp.product_id"
);
foreach ($stmt->fetchAll() as $row) {
    echo "Batch ID {$row['batch_id']}: {$row['product_name']} (Unit {$row['business_unit_id']}) | Batch: {$row['batch_number']} | Exp: {$row['expiration_date']} | Qty: {$row['quantity']} | Status: {$row['batch_status']}\n";
}

echo "\n--- product table columns ---\n";
foreach ($db->query('DESCRIBE product')->fetchAll() as $col) {
    echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Default']}\n";
}

