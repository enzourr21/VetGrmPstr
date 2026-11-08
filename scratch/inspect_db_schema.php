<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();

echo "--- Tables in Database ---\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

echo "\n--- Columns of appointment table ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM appointment")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- Columns of appointment_status table ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM appointment_status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- Search for 'walk' or 'visit' across all tables/columns ---\n";
$stmt = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND (COLUMN_NAME LIKE '%walk%' OR COLUMN_NAME LIKE '%visit%' OR COLUMN_NAME LIKE '%type%' OR COLUMN_NAME LIKE '%channel%' OR COLUMN_NAME LIKE '%source%')");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

