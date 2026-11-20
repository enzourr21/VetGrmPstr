<?php
declare(strict_types=1);

/**
 * Isolated Security & Regression Tests for Checkpoints 4 & 4.1:
 *   Employee Invitation Acceptance, Authentication Guards, and Guard Consistency.
 *
 * IMPORTANT VERIFICATION LIMITATION:
 *  - Uses an in-memory FakeAcceptancePdo double (no live MySQL 8.4, no HTTP server, no SMTP).
 *  - Concurrent acceptance is tested via simulated single-use consumption / rowCount guard in
 *    memory and DOES NOT replace real multi-connection MySQL 8.4 InnoDB FOR UPDATE testing.
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Core/Core.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Models/Units.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';
require_once dirname(__DIR__) . '/app/Services/InterfaceSwitcherService.php';

use App\Core\Controller;
use App\Core\Database;
use App\Services\EmployeeInvitationService;
use App\Services\InterfaceSwitcherService;

final class FakeAcceptancePdo extends PDO
{
    public array $businessUnits = [];
    public array $roles = [];
    public array $users = [];
    public array $employees = [];
    public array $accountRoles = [];
    public array $invitations = [];
    public array $auditLogs = [];

    public int $nextAuditId = 1;
    public int $lastInsertIdVal = 0;
    public bool $inTx = false;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    public array $forUpdateQueries = [];
    public ?string $failOnSqlSubstring = null;
    /** @var callable|null */
    public $onBeforeConsumeUpdate = null;

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
            'accountRoles' => $this->accountRoles,
            'invitations' => $this->invitations,
            'auditLogs' => $this->auditLogs,
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
            $this->accountRoles = $this->snapshot['accountRoles'];
            $this->invitations = $this->snapshot['invitations'];
            $this->auditLogs = $this->snapshot['auditLogs'];
            $this->nextAuditId = $this->snapshot['nextAuditId'];
        }
        $this->snapshot = null;
        $this->rollbackCount++;
        return true;
    }

    public function inTransaction(): bool { return $this->inTx; }

    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastInsertIdVal; }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeAcceptanceStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = new FakeAcceptanceStatement($this, $query);
        $stmt->execute([]);
        return $stmt;
    }

    public function executeSql(string $sql, array $params, int &$rowCount): array
    {
        $rowCount = 0;

        if ($this->failOnSqlSubstring !== null && str_contains($sql, $this->failOnSqlSubstring)) {
            throw new RuntimeException('Simulated DB error on: ' . $this->failOnSqlSubstring);
        }

        if (stripos($sql, 'FOR UPDATE') !== false) {
            $this->forUpdateQueries[] = $sql;
        }

        // 1. Auth guard: isAccountBlockedByUnacceptedInvitation
        if (str_contains($sql, 'AS has_any_invitation') && str_contains($sql, 'AS has_accepted_invitation') && !str_contains($sql, 'FROM employee e')) {
            $uid = (int)$params[0];
            $hasAny = 0;
            $hasAcc = 0;
            foreach ($this->invitations as $inv) {
                if ((int)$inv['user_account_id'] === $uid) {
                    $hasAny = 1;
                    if ($inv['status'] === 'accepted') {
                        $hasAcc = 1;
                    }
                }
            }
            return [['has_any_invitation' => $hasAny, 'has_accepted_invitation' => $hasAcc]];
        }

        // 2. validateTokenForAcceptance read query
        if (str_contains($sql, 'FROM employee_invitation ei') && str_contains($sql, 'WHERE ei.token_hash = ?')) {
            $hash = (string)$params[0];
            foreach ($this->invitations as $inv) {
                if ($inv['token_hash'] !== $hash) {
                    continue;
                }
                $emp = $this->employees[(int)$inv['employee_id']] ?? null;
                $ua = $this->users[(int)$inv['user_account_id']] ?? null;
                $bu = $this->businessUnits[(int)$inv['business_unit_id']] ?? null;
                if (!$emp || !$ua || !$bu) {
                    return [];
                }
                $hasAcc = 0;
                foreach ($this->invitations as $other) {
                    if ((int)$other['employee_id'] === (int)$emp['employee_id'] && $other['status'] === 'accepted') {
                        $hasAcc = 1;
                        break;
                    }
                }
                $roleNames = [];
                foreach ($this->accountRoles as $ar) {
                    if ((int)$ar['user_account_id'] === (int)$ua['user_account_id'] && (int)$ar['business_unit_id'] === (int)$inv['business_unit_id']) {
                        $roleNames[] = $this->roles[(int)$ar['role_id']] ?? '';
                    }
                }
                sort($roleNames);
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
                    'has_accepted_invitation' => $hasAcc,
                    'unit_roles' => implode(', ', $roleNames),
                ]];
            }
            return [];
        }

        // 3. Non-locking discovery by token_hash in acceptInvitation
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE token_hash = ?')) {
            $hash = (string)$params[0];
            foreach ($this->invitations as $inv) {
                if ($inv['token_hash'] === $hash) {
                    return [[
                        'invitation_id' => $inv['invitation_id'],
                        'user_account_id' => $inv['user_account_id'],
                        'employee_id' => $inv['employee_id'],
                        'business_unit_id' => $inv['business_unit_id'],
                    ]];
                }
            }
            return [];
        }

        // 4. Canonical locks: user_account -> employee -> employee_invitation
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
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE employee_id = ?') && str_contains($sql, 'FOR UPDATE')) {
            $eid = (int)$params[0];
            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)$inv['employee_id'] === $eid) {
                    $rows[] = $inv;
                }
            }
            usort($rows, fn($a, $b) => (int)$b['invitation_id'] <=> (int)$a['invitation_id']);
            return $rows;
        }
        if (str_contains($sql, 'FROM business_unit') && str_contains($sql, 'WHERE business_unit_id = ?')) {
            $bu = $this->businessUnits[(int)$params[0]] ?? null;
            return $bu ? [$bu] : [];
        }

        // 5. UPDATE employee_invitation -> expired
        if (str_contains($sql, "SET status = 'expired'")) {
            $now = (string)$params[0];
            $id = (int)$params[1];
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                $this->invitations[$id]['status'] = 'expired';
                $this->invitations[$id]['updated_at'] = $now;
                $rowCount = 1;
            }
            return [];
        }

        // 6. UPDATE employee_invitation -> accepted
        if (str_contains($sql, "SET status = 'accepted'")) {
            if ($this->onBeforeConsumeUpdate !== null) {
                ($this->onBeforeConsumeUpdate)($this);
            }
            [$acceptedAt, $updatedAt, $id, $hash] = [$params[0], $params[1], (int)$params[2], (string)$params[3]];
            if (
                isset($this->invitations[$id])
                && $this->invitations[$id]['status'] === 'pending'
                && hash_equals($this->invitations[$id]['token_hash'], $hash)
            ) {
                $this->invitations[$id]['status'] = 'accepted';
                $this->invitations[$id]['accepted_at'] = (string)$acceptedAt;
                $this->invitations[$id]['updated_at'] = (string)$updatedAt;
                $rowCount = 1;
            }
            return [];
        }

        // 7. UPDATE user_account password & email_verified_at
        if (str_contains($sql, 'UPDATE user_account') && str_contains($sql, 'SET password_hash = ?')) {
            [$pwHash, $verifiedAt, $uid] = [(string)$params[0], (string)$params[1], (int)$params[2]];
            if (isset($this->users[$uid])) {
                $this->users[$uid]['password_hash'] = $pwHash;
                $this->users[$uid]['email_verified_at'] = $verifiedAt;
                $this->users[$uid]['account_status'] = 'active';
                $rowCount = 1;
            }
            return [];
        }

        // 8. INSERT INTO audit_log
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
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }

        // 9. InterfaceSwitcherService::loadContext account/employee lookup
        if (str_contains($sql, 'FROM user_account ua') && str_contains($sql, 'LEFT JOIN employee e') && str_contains($sql, 'WHERE ua.user_account_id = ?')) {
            $uid = (int)$params[0];
            $ua = $this->users[$uid] ?? null;
            if (!$ua) {
                return [];
            }
            $emp = null;
            foreach ($this->employees as $e) {
                if ((int)$e['user_account_id'] === $uid) {
                    $emp = $e;
                    break;
                }
            }
            $bu = $emp ? ($this->businessUnits[(int)$emp['business_unit_id']] ?? null) : null;
            return [[
                'user_account_id' => $ua['user_account_id'],
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'employee_id' => $emp['employee_id'] ?? null,
                'first_name' => $emp['first_name'] ?? null,
                'last_name' => $emp['last_name'] ?? null,
                'position' => $emp['position'] ?? null,
                'employee_status' => $emp['employee_status'] ?? null,
                'emp_unit_id' => $emp['business_unit_id'] ?? null,
                'emp_unit_name' => $bu['business_unit_name'] ?? null,
                'emp_unit_status' => $bu['business_unit_status'] ?? null,
            ]];
        }

        // 10. Core::isSuperAdmin() & InterfaceSwitcherService::hasSuperAdminGrant()
        if (str_contains($sql, 'FROM user_account ua') && str_contains($sql, "ua.is_super_admin = 1 OR r.role_name = 'SUPER_ADMINISTRATOR'")) {
            $uid = (int)$params[0];
            $ua = $this->users[$uid] ?? null;
            if (!$ua || $ua['account_status'] !== 'active') {
                return [];
            }
            if ((int)$ua['is_super_admin'] === 1 || $this->hasSuperRole($uid)) {
                return [['1' => 1]];
            }
            return [];
        }

        // 11. Active business units list (InterfaceSwitcherService & Units::all)
        if (str_contains($sql, 'FROM business_unit') && (str_contains($sql, "business_unit_status = 'active'") || str_contains($sql, 'business_unit_status="active"')) && !str_contains($sql, 'business_unit_id=?') && !str_contains($sql, 'business_unit_name=?')) {
            $rows = [];
            foreach ($this->businessUnits as $bu) {
                if ($bu['business_unit_status'] === 'active') {
                    $rows[] = $bu;
                }
            }
            return $rows;
        }

        // 12. Units::find() & Units::idByName()
        if (str_contains($sql, 'FROM business_unit') && str_contains($sql, 'business_unit_id=?')) {
            $bu = $this->businessUnits[(int)$params[0]] ?? null;
            return ($bu && $bu['business_unit_status'] === 'active') ? [$bu] : [];
        }
        if (str_contains($sql, 'FROM business_unit') && str_contains($sql, 'business_unit_name=?')) {
            foreach ($this->businessUnits as $bu) {
                if ($bu['business_unit_name'] === (string)$params[0] && $bu['business_unit_status'] === 'active') {
                    return [['business_unit_id' => $bu['business_unit_id']]];
                }
            }
            return [];
        }

        // 13. InterfaceSwitcherService role list
        if (str_contains($sql, 'SELECT ar.business_unit_id, bu.business_unit_name, r.role_name')) {
            $uid = (int)$params[0];
            $rows = [];
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] !== $uid) {
                    continue;
                }
                $bu = $this->businessUnits[(int)$ar['business_unit_id']] ?? null;
                if (!$bu || $bu['business_unit_status'] !== 'active') {
                    continue;
                }
                $rows[] = [
                    'business_unit_id' => (int)$bu['business_unit_id'],
                    'business_unit_name' => $bu['business_unit_name'],
                    'role_name' => $this->roles[(int)$ar['role_id']] ?? '',
                ];
            }
            return $rows;
        }

        // 14. Core::requireSystemAdmin() / Core::requireBusinessManager() role lookup
        if (str_contains($sql, 'SELECT e.employee_id, bu.business_unit_id, bu.business_unit_name') && str_contains($sql, 'FROM user_account ua')) {
            $uid = (int)$params[0];
            $wantedRole = str_contains($sql, "'SYSTEM_ADMINISTRATOR'") ? 'SYSTEM_ADMINISTRATOR' : 'BUSINESS_MANAGER';
            $ua = $this->users[$uid] ?? null;
            if (!$ua || $ua['account_status'] !== 'active') {
                return [];
            }
            $emp = null;
            foreach ($this->employees as $e) {
                if ((int)$e['user_account_id'] === $uid && $e['employee_status'] === 'active') {
                    $emp = $e;
                    break;
                }
            }
            if (!$emp) {
                return [];
            }
            $rows = [];
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] !== $uid) {
                    continue;
                }
                if (($this->roles[(int)$ar['role_id']] ?? '') !== $wantedRole) {
                    continue;
                }
                $bu = $this->businessUnits[(int)$ar['business_unit_id']] ?? null;
                if (!$bu || $bu['business_unit_status'] !== 'active') {
                    continue;
                }
                $rows[] = [
                    'employee_id' => (int)$emp['employee_id'],
                    'business_unit_id' => (int)$bu['business_unit_id'],
                    'business_unit_name' => $bu['business_unit_name'],
                ];
            }
            return $rows;
        }

        // 15. Core::requireStaff() permission lookup
        if (str_contains($sql, 'JOIN permission p') && str_contains($sql, 'p.permission_code=?')) {
            [$unitId, $permCode, $uid, $eid] = [(int)$params[0], (string)$params[1], (int)$params[2], (int)$params[3]];
            $ua = $this->users[$uid] ?? null;
            $emp = $this->employees[$eid] ?? null;
            if (!$ua || $ua['account_status'] !== 'active' || !$emp || (int)$emp['user_account_id'] !== $uid || $emp['employee_status'] !== 'active') {
                return [];
            }
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] === $uid && (int)$ar['business_unit_id'] === $unitId) {
                    $rName = $this->roles[(int)$ar['role_id']] ?? '';
                    if ($permCode === 'appointment.view' && in_array($rName, ['RECEPTIONIST', 'BUSINESS_MANAGER', 'INVENTORY_MANAGER'], true)) {
                        return [['1' => 1]];
                    }
                }
            }
            return [];
        }

        throw new RuntimeException("Unexpected SQL in FakeAcceptancePdo: {$sql}");
    }

    private function hasSuperRole(int $uid): bool
    {
        foreach ($this->accountRoles as $ar) {
            if ((int)$ar['user_account_id'] === $uid
                && ($this->roles[(int)$ar['role_id']] ?? '') === 'SUPER_ADMINISTRATOR') {
                return true;
            }
        }
        return false;
    }
}

