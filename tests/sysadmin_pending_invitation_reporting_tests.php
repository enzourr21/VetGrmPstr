<?php
declare(strict_types=1);

/**
 * Isolated Regression Tests for Checkpoint 3.1, 3.2 & 4.x — Invitation History Classification,
 * Dashboard Counting, Directory Visibility, Action-State Gating, Live View Rendering, and
 * Server-Side Route/Guard Enforcement.
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
 * 11. Roster action-state classification (pending/expired/revoked invitation, active, inactive).
 * 12. Live view rendering of sysadmin/employees.php per-card actions, delivery status, URLs, and no secret leaks.
 * 13. Routes (POST-only), CSRF/auth/unit guards, 409 state-conflict guards, and JS double-submit wiring.
 */

require_once dirname(__DIR__) . '/app/Core/EnvConfig.php';
require_once dirname(__DIR__) . '/app/Core/Core.php';
require_once dirname(__DIR__) . '/app/Models/Units.php';
require_once dirname(__DIR__) . '/app/Models/Audit.php';
require_once dirname(__DIR__) . '/app/Models/AdminAccountService.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/EmployeeInvitationService.php';

if (!function_exists('e')) {
    function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

use App\Core\Database;
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

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = new FakeReportingStatement($this, $query);
        $stmt->execute([]);
        return $stmt;
    }

    public function executeSql(string $sql, array $params): array
    {
        // 0. AdminAccountService::assignableRolesForUnit
        if (str_contains($sql, 'FROM role') && str_contains($sql, 'SUPER_ADMINISTRATOR')) {
            $rows = [];
            foreach ($this->roles as $roleId => $roleName) {
                if ($roleName === 'SUPER_ADMINISTRATOR') {
                    continue;
                }
                $rows[] = [
                    'role_id' => $roleId,
                    'role_name' => $roleName,
                    'description' => $roleName . ' role',
                ];
            }
            return $rows;
        }

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
                $latestEmailStatus = null;

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
                    $latestEmailStatus = $newest['email_submission_status'] ?? null;
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
                    'latest_email_submission_status' => $latestEmailStatus,
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
        102 => ['invitation_id' => 102, 'employee_id' => 3, 'user_account_id' => 3, 'business_unit_id' => 1, 'invited_email' => 'pending.sysadmin@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'failed', 'created_at' => '2026-11-20 12:05:00'],
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
        111 => ['invitation_id' => 111, 'employee_id' => 11, 'user_account_id' => 11, 'business_unit_id' => 1, 'invited_email' => 'resent.pending@pawcarino.test', 'status' => 'pending', 'expires_at' => '2026-11-22 12:15:00', 'email_submission_status' => 'pending', 'created_at' => '2026-11-20 12:15:00'],
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

/**
 * Helper: inject a FakeReportingPdo into Database::$pdo so view partials calling
 * AdminAccountService::initialRolesForPosition() stay 100% isolated from MySQL.
 */
function bindFakeDatabasePdo(PDO $pdo): void
{
    $prop = new ReflectionProperty(Database::class, 'pdo');
    $prop->setValue(null, $pdo);
}

/**
 * Helper: render app/Views/sysadmin/employees.php in isolation and return HTML.
 *
 * @param array<string, mixed> $vars
 */
function renderSysAdminEmployeesView(array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/app/Views/sysadmin/employees.php';
    return (string)ob_get_clean();
}

/**
 * Helper: extract each <article class="roster-item">...</article> indexed by employee ID.
 *
 * @return array<int, string>
 */
function extractRosterCardsByEmployeeId(string $html): array
{
    $cards = [];
    if (preg_match_all('/<article\s+class="roster-item">.*?<\/article>/s', $html, $matches)) {
        foreach ($matches[0] as $cardHtml) {
            if (preg_match('/ID\s*#(\d+)/', $cardHtml, $idMatch)) {
                $cards[(int)$idMatch[1]] = $cardHtml;
            }
        }
    }
    return $cards;
}

/**
 * Helper: extract a PHP method body from source code by method name.
 */
function extractPhpMethodBody(string $source, string $methodName): string
{
    $pattern = '/\bfunction\s+' . preg_quote($methodName, '/') . '\s*\([^)]*\)\s*(?::\s*[^\s{]+)?\s*\{/s';
    if (!preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException("Method {$methodName}() not found in source.");
    }
    $start = (int)$m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($source);
    $i = $start;
    while ($i < $len && $depth > 0) {
        $ch = $source[$i];
        if ($ch === '{') {
            $depth++;
        } elseif ($ch === '}') {
            $depth--;
        }
        $i++;
    }
    if ($depth !== 0) {
        throw new RuntimeException("Unbalanced braces while parsing {$methodName}().");
    }
    return substr($source, $start, $i - $start - 1);
}

test('EmployeeInvitationService::classifyEmployeeRosterActionState classifies pending, expired, revoked, active, and inactive states accurately', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $rows = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['employee_id']] = $r;
    }

    // Fixture pending invitations (#2, #3, #11)
    foreach ([2, 3, 11] as $id) {
        $state = EmployeeInvitationService::classifyEmployeeRosterActionState($byId[$id]);
        assertTrue($state === 'pending_invitation', "Emp #{$id} must classify as pending_invitation, got {$state}");
    }

    // Fixture expired invitations (#4 past-due pending, #6 explicit expired)
    foreach ([4, 6] as $id) {
        $state = EmployeeInvitationService::classifyEmployeeRosterActionState($byId[$id]);
        assertTrue($state === 'expired_invitation', "Emp #{$id} must classify as expired_invitation, got {$state}");
    }

    // Fixture revoked invitation (#5)
    $state5 = EmployeeInvitationService::classifyEmployeeRosterActionState($byId[5]);
    assertTrue($state5 === 'revoked_invitation', "Emp #5 must classify as revoked_invitation, got {$state5}");

    // Fixture active employees (#1 direct, #7 accepted after retries, #8 legacy unverified, #12 accepted with extra revoked)
    foreach ([1, 7, 8, 12] as $id) {
        $state = EmployeeInvitationService::classifyEmployeeRosterActionState($byId[$id]);
        assertTrue($state === 'active', "Emp #{$id} must classify as active, got {$state}");
    }

    // Fixture inactive employee (#9)
    $state9 = EmployeeInvitationService::classifyEmployeeRosterActionState($byId[9]);
    assertTrue($state9 === 'inactive', "Emp #9 must classify as inactive, got {$state9}");

    // Synthetic edge cases: employee_status='inactive' and account_status='suspended'
    $synthEmpInactive = EmployeeInvitationService::classifyEmployeeRosterActionState([
        'account_status' => 'active',
        'employee_status' => 'inactive',
        'invitation_state' => 'none',
    ]);
    assertTrue($synthEmpInactive === 'inactive', "Synthetic employee_status='inactive' must classify as inactive, got {$synthEmpInactive}");

    $synthSuspended = EmployeeInvitationService::classifyEmployeeRosterActionState([
        'account_status' => 'suspended',
        'employee_status' => 'active',
        'invitation_state' => 'none',
    ]);
    assertTrue($synthSuspended === 'inactive', "Synthetic account_status='suspended' must classify as inactive, got {$synthSuspended}");

    // Synthetic inactive account with pending invitation must still classify as inactive
    $synthInactivePending = EmployeeInvitationService::classifyEmployeeRosterActionState([
        'account_status' => 'inactive',
        'employee_status' => 'active',
        'has_any_invitation' => 1,
        'has_accepted_invitation' => 0,
        'has_pending_invitation' => 1,
        'latest_invitation_status' => 'pending',
    ]);
    assertTrue($synthInactivePending === 'inactive', "Inactive account with pending invite must classify as inactive, got {$synthInactivePending}");

    // Verify latest_email_submission_status is populated on fixture rows
    assertTrue($byId[2]['latest_email_submission_status'] === 'submitted', 'Emp #2 email submission status must be submitted');
    assertTrue($byId[3]['latest_email_submission_status'] === 'failed', 'Emp #3 email submission status must be failed');
    assertTrue($byId[11]['latest_email_submission_status'] === 'pending', 'Emp #11 email submission status must be pending');
});

