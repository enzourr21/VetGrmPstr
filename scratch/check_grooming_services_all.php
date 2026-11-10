<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

$stmt = $db->query("SELECT * FROM service WHERE service_category = 'grooming' ORDER BY service_id");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID {$row['service_id']}: {$row['service_name']} | GroomingCat: {$row['grooming_category']} | Price: {$row['base_price']} | Dur: {$row['estimated_duration_minutes']}m | Desc: {$row['description']}\n";
}

