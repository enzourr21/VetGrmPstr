<?php
/**
 * Development helper: create (or assign) a Grooming Services receptionist.
 * Usage:  php scripts/create_receptionist.php staff@example.test "First" "Last"
 * The password is read from the RECEPTIONIST_PASSWORD environment variable (min 10 chars) or typed at the prompt.
 * No password is stored in the repository.
 */
require_once dirname(__DIR__) . '/config/database.php';

[$self, $email, $first, $last] = array_pad($argv, 4, null);
if (!$email || !$first || !$last || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create_receptionist.php <email> <first> <last>\n");
    exit(1);
}
$password = getenv('RECEPTIONIST_PASSWORD') ?: '';
if ($password === '') {
    echo 'Password (min 10 chars, input is visible): ';
    $password = trim((string)fgets(STDIN));
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}
$email = strtolower($email);

$pdo->beginTransaction();
try {
    $unit = (int)$pdo->query("SELECT business_unit_id FROM business_unit WHERE business_unit_name='Grooming Services'")->fetchColumn();
    $role = (int)$pdo->query("SELECT role_id FROM role WHERE role_name='RECEPTIONIST'")->fetchColumn();
    if (!$unit || !$role) {
        throw new RuntimeException('Run php database/seed.php first.');
    }
    $s = $pdo->prepare('SELECT user_account_id FROM user_account WHERE email=?');
    $s->execute([$email]);
    $uid = $s->fetchColumn();
    if (!$uid) {
        $pdo->prepare('INSERT INTO user_account (email, password_hash) VALUES (?,?)')->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();
    }
    $pdo->prepare('INSERT IGNORE INTO employee (user_account_id, first_name, last_name, position) VALUES (?,?,?,?)')->execute([$uid, $first, $last, 'Receptionist']);
    $pdo->prepare('INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?,?,?)')->execute([$uid, $role, $unit]);
    $pdo->commit();
    echo "Receptionist ready: $email (position Receptionist, role RECEPTIONIST, unit Grooming Services)\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