final class FakeAcceptanceStatement extends PDOStatement
{
    private array $rows = [];
    private int $cursor = 0;
    private int $affected = 0;

    public function __construct(private FakeAcceptancePdo $pdo, private string $sql) {}

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

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        if ($row === false) {
            return false;
        }
        $vals = array_values($row);
        return $vals[$column] ?? false;
    }

    public function rowCount(): int { return $this->affected; }
}

final class GuardForbiddenException extends RuntimeException {}
final class GuardRedirectException extends RuntimeException {}

final class GuardHarnessController extends Controller
{
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    protected function forbidden(string $message = 'You do not have access to this page.'): never
    {
        throw new GuardForbiddenException($message, 403);
    }

    protected function redirect(string $url): never
    {
        throw new GuardRedirectException($url, 302);
    }

    public function callIsSuperAdmin(): bool { return $this->isSuperAdmin(); }
    public function callRequireSuperAdmin(): int { return $this->requireSuperAdmin(); }
    public function callRequireSystemAdmin(?int $unitId = null): array { return $this->requireSystemAdmin($unitId); }
    public function callRequireBusinessManager(?int $unitId = null): array { return $this->requireBusinessManager($unitId); }
    public function callRequireStaff(string $perm, int $unitId): int { return $this->requireStaff($perm, $unitId); }
}

