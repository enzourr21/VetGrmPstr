<?php
/** Runs database/seeders/{roles,permissions,sample_data}.sql in order, plus controlled initial admin provisioning. Idempotent. Usage: php database/seed.php */
require_once dirname(__DIR__) . '/config/database.php';

foreach (['roles', 'permissions', 'sample_data'] as $name) {
    $sql = preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", file_get_contents(__DIR__ . "/seeders/$name.sql")));
    $n = 0;
    foreach (array_filter(array_map('trim', explode(";\n", $sql . "\n"))) as $stmt) {
        $pdo->exec($stmt);
        $n++;
    }
    echo "SEED $name ($n statements)\n";
}

require_once dirname(__DIR__) . '/scripts/create_super_admin.php';
require_once dirname(__DIR__) . '/scripts/create_system_admins.php';

$sa = provisionSuperAdmin($pdo, 'superadmin@vetgrpet.local', 'Evelyn', 'Sterling', 'superadmin', getenv('SUPER_ADMIN_PASSWORD') ?: 'SuperAdmin!2026');
echo 'ADMIN ' . $sa['message'] . "\n";

foreach (provisionUnitSystemAdmins($pdo) as $line) {
    echo 'ADMIN ' . $line . "\n";
}

echo "Done.\n";
