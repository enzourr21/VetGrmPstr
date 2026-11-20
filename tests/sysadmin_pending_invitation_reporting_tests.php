<?php
declare(strict_types=1);

/**
 * Isolated Regression Tests for Checkpoint 3.1 — Pending Invitation State & Dashboard Counting.
 *
 * Uses an in-memory FakeReportingPdo double (no live MySQL, no HTTP server, no SMTP).
 * Verifies:
 *  1. Pending, unexpired invitation is identified as pending.
 *  2. Pending invitation whose expires_at <= UTC now is NOT classified as pending.
 *  3. Accepted, revoked, and expired invitations are NOT classified as pending.
 *  4. Legacy employee with email_verified_at IS NULL and no invitation record is NOT misclassified as pending.
 *  5. Pending invitees are excluded from active_employees count.
 *  6. Pending System Administrator invitees are excluded from active_sysadmin_count.
 *  7. Pending invitees remain visible in unit employee-management listings.
 *  8. Business-unit isolation and existing role-counting behavior remain intact.
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
            $hasExplicitNow = str_contains($sql, 'ei.expires_at > ?');
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

                $hasPending = 0;
                foreach ($this->invitations as $inv) {
                    if (
                        (int)$inv['employee_id'] === (int)$emp['employee_id']
                        && (int)$inv['user_account_id'] === $uid
                        && (int)$inv['business_unit_id'] === (int)$emp['business_unit_id']
                        && $inv['status'] === 'pending'
                        && (string)$inv['expires_at'] > $nowUtc
                    ) {
                        $hasPending = 1;
                        break;
                    }
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
                    'has_pending_invitation' => $hasPending,
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
        // 1: Active Pet Store SysAdmin (verified)
        1 => ['user_account_id' => 1, 'email' => 'sysadmin.active@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 2: Pending, UNEXPIRED Pet Store Receptionist invitee
        2 => ['user_account_id' => 2, 'email' => 'pending.valid@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 3: Pending, UNEXPIRED Pet Store System Administrator invitee
        3 => ['user_account_id' => 3, 'email' => 'pending.sysadmin@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 4: Stale 'pending' invitation whose expires_at is in the PAST
        4 => ['user_account_id' => 4, 'email' => 'pending.pastdue@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 5: Revoked invitation
        5 => ['user_account_id' => 5, 'email' => 'revoked.inv@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 6: Expired invitation (status='expired')
        6 => ['user_account_id' => 6, 'email' => 'expired.inv@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 7: Accepted invitation (verified)
        7 => ['user_account_id' => 7, 'email' => 'accepted.inv@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => '2026-11-19 10:00:00'],
        // 8: Legacy active employee with email_verified_at=NULL and ZERO invitation rows
        8 => ['user_account_id' => 8, 'email' => 'legacy.unverified@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
        // 9: Inactive Pet Store employee
        9 => ['user_account_id' => 9, 'email' => 'inactive.emp@pawcarino.test', 'account_status' => 'inactive', 'is_super_admin' => 0, 'email_verified_at' => '2026-01-01 00:00:00'],
        // 10: Pending, UNEXPIRED Veterinary Clinic (unit 2) invitee (must NOT leak into Pet Store unit 1)
        10 => ['user_account_id' => 10, 'email' => 'vet.pending@pawcarino.test', 'account_status' => 'active', 'is_super_admin' => 0, 'email_verified_at' => null],
    ];

    foreach ($pdo->users as $id => $u) {
        $unitId = ($id === 10) ? 2 : 1;
        $pdo->employees[$id] = [
            'employee_id' => $id,
            'user_account_id' => $id,
            'business_unit_id' => $unitId,
            'first_name' => 'Emp' . $id,
            'last_name' => 'Test',
            'position' => ($id === 1 || $id === 3) ? 'System Administrator' : 'Receptionist',
            'employee_status' => ($id === 9) ? 'inactive' : 'active',
            'created_at' => '2026-11-20 10:00:00',
        ];
    }

    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 2, 'business_unit_id' => 1], // Active SysAdmin
        ['user_account_id' => 2, 'role_id' => 3, 'business_unit_id' => 1], // Pending Receptionist
        ['user_account_id' => 3, 'role_id' => 2, 'business_unit_id' => 1], // Pending SysAdmin
        ['user_account_id' => 4, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 5, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 6, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 7, 'role_id' => 4, 'business_unit_id' => 1],
        ['user_account_id' => 8, 'role_id' => 3, 'business_unit_id' => 1], // Legacy unverified
        ['user_account_id' => 9, 'role_id' => 2, 'business_unit_id' => 1], // Inactive SysAdmin
        ['user_account_id' => 10, 'role_id' => 2, 'business_unit_id' => 2], // Vet Clinic pending SysAdmin
    ];

    $pdo->invitations = [
        101 => ['invitation_id' => 101, 'employee_id' => 2, 'user_account_id' => 2, 'business_unit_id' => 1, 'invited_email' => 'pending.valid@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 12:00:00'],
        102 => ['invitation_id' => 102, 'employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 1, 'invited_email' => 'pending.sysadmin@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 12:05:00'],
        103 => ['invitation_id' => 103, 'employee_id' => 4, 'user_account_id' => 4, 'business_unit_id' => 1, 'invited_email' => 'pending.pastdue@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-20 11:59:59', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-18 11:59:59'],
        104 => ['invitation_id' => 104, 'employee_id' => 5, 'user_account_id' => 5, 'business_unit_id' => 1, 'invited_email' => 'revoked.inv@pawcarino.test', 'status' => 'revoked', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 11:00:00'],
        105 => ['invitation_id' => 105, 'employee_id' => 6, 'user_account_id' => 6, 'business_unit_id' => 1, 'invited_email' => 'expired.inv@pawcarino.test', 'status' => 'expired', 'expires_at' => '2026-11-19 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-17 12:00:00'],
        106 => ['invitation_id' => 106, 'employee_id' => 7, 'user_account_id' => 7, 'business_unit_id' => 1, 'invited_email' => 'accepted.inv@pawcarino.test', 'status' => 'accepted', 'expires_at' => '2026-11-21 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-19 09:00:00'],
        107 => ['invitation_id' => 107, 'employee_id' => 10, 'user_account_id' => 10, 'business_unit_id' => 2, 'invited_email' => 'vet.pending@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 12:10:00'],
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

echo "=== Checkpoint 3.1: Pending Invitation State & Dashboard Counting Tests ===\n\n";

test('Only pending, unexpired invitations in the target unit are returned by listPendingInvitationsForUnit', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $pendingUnit1 = EmployeeInvitationService::listPendingInvitationsForUnit($pdo, 1, $nowUtc);
    $empIds = array_map(fn($r) => (int)$r['employee_id'], $pendingUnit1);
    sort($empIds);

    assertTrue($empIds === [2, 3], 'Expected only unexpired pending invitees [2, 3] in unit 1, got: ' . json_encode($empIds));
    assertTrue(!in_array(4, $empIds, true), 'Past-due pending invitation must not be listed as pending');
    assertTrue(!in_array(5, $empIds, true), 'Revoked invitation must not be listed as pending');
    assertTrue(!in_array(6, $empIds, true), 'Expired invitation must not be listed as pending');
    assertTrue(!in_array(7, $empIds, true), 'Accepted invitation must not be listed as pending');
    assertTrue(!in_array(8, $empIds, true), 'Legacy unverified employee without invitation must not be listed as pending');
    assertTrue(!in_array(10, $empIds, true), 'Unit 2 pending invitee must not leak into unit 1');
});

test('Unit employee listing preserves visibility of pending invitees and flags only genuinely pending unexpired ones', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $rows = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['employee_id']] = $r;
    }

    assertTrue(count($rows) === 9, 'All 9 Pet Store employees (including pending and inactive) must remain visible');
    assertTrue(!isset($byId[10]), 'Unit 2 employee must not appear in Unit 1 listing');

    assertTrue(!empty($byId[2]['has_pending_invitation']), 'Valid pending invitee #2 must be flagged pending');
    assertTrue(!empty($byId[3]['has_pending_invitation']), 'Valid pending SysAdmin #3 must be flagged pending');
    assertTrue(empty($byId[4]['has_pending_invitation']), 'Past-due pending invitee #4 must NOT be flagged pending');
    assertTrue(empty($byId[5]['has_pending_invitation']), 'Revoked invitee #5 must NOT be flagged pending');
    assertTrue(empty($byId[6]['has_pending_invitation']), 'Expired invitee #6 must NOT be flagged pending');
    assertTrue(empty($byId[7]['has_pending_invitation']), 'Accepted invitee #7 must NOT be flagged pending');
    assertTrue(empty($byId[8]['has_pending_invitation']), 'Legacy unverified employee #8 must NOT be flagged pending');
    assertTrue(empty($byId[1]['has_pending_invitation']), 'Active SysAdmin #1 must NOT be flagged pending');
});

test('summarizeUnitEmployeeCounts excludes pending invitees from active employee and active SysAdmin counts while preserving legacy accounts', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $rows = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $counts = EmployeeInvitationService::summarizeUnitEmployeeCounts($rows);

    // Unit 1 has 9 employees:
    //  - 2 genuinely pending unexpired (#2 Receptionist, #3 SysAdmin) -> excluded from active counts
    //  - 1 inactive (#9 SysAdmin) -> excluded from active counts
    //  - 6 active non-pending (#1 Active SysAdmin, #4 past-due, #5 revoked, #6 expired, #7 accepted, #8 legacy unverified)
    assertTrue($counts['total_employees'] === 9, 'total_employees must include all 9 unit employees');
    assertTrue($counts['pending_invitation_count'] === 2, 'Expected 2 genuinely pending invitations, got ' . $counts['pending_invitation_count']);
    assertTrue($counts['active_employees'] === 6, 'Expected 6 active non-pending employees, got ' . $counts['active_employees']);
    assertTrue($counts['active_sysadmin_count'] === 1, 'Expected 1 active SysAdmin (#1 only; #3 is pending, #9 is inactive), got ' . $counts['active_sysadmin_count']);
});

test('Business-unit isolation: unit 2 pending SysAdmin is counted as pending in unit 2 and excluded from unit 2 active SysAdmin count', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $unit2Rows = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 2, $nowUtc);
    $unit2Counts = EmployeeInvitationService::summarizeUnitEmployeeCounts($unit2Rows);

    assertTrue($unit2Counts['total_employees'] === 1, 'Unit 2 should show its 1 employee');
    assertTrue($unit2Counts['pending_invitation_count'] === 1, 'Unit 2 should have 1 pending invitation');
    assertTrue($unit2Counts['active_employees'] === 0, 'Unit 2 active_employees must exclude pending invitee');
    assertTrue($unit2Counts['active_sysadmin_count'] === 0, 'Unit 2 active_sysadmin_count must exclude pending SysAdmin invitee');
});

test('Static check: SysAdminController no longer identifies pending invitations via email_verified_at IS NULL', function () {
    $src = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');
    assertTrue(!str_contains($src, 'email_verified_at IS NULL'), 'SysAdminController must not identify pending invitations via email_verified_at IS NULL');
    assertTrue(str_contains($src, 'listPendingInvitationsForUnit'), 'SysAdminController::dashboard must use listPendingInvitationsForUnit');
    assertTrue(str_contains($src, 'summarizeUnitEmployeeCounts'), 'SysAdminController::dashboard must use summarizeUnitEmployeeCounts');
});

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
