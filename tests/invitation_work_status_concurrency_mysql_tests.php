<?php
declare(strict_types=1);

/**
 * Focused Two-Connection MySQL 8.4 Concurrency Verification Harness for
 * InvitationPlaceholderCleanupService employee_work_status locking.
 *
 * SAFETY:
 *  - Uses a strict explicit allowlist of dedicated disposable concurrency test databases
 *    (currently only `vetgrpet_test_concurrency`), supplied via --db=<name> or
 *    PAWCARINO_CONCURRENCY_TEST_DB=<name>.
 *  - Rejects production, application, rollback, system, and arbitrary database names
 *    before opening any PDO connection, and re-verifies SELECT DATABASE() after connecting.
 *  - Does not modify .env, run migrations, or touch any non-fixture records.
 *
 * Prerequisites before running:
 *  1. Provision the dedicated disposable MySQL 8.4 database `vetgrpet_test_concurrency`
 *     with all migrations 001..030 applied and grant privileges to the configured DB user.
 *  2. Run:
 *     php tests/invitation_work_status_concurrency_mysql_tests.php --db=vetgrpet_test_concurrency
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

$allowedDbs = ['vetgrpet_test_concurrency'];

$targetDb = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $targetDb = trim(substr($arg, 5));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php tests/invitation_work_status_concurrency_mysql_tests.php --db=vetgrpet_test_concurrency\n";
        echo "Allowed disposable databases: " . implode(', ', $allowedDbs) . "\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        exit(2);
    }
}

if ($targetDb === null || $targetDb === '') {
    $envDb = getenv('PAWCARINO_CONCURRENCY_TEST_DB');
    $targetDb = is_string($envDb) ? trim($envDb) : '';
}

if ($targetDb === '' || !in_array($targetDb, $allowedDbs, true)) {
    fwrite(
        STDERR,
        "Refusing to run: target database '" . ($targetDb === '' ? '<empty>' : $targetDb) . "' is not in the strict allowlist.\n"
        . "Allowed disposable databases: " . implode(', ', $allowedDbs) . "\n"
        . "Supply via --db=vetgrpet_test_concurrency or PAWCARINO_CONCURRENCY_TEST_DB=vetgrpet_test_concurrency.\n"
    );
    exit(2);
}

require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Services/InvitationPlaceholderCleanupService.php';

use App\Services\InvitationPlaceholderCleanupService;

$envPath = dirname(__DIR__) . '/.env';
$env = is_file($envPath) ? parse_ini_file($envPath, false, INI_SCANNER_RAW) : [];
if ($env === false) {
    fwrite(STDERR, "Unable to parse .env for host/port/credentials.\n");
    exit(2);
}

$host = (string)(getenv('DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1'));
$port = (string)(getenv('DB_PORT') ?: ($env['DB_PORT'] ?? '3310'));
$user = (string)(getenv('DB_USERNAME') ?: ($env['DB_USERNAME'] ?? ''));
$pass = (string)(getenv('DB_PASSWORD') ?: ($env['DB_PASSWORD'] ?? ''));

$openConn = static function () use ($host, $port, $targetDb, $allowedDbs, $user, $pass): PDO {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$targetDb};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
    $actual = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actual !== $targetDb || !in_array($actual, $allowedDbs, true)) {
        throw new RuntimeException("ABORT: connected to '{$actual}', expected allowlisted '{$targetDb}'.");
    }
    return $pdo;
};

$connA = $openConn();
$connB = $openConn();

$mysqlVersion = (string)$connA->query('SELECT VERSION()')->fetchColumn();
$isolation = (string)$connA->query('SELECT @@transaction_isolation')->fetchColumn();
echo "Connected to {$targetDb} on MySQL {$mysqlVersion} (isolation={$isolation}, lock_wait_timeout=2s)\n";

$passed = 0;
$failed = 0;

$check = static function (string $label, bool $cond, string $detail = '') use (&$passed, &$failed): void {
    if ($cond) {
        $passed++;
        echo "[PASS] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
};

/**
 * Atomically seed a disposable proven-placeholder fixture inside a single transaction.
 * Any failure rolls back all inserted rows cleanly so no partial fixture is left behind.
 *
 * @return array{uid:int,eid:int,cid:int,inv_id:int,log_id:int,actor_uid:int}
 */
