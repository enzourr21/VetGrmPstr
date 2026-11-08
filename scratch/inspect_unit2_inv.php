<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- inventory table columns ---\n";
foreach ($db->query('DESCRIBE inventory')->fetchAll() as $col) {
    echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Default']}\n";
}

echo "\n--- products for Unit 2 (Veterinary Clinic) ---\n";
$stmt = $db->query(
    "SELECT bp.business_product_id, bp.business_unit_id, bp.selling_price, p.product_name, p.sku,
            i.inventory_id, i.on_hand, i.committed
     FROM business_product bp
     JOIN product p ON p.product_id = bp.product_id
     LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
     WHERE bp.business_unit_id = 2"
);
foreach ($stmt->fetchAll() as $row) {
    echo "BP {$row['business_product_id']}: {$row['product_name']} | SKU: {$row['sku']} | On Hand: {$row['on_hand']} | Inv ID: {$row['inventory_id']}\n";
}

