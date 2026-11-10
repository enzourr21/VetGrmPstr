<?php
require 'app/bootstrap.php';
$pdo = \App\Core\Database::pdo();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        if (preg_match('/(setting|config|overlap|meta|json|extra|rule|policy|param)/i', $c['Field'])) {
            echo "$t.{$c['Field']} ({$c['Type']})\n";
        }
    }
}

