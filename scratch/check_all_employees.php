<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

$stmt = $db->query("SELECT e.*, bu.business_unit_name FROM employee e LEFT JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID {$row['employee_id']}: {$row['first_name']} {$row['last_name']} | Unit: {$row['business_unit_name']} ({$row['business_unit_id']}) | Pos: {$row['position']}\n";
}

