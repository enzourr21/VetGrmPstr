<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- customer columns ---\n";
$stmt = $db->query("DESCRIBE customer");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

echo "\n--- customer rows ---\n";
$stmt = $db->query("SELECT customer_id, user_account_id, first_name, last_name, customer_status FROM customer");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "Cust {$row['customer_id']}: {$row['first_name']} {$row['last_name']} | UA: {$row['user_account_id']} | Status: {$row['customer_status']}\n";
}

echo "\n--- pet columns ---\n";
$stmt = $db->query("DESCRIBE pet");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']} | {$row['Null']} | {$row['Default']}\n";
}

