<?php
declare(strict_types=1);

/**
 * Grooming Services System Administrator verification test suite.
 * Tests 4-page interface structure, approved positions, position-to-role mappings,
 * Owen Bennett configuration, collapsible cards, activity log, appointment visibility,
 * and business-unit isolation.
 *
 * Usage: tools\php83\php.exe tests\grooming_sysadmin_tests.php
 */
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\AdminAccountService;
use App\Models\Units;

$base = getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8080';
$pdo = Database::pdo();
$failed = 0;
$total = 0;

final class GroomingHttpClient
{
    public array $cookies = [];
    public function __construct(private string $base) {}
    public function get(string $p): array { return $this->send('GET', $p); }
    public function post(string $p, array $d): array { return $this->send('POST', $p, http_build_query($d)); }
    public function csrf(string $html): string
    {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        throw new RuntimeException('no csrf');
    }
    private function send(string $method, string $path, ?string $body = null): array
    {
        $h = ['Accept: text/html'];
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if ($method === 'POST') {
            $h[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $h),
                'content' => $body ?? '',
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);
        $raw = @file_get_contents($this->base . $path, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException("unreachable $path");
        }
        $status = 0;
        $loc = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (stripos($line, 'Set-Cookie:') === 0 && preg_match('/:\s*([^=]+)=([^;]+)/', $line, $m)) {
                $this->cookies[trim($m[1])] = trim($m[2]);
            } elseif (stripos($line, 'Location:') === 0) {
                $loc = trim(substr($line, 9));
            }
        }
        return ['status' => $status, 'location' => $loc, 'body' => $raw];
    }
    public function staffLogin(string $email, string $pw): array
    {
        $lp = $this->get('/staff/login');
        return $this->post('/staff/login', [
            'csrf_token' => $this->csrf($lp['body']),
            'email' => $email,
            'password' => $pw,
        ]);
    }
}

function check(string $name, bool $ok, string $details = ''): void
{
    global $failed, $total;
    $total++;
    if ($ok) {
        echo "  PASS $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($details !== '' ? " ($details)" : '') . "\n";
    }
}

echo "=== Suite A: Database Positions, Roles & Owen Bennett Configuration ===\n";

// 1. Exactly 7 approved Grooming positions
$positions = $pdo->query(
    "SELECT position_name, position_status FROM business_position WHERE business_unit_id = 3 AND position_status = 'active' ORDER BY position_name"
)->fetchAll();
$posNames = array_column($positions, 'position_name');
$expectedPositions = [
    'Bather',
    'Business Manager — Grooming Services',
    'Inventory Manager',
    'Master Stylist',
    'Receptionist',
    'Stylist',
    'System Administrator',
];
check('Grooming Services has exactly 7 active positions', count($posNames) === 7 && $posNames === $expectedPositions);

// 2. Combined positions removed
$combined1 = $pdo->query("SELECT COUNT(*) FROM business_position WHERE business_unit_id = 3 AND position_name = 'Master Pet Stylist & Reception Coordinator' AND position_status = 'active'")->fetchColumn();
check('Master Pet Stylist & Reception Coordinator is not active', (int)$combined1 === 0);
$combined2 = $pdo->query("SELECT COUNT(*) FROM business_position WHERE business_unit_id = 3 AND position_name = 'Pet Groomer & Stylist' AND position_status = 'active'")->fetchColumn();
check('Pet Groomer & Stylist is not active', (int)$combined2 === 0);

// 3. Roles in database
$hasStylistWs = (int)$pdo->query("SELECT COUNT(*) FROM role WHERE role_name = 'STYLIST_WORKSPACE'")->fetchColumn();
check('Role STYLIST_WORKSPACE exists in database', $hasStylistWs === 1);
$hasBatherWs = (int)$pdo->query("SELECT COUNT(*) FROM role WHERE role_name = 'BATHER_WORKSPACE'")->fetchColumn();
check('Role BATHER_WORKSPACE exists in database', $hasBatherWs === 1);

