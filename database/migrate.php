<?php
/**
 * Versioned migration runner. Applies database/migrations/NNN_*.sql in order,
 * recording each in schema_migrations. Never drops or resets existing data.
 * Usage: php database/migrate.php
 */
require_once dirname(__DIR__) . '/config/database.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/migrations/[0-9][0-9][0-9]_*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        echo "SKIP  $name (already applied)\n";
        continue;
    }
    $sql = file_get_contents($file);
    // strip full-line comments, then split on statement terminators
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql) . "\n")));
    try {
        foreach ($statements as $stmt) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }
        $ins = $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
        $ins->execute([$name]);
        echo "APPLY $name (" . count($statements) . " statements)\n";
    } catch (Throwable $e) {
        echo "FAIL  $name: " . $e->getMessage() . "\n";
        exit(1);
    }
}
echo "Done.\n";

