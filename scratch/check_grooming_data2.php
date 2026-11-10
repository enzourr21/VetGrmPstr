<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- employee_work_status columns ---\n";
$stmt = $db->query("DESCRIBE employee_work_status");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "{$row['Field']} | {$row['Type']}\n";
}

echo "\n--- Grooming Services ---\n";
$stmt = $db->query("SELECT service_id, service_name, service_category, grooming_category, base_price, estimated_duration_minutes, service_status FROM service WHERE service_category = 'grooming'");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID {$row['service_id']}: {$row['service_name']} | GroomingCat: {$row['grooming_category']} | Price: {$row['base_price']} | Dur: {$row['estimated_duration_minutes']}m | Status: {$row['service_status']}\n";
}

echo "\n--- Grooming Employees ---\n";
$stmt = $db->query(
    "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status,
            ews.work_status, ews.notes as work_notes,
            GROUP_CONCAT(r.role_name) as roles
     FROM employee e
     LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
     LEFT JOIN account_role ar ON ar.user_account_id = e.user_account_id AND ar.business_unit_id = e.business_unit_id
     LEFT JOIN role r ON r.role_id = ar.role_id
     WHERE e.business_unit_id = 3
     GROUP BY e.employee_id"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "Emp {$row['employee_id']}: {$row['first_name']} {$row['last_name']} | Pos: {$row['position']} | Status: {$row['employee_status']} | Work: {$row['work_status']} | Roles: {$row['roles']}\n";
}

