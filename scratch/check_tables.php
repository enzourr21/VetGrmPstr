<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- service columns ---\n";
$stmt = $db->query("DESCRIBE service");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']}\n";
}

echo "\n--- employee columns ---\n";
$stmt = $db->query("DESCRIBE employee");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']}\n";
}