// 4. Owen Bennett configuration
$owen = $pdo->query(
    "SELECT e.employee_id, e.position, ua.email,
            (SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name) FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = e.user_account_id AND ar.business_unit_id = 3) as roles
     FROM employee e
     JOIN user_account ua ON ua.user_account_id = e.user_account_id
     WHERE e.first_name = 'Owen' AND e.last_name = 'Bennett' AND e.business_unit_id = 3"
)->fetch();
check('Owen Bennett Default Job is Master Stylist', ($owen['position'] ?? '') === 'Master Stylist');
$owenRoles = explode(',', (string)($owen['roles'] ?? ''));
check('Owen Bennett has RECEPTIONIST role', in_array('RECEPTIONIST', $owenRoles, true));
check('Owen Bennett has STYLIST_WORKSPACE role', in_array('STYLIST_WORKSPACE', $owenRoles, true));
check('Owen Bennett has BATHER_WORKSPACE role', in_array('BATHER_WORKSPACE', $owenRoles, true));

// 5. Position-to-initial-role mappings
$map = AdminAccountService::POSITION_INITIAL_ROLE_MAP;
check('Master Stylist maps to STYLIST_WORKSPACE and BATHER_WORKSPACE', ($map['Master Stylist'] ?? null) === ['STYLIST_WORKSPACE', 'BATHER_WORKSPACE']);
check('Stylist maps to STYLIST_WORKSPACE', ($map['Stylist'] ?? null) === 'STYLIST_WORKSPACE');
check('Bather maps to BATHER_WORKSPACE', ($map['Bather'] ?? null) === 'BATHER_WORKSPACE');
check('Receptionist maps to RECEPTIONIST', ($map['Receptionist'] ?? null) === 'RECEPTIONIST');
check('Inventory Manager maps to INVENTORY_MANAGER', ($map['Inventory Manager'] ?? null) === 'INVENTORY_MANAGER');
check('Business Manager — Grooming Services maps to BUSINESS_MANAGER', ($map['Business Manager — Grooming Services'] ?? null) === 'BUSINESS_MANAGER');
check('System Administrator maps to SYSTEM_ADMINISTRATOR', ($map['System Administrator'] ?? null) === 'SYSTEM_ADMINISTRATOR');
check('Old Master Pet Stylist & Reception Coordinator mapping is removed', !isset($map['Master Pet Stylist & Reception Coordinator']));

// 6. Assignable roles for Grooming
$assignable = AdminAccountService::assignableRolesForUnit(Units::GROOMING);
$assignableNames = array_column($assignable, 'role_name');
sort($assignableNames);
$expectedAssignable = ['BATHER_WORKSPACE', 'BUSINESS_MANAGER', 'INVENTORY_MANAGER', 'RECEPTIONIST', 'STYLIST_WORKSPACE', 'SYSTEM_ADMINISTRATOR'];
sort($expectedAssignable);
check('Grooming Services has exactly 6 assignable system roles', $assignableNames === $expectedAssignable);

echo "\n=== Suite B: Grooming System Administrator 4-Page Interface & Navigation ===\n";

$http = new GroomingHttpClient($base);
// Login as Grooming System Administrator (Caleb Thorne: sysadmin.grooming@vetgrpet.local)
$loginRes = $http->staffLogin('sysadmin.grooming@vetgrpet.local', 'SysAdminGroom!2026');
check('Grooming System Administrator logs in successfully', $loginRes['status'] === 302);

// Page 1: Overview
$dash = $http->get('/sysadmin');
check('Overview page loads with HTTP 200', $dash['status'] === 200);
check('Overview heading is Grooming Services Administration', str_contains($dash['body'], 'Grooming Services Administration'));
check('Overview subtitle identifies Grooming Services · Unit #3', str_contains($dash['body'], 'System Administrator · Grooming Services · Unit #3'));
check('Overview has Unit at a glance section with 4 stat cards', str_contains($dash['body'], 'aria-label="Unit at a glance"') && str_contains($dash['body'], 'Total Employees') && str_contains($dash['body'], 'Active Employees') && str_contains($dash['body'], 'System Roles') && str_contains($dash['body'], 'Active Positions'));
check('Overview has Administrative Attention section', str_contains($dash['body'], 'Administrative Attention'));
check('Overview has Recent Administrative Activity section', str_contains($dash['body'], 'Recent Administrative Activity'));
check('Overview links to Full Activity Log', str_contains($dash['body'], '/sysadmin/activity'));
check('Overview excludes employee roster and creation form', !str_contains($dash['body'], 'roster-card') && !str_contains($dash['body'], 'name="full_name"'));
check('Overview excludes Add Unit Employee or Manage Positions buttons', !str_contains($dash['body'], '+ Add Unit Employee') && !str_contains($dash['body'], 'Manage Positions</a>'));

