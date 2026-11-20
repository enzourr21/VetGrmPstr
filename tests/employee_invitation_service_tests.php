<?php
declare(strict_types=1);

/**
 * Isolated Service Tests for EmployeeInvitationService (Checkpoint 2).
 *
 * IMPORTANT VERIFICATION DISTINCTION:
 *  - These are isolated unit tests and stateful in-memory FakePDO tests.
 *  - They DO NOT connect to MySQL, execute migrations, start the HTTP app, or contact SMTP.
 *  - Real MySQL 8.4 constraint/locking verification requires applying Migration 029 to an
 *    isolated disposable MySQL test instance in a later checkpoint.
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';

use App\Core\EnvConfig;
use App\Services\EmployeeInvitationService;

/**
 * Stateful in-memory PDO double supporting transactions (commit/rollback snapshots),
 * FOR UPDATE lock tracking, and the exact queries issued by EmployeeInvitationService + Audit::record().
 */
final class FakeInvitationPdo extends PDO
{
    public array $users = [];
    public array $employees = [];
    public array $businessUnits = [];
    public array $roles = [];
    public array $accountRoles = [];
    public array $invitations = [];
    public array $auditLogs = [];

    public int $nextInvitationId = 100;
    public int $nextAuditId = 1;
    public int $lastInsertIdVal = 0;

    public bool $inTx = false;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    public array $forUpdateQueries = [];
    public ?string $failOnSqlSubstring = null;

    private ?array $snapshot = null;

    public function __construct()
    {
        // Intentionally skip parent::__construct() so no live DB connection is attempted.
    }

    public function beginTransaction(): bool
    {
        if ($this->inTx) {
            throw new RuntimeException('Transaction already active.');
        }
        $this->inTx = true;
        $this->snapshot = [
            'invitations' => $this->invitations,
            'auditLogs' => $this->auditLogs,
            'nextInvitationId' => $this->nextInvitationId,
            'nextAuditId' => $this->nextAuditId,
        ];
        return true;
    }

