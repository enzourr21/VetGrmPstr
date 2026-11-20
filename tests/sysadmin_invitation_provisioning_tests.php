<?php
declare(strict_types=1);

/**
 * Isolated Integration Tests for Checkpoint 3 — System Administrator Employee Invitation Provisioning.
 *
 * Uses a stateful in-memory FakeProvisioningPdo double (no live MySQL, no live SMTP, no HTTP server).
 * Verifies:
 *  - Successful System Administrator employee creation + atomic invitation persistence
 *  - Invitation failure rolling back user_account, employee, customer, account_role, and audit_log
 *  - Post-commit email submission (never inside an open DB transaction)
 *  - SMTP failure retaining pending account & invitation for retry
 *  - Unauthorized business-unit assignment rejection
 *  - Super Administrator employee & System Administrator provisioning remaining direct-password flows
 *  - Static boundary verification on SuperAdminController & SysAdminController
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Models/Units.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';
require_once dirname(__DIR__) . '/app/Models/AdminAccountService.php';

use App\Core\EnvConfig;
use App\Models\AdminAccountService;
use App\Services\EmployeeInvitationService;

final class FakeProvisioningPdo extends PDO
{
    public array $businessUnits = [];
    public array $positions = [];
    public array $roles = [];
    public array $users = [];
    public array $employees = [];
    public array $customers = [];
    public array $accountRoles = [];
    public array $invitations = [];
    public array $auditLogs = [];

    public int $nextUserId = 100;
    public int $nextEmployeeId = 100;
    public int $nextCustomerId = 100;
    public int $nextInvitationId = 500;
    public int $nextAuditId = 1;
    public int $lastInsertIdVal = 0;

    public bool $inTx = false;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    public ?string $failOnSqlSubstring = null;
    public array $advisoryLocks = [];
    public int $simulatedNowTs = 1795176000; // 2026-11-20 12:00:00 UTC

    private ?array $snapshot = null;

    public function __construct() {}

    public function beginTransaction(): bool
    {
        if ($this->inTx) {
            throw new RuntimeException('Transaction already active.');
        }
        $this->inTx = true;
        $this->snapshot = [
            'users' => $this->users,
            'employees' => $this->employees,
            'customers' => $this->customers,
            'accountRoles' => $this->accountRoles,
            'invitations' => $this->invitations,
            'auditLogs' => $this->auditLogs,
            'nextUserId' => $this->nextUserId,
            'nextEmployeeId' => $this->nextEmployeeId,
            'nextCustomerId' => $this->nextCustomerId,
            'nextInvitationId' => $this->nextInvitationId,
            'nextAuditId' => $this->nextAuditId,
        ];
        return true;
    }

    public function commit(): bool
    {
        $this->inTx = false;
        $this->snapshot = null;
        $this->commitCount++;
        return true;
    }

    public function rollBack(): bool
    {
        $this->inTx = false;
        if ($this->snapshot !== null) {
            $this->users = $this->snapshot['users'];
            $this->employees = $this->snapshot['employees'];
            $this->customers = $this->snapshot['customers'];
            $this->accountRoles = $this->snapshot['accountRoles'];
            $this->invitations = $this->snapshot['invitations'];
            $this->auditLogs = $this->snapshot['auditLogs'];
            $this->nextUserId = $this->snapshot['nextUserId'];
            $this->nextEmployeeId = $this->snapshot['nextEmployeeId'];
            $this->nextCustomerId = $this->snapshot['nextCustomerId'];
            $this->nextInvitationId = $this->snapshot['nextInvitationId'];
            $this->nextAuditId = $this->snapshot['nextAuditId'];
        }
        $this->snapshot = null;
        $this->rollbackCount++;
        return true;
    }

    public function inTransaction(): bool { return $this->inTx; }

    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastInsertIdVal; }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = new FakeProvisioningStatement($this, $query);
        $stmt->execute([]);
        return $stmt;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeProvisioningStatement($this, $query);
    }

    public function executeSql(string $sql, array $params, int &$rowCount): array
    {
        $rowCount = 0;

        if ($this->failOnSqlSubstring !== null && str_contains($sql, $this->failOnSqlSubstring)) {
            throw new RuntimeException('Simulated DB error on: ' . $this->failOnSqlSubstring);
        }

        if (str_contains($sql, 'SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())')) {
            return [[(string)$this->simulatedNowTs]];
        }
        if (str_contains($sql, 'SELECT GET_LOCK(')) {
            $name = (string)$params[0];
            $this->advisoryLocks[$name] = true;
            return [['1']];
        }
        if (str_contains($sql, 'SELECT RELEASE_LOCK(')) {
            unset($this->advisoryLocks[(string)$params[0]]);
            return [['1']];
        }

        // AdminAccountService lookups
        if (str_contains($sql, 'SELECT user_account_id FROM user_account WHERE email = ?')) {
            foreach ($this->users as $u) {
                if (strtolower($u['email']) === strtolower((string)$params[0])) {
                    return [[(string)$u['user_account_id']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'SELECT user_account_id FROM user_account WHERE username = ?')) {
            foreach ($this->users as $u) {
                if (($u['username'] ?? null) !== null && strtolower((string)$u['username']) === strtolower((string)$params[0])) {
                    return [[(string)$u['user_account_id']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM business_unit') && str_contains($sql, 'WHERE business_unit_id = ?')) {
            $bu = $this->businessUnits[(int)$params[0]] ?? null;
            if ($bu && (!str_contains($sql, 'active') || $bu['business_unit_status'] === 'active')) {
                return [$bu];
            }
            return [];
        }
        if (str_contains($sql, 'SELECT position_name FROM business_position')) {
            foreach ($this->positions as $p) {
                if ((int)$p['business_unit_id'] === (int)$params[0] && $p['position_name'] === (string)$params[1] && $p['position_status'] === 'active') {
                    return [[$p['position_name']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM role') && !str_contains($sql, 'account_role')) {
            $rows = [];
            foreach ($this->roles as $id => $r) {
                if (str_contains($sql, "<> 'SUPER_ADMINISTRATOR'") && $r['role_name'] === 'SUPER_ADMINISTRATOR') {
                    continue;
                }
                $rows[] = ['role_id' => $id, 'role_name' => $r['role_name'], 'description' => $r['description']];
            }
            return $rows;
        }

        // AdminAccountService inserts
        if (str_contains($sql, 'INSERT INTO user_account')) {
            $id = $this->nextUserId++;
            $this->users[$id] = [
                'user_account_id' => $id,
                'email' => (string)$params[0],
                'username' => $params[1],
                'password_hash' => (string)$params[2],
                'account_status' => 'active',
                'is_super_admin' => 0,
                'email_verified_at' => $params[3],
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'INSERT INTO employee (')) {
            $id = $this->nextEmployeeId++;
            $this->employees[$id] = [
                'employee_id' => $id,
                'user_account_id' => (int)$params[0],
                'business_unit_id' => (int)$params[1],
                'first_name' => (string)$params[2],
                'last_name' => (string)$params[3],
                'position' => (string)$params[4],
                'phone' => $params[5],
                'employee_status' => 'active',
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'INSERT INTO customer')) {
            $id = $this->nextCustomerId++;
            $this->customers[$id] = [
                'customer_id' => $id,
                'user_account_id' => (int)$params[0],
                'first_name' => (string)$params[1],
                'last_name' => (string)$params[2],
                'phone' => $params[3],
                'customer_status' => 'active',
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'INSERT INTO account_role')) {
            $this->accountRoles[] = [
                'user_account_id' => (int)$params[0],
                'role_id' => (int)$params[1],
                'business_unit_id' => (int)$params[2],
            ];
            $rowCount = 1;
            return [];
        }

        // EmployeeInvitationService queries
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
            $hasSys = false;
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] === $actorUid
                    && (int)$ar['business_unit_id'] === $unitId
                    && ($this->roles[(int)$ar['role_id']]['role_name'] ?? '') === 'SYSTEM_ADMINISTRATOR') {
                    $hasSys = true;
                }
            }
            if (!$hasSys) {
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
                'has_super_role' => $this->hasSuperRole($actorUid) ? 1 : 0,
            ]];
        }
        if (str_contains($sql, 'SELECT user_account_id') && str_contains($sql, 'FROM employee') && !str_contains($sql, 'FOR UPDATE')) {
            $emp = $this->employees[(int)$params[0]] ?? null;
            return $emp ? [[(string)$emp['user_account_id']]] : [];
        }
        if (str_contains($sql, 'FROM user_account ua') && str_contains($sql, 'FOR UPDATE')) {
            $ua = $this->users[(int)$params[0]] ?? null;
            if (!$ua) {
                return [];
            }
            return [[
                'user_account_id' => $ua['user_account_id'],
                'email' => $ua['email'],
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'email_verified_at' => $ua['email_verified_at'],
                'has_super_role' => $this->hasSuperRole((int)$ua['user_account_id']) ? 1 : 0,
            ]];
        }
        if (str_contains($sql, 'FROM employee e') && str_contains($sql, 'WHERE e.employee_id = ?') && str_contains($sql, 'FOR UPDATE')) {
            $emp = $this->employees[(int)$params[0]] ?? null;
            return $emp ? [$emp] : [];
        }
        if (str_contains($sql, 'FROM employee_invitation ei') && str_contains($sql, 'WHERE ei.invitation_id = ?')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            if (!$inv) {
                return [];
            }
            $emp = $this->employees[(int)$inv['employee_id']] ?? null;
            $ua = $this->users[(int)$inv['user_account_id']] ?? null;
            $bu = $this->businessUnits[(int)$inv['business_unit_id']] ?? null;
            if (!$emp || !$ua || !$bu) {
                return [];
            }
            return [[
                'invitation_id' => $inv['invitation_id'],
                'user_account_id' => $inv['user_account_id'],
                'employee_id' => $inv['employee_id'],
                'business_unit_id' => $inv['business_unit_id'],
                'invited_email' => $inv['invited_email'],
                'token_hash' => $inv['token_hash'],
                'status' => $inv['status'],
                'expires_at' => $inv['expires_at'],
                'accepted_at' => $inv['accepted_at'],
                'revoked_at' => $inv['revoked_at'],
                'email_submission_status' => $inv['email_submission_status'],
                'emp_user_account_id' => $emp['user_account_id'],
                'emp_business_unit_id' => $emp['business_unit_id'],
                'first_name' => $emp['first_name'],
                'last_name' => $emp['last_name'],
                'position' => $emp['position'],
                'employee_status' => $emp['employee_status'],
                'account_email' => $ua['email'],
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'email_verified_at' => $ua['email_verified_at'],
                'business_unit_name' => $bu['business_unit_name'],
                'business_unit_status' => $bu['business_unit_status'],
                'has_super_role' => $this->hasSuperRole((int)$ua['user_account_id']) ? 1 : 0,
            ]];
        }
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, "status = 'pending'")) {
            $eid = (int)$params[0];
            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)$inv['employee_id'] === $eid && $inv['status'] === 'pending') {
                    $rows[] = ['invitation_id' => $inv['invitation_id'], 'expires_at' => $inv['expires_at']];
                }
            }
            return $rows;
        }
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'ORDER BY invitation_id DESC')) {
            $eid = (int)$params[0];
            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)$inv['employee_id'] === $eid) {
                    $rows[] = ['invitation_id' => $inv['invitation_id'], 'status' => $inv['status'], 'expires_at' => $inv['expires_at']];
                }
            }
            usort($rows, fn($a, $b) => $b['invitation_id'] <=> $a['invitation_id']);
            return $rows;
        }
        if (str_contains($sql, 'INSERT INTO employee_invitation')) {
            $id = $this->nextInvitationId++;
            $this->invitations[$id] = [
                'invitation_id' => $id,
                'user_account_id' => (int)$params[0],
                'employee_id' => (int)$params[1],
                'business_unit_id' => (int)$params[2],
                'invited_email' => (string)$params[3],
                'token_hash' => (string)$params[4],
                'status' => 'pending',
                'expires_at' => (string)$params[5],
                'accepted_at' => null,
                'revoked_at' => null,
                'revoke_reason' => null,
                'email_submission_status' => 'pending',
                'email_submitted_at' => null,
                'email_failed_at' => null,
                'email_error' => null,
                'created_by_user_id' => (int)$params[6],
                'created_at' => (string)$params[7],
                'updated_at' => (string)$params[8],
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, "SET status = 'revoked'")) {
            $id = (int)$params[3];
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                $this->invitations[$id]['status'] = 'revoked';
                $this->invitations[$id]['revoked_at'] = (string)$params[0];
                $this->invitations[$id]['revoke_reason'] = (string)$params[1];
                $this->invitations[$id]['updated_at'] = (string)$params[2];
                $rowCount = 1;
            }
            return [];
        }
        if (str_contains($sql, "SET email_submission_status = 'submitted'")) {
            $id = (int)$params[2];
            $hash = $params[3] ?? null;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending'
                && ($hash === null || hash_equals($this->invitations[$id]['token_hash'], (string)$hash))) {
                $this->invitations[$id]['email_submission_status'] = 'submitted';
                $this->invitations[$id]['email_submitted_at'] = (string)$params[0];
                $this->invitations[$id]['email_failed_at'] = null;
                $this->invitations[$id]['email_error'] = null;
                $this->invitations[$id]['updated_at'] = (string)$params[1];
                $rowCount = 1;
            }
            return [];
        }
        if (str_contains($sql, "SET email_submission_status = 'failed'")) {
            $id = (int)$params[3];
            $hash = $params[4] ?? null;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending'
                && ($hash === null || hash_equals($this->invitations[$id]['token_hash'], (string)$hash))) {
                $this->invitations[$id]['email_submission_status'] = 'failed';
                $this->invitations[$id]['email_failed_at'] = (string)$params[0];
                $this->invitations[$id]['email_submitted_at'] = null;
                $this->invitations[$id]['email_error'] = (string)$params[1];
                $this->invitations[$id]['updated_at'] = (string)$params[2];
                $rowCount = 1;
            }
            return [];
        }
        if (str_contains($sql, 'INSERT INTO audit_log')) {
            $id = $this->nextAuditId++;
            $this->auditLogs[] = ['log_id' => $id, 'action_code' => $params[2], 'summary' => $params[6] ?? ''];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }

        throw new RuntimeException("Unexpected SQL in FakeProvisioningPdo: {$sql}");
    }

    private function hasSuperRole(int $uid): bool
    {
        foreach ($this->accountRoles as $ar) {
            if ((int)$ar['user_account_id'] === $uid
                && ($this->roles[(int)$ar['role_id']]['role_name'] ?? '') === 'SUPER_ADMINISTRATOR') {
                return true;
            }
        }
        return false;
    }
}

final class FakeProvisioningStatement extends PDOStatement
{
    private array $rows = [];
    private int $cursor = 0;
    private int $affected = 0;

    public function __construct(private FakeProvisioningPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $affected = 0;
        $this->rows = $this->pdo->executeSql($this->sql, $params ?? [], $affected);
        $this->affected = $affected;
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

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        if ($row === false) {
            return false;
        }
        $vals = array_values($row);
        return $vals[$column] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }

    public function rowCount(): int { return $this->affected; }
}

function makeProvisioningFixture(): FakeProvisioningPdo
{
    $pdo = new FakeProvisioningPdo();
    $pdo->businessUnits = [
        1 => ['business_unit_id' => 1, 'business_unit_name' => 'Pet Store', 'business_unit_status' => 'active'],
        2 => ['business_unit_id' => 2, 'business_unit_name' => 'Veterinary Clinic', 'business_unit_status' => 'active'],
    ];
    $pdo->positions = [
        ['business_unit_id' => 1, 'position_name' => 'Receptionist', 'position_status' => 'active'],
        ['business_unit_id' => 1, 'position_name' => 'Inventory Manager', 'position_status' => 'active'],
        ['business_unit_id' => 2, 'position_name' => 'Veterinary Receptionist', 'position_status' => 'active'],
    ];
    $pdo->roles = [
        1 => ['role_name' => 'SUPER_ADMINISTRATOR', 'description' => 'Super Admin'],
        2 => ['role_name' => 'SYSTEM_ADMINISTRATOR', 'description' => 'Sys Admin'],
        3 => ['role_name' => 'RECEPTIONIST', 'description' => 'Receptionist'],
        4 => ['role_name' => 'INVENTORY_MANAGER', 'description' => 'Inventory Manager'],
    ];
    $pdo->users = [
        1 => ['user_account_id' => 1, 'email' => 'super@pawcarino.test', 'username' => 'superadmin', 'password_hash' => 'x', 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        2 => ['user_account_id' => 2, 'email' => 'sysadmin.pet@pawcarino.test', 'username' => 'sysadmin_pet', 'password_hash' => 'x', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        3 => ['user_account_id' => 3, 'email' => 'sysadmin.vet@pawcarino.test', 'username' => 'sysadmin_vet', 'password_hash' => 'x', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
    ];
    $pdo->employees = [
        1 => ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Evelyn', 'last_name' => 'Sterling', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        2 => ['employee_id' => 2, 'user_account_id' => 2, 'business_unit_id' => 1, 'first_name' => 'Adrian', 'last_name' => 'Mercer', 'position' => 'System Administrator', 'employee_status' => 'active'],
        3 => ['employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 2, 'first_name' => 'Beatrice', 'last_name' => 'Solis', 'position' => 'System Administrator', 'employee_status' => 'active'],
    ];
    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 2, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 3, 'role_id' => 2, 'business_unit_id' => 2],
    ];
    return $pdo;
}

$tempEnv = tempnam(sys_get_temp_dir(), 'pawcarino-cp3-');
if ($tempEnv === false) {
    throw new RuntimeException('Could not create temporary env file.');
}
foreach (['APP_URL', 'MAIL_DRIVER'] as $k) {
    putenv($k);
    unset($_ENV[$k]);
}
file_put_contents($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
EnvConfig::setEnvPathForTesting($tempEnv);

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
    echo "=== Checkpoint 3: System Admin Invitation Provisioning Tests (Isolated FakePDO) ===\n\n";

    test('SysAdmin creates pending employee + invitation atomically and submits email strictly after commit', function () {
        $pdo = makeProvisioningFixture();
        $mailCalledAfterCommit = false;
        $capturedUrl = '';

        $invSvc = new EmployeeInvitationService(
            $pdo,
            function (string $email, string $name, string $unit, string $pos, string $url) use ($pdo, &$mailCalledAfterCommit, &$capturedUrl): array {
                assertTrue(!$pdo->inTransaction(), 'Email submission MUST occur after transaction commit');
                assertTrue($pdo->commitCount === 1, 'Expected commit before email submission');
                $mailCalledAfterCommit = true;
                $capturedUrl = $url;
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            }
        );

        $res = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Carla Mendoza',
            'email' => 'carla.mendoza@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);

        assertTrue($res['ok'] === true && $res['is_invitation'] === true, 'Provisioning should succeed');
        assertTrue(!array_key_exists('invitation_token', $res) && !array_key_exists('raw_token', $res), 'Raw token must never be returned');
        assertTrue($mailCalledAfterCommit, 'Post-commit mail submission should run');
        assertTrue($res['email_submitted'] === true && $res['email_submission_status'] === 'submitted', 'Email status should be submitted');

        $ua = $pdo->users[$res['user_id']];
        $emp = $pdo->employees[$res['employee_id']];
        $inv = $pdo->invitations[$res['invitation_id']];
        assertTrue($ua['email_verified_at'] === null && $ua['account_status'] === 'active', 'Pending semantic must be email_verified_at=NULL & active');
        assertTrue($emp['business_unit_id'] === 1 && $emp['position'] === 'Receptionist', 'Employee unit/position must match');
        assertTrue($inv['status'] === 'pending' && $inv['email_submission_status'] === 'submitted', 'Invitation row must be pending + submitted');
        assertTrue(strlen($inv['token_hash']) === 64 && !str_contains($capturedUrl, $inv['token_hash']), 'Stored hash must differ from raw URL token');

        $codes = array_column($pdo->auditLogs, 'action_code');
        assertTrue(in_array('employee.created', $codes, true) && in_array('invitation.created', $codes, true), 'Both employee.created and invitation.created audits expected');
    });

    test('Invitation creation failure rolls back user_account, employee, customer, roles, and audit_log', function () {
        // Case A: DB failure on INSERT INTO employee_invitation
        $pdo = makeProvisioningFixture();
        $pdo->failOnSqlSubstring = 'INSERT INTO employee_invitation';
        $mailCalled = false;
        $invSvc = new EmployeeInvitationService($pdo, function () use (&$mailCalled) {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $beforeUsers = count($pdo->users);
        $beforeEmps = count($pdo->employees);
        $res = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Rollback Test',
            'email' => 'rollback@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);

        assertTrue($res['ok'] === false && $pdo->rollbackCount === 1 && !$pdo->inTransaction(), 'Must roll back cleanly');
        assertTrue(count($pdo->users) === $beforeUsers && count($pdo->employees) === $beforeEmps, 'No user or employee may persist');
        assertTrue(count($pdo->customers) === 0 && count($pdo->invitations) === 0 && count($pdo->auditLogs) === 0, 'No customer, invitation, or audit rows may persist');
        assertTrue(!$mailCalled, 'Mail must never be sent after rollback');

        // Case B: Super Admin actor attempting invitation creation is rejected 403 and rolled back
        $pdo2 = makeProvisioningFixture();
        $invSvc2 = new EmployeeInvitationService($pdo2);
        $resSuper = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Super Attempt',
            'email' => 'super.attempt@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 1, $pdo2, $invSvc2);
        assertTrue($resSuper['ok'] === false && ($resSuper['status'] ?? 0) === 403 && $pdo2->rollbackCount === 1, 'Super Admin actor must be rejected 403 and rolled back');
        assertTrue(count($pdo2->users) === 3 && count($pdo2->invitations) === 0, 'Zero partial records on 403 rollback');
    });

    test('SMTP failure preserves committed pending account and invitation and allows retry via resendInvitation', function () {
        $pdo = makeProvisioningFixture();
        $attempt = 0;
        $invSvc = new EmployeeInvitationService($pdo, function () use (&$attempt): array {
            $attempt++;
            if ($attempt === 1) {
                return ['submitted' => false, 'driver' => 'smtp', 'error' => 'SMTP 502 temporary error', 'status_code' => 502];
            }
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $res = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Retry Employee',
            'email' => 'retry.emp@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);

        assertTrue($res['ok'] === true && $res['email_submitted'] === false && $res['email_submission_status'] === 'failed', 'Account must commit while email_submission_status=failed');
        assertTrue(isset($pdo->users[$res['user_id']]) && isset($pdo->employees[$res['employee_id']]), 'Account and employee must remain persisted');
        assertTrue($pdo->invitations[$res['invitation_id']]['status'] === 'pending', 'Invitation lifecycle must remain pending');

        // Retry via resendInvitation succeeds
        $retry = $invSvc->resendInvitation(2, $res['employee_id'], 1);
        assertTrue($retry['ok'] === true && $retry['email_submitted'] === true && $retry['email_submission_status'] === 'submitted', 'Retry via resendInvitation must succeed');
        assertTrue($pdo->invitations[$res['invitation_id']]['status'] === 'revoked', 'Old invitation must be revoked on resend');
    });

    test('Unauthorized business-unit assignment by System Administrator is rejected without writes or email', function () {
        $pdo = makeProvisioningFixture();
        $mailCalled = false;
        $invSvc = new EmployeeInvitationService($pdo, function () use (&$mailCalled) {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        // Pet Store SysAdmin (enforcedUnitId=1) attempts to create employee in Vet Clinic (business_unit_id=2)
        $res = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Cross Unit',
            'email' => 'cross.unit@pawcarino.test',
            'business_unit_id' => 2,
            'position' => 'Veterinary Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);

        assertTrue($res['ok'] === false && isset($res['errors']['business_unit_id']), 'Cross-unit creation must fail validation');
        assertTrue(count($pdo->users) === 3 && count($pdo->invitations) === 0 && !$mailCalled, 'No records or email on unauthorized unit');
    });

    test('Super Administrator employee & System Administrator provisioning remain direct-password without invitations or email', function () {
        $pdo = makeProvisioningFixture();
        $mailCalled = false;
        $invSvc = new EmployeeInvitationService($pdo, function () use (&$mailCalled) {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        // 1. Super Admin direct employee creation (enforcedUnitId=null)
        $empRes = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Direct Staff',
            'email' => 'direct.staff@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'password' => 'StrongPass!2026',
        ], null, 1, $pdo, $invSvc);

        assertTrue($empRes['ok'] === true && $empRes['is_invitation'] === false, 'Super Admin employee creation must be direct-password');
        assertTrue(password_verify('StrongPass!2026', $pdo->users[$empRes['user_id']]['password_hash']), 'Supplied password must be hashed');
        assertTrue($pdo->users[$empRes['user_id']]['email_verified_at'] !== null, 'Direct creation must set email_verified_at');
        assertTrue(count($pdo->invitations) === 0 && !$mailCalled, 'Super Admin must never create invitations or send email');

        // 2. Super Admin System Administrator creation (enforcedUnitId=null, is_system_admin_provisioning=true)
        $saRes = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Unit SysAdmin',
            'email' => 'new.sysadmin@pawcarino.test',
            'business_unit_id' => 2,
            'roles' => ['SYSTEM_ADMINISTRATOR'],
            'is_system_admin_provisioning' => true,
            'password' => 'UnitAdmin!2026',
        ], null, 1, $pdo, $invSvc);

        assertTrue($saRes['ok'] === true && $saRes['is_invitation'] === false, 'Super Admin sysadmin creation must be direct-password');
        assertTrue($pdo->employees[$saRes['employee_id']]['position'] === 'Veterinary Clinic System Administrator', 'SysAdmin position must be authoritatively derived');
        assertTrue(count($pdo->invitations) === 0 && !$mailCalled, 'Super Admin sysadmin creation must never create invitations or send email');

        // 3. Super Admin omitting password or passing is_invitation=true must still require password and never invite
        $spoofRes = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Spoof Invite',
            'email' => 'spoof.invite@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], null, 1, $pdo, $invSvc);

        assertTrue($spoofRes['ok'] === false && isset($spoofRes['errors']['password']), 'Super Admin call (enforcedUnitId=null) must require password even if is_invitation=true');
        assertTrue(count($pdo->invitations) === 0 && !$mailCalled, 'No invitations or emails from Super Admin path');
    });

    test('Static boundary check: SuperAdminController has no invitation wiring and SysAdminController enforces SysAdmin-only boundary', function () {
        $superSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SuperAdminController.php');
        $sysSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');

        assertTrue(!str_contains($superSrc, 'EmployeeInvitationService') && !str_contains($superSrc, 'is_invitation') && !str_contains($superSrc, 'MailService'), 'SuperAdminController must not wire invitations');
        assertTrue(str_contains($sysSrc, "if (\$isSuper)") && str_contains($sysSrc, "'is_invitation' => true") && str_contains($sysSrc, "email_submitted"), 'SysAdminController::storeEmployee must block SuperAdmin and inspect email_submitted');
    });
} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempEnv);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
