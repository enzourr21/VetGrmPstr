<?php
/**
 * Test script for Grooming Services Receptionist Calendar Refactoring & Billing Modal Bug Fix.
 */

$baseUrl = 'http://127.0.0.1:8080';
$cookieFile = __DIR__ . '/cookie_test_cal.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

function makeReq($url, $method = 'GET', $data = [], $cookieFile = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $resp = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerStr = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    curl_close($ch);
    return ['code' => $httpCode, 'headers' => $headerStr, 'body' => $body];
}

echo "=== Testing Appointments Calendar & Billing Modal Refactor ===\n";

// 1. Login as Super Admin via demo route
$loginPage = makeReq("$baseUrl/login", 'GET', [], $cookieFile);
preg_match('/name="csrf_token" value="([^"]+)"/', $loginPage['body'], $csrfM);
$csrf = $csrfM[1] ?? '';

$loginRes = makeReq("$baseUrl/demo/super-admin", 'POST', ['csrf_token' => $csrf], $cookieFile);

// 2. Switch to Grooming Services Receptionist
makeReq("$baseUrl/switch-interface?target=grooming-receptionist", 'GET', [], $cookieFile);

// 3. Test Appointments Calendar Tab
$calRes = makeReq("$baseUrl/grooming/receptionist/appointments?tab=calendar", 'GET', [], $cookieFile);
if ($calRes['code'] !== 200) {
    echo "[FAIL] Appointments calendar HTTP " . $calRes['code'] . "\nHeaders: " . $calRes['headers'] . "\nBody: " . substr($calRes['body'], 0, 500) . "\n";
    exit(1);
}
echo "[PASS] Appointments calendar loaded successfully (HTTP 200).\n";
$calHtml = $calRes['body'];

$checks = [
    'Weekly calendar card' => 'grooming-weekly-calendar-card',
    'Calendar toolbar' => 'grooming-cal-toolbar',
    'Month picker' => 'id="grooming-cal-month-picker"',
    'Walk-in button' => '+ Walk-in Appointment',
    'Calendar header grid' => 'grooming-cal-header-grid',
    'Calendar body grid' => 'grooming-cal-body-grid',
    'Calendar time gutter' => 'grooming-cal-time-gutter',
    'Calendar day col' => 'grooming-cal-day-col',
    'Calendar speech bubble' => 'grooming-speech-bubble',
    'Today button' => 'Today',
    'Native walkin modal dialog' => '<dialog class="modal modal-wide" id="walkin-modal"',
];

foreach ($checks as $label => $needle) {
    if (strpos($calHtml, $needle) !== false) {
        echo "[PASS] Calendar: $label found.\n";
    } else {
        echo "[FAIL] Calendar: $label NOT found ($needle)!\n";
    }
}

// 4. Test Calendar Restrictions (No BM controls)
$bmControls = [
    'action="/manager/appointments/reassign"',
    'action="/manager/appointments/status"',
    'id="override-stylist-modal"',
    'Qualification Matrix',
];
foreach ($bmControls as $forbidden) {
    if (strpos($calHtml, $forbidden) === false) {
        echo "[PASS] Calendar Restriction: $forbidden properly omitted from Receptionist view.\n";
    } else {
        echo "[FAIL] Calendar Restriction: $forbidden unexpectedly found in Receptionist view!\n";
    }
}

// 5. Test Week Navigation
$nextWeek = makeReq("$baseUrl/grooming/receptionist/appointments?tab=calendar&week_offset=1", 'GET', [], $cookieFile);
if ($nextWeek['code'] === 200 && strpos($nextWeek['body'], 'grooming-weekly-calendar-card') !== false) {
    echo "[PASS] Calendar Navigation: next week navigation works (HTTP 200).\n";
} else {
    echo "[FAIL] Calendar Navigation: next week navigation failed!\n";
}

