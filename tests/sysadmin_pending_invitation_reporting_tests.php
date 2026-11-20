<?php
declare(strict_types=1);

/**
 * Isolated Regression Tests for Checkpoint 3.1 & 3.2 — Invitation History Classification,
 * Dashboard Counting, and Directory Visibility.
 *
 * Uses an in-memory FakeReportingPdo double (no live MySQL, no HTTP server, no SMTP).
 * Verifies:
 *  1. Pending, unexpired invitations remain excluded from active counts.
 *  2. Expired (both explicit 'expired' and past-due 'pending'), never-accepted invitations are excluded.
 *  3. Revoked, never-accepted invitations are excluded.
 *  4. Accepted invitations remain eligible under active-status rules even if older records are expired/revoked.
 *  5. Legacy accounts without invitation history (including email_verified_at=NULL) retain active classification.
 *  6. Employees with multiple invitation records are classified using complete history.
 *  7. Pending, expired, and revoked invitees remain visible in employee directory with accurate status.
 *  8. Expired and revoked invitations do not appear in the pending-invitation attention list.
 *  9. Active System Administrator counts exclude unresolved pending, expired, and revoked invitees.
 * 10. Business-unit isolation and role-counting behavior remain intact.
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';

use App\Services\EmployeeInvitationService;

final class FakeReportingPdo extends PDO
{
    public array $users = [];
    public array $employees = [];
    public array $roles = [];
    public array $accountRoles = [];
    public array $invitations = [];

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new FakeReportingStatement($this, $query);
    }

    public function executeSql(string $sql, array $params): array
    {
        // 1. listUnitEmployeesWithInvitationState
        if (str_contains($sql, 'AS has_pending_invitation') && str_contains($sql, 'FROM employee e')) {
            $hasExplicitNow = str_contains($sql, 'ei_pend.expires_at > ?');
            $unitId = (int)$params[0];
            $nowUtc = $hasExplicitNow ? (string)$params[1] : gmdate('Y-m-d H:i:s');

            $rows = [];
            foreach ($this->employees as $emp) {
                $uid = (int)$emp['user_account_id'];
                $ua = $this->users[$uid] ?? null;
                if (!$ua || (int)$ua['is_super_admin'] !== 0) {
                    continue;
                }

                $roleNames = [];
                foreach ($this->accountRoles as $ar) {
                    if ((int)$ar['user_account_id'] === $uid && (int)$ar['business_unit_id'] === $unitId) {
                        $roleNames[] = $this->roles[(int)$ar['role_id']] ?? '';
                    }
                }
                $roleNames = array_values(array_unique(array_filter($roleNames)));
                sort($roleNames);

                $belongs = ((int)$emp['business_unit_id'] === $unitId) || !empty($roleNames);
                if (!$belongs) {
                    continue;
                }

                $empInvites = [];
                foreach ($this->invitations as $inv) {
                    if (
                        (int)$inv['employee_id'] === (int)$emp['employee_id']
                        && (int)$inv['user_account_id'] === $uid
                        && (int)$inv['business_unit_id'] === (int)$emp['business_unit_id']
                    ) {
                        $empInvites[] = $inv;
                    }
                }
                usort($empInvites, fn($a, $b) => (int)$b['invitation_id'] <=> (int)$a['invitation_id']);

                $hasAny = count($empInvites) > 0 ? 1 : 0;
                $hasAccepted = 0;
                $hasPending = 0;
                $latestStatus = null;

                foreach ($empInvites as $inv) {
                    if ($inv['status'] === 'accepted') {
                        $hasAccepted = 1;
                    }
                    if ($inv['status'] === 'pending' && (string)$inv['expires_at'] > $nowUtc) {
                        $hasPending = 1;
                    }
                }
                if ($empInvites) {
                    $newest = $empInvites[0];
                    $latestStatus = ($newest['status'] === 'pending' && (string)$newest['expires_at'] <= $nowUtc)
                        ? 'expired'
                        : (string)$newest['status'];
                }

                $rows[] = [
                    'employee_id' => $emp['employee_id'],
                    'first_name' => $emp['first_name'],
                    'last_name' => $emp['last_name'],
                    'position' => $emp['position'],
                    'phone' => $emp['phone'] ?? null,
                    'employee_status' => $emp['employee_status'],
                    'created_at' => $emp['created_at'],
                    'home_unit_id' => $emp['business_unit_id'],
                    'user_account_id' => $uid,
                    'email' => $ua['email'],
                    'username' => $ua['username'] ?? null,
                    'account_status' => $ua['account_status'],
                    'is_super_admin' => $ua['is_super_admin'],
                    'unit_roles' => implode(', ', $roleNames),
                    'has_any_invitation' => $hasAny,
                    'has_accepted_invitation' => $hasAccepted,
                    'has_pending_invitation' => $hasPending,
                    'latest_invitation_status' => $latestStatus,
                ];
            }

            usort($rows, fn($a, $b) => $b['employee_id'] <=> $a['employee_id']);
            return $rows;
        }

        // 2. listPendingInvitationsForUnit
        if (str_contains($sql, 'FROM employee_invitation ei') && str_contains($sql, "ei.status = 'pending'")) {
            $hasExplicitNow = str_contains($sql, 'ei.expires_at > ?');
            $unitId = (int)$params[0];
            $nowUtc = $hasExplicitNow ? (string)$params[2] : gmdate('Y-m-d H:i:s');

            $rows = [];
            foreach ($this->invitations as $inv) {
                $emp = $this->employees[(int)$inv['employee_id']] ?? null;
                $ua = $this->users[(int)$inv['user_account_id']] ?? null;
                if (!$emp || !$ua) {
                    continue;
                }

                $hasAccepted = false;
                foreach ($this->invitations as $other) {
                    if (
                        (int)$other['employee_id'] === (int)$emp['employee_id']
                        && (int)$other['user_account_id'] === (int)$ua['user_account_id']
                        && (int)$other['business_unit_id'] === (int)$emp['business_unit_id']
                        && $other['status'] === 'accepted'
                    ) {
                        $hasAccepted = true;
                        break;
                    }
                }
                if ($hasAccepted) {
                    continue;
                }

                if (
                    (int)$inv['business_unit_id'] !== $unitId
                    || (int)$emp['business_unit_id'] !== $unitId
                    || (int)$emp['user_account_id'] !== (int)$inv['user_account_id']
                    || (int)$ua['is_super_admin'] !== 0
                    || $ua['account_status'] !== 'active'
                    || $emp['employee_status'] !== 'active'
                    || $inv['status'] !== 'pending'
                    || (string)$inv['expires_at'] <= $nowUtc
                ) {
                    continue;
                }
                $rows[] = [
                    'invitation_id' => $inv['invitation_id'],
                    'employee_id' => $inv['employee_id'],
                    'user_account_id' => $inv['user_account_id'],
                    'business_unit_id' => $inv['business_unit_id'],
                    'invited_email' => $inv['invited_email'],
                    'invitation_status' => $inv['status'],
                    'expires_at' => $inv['expires_at'],
                    'email_submission_status' => $inv['email_submission_status'],
                    'created_at' => $inv['created_at'],
                    'first_name' => $emp['first_name'],
                    'last_name' => $emp['last_name'],
                    'position' => $emp['position'],
                    'email' => $ua['email'],
                ];
            }

            usort($rows, fn($a, $b) => [$b['created_at'], $b['invitation_id']] <=> [$a['created_at'], $a['invitation_id']]);
            return $rows;
        }

        throw new RuntimeException("Unexpected SQL in FakeReportingPdo: {$sql}");
    }
}

final class FakeReportingStatement extends PDOStatement
{
    private array $rows = [];

    public function __construct(private FakeReportingPdo $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $this->rows = $this->pdo->executeSql($this->sql, $params ?? []);
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}

function makeReportingFixture(): FakeReportingPdo
{
    $pdo = new FakeReportingPdo();
    $pdo->roles = [
        1 => 'SUPER_ADMINISTRATOR',
        2 => 'SYSTEM_ADMINISTRATOR',
        3 => 'RECEPTIONIST',
        4 => 'INVENTORY_MANAGER',
    ];

    $pdo->users = [
        // 1: Active Pet Store SysAdmin (direct/verified, no invitation history)
        1 => ['user_account_id' => 1, 'email' => 'sysadmin.active@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 2: Pending, UNEXPIRED Pet Store Receptionist invitee
        2 => ['user_account_id' => 2, 'email' => 'pending.valid@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 3: Pending, UNEXPIRED Pet Store System Administrator invitee
        3 => ['user_account_id' => 3, 'email' => 'pending.sysadmin@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 4: Stale 'pending' invitation whose expires_at is in the PAST (never accepted)
        4 => ['user_account_id' => 4, 'email' => 'pending.pastdue@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 5: Revoked SysAdmin invitee (never accepted)
        5 => ['user_account_id' => 5, 'email' => 'revoked.sysadmin@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 6: Expired SysAdmin invitee (status='expired', never accepted)
        6 => ['user_account_id' => 6, 'email' => 'expired.sysadmin@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 7: Multi-record history: older expired + revoked, then ACCEPTED -> must be ACTIVE!
        7 => ['user_account_id' => 7, 'email' => 'accepted.after.retry@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-11-19 10:00:00'],
        // 8: Legacy active employee with email_verified_at=NULL and ZERO invitation rows -> must remain ACTIVE!
        8 => ['user_account_id' => 8, 'email' => 'legacy.unverified@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 9: Inactive Pet Store SysAdmin
        9 => ['user_account_id' => 9, 'email' => 'inactive.emp@pawcarino.test', 'account_status' => 'inactive', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 10: Veterinary Clinic (unit 2) expired SysAdmin invitee (must NOT leak into unit 1)
        10 => ['user_account_id' => 10, 'email' => 'vet.expired@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 11: Multi-record history: revoked #1, resent #2 (pending, unexpired) -> must be PENDING & excluded
        11 => ['user_account_id' => 11, 'email' => 'resent.pending@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 12: Multi-record history: accepted #1, plus an anomalous older/newer revoked row -> still ACCEPTED & ACTIVE!
        12 => ['user_account_id' => 12, 'email' => 'accepted.with.extra@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-11-19 11:00:00'],
    ];

    foreach ($pdo->users as $id => $u) {
        $unitId = ($id === 10) ? 2 : 1;
        $pdo->employees[$id] = [
            'employee_id' => $id,
            'user_account_id' => $id,
            'business_unit_id' => $unitId,
            'first_name' => 'Emp' . $id,
            'last_name' => 'Test',
            'position' => in_array($id, [1, 3, 5, 6, 10, 12], true) ? 'System Administrator' : 'Receptionist',
            'employee_status' => ($id === 9) ? 'inactive' : 'active',
            'created_at' => '2026-11-20 10:00:00',
        ];
    }

    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 2, 'business_unit_id' => 1],  // Active SysAdmin (direct)
        ['user_account_id' => 2, 'role_id' => 3, 'business_unit_id' => 1],  // Pending Receptionist
        ['user_account_id' => 3, 'role_id' => 2, 'business_unit_id' => 1],  // Pending SysAdmin
        ['user_account_id' => 4, 'role_id' => 3, 'business_unit_id' => 1],  // Past-due pending Receptionist
        ['user_account_id' => 5, 'role_id' => 2, 'business_unit_id' => 1],  // Revoked SysAdmin (unaccepted)
        ['user_account_id' => 6, 'role_id' => 2, 'business_unit_id' => 1],  // Expired SysAdmin (unaccepted)
        ['user_account_id' => 7, 'role_id' => 4, 'business_unit_id' => 1],  // Accepted Inventory Manager (after retries)
        ['user_account_id' => 8, 'role_id' => 3, 'business_unit_id' => 1],  // Legacy unverified Receptionist
        ['user_account_id' => 9, 'role_id' => 2, 'business_unit_id' => 1],  // Inactive SysAdmin
        ['user_account_id' => 10, 'role_id' => 2, 'business_unit_id' => 2], // Vet Clinic expired SysAdmin
        ['user_account_id' => 11, 'role_id' => 3, 'business_unit_id' => 1], // Resent pending Receptionist
        ['user_account_id' => 12, 'role_id' => 2, 'business_unit_id' => 1], // Accepted SysAdmin (multi-record)
    ];

    $pdo->invitations = [
        101 => ['invitation_id' => 101, 'employee_id' => 2, 'user_account_id' => 2, 'business_unit_id' => 1, 'invited_email' => 'pending.valid@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 12:00:00'],
        102 => ['invitation_id' => 102, 'employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 1, 'invited_email' => 'pending.sysadmin@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 12:05:00'],
        103 => ['invitation_id' => 103, 'employee_id' => 4, 'user_account_id' => 4, 'business_unit_id' => 1, 'invited_email' => 'pending.pastdue@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-20 11:59:59', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-18 11:59:59'],
        104 => ['invitation_id' => 104, 'employee_id' => 5, 'user_account_id' => 5, 'business_unit_id' => 1, 'invited_email' => 'revoked.sysadmin@pawcarino.test', 'status' => 'revoked', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 11:00:00'],
        105 => ['invitation_id' => 105, 'employee_id' => 6, 'user_account_id' => 6, 'business_unit_id' => 1, 'invited_email' => 'expired.sysadmin@pawcarino.test', 'status' => 'expired', 'expires_at' => '2026-11-19 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-17 12:00:00'],
        // Employee #7 multi-record history: expired -> revoked -> accepted
        106 => ['invitation_id' => 106, 'employee_id' => 7, 'user_account_id' => 7, 'business_unit_id' => 1, 'invited_email' => 'accepted.after.retry@pawcarino.test', 'status' => 'expired', 'expires_at' => '2026-11-15 09:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-13 09:00:00'],
        107 => ['invitation_id' => 107, 'employee_id' => 7, 'user_account_id' => 7, 'business_unit_id' => 1, 'invited_email' => 'accepted.after.retry@pawcarino.test', 'status' => 'revoked', 'expires_at' => '2026-11-18 09:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-16 09:00:00'],
        108 => ['invitation_id' => 108, 'employee_id' => 7, 'user_account_id' => 7, 'business_unit_id' => 1, 'invited_email' => 'accepted.after.retry@pawcarino.test', 'status' => 'accepted', 'expires_at' => '2026-11-21 09:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-19 09:00:00'],
        // Unit 2 expired SysAdmin
        109 => ['invitation_id' => 109, 'employee_id' => 10, 'user_account_id' => 10, 'business_unit_id' => 2, 'invited_email' => 'vet.expired@pawcarino.test', 'status' => 'expired', 'expires_at' => '2026-11-19 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-17 12:00:00'],
        // Employee #11 multi-record history: revoked -> pending unexpired
        110 => ['invitation_id' => 110, 'employee_id' => 11, 'user_account_id' => 11, 'business_unit_id' => 1, 'invited_email' => 'resent.pending@pawcarino.test', 'status' => 'revoked', 'expires_at' => '2026-11-22 10:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 10:00:00'],
        111 => ['invitation_id' => 111, 'employee_id' => 11, 'user_account_id' => 11, 'business_unit_id' => 1, 'invited_email' => 'resent.pending@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-22 12:15:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 12:15:00'],
        // Employee #12 multi-record history: accepted #112, plus anomalous later revoked #113 (must STILL classify as accepted!)
        112 => ['invitation_id' => 112, 'employee_id' => 12, 'user_account_id' => 12, 'business_unit_id' => 1, 'invited_email' => 'accepted.with.extra@pawcarino.test', 'status' => 'accepted', 'expires_at' => '2026-11-21 10:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-19 10:00:00'],
        113 => ['invitation_id' => 113, 'employee_id' => 12, 'user_account_id' => 12, 'business_unit_id' => 1, 'invited_email' => 'accepted.with.extra@pawcarino.test', 'status' => 'revoked', 'expires_at' => '2026-11-22 10:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 10:00:00'],
    ];

    return $pdo;
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

$nowUtc = '2026-11-20 12:30:00';

echo "=== Checkpoint 3.1 & 3.2: Invitation History Classification & Dashboard Counting Tests ===\n\n";

test('Only pending, unexpired, never-accepted invitations appear in pending-invitation attention list', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $pendingUnit1 = EmployeeInvitationService::listPendingInvitationsForUnit($pdo, 1, $nowUtc);
    $empIds = array_map(fn($r) => (int)$r['employee_id'], $pendingUnit1);
    sort($empIds);

    assertTrue($empIds === [2, 3, 11], 'Expected only pending unexpired invitees [2, 3, 11] in unit 1, got: ' . json_encode($empIds));
    assertTrue(!in_array(4, $empIds, true), 'Past-due pending invitation must not appear in pending attention list');
    assertTrue(!in_array(5, $empIds, true), 'Revoked invitation must not appear in pending attention list');
    assertTrue(!in_array(6, $empIds, true), 'Expired invitation must not appear in pending attention list');
    assertTrue(!in_array(7, $empIds, true), 'Accepted invitation must not appear in pending attention list');
    assertTrue(!in_array(8, $empIds, true), 'Legacy unverified employee without invitation must not appear');
    assertTrue(!in_array(10, $empIds, true), 'Unit 2 invitation must not leak into unit 1');
});

test('Employee directory preserves visibility of all unit employees and classifies pending, expired, revoked, accepted, and legacy accurately', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $rows = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['employee_id']] = $r;
    }

    assertTrue(count($rows) === 11, 'All 11 Pet Store employees (active, pending, expired, revoked, inactive, legacy) must remain visible');
    assertTrue(!isset($byId[10]), 'Unit 2 employee must not appear in Unit 1 listing');

    assertTrue($byId[1]['invitation_state'] === 'none', 'Direct SysAdmin #1 must be none');
    assertTrue($byId[2]['invitation_state'] === 'pending', 'Valid pending #2 must be pending');
    assertTrue($byId[3]['invitation_state'] === 'pending', 'Valid pending SysAdmin #3 must be pending');
    assertTrue($byId[4]['invitation_state'] === 'expired', 'Past-due pending #4 must classify as expired');
    assertTrue($byId[5]['invitation_state'] === 'revoked', 'Revoked SysAdmin #5 must classify as revoked');
    assertTrue($byId[6]['invitation_state'] === 'expired', 'Expired SysAdmin #6 must classify as expired');
    assertTrue($byId[7]['invitation_state'] === 'accepted', 'Accepted-after-expired/revoked #7 must classify as accepted');
    assertTrue($byId[8]['invitation_state'] === 'none', 'Legacy unverified #8 must classify as none');
    assertTrue($byId[11]['invitation_state'] === 'pending', 'Revoked-then-resent-pending #11 must classify as pending');
    assertTrue($byId[12]['invitation_state'] === 'accepted', 'Accepted-with-extra-revoked #12 must still classify as accepted');
});

test('Active employee and active SysAdmin counts exclude pending, expired, and revoked never-accepted invitees while preserving accepted & legacy accounts', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $rows = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $counts = EmployeeInvitationService::summarizeUnitEmployeeCounts($rows);

    // Unit 1 has 11 employees:
    //  - Excluded as unaccepted invitations (6):
    //      #2 (pending Receptionist), #3 (pending SysAdmin), #11 (resent pending Receptionist),
    //      #4 (past-due expired Receptionist), #5 (revoked SysAdmin), #6 (expired SysAdmin)
    //  - Excluded as inactive (1):
    //      #9 (inactive SysAdmin)
    //  - Included in active_employees (4):
    //      #1 (direct active SysAdmin), #7 (accepted Inventory Manager), #8 (legacy unverified Receptionist), #12 (accepted SysAdmin)
    //  - Included in active_sysadmin_count (2):
    //      #1 (direct active SysAdmin) and #12 (accepted SysAdmin) — excludes #3 (pending), #5 (revoked), #6 (expired), #9 (inactive)!
    assertTrue($counts['total_employees'] === 11, 'total_employees must be 11');
    assertTrue($counts['pending_invitation_count'] === 3, 'Expected 3 genuinely pending invitations (#2, #3, #11), got ' . $counts['pending_invitation_count']);
    assertTrue($counts['unaccepted_invitation_count'] === 6, 'Expected 6 unaccepted invitees (#2, #3, #4, #5, #6, #11), got ' . $counts['unaccepted_invitation_count']);
    assertTrue($counts['active_employees'] === 4, 'Expected 4 active employees (#1, #7, #8, #12), got ' . $counts['active_employees']);
    assertTrue($counts['active_sysadmin_count'] === 2, 'Expected 2 active SysAdmins (#1, #12), got ' . $counts['active_sysadmin_count']);
});

test('Business-unit isolation: unit 2 expired SysAdmin invitee is excluded from unit 2 active counts and pending attention list', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $unit2Rows = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 2, $nowUtc);
    $unit2Counts = EmployeeInvitationService::summarizeUnitEmployeeCounts($unit2Rows);
    $unit2Pending = EmployeeInvitationService::listPendingInvitationsForUnit($pdo, 2, $nowUtc);

    assertTrue($unit2Counts['total_employees'] === 1, 'Unit 2 should show its 1 employee');
    assertTrue($unit2Rows[0]['invitation_state'] === 'expired', 'Unit 2 invitee must classify as expired');
    assertTrue($unit2Counts['active_employees'] === 0, 'Unit 2 active_employees must exclude expired never-accepted invitee');
    assertTrue($unit2Counts['active_sysadmin_count'] === 0, 'Unit 2 active_sysadmin_count must exclude expired never-accepted SysAdmin invitee');
    assertTrue(count($unit2Pending) === 0, 'Expired invitee must not appear in Unit 2 pending attention list');
});

test('Directory view renders accurate badges for pending, expired, and revoked invitations', function () {
    $viewSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Views/sysadmin/employees.php');
    assertTrue(str_contains($viewSrc, 'Pending Invitation'), 'Directory view must render Pending Invitation badge');
    assertTrue(str_contains($viewSrc, 'Invitation Expired'), 'Directory view must render Invitation Expired badge');
    assertTrue(str_contains($viewSrc, 'Invitation Revoked'), 'Directory view must render Invitation Revoked badge');

    $ctrlSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');
    assertTrue(!str_contains($ctrlSrc, 'email_verified_at IS NULL'), 'SysAdminController must not rely on email_verified_at IS NULL');
});

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