function setDatabasePdoForTest(?PDO $pdo): void
{
    $ref = new ReflectionProperty(Database::class, 'pdo');
    $ref->setValue(null, $pdo);
}

function assertForbidden(callable $fn, string $msg): void
{
    try {
        $fn();
    } catch (GuardForbiddenException $e) {
        return;
    }
    throw new RuntimeException($msg);
}

/**
 * @return array{pdo:FakeAcceptancePdo, tokens:array<string,string>}
 */
function makeAcceptanceFixture(): array
{
    $pdo = new FakeAcceptancePdo();
    $pdo->businessUnits = [
        1 => ['business_unit_id' => 1, 'business_unit_name' => 'Pet Store', 'business_unit_status' => 'active'],
        2 => ['business_unit_id' => 2, 'business_unit_name' => 'Veterinary Clinic', 'business_unit_status' => 'active'],
    ];
    $pdo->roles = [
        1 => 'SUPER_ADMINISTRATOR',
        2 => 'SYSTEM_ADMINISTRATOR',
        3 => 'RECEPTIONIST',
        4 => 'INVENTORY_MANAGER',
    ];

    $randHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $knownHash = password_hash('LegacyPassword!2026', PASSWORD_DEFAULT);

    $pdo->users = [
        1 => ['user_account_id' => 1, 'email' => 'super@pawcarino.test', 'username' => 'superadmin', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        10 => ['user_account_id' => 10, 'email' => 'pending.emp@pawcarino.test', 'username' => 'pending_emp', 'password_hash' => $randHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        11 => ['user_account_id' => 11, 'email' => 'expired.emp@pawcarino.test', 'username' => 'expired_emp', 'password_hash' => $randHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        12 => ['user_account_id' => 12, 'email' => 'revoked.emp@pawcarino.test', 'username' => 'revoked_emp', 'password_hash' => $randHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        13 => ['user_account_id' => 13, 'email' => 'accepted.emp@pawcarino.test', 'username' => 'accepted_emp', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-11-19 10:00:00'],
        14 => ['user_account_id' => 14, 'email' => 'legacy.unverified@pawcarino.test', 'username' => 'legacy_unv', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        15 => ['user_account_id' => 15, 'email' => 'direct.emp@pawcarino.test', 'username' => 'direct_emp', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-11-01 00:00:00'],
    ];

    $pdo->employees = [
        1 => ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Evelyn', 'last_name' => 'Sterling', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        10 => ['employee_id' => 10, 'user_account_id' => 10, 'business_unit_id' => 1, 'first_name' => 'Carla', 'last_name' => 'Mendoza', 'position' => 'Receptionist', 'employee_status' => 'active'],
        11 => ['employee_id' => 11, 'user_account_id' => 11, 'business_unit_id' => 1, 'first_name' => 'Expired', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
        12 => ['employee_id' => 12, 'user_account_id' => 12, 'business_unit_id' => 1, 'first_name' => 'Revoked', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
        13 => ['employee_id' => 13, 'user_account_id' => 13, 'business_unit_id' => 1, 'first_name' => 'Accepted', 'last_name' => 'Staff', 'position' => 'Inventory Manager', 'employee_status' => 'active'],
        14 => ['employee_id' => 14, 'user_account_id' => 14, 'business_unit_id' => 1, 'first_name' => 'Legacy', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
        15 => ['employee_id' => 15, 'user_account_id' => 15, 'business_unit_id' => 1, 'first_name' => 'Direct', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
    ];

    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 10, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 11, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 12, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 13, 'role_id' => 4, 'business_unit_id' => 1],
        ['user_account_id' => 14, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 15, 'role_id' => 3, 'business_unit_id' => 1],
    ];

    $tokens = [
        'pending' => EmployeeInvitationService::generateToken(),
        'expired_pending' => EmployeeInvitationService::generateToken(),
        'revoked' => EmployeeInvitationService::generateToken(),
        'accepted' => EmployeeInvitationService::generateToken(),
    ];

    $pdo->invitations = [
        201 => [
            'invitation_id' => 201, 'user_account_id' => 10, 'employee_id' => 10, 'business_unit_id' => 1,
            'invited_email' => 'pending.emp@pawcarino.test', 'token_hash' => EmployeeInvitationService::hashToken($tokens['pending']),
            'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'accepted_at' => null, 'revoked_at' => null,
            'created_at' => '2026-11-20 12:00:00', 'updated_at' => '2026-11-20 12:00:00',
        ],
        202 => [
            'invitation_id' => 202, 'user_account_id' => 11, 'employee_id' => 11, 'business_unit_id' => 1,
            'invited_email' => 'expired.emp@pawcarino.test', 'token_hash' => EmployeeInvitationService::hashToken($tokens['expired_pending']),
            'status' => 'pending', 'expires_at' => '2026-11-20 11:59:59', 'accepted_at' => null, 'revoked_at' => null,
            'created_at' => '2026-11-18 11:59:59', 'updated_at' => '2026-11-18 11:59:59',
        ],
        203 => [
            'invitation_id' => 203, 'user_account_id' => 12, 'employee_id' => 12, 'business_unit_id' => 1,
            'invited_email' => 'revoked.emp@pawcarino.test', 'token_hash' => EmployeeInvitationService::hashToken($tokens['revoked']),
            'status' => 'revoked', 'expires_at' => '2026-11-22 12:00:00', 'accepted_at' => null, 'revoked_at' => '2026-11-20 12:05:00',
            'created_at' => '2026-11-20 12:00:00', 'updated_at' => '2026-11-20 12:05:00',
        ],
        // Older revoked row + accepted row for #13
        204 => [
            'invitation_id' => 204, 'user_account_id' => 13, 'employee_id' => 13, 'business_unit_id' => 1,
            'invited_email' => 'accepted.emp@pawcarino.test', 'token_hash' => EmployeeInvitationService::hashToken(EmployeeInvitationService::generateToken()),
            'status' => 'revoked', 'expires_at' => '2026-11-20 10:00:00', 'accepted_at' => null, 'revoked_at' => '2026-11-18 12:00:00',
            'created_at' => '2026-11-18 10:00:00', 'updated_at' => '2026-11-18 12:00:00',
        ],
        205 => [
            'invitation_id' => 205, 'user_account_id' => 13, 'employee_id' => 13, 'business_unit_id' => 1,
            'invited_email' => 'accepted.emp@pawcarino.test', 'token_hash' => EmployeeInvitationService::hashToken($tokens['accepted']),
            'status' => 'accepted', 'expires_at' => '2026-11-21 10:00:00', 'accepted_at' => '2026-11-19 10:00:00', 'revoked_at' => null,
            'created_at' => '2026-11-19 09:00:00', 'updated_at' => '2026-11-19 10:00:00',
        ],
    ];

    return ['pdo' => $pdo, 'tokens' => $tokens];
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

$fixedNow = strtotime('2026-11-20 12:30:00 UTC');

echo "=== Checkpoint 4: Invitation Acceptance & Authentication Guard Tests (Isolated FakePDO) ===\n\n";

test('Valid invitation acceptance sets password, verifies email, consumes invitation, enforces canonical lock order, and preserves immutable fields', function () use ($fixedNow) {
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    // Pre-acceptance read validation succeeds
    $display = $svc->validateTokenForAcceptance($tokens['pending']);
    assertTrue($display['ok'] === true && $display['invitation']['email'] === 'pending.emp@pawcarino.test', 'Valid token display check failed');
    assertTrue($display['invitation']['business_unit_name'] === 'Pet Store' && $display['invitation']['position'] === 'Receptionist', 'Display metadata mismatch');

    // Before acceptance, account is blocked from authenticating
    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 10) === true, 'Pending invitee must be blocked before acceptance');

    $res = $svc->acceptInvitation($tokens['pending'], 'NewEmployeePass!2026', 'NewEmployeePass!2026');
    assertTrue($res['ok'] === true && $res['status'] === 200 && $res['invitation_id'] === 201, 'Valid acceptance must succeed');

    // Verify canonical lock order: user_account -> employee -> employee_invitation (no business_unit lock)
    assertTrue(count($pdo->forUpdateQueries) === 3, 'Expected 3 FOR UPDATE queries');
    assertTrue(str_contains($pdo->forUpdateQueries[0], 'FROM user_account ua'), 'First lock must be user_account');
    assertTrue(str_contains($pdo->forUpdateQueries[1], 'FROM employee e'), 'Second lock must be employee');
    assertTrue(str_contains($pdo->forUpdateQueries[2], 'FROM employee_invitation'), 'Third lock must be employee_invitation');
    foreach ($pdo->forUpdateQueries as $q) {
        assertTrue(!str_contains($q, 'FROM business_unit') && !str_contains($q, 'JOIN business_unit'), 'business_unit must not be locked');
    }

    // Verify password updated, email verified, invitation consumed, and immutable fields untouched
    assertTrue(password_verify('NewEmployeePass!2026', $pdo->users[10]['password_hash']), 'New password must be hashed and verifiable');
    assertTrue($pdo->users[10]['email_verified_at'] === '2026-11-20 12:30:00', 'email_verified_at must be set in UTC');
    assertTrue($pdo->users[10]['email'] === 'pending.emp@pawcarino.test' && $pdo->users[10]['username'] === 'pending_emp', 'Email & username must remain immutable');
    assertTrue($pdo->employees[10]['business_unit_id'] === 1 && $pdo->employees[10]['position'] === 'Receptionist', 'Unit & position must remain immutable');
    assertTrue($pdo->invitations[201]['status'] === 'accepted' && $pdo->invitations[201]['accepted_at'] === '2026-11-20 12:30:00', 'Invitation must be marked accepted');

    // Audit log has invitation.accepted with zero raw token, hash, or password
    assertTrue(count($pdo->auditLogs) === 1 && $pdo->auditLogs[0]['action_code'] === 'invitation.accepted', 'invitation.accepted audit required');
    $auditText = $pdo->auditLogs[0]['summary'] . ' ' . (string)$pdo->auditLogs[0]['new_value'];
    assertTrue(!str_contains($auditText, $tokens['pending']) && !str_contains($auditText, 'NewEmployeePass'), 'Audit must never log tokens or passwords');

    // After acceptance, account is unblocked for authentication
    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 10) === false, 'Accepted invitee must be allowed to authenticate');
});

test('Password policy errors return 422 without consuming the invitation', function () use ($fixedNow) {
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    $short = $svc->acceptInvitation($tokens['pending'], 'short', 'short');
    $mismatch = $svc->acceptInvitation($tokens['pending'], 'ValidPass1234!', 'DifferentPass1234!');
    assertTrue(!$short['ok'] && $short['status'] === 422 && isset($short['errors']['password']), 'Short password must fail 422');
    assertTrue(!$mismatch['ok'] && $mismatch['status'] === 422 && isset($mismatch['errors']['password_confirm']), 'Mismatched password must fail 422');
    assertTrue($pdo->invitations[201]['status'] === 'pending', 'Invitation must remain pending after validation error');
});

test('Rejects malformed, unknown, expired (persisting expired state), revoked, and already-accepted tokens with generic non-enumerating errors', function () use ($fixedNow) {
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    $rMalformed = $svc->acceptInvitation('not-a-valid-hex-token', 'StrongPass!2026', 'StrongPass!2026');
    $rUnknown = $svc->acceptInvitation(EmployeeInvitationService::generateToken(), 'StrongPass!2026', 'StrongPass!2026');
    $rExpired = $svc->acceptInvitation($tokens['expired_pending'], 'StrongPass!2026', 'StrongPass!2026');
    $rRevoked = $svc->acceptInvitation($tokens['revoked'], 'StrongPass!2026', 'StrongPass!2026');
    $rAccepted = $svc->acceptInvitation($tokens['accepted'], 'StrongPass!2026', 'StrongPass!2026');

    assertTrue(!$rMalformed['ok'] && $rMalformed['status'] === 400, 'Malformed token must fail 400');
    assertTrue(!$rUnknown['ok'] && $rUnknown['status'] === 404, 'Unknown token must fail 404');
    assertTrue(!$rExpired['ok'] && $rExpired['status'] === 410, 'Expired token must fail 410');
    assertTrue($pdo->invitations[202]['status'] === 'expired', 'Expired pending invitation must persist status=expired');
    assertTrue(!$rRevoked['ok'] && $rRevoked['status'] === 409, 'Revoked token must fail 409');
    assertTrue(!$rAccepted['ok'] && $rAccepted['status'] === 409, 'Already-accepted token must fail 409');

    // Non-enumeration: all token-rejection messages are identical generic errors containing no email addresses
    $msgs = [$rMalformed['error'], $rUnknown['error'], $rExpired['error'], $rRevoked['error'], $rAccepted['error']];
    assertTrue(count(array_unique($msgs)) === 1 && !str_contains($msgs[0], '@'), 'Rejection errors must be generic and non-enumerating');
});

test('Rejects email, employee/account ownership, business-unit mismatch, and Super Admin targets on acceptance', function () use ($fixedNow) {
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    // 1. Account email changed after invitation was issued
    $pdo->users[10]['email'] = 'changed@pawcarino.test';
    $rEmail = $svc->acceptInvitation($tokens['pending'], 'StrongPass!2026', 'StrongPass!2026');
    assertTrue(!$rEmail['ok'] && $rEmail['status'] === 409, 'Email mismatch must block acceptance');
    $pdo->users[10]['email'] = 'pending.emp@pawcarino.test';

    // 2. Employee business unit changed after invitation was issued
    $pdo->employees[10]['business_unit_id'] = 2;
    $rUnit = $svc->acceptInvitation($tokens['pending'], 'StrongPass!2026', 'StrongPass!2026');
    assertTrue(!$rUnit['ok'] && $rUnit['status'] === 409, 'Business unit mismatch must block acceptance');
    $pdo->employees[10]['business_unit_id'] = 1;

    // 3. Account granted SUPER_ADMINISTRATOR role
    $pdo->accountRoles[] = ['user_account_id' => 10, 'role_id' => 1, 'business_unit_id' => 2];
    $rSuper = $svc->acceptInvitation($tokens['pending'], 'StrongPass!2026', 'StrongPass!2026');
    assertTrue(!$rSuper['ok'] && $rSuper['status'] === 409, 'Super Admin target must block acceptance');
    array_pop($pdo->accountRoles);
    assertTrue($pdo->invitations[201]['status'] === 'pending', 'Invitation must remain unconsumed on rejected mismatches');
});

test('Simulated concurrent acceptance: second attempt cannot consume the same invitation twice', function () use ($fixedNow) {
    // Case A: Sequential second attempt after first commits
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    $first = $svc->acceptInvitation($tokens['pending'], 'FirstWinner!2026', 'FirstWinner!2026');
    $second = $svc->acceptInvitation($tokens['pending'], 'SecondLoser!2026', 'SecondLoser!2026');
    assertTrue($first['ok'] === true && !$second['ok'] && $second['status'] === 409, 'Second acceptance must fail 409');
    assertTrue(password_verify('FirstWinner!2026', $pdo->users[10]['password_hash']), 'First winner password must remain intact');

    // Case B: Simulated race right before conditional UPDATE WHERE status='pending'
    ['pdo' => $pdo2, 'tokens' => $tokens2] = makeAcceptanceFixture();
    $pdo2->onBeforeConsumeUpdate = function (FakeAcceptancePdo $db): void {
        // Simulate another concurrent transaction having already consumed invitation #201
        $db->invitations[201]['status'] = 'accepted';
        $db->invitations[201]['accepted_at'] = '2026-11-20 12:29:59';
    };
    $svc2 = new EmployeeInvitationService($pdo2, null, fn(): int => $fixedNow);
    $raced = $svc2->acceptInvitation($tokens2['pending'], 'RacedSecond!2026', 'RacedSecond!2026');
    assertTrue(!$raced['ok'] && $raced['status'] === 409 && $pdo2->rollbackCount === 1, 'Conditional UPDATE rowCount guard must reject raced acceptance and roll back');
    assertTrue(!password_verify('RacedSecond!2026', $pdo2->users[10]['password_hash']), 'Raced password must NOT overwrite user_account');
});

test('Transaction rollback on DB failure leaves no partial invitation acceptance or password update', function () use ($fixedNow) {
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $originalHash = $pdo->users[10]['password_hash'];
    $pdo->failOnSqlSubstring = 'INSERT INTO audit_log';
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    $res = $svc->acceptInvitation($tokens['pending'], 'RollbackPass!2026', 'RollbackPass!2026');
    assertTrue(!$res['ok'] && $res['status'] === 500 && $pdo->rollbackCount === 1 && !$pdo->inTransaction(), 'Failure must roll back cleanly');
    assertTrue($pdo->invitations[201]['status'] === 'pending' && $pdo->invitations[201]['accepted_at'] === null, 'Invitation must remain pending');
    assertTrue($pdo->users[10]['password_hash'] === $originalHash && $pdo->users[10]['email_verified_at'] === null, 'Password and email_verified_at must be rolled back');
});

test('Authentication guard blocks pending, expired, and revoked invitees while allowing accepted, legacy unverified, direct, and Super Admin accounts', function () {
    ['pdo' => $pdo] = makeAcceptanceFixture();

    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 10) === true, 'Pending invitee (#10) must be blocked');
    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 11) === true, 'Expired never-accepted invitee (#11) must be blocked');
    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 12) === true, 'Revoked never-accepted invitee (#12) must be blocked');

    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 13) === false, 'Accepted invitee (#13, even with older revoked row) must be allowed');
    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 14) === false, 'Legacy unverified employee (#14, email_verified_at=NULL, no invitation history) must be allowed');
    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 15) === false, 'Direct-password employee (#15) must be allowed');
    assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 1) === false, 'Super Administrator (#1) must be allowed');
});

