<?php

require_once __DIR__ . '/config/database.php';

echo "PHP successfully connected using the application account!" . PHP_EOL;

echo "Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . PHP_EOL;

echo "MySQL Version: " . $pdo->query("SELECT VERSION()")->fetchColumn() . PHP_EOL;