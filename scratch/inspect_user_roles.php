<?php
require_once __DIR__ . '/../app/bootstrap.php';
$db = \App\Core\Database::pdo();
$stmt = $db->query(
    "SELECT ua.user_account_id, ua.email, ua.is_super_admin, e.employee_id, e.business_unit_id,
            GROUP_CONCAT(DISTINCT r.role_name) as roles
     FROM user_account ua
     LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
     LEFT JOIN account_role ar ON ar.user_account_id = ua.user_account_id
     LEFT JOIN role r ON r.role_id = ar.role_id
     GROUP BY ua.user_account_id"
);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf(
        "ID: %d | Email: %s | Super: %d | EmpID: %s | BU: %s | Roles: %s\n",
        $r['user_account_id'],
        $r['email'],
        $r['is_super_admin'],
        $r['employee_id'] ?? 'none',
        $r['business_unit_id'] ?? 'none',
        $r['roles'] ?? 'none'
    );
}
