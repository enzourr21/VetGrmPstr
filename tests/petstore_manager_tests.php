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
