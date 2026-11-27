<?php
declare(strict_types=1);

/**
 * Isolated Integration Tests for Provisioning & Email Eligibility (Decoupled Architecture).
 *
 * Uses a stateful in-memory FakeProvisioningPdo double (no live MySQL, no live SMTP).
 * Verifies:
 *  - Unit-scoped SysAdmin / Super Admin invitation provisioning creates employee_invitation +
 *    employee_invitation_role ONLY (zero user_account, employee, customer, or account_role writes)
 *  - Post-commit email submission (never inside an open DB transaction)
 *  - customer_registration_pending (expired/incomplete) never blocks employee invitations
 *  - CustomerRegistrationPendingService + EmployeeInvitationService share advisory lock order
 *  - Existing active customer email eligibility (allowed without username; 422 with username)
 *  - Existing employee, suspended account, and duplicate active pending invitation protections
 *  - Direct Super Admin employee creation ($enforcedUnitId === null) continues creating accounts directly
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Models/Units.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/CustomerRegistrationPendingService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';
require_once dirname(__DIR__) . '/app/Models/AdminAccountService.php';

use App\Core\EnvConfig;
use App\Models\AdminAccountService;
use App\Services\CustomerRegistrationPendingService;
use App\Services\EmployeeInvitationService;

final class FakeDuplicateKeyPdoException extends PDOException
{
    public function __construct(string $message = 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')
    {
        parent::__construct($message);
        $this->code = '23000';
    }
}

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
    public array $invitationRoles = [];
    public array $auditLogs = [];
    public array $customerRegistrationPending = [];
    public array $emailOtpSendLog = [];

    public int $nextUserId = 100;
    public int $nextEmployeeId = 100;
    public int $nextCustomerId = 100;
    public int $nextInvitationId = 500;
    public int $nextAuditId = 1;
    public int $nextPendingId = 1;
    public int $lastInsertIdVal = 0;

    public bool $inTx = false;
    public int $commitCount = 0;
    public int $rollbackCount = 0;
    public ?string $failOnSqlSubstring = null;
    public array $advisoryLocks = [];
    public int $simulatedNowTs = 1795176000;

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
            'customerRegistrationPending' => $this->customerRegistrationPending,
            'emailOtpSendLog' => $this->emailOtpSendLog,
            'nextUserId' => $this->nextUserId,
            'nextEmployeeId' => $this->nextEmployeeId,
            'nextCustomerId' => $this->nextCustomerId,
            'nextInvitationId' => $this->nextInvitationId,
            'nextAuditId' => $this->nextAuditId,
            'nextPendingId' => $this->nextPendingId,
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
            $this->advisoryLocks[(string)$params[0]] = true;
            return [['1']];
        }
        if (str_contains($sql, 'SELECT RELEASE_LOCK(')) {
            unset($this->advisoryLocks[(string)$params[0]]);
            return [['1']];
        }

        if (str_contains($sql, 'has_super_role') && str_contains($sql, 'WHERE ua.email = ?')) {
            $targetEmail = strtolower(trim((string)$params[0]));
            foreach ($this->users as $uid => $u) {
                if (strtolower(trim((string)$u['email'])) === $targetEmail) {
                    return [[
                        'user_account_id' => $u['user_account_id'],
                        'email' => $u['email'],
                        'username' => $u['username'] ?? null,
                        'account_status' => $u['account_status'],
                        'is_super_admin' => $u['is_super_admin'],
                        'email_verified_at' => $u['email_verified_at'],
                        'has_super_role' => $this->hasSuperRole((int)$uid) ? 1 : 0,
                    ]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'SELECT user_account_id FROM user_account WHERE email = ?')) {
            $targetEmail = strtolower(trim((string)$params[0]));
            foreach ($this->users as $u) {
                if (strtolower(trim((string)$u['email'])) === $targetEmail) {
                    return [[(string)$u['user_account_id']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'SELECT user_account_id FROM user_account WHERE username = ?')) {
            $targetUsername = strtolower(trim((string)$params[0]));
            foreach ($this->users as $u) {
                if (($u['username'] ?? null) !== null && strtolower(trim((string)$u['username'])) === $targetUsername) {
                    return [[(string)$u['user_account_id']]];
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
        if (str_contains($sql, 'SELECT COUNT(*) FROM email_otp_send_log WHERE email = ? AND sent_at >= ?')) {
            $targetEmail = strtolower(trim((string)$params[0]));
            $cutoff = (string)$params[1];
            $count = 0;
            foreach ($this->emailOtpSendLog as $log) {
                if (strtolower(trim((string)$log['email'])) === $targetEmail && (string)$log['sent_at'] >= $cutoff) {
                    $count++;
                }
            }
            return [[(string)$count]];
        }
        if (str_contains($sql, 'SELECT COUNT(*) FROM email_otp_send_log WHERE ip_address = ? AND sent_at >= ?')) {
            $targetIp = (string)$params[0];
            $cutoff = (string)$params[1];
            $count = 0;
            foreach ($this->emailOtpSendLog as $log) {
                if ((string)$log['ip_address'] === $targetIp && (string)$log['sent_at'] >= $cutoff) {
                    $count++;
                }
            }
            return [[(string)$count]];
        }
        if (str_contains($sql, 'FROM customer_registration_pending WHERE session_token_hash = ?')) {
            $tokenHash = (string)$params[0];
            foreach ($this->customerRegistrationPending as $row) {
                if ((string)$row['session_token_hash'] === $tokenHash) {
                    return [$row];
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
        if (str_contains($sql, 'SELECT position_id, position_name FROM business_position')) {
            foreach ($this->positions as $p) {
                if ((int)$p['business_unit_id'] === (int)$params[0] && $p['position_name'] === (string)$params[1] && $p['position_status'] === 'active') {
                    return [['position_id' => $p['position_id'] ?? 1, 'position_name' => $p['position_name']]];
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
                    return [[(string)($p['position_id'] ?? 1)]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM role') && !str_contains($sql, 'account_role') && !str_contains($sql, 'employee_invitation_role')) {
            $rows = [];
            foreach ($this->roles as $id => $r) {
                if (str_contains($sql, "<> 'SUPER_ADMINISTRATOR'") && $r['role_name'] === 'SUPER_ADMINISTRATOR') {
                    continue;
                }
                $rows[] = ['role_id' => $id, 'role_name' => $r['role_name'], 'description' => $r['description']];
            }
            return $rows;
        }
        if (str_contains($sql, 'INSERT INTO user_account')) {
            $email = strtolower(trim((string)$params[0]));
            $username = $params[1] !== null && trim((string)$params[1]) !== '' ? strtolower(trim((string)$params[1])) : null;
            foreach ($this->users as $u) {
                if (strtolower(trim((string)$u['email'])) === $email) {
                    throw new FakeDuplicateKeyPdoException('Duplicate entry for key user_account.email');
                }
                if ($username !== null && ($u['username'] ?? null) !== null && strtolower(trim((string)$u['username'])) === $username) {
                    throw new FakeDuplicateKeyPdoException('Duplicate entry for key user_account.username');
                }
            }
            $id = $this->nextUserId++;
            $this->users[$id] = [
                'user_account_id' => $id,
                'email' => $email,
                'username' => $username,
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
        if (str_contains($sql, 'INSERT INTO customer (')) {
            $id = $this->nextCustomerId++;
            $this->customers[$id] = [
                'customer_id' => $id,
                'user_account_id' => (int)$params[0],
                'first_name' => (string)$params[1],
                'last_name' => (string)($params[3] ?? $params[2]),
                'customer_status' => 'active',
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'INSERT INTO customer_registration_pending')) {
            $id = $this->nextPendingId++;
            $this->customerRegistrationPending[$id] = [
                'pending_id' => $id,
                'session_token_hash' => (string)$params[0],
                'email' => (string)$params[1],
                'username' => $params[2],
                'first_name' => (string)$params[3],
                'middle_name' => $params[4],
                'last_name' => (string)$params[5],
                'phone' => $params[6],
                'address_line1' => $params[7],
                'city' => $params[8],
                'province' => $params[9],
                'postal_code' => $params[10],
                'otp_hash' => null,
                'otp_expires_at' => null,
                'otp_verified_at' => null,
                'otp_attempts' => 0,
                'cumulative_failed_attempts' => 0,
                'last_sent_at' => (string)$params[11],
                'conflict_field' => $params[12],
                'created_at' => (string)$params[13],
                'expires_at' => (string)$params[14],
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'UPDATE customer_registration_pending') && str_contains($sql, 'SET otp_hash = ?, otp_expires_at = ?')) {
            $pid = (int)$params[2];
            if (isset($this->customerRegistrationPending[$pid])) {
                $this->customerRegistrationPending[$pid]['otp_hash'] = $params[0];
                $this->customerRegistrationPending[$pid]['otp_expires_at'] = $params[1];
                $rowCount = 1;
            }
            return [];
        }
        if (str_contains($sql, 'INSERT INTO email_otp_send_log')) {
            $this->emailOtpSendLog[] = ['email' => (string)$params[0], 'ip_address' => (string)$params[1], 'sent_at' => (string)$params[2]];
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'DELETE FROM customer_registration_pending WHERE pending_id = ?')) {
            unset($this->customerRegistrationPending[(int)$params[0]]);
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'DELETE FROM customer_registration_pending WHERE expires_at < ?')
            || str_contains($sql, 'DELETE FROM email_otp_send_log WHERE sent_at < ?')
            || str_contains($sql, 'DELETE FROM email_otp_failure_log WHERE failed_at < ?')) {
            return [];
        }
        if (str_contains($sql, 'INSERT INTO account_role')) {
            $this->accountRoles[] = ['user_account_id' => (int)$params[0], 'role_id' => (int)$params[1], 'business_unit_id' => (int)$params[2]];
            $rowCount = 1;
            return [];
        }

        // Invitation / Auth queries
        if (str_contains($sql, 'ei_any.user_account_id = ?') && str_contains($sql, 'ei_acc.user_account_id = ?')) {
            $uid = (int)$params[0];
            $any = false;
            $acc = false;
            foreach ($this->invitations as $inv) {
                if ((int)($inv['user_account_id'] ?? 0) === $uid) {
                    $any = true;
                    if ($inv['status'] === 'accepted') {
                        $acc = true;
                    }
                }
            }
            return [['has_any_invitation' => $any ? 1 : 0, 'has_accepted_invitation' => $acc ? 1 : 0]];
        }
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
                if ((int)$ar['user_account_id'] === $actorUid && (int)$ar['business_unit_id'] === $unitId && ($this->roles[(int)$ar['role_id']]['role_name'] ?? '') === 'SYSTEM_ADMINISTRATOR') {
                    $hasUnitSys = true;
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
                'has_super_role' => $this->hasSuperRole($actorUid) ? 1 : 0,
                'has_unit_sysadmin_role' => $hasUnitSys ? 1 : 0,
            ]];
        }
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE invited_email = ?')) {
            $em = strtolower(trim((string)$params[0]));
            $rows = array_values(array_filter($this->invitations, fn($i) => strtolower(trim((string)$i['invited_email'])) === $em));
            return $rows;
        }
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE username = ?')) {
            $un = strtolower(trim((string)$params[0]));
            return array_values(array_filter($this->invitations, fn($i) => $i['status'] === 'pending' && ($i['username'] ?? null) !== null && strtolower((string)$i['username']) === $un));
        }
        if (str_contains($sql, 'SELECT invitation_id, business_unit_id, invited_email') && str_contains($sql, 'FROM employee_invitation')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            return $inv ? [$inv] : [];
        }
        if (str_contains($sql, 'FROM employee_invitation ei') && str_contains($sql, 'WHERE ei.invitation_id = ?')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            if (!$inv) {
                return [];
            }
            $bu = $this->businessUnits[(int)$inv['business_unit_id']];
            return [$inv + ['business_unit_name' => $bu['business_unit_name'], 'business_unit_status' => $bu['business_unit_status']]];
        }
        if (str_contains($sql, 'FROM employee_invitation') && str_contains($sql, 'WHERE invitation_id = ?') && str_contains($sql, 'FOR UPDATE')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            return $inv ? [$inv] : [];
        }
        if (str_contains($sql, 'SELECT status, token_hash, email_submission_status')) {
            $inv = $this->invitations[(int)$params[0]] ?? null;
            return $inv ? [['status' => $inv['status'], 'token_hash' => $inv['token_hash'], 'email_submission_status' => $inv['email_submission_status']]] : [];
        }
        if (str_contains($sql, 'FROM employee_invitation_role eir')) {
            $iid = (int)$params[0];
            $rows = [];
            foreach ($this->invitationRoles as $ir) {
                if ((int)$ir['invitation_id'] === $iid) {
                    $rid = (int)$ir['role_id'];
                    $rows[] = ['role_id' => $rid, 'role_name' => $this->roles[$rid]['role_name'] ?? ''];
                }
            }
            return $rows;
        }
        if (str_contains($sql, 'INSERT INTO employee_invitation (')) {
            [
                $uid, $eid, $unitId, $email, $fn, $ln, $un, $ph, $posId, $posName,
                $hash, $exp, $repId, $actorUid, $cAt, $uAt
            ] = $params;
            $id = $this->nextInvitationId++;
            $this->invitations[$id] = [
                'invitation_id' => $id,
                'user_account_id' => null,
                'employee_id' => null,
                'business_unit_id' => (int)$unitId,
                'invited_email' => (string)$email,
                'first_name' => (string)$fn,
                'last_name' => (string)$ln,
                'username' => $un,
                'phone' => $ph,
                'position_id' => $posId,
                'position_name' => (string)$posName,
                'token_hash' => (string)$hash,
                'status' => 'pending',
                'expires_at' => (string)$exp,
                'accepted_at' => null,
                'revoked_at' => null,
                'revoke_reason' => null,
                'replaced_invitation_id' => $repId,
                'email_submission_status' => 'pending',
                'email_submitted_at' => null,
                'email_failed_at' => null,
                'email_error' => null,
                'created_by_user_id' => (int)$actorUid,
                'is_legacy_quarantined' => 0,
                'created_at' => (string)$cAt,
                'updated_at' => (string)$uAt,
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'INSERT INTO employee_invitation_role')) {
            $this->invitationRoles[] = ['invitation_id' => (int)$params[0], 'role_id' => (int)$params[1]];
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, "SET status = 'revoked'")) {
            [$revAt, $reason, $uAt, $id] = $params;
            $id = (int)$id;
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                $this->invitations[$id]['status'] = 'revoked';
                $this->invitations[$id]['revoked_at'] = $revAt;
                $this->invitations[$id]['revoke_reason'] = $reason;
                $this->invitations[$id]['updated_at'] = $uAt;
                $rowCount = 1;
            }
            return [];
        }
        if (str_contains($sql, "SET status = 'expired'")) {
            $id = (int)$params[1];
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
                $this->invitations[$id]['status'] = 'expired';
                $this->invitations[$id]['updated_at'] = (string)$params[0];
                $rowCount = 1;
            }
            return [];
        }
        if (str_contains($sql, "SET email_submission_status = 'submitted'")) {
            $id = (int)$params[2];
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
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
            if (isset($this->invitations[$id]) && $this->invitations[$id]['status'] === 'pending') {
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
            $this->auditLogs[] = [
                'log_id' => $id,
                'actor_user_id' => $params[0],
                'business_unit_id' => $params[1],
                'action_code' => $params[2],
                'summary' => $params[6] ?? '',
            ];
            $this->lastInsertIdVal = $id;
            $rowCount = 1;
            return [];
        }

        throw new RuntimeException("Unexpected SQL in FakeProvisioningPdo: {$sql}");
    }

    private function hasSuperRole(int $uid): bool
    {
        foreach ($this->accountRoles as $ar) {
            if ((int)$ar['user_account_id'] === $uid && ($this->roles[(int)$ar['role_id']]['role_name'] ?? '') === 'SUPER_ADMINISTRATOR') {
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

function makeProvisioningFixture(): FakeProvisioningPdo
{
    $pdo = new FakeProvisioningPdo();
    $pdo->businessUnits = [
        1 => ['business_unit_id' => 1, 'business_unit_name' => 'Pet Store', 'business_unit_status' => 'active'],
        2 => ['business_unit_id' => 2, 'business_unit_name' => 'Veterinary Clinic', 'business_unit_status' => 'active'],
    ];
    $pdo->positions = [
        ['position_id' => 11, 'business_unit_id' => 1, 'position_name' => 'Receptionist', 'position_status' => 'active'],
        ['position_id' => 12, 'business_unit_id' => 1, 'position_name' => 'Inventory Manager', 'position_status' => 'active'],
        ['position_id' => 21, 'business_unit_id' => 2, 'position_name' => 'Veterinary Receptionist', 'position_status' => 'active'],
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
        4 => ['user_account_id' => 4, 'email' => 'receptionist.pet@pawcarino.test', 'username' => 'receptionist_pet', 'password_hash' => 'x', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
    ];
    $pdo->employees = [
        1 => ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Evelyn', 'last_name' => 'Sterling', 'position' => 'Super Administrator', 'employee_status' => 'active'],
        2 => ['employee_id' => 2, 'user_account_id' => 2, 'business_unit_id' => 1, 'first_name' => 'Adrian', 'last_name' => 'Mercer', 'position' => 'System Administrator', 'employee_status' => 'active'],
        3 => ['employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 2, 'first_name' => 'Beatrice', 'last_name' => 'Solis', 'position' => 'System Administrator', 'employee_status' => 'active'],
        4 => ['employee_id' => 4, 'user_account_id' => 4, 'business_unit_id' => 1, 'first_name' => 'Dante', 'last_name' => 'Reyes', 'position' => 'Receptionist', 'employee_status' => 'active'],
    ];
    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 1, 'business_unit_id' => 1],
        ['user_account_id' => 2, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 3, 'role_id' => 2, 'business_unit_id' => 2],
        ['user_account_id' => 4, 'role_id' => 3, 'business_unit_id' => 1],
    ];
    return $pdo;
}

$tempEnv = tempnam(sys_get_temp_dir(), 'pawcarino-cp3-dec-');
foreach (['APP_URL', 'MAIL_DRIVER', 'OTP_HMAC_SECRET'] as $k) {
    putenv($k);
    unset($_ENV[$k]);
}
file_put_contents($tempEnv, "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\nOTP_HMAC_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\n");
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
    echo "=== Decoupled Provisioning & Eligibility Integration Tests (Isolated FakePDO) ===\n\n";

    test('SysAdmin invitation creates ZERO user_account, employee, customer, or account_role rows and sends mail post-commit', function () {
        $pdo = makeProvisioningFixture();
        $mailAfterCommit = false;
        $invSvc = new EmployeeInvitationService($pdo, function () use ($pdo, &$mailAfterCommit): array {
            assertTrue(!$pdo->inTransaction(), 'Email MUST run after commit');
            assertTrue($pdo->commitCount === 1, 'Commit count must be 1');
            $mailAfterCommit = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $uBefore = count($pdo->users);
        $eBefore = count($pdo->employees);
        $cBefore = count($pdo->customers);
        $rBefore = count($pdo->accountRoles);

        $res = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Carla Mendoza',
            'email' => 'carla.mendoza@pawcarino.test',
            'username' => 'carla_m',
            'phone' => '09171112222',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);

        assertTrue($res['ok'] === true && $res['is_invitation'] === true && $mailAfterCommit, 'Invitation provisioning must succeed');
        assertTrue(count($pdo->users) === $uBefore && count($pdo->employees) === $eBefore && count($pdo->customers) === $cBefore && count($pdo->accountRoles) === $rBefore, 'Zero account/employee/customer/role records may exist prior to acceptance');
        $inv = $pdo->invitations[(int)$res['invitation_id']];
        assertTrue($inv['first_name'] === 'Carla' && $inv['last_name'] === 'Mendoza' && $inv['username'] === 'carla_m', 'Profile stored on invitation');
        $codes = array_column($pdo->auditLogs, 'action_code');
        assertTrue($codes === ['invitation.created'], 'Only invitation.created audit emitted (no premature employee.created)');
    });

    test('Super Administrator provisions decoupled invitations across units and can resend/revoke by invitation_id', function () {
        $pdo = makeProvisioningFixture();
        $invSvc = new EmployeeInvitationService($pdo, fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]);

        $r1 = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Elena Santos',
            'email' => 'elena.santos@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 1, $pdo, $invSvc);
        $r2 = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Marco Cruz',
            'email' => 'marco.cruz@pawcarino.test',
            'business_unit_id' => 2,
            'position' => 'Veterinary Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 2, 1, $pdo, $invSvc);
        assertTrue($r1['ok'] === true && $r2['ok'] === true, 'Super Admin creates invitations across both units');

        $rs = $invSvc->resendInvitation(1, (int)$r1['invitation_id'], 1);
        assertTrue($rs['ok'] === true, 'Super Admin resends by invitation_id');
        $rv = $invSvc->revokeInvitation(1, (int)$rs['invitation_id'], 1, 'super_admin_revoked');
        assertTrue($rv['ok'] === true, 'Super Admin revokes by invitation_id');
    });

    test('Existing customer-only account: allowed without username, blocked 422 with username; expired/incomplete OTP never blocks', function () {
        $pdo = makeProvisioningFixture();
        $custUid = $pdo->nextUserId++;
        $pdo->users[$custUid] = [
            'user_account_id' => $custUid,
            'email' => 'existing.customer@gmail.com',
            'username' => 'existing_cust',
            'password_hash' => 'x',
            'account_status' => 'active',
            'is_super_admin' => 0,
            'email_verified_at' => '2026-01-01 00:00:00',
        ];
        $custId = $pdo->nextCustomerId++;
        $pdo->customers[$custId] = [
            'customer_id' => $custId,
            'user_account_id' => $custUid,
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'customer_status' => 'active',
        ];

        $pdo->customerRegistrationPending[1] = [
            'pending_id' => 1,
            'session_token_hash' => hash('sha256', 'tok1'),
            'email' => 'expired.otp@gmail.com',
            'username' => 'exp_otp',
            'first_name' => 'Exp',
            'middle_name' => null,
            'last_name' => 'Otp',
            'phone' => null,
            'address_line1' => null,
            'city' => null,
            'province' => null,
            'postal_code' => null,
            'otp_hash' => null,
            'otp_expires_at' => '2026-11-19 00:00:00',
            'otp_verified_at' => null,
            'otp_attempts' => 0,
            'cumulative_failed_attempts' => 0,
            'last_sent_at' => '2026-11-19 00:00:00',
            'conflict_field' => null,
            'created_at' => '2026-11-19 00:00:00',
            'expires_at' => '2026-11-19 00:30:00',
        ];

        $invSvc = new EmployeeInvitationService($pdo, fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]);

        $resOtp = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Exp Otp',
            'email' => 'Expired.OTP@gmail.com',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);
        assertTrue($resOtp['ok'] === true, 'Expired pending OTP must not block invitation');

        $resCustWithUn = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Existing Customer',
            'email' => 'existing.customer@gmail.com',
            'username' => 'desired_user',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);
        assertTrue($resCustWithUn['ok'] === false && isset($resCustWithUn['errors']['username']), 'Inviting existing customer with username must fail 422');

        $resCustOk = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Existing Customer',
            'email' => 'Existing.Customer@GMAIL.COM',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);
        assertTrue($resCustOk['ok'] === true && ($resCustOk['recipient_mode'] ?? '') === 'existing_customer', 'Inviting active customer without username must succeed');

        // Existing employee email -> rejected
        $resEmp = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Dup Emp',
            'email' => 'sysadmin.pet@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 1, $pdo, $invSvc);
        assertTrue($resEmp['ok'] === false && isset($resEmp['errors']['email']), 'Existing employee email must still be rejected');
    });

    test('CustomerRegistrationPendingService OTP-to-customer conversion shares canonical locks and stays clean', function () {
        $pdo = makeProvisioningFixture();
        $otpSvc = new CustomerRegistrationPendingService($pdo);
        $rawTok = bin2hex(random_bytes(32));
        $nowTime = gmdate('Y-m-d H:i:s', time() - 60);
        $futureTime = gmdate('Y-m-d H:i:s', time() + 1800);
        $pdo->customerRegistrationPending[99] = [
            'pending_id' => 99,
            'session_token_hash' => hash('sha256', $rawTok),
            'email' => 'otp.customer@pawcarino.test',
            'username' => 'otp_cust99',
            'first_name' => 'Otp',
            'middle_name' => null,
            'last_name' => 'Customer',
            'phone' => '09175551234',
            'address_line1' => '123 Pet St',
            'city' => 'Makati',
            'province' => 'Metro Manila',
            'postal_code' => '1200',
            'otp_hash' => 'verified',
            'otp_expires_at' => $futureTime,
            'otp_verified_at' => $nowTime,
            'otp_attempts' => 0,
            'cumulative_failed_attempts' => 0,
            'last_sent_at' => $nowTime,
            'conflict_field' => null,
            'created_at' => $nowTime,
            'expires_at' => $futureTime,
        ];

        $prov = $otpSvc->provisionVerifiedAccount($rawTok, 'CustPass!2026');
        assertTrue($prov['ok'] === true && empty($pdo->advisoryLocks), 'Customer OTP provisioning should succeed and release all locks');
        $invSvc = new EmployeeInvitationService($pdo, fn() => ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200]);
        $invRes = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Otp Customer',
            'email' => 'otp.customer@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);
        assertTrue($invRes['ok'] === true && ($invRes['recipient_mode'] ?? '') === 'existing_customer', 'Newly registered customer can subsequently be invited via Workflow B');
    });

    test('Direct Super Admin provisioning ($enforcedUnitId === null) continues creating accounts directly without invitations', function () {
        $pdo = makeProvisioningFixture();
        $mailCalled = false;
        $invSvc = new EmployeeInvitationService($pdo, function () use (&$mailCalled) {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $empRes = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Direct Staff',
            'email' => 'direct.staff@pawcarino.test',
            'username' => 'direct_staff',
            'password' => 'StrongPass!2026',
            'password_confirm' => 'StrongPass!2026',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
        ], null, 1, $pdo, $invSvc);

        assertTrue($empRes['ok'] === true && !$mailCalled && count($pdo->invitations) === 0, 'Direct Super Admin creation must not create invitations');
        assertTrue(isset($pdo->users[(int)$empRes['user_id']]) && isset($pdo->employees[(int)$empRes['employee_id']]), 'Direct user & employee created immediately');
    });
} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempEnv);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
