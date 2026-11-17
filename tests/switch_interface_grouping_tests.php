<?php
declare(strict_types=1);

/**
 * PawCariño — Business-Grouped Switch Interface Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Never truncates, resets, or drops tables; creates isolated fixtures and deletes only created rows.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/process_cleanup_helper.php';

use App\Core\Database;

$pdo = Database::pdo();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$allowedTestDatabases = ['vetgrpet_test'];
if ($dbName === '' || !in_array($dbName, $allowedTestDatabases, true)) {
    fwrite(STDERR, "Refusing to run against non-allowlisted database: {$dbName}\n");
    exit(1);
}

$passed = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        echo "FAIL: {$label}" . ($detail !== '' ? " [{$detail}]" : '') . "\n";
    }
}

final class SwitcherHttpClient
{
    public array $cookies = [];
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, http_build_query($data));
    }

    public function extractCsrf(string $html): string
    {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        throw new RuntimeException('CSRF token not found in HTML response.');
    }

    public function staffLogin(string $email, string $password): array
    {
        $this->cookies = [];
        $page = $this->get('/staff/login');
        $csrf = $this->extractCsrf($page['body']);
        return $this->post('/staff/login', [
            'csrf_token' => $csrf,
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function send(string $method, string $path, ?string $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = ['Accept: text/html', 'Connection: close'];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed for {$method} {$path}: {$err}");
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerText = substr($raw, 0, $headerSize);
        $resBody = substr($raw, $headerSize);
        curl_close($ch);

        preg_match_all('/^Set-Cookie:\s*([^=;]+)=([^;]+)/mi', $headerText, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $this->cookies[trim($m[1])] = trim($m[2]);
        }

        $location = '';
        if (preg_match('/^Location:\s*(.+)$/im', $headerText, $m)) {
            $location = trim($m[1]);
        }

        return ['status' => $status, 'location' => $location, 'body' => $resBody];
    }
}

/**
 * Parse #profile-switcher-menu into ordered direct options and grouped business-unit blocks.
 *
 * @return array{
 *   order: array<int, string>,
 *   direct: array<string, array{url:string, active:bool}>,
 *   groups: array<string, array{id:string, active:bool, items:array<int, array{name:string, url:string, active:bool}>}>
 * }
 */
function parseSwitcherMenu(string $html): array
{
    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($dom);
    $menuNode = $xpath->query('//*[@id="profile-switcher-menu"]')->item(0);
    $result = ['order' => [], 'direct' => [], 'groups' => []];
    if (!$menuNode) {
        return $result;
    }

    foreach ($menuNode->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        $cls = ' ' . $child->getAttribute('class') . ' ';
        if (str_contains($cls, ' profile-switcher-option ')) {
            $nameNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " switcher-opt-name ")]', $child)->item(0);
            $label = trim($nameNode ? $nameNode->textContent : $child->textContent);
            $result['order'][] = $label;
            $result['direct'][$label] = [
                'url' => (string)$child->getAttribute('href'),
                'active' => str_contains($cls, ' is-current-interface '),
            ];
        } elseif (str_contains($cls, ' profile-switcher-group ')) {
            $btn = $xpath->query('./button', $child)->item(0);
            if (!$btn) {
                continue;
            }
            $nameNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " switcher-opt-name ")]', $btn)->item(0);
            $groupLabel = trim($nameNode ? $nameNode->textContent : $btn->textContent);
            $btnCls = ' ' . $btn->getAttribute('class') . ' ';
            $groupId = (string)$btn->getAttribute('data-target');
            $items = [];
            foreach ($xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " profile-switcher-unit-link ")]', $child) as $a) {
                $aCls = ' ' . $a->getAttribute('class') . ' ';
                $uNameNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " unit-name ")]', $a)->item(0);
                $items[] = [
                    'name' => trim($uNameNode ? $uNameNode->textContent : $a->textContent),
                    'url' => (string)$a->getAttribute('href'),
                    'active' => str_contains($aCls, ' is-current-unit '),
                ];
            }
            $result['order'][] = $groupLabel;
            $result['groups'][$groupLabel] = [
                'id' => $groupId,
                'active' => str_contains($btnCls, ' is-current-parent '),
                'items' => $items,
            ];
        }
    }

    return $result;
}

