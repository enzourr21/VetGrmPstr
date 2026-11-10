<?php

// Comprehensive Automated Verification Suite for:
// - Pet Store Receptionist Sales Refinements (Walk-in vs Registered Customer, Product Search, Pagination)
// - Sample Registered Customers with Registered Pets
// - Grooming Receptionist Appointments Calendar Refinements

require_once __DIR__ . '/../app/bootstrap.php';

$baseUrl = 'http://127.0.0.1:8080';
$cookieFile = __DIR__ . '/cookie_refinements_test.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function req($url, $method = 'GET', $data = null) {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    return ['code' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function extractCsrfToken($html) {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] $message\n";
        $passCount++;
    } else {
        echo "[FAIL] $message\n";
        $failCount++;
    }
}

echo "========================================================\n";
echo "VETGRPET — Sales, Customers & Calendar Refinements Test\n";
echo "========================================================\n\n";

$db = \App\Core\Database::pdo();

// --- SECTION 1: Sample Customer and Pet Data Verification ---
echo "--- SECTION 1: Database Sample Registered Customers & Pets ---\n";
$customers = $db->query("SELECT * FROM customer WHERE phone IN ('555-0101', '555-0102', '555-0103', '555-0104', '555-0105') ORDER BY customer_id ASC")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($customers) >= 5, "Sample registered customers found in database (Found: " . count($customers) . ")");

$names = array_map(function($c) { return $c['first_name'] . ' ' . $c['last_name']; }, $customers);
assertTest(in_array('Oliver Vance', $names), "Sample customer 'Oliver Vance' exists");
assertTest(in_array('Sophia Martinez', $names), "Sample customer 'Sophia Martinez' exists");
assertTest(in_array('Liam Gallagher', $names), "Sample customer 'Liam Gallagher' exists");
assertTest(in_array('Emma Watson', $names), "Sample customer 'Emma Watson' exists");
assertTest(in_array('Noah Bennett', $names), "Sample customer 'Noah Bennett' exists");

$pets = $db->query("SELECT p.*, c.first_name, c.last_name FROM pet p JOIN customer c ON p.customer_id = c.customer_id WHERE c.phone IN ('555-0101', '555-0102', '555-0103', '555-0104', '555-0105')")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($pets) >= 7, "Sample registered pets linked to synthetic customers found (Found: " . count($pets) . ")");
$petNames = array_column($pets, 'pet_name');
assertTest(in_array('Barnaby', $petNames), "Pet 'Barnaby' (Golden Retriever) registered for Oliver Vance");
assertTest(in_array('Whiskers', $petNames), "Pet 'Whiskers' registered for Sophia Martinez");
assertTest(in_array('Bella', $petNames), "Pet 'Bella' registered for Sophia Martinez");
assertTest(in_array('Milo', $petNames), "Pet 'Milo' registered for Liam Gallagher");
assertTest(in_array('Pip', $petNames), "Pet 'Pip' registered for Emma Watson");
assertTest(in_array('Daisy', $petNames), "Pet 'Daisy' registered for Emma Watson");
assertTest(in_array('Rocky', $petNames), "Pet 'Rocky' registered for Noah Bennett");

// --- SECTION 2: Authentication & Navigation ---
echo "\n--- SECTION 2: Authentication & Pet Store Receptionist Navigation ---\n";
// Get login form and CSRF token
$loginForm = req("$baseUrl/login");
$csrf = extractCsrfToken($loginForm['body']);

// Sign in as Super Admin via /demo/super-admin
$loginResp = req("$baseUrl/demo/super-admin", 'POST', ['csrf_token' => $csrf]);
assertTest($loginResp['code'] === 302, "Super Admin login successful (HTTP 302)");

// Switch to Pet Store Receptionist
$switchResp = req("$baseUrl/switch-interface?target=petstore-receptionist");
assertTest($switchResp['code'] === 302, "Switch to Pet Store Receptionist redirects (HTTP 302)");

