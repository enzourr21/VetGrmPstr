<?php
require __DIR__ . '/../app/bootstrap.php';
$pdo = \App\Core\Database::pdo();

echo "=== PERFORMED_SERVICE FOR UNIT 2 ===\n";
$stmt = $pdo->query("SELECT ps.*, ap.appointment_id, s.service_name
FROM performed_service ps
JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
JOIN appointment a ON a.appointment_id = ap.appointment_id
JOIN service s ON s.service_id = ps.service_id
WHERE a.business_unit_id = 2
ORDER BY ps.performed_service_id DESC LIMIT 10");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "PS ID: {$r['performed_service_id']} | Appt: {$r['appointment_id']} | Service: {$r['service_name']} | Status: {$r['performed_status']} | Price: {$r['performed_price']} | Performer: {$r['performer_employee_id']}\n";
}
