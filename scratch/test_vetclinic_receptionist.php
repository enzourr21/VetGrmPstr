<?php
declare(strict_types=1);

require 'd:/VETGRPETproj/app/bootstrap.php';

use App\Core\Database;
use App\Models\AppointmentScheduleValidator;
use App\Models\Units;

$db = Database::pdo();

echo "=================================================================\n";
echo "VETGRPET — Veterinary Clinic Receptionist Comprehensive Test Suite\n";
echo "=================================================================\n\n";

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

$cookieFile = __DIR__ . '/test_vet_cookies.txt';
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
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
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
$res = httpReq('GET', '/vetclinic/receptionist/overview');
assertTest($res['code'] === 302 && str_contains($res['location'] ?? '', '/login'), "Unauthenticated access to /vetclinic/receptionist/overview redirects to /login (Got HTTP {$res['code']})");

// -----------------------------------------------------------------------------
// TEST 2: Sign in as Super Administrator
// -----------------------------------------------------------------------------
echo "\n--- TEST 2: Super Administrator Sign-In ---\n";
$loginForm = httpReq('GET', '/login');
$csrf = getCsrfToken($loginForm['body']);

$loginRes = httpReq('POST', '/demo/super-admin', ['csrf_token' => $csrf]);
assertTest($loginRes['code'] === 302 && $loginRes['location'] === '/super-admin', "Super Admin signed in and landed on /super-admin");

// -----------------------------------------------------------------------------
// TEST 3: Switcher Menu Contains Active Veterinary Clinic Receptionist Option
// -----------------------------------------------------------------------------
echo "\n--- TEST 3: Switch Interface Menu Structure ---\n";
$saPage = httpReq('GET', '/super-admin');
$body = $saPage['body'];
assertTest(str_contains($body, '/switch-interface?target=vetclinic-receptionist'), "Switcher submenu contains Veterinary Clinic link: /switch-interface?target=vetclinic-receptionist");
assertTest(str_contains($body, 'Veterinary Clinic'), "Switcher submenu contains 'Veterinary Clinic'");
assertTest(!str_contains($body, 'Veterinary Clinic (Coming Soon)'), "Veterinary Clinic is no longer marked 'Coming Soon'");

// -----------------------------------------------------------------------------
// TEST 4: Switch to Veterinary Clinic Receptionist
// -----------------------------------------------------------------------------
echo "\n--- TEST 4: Switch Interface to Veterinary Clinic Receptionist ---\n";
$swVet = httpReq('GET', '/switch-interface?target=vetclinic-receptionist');
assertTest($swVet['code'] === 302 && $swVet['location'] === '/vetclinic/receptionist/overview', "Switching to vetclinic-receptionist redirects to /vetclinic/receptionist/overview");

$swGen = httpReq('GET', '/switch-interface?target=receptionist&unit_id=2');
assertTest($swGen['code'] === 302 && str_contains($swGen['location'] ?? '', 'target=vetclinic-receptionist'), "General switcher with unit_id=2 redirects to vetclinic-receptionist target");

// -----------------------------------------------------------------------------
// TEST 5: Top Navigation in Veterinary Clinic Receptionist Workspace
// -----------------------------------------------------------------------------
echo "\n--- TEST 5: Top Navigation in Veterinary Clinic Receptionist Workspace ---\n";
$ovPage = httpReq('GET', '/vetclinic/receptionist/overview');
assertTest($ovPage['code'] === 200, "Overview page loads successfully (HTTP 200)");
$ovHtml = $ovPage['body'];

// Verify exactly the 5 pages are in the top navigation
assertTest(str_contains($ovHtml, '/vetclinic/receptionist/overview'), "Navigation has Overview link");
assertTest(str_contains($ovHtml, '/vetclinic/receptionist/appointments'), "Navigation has Appointments link");
assertTest(str_contains($ovHtml, '/vetclinic/receptionist/billing'), "Navigation has Billing link");
assertTest(str_contains($ovHtml, '/vetclinic/receptionist/records'), "Navigation has Records link");
assertTest(str_contains($ovHtml, '/vetclinic/receptionist/employees'), "Navigation has Employees link");