$seedFixture = static function (PDO $pdo, string $tag, ?string $initialWorkStatus): array {
    $unitId = (int)$pdo->query('SELECT business_unit_id FROM business_unit ORDER BY business_unit_id ASC LIMIT 1')->fetchColumn();
    $actorUid = (int)$pdo->query('SELECT user_account_id FROM user_account ORDER BY user_account_id ASC LIMIT 1')->fetchColumn();
    $roleId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name <> 'SUPER_ADMINISTRATOR' ORDER BY role_id ASC LIMIT 1")->fetchColumn();
    if ($unitId <= 0 || $actorUid <= 0 || $roleId <= 0) {
        throw new RuntimeException('Disposable DB must have seeded business_unit, user_account, and non-super role rows.');
    }

    $now = gmdate('Y-m-d H:i:s');
    $exp = gmdate('Y-m-d H:i:s', time() + 172800);
    $email = "ws.concurrency.{$tag}." . bin2hex(random_bytes(4)) . '@example.invalid';

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO user_account (email, password_hash, account_status, is_super_admin, email_verified_at) VALUES (?, ?, 'active', 0, NULL)")
            ->execute([$email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
        $uid = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status) VALUES (?, ?, 'Conc', 'Test', 'Delivery Personnel', 'active')")
            ->execute([$uid, $unitId]);
        $eid = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO customer (user_account_id, first_name, last_name, customer_status) VALUES (?, 'Conc', 'Test', 'active')")
            ->execute([$uid]);
        $cid = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)')
            ->execute([$uid, $roleId, $unitId]);

        if ($initialWorkStatus !== null) {
            $pdo->prepare('INSERT INTO employee_work_status (employee_id, work_status) VALUES (?, ?)')
                ->execute([$eid, $initialWorkStatus]);
        }

        $pdo->prepare(
            "INSERT INTO employee_invitation (
                user_account_id, employee_id, business_unit_id, invited_email, first_name, last_name,
                position_name, token_hash, status, expires_at, email_submission_status,
                created_by_user_id, is_legacy_quarantined, created_at, updated_at
             ) VALUES (?, ?, ?, ?, 'Conc', 'Test', 'Delivery Personnel', ?, 'pending', ?, 'pending', ?, 1, ?, ?)"
        )->execute([$uid, $eid, $unitId, $email, bin2hex(random_bytes(32)), $exp, $actorUid, $now, $now]);
        $invId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO employee_invitation_role (invitation_id, role_id) VALUES (?, ?)')
            ->execute([$invId, $roleId]);

        $pdo->prepare(
            "INSERT INTO audit_log (actor_user_id, business_unit_id, action_code, target_type, target_id, affected_employee_id, summary, old_value, new_value, created_at)
             VALUES (?, ?, 'invitation.created', 'employee_invitation', ?, ?, 'Concurrency test fixture', NULL, 'pending', ?)"
        )->execute([$actorUid, $unitId, $invId, $eid, $now]);
        $logId = (int)$pdo->lastInsertId();

        $pdo->commit();

        return [
            'uid' => $uid,
            'eid' => $eid,
            'cid' => $cid,
            'inv_id' => $invId,
            'log_id' => $logId,
            'actor_uid' => $actorUid,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
};

