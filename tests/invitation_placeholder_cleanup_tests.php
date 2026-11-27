<?php
declare(strict_types=1);

/**
 * Isolated Provenance & Rollback Safety Tests for InvitationPlaceholderCleanupService.
 *
 * Uses a stateful in-memory FakeCleanupPdo double (no live MySQL 8.4 mutation).
 * Verifies:
 *  - Zero-invitation legacy/sample/guest accounts (even with email_verified_at=NULL) remain untouched
 *  - Accepted invitees, verified accounts, and Super Administrators remain PROTECTED_GENUINE
 *  - Never-accepted placeholders with verified audit provenance and zero dependencies are cleanly detached & deleted
 *  - Never-accepted invitees with operational/customer/actor deps OR missing provenance are QUARANTINED_UNCERTAIN
 *  - Execution refuses without $confirmed === true
 *  - archiveForRollback() archives decoupled rows and only detaches live rows when explicitly requested
 */

require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Services/InvitationPlaceholderCleanupService.php';

use App\Services\InvitationPlaceholderCleanupService;

final class FakeCleanupPdo extends PDO
{
    public array $users = [];
    public array $employees = [];
    public array $customers = [];
    public array $roles = [
        1 => 'SUPER_ADMINISTRATOR',
        2 => 'SYSTEM_ADMINISTRATOR',
        3 => 'PET_STORE_CASHIER',
    ];
    public array $accountRoles = [];
    public array $employeeWorkStatus = [];
    public array $invitations = [];
    public array $invitationRoles = [];
    public array $auditLogs = [];
    public array $depRows = []; // [table => [column => [id => count]]]
    public array $archivedInvitations = [];
    public array $archivedInvitationRoles = [];

    public int $nextAuditId = 1000;
    public int $lastInsertIdVal = 0;
    public bool $inTx = false;
    private ?array $snapshot = null;

    public function __construct() {}

    public function beginTransaction(): bool
    {
        if ($this->inTx) {
            throw new RuntimeException('Nested transaction.');
        }
        $this->inTx = true;
        $this->snapshot = [
            'users' => $this->users,
            'employees' => $this->employees,
            'customers' => $this->customers,
            'accountRoles' => $this->accountRoles,
            'employeeWorkStatus' => $this->employeeWorkStatus,
            'invitations' => $this->invitations,
            'invitationRoles' => $this->invitationRoles,
            'auditLogs' => $this->auditLogs,
            'archivedInvitations' => $this->archivedInvitations,
            'archivedInvitationRoles' => $this->archivedInvitationRoles,
            'nextAuditId' => $this->nextAuditId,
        ];
        return true;
    }

    public function commit(): bool
    {
        $this->inTx = false;
        $this->snapshot = null;
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
        return true;
    }