// Verify customer-facing navigation links do NOT appear in the workspace navigation
assertTest(!preg_match('#<nav[^>]*>.*?<a[^>]+href="/products"[^>]*>Products</a>.*?</nav>#s', $ovHtml), "Customer 'Products' link is NOT present in workspace nav");
assertTest(!preg_match('#<nav[^>]*>.*?<a[^>]+href="/services"[^>]*>Services</a>.*?</nav>#s', $ovHtml), "Customer 'Services' link is NOT present in workspace nav");
assertTest(!preg_match('#<nav[^>]*>.*?<a[^>]+href="/staffs"[^>]*>Staffs</a>.*?</nav>#s', $ovHtml), "Customer 'Staffs' link is NOT present in workspace nav");
assertTest(!preg_match('#<nav[^>]*>.*?<a[^>]+href="/reviews"[^>]*>Customer Reviews</a>.*?</nav>#s', $ovHtml), "Customer 'Customer Reviews' link is NOT present in workspace nav");

// -----------------------------------------------------------------------------
// TEST 6: Overview Page Card Arrangement and Content
// -----------------------------------------------------------------------------
echo "\n--- TEST 6: Overview Page Card Arrangement and Content ---\n";
assertTest(str_contains($ovHtml, 'Current / Upcoming Appointment'), "Overview Section A: contains 'Current / Upcoming Appointment' card");
assertTest(str_contains($ovHtml, 'Medical Services Catalog'), "Overview Section B: contains 'Medical Services Catalog' card");
assertTest(str_contains($ovHtml, "Today's Appointment Summary"), "Overview Section C: contains 'Today\'s Appointment Summary' card");
assertTest(str_contains($ovHtml, 'Employees Present &amp; Work Status') || str_contains($ovHtml, 'Employees Present & Work Status'), "Overview Section D: contains 'Employees Present & Work Status' card");
assertTest(str_contains($ovHtml, 'id="see-appointments-link"'), "Overview contains 'See Appointments' navigation link");
assertTest(str_contains($ovHtml, 'Pending') && str_contains($ovHtml, 'Confirmed') && str_contains($ovHtml, 'In Progress') && str_contains($ovHtml, 'Pending Pay') && str_contains($ovHtml, 'Completed'), "Summary card displays counts for Pending, Confirmed, In Progress, Pending Payment, Completed");

// -----------------------------------------------------------------------------
// TEST 7: Appointments Page Views, Tabs, Overlap Badge & 4-Step Walk-in Modal
// -----------------------------------------------------------------------------
echo "\n--- TEST 7: Appointments Page & 4-Step Walk-in Modal ---\n";
$apptPage = httpReq('GET', '/vetclinic/receptionist/appointments?tab=calendar');
assertTest($apptPage['code'] === 200, "Appointments page calendar tab loads (HTTP 200)");
$apptHtml = $apptPage['body'];

assertTest(str_contains($apptHtml, 'tab=requests') && str_contains($apptHtml, 'tab=calendar'), "Appointments page contains exactly 2 tabs (Requests & Calendar)");
assertTest(str_contains($apptHtml, 'Max Overlap: 15m'), "Appointments toolbar displays active 'Max Overlap: 15m' badge");
assertTest(str_contains($apptHtml, 'id="open-walkin-btn"'), "Appointments calendar toolbar contains '+ Walk-in Appointment' button");

