<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- all rows in business_product ---\n";
$stmt = $db->query(
    "SELECT bp.business_product_id, bp.business_unit_id, bp.selling_price, p.product_name
     FROM business_product bp
     JOIN product p ON p.product_id = bp.product_id"
);
foreach ($stmt->fetchAll() as $row) {
    echo "BP {$row['business_product_id']}: Unit {$row['business_unit_id']} | {$row['product_name']} | Price: {$row['selling_price']}\n";
}