    public function inTransaction(): bool { return $this->inTx; }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string)$this->lastInsertIdVal;
    }

    public function exec(string $statement): int|false
    {
        if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS employee_invitation_decoupled_archive')
            || str_contains($statement, 'CREATE TABLE IF NOT EXISTS employee_invitation_role_archive')) {
            return 0;
        }
        if (str_contains($statement, 'DELETE eir') && str_contains($statement, 'employee_invitation_decoupled_archive')) {
            $removed = 0;
            $this->invitationRoles = array_values(array_filter(
                $this->invitationRoles,
                function (array $row) use (&$removed): bool {
                    $iid = (int)$row['invitation_id'];
                    if (isset($this->archivedInvitations[$iid])) {
                        $removed++;
                        return false;
                    }
                    return true;
                }
            ));
            return $removed;
        }
        if (str_contains($statement, 'DELETE ei') && str_contains($statement, 'employee_invitation_decoupled_archive')) {
            $removed = 0;
            foreach ($this->invitations as $iid => $inv) {
                if (isset($this->archivedInvitations[$iid]) && ($inv['user_account_id'] === null || $inv['employee_id'] === null)) {
                    unset($this->invitations[$iid]);
                    $removed++;
                }
            }
            return $removed;
        }
        throw new RuntimeException('Unhandled exec SQL in FakeCleanupPdo: ' . $statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = new FakeCleanupStatement($this, $query);
        $stmt->execute([]);
        return $stmt;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeCleanupStatement($this, $query);
    }

    public function executeSql(string $sql, array $params, int &$rowCount): array
    {
        $rowCount = 0;

        if (str_contains($sql, 'FROM user_account ua') && str_contains($sql, 'WHERE NOT EXISTS')) {
            $cnt = 0;
            foreach ($this->users as $uid => $ua) {
                $hasInv = false;
                foreach ($this->invitations as $inv) {
                    if ((int)($inv['user_account_id'] ?? 0) === (int)$uid) {
                        $hasInv = true;
                        break;
                    }
                }
                if (!$hasInv) {
                    $cnt++;
                }
            }
            return [[(string)$cnt]];
        }

        if (str_contains($sql, 'SELECT COUNT(*) FROM employee_invitation WHERE user_account_id IS NULL OR employee_id IS NULL')) {
            $cnt = 0;
            foreach ($this->invitations as $inv) {
                if (($inv['user_account_id'] ?? null) === null || ($inv['employee_id'] ?? null) === null) {
                    $cnt++;
                }
            }
            return [[(string)$cnt]];
        }

        if (str_contains($sql, 'SELECT DISTINCT ua.user_account_id') && str_contains($sql, 'JOIN employee_invitation ei_any')) {
            $rows = [];
            foreach ($this->users as $uid => $ua) {
                $hasInv = false;
                foreach ($this->invitations as $inv) {
                    if ((int)($inv['user_account_id'] ?? 0) === (int)$uid) {
                        $hasInv = true;
                        break;
                    }
                }
                if (!$hasInv) {
                    continue;
                }
                $rows[] = $this->buildJoinedAccountRow((int)$uid);
            }
            return $rows;
        }

        if (str_contains($sql, 'FROM user_account ua') && str_contains($sql, 'WHERE ua.user_account_id = ?') && str_contains($sql, 'FOR UPDATE')) {
            $uid = (int)$params[0];
            if (!isset($this->users[$uid])) {
                return [];
            }
            return [$this->buildJoinedAccountRow($uid)];
        }

        if (str_contains($sql, 'SELECT invitation_id, business_unit_id, status, accepted_at, revoked_at, expires_at')
            && str_contains($sql, 'FROM employee_invitation')) {
            $uid = (int)$params[0];
            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)($inv['user_account_id'] ?? 0) === $uid) {
                    $rows[] = [
                        'invitation_id' => $inv['invitation_id'],
                        'business_unit_id' => $inv['business_unit_id'],
                        'status' => $inv['status'],
                        'accepted_at' => $inv['accepted_at'] ?? null,
                        'revoked_at' => $inv['revoked_at'] ?? null,
                        'expires_at' => $inv['expires_at'] ?? null,
                    ];
                }
            }
            usort($rows, static fn(array $a, array $b): int => (int)$a['invitation_id'] <=> (int)$b['invitation_id']);
            return $rows;
        }

        if (str_contains($sql, 'SELECT invitation_id, business_unit_id') && str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'FOR UPDATE')) {
            $uid = (int)$params[0];
            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)($inv['user_account_id'] ?? 0) === $uid) {
                    $rows[] = [
                        'invitation_id' => $inv['invitation_id'],
                        'business_unit_id' => $inv['business_unit_id'],
                    ];
                }
            }
            return $rows;
        }

        if (str_contains($sql, "WHERE target_type = 'employee_invitation'") && str_contains($sql, 'FROM audit_log')) {
            $ids = array_map('intval', $params);
            $cnt = 0;
            foreach ($this->auditLogs as $log) {
                if (
                    ($log['target_type'] ?? null) === 'employee_invitation'
                    && in_array((string)$log['action_code'], ['invitation.created', 'invitation.resent'], true)
                    && in_array((int)($log['target_id'] ?? 0), $ids, true)
                ) {
                    $cnt++;
                }
            }
            return [[(string)$cnt]];
        }

        if (str_contains($sql, 'WHERE affected_employee_id = ?') && str_contains($sql, 'FROM audit_log')) {
            $eid = (int)$params[0];
            $cnt = 0;
            foreach ($this->auditLogs as $log) {
                if (
                    (int)($log['affected_employee_id'] ?? 0) === $eid
                    && in_array((string)$log['action_code'], ['invitation.created', 'invitation.resent'], true)
                ) {
                    $cnt++;
                }
            }
            return [[(string)$cnt]];
        }

        if (preg_match('/^SELECT COUNT\(\*\) FROM ([a-z_]+) WHERE ([a-z_]+) = \?$/i', trim($sql), $m)) {
            $table = $m[1];
            $col = $m[2];
            $id = (int)$params[0];
            if ($table === 'audit_log' && $col === 'actor_user_id') {
                $cnt = 0;
                foreach ($this->auditLogs as $log) {
                    if ((int)($log['actor_user_id'] ?? 0) === $id) {
                        $cnt++;
                    }
                }
                return [[(string)$cnt]];
            }
            if ($table === 'employee_invitation' && $col === 'created_by_user_id') {
                $cnt = 0;
                foreach ($this->invitations as $inv) {
                    if ((int)($inv['created_by_user_id'] ?? 0) === $id) {
                        $cnt++;
                    }
                }
                return [[(string)$cnt]];
            }
            return [[(string)($this->depRows[$table][$col][$id] ?? 0)]];
        }

        if (str_contains($sql, 'UPDATE employee_invitation') && str_contains($sql, 'SET is_legacy_quarantined = 1')) {
            $uid = (int)$params[0];
            foreach ($this->invitations as $iid => &$inv) {
                if ((int)($inv['user_account_id'] ?? 0) === $uid && (string)$inv['status'] !== 'accepted') {
                    $inv['is_legacy_quarantined'] = 1;
                    $rowCount++;
                }
            }
            return [];
        }

        if (str_contains($sql, 'INSERT IGNORE INTO employee_invitation_role')) {
            $iid = (int)$params[0];
            $uid = (int)$params[1];
            $unitId = (int)$params[2];
            foreach ($this->accountRoles as $ar) {
                if ((int)$ar['user_account_id'] === $uid && (int)$ar['business_unit_id'] === $unitId) {
                    $rid = (int)$ar['role_id'];
                    if (($this->roles[$rid] ?? '') === 'SUPER_ADMINISTRATOR') {
                        continue;
                    }
                    $exists = false;
                    foreach ($this->invitationRoles as $ir) {
                        if ((int)$ir['invitation_id'] === $iid && (int)$ir['role_id'] === $rid) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $this->invitationRoles[] = ['invitation_id' => $iid, 'role_id' => $rid];
                        $rowCount++;
                    }
                }
            }
            return [];
        }

        if (str_contains($sql, 'UPDATE employee_invitation') && str_contains($sql, 'SET user_account_id = NULL')) {
            $uid = (int)$params[0];
            foreach ($this->invitations as $iid => &$inv) {
                if ((int)($inv['user_account_id'] ?? 0) === $uid && (string)$inv['status'] !== 'accepted') {
                    $inv['user_account_id'] = null;
                    $inv['employee_id'] = null;
                    $inv['is_legacy_quarantined'] = 0;
                    $rowCount++;
                }
            }
            return [];
        }

        if (str_contains($sql, 'UPDATE audit_log') && str_contains($sql, 'SET affected_employee_id = NULL')) {
            $eid = (int)$params[0];
            foreach ($this->auditLogs as &$log) {
                if ((int)($log['affected_employee_id'] ?? 0) === $eid) {
                    $log['affected_employee_id'] = null;
                    $rowCount++;
                }
            }
            return [];
        }

        if (str_contains($sql, 'DELETE FROM employee_work_status WHERE employee_id = ?')) {
            $eid = (int)$params[0];
            if (isset($this->employeeWorkStatus[$eid])) {
                unset($this->employeeWorkStatus[$eid]);
                $rowCount = 1;
            }
            return [];
        }

        if (str_contains($sql, 'DELETE FROM account_role WHERE user_account_id = ?')) {
            $uid = (int)$params[0];
            $this->accountRoles = array_values(array_filter(
                $this->accountRoles,
                static fn(array $ar): bool => (int)$ar['user_account_id'] !== $uid
            ));
            return [];
        }

        if (str_contains($sql, 'DELETE FROM customer WHERE user_account_id = ?')) {
            $uid = (int)$params[0];
            foreach ($this->customers as $cid => $c) {
                if ((int)$c['user_account_id'] === $uid) {
                    unset($this->customers[$cid]);
                    $rowCount++;
                }
            }
            return [];
        }

        if (str_contains($sql, 'DELETE FROM employee WHERE employee_id = ?')) {
            $eid = (int)$params[0];
            if (isset($this->employees[$eid])) {
                unset($this->employees[$eid]);
                $rowCount = 1;
            }
            return [];
        }

        if (str_contains($sql, 'DELETE FROM user_account WHERE user_account_id = ?')) {
            $uid = (int)$params[0];
            if (isset($this->users[$uid])) {
                unset($this->users[$uid]);
                $rowCount = 1;
            }
            return [];
        }

        if (str_contains($sql, 'INSERT INTO audit_log')) {
            $id = $this->nextAuditId++;
            $this->auditLogs[] = [
                'audit_log_id' => $id,
                'action_code' => $params[0],
                'summary' => $params[1],
                'business_unit_id' => $params[2],
                'actor_user_id' => $params[3],
                'affected_employee_id' => $params[4],
                'old_value' => $params[5],
                'new_value' => $params[6],
                'target_type' => $params[7],
                'target_id' => $params[8],
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }

        if (str_contains($sql, 'INSERT IGNORE INTO employee_invitation_decoupled_archive')) {
            $now = (string)$params[0];
            foreach ($this->invitations as $iid => $inv) {
                if (($inv['user_account_id'] ?? null) === null || ($inv['employee_id'] ?? null) === null) {
                    if (!isset($this->archivedInvitations[$iid])) {
                        $this->archivedInvitations[$iid] = $inv + ['archived_at' => $now];
                        $rowCount++;
                    }
                }
            }
            return [];
        }

        if (str_contains($sql, 'INSERT IGNORE INTO employee_invitation_role_archive')) {
            foreach ($this->invitationRoles as $ir) {
                $iid = (int)$ir['invitation_id'];
                $inv = $this->invitations[$iid] ?? null;
                if ($inv && (($inv['user_account_id'] ?? null) === null || ($inv['employee_id'] ?? null) === null)) {
                    $key = $iid . ':' . (int)$ir['role_id'];
                    if (!isset($this->archivedInvitationRoles[$key])) {
                        $this->archivedInvitationRoles[$key] = $ir;
                        $rowCount++;
                    }
                }
            }
            return [];
        }

        throw new RuntimeException('Unhandled SQL in FakeCleanupPdo: ' . $sql);
    }

    private function buildJoinedAccountRow(int $uid): array
    {
        $ua = $this->users[$uid];
        $emp = null;
        foreach ($this->employees as $e) {
            if ((int)$e['user_account_id'] === $uid) {
                $emp = $e;
                break;
            }
        }
        $cust = null;
        foreach ($this->customers as $c) {
            if ((int)$c['user_account_id'] === $uid) {
                $cust = $c;
                break;
            }
        }
        $hasSuper = false;
        foreach ($this->accountRoles as $ar) {
            if ((int)$ar['user_account_id'] === $uid && ($this->roles[(int)$ar['role_id']] ?? '') === 'SUPER_ADMINISTRATOR') {
                $hasSuper = true;
                break;
            }
        }
        return [
            'user_account_id' => $uid,
            'email' => $ua['email'],
            'username' => $ua['username'] ?? null,
            'account_status' => $ua['account_status'] ?? 'active',
            'is_super_admin' => $ua['is_super_admin'] ?? 0,
            'email_verified_at' => $ua['email_verified_at'] ?? null,
            'employee_id' => $emp['employee_id'] ?? null,
            'business_unit_id' => $emp['business_unit_id'] ?? null,
            'first_name' => $emp['first_name'] ?? null,
            'last_name' => $emp['last_name'] ?? null,
            'position' => $emp['position'] ?? null,
            'phone' => $emp['phone'] ?? null,
            'employee_status' => $emp['employee_status'] ?? null,
            'customer_id' => $cust['customer_id'] ?? null,
            'cust_middle_name' => $cust['middle_name'] ?? null,
            'address_line1' => $cust['address_line1'] ?? null,
            'address_line2' => $cust['address_line2'] ?? null,
            'city' => $cust['city'] ?? null,
            'province' => $cust['province'] ?? null,
            'postal_code' => $cust['postal_code'] ?? null,
            'has_super_role' => $hasSuper ? 1 : 0,
        ];
    }
}