$salesResp = req("$baseUrl/petstore/receptionist/sales");
assertTest($salesResp['code'] === 200, "Pet Store Receptionist Sales page loaded (HTTP 200)");

// --- SECTION 3: Pet Store Receptionist Modal UI Elements ---
echo "\n--- SECTION 3: New Sale Modal Customer Type & Product Search UI ---\n";
$salesHtml = $salesResp['body'];

assertTest(strpos($salesHtml, 'id="type-walkin"') !== false, "Walk-in Customer radio option found (#type-walkin)");
assertTest(strpos($salesHtml, 'id="type-registered"') !== false, "Registered Customer radio option found (#type-registered)");
assertTest(strpos($salesHtml, 'Walk-in Customer') !== false, "Label 'Walk-in Customer' found");
assertTest(strpos($salesHtml, 'Registered Customer') !== false, "Label 'Registered Customer' found");
assertTest(strpos($salesHtml, 'id="sale-customer-id"') !== false, "Read-only customer ID field present (#sale-customer-id)");
assertTest(strpos($salesHtml, 'id="product-search-input"') !== false, "Searchable product selection input present (#product-search-input)");
assertTest(strpos($salesHtml, 'id="product-suggestions"') !== false, "Searchable product suggestions container present (#product-suggestions)");
assertTest(strpos($salesHtml, 'Search Registered Customers') === false, "Old 'Search Registered Customers' input removed");

// --- SECTION 4: Live Customer and Product Search Endpoints ---
echo "\n--- SECTION 4: Live Customer and Product Search AJAX Endpoints ---\n";
// Customer search endpoint in Pet Store Receptionist
$custSearchResp = req("$baseUrl/petstore/receptionist/customers/search?q=Oliver");
assertTest($custSearchResp['code'] === 200, "Customer search returns HTTP 200");
$custData = json_decode($custSearchResp['body'], true);
assertTest(isset($custData['customers']) && count($custData['customers']) > 0, "Customer search returns non-empty array");
$firstCust = $custData['customers'][0];
assertTest(preg_match('/^Oliver Vance #\d+$/', $firstCust['label']), "Customer search format strictly follows 'Customer Name #CustomerID' (Got: {$firstCust['label']})");
assertTest(isset($firstCust['customer_id']) && $firstCust['customer_id'] > 0, "Customer search result contains valid customer_id");

// Product search endpoint in Pet Store Receptionist
$prodSearchResp = req("$baseUrl/petstore/receptionist/products/search?q=Shampoo");
assertTest($prodSearchResp['code'] === 200, "Product search returns HTTP 200");
$prodData = json_decode($prodSearchResp['body'], true);
assertTest(isset($prodData['products']) && count($prodData['products']) > 0, "Product search returns matching products (Found " . count($prodData['products']) . ")");
$firstProd = $prodData['products'][0];
assertTest(isset($firstProd['business_product_id']) && isset($firstProd['product_name']) && isset($firstProd['selling_price']) && isset($firstProd['available_stock']), "Product search item contains business_product_id, product_name, selling_price, and available_stock");

// Test product search with empty query returns top active products
$prodAllResp = req("$baseUrl/petstore/receptionist/products/search?q=");
$prodAllData = json_decode($prodAllResp['body'], true);
assertTest(isset($prodAllData['products']) && count($prodAllData['products']) > 0, "Empty product search returns top available products");

// --- SECTION 5: Recent Sales Server-Side Pagination & Controls ---
echo "\n--- SECTION 5: Recent Sales Server-Side Pagination & Controls ---\n";
// Check page 1
assertTest(strpos($salesHtml, 'View More') === false, "Recent Sales 'View More' link removed");
assertTest(strpos($salesHtml, 'id="sales-prev-btn"') !== false, "Previous page pagination control present (#sales-prev-btn)");
assertTest(strpos($salesHtml, 'id="sales-next-btn"') !== false, "Next page pagination control present (#sales-next-btn)");
assertTest(preg_match('/Page 1 of \d+/', $salesHtml), "Pagination displays 'Page 1 of X'");

