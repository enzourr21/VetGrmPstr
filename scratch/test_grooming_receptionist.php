<?php
declare(strict_types=1);

require 'd:/VETGRPETproj/app/bootstrap.php';

$db = \App\Core\Database::pdo();

echo "========================================================\n";
echo "VETGRPET — Grooming Receptionist & Switcher Test Suite\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $message): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] $message\n";
        $passCount++;
    } else {
        echo "[FAIL] $message\n";
        $failCount++;
    }
}

// Helper: HTTP request using cURL with cookie jar
$cookieFile = __DIR__ . '/test_cookies.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function httpReq(string $method, string $path, array $postData = [], array $headers = []): array {
    global $cookieFile;
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Don't follow so we can inspect redirects
    curl_setopt($ch, CURLOPT_HEADER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $defaultHeaders = ['User-Agent: VetgrpetTester/1.0'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // Extract Location header
    $location = null;
    if (preg_match('/^Location:\s*(.+)$/im', $headerStr, $m)) {
        $location = trim($m[1]);
    }

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'location' => $location,
        'body' => $body,
    ];
}

// Extract CSRF token from HTML body
function getCsrfToken(string $html): string {
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

// -----------------------------------------------------------------------------
// TEST 1: Unauthenticated direct access is blocked
// -----------------------------------------------------------------------------
echo "--- TEST 1: Direct Authorization & Access Control ---\n";
$res = httpReq('GET', '/grooming/receptionist/overview');
assertTest($res['code'] === 302 && str_contains($res['location'] ?? '', '/login'), "Unauthenticated access to /grooming/receptionist/overview redirects to /login (Got HTTP {$res['code']})");

// -----------------------------------------------------------------------------
// TEST 2: Sign in as Super Administrator
// -----------------------------------------------------------------------------
echo "\n--- TEST 2: Super Administrator Sign-In ---\n";
$loginForm = httpReq('GET', '/login');
$csrf = getCsrfToken($loginForm['body']);

$loginRes = httpReq('POST', '/demo/super-admin', ['csrf_token' => $csrf]);
assertTest($loginRes['code'] === 302 && $loginRes['location'] === '/super-admin', "Super Admin signed in and landed on /super-admin (Got HTTP {$loginRes['code']}, Location: {$loginRes['location']})");

// -----------------------------------------------------------------------------
// TEST 3: Inspect Switch Interface Menu on /super-admin
// -----------------------------------------------------------------------------
echo "\n--- TEST 3: Switch Interface Refactored Structure ---\n";
$saPage = httpReq('GET', '/super-admin');
assertTest($saPage['code'] === 200, "Super Admin dashboard rendered successfully (HTTP 200)");

$body = $saPage['body'];
assertTest(str_contains($body, 'data-target="receptionist"'), "Switcher has expandable Receptionist parent category: data-target=\"receptionist\"");
assertTest(str_contains($body, 'switcher-units-receptionist'), "Switcher has Receptionist units container: id=\"switcher-units-receptionist\"");
assertTest(str_contains($body, '/switch-interface?target=petstore-receptionist'), "Receptionist submenu contains Pet Store link: /switch-interface?target=petstore-receptionist");
assertTest(str_contains($body, '/switch-interface?target=grooming-receptionist'), "Receptionist submenu contains Grooming Services link: /switch-interface?target=grooming-receptionist");
assertTest(str_contains($body, 'Veterinary Clinic (Coming Soon)'), "Receptionist submenu contains Veterinary Clinic (Coming Soon) option");
assertTest(str_contains($body, 'is-disabled') && str_contains($body, 'aria-disabled="true"'), "Veterinary Clinic option is marked disabled / Coming Soon");
assertTest(!str_contains($body, 'data-target="petstore-receptionist"'), "Standalone Pet Store Receptionist option removed from top-level switcher");

// -----------------------------------------------------------------------------
// TEST 4: Switch Interface to Pet Store Receptionist
// -----------------------------------------------------------------------------
echo "\n--- TEST 4: Switch to Pet Store Receptionist ---\n";
$swPs = httpReq('GET', '/switch-interface?target=petstore-receptionist');
assertTest($swPs['code'] === 302 && $swPs['location'] === '/petstore/receptionist/sales', "Switching to Pet Store Receptionist redirects to /petstore/receptionist/sales");

$psSales = httpReq('GET', '/petstore/receptionist/sales');
assertTest($psSales['code'] === 200, "Pet Store Receptionist sales page renders successfully (HTTP 200)");
assertTest(str_contains($psSales['body'], 'Daily Sales') && str_contains($psSales['body'], 'New Sale'), "Pet Store Receptionist Daily Sales card and New Sale button present");
assertTest(str_contains($psSales['body'], 'is-current-unit') && str_contains($psSales['body'], 'Pet Store'), "Pet Store child unit is highlighted with is-current-unit when on Pet Store Receptionist");

// -----------------------------------------------------------------------------
// TEST 5: Switch Interface to Grooming Services Receptionist
// -----------------------------------------------------------------------------
echo "\n--- TEST 5: Switch to Grooming Services Receptionist ---\n";
$swGr = httpReq('GET', '/switch-interface?target=grooming-receptionist');
assertTest($swGr['code'] === 302 && $swGr['location'] === '/grooming/receptionist/overview', "Switching to Grooming Services Receptionist redirects to /grooming/receptionist/overview");

$grOverview = httpReq('GET', '/grooming/receptionist/overview');
assertTest($grOverview['code'] === 200, "Grooming Services Receptionist Overview page renders successfully (HTTP 200)");

// Verify Top Navigation has EXACTLY the 5 Main Pages
$navBody = $grOverview['body'];
assertTest(str_contains($navBody, '/grooming/receptionist/overview') && str_contains($navBody, '>Overview</a>'), "Top nav has Overview link");
assertTest(str_contains($navBody, '/grooming/receptionist/appointments') && str_contains($navBody, '>Appointments</a>'), "Top nav has Appointments link");
assertTest(str_contains($navBody, '/grooming/receptionist/billing') && str_contains($navBody, '>Billing</a>'), "Top nav has Billing link");
assertTest(str_contains($navBody, '/grooming/receptionist/records') && str_contains($navBody, '>Records</a>'), "Top nav has Records link");
assertTest(str_contains($navBody, '/grooming/receptionist/employees') && str_contains($navBody, '>Employees</a>'), "Top nav has Employees link");

// Verify no secondary subnav or workspace identification banners
assertTest(!str_contains($navBody, 'subnav'), "No secondary subnav bar present");
assertTest(!str_contains($navBody, 'workspace-banner'), "No workspace identification banner present");

// Check switcher active highlight on Grooming Services unit
assertTest(str_contains($navBody, 'is-current-unit') && str_contains($navBody, 'Grooming Services'), "Grooming Services child unit is highlighted with is-current-unit when on Grooming Receptionist");

// -----------------------------------------------------------------------------
// TEST 6: Overview Page Sections
// -----------------------------------------------------------------------------
echo "\n--- TEST 6: Overview Page Sections ---\n";
assertTest(str_contains($grOverview['body'], 'Current / Upcoming Appointment'), "Overview Section A: Current / Upcoming Appointment card present (full width)");
assertTest(str_contains($grOverview['body'], 'Grooming Services Catalog'), "Overview Section B: Services Catalog card present (50% width)");
assertTest(str_contains($grOverview['body'], "Today's Appointment Summary"), "Overview Section C: Today's Appointment Summary card present (50% width)");
assertTest(str_contains($grOverview['body'], 'See Appointments &rarr;') || str_contains($grOverview['body'], 'see-appointments-link'), "Today's Appointment Summary card has 'See Appointments' link");
assertTest(str_contains($grOverview['body'], 'Employees Present &amp; Work Status'), "Overview Section D: Employees Present card present");

// -----------------------------------------------------------------------------
// TEST 7: Appointments Page (2 Tabs & Walk-in Modal)
// -----------------------------------------------------------------------------
echo "\n--- TEST 7: Appointments Page ---\n";
$apptReq = httpReq('GET', '/grooming/receptionist/appointments?tab=requests');
assertTest($apptReq['code'] === 200, "Appointments page (Requests tab) renders HTTP 200");
assertTest(str_contains($apptReq['body'], 'Appointment Requests') && str_contains($apptReq['body'], 'Appointments Calendar'), "Appointments page has exactly 2 tabs: Requests and Calendar");

$apptCal = httpReq('GET', '/grooming/receptionist/appointments?tab=calendar');
assertTest($apptCal['code'] === 200, "Appointments page (Calendar tab) renders HTTP 200");
assertTest(str_contains($apptCal['body'], '+ Walk-in Appointment'), "Appointments Calendar tab includes '+ Walk-in Appointment' button");
assertTest(str_contains($apptCal['body'], 'walkin-modal'), "Walk-in appointment modal exists in calendar tab");
assertTest(str_contains($apptCal['body'], 'Guest Customer') && str_contains($apptCal['body'], 'Registered Customer'), "Walk-in modal Part 1 provides Guest and Registered customer choices");

// -----------------------------------------------------------------------------
// TEST 8: Customer Search AJAX format: `Customer Name #CustomerID`
// -----------------------------------------------------------------------------
echo "\n--- TEST 8: Customer Search Suggestions Format ---\n";
$custSearch = httpReq('GET', '/grooming/receptionist/customers/search?q=Adrian');
$searchJson = json_decode($custSearch['body'], true);
assertTest($custSearch['code'] === 200 && !empty($searchJson['customers']), "Customer search endpoint returns HTTP 200 with customer results");
if (!empty($searchJson['customers'])) {
    $firstCust = $searchJson['customers'][0];
    assertTest(str_contains($firstCust['label'], '#') && preg_match('/#\d+$/', $firstCust['label']), "Customer search suggestion follows strict format 'Customer Name #CustomerID' (Got: {$firstCust['label']})");
}

// -----------------------------------------------------------------------------
// TEST 9: Book a Walk-in Appointment for a Guest Customer
// -----------------------------------------------------------------------------
echo "\n--- TEST 9: Walk-in Appointment Booking ---\n";
$csrfWalkin = getCsrfToken($apptCal['body']);

// Pick an available slot for today
$existingTimes = $db->query("SELECT preferred_time FROM appointment WHERE business_unit_id = 3 AND preferred_date = CURDATE()")->fetchAll(PDO::FETCH_COLUMN);
$candidateTimes = ['11:00', '11:30', '12:00', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00'];
$chosenTime = '11:00';
foreach ($candidateTimes as $ct) {
    if (!in_array($ct . ':00', $existingTimes, true)) {
        $chosenTime = $ct;
        break;
    }
}

$walkinPost = [
    'csrf_token' => $csrfWalkin,
    'customer_type' => 'guest',
    'guest_name' => 'Michael Scott',
    'guest_phone' => '0917-555-8899',
    'customer_id' => '',
    'appointment_date' => date('Y-m-d'),
    'appointment_time' => $chosenTime,
    'walkin_notes' => 'First-time walk-in guest, needs gentle bath and dry.',
    'pets' => [
        1 => [
            'name' => 'Bandit',
            'species' => 'Dog',
            'services' => [1], // Basic Bath & Dry ($25.00)
        ],
    ],
];
$walkinRes = httpReq('POST', '/grooming/receptionist/appointments/walk-in', $walkinPost);
$followRes = httpReq('GET', $walkinRes['location'] ?? '/grooming/receptionist/appointments');
if (preg_match('/<div class="flash flash-[^>]*>(.*?)<\/div>/s', $followRes['body'], $alertMatch)) {
    echo "Flash message received: " . trim(strip_tags($alertMatch[1])) . "\n";
}
assertTest($walkinRes['code'] === 302 && str_contains($walkinRes['location'], '/grooming/receptionist/appointments'), "Walk-in appointment booked and redirected to appointments calendar (HTTP 302)");

// Verify appointment in database
$latestApptStmt = $db->query("SELECT a.*, st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.business_unit_id = 3 ORDER BY a.appointment_id DESC LIMIT 1");
$latestAppt = $latestApptStmt->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($latestAppt) && $latestAppt['status_name'] === 'Confirmed', "New appointment created in database with Confirmed status (ID #" . ($latestAppt['appointment_id'] ?? '') . ")");

$notesData = json_decode((string)($latestAppt['notes'] ?? ''), true);
assertTest(is_array($notesData) && ($notesData['customer_name'] ?? '') === 'Michael Scott' && ($notesData['customer_type'] ?? '') === 'guest', "Appointment notes stores guest customer name without creating permanent personal customer profile");

// Verify performed_service was generated and performer assigned
$apptId = (int)($latestAppt['appointment_id'] ?? 0);
$psStmt = $db->prepare("SELECT ps.*, e.first_name, e.last_name, e.position FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id LEFT JOIN employee e ON e.employee_id = ps.performer_employee_id WHERE ap.appointment_id = ?");
$psStmt->execute([$apptId]);
$psRows = $psStmt->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($psRows) === 1, "Performed service row created for the appointment");
assertTest(!empty($psRows[0]['performer_employee_id']), "Qualified employee automatically assigned (Assigned: " . ($psRows[0]['first_name'] ?? '') . " " . ($psRows[0]['last_name'] ?? '') . " - " . ($psRows[0]['position'] ?? '') . ")");