test('Live rendering of sysadmin/employees.php enforces per-state roster actions, delivery status, CSRF/unit fields, and zero secret leaks (Super Admin view)', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    bindFakeDatabasePdo($pdo);

    $_SESSION = [
        'user_id' => 999, // distinct from all fixture employees (#1..#12) so none is treated as $isSelf
        'csrf' => 'isolated-test-csrf-token-value',
    ];

    $employees = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $positions = [
        ['position_name' => 'System Administrator'],
        ['position_name' => 'Receptionist'],
        ['position_name' => 'Inventory Manager'],
    ];
    $assignableRoles = [
        ['role_id' => 2, 'role_name' => 'SYSTEM_ADMINISTRATOR', 'description' => 'System Administrator'],
        ['role_id' => 3, 'role_name' => 'RECEPTIONIST', 'description' => 'Receptionist'],
        ['role_id' => 4, 'role_name' => 'INVENTORY_MANAGER', 'description' => 'Inventory Manager'],
    ];

    $html = renderSysAdminEmployeesView([
        'activeTab' => 'employees',
        'employees' => $employees,
        'positions' => $positions,
        'assignableRoles' => $assignableRoles,
        'unitId' => 1,
        'unitName' => 'Pet Store',
        'isSuper' => true,
        'isPetStore' => true,
        'allUnits' => [],
        'errors' => [],
        'old' => [],
        'currentPage' => 1,
        'totalPages' => 1,
        'totalEmployees' => count($employees),
    ]);

    $cards = extractRosterCardsByEmployeeId($html);
    assertTrue(count($cards) === 11, 'Expected 11 rendered roster cards for Pet Store, got ' . count($cards));

    // Pending Invitation — submitted email (#2)
    $card2 = $cards[2] ?? '';
    assertTrue($card2 !== '', 'Card #2 must be rendered');
    foreach ([
        'Pending Invitation',
        'Email sent',
        '/sysadmin/employees/2/invitation/resend?unit_id=1',
        '/sysadmin/employees/2/invitation/revoke?unit_id=1',
        'Resend Invitation',
        'Revoke Invitation',
        'csrf_token',
        'name="business_unit_id" value="1"',
        'data-prevent-double-submit',
    ] as $mustHave) {
        assertTrue(str_contains($card2, $mustHave), "Card #2 (pending/submitted) must contain '{$mustHave}'");
    }
    foreach (['/position', '/status', 'Change position', 'Deactivate', 'Reactivate'] as $mustNotHave) {
        assertTrue(!str_contains($card2, $mustNotHave), "Card #2 (pending/submitted) must NOT contain '{$mustNotHave}'");
    }

    // Pending Invitation — failed email (#3)
    $card3 = $cards[3] ?? '';
    assertTrue($card3 !== '', 'Card #3 must be rendered');
    foreach ([
        'Pending Invitation',
        'Delivery failed',
        '/sysadmin/employees/3/invitation/resend?unit_id=1',
        '/sysadmin/employees/3/invitation/revoke?unit_id=1',
        'Resend Invitation',
        'Revoke Invitation',
        'csrf_token',
        'name="business_unit_id" value="1"',
        'data-prevent-double-submit',
    ] as $mustHave) {
        assertTrue(str_contains($card3, $mustHave), "Card #3 (pending/failed) must contain '{$mustHave}'");
    }
    foreach (['/position', '/status', 'Change position', 'Deactivate', 'Reactivate'] as $mustNotHave) {
        assertTrue(!str_contains($card3, $mustNotHave), "Card #3 (pending/failed) must NOT contain '{$mustNotHave}'");
    }

    // Pending Invitation — pending email (#11)
    $card11 = $cards[11] ?? '';
    assertTrue($card11 !== '', 'Card #11 must be rendered');
    foreach ([
        'Pending Invitation',
        'Pending delivery',
        '/sysadmin/employees/11/invitation/resend?unit_id=1',
        '/sysadmin/employees/11/invitation/revoke?unit_id=1',
        'Resend Invitation',
        'Revoke Invitation',
        'csrf_token',
        'name="business_unit_id" value="1"',
        'data-prevent-double-submit',
    ] as $mustHave) {
        assertTrue(str_contains($card11, $mustHave), "Card #11 (pending/pending) must contain '{$mustHave}'");
    }
    foreach (['/position', '/status', 'Change position', 'Deactivate', 'Reactivate'] as $mustNotHave) {
        assertTrue(!str_contains($card11, $mustNotHave), "Card #11 (pending/pending) must NOT contain '{$mustNotHave}'");
    }

    // Expired Invitation (#4 past-due & #6 explicit expired)
    foreach ([4, 6] as $expId) {
        $card = $cards[$expId] ?? '';
        assertTrue($card !== '', "Card #{$expId} must be rendered");
        foreach ([
            'Invitation Expired',
            'Resend Invitation',
            "/sysadmin/employees/{$expId}/invitation/resend?unit_id=1",
            'csrf_token',
            'name="business_unit_id" value="1"',
            'data-prevent-double-submit',
        ] as $mustHave) {
            assertTrue(str_contains($card, $mustHave), "Card #{$expId} (expired) must contain '{$mustHave}'");
        }
        foreach (['Revoke Invitation', '/invitation/revoke', '/position', '/status', 'Change position', 'Deactivate', 'Reactivate'] as $mustNotHave) {
            assertTrue(!str_contains($card, $mustNotHave), "Card #{$expId} (expired) must NOT contain '{$mustNotHave}'");
        }
    }

    // Revoked Invitation (#5)
    $card5 = $cards[5] ?? '';
    assertTrue($card5 !== '', 'Card #5 must be rendered');
    assertTrue(str_contains($card5, 'Invitation Revoked'), "Card #5 (revoked) must contain 'Invitation Revoked'");
    foreach (['Resend Invitation', 'Revoke Invitation', '/invitation/resend', '/invitation/revoke', '/position', '/status', 'Change position', 'Deactivate', 'Reactivate'] as $mustNotHave) {
        assertTrue(!str_contains($card5, $mustNotHave), "Card #5 (revoked) must NOT contain '{$mustNotHave}'");
    }

    // Active employees (#1 direct, #7 accepted, #8 legacy, #12 accepted)
    foreach ([1, 7, 8, 12] as $actId) {
        $card = $cards[$actId] ?? '';
        assertTrue($card !== '', "Card #{$actId} must be rendered");
        foreach ([
            'status-active',
            "/sysadmin/employees/{$actId}/position?unit_id=1",
            'Change position',
            "/sysadmin/employees/{$actId}/status?unit_id=1",
            'name="account_status" value="inactive"',
            'Deactivate',
            'csrf_token',
            'name="business_unit_id" value="1"',
        ] as $mustHave) {
            assertTrue(str_contains($card, $mustHave), "Card #{$actId} (active) must contain '{$mustHave}'");
        }
        foreach (['Reactivate', 'Resend Invitation', 'Revoke Invitation', '/invitation/resend', '/invitation/revoke', 'Invitation email'] as $mustNotHave) {
            assertTrue(!str_contains($card, $mustNotHave), "Card #{$actId} (active) must NOT contain '{$mustNotHave}'");
        }
    }

    // Inactive employee (#9)
    $card9 = $cards[9] ?? '';
    assertTrue($card9 !== '', 'Card #9 must be rendered');
    foreach ([
        'status-inactive',
        '/sysadmin/employees/9/status?unit_id=1',
        'name="account_status" value="active"',
        'Reactivate',
        'csrf_token',
        'name="business_unit_id" value="1"',
    ] as $mustHave) {
        assertTrue(str_contains($card9, $mustHave), "Card #9 (inactive) must contain '{$mustHave}'");
    }
    foreach (['/position', 'Change position', 'Deactivate', 'Resend Invitation', 'Revoke Invitation', '/invitation/resend', '/invitation/revoke'] as $mustNotHave) {
        assertTrue(!str_contains($card9, $mustNotHave), "Card #9 (inactive) must NOT contain '{$mustNotHave}'");
    }

    // Verify rendered HTML never leaks raw tokens, password hashes, or SMTP credentials
    foreach (['token_hash', 'raw_token', '/invitation/accept?token=', '$2y$', '$argon2', 'password_hash', 'SMTP_PASS', 'SMTP_USER', 'MAIL_PASSWORD', 'smtp_password'] as $secretPattern) {
        assertTrue(!str_contains($html, $secretPattern), "Rendered employees view must never leak '{$secretPattern}'");
    }
});

