<?php
declare(strict_types=1);

/**
 * End-to-end verification suite for:
 *  - Priority 1: Permanent Super Administrator account, unrestricted system-wide access,
 *    dedicated Super Admin dashboard & sub-pages, 6-field account creation form,
 *    duplicate Super Admin prevention, and demo navigation button.
 *  - Priority 2: Three business-unit System Administrators (Pet Store, Veterinary Clinic,
 *    Grooming Services) with strict backend business-unit isolation (HTTP 403 on cross-unit).
 *  - Priority 3: Login methods & resulting session contexts:
 *      * Employee/Admin email+password -> authorized employee/admin session
 *      * Employee/Admin username+password -> customer-only session (denied employee/admin routes)
 *      * Customer email+password or username+password -> customer-only session
 *      * Employee entering Customer interface -> preserves employee session while enforcing
 *        customer context without exposing admin/employee privileges on customer pages.
 */

require __DIR__ . '/../app/Core/Core.php';
require_once __DIR__ . '/../scripts/create_super_admin.php';

use App\Core\Database;

$base = getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8080';
$pdo  = Database::pdo();
$results = [];
$failed  = 0;

final class AdminHttpClient
{
    public array $cookies = [];
    public function __construct(private string $base) {}

    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    public function post(string $path, array $data): array
    {
        return $this->send('POST', $path, http_build_query($data));
    }

    public function csrf(string $html): string
    {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        throw new RuntimeException('No CSRF token found in response HTML.');
    }

    private function send(string $method, string $path, ?string $body = null): array
    {
        $headers = ['Accept: text/html'];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "$k=$v";
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);
        $rawBody = @file_get_contents($this->base . $path, false, $ctx);
        if ($rawBody === false) {
            throw new RuntimeException("Could not reach {$this->base}{$path}.");
        }
        $respHeaders = $http_response_header ?? [];
        $status = 0;
        $location = null;
        foreach ($respHeaders as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int)$m[1];
            } elseif (stripos($h, 'Set-Cookie:') === 0 && preg_match('/:\s*([^=]+)=([^;]+)/', $h, $m)) {
                $this->cookies[trim($m[1])] = trim($m[2]);
            } elseif (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => $rawBody];
    }
}

function check(string $suite, string $Assertion, bool $ok, string $detail = ''): void
{
    global $results, $failed;
    $results[] = [$suite, $Assertion, $ok, $detail];
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? '  PASS ' : '  FAIL ') . "[$suite] $Assertion" . ($detail ? " ($detail)" : '') . "\n";
}

function cleanupTestArtifacts(PDO $pdo): void
{
    $testEmails = [
        'ramon.villanueva@vetgrpet.local',
        'noel.bautista@vetgrpet.local',
        'coadmin.unit1@vetgrpet.local',
        'coadmin.unit2@vetgrpet.local',
        'coadmin.unit3@vetgrpet.local',
        'mara.santos@example.com',
    ];
    $in = implode(',', array_fill(0, count($testEmails), '?'));
    $stmt = $pdo->prepare("SELECT user_account_id FROM user_account WHERE email IN ($in)");
    $stmt->execute($testEmails);
    $uids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($uids) {
        $uIn = implode(',', array_map('intval', $uids));
        $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ($uIn)");
        $pdo->exec("DELETE FROM account_role WHERE user_account_id IN ($uIn)");
        $pdo->exec("DELETE FROM employee WHERE user_account_id IN ($uIn)");
        $pdo->exec("DELETE FROM customer WHERE user_account_id IN ($uIn)");
        $pdo->exec("DELETE FROM user_account WHERE user_account_id IN ($uIn)");
    }
    $pdo->exec("DELETE FROM business_position WHERE position_name LIKE '%Lead Coordinator%' OR position_name LIKE '%Tampered Position%'");
}

// Ensure clean seeded state before running Phase 5 verification
cleanupTestArtifacts($pdo);
require __DIR__ . '/../database/seed.php';

// =========================================================================
// 1. Controlled Creation & Duplicate Super Admin Prevention
// =========================================================================
echo "\nSuite 1: Super Administrator controlled creation & duplicate prevention\n";
$superRow = $pdo->query("SELECT user_account_id, email, username, password_hash, is_super_admin FROM user_account WHERE is_super_admin = 1")->fetch();
check('S1', 'Exactly one permanent Super Administrator exists in user_account', (bool)$superRow && $superRow['email'] === 'superadmin@vetgrpet.local');
check('S1', 'Super Administrator password is stored as a secure bcrypt hash', password_verify('SuperAdmin!2026', (string)$superRow['password_hash']) && !str_contains((string)$superRow['password_hash'], 'SuperAdmin!2026'));