// -----------------------------------------------------------------------------
// TEST 10: Overview Page shows the Walk-in Appointment
// -----------------------------------------------------------------------------
echo "\n--- TEST 10: Overview reflects Current/Upcoming Appointment ---\n";
$overviewAfter = httpReq('GET', '/grooming/receptionist/overview');
assertTest(str_contains($overviewAfter['body'], 'Michael Scott') && str_contains($overviewAfter['body'], 'Bandit'), "Overview Section A now displays Michael Scott and Bandit as upcoming appointment");

// -----------------------------------------------------------------------------
// TEST 11: Billing Page & Add Billing Flow
// -----------------------------------------------------------------------------
echo "\n--- TEST 11: Billing Page & Payment Settlement ---\n";
$billingPage = httpReq('GET', '/grooming/receptionist/billing');
assertTest($billingPage['code'] === 200, "Billing page renders successfully (HTTP 200)");
assertTest(str_contains($billingPage['body'], '+ Add Billing'), "Billing page has '+ Add Billing' button");
assertTest(str_contains($billingPage['body'], 'Upcoming / Ongoing Studio Appointments'), "Billing page contains Upcoming / Ongoing Appointments card");
assertTest(str_contains($billingPage['body'], 'Recently Completed &amp; Settled Appointments'), "Billing page contains Recently Completed Appointments card");

