<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- Grooming Appointments ---\n";
$stmt = $db->query(
    "SELECT a.appointment_id, a.business_unit_id, a.customer_id, a.appointment_status_id,
            st.status_name, a.appointment_type, a.preferred_date, a.preferred_time,
            a.appointment_begin, a.appointment_end,
            c.first_name as cust_first, c.last_name as cust_last, c.phone as cust_phone
     FROM appointment a
     JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
     LEFT JOIN customer c ON c.customer_id = a.customer_id
     WHERE a.business_unit_id = 3
     ORDER BY a.appointment_id DESC
     LIMIT 10"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "Appt {$row['appointment_id']}: Date: {$row['preferred_date']} {$row['preferred_time']} | Status: {$row['status_name']} | Type: {$row['appointment_type']} | Cust: {$row['cust_first']} {$row['cust_last']} (#{$row['customer_id']})\n";
}

