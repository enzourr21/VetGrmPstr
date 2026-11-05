<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Models\AdminAccountService;
use App\Models\Units;

$base = getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8080';
$pdo = Database::pdo();
$failed = 0;
$total = 0;

function check(string $label, bool $ok, string $details = ''): void
{
    global $failed, $total;
    $total++;
    if ($ok) {
        echo "  PASS $label\n";
    } else {
        $failed++;
        echo "  FAIL $label" . ($details !== '' ? " ($details)" : '') . "\n";
    }
}

function scalar(PDO $pdo, string $sql, array $args = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

function cleanTestUser(PDO $pdo, string $email): void
{
    $st = $pdo->prepare("SELECT user_account_id FROM user_account WHERE email = ?");
    $st->execute([$email]);
    $uid = $st->fetchColumn();
    if ($uid) {
        $pdo->prepare("DELETE FROM account_role WHERE user_account_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM audit_log WHERE actor_user_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM customer WHERE user_account_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM employee WHERE user_account_id = ?")->execute([$uid]);
        $pdo->prepare("DELETE FROM user_account WHERE user_account_id = ?")->execute([$uid]);
    }
}

final class VetTestHttpClient
{
    private string $base;
    private array $cookies = [];

    public function __construct(string $base)
    {
        $this->base = rtrim($base, '/');
    }

    public function request(string $method, string $path, array $data = []): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->base . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        $headers = [];
        if ($this->cookies) {
            $cookiePairs = [];
            foreach ($this->cookies as $k => $v) {
                $cookiePairs[] = "$k=$v";
            }
            $headers[] = 'Cookie: ' . implode('; ', $cookiePairs);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new RuntimeException("CURL request failed: " . curl_error($ch));
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        // Store cookies
        preg_match_all('/^Set-Cookie:\s*([^=;]+)=([^;]+)/mi', $headerText, $m, PREG_SET_ORDER);
        foreach ($m as $match) {
            $this->cookies[trim($match[1])] = trim($match[2]);
        }

        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headerText, $locMatch)) {
            $location = trim($locMatch[1]);
        }

        return ['status' => $status, 'body' => $body, 'location' => $location, 'headers' => $headerText];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, $data);
    }

    public function csrf(string $html): string
    {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/value="([^"]+)"\s+name="csrf_token"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function staffLogin(string $email, string $password): array
    {
        $loginPage = $this->get('/staff/login');
        $token = $this->csrf($loginPage['body']);
        return $this->post('/staff/login', [
            'csrf_token' => $token,
            'email' => $email,
            'password' => $password,
        ]);
    }
}

echo "\n=== Veterinary Clinic System Administrator: System Role Structure Tests ===\n";

// =========================================================================
// Suite 1: Database Role Structure and Scoping
// =========================================================================
echo "\nSuite 1: Database Role Definitions & Shared Role Retention\n";

// Shared applicable Pet Store roles retained for Veterinary Clinic
$vetRoles = AdminAccountService::assignableRolesForUnit(Units::VET_CLINIC);
$vetRoleNames = array_column($vetRoles, 'role_name');

check('SYSTEM_ADMINISTRATOR is retained for Veterinary Clinic', in_array('SYSTEM_ADMINISTRATOR', $vetRoleNames, true));
check('BUSINESS_MANAGER is retained for Veterinary Clinic', in_array('BUSINESS_MANAGER', $vetRoleNames, true));
check('RECEPTIONIST is retained for Veterinary Clinic', in_array('RECEPTIONIST', $vetRoleNames, true));
check('INVENTORY_MANAGER is included for Veterinary Clinic', in_array('INVENTORY_MANAGER', $vetRoleNames, true));

// DELIVERY_PERSONNEL strictly excluded
check('DELIVERY_PERSONNEL is strictly excluded from Veterinary Clinic assignable roles', !in_array('DELIVERY_PERSONNEL', $vetRoleNames, true));