$dupAttempt = provisionSuperAdmin($pdo, 'another.super@vetgrpet.local', 'Second', 'Super', 'superadmin2', 'AnotherSuper!2026');
$superCount = (int)$pdo->query("SELECT COUNT(*) FROM user_account WHERE is_super_admin = 1")->fetchColumn();
check('S1', 'provisionSuperAdmin() prevents duplicate Super Admin creation when one already exists', $dupAttempt['created'] === false && $superCount === 1);

// =========================================================================
// 2. Super Admin Login with Email -> Unrestricted System-Wide Access
// =========================================================================
echo "\nSuite 2: Super Admin login with email & unrestricted access across all interfaces\n";
$sa = new AdminHttpClient($base);
$loginPage = $sa->get('/login');
check('S2', 'Login page includes Demo Super Administrator button', str_contains($loginPage['body'], 'action="/demo/super-admin"'));

$step1 = $sa->post('/login/email', ['csrf_token' => $sa->csrf($loginPage['body']), 'email' => 'superadmin@vetgrpet.local']);
check('S2', 'Super Admin email step validates and unlocks password step', $step1['status'] === 200);

$step2 = $sa->post('/login', [
    'csrf_token' => $sa->csrf($step1['body']),
    'email' => 'superadmin@vetgrpet.local',
    'password' => 'SuperAdmin!2026',
]);
check('S2', 'Super Admin email+password login redirects to /super-admin', $step2['status'] === 302 && $step2['location'] === '/super-admin');

$dash = $sa->get('/super-admin');
check('S2', 'Super Admin dashboard renders with system-wide metrics and business units overview', $dash['status'] === 200 && str_contains($dash['body'], 'Super Administrator Dashboard') && str_contains($dash['body'], 'Business Units Overview'));

// Verify all Super Admin sub-pages
foreach ([
    '/super-admin/business-units' => 'Operating Branches',
    '/super-admin/system-admins'  => 'Assigned Business-Unit System Administrators',
    '/super-admin/employees'      => 'Create Employee or System Administrator Account',
    '/super-admin/roles'          => 'System Role Definitions',
    '/super-admin/activity'       => 'System Activity',
] as $url => $heading) {
    $r = $sa->get($url);
    check('S2', "Super Admin can access $url", $r['status'] === 200 && str_contains($r['body'], $heading));
}

// Verify Super Admin can access System Admin, Business Manager, Staff, and Customer interfaces without extra roles
foreach ([
    '/sysadmin?unit_id=1' => 'Pet Store System Administrator',
    '/sysadmin?unit_id=2' => 'Veterinary Clinic System Administrator',
    '/sysadmin?unit_id=3' => 'Grooming Services System Administrator',
    '/manager?unit_id=1'  => 'Pet Store Business Manager Dashboard',
    '/manager?unit_id=2'  => 'Veterinary Clinic Business Manager Dashboard',
    '/manager?unit_id=3'  => 'Grooming Services Business Manager Dashboard',
    '/staff/appointments' => 'Grooming appointment requests',
    '/staff/workspace'    => 'EMPLOYEE WORKSPACE',
] as $url => $marker) {
    $r = $sa->get($url);
    check('S2', "Super Admin has unrestricted access to $url", $r['status'] === 200 && str_contains($r['body'], $marker));
}

// =========================================================================
// 3. Super Admin Creating System Admin & Employee Accounts
// =========================================================================
echo "\nSuite 3: Super Admin creating System Admin and Employee accounts\n";
$saSysPage = $sa->get('/super-admin/system-admins');
$createSysAdminRes = $sa->post('/super-admin/system-admins', [
    'csrf_token' => $sa->csrf($saSysPage['body']),
    'full_name' => 'Dr. Ramon Villanueva',
    'email' => 'ramon.villanueva@vetgrpet.local',
    'username' => 'ramon_vet_admin',
    'business_unit_id' => 2, // Veterinary Clinic
    'position' => 'System Administrator',
    'roles' => ['SYSTEM_ADMINISTRATOR'],
    'password' => 'RamonVet!2026',
    'phone' => '0917-555-0811',
]);
check('S3', 'Super Admin creates a new System Administrator in Veterinary Clinic', $createSysAdminRes['status'] === 302 && $createSysAdminRes['location'] === '/super-admin/system-admins');

