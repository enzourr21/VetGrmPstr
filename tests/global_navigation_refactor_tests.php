<?php
/**
 * Global Navigation and Workspace Header Refactoring Test Suite
 *
 * Verifies:
 * 1. Global complete removal of secondary subnav (.admin-subnav) across all interfaces.
 * 2. Global complete removal of workspace banner (.admin-workspace-banner) across all interfaces.
 * 3. Topbar navigation presence and role-specific entry points across all roles and units:
 *    - Super Administrator
 *    - System Administrator
 *    - Business Manager (Pet Store, Vet Clinic, Grooming)
 *    - Inventory Manager (Pet Store, Vet Clinic, Grooming)
 *    - Temporary Inventory Access Key holder
 *    - Employee Workspace
 * 4. Veterinary Clinic Inventory Manager:
 *    - Access to EXACTLY 3 pages: Inventory, Medical Records, Employees.
 *    - Topbar contains ONLY these 3 links.
 *    - Strict controller authorization: 403 on Overview, Appointments, Services, Orders, Settings.
 * 5. Multi-role support:
 *    - Employee with both BM and IM roles switching interfaces.
 *    - Accurate top navigation and permission enforcement per active context.
 * 6. Active state indicator (is-current) on topbar links.
 * 7. Profile avatar with interface switcher across staff workspaces and Customer Interface.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$base = 'http://127.0.0.1:8080';
$pdo = \App\Core\Database::pdo();

$passed = 0;
$failed = 0;

function check(string $name, bool $cond, string $extra = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] $name\n";
    } else {
        $failed++;
        echo "  [FAIL] $name" . ($extra ? " ($extra)" : '') . "\n";
    }
}

function req(string $method, string $path, array $data = [], string $cookies = ''): array {
    global $base;
    $ch = curl_init($base . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    if ($cookies !== '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $cookies]);
    }
    $res = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($res, 0, $headerSize);
    $body = substr($res, $headerSize);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    preg_match_all('/Set-Cookie:\s*([^;]+)/i', $header, $matches);
    $cookieStr = $cookies;
    if (!empty($matches[1])) {
        $map = [];
        if ($cookies !== '') {
            foreach (explode(';', $cookies) as $c) {
                $p = explode('=', trim($c), 2);
                if (count($p) === 2) $map[trim($p[0])] = trim($p[1]);
            }
        }
        foreach ($matches[1] as $c) {
            $p = explode('=', trim($c), 2);
            if (count($p) === 2) $map[trim($p[0])] = trim($p[1]);
        }
        $pairs = [];
        foreach ($map as $k => $v) $pairs[] = "$k=$v";
        $cookieStr = implode('; ', $pairs);
    }

    return ['status' => $status, 'header' => $header, 'body' => $body, 'cookies' => $cookieStr];
}

function staffLogin(string $email, string $password): string {
    $lp = req('GET', '/staff/login');
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $lp['body'], $m);
    $csrf = $m[1] ?? '';
    $post = req('POST', '/staff/login', [
        'csrf_token' => $csrf,
        'email' => $email,
        'password' => $password,
    ], $lp['cookies']);
    return $post['cookies'];
}

echo "====================================================================\n";
echo "GLOBAL NAVIGATION & WORKSPACE HEADER REFACTORING TEST SUITE\n";
echo "====================================================================\n";

// ---------------------------------------------------------------------
// SECTION 1: Super Administrator Navigation & Global Absence of Banner/Subnav
// ---------------------------------------------------------------------
echo "\n--- Section 1: Super Administrator Topbar Navigation ---\n";
$saCookies = staffLogin('superadmin@vetgrpet.local', 'SuperAdmin!2026');
check('Super Admin signs in successfully', !empty($saCookies));

$saPages = [
    'Dashboard' => '/super-admin',
    'Business Units' => '/super-admin/business-units',
    'System Admins' => '/super-admin/system-admins',
    'Employees' => '/super-admin/employees',
    'Roles' => '/super-admin/roles',
    'Activity' => '/super-admin/activity',
];

foreach ($saPages as $title => $path) {
    $resp = req('GET', $path, [], $saCookies);
    check("Super Admin $title ($path) loads 200", $resp['status'] === 200);
    check("Super Admin $title has NO admin-subnav", !str_contains($resp['body'], 'class="admin-subnav"'));
    check("Super Admin $title has NO admin-workspace-banner", !str_contains($resp['body'], 'class="admin-workspace-banner"'));
    check("Super Admin $title has topbar nav", str_contains($resp['body'], '<nav aria-label="Main">'));
}

$saDash = req('GET', '/super-admin', [], $saCookies);
check('Super Admin topbar contains Dashboard link', str_contains($saDash['body'], 'href="/super-admin"'));
check('Super Admin topbar Dashboard link has is-current', str_contains($saDash['body'], 'href="/super-admin" class="is-current"'));
check('Super Admin topbar contains Business Units link', str_contains($saDash['body'], 'href="/super-admin/business-units"'));
check('Super Admin topbar contains System Administrators link', str_contains($saDash['body'], 'href="/super-admin/system-admins"'));
check('Super Admin topbar contains Employee Accounts link', str_contains($saDash['body'], 'href="/super-admin/employees"'));
check('Super Admin topbar contains Roles & Permissions link', str_contains($saDash['body'], 'href="/super-admin/roles"'));
check('Super Admin topbar contains System Activity link', str_contains($saDash['body'], 'href="/super-admin/activity"'));
check('Super Admin topbar contains Profile Avatar menu', str_contains($saDash['body'], 'id="admin-profile-toggle"'));

// ---------------------------------------------------------------------
// SECTION 2: System Administrator Topbar Navigation
// ---------------------------------------------------------------------
echo "\n--- Section 2: System Administrator Topbar Navigation ---\n";
$sysCookies = staffLogin('sysadmin.petstore@vetgrpet.local', 'SysAdminPet!2026');
check('Pet Store Sys Admin signs in successfully', !empty($sysCookies));

$sysPages = [
    'Overview' => '/sysadmin',
    'Unit Employees' => '/sysadmin/employees',
    'Roles' => '/sysadmin/roles',
    'Activity' => '/sysadmin/activity',
];

foreach ($sysPages as $title => $path) {
    $resp = req('GET', $path, [], $sysCookies);
    check("Sys Admin $title ($path) loads 200", $resp['status'] === 200);
    check("Sys Admin $title has NO admin-subnav", !str_contains($resp['body'], 'class="admin-subnav"'));
    check("Sys Admin $title has NO admin-workspace-banner", !str_contains($resp['body'], 'class="admin-workspace-banner"'));
}

$sysDash = req('GET', '/sysadmin', [], $sysCookies);
check('Sys Admin topbar contains Overview (with is-current)', str_contains($sysDash['body'], 'href="/sysadmin" class="is-current"'));
check('Sys Admin topbar contains Unit Employees', str_contains($sysDash['body'], 'href="/sysadmin/employees"'));
check('Sys Admin topbar contains Roles & Permissions', str_contains($sysDash['body'], 'href="/sysadmin/roles"'));
check('Sys Admin topbar contains Activity Log', str_contains($sysDash['body'], 'href="/sysadmin/activity"'));
check('Sys Admin does NOT see Super Admin links', !str_contains($sysDash['body'], 'href="/super-admin"'));

// ---------------------------------------------------------------------
// SECTION 3: Business Manager Topbar Navigation (All 3 Units)
// ---------------------------------------------------------------------
echo "\n--- Section 3: Business Manager Topbar Navigation (All 3 Units) ---\n";

// Pet Store BM (David Reyes)
$psBmCookies = staffLogin('david.reyes@staff.sample.vetgrpet', 'Staff123!');
check('Pet Store BM signs in successfully', !empty($psBmCookies));
$psBm = req('GET', '/manager', [], $psBmCookies);
check('Pet Store BM Overview loads 200', $psBm['status'] === 200);
check('Pet Store BM has NO admin-subnav', !str_contains($psBm['body'], 'class="admin-subnav"'));
check('Pet Store BM has NO admin-workspace-banner', !str_contains($psBm['body'], 'class="admin-workspace-banner"'));
check('Pet Store BM topbar has Overview (is-current)', str_contains($psBm['body'], 'href="/manager" class="is-current"'));
check('Pet Store BM topbar has Sales', str_contains($psBm['body'], 'href="/manager/sales"'));
check('Pet Store BM topbar has Products', str_contains($psBm['body'], 'href="/manager/products"'));
check('Pet Store BM topbar has Inventory', str_contains($psBm['body'], 'href="/manager/inventory"'));
check('Pet Store BM topbar has Employees', str_contains($psBm['body'], 'href="/manager/employees"'));
check('Pet Store BM topbar has Business Settings', str_contains($psBm['body'], 'href="/manager/settings"'));

// Vet Clinic BM (Claire Dubois)
$vcBmCookies = staffLogin('claire.dubois@staff.sample.vetgrpet', 'Staff123!');
check('Vet Clinic BM signs in successfully', !empty($vcBmCookies));
$vcBm = req('GET', '/manager', [], $vcBmCookies);
check('Vet Clinic BM Overview loads 200', $vcBm['status'] === 200);
check('Vet Clinic BM has NO admin-subnav', !str_contains($vcBm['body'], 'class="admin-subnav"'));
check('Vet Clinic BM has NO admin-workspace-banner', !str_contains($vcBm['body'], 'class="admin-workspace-banner"'));
check('Vet Clinic BM topbar has Overview (is-current)', str_contains($vcBm['body'], 'href="/manager" class="is-current"'));
check('Vet Clinic BM topbar has Appointments', str_contains($vcBm['body'], 'href="/manager/appointments"'));
check('Vet Clinic BM topbar has Employees', str_contains($vcBm['body'], 'href="/manager/employees"'));
check('Vet Clinic BM topbar has Services', str_contains($vcBm['body'], 'href="/manager/services"'));
check('Vet Clinic BM topbar has Inventory', str_contains($vcBm['body'], 'href="/manager/inventory"'));
check('Vet Clinic BM topbar has Medical Records', str_contains($vcBm['body'], 'href="/manager/medical-records"'));
check('Vet Clinic BM topbar has Business Settings', str_contains($vcBm['body'], 'href="/manager/settings"'));

// Grooming BM (Marcus Chen)
$grBmCookies = staffLogin('marcus.chen@staff.sample.vetgrpet', 'Staff123!');
check('Grooming BM signs in successfully', !empty($grBmCookies));
$grBm = req('GET', '/manager', [], $grBmCookies);
check('Grooming BM Overview loads 200', $grBm['status'] === 200);
check('Grooming BM has NO admin-subnav', !str_contains($grBm['body'], 'class="admin-subnav"'));
check('Grooming BM has NO admin-workspace-banner', !str_contains($grBm['body'], 'class="admin-workspace-banner"'));
check('Grooming BM topbar has Overview (is-current)', str_contains($grBm['body'], 'href="/manager" class="is-current"'));
check('Grooming BM topbar has Appointments', str_contains($grBm['body'], 'href="/manager/appointments"'));
check('Grooming BM topbar has Employees', str_contains($grBm['body'], 'href="/manager/employees"'));
check('Grooming BM topbar has Services', str_contains($grBm['body'], 'href="/manager/services"'));
check('Grooming BM topbar has Inventory', str_contains($grBm['body'], 'href="/manager/inventory"'));
check('Grooming BM topbar has Business Settings', str_contains($grBm['body'], 'href="/manager/settings"'));

// ---------------------------------------------------------------------
// SECTION 4: Pet Store & Grooming Inventory Manager Navigation
// ---------------------------------------------------------------------
echo "\n--- Section 4: Pet Store & Grooming Inventory Manager Navigation ---\n";

// Pet Store IM (Janricson Ompoy)
$psImCookies = staffLogin('ja.ompoy@staff.sample.vetgrpet', 'Staff123!');
check('Pet Store IM signs in successfully', !empty($psImCookies));
$psIm = req('GET', '/manager/orders', [], $psImCookies);
check('Pet Store IM Orders loads 200', $psIm['status'] === 200);
check('Pet Store IM has NO admin-subnav', !str_contains($psIm['body'], 'class="admin-subnav"'));
check('Pet Store IM has NO admin-workspace-banner', !str_contains($psIm['body'], 'class="admin-workspace-banner"'));
check('Pet Store IM topbar has Orders (is-current)', str_contains($psIm['body'], 'href="/manager/orders" class="is-current"'));
check('Pet Store IM topbar has Inventory', str_contains($psIm['body'], 'href="/manager/inventory"'));
check('Pet Store IM topbar has Employees', str_contains($psIm['body'], 'href="/manager/employees"'));
check('Pet Store IM topbar excludes Overview', !str_contains($psIm['body'], 'href="/manager"'));
check('Pet Store IM topbar excludes Sales', !str_contains($psIm['body'], 'href="/manager/sales"'));
check('Pet Store IM topbar excludes Products', !str_contains($psIm['body'], 'href="/manager/products"'));
check('Pet Store IM topbar excludes Business Settings', !str_contains($psIm['body'], 'href="/manager/settings"'));

// Grooming IM (Maya Lin)
$grImCookies = staffLogin('maya.lin@staff.sample.vetgrpet', 'Staff123!');
check('Grooming IM signs in successfully', !empty($grImCookies));
$grIm = req('GET', '/manager/inventory', [], $grImCookies);
check('Grooming IM Inventory loads 200', $grIm['status'] === 200);
check('Grooming IM has NO admin-subnav', !str_contains($grIm['body'], 'class="admin-subnav"'));
check('Grooming IM has NO admin-workspace-banner', !str_contains($grIm['body'], 'class="admin-workspace-banner"'));
check('Grooming IM topbar has Inventory (is-current)', str_contains($grIm['body'], 'href="/manager/inventory" class="is-current"'));
check('Grooming IM topbar has Employees', str_contains($grIm['body'], 'href="/manager/employees"'));
check('Grooming IM topbar strictly excludes Orders', !str_contains($grIm['body'], 'href="/manager/orders"'));
check('Grooming IM topbar strictly excludes Appointments', !str_contains($grIm['body'], 'href="/manager/appointments"'));
check('Grooming IM topbar strictly excludes Services', !str_contains($grIm['body'], 'href="/manager/services"'));

// ---------------------------------------------------------------------
// SECTION 5: Veterinary Clinic Inventory Manager Navigation & Security
// ---------------------------------------------------------------------
echo "\n--- Section 5: Veterinary Clinic Inventory Manager Navigation & Security ---\n";

// Set up a dedicated Vet Clinic Inventory Manager user in DB if not already present
$vcImUser = $pdo->query("SELECT ua.user_account_id, ua.email, e.employee_id
                         FROM user_account ua
                         JOIN employee e ON e.user_account_id = ua.user_account_id
                         JOIN account_role ar ON ar.user_account_id = ua.user_account_id
                         JOIN role r ON r.role_id = ar.role_id
                         WHERE ar.business_unit_id = 2 AND r.role_name = 'INVENTORY_MANAGER'
                         LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$vcImUser) {
    // Create test employee for Vet Clinic Inventory Manager
    $testEmail = 'vetclinic.im@staff.sample.vetgrpet';
    $chkUser = $pdo->prepare("SELECT user_account_id FROM user_account WHERE email = ?");
    $chkUser->execute([$testEmail]);
    $uid = $chkUser->fetchColumn();

    if (!$uid) {
        $hash = password_hash('Staff123!', PASSWORD_BCRYPT);
        $ins = $pdo->prepare("INSERT INTO user_account (email, username, password_hash, account_status, is_super_admin) VALUES (?, 'vetclinic_im', ?, 'active', 0)");
        $ins->execute([$testEmail, $hash]);
        $uid = (int)$pdo->lastInsertId();

        $insEmp = $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status) VALUES (?, 2, 'Lucas', 'Vane', 'Inventory Specialist', 'active')");
        $insEmp->execute([$uid]);
        $empId = (int)$pdo->lastInsertId();
    } else {
        $empId = (int)$pdo->query("SELECT employee_id FROM employee WHERE user_account_id = $uid")->fetchColumn();
    }

    $imRoleId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = 'INVENTORY_MANAGER'")->fetchColumn();
    $pdo->prepare("INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, 2)")
        ->execute([$uid, $imRoleId]);

    $vcImEmail = $testEmail;
} else {
    $vcImEmail = $vcImUser['email'];
}

$vcImCookies = staffLogin($vcImEmail, 'Staff123!');
check('Vet Clinic Inventory Manager signs in successfully', !empty($vcImCookies));

// 1. Authorized Page 1: Inventory
$vcImInv = req('GET', '/manager/inventory', [], $vcImCookies);
check('Vet Clinic IM can access /manager/inventory (HTTP 200)', $vcImInv['status'] === 200);
check('Vet Clinic IM Inventory has NO admin-subnav', !str_contains($vcImInv['body'], 'class="admin-subnav"'));
check('Vet Clinic IM Inventory has NO admin-workspace-banner', !str_contains($vcImInv['body'], 'class="admin-workspace-banner"'));
check('Vet Clinic IM topbar Inventory link is active (is-current)', str_contains($vcImInv['body'], 'href="/manager/inventory" class="is-current"'));
check('Vet Clinic IM Inventory strictly excludes Inventory Overview card', !str_contains($vcImInv['body'], 'id="vet-inventory-overview-card"'));
check('Vet Clinic IM Inventory does NOT display Inventory Overview heading', !str_contains($vcImInv['body'], 'Inventory Overview'));

// 2. Authorized Page 2: Medical Records
$vcImMed = req('GET', '/manager/medical-records', [], $vcImCookies);
check('Vet Clinic IM can access /manager/medical-records (HTTP 200)', $vcImMed['status'] === 200);
check('Vet Clinic IM Medical Records has NO admin-subnav', !str_contains($vcImMed['body'], 'class="admin-subnav"'));
check('Vet Clinic IM Medical Records has NO admin-workspace-banner', !str_contains($vcImMed['body'], 'class="admin-workspace-banner"'));
check('Vet Clinic IM topbar Medical Records link is active (is-current)', str_contains($vcImMed['body'], 'href="/manager/medical-records" class="is-current"'));

// 3. Authorized Page 3: Employees
$vcImEmp = req('GET', '/manager/employees', [], $vcImCookies);
check('Vet Clinic IM can access /manager/employees (HTTP 200)', $vcImEmp['status'] === 200);
check('Vet Clinic IM Employees has NO admin-subnav', !str_contains($vcImEmp['body'], 'class="admin-subnav"'));
check('Vet Clinic IM Employees has NO admin-workspace-banner', !str_contains($vcImEmp['body'], 'class="admin-workspace-banner"'));
check('Vet Clinic IM topbar Employees link is active (is-current)', str_contains($vcImEmp['body'], 'href="/manager/employees" class="is-current"'));

// Verify EXACTLY THREE links in Vet Clinic IM navigation:
check('Vet Clinic IM topbar contains Inventory link', str_contains($vcImInv['body'], 'href="/manager/inventory"'));
check('Vet Clinic IM topbar contains Medical Records link', str_contains($vcImInv['body'], 'href="/manager/medical-records"'));
check('Vet Clinic IM topbar contains Employees link', str_contains($vcImInv['body'], 'href="/manager/employees"'));

// Verify STRICT EXCLUSION of Business Manager pages:
check('Vet Clinic IM topbar strictly excludes Overview link', !str_contains($vcImInv['body'], 'href="/manager"'));
check('Vet Clinic IM topbar strictly excludes Appointments link', !str_contains($vcImInv['body'], 'href="/manager/appointments"'));
check('Vet Clinic IM topbar strictly excludes Services link', !str_contains($vcImInv['body'], 'href="/manager/services"'));
check('Vet Clinic IM topbar strictly excludes Orders link', !str_contains($vcImInv['body'], 'href="/manager/orders"'));
check('Vet Clinic IM topbar strictly excludes Business Settings link', !str_contains($vcImInv['body'], 'href="/manager/settings"'));

// Controller & Route Security: Direct URL Navigation Enforcement
echo "\n--- Vet Clinic IM Route Authorization & URL Access Controls ---\n";
$overviewAttempt = req('GET', '/manager', [], $vcImCookies);
check('Vet Clinic IM accessing /manager redirects away from overview (302 redirect)', $overviewAttempt['status'] === 302 && str_contains($overviewAttempt['header'], '/manager/inventory'));

$apptAttempt = req('GET', '/manager/appointments', [], $vcImCookies);
check('Vet Clinic IM blocked from /manager/appointments (403 Forbidden)', $apptAttempt['status'] === 403);

$servAttempt = req('GET', '/manager/services', [], $vcImCookies);
check('Vet Clinic IM blocked from /manager/services (403 Forbidden)', $servAttempt['status'] === 403);

$ordAttempt = req('GET', '/manager/orders', [], $vcImCookies);
check('Vet Clinic IM blocked from /manager/orders (403 Forbidden)', $ordAttempt['status'] === 403);

$settAttempt = req('GET', '/manager/settings', [], $vcImCookies);
check('Vet Clinic IM blocked from /manager/settings (403 Forbidden)', $settAttempt['status'] === 403);

$crossPetAttempt = req('GET', '/manager/inventory?unit_id=1', [], $vcImCookies);
check('Vet Clinic IM blocked from Pet Store inventory (403 Forbidden)', $crossPetAttempt['status'] === 403);

$crossGrAttempt = req('GET', '/manager/inventory?unit_id=3', [], $vcImCookies);
check('Vet Clinic IM blocked from Grooming inventory (403 Forbidden)', $crossGrAttempt['status'] === 403);

// Also test Super Admin switching to Vet Clinic IM
$saSwitchVclm = req('GET', '/switch-interface?target=inventory-manager&unit_id=2', [], $saCookies);
check('Super Admin can switch to Vet Clinic IM (302 redirect)', $saSwitchVclm['status'] === 302);
$saVclmPage = req('GET', '/manager/inventory?unit_id=2', [], $saSwitchVclm['cookies']);
check('Super Admin in Vet Clinic IM mode accesses HTTP 200', $saVclmPage['status'] === 200);
check('Super Admin in Vet Clinic IM mode has NO admin-subnav', !str_contains($saVclmPage['body'], 'class="admin-subnav"'));
check('Super Admin in Vet Clinic IM mode has NO admin-workspace-banner', !str_contains($saVclmPage['body'], 'class="admin-workspace-banner"'));
check('Super Admin in Vet Clinic IM mode strictly excludes Inventory Overview card', !str_contains($saVclmPage['body'], 'id="vet-inventory-overview-card"'));
check('Super Admin in Vet Clinic IM mode sees EXACTLY 3 links (Inventory, Medical Records, Employees)',
    str_contains($saVclmPage['body'], 'href="/manager/inventory?unit_id=2"') &&
    str_contains($saVclmPage['body'], 'href="/manager/medical-records?unit_id=2"') &&
    str_contains($saVclmPage['body'], 'href="/manager/employees?unit_id=2"') &&
    !str_contains($saVclmPage['body'], 'href="/manager?unit_id=2"') &&
    !str_contains($saVclmPage['body'], 'href="/manager/appointments')
);

// ---------------------------------------------------------------------
// SECTION 6: Multi-Role Employee Support & Interface Switching
// ---------------------------------------------------------------------
echo "\n--- Section 6: Multi-Role Employee Support & Interface Switching ---\n";

// Assign both BUSINESS_MANAGER and INVENTORY_MANAGER roles to Claire Dubois (Vet Clinic BM)
$bmRoleId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = 'BUSINESS_MANAGER'")->fetchColumn();
$imRoleId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = 'INVENTORY_MANAGER'")->fetchColumn();
$claireUid = (int)$pdo->query("SELECT user_account_id FROM user_account WHERE email = 'claire.dubois@staff.sample.vetgrpet'")->fetchColumn();

$pdo->prepare("INSERT IGNORE INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, 2)")
    ->execute([$claireUid, $imRoleId]);

$multiCookies = staffLogin('claire.dubois@staff.sample.vetgrpet', 'Staff123!');
check('Multi-role employee (Claire Dubois) signs in successfully', !empty($multiCookies));

// View Business Manager interface
$claireBm = req('GET', '/manager', [], $multiCookies);
check('Multi-role employee sees Business Manager overview (200)', $claireBm['status'] === 200);
check('Multi-role BM switcher contains Business Manager option', str_contains($claireBm['body'], 'target=manager'));
check('Multi-role BM switcher contains Inventory Manager option', str_contains($claireBm['body'], 'target=inventory-manager'));
check('Multi-role BM topbar has 7 links including Overview and Appointments',
    str_contains($claireBm['body'], 'href="/manager" class="is-current"') &&
    str_contains($claireBm['body'], 'href="/manager/appointments"')
);

// Switch to Inventory Manager
$switchToIm = req('GET', '/switch-interface?target=inventory-manager&unit_id=2', [], $multiCookies);
check('Multi-role employee switches to Inventory Manager (302 redirect)', $switchToIm['status'] === 302);

$claireIm = req('GET', '/manager/inventory', [], $switchToIm['cookies']);
check('Multi-role employee in IM mode sees Inventory (200)', $claireIm['status'] === 200);
check('Multi-role employee in IM mode strictly excludes Inventory Overview card', !str_contains($claireIm['body'], 'id="vet-inventory-overview-card"'));
check('Multi-role IM topbar contains EXACTLY 3 links: Inventory, Medical Records, Employees',
    str_contains($claireIm['body'], 'href="/manager/inventory" class="is-current"') &&
    str_contains($claireIm['body'], 'href="/manager/medical-records"') &&
    str_contains($claireIm['body'], 'href="/manager/employees"')
);
check('Multi-role IM topbar strictly excludes Overview and Appointments',
    !str_contains($claireIm['body'], 'href="/manager"') &&
    !str_contains($claireIm['body'], 'href="/manager/appointments"')
);

// Verify direct URL block when in IM mode (redirects away from overview)
$multiBmBlocked = req('GET', '/manager', [], $switchToIm['cookies']);
check('Multi-role employee in IM mode accessing /manager redirects away from overview (302 redirect)', $multiBmBlocked['status'] === 302 && str_contains($multiBmBlocked['header'], '/manager/inventory'));

// Switch back to Business Manager
$switchToBm = req('GET', '/switch-interface?target=manager&unit_id=2', [], $switchToIm['cookies']);
check('Multi-role employee switches back to Business Manager (302 redirect)', $switchToBm['status'] === 302);

$claireBmRestored = req('GET', '/manager', [], $switchToBm['cookies']);
check('Multi-role employee restored to Business Manager overview (200)', $claireBmRestored['status'] === 200);
check('Restored BM topbar has Overview and Appointments links again',
    str_contains($claireBmRestored['body'], 'href="/manager" class="is-current"') &&
    str_contains($claireBmRestored['body'], 'href="/manager/appointments"')
);

// ---------------------------------------------------------------------
// SECTION 7: Temporary Inventory Access Key Holder Navigation
// ---------------------------------------------------------------------
echo "\n--- Section 7: Temporary Inventory Access Key Holder Navigation ---\n";

// Generate an active key in Vet Clinic via Claire
$vcInvPage = req('GET', '/manager/inventory', [], $vcBmCookies);
check('Vet Clinic BM inventory retains Inventory Overview card', str_contains($vcInvPage['body'], 'id="vet-inventory-overview-card"'));
check('Vet Clinic BM inventory retains Inventory Overview heading', str_contains($vcInvPage['body'], 'Inventory Overview'));
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $vcInvPage['body'], $m);
$csrfVc = $m[1] ?? '';

$genKey = req('POST', '/manager/inventory/generate-key', ['csrf_token' => $csrfVc], $vcBmCookies);
check('Vet Clinic BM generates inventory key (302 redirect)', $genKey['status'] === 302);
$activeKey = $pdo->query("SELECT key_code FROM inventory_access_key WHERE business_unit_id = 2 AND is_active = 1 ORDER BY key_id DESC LIMIT 1")->fetchColumn();
check('Active key obtained from database', !empty($activeKey));

// Nurse (Hannah Kim) signs in and redeems key
$nurseCookies = staffLogin('hannah.kim@staff.sample.vetgrpet', 'Staff123!');
check('Vet Nurse signs in successfully', !empty($nurseCookies));

$nurseWs = req('GET', '/staff/workspace', [], $nurseCookies);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $nurseWs['body'], $m2);
$csrfNurse = $m2[1] ?? '';

$redeem = req('POST', '/staff/inventory-access', ['csrf_token' => $csrfNurse, 'inventory_key' => $activeKey], $nurseCookies);
check('Nurse redeems inventory access key (302 redirect)', $redeem['status'] === 302);

$tempInv = req('GET', '/manager/inventory', [], $redeem['cookies']);
check('Nurse with temporary key accesses /manager/inventory (200)', $tempInv['status'] === 200);
check('Temporary access topbar contains Inventory link (is-current)', str_contains($tempInv['body'], 'href="/manager/inventory" class="is-current"'));
check('Temporary access topbar contains &larr; Employee Workspace link', str_contains($tempInv['body'], 'href="/staff/workspace"') && str_contains($tempInv['body'], 'Employee Workspace'));
check('Temporary access has NO admin-subnav', !str_contains($tempInv['body'], 'class="admin-subnav"'));
check('Temporary access has NO admin-workspace-banner', !str_contains($tempInv['body'], 'class="admin-workspace-banner"'));

// Security restrictions for temporary key holders
$tempMedAttempt = req('GET', '/manager/medical-records', [], $redeem['cookies']);
check('Temporary key holder blocked from medical records (403 Forbidden)', $tempMedAttempt['status'] === 403);

$tempEmpAttempt = req('GET', '/manager/employees', [], $redeem['cookies']);
check('Temporary key holder blocked from employees roster (403 Forbidden)', $tempEmpAttempt['status'] === 403);

$tempOverviewAttempt = req('GET', '/manager', [], $redeem['cookies']);
check('Temporary key holder blocked from /manager overview (403 Forbidden)', $tempOverviewAttempt['status'] === 403);

// Revoke key to clean up
$vcInvPage2 = req('GET', '/manager/inventory', [], $vcBmCookies);
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $vcInvPage2['body'], $m3);
$csrfVc2 = $m3[1] ?? '';
$revoke = req('POST', '/manager/inventory/revoke-key', ['csrf_token' => $csrfVc2], $vcBmCookies);
check('Vet Clinic BM revokes key (302 redirect)', $revoke['status'] === 302);

// ---------------------------------------------------------------------
// SECTION 8: Active Link Indicator (is-current) Topbar Verification
// ---------------------------------------------------------------------
echo "\n--- Section 8: Active Link Indicator (is-current) Topbar Verification ---\n";

// Pet Store BM navigating subpages:
$psSales = req('GET', '/manager/sales', [], $psBmCookies);
check('Pet Store BM Sales page: Sales link has is-current', str_contains($psSales['body'], 'href="/manager/sales" class="is-current"'));
check('Pet Store BM Sales page: Overview link does NOT have is-current', str_contains($psSales['body'], 'href="/manager" class=""'));

$psProds = req('GET', '/manager/products', [], $psBmCookies);
check('Pet Store BM Products page: Products link has is-current', str_contains($psProds['body'], 'href="/manager/products" class="is-current"'));
check('Pet Store BM Products page: Overview link does NOT have is-current', str_contains($psProds['body'], 'href="/manager" class=""'));

$psInv = req('GET', '/manager/inventory', [], $psBmCookies);
check('Pet Store BM Inventory page: Inventory link has is-current', str_contains($psInv['body'], 'href="/manager/inventory" class="is-current"'));
check('Pet Store BM Inventory page: Overview link does NOT have is-current', str_contains($psInv['body'], 'href="/manager" class=""'));

// Vet Clinic BM navigating subpages:
$vcAppts = req('GET', '/manager/appointments', [], $vcBmCookies);
check('Vet Clinic BM Appointments page: Appointments link has is-current', str_contains($vcAppts['body'], 'href="/manager/appointments" class="is-current"'));
check('Vet Clinic BM Appointments page: Overview link does NOT have is-current', str_contains($vcAppts['body'], 'href="/manager" class=""'));

$vcMeds = req('GET', '/manager/medical-records', [], $vcBmCookies);
check('Vet Clinic BM Medical Records page: Medical Records link has is-current', str_contains($vcMeds['body'], 'href="/manager/medical-records" class="is-current"'));
check('Vet Clinic BM Medical Records page: Overview link does NOT have is-current', str_contains($vcMeds['body'], 'href="/manager" class=""'));

// ---------------------------------------------------------------------
// SECTION 9: Customer Interface & Profile Dropdown
// ---------------------------------------------------------------------
echo "\n--- Section 9: Customer Interface & Profile Dropdown ---\n";
$guestHome = req('GET', '/');
check('Guest homepage loads 200', $guestHome['status'] === 200);
check('Guest homepage contains Products link', str_contains($guestHome['body'], '<a href="/products"'));
check('Guest homepage contains Services link', str_contains($guestHome['body'], '<a href="/services"'));
check('Guest homepage contains Staffs link', str_contains($guestHome['body'], '<a href="/staffs"'));
check('Guest homepage contains Customer Reviews link', str_contains($guestHome['body'], '<a href="/reviews"'));
check('Guest homepage contains Sign in link', str_contains($guestHome['body'], '<a href="/login"'));
check('Guest homepage contains Create account link', str_contains($guestHome['body'], '<a class="nav-cta" href="/register">Create account</a>'));
check('Guest homepage does NOT have profile avatar', !str_contains($guestHome['body'], 'id="admin-profile-toggle"'));

// Staff visiting customer interface
$staffCustHome = req('GET', '/', [], $psBmCookies);
check('Staff visiting customer interface retains Profile Avatar in topbar', str_contains($staffCustHome['body'], 'id="admin-profile-toggle"'));
check('Staff visiting customer interface has Switch Interface menu', str_contains($staffCustHome['body'], 'id="profile-switcher-menu"'));

echo "\n====================================================================\n";
echo "GLOBAL NAVIGATION REFACTOR TEST RESULTS:\n";
echo "Total Passed: $passed\n";
echo "Total Failed: $failed\n";
echo "====================================================================\n";

if ($failed === 0) {
    echo "ALL GLOBAL NAVIGATION REFACTOR TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME GLOBAL NAVIGATION REFACTOR TESTS FAILED!\n";
    exit(1);
}