// Three veterinary-specific workspace roles exist in DB
check('VETERINARIAN_WORKSPACE role exists in DB', (int)scalar($pdo, "SELECT COUNT(*) FROM role WHERE role_name = 'VETERINARIAN_WORKSPACE'") === 1);
check('VETERINARY_NURSE_WORKSPACE role exists in DB', (int)scalar($pdo, "SELECT COUNT(*) FROM role WHERE role_name = 'VETERINARY_NURSE_WORKSPACE'") === 1);
check('VETERINARY_TECHNICIAN_WORKSPACE role exists in DB', (int)scalar($pdo, "SELECT COUNT(*) FROM role WHERE role_name = 'VETERINARY_TECHNICIAN_WORKSPACE'") === 1);

// All 3 workspace roles are present in assignable roles for Veterinary Clinic
check('VETERINARIAN_WORKSPACE is assignable in Veterinary Clinic', in_array('VETERINARIAN_WORKSPACE', $vetRoleNames, true));
check('VETERINARY_NURSE_WORKSPACE is assignable in Veterinary Clinic', in_array('VETERINARY_NURSE_WORKSPACE', $vetRoleNames, true));
check('VETERINARY_TECHNICIAN_WORKSPACE is assignable in Veterinary Clinic', in_array('VETERINARY_TECHNICIAN_WORKSPACE', $vetRoleNames, true));

// Exactly 7 assignable roles in Veterinary Clinic
check('Veterinary Clinic has exactly 7 assignable system roles', count($vetRoles) === 7, 'Count: ' . count($vetRoles));

// Pre-existing Pet Store roles remain untouched
check('Pet Store role INVENTORY_MANAGER exists in role table', (int)scalar($pdo, "SELECT COUNT(*) FROM role WHERE role_name = 'INVENTORY_MANAGER'") === 1);
check('Pet Store role DELIVERY_PERSONNEL exists in role table', (int)scalar($pdo, "SELECT COUNT(*) FROM role WHERE role_name = 'DELIVERY_PERSONNEL'") === 1);
$petRoles = AdminAccountService::assignableRolesForUnit(Units::PET_STORE);
$petRoleNames = array_column($petRoles, 'role_name');
check('Pet Store assignable roles still include DELIVERY_PERSONNEL', in_array('DELIVERY_PERSONNEL', $petRoleNames, true));
check('Pet Store assignable roles still include INVENTORY_MANAGER', in_array('INVENTORY_MANAGER', $petRoleNames, true));
check('Pet Store assignable roles exclude VETERINARIAN_WORKSPACE', !in_array('VETERINARIAN_WORKSPACE', $petRoleNames, true));
check('Pet Store assignable roles exclude VETERINARY_NURSE_WORKSPACE', !in_array('VETERINARY_NURSE_WORKSPACE', $petRoleNames, true));
check('Pet Store assignable roles exclude VETERINARY_TECHNICIAN_WORKSPACE', !in_array('VETERINARY_TECHNICIAN_WORKSPACE', $petRoleNames, true));

// =========================================================================
// Suite 2: Distinct Workspaces & Capabilities
// =========================================================================
echo "\nSuite 2: Distinct Workspaces & Capabilities\n";