$ramonRow = $pdo->query("SELECT ua.user_account_id, ua.password_hash, e.business_unit_id, e.position FROM user_account ua JOIN employee e ON e.user_account_id = ua.user_account_id WHERE ua.email = 'ramon.villanueva@vetgrpet.local'")->fetch();
check('S3', 'Created account is persisted with hashed password, unit #2, and position', (bool)$ramonRow && (int)$ramonRow['business_unit_id'] === 2 && password_verify('RamonVet!2026', (string)$ramonRow['password_hash']));

// Create an ordinary employee via Super Admin (6-field form on /super-admin/employees)
$empPage2 = $sa->get('/super-admin/employees');
$createEmpRes = $sa->post('/super-admin/employees', [
    'csrf_token' => $sa->csrf($empPage2['body']),
    'full_name' => 'Noel Bautista',
    'email' => 'noel.bautista@vetgrpet.local',
    'username' => 'noel_bautista',
    'business_unit_id' => 1, // Pet Store
    'position' => 'Receptionist',
    'roles' => ['RECEPTIONIST'],
    'password' => 'NoelStore!2026',
]);
check('S3', 'Super Admin creates an ordinary Pet Store employee account via 6-field form', $createEmpRes['status'] === 302 && $createEmpRes['location'] === '/super-admin/employees');

// =========================================================================
// 4. Demo Navigation Button Verification
// =========================================================================
echo "\nSuite 4: Demo Super Administrator navigation button\n";
$demoClient = new AdminHttpClient($base);
$anonSuper = $demoClient->get('/super-admin');
check('S4', 'Unauthenticated GET /super-admin redirects to /login (protected by backend auth)', $anonSuper['status'] === 302 && $anonSuper['location'] === '/login');

$staffLogin = $demoClient->get('/staff/login');
check('S4', 'Staff login page also includes Demo Super Administrator button', str_contains($staffLogin['body'], 'action="/demo/super-admin"'));

$demoPost = $demoClient->post('/demo/super-admin', ['csrf_token' => $demoClient->csrf($staffLogin['body'])]);
check('S4', 'POST /demo/super-admin establishes demo Super Admin session and redirects to /super-admin', $demoPost['status'] === 302 && $demoPost['location'] === '/super-admin');
$demoDash = $demoClient->get('/super-admin');
check('S4', 'Demo Super Admin session can load /super-admin dashboard', $demoDash['status'] === 200 && str_contains($demoDash['body'], 'SUPER ADMINISTRATOR'));

// =========================================================================
// 5. Three Business-Unit System Administrators & Strict Backend Isolation
// =========================================================================
echo "\nSuite 5: Three Business-Unit System Administrators & Cross-Unit Isolation\n";
$unitAdmins = [
    1 => ['name' => 'Pet Store',         'email' => 'sysadmin.petstore@vetgrpet.local',  'username' => 'sysadmin_petstore',  'pw' => 'SysAdminPet!2026',   'otherUnit' => 2, 'otherEmpEmail' => 'sysadmin.vetclinic@vetgrpet.local'],
    2 => ['name' => 'Veterinary Clinic', 'email' => 'sysadmin.vetclinic@vetgrpet.local', 'username' => 'sysadmin_vetclinic', 'pw' => 'SysAdminVet!2026',   'otherUnit' => 3, 'otherEmpEmail' => 'sysadmin.grooming@vetgrpet.local'],
    3 => ['name' => 'Grooming Services', 'email' => 'sysadmin.grooming@vetgrpet.local',  'username' => 'sysadmin_grooming',  'pw' => 'SysAdminGroom!2026', 'otherUnit' => 1, 'otherEmpEmail' => 'sysadmin.petstore@vetgrpet.local'],
];

