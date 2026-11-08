<?php
declare(strict_types=1);

/**
 * Automated Verification Suite — Inventory Access Key Revocation & Multi-Unit Support
 * Covers Pet Store (Unit 1), Veterinary Clinic (Unit 2), and Grooming Services (Unit 3).
 * Usage: tools\php83\php.exe tests\inventory_key_revocation_tests.php
 */

require_once __DIR__ . '/../app/bootstrap.php';
restore_exception_handler();

use App\Core\Database;
use App\Models\InventoryAccessKey;
use App\Models\Units;

$base = getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8080';
$pdo = Database::pdo();
$passed = 0;
$failed = 0;

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

final class KeyRevokeHttpClient
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

echo "====================================================================\n";
echo "INVENTORY ACCESS KEY REVOCATION & MULTI-UNIT VERIFICATION SUITE\n";
echo "====================================================================\n\n";

// Deactivate any pre-existing active keys for clean test slate
$pdo->exec("UPDATE inventory_access_key SET is_active = 0 WHERE is_active = 1");

$petBm = new KeyRevokeHttpClient($base);
$petBm->staffLogin('david.reyes@staff.sample.vetgrpet', 'Staff123!');

$groomBm = new KeyRevokeHttpClient($base);
$groomBm->staffLogin('marcus.chen@staff.sample.vetgrpet', 'Staff123!');

$vetBm = new KeyRevokeHttpClient($base);
$vetBm->staffLogin('claire.dubois@staff.sample.vetgrpet', 'Staff123!');

$vetStaff = new KeyRevokeHttpClient($base);
$vetStaff->staffLogin('hannah.kim@staff.sample.vetgrpet', 'Staff123!');

$sysAdmin = new KeyRevokeHttpClient($base);
$sysAdmin->staffLogin('sysadmin.petstore@vetgrpet.local', 'SysAdminPet!2026');

$groomStaff = new KeyRevokeHttpClient($base);
$groomStaff->staffLogin('owen.bennett@staff.sample.vetgrpet', 'Staff123!');

$createdTestKeyIds = [];