// --- SECTION 6: Walk-in Sale Transaction Execution ---
echo "\n--- SECTION 6: Walk-in Sale Transaction Execution ---\n";
$csrfToken = extractCsrfToken($salesHtml);
assertTest(!empty($csrfToken), "CSRF token extracted from sales page ($csrfToken)");

// Count customers before walk-in sale
$custCountBefore = (int)$db->query("SELECT COUNT(*) FROM customer")->fetchColumn();

// Get a valid Pet Store product with stock
$sampleProd = $db->query("SELECT bp.business_product_id, bp.selling_price, p.product_name, COALESCE(i.on_hand, 0) as stock FROM business_product bp JOIN product p ON p.product_id = bp.product_id LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id WHERE bp.business_unit_id = 1 AND bp.product_status = 'active' AND COALESCE(i.on_hand, 0) >= 5 LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$walkinPayload = [
    'csrf_token' => $csrfToken,
    'customer_type' => 'walkin',
    'customer_name' => 'Walkin Guest User',
    'customer_id' => '', // Must remain NULL
    'payment_method' => 'Cash',
    'product_id' => [$sampleProd['business_product_id']],
    'quantity' => [1]
];

$walkinSaleResp = req("$baseUrl/petstore/receptionist/sales", 'POST', $walkinPayload);
assertTest($walkinSaleResp['code'] === 302, "Walk-in sale submitted successfully (HTTP 302)");