foreach ($unitAdmins as $unitId => $cfg) {
    $c = new AdminHttpClient($base);
    $lp = $c->get('/staff/login');
    $loginRes = $c->post('/staff/login', [
        'csrf_token' => $c->csrf($lp['body']),
        'email' => $cfg['email'],
        'password' => $cfg['pw'],
    ]);
    check('S5', "{$cfg['name']} System Admin email login redirects to /sysadmin", $loginRes['status'] === 302 && $loginRes['location'] === '/sysadmin');

    $ownDash = $c->get('/sysadmin');
    check('S5', "{$cfg['name']} System Admin accesses own unit dashboard (Unit #{$unitId})", $ownDash['status'] === 200 && str_contains($ownDash['body'], $cfg['name'] . ' System Administrator'));

    // Cannot access Super Admin
    $trySuper = $c->get('/super-admin');
    check('S5', "{$cfg['name']} System Admin is denied Super Admin dashboard (HTTP 403)", $trySuper['status'] === 403);

    // Cannot view another business unit via query param (?unit_id=other) or route param (/sysadmin/units/other)
    $tryOtherUnit = $c->get('/sysadmin?unit_id=' . $cfg['otherUnit']);
    check('S5', "{$cfg['name']} System Admin is denied viewing Unit #{$cfg['otherUnit']} via query param (HTTP 403)", $tryOtherUnit['status'] === 403);

    $tryOtherUnitRoute = $c->get('/sysadmin/units/' . $cfg['otherUnit']);
    check('S5', "{$cfg['name']} System Admin is denied viewing Unit #{$cfg['otherUnit']} via route param (HTTP 403)", $tryOtherUnitRoute['status'] === 403);

    $tryOtherEmployees = $c->get('/sysadmin/employees?unit_id=' . $cfg['otherUnit']);
    check('S5', "{$cfg['name']} System Admin is denied viewing Unit #{$cfg['otherUnit']} employees (HTTP 403)", $tryOtherEmployees['status'] === 403);

    // Can create a position in own unit
    $posPage = $c->get('/sysadmin/positions');
    $posPost = $c->post('/sysadmin/positions', [
        'csrf_token' => $c->csrf($posPage['body']),
        'business_unit_id' => $unitId,
        'position_name' => $cfg['name'] . ' Lead Coordinator',
        'description' => 'Unit coordinator position created in automated test',
    ]);
    check('S5', "{$cfg['name']} System Admin can create a position in own unit", $posPost['status'] === 302);

    // Cannot create a position in another unit by tampering with business_unit_id
    $posPage2 = $c->get('/sysadmin/positions');
    $tamperPos = $c->post('/sysadmin/positions', [
        'csrf_token' => $c->csrf($posPage2['body']),
        'business_unit_id' => $cfg['otherUnit'],
        'position_name' => 'Cross Unit Tampered Position',
        'description' => 'Should be blocked',
    ]);
    check('S5', "{$cfg['name']} System Admin is blocked from creating position in Unit #{$cfg['otherUnit']} (HTTP 403)", $tamperPos['status'] === 403);

    // Cannot modify roles of an employee belonging to another unit
    $otherEmpId = (int)$pdo->query("SELECT e.employee_id FROM employee e JOIN user_account ua ON ua.user_account_id = e.user_account_id WHERE ua.email = '{$cfg['otherEmpEmail']}'")->fetchColumn();
    $rolesPage = $c->get('/sysadmin/roles');
    $tamperRole = $c->post("/sysadmin/employees/$otherEmpId/roles", [
        'csrf_token' => $c->csrf($rolesPage['body']),
        'business_unit_id' => $unitId,
        'roles' => ['RECEPTIONIST'],
    ]);
    check('S5', "{$cfg['name']} System Admin is blocked from modifying employee #$otherEmpId in another unit (HTTP 403)", $tamperRole['status'] === 403);

    // Can create an employee in own unit and grant SYSTEM_ADMINISTRATOR within the same unit
    $empFormPage = $c->get('/sysadmin/employees');
    $newEmpPost = $c->post('/sysadmin/employees', [
        'csrf_token' => $c->csrf($empFormPage['body']),
        'business_unit_id' => $unitId,
        'full_name' => "Unit{$unitId} CoAdmin",
        'email' => "coadmin.unit{$unitId}@vetgrpet.local",
        'username' => "coadmin_unit{$unitId}",
        'position' => 'System Administrator',
        'roles' => ['SYSTEM_ADMINISTRATOR'],
        'password' => 'UnitCoAdmin!2026',
    ]);
    check('S5', "{$cfg['name']} System Admin can create an employee and grant SYSTEM_ADMINISTRATOR within own unit", $newEmpPost['status'] === 302);
}

// =========================================================================
// 6. Login Methods & Session Context Rules (Priority 3)
// =========================================================================
echo "\nSuite 6: Login methods (Email vs Username) & Session Context Isolation\n";

