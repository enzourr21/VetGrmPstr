<?php
/**
 * Administrator Top Navigation & Profile Dropdown Verification Suite
 * Tests role-aware navigation links, profile avatar initials, profile dropdown behavior,
 * interface switching, dark mode hooks, logout, and unauthorized URL access boundaries.
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
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIE => $this->cookies,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $res = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header = substr($res, 0, $headerSize);
        $body = substr($res, $headerSize);
        $this->extractCookies($header);
        curl_close($ch);
        return ['status' => $status, 'header' => $header, 'body' => $body];
    }

    public function post(string $path, array $data): array {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_COOKIE => $this->cookies,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $res = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header = substr($res, 0, $headerSize);
        $body = substr($res, $headerSize);
        $this->extractCookies($header);
        curl_close($ch);
        return ['status' => $status, 'header' => $header, 'body' => $body];
    }

    public function csrf(string $html): string {
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
echo "ADMINISTRATOR TOP NAVIGATION & PROFILE DROPDOWN TEST SUITE\n";
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
assertCheck('Guest navigation displays Create account link', str_contains($guestHome['body'], '<a class="nav-cta" href="/register"'));
assertCheck('Guest navigation does NOT display administrator links', !str_contains($guestHome['body'], '/super-admin') && !str_contains($guestHome['body'], '/sysadmin'));
assertCheck('Guest navigation does NOT display profile dropdown', !str_contains($guestHome['body'], 'id="admin-profile-dropdown"'));

// -------------------------------------------------------------------------
// Section 2: Super Administrator Navigation & Profile Menu
// -------------------------------------------------------------------------
echo "\n--- Section 2: Super Administrator Navigation & Profile Menu ---\n";
$superClient = new TestClient($base);
$superLogin = $superClient->loginStaff('superadmin@vetgrpet.local', 'SuperAdmin!2026');
assertCheck('Super Admin logs in successfully (302 redirect)', $superLogin['status'] === 302);

$superDash = $superClient->get('/super-admin');
assertCheck('Super Admin dashboard loads HTTP 200', $superDash['status'] === 200);

// Check Role-Aware Links
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
assertCheck('Profile dropdown contains Switch Interface link', str_contains($superDash['body'], '<a href="/customer-interface" class="profile-dropdown-item" role="menuitem">Switch Interface</a>'));
assertCheck('Profile dropdown contains Dark Mode button', str_contains($superDash['body'], '<button type="button" class="profile-dropdown-item" id="admin-dark-mode-toggle" role="menuitem">Dark Mode</button>'));
assertCheck('Profile dropdown contains Log Out button with CSRF token', str_contains($superDash['body'], 'action="/logout"') && str_contains($superDash['body'], 'Log Out'));

// Check Subpage current class update
$superUnits = $superClient->get('/super-admin/business-units');
assertCheck('Super Admin Business Units page loads HTTP 200', $superUnits['status'] === 200);
assertCheck('Business Units link has is-current class on subpage', str_contains($superUnits['body'], 'href="/super-admin/business-units" class="is-current"'));
assertCheck('Dashboard link does NOT have is-current class on subpage', str_contains($superUnits['body'], 'href="/super-admin" class=""'));

// -------------------------------------------------------------------------
// Section 3: System Administrator Navigation & Business Unit Isolation
// -------------------------------------------------------------------------
echo "\n--- Section 3: System Administrator Navigation & Scoped Links ---\n";
$petSysClient = new TestClient($base);
$petSysLogin = $petSysClient->loginStaff('sysadmin.petstore@vetgrpet.local', 'SysAdminPet!2026');
assertCheck('Pet Store Sys Admin logs in successfully (302 redirect)', $petSysLogin['status'] === 302);

$petSysDash = $petSysClient->get('/sysadmin');
assertCheck('Pet Store Sys Admin dashboard loads HTTP 200', $petSysDash['status'] === 200);

// Check Role-Aware Scoped Links
assertCheck('Sys Admin sees Overview link with is-current class', str_contains($petSysDash['body'], 'href="/sysadmin" class="is-current"'));
assertCheck('Sys Admin sees Unit Employees link', str_contains($petSysDash['body'], 'href="/sysadmin/employees"'));
assertCheck('Sys Admin sees Roles & Permissions link', str_contains($petSysDash['body'], 'href="/sysadmin/roles"'));
assertCheck('Sys Admin sees Activity Log link', str_contains($petSysDash['body'], 'href="/sysadmin/activity"'));

// Isolation: Sys Admin MUST NOT see Super Admin links
assertCheck('Sys Admin does NOT see Super Admin Dashboard', !str_contains($petSysDash['body'], 'href="/super-admin"'));
assertCheck('Sys Admin does NOT see Super Admin Business Units', !str_contains($petSysDash['body'], 'href="/super-admin/business-units"'));
assertCheck('Sys Admin does NOT see Super Admin System Administrators', !str_contains($petSysDash['body'], 'href="/super-admin/system-admins"'));
assertCheck('Sys Admin does NOT see guest links in topbar nav', !preg_match('/<nav aria-label="Main">[\s\S]*?<a href="\/products"/', $petSysDash['body']));

// Check Profile Avatar & Initials for Adrian Mercer ("AM")
assertCheck('Pet Store Sys Admin profile avatar displays initials "AM"', str_contains($petSysDash['body'], '<span class="profile-avatar">AM</span>'));

// Check Vet Clinic Sys Admin (Beatrice Solis -> "BS")
$vetSysClient = new TestClient($base);
$vetSysLogin = $vetSysClient->loginStaff('sysadmin.vetclinic@vetgrpet.local', 'SysAdminVet!2026');
assertCheck('Vet Clinic Sys Admin logs in successfully (302 redirect)', $vetSysLogin['status'] === 302);
$vetSysDash = $vetSysClient->get('/sysadmin');
assertCheck('Vet Clinic Sys Admin profile avatar displays initials "BS"', str_contains($vetSysDash['body'], '<span class="profile-avatar">BS</span>'));

// -------------------------------------------------------------------------
// Section 4: Interface Switching (Admin <-> Customer Interface)
// -------------------------------------------------------------------------
echo "\n--- Section 4: Interface Switching Context & Reversibility ---\n";
// Super Admin switches to customer interface
$switchToCust = $superClient->get('/customer-interface');
assertCheck('Switch to Customer Interface redirects (302)', $switchToCust['status'] === 302);

$custHome = $superClient->get('/');
assertCheck('In Customer Interface, Products link is displayed', str_contains($custHome['body'], '<a href="/products"'));
assertCheck('In Customer Interface, Services link is displayed', str_contains($custHome['body'], '<a href="/services"'));
assertCheck('In Customer Interface, Profile Avatar remains visible in topbar', str_contains($custHome['body'], '<span class="profile-avatar">ES</span>'));
assertCheck('In Customer Interface, dropdown Switch Interface points back to /workspace', str_contains($custHome['body'], '<a href="/workspace" class="profile-dropdown-item" role="menuitem">Switch Interface</a>'));

// Switch back to Admin Interface
$returnWorkspace = $superClient->get('/workspace');
assertCheck('Return to Workspace redirects (302)', $returnWorkspace['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $returnWorkspace['header'], $loc);
assertCheck('Workspace redirect returns to /super-admin', ($loc[1] ?? '') === '/super-admin');

$adminRestored = $superClient->get('/super-admin');
assertCheck('Admin Interface restored: Super Admin links present again', str_contains($adminRestored['body'], 'href="/super-admin" class="is-current"'));

// -------------------------------------------------------------------------
// Section 5: Log Out & Session Invalidation
// -------------------------------------------------------------------------
echo "\n--- Section 5: Log Out & Session Termination ---\n";
$superLogoutHtml = $superClient->get('/super-admin')['body'];
$csrfToken = $superClient->csrf($superLogoutHtml);
$logoutRes = $superClient->post('/logout', ['csrf_token' => $csrfToken]);
assertCheck('Log out POST redirects to /login (302)', $logoutRes['status'] === 302);

// Verify protected page direct access denied after logout
$postLogoutAccess = $superClient->get('/super-admin');
assertCheck('Access to /super-admin after logout is rejected (302 redirect to /login)', $postLogoutAccess['status'] === 302);
preg_match('/Location:\s*([^\r\n]+)/i', $postLogoutAccess['header'], $locPostLogout);
assertCheck('Redirect destination is /login', str_contains($locPostLogout[1] ?? '', '/login'));

// Verify guest navigation restored on homepage
$homeAfterLogout = $superClient->get('/');
assertCheck('Guest navigation restored after logout (contains Sign in)', str_contains($homeAfterLogout['body'], '<a href="/login"'));
assertCheck('Guest navigation restored after logout (no profile menu)', !str_contains($homeAfterLogout['body'], 'id="admin-profile-dropdown"'));

// -------------------------------------------------------------------------
// Section 6: Unauthorized Direct URL Access Protection
// -------------------------------------------------------------------------
echo "\n--- Section 6: Unauthorized Direct URL Access Boundaries ---\n";
// Re-login as Pet Store Sys Admin
$petSysClient = new TestClient($base);
$petSysClient->loginStaff('sysadmin.petstore@vetgrpet.local', 'SysAdminPet!2026');

// Pet Store Sys Admin attempts Super Admin page
$unauthSuper = $petSysClient->get('/super-admin');
assertCheck('Sys Admin accessing /super-admin directly is 403 Forbidden', $unauthSuper['status'] === 403);

$unauthSuperBu = $petSysClient->get('/super-admin/business-units');
assertCheck('Sys Admin accessing /super-admin/business-units directly is 403 Forbidden', $unauthSuperBu['status'] === 403);

// Pet Store Sys Admin attempts to access Vet Clinic unit
$unauthVet = $petSysClient->get('/sysadmin?unit_id=2');
assertCheck('Pet Store Sys Admin accessing Vet Clinic unit via ?unit_id=2 is 403 Forbidden', $unauthVet['status'] === 403);

// Anonymous direct access attempts
$anonClient = new TestClient($base);
$anonSuper = $anonClient->get('/super-admin');
assertCheck('Anonymous accessing /super-admin is redirected to /login (302)', $anonSuper['status'] === 302);

$anonSys = $anonClient->get('/sysadmin');
assertCheck('Anonymous accessing /sysadmin is redirected to /login (302)', $anonSys['status'] === 302);

echo "\n====================================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}