// Navigation consistency & order
check('Nav shows exactly the 4 required tabs: Overview -> Unit Employees -> Roles & Permissions -> Activity Log',
    str_contains($dash['body'], 'Overview</a>') &&
    str_contains($dash['body'], 'Unit Employees</a>') &&
    str_contains($dash['body'], 'Roles &amp; Permissions</a>') &&
    str_contains($dash['body'], 'Activity Log</a>') &&
    !str_contains($dash['body'], 'Unit Positions</a>')
);

// Redirect /sysadmin/positions to /sysadmin/employees
$posRedirect = $http->get('/sysadmin/positions');
check('/sysadmin/positions redirects to /sysadmin/employees', $posRedirect['status'] === 302 && str_contains((string)$posRedirect['location'], '/sysadmin/employees'));

echo "\n=== Suite C: Unit Employees Page, Roster, Pagination & Modal ===\n";

$empPage = $http->get('/sysadmin/employees');
check('Unit Employees page loads with HTTP 200', $empPage['status'] === 200);
check('Employees page has + Add Employee modal button', str_contains($empPage['body'], 'data-modal-open="add-employee-modal"'));
check('Employees page uses 6-per-page roster layout', str_contains($empPage['body'], 'Showing 6 per page'));
check('Employees page has centered pagination container', str_contains($empPage['body'], 'pagination-centered'));
check('Previous button is disabled on page 1', str_contains($empPage['body'], 'pagination-prev') && str_contains($empPage['body'], 'disabled'));

// Modal structure
check('Employee creation modal present with id "add-employee-modal"', str_contains($empPage['body'], 'id="add-employee-modal"'));
check('Modal submit button is "Send Invite Link"', str_contains($empPage['body'], 'Send Invite Link'));
check('Modal contains NO password input field', !str_contains($empPage['body'], 'type="password"'));
check('Modal position dropdown contains all 7 approved Grooming positions',
    str_contains($empPage['body'], 'value="Bather"') &&
    str_contains($empPage['body'], 'value="Business Manager — Grooming Services"') &&
    str_contains($empPage['body'], 'value="Inventory Manager"') &&
    str_contains($empPage['body'], 'value="Master Stylist"') &&
    str_contains($empPage['body'], 'value="Receptionist"') &&
    str_contains($empPage['body'], 'value="Stylist"') &&
    str_contains($empPage['body'], 'value="System Administrator"')
);
check('Modal position dropdown lacks removed combined positions',
    !str_contains($empPage['body'], 'Master Pet Stylist & Reception Coordinator') &&
    !str_contains($empPage['body'], 'Pet Groomer & Stylist')
);

echo "\n=== Suite D: Roles & Permissions Page with Collapsible Cards ===\n";

$rolesPage = $http->get('/sysadmin/roles');
check('Roles & Permissions page loads with HTTP 200', $rolesPage['status'] === 200);
check('Roles page uses collapsible cards (data-role-card)', str_contains($rolesPage['body'], 'data-role-card'));
check('Roles page has arrow toggle buttons (data-role-toggle)', str_contains($rolesPage['body'], 'data-role-toggle'));
check('Roles page has chevron icons (role-card-chevron)', str_contains($rolesPage['body'], 'role-card-chevron'));
check('Card for SYSTEM_ADMINISTRATOR present', str_contains($rolesPage['body'], 'SYSTEM_ADMINISTRATOR'));
check('Card for BUSINESS_MANAGER present', str_contains($rolesPage['body'], 'BUSINESS_MANAGER'));
check('Card for INVENTORY_MANAGER present', str_contains($rolesPage['body'], 'INVENTORY_MANAGER'));
check('Card for RECEPTIONIST present', str_contains($rolesPage['body'], 'RECEPTIONIST'));
check('Card for STYLIST_WORKSPACE present', str_contains($rolesPage['body'], 'STYLIST_WORKSPACE'));
check('Card for BATHER_WORKSPACE present', str_contains($rolesPage['body'], 'BATHER_WORKSPACE'));
check('DELIVERY_PERSONNEL role card is NOT present in Grooming', !str_contains($rolesPage['body'], 'DELIVERY_PERSONNEL'));
check('Clinical role cards NOT present in Grooming', !str_contains($rolesPage['body'], 'VETERINARIAN_WORKSPACE'));

