<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- existing pets ---\n";
$stmt = $db->query("SELECT pet_id, customer_id, pet_name, species, breed, pet_status FROM pet");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "Pet {$row['pet_id']}: {$row['pet_name']} ({$row['species']}) | Owner: #{$row['customer_id']} | Status: {$row['pet_status']}\n";
}

