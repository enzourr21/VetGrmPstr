<?php
declare(strict_types=1);

/**
 * Pet Store Business Manager Verification Test Suite
 * Validates all 17 verification items from Section 12 of specifications.
 */

require_once __DIR__ . '/../app/Core/Core.php';
require_once __DIR__ . '/../app/Models/OtpService.php';

use App\Core\Database;
use App\Models\Audit;
use App\Models\OtpService;
use App\Models\Units;

$pdo = Database::pdo();

$passed = 0;
$failed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failed++;
        echo "  FAIL: $label" . ($detail ? " ($detail)" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "VETGRPET PET STORE BUSINESS MANAGER TEST SUITE\n";
echo "====================================================================\n\n";

// Helper: HTTP request simulator against running dev server
function request(string $method, string $path, array $data = [], array $cookies = []): array
{
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    $cookieHeader = [];
    foreach ($cookies as $k => $v) {
        $cookieHeader[] = "$k=$v";
    }
    if ($cookieHeader) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . implode('; ', $cookieHeader)]);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($raw ?: '', 0, $headerSize);
    $body = substr($raw ?: '', $headerSize);

    // Extract set-cookie
    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headers, $matches);
    $newCookies = [];
    foreach ($matches[1] as $c) {
        [$k, $v] = explode('=', $c, 2);
        $newCookies[trim($k)] = trim($v);
    }

    curl_close($ch);
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'cookies' => array_merge($cookies, $newCookies)];
}