// Test search billing appointments AJAX for Walk-in "Michael Scott"
$searchBilling = httpReq('GET', '/grooming/receptionist/billing/search-appointments?type=walkin&q=Michael');
$sbJson = json_decode($searchBilling['body'], true);
assertTest($searchBilling['code'] === 200 && !empty($sbJson['appointments']), "Billing search returns eligible appointment for guest 'Michael'");
assertTest($sbJson['appointments'][0]['customer_id_display'] === null, "Guest appointment customer_id remains strictly NULL in billing API");

// Test load appointment details for billing
$apptDetails = httpReq('GET', "/grooming/receptionist/billing/appointments/$apptId");
$adJson = json_decode($apptDetails['body'], true);
assertTest($apptDetails['code'] === 200 && !empty($adJson['appointment']), "Appointment billing details loaded successfully via AJAX");
assertTest((float)($adJson['appointment']['total_amount'] ?? 0) === 25.00, "Calculated total amount matches services without double-charging (₱25.00)");

// Test settle billing with underpayment (Payment Received < Total Cost) -> Should fail
$csrfBilling = getCsrfToken($billingPage['body']);
$underpayRes = httpReq('POST', '/grooming/receptionist/billing/settle', [
    'csrf_token' => $csrfBilling,
    'appointment_id' => $apptId,
    'payment_method' => 'cash',
    'payment_received' => 20.00, // Less than ₱25.00!
]);
assertTest($underpayRes['code'] === 302, "Underpayment attempt rejected and redirected with error");