// Verify sale record in DB
$latestSale = $db->query("SELECT * FROM sale ORDER BY sale_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest($latestSale !== false, "Sale record created in database (Sale #{$latestSale['sale_id']})");
assertTest($latestSale['customer_id'] === null, "Walk-in sale has customer_id strictly NULL in database");

$custCountAfter = (int)$db->query("SELECT COUNT(*) FROM customer")->fetchColumn();
assertTest($custCountAfter === $custCountBefore, "No permanent customer account created for walk-in customer (Count remained $custCountAfter)");

// --- SECTION 7: Registered Sale Transaction Execution ---
echo "\n--- SECTION 7: Registered Sale Transaction Execution ---\n";
// Re-fetch sales page for fresh CSRF token
$freshSales = req("$baseUrl/petstore/receptionist/sales");
$csrfToken = extractCsrfToken($freshSales['body']);

// Attempt to submit registered sale WITHOUT selecting a valid customer (empty customer_id)
$invalidRegisteredPayload = [
    'csrf_token' => $csrfToken,
    'customer_type' => 'registered',
    'customer_name' => 'Some Typed Name',
    'customer_id' => '', // Missing!
    'payment_method' => 'Cash',
    'product_id' => [$sampleProd['business_product_id']],
    'quantity' => [1]
];
$invalidResp = req("$baseUrl/petstore/receptionist/sales", 'POST', $invalidRegisteredPayload);
assertTest($invalidResp['code'] === 302, "Invalid registered sale rejected and redirected (HTTP 302)");
$postInvalidSales = req("$baseUrl/petstore/receptionist/sales");
assertTest(strpos($postInvalidSales['body'], 'Please search and select a valid registered customer') !== false, "Error message displayed when registered customer is not selected");

// Submit valid registered sale with Oliver Vance
$csrfToken = extractCsrfToken($postInvalidSales['body']);
$oliver = $db->query("SELECT customer_id FROM customer WHERE phone = '555-0101' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$validRegisteredPayload = [
    'csrf_token' => $csrfToken,
    'customer_type' => 'registered',
    'customer_name' => 'Oliver Vance',
    'customer_id' => $oliver['customer_id'],
    'payment_method' => 'GCash',
    'product_id' => [$sampleProd['business_product_id']],
    'quantity' => [1]
];
$validResp = req("$baseUrl/petstore/receptionist/sales", 'POST', $validRegisteredPayload);
assertTest($validResp['code'] === 302, "Valid registered customer sale submitted successfully (HTTP 302)");

$latestRegSale = $db->query("SELECT * FROM sale ORDER BY sale_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest((int)$latestRegSale['customer_id'] === (int)$oliver['customer_id'], "Registered sale recorded customer_id={$oliver['customer_id']}");

// Check pagination with multiple pages now that >= 5 sales exist
$totalSalesCount = (int)$db->query("SELECT COUNT(*) FROM sale WHERE business_unit_id = 1")->fetchColumn();
if ($totalSalesCount > 5) {
    $salesPage2Resp = req("$baseUrl/petstore/receptionist/sales?page=2");
    assertTest($salesPage2Resp['code'] === 200, "Sales Page 2 returns HTTP 200");
    $page2Html = $salesPage2Resp['body'];
    assertTest(preg_match('/Page 2 of \d+/', $page2Html), "Pagination displays 'Page 2 of X' on page 2 (Total: $totalSalesCount)");
}

// --- SECTION 8: Grooming Receptionist Appointments Calendar Refinements ---
echo "\n--- SECTION 8: Grooming Receptionist Appointments Calendar Refinements ---\n";
// Switch to Grooming Receptionist
$switchGroomingResp = req("$baseUrl/switch-interface?target=grooming-receptionist");
assertTest($switchGroomingResp['code'] === 302, "Switch to Grooming Receptionist redirects (HTTP 302)");

// Load calendar view
$calResp = req("$baseUrl/grooming/receptionist/appointments?tab=calendar");
assertTest($calResp['code'] === 200, "Grooming Appointments Calendar tab loaded (HTTP 200)");
$calHtml = $calResp['body'];

// Verify layout class
assertTest(strpos($calHtml, 'has-calendar-view') !== false, "Calendar view contains 'has-calendar-view' body class");
// Verify scenic rails suppressed
assertTest(strpos($calHtml, 'scenic-rail') === false, "Scenic rails are suppressed from Appointments Calendar view");

// Verify grid & column structure
assertTest(strpos($calHtml, 'grooming-weekly-calendar-card') !== false, "Weekly calendar card present");
assertTest(strpos($calHtml, 'grooming-cal-header-grid') !== false, "Calendar header grid present");
assertTest(strpos($calHtml, 'grooming-cal-body-grid') !== false, "Calendar body grid present");

// Verify CSS rules in app.css
$cssContent = file_get_contents(__DIR__ . '/../public/assets/css/app.css');
assertTest(strpos($cssContent, 'body.has-calendar-view main') !== false, "app.css has wide container rule: body.has-calendar-view main");
assertTest(strpos($cssContent, 'max-width: min(96vw, 1440px)') !== false, "Calendar container max-width set to min(96vw, 1440px)");
assertTest(strpos($cssContent, 'repeat(7, minmax(115px, 1fr))') !== false, "Calendar columns set to repeat(7, minmax(115px, 1fr))");
assertTest(strpos($cssContent, 'min-height: 46px') !== false, "Appointment blocks set to min-height: 46px");
assertTest(strpos($cssContent, 'text-overflow: ellipsis') !== false, "Appointment blocks have text-overflow: ellipsis");

// Verify appointment block content in rendered HTML
assertTest(strpos($calHtml, 'grooming-cal-appt-block') !== false, "Appointment cards rendered in body grid");
assertTest(strpos($calHtml, 'grooming-cal-block-id') !== false, "Appointment block contains appointment ID and customer name (.grooming-cal-block-id)");
assertTest(strpos($calHtml, 'grooming-cal-block-time') !== false, "Appointment block contains time coverage (.grooming-cal-block-time)");
assertTest(strpos($calHtml, 'grooming-cal-block-customer') !== false, "Appointment block contains pet/customer details (.grooming-cal-block-customer)");

echo "\n========================================================\n";
echo "Test Suite Execution Completed!\n";
echo "Total Passed: $passCount\n";
echo "Total Failed: $failCount\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