// 6a. Ordinary Employee login with EMAIL -> employee session
$empEmailClient = new AdminHttpClient($base);
$lp = $empEmailClient->get('/login');
$s1 = $empEmailClient->post('/login/email', ['csrf_token' => $empEmailClient->csrf($lp['body']), 'email' => 'noel.bautista@vetgrpet.local']);
$s2 = $empEmailClient->post('/login', [
    'csrf_token' => $empEmailClient->csrf($s1['body']),
    'email' => 'noel.bautista@vetgrpet.local',
    'password' => 'NoelStore!2026',
]);
check('S6', 'Employee login with email+password routes to employee workspace (/staff/workspace)', $s2['status'] === 302 && $s2['location'] === '/staff/workspace');
$ws = $empEmailClient->get('/staff/workspace');
check('S6', 'Employee email session can access /staff/workspace', $ws['status'] === 200 && str_contains($ws['body'], 'EMPLOYEE WORKSPACE'));
check('S6', 'Ordinary employee email session is denied /super-admin (HTTP 403)', $empEmailClient->get('/super-admin')['status'] === 403);
check('S6', 'Ordinary employee email session is denied /sysadmin (HTTP 403)', $empEmailClient->get('/sysadmin')['status'] === 403);

// 6b. Employee entering Customer interface from an employee session
$enterCust = $empEmailClient->get('/customer-interface');
check('S6', 'GET /customer-interface switches active context to customer and redirects to /', $enterCust['status'] === 302 && $enterCust['location'] === '/');
$custHome = $empEmailClient->get('/');
check('S6', 'Customer interface in employee session does NOT expose administrative nav links or employee privileges on customer pages',
    $custHome['status'] === 200
    && str_contains($custHome['body'], 'href="/pets"')
    && !str_contains($custHome['body'], 'href="/super-admin"')
    && !str_contains($custHome['body'], 'href="/sysadmin"')
    && !str_contains($custHome['body'], 'href="/workspace"')
);
$petsInCustMode = $empEmailClient->get('/pets');
check('S6', 'Employee in customer active_context can access customer pages (/pets)', $petsInCustMode['status'] === 200);
$wsWhileCustMode = $empEmailClient->get('/staff/workspace');
check('S6', 'Direct access to employee workspace is denied (HTTP 403) while active_context is customer until returning to workspace', $wsWhileCustMode['status'] === 403);

$returnWs = $empEmailClient->get('/workspace');
check('S6', 'GET /workspace restores employee active_context and redirects to /staff/workspace', $returnWs['status'] === 302 && $returnWs['location'] === '/staff/workspace');
check('S6', 'Employee workspace is accessible again after switching back', $empEmailClient->get('/staff/workspace')['status'] === 200);

// 6c. Employee login with USERNAME -> customer-only session
$empUserClient = new AdminHttpClient($base);
$lp = $empUserClient->get('/login');
$u1 = $empUserClient->post('/login/email', ['csrf_token' => $empUserClient->csrf($lp['body']), 'email' => 'noel_bautista']);
check('S6', 'Login step 1 accepts valid username (noel_bautista)', $u1['status'] === 200);
$u2 = $empUserClient->post('/login', [
    'csrf_token' => $empUserClient->csrf($u1['body']),
    'email' => 'noel_bautista',
    'password' => 'NoelStore!2026',
]);
check('S6', 'Employee login with username+password redirects to customer home (/)', $u2['status'] === 302 && $u2['location'] === '/');
$empUserHome = $empUserClient->get('/');
check('S6', 'Employee username session does NOT show Return to Staff Workspace or any staff/admin links',
    !str_contains($empUserHome['body'], 'Return to Staff Workspace')
    && !str_contains($empUserHome['body'], '/staff/workspace')
);
check('S6', 'Employee username session is denied /staff/workspace (HTTP 403)', $empUserClient->get('/staff/workspace')['status'] === 403);
check('S6', 'Employee username session cannot switch to employee context via /workspace (HTTP 403)', $empUserClient->get('/workspace')['status'] === 403);
check('S6', 'Employee username session can access customer pages (/pets)', $empUserClient->get('/pets')['status'] === 200);

// 6d. System Administrator login with USERNAME -> customer-only session
$sysUserClient = new AdminHttpClient($base);
$lp = $sysUserClient->get('/login');
$su1 = $sysUserClient->post('/login/email', ['csrf_token' => $sysUserClient->csrf($lp['body']), 'email' => 'sysadmin_petstore']);
$su2 = $sysUserClient->post('/login', [
    'csrf_token' => $sysUserClient->csrf($su1['body']),
    'email' => 'sysadmin_petstore',
    'password' => 'SysAdminPet!2026',
]);
check('S6', 'System Admin login with username+password redirects to customer home (/) as customer-only session', $su2['status'] === 302 && $su2['location'] === '/');
check('S6', 'System Admin username session is denied /sysadmin (HTTP 403)', $sysUserClient->get('/sysadmin')['status'] === 403);
check('S6', 'System Admin username session is denied /super-admin (HTTP 403)', $sysUserClient->get('/super-admin')['status'] === 403);
check('S6', 'System Admin username session cannot escalate via /workspace (HTTP 403)', $sysUserClient->get('/workspace')['status'] === 403);
check('S6', 'System Admin username session can use customer features (/pets)', $sysUserClient->get('/pets')['status'] === 200);