test('Live rendering of sysadmin/employees.php omits ?unit_id= query suffix for non-Super-Admin System Admins', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    bindFakeDatabasePdo($pdo);

    $_SESSION = [
        'user_id' => 999,
        'csrf' => 'isolated-test-csrf-token-nonsuper',
    ];

    $employees = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $html = renderSysAdminEmployeesView([
        'activeTab' => 'employees',
        'employees' => $employees,
        'positions' => [
            ['position_name' => 'System Administrator'],
            ['position_name' => 'Receptionist'],
            ['position_name' => 'Inventory Manager'],
        ],
        'assignableRoles' => [
            ['role_id' => 2, 'role_name' => 'SYSTEM_ADMINISTRATOR', 'description' => 'System Administrator'],
            ['role_id' => 3, 'role_name' => 'RECEPTIONIST', 'description' => 'Receptionist'],
            ['role_id' => 4, 'role_name' => 'INVENTORY_MANAGER', 'description' => 'Inventory Manager'],
        ],
        'unitId' => 1,
        'unitName' => 'Pet Store',
        'isSuper' => false,
        'isPetStore' => true,
        'allUnits' => [],
        'errors' => [],
        'old' => [],
        'currentPage' => 1,
        'totalPages' => 1,
        'totalEmployees' => count($employees),
    ]);

    assertTrue(!str_contains($html, '?unit_id='), 'Non-Super-Admin render must not include ?unit_id= in URLs');
    assertTrue(!str_contains($html, '&amp;unit_id='), 'Non-Super-Admin render must not include &unit_id= in URLs');

    $cards = extractRosterCardsByEmployeeId($html);
    assertTrue(str_contains($cards[2] ?? '', 'action="/sysadmin/employees/2/invitation/resend"'), 'Pending card #2 must have clean resend URL');
    assertTrue(str_contains($cards[2] ?? '', 'action="/sysadmin/employees/2/invitation/revoke"'), 'Pending card #2 must have clean revoke URL');
    assertTrue(str_contains($cards[4] ?? '', 'action="/sysadmin/employees/4/invitation/resend"'), 'Expired card #4 must have clean resend URL');
    assertTrue(str_contains($cards[1] ?? '', 'action="/sysadmin/employees/1/position"'), 'Active card #1 must have clean position URL');
    assertTrue(str_contains($cards[1] ?? '', 'action="/sysadmin/employees/1/status"'), 'Active card #1 must have clean status URL');
    assertTrue(str_contains($cards[9] ?? '', 'action="/sysadmin/employees/9/status"'), 'Inactive card #9 must have clean status URL');
});

