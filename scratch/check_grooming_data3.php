<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

echo "--- Grooming Employees ---\n";
$stmt = $db->query(
    "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status,
            COALESCE(ews.work_status, 'Free') as work_status,
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

