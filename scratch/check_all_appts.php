<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- All Appointments ---\n";
$stmt = $db->query(
    "SELECT a.appointment_id, a.business_unit_id, a.customer_id, a.appointment_status_id,
            st.status_name, a.appointment_type, a.preferred_date, a.preferred_time
     FROM appointment a
     JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
     ORDER BY a.appointment_id DESC
     LIMIT 10"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "Appt {$row['appointment_id']}: Unit: {$row['business_unit_id']} | Date: {$row['preferred_date']} {$row['preferred_time']} | Status: {$row['status_name']} | Type: {$row['appointment_type']}\n";
}

