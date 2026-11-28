<?php
declare(strict_types=1);

/**
 * Isolated Regression Tests for Decoupled Reporting, Action-State Gating,
 * Two-Section Unit Employees View Rendering, and Route/Guard Enforcement.
 *
 * Uses an in-memory FakeReportingPdo double (no live MySQL, no HTTP server, no SMTP).
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
    public array $invitationRoles = [];

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
                $rows[] = ['role_id' => $roleId, 'role_name' => $roleName, 'description' => $roleName . ' role'];
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

                if ((int)$emp['business_unit_id'] !== $unitId && empty($roleNames)) {
                    continue;
                }

                $empInvites = array_values(array_filter($this->invitations, fn($inv) =>
                    (int)($inv['employee_id'] ?? 0) === (int)$emp['employee_id']
                    && (int)($inv['user_account_id'] ?? 0) === $uid
                    && (int)$inv['business_unit_id'] === (int)$emp['business_unit_id']
                ));
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
                    $latestStatus = ($newest['status'] === 'pending' && (string)$newest['expires_at'] <= $nowUtc) ? 'expired' : (string)$newest['status'];
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

        // 2. listUnitInvitations (active pending + unreplaced latest expired)
        if (str_contains($sql, 'AS action_state') && str_contains($sql, 'FROM employee_invitation ei')) {
            $hasExplicitNow = str_contains($sql, 'ei.expires_at > ?');
            $nowUtc = $hasExplicitNow ? (string)$params[0] : gmdate('Y-m-d H:i:s');
            $unitId = $hasExplicitNow ? (int)$params[2] : (int)$params[0];

            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)$inv['business_unit_id'] !== $unitId) {
                    continue;
                }
                $emailLower = strtolower((string)$inv['invited_email']);
                $isActivePending = ($inv['status'] === 'pending' && (string)$inv['expires_at'] > $nowUtc);
                $isUnreplacedExpired = false;
                if (!$isActivePending && ($inv['status'] === 'expired' || ($inv['status'] === 'pending' && (string)$inv['expires_at'] <= $nowUtc))) {
                    $maxIdForEmail = 0;
                    foreach ($this->invitations as $other) {
                        if ((int)$other['business_unit_id'] === $unitId && strtolower((string)$other['invited_email']) === $emailLower) {
                            $maxIdForEmail = max($maxIdForEmail, (int)$other['invitation_id']);
                        }
                    }
                    $hasEmployeeWithEmail = false;
                    foreach ($this->users as $uid => $ua) {
                        if (strtolower((string)$ua['email']) === $emailLower) {
                            foreach ($this->employees as $emp) {
                                if ((int)$emp['user_account_id'] === (int)$uid) {
                                    $hasEmployeeWithEmail = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    $isUnreplacedExpired = ((int)$inv['invitation_id'] === $maxIdForEmail) && !$hasEmployeeWithEmail;
                }

                if (!$isActivePending && !$isUnreplacedExpired) {
                    continue;
                }

                $roleNames = [];
                foreach ($this->invitationRoles as $ir) {
                    if ((int)$ir['invitation_id'] === (int)$inv['invitation_id']) {
                        $roleNames[] = $this->roles[(int)$ir['role_id']] ?? '';
                    }
                }
                sort($roleNames);

                $rows[] = [
                    'invitation_id' => $inv['invitation_id'],
                    'business_unit_id' => $inv['business_unit_id'],
                    'invited_email' => $inv['invited_email'],
                    'first_name' => $inv['first_name'],
                    'last_name' => $inv['last_name'],
                    'username' => $inv['username'] ?? null,
                    'phone' => $inv['phone'] ?? null,
                    'position_id' => $inv['position_id'] ?? null,
                    'position_name' => $inv['position_name'],
                    'status' => $inv['status'],
                    'expires_at' => $inv['expires_at'],
                    'email_submission_status' => $inv['email_submission_status'],
                    'email_submitted_at' => $inv['email_submitted_at'] ?? null,
                    'email_failed_at' => $inv['email_failed_at'] ?? null,
                    'created_at' => $inv['created_at'],
                    'is_legacy_quarantined' => $inv['is_legacy_quarantined'] ?? 0,
                    'invitation_state' => $isActivePending ? 'pending' : 'expired',
                    'action_state' => $isActivePending ? 'pending_invitation' : 'expired_invitation',
                    'unit_roles' => implode(', ', $roleNames),
                ];
            }

            usort($rows, function ($a, $b) {
                $pA = $a['action_state'] === 'pending_invitation' ? 0 : 1;
                $pB = $b['action_state'] === 'pending_invitation' ? 0 : 1;
                return [$pA, $b['created_at'], (int)$b['invitation_id']] <=> [$pB, $a['created_at'], (int)$a['invitation_id']];
            });
            return $rows;
        }

        // 3. Decoupled listPendingInvitationsForUnit (reads employee_invitation directly)
        if (str_contains($sql, 'FROM employee_invitation ei') && str_contains($sql, "ei.status = 'pending'")) {
            $hasExplicitNow = str_contains($sql, 'ei.expires_at > ?');
            $unitId = (int)$params[0];
            $nowUtc = $hasExplicitNow ? (string)$params[1] : gmdate('Y-m-d H:i:s');

            $rows = [];
            foreach ($this->invitations as $inv) {
                if ((int)$inv['business_unit_id'] === $unitId && $inv['status'] === 'pending' && (string)$inv['expires_at'] > $nowUtc) {
                    $rows[] = [
                        'invitation_id' => $inv['invitation_id'],
                        'business_unit_id' => $inv['business_unit_id'],
                        'invited_email' => $inv['invited_email'],
                        'email' => $inv['invited_email'],
                        'first_name' => $inv['first_name'],
                        'last_name' => $inv['last_name'],
                        'position' => $inv['position_name'],
                        'invitation_status' => 'pending',
                        'expires_at' => $inv['expires_at'],
                        'email_submission_status' => $inv['email_submission_status'],
                        'created_at' => $inv['created_at'],
                    ];
                }
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
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
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

    // Real employees in unit 1 + unit 2
    $pdo->users = [
        1 => ['user_account_id' => 1, 'email' => 'sysadmin.active@pawcarino.test', 'username' => 'sysadmin1', 'account_status' => 'active', 'is_super_admin' => 0],
        7 => ['user_account_id' => 7, 'email' => 'accepted.after.retry@pawcarino.test', 'username' => 'emp7', 'account_status' => 'active', 'is_super_admin' => 0],
        8 => ['user_account_id' => 8, 'email' => 'legacy.unverified@pawcarino.test', 'username' => 'emp8', 'account_status' => 'active', 'is_super_admin' => 0],
        9 => ['user_account_id' => 9, 'email' => 'inactive.emp@pawcarino.test', 'username' => 'emp9', 'account_status' => 'active', 'is_super_admin' => 0], // customer active, employee_status inactive!
        10 => ['user_account_id' => 10, 'email' => 'suspended.emp@pawcarino.test', 'username' => 'emp10', 'account_status' => 'suspended', 'is_super_admin' => 0],
        // Existing customer-only user with zero employee row
        30 => ['user_account_id' => 30, 'email' => 'existing.customer@example.com', 'username' => 'cust30', 'account_status' => 'active', 'is_super_admin' => 0],
    ];

    $pdo->employees = [
        1 => ['employee_id' => 1, 'user_account_id' => 1, 'business_unit_id' => 1, 'first_name' => 'Emp1', 'last_name' => 'Test', 'position' => 'System Administrator', 'employee_status' => 'active', 'created_at' => '2026-11-01 10:00:00'],
        7 => ['employee_id' => 7, 'user_account_id' => 7, 'business_unit_id' => 1, 'first_name' => 'Emp7', 'last_name' => 'Test', 'position' => 'Inventory Manager', 'employee_status' => 'active', 'created_at' => '2026-11-19 10:00:00'],
        8 => ['employee_id' => 8, 'user_account_id' => 8, 'business_unit_id' => 1, 'first_name' => 'Emp8', 'last_name' => 'Test', 'position' => 'Receptionist', 'employee_status' => 'active', 'created_at' => '2026-10-01 10:00:00'],
        9 => ['employee_id' => 9, 'user_account_id' => 9, 'business_unit_id' => 1, 'first_name' => 'Emp9', 'last_name' => 'Test', 'position' => 'System Administrator', 'employee_status' => 'inactive', 'created_at' => '2026-11-05 10:00:00'],
        10 => ['employee_id' => 10, 'user_account_id' => 10, 'business_unit_id' => 1, 'first_name' => 'Emp10', 'last_name' => 'Test', 'position' => 'Receptionist', 'employee_status' => 'active', 'created_at' => '2026-11-06 10:00:00'],
    ];

    $pdo->accountRoles = [
        ['user_account_id' => 1, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 7, 'role_id' => 4, 'business_unit_id' => 1],
        ['user_account_id' => 8, 'role_id' => 3, 'business_unit_id' => 1],
        ['user_account_id' => 9, 'role_id' => 2, 'business_unit_id' => 1],
        ['user_account_id' => 10, 'role_id' => 3, 'business_unit_id' => 1],
    ];

    // Decoupled invitations + one unit 2 invitation
    $pdo->invitations = [
        101 => ['invitation_id' => 101, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1, 'invited_email' => 'pending.valid@pawcarino.test', 'first_name' => 'Pend', 'last_name' => 'Valid', 'username' => 'p_valid', 'position_name' => 'Receptionist', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 12:00:00'],
        102 => ['invitation_id' => 102, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1, 'invited_email' => 'existing.customer@example.com', 'first_name' => 'Cust', 'last_name' => 'Invite', 'username' => null, 'position_name' => 'Inventory Manager', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'failed', 'created_at' => '2026-11-20 12:05:00'],
        103 => ['invitation_id' => 103, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1, 'invited_email' => 'pastdue.unreplaced@pawcarino.test', 'first_name' => 'PastDue', 'last_name' => 'Invitee', 'username' => null, 'position_name' => 'Receptionist', 'status' => 'pending', 'expires_at' => '2026-11-20 11:59:59', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-18 11:59:59'],
        104 => ['invitation_id' => 104, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1, 'invited_email' => 'revoked@pawcarino.test', 'first_name' => 'Revoked', 'last_name' => 'Hidden', 'username' => null, 'position_name' => 'Receptionist', 'status' => 'revoked', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 11:00:00'],
        // Superseded expired row + active pending replacement for same email -> only 106 shown!
        105 => ['invitation_id' => 105, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1, 'invited_email' => 'resent.user@pawcarino.test', 'first_name' => 'Resent', 'last_name' => 'User', 'username' => null, 'position_name' => 'Receptionist', 'status' => 'expired', 'expires_at' => '2026-11-19 10:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-17 10:00:00'],
        106 => ['invitation_id' => 106, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 1, 'invited_email' => 'resent.user@pawcarino.test', 'first_name' => 'Resent', 'last_name' => 'User', 'username' => null, 'position_name' => 'Receptionist', 'status' => 'pending', 'expires_at' => '2026-11-22 12:15:00', 'email_submission_status' => 'pending', 'created_at' => '2026-11-20 12:15:00'],
        // Accepted invitation linked to Emp #7 -> hidden from Invitations panel
        107 => ['invitation_id' => 107, 'user_account_id' => 7, 'employee_id' => 7, 'business_unit_id' => 1, 'invited_email' => 'accepted.after.retry@pawcarino.test', 'first_name' => 'Emp7', 'last_name' => 'Test', 'username' => 'emp7', 'position_name' => 'Inventory Manager', 'status' => 'accepted', 'expires_at' => '2026-11-21 09:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-19 09:00:00'],
        // Unit 2 invitation -> isolated from Unit 1
        108 => ['invitation_id' => 108, 'user_account_id' => null, 'employee_id' => null, 'business_unit_id' => 2, 'invited_email' => 'vet.invitee@pawcarino.test', 'first_name' => 'Vet', 'last_name' => 'Invitee', 'username' => null, 'position_name' => 'Veterinary Assistant', 'status' => 'pending', 'expires_at' => '2026-11-22 12:00:00', 'email_submission_status' => 'submitted', 'created_at' => '2026-11-20 12:00:00'],
    ];

    $pdo->invitationRoles = [
        ['invitation_id' => 101, 'role_id' => 3],
        ['invitation_id' => 102, 'role_id' => 4],
        ['invitation_id' => 103, 'role_id' => 3],
        ['invitation_id' => 106, 'role_id' => 3],
        ['invitation_id' => 108, 'role_id' => 3],
    ];

    return $pdo;
}

function bindFakeDatabasePdo(PDO $pdo): void
{
    $prop = new ReflectionProperty(Database::class, 'pdo');
    $prop->setValue(null, $pdo);
}

function renderSysAdminEmployeesView(array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/app/Views/sysadmin/employees.php';
    return (string)ob_get_clean();
}

function extractCardsByPrefix(string $html, string $prefix): array
{
    $cards = [];
    if (preg_match_all('/<article\s+class="roster-item">.*?<\/article>/s', $html, $matches)) {
        foreach ($matches[0] as $cardHtml) {
            if (preg_match('/' . preg_quote($prefix, '/') . '(\d+)/', $cardHtml, $idMatch)) {
                $cards[(int)$idMatch[1]] = $cardHtml;
            }
        }
    }
    return $cards;
}

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
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
        }
        $i++;
    }
    return substr($source, $start, $i - $start - 1);
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

echo "=== Decoupled Reporting, Action-State, View & Route Regression Tests ===\n\n";

test('listPendingInvitationsForUnit returns only active unexpired pending invitations directly from employee_invitation', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $pending = EmployeeInvitationService::listPendingInvitationsForUnit($pdo, 1, $nowUtc);
    $ids = array_map(fn($r) => (int)$r['invitation_id'], $pending);
    sort($ids);
    assertTrue($ids === [101, 102, 106], 'Expected only pending unexpired unit 1 invitations [101, 102, 106], got ' . json_encode($ids));
});

test('listUnitInvitations shows active pending + unreplaced expired invitations and hides revoked/accepted', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $invs = EmployeeInvitationService::listUnitInvitations($pdo, 1, $nowUtc);
    $byId = [];
    foreach ($invs as $r) {
        $byId[(int)$r['invitation_id']] = $r;
    }

    assertTrue(isset($byId[101], $byId[102], $byId[103], $byId[106]), 'Pending and unreplaced past-due invitations must appear');
    assertTrue(!isset($byId[104]) && !isset($byId[105]) && !isset($byId[107]) && !isset($byId[108]), 'Revoked, superseded expired, accepted, and other-unit invitations must be excluded');
    assertTrue(!array_key_exists('is_existing_customer_email', $byId[102]), 'listUnitInvitations must no longer emit retired is_existing_customer_email flag');
    assertTrue($byId[103]['action_state'] === 'expired_invitation', 'Past-due unreplaced invitation #103 must classify as expired_invitation');
});

test('summarizeUnitEmployeeCounts counts real active employees and decoupled pending invitations independently', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $employees = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $pending = EmployeeInvitationService::listPendingInvitationsForUnit($pdo, 1, $nowUtc);
    $counts = EmployeeInvitationService::summarizeUnitEmployeeCounts($employees, $pending);

    // 5 employees in fixture (#1 active sysadmin, #7 active inv mgr, #8 active rec, #9 inactive emp_status, #10 suspended)
    assertTrue($counts['total_employees'] === 5, 'Expected 5 actual employees');
    assertTrue($counts['active_employees'] === 3, 'Expected 3 active employees (#1, #7, #8)');
    assertTrue($counts['active_sysadmin_count'] === 1, 'Expected 1 active sysadmin (#1; #9 is inactive employee_status)');
    assertTrue($counts['pending_invitation_count'] === 3, 'Expected 3 active pending invitations (#101, #102, #106)');
});

test('classifyEmployeeRosterActionState distinguishes suspended, inactive employee_status (with active customer account), and active', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    $employees = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $byId = [];
    foreach ($employees as $e) {
        $byId[(int)$e['employee_id']] = $e;
    }
    assertTrue(EmployeeInvitationService::classifyEmployeeRosterActionState($byId[1]) === 'active', '#1 must be active');
    assertTrue(EmployeeInvitationService::classifyEmployeeRosterActionState($byId[9]) === 'inactive', '#9 with employee_status=inactive & account_status=active must be inactive');
    assertTrue(EmployeeInvitationService::classifyEmployeeRosterActionState($byId[10]) === 'suspended', '#10 with account_status=suspended must be suspended');
});

test('Live rendering of sysadmin/employees.php separates Pending & Expired Invitations from Unit Employee Roster cleanly (Super Admin & SysAdmin)', function () use ($nowUtc) {
    $pdo = makeReportingFixture();
    bindFakeDatabasePdo($pdo);
    $_SESSION = ['user_id' => 999, 'csrf' => 'isolated-csrf-token'];

    $employees = EmployeeInvitationService::listUnitEmployeesWithInvitationState($pdo, 1, $nowUtc);
    $unitInvitations = EmployeeInvitationService::listUnitInvitations($pdo, 1, $nowUtc);
    $positions = [
        ['position_name' => 'System Administrator'],
        ['position_name' => 'Receptionist'],
        ['position_name' => 'Inventory Manager'],
    ];
    $roles = [
        ['role_id' => 2, 'role_name' => 'SYSTEM_ADMINISTRATOR', 'description' => 'SysAdmin'],
        ['role_id' => 3, 'role_name' => 'RECEPTIONIST', 'description' => 'Rec'],
        ['role_id' => 4, 'role_name' => 'INVENTORY_MANAGER', 'description' => 'InvMgr'],
    ];

    $htmlSuper = renderSysAdminEmployeesView([
        'activeTab' => 'employees',
        'employees' => $employees,
        'unitInvitations' => $unitInvitations,
        'positions' => $positions,
        'allPositions' => $positions,
        'assignableRoles' => $roles,
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

    assertTrue(str_contains($htmlSuper, 'Pending &amp; Expired Invitations (4)'), 'Should show 4 pending/expired invitations');
    assertTrue(str_contains($htmlSuper, 'Unit Employee Roster (5)'), 'Should show 5 roster employees');

    $invCards = extractCardsByPrefix($htmlSuper, 'Invitation #');
    $empCards = extractCardsByPrefix($htmlSuper, 'ID #');
    assertTrue(count($invCards) === 4 && count($empCards) === 5, 'Card counts must match section totals');

    // Invitation #101 (pending, submitted) & #102 (pending, failed; no retired Existing Customer badge)
    assertTrue(str_contains($invCards[101], '/sysadmin/invitations/101/resend?unit_id=1') && str_contains($invCards[101], '/sysadmin/invitations/101/revoke?unit_id=1') && str_contains($invCards[101], 'Email sent'), 'Inv #101 actions & delivery badge missing');
    assertTrue(str_contains($invCards[102], 'Delivery failed') && !str_contains($invCards[102], 'Existing Customer'), 'Inv #102 must show Delivery failed without retired Existing Customer badge');
    assertTrue(!str_contains($htmlSuper, 'Existing Customer'), 'employees.php must not render retired Existing Customer badge anywhere');
    // Invitation #103 (expired): Resend only, NO Revoke
    assertTrue(str_contains($invCards[103], 'Invitation Expired') && str_contains($invCards[103], '/sysadmin/invitations/103/resend?unit_id=1') && !str_contains($invCards[103], 'Revoke Invitation'), 'Expired Inv #103 must show Resend only');

    // Roster #1 (active), #9 (inactive -> Reactivate only), #10 (suspended -> read-only badge)
    assertTrue(str_contains($empCards[1], '/sysadmin/employees/1/position?unit_id=1') && str_contains($empCards[1], 'Deactivate'), 'Active Emp #1 needs Change position & Deactivate');
    assertTrue(str_contains($empCards[9], 'Reactivate') && !str_contains($empCards[9], 'Change position'), 'Inactive Emp #9 must show Reactivate only');
    assertTrue(str_contains($empCards[10], 'globally suspended') && !str_contains($empCards[10], 'Change position') && !str_contains($empCards[10], 'Reactivate'), 'Suspended Emp #10 must have no action forms');

    // Non-Super Admin omits ?unit_id=1
    $htmlSys = renderSysAdminEmployeesView([
        'activeTab' => 'employees',
        'employees' => $employees,
        'unitInvitations' => $unitInvitations,
        'positions' => $positions,
        'allPositions' => $positions,
        'assignableRoles' => $roles,
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
    assertTrue(!str_contains($htmlSys, '?unit_id='), 'Non-Super Admin render must omit ?unit_id=');
    foreach (['token_hash', 'raw_token', '/invitations/accept?token=', '$2y$', 'MAIL_PASSWORD'] as $secret) {
        assertTrue(!str_contains($htmlSuper, $secret), "View must never leak {$secret}");
    }
});

test('Routes register invitation-id resend/revoke POST endpoints without retired switch-account, GET, or legacy routes', function () {
    $indexSrc = (string)file_get_contents(dirname(__DIR__) . '/public/index.php');
    assertTrue(str_contains($indexSrc, "'/sysadmin/invitations/{id}/resend', 'SysAdminController', 'resendEmployeeInvitation'"), 'Missing POST /sysadmin/invitations/{id}/resend');
    assertTrue(str_contains($indexSrc, "'/sysadmin/invitations/{id}/revoke', 'SysAdminController', 'revokeEmployeeInvitation'"), 'Missing POST /sysadmin/invitations/{id}/revoke');
    assertTrue(!str_contains($indexSrc, '/invitations/switch-account') && !str_contains($indexSrc, 'switchAccount'), 'Retired POST /invitations/switch-account route must not be registered');
    assertTrue(!str_contains($indexSrc, '/sysadmin/employees/{id}/invitation/'), 'Legacy employee-id invitation routes must be removed');
    assertTrue(preg_match("/\\\$r->get\\([^;]*\\/invitations?\\/(resend|revoke|switch)/i", $indexSrc) === 0, 'Must never expose GET resend/revoke/switch routes');
});

test('SysAdminController invitation resend/revoke and decoupled status guards enforce auth, CSRF, unit scope, and FOR UPDATE checks', function () {
    $ctrlSrc = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/SysAdminController.php');

    $resendBody = extractPhpMethodBody($ctrlSrc, 'resendEmployeeInvitation');
    foreach (['$this->requireSystemAdmin()', 'CSRF::check()', '->resendInvitation($actorUid, $id, $unitId)', '$this->notFound()'] as $exp) {
        assertTrue(str_contains($resendBody, $exp), "resendEmployeeInvitation missing '{$exp}'");
    }

    $revokeBody = extractPhpMethodBody($ctrlSrc, 'revokeEmployeeInvitation');
    foreach (['$this->requireSystemAdmin()', 'CSRF::check()', '->revokeInvitation($actorUid, $id, $unitId', '$this->notFound()'] as $exp) {
        assertTrue(str_contains($revokeBody, $exp), "revokeEmployeeInvitation missing '{$exp}'");
    }

    $statusBody = extractPhpMethodBody($ctrlSrc, 'updateEmployeeStatus');
    foreach ([
        '$this->requireSystemAdmin()',
        'CSRF::check()',
        '$this->verifyEmployeeInUnit(',
        'SELECT account_status FROM user_account WHERE user_account_id = ? FOR UPDATE',
        'SELECT employee_status FROM employee WHERE employee_id = ? FOR UPDATE',
        'UPDATE employee SET employee_status = ? WHERE employee_id = ?',
    ] as $exp) {
        assertTrue(str_contains($statusBody, $exp), "updateEmployeeStatus missing '{$exp}'");
    }
    assertTrue(!preg_match('/UPDATE\s+user_account\b/i', $statusBody), 'updateEmployeeStatus must never mutate user_account');
});

test('app.js wires data-prevent-double-submit cleanly after data-confirm-action', function () {
    $jsSrc = (string)file_get_contents(dirname(__DIR__) . '/public/assets/js/app.js');
    $confirmPos = strpos($jsSrc, "document.querySelectorAll('[data-confirm-action]')");
    $doublePos = strpos($jsSrc, "document.querySelectorAll('form[data-prevent-double-submit]')");
    assertTrue($confirmPos !== false && $doublePos !== false && $confirmPos < $doublePos, 'app.js listener order mismatch');
});

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
