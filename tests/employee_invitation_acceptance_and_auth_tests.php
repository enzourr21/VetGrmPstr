<?php
declare(strict_types=1);

/**
 * Isolated Security & Regression Tests for Decoupled Invitation Acceptance,
 * Customer-to-Employee Conversion (Workflow A & B), Unified Identity Separation,
 * and Legacy Quarantined Authentication Guards.
 *
 * Uses an in-memory FakeAcceptancePdo double (no live MySQL 8.4, no HTTP server, no SMTP).
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Core/Core.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Models/Units.php';
require_once dirname(__DIR__) . '/app/Models/AdminAccountService.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';
require_once dirname(__DIR__) . '/app/Services/InterfaceSwitcherService.php';
require_once dirname(__DIR__) . '/app/Controllers/AuthController.php';

use App\Controllers\AuthController;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Services\EmployeeInvitationService;
use App\Services\InterfaceSwitcherService;

final class FakeAcceptancePdo extends PDO
{
    public array $businessUnits = [];
    public array $positions = [];
    public array $roles = [];
    public array $users = [];
    public array $employees = [];
    public array $customers = [];
    public array $accountRoles = [];
    public array $invitations = [];
    public array $invitationRoles = [];
    public array $auditLogs = [];
    public array $advisoryLocks = [];

    public int $nextUserId = 100;
    public int $nextEmployeeId = 100;
    public int $nextCustomerId = 100;
    public int $nextAuditId = 1;
    public int $lastInsertIdVal = 0;

    public int $userAccountUpdates = 0;
    public int $customerUpdates = 0;

    public bool $inTx = false;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    public array $forUpdateQueries = [];
    public ?string $failOnSqlSubstring = null;
    public int $simulatedNowTs = 1795177800; // 2026-11-20 12:30:00 UTC
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
            'customers' => $this->customers,
            'accountRoles' => $this->accountRoles,
            'invitations' => $this->invitations,
            'invitationRoles' => $this->invitationRoles,
            'auditLogs' => $this->auditLogs,
            'nextUserId' => $this->nextUserId,
            'nextEmployeeId' => $this->nextEmployeeId,
            'nextCustomerId' => $this->nextCustomerId,
            'nextAuditId' => $this->nextAuditId,
            'userAccountUpdates' => $this->userAccountUpdates,
            'customerUpdates' => $this->customerUpdates,
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
            foreach ($this->snapshot as $k => $v) {
                $this->{$k} = $v;
            }
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

        if (str_contains($sql, 'SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())')) {
            return [[(string)$this->simulatedNowTs]];
        }
        if (str_contains($sql, 'SELECT GET_LOCK(')) {
            $this->advisoryLocks[(string)$params[0]] = true;
            return [['1']];
        }
        if (str_contains($sql, 'SELECT RELEASE_LOCK(')) {
            unset($this->advisoryLocks[(string)$params[0]]);
            return [['1']];
        }

        // Track unexpected Workflow B mutations on user_account / customer
        if (preg_match('/^\s*UPDATE\s+user_account\b/i', $sql)) {
            $this->userAccountUpdates++;
        }
        if (preg_match('/^\s*UPDATE\s+customer\b/i', $sql)) {
            $this->customerUpdates++;
        }

        // 1. Auth guard: isAccountBlockedByUnacceptedInvitation
        if (str_contains($sql, 'AS has_any_invitation') && str_contains($sql, 'AS has_accepted_invitation') && !str_contains($sql, 'FROM employee e')) {
            $uid = (int)$params[0];
            $hasAny = 0;
            $hasAcc = 0;
            foreach ($this->invitations as $inv) {
                if ((int)($inv['user_account_id'] ?? 0) === $uid) {
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
                $bu = $this->businessUnits[(int)$inv['business_unit_id']] ?? null;
                if (!$bu) {
                    return [];
                }
                $roleNames = [];
                foreach ($this->invitationRoles as $ir) {
                    if ((int)$ir['invitation_id'] === (int)$inv['invitation_id']) {
                        $roleNames[] = $this->roles[(int)$ir['role_id']] ?? '';
                    }
                }
                if (!$roleNames && !empty($inv['user_account_id'])) {
                    foreach ($this->accountRoles as $ar) {
                        if ((int)$ar['user_account_id'] === (int)$inv['user_account_id'] && (int)$ar['business_unit_id'] === (int)$inv['business_unit_id']) {
                            $roleNames[] = $this->roles[(int)$ar['role_id']] ?? '';
                        }
                    }
                }
                $roleNames = array_values(array_unique(array_filter($roleNames)));
                sort($roleNames);
                return [$inv + [
                    'business_unit_name' => $bu['business_unit_name'],
                    'business_unit_status' => $bu['business_unit_status'],
                    'invited_roles' => implode(', ', $roleNames),
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
                        'invited_email' => $inv['invited_email'],
                        'username' => $inv['username'] ?? null,
                        'business_unit_id' => $inv['business_unit_id'],
                    ]];
                }
            }
            return [];
        }

        // 4. user_account lookup by email (evaluateEmailEligibility + acceptInvitation)
        if (str_contains($sql, 'FROM user_account ua') && str_contains($sql, 'WHERE ua.email = ?')) {
            $email = strtolower(trim((string)$params[0]));
            foreach ($this->users as $uid => $ua) {
                if (strtolower(trim((string)$ua['email'])) === $email) {
                    return [[
                        'user_account_id' => $ua['user_account_id'],
                        'email' => $ua['email'],
                        'username' => $ua['username'] ?? null,
                        'account_status' => $ua['account_status'],
                        'is_super_admin' => $ua['is_super_admin'],
                        'email_verified_at' => $ua['email_verified_at'],
                        'has_super_role' => $this->hasSuperRole((int)$uid) ? 1 : 0,
                    ]];
                }
            }
            return [];
        }

        // 5. username check in user_account
        if (str_contains($sql, 'SELECT user_account_id FROM user_account WHERE username = ?')) {
            $u = strtolower(trim((string)$params[0]));
            foreach ($this->users as $ua) {
                if (($ua['username'] ?? null) !== null && strtolower(trim((string)$ua['username'])) === $u) {
                    return [[(string)$ua['user_account_id']]];
                }
            }
            return [];
        }

        // 6. customer / employee checks by user_account_id
        if (str_contains($sql, 'FROM customer') && str_contains($sql, 'WHERE user_account_id = ?')) {
            $uid = (int)$params[0];
            foreach ($this->customers as $c) {
                if ((int)$c['user_account_id'] === $uid) {
                    return [$c];
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM employee') && str_contains($sql, 'WHERE user_account_id = ?')) {
            $uid = (int)$params[0];
            foreach ($this->employees as $e) {
                if ((int)$e['user_account_id'] === $uid) {
                    return [$e];
                }
            }
            return [];
        }

        // 7. Invitations by invited_email
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE invited_email = ?')) {
            $email = strtolower(trim((string)$params[0]));
            $rows = array_values(array_filter($this->invitations, fn($i) => strtolower(trim((string)$i['invited_email'])) === $email));
            if (str_contains($sql, 'ORDER BY invitation_id DESC')) {
                usort($rows, fn($a, $b) => (int)$b['invitation_id'] <=> (int)$a['invitation_id']);
            } else {
                usort($rows, fn($a, $b) => (int)$a['invitation_id'] <=> (int)$b['invitation_id']);
            }
            return $rows;
        }

        // 8. business_unit / business_position / invitation roles
        if (str_contains($sql, 'FROM business_unit') && str_contains($sql, 'WHERE business_unit_id = ?')) {
            $bu = $this->businessUnits[(int)$params[0]] ?? null;
            return $bu ? [$bu] : [];
        }
        if (str_contains($sql, 'SELECT position_id') && str_contains($sql, 'FROM business_position')) {
            foreach ($this->positions as $p) {
                if ((int)$p['business_unit_id'] === (int)$params[0] && $p['position_name'] === (string)$params[1] && $p['position_status'] === 'active') {
                    return [[(string)$p['position_id']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM employee_invitation_role eir')) {
            $iid = (int)$params[0];
            $rows = [];
            foreach ($this->invitationRoles as $ir) {
                if ((int)$ir['invitation_id'] === $iid) {
                    $rid = (int)$ir['role_id'];
                    $rows[] = ['role_id' => $rid, 'role_name' => $this->roles[$rid] ?? ''];
                }
            }
            return $rows;
        }
        if (str_contains($sql, 'FROM account_role ar') && str_contains($sql, 'WHERE ar.user_account_id = ? AND ar.business_unit_id = ?')) {
            [$uid, $unitId] = [(int)$params[0], (int)$params[1]];
            $rows = [];
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] === $uid && (int)$ar['business_unit_id'] === $unitId) {
                    $rid = (int)$ar['role_id'];
                    $rows[] = ['role_id' => $rid, 'role_name' => $this->roles[$rid] ?? ''];
                }
            }
            return $rows;
        }

        // 9. Acceptance inserts & updates
        if (str_contains($sql, 'INSERT INTO user_account')) {
            $id = $this->nextUserId++;
            $this->users[$id] = [
                'user_account_id' => $id,
                'email' => (string)$params[0],
                'username' => $params[1],
                'password_hash' => (string)$params[2],
                'account_status' => 'active',
                'is_super_admin' => 0,
                'email_verified_at' => (string)$params[3],
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
        if (str_contains($sql, 'INSERT INTO customer (')) {
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
        if (str_contains($sql, "SET status = 'accepted'")) {
            if ($this->onBeforeConsumeUpdate !== null) {
                ($this->onBeforeConsumeUpdate)($this);
            }
            [$acceptedAt, $uid, $eid, $updatedAt, $id, $hash] = [
                (string)$params[0], (int)$params[1], (int)$params[2], (string)$params[3], (int)$params[4], (string)$params[5]
            ];
            if (isset($this->invitations[$id])
                && $this->invitations[$id]['status'] === 'pending'
                && hash_equals($this->invitations[$id]['token_hash'], $hash)) {
                $this->invitations[$id]['status'] = 'accepted';
                $this->invitations[$id]['accepted_at'] = $acceptedAt;
                $this->invitations[$id]['user_account_id'] = $uid;
                $this->invitations[$id]['employee_id'] = $eid;
                $this->invitations[$id]['is_legacy_quarantined'] = 0;
                $this->invitations[$id]['updated_at'] = $updatedAt;
                $rowCount = 1;
            }
            return [];
        }
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

        // 10. InterfaceSwitcherService & Core guard queries
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
        if (str_contains($sql, 'FROM user_account ua') && str_contains($sql, "ua.is_super_admin = 1 OR r.role_name = 'SUPER_ADMINISTRATOR'")) {
            $uid = (int)$params[0];
            $ua = $this->users[$uid] ?? null;
            if (!$ua || $ua['account_status'] !== 'active') {
                return [];
            }
            return ((int)$ua['is_super_admin'] === 1 || $this->hasSuperRole($uid)) ? [['1' => 1]] : [];
        }
        if (str_contains($sql, 'FROM business_unit') && (str_contains($sql, "business_unit_status = 'active'") || str_contains($sql, 'business_unit_status="active"')) && !str_contains($sql, 'business_unit_id=?') && !str_contains($sql, 'business_unit_name=?')) {
            return array_values(array_filter($this->businessUnits, fn($bu) => $bu['business_unit_status'] === 'active'));
        }
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
                if ((int)$ar['user_account_id'] !== $uid || ($this->roles[(int)$ar['role_id']] ?? '') !== $wantedRole) {
                    continue;
                }
                $bu = $this->businessUnits[(int)$ar['business_unit_id']] ?? null;
                if ($bu && $bu['business_unit_status'] === 'active') {
                    $rows[] = ['employee_id' => (int)$emp['employee_id'], 'business_unit_id' => (int)$bu['business_unit_id'], 'business_unit_name' => $bu['business_unit_name']];
                }
            }
            return $rows;
        }
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
        if (str_contains($sql, 'WHERE ua.is_super_admin = 1 AND ua.account_status = \'active\'') && str_contains($sql, 'ORDER BY ua.user_account_id LIMIT 1')) {
            $candidates = array_values(array_filter($this->users, fn($u) => (int)$u['is_super_admin'] === 1 && $u['account_status'] === 'active'));
            usort($candidates, fn($a, $b) => (int)$a['user_account_id'] <=> (int)$b['user_account_id']);
            if (!$candidates) {
                return [];
            }
            $u = $candidates[0];
            $uid = (int)$u['user_account_id'];
            $empId = null;
            foreach ($this->employees as $e) {
                if ((int)$e['user_account_id'] === $uid && $e['employee_status'] === 'active') {
                    $empId = (int)$e['employee_id'];
                    break;
                }
            }
            return [[
                'user_account_id' => $uid,
                'email' => $u['email'],
                'username' => $u['username'],
                'is_super_admin' => $u['is_super_admin'],
                'customer_id' => null,
                'employee_id' => $empId,
            ]];
        }

        throw new RuntimeException("Unexpected SQL in FakeAcceptancePdo: {$sql}");
    }

    private function hasSuperRole(int $uid): bool
    {
        foreach ($this->accountRoles as $ar) {
            if ((int)$ar['user_account_id'] === $uid && ($this->roles[(int)$ar['role_id']] ?? '') === 'SUPER_ADMINISTRATOR') {
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
        return $this->rows[$this->cursor++] ?? false;
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
    $pdo->positions = [
        ['position_id' => 11, 'business_unit_id' => 1, 'position_name' => 'Receptionist', 'position_status' => 'active'],
        ['position_id' => 12, 'business_unit_id' => 1, 'position_name' => 'Inventory Manager', 'position_status' => 'active'],
    ];
    $pdo->roles = [
        1 => 'SUPER_ADMINISTRATOR',
        2 => 'SYSTEM_ADMINISTRATOR',
        3 => 'RECEPTIONIST',
        4 => 'INVENTORY_MANAGER',
        5 => 'BUSINESS_MANAGER',
    ];

    $knownHash = password_hash('ExistingCustomerPass!2026', PASSWORD_DEFAULT);

    $pdo->users = [
        1 => ['user_account_id' => 1, 'email' => 'super@pawcarino.test', 'username' => 'superadmin', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        13 => ['user_account_id' => 13, 'email' => 'accepted.emp@pawcarino.test', 'username' => 'accepted_emp', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-11-19 10:00:00'],
        14 => ['user_account_id' => 14, 'email' => 'legacy.unverified@pawcarino.test', 'username' => 'legacy_unv', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // Legacy quarantined unresolved invitee (#16)
        16 => ['user_account_id' => 16, 'email' => 'quarantined.legacy@pawcarino.test', 'username' => 'quar_16', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // Existing active customer-only account with NULL username (#20)
        20 => ['user_account_id' => 20, 'email' => 'cust.nulluser@example.com', 'username' => null, 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-10-01 09:00:00'],
        // Another active user to test wrong-account signed-in (#21)
        21 => ['user_account_id' => 21, 'email' => 'other.customer@example.com', 'username' => 'othercust', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-10-01 09:00:00'],
        // Active user whose employee profile is deactivated (#22)
        22 => ['user_account_id' => 22, 'email' => 'deactivated.staff@pawcarino.test', 'username' => 'deact22', 'password_hash' => $knownHash, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-10-01 09:00:00'],
    ];

    $pdo->employees = [
        1 => ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Evelyn', 'last_name' => 'Sterling', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        13 => ['employee_id' => 13, 'user_account_id' => 13, 'business_unit_id' => 1, 'first_name' => 'Accepted', 'last_name' => 'Staff', 'position' => 'Inventory Manager', 'employee_status' => 'active'],
        14 => ['employee_id' => 14, 'user_account_id' => 14, 'business_unit_id' => 1, 'first_name' => 'Legacy', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
        16 => ['employee_id' => 16, 'user_account_id' => 16, 'business_unit_id' => 1, 'first_name' => 'Quar', 'last_name' => 'Legacy', 'position' => 'Receptionist', 'employee_status' => 'active'],
        22 => ['employee_id' => 22, 'user_account_id' => 22, 'business_unit_id' => 1, 'first_name' => 'Former', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'inactive'],
    ];

    $pdo->customers = [
        20 => ['customer_id' => 20, 'user_account_id' => 20, 'first_name' => 'Nora', 'last_name' => 'Cust', 'phone' => '09171110020', 'customer_status' => 'active'],
        21 => ['customer_id' => 21, 'user_account_id' => 21, 'first_name' => 'Other', 'last_name' => 'Cust', 'phone' => null, 'customer_status' => 'active'],
        22 => ['customer_id' => 22, 'user_account_id' => 22, 'first_name' => 'Former', 'last_name' => 'Staff', 'phone' => null, 'customer_status' => 'active'],
    ];

    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 13, 'role_id' => 4, 'business_unit_id' => 1],
        ['user_account_id' => 13, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 13, 'role_id' => 5, 'business_unit_id' => 1],
        ['user_account_id' => 14, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 16, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 22, 'role_id' => 3, 'business_unit_id' => 1],
    ];

    $tokens = [
        'new_pending' => EmployeeInvitationService::generateToken(),
        'cust_pending' => EmployeeInvitationService::generateToken(),
        'expired_pending' => EmployeeInvitationService::generateToken(),
        'revoked' => EmployeeInvitationService::generateToken(),
        'accepted' => EmployeeInvitationService::generateToken(),
    ];

    $pdo->invitations = [
        201 => [
            'invitation_id' => 201, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1,
            'invited_email' => 'new.invitee@pawcarino.test', 'first_name' => 'Carla', 'last_name' => 'Mendoza',
            'username' => 'carla_m', 'phone' => '09170000201', 'position_id' => 11, 'position_name' => 'Receptionist',
            'token_hash' => EmployeeInvitationService::hashToken($tokens['new_pending']),
            'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'accepted_at' => null, 'revoked_at' => null,
            'is_legacy_quarantined' => 0, 'created_at' => '2026-11-20 12:00:00', 'updated_at' => '2026-11-20 12:00:00',
        ],
        202 => [
            'invitation_id' => 202, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1,
            'invited_email' => 'cust.nulluser@example.com', 'first_name' => 'Nora', 'last_name' => 'AdminName',
            'username' => null, 'phone' => '09179999999', 'position_id' => 12, 'position_name' => 'Inventory Manager',
            'token_hash' => EmployeeInvitationService::hashToken($tokens['cust_pending']),
            'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'accepted_at' => null, 'revoked_at' => null,
            'is_legacy_quarantined' => 0, 'created_at' => '2026-11-20 12:00:00', 'updated_at' => '2026-11-20 12:00:00',
        ],
        203 => [
            'invitation_id' => 203, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1,
            'invited_email' => 'expired@pawcarino.test', 'first_name' => 'Exp', 'last_name' => 'Inv',
            'username' => null, 'phone' => null, 'position_id' => 11, 'position_name' => 'Receptionist',
            'token_hash' => EmployeeInvitationService::hashToken($tokens['expired_pending']),
            'status' => 'pending', 'expires_at' => '2026-11-20 11:59:59', 'accepted_at' => null, 'revoked_at' => null,
            'is_legacy_quarantined' => 0, 'created_at' => '2026-11-18 11:59:59', 'updated_at' => '2026-11-18 11:59:59',
        ],
        204 => [
            'invitation_id' => 204, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1,
            'invited_email' => 'revoked@pawcarino.test', 'first_name' => 'Rev', 'last_name' => 'Inv',
            'username' => null, 'phone' => null, 'position_id' => 11, 'position_name' => 'Receptionist',
            'token_hash' => EmployeeInvitationService::hashToken($tokens['revoked']),
            'status' => 'revoked', 'expires_at' => '2026-11-22 12:00:00', 'accepted_at' => null, 'revoked_at' => '2026-11-20 12:05:00',
            'is_legacy_quarantined' => 0, 'created_at' => '2026-11-20 12:00:00', 'updated_at' => '2026-11-20 12:05:00',
        ],
        205 => [
            'invitation_id' => 205, 'user_account_id' => 13, 'employee_id' => 13, 'business_unit_id' => 1,
            'invited_email' => 'accepted.emp@pawcarino.test', 'first_name' => 'Accepted', 'last_name' => 'Staff',
            'username' => 'accepted_emp', 'phone' => null, 'position_id' => 12, 'position_name' => 'Inventory Manager',
            'token_hash' => EmployeeInvitationService::hashToken($tokens['accepted']),
            'status' => 'accepted', 'expires_at' => '2026-11-21 10:00:00', 'accepted_at' => '2026-11-19 10:00:00', 'revoked_at' => null,
            'is_legacy_quarantined' => 0, 'created_at' => '2026-11-19 09:00:00', 'updated_at' => '2026-11-19 10:00:00',
        ],
        // Legacy quarantined unaccepted row
        206 => [
            'invitation_id' => 206, 'user_account_id' => 16, 'employee_id' => 16, 'business_unit_id' => 1,
            'invited_email' => 'quarantined.legacy@pawcarino.test', 'first_name' => 'Quar', 'last_name' => 'Legacy',
            'username' => 'quar_16', 'phone' => null, 'position_id' => 11, 'position_name' => 'Receptionist',
            'token_hash' => EmployeeInvitationService::hashToken(EmployeeInvitationService::generateToken()),
            'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'accepted_at' => null, 'revoked_at' => null,
            'is_legacy_quarantined' => 1, 'created_at' => '2026-11-20 12:00:00', 'updated_at' => '2026-11-20 12:00:00',
        ],
    ];

    $pdo->invitationRoles = [
        ['invitation_id' => 201, 'role_id' => 3],
        ['invitation_id' => 202, 'role_id' => 4],
        ['invitation_id' => 203, 'role_id' => 3],
        ['invitation_id' => 204, 'role_id' => 3],
        ['invitation_id' => 206, 'role_id' => 3],
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

echo "=== Decoupled Acceptance, Customer-to-Employee Conversion & Guard Tests ===\n\n";

test('Workflow A (new_account): atomically provisions user_account, employee, customer, and account_role only upon acceptance', function () use ($fixedNow) {
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    $display = $svc->validateTokenForAcceptance($tokens['new_pending']);
    assertTrue($display['ok'] === true && $display['mode'] === 'new_account', 'Mode must be new_account');

    // Validation error leaves invitation pending & creates zero rows
    $uBefore = count($pdo->users);
    $short = $svc->acceptInvitation($tokens['new_pending'], 'short', 'short');
    $mismatch = $svc->acceptInvitation($tokens['new_pending'], 'StrongPass!2026', 'MismatchPass!2026');
    assertTrue(!$short['ok'] && $short['status'] === 422 && !$mismatch['ok'] && $mismatch['status'] === 422, 'Invalid password must return 422');
    assertTrue(count($pdo->users) === $uBefore && $pdo->invitations[201]['status'] === 'pending', 'Zero writes on validation error');

    $res = $svc->acceptInvitation($tokens['new_pending'], 'NewEmployeePass!2026', 'NewEmployeePass!2026');
    assertTrue($res['ok'] === true && $res['status'] === 200 && $res['mode'] === 'new_account', 'Workflow A acceptance must succeed');

    $uid = (int)$res['user_account_id'];
    $eid = (int)$res['employee_id'];
    assertTrue(isset($pdo->users[$uid]) && password_verify('NewEmployeePass!2026', $pdo->users[$uid]['password_hash']), 'user_account provisioned with verified hash');
    assertTrue($pdo->users[$uid]['email'] === 'new.invitee@pawcarino.test' && $pdo->users[$uid]['username'] === 'carla_m' && $pdo->users[$uid]['email_verified_at'] === '2026-11-20 12:30:00', 'user_account metadata must match invitation');
    assertTrue(isset($pdo->employees[$eid]) && $pdo->employees[$eid]['position'] === 'Receptionist' && (int)$pdo->employees[$eid]['business_unit_id'] === 1, 'employee provisioned');

    $custs = array_values(array_filter($pdo->customers, fn($c) => (int)$c['user_account_id'] === $uid));
    assertTrue(count($custs) === 1, 'Linked customer profile provisioned');
    $roles = array_values(array_filter($pdo->accountRoles, fn($ar) => (int)$ar['user_account_id'] === $uid && (int)$ar['business_unit_id'] === 1));
    assertTrue(count($roles) === 1 && (int)$roles[0]['role_id'] === 3, 'Intended account_role provisioned');

    assertTrue($pdo->invitations[201]['status'] === 'accepted' && (int)$pdo->invitations[201]['user_account_id'] === $uid && (int)$pdo->invitations[201]['employee_id'] === $eid, 'Invitation linked & consumed');
    $auditCodes = array_column($pdo->auditLogs, 'action_code');
    assertTrue($auditCodes === ['employee.created', 'invitation.accepted'], 'employee.created and invitation.accepted audits recorded');
    foreach ($pdo->auditLogs as $logRow) {
        $auditText = $logRow['summary'] . ' ' . (string)$logRow['new_value'];
        assertTrue(!str_contains($auditText, $tokens['new_pending']) && !str_contains($auditText, 'NewEmployeePass'), 'No token or password in audit log');
    }
});

test('Workflow A blocks concurrent double-consume, username race, and rolls back cleanly on DB failure', function () use ($fixedNow) {
    // 1. Race right before consume update
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $uBefore = count($pdo->users);
    $pdo->onBeforeConsumeUpdate = function (FakeAcceptancePdo $db): void {
        $db->invitations[201]['status'] = 'accepted';
    };
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);
    $raced = $svc->acceptInvitation($tokens['new_pending'], 'RacedPass!2026', 'RacedPass!2026');
    assertTrue(!$raced['ok'] && $raced['status'] === 409 && $pdo->rollbackCount === 1 && count($pdo->users) === $uBefore, 'Race must roll back all provisioned rows');

    // 2. DB failure rollback
    ['pdo' => $pdo2, 'tokens' => $tokens2] = makeAcceptanceFixture();
    $uBefore2 = count($pdo2->users);
    $pdo2->failOnSqlSubstring = 'INSERT INTO audit_log';
    $svc2 = new EmployeeInvitationService($pdo2, null, fn(): int => $fixedNow);
    $err = $svc2->acceptInvitation($tokens2['new_pending'], 'RollbackPass!2026', 'RollbackPass!2026');
    assertTrue(!$err['ok'] && $err['status'] === 500 && $pdo2->rollbackCount === 1 && count($pdo2->users) === $uBefore2 && $pdo2->invitations[201]['status'] === 'pending', 'DB failure must roll back all created records');

    // 3. Username claimed elsewhere before acceptance -> 409
    ['pdo' => $pdo3, 'tokens' => $tokens3] = makeAcceptanceFixture();
    $pdo3->users[99] = ['user_account_id' => 99, 'email' => 'squatter@example.com', 'username' => 'carla_m', 'password_hash' => 'x', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-11-01 00:00:00'];
    $svc3 = new EmployeeInvitationService($pdo3, null, fn(): int => $fixedNow);
    $unConf = $svc3->acceptInvitation($tokens3['new_pending'], 'ValidPass!2026', 'ValidPass!2026');
    assertTrue(!$unConf['ok'] && $unConf['status'] === 409, 'Pre-assigned username taken before acceptance must reject 409');
});

test('Rejects malformed, unknown, expired, and revoked tokens with generic non-enumerating errors', function () use ($fixedNow) {
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    $rMal = $svc->acceptInvitation('bad', 'StrongPass!2026', 'StrongPass!2026');
    $rUnk = $svc->acceptInvitation(EmployeeInvitationService::generateToken(), 'StrongPass!2026', 'StrongPass!2026');
    $rExp = $svc->acceptInvitation($tokens['expired_pending'], 'StrongPass!2026', 'StrongPass!2026');
    $rRev = $svc->acceptInvitation($tokens['revoked'], 'StrongPass!2026', 'StrongPass!2026');
    $rAcc = $svc->acceptInvitation($tokens['accepted'], 'StrongPass!2026', 'StrongPass!2026');

    assertTrue($rMal['status'] === 400 && $rUnk['status'] === 404 && $rExp['status'] === 410 && $rRev['status'] === 409 && $rAcc['status'] === 409, 'Unexpected rejection status codes');
    assertTrue($pdo->invitations[203]['status'] === 'expired', 'Past-due invitation persists expired');
    $msgs = [$rMal['error'], $rUnk['error'], $rExp['error'], $rRev['error'], $rAcc['error']];
    assertTrue(count(array_unique($msgs)) === 1 && !str_contains($msgs[0], '@'), 'Rejection errors must be generic');
});

test('Workflow B (existing_customer): requires matching auth & confirmation and leaves user_account & customer 100% untouched', function () use ($fixedNow) {
    ['pdo' => $pdo, 'tokens' => $tokens] = makeAcceptanceFixture();
    $svc = new EmployeeInvitationService($pdo, null, fn(): int => $fixedNow);

    $display = $svc->validateTokenForAcceptance($tokens['cust_pending']);
    assertTrue($display['ok'] === true && $display['mode'] === 'existing_customer' && (int)$display['existing_user_account_id'] === 20, 'Must detect existing_customer mode');

    // 1. Unauthenticated -> 401 requires_auth
    $unauth = $svc->acceptInvitation($tokens['cust_pending'], 'SpoofedNewPass!99', 'SpoofedNewPass!99', null, true);
    assertTrue(!$unauth['ok'] && $unauth['status'] === 401 && !empty($unauth['requires_auth']), 'Unauthenticated Workflow B must return 401 requires_auth');

    // 2. Wrong authenticated user (#21) -> 403 wrong_account
    $wrong = $svc->acceptInvitation($tokens['cust_pending'], '', '', 21, true);
    assertTrue(!$wrong['ok'] && $wrong['status'] === 403 && !empty($wrong['wrong_account']), 'Wrong signed-in user must return 403 wrong_account');

    // 3. Matching user (#20) without confirmation -> 422
    $noConf = $svc->acceptInvitation($tokens['cust_pending'], '', '', 20, false);
    assertTrue(!$noConf['ok'] && $noConf['status'] === 422, 'Unconfirmed Workflow B must return 422');

    // 4. Stale Branch A form submission (password fields supplied while unauthenticated after signup) -> 401, password untouched
    $origHash = $pdo->users[20]['password_hash'];
    $origCust = $pdo->customers[20];
    $stale = $svc->acceptInvitation($tokens['cust_pending'], 'AttackerOverwrite!2026', 'AttackerOverwrite!2026', null, false);
    assertTrue(!$stale['ok'] && $stale['status'] === 401 && $pdo->users[20]['password_hash'] === $origHash, 'Stale Branch A submit must fail 401 without overwriting password');

    // 5. Authenticated matching user (#20) with confirm_accept = true -> converts without touching user_account or customer
    $ok = $svc->acceptInvitation($tokens['cust_pending'], 'ShouldNeverApply!2026', 'ShouldNeverApply!2026', 20, true);
    assertTrue($ok['ok'] === true && $ok['status'] === 200 && $ok['mode'] === 'existing_customer', 'Confirmed Workflow B acceptance must succeed');
    assertTrue($pdo->userAccountUpdates === 0, 'Workflow B MUST issue ZERO UPDATE statements on user_account (even when username IS NULL)');
    assertTrue($pdo->customerUpdates === 0, 'Workflow B MUST issue ZERO UPDATE statements on customer');
    assertTrue($pdo->users[20]['password_hash'] === $origHash && $pdo->users[20]['username'] === null, 'user_account password & NULL username must stay untouched');
    assertTrue($pdo->customers[20] === $origCust, 'customer record must stay 100% untouched');

    $eid = (int)$ok['employee_id'];
    assertTrue((int)$pdo->employees[$eid]['user_account_id'] === 20 && $pdo->employees[$eid]['position'] === 'Inventory Manager', 'New employee linked to existing user_account_id=20');
    $roles = array_values(array_filter($pdo->accountRoles, fn($ar) => (int)$ar['user_account_id'] === 20 && (int)$ar['business_unit_id'] === 1));
    assertTrue(count($roles) === 1 && (int)$roles[0]['role_id'] === 4, 'Assigned employee role to existing account');
    assertTrue($pdo->invitations[202]['status'] === 'accepted' && (int)$pdo->invitations[202]['user_account_id'] === 20 && (int)$pdo->invitations[202]['employee_id'] === $eid, 'Invitation consumed & linked');
});

test('Unified identity separation: employee_status deactivation keeps customer account active and returnToWorkspace downgrades cleanly', function () {
    ['pdo' => $pdo] = makeAcceptanceFixture();

    // InterfaceSwitcherService loadContext keeps account_active=true when account_status=active & employee_status=inactive
    $ctx = InterfaceSwitcherService::loadContext($pdo, 22);
    assertTrue($ctx['account_active'] === true, 'Deactivated employee must retain active customer account context');
    assertTrue($ctx['employee_active'] === false, 'Deactivated employee must have employee_active=false');

    // Static contract check on AuthController::returnToWorkspace (final controller)
    $authCtrl = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/AuthController.php');
    assertTrue(str_contains($authCtrl, "InterfaceSwitcherService::loadContext(\$this->db, (int)\$uid)") && str_contains($authCtrl, "empty(\$ctx['employee_active'])"), 'returnToWorkspace must verify live employee_active via InterfaceSwitcherService');
    assertTrue(str_contains($authCtrl, "Session::set('active_context', 'customer')"), 'returnToWorkspace must downgrade deactivated employee to customer context');

    // SysAdminController::updateEmployeeStatus updates ONLY employee.employee_status
    $sysCtrl = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');
    assertTrue(!str_contains($sysCtrl, 'UPDATE user_account'), 'SysAdminController must never UPDATE user_account during employee status toggle');
    assertTrue(str_contains($sysCtrl, 'UPDATE employee SET employee_status = ? WHERE employee_id = ?'), 'SysAdminController::updateEmployeeStatus must update employee.employee_status only');

    // layout.php shows Sign Out cleanly and hides mobile Sign in/Sign up for signed-in users
    $layout = (string)file_get_contents(dirname(__DIR__) . '/app/Views/layout.php');
    assertTrue(!str_contains($layout, '!$isSignedIn || $baseContext === \'employee\''), 'Mobile guest links must hide whenever signed in');
    assertTrue(str_contains($layout, 'action="/logout"') && str_contains($layout, 'Log Out'), 'Customer header must offer Log Out form');
});

test('Legacy quarantined invitations retain auth block while accepted, legacy direct, and Super Admin remain unblocked', function () {
    ['pdo' => $pdo] = makeAcceptanceFixture();
    setDatabasePdoForTest($pdo);
    $ctrl = new GuardHarnessController($pdo);

    try {
        assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 16) === true, 'Quarantined unresolved invitee #16 must remain blocked');
        assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 13) === false, 'Accepted invitee #13 unblocked');
        assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 14) === false, 'Legacy unverified #14 unblocked');
        assertTrue(EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($pdo, 1) === false, 'Direct Super Admin #1 unblocked');

        // Core & InterfaceSwitcherService deny quarantined invitee even if is_super_admin=1
        $pdo->users[16]['is_super_admin'] = 1;
        $_SESSION = ['user_id' => 16, 'employee_id' => 16, 'base_context' => 'employee', 'active_context' => 'employee', 'is_super_admin' => true];
        assertTrue($ctrl->callIsSuperAdmin() === false, 'Quarantined invitee cannot pass isSuperAdmin()');
        assertForbidden(fn() => $ctrl->callRequireSuperAdmin(), 'Quarantined invitee must fail requireSuperAdmin()');
        assertForbidden(fn() => $ctrl->callRequireStaff('appointment.view', 1), 'Quarantined invitee must fail requireStaff()');

        // Direct Super Admin #1 succeeds
        $_SESSION = ['user_id' => 1, 'employee_id' => 1, 'base_context' => 'employee', 'active_context' => 'employee', 'is_super_admin' => true];
        assertTrue($ctrl->callIsSuperAdmin() === true && $ctrl->callRequireSuperAdmin() === 1, 'Direct Super Admin passes guards');

        $demo = AuthController::resolveDemoSuperAdminAccount($pdo);
        assertTrue($demo['ok'] === true && (int)$demo['account']['user_account_id'] === 1, 'Demo Super Admin shortcut resolves #1');
    } finally {
        $_SESSION = [];
        setDatabasePdoForTest(null);
    }
});

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
