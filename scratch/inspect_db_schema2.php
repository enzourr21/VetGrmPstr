<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();

echo "--- Columns of sale table ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM sale")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- Columns of service_invoice table ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM service_invoice")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- Columns of performed_service table ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM performed_service")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- Migrations ---\n";
$migs = $pdo->query("SELECT * FROM schema_migrations ORDER BY migration_id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($migs);

