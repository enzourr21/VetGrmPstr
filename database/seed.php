<?php
/** Runs database/seeders/{roles,permissions,sample_data}.sql in order. Idempotent. Usage: php database/seed.php */
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
echo "Done.\n";

