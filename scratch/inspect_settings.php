<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- business_settings columns ---\n";
foreach ($db->query('DESCRIBE business_settings')->fetchAll() as $col) {
    echo "{$col['Field']} | {$col['Type']} | {$col['Null']} | {$col['Default']}\n";
}

echo "\n--- rows in business_settings ---\n";
foreach ($db->query('SELECT * FROM business_settings')->fetchAll() as $row) {
    print_r($row);
}