// Also test System Admin username login on /staff/login
$sysStaffUserClient = new AdminHttpClient($base);
$slp = $sysStaffUserClient->get('/staff/login');
$suStaff = $sysStaffUserClient->post('/staff/login', [
    'csrf_token' => $sysStaffUserClient->csrf($slp['body']),
    'email' => 'sysadmin_vetclinic',
    'password' => 'SysAdminVet!2026',
]);
check('S6', 'System Admin username login on /staff/login also establishes customer-only session (redirects to /)', $suStaff['status'] === 302 && $suStaff['location'] === '/');
check('S6', 'System Admin username session via /staff/login is denied /sysadmin (HTTP 403)', $sysStaffUserClient->get('/sysadmin')['status'] === 403);

// 6e. Customer login with EMAIL and USERNAME -> customer-only session, denied all employee/admin routes
$regClient = new AdminHttpClient($base);
$regForm = $regClient->get('/register');
$regRes = $regClient->post('/register', [
    'csrf_token' => $regClient->csrf($regForm['body']),
    'email' => 'mara.santos@example.com',
    'first_name' => 'Mara',
    'last_name' => 'Santos',
    'phone' => '0917-555-0192',
    'password' => 'Customer!2026',
    'password_confirm' => 'Customer!2026',
]);
$pdo->prepare("UPDATE user_account SET username = 'mara_santos' WHERE email = 'mara.santos@example.com'")->execute();

$custEmailClient = new AdminHttpClient($base);
$lp = $custEmailClient->get('/login');
$ce1 = $custEmailClient->post('/login/email', ['csrf_token' => $custEmailClient->csrf($lp['body']), 'email' => 'mara.santos@example.com']);
$ce2 = $custEmailClient->post('/login', [
    'csrf_token' => $custEmailClient->csrf($ce1['body']),
    'email' => 'mara.santos@example.com',
    'password' => 'Customer!2026',
]);
check('S6', 'Customer login with email+password redirects to /', $ce2['status'] === 302 && $ce2['location'] === '/');
check('S6', 'Customer email session is denied /super-admin (HTTP 403)', $custEmailClient->get('/super-admin')['status'] === 403);
check('S6', 'Customer email session is denied /sysadmin (HTTP 403)', $custEmailClient->get('/sysadmin')['status'] === 403);
check('S6', 'Customer email session is denied /manager (HTTP 403)', $custEmailClient->get('/manager')['status'] === 403);
check('S6', 'Customer email session is denied /staff/workspace (HTTP 403)', $custEmailClient->get('/staff/workspace')['status'] === 403);

$custUserClient = new AdminHttpClient($base);
$lp = $custUserClient->get('/login');
$cu1 = $custUserClient->post('/login/email', ['csrf_token' => $custUserClient->csrf($lp['body']), 'email' => 'mara_santos']);
$cu2 = $custUserClient->post('/login', [
    'csrf_token' => $custUserClient->csrf($cu1['body']),
    'email' => 'mara_santos',
    'password' => 'Customer!2026',
]);
check('S6', 'Customer login with username+password redirects to /', $cu2['status'] === 302 && $cu2['location'] === '/');
check('S6', 'Customer username session can access /pets', $custUserClient->get('/pets')['status'] === 200);
check('S6', 'Customer username session is denied /super-admin (HTTP 403)', $custUserClient->get('/super-admin')['status'] === 403);
check('S6', 'Customer username session is denied /sysadmin (HTTP 403)', $custUserClient->get('/sysadmin')['status'] === 403);

// Restore clean seed state after tests so demo environment is pristine
cleanupTestArtifacts($pdo);
require __DIR__ . '/../database/seed.php';

echo "\n=========================================================================\n";
if ($failed > 0) {
    echo "$failed ASSERTION(S) FAILED OUT OF " . count($results) . "\n";
    exit(1);
}
echo 'ALL ' . count($results) . " PHASE 5 ADMIN & SESSION ASSERTIONS PASSED\n";
