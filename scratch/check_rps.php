<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();
foreach ([7, 8, 9, 10, 11, 12, 13] as $id) {
    $rps = $pdo->query("SELECT rps.*, s.service_name, s.estimated_duration_minutes FROM requested_pet_service rps JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id JOIN service s ON s.service_id = rps.service_id WHERE ap.appointment_id = $id")->fetchAll(PDO::FETCH_ASSOC);
    $ps = $pdo->query("SELECT ps.*, s.service_name, s.estimated_duration_minutes FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id JOIN service s ON s.service_id = ps.service_id WHERE ap.appointment_id = $id")->fetchAll(PDO::FETCH_ASSOC);
    echo "Appt #$id - RPS: " . count($rps) . ", PS: " . count($ps) . "\n";
}

