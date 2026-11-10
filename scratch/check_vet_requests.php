<?php
require __DIR__ . '/../app/bootstrap.php';
$pdo = \App\Core\Database::pdo();

echo "=== PENDING / AWAITING REQUESTS IN UNIT 2 ===\n";
$stmt = $pdo->query("SELECT a.appointment_id, a.preferred_date, a.preferred_time, st.status_name, c.first_name, c.last_name
FROM appointment a
JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
JOIN customer c ON c.customer_id = a.customer_id
WHERE a.business_unit_id = 2 AND st.status_name IN ('Pending', 'Awaiting Customer Response')");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "ID: {$r['appointment_id']} | Date: {$r['preferred_date']} | Status: {$r['status_name']} | Cust: {$r['first_name']} {$r['last_name']}\n";
}