final class FakeCleanupStatement extends PDOStatement
{
    private FakeCleanupPdo $pdo;
    private string $sql;
    private array $rows = [];
    private int $cursor = 0;
    private int $lastRowCount = 0;

    public function __construct(FakeCleanupPdo $pdo, string $sql)
    {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function execute(?array $params = null): bool
    {
        $rc = 0;
        $this->rows = $this->pdo->executeSql($this->sql, $params ?? [], $rc);
        $this->lastRowCount = $rc;
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
        $rest = array_slice($this->rows, $this->cursor);
        $this->cursor = count($this->rows);
        return $rest;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        if (!is_array($row)) {
            return false;
        }
        $vals = array_values($row);
        return $vals[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->lastRowCount;
    }
}

$passed = 0;
$failed = 0;

function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertSameVal(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($msg . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
    }
}

function buildScenarioPdo(): FakeCleanupPdo
{
    $pdo = new FakeCleanupPdo();

    // 1. Legacy direct employee with NO invitation history & NULL email_verified_at -> zero_invitation_accounts_protected
    $pdo->users[1] = ['user_account_id' => 1, 'email' => 'legacy.staff@pawcarino.ph', 'username' => 'legacystaff', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null];
    $pdo->employees[1] = ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Legacy', 'last_name' => 'Staff', 'position' => 'Cashier', 'phone' => null, 'employee_status' => 'active'];
    $pdo->customers[1] = ['customer_id' => 1, 'user_account_id' => 1];

    // 2. Guest customer account with NO invitation history -> zero_invitation_accounts_protected
    $pdo->users[2] = ['user_account_id' => 2, 'email' => 'guest.customer@example.com', 'username' => null, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null];
    $pdo->customers[2] = ['customer_id' => 2, 'user_account_id' => 2];

    // 3. Accepted invitee (protected even with email_verified_at populated and accepted invitation)
    $pdo->users[3] = ['user_account_id' => 3, 'email' => 'accepted.staff@pawcarino.ph', 'username' => 'acceptedstaff', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-11-01 10:00:00'];
    $pdo->employees[3] = ['employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 1, 'first_name' => 'Accepted', 'last_name' => 'Staff', 'position' => 'Cashier', 'phone' => null, 'employee_status' => 'active'];
    $pdo->invitations[30] = ['invitation_id' => 30, 'user_account_id' => 3, 'employee_id' => 3, 'business_unit_id' => 1, 'invited_email' => 'accepted.staff@pawcarino.ph', 'first_name' => 'Accepted', 'last_name' => 'Staff', 'position_name' => 'Cashier', 'token_hash' => str_repeat('a', 64), 'status' => 'accepted', 'expires_at' => '2026-12-01 00:00:00', 'accepted_at' => '2026-11-01 10:00:00', 'email_submission_status' => 'submitted', 'created_by_user_id' => 1, 'created_at' => '2026-10-31 10:00:00', 'updated_at' => '2026-11-01 10:00:00'];

    // 4. Verified account whose only invitation is revoked -> PROTECTED_GENUINE (verified email protection)
    $pdo->users[4] = ['user_account_id' => 4, 'email' => 'verified.revoked@pawcarino.ph', 'username' => 'verrev', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-10-15 08:00:00'];
    $pdo->employees[4] = ['employee_id' => 4, 'user_account_id' => 4, 'business_unit_id' => 1, 'first_name' => 'Verified', 'last_name' => 'User', 'position' => 'Cashier', 'phone' => null, 'employee_status' => 'active'];
    $pdo->invitations[40] = ['invitation_id' => 40, 'user_account_id' => 4, 'employee_id' => 4, 'business_unit_id' => 1, 'invited_email' => 'verified.revoked@pawcarino.ph', 'first_name' => 'Verified', 'last_name' => 'User', 'position_name' => 'Cashier', 'token_hash' => str_repeat('b', 64), 'status' => 'revoked', 'expires_at' => '2026-11-10 00:00:00', 'revoked_at' => '2026-11-02 00:00:00', 'email_submission_status' => 'submitted', 'created_by_user_id' => 1, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-02 00:00:00'];

    // 5. Proven unaccepted placeholder (unverified, zero FK deps, positive invitation.created audit provenance)
    $pdo->users[5] = ['user_account_id' => 5, 'email' => 'placeholder@pawcarino.ph', 'username' => 'placeholder5', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null];
    $pdo->employees[5] = ['employee_id' => 5, 'user_account_id' => 5, 'business_unit_id' => 1, 'first_name' => 'Place', 'last_name' => 'Holder', 'position' => 'Cashier', 'phone' => '09170000005', 'employee_status' => 'active'];
    $pdo->customers[5] = ['customer_id' => 5, 'user_account_id' => 5];
    $pdo->accountRoles[] = ['user_account_id' => 5, 'role_id' => 3, 'business_unit_id' => 1];
    $pdo->employeeWorkStatus[5] = ['employee_id' => 5];
    $pdo->invitations[50] = ['invitation_id' => 50, 'user_account_id' => 5, 'employee_id' => 5, 'business_unit_id' => 1, 'invited_email' => 'placeholder@pawcarino.ph', 'first_name' => 'Place', 'last_name' => 'Holder', 'position_name' => 'Cashier', 'token_hash' => str_repeat('c', 64), 'status' => 'pending', 'expires_at' => '2026-12-01 00:00:00', 'email_submission_status' => 'submitted', 'created_by_user_id' => 1, 'created_at' => '2026-11-15 00:00:00', 'updated_at' => '2026-11-15 00:00:00'];
    $pdo->auditLogs[] = ['audit_log_id' => 500, 'action_code' => 'invitation.created', 'summary' => 'Invite 50', 'business_unit_id' => 1, 'actor_user_id' => 1, 'affected_employee_id' => 5, 'old_value' => null, 'new_value' => 'pending', 'target_type' => 'employee_invitation', 'target_id' => 50];

    // 6. Quarantined uncertain: never accepted, but customer owns a pet (must NEVER be deleted)
    $pdo->users[6] = ['user_account_id' => 6, 'email' => 'customer.with.pet@example.com', 'username' => 'petowner6', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null];
    $pdo->employees[6] = ['employee_id' => 6, 'user_account_id' => 6, 'business_unit_id' => 1, 'first_name' => 'Pet', 'last_name' => 'Owner', 'position' => 'Cashier', 'phone' => null, 'employee_status' => 'active'];
    $pdo->customers[6] = ['customer_id' => 6, 'user_account_id' => 6];
    $pdo->depRows['pet']['customer_id'][6] = 2;
    $pdo->invitations[60] = ['invitation_id' => 60, 'user_account_id' => 6, 'employee_id' => 6, 'business_unit_id' => 1, 'invited_email' => 'customer.with.pet@example.com', 'first_name' => 'Pet', 'last_name' => 'Owner', 'position_name' => 'Cashier', 'token_hash' => str_repeat('d', 64), 'status' => 'expired', 'expires_at' => '2026-11-10 00:00:00', 'email_submission_status' => 'submitted', 'created_by_user_id' => 1, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'];
    $pdo->auditLogs[] = ['audit_log_id' => 600, 'action_code' => 'invitation.created', 'summary' => 'Invite 60', 'business_unit_id' => 1, 'actor_user_id' => 1, 'affected_employee_id' => 6, 'old_value' => null, 'new_value' => 'pending', 'target_type' => 'employee_invitation', 'target_id' => 60];

    // 7. Quarantined uncertain: missing invitation audit provenance
    $pdo->users[7] = ['user_account_id' => 7, 'email' => 'missing.provenance@example.com', 'username' => null, 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null];
    $pdo->employees[7] = ['employee_id' => 7, 'user_account_id' => 7, 'business_unit_id' => 1, 'first_name' => 'No', 'last_name' => 'Prov', 'position' => 'Cashier', 'phone' => null, 'employee_status' => 'active'];
    $pdo->invitations[70] = ['invitation_id' => 70, 'user_account_id' => 7, 'employee_id' => 7, 'business_unit_id' => 1, 'invited_email' => 'missing.provenance@example.com', 'first_name' => 'No', 'last_name' => 'Prov', 'position_name' => 'Cashier', 'token_hash' => str_repeat('e', 64), 'status' => 'pending', 'expires_at' => '2026-12-01 00:00:00', 'email_submission_status' => 'submitted', 'created_by_user_id' => 1, 'created_at' => '2026-11-15 00:00:00', 'updated_at' => '2026-11-15 00:00:00'];

    return $pdo;
}

check('1. analyze() accurately separates protected, proven placeholders, and quarantined rows without mutating', function () {
    $pdo = buildScenarioPdo();
    $svc = new InvitationPlaceholderCleanupService($pdo);
    $res = $svc->analyze();

    assertSameVal(2, $res['summary']['zero_invitation_accounts_protected'], 'Zero-invitation accounts must stay protected.');
    assertSameVal(5, $res['summary']['linked_invitation_accounts_inspected'], 'Should inspect the 5 accounts linked to invitations.');
    assertSameVal(1, $res['summary']['proven_placeholder_count'], 'Only user 5 is a proven clean placeholder.');
    assertSameVal(2, $res['summary']['quarantined_uncertain_count'], 'Users 6 and 7 must be quarantined.');
    assertSameVal(2, $res['summary']['protected_with_invitations_count'], 'Users 3 and 4 must stay protected.');

    assertSameVal(5, $res['proven_placeholders'][0]['user_account_id'], 'User 5 must be classified PROVEN_PLACEHOLDER.');
});

check('2. applyCleanup() refuses without explicit confirmation', function () {
    $pdo = buildScenarioPdo();
    $svc = new InvitationPlaceholderCleanupService($pdo);
    $threw = false;
    try {
        $svc->applyCleanup(false);
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), '--confirm-cleanup');
    }
    if (!$threw) {
        throw new RuntimeException('Expected refusal when confirmed=false.');
    }
    assertSameVal(7, count($pdo->users), 'No user must be modified when unconfirmed.');
});

check('3. applyCleanup(true) detaches proven placeholders, preserves invitation & audit logs, and quarantines uncertain rows', function () {
    $pdo = buildScenarioPdo();
    $svc = new InvitationPlaceholderCleanupService($pdo);
    $res = $svc->applyCleanup(true, 1);

    assertSameVal([5], $res['cleaned_user_ids'], 'Only user 5 should be cleaned.');
    assertSameVal([6, 7], $res['quarantined_user_ids'], 'Users 6 and 7 should be quarantined.');

    // User 5 deleted from account/employee/customer/work_status, invitation 50 preserved detached & role snapshotted
    if (isset($pdo->users[5]) || isset($pdo->employees[5]) || isset($pdo->customers[5]) || isset($pdo->employeeWorkStatus[5])) {
        throw new RuntimeException('Proven placeholder user/employee/customer records must be deleted.');
    }
    assertSameVal(null, $pdo->invitations[50]['user_account_id'], 'Invitation 50 user_account_id must be detached to NULL.');
    assertSameVal(null, $pdo->invitations[50]['employee_id'], 'Invitation 50 employee_id must be detached to NULL.');
    $roles50 = array_values(array_filter($pdo->invitationRoles, static fn(array $r): bool => (int)$r['invitation_id'] === 50));
    assertSameVal(1, count($roles50), 'Invitation 50 must retain its snapshotted employee_invitation_role row.');
    assertSameVal(3, (int)$roles50[0]['role_id'], 'Role 3 snapshot must match original account_role.');

    // Audit log history preserved with affected_employee_id set NULL
    $origAudit500 = null;
    foreach ($pdo->auditLogs as $log) {
        if ((int)$log['audit_log_id'] === 500) {
            $origAudit500 = $log;
        }
    }
    if ($origAudit500 === null || $origAudit500['affected_employee_id'] !== null) {
        throw new RuntimeException('Original audit log must remain intact with affected_employee_id = NULL.');
    }

    // Legacy, guest, accepted, verified, and quarantined rows remain untouched in user_account
    foreach ([1, 2, 3, 4, 6, 7] as $uid) {
        if (!isset($pdo->users[$uid])) {
            throw new RuntimeException("Protected or quarantined user {$uid} must not be deleted.");
        }
    }
    assertSameVal(1, $pdo->invitations[60]['is_legacy_quarantined'], 'Quarantined invitation 60 must be flagged.');
    assertSameVal(1, $pdo->invitations[70]['is_legacy_quarantined'], 'Quarantined invitation 70 must be flagged.');
});

check('4. archiveForRollback() safely archives detached invitations and only deletes live rows when requested', function () {
    $pdo = buildScenarioPdo();
    $svc = new InvitationPlaceholderCleanupService($pdo);
    $svc->applyCleanup(true, 1);

    $archOnly = $svc->archiveForRollback(false);
    assertSameVal(1, $archOnly['archived_invitations'], 'Should archive 1 decoupled invitation (id 50).');
    assertSameVal(1, $archOnly['archived_roles'], 'Should archive 1 decoupled role.');
    assertSameVal(false, $archOnly['detached_from_live'], 'Live row should remain until detach requested.');
    if (!isset($pdo->invitations[50])) {
        throw new RuntimeException('Invitation 50 should remain in live table when detachArchivedFromLive=false.');
    }

    $detachRes = $svc->archiveForRollback(true);
    assertSameVal(true, $detachRes['detached_from_live'], 'detach flag should be true.');
    if (isset($pdo->invitations[50])) {
        throw new RuntimeException('Decoupled invitation 50 should be removed from live table after archival + detach.');
    }
    if (!isset($pdo->archivedInvitations[50])) {
        throw new RuntimeException('Archived copy of invitation 50 must exist.');
    }
});

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