function startBuiltInServer(int $port): array
{
    $root = dirname(__DIR__);
    $phpBin = 'D:\\VETGRPETproj\\tools\\php83\\php.exe';
    if (!is_file($phpBin)) {
        $phpBin = PHP_BINARY;
    }
    $cmd = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', $phpBin, $port, $root . DIRECTORY_SEPARATOR . 'public', $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
    $nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $desc = [
        0 => ['file', $nul, 'r'],
        1 => ['file', $nul, 'w'],
        2 => ['file', $nul, 'w'],
    ];
    $proc = proc_open($cmd, $desc, $pipes, $root);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start built-in PHP server.');
    }

    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if ($fp !== false) {
            fclose($fp);
            return [$proc, $pipes];
        }
        usleep(100000);
    }
    safelyTerminateAndClose($proc, $pipes, 2);
    throw new RuntimeException("Built-in server did not listen on port {$port} in time.");
}

function createTestStaff(PDO $pdo, string $email, int $unitId, string $first, string $last, string $position, string $pwHash, array $roleAssignments, bool $isSuper = false): array
{
    $pdo->prepare(
        "INSERT INTO user_account (email, password_hash, account_status, is_super_admin) VALUES (?, ?, 'active', ?)"
    )->execute([$email, $pwHash, $isSuper ? 1 : 0]);
    $uid = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, phone, employee_status)
         VALUES (?, ?, ?, ?, ?, '0917-000-0000', 'active')"
    )->execute([$uid, $unitId, $first, $last, $position]);
    $empId = (int)$pdo->lastInsertId();

    $rFind = $pdo->prepare('SELECT role_id FROM role WHERE role_name = ? LIMIT 1');
    $arIns = $pdo->prepare('INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)');
    foreach ($roleAssignments as [$uId, $roleName]) {
        $rFind->execute([$roleName]);
        $rId = $rFind->fetchColumn();
        if (!$rId) {
            throw new RuntimeException("Role not found in test DB: {$roleName}");
        }
        $arIns->execute([$uid, (int)$rId, (int)$uId]);
    }

    return [$uid, $empId];
}

$port = 18092;
[$serverProc, $serverPipes] = startBuiltInServer($port);
$baseUrl = "http://127.0.0.1:{$port}";
$suffix = bin2hex(random_bytes(4));
$pwPlain = 'SwitchTest!2026';
$pwHash = password_hash($pwPlain, PASSWORD_BCRYPT);
$createdUserIds = [];