    public function commit(): bool
    {
        if (!$this->inTx) {
            throw new RuntimeException('No active transaction to commit.');
        }
        $this->inTx = false;
        $this->snapshot = null;
        $this->commitCount++;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->inTx) {
            throw new RuntimeException('No active transaction to roll back.');
        }
        $this->inTx = false;
        if ($this->snapshot !== null) {
            $this->invitations = $this->snapshot['invitations'];
            $this->auditLogs = $this->snapshot['auditLogs'];
            $this->nextInvitationId = $this->snapshot['nextInvitationId'];
            $this->nextAuditId = $this->snapshot['nextAuditId'];
        }
        $this->snapshot = null;
        $this->rollbackCount++;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string)$this->lastInsertIdVal;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeInvitationStatement($this, $query);
    }

    public function executeStatement(string $sql, array $params): array
    {
        if ($this->failOnSqlSubstring !== null && str_contains($sql, $this->failOnSqlSubstring)) {
            throw new RuntimeException('Simulated DB write failure.');
        }

        if (stripos($sql, 'FOR UPDATE') !== false) {
            $this->forUpdateQueries[] = $sql;
        }

        // 1. Authorization query: System Administrator check
        if (str_contains($sql, "r.role_name = 'SYSTEM_ADMINISTRATOR'") && str_contains($sql, 'has_super_role')) {
            [$unitId, $actorUid] = [(int)$params[0], (int)$params[1]];
            $ua = $this->users[$actorUid] ?? null;
            $bu = $this->businessUnits[$unitId] ?? null;
            if (!$ua || !$bu) {
                return [];
            }
            $emp = null;
            foreach ($this->employees as $e) {
                if ((int)$e['user_account_id'] === $actorUid) {
                    $emp = $e;
                    break;
                }
            }
            if (!$emp) {
                return [];
            }

            $hasSysAdminInUnit = false;
            $hasSuperRole = false;
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] !== $actorUid) {
                    continue;
                }
                $rName = $this->roles[(int)$ar['role_id']] ?? '';
                if ($rName === 'SYSTEM_ADMINISTRATOR' && (int)$ar['business_unit_id'] === $unitId) {
                    $hasSysAdminInUnit = true;
                }
                if ($rName === 'SUPER_ADMINISTRATOR') {
                    $hasSuperRole = true;
                }
            }

            if (!$hasSysAdminInUnit) {
                return [];
            }

            return [[
                'user_account_id' => $actorUid,
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'employee_id' => $emp['employee_id'],
                'employee_status' => $emp['employee_status'],
                'business_unit_id' => $unitId,
                'business_unit_status' => $bu['business_unit_status'],
                'has_super_role' => $hasSuperRole ? 1 : 0,
            ]];
        }

        // 2. Lock target employee + user_account + business_unit
        if (str_contains($sql, 'FROM employee e') && str_contains($sql, 'WHERE e.employee_id = ?')) {
            $eid = (int)$params[0];
            $emp = $this->employees[$eid] ?? null;
            if (!$emp) {
                return [];
            }
            $ua = $this->users[(int)$emp['user_account_id']] ?? null;
            $bu = $this->businessUnits[(int)$emp['business_unit_id']] ?? null;
            if (!$ua || !$bu) {
                return [];
            }
            return [[
                'employee_id' => $emp['employee_id'],
                'user_account_id' => $emp['user_account_id'],
                'business_unit_id' => $emp['business_unit_id'],
                'first_name' => $emp['first_name'],
                'last_name' => $emp['last_name'],
                'position' => $emp['position'],
                'employee_status' => $emp['employee_status'],
                'email' => $ua['email'],
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'email_verified_at' => $ua['email_verified_at'],
                'business_unit_name' => $bu['business_unit_name'],
                'business_unit_status' => $bu['business_unit_status'],
            ]];
        }

        // 3. Lock pending invitations for employee
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, "status = 'pending'")) {
            $eid = (int)$params[0];
            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)$inv['employee_id'] === $eid && $inv['status'] === 'pending') {
                    $rows[] = [
                        'invitation_id' => $inv['invitation_id'],
                        'expires_at' => $inv['expires_at'],
                    ];
                }
            }
            usort($rows, fn($a, $b) => $a['invitation_id'] <=> $b['invitation_id']);
            return $rows;
        }

        // 4. INSERT INTO employee_invitation
        if (str_contains($sql, 'INSERT INTO employee_invitation')) {
            [$uid, $eid, $unitId, $email, $tokenHash, $expiresAt, $actorUid, $createdAt, $updatedAt] = $params;
            if (strlen((string)$tokenHash) !== 64) {
                throw new RuntimeException('CHECK constraint chk_emp_inv_token_hash_len failed.');
            }
            if ((string)$expiresAt <= (string)$createdAt) {
                throw new RuntimeException('CHECK constraint chk_emp_inv_expiry_window failed.');
            }
            foreach ($this->invitations as $existing) {
                if ($existing['token_hash'] === $tokenHash) {
                    throw new RuntimeException('UNIQUE constraint uk_emp_inv_token_hash failed.');
                }
            }
            $id = $this->nextInvitationId++;
            $this->invitations[$id] = [
                'invitation_id' => $id,
                'user_account_id' => (int)$uid,
                'employee_id' => (int)$eid,
                'business_unit_id' => (int)$unitId,
                'invited_email' => (string)$email,
                'token_hash' => (string)$tokenHash,
                'status' => 'pending',
                'expires_at' => (string)$expiresAt,
                'accepted_at' => null,
                'revoked_at' => null,
                'revoke_reason' => null,
                'email_submission_status' => 'pending',
                'email_submitted_at' => null,
                'email_failed_at' => null,
                'email_error' => null,
                'created_by_user_id' => (int)$actorUid,
                'created_at' => (string)$createdAt,
                'updated_at' => (string)$updatedAt,
            ];
            $this->lastInsertIdVal = $id;
            return [];
        }

        // 5. UPDATE employee_invitation -> revoked
        if (str_contains($sql, "SET status = 'revoked'")) {
            [$revokedAt, $reason, $updatedAt, $id] = $params;
            $id = (int)$id;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                $this->invitations[$id]['status'] = 'revoked';
                $this->invitations[$id]['revoked_at'] = (string)$revokedAt;
                $this->invitations[$id]['revoke_reason'] = (string)$reason;
                $this->invitations[$id]['updated_at'] = (string)$updatedAt;
            }
            return [];
        }

        // 6. UPDATE employee_invitation -> expired
        if (str_contains($sql, "SET status = 'expired'")) {
            [$updatedAt, $id] = $params;
            $id = (int)$id;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                $this->invitations[$id]['status'] = 'expired';
                $this->invitations[$id]['updated_at'] = (string)$updatedAt;
            }
            return [];
        }

        // 7. UPDATE employee_invitation -> email_submission_status = 'submitted'
        if (str_contains($sql, "SET email_submission_status = 'submitted'")) {
            [$submittedAt, $updatedAt, $id] = $params;
            $id = (int)$id;
            if (isset($this->invitations[$id])) {
                $this->invitations[$id]['email_submission_status'] = 'submitted';
                $this->invitations[$id]['email_submitted_at'] = (string)$submittedAt;
                $this->invitations[$id]['email_error'] = null;
                $this->invitations[$id]['updated_at'] = (string)$updatedAt;
            }
            return [];
        }

        // 8. UPDATE employee_invitation -> email_submission_status = 'failed'
        if (str_contains($sql, "SET email_submission_status = 'failed'")) {
            [$failedAt, $error, $updatedAt, $id] = $params;
            $id = (int)$id;
            if (isset($this->invitations[$id])) {
                $this->invitations[$id]['email_submission_status'] = 'failed';
                $this->invitations[$id]['email_failed_at'] = (string)$failedAt;
                $this->invitations[$id]['email_error'] = (string)$error;
                $this->invitations[$id]['updated_at'] = (string)$updatedAt;
            }
            return [];
        }

        // 9. INSERT INTO audit_log (via Audit::record)
        if (str_contains($sql, 'INSERT INTO audit_log')) {
            $id = $this->nextAuditId++;
            $this->auditLogs[] = [
                'log_id' => $id,
                'actor_user_id' => $params[0],
                'business_unit_id' => $params[1],
                'action_code' => $params[2],
                'target_type' => $params[3],
                'target_id' => $params[4],
                'affected_employee_id' => $params[5],
                'summary' => $params[6],
                'old_value' => $params[7],
                'new_value' => $params[8],
                'ip_address' => $params[9],
            ];
            $this->lastInsertIdVal = $id;
            return [];
        }

        throw new RuntimeException("Unexpected SQL in FakeInvitationPdo: {$sql}");
    }
}