test('Static wiring check: routes, InvitationController immutability, and server-side auth guards are wired cleanly', function () {
    $routes = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
    $invCtrl = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/InvitationController.php');
    $authCtrl = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/AuthController.php');
    $staffCtrl = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/StaffController.php');
    $core = (string)file_get_contents(dirname(__DIR__) . '/app/Core/Core.php');
    $switcher = (string)file_get_contents(dirname(__DIR__) . '/app/Services/InterfaceSwitcherService.php');
    $superCtrl = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SuperAdminController.php');

    assertTrue(str_contains($routes, "/invitations/accept', 'InvitationController', 'showAcceptForm'") && str_contains($routes, "/invitations/accept', 'InvitationController', 'accept'"), 'Acceptance routes must be registered');
    assertTrue(!str_contains($invCtrl, 'business_unit_id') && !str_contains($invCtrl, '$_POST[\'roles\']') && !str_contains($invCtrl, '$_POST[\'email\']'), 'InvitationController::accept must ignore role/unit/email POST inputs');
    assertTrue(str_contains($authCtrl, 'isAccountBlockedByUnacceptedInvitation'), 'AuthController must enforce invitation guard');
    assertTrue(str_contains($staffCtrl, 'isAccountBlockedByUnacceptedInvitation'), 'StaffController must enforce invitation guard');
    assertTrue(str_contains($core, 'isAccountBlockedByUnacceptedInvitation'), 'Core.php protected route guards must enforce invitation guard');
    assertTrue(str_contains($switcher, 'isAccountBlockedByUnacceptedInvitation'), 'InterfaceSwitcherService must enforce invitation guard');
    assertTrue(!str_contains($superCtrl, 'EmployeeInvitationService') && !str_contains($superCtrl, 'is_invitation'), 'SuperAdminController must remain unchanged');
});