$getRolePerms = function(string $roleName) use ($pdo): array {
    $st = $pdo->prepare(
        "SELECT p.permission_code
         FROM role_permission rp
         JOIN role r ON r.role_id = rp.role_id
         JOIN permission p ON p.permission_id = rp.permission_id
         WHERE r.role_name = ?
         ORDER BY p.permission_code"
    );
    $st->execute([$roleName]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

$vetPerms = $getRolePerms('VETERINARIAN_WORKSPACE');
$nursePerms = $getRolePerms('VETERINARY_NURSE_WORKSPACE');
$techPerms = $getRolePerms('VETERINARY_TECHNICIAN_WORKSPACE');

check('Veterinarian Workspace has clinical.records.manage', in_array('clinical.records.manage', $vetPerms, true));
check('Veterinarian Workspace has clinical.records.view', in_array('clinical.records.view', $vetPerms, true));
check('Veterinarian Workspace has appointment.view', in_array('appointment.view', $vetPerms, true));
check('Veterinarian Workspace holds exactly 3 permissions', count($vetPerms) === 3);

check('Veterinary Nurse Workspace has appointment.view', in_array('appointment.view', $nursePerms, true));
check('Veterinary Nurse Workspace has clinical.records.view', in_array('clinical.records.view', $nursePerms, true));
check('Veterinary Nurse Workspace does NOT have clinical.records.manage', !in_array('clinical.records.manage', $nursePerms, true));
check('Veterinary Nurse Workspace holds exactly 2 permissions', count($nursePerms) === 2);

check('Veterinary Technician Workspace has appointment.view', in_array('appointment.view', $techPerms, true));
check('Veterinary Technician Workspace does NOT have clinical.records.view', !in_array('clinical.records.view', $techPerms, true));
check('Veterinary Technician Workspace does NOT have clinical.records.manage', !in_array('clinical.records.manage', $techPerms, true));
check('Veterinary Technician Workspace holds exactly 1 permission', count($techPerms) === 1);

check('Veterinary Nurse and Technician workspaces are distinct in permissions', $nursePerms !== $techPerms);
check('Technician Workspace is not named Nurse Technician Workspace', (int)scalar($pdo, "SELECT COUNT(*) FROM role WHERE role_name LIKE '%NURSE%TECH%'") === 0);

// Ensure no Pet Store permissions leaked to clinical roles
foreach (['VETERINARIAN_WORKSPACE', 'VETERINARY_NURSE_WORKSPACE', 'VETERINARY_TECHNICIAN_WORKSPACE'] as $vrn) {
    $perms = $getRolePerms($vrn);
    check("$vrn has no Pet Store inventory or delivery permissions",
        !in_array('inventory.manage', $perms, true) &&
        !in_array('inventory.view', $perms, true) &&
        !in_array('delivery.view', $perms, true) &&
        !in_array('delivery.update', $perms, true)
    );
}

// =========================================================================
// Suite 3: Positions & Position-to-Initial-Role Mapping
// =========================================================================
echo "\nSuite 3: Employee Positions & Position-to-Initial-Role Mapping\n";

$vetPositions = AdminAccountService::positionsForUnit(Units::vetClinicId(), true);
$vetPosNames = array_column($vetPositions, 'position_name');

check('Lead Veterinarian position exists and is active', in_array('Lead Veterinarian', $vetPosNames, true));
check('Associate Veterinarian position exists and is active', in_array('Associate Veterinarian', $vetPosNames, true));
check('Veterinary Nurse position exists and is active', in_array('Veterinary Nurse', $vetPosNames, true));
check('Veterinary Technician position exists and is active', in_array('Veterinary Technician', $vetPosNames, true));
check('Veterinary Receptionist position exists and is active', in_array('Veterinary Receptionist', $vetPosNames, true));
check('System Administrator position exists and is active', in_array('System Administrator', $vetPosNames, true));
check('Business Manager — Veterinary Clinic position exists and is active', in_array('Business Manager — Veterinary Clinic', $vetPosNames, true));
check('Inventory Manager position exists and is active', in_array('Inventory Manager', $vetPosNames, true));
check('Receptionist position exists and is active', in_array('Receptionist', $vetPosNames, true));

// Test initial role mapping
check('Lead Veterinarian maps to VETERINARIAN_WORKSPACE', AdminAccountService::initialRoleForPosition('Lead Veterinarian', Units::VET_CLINIC) === 'VETERINARIAN_WORKSPACE');
check('Associate Veterinarian maps to VETERINARIAN_WORKSPACE', AdminAccountService::initialRoleForPosition('Associate Veterinarian', Units::VET_CLINIC) === 'VETERINARIAN_WORKSPACE');
check('Veterinary Nurse maps to VETERINARY_NURSE_WORKSPACE', AdminAccountService::initialRoleForPosition('Veterinary Nurse', Units::VET_CLINIC) === 'VETERINARY_NURSE_WORKSPACE');
check('Veterinary Technician maps to VETERINARY_TECHNICIAN_WORKSPACE', AdminAccountService::initialRoleForPosition('Veterinary Technician', Units::VET_CLINIC) === 'VETERINARY_TECHNICIAN_WORKSPACE');
check('Veterinary Receptionist maps to RECEPTIONIST', AdminAccountService::initialRoleForPosition('Veterinary Receptionist', Units::VET_CLINIC) === 'RECEPTIONIST');
check('System Administrator maps to SYSTEM_ADMINISTRATOR', AdminAccountService::initialRoleForPosition('System Administrator', Units::VET_CLINIC) === 'SYSTEM_ADMINISTRATOR');
check('Business Manager — Veterinary Clinic maps to BUSINESS_MANAGER', AdminAccountService::initialRoleForPosition('Business Manager — Veterinary Clinic', Units::VET_CLINIC) === 'BUSINESS_MANAGER');
check('Inventory Manager maps to INVENTORY_MANAGER', AdminAccountService::initialRoleForPosition('Inventory Manager', Units::VET_CLINIC) === 'INVENTORY_MANAGER');
check('Receptionist maps to RECEPTIONIST', AdminAccountService::initialRoleForPosition('Receptionist', Units::VET_CLINIC) === 'RECEPTIONIST');

// =========================================================================
// Suite 4: Veterinary Clinic Roles & Permissions UI (/sysadmin/roles)
// =========================================================================
echo "\nSuite 4: Veterinary Clinic Roles & Permissions UI\n";

$vetClient = new VetTestHttpClient($base);
$loginRes = $vetClient->staffLogin('sysadmin.vetclinic@vetgrpet.local', 'SysAdminVet!2026');
check('Veterinary Clinic System Admin logs in to /sysadmin', $loginRes['status'] === 302 && $loginRes['location'] === '/sysadmin');

$rolesPage = $vetClient->get('/sysadmin/roles');
check('Roles & Permissions page loads with 200', $rolesPage['status'] === 200);
check('Page title references Veterinary Clinic', str_contains($rolesPage['body'], 'Roles &amp; Permissions in Veterinary Clinic'));
check('Page heading references Role & Permission Administration', str_contains($rolesPage['body'], 'Role &amp; Permission Administration'));

// Verify all 6 roles appear in the role cards section
check('UI card for SYSTEM_ADMINISTRATOR present', str_contains($rolesPage['body'], '<code>SYSTEM_ADMINISTRATOR</code>'));
check('UI card for BUSINESS_MANAGER present', str_contains($rolesPage['body'], '<code>BUSINESS_MANAGER</code>'));
check('UI card for RECEPTIONIST present', str_contains($rolesPage['body'], '<code>RECEPTIONIST</code>'));
check('UI card for VETERINARIAN_WORKSPACE present', str_contains($rolesPage['body'], '<code>VETERINARIAN_WORKSPACE</code>'));
check('UI card for VETERINARY_NURSE_WORKSPACE present', str_contains($rolesPage['body'], '<code>VETERINARY_NURSE_WORKSPACE</code>'));
check('UI card for VETERINARY_TECHNICIAN_WORKSPACE present', str_contains($rolesPage['body'], '<code>VETERINARY_TECHNICIAN_WORKSPACE</code>'));

// Verify descriptions in cards
check('UI contains Veterinarian Workspace description', str_contains($rolesPage['body'], 'Veterinarian Workspace'));
check('UI contains Veterinary Nurse Workspace description', str_contains($rolesPage['body'], 'Veterinary Nurse Workspace'));
check('UI contains Veterinary Technician Workspace description', str_contains($rolesPage['body'], 'Veterinary Technician Workspace'));

// Verify DELIVERY_PERSONNEL does NOT appear on Veterinary Clinic roles page, but INVENTORY_MANAGER does
check('UI does not offer DELIVERY_PERSONNEL in Veterinary Clinic', !str_contains($rolesPage['body'], 'DELIVERY_PERSONNEL'));
check('UI offers INVENTORY_MANAGER in Veterinary Clinic', str_contains($rolesPage['body'], 'INVENTORY_MANAGER'));

// Verify checkboxes in employee table
check('Checkbox for VETERINARIAN_WORKSPACE present in employee table', str_contains($rolesPage['body'], 'value="VETERINARIAN_WORKSPACE"'));
check('Checkbox for VETERINARY_NURSE_WORKSPACE present in employee table', str_contains($rolesPage['body'], 'value="VETERINARY_NURSE_WORKSPACE"'));
check('Checkbox for VETERINARY_TECHNICIAN_WORKSPACE present in employee table', str_contains($rolesPage['body'], 'value="VETERINARY_TECHNICIAN_WORKSPACE"'));

// =========================================================================
// Suite 5: Backend Authorization & Role Assignment Enforcement
// =========================================================================
echo "\nSuite 5: Backend Authorization & Role Assignment Enforcement\n";

// Target a Veterinary Clinic employee: Claire Dubois (id 3)
$csrfTok = $vetClient->csrf($rolesPage['body']);

// 1. Assign multiple roles independently
$updateMulti = $vetClient->post('/sysadmin/employees/3/roles', [
    'csrf_token' => $csrfTok,
    'business_unit_id' => 2,
    'roles' => ['BUSINESS_MANAGER', 'VETERINARIAN_WORKSPACE', 'RECEPTIONIST'],
]);
check('Assigning multiple roles independently succeeds (302)', $updateMulti['status'] === 302);
$claireRoles = $pdo->query("SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id=ar.role_id JOIN employee e ON e.user_account_id=ar.user_account_id WHERE e.employee_id=3 ORDER BY r.role_name")->fetchAll(PDO::FETCH_COLUMN);
check('Multiple roles saved in DB for employee', $claireRoles === ['BUSINESS_MANAGER', 'RECEPTIONIST', 'VETERINARIAN_WORKSPACE']);

// Restore Claire's original single role
$vetClient->post('/sysadmin/employees/3/roles', [
    'csrf_token' => $csrfTok,
    'business_unit_id' => 2,
    'roles' => ['BUSINESS_MANAGER'],
]);

// 2. Reject DELIVERY_PERSONNEL in Veterinary Clinic
$badDelivery = $vetClient->post('/sysadmin/employees/3/roles', [
    'csrf_token' => $csrfTok,
    'business_unit_id' => 2,
    'roles' => ['DELIVERY_PERSONNEL'],
]);
check('Assigning DELIVERY_PERSONNEL in Veterinary Clinic returns 422', $badDelivery['status'] === 422);

// 3. INVENTORY_MANAGER can be assigned in Veterinary Clinic
$okInv = $vetClient->post('/sysadmin/employees/3/roles', [
    'csrf_token' => $csrfTok,
    'business_unit_id' => 2,
    'roles' => ['BUSINESS_MANAGER', 'INVENTORY_MANAGER'],
]);
check('Assigning INVENTORY_MANAGER in Veterinary Clinic succeeds (302)', $okInv['status'] === 302);
// Restore Claire's original single role
$vetClient->post('/sysadmin/employees/3/roles', [
    'csrf_token' => $csrfTok,
    'business_unit_id' => 2,
    'roles' => ['BUSINESS_MANAGER'],
]);

// 4. Reject Super Administrator assignment by Unit SysAdmin
$badSuper = $vetClient->post('/sysadmin/employees/3/roles', [
    'csrf_token' => $csrfTok,
    'business_unit_id' => 2,
    'roles' => ['SUPER_ADMINISTRATOR'],
]);
check('System Administrator cannot grant SUPER_ADMINISTRATOR (403)', $badSuper['status'] === 403);

// 5. Cross-unit protection: Vet Admin cannot modify Pet Store employee roles
$petEmpId = (int)scalar($pdo, "SELECT employee_id FROM employee WHERE business_unit_id=1 LIMIT 1");
$crossUnit = $vetClient->post("/sysadmin/employees/$petEmpId/roles", [
    'csrf_token' => $csrfTok,
    'business_unit_id' => 2,
    'roles' => ['RECEPTIONIST'],
]);
check('Vet Admin is blocked from modifying Pet Store employee roles (403)', $crossUnit['status'] === 403);

// 6. Changing default position does NOT modify existing system roles
$empPage = $vetClient->get('/sysadmin/employees');
$empCsrf = $vetClient->csrf($empPage['body']);

// Create a test employee with initial position 'Veterinarian (DVM)'
$testEmail = 'vet.pos.test@vetgrpet.local';
cleanTestUser($pdo, $testEmail);

$createTestEmp = $vetClient->post('/sysadmin/employees', [
    'csrf_token' => $empCsrf,
    'business_unit_id' => 2,
    'full_name' => 'Position Test Doc',
    'email' => $testEmail,
    'position' => 'Lead Veterinarian',
    'password' => 'DocPass!2026',
]);
check('Create employee with Lead Veterinarian succeeds', $createTestEmp['status'] === 302);

$testEmpRow = $pdo->query("SELECT e.employee_id, e.user_account_id, e.position FROM employee e JOIN user_account ua ON ua.user_account_id=e.user_account_id WHERE ua.email='$testEmail'")->fetch();
$testEid = (int)$testEmpRow['employee_id'];
$initialRoles = $pdo->query("SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id=ar.role_id WHERE ar.user_account_id={$testEmpRow['user_account_id']}")->fetchAll(PDO::FETCH_COLUMN);
check('Automatic initial role VETERINARIAN_WORKSPACE was assigned', in_array('VETERINARIAN_WORKSPACE', $initialRoles, true));

// Now change employee position to 'Veterinary Nurse'
$posChange = $vetClient->post("/sysadmin/employees/$testEid/position", [
    'csrf_token' => $empCsrf,
    'business_unit_id' => 2,
    'position' => 'Veterinary Nurse',
]);
check('Position changed to Veterinary Nurse (302)', $posChange['status'] === 302);

$rolesAfterPosChange = $pdo->query("SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id=ar.role_id WHERE ar.user_account_id={$testEmpRow['user_account_id']}")->fetchAll(PDO::FETCH_COLUMN);
check('Position change does NOT alter existing system roles', $rolesAfterPosChange === $initialRoles);

// Cleanup test employee
cleanTestUser($pdo, $testEmail);

// =========================================================================
// Suite 6: Employee Creation with Position-to-Initial-Role Mapping
// =========================================================================
echo "\nSuite 6: Employee Creation & Automatic Initial Role Assignment\n";

// 1. Create with 'Veterinary Nurse' without specifying roles -> receives VETERINARY_NURSE_WORKSPACE
$nurseEmail = 'auto.nurse.test@vetgrpet.local';
cleanTestUser($pdo, $nurseEmail);
$createNurse = $vetClient->post('/sysadmin/employees', [
    'csrf_token' => $empCsrf,
    'business_unit_id' => 2,
    'full_name' => 'Auto Nurse',
    'email' => $nurseEmail,
    'position' => 'Veterinary Nurse',
    'password' => 'NursePass!2026',
]);
check('Creation with position Veterinary Nurse succeeds', $createNurse['status'] === 302);
$nurseRow = $pdo->query("SELECT ua.user_account_id FROM user_account ua WHERE ua.email='$nurseEmail'")->fetch();
$nurseAssignedRoles = $pdo->query("SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id=ar.role_id WHERE ar.user_account_id={$nurseRow['user_account_id']}")->fetchAll(PDO::FETCH_COLUMN);
check('VETERINARY_NURSE_WORKSPACE automatically assigned on creation', in_array('VETERINARY_NURSE_WORKSPACE', $nurseAssignedRoles, true));
cleanTestUser($pdo, $nurseEmail);

// 2. Create with 'Veterinary Technician' without specifying roles -> receives VETERINARY_TECHNICIAN_WORKSPACE
$techEmail = 'auto.tech.test@vetgrpet.local';
cleanTestUser($pdo, $techEmail);
$createTech = $vetClient->post('/sysadmin/employees', [
    'csrf_token' => $empCsrf,
    'business_unit_id' => 2,
    'full_name' => 'Auto Tech',
    'email' => $techEmail,
    'position' => 'Veterinary Technician',
    'password' => 'TechPass!2026',
]);
check('Creation with position Veterinary Technician succeeds', $createTech['status'] === 302);
$techRow = $pdo->query("SELECT ua.user_account_id FROM user_account ua WHERE ua.email='$techEmail'")->fetch();
$techAssignedRoles = $pdo->query("SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id=ar.role_id WHERE ar.user_account_id={$techRow['user_account_id']}")->fetchAll(PDO::FETCH_COLUMN);
check('VETERINARY_TECHNICIAN_WORKSPACE automatically assigned on creation', in_array('VETERINARY_TECHNICIAN_WORKSPACE', $techAssignedRoles, true));
cleanTestUser($pdo, $techEmail);

// 3. Create with retired 'Veterinary Assistant' -> rejected (422)
$asstEmail = 'auto.asst.test@vetgrpet.local';
cleanTestUser($pdo, $asstEmail);
$createAsst = $vetClient->post('/sysadmin/employees', [
    'csrf_token' => $empCsrf,
    'business_unit_id' => 2,
    'full_name' => 'Auto Assistant',
    'email' => $asstEmail,
    'position' => 'Veterinary Assistant',
    'password' => 'AsstPass!2026',
]);
check('Creation with retired Veterinary Assistant rejected (422)', $createAsst['status'] === 422);

// 4. Create with 'Associate Veterinarian' -> succeeds and auto-assigns VETERINARIAN_WORKSPACE
$assocEmail = 'auto.assoc.test@vetgrpet.local';
cleanTestUser($pdo, $assocEmail);
$createAssoc = $vetClient->post('/sysadmin/employees', [
    'csrf_token' => $empCsrf,
    'business_unit_id' => 2,
    'full_name' => 'Auto Assoc Vet',
    'email' => $assocEmail,
    'position' => 'Associate Veterinarian',
    'password' => 'AssocPass!2026',
]);
check('Creation with Associate Veterinarian succeeds (302)', $createAssoc['status'] === 302);
$assocRow = $pdo->query("SELECT ua.user_account_id FROM user_account ua WHERE ua.email='$assocEmail'")->fetch();
$assocAssignedRoles = $pdo->query("SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id=ar.role_id WHERE ar.user_account_id={$assocRow['user_account_id']}")->fetchAll(PDO::FETCH_COLUMN);
check('VETERINARIAN_WORKSPACE automatically assigned on creation for Associate Veterinarian', in_array('VETERINARIAN_WORKSPACE', $assocAssignedRoles, true));
cleanTestUser($pdo, $assocEmail);

// 5. Attempting to assign DELIVERY_PERSONNEL during employee creation in Vet Clinic fails
$delivVet = $vetClient->post('/sysadmin/employees', [
    'csrf_token' => $empCsrf,
    'business_unit_id' => 2,
    'full_name' => 'Bad Delivery Vet',
    'email' => 'bad.deliv.vet@vetgrpet.local',
    'position' => 'Veterinary Receptionist',
    'roles' => ['DELIVERY_PERSONNEL'],
    'password' => 'BadPass!2026',
]);
check('Creating employee with DELIVERY_PERSONNEL in Vet Clinic is rejected (422)', $delivVet['status'] === 422);

echo "\n";
if ($failed > 0) {
    echo "FAILED: $failed of $total assertions failed.\n";
    exit(1);
}
echo "ALL $total VETERINARY CLINIC SYSADMIN ROLE ASSERTIONS PASSED\n";