// Verify appointment was NOT completed
$checkAppt = $db->query("SELECT appointment_status_id FROM appointment WHERE appointment_id = $apptId")->fetchColumn();
assertTest((int)$checkAppt !== 7, "Appointment status remains uncompleted when underpaid");

// Test settle billing with valid payment (Payment Received: ₱30.00, Total: ₱25.00 -> Change: ₱5.00)
$settleRes = httpReq('POST', '/grooming/receptionist/billing/settle', [
    'csrf_token' => $csrfBilling,
    'appointment_id' => $apptId,
    'payment_method' => 'cash',
    'payment_received' => 30.00,
]);
assertTest($settleRes['code'] === 302 && str_contains($settleRes['location'], '/grooming/receptionist/billing'), "Billing settled successfully and redirected to billing page");

// Verify in DB that invoice, payment, and completed status exist
$invStmt = $db->prepare("SELECT si.*, p.payment_method, p.amount, p.payment_status FROM service_invoice si JOIN payment p ON p.invoice_id = si.invoice_id WHERE si.appointment_id = ?");
$invStmt->execute([$apptId]);
$invRow = $invStmt->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($invRow) && $invRow['invoice_status'] === 'paid', "Service invoice created with status 'paid' for appointment #$apptId");
assertTest((float)($invRow['total_amount'] ?? 0) === 25.00 && ($invRow['payment_status'] ?? '') === 'completed', "Payment record created with amount ₱25.00 and status 'completed'");

$finalAppt = $db->query("SELECT a.*, st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id = $apptId")->fetch(PDO::FETCH_ASSOC);
assertTest($finalAppt['status_name'] === 'Completed', "Appointment #$apptId is now marked 'Completed' only after full settlement");