final class FakeInvitationStatement extends PDOStatement
{
    private array $rows = [];
    private int $cursor = 0;

    public function __construct(private FakeInvitationPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $this->rows = $this->pdo->executeStatement($this->sql, $params ?? []);
        $this->cursor = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        return $this->rows[$this->cursor++];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

function makeFixturePdo(): FakeInvitationPdo
{
    $pdo = new FakeInvitationPdo();
    $pdo->businessUnits = [
        1 => ['business_unit_id' => 1, 'business_unit_name' => 'Pet Store', 'business_unit_status' => 'active'],
        2 => ['business_unit_id' => 2, 'business_unit_name' => 'Veterinary Clinic', 'business_unit_status' => 'active'],
    ];
    $pdo->roles = [
        1 => 'SUPER_ADMINISTRATOR',
        2 => 'SYSTEM_ADMINISTRATOR',
        3 => 'BUSINESS_MANAGER',
        4 => 'RECEPTIONIST',
    ];
    $pdo->users = [
        // 1: Super Administrator (even if also assigned SYSTEM_ADMINISTRATOR, must be denied)
        1 => ['user_account_id' => 1, 'email' => 'super@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 2: Pet Store System Administrator
        2 => ['user_account_id' => 2, 'email' => 'sysadmin.pet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 3: Vet Clinic System Administrator
        3 => ['user_account_id' => 3, 'email' => 'sysadmin.vet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 4: Pet Store Business Manager (non-SysAdmin)
        4 => ['user_account_id' => 4, 'email' => 'manager.pet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 5: Super Admin by role (is_super_admin=0 but holds SUPER_ADMINISTRATOR role)
        5 => ['user_account_id' => 5, 'email' => 'super.role@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 10: Pending Pet Store Employee
        10 => ['user_account_id' => 10, 'email' => 'carla.mendoza@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 11: Already-verified Pet Store Employee
        11 => ['user_account_id' => 11, 'email' => 'verified.emp@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-06-01 10:00:00'],
        // 12: Inactive Pending Pet Store Employee
        12 => ['user_account_id' => 12, 'email' => 'inactive.emp@pawcarino.test', 'account_status' => 'inactive', 'is_super_admin' => 0, 'email_verified_at' => null],
    ];
    $pdo->employees = [
        1 => ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Super', 'last_name' => 'Admin', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        2 => ['employee_id' => 2, 'user_account_id' => 2, 'business_unit_id' => 1, 'first_name' => 'Pet', 'last_name' => 'SysAdmin', 'position' => 'Pet Store System Administrator', 'employee_status' => 'active'],
        3 => ['employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 2, 'first_name' => 'Vet', 'last_name' => 'SysAdmin', 'position' => 'Veterinary Clinic System Administrator', 'employee_status' => 'active'],
        4 => ['employee_id' => 4, 'user_account_id' => 4, 'business_unit_id' => 1, 'first_name' => 'Pet', 'last_name' => 'Manager', 'position' => 'Business Manager — Pet Store', 'employee_status' => 'active'],
        5 => ['employee_id' => 5, 'user_account_id' => 5, 'business_unit_id' => 1, 'first_name' => 'Role', 'last_name' => 'Super', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        10 => ['employee_id' => 10, 'user_account_id' => 10, 'business_unit_id' => 1, 'first_name' => 'Carla', 'last_name' => 'Mendoza', 'position' => 'Receptionist', 'employee_status' => 'active'],
        11 => ['employee_id' => 11, 'user_account_id' => 11, 'business_unit_id' => 1, 'first_name' => 'Verified', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
        12 => ['employee_id' => 12, 'user_account_id' => 12, 'business_unit_id' => 1, 'first_name' => 'Inactive', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'inactive'],
    ];
    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 1, 'role_id' => 2, 'business_unit_id' => 1], // even if also sysadmin, super admin is blocked
        ['user_account_id' => 2, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 3, 'role_id' => 2, 'business_unit_id' => 2],
        ['user_account_id' => 4, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 5, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 5, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 10, 'role_id' => 4, 'business_unit_id' => 1],
    ];
    return $pdo;
}

$tempEnv = tempnam(sys_get_temp_dir(), 'pawcarino-inv-cp2-');
if ($tempEnv === false) {
    throw new RuntimeException('Could not create temporary env file.');
}

foreach (['APP_URL', 'MAIL_DRIVER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS'] as $k) {
    putenv($k);
    unset($_ENV[$k]);
}

function loadTempEnv(string $path, string $contents): void
{
    file_put_contents($path, $contents);
    EnvConfig::setEnvPathForTesting($path);
}

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

try {
    echo "=== EmployeeInvitationService Isolated Unit & FakePDO Tests (No Live MySQL/SMTP) ===\n\n";

    // --- 1. Token Generation, Hashing & Trusted APP_URL ---
    test('generateToken produces unique 64-char hex tokens and hashToken produces distinct SHA-256 digest', function () {
        $t1 = EmployeeInvitationService::generateToken();
        $t2 = EmployeeInvitationService::generateToken();
        assertTrue(EmployeeInvitationService::isValidRawTokenFormat($t1), 'Token 1 format invalid');
        assertTrue(EmployeeInvitationService::isValidRawTokenFormat($t2), 'Token 2 format invalid');
        assertTrue($t1 !== $t2, 'Tokens should not collide');

        $h1 = EmployeeInvitationService::hashToken($t1);
        assertTrue(strlen($h1) === 64 && $h1 === hash('sha256', $t1) && $h1 !== $t1, 'Invalid SHA-256 hash');
    });

    test('buildAcceptanceUrl strictly uses EnvConfig::appUrl and ignores $_SERVER[HTTP_HOST]', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test/\nMAIL_DRIVER=null\n");
        $_SERVER['HTTP_HOST'] = 'evil.attacker.invalid';
        $tok = EmployeeInvitationService::generateToken();
        $url = EmployeeInvitationService::buildAcceptanceUrl($tok);
        assertTrue($url === "https://portal.pawcarino.test/invitations/accept?token={$tok}", "Unexpected URL: {$url}");
        assertTrue(!str_contains($url, 'evil.attacker.invalid'), 'Must ignore HTTP_HOST');
    });

    // --- 2. Authorization Boundaries ---
    test('Super Administrator (is_super_admin=1 or SUPER_ADMINISTRATOR role) is denied invitation creation', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);

        $r1 = $svc->createInvitation(1, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $r2 = $svc->createInvitation(5, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue(!$r1['ok'] && $r1['status'] === 403, 'is_super_admin=1 must be denied 403');
        assertTrue(!$r2['ok'] && $r2['status'] === 403, 'SUPER_ADMINISTRATOR role must be denied 403');
        assertTrue(count($pdo->invitations) === 0, 'No invitations should be written on auth failure');
    });

    test('Cross-unit System Administrator and non-SysAdmin employees are denied (403)', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);

        $crossUnit = $svc->createInvitation(3, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $manager = $svc->createInvitation(4, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue(!$crossUnit['ok'] && $crossUnit['status'] === 403, 'Cross-unit SysAdmin must be denied');
        assertTrue(!$manager['ok'] && $manager['status'] === 403, 'Non-SysAdmin must be denied');
        assertTrue(count($pdo->invitations) === 0, 'No invitations should be written');
    });

    // --- 3. Cross-Table Ownership, Unit, Email & Eligibility Validation ---
    test('Rejects mismatched user_account_id, mismatched business_unit_id, and mismatched invited_email', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);

        $badAccount = $svc->createInvitation(2, 10, 11, 1, 'carla.mendoza@pawcarino.test');
        assertTrue(!$badAccount['ok'] && $badAccount['status'] === 422, 'Mismatched user_account_id must fail 422');

        $badUnit = $svc->createInvitation(3, 10, 10, 2, 'carla.mendoza@pawcarino.test');
        assertTrue(!$badUnit['ok'] && $badUnit['status'] === 403, 'Mismatched employee business_unit_id must fail 403');

        $badEmail = $svc->createInvitation(2, 10, 10, 1, 'other@pawcarino.test');
        assertTrue(!$badEmail['ok'] && $badEmail['status'] === 422, 'Mismatched email must fail 422');

        assertTrue(count($pdo->invitations) === 0, 'No invitation rows should exist');
    });

    test('Rejects already-verified employee and inactive employee accounts', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);

        $verified = $svc->createInvitation(2, 11, 11, 1, 'verified.emp@pawcarino.test');
        $inactive = $svc->createInvitation(2, 12, 12, 1, 'inactive.emp@pawcarino.test');
        assertTrue(!$verified['ok'] && $verified['status'] === 409, 'Verified account must be rejected 409');
        assertTrue(!$inactive['ok'] && $inactive['status'] === 409, 'Inactive account must be rejected 409');
    });

    // --- 4. Create Invitation, 48h UTC Expiry, Post-Commit SMTP & Audit Hygiene ---
    test('createInvitation commits hashed token + 48h UTC expiry, submits SMTP strictly post-commit, and hides raw token', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $fixedNow = strtotime('2026-11-20 12:00:00 UTC');
        $capturedMail = null;

        $svc = new EmployeeInvitationService(
            $pdo,
            function (string $email, string $name, string $unit, string $pos, string $url, int $hours) use ($pdo, &$capturedMail): array {
                assertTrue(!$pdo->inTransaction(), 'Mail transport MUST NOT be called inside an open DB transaction');
                $capturedMail = compact('email', 'name', 'unit', 'pos', 'url', 'hours');
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            },
            fn(): int => $fixedNow
        );

        $res = $svc->createInvitation(2, 10, 10, 1, '  CARLA.MENDOZA@PAWCARINO.TEST ');
        assertTrue($res['ok'] === true && $res['status'] === 201, 'Create should succeed');
        assertTrue(!array_key_exists('raw_token', $res), 'Public createInvitation must not leak raw_token');
        assertTrue($res['invitation_status'] === 'pending', 'Lifecycle status must be pending');
        assertTrue($res['email_submission_status'] === 'submitted' && $res['email_submitted'] === true, 'Email should be recorded as submitted');
        assertTrue($res['expires_at'] === '2026-11-22 12:00:00', "Expected +48h UTC expiry, got {$res['expires_at']}");
        assertTrue($pdo->commitCount === 1 && count($pdo->forUpdateQueries) >= 2, 'Expected commit and FOR UPDATE row locks');

        $inv = $pdo->invitations[$res['invitation_id']];
        assertTrue($capturedMail !== null && $capturedMail['hours'] === 48, 'Mail sender should receive 48h expiry');
        preg_match('/token=([0-9a-f]{64})$/', $capturedMail['url'], $m);
        assertTrue(isset($m[1]), 'Acceptance URL must contain 64-char hex token');
        $sentRawToken = $m[1];
        assertTrue($inv['token_hash'] === hash('sha256', $sentRawToken), 'Stored token_hash must match SHA-256 of sent token');
        assertTrue($inv['token_hash'] !== $sentRawToken, 'Raw token must never be stored');
        assertTrue($inv['email_submitted_at'] === '2026-11-20 12:00:00' && $inv['email_error'] === null, 'email_submitted_at should be set');

        // Verify audit log has invitation.created and zero secret leakage
        assertTrue(count($pdo->auditLogs) === 1 && $pdo->auditLogs[0]['action_code'] === 'invitation.created', 'Audit log missing');
        $auditText = $pdo->auditLogs[0]['summary'] . ' ' . (string)$pdo->auditLogs[0]['new_value'];
        assertTrue(!str_contains($auditText, $sentRawToken) && !str_contains($auditText, $inv['token_hash']), 'Audit must never contain token or hash');
    });

    test('createInvitation rejects duplicate active pending invitation with 409', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $fixedNow = strtotime('2026-11-20 12:00:00 UTC');
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200],
            fn(): int => $fixedNow
        );

        $first = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $dup = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue($first['ok'] === true, 'First invitation should succeed');
        assertTrue(!$dup['ok'] && $dup['status'] === 409, 'Duplicate active pending invitation must be rejected with 409');
        assertTrue(count($pdo->invitations) === 1, 'Only one invitation should exist');
    });