test('Checkpoint 4.1: InterfaceSwitcherService blocks unresolved invitation history regardless of is_super_admin or SUPER_ADMINISTRATOR role', function () {
    ['pdo' => $pdo] = makeAcceptanceFixture();

    // Case A: Pending invitee #10 mutated to have is_super_admin = 1 -> STILL blocked
    $pdo->users[10]['is_super_admin'] = 1;
    $ctxFlag = InterfaceSwitcherService::loadContext($pdo, 10);
    assertTrue($ctxFlag['account_active'] === false, 'Unresolved invitee with is_super_admin=1 must have account_active=false');
    assertTrue($ctxFlag['is_super_admin'] === false, 'Unresolved invitee with is_super_admin=1 must have is_super_admin=false');
    assertTrue($ctxFlag['employee_active'] === false, 'Unresolved invitee with is_super_admin=1 must have employee_active=false');
    assertTrue(InterfaceSwitcherService::isSuperAdminAccount($pdo, 10) === false, 'isSuperAdminAccount must return false for unresolved invitee with is_super_admin=1');
    $pdo->users[10]['is_super_admin'] = 0;

    // Case B: Expired invitee #11 granted SUPER_ADMINISTRATOR role -> STILL blocked
    $pdo->accountRoles[] = ['user_account_id' => 11, 'role_id' => 1, 'business_unit_id' => 1];
    $ctxRole = InterfaceSwitcherService::loadContext($pdo, 11);
    assertTrue($ctxRole['account_active'] === false && $ctxRole['is_super_admin'] === false && $ctxRole['employee_active'] === false, 'Unresolved invitee with SUPER_ADMINISTRATOR role must remain blocked');
    assertTrue(InterfaceSwitcherService::isSuperAdminAccount($pdo, 11) === false, 'isSuperAdminAccount must return false for unresolved invitee with SUPER_ADMINISTRATOR role');
    array_pop($pdo->accountRoles);

    // Case C: Revoked invitee #12 -> blocked
    $ctxRevoked = InterfaceSwitcherService::loadContext($pdo, 12);
    assertTrue($ctxRevoked['account_active'] === false && $ctxRevoked['employee_active'] === false, 'Revoked never-accepted invitee must remain blocked');

    // Case D: Accepted invitee #13 -> active
    $ctxAccepted = InterfaceSwitcherService::loadContext($pdo, 13);
    assertTrue($ctxAccepted['account_active'] === true && $ctxAccepted['employee_active'] === true && $ctxAccepted['is_super_admin'] === false, 'Accepted invitee must be active in InterfaceSwitcherService');

    // Case E: Direct-provisioned Super Administrator #1 (zero invitation history) -> unaffected
    $ctxSuper = InterfaceSwitcherService::loadContext($pdo, 1);
    assertTrue($ctxSuper['account_active'] === true && $ctxSuper['is_super_admin'] === true && $ctxSuper['employee_active'] === true, 'Direct-provisioned Super Administrator must remain unaffected');

    // Case F: Legacy unverified employee #14 (email_verified_at=NULL, zero invitation history) -> unaffected
    $ctxLegacy = InterfaceSwitcherService::loadContext($pdo, 14);
    assertTrue($ctxLegacy['account_active'] === true && $ctxLegacy['employee_active'] === true, 'Legacy unverified employee without invitation records must remain unaffected');
});