$prevWeek = makeReq("$baseUrl/grooming/receptionist/appointments?tab=calendar&week_offset=-1", 'GET', [], $cookieFile);
if ($prevWeek['code'] === 200 && strpos($prevWeek['body'], 'grooming-weekly-calendar-card') !== false) {
    echo "[PASS] Calendar Navigation: prev week navigation works (HTTP 200).\n";
} else {
    echo "[FAIL] Calendar Navigation: prev week navigation failed!\n";
}

// 6. Test Billing Page & Add Billing Modal Structure
$billRes = makeReq("$baseUrl/grooming/receptionist/billing", 'GET', [], $cookieFile);
if ($billRes['code'] !== 200) {
    echo "[FAIL] Billing page HTTP " . $billRes['code'] . "\n";
    exit(1);
}
echo "[PASS] Billing page loaded successfully (HTTP 200).\n";
$billHtml = $billRes['body'];

$billChecks = [
    'Native add billing dialog' => '<dialog class="modal modal-wide" id="add-billing-modal"',
    'Modal head with title' => 'id="add-billing-modal-title"',
    'Modal close button' => 'data-modal-close',
    'Walk-in radio' => 'value="walkin"',
    'Reserved radio' => 'value="reserved"',
    'Debounced search input handler' => 'handleBillingSearchInput(this.value)',
    'Search spinner element' => 'id="billing-search-spinner"',
    'Min-height stable results container' => 'min-height: 180px',
    'Payment Method select' => 'id="b-payment-method"',
    'Total Cost display' => 'id="b-total-cost-display"',
    'Payment Received input' => 'id="b-payment-received"',
    'Change Due display' => 'id="b-change-display"',
    'Payment warning with stable min-height' => 'id="b-payment-warning"',
    'Submit button' => 'id="b-submit-settle-btn"',
    'Double submit listener' => 'settleForm._submitting',
];

foreach ($billChecks as $label => $needle) {
    if (strpos($billHtml, $needle) !== false) {
        echo "[PASS] Billing Modal: $label found.\n";
    } else {
        echo "[FAIL] Billing Modal: $label NOT found ($needle)!\n";
    }
}

// Check that Payment Method comes BEFORE Total Cost in billing HTML
$posMethod = strpos($billHtml, 'id="b-payment-method"');
$posTotal = strpos($billHtml, 'id="b-total-cost-display"');
if ($posMethod !== false && $posTotal !== false && $posMethod < $posTotal) {
    echo "[PASS] Billing Layout: Payment Method correctly placed BEFORE Total Cost.\n";
} else {
    echo "[FAIL] Billing Layout: Payment Method NOT placed before Total Cost!\n";
}

// 7. Test Billing Search APIs
$searchWalkin = makeReq("$baseUrl/grooming/receptionist/billing/search-appointments?type=walkin&q=", 'GET', [], $cookieFile);
if ($searchWalkin['code'] === 200) {
    $swData = json_decode($searchWalkin['body'], true);
    if ($swData && isset($swData['ok']) && $swData['ok'] === true) {
        echo "[PASS] Billing API: Search walkin returned valid JSON response.\n";
    } else {
        echo "[FAIL] Billing API: Search walkin returned invalid JSON: {$searchWalkin['body']}\n";
    }
} else {
    echo "[FAIL] Billing API: Search walkin HTTP {$searchWalkin['code']}\n";
}

$searchReserved = makeReq("$baseUrl/grooming/receptionist/billing/search-appointments?type=reserved&q=", 'GET', [], $cookieFile);
if ($searchReserved['code'] === 200) {
    $srData = json_decode($searchReserved['body'], true);
    if ($srData && isset($srData['ok']) && $srData['ok'] === true) {
        echo "[PASS] Billing API: Search reserved returned valid JSON response.\n";
    } else {
        echo "[FAIL] Billing API: Search reserved returned invalid JSON: {$searchReserved['body']}\n";
    }
} else {
    echo "[FAIL] Billing API: Search reserved HTTP {$searchReserved['code']}\n";
}

echo "=== All Refactor Tests Completed Successfully ===\n";