test('Routes register POST-only invitation resend/revoke endpoints with no GET routes', function () {
    $indexSrc = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');

    assertTrue(
        preg_match("/\\\$r->post\\(\\s*'\\/sysadmin\\/employees\\/\\{id\\}\\/invitation\\/resend'\\s*,\\s*'SysAdminController'\\s*,\\s*'resendEmployeeInvitation'\\s*\\)/", $indexSrc) === 1,
        'public/index.php must register POST /sysadmin/employees/{id}/invitation/resend -> SysAdminController::resendEmployeeInvitation'
    );
    assertTrue(
        preg_match("/\\\$r->post\\(\\s*'\\/sysadmin\\/employees\\/\\{id\\}\\/invitation\\/revoke'\\s*,\\s*'SysAdminController'\\s*,\\s*'revokeEmployeeInvitation'\\s*\\)/", $indexSrc) === 1,
        'public/index.php must register POST /sysadmin/employees/{id}/invitation/revoke -> SysAdminController::revokeEmployeeInvitation'
    );
    assertTrue(
        preg_match("/\\\$r->get\\([^;]*\\/invitation\\/(resend|revoke)/i", $indexSrc) === 0,
        'public/index.php must NOT register any GET route for invitation resend or revoke'
    );
});

test('SysAdminController invitation resend/revoke actions enforce auth, CSRF, unit scope, delegation, and non-destructive revoke', function () {
    $ctrlSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');

    $resendBody = extractPhpMethodBody($ctrlSrc, 'resendEmployeeInvitation');
    foreach ([
        '$this->requireSystemAdmin()',
        'CSRF::check()',
        '$this->verifyEmployeeInUnit(',
        '(int)$emp[\'business_unit_id\'] !== $unitId',
        '$this->forbidden(',
        '->resendInvitation($actorUid, $id, $unitId)',
    ] as $expected) {
        assertTrue(str_contains($resendBody, $expected), "resendEmployeeInvitation() must enforce '{$expected}'");
    }

    $revokeBody = extractPhpMethodBody($ctrlSrc, 'revokeEmployeeInvitation');
    foreach ([
        '$this->requireSystemAdmin()',
        'CSRF::check()',
        '$this->verifyEmployeeInUnit(',
        '(int)$emp[\'business_unit_id\'] !== $unitId',
        '$this->forbidden(',
        '->revokeInvitation($actorUid, $id, $unitId',
    ] as $expected) {
        assertTrue(str_contains($revokeBody, $expected), "revokeEmployeeInvitation() must enforce '{$expected}'");
    }
    assertTrue(!preg_match('/UPDATE\s+user_account\b/i', $revokeBody), 'revokeEmployeeInvitation() must NOT modify user_account status');
    assertTrue(!preg_match('/UPDATE\s+employee\b/i', $revokeBody), 'revokeEmployeeInvitation() must NOT modify employee status');
});

