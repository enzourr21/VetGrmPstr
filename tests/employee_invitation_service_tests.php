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

        // 1a. Unaccepted invitation block check (isAccountBlockedByUnacceptedInvitation)
        if (
            str_contains($sql, 'ei_any.user_account_id = ?')
            && str_contains($sql, 'ei_acc.user_account_id = ?')
        ) {
            $anyUid = (int)$params[0];
            $accUid = (int)$params[1];
            $hasAny = false;
            $hasAccepted = false;
            foreach ($this->invitations as $inv) {
                if ((int)$inv['user_account_id'] === $anyUid) {
                    $hasAny = true;
                }
                if ((int)$inv['user_account_id'] === $accUid && (string)$inv['status'] === 'accepted') {
                    $hasAccepted = true;
                }
            }
            return [[
                'has_any_invitation' => $hasAny ? 1 : 0,
                'has_accepted_invitation' => $hasAccepted ? 1 : 0,
            ]];
        }

        // 1b. Authorization query: Super Administrator or unit System Administrator check
        if (
            str_contains($sql, 'has_super_role')
            && str_contains($sql, 'has_unit_sysadmin_role')
            && str_contains($sql, 'LEFT JOIN employee e')
            && str_contains($sql, 'JOIN business_unit bu')
            && str_contains($sql, 'WHERE ua.user_account_id = ?')
        ) {
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

            $hasSuperRole = $this->userHasSuperRole($actorUid);
            $hasUnitSysAdminRole = false;
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] !== $actorUid) {
                    continue;
                }
                $rName = $this->roles[(int)$ar['role_id']] ?? '';
                if ($rName === 'SYSTEM_ADMINISTRATOR' && (int)$ar['business_unit_id'] === $unitId) {
                    $hasUnitSysAdminRole = true;
                }
            }

            return [[
                'user_account_id' => $ua['user_account_id'],
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'employee_id' => $emp !== null ? $emp['employee_id'] : null,
                'employee_status' => $emp !== null ? $emp['employee_status'] : null,
                'business_unit_id' => $bu['business_unit_id'],
                'business_unit_status' => $bu['business_unit_status'],
                'has_super_role' => $hasSuperRole ? 1 : 0,
                'has_unit_sysadmin_role' => $hasUnitSysAdminRole ? 1 : 0,
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
        3 => ['business_unit_id' => 3, 'business_unit_name' => 'Grooming Studio', 'business_unit_status' => 'inactive'],
    ];
    $pdo->roles = [
        1 => 'SUPER_ADMINISTRATOR',
        2 => 'SYSTEM_ADMINISTRATOR',
        3 => 'BUSINESS_MANAGER',
        4 => 'RECEPTIONIST',
    ];
    $pdo->users = [
        // 1: Active Super Administrator via flag (no SYSTEM_ADMINISTRATOR role)
        1 => ['user_account_id' => 1, 'email' => 'super@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 2: Pet Store System Administrator
        2 => ['user_account_id' => 2, 'email' => 'sysadmin.pet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 3: Vet Clinic System Administrator
        3 => ['user_account_id' => 3, 'email' => 'sysadmin.vet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 4: Pet Store Business Manager (non-SysAdmin)
        4 => ['user_account_id' => 4, 'email' => 'manager.pet@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 5: Active Super Administrator by role only (is_super_admin=0, holds SUPER_ADMINISTRATOR role, no SYSTEM_ADMINISTRATOR role)
        5 => ['user_account_id' => 5, 'email' => 'super.role@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 6: Active Super Administrator without an employee row (is_super_admin=1, no roles)
        6 => ['user_account_id' => 6, 'email' => 'super.noemp@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 7: Inactive Super Administrator (account_status=inactive, is_super_admin=1 + SUPER_ADMINISTRATOR role)
        7 => ['user_account_id' => 7, 'email' => 'super.inactive@pawcarino.test', 'account_status' => 'inactive', 'is_super_admin' => 1, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 10: Pending Pet Store Employee
        10 => ['user_account_id' => 10, 'email' => 'carla.mendoza@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 11: Already-verified Pet Store Employee (Receptionist)
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
        // 17: Pending Veterinary Clinic Employee (unit 2)
        17 => ['user_account_id' => 17, 'email' => 'marco.reyes@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 18: Pending Employee in Inactive Business Unit (unit 3)
        18 => ['user_account_id' => 18, 'email' => 'groomer.inactive.unit@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
    ];
    $pdo->employees = [
        1 => ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Super', 'last_name' => 'Admin', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        2 => ['employee_id' => 2, 'user_account_id' => 2, 'business_unit_id' => 1, 'first_name' => 'Pet', 'last_name' => 'SysAdmin', 'position' => 'Pet Store System Administrator', 'employee_status' => 'active'],
        3 => ['employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 2, 'first_name' => 'Vet', 'last_name' => 'SysAdmin', 'position' => 'Veterinary Clinic System Administrator', 'employee_status' => 'active'],
        4 => ['employee_id' => 4, 'user_account_id' => 4, 'business_unit_id' => 1, 'first_name' => 'Pet', 'last_name' => 'Manager', 'position' => 'Business Manager — Pet Store', 'employee_status' => 'active'],
        5 => ['employee_id' => 5, 'user_account_id' => 5, 'business_unit_id' => 1, 'first_name' => 'Role', 'last_name' => 'Super', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        // Note: User 6 intentionally has NO employee row
        7 => ['employee_id' => 7, 'user_account_id' => 7, 'business_unit_id' => 1, 'first_name' => 'Inactive', 'last_name' => 'Super', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        10 => ['employee_id' => 10, 'user_account_id' => 10, 'business_unit_id' => 1, 'first_name' => 'Carla', 'last_name' => 'Mendoza', 'position' => 'Receptionist', 'employee_status' => 'active'],
        11 => ['employee_id' => 11, 'user_account_id' => 11, 'business_unit_id' => 1, 'first_name' => 'Verified', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
        12 => ['employee_id' => 12, 'user_account_id' => 12, 'business_unit_id' => 1, 'first_name' => 'Inactive', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'inactive'],
        13 => ['employee_id' => 13, 'user_account_id' => 13, 'business_unit_id' => 1, 'first_name' => 'Legacy', 'last_name' => 'Staff', 'position' => 'Receptionist', 'employee_status' => 'active'],
        14 => ['employee_id' => 14, 'user_account_id' => 14, 'business_unit_id' => 1, 'first_name' => 'SameUnit', 'last_name' => 'SuperTarget', 'position' => 'Receptionist', 'employee_status' => 'active'],
        15 => ['employee_id' => 15, 'user_account_id' => 15, 'business_unit_id' => 1, 'first_name' => 'CrossUnit', 'last_name' => 'SuperTarget', 'position' => 'Receptionist', 'employee_status' => 'active'],
        16 => ['employee_id' => 16, 'user_account_id' => 16, 'business_unit_id' => 1, 'first_name' => 'Flag', 'last_name' => 'SuperTarget', 'position' => 'Receptionist', 'employee_status' => 'active'],
        17 => ['employee_id' => 17, 'user_account_id' => 17, 'business_unit_id' => 2, 'first_name' => 'Marco', 'last_name' => 'Reyes', 'position' => 'Veterinary Assistant', 'employee_status' => 'active'],
        18 => ['employee_id' => 18, 'user_account_id' => 18, 'business_unit_id' => 3, 'first_name' => 'Grooming', 'last_name' => 'Staff', 'position' => 'Groomer', 'employee_status' => 'active'],
    ];
    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 2, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 3, 'role_id' => 2, 'business_unit_id' => 2],
        ['user_account_id' => 4, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 5, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 7, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 10, 'role_id' => 4, 'business_unit_id' => 1],
        ['user_account_id' => 11, 'role_id' => 4, 'business_unit_id' => 1],
        ['user_account_id' => 14, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 15, 'role_id' => 1, 'business_unit_id' => 2], // SUPER_ADMINISTRATOR in DIFFERENT unit!
        ['user_account_id' => 17, 'role_id' => 4, 'business_unit_id' => 2],
        ['user_account_id' => 18, 'role_id' => 4, 'business_unit_id' => 3],
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

    // --- 2. Actor Authorization (Super Admin & Unit SysAdmin) & Target Super Admin Exclusion ---
    test('Active Super Administrators (flag, role-only, and without employee row) succeed on create, resend, and revoke across active business units', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");

        $actors = [
            1 => 'is_super_admin=1 without SYSTEM_ADMINISTRATOR role',
            5 => 'SUPER_ADMINISTRATOR role without SYSTEM_ADMINISTRATOR role',
            6 => 'is_super_admin=1 without employee row',
        ];
        $targets = [
            ['employee_id' => 10, 'user_account_id' => 10, 'unit_id' => 1, 'email' => 'carla.mendoza@pawcarino.test'],
            ['employee_id' => 17, 'user_account_id' => 17, 'unit_id' => 2, 'email' => 'marco.reyes@pawcarino.test'],
        ];

        foreach ($actors as $actorId => $label) {
            foreach ($targets as $target) {
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

                $empId = $target['employee_id'];
                $uid = $target['user_account_id'];
                $unitId = $target['unit_id'];
                $email = $target['email'];

                // 1) Create invitation
                $created = $svc->createInvitation($actorId, $empId, $uid, $unitId, $email);
                assertTrue(
                    $created['ok'] === true && $created['status'] === 201 && $created['email_submitted'] === true,
                    "Create failed for Super Admin #{$actorId} ({$label}) in unit {$unitId}"
                );
                assertTrue(!array_key_exists('raw_token', $created), 'createInvitation must never return raw_token');
                $inv1Id = (int)$created['invitation_id'];
                $rawTok1 = $capturedTokens[0] ?? '';
                assertTrue(EmployeeInvitationService::isValidRawTokenFormat($rawTok1), 'Captured raw token 1 must be 64-char hex');
                assertTrue(
                    $pdo->invitations[$inv1Id]['token_hash'] === EmployeeInvitationService::hashToken($rawTok1)
                    && $pdo->invitations[$inv1Id]['token_hash'] !== $rawTok1,
                    'Stored token_hash must be SHA-256 digest of raw token'
                );
                assertTrue(
                    $pdo->invitations[$inv1Id]['expires_at'] === gmdate('Y-m-d H:i:s', $now + EmployeeInvitationService::EXPIRY_SECONDS),
                    'Create must set 48-hour UTC expiry'
                );
                assertTrue($pdo->invitations[$inv1Id]['created_by_user_id'] === $actorId, 'created_by_user_id must match Super Admin actor');

                // Verify canonical lock order and non-locking business_unit
                assertTrue(count($pdo->forUpdateQueries) === 3, 'Expected 3 FOR UPDATE queries on create');
                assertTrue(str_contains($pdo->forUpdateQueries[0], 'FROM user_account ua'), 'First lock must be user_account');
                assertTrue(str_contains($pdo->forUpdateQueries[1], 'FROM employee e'), 'Second lock must be employee');
                assertTrue(str_contains($pdo->forUpdateQueries[2], 'FROM employee_invitation'), 'Third lock must be employee_invitation');
                foreach ($pdo->forUpdateQueries as $q) {
                    assertTrue(!str_contains($q, 'FROM business_unit') && !str_contains($q, 'JOIN business_unit'), 'business_unit must never be locked FOR UPDATE');
                }

                // Single active pending guard: duplicate create rejected 409
                $dupCreate = $svc->createInvitation($actorId, $empId, $uid, $unitId, $email);
                assertTrue(!$dupCreate['ok'] && $dupCreate['status'] === 409, 'Duplicate pending create must be rejected 409');

                // 2) Resend invitation
                $now += 3600;
                $pdo->forUpdateQueries = [];
                $resent = $svc->resendInvitation($actorId, $empId, $unitId);
                assertTrue(
                    $resent['ok'] === true && $resent['status'] === 200 && $resent['revoked_count'] === 1 && $resent['email_submitted'] === true,
                    "Resend failed for Super Admin #{$actorId} ({$label}) in unit {$unitId}"
                );
                assertTrue(!array_key_exists('raw_token', $resent), 'resendInvitation must never return raw_token');
                $inv2Id = (int)$resent['invitation_id'];
                $rawTok2 = $capturedTokens[1] ?? '';
                assertTrue(EmployeeInvitationService::isValidRawTokenFormat($rawTok2) && $rawTok2 !== $rawTok1, 'Replacement token must be fresh 64-char hex');
                assertTrue($pdo->invitations[$inv1Id]['status'] === 'revoked' && $pdo->invitations[$inv1Id]['revoke_reason'] === 'resent', 'Prior invite must be revoked with reason resent');
                assertTrue($pdo->invitations[$inv2Id]['status'] === 'pending', 'Replacement invite must be pending');
                assertTrue($pdo->invitations[$inv2Id]['token_hash'] === EmployeeInvitationService::hashToken($rawTok2), 'Replacement token_hash must match SHA-256');
                assertTrue(
                    $pdo->invitations[$inv2Id]['expires_at'] === gmdate('Y-m-d H:i:s', $now + EmployeeInvitationService::EXPIRY_SECONDS),
                    'Resend must refresh 48-hour UTC expiry'
                );
                assertTrue(
                    count($pdo->forUpdateQueries) === 3
                    && str_contains($pdo->forUpdateQueries[0], 'FROM user_account ua')
                    && str_contains($pdo->forUpdateQueries[1], 'FROM employee e')
                    && str_contains($pdo->forUpdateQueries[2], 'FROM employee_invitation'),
                    'Resend must preserve canonical lock order'
                );

                // 3) Revoke invitation
                $now += 600;
                $pdo->forUpdateQueries = [];
                $revoked = $svc->revokeInvitation($actorId, $empId, $unitId, 'super_admin_revoked');
                assertTrue(
                    $revoked['ok'] === true && $revoked['status'] === 200 && $revoked['revoked_count'] === 1 && $revoked['revoked_invitation_ids'] === [$inv2Id],
                    "Revoke failed for Super Admin #{$actorId} ({$label}) in unit {$unitId}"
                );
                assertTrue($pdo->invitations[$inv2Id]['status'] === 'revoked' && $pdo->invitations[$inv2Id]['revoke_reason'] === 'super_admin_revoked', 'Invitation must be revoked');
                assertTrue(
                    count($pdo->forUpdateQueries) === 3
                    && str_contains($pdo->forUpdateQueries[0], 'FROM user_account ua')
                    && str_contains($pdo->forUpdateQueries[1], 'FROM employee e')
                    && str_contains($pdo->forUpdateQueries[2], 'FROM employee_invitation'),
                    'Revoke must preserve canonical lock order'
                );

                // Single-use / post-revocation guard: subsequent revoke and resend must fail 409
                $revAgain = $svc->revokeInvitation($actorId, $empId, $unitId);
                $resAfterRev = $svc->resendInvitation($actorId, $empId, $unitId);
                assertTrue(!$revAgain['ok'] && $revAgain['status'] === 409, 'Revoking already-revoked invite must fail 409');
                assertTrue(!$resAfterRev['ok'] && $resAfterRev['status'] === 409, 'Resending after revocation must fail 409');

                // Verify audit logs carry Super Admin actor_user_id and never leak raw tokens
                assertTrue(count($pdo->auditLogs) === 3, 'Expected 3 audit log entries (created, resent, revoked)');
                $expectedActions = ['invitation.created', 'invitation.resent', 'invitation.revoked'];
                foreach ($pdo->auditLogs as $idx => $log) {
                    assertTrue($log['action_code'] === $expectedActions[$idx], "Unexpected audit action #{$idx}: {$log['action_code']}");
                    assertTrue((int)$log['actor_user_id'] === $actorId, "Audit log actor_user_id must be Super Admin #{$actorId}");
                    assertTrue((int)$log['business_unit_id'] === $unitId, "Audit log business_unit_id must be {$unitId}");
                    assertTrue((int)$log['affected_employee_id'] === $empId, "Audit log affected_employee_id must be {$empId}");
                    assertTrue(!str_contains((string)$log['summary'], $rawTok1) && !str_contains((string)$log['summary'], $rawTok2), 'Audit log must never expose raw tokens');
                }
            }
        }
    });

    test('Inactive Super Admin, Super Admin targeting inactive business unit, and Super Admin with unresolved unaccepted invitation history are denied (403)', function () use ($tempEnv) {
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

        // Seed synthetic pending invitations on emp 10 (unit 1) and emp 18 (inactive unit 3) so resend & revoke test 403 auth
        $pdo->invitations[910] = [
            'invitation_id' => 910,
            'user_account_id' => 10,
            'employee_id' => 10,
            'business_unit_id' => 1,
            'invited_email' => 'carla.mendoza@pawcarino.test',
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
        $pdo->invitations[918] = [
            'invitation_id' => 918,
            'user_account_id' => 18,
            'employee_id' => 18,
            'business_unit_id' => 3,
            'invited_email' => 'groomer.inactive.unit@pawcarino.test',
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
            'created_by_user_id' => 1,
            'created_at' => '2026-11-20 12:00:00',
            'updated_at' => '2026-11-20 12:00:00',
        ];

        // 1) Inactive Super Admin (#7) denied across create, resend, revoke
        $inactCreate = $svc->createInvitation(7, 17, 17, 2, 'marco.reyes@pawcarino.test');
        $inactResend = $svc->resendInvitation(7, 10, 1);
        $inactRevoke = $svc->revokeInvitation(7, 10, 1);
        assertTrue(!$inactCreate['ok'] && $inactCreate['status'] === 403, 'Inactive Super Admin create must be denied 403');
        assertTrue(!$inactResend['ok'] && $inactResend['status'] === 403, 'Inactive Super Admin resend must be denied 403');
        assertTrue(!$inactRevoke['ok'] && $inactRevoke['status'] === 403, 'Inactive Super Admin revoke must be denied 403');

        // 2) Active Super Admins (#1, #5, #6) targeting inactive business unit (unit 3) denied across create, resend, revoke
        foreach ([1, 5, 6] as $saId) {
            $buCreate = $svc->createInvitation($saId, 18, 18, 3, 'groomer.inactive.unit@pawcarino.test');
            $buResend = $svc->resendInvitation($saId, 18, 3);
            $buRevoke = $svc->revokeInvitation($saId, 18, 3);
            assertTrue(!$buCreate['ok'] && $buCreate['status'] === 403, "Super Admin #{$saId} create on inactive unit 3 must be denied 403");
            assertTrue(!$buResend['ok'] && $buResend['status'] === 403, "Super Admin #{$saId} resend on inactive unit 3 must be denied 403");
            assertTrue(!$buRevoke['ok'] && $buRevoke['status'] === 403, "Super Admin #{$saId} revoke on inactive unit 3 must be denied 403");
        }

        // 3) Super Admin (#1) with unresolved unaccepted invitation history (pending, expired, or revoked) denied 403
        foreach (['pending', 'expired', 'revoked'] as $unacceptedStatus) {
            $pdo->invitations[950] = [
                'invitation_id' => 950,
                'user_account_id' => 1,
                'employee_id' => 1,
                'business_unit_id' => 1,
                'invited_email' => 'super@pawcarino.test',
                'token_hash' => hash('sha256', EmployeeInvitationService::generateToken()),
                'status' => $unacceptedStatus,
                'expires_at' => '2026-11-22 12:00:00',
                'accepted_at' => null,
                'revoked_at' => $unacceptedStatus === 'revoked' ? '2026-11-20 12:00:00' : null,
                'revoke_reason' => $unacceptedStatus === 'revoked' ? 'admin_revoked' : null,
                'email_submission_status' => 'pending',
                'email_submitted_at' => null,
                'email_failed_at' => null,
                'email_error' => null,
                'created_by_user_id' => 2,
                'created_at' => '2026-11-20 12:00:00',
                'updated_at' => '2026-11-20 12:00:00',
            ];

            $blkCreate = $svc->createInvitation(1, 17, 17, 2, 'marco.reyes@pawcarino.test');
            $blkResend = $svc->resendInvitation(1, 10, 1);
            $blkRevoke = $svc->revokeInvitation(1, 10, 1);
            assertTrue(!$blkCreate['ok'] && $blkCreate['status'] === 403, "Super Admin with {$unacceptedStatus} unaccepted invite create must be denied 403");
            assertTrue(!$blkResend['ok'] && $blkResend['status'] === 403, "Super Admin with {$unacceptedStatus} unaccepted invite resend must be denied 403");
            assertTrue(!$blkRevoke['ok'] && $blkRevoke['status'] === 403, "Super Admin with {$unacceptedStatus} unaccepted invite revoke must be denied 403");
        }

        assertTrue($pdo->invitations[910]['status'] === 'pending' && $pdo->invitations[918]['status'] === 'pending', 'Seeded invitations must remain untouched');
        assertTrue(count($pdo->auditLogs) === 0 && $mailCalls === 0, 'No audit logs or emails may be emitted on denied actions');
    });

    test('Same-unit System Administrator retains access while cross-unit System Administrator and unauthorized ordinary employees are denied (403)', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService(
            $pdo,
            fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]
        );

        // Same-unit SysAdmin (#2) succeeds on create in unit 1
        $sameCreate = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue($sameCreate['ok'] === true && $sameCreate['status'] === 201, 'Same-unit SysAdmin (#2) create must succeed');

        // Cross-unit SysAdmin (#3), Business Manager (#4), and Receptionist (#11) denied across create, resend, and revoke on unit 1
        foreach ([3 => 'Cross-unit SysAdmin', 4 => 'Business Manager', 11 => 'Receptionist'] as $actorId => $roleLabel) {
            $c = $svc->createInvitation($actorId, 10, 10, 1, 'carla.mendoza@pawcarino.test');
            $rs = $svc->resendInvitation($actorId, 10, 1);
            $rv = $svc->revokeInvitation($actorId, 10, 1);
            assertTrue(!$c['ok'] && $c['status'] === 403, "{$roleLabel} (#{$actorId}) create must be denied 403");
            assertTrue(!$rs['ok'] && $rs['status'] === 403, "{$roleLabel} (#{$actorId}) resend must be denied 403");
            assertTrue(!$rv['ok'] && $rv['status'] === 403, "{$roleLabel} (#{$actorId}) revoke must be denied 403");
        }

        // Unit 1 SysAdmin (#2) is also denied cross-unit on unit 2 employee (#17)
        $crossFrom2 = $svc->createInvitation(2, 17, 17, 2, 'marco.reyes@pawcarino.test');
        assertTrue(!$crossFrom2['ok'] && $crossFrom2['status'] === 403, 'Unit 1 SysAdmin (#2) must be denied cross-unit access to unit 2');

        // Same-unit SysAdmin (#2) still succeeds on resend and revoke in unit 1
        $sameResend = $svc->resendInvitation(2, 10, 1);
        assertTrue($sameResend['ok'] === true && $sameResend['status'] === 200, 'Same-unit SysAdmin (#2) resend must succeed');
        $sameRevoke = $svc->revokeInvitation(2, 10, 1);
        assertTrue($sameRevoke['ok'] === true && $sameRevoke['status'] === 200, 'Same-unit SysAdmin (#2) revoke must succeed');
    });

    test('Target Super Administrator (flag, same-unit role, or cross-unit role) is rejected across create, resend, and revoke for both System Admin and Super Admin actors', function () use ($tempEnv) {
        loadTempEnv($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\n");
        $pdo = makeFixturePdo();
        $svc = new EmployeeInvitationService($pdo);

        // Seed synthetic pending invitations on targets 14, 15, and 16 to verify resend & revoke reject Super Admin targets
        $targets = [
            14 => ['email' => 'target.super.same@pawcarino.test', 'inv_id' => 900, 'label' => 'same-unit SUPER_ADMINISTRATOR role'],
            15 => ['email' => 'target.super.other@pawcarino.test', 'inv_id' => 901, 'label' => 'cross-unit SUPER_ADMINISTRATOR role'],
            16 => ['email' => 'target.super.flag@pawcarino.test', 'inv_id' => 902, 'label' => 'is_super_admin=1'],
        ];
        foreach ($targets as $tid => $info) {
            $pdo->invitations[$info['inv_id']] = [
                'invitation_id' => $info['inv_id'],
                'user_account_id' => $tid,
                'employee_id' => $tid,
                'business_unit_id' => 1,
                'invited_email' => $info['email'],
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
        }

        foreach ([2 => 'System Administrator (#2)', 1 => 'Super Administrator (#1)'] as $actorId => $actorLabel) {
            foreach ($targets as $tid => $info) {
                $c = $svc->createInvitation($actorId, $tid, $tid, 1, $info['email']);
                $rs = $svc->resendInvitation($actorId, $tid, 1);
                $rv = $svc->revokeInvitation($actorId, $tid, 1);

                assertTrue(!$c['ok'] && $c['status'] === 403, "{$actorLabel} create must reject {$info['label']}");
                assertTrue(!$rs['ok'] && $rs['status'] === 403, "{$actorLabel} resend must reject {$info['label']}");
                assertTrue(!$rv['ok'] && $rv['status'] === 403, "{$actorLabel} revoke must reject {$info['label']}");
            }
        }

        assertTrue(count($pdo->invitations) === 3 && count($pdo->auditLogs) === 0, 'No invitations may be created or mutated for Super Admin targets');
        foreach ($targets as $info) {
            assertTrue($pdo->invitations[$info['inv_id']]['status'] === 'pending', 'Seeded Super Admin target invitation must remain untouched');
        }
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

    test('Email normalization and invitation lifecycle protections (duplicate pending, revoked, and accepted)', function () use ($tempEnv) {
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

        // 1) Genuinely mismatched email is rejected 422 before creating any invitation
        $mismatch = $svc->createInvitation(2, 10, 10, 1, 'wrong.person@pawcarino.test');
        assertTrue(!$mismatch['ok'] && $mismatch['status'] === 422, 'Mismatched email must be rejected with 422');
        assertTrue(count($pdo->invitations) === 0 && $mailCalls === 0, 'No invitation or email should be created on email mismatch');

        // Case-insensitive + surrounding-whitespace email matches user_account.email and stores normalized lowercase
        $created = $svc->createInvitation(2, 10, 10, 1, '  Carla.Mendoza@PawCarino.TEST  ');
        assertTrue($created['ok'] === true && $created['status'] === 201, 'Mixed-case / padded email matching user_account.email must succeed');
        $invId = (int)$created['invitation_id'];
        assertTrue($created['invited_email'] === 'carla.mendoza@pawcarino.test', 'Returned invited_email must be normalized lowercase');
        assertTrue($pdo->invitations[$invId]['invited_email'] === 'carla.mendoza@pawcarino.test', 'Persisted invited_email must be normalized lowercase');
        assertTrue($mailCalls === 1, 'Initial create must send one email');

        // 2) Duplicate pending invitation protection while unexpired pending exists
        $now += 60;
        $dup = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue(!$dup['ok'] && $dup['status'] === 409, 'Duplicate pending createInvitation must reject with 409');
        assertTrue(count($pdo->invitations) === 1 && $mailCalls === 1, 'Duplicate pending create must not add an invitation or send a duplicate email');

        // 3a) Revoked lifecycle rules: resend and revoke both reject with 409
        $revoked = $svc->revokeInvitation(2, 10, 1, 'admin_revoked');
        assertTrue($revoked['ok'] === true && $revoked['status'] === 200, 'Initial revoke must succeed');
        assertTrue($pdo->invitations[$invId]['status'] === 'revoked', 'Invitation status must be revoked');

        $resendRevoked = $svc->resendInvitation(2, 10, 1);
        $revokeAgain = $svc->revokeInvitation(2, 10, 1);
        assertTrue(!$resendRevoked['ok'] && $resendRevoked['status'] === 409, 'resendInvitation after revocation must reject with 409');
        assertTrue(!$revokeAgain['ok'] && $revokeAgain['status'] === 409, 'revokeInvitation on already-revoked invitation must reject with 409');
        assertTrue(count($pdo->invitations) === 1 && $mailCalls === 1, 'Rejected post-revoke actions must not create invitations or send mail');

        // 3b) Accepted lifecycle rules: create, resend, and revoke all reject with 409
        $pdo->invitations[$invId]['status'] = 'accepted';
        $pdo->invitations[$invId]['accepted_at'] = '2026-11-20 12:05:00';
        $pdo->invitations[$invId]['revoked_at'] = null;
        $pdo->invitations[$invId]['revoke_reason'] = null;
        $pdo->users[10]['email_verified_at'] = '2026-11-20 12:05:00';

        $createAccepted = $svc->createInvitation(2, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        $resendAccepted = $svc->resendInvitation(2, 10, 1);
        $revokeAccepted = $svc->revokeInvitation(2, 10, 1);
        assertTrue(!$createAccepted['ok'] && $createAccepted['status'] === 409, 'createInvitation for accepted employee must reject with 409');
        assertTrue(!$resendAccepted['ok'] && $resendAccepted['status'] === 409, 'resendInvitation for accepted employee must reject with 409');
        assertTrue(!$revokeAccepted['ok'] && $revokeAccepted['status'] === 409, 'revokeInvitation for accepted employee must reject with 409');
        assertTrue(count($pdo->invitations) === 1 && $mailCalls === 1, 'Rejected post-accept actions must not create invitations or send mail');
    });

    test('revokeInvitation transitions past-due pending invitation to expired and rejects 409 so resend remains possible', function () use ($tempEnv) {
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

        $created = $svc->createInvitation(1, 10, 10, 1, 'carla.mendoza@pawcarino.test');
        assertTrue($created['ok'] === true && $mailCalls === 1, 'Initial Super Admin createInvitation should succeed');
        $invId = (int)$created['invitation_id'];

        // Advance past 48h expiry while DB status is still 'pending'
        $now += EmployeeInvitationService::EXPIRY_SECONDS + 10;

        $revExpired = $svc->revokeInvitation(1, 10, 1, 'admin_revoked');
        assertTrue(!$revExpired['ok'] && $revExpired['status'] === 409, 'revokeInvitation must reject past-due pending invitation with 409');
        assertTrue($pdo->invitations[$invId]['status'] === 'expired', 'Past-due pending invitation must transition to expired (not revoked)');

        // Because it transitioned to expired (not revoked), resendInvitation must still succeed
        $resent = $svc->resendInvitation(1, 10, 1);
        assertTrue($resent['ok'] === true && $resent['status'] === 200 && $mailCalls === 2, 'resendInvitation after past-due revoke attempt must still succeed');
        $newInvId = (int)$resent['invitation_id'];
        assertTrue($newInvId !== $invId && $pdo->invitations[$newInvId]['status'] === 'pending', 'Replacement pending invitation should be created');
    });
} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempEnv);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