try {
    // =========================================================================
    // 1. PET STORE BM: KEY GENERATION, MODAL LAYOUT & REVOCATION
    // =========================================================================
    echo "--- Section 1: Pet Store BM Key Generation, Button Placement & Revocation ---\n";

    $psInv0 = $petBm->get('/manager/inventory');
    assertCheck('Pet Store BM accesses /manager/inventory (200)', $psInv0['status'] === 200);

    // Generate Pet Store Key
    $genPs = $petBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $petBm->csrf($psInv0['body']),
        'return_tab' => 'stock',
    ]);
    assertCheck('Pet Store BM generates key (302 redirect)', $genPs['status'] === 302);

    $psActiveKey = InventoryAccessKey::getActiveForUnit($pdo, Units::petStoreId());
    assertCheck('Active Pet Store key created in DB', $psActiveKey !== null && (int)$psActiveKey['business_unit_id'] === Units::petStoreId());
    $psKeyId = (int)$psActiveKey['key_id'];
    $psKeyCode = (string)$psActiveKey['key_code'];
    $createdTestKeyIds[] = $psKeyId;

    // View Key & Revoke Action presence
    $psInv1 = $petBm->get('/manager/inventory');
    assertCheck('Pet Store displays Revoke button in header actions', str_contains($psInv1['body'], 'id="header-revoke-inv-key-btn"'));
    assertCheck('Pet Store contains confirmation dialog #revoke-key-modal', str_contains($psInv1['body'], 'id="revoke-key-modal"'));
    assertCheck('Pet Store confirmation modal clearly identifies selected key code', str_contains($psInv1['body'], $psKeyCode));

    // CHECK BUTTON PLACEMENT: Revoke Key must be on the left side of Copy Key button
    $psRevokeModalPos = strpos($psInv1['body'], 'id="modal-revoke-inv-key-btn"');
    $psCopyModalPos = strpos($psInv1['body'], 'id="copy-inv-key-btn"');
    assertCheck('Pet Store View Key modal has Revoke Key button on the LEFT side of Copy Key button',
        $psRevokeModalPos !== false && $psCopyModalPos !== false && $psRevokeModalPos < $psCopyModalPos
    );

    // CHECK CLOSE BUTTON & KEY HISTORY: Close button is just &times; ("X") and Key History is removed
    assertCheck('Pet Store Key modal close button does not contain "Close"', !preg_match('/id="inventory-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>[^<]*Close/i', $psInv1['body']));
    assertCheck('Pet Store Key modal close button displays &times;', preg_match('/id="inventory-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>\s*&times;\s*<\/button>/i', $psInv1['body']) === 1);
    assertCheck('Pet Store Revoke modal close button does not contain "Close"', !preg_match('/id="revoke-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>[^<]*Close/i', $psInv1['body']));
    assertCheck('Pet Store Revoke modal close button displays &times;', preg_match('/id="revoke-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>\s*&times;\s*<\/button>/i', $psInv1['body']) === 1);
    assertCheck('Pet Store page does not contain Key History button', !str_contains($psInv1['body'], 'Key History'));

    // Execute Revocation
    $revokePs = $petBm->post('/manager/inventory/revoke-key', [
        'csrf_token' => $petBm->csrf($psInv1['body']),
        'key_id' => $psKeyId,
        'return_tab' => 'stock',
    ]);
    assertCheck('Pet Store BM revokes key successfully (302 redirect)', $revokePs['status'] === 302);

    // Verify DB State after Revocation (Item 4: Record remains, not deleted)
    $psKeyDbAfter = $pdo->query("SELECT * FROM inventory_access_key WHERE key_id = {$psKeyId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck('Historical key record remains in database (not deleted)', !empty($psKeyDbAfter));
    assertCheck('Key is_active is now 0', (int)$psKeyDbAfter['is_active'] === 0);
    assertCheck('Key revoked_at timestamp is populated', !empty($psKeyDbAfter['revoked_at']));
    assertCheck('Key revoked_by_employee_id recorded as David Reyes (4)', (int)$psKeyDbAfter['revoked_by_employee_id'] === 4);

    // Verify Active key lookup returns null
    $activeAfterRevoke = InventoryAccessKey::getActiveForUnit($pdo, Units::petStoreId());
    assertCheck('getActiveForUnit returns null after revocation', $activeAfterRevoke === null);

    // Verify Interface transitions back to Generate Inventory Key
    $psInvAfterRevoke = $petBm->get('/manager/inventory');
    assertCheck('Inventory page returns to "Generate Inventory Key" state', str_contains($psInvAfterRevoke['body'], 'Generate Inventory Key'));
    assertCheck('Success flash message is displayed', str_contains($psInvAfterRevoke['body'], 'has been revoked') || str_contains($psInvAfterRevoke['body'], 'revoked successfully'));

    // Verify Key History button is not present after revocation
    assertCheck('Key History button is completely removed from Pet Store after revocation', !str_contains($psInvAfterRevoke['body'], 'Key History'));

    // Repeated Revocation Handling (Item 7: Safe, idempotent)
    $repeatRevoke = $petBm->post('/manager/inventory/revoke-key', [
        'csrf_token' => $petBm->csrf($psInvAfterRevoke['body']),
        'key_id' => $psKeyId,
        'return_tab' => 'stock',
    ]);
    assertCheck('Repeated revocation handled safely (302 redirect)', $repeatRevoke['status'] === 302);

    $psKeyDbRepeat = $pdo->query("SELECT * FROM inventory_access_key WHERE key_id = {$psKeyId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck('Repeated revocation does NOT overwrite original revoked_at timestamp', $psKeyDbRepeat['revoked_at'] === $psKeyDbAfter['revoked_at']);

    // =========================================================================
    // 2. GROOMING SERVICES BM: KEY GENERATION, BUTTON PLACEMENT & REVOCATION
    // =========================================================================
    echo "\n--- Section 2: Grooming Services BM Key Generation, Button Placement & Revocation ---\n";

    $gsInv0 = $groomBm->get('/manager/inventory');
    $genGs = $groomBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $groomBm->csrf($gsInv0['body']),
        'return_tab' => 'stock',
    ]);
    assertCheck('Grooming Services BM generates key (302 redirect)', $genGs['status'] === 302);

    $gsActiveKey = InventoryAccessKey::getActiveForUnit($pdo, Units::groomingId());
    assertCheck('Active Grooming Services key created in DB', $gsActiveKey !== null && (int)$gsActiveKey['business_unit_id'] === Units::groomingId());
    $gsKeyId = (int)$gsActiveKey['key_id'];
    $gsKeyCode = (string)$gsActiveKey['key_code'];
    $createdTestKeyIds[] = $gsKeyId;

    $gsInv1 = $groomBm->get('/manager/inventory');
    assertCheck('Grooming Services shows Revoke button in header', str_contains($gsInv1['body'], 'id="header-revoke-inv-key-btn"'));

    // CHECK BUTTON PLACEMENT for Grooming Services
    $gsRevokeModalPos = strpos($gsInv1['body'], 'id="modal-revoke-inv-key-btn"');
    $gsCopyModalPos = strpos($gsInv1['body'], 'id="copy-inv-key-btn"');
    assertCheck('Grooming Services View Key modal has Revoke Key button on the LEFT side of Copy Key button',
        $gsRevokeModalPos !== false && $gsCopyModalPos !== false && $gsRevokeModalPos < $gsCopyModalPos
    );

    // CHECK CLOSE BUTTON & KEY HISTORY for Grooming Services
    assertCheck('Grooming Services Key modal close button does not contain "Close"', !preg_match('/id="inventory-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>[^<]*Close/i', $gsInv1['body']));
    assertCheck('Grooming Services Key modal close button displays &times;', preg_match('/id="inventory-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>\s*&times;\s*<\/button>/i', $gsInv1['body']) === 1);
    assertCheck('Grooming Services Revoke modal close button does not contain "Close"', !preg_match('/id="revoke-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>[^<]*Close/i', $gsInv1['body']));
    assertCheck('Grooming Services Revoke modal close button displays &times;', preg_match('/id="revoke-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>\s*&times;\s*<\/button>/i', $gsInv1['body']) === 1);
    assertCheck('Grooming Services page does not contain Key History button', !str_contains($gsInv1['body'], 'Key History'));

    $revokeGs = $groomBm->post('/manager/inventory/revoke-key', [
        'csrf_token' => $groomBm->csrf($gsInv1['body']),
        'key_id' => $gsKeyId,
        'return_tab' => 'stock',
    ]);
    assertCheck('Grooming Services BM revokes key successfully (302 redirect)', $revokeGs['status'] === 302);

    $gsKeyDbAfter = $pdo->query("SELECT * FROM inventory_access_key WHERE key_id = {$gsKeyId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck('Grooming key is_active = 0 and revoked_at is populated', (int)$gsKeyDbAfter['is_active'] === 0 && !empty($gsKeyDbAfter['revoked_at']));
    assertCheck('Grooming revoked_by_employee_id recorded as Marcus Chen (2)', (int)$gsKeyDbAfter['revoked_by_employee_id'] === 2);

    // =========================================================================
    // 3. VETERINARY CLINIC BM: KEY GENERATION, BUTTON PLACEMENT & REVOCATION
    // =========================================================================
    echo "\n--- Section 3: Veterinary Clinic BM Key Generation, Button Placement & Revocation ---\n";

    assertCheck('InventoryAccessKey::isUnitSupported(2) is true for Veterinary Clinic', InventoryAccessKey::isUnitSupported(Units::vetClinicId()) === true);

    $vetInv0 = $vetBm->get('/manager/inventory');
    assertCheck('Vet Clinic BM accesses /manager/inventory (200)', $vetInv0['status'] === 200);
    assertCheck('Vet Clinic BM shows "Generate Inventory Key" button when no active key exists', str_contains($vetInv0['body'], 'Generate Inventory Key'));

    // Generate Vet Clinic Key
    $genVet = $vetBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $vetBm->csrf($vetInv0['body']),
        'return_tab' => 'stock',
    ]);
    assertCheck('Vet Clinic BM generates key successfully (302 redirect)', $genVet['status'] === 302);

    $vetActiveKey = InventoryAccessKey::getActiveForUnit($pdo, Units::vetClinicId());
    assertCheck('Active Vet Clinic key created in DB with business_unit_id = 2', $vetActiveKey !== null && (int)$vetActiveKey['business_unit_id'] === Units::vetClinicId());
    $vetKeyId = (int)$vetActiveKey['key_id'];
    $vetKeyCode = (string)$vetActiveKey['key_code'];
    $createdTestKeyIds[] = $vetKeyId;

    $vetInv1 = $vetBm->get('/manager/inventory');
    assertCheck('Vet Clinic BM button changed to "View Key"', str_contains($vetInv1['body'], 'View Key'));
    assertCheck('Vet Clinic BM shows Revoke button in header', str_contains($vetInv1['body'], 'id="header-revoke-inv-key-btn"'));
    assertCheck('Vet Clinic View Key modal contains key code', str_contains($vetInv1['body'], $vetKeyCode));

    // CHECK BUTTON PLACEMENT for Veterinary Clinic: Revoke Key on the LEFT side of Copy Key button
    $vetRevokeModalPos = strpos($vetInv1['body'], 'id="modal-revoke-inv-key-btn"');
    $vetCopyModalPos = strpos($vetInv1['body'], 'id="copy-inv-key-btn"');
    assertCheck('Veterinary Clinic View Key modal has Revoke Key button on the LEFT side of Copy Key button',
        $vetRevokeModalPos !== false && $vetCopyModalPos !== false && $vetRevokeModalPos < $vetCopyModalPos
    );

    // CHECK CLOSE BUTTON & KEY HISTORY for Veterinary Clinic
    assertCheck('Vet Clinic Key modal close button does not contain "Close"', !preg_match('/id="inventory-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>[^<]*Close/i', $vetInv1['body']));
    assertCheck('Vet Clinic Key modal close button displays &times;', preg_match('/id="inventory-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>\s*&times;\s*<\/button>/i', $vetInv1['body']) === 1);
    assertCheck('Vet Clinic Revoke modal close button does not contain "Close"', !preg_match('/id="revoke-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>[^<]*Close/i', $vetInv1['body']));
    assertCheck('Vet Clinic Revoke modal close button displays &times;', preg_match('/id="revoke-key-modal"[\s\S]*?<button[^>]*data-modal-close[^>]*>\s*&times;\s*<\/button>/i', $vetInv1['body']) === 1);
    assertCheck('Vet Clinic page does not contain Key History button', !str_contains($vetInv1['body'], 'Key History'));

    // Vet Staff redemption of active key
    $vetWs = $vetStaff->get('/staff/workspace');
    assertCheck('Vet Nurse workspace displays Temporary Inventory Access unlock card', str_contains($vetWs['body'], 'Temporary Inventory Access'));
    $vetRedeemRes = $vetStaff->post('/staff/inventory-access', [
        'csrf_token' => $vetStaff->csrf($vetWs['body']),
        'inventory_key' => $vetKeyCode,
    ]);
    assertCheck('Vet Nurse successfully redeems active Vet Clinic key (302 redirect)', $vetRedeemRes['status'] === 302);
    $vetStaffInv = $vetStaff->get('/manager/inventory');
    assertCheck('Vet Nurse can access Vet Clinic inventory under active temporary grant (200)', $vetStaffInv['status'] === 200);

    // Vet Clinic BM revokes the key
    $revokeVet = $vetBm->post('/manager/inventory/revoke-key', [
        'csrf_token' => $vetBm->csrf($vetInv1['body']),
        'key_id' => $vetKeyId,
        'return_tab' => 'stock',
    ]);
    assertCheck('Vet Clinic BM revokes key successfully (302 redirect)', $revokeVet['status'] === 302);

    $vetKeyDbAfter = $pdo->query("SELECT * FROM inventory_access_key WHERE key_id = {$vetKeyId}")->fetch(PDO::FETCH_ASSOC);
    assertCheck('Vet Clinic key is_active = 0 in DB', (int)$vetKeyDbAfter['is_active'] === 0);
    assertCheck('Vet Clinic key revoked_at is populated', !empty($vetKeyDbAfter['revoked_at']));
    assertCheck('Vet Clinic key revoked_by_employee_id recorded as Claire Dubois (3)', (int)$vetKeyDbAfter['revoked_by_employee_id'] === 3);

    // Vet Nurse session is immediately invalidated
    $vetStaffInvAfterRevoke = $vetStaff->get('/manager/inventory');
    assertCheck('Vet Nurse temporary access immediately terminates upon key revocation (403 Forbidden)', $vetStaffInvAfterRevoke['status'] === 403);

    // Vet Nurse cannot re-redeem revoked key
    $vetReRedeem = $vetStaff->post('/staff/inventory-access', [
        'csrf_token' => $vetStaff->csrf($vetStaff->get('/staff/workspace')['body']),
        'inventory_key' => $vetKeyCode,
    ]);
    assertCheck('Attempt to redeem revoked Vet Clinic key is rejected (302 with flash error)', $vetReRedeem['status'] === 302);
    $vetWsAfterFail = $vetStaff->get('/staff/workspace');
    assertCheck('Workspace displays revoked key error message', str_contains($vetWsAfterFail['body'], 'revoked'));

    // =========================================================================
    // 4. THREE-WAY CROSS-BUSINESS-UNIT ISOLATION
    // =========================================================================
    echo "\n--- Section 4: Three-Way Cross-Business-Unit Isolation ---\n";

    // Generate active keys for Vet Clinic and Grooming Services
    $genVet2 = $vetBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $vetBm->csrf($vetBm->get('/manager/inventory')['body']),
    ]);
    $vetKey2 = InventoryAccessKey::getActiveForUnit($pdo, Units::vetClinicId());
    $vetKeyId2 = (int)$vetKey2['key_id'];
    $vetKeyCode2 = (string)$vetKey2['key_code'];
    $createdTestKeyIds[] = $vetKeyId2;

    $genGs2 = $groomBm->post('/manager/inventory/generate-key', [
        'csrf_token' => $groomBm->csrf($groomBm->get('/manager/inventory')['body']),
    ]);
    $gsKey2 = InventoryAccessKey::getActiveForUnit($pdo, Units::groomingId());
    $gsKeyId2 = (int)$gsKey2['key_id'];
    $gsKeyCode2 = (string)$gsKey2['key_code'];
    $createdTestKeyIds[] = $gsKeyId2;

    // Cross-unit redemption test: Vet staff tries Grooming key
    $crossStaff1 = $vetStaff->post('/staff/inventory-access', [
        'csrf_token' => $vetStaff->csrf($vetStaff->get('/staff/workspace')['body']),
        'inventory_key' => $gsKeyCode2,
    ]);
    assertCheck('Vet Nurse entering Grooming Services key is rejected (403 Forbidden)', $crossStaff1['status'] === 403);

    // Cross-unit redemption test: Grooming staff tries Vet Clinic key
    $crossStaff2 = $groomStaff->post('/staff/inventory-access', [
        'csrf_token' => $groomStaff->csrf($groomStaff->get('/staff/workspace')['body']),
        'inventory_key' => $vetKeyCode2,
    ]);
    assertCheck('Grooming Stylist entering Vet Clinic key is rejected (403 Forbidden)', $crossStaff2['status'] === 403);

    // Cross-unit revocation test: Pet Store BM tries to revoke Vet Clinic key
    $crossRevokeVet = $petBm->post('/manager/inventory/revoke-key', [
        'csrf_token' => $petBm->csrf($petBm->get('/manager/inventory')['body']),
        'key_id' => $vetKeyId2,
    ]);
    assertCheck('Pet Store BM cannot revoke Vet Clinic key (403 Forbidden)', $crossRevokeVet['status'] === 403);

    // Cross-unit revocation test: Vet Clinic BM tries to revoke Grooming key
    $crossRevokeGroom = $vetBm->post('/manager/inventory/revoke-key', [
        'csrf_token' => $vetBm->csrf($vetBm->get('/manager/inventory')['body']),
        'key_id' => $gsKeyId2,
    ]);
    assertCheck('Vet Clinic BM cannot revoke Grooming Services key (403 Forbidden)', $crossRevokeGroom['status'] === 403);

    // Both keys remain active and unaffected
    assertCheck('Vet Clinic key #2 remains active after cross-unit attempts', InventoryAccessKey::getActiveForUnit($pdo, Units::vetClinicId()) !== null);
    assertCheck('Grooming key #2 remains active after cross-unit attempts', InventoryAccessKey::getActiveForUnit($pdo, Units::groomingId()) !== null);

    // =========================================================================
    // 5. UNAUTHORIZED USERS REJECTION
    // =========================================================================
    echo "\n--- Section 5: Unauthorized Revocation Rejection ---\n";

    // System Administrator attempt
    $saPage = $sysAdmin->get('/sysadmin');
    $saRevoke = $sysAdmin->post('/manager/inventory/revoke-key', [
        'csrf_token' => $sysAdmin->csrf($saPage['body']),
        'key_id' => $vetKeyId2,
    ]);
    assertCheck('System Administrator cannot revoke keys (403 Forbidden)', $saRevoke['status'] === 403);

    // Staff attempt (Vet Nurse)
    $nurseRevoke = $vetStaff->post('/manager/inventory/revoke-key', [
        'csrf_token' => $vetStaff->csrf($vetStaff->get('/staff/workspace')['body']),
        'key_id' => $vetKeyId2,
    ]);
    assertCheck('Vet Nurse cannot revoke keys (403 Forbidden)', $nurseRevoke['status'] === 403);

} finally {
    // Teardown test keys
    if (!empty($createdTestKeyIds)) {
        $placeholders = implode(',', array_fill(0, count($createdTestKeyIds), '?'));
        $pdo->prepare("DELETE FROM inventory_access_key WHERE key_id IN ({$placeholders})")->execute($createdTestKeyIds);
    }
}

echo "\n====================================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