test('SysAdminController::updateEmployeePosition enforces 409 conflict guards for unaccepted invitees and inactive accounts (pre-check and FOR UPDATE)', function () {
    $ctrlSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');
    $body = extractPhpMethodBody($ctrlSrc, 'updateEmployeePosition');

    assertTrue(str_contains($body, '$this->requireSystemAdmin()'), 'updateEmployeePosition() must enforce requireSystemAdmin()');
    assertTrue(str_contains($body, 'CSRF::check()'), 'updateEmployeePosition() must enforce CSRF::check()');
    assertTrue(str_contains($body, '$this->verifyEmployeeInUnit('), 'updateEmployeePosition() must verify employee in unit');

    $txPos = strpos($body, '$this->db->beginTransaction()');
    $updatePos = strpos($body, 'UPDATE employee SET position');
    assertTrue($txPos !== false && $updatePos !== false && $txPos < $updatePos, 'updateEmployeePosition() must wrap position update in a transaction');

    $preTx = substr($body, 0, $txPos);
    $inTxBeforeUpdate = substr($body, $txPos, $updatePos - $txPos);

    // Pre-transaction checks
    assertTrue(str_contains($preTx, 'EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation'), 'updateEmployeePosition() must pre-check unaccepted invitations');
    assertTrue(
        str_contains($preTx, "account_status'] !== 'active'") && str_contains($preTx, "employee_status'] !== 'active'"),
        'updateEmployeePosition() must pre-check active account_status and employee_status'
    );
    assertTrue(substr_count($preTx, 'http_response_code(409)') >= 2, 'updateEmployeePosition() pre-checks must return HTTP 409 for both unaccepted invitees and inactive accounts');

    // In-transaction FOR UPDATE re-checks before UPDATE
    assertTrue(str_contains($inTxBeforeUpdate, 'SELECT account_status FROM user_account WHERE user_account_id = ? FOR UPDATE'), 'updateEmployeePosition() must lock user_account FOR UPDATE');
    assertTrue(str_contains($inTxBeforeUpdate, 'SELECT employee_status, position FROM employee WHERE employee_id = ? FOR UPDATE'), 'updateEmployeePosition() must lock employee FOR UPDATE');
    assertTrue(str_contains($inTxBeforeUpdate, 'EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation'), 'updateEmployeePosition() must re-check unaccepted invitations under FOR UPDATE');
    assertTrue(
        str_contains($inTxBeforeUpdate, "\$lockedAccountStatus !== 'active'") && str_contains($inTxBeforeUpdate, "employee_status'] !== 'active'"),
        'updateEmployeePosition() must re-check active status under FOR UPDATE'
    );
    assertTrue(substr_count($inTxBeforeUpdate, 'http_response_code(409)') >= 2, 'updateEmployeePosition() FOR UPDATE re-checks must return HTTP 409');
    assertTrue(substr_count($inTxBeforeUpdate, '$this->db->rollBack()') >= 2, 'updateEmployeePosition() must roll back before returning 409');
});