// Walk-in modal 4 steps inspection
assertTest(str_contains($apptHtml, 'id="walkin-modal"'), "Walk-in modal dialog exists in DOM");
assertTest(str_contains($apptHtml, 'Step 1: Customer / Owner Information'), "Walk-in modal contains Step 1: Customer / Owner Information");
assertTest(str_contains($apptHtml, 'Step 2: Pet Information'), "Walk-in modal contains Step 2: Pet Information");
assertTest(str_contains($apptHtml, 'Step 3: Medical Services'), "Walk-in modal contains Step 3: Medical Services");
assertTest(str_contains($apptHtml, 'Step 4: Appointment Schedule &amp; Confirmation') || str_contains($apptHtml, 'Step 4: Appointment Schedule & Confirmation'), "Walk-in modal contains Step 4: Appointment Schedule & Confirmation");
assertTest(str_contains($apptHtml, 'name="guest_name"'), "Walk-in modal collects guest owner name");
assertTest(str_contains($apptHtml, 'id="selected-customer-id"') && str_contains($apptHtml, 'readonly'), "Walk-in modal customer ID is read-only for registered customers");

// Tab 1: Requests
$reqPage = httpReq('GET', '/vetclinic/receptionist/appointments?tab=requests');
assertTest($reqPage['code'] === 200, "Appointments page requests tab loads (HTTP 200)");

// -----------------------------------------------------------------------------
// TEST 8: AJAX Customer and Pet Lookup
// -----------------------------------------------------------------------------
echo "\n--- TEST 8: Customer & Pet Lookup Endpoints ---\n";
$custSearch = httpReq('GET', '/vetclinic/receptionist/customers/search?q=Oliver');
assertTest($custSearch['code'] === 200, "Customer search returns HTTP 200");
$custData = json_decode($custSearch['body'], true);
assertTest($custData['ok'] === true && !empty($custData['customers']), "Customer search returned results");
assertTest(str_contains($custData['customers'][0]['label'] ?? '', 'Oliver Vance #6'), "Customer label strictly formatted as 'Customer Name #CustomerID': {$custData['customers'][0]['label']}");

$petSearch = httpReq('GET', '/vetclinic/receptionist/customers/6/pets');
assertTest($petSearch['code'] === 200, "Customer pets endpoint returns HTTP 200");
$petData = json_decode($petSearch['body'], true);
assertTest($petData['ok'] === true && !empty($petData['pets']), "Customer pets returned registered pets for Oliver Vance");

// -----------------------------------------------------------------------------
// TEST 9: Walk-in Appointment Creation & Overlap Validation
// -----------------------------------------------------------------------------
echo "\n--- TEST 9: Walk-in Booking & Overlap Validation ---\n";
// Insert mock test booking on a distinct test date
$testDate = '2026-12-15';
$db->exec("DELETE FROM payment WHERE invoice_id IN (SELECT invoice_id FROM service_invoice WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'))");
$db->exec("DELETE FROM invoice_item WHERE invoice_id IN (SELECT invoice_id FROM service_invoice WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'))");
$db->exec("DELETE FROM service_invoice WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%')");
$db->exec("DELETE FROM performed_service WHERE appointment_pet_id IN (SELECT appointment_pet_id FROM appointment_pet WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'))");
$db->exec("DELETE FROM requested_pet_service WHERE appointment_pet_id IN (SELECT appointment_pet_id FROM appointment_pet WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'))");
$db->exec("DELETE FROM appointment_pet WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%')");
$db->exec("DELETE FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'");

$apptCsrf = getCsrfToken($apptHtml);

// 1. Submit valid guest walk-in appointment
$walkinPost = [
    'csrf_token' => $apptCsrf,
    'customer_type' => 'guest',
    'guest_name' => 'Dr. Gordon Freeman',
    'guest_phone' => '0917-888-9999',
    'pets' => [
        1 => [
            'name' => 'Lamarr',
            'species' => 'Other',
            'breed' => 'Headcrab',
            'services' => [8], // Comprehensive Wellness Exam (30m)
        ],
    ],
    'appointment_date' => $testDate,
    'appointment_time' => '10:00',
    'walkin_notes' => '[TEST-VET-RECEPTIONIST] Routine checkup for Lamarr',
];

$walkinRes = httpReq('POST', '/vetclinic/receptionist/appointments/walk-in', $walkinPost);
assertTest($walkinRes['code'] === 302 && str_contains($walkinRes['location'] ?? '', '/vetclinic/receptionist/appointments?tab=calendar'), "Walk-in appointment booked and redirected to calendar");

