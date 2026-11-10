<?php
require __DIR__ . '/../app/bootstrap.php';
$pdo = \App\Core\Database::pdo();

echo "=== BUSINESS SETTINGS FOR UNIT 2 ===\n";
$stmt = $pdo->query("SELECT * FROM business_settings WHERE business_unit_id = 2");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
}

echo "=== OPERATING HOURS FOR UNIT 2 ===\n";
$stmt = $pdo->query("SELECT * FROM operating_hours WHERE business_unit_id = 2 ORDER BY day_of_week");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "Day: {$r['day_of_week']} | Open: {$r['is_open']} ({$r['open_time']} - {$r['close_time']})\n";
}
