<?php
declare(strict_types=1);

/**
 * Automated Verification Suite — Temporary Inventory Access Keys
 * Covers:
 * - Pet Store BM -> Inventory & Grooming Services BM -> Inventory shared key mechanism
 * - One-valid-key-at-a-time state machine (Generate Inventory Key <-> View Key)
 * - View Key read-only behavior & duplicate generation prevention
 * - Strict business-unit association & cross-business-unit access prevention (both directions)
 * - Unauthorized direct access rejection & role boundaries
 * - Temporary access expiration & independent per-unit key lifecycle
 *
 * Usage: tools\php83\php.exe tests\inventory_key_tests.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$base = getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8080';
$pdo = Database::pdo();
$passed = 0;
$failed = 0;

final class InvKeyHttpClient
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
        throw new RuntimeException('CSRF token not found in HTML response.');
    }

    public function staffLogin(string $email, string $password): array
    {
        $lp = $this->get('/staff/login');
        return $this->post('/staff/login', [
            'csrf_token' => $this->csrf($lp['body']),
            'email' => $email,
            'password' => $password,
        ]);
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
                'timeout' => 15,
            ],
        ]);

        $raw = @file_get_contents($this->base . $path, false, $ctx);
        if ($raw === false) {
            throw new RuntimeException("HTTP request failed for {$method} {$path}");
        }

        $status = 0;
        $location = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (stripos($line, 'Set-Cookie:') === 0 && preg_match('/:\s*([^=]+)=([^;]+)/', $line, $m)) {
                $this->cookies[trim($m[1])] = trim($m[2]);
            } elseif (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => $raw];
    }
}

function assertCheck(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// Preserve any pre-existing keys, clean test keys in finally block
$maxExistingKeyId = (int)$pdo->query("SELECT COALESCE(MAX(key_id), 0) FROM inventory_access_key")->fetchColumn();
$pdo->exec("UPDATE inventory_access_key SET is_active = 0 WHERE is_active = 1");

// Provision a temporary Pet Store operational employee (Cashier, no BM/IM role) for cross-unit & temp-access tests
$tempEmail = 'ps.cashier.keytest.' . time() . '@staff.sample.vetgrpet';
$tempUid = 0;
$tempEmpId = 0;

try {
    $pwHash = password_hash('Staff123!', PASSWORD_DEFAULT);
    $insUa = $pdo->prepare("INSERT INTO user_account (email, password_hash, account_status, email_verified_at) VALUES (?, ?, 'active', NOW())");
    $insUa->execute([$tempEmail, $pwHash]);
    $tempUid = (int)$pdo->lastInsertId();

    $insEmp = $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status) VALUES (?, 1, 'Temp', 'Cashier', 'Cashier', 'active')");
    $insEmp->execute([$tempUid]);
    $tempEmpId = (int)$pdo->lastInsertId();

    $tempImUid = 0;
    $tempImEmpId = 0;
    $imChk = $pdo->prepare("SELECT u.user_account_id FROM user_account u JOIN employee e ON e.user_account_id = u.user_account_id WHERE u.email = ?");
    $imChk->execute(['ja.ompoy@staff.sample.vetgrpet']);
    if (!$imChk->fetchColumn()) {
        $insImUa = $pdo->prepare("INSERT INTO user_account (email, password_hash, account_status, email_verified_at) VALUES ('ja.ompoy@staff.sample.vetgrpet', ?, 'active', NOW())");
        $insImUa->execute([$pwHash]);
        $tempImUid = (int)$pdo->lastInsertId();

        $insImEmp = $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status) VALUES (?, 1, 'Janricson', 'Ompoy', 'Inventory Manager', 'active')");
        $insImEmp->execute([$tempImUid]);
        $tempImEmpId = (int)$pdo->lastInsertId();

        $insImRole = $pdo->prepare("INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, 11, 1)");
        $insImRole->execute([$tempImUid]);
    }

    // Sign in test actors
    $petBm = new InvKeyHttpClient($base);
    $petBm->staffLogin('david.reyes@staff.sample.vetgrpet', 'Staff123!');

    $groomBm = new InvKeyHttpClient($base);
    $groomBm->staffLogin('marcus.chen@staff.sample.vetgrpet', 'Staff123!');

    $invMgr = new InvKeyHttpClient($base);
    $invMgr->staffLogin('ja.ompoy@staff.sample.vetgrpet', 'Staff123!');

    $groomStaff = new InvKeyHttpClient($base);
    $groomStaff->staffLogin('owen.bennett@staff.sample.vetgrpet', 'Staff123!');

    $petStaff = new InvKeyHttpClient($base);
    $petStaff->staffLogin($tempEmail, 'Staff123!');

    $groomSysAdmin = new InvKeyHttpClient($base);
    $groomSysAdmin->staffLogin('sysadmin.grooming@vetgrpet.local', 'SysAdminGroom!2026');

    // =========================================================================
    // 1. INITIAL STATE: NO VALID KEY EXISTS ON EITHER BUSINESS UNIT
    // =========================================================================
    echo "\n=== 1. Initial Button State (No Valid Key Exists) ===\n";

    $psInv0 = $petBm->get('/manager/inventory');
    assertCheck('Pet Store BM Inventory loads (200)', $psInv0['status'] === 200);
    assertCheck(
        'Pet Store BM shows "Generate Inventory Key" and NOT "View Key" when no active key exists',
        str_contains($psInv0['body'], 'Generate Inventory Key') && !str_contains($psInv0['body'], '>View Key<')
    );
    $psSegPos = strpos($psInv0['body'], 'class="segmented-bar"');
    $psGenBtnPos = strpos($psInv0['body'], 'id="generate-inv-key-btn"');
    $psSearchPos = strpos($psInv0['body'], 'class="search-input-wrap"');
    assertCheck(
        'Pet Store "Generate Inventory Key" is placed on the left side of the search bar and uses btn primary btn-sm',
        $psSegPos !== false
        && $psGenBtnPos !== false
        && $psSearchPos !== false
        && $psSegPos < $psGenBtnPos
        && $psGenBtnPos < $psSearchPos
        && str_contains($psInv0['body'], 'class="btn primary btn-sm" id="generate-inv-key-btn"')
    );
    assertCheck(
        'Pet Store Stock Levels & Adjustments tab does NOT show "+ Purchase Order" button',
        !str_contains($psInv0['body'], '+ Purchase Order') && !str_contains($psInv0['body'], 'data-modal-open="po-create-modal"')
    );
    $psOrders0 = $petBm->get('/manager/inventory?tab=orders');
    assertCheck(
        'Pet Store Purchase Orders tab shows "+ Purchase Order" (renamed from "+ Create Purchase Order")',
        str_contains($psOrders0['body'], '+ Purchase Order') && !str_contains($psOrders0['body'], '+ Create Purchase Order')
    );

    $gsInv0 = $groomBm->get('/manager/inventory');
    assertCheck('Grooming Services BM Inventory loads (200)', $gsInv0['status'] === 200);
    assertCheck(
        'Grooming Services BM shows "Generate Inventory Key" and NOT "View Key" when no active key exists',
        str_contains($gsInv0['body'], 'Generate Inventory Key') && !str_contains($gsInv0['body'], '>View Key<')
    );
    $gsSegPos = strpos($gsInv0['body'], 'class="segmented-bar"');
    $gsGenBtnPos = strpos($gsInv0['body'], 'id="generate-inv-key-btn"');
    $gsSearchPos = strpos($gsInv0['body'], 'class="search-input-wrap"');
    assertCheck(
        'Grooming Services "Generate Inventory Key" is placed on the left side of the search bar and uses btn primary btn-sm',
        $gsSegPos !== false
        && $gsGenBtnPos !== false
        && $gsSearchPos !== false
        && $gsSegPos < $gsGenBtnPos
        && $gsGenBtnPos < $gsSearchPos
        && str_contains($gsInv0['body'], 'class="btn primary btn-sm" id="generate-inv-key-btn"')
    );
    assertCheck(
        'Grooming Services Stock Levels & Adjustments tab does NOT show "+ Purchase Order" button',
        !str_contains($gsInv0['body'], '+ Purchase Order') && !str_contains($gsInv0['body'], 'data-modal-open="po-create-modal"')
    );
    $gsOrders0 = $groomBm->get('/manager/inventory?tab=orders');
    assertCheck(
        'Grooming Services Purchase Orders tab shows "+ Purchase Order" (renamed from "+ Create Purchase Order")',
        str_contains($gsOrders0['body'], '+ Purchase Order') && !str_contains($gsOrders0['body'], '+ Create Purchase Order')
    );

    // =========================================================================
    // 2. PET STORE KEY GENERATION, VIEW KEY, DUPLICATE GUARD & UNIT INDEPENDENCE
    // =========================================================================
    echo "\n=== 2. Pet Store Key Generation & Independent Grooming State ===\n";

    $psCsrf = $petBm->csrf($psInv0['body']);
    $genPs1 = $petBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $psCsrf,
        'return_tab' => 'stock',
    ]);
    assertCheck('Pet Store Generate Inventory Key redirects (302)', $genPs1['status'] === 302);

    $psKeyRow1 = $pdo->query("SELECT * FROM inventory_access_key WHERE business_unit_id = 1 AND is_active = 1 AND expires_at > NOW() ORDER BY key_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertCheck('Pet Store key created in DB with business_unit_id = 1', !empty($psKeyRow1) && (int)$psKeyRow1['business_unit_id'] === 1);
    $psKeyCode1 = (string)($psKeyRow1['key_code'] ?? '');
    $psKeyId1 = (int)($psKeyRow1['key_id'] ?? 0);

    $psInv1 = $petBm->get('/manager/inventory');
    assertCheck(
        'Pet Store BM button changed to "View Key" (and no longer shows "Generate Inventory Key")',
        str_contains($psInv1['body'], 'View Key') && !str_contains($psInv1['body'], 'Generate Inventory Key')
    );
    assertCheck(
        'Pet Store View Key modal contains the existing key code, unit name, and expiration details',
        str_contains($psInv1['body'], $psKeyCode1)
        && str_contains($psInv1['body'], 'Pet Store')
        && str_contains($psInv1['body'], 'Expires At')
        && str_contains($psInv1['body'], 'Remaining Validity')
    );

    // Reloading / viewing View Key must NOT generate or replace the key
    $psInvReload = $petBm->get('/manager/inventory');
    $psCountAfterView = (int)$pdo->query("SELECT COUNT(*) FROM inventory_access_key WHERE business_unit_id = 1 AND is_active = 1 AND expires_at > NOW()")->fetchColumn();
    assertCheck(
        'Viewing / reloading Inventory keeps exact same key without replacing or duplicating',
        $psCountAfterView === 1 && str_contains($psInvReload['body'], $psKeyCode1)
    );

    // Duplicate POST to generate-key while valid key exists MUST NOT replace or create second active key
    $dupGenPs = $petBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $petBm->csrf($psInvReload['body']),
    ]);
    assertCheck('Duplicate generate-key POST redirects safely (302)', $dupGenPs['status'] === 302);
    $psActiveRows = $pdo->query("SELECT key_id, key_code FROM inventory_access_key WHERE business_unit_id = 1 AND is_active = 1 AND expires_at > NOW()")->fetchAll(PDO::FETCH_ASSOC);
    assertCheck(
        'One-valid-key-at-a-time enforced: duplicate POST did not create second key or replace active key',
        count($psActiveRows) === 1
        && (int)$psActiveRows[0]['key_id'] === $psKeyId1
        && $psActiveRows[0]['key_code'] === $psKeyCode1
    );

    // Verify Grooming Services BM is completely unaffected by Pet Store's active key
    $gsInvCheck = $groomBm->get('/manager/inventory');
    assertCheck(
        'Grooming Services BM still shows "Generate Inventory Key" while Pet Store has an active key',
        str_contains($gsInvCheck['body'], 'Generate Inventory Key') && !str_contains($gsInvCheck['body'], '>View Key<')
    );
    assertCheck(
        'Grooming Services BM never sees Pet Store key code',
        !str_contains($gsInvCheck['body'], $psKeyCode1)
    );

    // =========================================================================
    // 3. GROOMING SERVICES KEY GENERATION & MUTUAL KEY ISOLATION
    // =========================================================================
    echo "\n=== 3. Grooming Services Key Generation & Mutual Isolation ===\n";

    $genGs1 = $groomBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $groomBm->csrf($gsInvCheck['body']),
    ]);
    assertCheck('Grooming Services Generate Inventory Key redirects (302)', $genGs1['status'] === 302);

    $gsKeyRow1 = $pdo->query("SELECT * FROM inventory_access_key WHERE business_unit_id = 3 AND is_active = 1 AND expires_at > NOW() ORDER BY key_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertCheck('Grooming Services key created in DB with business_unit_id = 3', !empty($gsKeyRow1) && (int)$gsKeyRow1['business_unit_id'] === 3);
    $gsKeyCode1 = (string)($gsKeyRow1['key_code'] ?? '');
    $gsKeyId1 = (int)($gsKeyRow1['key_id'] ?? 0);
    assertCheck('Pet Store key and Grooming Services key are distinct', $psKeyCode1 !== '' && $gsKeyCode1 !== '' && $psKeyCode1 !== $gsKeyCode1);

    $gsInv1 = $groomBm->get('/manager/inventory');
    assertCheck(
        'Grooming Services BM button changed to "View Key" (and no longer shows "Generate Inventory Key")',
        str_contains($gsInv1['body'], 'View Key') && !str_contains($gsInv1['body'], 'Generate Inventory Key')
    );
    assertCheck(
        'Grooming View Key modal displays Grooming key and unit name, never Pet Store key',
        str_contains($gsInv1['body'], $gsKeyCode1)
        && str_contains($gsInv1['body'], 'Grooming Services')
        && !str_contains($gsInv1['body'], $psKeyCode1)
    );

    $psInvCheckAgain = $petBm->get('/manager/inventory');
    assertCheck(
        'Pet Store View Key modal displays Pet Store key and never Grooming key',
        str_contains($psInvCheckAgain['body'], $psKeyCode1)
        && !str_contains($psInvCheckAgain['body'], $gsKeyCode1)
    );

    // Duplicate POST guard on Grooming Services too
    $groomBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $groomBm->csrf($gsInv1['body']),
    ]);
    $gsActiveRows = $pdo->query("SELECT key_id, key_code FROM inventory_access_key WHERE business_unit_id = 3 AND is_active = 1 AND expires_at > NOW()")->fetchAll(PDO::FETCH_ASSOC);
    assertCheck(
        'One-valid-key-at-a-time enforced for Grooming Services as well',
        count($gsActiveRows) === 1
        && (int)$gsActiveRows[0]['key_id'] === $gsKeyId1
        && $gsActiveRows[0]['key_code'] === $gsKeyCode1
    );

    // =========================================================================
    // 4. UNAUTHORIZED DIRECT ACCESS & ROLE BOUNDARIES
    // =========================================================================
    echo "\n=== 4. Unauthorized Direct Access & Role Boundaries ===\n";

    // Unauthorized employees denied direct /manager/inventory access
    $directGroomStaff = $groomStaff->get('/manager/inventory');
    assertCheck('Unauthorized Grooming employee denied direct /manager/inventory access (403)', $directGroomStaff['status'] === 403);

    $directPetStaff = $petStaff->get('/manager/inventory');
    assertCheck('Unauthorized Pet Store employee denied direct /manager/inventory access (403)', $directPetStaff['status'] === 403);

    $directSysAdmin = $groomSysAdmin->get('/manager/inventory');
    assertCheck('System Administrator denied direct /manager/inventory access (403)', $directSysAdmin['status'] === 403);

    // Inventory Manager cannot see or generate BM Inventory Keys
    $imInv = $invMgr->get('/manager/inventory');
    assertCheck('Inventory Manager still accesses /manager/inventory (200)', $imInv['status'] === 200);
    assertCheck(
        'Inventory Manager does NOT see Generate Inventory Key, View Key, or BM key code',
        !str_contains($imInv['body'], 'Generate Inventory Key')
        && !str_contains($imInv['body'], '>View Key<')
        && !str_contains($imInv['body'], $psKeyCode1)
    );
    $imGenAttempt = $invMgr->post('/manager/inventory/generate-key', [
        'csrf_token' => $invMgr->csrf($imInv['body']),
    ]);
    assertCheck('Inventory Manager is denied POST /manager/inventory/generate-key (403)', $imGenAttempt['status'] === 403);

    // Cross-unit BM generation blocked
    $crossBm1 = $petBm->post('/manager/units/3/inventory/generate-key', [
        'csrf_token' => $petBm->csrf($psInvCheckAgain['body']),
    ]);
    assertCheck('Pet Store BM cannot generate key for Grooming unit (403)', $crossBm1['status'] === 403);

    $crossBm2 = $groomBm->post('/manager/units/1/inventory/generate-key', [
        'csrf_token' => $groomBm->csrf($gsInv1['body']),
    ]);
    assertCheck('Grooming BM cannot generate key for Pet Store unit (403)', $crossBm2['status'] === 403);

    // =========================================================================
    // 5. CROSS-BUSINESS-UNIT KEY REDEMPTION PREVENTION (BOTH DIRECTIONS)
    // =========================================================================
    echo "\n=== 5. Strict Cross-Business-Unit Access Prevention ===\n";

    // Grooming employee tries Pet Store key -> MUST BE REJECTED (403)
    $gsWs = $groomStaff->get('/staff/workspace');
    $crossRedeem1 = $groomStaff->post('/staff/inventory-access', [
        'csrf_token' => $groomStaff->csrf($gsWs['body']),
        'inventory_key' => $psKeyCode1,
    ]);
    assertCheck('Pet Store key rejected when entered by Grooming Services employee (403)', $crossRedeem1['status'] === 403);
    assertCheck('Grooming employee still denied /manager/inventory after cross-unit attempt (403)', $groomStaff->get('/manager/inventory')['status'] === 403);
    assertCheck('Grooming employee denied /manager/units/1/inventory (403)', $groomStaff->get('/manager/units/1/inventory')['status'] === 403);

    // Pet Store employee tries Grooming Services key -> MUST BE REJECTED (403)
    $psWs = $petStaff->get('/staff/workspace');
    $crossRedeem2 = $petStaff->post('/staff/inventory-access', [
        'csrf_token' => $petStaff->csrf($psWs['body']),
        'inventory_key' => $gsKeyCode1,
    ]);
    assertCheck('Grooming Services key rejected when entered by Pet Store employee (403)', $crossRedeem2['status'] === 403);
    assertCheck('Pet Store employee still denied /manager/inventory after cross-unit attempt (403)', $petStaff->get('/manager/inventory')['status'] === 403);
    assertCheck('Pet Store employee denied /manager/units/3/inventory (403)', $petStaff->get('/manager/units/3/inventory')['status'] === 403);

    // System Administrator tries redeeming Grooming key -> MUST BE REJECTED (403)
    $saPage = $groomSysAdmin->get('/sysadmin');
    $saRedeem = $groomSysAdmin->post('/staff/inventory-access', [
        'csrf_token' => $groomSysAdmin->csrf($saPage['body']),
        'inventory_key' => $gsKeyCode1,
    ]);
    assertCheck('System Administrator cannot redeem operational Inventory Access Key (403)', $saRedeem['status'] === 403);

    // =========================================================================
    // 6. VALID SAME-UNIT TEMPORARY ACCESS & ISOLATION WHILE ACTIVE
    // =========================================================================
    echo "\n=== 6. Valid Same-Unit Temporary Access & Scoped Boundaries ===\n";

    // Grooming employee redeems Grooming key
    $gsValidRedeem = $groomStaff->post('/staff/inventory-access', [
        'csrf_token' => $groomStaff->csrf($gsWs['body']),
        'inventory_key' => $gsKeyCode1,
    ]);
    assertCheck('Valid Grooming key unlocks temporary inventory access (302 -> /manager/inventory)', $gsValidRedeem['status'] === 302 && $gsValidRedeem['location'] === '/manager/inventory');

    $gsTempInv = $groomStaff->get('/manager/inventory');
    assertCheck('Grooming employee with valid Grooming key views Grooming Inventory (200)', $gsTempInv['status'] === 200 && str_contains($gsTempInv['body'], 'Grooming Services Stock Levels'));
    assertCheck('Grooming temp-access employee cannot access Pet Store inventory via /manager/units/1/inventory (403)', $groomStaff->get('/manager/units/1/inventory')['status'] === 403);
    assertCheck('Grooming temp-access employee cannot access Pet Store inventory via ?unit_id=1 (403)', $groomStaff->get('/manager/inventory?unit_id=1')['status'] === 403);
    assertCheck('Grooming temp-access employee cannot view/generate keys or access BM settings (403)',
        !str_contains($gsTempInv['body'], 'View Key')
        && !str_contains($gsTempInv['body'], 'Generate Inventory Key')
        && $groomStaff->get('/manager/settings')['status'] === 403
    );

    // Pet Store employee redeems Pet Store key
    $psValidRedeem = $petStaff->post('/staff/inventory-access', [
        'csrf_token' => $petStaff->csrf($psWs['body']),
        'inventory_key' => $psKeyCode1,
    ]);
    assertCheck('Valid Pet Store key unlocks temporary inventory access (302 -> /manager/inventory)', $psValidRedeem['status'] === 302 && $psValidRedeem['location'] === '/manager/inventory');

    $psTempInv = $petStaff->get('/manager/inventory');
    assertCheck('Pet Store employee with valid Pet Store key views Pet Store Inventory (200)', $psTempInv['status'] === 200 && str_contains($psTempInv['body'], 'Pet Store Stock Levels'));
    assertCheck('Pet Store temp-access employee cannot access Grooming inventory via /manager/units/3/inventory (403)', $petStaff->get('/manager/units/3/inventory')['status'] === 403);
    assertCheck('Pet Store temp-access employee cannot access Grooming inventory via ?unit_id=3 (403)', $petStaff->get('/manager/inventory?unit_id=3')['status'] === 403);

    // =========================================================================
    // 7. EXPIRATION & RE-GENERATION LIFECYCLE (INDEPENDENT PER UNIT)
    // =========================================================================
    echo "\n=== 7. Key Expiration & Independent Re-Generation ===\n";

    // Expire ONLY Pet Store's key in DB
    $pdo->prepare("UPDATE inventory_access_key SET expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE key_id = ?")->execute([$psKeyId1]);

    // Pet Store employee temporary access is immediately revoked
    assertCheck('Expired Pet Store key immediately revokes Pet Store employee access (403)', $petStaff->get('/manager/inventory')['status'] === 403);

    // Pet Store BM button reverts back to "Generate Inventory Key"
    $psAfterExp = $petBm->get('/manager/inventory');
    assertCheck(
        'When Pet Store key expires, Pet Store BM button reverts to "Generate Inventory Key"',
        str_contains($psAfterExp['body'], 'Generate Inventory Key') && !str_contains($psAfterExp['body'], '>View Key<')
    );

    // Grooming Services key is STILL valid -> Grooming BM still shows "View Key" and Grooming employee still has access!
    $gsStillValid = $groomBm->get('/manager/inventory');
    assertCheck(
        'Grooming Services BM still shows "View Key" when only Pet Store key expired',
        str_contains($gsStillValid['body'], 'View Key') && !str_contains($gsStillValid['body'], 'Generate Inventory Key')
    );
    assertCheck('Grooming employee temporary access remains active while Grooming key is unexpired (200)', $groomStaff->get('/manager/inventory')['status'] === 200);

    // Pet Store BM can now generate a new key
    $genPs2 = $petBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $petBm->csrf($psAfterExp['body']),
    ]);
    assertCheck('Pet Store BM can generate a new key after previous key expired (302)', $genPs2['status'] === 302);
    $psKeyRow2 = $pdo->query("SELECT * FROM inventory_access_key WHERE business_unit_id = 1 AND is_active = 1 AND expires_at > NOW() ORDER BY key_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertCheck(
        'New Pet Store key generated with distinct code and switches button back to "View Key"',
        !empty($psKeyRow2)
        && (int)$psKeyRow2['key_id'] > $psKeyId1
        && $psKeyRow2['key_code'] !== $psKeyCode1
        && str_contains($petBm->get('/manager/inventory')['body'], 'View Key')
    );

    // Now expire Grooming Services' key as well
    $pdo->prepare("UPDATE inventory_access_key SET expires_at = DATE_SUB(NOW(), INTERVAL 10 SECOND) WHERE key_id = ?")->execute([$gsKeyId1]);
    assertCheck('Expired Grooming key immediately revokes Grooming employee access (403)', $groomStaff->get('/manager/inventory')['status'] === 403);

    $gsAfterExp = $groomBm->get('/manager/inventory');
    assertCheck(
        'When Grooming key expires, Grooming BM button reverts to "Generate Inventory Key"',
        str_contains($gsAfterExp['body'], 'Generate Inventory Key') && !str_contains($gsAfterExp['body'], '>View Key<')
    );

    $genGs2 = $groomBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $groomBm->csrf($gsAfterExp['body']),
    ]);
    assertCheck('Grooming BM can generate a new key after previous key expired (302)', $genGs2['status'] === 302);
    assertCheck('Grooming BM shows "View Key" again for new key', str_contains($groomBm->get('/manager/inventory')['body'], 'View Key'));
} finally {
    // Clean up test keys and temporary test employee
    $pdo->prepare("DELETE FROM inventory_access_key WHERE key_id > ?")->execute([$maxExistingKeyId]);
    $pdo->prepare("DELETE FROM audit_log WHERE action_code LIKE 'inventory.%key%' OR action_code LIKE 'inventory.temp_access%'")->execute();
    if ($tempEmpId > 0) {
        $pdo->prepare("DELETE FROM employee WHERE employee_id = ?")->execute([$tempEmpId]);
    }
    if ($tempUid > 0) {
        $pdo->prepare("DELETE FROM customer WHERE user_account_id = ?")->execute([$tempUid]);
        $pdo->prepare("DELETE FROM user_account WHERE user_account_id = ?")->execute([$tempUid]);
    }
}

echo "\n====================================================================\n";
echo "INVENTORY ACCESS KEY TEST RESULTS: {$passed} passed, {$failed} failed\n";
echo "====================================================================\n";
exit($failed === 0 ? 0 : 1);