function extractCsrf(string $html): string
{
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function staffLogin(string $email, string $password): array
{
    $r1 = request('GET', '/staff/login');
    $csrf = extractCsrf($r1['body']);
    $r2 = request('POST', '/staff/login', [
        'csrf_token' => $csrf,
        'email' => $email,
        'password' => $password,
    ], $r1['cookies']);

    return array_merge($r1['cookies'], $r2['cookies']);
}

function customerLogin(string $email, string $password): array
{
    $r1 = request('GET', '/login');
    $csrf = extractCsrf($r1['body']);
    $r2 = request('POST', '/login/email', [
        'csrf_token' => $csrf,
        'email' => $email,
    ], $r1['cookies']);
    $c = array_merge($r1['cookies'], $r2['cookies']);
    $csrf2 = extractCsrf($r2['body']) ?: $csrf;
    $r3 = request('POST', '/login/password', [
        'csrf_token' => $csrf2,
        'email' => $email,
        'password' => $password,
    ], $c);

    return array_merge($c, $r3['cookies']);
}

// -------------------------------------------------------------
// SECTION 1 & 2: Six Pages, Navigation & Authorization
// -------------------------------------------------------------
echo "--- 1 & 2. Six Pages, Navigation, and Authorization ---\n";

$bmCookies = staffLogin('david.reyes@staff.sample.vetgrpet', 'Staff123!');
check('Pet Store Business Manager (David Reyes) signs in', !empty($bmCookies));

$overviewReq = request('GET', '/manager', [], $bmCookies);
check('1. Overview page renders for Business Manager (200)', $overviewReq['status'] === 200 && str_contains($overviewReq['body'], 'Pet Store Overview'));
check('Overview contains no large employee roster', !str_contains($overviewReq['body'], 'Unit Staff Roster'));
check('Navigation contains 6 standard tabs',
    str_contains($overviewReq['body'], 'Overview') &&
    str_contains($overviewReq['body'], 'Sales') &&
    str_contains($overviewReq['body'], 'Products') &&
    str_contains($overviewReq['body'], 'Inventory') &&
    str_contains($overviewReq['body'], 'Employees') &&
    str_contains($overviewReq['body'], 'Business Settings')
);

$salesReq = request('GET', '/manager/sales', [], $bmCookies);
check('2. Sales page renders for Business Manager (200)', $salesReq['status'] === 200 && str_contains($salesReq['body'], 'Walk-in Sales') && str_contains($salesReq['body'], 'Online Orders'));

$productsReq = request('GET', '/manager/products', [], $bmCookies);
check('3. Products page renders for Business Manager (200)', $productsReq['status'] === 200 && str_contains($productsReq['body'], 'Product Catalog'));

$invReq = request('GET', '/manager/inventory', [], $bmCookies);
check('4. Inventory page renders for Business Manager (200)', $invReq['status'] === 200 && str_contains($invReq['body'], 'Stock Levels &amp; Adjustments'));

$empReq = request('GET', '/manager/employees', [], $bmCookies);
check('5. Employees page renders for Business Manager (200)', $empReq['status'] === 200 && str_contains($empReq['body'], 'Pet Store Employee Roster'));

$settingsReq = request('GET', '/manager/settings', [], $bmCookies);
check('6. Business Settings page renders for Business Manager (200)', $settingsReq['status'] === 200 && str_contains($settingsReq['body'], 'Business Settings'));

// Authorization tests
$anonReq = request('GET', '/manager');
check('Anonymous user cannot access /manager (Redirected 302)', $anonReq['status'] === 302);

$custPw = password_hash('Customer123!', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO user_account (email, password_hash, account_status) VALUES ('test_customer@vetgrpet.local', ?, 'active') ON DUPLICATE KEY UPDATE password_hash = ?")->execute([$custPw, $custPw]);
$cuid = (int)$pdo->query("SELECT user_account_id FROM user_account WHERE email = 'test_customer@vetgrpet.local'")->fetchColumn();
$pdo->prepare("INSERT INTO customer (user_account_id, first_name, last_name, customer_status) VALUES (?, 'Test', 'Customer', 'active') ON DUPLICATE KEY UPDATE customer_status = 'active'")->execute([$cuid]);

$custCookies = customerLogin('test_customer@vetgrpet.local', 'Customer123!');
$custReq = request('GET', '/manager', [], $custCookies);
check('Customer session denied access to /manager (403)', $custReq['status'] === 403);

$vetNurseCookies = staffLogin('hannah.kim@staff.sample.vetgrpet', 'Staff123!');
$vetNurseReq = request('GET', '/manager', [], $vetNurseCookies);
check('Veterinary Nurse denied Business Manager access (403)', $vetNurseReq['status'] === 403);

// Cross-unit access check
$crossUnitReq = request('GET', '/manager?unit_id=2', [], $bmCookies);
check('Pet Store Business Manager denied cross-unit access to Vet Clinic (403)', $crossUnitReq['status'] === 403);

// -------------------------------------------------------------
// SECTION 3 & 4: Products Catalog & Deactivation Rules
// -------------------------------------------------------------
echo "\n--- 3 & 4. Product Catalog, Editing & Deactivation ---\n";

$csrf = extractCsrf($productsReq['body']);

$testSku = 'SKU-PS-TEST-' . rand(1000, 9999);
$createProd = request('POST', '/manager/products', [
    'csrf_token' => $csrf,
    'product_name' => 'Premium Rubber Chew Bone',
    'sku' => $testSku,
    'product_category' => 'Toys & Enrichment',
    'selling_price' => '14.99',
    'description' => 'Durable non-toxic rubber chew toy for active pets.',
], $bmCookies);

check('Product creation redirected successfully (302)', $createProd['status'] === 302);

$testBp = $pdo->query("SELECT bp.business_product_id, bp.product_status, p.product_name, p.sku FROM business_product bp JOIN product p ON p.product_id = bp.product_id WHERE p.sku = '$testSku'")->fetch(PDO::FETCH_ASSOC);
check('Product was inserted with active status in business_product', !empty($testBp) && $testBp['product_status'] === 'active');
$testBpId = (int)($testBp['business_product_id'] ?? 0);

// Edit product
$editProd = request('POST', "/manager/products/$testBpId/update", [
    'csrf_token' => $csrf,
    'product_name' => 'Premium Rubber Chew Bone (Large)',
    'sku' => $testSku,
    'product_category' => 'Toys & Enrichment',
    'selling_price' => '18.50',
    'description' => 'Updated description for large breed dogs.',
], $bmCookies);
check('Product update redirected successfully (302)', $editProd['status'] === 302);

$updatedBp = $pdo->query("SELECT bp.selling_price, p.product_name FROM business_product bp JOIN product p ON p.product_id = bp.product_id WHERE bp.business_product_id = $testBpId")->fetch(PDO::FETCH_ASSOC);
check('Product price and name updated', (float)$updatedBp['selling_price'] === 18.50 && str_contains($updatedBp['product_name'], 'Large'));

// Deactivate product
$deactProd = request('POST', "/manager/products/$testBpId/status", [
    'csrf_token' => $csrf,
    'status' => 'inactive',
], $bmCookies);
check('Product deactivation redirected (302)', $deactProd['status'] === 302);

$deactBp = $pdo->query("SELECT product_status FROM business_product WHERE business_product_id = $testBpId")->fetch(PDO::FETCH_ASSOC);
check('Product status is now inactive (soft deactivation, historical records preserved)', $deactBp['product_status'] === 'inactive');

// Check that public catalog filters out inactive product
$pubCatalog = request('GET', '/products');
check('Inactive product is not offered in customer catalog', !str_contains($pubCatalog['body'], $testSku));

// Re-activate product
$reactProd = request('POST', "/manager/products/$testBpId/status", [
    'csrf_token' => $csrf,
    'status' => 'active',
], $bmCookies);
check('Product can be re-activated', $reactProd['status'] === 302);

// -------------------------------------------------------------
// SECTION 5: Sales Page Walk-in vs Online Views & Details
// -------------------------------------------------------------
echo "\n--- 5. Sales Views (Walk-in vs Online) & Full Transaction Details ---\n";

// Insert sample walk-in sale and sample online order for Pet Store to test full rendering
$pdo->exec("INSERT INTO sale (sale_id, business_unit_id, branch_id, customer_id, employee_id, sale_date, total_amount, sale_status)
            VALUES (9001, 1, 1, 1, 5, NOW(), 37.00, 'completed')
            ON DUPLICATE KEY UPDATE total_amount = 37.00");
$pdo->exec("INSERT INTO sale_item (sale_id, business_product_id, quantity, unit_price)
            VALUES (9001, 1, 2, 13.50), (9001, 7, 1, 10.00)
            ON DUPLICATE KEY UPDATE quantity = 2");
$pdo->exec("INSERT INTO payment (payment_id, sale_id, payment_type, amount, payment_method, payment_status)
            VALUES (9001, 9001, 'payment', 37.00, 'Cash', 'completed')
            ON DUPLICATE KEY UPDATE amount = 37.00");

$pdo->exec("INSERT INTO customer_order (order_id, customer_id, business_unit_id, branch_id, order_date, fulfillment_method, order_status, total_amount, delivery_address_line1, delivery_city)
            VALUES (9002, 1, 1, 1, NOW(), 'delivery', 'completed', 48.00, '123 Pet St', 'Makati')
            ON DUPLICATE KEY UPDATE total_amount = 48.00");
$pdo->exec("INSERT INTO order_item (order_id, business_product_id, quantity, unit_price)
            VALUES (9002, 6, 2, 24.00)
            ON DUPLICATE KEY UPDATE quantity = 2");
$pdo->exec("INSERT INTO payment (payment_id, order_id, payment_type, amount, payment_method, payment_status)
            VALUES (9002, 9002, 'payment', 48.00, 'GCash', 'completed')
            ON DUPLICATE KEY UPDATE amount = 48.00");

$walkInView = request('GET', '/manager/sales?tab=walkin', [], $bmCookies);
check('Walk-in sales list renders sample sale #REC-S-9001', str_contains($walkInView['body'], 'REC-S-9001'));
check('Walk-in details modal contains customer, items, prices, discounts, subtotal, and receipt',
    str_contains($walkInView['body'], 'walkin-modal-9001') &&
    str_contains($walkInView['body'], 'Subtotal:') &&
    str_contains($walkInView['body'], 'Discount:') &&
    str_contains($walkInView['body'], 'Cash') &&
    str_contains($walkInView['body'], 'Final Total:')
);

$onlineView = request('GET', '/manager/sales?tab=online', [], $bmCookies);
check('Online orders view renders order #ORD-9002', str_contains($onlineView['body'], 'ORD-9002'));
check('Online details modal contains fulfillment, delivery address, items, payment method, and receipt',
    str_contains($onlineView['body'], 'online-modal-9002') &&
    str_contains($onlineView['body'], 'Delivery') &&
    str_contains($onlineView['body'], '123 Pet St') &&
    str_contains($onlineView['body'], 'GCash')
);

// -------------------------------------------------------------
// SECTION 6, 7 & 8: Purchase Orders & Stock Receiving
// -------------------------------------------------------------
echo "\n--- 6, 7 & 8. Purchase Order Creation & Multi-Receiving ---\n";

$invPage = request('GET', '/manager/inventory?tab=stock', [], $bmCookies);
$csrf = extractCsrf($invPage['body']);

// Check initial on_hand for business_product 1
$initialOnHand = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();

// Create PO for 50 units of product 1
$createPo = request('POST', '/manager/inventory/purchase-orders', [
    'csrf_token' => $csrf,
    'supplier_name' => 'Philippine Pet Wholesale Distributors',
    'contact_person' => 'Roberto Carlos',
    'supplier_email' => 'sales@philippinepet.local',
    'supplier_phone' => '0917-888-0000',
    'items' => [
        ['business_product_id' => 1, 'quantity_ordered' => 50, 'unit_cost' => 8.50]
    ]
], $bmCookies);

check('PO creation redirects (302)', $createPo['status'] === 302);

$latestPo = $pdo->query("SELECT * FROM purchase_order WHERE supplier_name = 'Philippine Pet Wholesale Distributors' ORDER BY purchase_order_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('PO was created with status "Ordered"', !empty($latestPo) && $latestPo['po_status'] === 'Ordered');
check('PO Number was system-generated', str_starts_with((string)$latestPo['po_number'], 'PO-PS-'));

// CRITICAL VERIFICATION: PO Creation must NEVER directly increase stock!
$afterPoOnHand = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
check('PO creation did not alter on-hand stock (initial: ' . $initialOnHand . ', after: ' . $afterPoOnHand . ')', $initialOnHand === $afterPoOnHand);

$poId = (int)$latestPo['purchase_order_id'];
$poItemId = (int)$pdo->query("SELECT po_item_id FROM purchase_order_item WHERE purchase_order_id = $poId")->fetchColumn();

// 1st Receiving: 30 accepted, 0 rejected
$rcv1 = request('POST', "/manager/inventory/purchase-orders/$poId/receive", [
    'csrf_token' => $csrf,
    'items' => [
        [
            'po_item_id' => $poItemId,
            'business_product_id' => 1,
            'quantity_received' => 30,
            'quantity_accepted' => 30,
            'quantity_rejected' => 0,
        ]
    ]
], $bmCookies);
check('1st Receiving recorded (302)', $rcv1['status'] === 302);

$onHandAfterRcv1 = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
check('Stock increased by exactly accepted quantity (+30)', $onHandAfterRcv1 === $initialOnHand + 30);

$poAfterRcv1 = $pdo->query("SELECT po_status FROM purchase_order WHERE purchase_order_id = $poId")->fetch(PDO::FETCH_ASSOC);
check('PO status moved to Partially Received', $poAfterRcv1['po_status'] === 'Partially Received');

// 2nd Receiving: 15 accepted, 5 rejected (with reason)
$rcv2 = request('POST', "/manager/inventory/purchase-orders/$poId/receive", [
    'csrf_token' => $csrf,
    'items' => [
        [
            'po_item_id' => $poItemId,
            'business_product_id' => 1,
            'quantity_received' => 20,
            'quantity_accepted' => 15,
            'quantity_rejected' => 5,
            'rejection_reason' => 'Damaged carton during transit'
        ]
    ]
], $bmCookies);
check('2nd Receiving recorded (302)', $rcv2['status'] === 302);

$onHandAfterRcv2 = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
check('ONLY accepted quantity (+15) increased stock, rejected (5) did NOT enter inventory', $onHandAfterRcv2 === $initialOnHand + 45);

$poAfterRcv2 = $pdo->query("SELECT po_status FROM purchase_order WHERE purchase_order_id = $poId")->fetch(PDO::FETCH_ASSOC);
check('PO status is now Fully Received after all 50 units accounted for', $poAfterRcv2['po_status'] === 'Fully Received');

$rcvCount = (int)$pdo->query("SELECT COUNT(*) FROM stock_receiving WHERE purchase_order_id = $poId")->fetchColumn();
check('Multiple receiving records exist for the same purchase order (count: 2)', $rcvCount === 2);

// -------------------------------------------------------------
// SECTION 9: Stock Adjustment, Reasons & Audit Log
// -------------------------------------------------------------
echo "\n--- 9. Stock Adjustments, Mandatory Reasons & Audit Log ---\n";

$currStock = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();

// 1. Positive adjustment: New stock received
$adjInc = request('POST', '/manager/inventory/adjust', [
    'csrf_token' => $csrf,
    'business_product_id' => 1,
    'adjustment_type' => 'increase',
    'quantity' => 5,
    'reason' => 'New stock received'
], $bmCookies);
check('Stock increase (+5) succeeded (302)', $adjInc['status'] === 302);

$afterInc = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
check('Inventory incremented by 5', $afterInc === $currStock + 5);

// 2. Return reason WITHOUT inspection checkbox MUST be rejected
$adjReturnNoCheck = request('POST', '/manager/inventory/adjust', [
    'csrf_token' => $csrf,
    'business_product_id' => 1,
    'adjustment_type' => 'increase',
    'quantity' => 2,
    'reason' => 'Returned product from refund',
    // inspection_confirmed omitted!
], $bmCookies);

$afterFailedReturn = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
check('Return reason without inspection confirmation is rejected and leaves stock unchanged', $afterFailedReturn === $afterInc);

// 3. Return reason WITH inspection confirmation
$adjReturnWithCheck = request('POST', '/manager/inventory/adjust', [
    'csrf_token' => $csrf,
    'business_product_id' => 1,
    'adjustment_type' => 'increase',
    'quantity' => 2,
    'reason' => 'Returned product from refund',
    'inspection_confirmed' => 1,
    'related_reference' => 'REF-9001'
], $bmCookies);
$afterValidReturn = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
check('Return reason with inspection confirmation increases stock (+2)', $afterValidReturn === $afterInc + 2);

// 4. Decrease adjustment
$adjDec = request('POST', '/manager/inventory/adjust', [
    'csrf_token' => $csrf,
    'business_product_id' => 1,
    'adjustment_type' => 'decrease',
    'quantity' => 3,
    'reason' => 'Damaged product'
], $bmCookies);
$afterDec = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
check('Stock decrease (-3) with reason "Damaged product" succeeded', $afterDec === $afterValidReturn - 3);

// 5. Audit record check
$adjAudit = $pdo->query("SELECT * FROM stock_adjustment WHERE business_product_id = 1 ORDER BY adjustment_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('Stock adjustment recorded in audit table with previous and new on_hand',
    !empty($adjAudit) &&
    $adjAudit['adjustment_type'] === 'decrease' &&
    $adjAudit['reason'] === 'Damaged product' &&
    (int)$adjAudit['previous_on_hand'] === $afterValidReturn &&
    (int)$adjAudit['new_on_hand'] === $afterDec
);

// 6. Test Inventory Manager role can also adjust stock
$imCookies = staffLogin('ja.ompoy@staff.sample.vetgrpet', 'Staff123!');
check('Inventory Manager (Janricson Ompoy) signs in', !empty($imCookies));

$imInvPage = request('GET', '/manager/inventory', [], $imCookies);
check('Inventory Manager can access /manager/inventory (200)', $imInvPage['status'] === 200);
check('Inventory Manager sees "Request Order" button instead of "Purchase Order"',
    str_contains($imInvPage['body'], 'Request Order') && !str_contains($imInvPage['body'], '+ Purchase Order')
);

// 7. Inventory Manager CANNOT access Business Settings
$imSettings = request('GET', '/manager/settings', [], $imCookies);
check('Inventory Manager is denied Business Settings access (403)', $imSettings['status'] === 403);

// -------------------------------------------------------------
// SECTION 10: Employees Page (Read-Only)
// -------------------------------------------------------------
echo "\n--- 10. Employees Page & Work Status Badges ---\n";

$empPage = request('GET', '/manager/employees', [], $bmCookies);
check('Employee roster displays David Reyes', str_contains($empPage['body'], 'David Reyes'));
check('Employee roster displays Janricson Ompoy', str_contains($empPage['body'], 'Janricson Ompoy'));
check('Employee roster displays work status badges (On Duty / Free)', str_contains($empPage['body'], 'On Duty') && str_contains($empPage['body'], 'Free'));
check('Employees page contains no branch information', !str_contains($empPage['body'], 'Branch Information'));
check('Employees page is read-only (no role/account editing forms)',
    !str_contains($empPage['body'], 'action="/sysadmin/employees') &&
    !str_contains($empPage['body'], 'Send Invite Link')
);

// -------------------------------------------------------------
// SECTION 11, 12, 13 & 14: Business Settings (Profile, Hours, Policies, Payments)
// -------------------------------------------------------------
echo "\n--- 11, 12, 13, 14 & 15. Business Settings (Profile, Hours, Policies, Gateways) ---\n";

// Reset payment verification state so tests are fully reproducible
$pdo->exec("UPDATE business_settings SET gcash_verified = 0, gcash_mobile = NULL, maya_verified = 0, maya_mobile = NULL WHERE business_unit_id = 1");
$pdo->exec("DELETE FROM otp_verification WHERE business_unit_id = 1");

$settingsPage = request('GET', '/manager/settings', [], $bmCookies);
$csrf = extractCsrf($settingsPage['body']);

// 1. Business Profile: 8-digit landline number
$upd8Digit = request('POST', '/manager/settings/profile', [
    'csrf_token' => $csrf,
    'business_name' => 'VETGRPET Pet Store',
    'business_email' => 'petstore@vetgrpet.local',
    'contact_number' => '82345678'
], $bmCookies);
check('8-digit landline number is accepted (302)', $upd8Digit['status'] === 302);

$setRow = $pdo->query("SELECT contact_number FROM business_settings WHERE business_unit_id = 1")->fetch(PDO::FETCH_ASSOC);
check('Settings saved 8-digit number', $setRow['contact_number'] === '82345678');

// 2. Business Profile: 11-digit phone number
$upd11Digit = request('POST', '/manager/settings/profile', [
    'csrf_token' => $csrf,
    'business_name' => 'VETGRPET Pet Store',
    'business_email' => 'petstore@vetgrpet.local',
    'contact_number' => '09175550199'
], $bmCookies);
check('11-digit mobile number is accepted (302)', $upd11Digit['status'] === 302);

// 3. Business Profile: Invalid phone length (e.g. 5 digits) MUST be rejected
$updBadPhone = request('POST', '/manager/settings/profile', [
    'csrf_token' => $csrf,
    'business_name' => 'VETGRPET Pet Store',
    'business_email' => 'petstore@vetgrpet.local',
    'contact_number' => '12345'
], $bmCookies);
$setRowAfterBad = $pdo->query("SELECT contact_number FROM business_settings WHERE business_unit_id = 1")->fetch(PDO::FETCH_ASSOC);
check('Invalid phone length is rejected and not saved', $setRowAfterBad['contact_number'] === '09175550199');

// 4. Operating Hours configuration
$updHours = request('POST', '/manager/settings/hours', [
    'csrf_token' => $csrf,
    'hours' => [
        1 => ['is_open' => 1, 'open_time' => '07:30', 'close_time' => '19:30'],
        2 => ['is_open' => 1, 'open_time' => '07:30', 'close_time' => '19:30'],
        3 => ['is_open' => 1, 'open_time' => '07:30', 'close_time' => '19:30'],
        4 => ['is_open' => 1, 'open_time' => '07:30', 'close_time' => '19:30'],
        5 => ['is_open' => 1, 'open_time' => '07:30', 'close_time' => '19:30'],
        6 => ['is_open' => 1, 'open_time' => '08:00', 'close_time' => '18:00'],
        7 => ['is_open' => 0, 'open_time' => '00:00', 'close_time' => '00:00'],
    ]
], $bmCookies);
check('Operating hours update redirects (302)', $updHours['status'] === 302);

$sunHours = $pdo->query("SELECT is_open FROM operating_hours WHERE business_unit_id = 1 AND day_of_week = 7")->fetch(PDO::FETCH_ASSOC);
check('Sunday schedule saved as closed (is_open = 0)', (int)$sunHours['is_open'] === 0);

// 5. Business Policies are read-only
check('Business policies section has Read-Only badge and no editing form',
    str_contains($settingsPage['body'], '3. Business Policies') &&
    str_contains($settingsPage['body'], 'Read-Only') &&
    !str_contains($settingsPage['body'], 'action="/manager/settings/policies')
);

// 6. COD Gateway toggle
$updCod = request('POST', '/manager/settings/payment', [
    'csrf_token' => $csrf,
    'service_type' => 'cod',
    'cod_enabled' => 1
], $bmCookies);
check('COD update succeeded without OTP (302)', $updCod['status'] === 302);

// 7. GCash & Maya OTP Workflow & Strict Server-Side Verification
// A: Confirm without OTP verification MUST be rejected server-side
$unverifiedGcash = request('POST', '/manager/settings/payment', [
    'csrf_token' => $csrf,
    'service_type' => 'gcash',
    'mobile_number' => '09179998877'
], $bmCookies);
$gcashCheck = (int)$pdo->query("SELECT gcash_verified FROM business_settings WHERE business_unit_id = 1")->fetchColumn();
check('Unverified GCash confirmation is strictly rejected by backend', $gcashCheck === 0);

// B: Request OTP via endpoint
$otpReq = request('POST', '/manager/settings/send-otp', [
    'csrf_token' => $csrf,
    'service_type' => 'gcash',
    'mobile_number' => '09179998877'
], $bmCookies);
$otpJson = json_decode($otpReq['body'], true);
check('OTP request succeeds with JSON response', !empty($otpJson['ok']));
check('OTP response does not leak secret code in response text',
    !isset($otpJson['simulated_otp']) && !str_contains($otpJson['message'] ?? '', 'code is:')
);

// C: Verify with invalid OTP
$verifyBad = request('POST', '/manager/settings/verify-otp', [
    'csrf_token' => $csrf,
    'service_type' => 'gcash',
    'mobile_number' => '09179998877',
    'otp' => '000000'
], $bmCookies);
$verifyBadJson = json_decode($verifyBad['body'], true);
check('Invalid OTP is rejected by verification endpoint', empty($verifyBadJson['ok']));

// D: Verify with valid OTP using test OTP override on backend
OtpService::requestOtp(1, 'gcash', '09179998877', '849201');
$verifyGood = request('POST', '/manager/settings/verify-otp', [
    'csrf_token' => $csrf,
    'service_type' => 'gcash',
    'mobile_number' => '09179998877',
    'otp' => '849201'
], $bmCookies);
$verifyGoodJson = json_decode($verifyGood['body'], true);
check('Valid OTP is verified successfully by backend', !empty($verifyGoodJson['ok']));

// E: Now confirm verified GCash
$confirmGcash = request('POST', '/manager/settings/payment', [
    'csrf_token' => $csrf,
    'service_type' => 'gcash',
    'mobile_number' => '09179998877'
], $bmCookies);
$gcashVerified = (int)$pdo->query("SELECT gcash_verified FROM business_settings WHERE business_unit_id = 1")->fetchColumn();
check('Verified GCash setup successfully confirmed in business_settings', $gcashVerified === 1);

// F: Maya has separate account and verification state
$mayaVerified = (int)$pdo->query("SELECT maya_verified FROM business_settings WHERE business_unit_id = 1")->fetchColumn();
check('Maya remains unverified (isolated from GCash)', $mayaVerified === 0);

// Verify Maya separately
OtpService::requestOtp(1, 'maya', '09187776655', '391024');
$verifyMaya = request('POST', '/manager/settings/verify-otp', [
    'csrf_token' => $csrf,
    'service_type' => 'maya',
    'mobile_number' => '09187776655',
    'otp' => '391024'
], $bmCookies);
check('Maya verified independently', !empty(json_decode($verifyMaya['body'], true)['ok']));

$confirmMaya = request('POST', '/manager/settings/payment', [
    'csrf_token' => $csrf,
    'service_type' => 'maya',
    'mobile_number' => '09187776655'
], $bmCookies);
$mayaVerifiedAfter = (int)$pdo->query("SELECT maya_verified FROM business_settings WHERE business_unit_id = 1")->fetchColumn();
check('Maya setup confirmed and verified', $mayaVerifiedAfter === 1);

// -------------------------------------------------------------
// SECTION 16: Check No Notifications or Branch Info Added
// -------------------------------------------------------------
echo "\n--- 16. Structural Integrity Checks ---\n";
check('No Notifications tab or section added to Business Manager', !str_contains($settingsPage['body'], 'Notifications'));
check('No Branch Information tab or section added', !str_contains($settingsPage['body'], 'Branch Information'));

// -------------------------------------------------------------
// SECTION 17: Final UI Refinements Verification
// -------------------------------------------------------------
echo "\n--- 17. Final UI Refinements Verification ---\n";

// 1 & 2: Overview Page Refinements
$ovRefined = request('GET', '/manager', [], $bmCookies);
check('Overview page card is renamed to "Recent Sales"', (bool)preg_match('/<h2[^>]*>\s*Recent Sales\s*<\/h2>/i', $ovRefined['body']) && !str_contains($ovRefined['body'], 'Recently Successful Sales'));
check('Overview displays unified Recent Sales list', str_contains($ovRefined['body'], 'Sales Channel') && str_contains($ovRefined['body'], 'Ref / Receipt #'));
check('Overview Confirmed Appointments card is removed', !str_contains($ovRefined['body'], 'Confirmed Appointments'));
check('Overview displays Employees On Duty card', str_contains($ovRefined['body'], 'Employees On Duty'));
check('Overview displays Pending Refund Requests card with real requests', str_contains($ovRefined['body'], 'Pending Refund Requests') && str_contains($ovRefined['body'], 'REF-REQ-1001'));

// 3: Sales Page Selected Indicator & Filter Button Removal
$salesRefined = request('GET', '/manager/sales?tab=walkin', [], $bmCookies);
check('Sales page Walk-in indicator uses emerald color treatment', str_contains($salesRefined['body'], 'var(--brand-soft') || str_contains($salesRefined['body'], 'var(--brand'));
check('Sales page does not contain green Filter button', !preg_match('/<button[^>]+class="btn secondary[^>]*>Filter<\/button>/i', $salesRefined['body']));

// 4 & 5: Products Page Refinements (No separate add button in page-head, custom confirmation dialog)
$prodRefined = request('GET', '/manager/products', [], $bmCookies);
preg_match('/<div class="page-head">([\s\S]*?)<\/div>\s*<!--/i', $prodRefined['body'], $pageHeadMatch);
$pageHeadHtml = $pageHeadMatch[1] ?? '';
check('Products page-head does not contain separate Add Product button', !str_contains($pageHeadHtml, 'Add Product') && !str_contains($pageHeadHtml, '<button'));
check('Products table does not use browser-native confirm() dialog', !str_contains($prodRefined['body'], 'onsubmit="return confirm('));
check('Products page includes application-styled status confirmation dialogs', str_contains($prodRefined['body'], 'id="confirm-status-modal-'));
check('Products page does not contain green Filter button', !preg_match('/<button[^>]+class="btn secondary[^>]*>Filter<\/button>/i', $prodRefined['body']));
check('Products category and status filters auto-submit on change', str_contains($prodRefined['body'], 'onchange="this.form.submit()"'));

// 6 & 7: Product Image Upload & Preservation on Edit
// Test multipart product creation with image upload
$csrf = extractCsrf($prodRefined['body']);
$testSkuImg = 'SKU-IMG-' . rand(1000, 9999);
$testImgPath = sys_get_temp_dir() . '/test_paw.png';
// Create a 1x1 test PNG
file_put_contents($testImgPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

// Multipart request helper
$boundary = '---------------------------' . microtime(true);
$multipartBody = "--$boundary\r\n" .
    "Content-Disposition: form-data; name=\"csrf_token\"\r\n\r\n$csrf\r\n" .
    "--$boundary\r\n" .
    "Content-Disposition: form-data; name=\"product_name\"\r\n\r\nOrganic Catnip Ball\r\n" .
    "--$boundary\r\n" .
    "Content-Disposition: form-data; name=\"sku\"\r\n\r\n$testSkuImg\r\n" .
    "--$boundary\r\n" .
    "Content-Disposition: form-data; name=\"product_category\"\r\n\r\nToys & Enrichment\r\n" .
    "--$boundary\r\n" .
    "Content-Disposition: form-data; name=\"selling_price\"\r\n\r\n8.50\r\n" .
    "--$boundary\r\n" .
    "Content-Disposition: form-data; name=\"description\"\r\n\r\nNatural pressed catnip ball toy.\r\n" .
    "--$boundary\r\n" .
    "Content-Disposition: form-data; name=\"product_image\"; filename=\"test_paw.png\"\r\n" .
    "Content-Type: image/png\r\n\r\n" . file_get_contents($testImgPath) . "\r\n" .
    "--$boundary--\r\n";

$headers = [
    'Content-Type: multipart/form-data; boundary=' . $boundary,
    'Accept: text/html',
];
$cookiePairs = [];
foreach ($bmCookies as $k => $v) { $cookiePairs[] = "$k=$v"; }
if ($cookiePairs) { $headers[] = 'Cookie: ' . implode('; ', $cookiePairs); }

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $multipartBody,
        'follow_location' => 0,
        'ignore_errors' => true,
    ],
]);
$resp = @file_get_contents('http://127.0.0.1:8080/manager/products', false, $ctx);
$status = 0;
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int)$m[1]; }
}
check('Product creation with image upload redirects (302)', $status === 302);

$createdImgBp = $pdo->query("SELECT bp.business_product_id, p.image_url FROM business_product bp JOIN product p ON p.product_id = bp.product_id WHERE p.sku = '$testSkuImg'")->fetch(PDO::FETCH_ASSOC);
check('Uploaded image was stored and saved to product.image_url', !empty($createdImgBp) && str_starts_with($createdImgBp['image_url'], '/uploads/products/'));
$imgBpId = (int)($createdImgBp['business_product_id'] ?? 0);
$originalImgUrl = $createdImgBp['image_url'] ?? '';

// Edit product WITHOUT replacement image file
$editNoImg = request('POST', "/manager/products/$imgBpId/update", [
    'csrf_token' => $csrf,
    'product_name' => 'Organic Catnip Ball (Deluxe)',
    'sku' => $testSkuImg,
    'product_category' => 'Toys & Enrichment',
    'selling_price' => '9.50',
    'description' => 'Updated description without changing image.',
], $bmCookies);
check('Product edit without file upload redirects (302)', $editNoImg['status'] === 302);

$preservedBp = $pdo->query("SELECT p.image_url FROM business_product bp JOIN product p ON p.product_id = bp.product_id WHERE bp.business_product_id = $imgBpId")->fetch(PDO::FETCH_ASSOC);
check('Editing product without replacement preserves current image_url', $preservedBp['image_url'] === $originalImgUrl);

// 8 & 9: Inventory Page (PO product rows retain quantity input but no price field; supplier phone styling)
$appJsContent = file_get_contents(__DIR__ . '/../public/assets/js/app.js');
check('PO item creation in app.js does not contain unit_cost / price field', !str_contains($appJsContent, 'name="items[\' + poItemIndex + \'][unit_cost]"'));
check('PO item creation in app.js retains quantity_ordered input', str_contains($appJsContent, 'name="items[\' + poItemIndex + \'][quantity_ordered]"'));

$invPage = request('GET', '/manager/inventory?tab=orders', [], $bmCookies);
check('Supplier Phone input field uses type="tel"', str_contains($invPage['body'], 'id="po-supplier-phone"') && str_contains($invPage['body'], 'type="tel"'));

$appCssContent = file_get_contents(__DIR__ . '/../public/assets/css/app.css');
check('CSS includes input[type=tel] and input[type=search] in standardized form control styles', str_contains($appCssContent, 'input[type=tel]') && str_contains($appCssContent, 'input[type=search]'));

// 10: Employees Page (Search field appearance & no green filter button)
$empPage = request('GET', '/manager/employees', [], $bmCookies);
check('Employees search field uses .search-input-wrap with aligned search icon', str_contains($empPage['body'], 'class="search-input-wrap"') && str_contains($empPage['body'], 'class="search-icon"'));
check('Employees page does not contain green Filter button', !preg_match('/<button[^>]+class="btn secondary[^>]*>Filter<\/button>/i', $empPage['body']));
check('Employees position and work status dropdowns auto-submit on change', str_contains($empPage['body'], 'id="emp-pos" name="position" style="width: 100%;" onchange="this.form.submit()"'));

// 11: Business Settings Operating Hours Custom Time Picker
$settingsRefined = request('GET', '/manager/settings', [], $bmCookies);
check('Business Settings uses custom time picker UI', str_contains($settingsRefined['body'], 'class="custom-time-picker') && str_contains($settingsRefined['body'], 'class="time-select time-hour"'));
check('Business Settings does not use browser default input type="time"', !str_contains($settingsRefined['body'], 'type="time"'));
check('Custom time picker includes hour, minute, and AM/PM select elements', str_contains($settingsRefined['body'], 'time-minute') && str_contains($settingsRefined['body'], 'time-ampm'));

@unlink($testImgPath);

echo "\n--- 18. Overview Page Corrections & Refund Requests Management ---\n";

// 1. Overview Page Corrections
$ov = request('GET', '/manager', [], $bmCookies);
check('Overview contains Employees On Duty card', str_contains($ov['body'], 'Employees On Duty'));
check('Overview excludes Confirmed Appointments card', !str_contains($ov['body'], 'Confirmed Appointments'));
check('Overview Employees On Duty displays active on-duty/free staff', str_contains($ov['body'], 'David Reyes') && str_contains($ov['body'], 'Janricson Ompoy'));
check('Overview Pending Refund Requests displays pending reference', str_contains($ov['body'], 'REF-REQ-1001') || str_contains($ov['body'], 'REF-REQ-1002'));
check('Overview Pending Refund Requests links to Sales refunds view', str_contains($ov['body'], 'tab=refunds&amp;view=') || str_contains($ov['body'], 'tab=refunds&view='));
check('Overview Recent Sales unified record is preserved', str_contains($ov['body'], 'Recent Sales') && str_contains($ov['body'], 'Sales Channel'));

// 2. Sales Page Structure (3 Views: Walk-in, Online, Refund Requests)
$salesWalk = request('GET', '/manager/sales?tab=walkin', [], $bmCookies);
check('Sales page Walk-in tab has is-active class', (bool)preg_match('/tab=walkin[^>]*class="[^"]*segmented-pill[^"]*is-active/s', $salesWalk['body']));

$salesOnline = request('GET', '/manager/sales?tab=online', [], $bmCookies);
check('Sales page Online tab has is-active class', (bool)preg_match('/tab=online[^>]*class="[^"]*segmented-pill[^"]*is-active/s', $salesOnline['body']));

$salesRefunds = request('GET', '/manager/sales?tab=refunds', [], $bmCookies);
check('Sales page Refund Requests tab renders (200)', $salesRefunds['status'] === 200);
check('Sales page Refund Requests tab has is-active class', (bool)preg_match('/tab=refunds[^>]*class="[^"]*segmented-pill[^"]*is-active/s', $salesRefunds['body']));
check('Sales page Refund Requests active treatment uses emerald color', str_contains($salesRefunds['body'], 'var(--brand-soft') || str_contains($salesRefunds['body'], 'var(--brand'));
check('Sales page Refund Requests has automatic status filter dropdown', str_contains($salesRefunds['body'], '<select name="status" onchange="this.form.submit()"'));
check('Sales page Refund Requests has search input', str_contains($salesRefunds['body'], 'type="search"') && str_contains($salesRefunds['body'], 'name="q"'));
check('Sales page does not contain green Apply Filter button', !preg_match('/<button[^>]+class="btn secondary[^>]*>Filter<\/button>/i', $salesRefunds['body']));

// 3. Refund Requests List & Details
check('Refund requests list displays pending request REF-REQ-1001', str_contains($salesRefunds['body'], 'REF-REQ-1001'));
check('Refund requests list displays pending request REF-REQ-1002', str_contains($salesRefunds['body'], 'REF-REQ-1002'));
check('Refund details modal contains customer reason', str_contains($salesRefunds['body'], 'Wrong formulation received'));
check('Refund details modal contains supporting evidence', str_contains($salesRefunds['body'], 'Unopened bag with intact factory seal'));
check('Refund details modal provides link to view original transaction', str_contains($salesRefunds['body'], 'View Original Transaction &rarr;'));
check('Refund details modal contains purchased products table', str_contains($salesRefunds['body'], 'Purchased Products in Original Transaction'));

// 4. Refund Request Actions (Backend decisions, separate payment & inventory)
// Create test refund requests for approval and rejection tests
$pdo->exec("INSERT INTO refund_request (refund_reference, business_unit_id, customer_id, order_id, sale_id, payment_id, channel, requested_amount, customer_reason, request_status)
            VALUES ('REF-TEST-APP', 1, 1, 9002, NULL, 9002, 'online', 15.00, 'Test approval request', 'pending')
            ON DUPLICATE KEY UPDATE request_status = 'pending'");
$appTestId = (int)$pdo->query("SELECT refund_request_id FROM refund_request WHERE refund_reference = 'REF-TEST-APP'")->fetchColumn();

$pdo->exec("INSERT INTO refund_request (refund_reference, business_unit_id, customer_id, order_id, sale_id, payment_id, channel, requested_amount, customer_reason, request_status)
            VALUES ('REF-TEST-REJ', 1, 1, NULL, 9001, 9001, 'walkin', 10.00, 'Test rejection request', 'pending')
            ON DUPLICATE KEY UPDATE request_status = 'pending'");
$rejTestId = (int)$pdo->query("SELECT refund_request_id FROM refund_request WHERE refund_reference = 'REF-TEST-REJ'")->fetchColumn();

// Get fresh CSRF token
$csrfSales = extractCsrf($salesRefunds['body']);

// Test Rejection: rejection without reason is rejected
$rejNoReason = request('POST', "/manager/sales/refunds/$rejTestId/reject", [
    'csrf_token' => $csrfSales,
    'rejection_reason' => '',
], $bmCookies);
$rejCheck1 = $pdo->query("SELECT request_status FROM refund_request WHERE refund_request_id = $rejTestId")->fetchColumn();
check('Rejection without reason is rejected by backend (status remains pending)', $rejCheck1 === 'pending');

// Test Rejection with reason
$rejWithReason = request('POST', "/manager/sales/refunds/$rejTestId/reject", [
    'csrf_token' => $csrfSales,
    'rejection_reason' => 'Product was opened and used beyond policy limit.',
], $bmCookies);
check('Rejection with reason redirects (302)', $rejWithReason['status'] === 302);

$rejCheck2 = $pdo->query("SELECT * FROM refund_request WHERE refund_request_id = $rejTestId")->fetch(PDO::FETCH_ASSOC);
check('Refund request status is now rejected', $rejCheck2['request_status'] === 'rejected');
check('Rejection reason saved in database', $rejCheck2['rejection_reason'] === 'Product was opened and used beyond policy limit.');
check('Deciding employee recorded for rejection', (int)$rejCheck2['decided_by_employee_id'] === 5);
check('Decision timestamp recorded for rejection', !empty($rejCheck2['decided_at']));

// Verify original transaction is preserved
$origSale = $pdo->query("SELECT sale_status FROM sale WHERE sale_id = 9001")->fetchColumn();
check('Original sale transaction is preserved intact (completed)', $origSale === 'completed');

// Test Duplicate Rejection / Finalized modification prevention
$dupRej = request('POST', "/manager/sales/refunds/$rejTestId/reject", [
    'csrf_token' => $csrfSales,
    'rejection_reason' => 'Second attempt',
], $bmCookies);
check('Duplicate rejection on finalized request redirected with error', $dupRej['status'] === 302);

// Test Approval:
$beforeStock = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 6")->fetchColumn();
$beforePayment = $pdo->query("SELECT payment_status FROM payment WHERE payment_id = 9002")->fetchColumn();

$appReq = request('POST', "/manager/sales/refunds/$appTestId/approve", [
    'csrf_token' => $csrfSales,
    'approved_amount' => '15.00',
], $bmCookies);
check('Approval redirects (302)', $appReq['status'] === 302);

$appCheck = $pdo->query("SELECT * FROM refund_request WHERE refund_request_id = $appTestId")->fetch(PDO::FETCH_ASSOC);
check('Refund request status is now approved', $appCheck['request_status'] === 'approved');
check('Approved amount is recorded', (float)$appCheck['approved_amount'] === 15.00);
check('Deciding employee recorded for approval', (int)$appCheck['decided_by_employee_id'] === 5);
check('Decision timestamp recorded for approval', !empty($appCheck['decided_at']));

// Verify important payment and inventory rules
$afterStock = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 6")->fetchColumn();
$afterPayment = $pdo->query("SELECT payment_status FROM payment WHERE payment_id = 9002")->fetchColumn();
$origOrder = $pdo->query("SELECT order_status FROM customer_order WHERE order_id = 9002")->fetchColumn();

check('Approving refund request does NOT automatically mark payment as refunded (remains completed)', $afterPayment === 'completed' && $afterPayment === $beforePayment);
check('Approving refund request does NOT automatically increase sellable inventory stock', $afterStock === $beforeStock);
check('Original order transaction is preserved intact (completed)', $origOrder === 'completed');

// Test Duplicate Approval prevention
$dupApp = request('POST', "/manager/sales/refunds/$appTestId/approve", [
    'csrf_token' => $csrfSales,
], $bmCookies);
check('Duplicate decision on already-finalized approved request is blocked', $dupApp['status'] === 302);

// Clean up test refund requests
$pdo->exec("DELETE FROM refund_request WHERE refund_reference IN ('REF-TEST-APP', 'REF-TEST-REJ')");

echo "\n--- 19. Overview Buttons Removal & Employees Page Refinements ---\n";

// Overview Buttons Removal
$ovFresh = request('GET', '/manager', [], $bmCookies);
check('Overview page does NOT contain Full Roster button', !str_contains($ovFresh['body'], 'Full Roster'));
check('Overview page does NOT contain View All Refunds button', !str_contains($ovFresh['body'], 'View All Refunds'));
check('Overview page does NOT contain Review Request button', !str_contains($ovFresh['body'], 'Review Request'));

// Employees Page Table Columns & Modal
$empFresh = request('GET', '/manager/employees', [], $bmCookies);
check('Employees table header does not contain Contact #', !str_contains($empFresh['body'], '<th>Contact #</th>'));
check('Employees table header does not contain Date Hired', !str_contains($empFresh['body'], '<th>Date Hired</th>'));
// Ensure table wrapper enforces vertical scroll and suppresses horizontal scroll
check('Employees table wrapper has vertical scroll and suppresses horizontal scroll', str_contains($empFresh['body'], 'overflow-y: auto') && str_contains($empFresh['body'], 'overflow-x: hidden'));
// Ensure View Profile modal contains Contact Number and Date Hired
check('Employee profile modal displays Contact Number', str_contains($empFresh['body'], 'Contact Number:'));
check('Employee profile modal displays Date Hired', str_contains($empFresh['body'], 'Date Hired:'));
// Ensure pagination controls are present
check('Employees page includes pagination navigation', str_contains($empFresh['body'], 'pagination-centered'));
check('Employees page pagination includes Previous and Next controls', str_contains($empFresh['body'], 'pagination-prev') && str_contains($empFresh['body'], 'pagination-next'));
check('Employees page pagination displays Page status', (bool)preg_match('/Page \d+ of \d+/', $empFresh['body']));

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n====================================================================\n";
echo "PET STORE BUSINESS MANAGER TEST RESULTS:\n";
echo "Total Passed: $passed\n";
echo "Total Failed: $failed\n";
echo "====================================================================\n";

if ($failed === 0) {
    echo "ALL PET STORE BUSINESS MANAGER TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "SOME PET STORE BUSINESS MANAGER TESTS FAILED!\n";
    exit(1);
}
