<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

$stmt = $db->query("SELECT user_account_id, email, username, password_hash, account_status, is_super_admin FROM user_account WHERE is_super_admin = 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Super Admin email: {$row['email']}\n";
$common = [
    'password', 'Password123!', 'Password123', 'admin', 'admin123',
    'superadmin', 'Superadmin123!', 'Superadmin123', 'SuperAdmin123!',
    'vetgrpet', 'vetgrpet123', 'ChangeMe123!', 'Admin@123', 'Admin123!'
];
foreach ($common as $p) {
    if (password_verify($p, $row['password_hash'])) {
        echo "Found matching password: '$p'\n";
        break;
    }
}