test('SysAdminController::updateEmployeeStatus enforces 409 guard for unaccepted invitees (pre-check and FOR UPDATE) without altering position or roles', function () {
    $ctrlSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');
    $body = extractPhpMethodBody($ctrlSrc, 'updateEmployeeStatus');

    assertTrue(str_contains($body, '$this->requireSystemAdmin()'), 'updateEmployeeStatus() must enforce requireSystemAdmin()');
    assertTrue(str_contains($body, 'CSRF::check()'), 'updateEmployeeStatus() must enforce CSRF::check()');
    assertTrue(str_contains($body, '$this->verifyEmployeeInUnit('), 'updateEmployeeStatus() must verify employee in unit');

    $txPos = strpos($body, '$this->db->beginTransaction()');
    $updatePos = strpos($body, 'UPDATE user_account SET account_status');
    assertTrue($txPos !== false && $updatePos !== false && $txPos < $updatePos, 'updateEmployeeStatus() must wrap status update in a transaction');

    $preTx = substr($body, 0, $txPos);
    $inTxBeforeUpdate = substr($body, $txPos, $updatePos - $txPos);

    assertTrue(str_contains($preTx, 'EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation'), 'updateEmployeeStatus() must pre-check unaccepted invitations');
    assertTrue(str_contains($preTx, 'http_response_code(409)'), 'updateEmployeeStatus() pre-check must return HTTP 409');

    assertTrue(str_contains($inTxBeforeUpdate, 'SELECT account_status FROM user_account WHERE user_account_id = ? FOR UPDATE'), 'updateEmployeeStatus() must lock user_account FOR UPDATE');
    assertTrue(str_contains($inTxBeforeUpdate, 'SELECT employee_status FROM employee WHERE employee_id = ? FOR UPDATE'), 'updateEmployeeStatus() must lock employee FOR UPDATE');
    assertTrue(str_contains($inTxBeforeUpdate, 'EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation'), 'updateEmployeeStatus() must re-check unaccepted invitations under FOR UPDATE');
    assertTrue(str_contains($inTxBeforeUpdate, 'http_response_code(409)'), 'updateEmployeeStatus() FOR UPDATE re-check must return HTTP 409');
    assertTrue(str_contains($inTxBeforeUpdate, '$this->db->rollBack()'), 'updateEmployeeStatus() must roll back before returning 409');

    // Must not alter employee.position or account_role during status change/reactivation
    assertTrue(!preg_match('/UPDATE\s+employee\s+SET\b[^;]*\bposition\b/i', $body), 'updateEmployeeStatus() must not alter employee.position');
    assertTrue(!str_contains($body, 'account_role'), 'updateEmployeeStatus() must not alter account_role assignments');
});

