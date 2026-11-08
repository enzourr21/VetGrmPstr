<?php
declare(strict_types=1);

/**
 * Grooming Services Inventory Manager Interface Verification Test Suite
 * Validates all requirements from the specification:
 * - 2-page navigation (Inventory, Employees) - strictly NO Orders page (403 Forbidden)
 * - Banner displays "INVENTORY MANAGER · GROOMING SERVICES" scoped to Unit #3
 * - Inventory page: full inventory authority except PO creation (Request Purchase Order vs + Purchase Order)
 * - No batch or expiry tracking for Grooming Services
 * - Purchase Order Request workflow: exactly 6 approved reasons, duplicate product check, BU product validation
 * - Business Manager review and approval parity
 * - Employees page: read-only directory
 * - Role exclusivity, server-side security, cross-unit isolation & role guards
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
echo "VETGRPET GROOMING SERVICES INVENTORY MANAGER TEST SUITE\n";
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

// -------------------------------------------------------------
// SECTION 1: Authentication & Navigation Structure
// -------------------------------------------------------------
echo "--- 1. Authentication & Navigation Structure ---\n";
$imCookies = staffLogin('maya.lin@staff.sample.vetgrpet', 'Staff123!');
check('Grooming Services Inventory Manager (Maya Lin) signs in successfully', !empty($imCookies));

// Login landing for Grooming IM should be /manager/inventory
$landingChk = request('GET', '/manager', [], $imCookies);
check('Accessing /manager redirects Grooming Inventory Manager to /manager/inventory (302)',
    $landingChk['status'] === 302 && str_contains($landingChk['headers'], '/manager/inventory')
);

// Access Inventory page
$invPage = request('GET', '/manager/inventory', [], $imCookies);
check('Inventory Manager can access Inventory page (200)', $invPage['status'] === 200);
check('Workspace banner is completely removed', !str_contains($invPage['body'], 'admin-workspace-banner'));
check('Secondary subnav is completely removed', !str_contains($invPage['body'], 'admin-subnav'));

// Verify EXACTLY 2 navigation tabs: Inventory and Employees in topbar
check('Top navigation contains Inventory tab', str_contains($invPage['body'], 'href="/manager/inventory"'));
check('Top navigation contains Employees tab', str_contains($invPage['body'], 'href="/manager/employees"'));
check('Top navigation strictly EXCLUDES Orders tab', !str_contains($invPage['body'], 'href="/manager/orders"'));
check('Top navigation strictly EXCLUDES Business Manager tabs: Overview, Appointments, Services, Business Settings',
    !str_contains($invPage['body'], 'href="/manager"') &&
    !str_contains($invPage['body'], 'href="/manager/appointments"') &&
    !str_contains($invPage['body'], 'href="/manager/services"') &&
    !str_contains($invPage['body'], 'href="/manager/settings"')
);

// Attempt to access Orders page (must be 403 Forbidden)
$ordersAttempt = request('GET', '/manager/orders', [], $imCookies);
check('Grooming Inventory Manager blocked from accessing /manager/orders (403 Forbidden)', $ordersAttempt['status'] === 403);

$ordersParamAttempt = request('GET', '/manager/orders?unit_id=3', [], $imCookies);
check('Grooming Inventory Manager blocked from /manager/orders?unit_id=3 (403 Forbidden)', $ordersParamAttempt['status'] === 403);

// Attempt to access BM-only routes (must be 403 Forbidden)
$apptAttempt = request('GET', '/manager/appointments', [], $imCookies);
check('Grooming Inventory Manager blocked from /manager/appointments (403 Forbidden)', $apptAttempt['status'] === 403);

$servAttempt = request('GET', '/manager/services', [], $imCookies);
check('Grooming Inventory Manager blocked from /manager/services (403 Forbidden)', $servAttempt['status'] === 403);

$settAttempt = request('GET', '/manager/settings', [], $imCookies);
check('Grooming Inventory Manager blocked from /manager/settings (403 Forbidden)', $settAttempt['status'] === 403);


// -------------------------------------------------------------
// SECTION 2: Inventory Page & Product Display (Strictly No Batch/Expiry)
// -------------------------------------------------------------
echo "\n--- 2. Inventory Page & Product Display ---\n";
check('Inventory page lists Grooming products (Oatmeal Pet Shampoo 250ml)', str_contains($invPage['body'], 'Oatmeal Pet Shampoo 250ml'));
check('Inventory page lists Deshedding Brush', str_contains($invPage['body'], 'Deshedding Brush'));
check('Inventory page lists Nail Clippers', str_contains($invPage['body'], 'Nail Clippers'));
check('Inventory page lists Leave-in Conditioner Spray', str_contains($invPage['body'], 'Leave-in Conditioner Spray'));

// Strictly NO batch or expiry tracking for Grooming
check('Grooming inventory strictly excludes Batches & Expiry column header', !str_contains($invPage['body'], '<th>Batches &amp; Expiry</th>'));
check('Grooming inventory strictly excludes Veterinary batch notes', !str_contains($invPage['body'], 'Batches &amp; Expiry tracking active'));

// Search functionality
$searchReq = request('GET', '/manager/inventory?q=Shampoo', [], $imCookies);
check('Inventory search "Shampoo" matches Oatmeal Pet Shampoo 250ml', str_contains($searchReq['body'], '<strong>Oatmeal Pet Shampoo 250ml</strong>'));
check('Inventory search "Shampoo" excludes Deshedding Brush from stock table', !str_contains($searchReq['body'], '<strong>Deshedding Brush</strong>'));


// -------------------------------------------------------------
// SECTION 3: Stock Adjustments (Increase & Decrease with Audit Logging)
// -------------------------------------------------------------
echo "\n--- 3. Stock Adjustments & Receiving ---\n";
$csrfInv = extractCsrf($invPage['body']);

// Get current stock of Grooming product (business_product_id = 2, Oatmeal Pet Shampoo)
$stockBefore = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 2")->fetchColumn();

// Stock Increase
$incReq = request('POST', '/manager/inventory/adjust', [
    'csrf_token' => $csrfInv,
    'business_product_id' => 2,
    'adjustment_type' => 'increase',
    'quantity' => 5,
    'reason' => 'New stock received',
], $imCookies);
check('Stock increase redirects (302)', $incReq['status'] === 302);

$stockAfterInc = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 2")->fetchColumn();
check('Stock on-hand increased by 5', $stockAfterInc === $stockBefore + 5);

// Verify audit logging in stock_adjustment
$latestAdj = $pdo->query("SELECT * FROM stock_adjustment WHERE business_product_id = 2 ORDER BY adjustment_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('Stock adjustment recorded in audit table with correct unit (3) and employee (66)',
    !empty($latestAdj) && (int)$latestAdj['business_unit_id'] === 3 && (int)$latestAdj['employee_id'] === 66 && (int)$latestAdj['quantity_change'] === 5
);

// Stock Decrease
$decReq = request('POST', '/manager/inventory/adjust', [
    'csrf_token' => $csrfInv,
    'business_product_id' => 2,
    'adjustment_type' => 'decrease',
    'quantity' => 2,
    'reason' => 'Damaged product',
], $imCookies);
check('Stock decrease redirects (302)', $decReq['status'] === 302);

$stockAfterDec = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 2")->fetchColumn();
check('Stock on-hand decreased by 2', $stockAfterDec === $stockAfterInc - 2);

// Revert test stock adjustments to maintain clean baseline
$pdo->prepare("UPDATE inventory SET on_hand = ? WHERE business_product_id = 2")->execute([$stockBefore]);


// -------------------------------------------------------------
// SECTION 4: Purchase Order Requests & Approved Grooming Reasons
// -------------------------------------------------------------
echo "\n--- 4. Purchase Order Requisitions & Approved Grooming Reasons ---\n";

// Buttons behavior
check('Inventory Manager sees "Request Purchase Order" button', str_contains($invPage['body'], 'Request Purchase Order'));
check('Inventory Manager does NOT see "+ Purchase Order" button', !str_contains($invPage['body'], '+ Purchase Order'));

// Attempt direct PO creation as IM (must be 403 Forbidden)
$directPo = request('POST', '/manager/inventory/purchase-orders', [
    'csrf_token' => $csrfInv,
    'supplier_name' => 'Direct Grooming Supplier',
    'contact_person' => 'Supplier Rep',
    'supplier_email' => 'supplier@grooming.local',
    'items' => [['business_product_id' => 2, 'quantity_ordered' => 10]],
], $imCookies);
check('Inventory Manager cannot create actual Purchase Orders (403 Forbidden)', $directPo['status'] === 403);

// Modal reasons verification: exactly 6 approved Grooming reasons
$approvedReasons = [
    'Low Stock',
    'Increased Usage',
    'Increased Service Demand',
    'Routine Restocking',
    'Damaged / Unusable Supplies',
    'New Service Requirements',
];
foreach ($approvedReasons as $ar) {
    check("Request modal contains approved Grooming reason: '$ar'", str_contains($invPage['body'], 'value="' . $ar . '"'));
}
check('Request modal does NOT contain "Other"', !str_contains($invPage['body'], 'value="Other"'));
check('Request modal does NOT contain Pet Store reasons (e.g. "Out of stock emergency")', !str_contains($invPage['body'], 'Out of stock emergency'));

// Server-side validation: Invalid reason rejection
$invReasonReq = request('POST', '/manager/inventory/purchase-order-requests', [
    'csrf_token' => $csrfInv,
    'reason' => 'Arbitrary Unapproved Reason',
    'priority' => 'Medium',
    'items' => [['business_product_id' => 2, 'quantity_requested' => 10]],
], $imCookies);
$invReasonPage = request('GET', '/manager/inventory?tab=orders', [], $imCookies);
check('Invalid request reason is rejected server-side', str_contains($invReasonPage['body'], 'Invalid reason for Grooming Services'));

// Server-side validation: Duplicate products rejection
$dupReq = request('POST', '/manager/inventory/purchase-order-requests', [
    'csrf_token' => $csrfInv,
    'reason' => 'Low Stock',
    'priority' => 'Medium',
    'items' => [
        ['business_product_id' => 2, 'quantity_requested' => 5],
        ['business_product_id' => 2, 'quantity_requested' => 10],
    ],
], $imCookies);
$dupPage = request('GET', '/manager/inventory?tab=orders', [], $imCookies);
check('Duplicate product entries are rejected server-side', str_contains($dupPage['body'], 'Duplicate product entries are not permitted'));

// Server-side validation: Cross-unit product rejection (product 1 is Pet Store)
$crossProdReq = request('POST', '/manager/inventory/purchase-order-requests', [
    'csrf_token' => $csrfInv,
    'reason' => 'Low Stock',
    'priority' => 'Medium',
    'items' => [['business_product_id' => 1, 'quantity_requested' => 10]],
], $imCookies);
$crossProdPage = request('GET', '/manager/inventory?tab=orders', [], $imCookies);
check('Requested products from another business unit are rejected server-side', str_contains($crossProdPage['body'], 'do not belong to the selected business unit'));

// Server-side validation: Zero quantity rejection
$zeroQtyReq = request('POST', '/manager/inventory/purchase-order-requests', [
    'csrf_token' => $csrfInv,
    'reason' => 'Low Stock',
    'priority' => 'Medium',
    'items' => [['business_product_id' => 2, 'quantity_requested' => 0]],
], $imCookies);
$zeroQtyPage = request('GET', '/manager/inventory?tab=orders', [], $imCookies);
check('Zero or empty requested quantity is rejected server-side', str_contains($zeroQtyPage['body'], 'greater than zero'));

// Valid Grooming POR submission
$stockShampooBefore = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 2")->fetchColumn();
$poCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM purchase_order WHERE business_unit_id = 3")->fetchColumn();

$validPor = request('POST', '/manager/inventory/purchase-order-requests', [
    'csrf_token' => $csrfInv,
    'reason' => 'Increased Service Demand',
    'priority' => 'High',
    'needed_by_date' => date('Y-m-d', strtotime('+3 days')),
    'notes' => 'Grooming weekend package promotion restock',
    'items' => [
        ['business_product_id' => 2, 'quantity_requested' => 25, 'notes' => 'Oatmeal Shampoo'],
        ['business_product_id' => 4, 'quantity_requested' => 10, 'notes' => 'Clippers backup'],
    ],
], $imCookies);
check('Valid Grooming PO Request submission redirects (302)', $validPor['status'] === 302);

$stockShampooAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE business_product_id = 2")->fetchColumn();
$poCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM purchase_order WHERE business_unit_id = 3")->fetchColumn();
check('PO Request submission does NOT alter inventory stock', $stockShampooBefore === $stockShampooAfter);
check('PO Request submission does NOT create an actual Purchase Order', $poCountBefore === $poCountAfter);

// Verify DB record
$savedPor = $pdo->query("SELECT * FROM purchase_order_request WHERE business_unit_id = 3 ORDER BY request_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('PO Request saved with system-generated POR number', !empty($savedPor) && str_starts_with($savedPor['request_number'], 'POR-'));
check('PO Request records business_unit_id = 3', (int)($savedPor['business_unit_id'] ?? 0) === 3);
check('PO Request records requester employee_id = 66 (Maya Lin)', (int)($savedPor['requested_by_employee_id'] ?? 0) === 66);
check('PO Request records reason "Increased Service Demand"', ($savedPor['reason'] ?? '') === 'Increased Service Demand');
check('PO Request initial status is Pending', ($savedPor['request_status'] ?? '') === 'Pending');

// Verify items saved
$savedItems = $pdo->query("SELECT * FROM purchase_order_request_item WHERE request_id = " . (int)$savedPor['request_id'])->fetchAll(PDO::FETCH_ASSOC);
check('PO Request saved both line items (Shampoo & Clippers)', count($savedItems) === 2);

// Inventory Manager tracking view
$invOrdersTab = request('GET', '/manager/inventory?tab=orders', [], $imCookies);
check('Inventory Manager can view submitted POR in tracking list', str_contains($invOrdersTab['body'], $savedPor['request_number']));
check('Inventory Manager does NOT have review/approval controls',
    !str_contains($invOrdersTab['body'], 'data-modal-open="review-por-' . $savedPor['request_id'] . '"')
);

// Inventory Manager cannot review request (must be 403 Forbidden)
$imReviewAttempt = request('POST', "/manager/inventory/purchase-order-requests/{$savedPor['request_id']}/review", [
    'csrf_token' => $csrfInv,
    'decision' => 'approve',
], $imCookies);
check('Inventory Manager cannot review or approve PO requests (403 Forbidden)', $imReviewAttempt['status'] === 403);


// -------------------------------------------------------------
// SECTION 5: Grooming Business Manager Parity & Review
// -------------------------------------------------------------
echo "\n--- 5. Grooming Business Manager Parity & Review ---\n";
$bmCookies = staffLogin('marcus.chen@staff.sample.vetgrpet', 'Staff123!');
check('Grooming Business Manager (Marcus Chen) signs in successfully', !empty($bmCookies));

$bmInvPage = request('GET', '/manager/inventory?tab=orders', [], $bmCookies);
check('Business Manager sees "+ Purchase Order" button', str_contains($bmInvPage['body'], '+ Purchase Order'));
check('Business Manager sees submitted PO Request with Review action',
    str_contains($bmInvPage['body'], $savedPor['request_number']) && str_contains($bmInvPage['body'], 'Review')
);

$bmCsrf = extractCsrf($bmInvPage['body']);
$bmReview = request('POST', "/manager/inventory/purchase-order-requests/{$savedPor['request_id']}/review", [
    'csrf_token' => $bmCsrf,
    'decision' => 'approve',
    'review_notes' => 'Approved for Grooming vendor delivery.',
], $bmCookies);
check('Business Manager can approve PO request (302 redirect)', $bmReview['status'] === 302);

$approvedPor = $pdo->query("SELECT * FROM purchase_order_request WHERE request_id = {$savedPor['request_id']}")->fetch(PDO::FETCH_ASSOC);
check('PO Request status updated to "Approved"', ($approvedPor['request_status'] ?? '') === 'Approved');
check('PO Request records reviewer employee ID = 2 (Marcus Chen)', (int)($approvedPor['reviewed_by_employee_id'] ?? 0) === 2);


// -------------------------------------------------------------
// SECTION 6: Employees Page (Read-Only Directory)
// -------------------------------------------------------------
echo "\n--- 6. Employees Page (Read-Only Directory) ---\n";
$empPage = request('GET', '/manager/employees', [], $imCookies);
check('Grooming Inventory Manager can access Employees page (200)', $empPage['status'] === 200);
check('Employees page displays Marcus Chen', str_contains($empPage['body'], 'Marcus Chen'));
check('Employees page displays Owen Bennett', str_contains($empPage['body'], 'Owen Bennett'));
check('Employees page displays Caleb Thorne', str_contains($empPage['body'], 'Caleb Thorne'));
check('Employees page displays Maya Lin', str_contains($empPage['body'], 'Maya Lin'));

// Read-only checks
check('Employees page does not contain employee creation forms', !str_contains($empPage['body'], 'action="/sysadmin/employees'));
check('Employees page does not contain invite or password reset buttons', !str_contains($empPage['body'], 'Send Invite Link'));
check('Employees page does not expose salary information', !str_contains($empPage['body'], 'salary'));

// Filtering on employees page
$filterPos = request('GET', '/manager/employees?position=Master+Stylist', [], $imCookies);
check('Employees position filter for "Master Stylist" matches Owen Bennett', str_contains($filterPos['body'], 'Owen Bennett'));
check('Employees position filter for "Master Stylist" excludes Marcus Chen', !str_contains($filterPos['body'], 'Marcus Chen'));


// -------------------------------------------------------------
// SECTION 7: Cross-Unit Isolation & Security Guards
// -------------------------------------------------------------
echo "\n--- 7. Cross-Unit Isolation & Security Guards ---\n";

// Cross-unit inventory guards
$crossInvPS = request('GET', '/manager/inventory?unit_id=1', [], $imCookies);
check('Grooming Inventory Manager blocked from Pet Store inventory (403 Forbidden)', $crossInvPS['status'] === 403);

$crossInvVC = request('GET', '/manager/inventory?unit_id=2', [], $imCookies);
check('Grooming Inventory Manager blocked from Vet Clinic inventory (403 Forbidden)', $crossInvVC['status'] === 403);

// Cross-unit employee guards
$crossEmpPS = request('GET', '/manager/employees?unit_id=1', [], $imCookies);
check('Grooming Inventory Manager blocked from Pet Store employees (403 Forbidden)', $crossEmpPS['status'] === 403);

$crossEmpVC = request('GET', '/manager/employees?unit_id=2', [], $imCookies);
check('Grooming Inventory Manager blocked from Vet Clinic employees (403 Forbidden)', $crossEmpVC['status'] === 403);

// Stylist access block
$stylistCookies = staffLogin('owen.bennett@staff.sample.vetgrpet', 'Staff123!');
$stylistInv = request('GET', '/manager/inventory', [], $stylistCookies);
check('Stylist (Owen Bennett) blocked from Inventory Management (403 Forbidden)', $stylistInv['status'] === 403);

// SysAdmin inventory access (read-only, cannot adjust stock or create POs)
$sysCookies = staffLogin('sysadmin.grooming@vetgrpet.local', 'SysAdminGroom!2026');
$sysInv = request('GET', '/sysadmin/activity', [], $sysCookies);
check('SysAdmin can access System Activity (200)', $sysInv['status'] === 200);

$sysAdjAttempt = request('POST', '/manager/inventory/adjust', [
    'csrf_token' => $csrfInv,
    'business_product_id' => 2,
    'adjustment_type' => 'increase',
    'quantity' => 1,
    'reason' => 'Unauthorized adjustment',
], $sysCookies);
check('SysAdmin blocked from operational stock adjustment (403 Forbidden)', $sysAdjAttempt['status'] === 403);

// Anonymous visitor guard
$anonInv = request('GET', '/manager/inventory');
check('Anonymous visitor redirected from /manager/inventory (302)', $anonInv['status'] === 302);


// -------------------------------------------------------------
// SECTION 8: Super Admin Interface Switcher
// -------------------------------------------------------------
echo "\n--- 8. Super Admin Interface Switcher ---\n";
$saCookies = staffLogin('superadmin@vetgrpet.local', 'SuperAdmin!2026');
check('Super Admin signs in successfully', !empty($saCookies));

// Switch to Grooming Inventory Manager
$switchReq = request('GET', '/switch-interface?target=inventory-manager&unit_id=3', [], $saCookies);
check('Super Admin switching to Grooming Inventory Manager redirects to /manager/inventory?unit_id=3 (302)',
    $switchReq['status'] === 302 && str_contains($switchReq['headers'], '/manager/inventory?unit_id=3')
);

$saGroomingInv = request('GET', '/manager/inventory?unit_id=3', [], $switchReq['cookies']);
check('Super Admin in Grooming Inventory Manager mode accesses HTTP 200', $saGroomingInv['status'] === 200);
check('Super Admin layout has NO workspace banner', !str_contains($saGroomingInv['body'], 'class="admin-workspace-banner"'));
check('Super Admin layout has NO admin-subnav', !str_contains($saGroomingInv['body'], 'class="admin-subnav"'));
check('Super Admin sees exactly 2 tabs for Grooming IM (Inventory, Employees)',
    str_contains($saGroomingInv['body'], 'href="/manager/inventory?unit_id=3"') &&
    str_contains($saGroomingInv['body'], 'href="/manager/employees?unit_id=3"') &&
    !str_contains($saGroomingInv['body'], 'href="/manager/orders')
);

echo "\n====================================================================\n";
echo "GROOMING SERVICES INVENTORY MANAGER TEST RESULTS:\n";
echo "Total Passed: $passed\n";
echo "Total Failed: $failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    echo "SOME GROOMING INVENTORY MANAGER TESTS FAILED!\n";
    exit(1);
} else {
    echo "ALL GROOMING INVENTORY MANAGER TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
}