$cleanupFixture = static function (PDO $pdo, ?array $fx): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($fx === null) {
        return;
    }
    $pdo->beginTransaction();
    try {
        if (!empty($fx['log_id'])) {
            $pdo->prepare('DELETE FROM audit_log WHERE log_id = ?')->execute([(int)$fx['log_id']]);
        }
        if (!empty($fx['inv_id'])) {
            $pdo->prepare('DELETE FROM employee_invitation_role WHERE invitation_id = ?')->execute([(int)$fx['inv_id']]);
            $pdo->prepare('DELETE FROM employee_invitation WHERE invitation_id = ?')->execute([(int)$fx['inv_id']]);
        }
        if (!empty($fx['eid'])) {
            $pdo->prepare('DELETE FROM employee_work_status WHERE employee_id = ?')->execute([(int)$fx['eid']]);
        }
        if (!empty($fx['uid'])) {
            $pdo->prepare('DELETE FROM account_role WHERE user_account_id = ?')->execute([(int)$fx['uid']]);
        }
        if (!empty($fx['cid'])) {
            $pdo->prepare('DELETE FROM customer WHERE customer_id = ?')->execute([(int)$fx['cid']]);
        }
        if (!empty($fx['eid'])) {
            $pdo->prepare('DELETE FROM employee WHERE employee_id = ?')->execute([(int)$fx['eid']]);
        }
        if (!empty($fx['uid'])) {
            $pdo->prepare('DELETE FROM user_account WHERE user_account_id = ?')->execute([(int)$fx['uid']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
};

// ---------------------------------------------------------------------------
// Scenario A — Existing work-status row (Free -> On Duty uncommitted on B)
// ---------------------------------------------------------------------------
echo "\n--- Scenario A: Existing work-status row ---\n";
$fxA = null;
try {
    $fxA = $seedFixture($connA, 'a', 'Free');
    $svcA = new InvitationPlaceholderCleanupService($connA);

    // 1. Connection B starts a transaction and changes Free -> On Duty without committing
    $connB->beginTransaction();
    $connB->prepare("UPDATE employee_work_status SET work_status = 'On Duty' WHERE employee_id = ?")
        ->execute([$fxA['eid']]);

    // 2. Connection A attempts cleanup's locking read (via applyCleanup) -> expected MySQL 1205 lock wait
    $errCodeA = null;
    $errMsgA = '';
    try {
        $svcA->applyCleanup(true, $fxA['actor_uid']);
    } catch (PDOException $e) {
        $errCodeA = (int)($e->errorInfo[1] ?? 0);
        $errMsgA = $e->getMessage();
    }
    echo "Scenario A lock-wait outcome: MySQL error={$errCodeA} ({$errMsgA})\n";
    $check('Scenario A: Connection A blocked on FOR UPDATE instead of reading stale Free (expected MySQL 1205)', $errCodeA === 1205, "got {$errCodeA}");

    // 3. Commit B's transaction
    $connB->commit();

    // 4. Connection A re-runs cleanup -> sees committed On Duty and quarantines without deleting
    $resA = $svcA->applyCleanup(true, $fxA['actor_uid']);
    $check('Scenario A: Candidate not in cleaned_user_ids after B commits On Duty', !in_array($fxA['uid'], $resA['cleaned_user_ids'], true));
    $check('Scenario A: Candidate quarantined after B commits On Duty', in_array($fxA['uid'], $resA['quarantined_user_ids'], true));

    $wsAfterA = $connA->prepare('SELECT work_status FROM employee_work_status WHERE employee_id = ?');
    $wsAfterA->execute([$fxA['eid']]);
    $check('Scenario A: employee_work_status remains On Duty', $wsAfterA->fetchColumn() === 'On Duty');

    $uaAfterA = $connA->prepare('SELECT COUNT(*) FROM user_account WHERE user_account_id = ?');
    $uaAfterA->execute([$fxA['uid']]);
    $check('Scenario A: user_account row preserved', (int)$uaAfterA->fetchColumn() === 1);
} finally {
    if ($connB->inTransaction()) {
        $connB->rollBack();
    }
    $cleanupFixture($connA, $fxA);
}

// ---------------------------------------------------------------------------
// Scenario B — Missing work-status row (reproduced cleanup locking sequence vs competing INSERT)
// Verifies the expected lock interaction when Connection A holds the cleanup's
// account/employee FOR UPDATE lock and missing-status FOR UPDATE lock while
// Connection B attempts to insert the corresponding employee_work_status row.
// Does not isolate which individual lock (parent employee lock vs index gap lock)
// caused Connection B to block.
// ---------------------------------------------------------------------------
echo "\n--- Scenario B: Missing work-status row (lock interaction for cleanup sequence) ---\n";
$fxB = null;
try {
    $fxB = $seedFixture($connA, 'b', null);

    // 1. Connection A reproduces cleanup's locking sequence (uaLock + missing employee_work_status FOR UPDATE)
    $connA->beginTransaction();
    $uaLock = $connA->prepare(
        "SELECT ua.user_account_id, e.employee_id, c.customer_id
         FROM user_account ua
         LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
         LEFT JOIN customer c ON c.user_account_id = ua.user_account_id
         WHERE ua.user_account_id = ?
         FOR UPDATE"
    );
    $uaLock->execute([$fxB['uid']]);
    $uaLock->fetch();

    $wsLock = $connA->prepare('SELECT work_status FROM employee_work_status WHERE employee_id = ? FOR UPDATE');
    $wsLock->execute([$fxB['eid']]);
    $check('Scenario B: Missing work-status row read returns false', $wsLock->fetch() === false);

    // 2. Connection B attempts a competing INSERT -> expected MySQL 1205 under this lock sequence
    $errCodeB = null;
    $errMsgB = '';
    try {
        $connB->prepare("INSERT INTO employee_work_status (employee_id, work_status) VALUES (?, 'On Duty')")
            ->execute([$fxB['eid']]);
    } catch (PDOException $e) {
        $errCodeB = (int)($e->errorInfo[1] ?? 0);
        $errMsgB = $e->getMessage();
    }
    echo "Scenario B insert-race outcome: MySQL error={$errCodeB} ({$errMsgB})\n";
    $check('Scenario B: Competing INSERT blocked under cleanup locking sequence (expected MySQL 1205)', $errCodeB === 1205, "got {$errCodeB}");

    $connA->rollBack();

    $wsCntB = $connA->prepare('SELECT COUNT(*) FROM employee_work_status WHERE employee_id = ?');
    $wsCntB->execute([$fxB['eid']]);
    $check('Scenario B: No unsafe employee_work_status row inserted', (int)$wsCntB->fetchColumn() === 0);
} finally {
    if ($connA->inTransaction()) {
        $connA->rollBack();
    }
    if ($connB->inTransaction()) {
        $connB->rollBack();
    }
    $cleanupFixture($connA, $fxB);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
