<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

$stmt = $db->query("
    SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'vetgrpet_db' AND TABLE_NAME IN ('appointment', 'appointment_pet', 'pet')
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['REFERENCED_TABLE_NAME']) {
        echo "{$row['TABLE_NAME']}.{$row['COLUMN_NAME']} -> {$row['REFERENCED_TABLE_NAME']}.{$row['REFERENCED_COLUMN_NAME']} ({$row['CONSTRAINT_NAME']})\n";
    }
}