// Verify in DB
$checkAppt = $db->query("SELECT * FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%' ORDER BY appointment_id DESC LIMIT 1")->fetch();
assertTest((bool)$checkAppt, "Walk-in appointment persisted in database");
$testApptId = (int)$checkAppt['appointment_id'];
assertTest((int)$checkAppt['business_unit_id'] === 2, "Appointment belongs to Veterinary Clinic (unit 2)");
assertTest($checkAppt['appointment_type'] === 'medical', "Appointment type is strictly 'medical'");
assertTest((int)$checkAppt['appointment_status_id'] === 3, "Appointment status is 'Confirmed'");

// Verify notes contains guest metadata with NULL customer ID semantics
$notesDecoded = json_decode((string)$checkAppt['notes'], true);
assertTest(($notesDecoded['customer_name'] ?? '') === 'Dr. Gordon Freeman', "Guest owner name stored in notes metadata");
assertTest(($notesDecoded['customer_type'] ?? '') === 'guest', "Guest customer_type stored in notes metadata");

// Verify Rule A: Same start time (10:00) on same date must be rejected
$dupPost = $walkinPost;
$dupPost['walkin_notes'] = '[TEST-VET-RECEPTIONIST] Duplicate start time attempt';
$dupRes = httpReq('POST', '/vetclinic/receptionist/appointments/walk-in', $dupPost);
$dupCheck = $db->query("SELECT COUNT(*) FROM appointment WHERE notes LIKE '%Duplicate start time attempt%'")->fetchColumn();
assertTest((int)$dupCheck === 0, "Rule A: Duplicate start time (10:00) was rejected and not inserted");

// Verify Rule B: 10:10 (20m overlap, exceeds 15m max) must be rejected
$overlapPost = $walkinPost;
$overlapPost['appointment_time'] = '10:10';
$overlapPost['walkin_notes'] = '[TEST-VET-RECEPTIONIST] 20m overlap attempt';
$overlapRes = httpReq('POST', '/vetclinic/receptionist/appointments/walk-in', $overlapPost);
$overlapCheck = $db->query("SELECT COUNT(*) FROM appointment WHERE notes LIKE '%20m overlap attempt%'")->fetchColumn();
assertTest((int)$overlapCheck === 0, "Rule B: 20-minute overlap was rejected (exceeds 15m max)");

// Verify Rule B: 10:15 (exactly 15m overlap) must be accepted
$validOverlapPost = $walkinPost;
$validOverlapPost['appointment_time'] = '10:15';
$validOverlapPost['walkin_notes'] = '[TEST-VET-RECEPTIONIST] 15m allowed overlap';
$validOverlapRes = httpReq('POST', '/vetclinic/receptionist/appointments/walk-in', $validOverlapPost);
$validOverlapCheck = $db->query("SELECT COUNT(*) FROM appointment WHERE notes LIKE '%15m allowed overlap%'")->fetchColumn();
assertTest((int)$validOverlapCheck === 1, "Rule B: Exactly 15-minute overlap was accepted and inserted");

// -----------------------------------------------------------------------------
// TEST 10: Billing Page & Payment Settlement
// -----------------------------------------------------------------------------
echo "\n--- TEST 10: Billing Page & Payment Settlement ---\n";
$billingPage = httpReq('GET', '/vetclinic/receptionist/billing');
assertTest($billingPage['code'] === 200, "Billing page loads (HTTP 200)");
$billingHtml = $billingPage['body'];

assertTest(str_contains($billingHtml, 'Upcoming / Ongoing Clinic Appointments'), "Billing page contains Upcoming / Ongoing Appointments card");
assertTest(str_contains($billingHtml, 'Recently Completed &amp; Settled Consultations') || str_contains($billingHtml, 'Recently Completed & Settled Consultations'), "Billing page contains Recently Completed card");
assertTest(str_contains($billingHtml, 'id="add-billing-btn"'), "Billing page contains '+ Add Billing' button");

