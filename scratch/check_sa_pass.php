<?php
require 'd:/VETGRPETproj/app/bootstrap.php';
$db = \App\Core\Database::pdo();

$stmt = $db->query("SELECT user_account_id, email, username, password_hash, account_status, is_super_admin FROM user_account WHERE is_super_admin = 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Super Admin: {$row['email']}, Status: {$row['account_status']}\n";
$passwordsToTry = ['password', 'password123', 'admin', 'admin123', 'superadmin', 'vetgrpet', 'secret'];
foreach ($passwordsToTry as $p) {
    if (password_verify($p, $row['password_hash'])) {
        echo "Found password: $p\n";
    }
}

