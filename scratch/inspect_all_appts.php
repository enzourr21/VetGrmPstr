<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- All appointments in db ---\n";
$stmt = $db->query(
    "SELECT a.appointment_id, a.business_unit_id, a.appointment_type, a.preferred_date, st.status_name
     FROM appointment a
     JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id"
);
$appts = $stmt->fetchAll();
echo "Found " . count($appts) . " total appointments:\n";
foreach ($appts as $a) {
    echo "ID {$a['appointment_id']} | Unit: {$a['business_unit_id']} | Type: {$a['appointment_type']} | Date: {$a['preferred_date']} | Status: {$a['status_name']}\n";
}

