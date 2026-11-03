<?php

require_once dirname(__DIR__) . '/config/database.php';

echo "=== DATABASE CONNECTION INFO ===\n";
echo "Host:     $host\n";
echo "Port:     $port\n";
echo "Database: $database\n";
echo "Username: $username\n";
echo "Status:   Connected OK\n\n";

// List all tables
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "=== TABLES FOUND: " . count($tables) . " ===\n\n";

foreach ($tables as $table) {
    echo str_repeat('=', 60) . "\n";
    echo "TABLE: $table\n";
    echo str_repeat('=', 60) . "\n\n";

    // DESCRIBE
    echo "--- DESCRIBE $table ---\n";
    $cols = $pdo->query("DESCRIBE `$table`")->fetchAll();
    echo str_pad('Field', 30) . str_pad('Type', 30) . str_pad('Null', 6) . str_pad('Key', 6) . str_pad('Default', 15) . "Extra\n";
    echo str_repeat('-', 100) . "\n";
    foreach ($cols as $col) {
        echo str_pad($col['Field'], 30)
           . str_pad($col['Type'], 30)
           . str_pad($col['Null'], 6)
           . str_pad($col['Key'], 6)
           . str_pad($col['Default'] ?? 'NULL', 15)
           . ($col['Extra'] ?? '') . "\n";
    }
    echo "\n";

    // SHOW CREATE TABLE
    echo "--- CREATE TABLE $table ---\n";
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
    $key = isset($create['Create Table']) ? 'Create Table' : 'Create View';
    echo $create[$key] . ";\n\n";
}

echo "=== DONE ===\n";