test('Checkpoint 4.1: Existing sessions with unresolved invitation history cannot bypass Core guards via is_super_admin or SUPER_ADMINISTRATOR role', function () {
    ['pdo' => $pdo] = makeAcceptanceFixture();
    $pdo->roles[5] = 'BUSINESS_MANAGER';
    setDatabasePdoForTest($pdo);
    $ctrl = new GuardHarnessController($pdo);

    try {
        // Pending invitee #10 given is_super_admin=1 AND SYSTEM_ADMINISTRATOR / BUSINESS_MANAGER roles in an existing session
        $pdo->users[10]['is_super_admin'] = 1;
        $pdo->accountRoles[] = ['user_account_id' => 10, 'role_id' => 2, 'business_unit_id' => 1];
        $pdo->accountRoles[] = ['user_account_id' => 10, 'role_id' => 5, 'business_unit_id' => 1];

        $_SESSION = [
            'user_id' => 10,
            'employee_id' => 10,
            'base_context' => 'employee',
            'active_context' => 'employee',
            'auth_method' => 'email',
            'is_super_admin' => true,
        ];
        assertTrue($ctrl->callIsSuperAdmin() === false, 'isSuperAdmin() must return false when unresolved invitation exists even if is_super_admin=1');
        assertForbidden(fn() => $ctrl->callRequireSuperAdmin(), 'requireSuperAdmin() must deny unresolved invitee with is_super_admin=1');
        assertForbidden(fn() => $ctrl->callRequireSystemAdmin(1), 'requireSystemAdmin() must deny unresolved invitee with is_super_admin=1');
        assertForbidden(fn() => $ctrl->callRequireBusinessManager(1), 'requireBusinessManager() must deny unresolved invitee with is_super_admin=1');
        assertForbidden(fn() => $ctrl->callRequireStaff('appointment.view', 1), 'requireStaff() must deny unresolved invitee with is_super_admin=1');

        // Expired invitee #11 given SUPER_ADMINISTRATOR role (without is_super_admin flag)
        $pdo->accountRoles[] = ['user_account_id' => 11, 'role_id' => 1, 'business_unit_id' => 1];
        $_SESSION = [
            'user_id' => 11,
            'employee_id' => 11,
            'base_context' => 'employee',
            'active_context' => 'employee',
            'auth_method' => 'email',
            'is_super_admin' => true,
        ];
        assertTrue($ctrl->callIsSuperAdmin() === false, 'isSuperAdmin() must return false for unresolved invitee with SUPER_ADMINISTRATOR role');
        assertForbidden(fn() => $ctrl->callRequireSuperAdmin(), 'requireSuperAdmin() must deny unresolved invitee with SUPER_ADMINISTRATOR role');
        assertForbidden(fn() => $ctrl->callRequireSystemAdmin(1), 'requireSystemAdmin() must deny unresolved invitee with SUPER_ADMINISTRATOR role');
        assertForbidden(fn() => $ctrl->callRequireBusinessManager(1), 'requireBusinessManager() must deny unresolved invitee with SUPER_ADMINISTRATOR role');
        assertForbidden(fn() => $ctrl->callRequireStaff('appointment.view', 1), 'requireStaff() must deny unresolved invitee with SUPER_ADMINISTRATOR role');

        // Revoked invitee #12
        $_SESSION = [
            'user_id' => 12,
            'employee_id' => 12,
            'base_context' => 'employee',
            'active_context' => 'employee',
            'auth_method' => 'email',
            'is_super_admin' => false,
        ];
        assertForbidden(fn() => $ctrl->callRequireStaff('appointment.view', 1), 'requireStaff() must deny revoked invitee');
    } finally {
        $_SESSION = [];
        setDatabasePdoForTest(null);
    }
});