test('app.js wires data-prevent-double-submit cleanly after data-confirm-action without breaking confirm cancellation', function () {
    $jsSrc = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');

    $confirmPos = strpos($jsSrc, "document.querySelectorAll('[data-confirm-action]')");
    $doublePos = strpos($jsSrc, "document.querySelectorAll('form[data-prevent-double-submit]')");
    assertTrue($confirmPos !== false, 'app.js must wire [data-confirm-action] listener');
    assertTrue($doublePos !== false, 'app.js must wire form[data-prevent-double-submit] listener');
    assertTrue($confirmPos < $doublePos, '[data-confirm-action] must be registered before form[data-prevent-double-submit] so confirm cancellation prevents submit lock');

    $confirmSnippet = substr($jsSrc, $confirmPos, $doublePos - $confirmPos);
    assertTrue(str_contains($confirmSnippet, '!window.confirm(msg)') && str_contains($confirmSnippet, 'ev.preventDefault()'), 'data-confirm-action must call ev.preventDefault() on cancel');
    assertTrue(str_contains($confirmSnippet, 'el._submitting'), 'data-confirm-action must skip re-prompting while form is already submitting');

    $doubleSnippet = substr($jsSrc, $doublePos, 900);
    $preventedPos = strpos($doubleSnippet, 'if (ev.defaultPrevented) return;');
    $lockPos = strpos($doubleSnippet, 'form._submitting = true;');
    $disablePos = strpos($doubleSnippet, 'btn.disabled = true;');
    assertTrue($preventedPos !== false, 'data-prevent-double-submit must bail out early when ev.defaultPrevented is true');
    assertTrue($lockPos !== false && $preventedPos < $lockPos, 'ev.defaultPrevented check must run before setting form._submitting = true');
    assertTrue($disablePos !== false && $lockPos < $disablePos, 'form._submitting guard must run before disabling submit buttons');
});

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
