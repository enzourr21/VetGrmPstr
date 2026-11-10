<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();
$appts = $pdo->query('SELECT a.*, st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id IN (7, 8, 9, 10, 11, 12, 13) ORDER BY a.appointment_id ASC')->fetchAll(PDO::FETCH_ASSOC);
foreach ($appts as $a) {
    echo "Appt #{$a['appointment_id']} ({$a['status_name']}): Unit {$a['business_unit_id']}, Preferred: {$a['preferred_date']} {$a['preferred_time']}, Begin: {$a['appointment_begin']}, End: {$a['appointment_end']}\n";
    echo "Notes: {$a['notes']}\n";
    $ps = $pdo->query("SELECT ps.*, s.service_name, s.estimated_duration_minutes FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id JOIN service s ON s.service_id = ps.service_id WHERE ap.appointment_id = {$a['appointment_id']}")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ps as $p) {
        echo "  - Svc #{$p['service_id']} '{$p['service_name']}' ({$p['estimated_duration_minutes']}m) - Price: {$p['performed_price']}, Performer: {$p['performer_employee_id']}\n";
    }
    echo "\n";
}