    test('createInvitation marks expired stale pending invitation as expired and issues fresh replacement', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200],
            function () use (&$now): int { return $now; }
        );

        $first = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $now += 48 * 3600 + 1; // 48 hours and 1 second later
        $second = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');

        assertTrue($second['ok'] === true, 'New invitation after expiry should succeed');
        assertTrue($pdo->invitations[$first['invitation_id']]['status'] === 'expired', 'Prior expired invitation must transition to expired');
        assertTrue($pdo->invitations[$second['invitation_id']]['status'] === 'pending', 'Replacement must be pending');
    });

    // --- 5. Email Submission Failure Independence & Null Driver ---
    test('Null mail driver keeps invitation pending and records email_submission_status=failed', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo); // uses real MailService::sendEmployeeInvitation

        $res = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue($res['ok'] === true && $res['invitation_status'] === 'pending', 'Invitation must remain pending');
        assertTrue($res['email_submitted'] === false && $res['email_submission_status'] === 'failed', 'Null driver must be recorded as failed');
        assertTrue($res['email_status_code'] === 503, 'Expected 503 for disabled null driver');

        $inv = $pdo->invitations[$res['invitation_id']];
        assertTrue($inv['status'] === 'pending', 'Lifecycle status stays pending');
        assertTrue($inv['email_submission_status'] === 'failed' && $inv['email_failed_at'] !== null && $inv['email_submitted_at'] === null, 'Failure timestamp must be recorded');
        assertTrue(str_contains(strtolower((string)$inv['email_error']), 'disabled'), 'Error must explain submission is disabled');
    });

    test('SMTP transport failure retains pending invitation, records sanitized failure, and scrubs leaked URLs/tokens', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService(
            $pdo,
            fn(string $e, string $n, string $u, string $p, string $url) => [
                'submitted' => false,
                'driver' => 'smtp',
                'error' => "SMTP failed for {$url}",
                'status_code' => 502,
            ]
        );

        $res = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue($res['ok'] === true && $res['invitation_status'] === 'pending', 'Invitation must remain pending for retry');
        assertTrue($res['email_submitted'] === false && $res['email_submission_status'] === 'failed' && $res['email_status_code'] === 502, 'SMTP failure must be recorded');
        assertTrue(!str_contains((string)$res['email_error'], 'https://') && !str_contains((string)$res['email_error'], 'token='), 'URLs/tokens must be scrubbed from email_error');
    });

    test('submitInvitationEmail refuses to execute inside an open DB transaction', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);
        $pdo->beginTransaction();
        $threw = false;
        try {
            $svc->submitInvitationEmail(100, EmployeeInvitationService::generateToken(), 'carla@pawcarino.test', 'Carla', 'Pet Store', 'Receptionist');
        } catch (RuntimeException $e) {
            $threw = true;
        } finally {
            $pdo->rollBack();
        }
        assertTrue($threw, 'submitInvitationEmail must throw if called inside a transaction');
    });

    // --- 6. Resend Invalidation, Revocation, In-Transaction Helper & Rollback ---
    test('resendInvitation invalidates previous pending invitation before inserting replacement and submits post-commit', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $sentUrls = [];
        $svc = new EmployeeInvitationService(
            $pdo,
            function (string $e, string $n, string $u, string $p, string $url) use ($pdo, &$sentUrls): array {
                assertTrue(!$pdo->inTransaction(), 'Resend email must run post-commit');
                $sentUrls[] = $url;
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            },
            function () use (&$now): int { return $now; }
        );

        $first = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $now += 3600; // +1 hour
        $resent = $svc->resendInvitation(2, 10, 1);

        assertTrue($resent['ok'] === true && $resent['revoked_count'] === 1, 'Resend should revoke 1 prior pending invitation');
        $oldInv = $pdo->invitations[$first['invitation_id']];
        $newInv = $pdo->invitations[$resent['invitation_id']];
        assertTrue($oldInv['status'] === 'revoked' && $oldInv['revoke_reason'] === 'resent' && $oldInv['revoked_at'] !== null, 'Old invitation must be revoked with reason resent');
        assertTrue($newInv['status'] === 'pending' && $newInv['token_hash'] !== $oldInv['token_hash'], 'New invitation must be pending with distinct token hash');
        assertTrue($newInv['expires_at'] === '2026-11-22 13:00:00', 'New invitation must have fresh 48h expiry');
        assertTrue(count($sentUrls) === 2 && $sentUrls[0] !== $sentUrls[1], 'Two distinct invitation URLs should be submitted');
    });

    test('revokeInvitation revokes pending invitation and rejects second revoke with 409', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]
        );

        $created = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $rev = $svc->revokeInvitation(2, 10, 1, 'position_cancelled');
        assertTrue($rev['ok'] === true && $rev['revoked_count'] === 1, 'Revoke should succeed');
        assertTrue($pdo->invitations[$created['invitation_id']]['status'] === 'revoked', 'Status must be revoked');
        assertTrue($pdo->invitations[$created['invitation_id']]['revoke_reason'] === 'position_cancelled', 'Reason must be recorded');

        $revAgain = $svc->revokeInvitation(2, 10, 1);
        assertTrue(!$revAgain['ok'] && $revAgain['status'] === 409, 'Second revoke must fail 409');
    });

    test('createInvitationInTransaction requires active tx and does not call mail sender', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $mailCalled = false;
        $svc = new EmployeeInvitationService($pdo, function () use (&$mailCalled) {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $noTx = $svc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue(!$noTx['ok'] && $noTx['status'] === 500, 'Must fail when no transaction is active');

        $pdo->beginTransaction();
        $inTx = $svc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue($inTx['ok'] === true && EmployeeInvitationService::isValidRawTokenFormat($inTx['raw_token']), 'In-transaction creation should return raw token for caller post-commit send');
        assertTrue(!$mailCalled && $pdo->inTransaction(), 'Must not call mail sender or commit caller transaction');
        $pdo->commit();
    });

    test('DB error during createInvitation rolls back cleanly without partial records or mail submission', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $pdo->failOnSqlSubstring = 'INSERT INTO audit_log';
        $mailCalled = false;
        $svc = new EmployeeInvitationService($pdo, function () use (&$mailCalled) {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $res = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue(!$res['ok'] && $res['status'] === 500, 'Should fail cleanly with 500');
        assertTrue($pdo->rollbackCount === 1 && !$pdo->inTransaction(), 'Transaction must roll back');
        assertTrue(count($pdo->invitations) === 0 && count($pdo->auditLogs) === 0, 'No partial rows may persist');
        assertTrue(!$mailCalled, 'Mail sender must not be invoked after rollback');
    });
} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempEnv);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