try {
    echo "====================================================================\n";
    echo "BUSINESS-GROUPED SWITCH INTERFACE TEST SUITE (DB: {$dbName})\n";
    echo "====================================================================\n";

    [$superUid] = createTestStaff($pdo, "sw_super_{$suffix}@test.invalid", 1, 'Super', 'Admin', 'Super Administrator', $pwHash, [[1, 'SUPER_ADMINISTRATOR']], true);
    [$leadVetUid] = createTestStaff($pdo, "sw_leadvet_{$suffix}@test.invalid", 2, 'Elena', 'Lead', 'Lead Veterinarian', $pwHash, [[2, 'VETERINARIAN_WORKSPACE']]);
    [$assocVetUid] = createTestStaff($pdo, "sw_assocvet_{$suffix}@test.invalid", 2, 'Marco', 'Assoc', 'Associate Veterinarian', $pwHash, [[2, 'VETERINARIAN_WORKSPACE']]);
    [$nurseUid] = createTestStaff($pdo, "sw_nurse_{$suffix}@test.invalid", 2, 'Hannah', 'Nurse', 'Veterinary Nurse', $pwHash, [[2, 'VETERINARY_NURSE_WORKSPACE']]);
    [$assistUid] = createTestStaff($pdo, "sw_assist_{$suffix}@test.invalid", 2, 'Leo', 'Assist', 'Veterinary Technician', $pwHash, [[2, 'VETERINARY_TECHNICIAN_WORKSPACE']]);
    [$noroleVetUid] = createTestStaff($pdo, "sw_norolevet_{$suffix}@test.invalid", 2, 'Ghost', 'Vet', 'Lead Veterinarian', $pwHash, []);
    [$masterGroomUid] = createTestStaff($pdo, "sw_mastergroom_{$suffix}@test.invalid", 3, 'Owen', 'Master', 'Master Stylist', $pwHash, [[3, 'STYLIST_WORKSPACE'], [3, 'BATHER_WORKSPACE']]);
    [$batherUid] = createTestStaff($pdo, "sw_bather_{$suffix}@test.invalid", 3, 'Bea', 'Bather', 'Bather', $pwHash, [[3, 'BATHER_WORKSPACE']]);
    [$courierUid, $courierEmpId] = createTestStaff($pdo, "sw_courier_{$suffix}@test.invalid", 1, 'Carlo', 'Courier', 'Delivery Personnel', $pwHash, [[1, 'DELIVERY_PERSONNEL']]);
    [$crossCourierUid] = createTestStaff($pdo, "sw_crosscourier_{$suffix}@test.invalid", 2, 'Cross', 'Courier', 'Veterinary Nurse', $pwHash, [[1, 'DELIVERY_PERSONNEL'], [2, 'VETERINARY_NURSE_WORKSPACE']]);
    [$multiUnitUid, $multiEmpId] = createTestStaff($pdo, "sw_multi_{$suffix}@test.invalid", 1, 'Multi', 'Manager', 'Business Manager — Pet Store', $pwHash, [[1, 'BUSINESS_MANAGER'], [2, 'INVENTORY_MANAGER'], [3, 'RECEPTIONIST']]);
    [$multiSysUid] = createTestStaff($pdo, "sw_sysmulti_{$suffix}@test.invalid", 1, 'Sys', 'Admin', 'System Administrator', $pwHash, [[1, 'SYSTEM_ADMINISTRATOR'], [3, 'SYSTEM_ADMINISTRATOR']]);

    $createdUserIds = [$superUid, $leadVetUid, $assocVetUid, $nurseUid, $assistUid, $noroleVetUid, $masterGroomUid, $batherUid, $courierUid, $crossCourierUid, $multiUnitUid, $multiSysUid];

    // -----------------------------------------------------------------
    // 1. Correct Business-Unit Grouping (Super Admin view)
    // -----------------------------------------------------------------
    $sa = new SwitcherHttpClient($baseUrl);
    $saLogin = $sa->staffLogin("sw_super_{$suffix}@test.invalid", $pwPlain);
    check('Super Admin logs in successfully', $saLogin['status'] === 302);
    $saDash = $sa->get('/super-admin');
    $saMenu = parseSwitcherMenu($saDash['body']);

    check(
        'Top-level switcher order matches business-unit grouping structure',
        $saMenu['order'] === ['Super Administrator', 'Pet Store', 'Veterinary Clinic', 'Pet Grooming', 'System Administrator', 'Customer Interface'],
        'Actual: ' . implode(' > ', $saMenu['order'])
    );

    $psNames = array_column($saMenu['groups']['Pet Store']['items'] ?? [], 'name');
    check(
        'Pet Store group contains exact expected interfaces in order',
        $psNames === ['Business Manager', 'Receptionist', 'Inventory Manager', 'Delivery Personnel'],
        'Actual: ' . implode(', ', $psNames)
    );

    $vcNames = array_column($saMenu['groups']['Veterinary Clinic']['items'] ?? [], 'name');
    check(
        'Veterinary Clinic group contains exact expected 7 interfaces in order',
        $vcNames === ['Business Manager', 'Receptionist', 'Inventory Manager', 'Lead Vet', 'Associate Vet', 'Vet Nurse', 'Vet Assistant'],
        'Actual: ' . implode(', ', $vcNames)
    );

    $pgNames = array_column($saMenu['groups']['Pet Grooming']['items'] ?? [], 'name');
    check(
        'Pet Grooming group contains exact discovered applicable roles',
        $pgNames === ['Business Manager', 'Receptionist', 'Inventory Manager', 'Stylist', 'Bather'],
        'Actual: ' . implode(', ', $pgNames)
    );

    $sysNames = array_column($saMenu['groups']['System Administrator']['items'] ?? [], 'name');
    check(
        'System Administrator group contains Pet Store, Veterinary Clinic, Pet Groom Service',
        $sysNames === ['Pet Store', 'Veterinary Clinic', 'Pet Groom Service'],
        'Actual: ' . implode(', ', $sysNames)
    );

    check(
        'Super Administrator option is active on /super-admin',
        !empty($saMenu['direct']['Super Administrator']['active'])
    );

    // Super Admin can still switch across all interfaces + delivery read-only
    $saToDelivery = $sa->get('/switch-interface?target=delivery');
    $saDelivView = $sa->get('/delivery/orders');
    check(
        'Super Admin preserves read-only Delivery Personnel switch & access',
        $saToDelivery['status'] === 302 && $saToDelivery['location'] === '/delivery/orders'
            && $saDelivView['status'] === 200
            && str_contains($saDelivView['body'], 'Administrative Oversight Mode (Read-Only)')
    );
    $saToLeadVet = $sa->get('/switch-interface?target=vetclinic-lead-vet');
    $saLeadVetPage = $sa->get($saToLeadVet['location']);
    $saLeadMenu = parseSwitcherMenu($saLeadVetPage['body']);
    check(
        'Super Admin switching to Lead Vet activates Veterinary Clinic -> Lead Vet',
        $saToLeadVet['status'] === 302
            && !empty($saLeadMenu['groups']['Veterinary Clinic']['active'])
            && !empty($saLeadMenu['groups']['Veterinary Clinic']['items'][3]['active'])
    );

    // -----------------------------------------------------------------
    // 2. Role & Position Isolation inside Business Unit Groups
    // -----------------------------------------------------------------
    $leadClient = new SwitcherHttpClient($baseUrl);
    $leadClient->staffLogin("sw_leadvet_{$suffix}@test.invalid", $pwPlain);
    $leadMenu = parseSwitcherMenu($leadClient->get('/staff/workspace')['body']);
    $leadVcNames = array_column($leadMenu['groups']['Veterinary Clinic']['items'] ?? [], 'name');
    check(
        'Lead Vet sees ONLY Veterinary Clinic -> Lead Vet (and Customer Interface)',
        $leadMenu['order'] === ['Veterinary Clinic', 'Customer Interface']
            && $leadVcNames === ['Lead Vet']
            && !empty($leadMenu['groups']['Veterinary Clinic']['items'][0]['active'])
    );
    check(
        'Lead Vet blocked (403) from Associate Vet, Vet Nurse, Vet Assistant, Delivery, Pet Store BM',
        $leadClient->get('/switch-interface?target=vetclinic-associate-vet')['status'] === 403
            && $leadClient->get('/switch-interface?target=vetclinic-vet-nurse')['status'] === 403
            && $leadClient->get('/switch-interface?target=vetclinic-vet-assistant')['status'] === 403
            && $leadClient->get('/switch-interface?target=delivery')['status'] === 403
            && $leadClient->get('/switch-interface?target=manager&unit_id=1')['status'] === 403
    );

    $assocClient = new SwitcherHttpClient($baseUrl);
    $assocClient->staffLogin("sw_assocvet_{$suffix}@test.invalid", $pwPlain);
    $assocMenu = parseSwitcherMenu($assocClient->get('/staff/workspace')['body']);
    check(
        'Associate Vet sees ONLY Associate Vet (not Lead Vet)',
        array_column($assocMenu['groups']['Veterinary Clinic']['items'] ?? [], 'name') === ['Associate Vet']
            && $assocClient->get('/switch-interface?target=vetclinic-lead-vet')['status'] === 403
    );

    $nurseClient = new SwitcherHttpClient($baseUrl);
    $nurseClient->staffLogin("sw_nurse_{$suffix}@test.invalid", $pwPlain);
    $nurseMenu = parseSwitcherMenu($nurseClient->get('/staff/workspace')['body']);
    check(
        'Vet Nurse sees ONLY Veterinary Clinic -> Vet Nurse and cannot switch to Lead Vet',
        array_column($nurseMenu['groups']['Veterinary Clinic']['items'] ?? [], 'name') === ['Vet Nurse']
            && $nurseClient->get('/switch-interface?target=vetclinic-lead-vet')['status'] === 403
    );

    $assistClient = new SwitcherHttpClient($baseUrl);
    $assistClient->staffLogin("sw_assist_{$suffix}@test.invalid", $pwPlain);
    $assistMenu = parseSwitcherMenu($assistClient->get('/staff/workspace')['body']);
    check(
        'Vet Assistant (VETERINARY_TECHNICIAN_WORKSPACE) sees ONLY Vet Assistant',
        array_column($assistMenu['groups']['Veterinary Clinic']['items'] ?? [], 'name') === ['Vet Assistant']
            && $assistClient->get('/switch-interface?target=vetclinic-vet-nurse')['status'] === 403
    );

    $noRoleClient = new SwitcherHttpClient($baseUrl);
    $noRoleClient->staffLogin("sw_norolevet_{$suffix}@test.invalid", $pwPlain);
    $noRoleMenu = parseSwitcherMenu($noRoleClient->get('/staff/workspace')['body']);
    check(
        'Position without active role assignment exposes NO Veterinary Clinic group and rejects switching (403)',
        $noRoleMenu['order'] === ['Customer Interface']
            && $noRoleClient->get('/switch-interface?target=vetclinic-lead-vet')['status'] === 403
    );

    $masterClient = new SwitcherHttpClient($baseUrl);
    $masterClient->staffLogin("sw_mastergroom_{$suffix}@test.invalid", $pwPlain);
    $masterMenu = parseSwitcherMenu($masterClient->get('/staff/workspace')['body']);
    check(
        'Pet Grooming Master Stylist (STYLIST_WORKSPACE + BATHER_WORKSPACE) sees ONLY Stylist & Bather under Pet Grooming',
        $masterMenu['order'] === ['Pet Grooming', 'Customer Interface']
            && array_column($masterMenu['groups']['Pet Grooming']['items'] ?? [], 'name') === ['Stylist', 'Bather']
            && $masterClient->get('/switch-interface?target=grooming-bather')['status'] === 302
            && $masterClient->get('/switch-interface?target=grooming-receptionist')['status'] === 403
    );

    $batherClient = new SwitcherHttpClient($baseUrl);
    $batherClient->staffLogin("sw_bather_{$suffix}@test.invalid", $pwPlain);
    $batherMenu = parseSwitcherMenu($batherClient->get('/staff/workspace')['body']);
    check(
        'Pet Grooming Bather sees ONLY Bather and is blocked (403) from Stylist',
        array_column($batherMenu['groups']['Pet Grooming']['items'] ?? [], 'name') === ['Bather']
            && $batherClient->get('/switch-interface?target=grooming-stylist')['status'] === 403
    );

    // -----------------------------------------------------------------
    // 3. Delivery Personnel Visibility Under Pet Store
    // -----------------------------------------------------------------
    $courierClient = new SwitcherHttpClient($baseUrl);
    $courierClient->staffLogin("sw_courier_{$suffix}@test.invalid", $pwPlain);
    $courierMenu = parseSwitcherMenu($courierClient->get('/delivery/orders')['body']);
    check(
        'Pet Store Delivery Personnel sees Pet Store -> Delivery Personnel marked active',
        $courierMenu['order'] === ['Pet Store', 'Customer Interface']
            && array_column($courierMenu['groups']['Pet Store']['items'] ?? [], 'name') === ['Delivery Personnel']
            && !empty($courierMenu['groups']['Pet Store']['active'])
            && !empty($courierMenu['groups']['Pet Store']['items'][0]['active'])
    );

    $crossCourierClient = new SwitcherHttpClient($baseUrl);
    $crossCourierClient->staffLogin("sw_crosscourier_{$suffix}@test.invalid", $pwPlain);
    $crossCourierMenu = parseSwitcherMenu($crossCourierClient->get('/staff/workspace')['body']);
    check(
        'Cross-unit employee with unit-1 DELIVERY_PERSONNEL does NOT see Pet Store -> Delivery Personnel and is blocked (403)',
        !isset($crossCourierMenu['groups']['Pet Store'])
            && isset($crossCourierMenu['groups']['Veterinary Clinic'])
            && $crossCourierClient->get('/switch-interface?target=delivery')['status'] === 403
    );

    // -----------------------------------------------------------------
    // 4. Multi-Unit Authorized Assignments
    // -----------------------------------------------------------------
    $multiClient = new SwitcherHttpClient($baseUrl);
    $multiClient->staffLogin("sw_multi_{$suffix}@test.invalid", $pwPlain);
    $multiBmPage = $multiClient->get('/manager?unit_id=1');
    $multiMenu = parseSwitcherMenu($multiBmPage['body']);
    check(
        'Multi-unit employee sees ONLY authorized units & roles across Pet Store, Veterinary Clinic, Pet Grooming',
        $multiMenu['order'] === ['Pet Store', 'Veterinary Clinic', 'Pet Grooming', 'Customer Interface']
            && array_column($multiMenu['groups']['Pet Store']['items'] ?? [], 'name') === ['Business Manager']
            && array_column($multiMenu['groups']['Veterinary Clinic']['items'] ?? [], 'name') === ['Inventory Manager']
            && array_column($multiMenu['groups']['Pet Grooming']['items'] ?? [], 'name') === ['Receptionist']
            && !empty($multiMenu['groups']['Pet Store']['active'])
            && empty($multiMenu['groups']['Veterinary Clinic']['active'])
            && empty($multiMenu['groups']['Pet Grooming']['active'])
    );

    $swIm2 = $multiClient->get('/switch-interface?target=inventory-manager&unit_id=2');
    $im2Page = $multiClient->get('/manager/inventory?unit_id=2');
    $im2Menu = parseSwitcherMenu($im2Page['body']);
    check(
        'Multi-unit employee switches to Vet Clinic Inventory Manager and active state follows',
        $swIm2['status'] === 302
            && $im2Page['status'] === 200
            && empty($im2Menu['groups']['Pet Store']['active'])
            && !empty($im2Menu['groups']['Veterinary Clinic']['active'])
            && !empty($im2Menu['groups']['Veterinary Clinic']['items'][0]['active'])
    );

    $swRec3 = $multiClient->get('/switch-interface?target=grooming-receptionist');
    $rec3Page = $multiClient->get('/grooming/receptionist/overview');
    $rec3Menu = parseSwitcherMenu($rec3Page['body']);
    check(
        'Multi-unit employee switches to Pet Grooming Receptionist and active state follows',
        $swRec3['status'] === 302
            && $rec3Page['status'] === 200
            && !empty($rec3Menu['groups']['Pet Grooming']['active'])
            && !empty($rec3Menu['groups']['Pet Grooming']['items'][0]['active'])
    );

    // Cross-unit / cross-role tampering for multi-unit user
    check(
        'Multi-unit employee blocked (403) from unauthorized cross-unit roles (BM unit 2, IM unit 1, Pet Store Receptionist, SysAdmin)',
        $multiClient->get('/switch-interface?target=manager&unit_id=2')['status'] === 403
            && $multiClient->get('/switch-interface?target=inventory-manager&unit_id=1')['status'] === 403
            && $multiClient->get('/switch-interface?target=petstore-receptionist')['status'] === 403
            && $multiClient->get('/switch-interface?target=sysadmin&unit_id=1')['status'] === 403
            && $multiClient->get('/switch-interface?target=employee&unit_id=1')['status'] === 302
    );

    // -----------------------------------------------------------------
    // 5. System Administrator Visibility & Access
    // -----------------------------------------------------------------
    $sysClient = new SwitcherHttpClient($baseUrl);
    $sysClient->staffLogin("sw_sysmulti_{$suffix}@test.invalid", $pwPlain);
    $sysMenu = parseSwitcherMenu($sysClient->get('/sysadmin?unit_id=1')['body']);
    check(
        'Multi-unit System Admin sees System Administrator with ONLY Pet Store & Pet Groom Service (excludes Vet Clinic)',
        $sysMenu['order'] === ['System Administrator', 'Customer Interface']
            && array_column($sysMenu['groups']['System Administrator']['items'] ?? [], 'name') === ['Pet Store', 'Pet Groom Service']
            && !empty($sysMenu['groups']['System Administrator']['active'])
            && !empty($sysMenu['groups']['System Administrator']['items'][0]['active'])
    );
    check(
        'System Admin switches cleanly to Pet Groom Service (302) and is blocked from Vet Clinic (403) & Delivery (403)',
        $sysClient->get('/switch-interface?target=sysadmin&unit_id=3')['status'] === 302
            && $sysClient->get('/sysadmin?unit_id=3')['status'] === 200
            && $sysClient->get('/switch-interface?target=sysadmin&unit_id=2')['status'] === 403
            && $sysClient->get('/switch-interface?target=employee&unit_id=2')['status'] === 403
            && $sysClient->get('/switch-interface?target=delivery')['status'] === 403
    );

    // -----------------------------------------------------------------
    // 6. Inactive Employee, Inactive Account, and Inactive Business Unit
    // -----------------------------------------------------------------
    // 6a. Inactive employee assignment hides groups and blocks switching
    $pdo->prepare("UPDATE employee SET employee_status = 'inactive' WHERE employee_id = ?")->execute([$multiEmpId]);
    $custWithInactiveEmp = parseSwitcherMenu($multiClient->get('/')['body']);
    check(
        'Inactive employee hides all unit/admin switcher groups and blocks switch-interface (403)',
        $custWithInactiveEmp['order'] === ['Customer Interface']
            && $multiClient->get('/switch-interface?target=manager&unit_id=1')['status'] === 403
            && $multiClient->get('/switch-interface?target=inventory-manager&unit_id=2')['status'] === 403
            && $multiClient->get('/switch-interface?target=grooming-receptionist')['status'] === 403
            && $multiClient->get('/switch-interface?target=employee&unit_id=1')['status'] === 403
    );
    $pdo->prepare("UPDATE employee SET employee_status = 'active' WHERE employee_id = ?")->execute([$multiEmpId]);

    // 6b. Inactive user account hides groups and blocks switching
    $pdo->prepare("UPDATE user_account SET account_status = 'inactive' WHERE user_account_id = ?")->execute([$courierUid]);
    check(
        'Inactive user account hides unit switcher groups and blocks switch-interface (403)',
        parseSwitcherMenu($courierClient->get('/')['body'])['order'] === ['Customer Interface']
            && $courierClient->get('/switch-interface?target=delivery')['status'] === 403
    );
    $pdo->prepare("UPDATE user_account SET account_status = 'active' WHERE user_account_id = ?")->execute([$courierUid]);

    // 6c. Inactive business unit hides Pet Grooming & Pet Groom Service and blocks switching
    $pdo->prepare("UPDATE business_unit SET business_unit_status = 'inactive' WHERE business_unit_id = 3")->execute();
    try {
        $multiAfterUnitOff = parseSwitcherMenu($multiClient->get('/')['body']);
        $sysAfterUnitOff = parseSwitcherMenu($sysClient->get('/sysadmin?unit_id=1')['body']);
        check(
            'Inactive business unit hides Pet Grooming and Pet Groom Service in Switch Interface and blocks switching (403)',
            !isset($multiAfterUnitOff['groups']['Pet Grooming'])
                && isset($multiAfterUnitOff['groups']['Pet Store'])
                && array_column($sysAfterUnitOff['groups']['System Administrator']['items'] ?? [], 'name') === ['Pet Store']
                && $multiClient->get('/switch-interface?target=grooming-receptionist')['status'] === 403
                && $sysClient->get('/switch-interface?target=sysadmin&unit_id=3')['status'] === 403
        );
    } finally {
        $pdo->prepare("UPDATE business_unit SET business_unit_status = 'active' WHERE business_unit_id = 3")->execute();
    }

    echo "\n====================================================================\n";
    echo "SWITCH INTERFACE GROUPING TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
    echo "====================================================================\n";
} finally {
    // Ensure unit 3 is always active and remove only created test fixtures
    try {
        $pdo->prepare("UPDATE business_unit SET business_unit_status = 'active' WHERE business_unit_id = 3")->execute();
        if ($createdUserIds) {
            $ph = implode(',', array_fill(0, count($createdUserIds), '?'));
            $pdo->prepare("DELETE FROM account_role WHERE user_account_id IN ({$ph})")->execute($createdUserIds);
            $pdo->prepare("DELETE FROM audit_log WHERE actor_user_id IN ({$ph})")->execute($createdUserIds);
            $pdo->prepare("DELETE FROM customer WHERE user_account_id IN ({$ph})")->execute($createdUserIds);
            $pdo->prepare("DELETE FROM employee WHERE user_account_id IN ({$ph})")->execute($createdUserIds);
            $pdo->prepare("DELETE FROM user_account WHERE user_account_id IN ({$ph})")->execute($createdUserIds);
        }
    } catch (Throwable $cleanupEx) {
        fwrite(STDERR, "Cleanup warning: {$cleanupEx->getMessage()}\n");
    }

    safelyTerminateAndClose($serverProc, $serverPipes, 2);
}

exit($failed > 0 ? 1 : 0);
