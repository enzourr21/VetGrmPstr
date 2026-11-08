<?php
/**
 * Administrator Top Navigation & Interface Switching Verification Suite
 * Tests:
 * 1. Single primary navigation bar (no redundant subnav in super admin).
 * 2. Administrator context panel preservation with all operational shortcuts.
 * 3. Exact active state determination across all top navigation destinations.
 * 4. Multi-role authorized interface switching (Super Admin, Sys Admin, BM, Employee, Customer).
 * 5. Role isolation and tamper resistance (no privilege escalation via interface switching).
 * 6. Profile dropdown with Switch Interface submenu, Dark Mode toggle, and secure CSRF logout.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = \App\Core\Database::pdo();
$base = 'http://127.0.0.1:8080';

$passed = 0;
$failed = 0;

function assertCheck(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}\n";
    }
}

class TestClient {
    private string $base;
    public string $cookies = '';

    public function __construct(string $base) {
        $this->base = $base;
    }

    public function get(string $path): array {
        $ch = curl_init($this->base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($this->cookies !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookies);
        }
        $raw = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $this->extractCookies($header);
        curl_close($ch);
        return ['status' => $status, 'header' => $header, 'body' => $body];
    }

    public function post(string $path, array $data = []): array {
        $ch = curl_init($this->base . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        if ($this->cookies !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookies);
        }
        $raw = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $this->extractCookies($header);
        curl_close($ch);
        return ['status' => $status, 'header' => $header, 'body' => $body];
    }

    public function csrf(string $html): string {
        if (preg_match('/name="(?:_csrf|csrf_token)"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function loginStaff(string $email, string $password): array {
        $lp = $this->get('/staff/login');
        return $this->post('/staff/login', [
            'csrf_token' => $this->csrf($lp['body']),
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function extractCookies(string $header): void {
        preg_match_all('/Set-Cookie:\s*([^;]+)/i', $header, $matches);
        if (!empty($matches[1])) {
            $newCookieMap = [];
            if ($this->cookies !== '') {
                foreach (explode(';', $this->cookies) as $c) {
                    $parts = explode('=', trim($c), 2);
                    if (count($parts) === 2) {
                        $newCookieMap[trim($parts[0])] = trim($parts[1]);
                    }
                }
            }
            foreach ($matches[1] as $c) {
                $parts = explode('=', trim($c), 2);
                if (count($parts) === 2) {
                    $newCookieMap[trim($parts[0])] = trim($parts[1]);
                }
            }
            $pairs = [];
            foreach ($newCookieMap as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            $this->cookies = implode('; ', $pairs);
        }
    }
}

echo "====================================================================\n";
echo "ADMINISTRATOR TOP NAVIGATION & INTERFACE SWITCHING TEST SUITE\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// Section 1: Guest / Unauthenticated Navigation
// -------------------------------------------------------------------------
echo "--- Section 1: Guest Navigation Baseline ---\n";
$guestClient = new TestClient($base);
$guestHome = $guestClient->get('/');
assertCheck('Guest homepage loads HTTP 200', $guestHome['status'] === 200);
assertCheck('Guest navigation displays Products', str_contains($guestHome['body'], '<a href="/products"'));
assertCheck('Guest navigation displays Services', str_contains($guestHome['body'], '<a href="/services"'));
assertCheck('Guest navigation displays Staffs', str_contains($guestHome['body'], '<a href="/staffs"'));
assertCheck('Guest navigation displays Customer Reviews', str_contains($guestHome['body'], '<a href="/reviews"'));
assertCheck('Guest navigation displays Sign in link', str_contains($guestHome['body'], '<a href="/login"'));
assertCheck('Guest navigation displays Create account link', str_contains($guestHome['body'], '<a class="nav-cta" href="/register">Create account</a>'));
assertCheck('Guest navigation does NOT display administrator links', !str_contains($guestHome['body'], 'href="/super-admin"'));
assertCheck('Guest navigation does NOT display profile dropdown', !str_contains($guestHome['body'], 'id="admin-profile-toggle"'));

// -------------------------------------------------------------------------
// Section 2: Super Administrator Navigation & Elimination of Duplicate Nav
// -------------------------------------------------------------------------
echo "\n--- Section 2: Super Administrator Navigation & Single Nav Bar ---\n";
$superClient = new TestClient($base);
$superLogin = $superClient->loginStaff('superadmin@vetgrpet.local', 'SuperAdmin!2026');
assertCheck('Super Admin logs in successfully (302 redirect)', $superLogin['status'] === 302);

$superDash = $superClient->get('/super-admin');
assertCheck('Super Admin dashboard loads HTTP 200', $superDash['status'] === 200);

// Single primary navigation bar check:
assertCheck('Super Admin layout contains primary topbar nav', str_contains($superDash['body'], '<nav aria-label="Main">'));
assertCheck('Super Admin layout does NOT contain duplicate admin-subnav', !str_contains($superDash['body'], '<nav class="admin-subnav"'));

// Workspace banner and secondary subnav must be completely removed globally:
assertCheck('Super Admin layout has NO admin-workspace-banner', !str_contains($superDash['body'], 'class="admin-workspace-banner"'));
assertCheck('Super Admin layout has NO admin-subnav', !str_contains($superDash['body'], 'class="admin-subnav"'));

// Check Topbar Links
assertCheck('Super Admin sees Dashboard link with is-current class', str_contains($superDash['body'], 'href="/super-admin" class="is-current"'));
assertCheck('Super Admin sees Business Units link', str_contains($superDash['body'], 'href="/super-admin/business-units"'));
assertCheck('Super Admin sees System Administrators link', str_contains($superDash['body'], 'href="/super-admin/system-admins"'));
assertCheck('Super Admin sees Employee Accounts link', str_contains($superDash['body'], 'href="/super-admin/employees"'));
assertCheck('Super Admin sees Roles & Permissions link', str_contains($superDash['body'], 'href="/super-admin/roles"'));
assertCheck('Super Admin sees System Activity link', str_contains($superDash['body'], 'href="/super-admin/activity"'));

// Check Guest links are replaced
assertCheck('Super Admin does NOT see guest Products link in topbar nav', !preg_match('/<nav aria-label="Main">[\s\S]*?<a href="\/products"/', $superDash['body']));
assertCheck('Super Admin does NOT see guest Services link in topbar nav', !preg_match('/<nav aria-label="Main">[\s\S]*?<a href="\/services"/', $superDash['body']));

// Check Profile Avatar & Initials
assertCheck('Super Admin header contains profile avatar button', str_contains($superDash['body'], 'id="admin-profile-toggle"'));
assertCheck('Super Admin profile avatar displays Evelyn Sterling initials "ES"', str_contains($superDash['body'], '<span class="profile-avatar">ES</span>'));

// Check Profile Dropdown Elements
assertCheck('Super Admin header contains profile dropdown container with hidden attribute', str_contains($superDash['body'], 'id="admin-profile-dropdown" role="menu" hidden'));
assertCheck('Profile dropdown contains Switch Interface toggle button', str_contains($superDash['body'], 'id="profile-switch-btn"'));
assertCheck('Profile dropdown contains Switch Interface submenu container', str_contains($superDash['body'], 'id="profile-switcher-menu"'));
assertCheck('Profile dropdown contains Dark Mode button', str_contains($superDash['body'], '<button type="button" class="profile-dropdown-item" id="admin-dark-mode-toggle" role="menuitem">Dark Mode</button>'));
assertCheck('Profile dropdown contains Log Out button with CSRF token', str_contains($superDash['body'], 'action="/logout"') && str_contains($superDash['body'], 'Log Out'));

// Check Super Admin authorized interfaces & extending business unit menus in dropdown
assertCheck('Switcher contains Super Administrator direct option (marked Current)', str_contains($superDash['body'], 'target=super-admin') && str_contains($superDash['body'], 'is-current-interface'));
assertCheck('Switcher contains System Administrator expandable button', str_contains($superDash['body'], 'data-target="sysadmin"') && str_contains($superDash['body'], 'profile-switcher-parent-btn'));
assertCheck('Switcher contains Business Manager expandable button', str_contains($superDash['body'], 'data-target="manager"') && str_contains($superDash['body'], 'profile-switcher-parent-btn'));
assertCheck('Switcher contains Employee Workspace expandable button', str_contains($superDash['body'], 'data-target="employee"') && str_contains($superDash['body'], 'profile-switcher-parent-btn'));
assertCheck('Switcher contains Customer Interface direct option', str_contains($superDash['body'], 'target=customer') && str_contains($superDash['body'], 'profile-switcher-option'));

// Check 3 Business Units appear below Business Manager:
assertCheck('Business Manager submenu contains Pet Store unit link', str_contains($superDash['body'], 'target=manager&amp;unit_id=1') || str_contains($superDash['body'], 'target=manager&unit_id=1'));
assertCheck('Business Manager submenu contains Veterinary Clinic unit link', str_contains($superDash['body'], 'target=manager&amp;unit_id=2') || str_contains($superDash['body'], 'target=manager&unit_id=2'));
assertCheck('Business Manager submenu contains Grooming Services unit link', str_contains($superDash['body'], 'target=manager&amp;unit_id=3') || str_contains($superDash['body'], 'target=manager&unit_id=3'));

// Check 3 Business Units appear below System Administrator:
assertCheck('System Administrator submenu contains Pet Store unit link', str_contains($superDash['body'], 'target=sysadmin&amp;unit_id=1') || str_contains($superDash['body'], 'target=sysadmin&unit_id=1'));
assertCheck('System Administrator submenu contains Veterinary Clinic unit link', str_contains($superDash['body'], 'target=sysadmin&amp;unit_id=2') || str_contains($superDash['body'], 'target=sysadmin&unit_id=2'));
assertCheck('System Administrator submenu contains Grooming Services unit link', str_contains($superDash['body'], 'target=sysadmin&amp;unit_id=3') || str_contains($superDash['body'], 'target=sysadmin&unit_id=3'));

// -------------------------------------------------------------------------
// Section 3: Active State Accuracy Across Subpages (Root Cause Bug Fix)
// -------------------------------------------------------------------------
echo "\n--- Section 3: Active State Accuracy Across Admin Subpages ---\n";
// Subpage: Business Units
$superUnits = $superClient->get('/super-admin/business-units');
assertCheck('Super Admin Business Units page loads HTTP 200', $superUnits['status'] === 200);
assertCheck('Business Units link has is-current class on subpage', str_contains($superUnits['body'], 'href="/super-admin/business-units" class="is-current"'));
assertCheck('Dashboard link does NOT have is-current class on Business Units page', str_contains($superUnits['body'], 'href="/super-admin" class=""'));

// Subpage: System Administrators (The exact issue from user screenshot)
$superSysAdmins = $superClient->get('/super-admin/system-admins');
assertCheck('Super Admin System Administrators page loads HTTP 200', $superSysAdmins['status'] === 200);
assertCheck('System Administrators link has is-current class', str_contains($superSysAdmins['body'], 'href="/super-admin/system-admins" class="is-current"'));
assertCheck('Dashboard link does NOT have is-current class on System Administrators page', str_contains($superSysAdmins['body'], 'href="/super-admin" class=""'));
assertCheck('System Administrators page has NO duplicate admin-subnav', !str_contains($superSysAdmins['body'], '<nav class="admin-subnav"'));

// Subpage: Employee Accounts
$superEmps = $superClient->get('/super-admin/employees');
assertCheck('Employee Accounts link has is-current class', str_contains($superEmps['body'], 'href="/super-admin/employees" class="is-current"'));
assertCheck('Dashboard link does NOT have is-current class on Employee Accounts page', str_contains($superEmps['body'], 'href="/super-admin" class=""'));

// Subpage: Roles & Permissions
$superRoles = $superClient->get('/super-admin/roles');
assertCheck('Roles & Permissions link has is-current class', str_contains($superRoles['body'], 'href="/super-admin/roles" class="is-current"'));
assertCheck('Dashboard link does NOT have is-current class on Roles page', str_contains($superRoles['body'], 'href="/super-admin" class=""'));

// Subpage: System Activity
$superActivity = $superClient->get('/super-admin/activity');
assertCheck('System Activity link has is-current class', str_contains($superActivity['body'], 'href="/super-admin/activity" class="is-current"'));
assertCheck('Dashboard link does NOT have is-current class on Activity page', str_contains($superActivity['body'], 'href="/super-admin" class=""'));

// -------------------------------------------------------------------------
// Section 4: System Administrator Navigation & Authorized Interfaces
// -------------------------------------------------------------------------
echo "\n--- Section 4: System Administrator Navigation & Scoped Links ---\n";
$petSysClient = new TestClient($base);
$petSysLogin = $petSysClient->loginStaff('sysadmin.petstore@vetgrpet.local', 'SysAdminPet!2026');
assertCheck('Pet Store Sys Admin logs in successfully (302 redirect)', $petSysLogin['status'] === 302);

$petSysDash = $petSysClient->get('/sysadmin');
assertCheck('Pet Store Sys Admin dashboard loads HTTP 200', $petSysDash['status'] === 200);

// Check Role-Aware Scoped Links
assertCheck('Sys Admin sees Overview link with is-current class', str_contains($petSysDash['body'], 'href="/sysadmin"') && str_contains($petSysDash['body'], 'is-current'));
assertCheck('Sys Admin layout does NOT contain duplicate admin-subnav', !str_contains($petSysDash['body'], '<nav class="admin-subnav"'));
assertCheck('Sys Admin sees Unit Employees link', str_contains($petSysDash['body'], 'href="/sysadmin/employees"'));
assertCheck('Sys Admin sees Roles & Permissions link', str_contains($petSysDash['body'], 'href="/sysadmin/roles"'));
assertCheck('Sys Admin sees Activity Log link', str_contains($petSysDash['body'], 'href="/sysadmin/activity"'));

// Isolation: Sys Admin MUST NOT see Super Admin links in topbar
assertCheck('Sys Admin does NOT see Super Admin Dashboard', !str_contains($petSysDash['body'], 'href="/super-admin"'));
assertCheck('Sys Admin does NOT see Super Admin Business Units', !str_contains($petSysDash['body'], 'href="/super-admin/business-units"'));
assertCheck('Sys Admin does NOT see Super Admin System Administrators', !str_contains($petSysDash['body'], 'href="/super-admin/system-admins"'));
assertCheck('Sys Admin does NOT see guest links in topbar nav', !preg_match('/<nav aria-label="Main">[\s\S]*?<a href="\/products"/', $petSysDash['body']));

// Check Profile Avatar & Initials for Adrian Mercer ("AM")
assertCheck('Pet Store Sys Admin profile avatar displays initials "AM"', str_contains($petSysDash['body'], '<span class="profile-avatar">AM</span>'));

// Check Sys Admin Switcher menu:
assertCheck('Sys Admin switcher contains System Administrator (Current)', str_contains($petSysDash['body'], 'target=sysadmin') && str_contains($petSysDash['body'], 'is-current-interface'));
assertCheck('Sys Admin switcher contains Employee Workspace', str_contains($petSysDash['body'], 'target=employee'));
assertCheck('Sys Admin switcher contains Customer Interface', str_contains($petSysDash['body'], 'target=customer'));
assertCheck('Sys Admin switcher does NOT contain Super Administrator', !str_contains($petSysDash['body'], 'target=super-admin'));
assertCheck('Sys Admin switcher does NOT contain Business Manager', !str_contains($petSysDash['body'], 'target=manager'));

// Check Vet Clinic Sys Admin (Beatrice Solis -> "BS")
$vetSysClient = new TestClient($base);
$vetSysLogin = $vetSysClient->loginStaff('sysadmin.vetclinic@vetgrpet.local', 'SysAdminVet!2026');
assertCheck('Vet Clinic Sys Admin logs in successfully (302 redirect)', $vetSysLogin['status'] === 302);
$vetSysDash = $vetSysClient->get('/sysadmin');
assertCheck('Vet Clinic Sys Admin profile avatar displays initials "BS"', str_contains($vetSysDash['body'], '<span class="profile-avatar">BS</span>'));

// -------------------------------------------------------------------------
// Section 5: Multi-Interface Switching & Session Reversibility
// -------------------------------------------------------------------------
echo "\n--- Section 5: Multi-Interface Switching & Session Reversibility ---\n";
// Super Admin switches to System Admin interface
$switchToSys = $superClient->get('/switch-interface?target=sysadmin');
assertCheck('Super Admin switches to Sys Admin redirects (302)', $switchToSys['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $switchToSys['header'], $loc);
assertCheck('Redirect destination is /sysadmin', trim($loc[1] ?? '') === '/sysadmin');

$sysView = $superClient->get('/sysadmin');
assertCheck('Sys Admin landing page loads HTTP 200', $sysView['status'] === 200);
assertCheck('Top navigation now displays Sys Admin Overview', str_contains($sysView['body'], 'href="/sysadmin"') && str_contains($sysView['body'], 'is-current'));

// Super Admin switches to Business Manager interface (Veterinary Clinic unit 2)
$switchToVcBm = $superClient->get('/switch-interface?target=manager&unit_id=2');
assertCheck('Super Admin switches to Vet Clinic BM redirects (302)', $switchToVcBm['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $switchToVcBm['header'], $loc);
assertCheck('Redirect destination is /manager?unit_id=2', trim($loc[1] ?? '') === '/manager?unit_id=2');

$vcBmView = $superClient->get('/manager?unit_id=2');
assertCheck('Vet Clinic BM landing page loads HTTP 200', $vcBmView['status'] === 200);
assertCheck('Vet Clinic BM page contains Veterinary Clinic', str_contains($vcBmView['body'], 'Veterinary Clinic'));

// Super Admin switches to Business Manager interface (Grooming Services unit 3)
$switchToGrBm = $superClient->get('/switch-interface?target=manager&unit_id=3');
assertCheck('Super Admin switches to Grooming BM redirects (302)', $switchToGrBm['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $switchToGrBm['header'], $loc);
assertCheck('Redirect destination is /manager?unit_id=3', trim($loc[1] ?? '') === '/manager?unit_id=3');

$grBmView = $superClient->get('/manager?unit_id=3');
assertCheck('Grooming BM landing page loads HTTP 200', $grBmView['status'] === 200);
assertCheck('Grooming BM page contains Grooming Services', str_contains($grBmView['body'], 'Grooming Services'));

// Super Admin switches to Business Manager interface (Pet Store default)
$switchToBm = $superClient->get('/switch-interface?target=manager');
assertCheck('Super Admin switches to Business Manager redirects (302)', $switchToBm['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $switchToBm['header'], $loc);
assertCheck('Redirect destination is /manager', trim($loc[1] ?? '') === '/manager');

$bmView = $superClient->get('/manager');
assertCheck('Business Manager landing page loads HTTP 200', $bmView['status'] === 200);
assertCheck('Business Manager layout has NO admin-workspace-banner', !str_contains($bmView['body'], 'class="admin-workspace-banner"'));
assertCheck('Business Manager top navigation contains Overview', str_contains($bmView['body'], 'href="/manager"') && str_contains($bmView['body'], 'is-current'));

// Super Admin switches to Employee Workspace
$switchToEmp = $superClient->get('/switch-interface?target=employee');
assertCheck('Super Admin switches to Employee Workspace redirects (302)', $switchToEmp['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $switchToEmp['header'], $loc);
assertCheck('Redirect destination is /staff/workspace', trim($loc[1] ?? '') === '/staff/workspace');

$empView = $superClient->get('/staff/workspace');
assertCheck('Employee Workspace loads HTTP 200', $empView['status'] === 200);

// Super Admin switches to Customer Interface
$switchToCust = $superClient->get('/switch-interface?target=customer');
assertCheck('Super Admin switches to Customer Interface redirects (302)', $switchToCust['status'] === 302);

$custHome = $superClient->get('/');
assertCheck('In Customer Interface, Products link is displayed', str_contains($custHome['body'], '<a href="/products"'));
assertCheck('In Customer Interface, Services link is displayed', str_contains($custHome['body'], '<a href="/services"'));
assertCheck('In Customer Interface, Profile Avatar remains visible in topbar', str_contains($custHome['body'], '<span class="profile-avatar">ES</span>'));
assertCheck('In Customer Interface, profile switcher lists Super Admin to return', str_contains($custHome['body'], 'target=super-admin'));
assertCheck('Main page does NOT contain redundant customer navigation cards', !str_contains($custHome['body'], 'Your Customer Account') && !str_contains($custHome['body'], 'href="/appointments/new"'));

// Super Admin returns to Super Administrator interface
$returnSuper = $superClient->get('/switch-interface?target=super-admin');
assertCheck('Return to Super Admin redirects (302)', $returnSuper['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $returnSuper['header'], $loc);
assertCheck('Redirect destination is /super-admin', trim($loc[1] ?? '') === '/super-admin');

$adminRestored = $superClient->get('/super-admin');
assertCheck('Super Admin Interface restored: Dashboard link active', str_contains($adminRestored['body'], 'href="/super-admin" class="is-current"'));

// -------------------------------------------------------------------------
// Section 6: Business Manager Authorized Interface Switching
// -------------------------------------------------------------------------
echo "\n--- Section 6: Business Manager Interface Switching ---\n";
$bmClient = new TestClient($base);
$bmLogin = $bmClient->loginStaff('david.reyes@staff.sample.vetgrpet', 'Staff123!');
assertCheck('Pet Store BM logs in successfully (302)', $bmLogin['status'] === 302);

$bmDash = $bmClient->get('/manager');
assertCheck('BM dashboard loads HTTP 200', $bmDash['status'] === 200);

// BM switches to Employee Workspace
$bmToEmp = $bmClient->get('/switch-interface?target=employee');
assertCheck('BM switches to Employee Workspace redirects (302)', $bmToEmp['status'] === 302);
$bmEmpView = $bmClient->get('/staff/workspace');
assertCheck('BM employee workspace loads HTTP 200', $bmEmpView['status'] === 200);

// BM switches back to Business Manager
$bmToBm = $bmClient->get('/switch-interface?target=manager');
assertCheck('BM switches back to Manager redirects (302)', $bmToBm['status'] === 302);
$bmDashAgain = $bmClient->get('/manager');
assertCheck('BM dashboard restored HTTP 200', $bmDashAgain['status'] === 200);

// -------------------------------------------------------------------------
// Section 7: Security Boundaries & Anti-Privilege-Escalation
// -------------------------------------------------------------------------
echo "\n--- Section 7: Security Boundaries & Anti-Privilege-Escalation ---\n";
// 1. Sys Admin cannot switch to Super Admin (403 Forbidden)
$sysToSuper = $petSysClient->get('/switch-interface?target=super-admin');
assertCheck('Sys Admin attempting switch to Super Admin is 403 Forbidden', $sysToSuper['status'] === 403);

// 2. Sys Admin cannot switch to Business Manager (403 Forbidden)
$sysToBm = $petSysClient->get('/switch-interface?target=manager');
assertCheck('Sys Admin attempting switch to Business Manager is 403 Forbidden', $sysToBm['status'] === 403);

// 3. Business Manager cannot switch to Super Admin (403 Forbidden)
$bmToSuper = $bmClient->get('/switch-interface?target=super-admin');
assertCheck('BM attempting switch to Super Admin is 403 Forbidden', $bmToSuper['status'] === 403);

// 4. Business Manager cannot switch to System Admin (403 Forbidden)
$bmToSys = $bmClient->get('/switch-interface?target=sysadmin');
assertCheck('BM attempting switch to System Admin is 403 Forbidden', $bmToSys['status'] === 403);

// 5. Direct access to /super-admin blocked for Sys Admin
$unauthSuper = $petSysClient->get('/super-admin');
assertCheck('Sys Admin accessing /super-admin directly is 403 Forbidden', $unauthSuper['status'] === 403);

// 6. Direct access to /super-admin/business-units blocked for Sys Admin
$unauthSuperBu = $petSysClient->get('/super-admin/business-units');
assertCheck('Sys Admin accessing /super-admin/business-units directly is 403 Forbidden', $unauthSuperBu['status'] === 403);

// 7. Pet Store Sys Admin cross-unit access blocked
$unauthVet = $petSysClient->get('/sysadmin?unit_id=2');
assertCheck('Pet Store Sys Admin accessing Vet Clinic unit via ?unit_id=2 is 403 Forbidden', $unauthVet['status'] === 403);

// 8. Anonymous access redirects to /login
$anonClient = new TestClient($base);
$anonSuper = $anonClient->get('/super-admin');
assertCheck('Anonymous accessing /super-admin is redirected to /login (302)', $anonSuper['status'] === 302);
$anonSwitch = $anonClient->get('/switch-interface?target=super-admin');
assertCheck('Anonymous accessing /switch-interface is redirected to /login (302)', $anonSwitch['status'] === 302);

// -------------------------------------------------------------------------
// Section 8: Log Out & Session Termination
// -------------------------------------------------------------------------
echo "\n--- Section 8: Log Out & Session Invalidation ---\n";
$superLogoutHtml = $superClient->get('/super-admin')['body'];
$csrfToken = $superClient->csrf($superLogoutHtml);
$logoutRes = $superClient->post('/logout', ['csrf_token' => $csrfToken]);
assertCheck('Log out POST redirects to /login (302)', $logoutRes['status'] === 302);

$postLogoutAccess = $superClient->get('/super-admin');
assertCheck('Access to /super-admin after logout is rejected (302 redirect to /login)', $postLogoutAccess['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $postLogoutAccess['header'], $locPostLogout);
assertCheck('Redirect destination is /login', str_contains($locPostLogout[1] ?? '', '/login'));

$homeAfterLogout = $superClient->get('/');
assertCheck('Guest navigation restored after logout (contains Sign in)', str_contains($homeAfterLogout['body'], '<a href="/login"'));
assertCheck('Guest navigation restored after logout (no profile menu)', !str_contains($homeAfterLogout['body'], 'id="admin-profile-dropdown"'));

echo "\n====================================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