test('Checkpoint 4.1: Core guards permit accepted invitees, direct-provisioned Super Administrators, and legacy accounts without invitation history', function () {
    ['pdo' => $pdo] = makeAcceptanceFixture();
    $pdo->roles[5] = 'BUSINESS_MANAGER';
    // Grant accepted invitee #13 SYSTEM_ADMINISTRATOR and BUSINESS_MANAGER in unit 1 for full guard coverage
    $pdo->accountRoles[] = ['user_account_id' => 13, 'role_id' => 2, 'business_unit_id' => 1];
    $pdo->accountRoles[] = ['user_account_id' => 13, 'role_id' => 5, 'business_unit_id' => 1];
    setDatabasePdoForTest($pdo);
    $ctrl = new GuardHarnessController($pdo);

    try {
        // 1. Accepted invitee #13 (has older revoked invitation + accepted invitation)
        $_SESSION = [
            'user_id' => 13,
            'employee_id' => 13,
            'base_context' => 'employee',
            'active_context' => 'employee',
            'auth_method' => 'email',
            'is_super_admin' => false,
        ];
        $sysRes = $ctrl->callRequireSystemAdmin(1);
        $bmRes = $ctrl->callRequireBusinessManager(1);
        $staffEmpId = $ctrl->callRequireStaff('appointment.view', 1);
        assertTrue($sysRes[0] === 13 && $sysRes[2] === 1 && $sysRes[4] === false, 'Accepted invitee must access authorized System Admin workspace');
        assertTrue($bmRes[0] === 13 && $bmRes[2] === 1 && $bmRes[4] === false, 'Accepted invitee must access authorized Business Manager workspace');
        assertTrue($staffEmpId === 13, 'Accepted invitee must access authorized staff workspace');

        // 2. Direct-provisioned Super Administrator #1 (zero invitation history)
        $_SESSION = [
            'user_id' => 1,
            'employee_id' => 1,
            'base_context' => 'employee',
            'active_context' => 'employee',
            'auth_method' => 'email',
            'is_super_admin' => true,
        ];
        assertTrue($ctrl->callIsSuperAdmin() === true, 'Direct-provisioned Super Admin must pass isSuperAdmin()');
        assertTrue($ctrl->callRequireSuperAdmin() === 1, 'Direct-provisioned Super Admin must pass requireSuperAdmin()');
        assertTrue($ctrl->callRequireSystemAdmin(1)[4] === true, 'Direct-provisioned Super Admin must pass requireSystemAdmin()');
        assertTrue($ctrl->callRequireBusinessManager(1)[4] === true, 'Direct-provisioned Super Admin must pass requireBusinessManager()');
        assertTrue($ctrl->callRequireStaff('appointment.view', 1) === 1, 'Direct-provisioned Super Admin must pass requireStaff()');

        // 3. Legacy unverified employee #14 (email_verified_at=NULL, zero invitation history)
        $_SESSION = [
            'user_id' => 14,
            'employee_id' => 14,
            'base_context' => 'employee',
            'active_context' => 'employee',
            'auth_method' => 'email',
            'is_super_admin' => false,
        ];
        assertTrue($ctrl->callRequireStaff('appointment.view', 1) === 14, 'Legacy unverified employee without invitation history must remain unaffected');
    } finally {
        $_SESSION = [];
        setDatabasePdoForTest(null);
    }
});