// Verify duplicate billing is prevented if tried again
$dupRes = httpReq('POST', '/grooming/receptionist/billing/settle', [
    'csrf_token' => $csrfBilling,
    'appointment_id' => $apptId,
    'payment_method' => 'cash',
    'payment_received' => 30.00,
]);
assertTest($dupRes['code'] === 302, "Duplicate billing attempt prevented");

// -----------------------------------------------------------------------------
// TEST 12: Records Page & Read-Only Detail View
// -----------------------------------------------------------------------------
echo "\n--- TEST 12: Records Page & Read-Only View ---\n";
$recordsPage = httpReq('GET', '/grooming/receptionist/records');
assertTest($recordsPage['code'] === 200, "Records page renders successfully (HTTP 200)");
assertTest(str_contains($recordsPage['body'], 'Filter Status:') && str_contains($recordsPage['body'], 'Pending Payment') && str_contains($recordsPage['body'], 'Completed'), "Records page has status filters: All, Pending Payment, Completed");
assertTest(str_contains($recordsPage['body'], 'Michael Scott') && str_contains($recordsPage['body'], "#$apptId"), "Completed appointment #$apptId appears in Records table");

// Test record details AJAX endpoint
$recDetail = httpReq('GET', "/grooming/receptionist/records/$apptId");
$rdJson = json_decode($recDetail['body'], true);
assertTest($recDetail['code'] === 200 && !empty($rdJson['record']), "Record details endpoint returns complete read-only data (HTTP 200)");
assertTest(!empty($rdJson['record']['billing_breakdown']), "Record details includes itemized billing breakdown");

// -----------------------------------------------------------------------------
// TEST 13: Employees Page
// -----------------------------------------------------------------------------
echo "\n--- TEST 13: Grooming Employees Page ---\n";
$empPage = httpReq('GET', '/grooming/receptionist/employees');
assertTest($empPage['code'] === 200, "Grooming Employees page renders successfully (HTTP 200)");
assertTest(str_contains($empPage['body'], 'Owen Bennett') && str_contains($empPage['body'], 'Master Stylist'), "Employees page displays Grooming Master Stylist Owen Bennett");
assertTest(!str_contains($empPage['body'], 'Dr. Elena Vance'), "Employees page isolates business unit: Veterinary Clinic staff (Elena Vance) NOT shown");
assertTest(!str_contains($empPage['body'], 'David Reyes'), "Employees page isolates business unit: Pet Store staff (David Reyes) NOT shown");
assertTest(!str_contains($empPage['body'], 'action="/manager/employees"'), "No Business Manager employee management forms present on Receptionist page");

// -----------------------------------------------------------------------------
// TEST 14: Return to Super Administrator Interface
// -----------------------------------------------------------------------------
echo "\n--- TEST 14: Return to Super Administrator Interface ---\n";
$swSa = httpReq('GET', '/switch-interface?target=super-admin');
assertTest($swSa['code'] === 302 && $swSa['location'] === '/super-admin', "Switching back to Super Administrator redirects to /super-admin");

$saAfter = httpReq('GET', '/super-admin');
assertTest($saAfter['code'] === 200 && str_contains($saAfter['body'], 'Dashboard'), "Super Administrator interface restored with full administrative privileges");

// -----------------------------------------------------------------------------
// TEST 15: Regression Tests
// -----------------------------------------------------------------------------
echo "\n--- TEST 15: Cross-Workspace Regression Tests ---\n";
// Pet Store BM
$bm1 = httpReq('GET', '/manager?unit_id=1');
assertTest($bm1['code'] === 200, "Pet Store Business Manager interface remains functional (HTTP 200)");

// Grooming BM
$bm3 = httpReq('GET', '/manager?unit_id=3');
assertTest($bm3['code'] === 200, "Grooming Services Business Manager interface remains functional (HTTP 200)");

// Vet Clinic BM
$bm2 = httpReq('GET', '/manager?unit_id=2');
assertTest($bm2['code'] === 200, "Veterinary Clinic Business Manager interface remains functional (HTTP 200)");

// System Admin
$sys = httpReq('GET', '/sysadmin');
assertTest($sys['code'] === 200, "System Administrator interface remains functional (HTTP 200)");

echo "\n========================================================\n";
echo "Test Suite Execution Completed!\n";
echo "Total Passed: $passCount\n";
echo "Total Failed: $failCount\n";
echo "========================================================\n";
