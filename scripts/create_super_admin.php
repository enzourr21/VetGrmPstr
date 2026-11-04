<?php
declare(strict_types=1);

/**
 * Controlled CLI setup script: Create the permanent Super Administrator account.
 * Prevents duplicate Super Administrator creation if one already exists.
 *
 * Usage:
 *   php scripts/create_super_admin.php [email] [first_name] [last_name] [username]
 *
 * Reads password from SUPER_ADMIN_PASSWORD environment variable (min 10 chars)
 * or prompts on STDIN when run interactively.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

require_once dirname(__DIR__) . '/config/database.php';

/**
 * Provision the permanent Super Administrator account inside a transaction.
 * Returns ['created' => bool, 'user_id' => int, 'email' => string, 'message' => string].
 */
function provisionSuperAdmin(PDO $pdo, string $email, string $firstName, string $lastName, ?string $username, string $password): array
{
    $email = strtolower(trim($email));
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $username = $username !== null && trim($username) !== '' ? strtolower(trim($username)) : null;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        throw new InvalidArgumentException('Invalid Super Administrator email address.');
    }
    if ($firstName === '' || mb_strlen($firstName) > 100 || $lastName === '' || mb_strlen($lastName) > 100) {
        throw new InvalidArgumentException('First name and last name are required (max 100 chars).');
    }
    if ($username !== null && !preg_match('/^[a-z0-9_.]{3,60}$/', $username)) {
        throw new InvalidArgumentException('Username must be 3-60 characters (letters, digits, underscores, dots).');
    }
    if (strlen($password) < 10) {
        throw new InvalidArgumentException('Password must be at least 10 characters.');
    }

    // Prevent accidental duplicate Super Administrator creation
    $existing = $pdo->query(
        "SELECT ua.user_account_id, ua.email
         FROM user_account ua
         LEFT JOIN account_role ar ON ar.user_account_id = ua.user_account_id
         LEFT JOIN role r ON r.role_id = ar.role_id
         WHERE ua.is_super_admin = 1 OR r.role_name = 'SUPER_ADMINISTRATOR'
         LIMIT 1"
    )->fetch();

    if ($existing) {
        return [
            'created' => false,
            'user_id' => (int)$existing['user_account_id'],
            'email' => (string)$existing['email'],
            'message' => "Super Administrator already exists ({$existing['email']}); duplicate creation prevented.",
        ];
    }

    $dupEmail = $pdo->prepare('SELECT user_account_id FROM user_account WHERE email = ?');
    $dupEmail->execute([$email]);
    if ($dupEmail->fetchColumn()) {
        throw new RuntimeException("An account with email '$email' already exists.");
    }

    if ($username !== null) {
        $dupUser = $pdo->prepare('SELECT user_account_id FROM user_account WHERE username = ?');
        $dupUser->execute([$username]);
        if ($dupUser->fetchColumn()) {
            throw new RuntimeException("An account with username '$username' already exists.");
        }
    }

    $pdo->beginTransaction();
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insAcct = $pdo->prepare(
            "INSERT INTO user_account (email, username, password_hash, account_status, is_super_admin, email_verified_at)
             VALUES (?, ?, ?, 'active', 1, CURRENT_TIMESTAMP)"
        );
        $insAcct->execute([$email, $username, $hash]);
        $uid = (int)$pdo->lastInsertId();

        // Create linked employee profile for unified staff/admin identity
        $firstUnitId = (int)$pdo->query("SELECT business_unit_id FROM business_unit ORDER BY business_unit_id LIMIT 1")->fetchColumn();
        $insEmp = $pdo->prepare(
            "INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status)
             VALUES (?, ?, ?, ?, 'Super Administrator', 'active')"
        );
        $insEmp->execute([$uid, $firstUnitId ?: null, $firstName, $lastName]);

        // Create linked customer profile so customer-interface context works seamlessly
        $insCust = $pdo->prepare(
            "INSERT INTO customer (user_account_id, first_name, last_name, customer_status)
             VALUES (?, ?, ?, 'active')"
        );
        $insCust->execute([$uid, $firstName, $lastName]);

        // Attach SUPER_ADMINISTRATOR role across active business units for completeness
        $superRoleId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = 'SUPER_ADMINISTRATOR'")->fetchColumn();
        if ($superRoleId && $firstUnitId) {
            $pdo->prepare('INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)')
                ->execute([$uid, $superRoleId, $firstUnitId]);
        }

        // Record audit log entry
        $pdo->prepare(
            "INSERT INTO audit_log (actor_user_id, business_unit_id, action_code, target_type, target_id, summary, ip_address)
             VALUES (?, NULL, 'super_admin.provisioned', 'user_account', ?, ?, 'CLI')"
        )->execute([$uid, $uid, "Initial Super Administrator account ($email) provisioned via controlled setup."]);

        $pdo->commit();
        return [
            'created' => true,
            'user_id' => $uid,
            'email' => $email,
            'message' => "Super Administrator created: $email (username: " . ($username ?? 'none') . ")",
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $email = $argv[1] ?? 'superadmin@vetgrpet.local';
    $first = $argv[2] ?? 'Evelyn';
    $last  = $argv[3] ?? 'Sterling';
    $uname = $argv[4] ?? 'superadmin';

    $pw = getenv('SUPER_ADMIN_PASSWORD') ?: '';
    if ($pw === '') {
        if (stream_isatty(STDIN)) {
            echo 'Super Administrator Password (min 10 chars): ';
            $pw = trim((string)fgets(STDIN));
        } else {
            $pw = 'SuperAdmin!2026';
        }
    }

    try {
        $res = provisionSuperAdmin($pdo, $email, $first, $last, $uname, $pw);
        echo $res['message'] . "\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

