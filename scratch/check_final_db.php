<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();
$appts = $pdo->query('SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end, st.status_name, a.notes FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id ORDER BY a.business_unit_id ASC, a.preferred_date ASC, a.preferred_time ASC')->fetchAll(PDO::FETCH_ASSOC);
echo "Total appointments: " . count($appts) . "\n";
foreach ($appts as $a) {
    echo "Unit {$a['business_unit_id']} | ID #{$a['appointment_id']} ({$a['status_name']}): {$a['preferred_date']} {$a['preferred_time']} | Notes: " . substr((string)$a['notes'], 0, 40) . "...\n";
}