echo "\n=== Suite E: Activity Log Scoped to Grooming Services ===\n";

$actPage = $http->get('/sysadmin/activity');
check('Activity Log page loads with HTTP 200 for Grooming SysAdmin', $actPage['status'] === 200);
check('Activity Log title references Grooming Services', str_contains($actPage['body'], 'Grooming Services'));
check('Activity Log contains activity table or valid empty state',
    (str_contains($actPage['body'], 'Date and time') && str_contains($actPage['body'], 'Administrator')) ||
    str_contains($actPage['body'], 'No administrative activity has been recorded')
);
check('Activity filters container present', str_contains($actPage['body'], 'activity-filters'));

echo "\n=== Suite F: Appointment Visibility & Operational Isolation ===\n";

// Grooming SysAdmin has no operational appointment access
$sysAdminAppts = $http->get('/staff/appointments');
check('Grooming System Administrator is denied appointment list (403 Forbidden)', $sysAdminAppts['status'] === 403);

// Grooming SysAdmin cannot cancel appointments
$sysAdminCancel = $http->post('/staff/appointments/1/cancel', ['csrf_token' => 'dummy', 'cancellation_reason' => 'test']);
check('Grooming System Administrator cannot cancel appointments (403 Forbidden)', $sysAdminCancel['status'] === 403);

// Grooming SysAdmin cannot reassign appointments
$sysAdminReassign = $http->post('/staff/appointments/1/reassign', ['csrf_token' => 'dummy', 'employee_id' => 7]);
check('Grooming System Administrator cannot reassign appointments (403 Forbidden)', $sysAdminReassign['status'] === 403);

// Cross-unit isolation: Grooming SysAdmin cannot manage Pet Store or Vet Clinic
$crossUnitPet = $http->get('/sysadmin?unit_id=1');
check('Grooming SysAdmin cannot access Pet Store overview (scoped to Grooming)', str_contains($crossUnitPet['body'], 'Grooming Services'));

// Test employee creation invitation flow in Grooming
$testEmail = 'grooming.test.worker.' . time() . '@vetgrpet.local';
$createRes = $http->post('/sysadmin/employees', [
    'csrf_token' => $http->csrf($empPage['body']),
    'full_name' => 'Maya Lin',
    'email' => $testEmail,
    'position' => 'Master Stylist',
    'roles' => [], // Should automatically assign STYLIST_WORKSPACE and BATHER_WORKSPACE
]);
check('Creating employee with Master Stylist returns 302 redirect', $createRes['status'] === 302);

// Verify created account
$createdEmp = $pdo->query(
    "SELECT e.employee_id, e.position, ua.email_verified_at,
            (SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name) FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = e.user_account_id) as roles
     FROM employee e
     JOIN user_account ua ON ua.user_account_id = e.user_account_id
     WHERE ua.email = '$testEmail'"
)->fetch();
check('Created employee position saved as Master Stylist', ($createdEmp['position'] ?? '') === 'Master Stylist');
check('Invitation user account has unverified email (invitation flow)', $createdEmp && $createdEmp['email_verified_at'] === null);
$createdRoles = explode(',', (string)($createdEmp['roles'] ?? ''));
check('Master Stylist automatically assigned both STYLIST_WORKSPACE and BATHER_WORKSPACE',
    in_array('STYLIST_WORKSPACE', $createdRoles, true) && in_array('BATHER_WORKSPACE', $createdRoles, true)
);

// Cleanup test user
$cleanUid = (int)$pdo->query("SELECT user_account_id FROM user_account WHERE email = '$testEmail'")->fetchColumn();
if ($cleanUid) {
    $pdo->exec("DELETE FROM account_role WHERE user_account_id = $cleanUid");
    $pdo->exec("DELETE FROM audit_log WHERE target_type = 'employee' AND target_id = {$createdEmp['employee_id']}");
    $pdo->exec("DELETE FROM employee WHERE user_account_id = $cleanUid");
    $pdo->exec("DELETE FROM customer WHERE user_account_id = $cleanUid");
    $pdo->exec("DELETE FROM user_account WHERE user_account_id = $cleanUid");
}

echo "\n" . ($failed === 0 ? "ALL $total ASSERTIONS PASSED!" : "FAILED: $failed of $total assertions failed.") . "\n";
exit($failed === 0 ? 0 : 1);
