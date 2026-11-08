<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- Medical appointments in db ---\n";
$stmt = $db->query(
    "SELECT a.*, st.status_name, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, c.phone, ua.email
     FROM appointment a
     JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
     JOIN customer c ON c.customer_id = a.customer_id
     LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
     WHERE a.business_unit_id = 2 AND a.appointment_type = 'medical'
     ORDER BY a.appointment_id DESC"
);
$appts = $stmt->fetchAll();
echo "Found " . count($appts) . " medical appointments:\n";
foreach ($appts as $a) {
    echo "ID {$a['appointment_id']} | Date: {$a['preferred_date']} | Status: {$a['status_name']} | Cust: {$a['customer_name']} | Notes: {$a['notes']}\n";
    // Check pets
    $petStmt = $db->prepare(
        "SELECT p.*, ap.appointment_pet_id
         FROM appointment_pet ap
         JOIN pet p ON p.pet_id = ap.pet_id
         WHERE ap.appointment_id = ?"
    );
    $petStmt->execute([$a['appointment_id']]);
    foreach ($petStmt->fetchAll() as $p) {
        echo "  -> Pet: {$p['name']} ({$p['species']}, {$p['breed']}, sex: {$p['gender']}, birth: {$p['birth_date']})\n";
    }
    // Check performed services
    $psStmt = $db->prepare(
        "SELECT ps.*, s.service_name, CONCAT(e.first_name, ' ', e.last_name) AS performer_name, e.position
         FROM performed_service ps
         JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
         JOIN service s ON s.service_id = ps.service_id
         LEFT JOIN employee e ON e.employee_id = ps.performer_employee_id
         WHERE ap.appointment_id = ?"
    );
    $psStmt->execute([$a['appointment_id']]);
    foreach ($psStmt->fetchAll() as $ps) {
        echo "  -> Performed Service: {$ps['service_name']} | Performer: {$ps['performer_name']} ({$ps['position']})\n";
    }
}
