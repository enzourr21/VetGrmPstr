<?php
/**
 * Verification test suite for Veterinary Clinic System Administrator Refactoring
 * Matches the Pet Store System Administrator implementation as the source of truth.
 * Usage: php tests/vetclinic_sysadmin_refactor_tests.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Models/Units.php';
require_once __DIR__ . '/../app/Models/AdminAccountService.php';

use App\Models\Units;
use App\Models\AdminAccountService;

$passed = 0;
$failed = 0;

function check(string $desc, bool $ok, string $extra = ''): void {
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS $desc\n";
    } else {
        $failed++;
        echo "  FAIL $desc" . ($extra !== '' ? " ($extra)" : '') . "\n";
    }
}

class VetTestHttp {
    private string $base;
    private ?string $cookie = null;

    public function __construct(string $base) {
        $this->base = rtrim($base, '/');
    }

    public function req(string $method, string $path, array $data = [], array $headers = []): array {
        $ch = curl_init($this->base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($this->cookie) {
            $headers[] = 'Cookie: ' . $this->cookie;
        }
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body = http_build_query($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new RuntimeException("CURL error: " . curl_error($ch));
        }
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $head = substr((string)$resp, 0, $headerSize);
        $body = substr((string)$resp, $headerSize);

        if (preg_match_all('/Set-Cookie:\s*([^;\r\n]+)/i', $head, $m)) {
            $jar = [];
            if ($this->cookie) {
                foreach (explode('; ', $this->cookie) as $pair) {
                    [$k] = explode('=', $pair, 2);
                    $jar[$k] = $pair;
                }
            }
            foreach ($m[1] as $cookieLine) {
                [$k] = explode('=', $cookieLine, 2);
                $jar[$k] = $cookieLine;
            }
            $this->cookie = implode('; ', array_values($jar));
        }

        $loc = null;
        if (preg_match('/^Location:\s*(.+)$/im', $head, $lm)) {
            $loc = trim($lm[1]);
        }

        return ['status' => $status, 'body' => $body, 'location' => $loc];
    }

    public function get(string $path): array { return $this->req('GET', $path); }
    public function post(string $path, array $data = []): array { return $this->req('POST', $path, $data); }

    public function csrf(string $html): string {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/value="([^"]+)"\s+name="csrf_token"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function staffLogin(string $email, string $password): array {
        $p = $this->get('/staff/login');
        $tok = $this->csrf($p['body']);
        return $this->post('/staff/login', ['csrf_token' => $tok, 'email' => $email, 'password' => $password]);
    }
}

$base = 'http://127.0.0.1:8080';
$vet = new VetTestHttp($base);
$login = $vet->staffLogin('sysadmin.vetclinic@vetgrpet.local', 'SysAdminVet!2026');

// Clean up any test records
$pdo->exec("DELETE FROM account_role WHERE user_account_id IN (SELECT user_account_id FROM user_account WHERE email LIKE 'vc.test.%@vetgrpet.local')");
$pdo->exec("DELETE FROM audit_log WHERE affected_employee_id IN (SELECT employee_id FROM employee WHERE user_account_id IN (SELECT user_account_id FROM user_account WHERE email LIKE 'vc.test.%@vetgrpet.local'))");
$pdo->exec("DELETE FROM customer WHERE user_account_id IN (SELECT user_account_id FROM user_account WHERE email LIKE 'vc.test.%@vetgrpet.local')");
$pdo->exec("DELETE FROM employee WHERE user_account_id IN (SELECT user_account_id FROM user_account WHERE email LIKE 'vc.test.%@vetgrpet.local')");
$pdo->exec("DELETE FROM user_account WHERE email LIKE 'vc.test.%@vetgrpet.local'");

echo "\n=== Suite 1: Navigation Consistency & Order ===\n";
$dash = $vet->get('/sysadmin');
check('Dashboard loads with HTTP 200', $dash['status'] === 200);

// Order: Overview, Unit Employees, Roles & Permissions, Activity Log
$posOverview = strpos($dash['body'], '>Overview<');
$posEmployees = strpos($dash['body'], '>Unit Employees<');
$posRoles = strpos($dash['body'], '>Roles &amp; Permissions<');
$posActivity = strpos($dash['body'], '>Activity Log<');
check('Nav has Overview tab', $posOverview !== false);
check('Nav has Unit Employees tab', $posEmployees !== false);
check('Nav has Roles & Permissions tab', $posRoles !== false);
check('Nav has Activity Log tab', $posActivity !== false);
check('Nav tab order: Overview -> Unit Employees -> Roles & Permissions -> Activity Log',
    $posOverview < $posEmployees && $posEmployees < $posRoles && $posRoles < $posActivity);
check('Nav excludes Unit Positions tab', !str_contains($dash['body'], '>Unit Positions<'));

// Positions route redirects to employees
$posPage = $vet->get('/sysadmin/positions');
check('/sysadmin/positions redirects to /sysadmin/employees', ($posPage['location'] ?? '') === '/sysadmin/employees');

echo "\n=== Suite 2: Overview Page Structure & ContentArrangement ===\n";
check('Overview title is "Veterinary Clinic Administration"', str_contains($dash['body'], 'Veterinary Clinic Administration'));
check('Overview subtitle identifies System Administrator · Veterinary Clinic · Unit #2',
    str_contains($dash['body'], 'System Administrator · Veterinary Clinic · Unit #2'));
check('Overview excludes "Add Unit Employee" button', !str_contains($dash['body'], '+ Add Unit Employee'));
check('Overview excludes "Manage Positions" button', !str_contains($dash['body'], 'Manage Positions'));

// Four statistics cards in exact order
$posStat1 = strpos($dash['body'], 'Total Employees');
$posStat2 = strpos($dash['body'], 'Active Employees');
$posStat3 = strpos($dash['body'], 'System Roles');
$posStat4 = strpos($dash['body'], 'Active Positions');
check('Has 4 stat cards in order: Total -> Active -> Roles -> Positions',
    $posStat1 !== false && $posStat2 !== false && $posStat3 !== false && $posStat4 !== false &&
    $posStat1 < $posStat2 && $posStat2 < $posStat3 && $posStat3 < $posStat4);

// Active Positions card displays active positions
check('Active positions card shows active positions count', preg_match('/<strong class="stat-value">\s*\d+\s*<span/i', $dash['body']) === 1);

// Administrative Attention section
check('Overview has Administrative Attention section', str_contains($dash['body'], 'Administrative Attention'));
check('Overview has Recent Administrative Activity section', str_contains($dash['body'], 'Recent Administrative Activity'));
check('Overview links to Full Activity Log', str_contains($dash['body'], 'href="/sysadmin/activity"'));
check('Overview excludes employee roster or inline creation form', !str_contains($dash['body'], 'roster-item') && !str_contains($dash['body'], 'id="add-employee-form"'));

echo "\n=== Suite 3: Unit Employees Page, Roster & Modal ===\n";
$emp = $vet->get('/sysadmin/employees');
check('Employees page loads with HTTP 200', $emp['status'] === 200);
check('Employees page has "+ Add Employee" button in header', str_contains($emp['body'], 'data-modal-open="add-employee-modal"') && str_contains($emp['body'], '+ Add Employee'));
check('Employees page has no inline creation form on page body', !str_contains($emp['body'], 'class="employee-form"'));
check('Roster header indicates 6 per page', str_contains($emp['body'], 'Showing 6 per page'));
check('Roster has centered pagination container', str_contains($emp['body'], 'pagination-centered'));
check('Pagination format matches Previous · Page 1 of N · Next', str_contains($emp['body'], 'Page 1 of') && str_contains($emp['body'], 'Previous') && str_contains($emp['body'], 'Next'));
check('Previous button is disabled on page 1', str_contains($emp['body'], 'pagination-prev') && (str_contains($emp['body'], 'disabled') || str_contains($emp['body'], 'aria-disabled="true"')));
check('No employee in roster has (retired) position', !str_contains($emp['body'], '(retired)'));

// Modal tests
check('Modal element present with id "add-employee-modal"', str_contains($emp['body'], 'id="add-employee-modal"'));
check('Modal has Send Invite Link submit button', str_contains($emp['body'], 'Send Invite Link'));
check('Modal submit button is initially disabled', preg_match('/id="create-emp-submit-btn"[^>]*\bdisabled\b/', $emp['body']) === 1);
check('Modal contains NO password input field', !str_contains($emp['body'], 'name="password"'));
check('Modal full name has required attribute', preg_match('/name="full_name"[^>]*\brequired\b/', $emp['body']) === 1);
check('Modal email has required attribute', preg_match('/name="email"[^>]*\brequired\b/', $emp['body']) === 1);
check('Modal position has required attribute', preg_match('/name="position"[^>]*\brequired\b/', $emp['body']) === 1);
check('Modal username is optional (no required attribute)', !preg_match('/name="username"[^>]*\brequired\b/', $emp['body']));
check('Modal phone is optional (no required attribute)', !preg_match('/name="phone"[^>]*\brequired\b/', $emp['body']));
check('Modal business unit is locked to Veterinary Clinic', str_contains($emp['body'], 'value="Veterinary Clinic" disabled'));

echo "\n=== Suite 4: Available Positions & Renamed Clinical Positions ===\n";
// Available positions in dropdown
check('Dropdown has "Lead Veterinarian"', str_contains($emp['body'], '<option value="Lead Veterinarian"'));
check('Dropdown has "Associate Veterinarian"', str_contains($emp['body'], '<option value="Associate Veterinarian"'));
check('Dropdown has "Veterinary Nurse"', str_contains($emp['body'], '<option value="Veterinary Nurse"'));
check('Dropdown has "Veterinary Technician"', str_contains($emp['body'], '<option value="Veterinary Technician"'));
check('Dropdown has "System Administrator"', str_contains($emp['body'], '<option value="System Administrator"'));
check('Dropdown has "Business Manager — Veterinary Clinic"', str_contains($emp['body'], '<option value="Business Manager — Veterinary Clinic"'));
check('Dropdown has "Inventory Manager"', str_contains($emp['body'], '<option value="Inventory Manager"'));
check('Dropdown has "Receptionist"', str_contains($emp['body'], '<option value="Receptionist"'));

// Replaced/retired positions NOT in dropdown
check('Dropdown lacks "Veterinarian (DVM)"', !str_contains($emp['body'], '<option value="Veterinarian (DVM)"'));
check('Dropdown lacks "Veterinary Assistant"', !str_contains($emp['body'], '<option value="Veterinary Assistant"'));

// Historical employees updated safely
$elena = $pdo->query("SELECT position FROM employee WHERE employee_id = 1")->fetchColumn();
check('Dr. Elena Vance position renamed to "Lead Veterinarian"', $elena === 'Lead Veterinarian');
$claire = $pdo->query("SELECT position FROM employee WHERE employee_id = 3")->fetchColumn();
check('Claire Dubois position is active "Business Manager — Veterinary Clinic"', $claire === 'Business Manager — Veterinary Clinic');
$beatrice = $pdo->query("SELECT position FROM employee WHERE employee_id = 10")->fetchColumn();
check('Beatrice Solis position is active "System Administrator"', $beatrice === 'System Administrator');

echo "\n=== Suite 5: Roles & Permissions Page & Collapsible Cards ===\n";
$rolesPage = $vet->get('/sysadmin/roles');
check('Roles page loads with HTTP 200', $rolesPage['status'] === 200);
check('Roles page uses collapsible cards (data-role-card)', str_contains($rolesPage['body'], 'data-role-card'));
check('Roles page has arrow toggle buttons (data-role-toggle)', str_contains($rolesPage['body'], 'data-role-toggle'));
check('Roles page has chevron icons (role-card-chevron)', str_contains($rolesPage['body'], 'role-card-chevron'));
check('Roles page has expandable panels (data-role-panel)', str_contains($rolesPage['body'], 'data-role-panel'));

// All 4 shared roles present, including INVENTORY_MANAGER
check('Card for SYSTEM_ADMINISTRATOR present', str_contains($rolesPage['body'], 'SYSTEM_ADMINISTRATOR'));
check('Card for BUSINESS_MANAGER present', str_contains($rolesPage['body'], 'BUSINESS_MANAGER'));
check('Card for INVENTORY_MANAGER present', str_contains($rolesPage['body'], 'INVENTORY_MANAGER'));
check('Card for RECEPTIONIST present', str_contains($rolesPage['body'], 'RECEPTIONIST'));

// All 3 workspace roles present
check('Card for VETERINARIAN_WORKSPACE present', str_contains($rolesPage['body'], 'VETERINARIAN_WORKSPACE'));
check('Card for VETERINARY_NURSE_WORKSPACE present', str_contains($rolesPage['body'], 'VETERINARY_NURSE_WORKSPACE'));
check('Card for VETERINARY_TECHNICIAN_WORKSPACE present', str_contains($rolesPage['body'], 'VETERINARY_TECHNICIAN_WORKSPACE'));

// DELIVERY_PERSONNEL strictly excluded
check('DELIVERY_PERSONNEL role card is NOT present', !str_contains($rolesPage['body'], 'DELIVERY_PERSONNEL'));

// Assignable roles count is exactly 7
$assignable = AdminAccountService::assignableRolesForUnit(Units::VET_CLINIC);
check('Assignable roles count for Veterinary Clinic is exactly 7', count($assignable) === 7, 'Count: ' . count($assignable));

echo "\n=== Suite 6: Employee Invitation Flow & Backend Enforcement ===\n";
$vtok = $vet->csrf($emp['body']);

// Incomplete submission rejected with 422
$bad1 = $vet->post('/sysadmin/employees', [
    'csrf_token' => $vtok,
    'full_name' => '',
    'email' => 'vc.test.empty@vetgrpet.local',
    'position' => 'Veterinary Nurse',
    'roles' => ['VETERINARY_NURSE_WORKSPACE'],
]);
check('Missing full name rejected with 422', $bad1['status'] === 422);

$bad2 = $vet->post('/sysadmin/employees', [
    'csrf_token' => $vtok,
    'full_name' => 'Invalid Email',
    'email' => 'invalid-email-address',
    'position' => 'Veterinary Nurse',
    'roles' => ['VETERINARY_NURSE_WORKSPACE'],
]);
check('Invalid email rejected with 422', $bad2['status'] === 422);

$bad3 = $vet->post('/sysadmin/employees', [
    'csrf_token' => $vtok,
    'full_name' => 'Retired Pos',
    'email' => 'vc.test.retired@vetgrpet.local',
    'position' => 'Veterinary Assistant',
    'roles' => ['VETERINARIAN_WORKSPACE'],
]);
check('Retired position rejected with 422', $bad3['status'] === 422);

// Successful invitation creation
$createSuccess = $vet->post('/sysadmin/employees', [
    'csrf_token' => $vtok,
    'full_name' => 'Maria Santos',
    'email' => 'vc.test.maria@vetgrpet.local',
    'username' => 'mariasantos',
    'position' => 'Veterinary Receptionist',
    'roles' => ['RECEPTIONIST'],
    'phone' => '0917-888-9999',
]);
check('Creating employee via invitation returns 302 redirect', $createSuccess['status'] === 302);
check('Redirects back to /sysadmin/employees', $createSuccess['location'] === '/sysadmin/employees');

$newEmp = $pdo->query("SELECT ua.user_account_id, ua.password_hash, ua.email_verified_at, e.employee_id, e.position, e.business_unit_id
    FROM user_account ua
    JOIN employee e ON e.user_account_id = ua.user_account_id
    WHERE ua.email = 'vc.test.maria@vetgrpet.local'")->fetch(PDO::FETCH_ASSOC);
check('Employee record created in DB', (bool)$newEmp);
check('Employee belongs to business_unit_id = 2', (int)$newEmp['business_unit_id'] === 2);
check('Employee position saved as "Veterinary Receptionist"', $newEmp['position'] === 'Veterinary Receptionist');
check('Invitation user account has unverified email (email_verified_at IS NULL)', $newEmp['email_verified_at'] === null);
check('User account has hashed password, not empty or plain text', strlen($newEmp['password_hash']) > 30);

// Audit log record
$invAudit = $pdo->query("SELECT * FROM audit_log WHERE affected_employee_id = {$newEmp['employee_id']} AND action_code = 'invitation.created'")->fetch(PDO::FETCH_ASSOC);
check('Invitation recorded in audit_log as invitation.created', (bool)$invAudit);
check('Audit log contains NO sensitive tokens or passwords', !str_contains($invAudit['summary'] . $invAudit['new_value'], 'password'));

// Assigning INVENTORY_MANAGER to Maria Santos succeeds
$rtok = $vet->csrf($rolesPage['body']);
$assignInv = $vet->post("/sysadmin/employees/{$newEmp['employee_id']}/roles", [
    'csrf_token' => $rtok,
    'roles' => ['RECEPTIONIST', 'INVENTORY_MANAGER'],
]);
check('Assigning INVENTORY_MANAGER in Veterinary Clinic succeeds (302)', $assignInv['status'] === 302);

$mRoles = $pdo->query("SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = {$newEmp['user_account_id']} ORDER BY r.role_name")->fetchAll(PDO::FETCH_COLUMN);
check('Maria Santos has INVENTORY_MANAGER and RECEPTIONIST', $mRoles === ['INVENTORY_MANAGER', 'RECEPTIONIST']);

// Changing position does NOT modify system roles
$changePos = $vet->post("/sysadmin/employees/{$newEmp['employee_id']}/position", [
    'csrf_token' => $vtok,
    'position' => 'Associate Veterinarian',
]);
check('Changing default position returns 302', $changePos['status'] === 302);
$mRolesAfter = $pdo->query("SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = {$newEmp['user_account_id']} ORDER BY r.role_name")->fetchAll(PDO::FETCH_COLUMN);
check('System roles preserved after default position change', $mRolesAfter === ['INVENTORY_MANAGER', 'RECEPTIONIST']);

echo "\n=== Suite 7: Activity Log Page ===\n";
$act = $vet->get('/sysadmin/activity');
check('Activity log loads with HTTP 200 for Vet Clinic admin', $act['status'] === 200);
check('Activity log title is "Administrative Activity Log"', str_contains($act['body'], 'Administrative Activity Log'));
check('Activity log header specifies Veterinary Clinic', str_contains($act['body'], 'Veterinary Clinic · Audit Trail'));
check('Activity log has filters form', str_contains($act['body'], 'class="activity-filters"'));
check('Activity log has activity type filter', str_contains($act['body'], 'name="type"'));
check('Activity log has affected employee filter', str_contains($act['body'], 'name="employee_id"'));
check('Activity log has date_from and date_to filters', str_contains($act['body'], 'name="date_from"') && str_contains($act['body'], 'name="date_to"'));
check('Activity log has scrollable table container (activity-scroll)', str_contains($act['body'], 'activity-scroll'));
check('Recent activity shows Maria Santos invitation creation', str_contains($act['body'], 'Maria Santos'));

echo "\n=== Suite 8: Business-Unit Isolation & Security Boundaries ===\n";
// Vet Admin cannot view Pet Store activity log (403)
$crossAct = $vet->get('/sysadmin/activity?unit_id=1');
check('Vet Admin viewing Pet Store activity returns HTTP 403', $crossAct['status'] === 403);

// Vet Admin cannot view or modify Pet Store employee
$petEmp = $pdo->query("SELECT employee_id FROM employee WHERE business_unit_id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$crossStatus = $vet->post("/sysadmin/employees/{$petEmp['employee_id']}/status", [
    'csrf_token' => $vtok,
    'account_status' => 'inactive',
]);
check('Vet Admin modifying Pet Store employee status returns HTTP 403', $crossStatus['status'] === 403);

$crossRole = $vet->post("/sysadmin/employees/{$petEmp['employee_id']}/roles", [
    'csrf_token' => $rtok,
    'roles' => ['RECEPTIONIST'],
]);
check('Vet Admin modifying Pet Store employee roles returns HTTP 403', $crossRole['status'] === 403);

// Pet Admin cannot view or modify Vet Clinic employee
$petAdmin = new VetTestHttp($base);
$petAdmin->staffLogin('sysadmin.petstore@vetgrpet.local', 'SysAdminPet!2026');
$petTok = $petAdmin->csrf($petAdmin->get('/sysadmin/employees')['body']);

$crossVetAct = $petAdmin->get('/sysadmin/activity?unit_id=2');
check('Pet Admin viewing Vet Clinic activity returns HTTP 403', $crossVetAct['status'] === 403);

$crossVetStatus = $petAdmin->post("/sysadmin/employees/{$newEmp['employee_id']}/status", [
    'csrf_token' => $petTok,
    'account_status' => 'inactive',
]);
check('Pet Admin modifying Vet Clinic employee status returns HTTP 403', $crossVetStatus['status'] === 403);

$crossVetRole = $petAdmin->post("/sysadmin/employees/{$newEmp['employee_id']}/roles", [
    'csrf_token' => $petTok,
    'roles' => ['RECEPTIONIST'],
]);
check('Pet Admin modifying Vet Clinic employee roles returns HTTP 403', $crossVetRole['status'] === 403);

echo "\n=======================================================\n";
echo "TOTAL TESTS: " . ($passed + $failed) . " | PASSED: $passed | FAILED: $failed\n";
if ($failed === 0) {
    echo "ALL VETERINARY CLINIC REFACTOR ASSERTIONS PASSED!\n";
} else {
    echo "SOME ASSERTIONS FAILED.\n";
    exit(1);
}
