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
    /** @var list<array{employee_id:int,for_update:bool,in_tx:bool}> */
    public array $workStatusQueries = [];
    public ?Closure $onBeforeWorkStatusForUpdate = null;
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
            if ($this->inTx) {
                throw new RuntimeException('DDL must not run inside an active transaction.');
            }
            return 0;
        }
        if (str_contains($statement, 'DELETE eir') && str_contains($statement, 'employee_invitation_decoupled_archive')) {
            $filtersDecoupled = str_contains($statement, 'JOIN employee_invitation ei')
                && str_contains($statement, 'ei.user_account_id IS NULL OR ei.employee_id IS NULL');
            $removed = 0;
            $this->invitationRoles = array_values(array_filter(
                $this->invitationRoles,
                function (array $row) use (&$removed, $filtersDecoupled): bool {
                    $iid = (int)$row['invitation_id'];
                    if (!isset($this->archivedInvitations[$iid])) {
                        return true;
                    }
                    if ($filtersDecoupled) {
                        $inv = $this->invitations[$iid] ?? null;
                        if (!$inv || (($inv['user_account_id'] ?? null) !== null && ($inv['employee_id'] ?? null) !== null)) {
                            return true;
                        }
                    }
                    $removed++;
                    return false;
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

        if (str_contains($sql, 'SELECT invitation_id, employee_id, business_unit_id, status, accepted_at, revoked_at, expires_at')
            && str_contains($sql, 'FROM employee_invitation')) {
            $uid = (int)$params[0];
            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)($inv['user_account_id'] ?? 0) === $uid) {
                    $rows[] = [
                        'invitation_id' => $inv['invitation_id'],
                        'employee_id' => $inv['employee_id'] ?? null,
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

        if (str_contains($sql, 'SELECT work_status FROM employee_work_status WHERE employee_id = ?')) {
            $eid = (int)$params[0];
            $isForUpdate = str_contains($sql, 'FOR UPDATE');
            if ($isForUpdate && !$this->inTx) {
                throw new RuntimeException('FOR UPDATE must execute inside an active transaction.');
            }
            $this->workStatusQueries[] = [
                'employee_id' => $eid,
                'for_update' => $isForUpdate,
                'in_tx' => $this->inTx,
            ];
            if ($isForUpdate && $this->onBeforeWorkStatusForUpdate !== null) {
                $hook = $this->onBeforeWorkStatusForUpdate;
                $this->onBeforeWorkStatusForUpdate = null;
                $hook($this, $eid);
            }
            if (!array_key_exists($eid, $this->employeeWorkStatus)) {
                return [];
            }
            $row = $this->employeeWorkStatus[$eid];
            $ws = array_key_exists('work_status', $row) ? $row['work_status'] : 'Free';
            return [['work_status' => $ws]];
        }

        if (str_contains($sql, 'FROM employee_invitation')
            && str_contains($sql, 'WHERE employee_id = ?')
            && str_contains($sql, 'user_account_id IS NULL OR user_account_id <> ?')) {
            $eid = (int)$params[0];
            $uid = (int)$params[1];
            $cnt = 0;
            foreach ($this->invitations as $inv) {
                $invEid = $inv['employee_id'] !== null ? (int)$inv['employee_id'] : null;
                $invUid = $inv['user_account_id'] !== null ? (int)$inv['user_account_id'] : null;
                if ($invEid === $eid && ($invUid === null || $invUid !== $uid)) {
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

        if (str_contains($sql, 'INSERT IGNORE INTO employee_invitation_role (')) {
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
            if (array_key_exists($eid, $this->employeeWorkStatus)) {
                $onlyFree = str_contains($sql, "work_status = 'Free'");
                $row = $this->employeeWorkStatus[$eid];
                $ws = array_key_exists('work_status', $row) ? $row['work_status'] : 'Free';
                if (!$onlyFree || $ws === 'Free') {
                    unset($this->employeeWorkStatus[$eid]);
                    $rowCount = 1;
                }
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

        if (str_contains($sql, 'INSERT INTO employee_invitation_decoupled_archive') && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            $now = (string)$params[0];
            $mutableCols = [
                'status', 'expires_at', 'revoked_at', 'revoke_reason', 'replaced_invitation_id',
                'email_submission_status', 'email_submitted_at', 'email_failed_at', 'email_error',
                'updated_at',
            ];
            foreach ($this->invitations as $iid => $inv) {
                if (($inv['user_account_id'] ?? null) === null || ($inv['employee_id'] ?? null) === null) {
                    if (!isset($this->archivedInvitations[$iid])) {
                        $this->archivedInvitations[$iid] = $inv + ['archived_at' => $now];
                        $rowCount++;
                    } else {
                        // Update only mutable lifecycle/delivery fields; preserve immutable provenance
                        foreach ($mutableCols as $col) {
                            $this->archivedInvitations[$iid][$col] = $inv[$col] ?? null;
                        }
                        $this->archivedInvitations[$iid]['archived_at'] = $now;
                        $rowCount += 2; // MySQL rowCount=2 for ON DUPLICATE KEY UPDATE
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

        if (str_contains($sql, 'SELECT COUNT(DISTINCT ei.invitation_id)') && str_contains($sql, 'JOIN employee_invitation_decoupled_archive')) {
            $seen = [];
            foreach ($this->invitations as $iid => $inv) {
                if ((($inv['user_account_id'] ?? null) === null || ($inv['employee_id'] ?? null) === null)
                    && isset($this->archivedInvitations[(int)$iid])) {
                    $seen[(int)$iid] = true;
                }
            }
            return [[(string)count($seen)]];
        }

        if (str_contains($sql, 'FROM employee_invitation_role eir') && str_contains($sql, 'JOIN employee_invitation_role_archive ra')) {
            $cnt = 0;
            foreach ($this->invitationRoles as $ir) {
                $iid = (int)$ir['invitation_id'];
                $rid = (int)$ir['role_id'];
                $inv = $this->invitations[$iid] ?? null;
                if ($inv
                    && (($inv['user_account_id'] ?? null) === null || ($inv['employee_id'] ?? null) === null)
                    && isset($this->archivedInvitationRoles[$iid . ':' . $rid])) {
                    $cnt++;
                }
            }
            return [[(string)$cnt]];
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
        return array_key_exists($column, $vals) ? $vals[$column] : false;
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
    $pdo->employeeWorkStatus[5] = ['employee_id' => 5, 'work_status' => 'Free'];
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
    assertSameVal(0, $archOnly['detached_invitations'], 'No invitations detached when detachArchivedFromLive=false.');
    assertSameVal(0, $archOnly['detached_roles'], 'No roles detached when detachArchivedFromLive=false.');
    if (!isset($pdo->invitations[50])) {
        throw new RuntimeException('Invitation 50 should remain in live table when detachArchivedFromLive=false.');
    }

    $detachRes = $svc->archiveForRollback(true);
    assertSameVal(1, $detachRes['archived_invitations'], 'Should report 1 decoupled invitation archived before detach.');
    assertSameVal(1, $detachRes['archived_roles'], 'Should report 1 decoupled role archived before detach.');
    assertSameVal(true, $detachRes['detached_from_live'], 'detach flag should be true.');
    assertSameVal(1, $detachRes['detached_invitations'], 'Should detach 1 decoupled invitation.');
    assertSameVal(1, $detachRes['detached_roles'], 'Should detach 1 decoupled role.');
    if (isset($pdo->invitations[50])) {
        throw new RuntimeException('Decoupled invitation 50 should be removed from live table after archival + detach.');
    }
    if (!isset($pdo->archivedInvitations[50])) {
        throw new RuntimeException('Archived copy of invitation 50 must exist.');
    }
});

check('5. Fix A: archiveForRollback(true) preserves live employee_invitation_role rows for invitations subsequently accepted', function () {
    $pdo = buildScenarioPdo();
    $svc = new InvitationPlaceholderCleanupService($pdo);
    $svc->applyCleanup(true, 1);

    // Archive invitation #50 while decoupled
    $svc->archiveForRollback(false);
    if (!isset($pdo->archivedInvitations[50])) {
        throw new RuntimeException('Expected invitation 50 to be archived.');
    }

    // Simulate invitation #50 being accepted before a later --detach-archived-for-down run
    $pdo->invitations[50]['user_account_id'] = 99;
    $pdo->invitations[50]['employee_id'] = 99;
    $pdo->invitations[50]['status'] = 'accepted';
    $pdo->invitations[50]['accepted_at'] = '2026-11-28 00:10:00';

    // Re-run with detach=true: accepted live invitation AND its live role rows MUST remain untouched
    $svc->archiveForRollback(true);
    if (!isset($pdo->invitations[50])) {
        throw new RuntimeException('Accepted invitation 50 must NOT be deleted from live employee_invitation.');
    }
    $liveRoles50 = array_values(array_filter(
        $pdo->invitationRoles,
        static fn(array $r): bool => (int)$r['invitation_id'] === 50
    ));
    assertSameVal(1, count($liveRoles50), 'Accepted invitation 50 must retain its live employee_invitation_role row.');
    assertSameVal(3, (int)$liveRoles50[0]['role_id'], 'Live role_id for accepted invitation 50 must remain intact.');
});

check('6. Fix B: archiveForRollback() refreshes mutable status/delivery fields on re-archive without overwriting immutable provenance', function () {
    $pdo = buildScenarioPdo();
    $svc = new InvitationPlaceholderCleanupService($pdo);
    $svc->applyCleanup(true, 1);

    // Initial archive while status=pending, email_submission_status=submitted
    $svc->archiveForRollback(false);
    assertSameVal('pending', $pdo->archivedInvitations[50]['status'], 'Initial archive status should be pending.');
    assertSameVal('submitted', $pdo->archivedInvitations[50]['email_submission_status'], 'Initial archive delivery status should be submitted.');
    $origEmail = $pdo->archivedInvitations[50]['invited_email'];
    $origTokenHash = $pdo->archivedInvitations[50]['token_hash'];
    $origCreator = $pdo->archivedInvitations[50]['created_by_user_id'];
    $origCreatedAt = $pdo->archivedInvitations[50]['created_at'];

    // Live decoupled invitation transitions status and delivery metadata (plus simulate tampered immutable fields)
    $pdo->invitations[50]['status'] = 'revoked';
    $pdo->invitations[50]['revoked_at'] = '2026-11-28 00:20:00';
    $pdo->invitations[50]['revoke_reason'] = 'admin_revoked';
    $pdo->invitations[50]['email_submission_status'] = 'failed';
    $pdo->invitations[50]['email_submitted_at'] = null;
    $pdo->invitations[50]['email_failed_at'] = '2026-11-28 00:19:00';
    $pdo->invitations[50]['email_error'] = 'SMTP 502 temporary error';
    $pdo->invitations[50]['updated_at'] = '2026-11-28 00:20:00';
    $pdo->invitations[50]['invited_email'] = 'tampered@example.com';
    $pdo->invitations[50]['created_by_user_id'] = 999;

    // Re-archive refreshes mutable fields while preserving immutable provenance
    $svc->archiveForRollback(false);
    $arch = $pdo->archivedInvitations[50];
    assertSameVal('revoked', $arch['status'], 'Re-archived status must update to revoked.');
    assertSameVal('2026-11-28 00:20:00', $arch['revoked_at'], 'Re-archived revoked_at must update.');
    assertSameVal('admin_revoked', $arch['revoke_reason'], 'Re-archived revoke_reason must update.');
    assertSameVal('failed', $arch['email_submission_status'], 'Re-archived email_submission_status must update to failed.');
    assertSameVal(null, $arch['email_submitted_at'], 'Re-archived email_submitted_at must clear.');
    assertSameVal('2026-11-28 00:19:00', $arch['email_failed_at'], 'Re-archived email_failed_at must update.');
    assertSameVal('SMTP 502 temporary error', $arch['email_error'], 'Re-archived email_error must update.');
    assertSameVal('2026-11-28 00:20:00', $arch['updated_at'], 'Re-archived updated_at must update.');

    assertSameVal($origEmail, $arch['invited_email'], 'Immutable invited_email must not be overwritten on re-archive.');
    assertSameVal($origTokenHash, $arch['token_hash'], 'Immutable token_hash must not be overwritten on re-archive.');
    assertSameVal($origCreator, $arch['created_by_user_id'], 'Immutable created_by_user_id must not be overwritten on re-archive.');
    assertSameVal($origCreatedAt, $arch['created_at'], 'Immutable created_at must not be overwritten on re-archive.');
});

check('7. Fix D: archiveForRollback() fails closed if called inside an active database transaction', function () {
    $pdo = buildScenarioPdo();
    $svc = new InvitationPlaceholderCleanupService($pdo);
    $pdo->beginTransaction();
    $threw = false;
    try {
        $svc->archiveForRollback(false);
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'active database transaction');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    if (!$threw) {
        throw new RuntimeException('Expected archiveForRollback() to fail closed inside an active transaction.');
    }
    assertSameVal(0, count($pdo->archivedInvitations), 'No archive records may be written when transaction guard rejects.');
});

check('8. Fix C: CLI rejects unknown flags, duplicate flags, conflicting modes, and invalid combinations fail-closed (exit 2)', function () {
    $cliScript = dirname(__DIR__) . '/scripts/cleanup_invitation_placeholders.php';
    $invalidInvocations = [
        ['--apply', '--confirm-cleanup', '--dry-run'],
        ['--archive-for-rollback', '--dry-run'],
        ['--archive-for-rollback', '--detach-archived-for-down', '--dry-run'],
        ['--apply', '--archive-for-rollback', '--confirm-cleanup'],
        ['--apply'],
        ['--confirm-cleanup'],
        ['--dry-run', '--confirm-cleanup'],
        ['--archive-for-rollback', '--confirm-cleanup'],
        ['--detach-archived-for-down'],
        ['--dry-run', '--detach-archived-for-down'],
        ['--apply', '--confirm-cleanup', '--detach-archived-for-down'],
        ['--archive-for-rollback', '--detach-archived-for-dow'],
        ['--unknown-flag'],
        ['--dry-run', '--dry-run'],
    ];

    foreach ($invalidInvocations as $flags) {
        $cmd = array_merge([PHP_BINARY, $cliScript], $flags);
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $spec, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to spawn CLI process for flags: ' . implode(' ', $flags));
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        assertSameVal(2, $exitCode, 'CLI must fail closed with exit 2 for: ' . implode(' ', $flags));
        if (trim((string)$stderr) === '') {
            throw new RuntimeException('CLI must emit error message on STDERR for: ' . implode(' ', $flags));
        }
        if (trim((string)$stdout) !== '') {
            throw new RuntimeException('CLI must not emit JSON output on invalid flags: ' . implode(' ', $flags));
        }
    }
});

check('9. archiveForRollback() reports accurate distinct counts across multi-row, re-archive, repeated, and detach operations', function () {
    $pdo = buildScenarioPdo();
    $svc = new InvitationPlaceholderCleanupService($pdo);
    $svc->applyCleanup(true, 1);

    // Add a second role to decoupled invitation 50 and add a second decoupled invitation 80 with 1 role.
    $pdo->invitationRoles[] = ['invitation_id' => 50, 'role_id' => 2];
    $pdo->invitations[80] = [
        'invitation_id' => 80,
        'user_account_id' => null,
        'employee_id' => null,
        'business_unit_id' => 1,
        'invited_email' => 'decoupled.second@pawcarino.ph',
        'first_name' => 'Second',
        'last_name' => 'Decoupled',
        'username' => 'seconddec80',
        'phone' => '09170000080',
        'position_id' => 12,
        'position_name' => 'Receptionist',
        'token_hash' => str_repeat('f', 64),
        'status' => 'pending',
        'expires_at' => '2026-12-05 00:00:00',
        'revoked_at' => null,
        'revoke_reason' => null,
        'replaced_invitation_id' => null,
        'email_submission_status' => 'pending',
        'email_submitted_at' => null,
        'email_failed_at' => null,
        'email_error' => null,
        'created_by_user_id' => 1,
        'created_at' => '2026-11-20 00:00:00',
        'updated_at' => '2026-11-20 00:00:00',
    ];
    $pdo->invitationRoles[] = ['invitation_id' => 80, 'role_id' => 3];

    // 1) Initial archival of multiple decoupled invitations (50 and 80; accepted 30 must be excluded)
    $first = $svc->archiveForRollback(false);
    assertSameVal(2, $first['archived_invitations'], 'Initial archive must count 2 distinct decoupled invitations.');
    assertSameVal(3, $first['archived_roles'], 'Initial archive must count 3 decoupled role rows.');
    assertSameVal(false, $first['detached_from_live'], 'Detach flag should be false.');
    assertSameVal(0, $first['detached_invitations'], 'Detached invitations must be 0.');
    assertSameVal(0, $first['detached_roles'], 'Detached roles must be 0.');

    // Capture immutable archive provenance for both invitations
    $snap50 = $pdo->archivedInvitations[50];
    $snap80 = $pdo->archivedInvitations[80];

    // 2) Mutate mutable metadata + simulate tampered immutable fields on live rows, then re-archive
    // FakeCleanupPdo simulates MySQL rowCount=2 per updated row (total rowCount=4), which must NOT leak into archived_invitations.
    $pdo->invitations[50]['status'] = 'revoked';
    $pdo->invitations[50]['revoked_at'] = '2026-11-28 01:00:00';
    $pdo->invitations[50]['revoke_reason'] = 'admin_revoked';
    $pdo->invitations[50]['updated_at'] = '2026-11-28 01:00:00';
    $pdo->invitations[50]['invited_email'] = 'tampered50@example.com';
    $pdo->invitations[50]['token_hash'] = str_repeat('9', 64);

    $pdo->invitations[80]['email_submission_status'] = 'failed';
    $pdo->invitations[80]['email_failed_at'] = '2026-11-28 01:05:00';
    $pdo->invitations[80]['email_error'] = 'SMTP error';
    $pdo->invitations[80]['updated_at'] = '2026-11-28 01:05:00';
    $pdo->invitations[80]['first_name'] = 'TamperedFirst';
    $pdo->invitations[80]['created_by_user_id'] = 999;

    $rearchive = $svc->archiveForRollback(false);
    assertSameVal(2, $rearchive['archived_invitations'], 'Re-archive must report 2 distinct invitations (not 4 from ON DUPLICATE KEY UPDATE rowCount).');
    assertSameVal(3, $rearchive['archived_roles'], 'Re-archive must report 3 ensured roles (not 0 from INSERT IGNORE rowCount).');
    assertSameVal(0, $rearchive['detached_invitations'], 'No detachment on re-archive with detach=false.');

    // Mutable refreshed, immutable preserved
    assertSameVal('revoked', $pdo->archivedInvitations[50]['status'], 'Invitation 50 mutable status must refresh.');
    assertSameVal($snap50['invited_email'], $pdo->archivedInvitations[50]['invited_email'], 'Invitation 50 immutable email must be preserved.');
    assertSameVal($snap50['token_hash'], $pdo->archivedInvitations[50]['token_hash'], 'Invitation 50 immutable token_hash must be preserved.');
    assertSameVal('failed', $pdo->archivedInvitations[80]['email_submission_status'], 'Invitation 80 mutable delivery status must refresh.');
    assertSameVal($snap80['first_name'], $pdo->archivedInvitations[80]['first_name'], 'Invitation 80 immutable first_name must be preserved.');
    assertSameVal($snap80['created_by_user_id'], $pdo->archivedInvitations[80]['created_by_user_id'], 'Invitation 80 immutable creator must be preserved.');
    assertSameVal($snap80['created_at'], $pdo->archivedInvitations[80]['created_at'], 'Invitation 80 immutable created_at must be preserved.');

    // 3) Repeated archival with no live changes must still report exact distinct ensured counts
    $repeat = $svc->archiveForRollback(false);
    assertSameVal(2, $repeat['archived_invitations'], 'Repeated archive must report 2 distinct decoupled invitations.');
    assertSameVal(3, $repeat['archived_roles'], 'Repeated archive must report 3 ensured decoupled roles.');
    assertSameVal(2, count($pdo->archivedInvitations), 'Repeated archive must not duplicate archive rows.');
    assertSameVal(3, count($pdo->archivedInvitationRoles), 'Repeated archive must not duplicate role archive rows.');

    // 4) Detach enabled: reports archived (pre-detach) and detached counts accurately, then subsequent run reports 0
    $detach = $svc->archiveForRollback(true);
    assertSameVal(2, $detach['archived_invitations'], 'Detach pass must report 2 distinct decoupled invitations archived before detach.');
    assertSameVal(3, $detach['archived_roles'], 'Detach pass must report 3 decoupled roles archived before detach.');
    assertSameVal(true, $detach['detached_from_live'], 'Detach flag must be true.');
    assertSameVal(2, $detach['detached_invitations'], 'Detach pass must report 2 detached invitations.');
    assertSameVal(3, $detach['detached_roles'], 'Detach pass must report 3 detached roles.');
    if (isset($pdo->invitations[50]) || isset($pdo->invitations[80])) {
        throw new RuntimeException('Decoupled invitations 50 and 80 must be deleted from live table when detach=true.');
    }
    if (!isset($pdo->invitations[30])) {
        throw new RuntimeException('Accepted invitation 30 must remain intact in live table.');
    }

    $afterDetach = $svc->archiveForRollback(true);
    assertSameVal(0, $afterDetach['archived_invitations'], 'Post-detach run must report 0 archived decoupled invitations.');
    assertSameVal(0, $afterDetach['archived_roles'], 'Post-detach run must report 0 archived decoupled roles.');
    assertSameVal(0, $afterDetach['detached_invitations'], 'Post-detach run must report 0 detached invitations.');
    assertSameVal(0, $afterDetach['detached_roles'], 'Post-detach run must report 0 detached roles.');
    assertSameVal(2, count($pdo->archivedInvitations), 'Archive rows remain preserved after detachment.');
    assertSameVal(3, count($pdo->archivedInvitationRoles), 'Archive role rows remain preserved after detachment.');
});

check('10. Non-default employee_work_status (On Duty / Not Present) quarantines candidate while Free or missing row remains cleanable', function () {
    // A. 'On Duty' -> quarantined and excluded from applyCleanup deletion path
    $pdoOnDuty = buildScenarioPdo();
    $pdoOnDuty->employeeWorkStatus[5] = ['employee_id' => 5, 'work_status' => 'On Duty'];
    $svcOnDuty = new InvitationPlaceholderCleanupService($pdoOnDuty);

    $analysisOnDuty = $svcOnDuty->analyze();
    assertSameVal(0, $analysisOnDuty['summary']['proven_placeholder_count'], 'On Duty candidate must not be PROVEN_PLACEHOLDER.');
    assertSameVal(3, $analysisOnDuty['summary']['quarantined_uncertain_count'], 'On Duty candidate must be QUARANTINED_UNCERTAIN.');
    $q5 = null;
    foreach ($analysisOnDuty['quarantined_uncertain'] as $row) {
        if ((int)$row['user_account_id'] === 5) {
            $q5 = $row;
        }
    }
    if ($q5 === null || !in_array('employee_work_status_non_default', $q5['reasons'], true)) {
        throw new RuntimeException('Expected user 5 to be quarantined with employee_work_status_non_default.');
    }
    $applyOnDuty = $svcOnDuty->applyCleanup(true, 1);
    assertSameVal([], $applyOnDuty['cleaned_user_ids'], 'On Duty candidate must not reach cleanup deletion path.');
    if (!isset($pdoOnDuty->users[5], $pdoOnDuty->employees[5], $pdoOnDuty->employeeWorkStatus[5])) {
        throw new RuntimeException('On Duty user, employee, and work_status rows must remain intact.');
    }
    assertSameVal(1, $pdoOnDuty->invitations[50]['is_legacy_quarantined'], 'On Duty candidate invitation must be marked quarantined.');

    // B. 'Not Present' -> quarantined and excluded from applyCleanup deletion path
    $pdoNotPresent = buildScenarioPdo();
    $pdoNotPresent->employeeWorkStatus[5] = ['employee_id' => 5, 'work_status' => 'Not Present'];
    $svcNotPresent = new InvitationPlaceholderCleanupService($pdoNotPresent);
    $applyNotPresent = $svcNotPresent->applyCleanup(true, 1);
    assertSameVal([], $applyNotPresent['cleaned_user_ids'], 'Not Present candidate must not reach cleanup deletion path.');
    if (!isset($pdoNotPresent->users[5], $pdoNotPresent->employees[5], $pdoNotPresent->employeeWorkStatus[5])) {
        throw new RuntimeException('Not Present user, employee, and work_status rows must remain intact.');
    }

    // C. NULL work_status -> treated as unknown/non-default and quarantined (defensive fail-closed)
    $pdoNullWs = buildScenarioPdo();
    $pdoNullWs->employeeWorkStatus[5] = ['employee_id' => 5, 'work_status' => null];
    $svcNullWs = new InvitationPlaceholderCleanupService($pdoNullWs);
    $analysisNullWs = $svcNullWs->analyze();
    assertSameVal(0, $analysisNullWs['summary']['proven_placeholder_count'], 'NULL work_status must not be PROVEN_PLACEHOLDER.');
    $q5Null = null;
    foreach ($analysisNullWs['quarantined_uncertain'] as $row) {
        if ((int)$row['user_account_id'] === 5) {
            $q5Null = $row;
        }
    }
    if ($q5Null === null || !in_array('employee_work_status_non_default', $q5Null['reasons'], true)) {
        throw new RuntimeException('Expected user 5 with NULL work_status to be quarantined with employee_work_status_non_default.');
    }
    $applyNullWs = $svcNullWs->applyCleanup(true, 1);
    assertSameVal([], $applyNullWs['cleaned_user_ids'], 'NULL work_status candidate must not reach cleanup deletion path.');
    if (!isset($pdoNullWs->users[5], $pdoNullWs->employees[5], $pdoNullWs->employeeWorkStatus[5])) {
        throw new RuntimeException('NULL work_status user, employee, and work_status rows must remain intact.');
    }
    assertSameVal(1, $pdoNullWs->invitations[50]['is_legacy_quarantined'], 'NULL work_status candidate invitation must be marked quarantined.');

    // D. Positive: missing employee_work_status row (and 'Free' already covered in Check 3) cleans up cleanly
    $pdoMissingWs = buildScenarioPdo();
    unset($pdoMissingWs->employeeWorkStatus[5]);
    $svcMissingWs = new InvitationPlaceholderCleanupService($pdoMissingWs);
    $applyMissingWs = $svcMissingWs->applyCleanup(true, 1);
    assertSameVal([5], $applyMissingWs['cleaned_user_ids'], 'Missing work_status row must still allow proven placeholder cleanup.');

    // D. Accepted invitee with 'On Duty' remains PROTECTED_GENUINE
    $pdoProt = buildScenarioPdo();
    $pdoProt->employeeWorkStatus[3] = ['employee_id' => 3, 'work_status' => 'On Duty'];
    $svcProt = new InvitationPlaceholderCleanupService($pdoProt);
    $analysisProt = $svcProt->analyze();
    $prot3 = null;
    foreach ($analysisProt['protected_with_invitations'] as $row) {
        if ((int)$row['user_account_id'] === 3) {
            $prot3 = $row;
        }
    }
    if ($prot3 === null || $prot3['classification'] !== InvitationPlaceholderCleanupService::CLASS_PROTECTED) {
        throw new RuntimeException('Accepted account with On Duty work_status must stay PROTECTED_GENUINE.');
    }
});

check('11. Mismatched invitation employee_id on user-linked rows (different employee or NULL) quarantines candidate', function () {
    // A. Invitation linked to user 5 points to employee_id = 3 instead of 5
    $pdoDiffEmp = buildScenarioPdo();
    $pdoDiffEmp->invitations[50]['employee_id'] = 3;
    $svcDiffEmp = new InvitationPlaceholderCleanupService($pdoDiffEmp);

    $analysisA = $svcDiffEmp->analyze();
    assertSameVal(0, $analysisA['summary']['proven_placeholder_count'], 'Mismatched employee_id must block PROVEN_PLACEHOLDER.');
    $q5A = null;
    foreach ($analysisA['quarantined_uncertain'] as $row) {
        if ((int)$row['user_account_id'] === 5) {
            $q5A = $row;
        }
    }
    if ($q5A === null || !in_array('mismatched_invitation_employee_link', $q5A['reasons'], true)) {
        throw new RuntimeException('Expected mismatched_invitation_employee_link quarantine reason.');
    }
    $applyA = $svcDiffEmp->applyCleanup(true, 1);
    assertSameVal([], $applyA['cleaned_user_ids'], 'Mismatched employee_id candidate must not be cleaned.');
    if (!isset($pdoDiffEmp->users[5], $pdoDiffEmp->employees[5], $pdoDiffEmp->employees[3])) {
        throw new RuntimeException('Mismatched cleanup must leave user 5 and employees 3/5 intact.');
    }
    assertSameVal(3, $pdoDiffEmp->invitations[50]['employee_id'], 'Mismatched invitation employee_id must not be detached.');

    // B. Invitation linked to user 5 has employee_id = NULL while user 5 owns employee 5
    $pdoNullEid = buildScenarioPdo();
    $pdoNullEid->invitations[50]['employee_id'] = null;
    $svcNullEid = new InvitationPlaceholderCleanupService($pdoNullEid);

    $analysisB = $svcNullEid->analyze();
    assertSameVal(0, $analysisB['summary']['proven_placeholder_count'], 'NULL employee_id on user-linked invitation must block PROVEN_PLACEHOLDER.');
    $applyB = $svcNullEid->applyCleanup(true, 1);
    assertSameVal([], $applyB['cleaned_user_ids'], 'NULL employee_id mismatch candidate must not be cleaned.');
    if (!isset($pdoNullEid->users[5], $pdoNullEid->employees[5])) {
        throw new RuntimeException('NULL employee_id mismatch must preserve user 5 and employee 5.');
    }
});

check('12. Cross-linked invitations referencing candidate employee_id (another account or NULL) quarantine candidate', function () {
    // A. User 5 has matched invitation 50 (5,5), plus invitation 51 referencing employee 5 under user 4 (accepted)
    $pdoOtherAcct = buildScenarioPdo();
    $pdoOtherAcct->invitations[51] = [
        'invitation_id' => 51,
        'user_account_id' => 4,
        'employee_id' => 5,
        'business_unit_id' => 1,
        'invited_email' => 'cross.other@pawcarino.ph',
        'first_name' => 'Cross',
        'last_name' => 'Link',
        'position_name' => 'Cashier',
        'token_hash' => str_repeat('f', 64),
        'status' => 'accepted',
        'expires_at' => '2026-12-01 00:00:00',
        'accepted_at' => '2026-11-16 00:00:00',
        'email_submission_status' => 'submitted',
        'created_by_user_id' => 1,
        'created_at' => '2026-11-15 00:00:00',
        'updated_at' => '2026-11-16 00:00:00',
    ];
    $svcOther = new InvitationPlaceholderCleanupService($pdoOtherAcct);
    $analysisOther = $svcOther->analyze();
    assertSameVal(0, $analysisOther['summary']['proven_placeholder_count'], 'Cross-linked employee invitation under another account must block PROVEN_PLACEHOLDER.');
    $q5Other = null;
    foreach ($analysisOther['quarantined_uncertain'] as $row) {
        if ((int)$row['user_account_id'] === 5) {
            $q5Other = $row;
        }
    }
    if ($q5Other === null || !in_array('cross_linked_employee_invitation', $q5Other['reasons'], true)) {
        throw new RuntimeException('Expected cross_linked_employee_invitation quarantine reason.');
    }
    $applyOther = $svcOther->applyCleanup(true, 1);
    assertSameVal([], $applyOther['cleaned_user_ids'], 'Cross-linked candidate must not reach cleanup deletion.');
    if (!isset($pdoOtherAcct->users[5], $pdoOtherAcct->employees[5], $pdoOtherAcct->invitations[51])) {
        throw new RuntimeException('Cross-linked accounts/invitations must remain untouched.');
    }

    // B. User 5 has matched invitation 50 (5,5), plus invitation 52 referencing employee 5 with user_account_id = NULL
    $pdoNullUser = buildScenarioPdo();
    $pdoNullUser->invitations[52] = [
        'invitation_id' => 52,
        'user_account_id' => null,
        'employee_id' => 5,
        'business_unit_id' => 1,
        'invited_email' => 'cross.nulluser@pawcarino.ph',
        'first_name' => 'Cross',
        'last_name' => 'NullUser',
        'position_name' => 'Cashier',
        'token_hash' => str_repeat('1', 64),
        'status' => 'pending',
        'expires_at' => '2026-12-01 00:00:00',
        'email_submission_status' => 'submitted',
        'created_by_user_id' => 1,
        'created_at' => '2026-11-15 00:00:00',
        'updated_at' => '2026-11-15 00:00:00',
    ];
    $svcNullUser = new InvitationPlaceholderCleanupService($pdoNullUser);
    $applyNullUser = $svcNullUser->applyCleanup(true, 1);
    assertSameVal([], $applyNullUser['cleaned_user_ids'], 'Employee referenced by NULL-user invitation must not be deleted.');
    if (!isset($pdoNullUser->users[5], $pdoNullUser->employees[5], $pdoNullUser->invitations[52])) {
        throw new RuntimeException('Employee 5 and invitation 52 must remain intact.');
    }
});

check('13. foreignKeyCoverage() distinguishes classification checks from cleanup handling and covers all migration FKs to user_account, customer, and employee', function () {
    $coverage = InvitationPlaceholderCleanupService::foreignKeyCoverage();
    $classKeys = $coverage['classification_checks'];
    $cleanupKeys = $coverage['cleanup_handled'];

    // Verify clear distinction between unconditional blocking checks and cleanup-handled FKs
    assertSameVal('blocking_dependency', $classKeys['pet.customer_id']['check_kind'], 'pet.customer_id must be a classification blocking dependency.');
    assertSameVal('non_default_status', $classKeys['employee_work_status.employee_id']['check_kind'], 'employee_work_status.employee_id must be classified as non_default_status.');
    assertSameVal('identity_link_and_lifecycle', $classKeys['employee_invitation.user_account_id']['check_kind'], 'employee_invitation.user_account_id must be classified as identity_link_and_lifecycle.');
    assertSameVal('identity_link_and_lifecycle', $classKeys['employee_invitation.employee_id']['check_kind'], 'employee_invitation.employee_id must be classified as identity_link_and_lifecycle.');

    foreach (['customer.user_account_id', 'employee.user_account_id', 'account_role.user_account_id'] as $handledOnlyKey) {
        if (isset($classKeys[$handledOnlyKey])) {
            throw new RuntimeException("{$handledOnlyKey} is handled via downstream/snapshot cleanup and must not be a direct blocker key.");
        }
        if (!isset($cleanupKeys[$handledOnlyKey])) {
            throw new RuntimeException("{$handledOnlyKey} must be registered in cleanup_handled.");
        }
    }

    // Static best-effort scan of database/migrations/001..999 for FKs referencing user_account, customer, or employee.
    // Limitations (does NOT replace a live INFORMATION_SCHEMA.KEY_COLUMN_USAGE verifier):
    //  - Does not verify live MySQL schema state or track DROP FOREIGN KEY / DROP TABLE / RENAME TABLE / replacements.
    //  - Regex assumes CREATE TABLE ... ENGINE=, single-column FOREIGN KEY (col) REFERENCES tbl(col), and non-CONCAT PREPARE strings.
    $files = glob(dirname(__DIR__) . '/database/migrations/[0-9][0-9][0-9]_*.sql');
    if (!$files) {
        throw new RuntimeException('No migration files found.');
    }
    sort($files);

    $discovered = [];
    $targetRef = '(?:user_account|customer|employee)';
    foreach ($files as $file) {
        $raw = (string)file_get_contents($file);
        $raw = preg_replace('/^\s*--.*$/m', '', $raw);

        // 1) CREATE TABLE [IF NOT EXISTS] tbl ( ... )
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_]+)`?\s*\((.*?)\)\s*ENGINE=/is', $raw, $tables, PREG_SET_ORDER)) {
            foreach ($tables as $t) {
                $tbl = strtolower($t[1]);
                $body = $t[2];
                if (preg_match_all('/FOREIGN\s+KEY\s*\(\s*`?([a-z_]+)`?\s*\)\s*REFERENCES\s+`?(' . $targetRef . ')`?\s*\(\s*`?([a-z_]+)`?\s*\)/i', $body, $fks, PREG_SET_ORDER)) {
                    foreach ($fks as $fk) {
                        $discovered[$tbl . '.' . strtolower($fk[1])] = strtolower($fk[2]) . '.' . strtolower($fk[3]);
                    }
                }
            }
        }

        // 2) ALTER TABLE tbl ... ADD [CONSTRAINT ...] FOREIGN KEY (...) REFERENCES ... (including inside single-quoted PREPARE strings)
        if (preg_match_all('/ALTER\s+TABLE\s+`?([a-z_]+)`?\s+([^;\']*)/is', $raw, $alters, PREG_SET_ORDER)) {
            foreach ($alters as $a) {
                $tbl = strtolower($a[1]);
                $clause = $a[2];
                if (preg_match_all('/FOREIGN\s+KEY\s*\(\s*`?([a-z_]+)`?\s*\)\s*REFERENCES\s+`?(' . $targetRef . ')`?\s*\(\s*`?([a-z_]+)`?\s*\)/i', $clause, $fks, PREG_SET_ORDER)) {
                    foreach ($fks as $fk) {
                        $discovered[$tbl . '.' . strtolower($fk[1])] = strtolower($fk[2]) . '.' . strtolower($fk[3]);
                    }
                }
            }
        }
    }

    // Must discover at least the 37 current schema FKs
    if (count($discovered) < 37) {
        throw new RuntimeException('Expected at least 37 FKs referencing user_account/customer/employee in migrations, found ' . count($discovered));
    }

    $allCovered = $classKeys + $cleanupKeys;
    $uncovered = [];
    foreach ($discovered as $key => $ref) {
        if (!isset($allCovered[$key])) {
            $uncovered[] = "{$key} -> {$ref}";
        } else {
            $expectedRef = $allCovered[$key]['referenced_table'] . '.' . $allCovered[$key]['referenced_column'];
            if ($expectedRef !== $ref) {
                throw new RuntimeException("FK target mismatch for {$key}: expected {$expectedRef}, migration has {$ref}");
            }
        }
    }
    if ($uncovered) {
        throw new RuntimeException('Uncovered migration FKs found: ' . implode(', ', $uncovered));
    }

    $stale = [];
    foreach ($allCovered as $key => $_meta) {
        if (!isset($discovered[$key])) {
            $stale[] = $key;
        }
    }
    if ($stale) {
        throw new RuntimeException('Stale FK coverage entries not present in migrations: ' . implode(', ', $stale));
    }
});

check('14. applyCleanup() locks employee_work_status FOR UPDATE in-transaction and quarantines on concurrent work_status change', function () {
    // A. Standalone analyze() must NOT acquire FOR UPDATE locks
    $pdoRead = buildScenarioPdo();
    $svcRead = new InvitationPlaceholderCleanupService($pdoRead);
    $svcRead->analyze();
    if (!$pdoRead->workStatusQueries) {
        throw new RuntimeException('Expected analyze() to inspect employee_work_status.');
    }
    foreach ($pdoRead->workStatusQueries as $q) {
        assertSameVal(false, $q['for_update'], 'analyze() must not use FOR UPDATE on employee_work_status.');
        assertSameVal(false, $q['in_tx'], 'analyze() must run outside a transaction.');
    }

    // B. Concurrent transition Free -> On Duty between initial analyze() and transactional FOR UPDATE recheck
    $pdoRace = buildScenarioPdo();
    $pdoRace->employeeWorkStatus[5] = ['employee_id' => 5, 'work_status' => 'Free'];
    $pdoRace->onBeforeWorkStatusForUpdate = static function (FakeCleanupPdo $db, int $eid): void {
        if ($eid === 5) {
            $db->employeeWorkStatus[5]['work_status'] = 'On Duty';
        }
    };
    $svcRace = new InvitationPlaceholderCleanupService($pdoRace);
    $resRace = $svcRace->applyCleanup(true, 1);

    assertSameVal([], $resRace['cleaned_user_ids'], 'Concurrent On Duty transition must prevent cleanup deletion.');
    if (!in_array(5, $resRace['quarantined_user_ids'], true)) {
        throw new RuntimeException('Concurrent On Duty transition must quarantine user 5.');
    }
    if (!isset($pdoRace->users[5], $pdoRace->employees[5], $pdoRace->employeeWorkStatus[5])) {
        throw new RuntimeException('User 5, employee 5, and employee_work_status must remain intact after concurrent transition.');
    }
    assertSameVal('On Duty', $pdoRace->employeeWorkStatus[5]['work_status'], 'Updated work_status must be preserved.');
    assertSameVal(1, $pdoRace->invitations[50]['is_legacy_quarantined'], 'Invitation 50 must be marked quarantined.');

    $emp5Queries = array_values(array_filter(
        $pdoRace->workStatusQueries,
        static fn(array $q): bool => $q['employee_id'] === 5
    ));
    assertSameVal(2, count($emp5Queries), 'Expected 2 employee_work_status reads for employee 5 (initial analyze + transactional recheck).');
    assertSameVal(false, $emp5Queries[0]['for_update'], 'Initial analyze() read must not lock.');
    assertSameVal(false, $emp5Queries[0]['in_tx'], 'Initial analyze() read must be outside transaction.');
    assertSameVal(true, $emp5Queries[1]['for_update'], 'Transactional recheck must lock employee_work_status FOR UPDATE.');
    assertSameVal(true, $emp5Queries[1]['in_tx'], 'Transactional recheck FOR UPDATE must execute inside transaction.');

    // C. Concurrent transition Free -> NULL between initial analyze() and transactional FOR UPDATE recheck
    $pdoRaceNull = buildScenarioPdo();
    $pdoRaceNull->employeeWorkStatus[5] = ['employee_id' => 5, 'work_status' => 'Free'];
    $pdoRaceNull->onBeforeWorkStatusForUpdate = static function (FakeCleanupPdo $db, int $eid): void {
        if ($eid === 5) {
            $db->employeeWorkStatus[5]['work_status'] = null;
        }
    };
    $svcRaceNull = new InvitationPlaceholderCleanupService($pdoRaceNull);
    $resRaceNull = $svcRaceNull->applyCleanup(true, 1);

    assertSameVal([], $resRaceNull['cleaned_user_ids'], 'Concurrent NULL work_status transition must prevent cleanup deletion.');
    if (!in_array(5, $resRaceNull['quarantined_user_ids'], true)) {
        throw new RuntimeException('Concurrent NULL work_status transition must quarantine user 5.');
    }
    if (!isset($pdoRaceNull->users[5], $pdoRaceNull->employees[5], $pdoRaceNull->employeeWorkStatus[5])) {
        throw new RuntimeException('User 5, employee 5, and NULL work_status row must remain intact.');
    }
    if (!array_key_exists('work_status', $pdoRaceNull->employeeWorkStatus[5]) || $pdoRaceNull->employeeWorkStatus[5]['work_status'] !== null) {
        throw new RuntimeException('NULL work_status value must remain unchanged.');
    }
    assertSameVal(1, $pdoRaceNull->invitations[50]['is_legacy_quarantined'], 'Invitation 50 must be marked quarantined after concurrent NULL transition.');

    $emp5NullQueries = array_values(array_filter(
        $pdoRaceNull->workStatusQueries,
        static fn(array $q): bool => $q['employee_id'] === 5
    ));
    assertSameVal(2, count($emp5NullQueries), 'Expected 2 employee_work_status reads for employee 5 in Free -> NULL scenario.');
    assertSameVal(false, $emp5NullQueries[0]['for_update'], 'Initial analyze() read in Free -> NULL scenario must not lock.');
    assertSameVal(false, $emp5NullQueries[0]['in_tx'], 'Initial analyze() read in Free -> NULL scenario must be outside transaction.');
    assertSameVal(true, $emp5NullQueries[1]['for_update'], 'Transactional recheck in Free -> NULL scenario must lock FOR UPDATE.');
    assertSameVal(true, $emp5NullQueries[1]['in_tx'], 'Transactional recheck in Free -> NULL scenario must execute inside transaction.');
});

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