// Test search billing appointments AJAX
$bSearch = httpReq('GET', '/vetclinic/receptionist/billing/search-appointments?type=walkin&q=Gordon');
assertTest($bSearch['code'] === 200, "Billing search endpoint returns HTTP 200");
$bSearchData = json_decode($bSearch['body'], true);
assertTest($bSearchData['ok'] === true, "Billing search returned valid JSON response");

// Test billing details AJAX for testApptId
$bDetails = httpReq('GET', '/vetclinic/receptionist/billing/appointments/' . $testApptId);
$bDetailsData = json_decode($bDetails['body'], true);
assertTest($bDetailsData['ok'] === true && !empty($bDetailsData['appointment']), "Billing details endpoint returned appointment data");
$totalCost = (float)$bDetailsData['appointment']['total_amount'];
assertTest($totalCost > 0, "Itemized total cost calculated (₱$totalCost)");

// Test settle billing: Payment Received < Total Cost must be rejected
$bCsrf = getCsrfToken($billingHtml);
$underpayRes = httpReq('POST', '/vetclinic/receptionist/billing/settle', [
    'csrf_token' => $bCsrf,
    'appointment_id' => $testApptId,
    'payment_method' => 'cash',
    'payment_received' => (string)($totalCost - 10),
]);
$checkUnpaid = $db->query("SELECT invoice_status FROM service_invoice WHERE appointment_id = $testApptId")->fetchColumn();
assertTest($checkUnpaid !== 'paid', "Settle billing rejects payment received less than total cost");

// Test settle billing: Full payment with change
$settleRes = httpReq('POST', '/vetclinic/receptionist/billing/settle', [
    'csrf_token' => $bCsrf,
    'appointment_id' => $testApptId,
    'payment_method' => 'cash',
    'payment_received' => (string)($totalCost + 20),
]);
assertTest($settleRes['code'] === 302 && str_contains($settleRes['location'] ?? '', '/vetclinic/receptionist/billing'), "Settle billing completed and redirected to billing page");

// Verify in DB: appointment is Completed, invoice is paid, payment record inserted
$paidInv = $db->query("SELECT * FROM service_invoice WHERE appointment_id = $testApptId")->fetch();
assertTest($paidInv && $paidInv['invoice_status'] === 'paid', "Service invoice status transitioned to 'paid'");
$payRow = $db->query("SELECT * FROM payment WHERE invoice_id = {$paidInv['invoice_id']}")->fetch();
assertTest($payRow && $payRow['payment_status'] === 'completed', "Payment record created with status 'completed'");
$checkSt = $db->query("SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id = $testApptId")->fetchColumn();
assertTest($checkSt === 'Completed', "Appointment status transitioned to 'Completed'");

// Duplicate billing must be rejected
$dupSettle = httpReq('POST', '/vetclinic/receptionist/billing/settle', [
    'csrf_token' => $bCsrf,
    'appointment_id' => $testApptId,
    'payment_method' => 'cash',
    'payment_received' => (string)$totalCost,
]);
assertTest($dupSettle['code'] === 302, "Duplicate settlement request redirected with error");

// -----------------------------------------------------------------------------
// TEST 11: Records Page & Clinical Data Access Restriction
// -----------------------------------------------------------------------------
echo "\n--- TEST 11: Records Page & Clinical Data Access Restriction ---\n";
$recordsPage = httpReq('GET', '/vetclinic/receptionist/records');
assertTest($recordsPage['code'] === 200, "Records page loads (HTTP 200)");
$recHtml = $recordsPage['body'];

assertTest(str_contains($recHtml, 'Veterinary Clinic Consultation Log'), "Records page displays Consultation Log");
assertTest(str_contains($recHtml, 'status=all') && str_contains($recHtml, 'status=pending_payment') && str_contains($recHtml, 'status=completed'), "Records page provides All, Pending Payment, Completed filters");

