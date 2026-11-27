<?php
declare(strict_types=1);

/**
 * Isolated Service Tests for Decoupled EmployeeInvitationService.
 *
 * IMPORTANT VERIFICATION LIMITATIONS:
 *  - Uses an in-memory FakeInvitationPdo double (no live MySQL 8.4, no live SMTP, no HTTP server).
 *  - Advisory locks (GET_LOCK/RELEASE_LOCK), generated active-pending uniqueness keys, CHECK
 *    constraints, and UTC_TIMESTAMP() are simulated in memory.
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Models/Units.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';
require_once dirname(__DIR__) . '/app/Models/AdminAccountService.php';

use App\Core\EnvConfig;
use App\Services\EmployeeInvitationService;

final class FakeInvitationPdo extends PDO
{
    public array $users = [];
    public array $employees = [];
    public array $customers = [];
    public array $businessUnits = [];
    public array $positions = [];
    public array $roles = [];
    public array $accountRoles = [];
    public array $invitations = [];
    public array $invitationRoles = [];
    public array $auditLogs = [];

    public int $nextInvitationId = 100;
    public int $nextAuditId = 1;
    public int $lastInsertIdVal = 0;

    public bool $inTx = false;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    public array $lockCallLog = [];
    public array $forUpdateQueries = [];
    public ?string $failOnSqlSubstring = null;

    public array $advisoryLocks = [];
    public array $heldBySelf = [];
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
            'invitations' => $this->invitations,
            'invitationRoles' => $this->invitationRoles,
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
            $this->invitationRoles = $this->snapshot['invitationRoles'];
            $this->auditLogs = $this->snapshot['auditLogs'];
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
        $stmt = new FakeInvitationStatement($this, $query);
        $stmt->execute([]);
        return $stmt;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeInvitationStatement($this, $query);
    }

    public function executeStatement(string $sql, array $params, ?int &$rowCountOut = null): array
    {
        $rowCountOut = 0;

        if ($this->failOnSqlSubstring !== null && str_contains($sql, $this->failOnSqlSubstring)) {
            throw new RuntimeException('Simulated DB write failure.');
        }

        if (stripos($sql, 'FOR UPDATE') !== false) {
            $this->forUpdateQueries[] = $sql;
        }

        if (str_contains($sql, 'SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())')) {
            return [[(string)$this->simulatedNowTs]];
        }

        if (str_contains($sql, 'SELECT GET_LOCK(')) {
            $name = (string)$params[0];
            $this->lockCallLog[] = 'GET:' . $name;
            if (isset($this->advisoryLocks[$name]) && !isset($this->heldBySelf[$name])) {
                return [['0']];
            }
            $this->advisoryLocks[$name] = true;
            $this->heldBySelf[$name] = true;
            return [['1']];
        }
        if (str_contains($sql, 'SELECT RELEASE_LOCK(')) {
            $name = (string)$params[0];
            $this->lockCallLog[] = 'REL:' . $name;
            if (isset($this->heldBySelf[$name])) {
                unset($this->advisoryLocks[$name], $this->heldBySelf[$name]);
                return [['1']];
            }
            return [['0']];
        }

        // Unaccepted invitation block check (isAccountBlockedByUnacceptedInvitation)
        if (str_contains($sql, 'ei_any.user_account_id = ?') && str_contains($sql, 'ei_acc.user_account_id = ?')) {
            $anyUid = (int)$params[0];
            $accUid = (int)$params[1];
            $hasAny = false;
            $hasAccepted = false;
            foreach ($this->invitations as $inv) {
                if ((int)($inv['user_account_id'] ?? 0) === $anyUid) {
                    $hasAny = true;
                }
                if ((int)($inv['user_account_id'] ?? 0) === $accUid && (string)$inv['status'] === 'accepted') {
                    $hasAccepted = true;
                }
            }
            return [[
                'has_any_invitation' => $hasAny ? 1 : 0,
                'has_accepted_invitation' => $hasAccepted ? 1 : 0,
            ]];
        }

        // Actor authorization check
        if (str_contains($sql, 'has_super_role') && str_contains($sql, 'has_unit_sysadmin_role') && str_contains($sql, 'JOIN business_unit bu')) {
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
            $hasUnitSys = false;
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] === $actorUid
                    && (int)$ar['business_unit_id'] === $unitId
                    && ($this->roles[(int)$ar['role_id']] ?? '') === 'SYSTEM_ADMINISTRATOR') {
                    $hasUnitSys = true;
                    break;
                }
            }
            return [[
                'user_account_id' => $ua['user_account_id'],
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'employee_id' => $emp['employee_id'] ?? null,
                'employee_status' => $emp['employee_status'] ?? null,
                'business_unit_id' => $bu['business_unit_id'],
                'business_unit_status' => $bu['business_unit_status'],
                'has_super_role' => $this->userHasSuperRole($actorUid) ? 1 : 0,
                'has_unit_sysadmin_role' => $hasUnitSys ? 1 : 0,
            ]];
        }

        // AdminAccountService::validateEmployeeProvisioningInput queries
        if (str_contains($sql, 'FROM business_unit') && str_contains($sql, 'WHERE business_unit_id = ?')) {
            $bu = $this->businessUnits[(int)$params[0]] ?? null;
            if (!$bu) {
                return [];
            }
            if (str_contains($sql, "business_unit_status = 'active'") && $bu['business_unit_status'] !== 'active') {
                return [];
            }
            return [$bu];
        }
        if (str_contains($sql, 'SELECT position_id, position_name FROM business_position')) {
            foreach ($this->positions as $p) {
                if ((int)$p['business_unit_id'] === (int)$params[0] && $p['position_name'] === (string)$params[1] && $p['position_status'] === 'active') {
                    return [['position_id' => $p['position_id'], 'position_name' => $p['position_name']]];
                }
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
        if (str_contains($sql, 'SELECT position_id') && str_contains($sql, 'FROM business_position')) {
            foreach ($this->positions as $p) {
                if ((int)$p['business_unit_id'] === (int)$params[0] && $p['position_name'] === (string)$params[1] && $p['position_status'] === 'active') {
                    return [[(string)$p['position_id']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM role') && !str_contains($sql, 'account_role') && !str_contains($sql, 'employee_invitation_role')) {
            $rows = [];
            foreach ($this->roles as $id => $rName) {
                if (str_contains($sql, "<> 'SUPER_ADMINISTRATOR'") && $rName === 'SUPER_ADMINISTRATOR') {
                    continue;
                }
                $rows[] = ['role_id' => $id, 'role_name' => $rName, 'description' => $rName];
            }
            return $rows;
        }

        // evaluateEmailEligibility queries
        if (str_contains($sql, 'has_super_role') && str_contains($sql, 'FROM user_account ua') && str_contains($sql, 'WHERE ua.email = ?')) {
            $targetEmail = strtolower(trim((string)$params[0]));
            foreach ($this->users as $uid => $ua) {
                if (strtolower(trim((string)$ua['email'])) === $targetEmail) {
                    return [[
                        'user_account_id' => $ua['user_account_id'],
                        'email' => $ua['email'],
                        'username' => $ua['username'] ?? null,
                        'account_status' => $ua['account_status'],
                        'is_super_admin' => $ua['is_super_admin'],
                        'email_verified_at' => $ua['email_verified_at'],
                        'has_super_role' => $this->userHasSuperRole((int)$uid) ? 1 : 0,
                    ]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'SELECT user_account_id FROM user_account WHERE username = ?')) {
            $u = strtolower(trim((string)$params[0]));
            foreach ($this->users as $ua) {
                if (($ua['username'] ?? null) !== null && strtolower(trim((string)$ua['username'])) === $u) {
                    return [[(string)$ua['user_account_id']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'SELECT employee_id FROM employee WHERE user_account_id = ?')) {
            $uid = (int)$params[0];
            foreach ($this->employees as $e) {
                if ((int)$e['user_account_id'] === $uid) {
                    return [[(string)$e['employee_id']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'SELECT customer_id, customer_status FROM customer WHERE user_account_id = ?')) {
            $uid = (int)$params[0];
            foreach ($this->customers as $c) {
                if ((int)$c['user_account_id'] === $uid) {
                    return [$c];
                }
            }
            return [];
        }

        // Lock invitations by email or username
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE invited_email = ?')) {
            $em = strtolower(trim((string)$params[0]));
            $rows = [];
            foreach ($this->invitations as $inv) {
                if (strtolower(trim((string)$inv['invited_email'])) === $em) {
                    $rows[] = $inv;
                }
            }
            if (str_contains($sql, 'ORDER BY invitation_id DESC')) {
                usort($rows, fn($a, $b) => $b['invitation_id'] <=> $a['invitation_id']);
            } else {
                usort($rows, fn($a, $b) => $a['invitation_id'] <=> $b['invitation_id']);
            }
            return $rows;
        }
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE username = ?')) {
            $un = strtolower(trim((string)$params[0]));
            $rows = [];
            foreach ($this->invitations as $inv) {
                if (($inv['username'] ?? null) !== null
                    && strtolower(trim((string)$inv['username'])) === $un
                    && $inv['status'] === 'pending') {
                    $rows[] = $inv;
                }
            }
            return $rows;
        }

        // Stub / Lock by invitation_id
        if (str_contains($sql, 'SELECT invitation_id, business_unit_id, invited_email') && str_contains($sql, 'FROM employee_invitation')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            return $inv ? [$inv] : [];
        }
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE invitation_id = ?') && str_contains($sql, 'FOR UPDATE')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            return $inv ? [$inv] : [];
        }
        if (str_contains($sql, 'SELECT status, token_hash, email_submission_status') && str_contains($sql, 'FROM employee_invitation')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            return $inv ? [[
                'status' => $inv['status'],
                'token_hash' => $inv['token_hash'],
                'email_submission_status' => $inv['email_submission_status'],
            ]] : [];
        }

        // validateInvitationForEmailSubmission joined read
        if (str_contains($sql, 'FROM employee_invitation ei') && str_contains($sql, 'WHERE ei.invitation_id = ?')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            if (!$inv) {
                return [];
            }
            $bu = $this->businessUnits[(int)$inv['business_unit_id']] ?? null;
            if (!$bu) {
                return [];
            }
            return [$inv + [
                'business_unit_name' => $bu['business_unit_name'],
                'business_unit_status' => $bu['business_unit_status'],
            ]];
        }

        // Invitation role queries
        if (str_contains($sql, 'FROM employee_invitation_role eir')) {
            $iid = (int)$params[0];
            $rows = [];
            foreach ($this->invitationRoles as $ir) {
                if ((int)$ir['invitation_id'] === $iid) {
                    $rid = (int)$ir['role_id'];
                    $rows[] = ['role_id' => $rid, 'role_name' => $this->roles[$rid] ?? ''];
                }
            }
            usort($rows, fn($a, $b) => $a['role_id'] <=> $b['role_id']);
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

        // INSERT INTO employee_invitation
        if (str_contains($sql, 'INSERT INTO employee_invitation (')) {
            [
                $uid, $eid, $unitId, $email, $firstName, $lastName, $username, $phone,
                $posId, $posName, $tokenHash, $expiresAt, $replacedId, $actorUid, $createdAt, $updatedAt
            ] = $params;
            if (strlen((string)$tokenHash) !== 64) {
                throw new RuntimeException('CHECK chk_emp_inv_token_hash_len failed.');
            }
            if ($uid !== null || $eid !== null) {
                throw new RuntimeException('Decoupled invitation must insert NULL user_account_id and employee_id.');
            }
            // Enforce generated column unique keys for active pending
            foreach ($this->invitations as $existing) {
                if ($existing['status'] === 'pending') {
                    if (strtolower((string)$existing['invited_email']) === strtolower((string)$email)) {
                        throw new RuntimeException('UNIQUE uk_emp_inv_active_pending_email violated.');
                    }
                    if ($username !== null && $existing['username'] !== null
                        && strtolower((string)$existing['username']) === strtolower((string)$username)) {
                        throw new RuntimeException('UNIQUE uk_emp_inv_active_pending_username violated.');
                    }
                }
            }
            $id = $this->nextInvitationId++;
            $this->invitations[$id] = [
                'invitation_id' => $id,
                'user_account_id' => null,
                'employee_id' => null,
                'business_unit_id' => (int)$unitId,
                'invited_email' => (string)$email,
                'first_name' => (string)$firstName,
                'last_name' => (string)$lastName,
                'username' => $username,
                'phone' => $phone,
                'position_id' => $posId,
                'position_name' => (string)$posName,
                'token_hash' => (string)$tokenHash,
                'status' => 'pending',
                'expires_at' => (string)$expiresAt,
                'accepted_at' => null,
                'revoked_at' => null,
                'revoke_reason' => null,
                'replaced_invitation_id' => $replacedId,
                'email_submission_status' => 'pending',
                'email_submitted_at' => null,
                'email_failed_at' => null,
                'email_error' => null,
                'created_by_user_id' => (int)$actorUid,
                'is_legacy_quarantined' => 0,
                'created_at' => (string)$createdAt,
                'updated_at' => (string)$updatedAt,
            ];
            $this->lastInsertIdVal = $id;
            $rowCountOut = 1;
            return [];
        }

        if (str_contains($sql, 'INSERT INTO employee_invitation_role')) {
            $this->invitationRoles[] = [
                'invitation_id' => (int)$params[0],
                'role_id' => (int)$params[1],
            ];
            $rowCountOut = 1;
            return [];
        }

        if (str_contains($sql, "SET status = 'revoked'")) {
            [$revokedAt, $reason, $updatedAt, $id] = $params;
            $id = (int)$id;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                $this->invitations[$id]['status'] = 'revoked';
                $this->invitations[$id]['revoked_at'] = (string)$revokedAt;
                $this->invitations[$id]['revoke_reason'] = (string)$reason;
                $this->invitations[$id]['updated_at'] = (string)$updatedAt;
                $rowCountOut = 1;
            }
            return [];
        }

        if (str_contains($sql, "SET status = 'expired'")) {
            [$updatedAt, $id] = $params;
            $id = (int)$id;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                $this->invitations[$id]['status'] = 'expired';
                $this->invitations[$id]['updated_at'] = (string)$updatedAt;
                $rowCountOut = 1;
            }
            return [];
        }

        if (str_contains($sql, "SET email_submission_status = 'submitted'")) {
            [$submittedAt, $updatedAt, $id] = [$params[0], $params[1], (int)$params[2]];
            $expectedHash = $params[3] ?? null;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                if ($expectedHash === null || hash_equals($this->invitations[$id]['token_hash'], (string)$expectedHash)) {
                    $inv = &$this->invitations[$id];
                    $inv['email_submission_status'] = 'submitted';
                    $inv['email_submitted_at'] = (string)$submittedAt;
                    $inv['email_failed_at'] = null;
                    $inv['email_error'] = null;
                    $inv['updated_at'] = (string)$updatedAt;
                    $rowCountOut = 1;
                }
            }
            return [];
        }

        if (str_contains($sql, "SET email_submission_status = 'failed'")) {
            [$failedAt, $err, $updatedAt, $id] = [$params[0], $params[1], $params[2], (int)$params[3]];
            $expectedHash = $params[4] ?? null;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                if ($expectedHash === null || hash_equals($this->invitations[$id]['token_hash'], (string)$expectedHash)) {
                    $inv = &$this->invitations[$id];
                    $inv['email_submission_status'] = 'failed';
                    $inv['email_failed_at'] = (string)$failedAt;
                    $inv['email_submitted_at'] = null;
                    $inv['email_error'] = (string)$err;
                    $inv['updated_at'] = (string)$updatedAt;
                    $rowCountOut = 1;
                }
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
                'ip_address' => $params[9],
            ];
            $this->lastInsertIdVal = $id;
            $rowCountOut = 1;
            return [];
        }

        throw new RuntimeException("Unexpected SQL in FakeInvitationPdo: {$sql}");
    }

    private function userHasSuperRole(int $uid): bool
    {
        foreach ($this->accountRoles as $ar) {
            if ((int)$ar['user_account_id'] === $uid && ($this->roles[(int)$ar['role_id']] ?? '') === 'SUPER_ADMINISTRATOR') {
                return true;
            }
        }
        return false;
    }
}

final class FakeInvitationStatement extends PDOStatement
{
    private array $rows = [];
    private int $cursor = 0;
    private int $affected = 0;

    public function __construct(private FakeInvitationPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $affected = 0;
        $this->rows = $this->pdo->executeStatement($this->sql, $params ?? [], $affected);
        $this->affected = $affected;
        $this->cursor = 0;
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->cursor++] ?? false;
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

function makeFixturePdo(): FakeInvitationPdo
{
    $pdo = new FakeInvitationPdo();
    $pdo->businessUnits = [
        1 => ['business_unit_id' => 1, 'business_unit_name' => 'Pet Store', 'business_unit_status' => 'active'],
        2 => ['business_unit_id' => 2, 'business_unit_name' => 'Veterinary Clinic', 'business_unit_status' => 'active'],
        3 => ['business_unit_id' => 3, 'business_unit_name' => 'Grooming Studio', 'business_unit_status' => 'inactive'],
    ];
    $pdo->positions = [
        ['position_id' => 11, 'business_unit_id' => 1, 'position_name' => 'Receptionist', 'position_status' => 'active'],
        ['position_id' => 21, 'business_unit_id' => 2, 'position_name' => 'Veterinary Assistant', 'position_status' => 'active'],
    ];
    $pdo->roles = [
        1 => 'SUPER_ADMINISTRATOR',
        2 => 'SYSTEM_ADMINISTRATOR',
        3 => 'BUSINESS_MANAGER',
        4 => 'RECEPTIONIST',
    ];
    $pdo->users = [
        1 => ['user_account_id' => 1, 'email' => 'super@pawcarino.test', 'username' => 'super1', 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        2 => ['user_account_id' => 2, 'email' => 'sysadmin.pet@pawcarino.test', 'username' => 'syspet', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        3 => ['user_account_id' => 3, 'email' => 'sysadmin.vet@pawcarino.test', 'username' => 'sysvet', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        4 => ['user_account_id' => 4, 'email' => 'manager.pet@pawcarino.test', 'username' => 'mgrpet', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        5 => ['user_account_id' => 5, 'email' => 'super.role@pawcarino.test', 'username' => 'superrole', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        6 => ['user_account_id' => 6, 'email' => 'super.noemp@pawcarino.test', 'username' => 'supernoemp', 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        7 => ['user_account_id' => 7, 'email' => 'super.inactive@pawcarino.test', 'username' => 'superinact', 'account_status' => 'inactive', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        20 => ['user_account_id' => 20, 'email' => 'active.customer@example.com', 'username' => 'cust20', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-06-01 00:00:00'],
        21 => ['user_account_id' => 21, 'email' => 'inactive.customer@example.com', 'username' => null, 'account_status' => 'inactive', 'is_super_admin' => 0, 'email_verified_at' => '2026-06-01 00:00:00'],
        22 => ['user_account_id' => 22, 'email' => 'suspended.account@example.com', 'username' => 'susp22', 'account_status' => 'suspended', 'is_super_admin' => 0, 'email_verified_at' => '2026-06-01 00:00:00'],
    ];
    $pdo->employees = [
        1 => ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Super', 'last_name' => 'Admin', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        2 => ['employee_id' => 2, 'user_account_id' => 2, 'business_unit_id' => 1, 'first_name' => 'Pet', 'last_name' => 'SysAdmin', 'position' => 'System Administrator', 'employee_status' => 'active'],
        3 => ['employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 2, 'first_name' => 'Vet', 'last_name' => 'SysAdmin', 'position' => 'System Administrator', 'employee_status' => 'active'],
        4 => ['employee_id' => 4, 'user_account_id' => 4, 'business_unit_id' => 1, 'first_name' => 'Pet', 'last_name' => 'Manager', 'position' => 'Business Manager', 'employee_status' => 'active'],
        5 => ['employee_id' => 5, 'user_account_id' => 5, 'business_unit_id' => 1, 'first_name' => 'Role', 'last_name' => 'Super', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        7 => ['employee_id' => 7, 'user_account_id' => 7, 'business_unit_id' => 1, 'first_name' => 'Inactive', 'last_name' => 'Super', 'position' => 'Super Administrator', 'employee_status' => 'active'],
    ];
    $pdo->customers = [
        20 => ['customer_id' => 20, 'user_account_id' => 20, 'customer_status' => 'active'],
        21 => ['customer_id' => 21, 'user_account_id' => 21, 'customer_status' => 'inactive'],
        22 => ['customer_id' => 22, 'user_account_id' => 22, 'customer_status' => 'active'],
    ];
    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 2, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 3, 'role_id' => 2, 'business_unit_id' => 2],
        ['user_account_id' => 4, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 5, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 7, 'role_id' => 1, 'business_unit_id' => 1],
    ];
    return $pdo;
}

$tempEnv = tempnam(sys_get_temp_dir(), 'pawcarino-inv-dec-');
foreach (['APP_URL', 'MAIL_DRIVER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS'] as $k) {
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
    echo "=== Decoupled EmployeeInvitationService Unit & FakePDO Tests ===\n\n";

    test('Token helpers and APP_URL trust boundary remain strict', function () {
        $t1 = EmployeeInvitationService::generateToken();
        $t2 = EmployeeInvitationService::generateToken();
        assertTrue(EmployeeInvitationService::isValidRawTokenFormat($t1) && $t1 !== $t2, 'Tokens must be distinct 64-char hex');
        assertTrue(EmployeeInvitationService::hashToken($t1) === hash('sha256', $t1), 'hashToken mismatch');
        $_SERVER['HTTP_HOST'] = 'evil.example';
        $url = EmployeeInvitationService::buildAcceptanceUrl($t1);
        assertTrue($url === "https://portal.pawcarino.test/invitations/accept?token={$t1}", 'Must ignore HTTP_HOST');
    });

    test('createInvitationForUnit stores independent invitation + roles without creating user_account, employee, or customer', function () {
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $capturedTokens = [];
        $svc = new EmployeeInvitationService(
            $pdo,
            function (string $email, string $name, string $unit, string $pos, string $url) use (&$capturedTokens): array {
                parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
                $capturedTokens[] = (string)($q['token'] ?? '');
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            },
            function () use (&$now): int { return $now; }
        );

        $beforeUsers = count($pdo->users);
        $beforeEmps = count($pdo->employees);
        $beforeCusts = count($pdo->customers);
        $beforeRoles = count($pdo->accountRoles);

        $res = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => '  Carla   Mendoza ',
            'email' => '  Carla.Mendoza@PawCarino.Test ',
            'username' => 'CarlaM',
            'phone' => '09171234567',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);

        assertTrue($res['ok'] === true && $res['status'] === 201 && $res['email_submitted'] === true, 'createInvitationForUnit must succeed');
        assertTrue(!isset($res['raw_token']), 'Must never return raw token');
        assertTrue(count($pdo->users) === $beforeUsers, 'Zero user_account rows created before acceptance');
        assertTrue(count($pdo->employees) === $beforeEmps, 'Zero employee rows created before acceptance');
        assertTrue(count($pdo->customers) === $beforeCusts, 'Zero customer rows created before acceptance');
        assertTrue(count($pdo->accountRoles) === $beforeRoles, 'Zero account_role rows created before acceptance');

        $invId = (int)$res['invitation_id'];
        $inv = $pdo->invitations[$invId];
        assertTrue($inv['user_account_id'] === null && $inv['employee_id'] === null, 'FK links must be NULL');
        assertTrue($inv['invited_email'] === 'carla.mendoza@pawcarino.test', 'Normalized lowercase email');
        assertTrue($inv['first_name'] === 'Carla' && $inv['last_name'] === 'Mendoza', 'Split first/last name persisted');
        assertTrue($inv['username'] === 'carlam', 'Normalized lowercase username persisted');
        assertTrue((int)$inv['position_id'] === 11 && $inv['position_name'] === 'Receptionist', 'Position ID and snapshot persisted');
        assertTrue($inv['token_hash'] === EmployeeInvitationService::hashToken($capturedTokens[0]), 'Hash matches sent token');

        $invRoles = array_values(array_filter($pdo->invitationRoles, fn($r) => (int)$r['invitation_id'] === $invId));
        assertTrue(count($invRoles) === 1 && (int)$invRoles[0]['role_id'] === 4, 'Intended role stored in employee_invitation_role');

        // Verify canonical lock ordering: pc_em_<md5> before pc_un_<md5>
        $getLocks = array_values(array_filter($pdo->lockCallLog, fn($x) => str_starts_with($x, 'GET:')));
        assertTrue(str_starts_with($getLocks[0], 'GET:pc_em_') && str_starts_with($getLocks[1], 'GET:pc_un_'), 'Must acquire pc_em_ before pc_un_');
    });

    test('Super Administrators (flag, role-only, no employee row) succeed across active units; unauthorized actors get 403', function () {
        foreach ([1, 5, 6] as $saId) {
            $pdo = makeFixturePdo();
            $svc = new EmployeeInvitationService($pdo, fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]);
            $res = $svc->createInvitationForUnit($saId, 2, [
                'business_unit_id' => 2,
                'full_name' => 'Marco Reyes',
                'email' => "marco.{$saId}@pawcarino.test",
                'position' => 'Veterinary Assistant',
                'roles' => ['RECEPTIONIST'],
            ]);
            assertTrue($res['ok'] === true && $res['status'] === 201, "Super Admin #{$saId} should succeed in unit 2");
        }

        // Denied: inactive Super Admin (#7), inactive unit (3), cross-unit SysAdmin (#3->1), manager (#4->1)
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo, fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]);
        foreach ([[7, 1], [1, 3], [3, 1], [4, 1]] as [$actor, $unit]) {
            $den = $svc->createInvitationForUnit($actor, $unit, [
                'business_unit_id' => $unit,
                'full_name' => 'Denied Target',
                'email' => "denied.{$actor}.{$unit}@pawcarino.test",
                'position' => 'Receptionist',
                'roles' => ['RECEPTIONIST'],
            ]);
            assertTrue(!$den['ok'] && $den['status'] === 403, "Actor #{$actor} on unit {$unit} must return 403");
        }
    });

    test('Email and username uniqueness allow existing active customer without username, block existing customer with username, and release on revoke/expire', function () {
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200],
            function () use (&$now): int { return $now; }
        );

        // Existing customer-only WITH username supplied -> 422 on username
        $custWithUser = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Existing Cust',
            'email' => 'active.customer@example.com',
            'username' => 'newuser99',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue(!$custWithUser['ok'] && $custWithUser['status'] === 422 && isset($custWithUser['errors']['username']), 'Existing customer with username must fail 422 on username');

        // Existing customer-only WITHOUT username -> 201 (Workflow B invite)
        $custNoUser = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Existing Cust',
            'email' => 'ACTIVE.CUSTOMER@example.com',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue($custNoUser['ok'] === true && $custNoUser['status'] === 201 && ($custNoUser['recipient_mode'] ?? '') === 'existing_customer', 'Existing active customer without username must succeed');

        // Existing employee email -> 422 on email
        $empDup = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Existing Emp',
            'email' => 'sysadmin.pet@pawcarino.test',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue(!$empDup['ok'] && isset($empDup['errors']['email']), 'Existing employee email must fail');

        // Suspended account email -> 409 / error
        $susp = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Susp Emp',
            'email' => 'suspended.account@example.com',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue(!$susp['ok'], 'Suspended account email must be rejected');

        // Active pending invite blocks duplicate email and duplicate username, then revoke frees both!
        $p1 = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Release Test',
            'email' => 'release.test@pawcarino.test',
            'username' => 'releaseme',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue($p1['ok'] === true, 'Initial invite succeeds');
        $inv1 = (int)$p1['invitation_id'];

        $dupEmail = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Dup Email',
            'email' => 'release.test@pawcarino.test',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue(!$dupEmail['ok'] && isset($dupEmail['errors']['email']), 'Active pending email blocks duplicate create');

        $dupUser = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Dup User',
            'email' => 'other.person@pawcarino.test',
            'username' => 'releaseme',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue(!$dupUser['ok'] && isset($dupUser['errors']['username']), 'Active pending username blocks another invite');

        // Revoke frees both email and username for a fresh invite
        $rev = $svc->revokeInvitation(2, $inv1, 1);
        assertTrue($rev['ok'] === true, 'Revoke by invitation_id succeeds');

        $p2 = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Fresh After Revoke',
            'email' => 'release.test@pawcarino.test',
            'username' => 'releaseme',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue($p2['ok'] === true && (int)$p2['invitation_id'] !== $inv1, 'Revoked invite does not block fresh invite for same email/username');
        assertTrue($pdo->invitations[(int)$p2['invitation_id']]['replaced_invitation_id'] === null, 'Fresh create does not link replaced_invitation_id');

        // Past-due expiration auto-expires inside createInvitationForUnit and frees email & username
        $now += EmployeeInvitationService::EXPIRY_SECONDS + 5;
        $p3 = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Fresh After Expire',
            'email' => 'release.test@pawcarino.test',
            'username' => 'releaseme',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue($p3['ok'] === true, 'Expired pending invite auto-expires and frees email/username');
        assertTrue($pdo->invitations[(int)$p2['invitation_id']]['status'] === 'expired', 'Prior past-due invite transitioned to expired');
    });

    test('resendInvitation & revokeInvitation key off invitation_id, enforce unit scope, preserve roles, and retry failed SMTP', function () {
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $attempt = 0;
        $svc = new EmployeeInvitationService(
            $pdo,
            function (string $e, string $n, string $u, string $p, string $url) use (&$attempt): array {
                $attempt++;
                if ($attempt === 1) {
                    return ['submitted' => false, 'driver' => 'smtp', 'error' => "SMTP error {$url}", 'status_code' => 502];
                }
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            },
            function () use (&$now): int { return $now; }
        );

        $c = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Retry Invitee',
            'email' => 'retry.invitee@pawcarino.test',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue($c['ok'] === true && $c['email_submitted'] === false && $c['email_submission_status'] === 'failed', 'Initial SMTP fail recorded safely');
        assertTrue(!str_contains((string)$c['email_error'], 'token='), 'Error message must scrub URL/token');
        $inv1 = (int)$c['invitation_id'];

        // Missing ID -> 404; Wrong unit -> 403
        assertTrue($svc->resendInvitation(2, 999999, 1)['status'] === 404, 'Missing invitation must return 404');
        assertTrue($svc->resendInvitation(3, $inv1, 2)['status'] === 403, 'Wrong unit resend must return 403');
        assertTrue($svc->revokeInvitation(3, $inv1, 2)['status'] === 403, 'Wrong unit revoke must return 403');

        // Resend by invitation_id creates replacement with copied roles and replaced_invitation_id
        $now += 60;
        $rs = $svc->resendInvitation(2, $inv1, 1);
        assertTrue($rs['ok'] === true && $rs['status'] === 200 && $rs['email_submitted'] === true, 'Resend by invitation_id succeeds');
        $inv2 = (int)$rs['invitation_id'];
        assertTrue($pdo->invitations[$inv1]['status'] === 'revoked' && $pdo->invitations[$inv1]['revoke_reason'] === 'resent', 'Prior invite revoked as resent');
        assertTrue((int)$pdo->invitations[$inv2]['replaced_invitation_id'] === $inv1, 'Replacement links replaced_invitation_id');

        // Past-due pending revoke transitions to expired and rejects 409; resend then succeeds
        $now += EmployeeInvitationService::EXPIRY_SECONDS + 10;
        $rvExp = $svc->revokeInvitation(1, $inv2, 1);
        assertTrue(!$rvExp['ok'] && $rvExp['status'] === 409 && $pdo->invitations[$inv2]['status'] === 'expired', 'Past-due revoke transitions to expired');
        $rsExp = $svc->resendInvitation(1, $inv2, 1);
        assertTrue($rsExp['ok'] === true && $pdo->invitations[(int)$rsExp['invitation_id']]['status'] === 'pending', 'Expired invite can still be resent');
    });

    test('DB error during createInvitationForUnit rolls back cleanly without email submission', function () {
        $pdo = makeFixturePdo();
        $pdo->failOnSqlSubstring = 'INSERT INTO employee_invitation_role';
        $mailCalled = false;
        $svc = new EmployeeInvitationService($pdo, function () use (&$mailCalled) {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $res = $svc->createInvitationForUnit(2, 1, [
            'business_unit_id' => 1,
            'full_name' => 'Rollback Test',
            'email' => 'rollback.unit@pawcarino.test',
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ]);
        assertTrue(!$res['ok'] && $res['status'] === 500 && $pdo->rollbackCount === 1 && !$pdo->inTransaction(), 'Must roll back cleanly');
        assertTrue(count($pdo->invitations) === 0 && !$mailCalled, 'No partial rows or mail after rollback');
    });
} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempEnv);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
