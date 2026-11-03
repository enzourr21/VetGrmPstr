<?php

$envPath = dirname(__DIR__) . '/.env';

if (!file_exists($envPath)) {
    throw new RuntimeException('.env file not found.');
}

$env = parse_ini_file($envPath, false, INI_SCANNER_RAW);

if ($env === false) {
    throw new RuntimeException('Unable to read .env file.');
}

$host = $env['DB_HOST'];
$port = $env['DB_PORT'];
$database = $env['DB_DATABASE'];
$username = $env['DB_USERNAME'];
$password = $env['DB_PASSWORD'];

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    throw new RuntimeException(
        'Database connection failed.',
        0,
        $e
    );
}