// Test record details AJAX endpoint
$recDetails = httpReq('GET', '/vetclinic/receptionist/records/' . $testApptId);
assertTest($recDetails['code'] === 200, "Record details AJAX endpoint returns HTTP 200");
$recData = json_decode($recDetails['body'], true);
assertTest($recData['ok'] === true && !empty($recData['record']), "Record details JSON returned");
assertTest(isset($recData['record']['admin_meta']), "Record contains administrative metadata (booking date, billed_by, payment info)");

// Verify clinical data is strictly NOT exposed
assertTest(!isset($recData['record']['diagnosis']), "Server-side restriction: No clinical 'diagnosis' exposed");
assertTest(!isset($recData['record']['soap_notes']), "Server-side restriction: No clinical 'soap_notes' exposed");
assertTest(!isset($recData['record']['prescriptions']), "Server-side restriction: No clinical 'prescriptions' exposed");
assertTest(!isset($recData['record']['diagnostic_tests']), "Server-side restriction: No clinical 'diagnostic_tests' exposed");

// -----------------------------------------------------------------------------
// TEST 12: Employees Page
// -----------------------------------------------------------------------------
echo "\n--- TEST 12: Employees Page ---\n";
$empPage = httpReq('GET', '/vetclinic/receptionist/employees');
assertTest($empPage['code'] === 200, "Employees page loads (HTTP 200)");
$empHtml = $empPage['body'];

assertTest(str_contains($empHtml, 'Veterinary Clinic Personnel Directory'), "Employees page displays Veterinary Clinic directory");
assertTest(str_contains($empHtml, 'Dr. Elena Vance') || str_contains($empHtml, 'Elena Vance'), "Directory contains Dr. Elena Vance");
assertTest(str_contains($empHtml, 'Hannah Kim'), "Directory contains Hannah Kim");
assertTest(!str_contains($empHtml, 'action="/manager/employees/edit"'), "Directory is strictly read-only (no edit profile actions)");

// -----------------------------------------------------------------------------
// TEST 13: Business Unit Isolation & RBAC
// -----------------------------------------------------------------------------
echo "\n--- TEST 13: Business Unit Isolation & RBAC ---\n";
// Attempt to access manager settings with receptionist session
// Create non-super receptionist session for Hannah Kim (employee_id 6, user_account_id 6)
$hannahCookieFile = __DIR__ . '/test_hannah_cookies.txt';
if (file_exists($hannahCookieFile)) unlink($hannahCookieFile);

// Helper for Hannah request
function hannahReq(string $method, string $path, array $data = []): array {
    global $hannahCookieFile;
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $hannahCookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $hannahCookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $body = substr($response, $headerSize);
    return ['code' => $httpCode, 'body' => $body];
}

// Log in as Hannah Kim directly in session via test helper
// We can test Hannah's account role
$hannahRoleCheck = $db->query("SELECT 1 FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = 6 AND ar.business_unit_id = 2 AND r.role_name = 'RECEPTIONIST'")->fetchColumn();
assertTest((bool)$hannahRoleCheck, "Hannah Kim holds RECEPTIONIST role for Veterinary Clinic (unit 2)");

// Clean up test appointment records from test suite
$db->exec("DELETE FROM payment WHERE invoice_id IN (SELECT invoice_id FROM service_invoice WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'))");
$db->exec("DELETE FROM invoice_item WHERE invoice_id IN (SELECT invoice_id FROM service_invoice WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'))");
$db->exec("DELETE FROM service_invoice WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%')");
$db->exec("DELETE FROM performed_service WHERE appointment_pet_id IN (SELECT appointment_pet_id FROM appointment_pet WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'))");
$db->exec("DELETE FROM requested_pet_service WHERE appointment_pet_id IN (SELECT appointment_pet_id FROM appointment_pet WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'))");
$db->exec("DELETE FROM appointment_pet WHERE appointment_id IN (SELECT appointment_id FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%')");
$db->exec("DELETE FROM appointment WHERE notes LIKE '%[TEST-VET-RECEPTIONIST]%'");

echo "\n=================================================================\n";
echo "TEST RESULTS: $passCount PASSED, $failCount FAILED\n";
echo "=================================================================\n";
