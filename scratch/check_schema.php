<?php
require __DIR__ . '/../app/bootstrap.php';
$pdo = \App\Core\Database::pdo();

echo "--- APPOINTMENT COLUMNS ---\n";
$stmt = $pdo->query("SHOW COLUMNS FROM appointment");
while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    echo "{$r['Field']}: {$r['Type']} (Null: {$r['Null']}, Key: {$r['Key']}, Default: {$r['Default']})\n";
}

echo "\n--- APPOINTMENT_PET COLUMNS ---\n";
$stmt = $pdo->query("SHOW COLUMNS FROM appointment_pet");
while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    echo "{$r['Field']}: {$r['Type']} (Null: {$r['Null']}, Key: {$r['Key']}, Default: {$r['Default']})\n";
}
