<?php
declare(strict_types=1);

/**
 * Pet Store Inventory Manager Interface Verification Test Suite
 * Validates all requirements from the specification:
 * - 3-page navigation (Orders, Inventory, Employees)
 * - Orders page: strictly read-only monitoring, filters, search, modal details, privacy for delivery failure
 * - Inventory page: full inventory authority except PO creation (Request Purchase Order vs + Purchase Order)
 * - Purchase Order Request workflow: submission, no stock changes, tracking, BM review & approval
 * - Employees page: read-only directory
 * - Role-based server-side access control & business unit isolation
 */

require_once __DIR__ . '/../app/Core/Core.php';
use App\Core\Database;

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
echo "VETGRPET PET STORE INVENTORY MANAGER TEST SUITE\n";
echo "====================================================================\n\n";

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

// Ensure test data exists
$pdo->exec("INSERT INTO customer_order (order_id, customer_id, business_unit_id, branch_id, order_date, fulfillment_method, order_status, total_amount)
            VALUES (9201, 1, 1, 1, NOW(), 'pickup', 'ready_for_pickup', 35.50)
            ON DUPLICATE KEY UPDATE fulfillment_method = 'pickup', order_status = 'ready_for_pickup', total_amount = 35.50");
$pdo->exec("INSERT INTO order_item (order_id, business_product_id, quantity, unit_price)
            VALUES (9201, 1, 1, 35.50)
            ON DUPLICATE KEY UPDATE quantity = 1");

$pdo->exec("INSERT INTO customer_order (order_id, customer_id, business_unit_id, branch_id, order_date, fulfillment_method, order_status, total_amount, delivery_address_line1, delivery_city, delivery_failure_reason)
            VALUES (9202, 1, 1, 1, NOW(), 'delivery', 'delivery_failed', 62.00, '789 Pine Rd', 'Taguig', 'CONFIDENTIAL: Guard refused entry without prior pass')
            ON DUPLICATE KEY UPDATE fulfillment_method = 'delivery', order_status = 'delivery_failed', total_amount = 62.00, delivery_failure_reason = 'CONFIDENTIAL: Guard refused entry without prior pass'");
$pdo->exec("INSERT INTO order_item (order_id, business_product_id, quantity, unit_price)
            VALUES (9202, 2, 2, 31.00)
            ON DUPLICATE KEY UPDATE quantity = 2");

// -------------------------------------------------------------
// SECTION 1: Authentication & Navigation Structure
// -------------------------------------------------------------
echo "--- 1. Authentication & Navigation Structure ---\n";
$imCookies = staffLogin('ja.ompoy@staff.sample.vetgrpet', 'Staff123!');
check('Pet Store Inventory Manager (Janricson Ompoy) signs in successfully', !empty($imCookies));

$ordPage = request('GET', '/manager/orders', [], $imCookies);
check('Inventory Manager can access Orders page (200)', $ordPage['status'] === 200);
check('Workspace banner displays "INVENTORY MANAGER · PET STORE"', str_contains($ordPage['body'], 'INVENTORY MANAGER · PET STORE'));
check('Subnav contains exactly 3 navigation pages: Orders, Inventory, Employees',
    str_contains($ordPage['body'], 'href="/manager/orders"') &&
    str_contains($ordPage['body'], 'href="/manager/inventory"') &&
    str_contains($ordPage['body'], 'href="/manager/employees"')
);
check('Subnav excludes Business Manager pages: Overview, Sales, Products, Business Settings',
    !str_contains($ordPage['body'], 'href="/manager"') &&
    !str_contains($ordPage['body'], 'href="/manager/sales"') &&
    !str_contains($ordPage['body'], 'href="/manager/products"') &&
    !str_contains($ordPage['body'], 'href="/manager/settings"')
);

// -------------------------------------------------------------
// SECTION 2: Orders Page Monitoring (Strictly Read-Only)
// -------------------------------------------------------------
echo "\n--- 2. Orders Page Monitoring & Read-Only Checks ---\n";
check('Orders page renders pickup order #ORD-9201', str_contains($ordPage['body'], '#ORD-9201'));
check('Orders page renders delivery failed order #ORD-9202', str_contains($ordPage['body'], '#ORD-9202'));
check('Orders page displays Delivery Failed status badge', str_contains($ordPage['body'], 'Delivery Failed'));
check('Confidential delivery failure reasons are strictly redacted from Inventory Manager',
    !str_contains($ordPage['body'], 'Guard refused entry') && !str_contains($ordPage['body'], 'CONFIDENTIAL')
);

// Filtering tests
$pickupFilter = request('GET', '/manager/orders?type=pickup', [], $imCookies);
check('Fulfillment filter type=pickup includes #ORD-9201', str_contains($pickupFilter['body'], '#ORD-9201'));
check('Fulfillment filter type=pickup excludes #ORD-9202', !str_contains($pickupFilter['body'], '#ORD-9202'));

$delivFilter = request('GET', '/manager/orders?type=delivery', [], $imCookies);
check('Fulfillment filter type=delivery includes #ORD-9202', str_contains($delivFilter['body'], '#ORD-9202'));
check('Fulfillment filter type=delivery excludes #ORD-9201', !str_contains($delivFilter['body'], '#ORD-9201'));

$statusFilter = request('GET', '/manager/orders?status=delivery_failed', [], $imCookies);
check('Status filter status=delivery_failed includes #ORD-9202', str_contains($statusFilter['body'], '#ORD-9202'));
check('Status filter status=delivery_failed excludes #ORD-9201', !str_contains($statusFilter['body'], '#ORD-9201'));

$searchFilter = request('GET', '/manager/orders?q=9201', [], $imCookies);
check('Search query "9201" matches order #ORD-9201', str_contains($searchFilter['body'], '#ORD-9201'));
check('Search query "9201" excludes order #ORD-9202', !str_contains($searchFilter['body'], '#ORD-9202'));

// Strict read-only verification: no order editing, canceling, dispatching, or status changing controls
check('Orders page does not contain order status modification forms', !str_contains($ordPage['body'], 'action="/manager/orders/'));
check('Orders page does not contain cancel buttons', !str_contains($ordPage['body'], 'Cancel Order'));
check('Orders page does not contain delivery dispatch buttons', !str_contains($ordPage['body'], 'Dispatch Delivery'));
check('Orders page does not contain refund buttons', !str_contains($ordPage['body'], 'Process Refund'));

// -------------------------------------------------------------
// SECTION 3: Inventory Page & Purchase Order Request Permissions
// -------------------------------------------------------------
echo "\n--- 3. Inventory Authority & Purchase Order Requisitions ---\n";
$invPage = request('GET', '/manager/inventory', [], $imCookies);
check('Inventory Manager can access Inventory page (200)', $invPage['status'] === 200);
check('Inventory Manager sees "Request Purchase Order" button', str_contains($invPage['body'], 'Request Purchase Order'));
check('Inventory Manager does NOT see "+ Purchase Order" button', !str_contains($invPage['body'], '+ Purchase Order'));

$csrfInv = extractCsrf($invPage['body']);

// Attempt direct actual PO creation as IM (must be 403 Forbidden)
$directPo = request('POST', '/manager/inventory/purchase-orders', [
    'csrf_token' => $csrfInv,
    'supplier_name' => 'Direct PO Attempt',
    'contact_person' => 'Forbidden Supplier',
    'supplier_email' => 'forbidden@supplier.com',
    'items' => [['business_product_id' => 1, 'quantity_ordered' => 50]],
], $imCookies);
check('Inventory Manager cannot create actual Purchase Orders (403 Forbidden)', $directPo['status'] === 403);

// Submit Purchase Order Request
$stockBefore = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
$poCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM purchase_order")->fetchColumn();

$poReq = request('POST', '/manager/inventory/purchase-order-requests', [
    'csrf_token' => $csrfInv,
    'reason' => 'Low stock replenishment',
    'priority' => 'High',
    'needed_by_date' => date('Y-m-d', strtotime('+5 days')),
    'notes' => 'Seasonal restock for holiday weekend',
    'items' => [
        ['business_product_id' => 1, 'quantity_requested' => 30, 'notes' => 'Primary sku'],
    ],
], $imCookies);
check('PO Request submission redirects (302)', $poReq['status'] === 302);

$stockAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 1")->fetchColumn();
$poCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM purchase_order")->fetchColumn();
check('PO Request submission does NOT increase or alter inventory stock', $stockBefore === $stockAfter);
check('PO Request submission does NOT create an actual Purchase Order', $poCountBefore === $poCountAfter);

// Verify request saved in DB
$latestPor = $pdo->query("SELECT * FROM purchase_order_request ORDER BY request_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('PO Request saved with system-generated number (POR-...)', !empty($latestPor) && str_starts_with($latestPor['request_number'], 'POR-'));
check('PO Request saved with initial Pending status', ($latestPor['request_status'] ?? '') === 'Pending');

// Verify IM can track request
$invOrdersTab = request('GET', '/manager/inventory?tab=orders', [], $imCookies);
check('Inventory Manager can view submitted request in tracking list', str_contains($invOrdersTab['body'], $latestPor['request_number']));
check('Inventory Manager does NOT have review/approval controls',
    !str_contains($invOrdersTab['body'], 'data-modal-open="review-por-' . $latestPor['request_id'] . '"') &&
    !str_contains($invOrdersTab['body'], '/purchase-order-requests/' . $latestPor['request_id'] . '/review')
);

// Attempt direct review as IM (must be 403 Forbidden)
$imReviewAttempt = request('POST', "/manager/inventory/purchase-order-requests/{$latestPor['request_id']}/review", [
    'csrf_token' => $csrfInv,
    'decision' => 'approve',
], $imCookies);
check('Inventory Manager cannot review or approve PO requests (403 Forbidden)', $imReviewAttempt['status'] === 403);

// -------------------------------------------------------------
// SECTION 4: Employees Page (Read-Only)
// -------------------------------------------------------------
echo "\n--- 4. Employees Page (Read-Only Directory) ---\n";
$empPage = request('GET', '/manager/employees', [], $imCookies);
check('Inventory Manager can access Employees page (200)', $empPage['status'] === 200);
check('Employees page displays unit roster including David Reyes & Janricson Ompoy',
    str_contains($empPage['body'], 'David Reyes') && str_contains($empPage['body'], 'Janricson Ompoy')
);
check('Employees page is read-only (no account creation or role editing)',
    !str_contains($empPage['body'], 'action="/sysadmin/employees') &&
    !str_contains($empPage['body'], 'Send Invite Link')
);

// -------------------------------------------------------------
// SECTION 5: Business Manager Reviews Requisition
// -------------------------------------------------------------
echo "\n--- 5. Business Manager Parity & Requisition Review ---\n";
$bmCookies = staffLogin('david.reyes@staff.sample.vetgrpet', 'Staff123!');
$bmInvPage = request('GET', '/manager/inventory?tab=orders', [], $bmCookies);
check('Business Manager sees "+ Purchase Order" button', str_contains($bmInvPage['body'], '+ Purchase Order'));
check('Business Manager sees submitted PO Request with Review action',
    str_contains($bmInvPage['body'], $latestPor['request_number']) && str_contains($bmInvPage['body'], 'Review')
);

$bmCsrf = extractCsrf($bmInvPage['body']);
$bmReview = request('POST', "/manager/inventory/purchase-order-requests/{$latestPor['request_id']}/review", [
    'csrf_token' => $bmCsrf,
    'decision' => 'approve',
    'review_notes' => 'Approved for weekly supplier batch.',
], $bmCookies);
check('Business Manager can approve PO request (302 redirect)', $bmReview['status'] === 302);

$approvedPor = $pdo->query("SELECT * FROM purchase_order_request WHERE request_id = {$latestPor['request_id']}")->fetch(PDO::FETCH_ASSOC);
check('PO Request status updated to "Approved"', ($approvedPor['request_status'] ?? '') === 'Approved');
check('PO Request records reviewer employee ID', !empty($approvedPor['reviewed_by_employee_id']));

// -------------------------------------------------------------
// SECTION 6: Server-Side Security, Route Guards & Isolation
// -------------------------------------------------------------
echo "\n--- 6. Server-Side Security & Business Unit Isolation ---\n";

// Overview redirect
$mgrOverview = request('GET', '/manager', [], $imCookies);
check('Accessing /manager redirects Inventory Manager to /manager/orders (302)',
    $mgrOverview['status'] === 302 && str_contains($mgrOverview['headers'], '/manager/orders')
);

// BM-only route guards
$salesGuard = request('GET', '/manager/sales', [], $imCookies);
check('Inventory Manager blocked from /manager/sales (403 Forbidden)', $salesGuard['status'] === 403);

$prodGuard = request('GET', '/manager/products', [], $imCookies);
check('Inventory Manager blocked from /manager/products (403 Forbidden)', $prodGuard['status'] === 403);

$settGuard = request('GET', '/manager/settings', [], $imCookies);
check('Inventory Manager blocked from /manager/settings (403 Forbidden)', $settGuard['status'] === 403);

// Cross-unit isolation guards
$crossOrders = request('GET', '/manager/orders?unit_id=2', [], $imCookies);
check('Inventory Manager blocked from accessing Vet Clinic orders (403 Forbidden)', $crossOrders['status'] === 403);

$crossInv = request('GET', '/manager/inventory?unit_id=2', [], $imCookies);
check('Inventory Manager blocked from accessing Vet Clinic inventory (403 Forbidden)', $crossInv['status'] === 403);

$crossEmp = request('GET', '/manager/employees?unit_id=2', [], $imCookies);
check('Inventory Manager blocked from accessing Vet Clinic employees (403 Forbidden)', $crossEmp['status'] === 403);

// Unauthenticated & Customer guards
$anonOrders = request('GET', '/manager/orders');
check('Anonymous visitor redirected from /manager/orders (302)', $anonOrders['status'] === 302);

$custPw = password_hash('Customer123!', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO user_account (email, password_hash, account_status) VALUES ('test_im_cust@vetgrpet.local', ?, 'active') ON DUPLICATE KEY UPDATE password_hash = ?")->execute([$custPw, $custPw]);
$cuid = (int)$pdo->query("SELECT user_account_id FROM user_account WHERE email = 'test_im_cust@vetgrpet.local'")->fetchColumn();
$pdo->prepare("INSERT INTO customer (user_account_id, first_name, last_name, customer_status) VALUES (?, 'Test', 'Customer', 'active') ON DUPLICATE KEY UPDATE customer_status = 'active'")->execute([$cuid]);

function custLogin(string $email, string $password): array
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

$custCookies = custLogin('test_im_cust@vetgrpet.local', 'Customer123!');
$custOrders = request('GET', '/manager/orders', [], $custCookies);
check('Customer session blocked from /manager/orders (403 Forbidden)', $custOrders['status'] === 403);

echo "\n====================================================================\n";
echo "PET STORE INVENTORY MANAGER TEST RESULTS:\n";
echo "Total Passed: $passed\n";
echo "Total Failed: $failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    echo "SOME INVENTORY MANAGER TESTS FAILED!\n";
    exit(1);
} else {
    echo "ALL INVENTORY MANAGER TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
}
