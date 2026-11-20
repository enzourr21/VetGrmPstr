<?php
declare(strict_types=1);

/**
 * Isolated Service Tests for EmployeeInvitationService (Checkpoint 2.2).
 *
 * IMPORTANT VERIFICATION LIMITATIONS:
 *  - These are isolated unit tests and stateful in-memory FakePDO tests.
 *  - They DO NOT connect to MySQL 8.4, execute Migration 029, start the HTTP app, or contact SMTP.
 *  - Advisory locks (GET_LOCK/RELEASE_LOCK), CHECK constraints, foreign keys, InnoDB FOR UPDATE
 *    lock behavior, and UTC_TIMESTAMP() are simulated in memory and MUST still be verified against
 *    an isolated disposable MySQL 8.4 instance before controller integration.
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';

use App\Core\EnvConfig;
use App\Services\EmployeeInvitationService;

/**
 * Stateful in-memory PDO double supporting transactions (commit/rollback snapshots),
 * FOR UPDATE lock-order tracking, advisory GET_LOCK simulation, and exact queries issued
 * by EmployeeInvitationService + Audit::record().
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

    /** Simulated advisory locks currently held across connections. */
    public array $advisoryLocks = [];
    public array $heldBySelf = [];
    public bool $simulateMysqlClock = true;
    public int $simulatedNowTs = 1795176000; // 2026-11-20 12:00:00 UTC

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

        // 0. MySQL UTC_TIMESTAMP() query
        if (str_contains($sql, 'SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())')) {
            return [[(string)$this->simulatedNowTs]];
        }

        // 0b. MySQL GET_LOCK / RELEASE_LOCK simulation
        if (str_contains($sql, 'SELECT GET_LOCK(')) {
            $name = (string)$params[0];
            if (isset($this->advisoryLocks[$name]) && !isset($this->heldBySelf[$name])) {
                return [['0']];
            }
            $this->advisoryLocks[$name] = true;
            $this->heldBySelf[$name] = true;
            return [['1']];
        }
        if (str_contains($sql, 'SELECT RELEASE_LOCK(')) {
            $name = (string)$params[0];
            if (isset($this->heldBySelf[$name])) {
                unset($this->advisoryLocks[$name], $this->heldBySelf[$name]);
                return [['1']];
            }
            return [['0']];
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
            $hasSuperRole = $this->userHasSuperRole($actorUid);
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] !== $actorUid) {
                    continue;
                }
                $rName = $this->roles[(int)$ar['role_id']] ?? '';
                if ($rName === 'SYSTEM_ADMINISTRATOR' && (int)$ar['business_unit_id'] === $unitId) {
                    $hasSysAdminInUnit = true;
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

        // 2a. Non-locking employee user_account_id lookup
        if (str_contains($sql, 'SELECT user_account_id') && str_contains($sql, 'FROM employee') && !str_contains($sql, 'FOR UPDATE')) {
            $eid = (int)$params[0];
            $emp = $this->employees[$eid] ?? null;
            if (!$emp) {
                return [];
            }
            return [[(string)$emp['user_account_id'], 'user_account_id' => $emp['user_account_id']]];
        }

        // 2b. Lock user_account (with cross-unit SUPER_ADMINISTRATOR check)
        if (str_contains($sql, 'FROM user_account ua') && str_contains($sql, 'FOR UPDATE')) {
            $uid = (int)$params[0];
            $ua = $this->users[$uid] ?? null;
            if (!$ua) {
                return [];
            }
            return [[
                'user_account_id' => $ua['user_account_id'],
                'email' => $ua['email'],
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'email_verified_at' => $ua['email_verified_at'],
                'has_super_role' => $this->userHasSuperRole($uid) ? 1 : 0,
            ]];
        }

        // 2c. Lock employee
        if (str_contains($sql, 'FROM employee e') && str_contains($sql, 'WHERE e.employee_id = ?') && str_contains($sql, 'FOR UPDATE')) {
            $eid = (int)$params[0];
            $emp = $this->employees[$eid] ?? null;
            if (!$emp) {
                return [];
            }
            return [$emp];
        }

        // 2d. Non-locking business_unit lookup
        if (str_contains($sql, 'FROM business_unit') && str_contains($sql, 'WHERE business_unit_id = ?')) {
            $buId = (int)$params[0];
            $bu = $this->businessUnits[$buId] ?? null;
            return $bu ? [$bu] : [];
        }

        // 3a. Pre-send validation read on employee_invitation
        if (str_contains($sql, 'FROM employee_invitation ei') && str_contains($sql, 'WHERE ei.invitation_id = ?')) {
            $invId = (int)$params[0];
            $inv = $this->invitations[$invId] ?? null;
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
                'has_super_role' => $this->userHasSuperRole((int)$ua['user_account_id']) ? 1 : 0,
            ]];
        }

        // 3b. Lock pending invitations for employee
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

        // 3c. Lock all invitations for employee (newest-first)
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'ORDER BY invitation_id DESC')) {
            $eid = (int)$params[0];
            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)$inv['employee_id'] === $eid) {
                    $rows[] = [
                        'invitation_id' => $inv['invitation_id'],
                        'status' => $inv['status'],
                        'expires_at' => $inv['expires_at'],
                    ];
                }
            }
            usort($rows, fn($a, $b) => $b['invitation_id'] <=> $a['invitation_id']);
            return $rows;
        }

        // 3d. Status/token lookup after 0-row outcome update
        if (str_contains($sql, 'SELECT status, token_hash, email_submission_status') && str_contains($sql, 'FROM employee_invitation')) {
            $invId = (int)$params[0];
            $inv = $this->invitations[$invId] ?? null;
            if (!$inv) {
                return [];
            }
            return [[
                'status' => $inv['status'],
                'token_hash' => $inv['token_hash'],
                'email_submission_status' => $inv['email_submission_status'],
            ]];
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
            $rowCountOut = 1;
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
                $rowCountOut = 1;
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
                $rowCountOut = 1;
            }
            return [];
        }

        // 7. UPDATE employee_invitation -> email_submission_status = 'submitted' (conditional on status = 'pending')
        if (str_contains($sql, "SET email_submission_status = 'submitted'")) {
            $submittedAt = (string)$params[0];
            $updatedAt = (string)$params[1];
            $id = (int)$params[2];
            $expectedHash = $params[3] ?? null;

            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                if ($expectedHash === null || hash_equals($this->invitations[$id]['token_hash'], (string)$expectedHash)) {
                    $inv = &$this->invitations[$id];
                    $changed = ($inv['email_submission_status'] !== 'submitted'
                        || $inv['email_submitted_at'] !== $submittedAt
                        || $inv['email_failed_at'] !== null
                        || $inv['email_error'] !== null
                        || $inv['updated_at'] !== $updatedAt);
                    $inv['email_submission_status'] = 'submitted';
                    $inv['email_submitted_at'] = $submittedAt;
                    $inv['email_failed_at'] = null;
                    $inv['email_error'] = null;
                    $inv['updated_at'] = $updatedAt;
                    $this->assertMigrationEmailCheck($inv);
                    $rowCountOut = $changed ? 1 : 0;
                }
            }
            return [];
        }

        // 8. UPDATE employee_invitation -> email_submission_status = 'failed' (conditional on status = 'pending')
        if (str_contains($sql, "SET email_submission_status = 'failed'")) {
            $failedAt = (string)$params[0];
            $error = (string)$params[1];
            $updatedAt = (string)$params[2];
            $id = (int)$params[3];
            $expectedHash = $params[4] ?? null;

            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                if ($expectedHash === null || hash_equals($this->invitations[$id]['token_hash'], (string)$expectedHash)) {
                    $inv = &$this->invitations[$id];
                    $changed = ($inv['email_submission_status'] !== 'failed'
                        || $inv['email_failed_at'] !== $failedAt
                        || $inv['email_submitted_at'] !== null
                        || $inv['email_error'] !== $error
                        || $inv['updated_at'] !== $updatedAt);
                    $inv['email_submission_status'] = 'failed';
                    $inv['email_failed_at'] = $failedAt;
                    $inv['email_submitted_at'] = null;
                    $inv['email_error'] = $error;
                    $inv['updated_at'] = $updatedAt;
                    $this->assertMigrationEmailCheck($inv);
                    $rowCountOut = $changed ? 1 : 0;
                }
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
            $rowCountOut = 1;
            return [];
        }

        throw new RuntimeException("Unexpected SQL in FakeInvitationPdo: {$sql}");
    }

    private function userHasSuperRole(int $uid): bool
    {
        foreach ($this->accountRoles as $ar) {
            if ((int)$ar['user_account_id'] !== $uid) {
                continue;
            }
            if (($this->roles[(int)$ar['role_id']] ?? '') === 'SUPER_ADMINISTRATOR') {
                return true;
            }
        }
        return false;
    }

    private function assertMigrationEmailCheck(array $inv): void
    {
        $status = $inv['email_submission_status'];
        if ($status === 'submitted' && $inv['email_submitted_at'] === null) {
            throw new RuntimeException('CHECK constraint chk_emp_inv_email_ts failed (submitted without timestamp).');
        }
        if ($status === 'failed' && $inv['email_failed_at'] === null) {
            throw new RuntimeException('CHECK constraint chk_emp_inv_email_ts failed (failed without timestamp).');
        }
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

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function rowCount(): int
    {
        return $this->affected;
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
        // 1: Super Administrator via flag (even if also assigned SYSTEM_ADMINISTRATOR, must be denied)
        1 => ['user_account_id' => 1, 'email' => 'super@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 2: Pet Store System Administrator
        2 => ['user_account_id' => 2, 'email' => 'sysadmin.pet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 3: Vet Clinic System Administrator
        3 => ['user_account_id' => 3, 'email' => 'sysadmin.vet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 4: Pet Store Business Manager (non-SysAdmin)
        4 => ['user_account_id' => 4, 'email' => 'manager.pet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 5: Actor Super Admin by role (is_super_admin=0 but holds SUPER_ADMINISTRATOR role)
        5 => ['user_account_id' => 5, 'email' => 'super.role@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 10: Pending Pet Store Employee
        10 => ['user_account_id' => 10, 'email' => 'carla.mendoza@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 11: Already-verified Pet Store Employee
        11 => ['user_account_id' => 11, 'email' => 'verified.emp@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-06-01 10:00:00'],
        // 12: Inactive Pending Pet Store Employee
        12 => ['user_account_id' => 12, 'email' => 'inactive.emp@pawcarino.test', 'account_status' => 'inactive', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 13: Legacy Pet Store Employee (email_verified_at IS NULL, ZERO invitation records)
        13 => ['user_account_id' => 13, 'email' => 'legacy.emp@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 14: Target with is_super_admin=0 but SUPER_ADMINISTRATOR role in Pet Store (unit 1)
        14 => ['user_account_id' => 14, 'email' => 'target.super.same@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 15: Target in Pet Store (unit 1) holding SUPER_ADMINISTRATOR role in Vet Clinic (unit 2)
        15 => ['user_account_id' => 15, 'email' => 'target.super.other@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 16: Target with is_super_admin=1 and email_verified_at=NULL
        16 => ['user_account_id' => 16, 'email' => 'target.super.flag@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => null],
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
        13 => ['employee_id' => 13, 'user_account_id' => 13, 'business_unit_id' => 1, 'first_name' => 'Legacy', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
        14 => ['employee_id' => 14, 'user_account_id' => 14, 'business_unit_id' => 1, 'first_name' => 'SameUnit', 'last_name' => 'SuperTarget', 'position' => 'Receptionist', 'employee_status' => 'active'],
        15 => ['employee_id' => 15, 'user_account_id' => 15, 'business_unit_id' => 1, 'first_name' => 'CrossUnit', 'last_name' => 'SuperTarget', 'position' => 'Receptionist', 'employee_status' => 'active'],
        16 => ['employee_id' => 16, 'user_account_id' => 16, 'business_unit_id' => 1, 'first_name' => 'Flag', 'last_name' => 'SuperTarget', 'position' => 'Receptionist', 'employee_status' => 'active'],
    ];
    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 1, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 2, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 3, 'role_id' => 2, 'business_unit_id' => 2],
        ['user_account_id' => 4, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 5, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 5, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 10, 'role_id' => 4, 'business_unit_id' => 1],
        ['user_account_id' => 14, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 15, 'role_id' => 1, 'business_unit_id' => 2], // SUPER_ADMINISTRATOR in DIFFERENT unit!
    ];
    return $pdo;
}

$tempEnv = tempnam(sys_get_temp_dir(), 'pawcarino-inv-cp22-');
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
    echo "=== EmployeeInvitationService Isolated Unit & FakePDO Tests (Checkpoint 2.2) ===\n\n";

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

    // --- 2. Actor & Target Super Administrator Protection ---
    test('Actor Super Administrator (flag or role) and cross-unit SysAdmin are denied (403)', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);

        $r1 = $svc->createInvitation(1, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $r2 = $svc->createInvitation(5, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $crossUnit = $svc->createInvitation(3, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $manager = $svc->createInvitation(4, 10, 10, 1, 'carla.mendoza@pawcarino.test');

        assertTrue(!$r1['ok'] && $r1['status'] === 403, 'is_super_admin=1 actor must be denied');
        assertTrue(!$r2['ok'] && $r2['status'] === 403, 'SUPER_ADMINISTRATOR role actor must be denied');
        assertTrue(!$crossUnit['ok'] && $crossUnit['status'] === 403, 'Cross-unit SysAdmin must be denied');
        assertTrue(!$manager['ok'] && $manager['status'] === 403, 'Non-SysAdmin must be denied');
        assertTrue(count($pdo->invitations) === 0, 'No invitations should be written');
    });

    test('Target Super Administrator (flag, same-unit role, or cross-unit role) is rejected across create, resend, and revoke', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);

        // Seed synthetic pending invitation on target 15 to verify resend & revoke also reject Super Admin targets
        $pdo->invitations[900] = [
            'invitation_id' => 900,
            'user_account_id' => 15,
            'employee_id' => 15,
            'business_unit_id' => 1,
            'invited_email' => 'target.super.other@pawcarino.test',
            'token_hash' => hash('sha256', EmployeeInvitationService::generateToken()),
            'status' => 'pending',
            'expires_at' => '2026-11-22 12:00:00',
            'accepted_at' => null,
            'revoked_at' => null,
            'revoke_reason' => null,
            'email_submission_status' => 'pending',
            'email_submitted_at' => null,
            'email_failed_at' => null,
            'email_error' => null,
            'created_by_user_id' => 2,
            'created_at' => '2026-11-20 12:00:00',
            'updated_at' => '2026-11-20 12:00:00',
        ];

        $cSame = $svc->createInvitation(2, 14, 14, 1, 'target.super.same@pawcarino.test');
        $cOther = $svc->createInvitation(2, 15, 15, 1, 'target.super.other@pawcarino.test');
        $cFlag = $svc->createInvitation(2, 16, 16, 1, 'target.super.flag@pawcarino.test');

        $resOther = $svc->resendInvitation(2, 15, 1);
        $revOther = $svc->revokeInvitation(2, 15, 1);
        $resSame = $svc->resendInvitation(2, 14, 1);
        $revFlag = $svc->revokeInvitation(2, 16, 1);

        assertTrue(!$cSame['ok'] && $cSame['status'] === 403, 'Create must reject same-unit SUPER_ADMINISTRATOR role');
        assertTrue(!$cOther['ok'] && $cOther['status'] === 403, 'Create must reject cross-unit SUPER_ADMINISTRATOR role');
        assertTrue(!$cFlag['ok'] && $cFlag['status'] === 403, 'Create must reject is_super_admin=1');
        assertTrue(!$resOther['ok'] && $resOther['status'] === 403, 'Resend must reject cross-unit SUPER_ADMINISTRATOR role');
        assertTrue(!$revOther['ok'] && $revOther['status'] === 403, 'Revoke must reject cross-unit SUPER_ADMINISTRATOR role');
        assertTrue(!$resSame['ok'] && $resSame['status'] === 403, 'Resend must reject same-unit SUPER_ADMINISTRATOR role');
        assertTrue(!$revFlag['ok'] && $revFlag['status'] === 403, 'Revoke must reject is_super_admin=1');
    });

    // --- 3. Canonical Lock Order & Non-Locking business_unit ---
    test('Lock order enforces user_account -> employee -> employee_invitation and never FOR UPDATE locks business_unit', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]
        );

        $res = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue($res['ok'] === true, 'Create should succeed');

        $forUpdates = $pdo->forUpdateQueries;
        assertTrue(count($forUpdates) === 3, 'Expected 3 FOR UPDATE queries (user_account, employee, employee_invitation)');
        assertTrue(str_contains($forUpdates[0], 'FROM user_account ua'), 'First lock must be user_account');
        assertTrue(str_contains($forUpdates[1], 'FROM employee e'), 'Second lock must be employee');
        assertTrue(str_contains($forUpdates[2], 'FROM employee_invitation'), 'Third lock must be employee_invitation');

        foreach ($forUpdates as $q) {
            assertTrue(!str_contains($q, 'FROM business_unit') && !str_contains($q, 'JOIN business_unit'), 'business_unit table must never be locked with FOR UPDATE');
        }
    });

    // --- 4. Resend Eligibility Matrix ---
    test('resendInvitation rejects legacy employee with email_verified_at=NULL and zero invitation history (409)', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $mailCalled = false;
        $svc = new EmployeeInvitationService($pdo, function () use (&$mailCalled) {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $res = $svc->resendInvitation(2, 13, 1);
        assertTrue(!$res['ok'] && $res['status'] === 409, 'Legacy account with no invitations must be rejected 409');
        assertTrue(count($pdo->invitations) === 0 && !$mailCalled, 'No invitation or mail may be created');
    });

    test('resendInvitation rejects when latest invitation is revoked or accepted', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]
        );

        // Revoked case
        $created = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $rev = $svc->revokeInvitation(2, 10, 1, 'admin_revoked');
        assertTrue($rev['ok'] === true, 'Revoke should succeed');
        $resendRevoked = $svc->resendInvitation(2, 10, 1);
        assertTrue(!$resendRevoked['ok'] && $resendRevoked['status'] === 409, 'Resend must reject when latest invitation is revoked');

        // Accepted row case (even if email_verified_at were defensively null)
        $pdo->invitations[$created['invitation_id']]['status'] = 'accepted';
        $pdo->invitations[$created['invitation_id']]['accepted_at'] = '2026-11-20 12:10:00';
        $pdo->invitations[$created['invitation_id']]['revoked_at'] = null;
        $resendAccepted = $svc->resendInvitation(2, 10, 1);
        assertTrue(!$resendAccepted['ok'] && $resendAccepted['status'] === 409, 'Resend must reject when accepted invitation exists');
    });

    test('resendInvitation succeeds for valid pending, stale-expired pending, and already-expired latest invitation', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200],
            function () use (&$now): int { return $now; }
        );

        // 1) Valid pending -> revoked + replacement
        $inv1 = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $now += 3600;
        $inv2 = $svc->resendInvitation(2, 10, 1);
        assertTrue($inv2['ok'] === true && $inv2['revoked_count'] === 1 && $inv2['expired_count'] === 0, 'Valid pending resend failed');
        assertTrue($pdo->invitations[$inv1['invitation_id']]['status'] === 'revoked', 'First invite must be revoked');

        // 2) Pending past expires_at -> marked expired + replacement
        $now += 48 * 3600 + 5;
        $inv3 = $svc->resendInvitation(2, 10, 1);
        assertTrue($inv3['ok'] === true && $inv3['revoked_count'] === 0 && $inv3['expired_count'] === 1, 'Stale-pending resend should mark expired');
        assertTrue($pdo->invitations[$inv2['invitation_id']]['status'] === 'expired', 'Second invite must be marked expired');

        // 3) Already-transitioned expired as latest -> allowed
        $pdo->invitations[$inv3['invitation_id']]['status'] = 'expired';
        $inv4 = $svc->resendInvitation(2, 10, 1);
        assertTrue($inv4['ok'] === true && $pdo->invitations[$inv4['invitation_id']]['status'] === 'pending', 'Already-expired latest invitation should allow resend');
    });

    // --- 5. Pre-Send Email Validation (No Mail Triggered on Invalid/Stale/Tampered States) ---
    test('submitInvitationEmail refuses to call mailSender for nonexistent, invalid, mismatched, revoked, accepted, expired, or deactivated invitations', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $mailCalls = 0;

        $svc = new EmployeeInvitationService(
            $pdo,
            function () use (&$mailCalls): array {
                $mailCalls++;
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            },
            function () use (&$now): int { return $now; }
        );

        // Create invitation inside open tx to get raw token without triggering send
        $pdo->beginTransaction();
        $txRes = $svc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $pdo->commit();
        $invId = (int)$txRes['invitation_id'];
        $rawTok = (string)$txRes['raw_token'];

        // a) Nonexistent invitation ID
        $rNotFound = $svc->submitInvitationEmail(999999, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue(!$rNotFound['submitted'] && $rNotFound['status_code'] === 404, 'Nonexistent ID must fail 404');

        // b) Invalid raw token format & wrong token hash
        $rBadFmt = $svc->submitInvitationEmail($invId, 'short', 'carla.mendoza@pawcarino.test');
        $rWrongTok = $svc->submitInvitationEmail($invId, EmployeeInvitationService::generateToken(), 'carla.mendoza@pawcarino.test');
        assertTrue(!$rBadFmt['submitted'] && $rBadFmt['status_code'] === 422, 'Bad token format must fail 422');
        assertTrue(!$rWrongTok['submitted'] && $rWrongTok['status_code'] === 422, 'Wrong token must fail 422');

        // c) Mismatched recipient email
        $rBadEmail = $svc->submitInvitationEmail($invId, $rawTok, 'attacker@pawcarino.test');
        assertTrue(!$rBadEmail['submitted'] && $rBadEmail['status_code'] === 422, 'Mismatched email must fail 422');

        // d) Account email changed out-of-band without updating invitation
        $pdo->users[10]['email'] = 'changed@pawcarino.test';
        $rChangedEmail = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue(!$rChangedEmail['submitted'] && $rChangedEmail['status_code'] === 422, 'Account email mismatch must fail 422');
        $pdo->users[10]['email'] = 'carla.mendoza@pawcarino.test';

        // e) Deactivated account
        $pdo->users[10]['account_status'] = 'inactive';
        $rInactive = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue(!$rInactive['submitted'] && $rInactive['status_code'] === 409, 'Inactive account must fail 409');
        $pdo->users[10]['account_status'] = 'active';

        // f) Expired invitation
        $now += 48 * 3600 + 1;
        $rExpired = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue(!$rExpired['submitted'] && $rExpired['status_code'] === 409, 'Expired invitation must fail 409');
        $now -= (48 * 3600 + 1);

        // g) Revoked invitation (revoked between creation and email submission)
        $svc->revokeInvitation(2, 10, 1, 'admin_revoked');
        $rRevoked = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue(!$rRevoked['submitted'] && $rRevoked['status_code'] === 409, 'Revoked invitation must fail 409');
        assertTrue($pdo->invitations[$invId]['email_submission_status'] === 'pending', 'Revoked row email_submission_status must not be mutated');

        // h) Accepted invitation
        $pdo->invitations[$invId]['status'] = 'accepted';
        $pdo->invitations[$invId]['accepted_at'] = '2026-11-20 12:05:00';
        $pdo->invitations[$invId]['revoked_at'] = null;
        $rAccepted = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue(!$rAccepted['submitted'] && $rAccepted['status_code'] === 409, 'Accepted invitation must fail 409');

        assertTrue($mailCalls === 0, 'Mail sender must NEVER be invoked on failed pre-send validation');
    });

    test('submitInvitationEmail ignores tampered caller display strings and uses verified DB metadata', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $captured = null;
        $svc = new EmployeeInvitationService(
            $pdo,
            function (string $email, string $name, string $unit, string $pos, string $url) use (&$captured): array {
                $captured = compact('email', 'name', 'unit', 'pos', 'url');
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            }
        );

        $pdo->beginTransaction();
        $txRes = $svc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $pdo->commit();

        $res = $svc->submitInvitationEmail(
            (int)$txRes['invitation_id'],
            (string)$txRes['raw_token'],
            'carla.mendoza@pawcarino.test',
            'Spoofed Name',
            'Spoofed Unit',
            'Super Administrator'
        );
        assertTrue($res['submitted'] === true, 'Submission should succeed');
        assertTrue($captured['name'] === 'Carla Mendoza', 'Must use persisted name');
        assertTrue($captured['unit'] === 'Pet Store', 'Must use persisted business unit');
        assertTrue($captured['pos'] === 'Receptionist', 'Must use persisted position');
    });

    // --- 6. Email Outcome Timestamps & Missing Rows ---
    test('recordEmailSubmissionOutcome clears opposite timestamp on failed->submitted and submitted->failed and rejects missing ID', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200],
            function () use (&$now): int { return $now; }
        );

        $pdo->beginTransaction();
        $txRes = $svc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $pdo->commit();
        $invId = (int)$txRes['invitation_id'];

        // Failed first
        $r1 = $svc->recordEmailSubmissionOutcome($invId, false, 'Temporary SMTP 421');
        assertTrue($r1['ok'] === true && $r1['email_submission_status'] === 'failed', 'Should record failure');
        assertTrue($pdo->invitations[$invId]['email_failed_at'] === '2026-11-20 12:00:00', 'email_failed_at must be set');
        assertTrue($pdo->invitations[$invId]['email_submitted_at'] === null, 'email_submitted_at must be null');

        // Retry succeeds -> clears email_failed_at & email_error
        $now += 60;
        $r2 = $svc->recordEmailSubmissionOutcome($invId, true);
        assertTrue($r2['ok'] === true && $r2['email_submission_status'] === 'submitted', 'Should record success');
        assertTrue($pdo->invitations[$invId]['email_submitted_at'] === '2026-11-20 12:01:00', 'email_submitted_at must be set');
        assertTrue($pdo->invitations[$invId]['email_failed_at'] === null && $pdo->invitations[$invId]['email_error'] === null, 'email_failed_at and email_error must be cleared');

        // Subsequent failure -> clears email_submitted_at
        $now += 60;
        $r3 = $svc->recordEmailSubmissionOutcome($invId, false, 'Second attempt failed');
        assertTrue($r3['ok'] === true && $r3['email_submission_status'] === 'failed', 'Should record subsequent failure');
        assertTrue($pdo->invitations[$invId]['email_failed_at'] === '2026-11-20 12:02:00', 'email_failed_at must be updated');
        assertTrue($pdo->invitations[$invId]['email_submitted_at'] === null, 'email_submitted_at must be cleared on failure');

        // Nonexistent invitation ID must return ok=false
        $rMissing = $svc->recordEmailSubmissionOutcome(987654, true);
        assertTrue($rMissing['ok'] === false && $rMissing['email_submission_status'] === 'unknown', 'Missing invitation ID must return ok=false');
    });

    // --- 7. Submission Concurrency & Stale Outcome Protection ---
    test('Concurrent submission attempt for same invitation is blocked by advisory lock without duplicate SMTP send', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $mailCalls = 0;
        $svc = new EmployeeInvitationService(
            $pdo,
            function () use (&$mailCalls): array {
                $mailCalls++;
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            }
        );

        $pdo->beginTransaction();
        $txRes = $svc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $pdo->commit();
        $invId = (int)$txRes['invitation_id'];
        $rawTok = (string)$txRes['raw_token'];

        // Simulate another worker holding the advisory lock for this invitation
        $pdo->advisoryLocks['pc_inv_mail_' . $invId] = true;
        $res = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue(!$res['submitted'] && $res['status_code'] === 409, 'Contended advisory lock must return 409 without sending');
        assertTrue($mailCalls === 0, 'Duplicate SMTP send must not occur');
        unset($pdo->advisoryLocks['pc_inv_mail_' . $invId]);
    });

    test('Revocation while SMTP is in progress prevents delayed SMTP outcome from overwriting revoked invitation or replacement', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $svc = null;

        $pdo->beginTransaction();
        $tmpSvc = new EmployeeInvitationService($pdo);
        $txRes = $tmpSvc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $pdo->commit();

        $oldInvId = (int)$txRes['invitation_id'];
        $oldRawTok = (string)$txRes['raw_token'];
        $replacementId = null;

        // Mail sender simulates concurrent resend/revocation happening WHILE SMTP socket is in flight
        $svc = new EmployeeInvitationService(
            $pdo,
            function () use ($pdo, $oldInvId, &$replacementId): array {
                // Simulate another request revoking oldInvId and issuing replacement
                $pdo->invitations[$oldInvId]['status'] = 'revoked';
                $pdo->invitations[$oldInvId]['revoked_at'] = '2026-11-20 12:00:05';
                $pdo->invitations[$oldInvId]['revoke_reason'] = 'resent';
                $pdo->invitations[$oldInvId]['updated_at'] = '2026-11-20 12:00:05';

                $replacementId = 101;
                $pdo->invitations[$replacementId] = [
                    'invitation_id' => $replacementId,
                    'user_account_id' => 10,
                    'employee_id' => 10,
                    'business_unit_id' => 1,
                    'invited_email' => 'carla.mendoza@pawcarino.test',
                    'token_hash' => hash('sha256', EmployeeInvitationService::generateToken()),
                    'status' => 'pending',
                    'expires_at' => '2026-11-22 12:00:05',
                    'accepted_at' => null,
                    'revoked_at' => null,
                    'revoke_reason' => null,
                    'email_submission_status' => 'pending',
                    'email_submitted_at' => null,
                    'email_failed_at' => null,
                    'email_error' => null,
                    'created_by_user_id' => 2,
                    'created_at' => '2026-11-20 12:00:05',
                    'updated_at' => '2026-11-20 12:00:05',
                ];

                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            }
        );

        $res = $svc->submitInvitationEmail($oldInvId, $oldRawTok, 'carla.mendoza@pawcarino.test');
        assertTrue($res['submitted'] === false && $res['status_code'] === 409, 'Delayed outcome after revocation must report stale 409');
        assertTrue($pdo->invitations[$oldInvId]['status'] === 'revoked', 'Old invitation must remain revoked');
        assertTrue($pdo->invitations[$oldInvId]['email_submission_status'] === 'pending', 'Old invitation email_submission_status must not be overwritten');
        assertTrue($pdo->invitations[$oldInvId]['email_submitted_at'] === null, 'Old invitation email_submitted_at must remain null');
        assertTrue($pdo->invitations[$replacementId]['status'] === 'pending' && $pdo->invitations[$replacementId]['email_submission_status'] === 'pending', 'Newer invitation must remain untouched');
    });

    test('Null mail driver and SMTP failure keep pending invitation intact and record sanitized failure', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);

        $res = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue($res['ok'] === true && $res['invitation_status'] === 'pending', 'Invitation must remain pending');
        assertTrue($res['email_submitted'] === false && $res['email_submission_status'] === 'failed' && $res['email_status_code'] === 503, 'Null driver must be recorded as failed 503');
        assertTrue($pdo->invitations[$res['invitation_id']]['email_failed_at'] !== null && $pdo->invitations[$res['invitation_id']]['email_submitted_at'] === null, 'Failure timestamp must be set and submitted cleared');

        // Also test SMTP failure URL/token scrubbing
        $svcSmtpFail = new EmployeeInvitationService(
            $pdo,
            fn(string $e, string $n, string $u, string $p, string $url) => [
                'submitted' => false,
                'driver' => 'smtp',
                'error' => "SMTP error for {$url}",
                'status_code' => 502,
            ]
        );
        $resent = $svcSmtpFail->resendInvitation(2, 10, 1);
        assertTrue($resent['ok'] === true && $resent['email_submitted'] === false && $resent['email_submission_status'] === 'failed', 'SMTP failure must be recorded');
        assertTrue(!str_contains((string)$resent['email_error'], 'https://') && !str_contains((string)$resent['email_error'], 'token='), 'Error must scrub URLs/tokens');
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

    test('Previously submitted invitation cannot be submitted again and preserves submitted status and timestamp', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $mailCalls = 0;
        $svc = new EmployeeInvitationService(
            $pdo,
            function () use (&$mailCalls): array {
                $mailCalls++;
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            },
            function () use (&$now): int { return $now; }
        );

        $pdo->beginTransaction();
        $txRes = $svc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $pdo->commit();
        $invId = (int)$txRes['invitation_id'];
        $rawTok = (string)$txRes['raw_token'];

        $first = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue($first['submitted'] === true && $first['status_code'] === 200 && $mailCalls === 1, 'Initial submission should succeed');
        $submittedAt = $pdo->invitations[$invId]['email_submitted_at'];
        $updatedAt = $pdo->invitations[$invId]['updated_at'];

        $now += 120;
        $second = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue($second['submitted'] === false && $second['status_code'] === 409, 'Duplicate submission must be rejected with 409');
        assertTrue($second['email_submission_status'] === 'submitted', 'Returned email_submission_status must remain submitted');
        assertTrue($mailCalls === 1, 'Mail sender must NOT be invoked on duplicate submission');
        assertTrue($pdo->invitations[$invId]['status'] === 'pending', 'Lifecycle status must remain pending');
        assertTrue($pdo->invitations[$invId]['email_submission_status'] === 'submitted', 'Stored email_submission_status must remain submitted');
        assertTrue($pdo->invitations[$invId]['email_submitted_at'] === $submittedAt, 'email_submitted_at must remain unchanged');
        assertTrue($pdo->invitations[$invId]['updated_at'] === $updatedAt, 'updated_at must remain unchanged');
    });

    test('Failed email submission can still be retried using the same valid invitation', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $now = strtotime('2026-11-20 12:00:00 UTC');
        $attempt = 0;
        $svc = new EmployeeInvitationService(
            $pdo,
            function () use (&$attempt): array {
                $attempt++;
                if ($attempt === 1) {
                    return ['submitted' => false, 'driver' => 'smtp', 'error' => 'Temporary SMTP failure', 'status_code' => 502];
                }
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            },
            function () use (&$now): int { return $now; }
        );

        $pdo->beginTransaction();
        $txRes = $svc->createInvitationInTransaction(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $pdo->commit();
        $invId = (int)$txRes['invitation_id'];
        $rawTok = (string)$txRes['raw_token'];

        $first = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue($first['submitted'] === false && $first['email_submission_status'] === 'failed' && $attempt === 1, 'First attempt should fail');

        $now += 60;
        $retry = $svc->submitInvitationEmail($invId, $rawTok, 'carla.mendoza@pawcarino.test');
        assertTrue($retry['submitted'] === true && $retry['status_code'] === 200 && $attempt === 2, 'Retry after failed submission should succeed');
        assertTrue($pdo->invitations[$invId]['email_submission_status'] === 'submitted', 'Retry should transition status to submitted');
        assertTrue($pdo->invitations[$invId]['email_submitted_at'] === '2026-11-20 12:01:00', 'email_submitted_at should be recorded');
        assertTrue($pdo->invitations[$invId]['email_failed_at'] === null && $pdo->invitations[$invId]['email_error'] === null, 'email_failed_at and email_error should be cleared');
    });
} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempEnv);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
