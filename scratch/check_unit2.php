<?php
require __DIR__ . '/../app/bootstrap.php';
$pdo = \App\Core\Database::pdo();

echo "=== ALL TABLES ===\n";
$stmt = $pdo->query("SHOW TABLES");
while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
    echo $r[0] . "\n";
}
