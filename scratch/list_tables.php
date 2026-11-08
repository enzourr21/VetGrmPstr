<?php
require 'app/bootstrap.php';
$db = \App\Core\Database::pdo();
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo implode("\n", $tables);