test('Checkpoint 4.1: Static guard-consistency verification across Core, InterfaceSwitcherService, AuthController, StaffController, and custom employee guards', function () {
    $root = dirname(__DIR__);
    $authCtrl = (string)file_get_contents($root . '/app/Controllers/AuthController.php');
    $staffCtrl = (string)file_get_contents($root . '/app/Controllers/StaffController.php');
    $switcher = (string)file_get_contents($root . '/app/Services/InterfaceSwitcherService.php');
    $core = (string)file_get_contents($root . '/app/Core/Core.php');
    $delivery = (string)file_get_contents($root . '/app/Controllers/DeliveryController.php');
    $petRec = (string)file_get_contents($root . '/app/Controllers/PetStoreReceptionistController.php');
    $groomRec = (string)file_get_contents($root . '/app/Controllers/GroomingReceptionistController.php');
    $vetRec = (string)file_get_contents($root . '/app/Controllers/VetClinicReceptionistController.php');
    $manager = (string)file_get_contents($root . '/app/Controllers/ManagerController.php');

    // No Super Admin exemption on invitation blocking anywhere
    assertTrue(!preg_match('/\$blockedByInvitation\s*=\s*[^;]*is_super_admin/', $authCtrl), 'AuthController must not exempt is_super_admin from invitation blocking');
    assertTrue(!preg_match('/\$blockedByInvitation\s*=\s*[^;]*is_super_admin/', $staffCtrl), 'StaffController must not exempt is_super_admin from invitation blocking');
    assertTrue(!str_contains($switcher, '!$isSuperAdmin && EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation'), 'InterfaceSwitcherService must not exempt $isSuperAdmin from invitation blocking');

    // Core enforces invitation check inside isSuperAdmin() and before isSuperAdmin() in all require* guards
    assertTrue(substr_count($core, 'requireInvitationAcceptedForSession(') >= 5, 'Core must enforce requireInvitationAcceptedForSession across SuperAdmin/SystemAdmin/BusinessManager/Staff guards');

    // Directly implemented employee-session paths enforce invitation guard
    assertTrue(str_contains($authCtrl, 'requireInvitationAcceptedForSession('), 'AuthController::returnToWorkspace must enforce invitation guard');
    assertTrue(substr_count($staffCtrl, 'requireInvitationAcceptedForSession(') >= 3, 'StaffController::workspace, redeemInventoryKey, and exitInventoryAccess must enforce invitation guard');
    assertTrue(str_contains($delivery, 'requireInvitationAcceptedForSession('), 'DeliveryController::requireDeliveryViewer must enforce invitation guard');
    assertTrue(substr_count($petRec, 'requireInvitationAcceptedForSession(') >= 2, 'PetStoreReceptionistController guards must enforce invitation guard');
    assertTrue(str_contains($groomRec, 'requireInvitationAcceptedForSession(') && !str_contains($groomRec, "(bool)Session::get('is_super_admin')"), 'GroomingReceptionistController must enforce invitation guard and DB-backed isSuperAdmin()');
    assertTrue(str_contains($vetRec, 'requireInvitationAcceptedForSession(') && !str_contains($vetRec, "(bool)Session::get('is_super_admin')"), 'VetClinicReceptionistController must enforce invitation guard and DB-backed isSuperAdmin()');
    assertTrue(substr_count($manager, 'requireInvitationAcceptedForSession(') >= 3, 'ManagerController direct guards must enforce invitation guard');
});

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
