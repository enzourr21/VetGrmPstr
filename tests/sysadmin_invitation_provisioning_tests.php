<?php
declare(strict_types=1);

/**
 * Isolated Integration Tests for Checkpoint 3 — System Administrator Employee Invitation Provisioning.
 *
 * Uses a stateful in-memory FakeProvisioningPdo double (no live MySQL, no live SMTP, no HTTP server).
 * Verifies:
 *  - Successful System Administrator employee creation + atomic invitation persistence
 *  - Super Administrator unit-scoped invitation provisioning across Pet Store & Veterinary Clinic
 *  - Invitation failure & unauthorized actor rolling back user_account, employee, customer, account_role, and audit_log
 *  - Post-commit email submission (never inside an open DB transaction)
 *  - SMTP failure retaining pending account & invitation for retry
 *  - Unauthorized business-unit assignment rejection
 *  - Super Administrator direct-password employee & System Administrator provisioning ($enforcedUnitId === null)
 *  - Static boundary verification on SuperAdminController & SysAdminController
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
            $this->users = $this->snapshot['users'];
            $this->employees = $this->snapshot['employees'];
            $this->customers = $this->snapshot['customers'];
            $this->accountRoles = $this->snapshot['accountRoles'];
            $this->invitations = $this->snapshot['invitations'];
            $this->auditLogs = $this->snapshot['auditLogs'];
            $this->customerRegistrationPending = $this->snapshot['customerRegistrationPending'];
            $this->emailOtpSendLog = $this->snapshot['emailOtpSendLog'];
            $this->nextUserId = $this->snapshot['nextUserId'];
            $this->nextEmployeeId = $this->snapshot['nextEmployeeId'];
            $this->nextCustomerId = $this->snapshot['nextCustomerId'];
            $this->nextInvitationId = $this->snapshot['nextInvitationId'];
            $this->nextAuditId = $this->snapshot['nextAuditId'];
            $this->nextPendingId = $this->snapshot['nextPendingId'];
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

        // AdminAccountService & CustomerRegistrationPendingService lookups
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

        // AdminAccountService & CustomerRegistrationPendingService inserts/updates/deletes
        if (str_contains($sql, 'INSERT INTO user_account')) {
            $email = strtolower(trim((string)$params[0]));
            $username = $params[1] !== null && trim((string)$params[1]) !== ''
                ? strtolower(trim((string)$params[1]))
                : null;
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
            if (str_contains($sql, 'middle_name')) {
                $this->customers[$id] = [
                    'customer_id' => $id,
                    'user_account_id' => (int)$params[0],
                    'first_name' => (string)$params[1],
                    'middle_name' => $params[2],
                    'last_name' => (string)$params[3],
                    'phone' => $params[4],
                    'address_line1' => $params[5],
                    'city' => $params[6],
                    'province' => $params[7],
                    'postal_code' => $params[8],
                    'customer_status' => 'active',
                ];
            } else {
                $this->customers[$id] = [
                    'customer_id' => $id,
                    'user_account_id' => (int)$params[0],
                    'first_name' => (string)$params[1],
                    'last_name' => (string)$params[2],
                    'phone' => $params[3],
                    'customer_status' => 'active',
                ];
            }
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
        if (str_contains($sql, 'UPDATE customer_registration_pending') && str_contains($sql, 'SET otp_hash = ?, otp_expires_at = ?') && str_contains($sql, 'WHERE pending_id = ?')) {
            $pid = (int)$params[2];
            if (isset($this->customerRegistrationPending[$pid])) {
                $this->customerRegistrationPending[$pid]['otp_hash'] = $params[0];
                $this->customerRegistrationPending[$pid]['otp_expires_at'] = $params[1];
                $rowCount = 1;
            }
            return [];
        }
        if (str_contains($sql, 'INSERT INTO email_otp_send_log')) {
            $this->emailOtpSendLog[] = [
                'email' => (string)$params[0],
                'ip_address' => (string)$params[1],
                'sent_at' => (string)$params[2],
            ];
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'DELETE FROM customer_registration_pending WHERE expires_at < ?')) {
            $cutoff = (string)$params[0];
            foreach ($this->customerRegistrationPending as $pid => $row) {
                if ((string)$row['expires_at'] < $cutoff) {
                    unset($this->customerRegistrationPending[$pid]);
                    $rowCount++;
                }
            }
            return [];
        }
        if (str_contains($sql, 'DELETE FROM email_otp_send_log WHERE sent_at < ?')) {
            $cutoff = (string)$params[0];
            foreach ($this->emailOtpSendLog as $idx => $log) {
                if ((string)$log['sent_at'] < $cutoff) {
                    unset($this->emailOtpSendLog[$idx]);
                    $rowCount++;
                }
            }
            $this->emailOtpSendLog = array_values($this->emailOtpSendLog);
            return [];
        }
        if (str_contains($sql, 'DELETE FROM email_otp_failure_log WHERE failed_at < ?')) {
            return [];
        }
        if (str_contains($sql, 'DELETE FROM customer_registration_pending WHERE pending_id = ?')) {
            $pid = (int)$params[0];
            if (isset($this->customerRegistrationPending[$pid])) {
                unset($this->customerRegistrationPending[$pid]);
                $rowCount = 1;
            }
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
        if (
            str_contains($sql, 'has_any_invitation')
            && str_contains($sql, 'has_accepted_invitation')
            && str_contains($sql, 'ei_any.user_account_id = ?')
        ) {
            $uidAny = (int)$params[0];
            $uidAcc = (int)$params[1];
            $hasAny = false;
            $hasAcc = false;
            foreach ($this->invitations as $inv) {
                if ((int)$inv['user_account_id'] === $uidAny) {
                    $hasAny = true;
                }
                if ((int)$inv['user_account_id'] === $uidAcc && (string)$inv['status'] === 'accepted') {
                    $hasAcc = true;
                }
            }
            return [[
                'has_any_invitation' => $hasAny ? 1 : 0,
                'has_accepted_invitation' => $hasAcc ? 1 : 0,
            ]];
        }
        if (str_contains($sql, 'has_super_role') && str_contains($sql, 'has_unit_sysadmin_role')) {
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
                    && ($this->roles[(int)$ar['role_id']]['role_name'] ?? '') === 'SYSTEM_ADMINISTRATOR') {
                    $hasUnitSys = true;
                    break;
                }
            }
            return [[
                'user_account_id' => $actorUid,
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'employee_id' => $emp['employee_id'] ?? null,
                'employee_status' => $emp['employee_status'] ?? null,
                'business_unit_id' => $unitId,
                'business_unit_status' => $bu['business_unit_status'],
                'has_super_role' => $this->hasSuperRole($actorUid) ? 1 : 0,
                'has_unit_sysadmin_role' => $hasUnitSys ? 1 : 0,
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
            $this->auditLogs[] = [
                'log_id' => $id,
                'actor_user_id' => $params[0] !== null ? (int)$params[0] : null,
                'business_unit_id' => $params[1] !== null ? (int)$params[1] : null,
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

$tempEnv = tempnam(sys_get_temp_dir(), 'pawcarino-cp3-');
if ($tempEnv === false) {
    throw new RuntimeException('Could not create temporary env file.');
}
foreach (['APP_URL', 'MAIL_DRIVER', 'OTP_HMAC_SECRET'] as $k) {
    putenv($k);
    unset($_ENV[$k]);
}
file_put_contents(
    $tempEnv,
    "APP_URL=https://portal.pawcarino.test\nMAIL_DRIVER=null\nOTP_HMAC_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef\n"
);
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

        // Case B1: Cross-unit Vet Clinic SysAdmin (#3) attempting Pet Store (unit 1) invitation creation is rejected 403 and rolled back
        $pdo2 = makeProvisioningFixture();
        $mailCalled2 = false;
        $invSvc2 = new EmployeeInvitationService($pdo2, function () use (&$mailCalled2) {
            $mailCalled2 = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });
        $beforeUsers2 = count($pdo2->users);
        $beforeEmps2 = count($pdo2->employees);
        $beforeRoles2 = count($pdo2->accountRoles);
        $resCrossUnit = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Cross Unit Actor',
            'email' => 'cross.actor@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 3, $pdo2, $invSvc2);
        assertTrue($resCrossUnit['ok'] === false && ($resCrossUnit['status'] ?? 0) === 403 && $pdo2->rollbackCount === 1 && !$pdo2->inTransaction(), 'Cross-unit SysAdmin actor must be rejected 403 and rolled back');
        assertTrue(count($pdo2->users) === $beforeUsers2 && count($pdo2->employees) === $beforeEmps2 && count($pdo2->accountRoles) === $beforeRoles2, 'Zero partial user/employee/role records on cross-unit 403 rollback');
        assertTrue(count($pdo2->customers) === 0 && count($pdo2->invitations) === 0 && count($pdo2->auditLogs) === 0 && !$mailCalled2, 'Zero partial customer/invitation/audit records or email on cross-unit 403 rollback');

        // Case B2: Ordinary non-admin Receptionist (#4) attempting unit 1 invitation creation is rejected 403 and rolled back
        $pdo3 = makeProvisioningFixture();
        $mailCalled3 = false;
        $invSvc3 = new EmployeeInvitationService($pdo3, function () use (&$mailCalled3) {
            $mailCalled3 = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });
        $beforeUsers3 = count($pdo3->users);
        $beforeEmps3 = count($pdo3->employees);
        $beforeRoles3 = count($pdo3->accountRoles);
        $resReceptionist = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Ordinary Actor Attempt',
            'email' => 'ordinary.attempt@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 4, $pdo3, $invSvc3);
        assertTrue($resReceptionist['ok'] === false && ($resReceptionist['status'] ?? 0) === 403 && $pdo3->rollbackCount === 1 && !$pdo3->inTransaction(), 'Ordinary Receptionist actor must be rejected 403 and rolled back');
        assertTrue(count($pdo3->users) === $beforeUsers3 && count($pdo3->employees) === $beforeEmps3 && count($pdo3->accountRoles) === $beforeRoles3, 'Zero partial user/employee/role records on non-admin 403 rollback');
        assertTrue(count($pdo3->customers) === 0 && count($pdo3->invitations) === 0 && count($pdo3->auditLogs) === 0 && !$mailCalled3, 'Zero partial customer/invitation/audit records or email on non-admin 403 rollback');
    });

    test('Super Administrator provisions unit-scoped employee invitations across Pet Store and Veterinary Clinic', function () {
        $pdo = makeProvisioningFixture();
        // Verify fixture actor #1 is a Super Administrator with NO SYSTEM_ADMINISTRATOR role
        assertTrue((int)$pdo->users[1]['is_super_admin'] === 1, 'Fixture actor #1 must have is_super_admin=1');
        $actor1Roles = array_map(
            fn($ar) => $pdo->roles[(int)$ar['role_id']]['role_name'] ?? '',
            array_filter($pdo->accountRoles, fn($ar) => (int)$ar['user_account_id'] === 1)
        );
        assertTrue(in_array('SUPER_ADMINISTRATOR', $actor1Roles, true) && !in_array('SYSTEM_ADMINISTRATOR', $actor1Roles, true), 'Fixture actor #1 must hold SUPER_ADMINISTRATOR and not SYSTEM_ADMINISTRATOR');

        $mailLog = [];
        $invSvc = new EmployeeInvitationService(
            $pdo,
            function (string $email, string $name, string $unit, string $pos, string $url) use ($pdo, &$mailLog): array {
                assertTrue(!$pdo->inTransaction(), 'Email submission MUST occur strictly after commit');
                $mailLog[] = ['email' => $email, 'name' => $name, 'unit' => $unit, 'pos' => $pos, 'url' => $url, 'commits' => $pdo->commitCount];
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            }
        );

        $cases = [
            ['unit_id' => 1, 'unit_name' => 'Pet Store', 'full_name' => 'Elena Santos', 'email' => 'elena.santos@pawcarino.test', 'position' => 'Receptionist', 'roles' => ['RECEPTIONIST']],
            ['unit_id' => 2, 'unit_name' => 'Veterinary Clinic', 'full_name' => 'Marco Cruz', 'email' => 'marco.cruz@pawcarino.test', 'position' => 'Veterinary Receptionist', 'roles' => ['RECEPTIONIST']],
        ];

        $createdByUnit = [];
        $expectedCommits = 0;
        foreach ($cases as $c) {
            $expectedCommits++;
            $mailCountBefore = count($mailLog);

            $res = AdminAccountService::createEmployeeAccount([
                'full_name' => $c['full_name'],
                'email' => $c['email'],
                'business_unit_id' => $c['unit_id'],
                'position' => $c['position'],
                'roles' => $c['roles'],
                'is_invitation' => true,
            ], $c['unit_id'], 1, $pdo, $invSvc);

            assertTrue($res['ok'] === true && $res['is_invitation'] === true, "Super Admin invitation provisioning should succeed for {$c['unit_name']}");
            assertTrue(!array_key_exists('invitation_token', $res) && !array_key_exists('raw_token', $res), 'Raw token must never be returned');
            assertTrue($res['email_submitted'] === true && $res['email_submission_status'] === 'submitted', 'Post-commit email status must be submitted');
            assertTrue(count($mailLog) === $mailCountBefore + 1 && $mailLog[$mailCountBefore]['commits'] === $expectedCommits, 'Post-commit mail must fire once after commit');

            $uid = (int)$res['user_id'];
            $eid = (int)$res['employee_id'];
            $invId = (int)$res['invitation_id'];

            $ua = $pdo->users[$uid];
            $emp = $pdo->employees[$eid];
            $inv = $pdo->invitations[$invId];

            assertTrue($ua['email_verified_at'] === null && (int)$ua['is_super_admin'] === 0 && $ua['account_status'] === 'active', 'Pending user_account invariants must hold');
            assertTrue($emp['user_account_id'] === $uid && $emp['business_unit_id'] === $c['unit_id'] && $emp['position'] === $c['position'] && $emp['employee_status'] === 'active', 'Employee invariants must hold');

            $linkedCustomers = array_values(array_filter($pdo->customers, fn($cust) => (int)$cust['user_account_id'] === $uid && $cust['customer_status'] === 'active'));
            assertTrue(count($linkedCustomers) === 1, 'Linked active customer record must be created');

            $unitRoles = array_values(array_filter($pdo->accountRoles, fn($ar) => (int)$ar['user_account_id'] === $uid && (int)$ar['business_unit_id'] === $c['unit_id']));
            assertTrue(count($unitRoles) === 1 && ($pdo->roles[(int)$unitRoles[0]['role_id']]['role_name'] ?? '') === 'RECEPTIONIST', 'Scoped account_role must be created');

            assertTrue($inv['status'] === 'pending' && (int)$inv['created_by_user_id'] === 1 && (int)$inv['business_unit_id'] === $c['unit_id'] && $inv['email_submission_status'] === 'submitted', 'Invitation row invariants must hold');
            assertTrue(preg_match('/^[0-9a-f]{64}$/', (string)$inv['token_hash']) === 1, 'token_hash must be a 64-char SHA-256 hex digest');
            $capturedUrl = $mailLog[$mailCountBefore]['url'];
            assertTrue(!str_contains($capturedUrl, (string)$inv['token_hash']), 'Stored token_hash must differ from raw URL token');
            assertTrue(preg_match('/[?&]token=([0-9a-f]{64})$/', $capturedUrl, $tokMatch) === 1 && hash_equals((string)$inv['token_hash'], EmployeeInvitationService::hashToken($tokMatch[1])), 'URL raw token must hash to stored token_hash');

            $unitAudits = array_values(array_filter($pdo->auditLogs, fn($a) => $a['business_unit_id'] === $c['unit_id'] && $a['actor_user_id'] === 1));
            $unitCodes = array_column($unitAudits, 'action_code');
            assertTrue(in_array('employee.created', $unitCodes, true) && in_array('invitation.created', $unitCodes, true), "Both employee.created and invitation.created audits with actor_user_id=1 expected for {$c['unit_name']}");

            $createdByUnit[$c['unit_id']] = ['user_id' => $uid, 'employee_id' => $eid, 'invitation_id' => $invId];
        }

        // Super Admin attempting to invite an employee with SUPER_ADMINISTRATOR role must still be rejected
        $beforeUsers = count($pdo->users);
        $beforeEmps = count($pdo->employees);
        $beforeCusts = count($pdo->customers);
        $beforeRoles = count($pdo->accountRoles);
        $beforeInvs = count($pdo->invitations);
        $beforeAudits = count($pdo->auditLogs);
        $beforeMails = count($mailLog);

        $badRoleRes = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Forbidden Super',
            'email' => 'forbidden.super@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['SUPER_ADMINISTRATOR'],
            'is_invitation' => true,
        ], 1, 1, $pdo, $invSvc);

        assertTrue($badRoleRes['ok'] === false && isset($badRoleRes['errors']['roles']), 'SUPER_ADMINISTRATOR role assignment via invitation must be rejected with errors[roles]');
        assertTrue(count($pdo->users) === $beforeUsers && count($pdo->employees) === $beforeEmps && count($pdo->customers) === $beforeCusts && count($pdo->accountRoles) === $beforeRoles && count($pdo->invitations) === $beforeInvs && count($pdo->auditLogs) === $beforeAudits && count($mailLog) === $beforeMails, 'Zero partial records or email on SUPER_ADMINISTRATOR role rejection');

        // Super Admin can resendInvitation and revokeInvitation on created pending invitations across units
        foreach ([1, 2] as $unitId) {
            $eid = $createdByUnit[$unitId]['employee_id'];
            $origInvId = $createdByUnit[$unitId]['invitation_id'];

            $resend = $invSvc->resendInvitation(1, $eid, $unitId);
            assertTrue($resend['ok'] === true && $resend['revoked_count'] === 1 && $resend['email_submitted'] === true && $resend['email_submission_status'] === 'submitted', "Super Admin resendInvitation must succeed for unit {$unitId}");
            $replacementInvId = (int)$resend['invitation_id'];
            assertTrue($pdo->invitations[$origInvId]['status'] === 'revoked', 'Original pending invitation must be revoked on resend');
            assertTrue($pdo->invitations[$replacementInvId]['status'] === 'pending' && (int)$pdo->invitations[$replacementInvId]['created_by_user_id'] === 1, 'Replacement invitation must be pending with created_by_user_id=1');

            $revoke = $invSvc->revokeInvitation(1, $eid, $unitId, 'super_admin_revoked');
            assertTrue($revoke['ok'] === true && $revoke['revoked_count'] === 1 && $revoke['revoked_invitation_ids'] === [$replacementInvId], "Super Admin revokeInvitation must succeed for unit {$unitId}");
            assertTrue($pdo->invitations[$replacementInvId]['status'] === 'revoked', 'Replacement invitation must be revoked');
        }
        $allCodes = array_column(array_filter($pdo->auditLogs, fn($a) => $a['actor_user_id'] === 1), 'action_code');
        assertTrue(in_array('invitation.resent', $allCodes, true) && in_array('invitation.revoked', $allCodes, true), 'Super Admin resend and revoke audits must record actor_user_id=1');
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
        $beforeUsers = count($pdo->users);
        $res = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Cross Unit',
            'email' => 'cross.unit@pawcarino.test',
            'business_unit_id' => 2,
            'position' => 'Veterinary Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);

        assertTrue($res['ok'] === false && isset($res['errors']['business_unit_id']), 'Cross-unit creation must fail validation');
        assertTrue(count($pdo->users) === $beforeUsers && count($pdo->invitations) === 0 && !$mailCalled, 'No records or email on unauthorized unit');
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

    test('Static boundary check: SuperAdminController has no invitation wiring and SysAdminController::storeEmployee allows Super Admin unit-scoped invitations', function () {
        $superSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SuperAdminController.php');
        $sysSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');

        assertTrue(!str_contains($superSrc, 'EmployeeInvitationService') && !str_contains($superSrc, 'is_invitation') && !str_contains($superSrc, 'MailService'), 'SuperAdminController must not wire invitations');

        assertTrue(preg_match('/public function storeEmployee\b.*?\n    \}/s', $sysSrc, $match) === 1, 'SysAdminController::storeEmployee method must exist');
        $storeBody = $match[0];
        assertTrue(!str_contains($storeBody, 'Only business-unit System Administrators can create invited employee accounts'), 'SysAdminController::storeEmployee must not block Super Admin with legacy error message');
        assertTrue(!str_contains($storeBody, 'if ($isSuper)'), 'SysAdminController::storeEmployee must not block $isSuper');
        assertTrue(str_contains($storeBody, 'requireSystemAdmin($routeUnitId)'), 'SysAdminController::storeEmployee must enforce requireSystemAdmin($routeUnitId)');
        assertTrue(str_contains($storeBody, 'CSRF::check()'), 'SysAdminController::storeEmployee must enforce CSRF::check()');
        assertTrue(str_contains($storeBody, "'is_invitation' => true"), 'SysAdminController::storeEmployee must pass is_invitation => true');
        assertTrue(str_contains($storeBody, 'AdminAccountService::createEmployeeAccount($input, $unitId, $actorUid)'), 'SysAdminController::storeEmployee must delegate to AdminAccountService::createEmployeeAccount($input, $unitId, $actorUid)');
        assertTrue(str_contains($storeBody, 'email_submitted'), 'SysAdminController::storeEmployee must inspect email_submitted');
        assertTrue(str_contains($storeBody, '$this->suffix($isSuper, $unitId)'), 'SysAdminController::storeEmployee must preserve $this->suffix($isSuper, $unitId)');
    });

    test('Pending customer OTP registration does NOT block employee invitation and fails safely at customer provisioning on duplicate email', function () {
        $pdo = makeProvisioningFixture();
        $pendingSvc = new CustomerRegistrationPendingService($pdo);
        $sessionToken = CustomerRegistrationPendingService::generateSessionToken();

        $beforeUsers = count($pdo->users);
        $beforeCustomers = count($pdo->customers);

        $initRes = $pendingSvc->initiateRegistration($sessionToken, [
            'email' => 'candidate.staff+otp@gmail.com',
            'first_name' => 'Candidate',
            'last_name' => 'Staff',
        ], '203.0.113.10', '123456');

        assertTrue($initRes['ok'] === true && $initRes['status'] === 200, 'Customer OTP initiation should succeed');
        assertTrue(count($pdo->customerRegistrationPending) === 1, 'One customer_registration_pending row should exist');
        assertTrue(count($pdo->emailOtpSendLog) === 1, 'One email_otp_send_log entry should exist');
        assertTrue(count($pdo->users) === $beforeUsers && count($pdo->customers) === $beforeCustomers, 'Pending OTP registration must create zero user_account or customer rows');

        $pendingSnapshot = $pdo->customerRegistrationPending;
        $sendLogSnapshot = $pdo->emailOtpSendLog;
        $pendingId = (int)array_key_first($pdo->customerRegistrationPending);

        $mailLog = [];
        $invSvc = new EmployeeInvitationService(
            $pdo,
            function (string $email, string $name, string $unit, string $pos, string $url) use (&$mailLog): array {
                $mailLog[] = ['email' => $email, 'name' => $name, 'unit' => $unit, 'pos' => $pos, 'url' => $url];
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            }
        );

        $inviteRes = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Candidate Staff',
            'email' => '  Candidate.Staff+OTP@GMAIL.COM  ',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 1, $pdo, $invSvc);

        assertTrue($inviteRes['ok'] === true && $inviteRes['is_invitation'] === true && $inviteRes['email_submitted'] === true, 'Employee invitation should succeed despite pending customer OTP row');
        assertTrue(count($mailLog) === 1 && $mailLog[0]['email'] === 'candidate.staff+otp@gmail.com', 'Invitation email must be sent to normalized lowercase email');

        $uid = (int)$inviteRes['user_id'];
        $invId = (int)$inviteRes['invitation_id'];
        assertTrue($pdo->users[$uid]['email'] === 'candidate.staff+otp@gmail.com', 'user_account.email must be normalized lowercase');
        assertTrue($pdo->users[$uid]['email_verified_at'] === null, 'Invited employee email_verified_at must be null');
        assertTrue($pdo->invitations[$invId]['status'] === 'pending', 'employee_invitation.status must be pending');
        assertTrue($pdo->customerRegistrationPending === $pendingSnapshot, 'customer_registration_pending row must remain completely unmodified');
        assertTrue($pdo->emailOtpSendLog === $sendLogSnapshot, 'email_otp_send_log must remain completely unmodified');

        // Mark pending row OTP-verified and attempt customer account provisioning -> must hit UNIQUE email 409 safely
        $pdo->customerRegistrationPending[$pendingId]['otp_verified_at'] = gmdate('Y-m-d H:i:s');
        $usersAfterInvite = $pdo->users;
        $employeesAfterInvite = $pdo->employees;
        $customersAfterInvite = $pdo->customers;
        $invitationsAfterInvite = $pdo->invitations;

        $provisionRes = $pendingSvc->provisionVerifiedAccount($sessionToken, 'CustomerPass!2026');
        assertTrue($provisionRes['ok'] === false && ($provisionRes['status'] ?? 0) === 409 && ($provisionRes['conflict_field'] ?? '') === 'email', 'Customer provisioning must fail safely with 409 email conflict');
        assertTrue(!$pdo->inTransaction(), 'Transaction must be rolled back on duplicate email conflict');
        assertTrue($pdo->users === $usersAfterInvite && $pdo->employees === $employeesAfterInvite && $pdo->customers === $customersAfterInvite && $pdo->invitations === $invitationsAfterInvite, 'Invited employee records must remain untouched after 409');
    });

    test('Expired and incomplete (including OTP-verified unprovisioned) pending registrations do NOT block employee invitations', function () {
        $pdo = makeProvisioningFixture();
        $pastTime = gmdate('Y-m-d H:i:s', time() - 3600);
        $futureTime = gmdate('Y-m-d H:i:s', time() + 1800);
        $nowTime = gmdate('Y-m-d H:i:s');

        $expiredId = $pdo->nextPendingId++;
        $pdo->customerRegistrationPending[$expiredId] = [
            'pending_id' => $expiredId,
            'session_token_hash' => hash('sha256', 'expired-session-token'),
            'email' => 'expired.otp@gmail.com',
            'username' => null,
            'first_name' => 'Expired',
            'middle_name' => null,
            'last_name' => 'Otp',
            'phone' => null,
            'address_line1' => null,
            'city' => null,
            'province' => null,
            'postal_code' => null,
            'otp_hash' => hash('sha256', 'dummy-otp'),
            'otp_expires_at' => $pastTime,
            'otp_verified_at' => null,
            'otp_attempts' => 0,
            'cumulative_failed_attempts' => 0,
            'last_sent_at' => $pastTime,
            'conflict_field' => null,
            'created_at' => $pastTime,
            'expires_at' => $pastTime,
        ];

        $incompleteId = $pdo->nextPendingId++;
        $pdo->customerRegistrationPending[$incompleteId] = [
            'pending_id' => $incompleteId,
            'session_token_hash' => hash('sha256', 'incomplete-verified-token'),
            'email' => 'incomplete.verified@gmail.com',
            'username' => null,
            'first_name' => 'Incomplete',
            'middle_name' => null,
            'last_name' => 'Verified',
            'phone' => null,
            'address_line1' => null,
            'city' => null,
            'province' => null,
            'postal_code' => null,
            'otp_hash' => hash('sha256', 'dummy-otp-2'),
            'otp_expires_at' => $futureTime,
            'otp_verified_at' => $nowTime,
            'otp_attempts' => 0,
            'cumulative_failed_attempts' => 0,
            'last_sent_at' => $nowTime,
            'conflict_field' => null,
            'created_at' => $nowTime,
            'expires_at' => $futureTime,
        ];

        $pendingSnapshot = $pdo->customerRegistrationPending;

        $mailLog = [];
        $invSvc = new EmployeeInvitationService(
            $pdo,
            function (string $email, string $name, string $unit, string $pos, string $url) use (&$mailLog): array {
                $mailLog[] = $email;
                return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
            }
        );

        // Invite expired.otp@gmail.com via SysAdmin #2
        $resExpired = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Expired Otp',
            'email' => 'Expired.OTP@gmail.com',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);

        // Invite incomplete.verified@gmail.com via Super Admin #1
        $resIncomplete = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Incomplete Verified',
            'email' => 'INCOMPLETE.VERIFIED@gmail.com',
            'business_unit_id' => 1,
            'position' => 'Inventory Manager',
            'roles' => ['INVENTORY_MANAGER'],
            'is_invitation' => true,
        ], 1, 1, $pdo, $invSvc);

        assertTrue($resExpired['ok'] === true && $resExpired['is_invitation'] === true && $resExpired['email_submitted'] === true, 'Expired pending OTP registration must not block employee invitation');
        assertTrue($resIncomplete['ok'] === true && $resIncomplete['is_invitation'] === true && $resIncomplete['email_submitted'] === true, 'Incomplete OTP-verified pending registration must not block employee invitation');
        assertTrue($mailLog === ['expired.otp@gmail.com', 'incomplete.verified@gmail.com'], 'Both invitations should dispatch email to normalized lowercase addresses');
        assertTrue($pdo->customerRegistrationPending === $pendingSnapshot, 'Neither pending registration row may be deleted or modified');
    });

    test('Existing Account/Customer records and duplicate/lifecycle protections remain enforced on employee invitations', function () {
        $pdo = makeProvisioningFixture();
        $mailCalled = false;
        $invSvc = new EmployeeInvitationService($pdo, function () use (&$mailCalled): array {
            $mailCalled = true;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        // 1. Existing customer-only account (user_account + customer, no employee)
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
            'phone' => null,
            'customer_status' => 'active',
        ];

        // 2. Existing inactive/suspended user_account
        $suspendedUid = $pdo->nextUserId++;
        $pdo->users[$suspendedUid] = [
            'user_account_id' => $suspendedUid,
            'email' => 'suspended.user@gmail.com',
            'username' => 'suspended_user',
            'password_hash' => 'x',
            'account_status' => 'suspended',
            'is_super_admin' => 0,
            'email_verified_at' => '2026-01-01 00:00:00',
        ];

        $beforeUsers = $pdo->users;
        $beforeEmployees = $pdo->employees;
        $beforeCustomers = $pdo->customers;
        $beforeInvitations = $pdo->invitations;
        $beforeAuditLogs = $pdo->auditLogs;

        $resCust = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Existing Customer',
            'email' => 'Existing.Customer@GMAIL.COM',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvc);

        assertTrue($resCust['ok'] === false && ($resCust['errors']['email'] ?? '') === 'An account with this email address already exists.', 'Existing customer-only account must block invitation with duplicate email error');

        $resEmp = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Existing Employee',
            'email' => 'SYSADMIN.PET@pawcarino.test',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 1, $pdo, $invSvc);

        assertTrue($resEmp['ok'] === false && ($resEmp['errors']['email'] ?? '') === 'An account with this email address already exists.', 'Existing employee account must block invitation with duplicate email error');

        $resSusp = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Suspended User',
            'email' => 'SUSPENDED.USER@gmail.com',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 1, $pdo, $invSvc);

        assertTrue($resSusp['ok'] === false && ($resSusp['errors']['email'] ?? '') === 'An account with this email address already exists.', 'Existing suspended user_account must block invitation with duplicate email error');

        assertTrue(
            $pdo->users === $beforeUsers
            && $pdo->employees === $beforeEmployees
            && $pdo->customers === $beforeCustomers
            && $pdo->invitations === $beforeInvitations
            && $pdo->auditLogs === $beforeAuditLogs
            && !$mailCalled,
            'Duplicate rejections must perform zero writes and send no emails'
        );

        // 3. Pending invitation email blocks createEmployeeAccount() and direct EmployeeInvitationService::createInvitation() returns 409
        $inviteMailCount = 0;
        $invSvcLive = new EmployeeInvitationService($pdo, function () use (&$inviteMailCount): array {
            $inviteMailCount++;
            return ['submitted' => true, 'driver' => 'smtp', 'error' => null, 'status_code' => 200];
        });

        $firstInvite = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Pending Staff',
            'email' => 'pending.staff@gmail.com',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvcLive);

        assertTrue($firstInvite['ok'] === true && $inviteMailCount === 1, 'Initial pending invitation should succeed');

        $snapshotAfterFirst = [
            'users' => $pdo->users,
            'employees' => $pdo->employees,
            'customers' => $pdo->customers,
            'invitations' => $pdo->invitations,
            'auditLogs' => $pdo->auditLogs,
        ];

        $dupCreate = AdminAccountService::createEmployeeAccount([
            'full_name' => 'Pending Staff',
            'email' => 'Pending.Staff@GMAIL.COM',
            'business_unit_id' => 1,
            'position' => 'Receptionist',
            'roles' => ['RECEPTIONIST'],
            'is_invitation' => true,
        ], 1, 2, $pdo, $invSvcLive);

        assertTrue($dupCreate['ok'] === false && ($dupCreate['errors']['email'] ?? '') === 'An account with this email address already exists.', 'Pending invitation email must block createEmployeeAccount()');

        $directDup = $invSvcLive->createInvitation(
            2,
            (int)$firstInvite['employee_id'],
            (int)$firstInvite['user_id'],
            1,
            'pending.staff@gmail.com'
        );

        assertTrue($directDup['ok'] === false && ($directDup['status'] ?? 0) === 409, 'Direct EmployeeInvitationService::createInvitation() for employee with pending invitation must fail with 409');
        assertTrue($inviteMailCount === 1, 'Duplicate invitation attempts must not send additional emails');
        assertTrue(
            $pdo->users === $snapshotAfterFirst['users']
            && $pdo->employees === $snapshotAfterFirst['employees']
            && $pdo->customers === $snapshotAfterFirst['customers']
            && $pdo->invitations === $snapshotAfterFirst['invitations']
            && $pdo->auditLogs === $snapshotAfterFirst['auditLogs'],
            'Duplicate createEmployeeAccount and createInvitation must leave all tables unchanged'
        );
    });
} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempEnv);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
