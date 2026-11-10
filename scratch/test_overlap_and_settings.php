<?php
declare(strict_types=1);

/**
 * Comprehensive Verification Test Suite for:
 * - Appointment Overlap Rules (Rules A, B, C, D)
 * - Maximum Appointment Overlap Business Settings
 * - Business Unit Isolation (Grooming vs Veterinary Clinic vs Pet Store)
 * - Calendar Integration & Receptionist Workflows
 * - Veterinary Clinic Sample Appointments
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Models\AppointmentScheduleValidator;
use App\Models\Units;

$baseUrl = 'http://127.0.0.1:8080';
$cookieFile = __DIR__ . '/cookie_overlap_test.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function req(string $url, string $method = 'GET', mixed $data = null): array {
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
        } elseif ($data !== null) {
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

function extractCsrf(string $html): string {
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/', $html, $m)) {
        return $m[1];
    }
    return '';
}

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

echo "========================================================\n";
echo "VETGRPET — Appointment Overlap & Business Settings Tests\n";
echo "========================================================\n\n";

$db = \App\Core\Database::pdo();

// -------------------------------------------------------------
// PART 1: Core Mathematical Overlap Validator Tests
// -------------------------------------------------------------
echo "--- PART 1: Core Overlap Rules Unit Verification ---\n";

// Scenario: Existing appointment A on 2026-12-01 from 10:00 AM to 11:00 AM (60m). Max allowed overlap: 15m.
// Insert temporary mock appointment A for testing
$db->beginTransaction();
$db->exec("DELETE FROM appointment WHERE notes LIKE '%[UNIT-TEST-OVERLAP]%'");
$db->prepare("INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, appointment_begin, appointment_end, notes)
              VALUES (3, 3, 5, 3, 'grooming', '2026-12-01', '10:00:00', '2026-12-01 10:00:00', '2026-12-01 11:00:00', '[UNIT-TEST-OVERLAP] Mock Base Appointment')")->execute();
$mockBaseId = (int)$db->lastInsertId();
$db->commit();

// 1. Same Start Time (10:00 AM) -> MUST BE REJECTED by Rule A
$t1 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '10:00:00', 45, 0, 15);
assertTest(!$t1['valid'] && $t1['rule'] === 'same_start', "Rule A: Identical start time (10:00 AM) rejected even with 15m overlap setting");

// 2. Start at 10:30 AM (Duration 45m, Ends 11:15 AM) -> Overlap with 10:00-11:00 is 30 mins -> MUST BE REJECTED
$t2 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '10:30:00', 45, 0, 15);
assertTest(!$t2['valid'] && $t2['rule'] === 'max_overlap' && $t2['overlap_minutes'] === 30, "Rule B: 30-minute overlap rejected (exceeds 15m max)");

// 3. Start at 10:44 AM (Duration 45m, Ends 11:29 AM) -> Overlap is 16 mins -> MUST BE REJECTED
$t3 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '10:44:00', 45, 0, 15);
assertTest(!$t3['valid'] && $t3['rule'] === 'max_overlap' && $t3['overlap_minutes'] === 16, "Rule B: 16-minute overlap rejected (exceeds 15m max)");

// 4. Start at 10:45 AM (Duration 45m, Ends 11:30 AM) -> Overlap is exactly 15 mins -> MUST BE ACCEPTED
$t4 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '10:45:00', 45, 0, 15);
assertTest($t4['valid'], "Rule B: Exactly 15-minute overlap accepted");

// 5. Start at 10:50 AM (Duration 45m, Ends 11:35 AM) -> Overlap is 10 mins -> MUST BE ACCEPTED
$t5 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '10:50:00', 45, 0, 15);
assertTest($t5['valid'], "Rule B: 10-minute overlap accepted (below 15m max)");

// 6. Start at 11:00 AM (Duration 45m, Ends 11:45 AM) -> Adjacent, 0 mins overlap -> MUST BE ACCEPTED
$t6 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '11:00:00', 45, 0, 15);
assertTest($t6['valid'], "Rule B: Zero overlap (adjacent 11:00 AM) accepted");

// 7. Rule C — Reverse Direction: New appointment starts BEFORE existing (09:30 AM to 10:20 AM, 50m dur) -> Overlap is 20m -> MUST BE REJECTED
$t7 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '09:30:00', 50, 0, 15);
assertTest(!$t7['valid'] && $t7['rule'] === 'max_overlap' && $t7['overlap_minutes'] === 20, "Rule C: Prior appointment with 20m overlap rejected (bidirectional)");

// 8. Rule C — Reverse Direction: New appointment starts BEFORE existing (09:30 AM to 10:15 AM, 45m dur) -> Overlap is 15m -> MUST BE ACCEPTED
$t8 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '09:30:00', 45, 0, 15);
assertTest($t8['valid'], "Rule C: Prior appointment with 15m overlap accepted (bidirectional)");

// 9. Appointments on a different date (2026-12-02 at 10:00 AM) -> MUST BE ACCEPTED
$t9 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-02', '10:00:00', 60, 0, 15);
assertTest($t9['valid'], "Appointments on different dates do not conflict");

// 10. Self-exclusion: editing appointment #mockBaseId at 10:00 AM -> MUST BE ACCEPTED when excluding self
$t10 = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '10:00:00', 60, $mockBaseId, 15);
assertTest($t10['valid'], "Editing an appointment excludes itself from conflict checks");

// 11. Overlap setting set to 0 -> 10:45 AM (15m overlap) must now be REJECTED; 11:00 AM (0m overlap) accepted
$t11a = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '10:45:00', 45, 0, 0);
$t11b = AppointmentScheduleValidator::validateAppointment($db, 3, '2026-12-01', '11:00:00', 45, 0, 0);
assertTest(!$t11a['valid'] && $t11a['rule'] === 'max_overlap', "When max overlap is 0m, 15m overlap is rejected");
assertTest($t11b['valid'], "When max overlap is 0m, 0m overlap (adjacent) is accepted");

// Clean up mock base appointment
$db->exec("DELETE FROM appointment WHERE appointment_id = $mockBaseId");

// -------------------------------------------------------------
// PART 2: Business Manager Settings & Business Unit Isolation
// -------------------------------------------------------------
echo "\n--- PART 2: Business Manager Settings & Isolation ---\n";

// Reset default settings
AppointmentScheduleValidator::updateMaxOverlapMinutes($db, 3, 15);
AppointmentScheduleValidator::updateMaxOverlapMinutes($db, 2, 15);

// Authenticate as Super Admin
$loginForm = req("$baseUrl/login");
$csrf = extractCsrf($loginForm['body']);
$loginResp = req("$baseUrl/demo/super-admin", 'POST', ['csrf_token' => $csrf]);
assertTest($loginResp['code'] === 302, "Super Admin login successful");

// A. Inspect Grooming Business Settings
$groomingSettingsResp = req("$baseUrl/manager/settings?unit_id=3");
assertTest($groomingSettingsResp['code'] === 200, "Grooming Business Settings page loads (HTTP 200)");
$groomingHtml = $groomingSettingsResp['body'];
assertTest(strpos($groomingHtml, 'Maximum Appointment Overlap') !== false, "Grooming Settings contains 'Maximum Appointment Overlap' label");
assertTest(strpos($groomingHtml, 'Appointments with identical start times are always prohibited') !== false, "Grooming Settings contains mandated description");
assertTest(strpos($groomingHtml, 'id="max-appointment-overlap-minutes"') !== false, "Numeric input for max overlap exists in Section 3 Business Policies");
assertTest(strpos($groomingHtml, 'Currently saved: <strong id="current-overlap-val">15 minutes</strong>') !== false, "Currently saved value displays '15 minutes' default");

// B. Save new overlap setting for Grooming (Change to 10 minutes)
$csrfSettings = extractCsrf($groomingHtml);
$saveGroomingResp = req("$baseUrl/manager/settings/overlap?unit_id=3", 'POST', [
    'csrf_token' => $csrfSettings,
    'max_appointment_overlap_minutes' => '10'
]);
assertTest($saveGroomingResp['code'] === 302, "Grooming overlap setting saved and redirected (HTTP 302)");

// Verify Grooming updated to 10m
$groomingVal = AppointmentScheduleValidator::getMaxOverlapMinutes($db, 3);
assertTest($groomingVal === 10, "Database confirms Grooming max overlap persisted as 10 minutes");

// Verify Veterinary Clinic remained 15m (Business Unit Isolation!)
$vetVal = AppointmentScheduleValidator::getMaxOverlapMinutes($db, 2);
assertTest($vetVal === 15, "Business Unit Isolation: Veterinary Clinic remained at 15 minutes when Grooming changed to 10");

// C. Inspect Veterinary Clinic Business Settings
$vetSettingsResp = req("$baseUrl/manager/settings?unit_id=2");
assertTest($vetSettingsResp['code'] === 200, "Veterinary Clinic Business Settings page loads (HTTP 200)");
$vetHtml = $vetSettingsResp['body'];
assertTest(strpos($vetHtml, 'Currently saved: <strong id="current-overlap-val">15 minutes</strong>') !== false, "Vet Clinic Settings displays '15 minutes'");

// Save new overlap setting for Vet Clinic (Change to 20 minutes)
$csrfVet = extractCsrf($vetHtml);
$saveVetResp = req("$baseUrl/manager/settings/overlap?unit_id=2", 'POST', [
    'csrf_token' => $csrfVet,
    'max_appointment_overlap_minutes' => '20'
]);
assertTest($saveVetResp['code'] === 302, "Veterinary Clinic overlap setting saved and redirected (HTTP 302)");

// Verify Vet Clinic updated to 20m while Grooming remained 10m
$vetValAfter = AppointmentScheduleValidator::getMaxOverlapMinutes($db, 2);
$groomingValAfter = AppointmentScheduleValidator::getMaxOverlapMinutes($db, 3);
assertTest($vetValAfter === 20, "Database confirms Veterinary Clinic max overlap persisted as 20 minutes");
assertTest($groomingValAfter === 10, "Business Unit Isolation: Grooming remained at 10 minutes when Vet Clinic changed to 20");

// D. Test Validation for Invalid Values: negative or non-numeric
$invalidResp1 = req("$baseUrl/manager/settings/overlap?unit_id=3", 'POST', [
    'csrf_token' => $csrfVet,
    'max_appointment_overlap_minutes' => '-5'
]);
assertTest($invalidResp1['code'] === 302, "Negative value rejected with redirect");
$postNegResp = req("$baseUrl/manager/settings?unit_id=3");
assertTest(strpos($postNegResp['body'], 'valid non-negative whole number') !== false, "Error message displayed for negative value");

$invalidResp2 = req("$baseUrl/manager/settings/overlap?unit_id=3", 'POST', [
    'csrf_token' => $csrfVet,
    'max_appointment_overlap_minutes' => '12.5'
]);
assertTest($invalidResp2['code'] === 302, "Fractional value rejected with redirect");

// E. Verify Pet Store is unaffected (no overlap settings in Pet Store)
$petStoreSettings = req("$baseUrl/manager/settings?unit_id=1");
assertTest(strpos($petStoreSettings['body'], 'id="appointment-overlap-form"') === false, "Pet Store Business Settings omits appointment overlap configuration");

// Reset Grooming to 15m and Vet Clinic to 15m for standard operation
AppointmentScheduleValidator::updateMaxOverlapMinutes($db, 3, 15);
AppointmentScheduleValidator::updateMaxOverlapMinutes($db, 2, 15);

// -------------------------------------------------------------
// PART 3: Calendar Presentation Verification
// -------------------------------------------------------------
echo "\n--- PART 3: Calendar Header Badges & View Integration ---\n";

// Grooming BM Calendar
$groomingCalResp = req("$baseUrl/manager/appointments?unit_id=3");
assertTest($groomingCalResp['code'] === 200, "Grooming BM Calendar loads successfully (HTTP 200)");
assertTest(strpos($groomingCalResp['body'], 'id="grooming-cal-overlap-badge"') !== false, "Grooming BM Calendar displays Max Overlap badge in toolbar");
assertTest(strpos($groomingCalResp['body'], 'Max Overlap: 15m') !== false, "Grooming BM Calendar badge displays 'Max Overlap: 15m'");

// Vet Clinic BM Calendar
$vetCalResp = req("$baseUrl/manager/appointments?unit_id=2");
assertTest($vetCalResp['code'] === 200, "Veterinary Clinic BM Calendar loads successfully (HTTP 200)");
assertTest(strpos($vetCalResp['body'], 'id="vet-cal-overlap-badge"') !== false, "Veterinary Clinic BM Calendar displays Max Overlap badge in toolbar");
assertTest(strpos($vetCalResp['body'], 'Max Overlap: 15m') !== false, "Veterinary Clinic BM Calendar badge displays 'Max Overlap: 15m'");

// Grooming Receptionist Calendar
$recCalResp = req("$baseUrl/grooming/receptionist/appointments?tab=calendar");
assertTest($recCalResp['code'] === 200, "Grooming Receptionist Calendar loads successfully (HTTP 200)");
assertTest(strpos($recCalResp['body'], 'id="grooming-rec-overlap-badge"') !== false, "Grooming Receptionist Calendar displays Max Overlap badge");

// -------------------------------------------------------------
// PART 4: Receptionist Walk-in & Acceptance Overlap Enforcement
// -------------------------------------------------------------
echo "\n--- PART 4: Receptionist Walk-in & Request Overlap Enforcement ---\n";

// Switch to Grooming Receptionist
req("$baseUrl/switch-interface?target=grooming-receptionist");

// Attempt to book a walk-in at 10:30 AM on 2026-11-10 (Same start time as existing #7) -> MUST FAIL Rule A
$recPage = req("$baseUrl/grooming/receptionist/appointments?tab=calendar");
$csrfRec = extractCsrf($recPage['body']);

$walkinSameStartPayload = [
    'csrf_token' => $csrfRec,
    'customer_type' => 'guest',
    'guest_name' => 'Dwight Schrute',
    'guest_phone' => '0917-000-1111',
    'pets' => [
        [
            'name' => 'Mose',
            'species' => 'Dog',
            'services' => [1] // Basic Bath & Dry (45m)
        ]
    ],
    'appointment_date' => '2026-11-10',
    'appointment_time' => '10:30', // Same start time as #7!
    'walkin_notes' => 'Test duplicate start time rejection'
];
$walkinSameResp = req("$baseUrl/grooming/receptionist/appointments/walk-in", 'POST', $walkinSameStartPayload);
assertTest($walkinSameResp['code'] === 302, "Same start time walk-in rejected with redirect (HTTP 302)");

$postSameCheck = req("$baseUrl/grooming/receptionist/appointments?tab=calendar");
assertTest(strpos($postSameCheck['body'], 'identical start times') !== false, "Error message confirms rejection due to identical start times");

// Attempt to book a walk-in with excessive overlap (e.g. 10:40 AM with 45m duration -> 35m overlap with #7) -> MUST FAIL Rule B
$walkinExcessivePayload = [
    'csrf_token' => $csrfRec,
    'customer_type' => 'guest',
    'guest_name' => 'Dwight Schrute',
    'guest_phone' => '0917-000-1111',
    'pets' => [
        [
            'name' => 'Mose',
            'species' => 'Dog',
            'services' => [1] // 45m
        ]
    ],
    'appointment_date' => '2026-11-10',
    'appointment_time' => '10:40', // 10:40 - 11:25 -> overlaps with #7 (10:30-11:15) by 35 mins!
    'walkin_notes' => 'Test excessive overlap rejection'
];
$walkinExcessResp = req("$baseUrl/grooming/receptionist/appointments/walk-in", 'POST', $walkinExcessivePayload);
assertTest($walkinExcessResp['code'] === 302, "Excessive overlap walk-in rejected with redirect (HTTP 302)");

$postExcessCheck = req("$baseUrl/grooming/receptionist/appointments?tab=calendar");
assertTest(strpos($postExcessCheck['body'], 'exceeds the allowed maximum overlap') !== false, "Error message confirms rejection due to exceeding maximum overlap");

// -------------------------------------------------------------
// PART 5: Veterinary Clinic Sample Appointments Verification
// -------------------------------------------------------------
echo "\n--- PART 5: Veterinary Clinic Sample Appointments Verification ---\n";

$vetAppts = $db->query("SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end,
                               st.status_name, s.service_name, s.estimated_duration_minutes,
                               e.first_name, e.last_name, e.position
                        FROM appointment a
                        JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                        JOIN appointment_pet ap ON ap.appointment_id = a.appointment_id
                        JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
                        JOIN service s ON s.service_id = ps.service_id
                        LEFT JOIN employee e ON e.employee_id = ps.performer_employee_id
                        WHERE a.business_unit_id = 2 AND a.notes LIKE '%[SAMPLE-VET-%'
                        ORDER BY a.preferred_date ASC, a.preferred_time ASC")->fetchAll(PDO::FETCH_ASSOC);

assertTest(count($vetAppts) >= 16, "Veterinary Clinic contains at least 16 seeded sample appointments (Found: " . count($vetAppts) . ")");

// Verify all sample appointments strictly satisfy overlap rules (Zero conflicts!)
$vetConflictFound = false;
$vetDates = array_unique(array_column($vetAppts, 'preferred_date'));
foreach ($vetDates as $vd) {
    $dayAppts = array_values(array_filter($vetAppts, fn($a) => $a['preferred_date'] === $vd));
    for ($i = 0; $i < count($dayAppts); $i++) {
        for ($j = $i + 1; $j < count($dayAppts); $j++) {
            $sA = strtotime($dayAppts[$i]['appointment_begin']);
            $eA = strtotime($dayAppts[$i]['appointment_end']);
            $sB = strtotime($dayAppts[$j]['appointment_begin']);
            $eB = strtotime($dayAppts[$j]['appointment_end']);

            if ($sA === $sB) {
                $vetConflictFound = true;
                echo "[FAIL] Same start time found on Vet date $vd: Appt #{$dayAppts[$i]['appointment_id']} vs #{$dayAppts[$j]['appointment_id']}\n";
            }
            $ov = max(0, min($eA, $eB) - max($sA, $sB));
            $ovMins = (int)ceil($ov / 60);
            if ($ovMins > 15) {
                $vetConflictFound = true;
                echo "[FAIL] Excessive overlap ({$ovMins}m > 15m) on Vet date $vd: Appt #{$dayAppts[$i]['appointment_id']} vs #{$dayAppts[$j]['appointment_id']}\n";
            }
        }
    }
}
assertTest(!$vetConflictFound, "All seeded Veterinary Clinic appointments strictly satisfy the 15-minute maximum overlap rule with zero identical start times");

// Verify Veterinary Clinic calendar rendering contains sample appointments
req("$baseUrl/switch-interface?target=super-admin");
$vetCalRender = req("$baseUrl/manager/appointments?unit_id=2&start=2026-11-09");
assertTest($vetCalRender['code'] === 200, "Veterinary Clinic calendar renders for week of 2026-11-09");
assertTest(strpos($vetCalRender['body'], 'Dr. Elena Vance') !== false, "Veterinary Clinic calendar displays Lead Veterinarian Dr. Elena Vance");
assertTest(strpos($vetCalRender['body'], 'Comprehensive Wellness Exam') !== false, "Veterinary Clinic calendar displays Comprehensive Wellness Exam");
assertTest(strpos($vetCalRender['body'], 'Veterinary Dental Assessment') !== false, "Veterinary Clinic calendar displays Dental Assessment");

// -------------------------------------------------------------
// PART 6: Regression Verification (Synthetic Records Cleaned)
// -------------------------------------------------------------
echo "\n--- PART 6: Cleanup & Final Integrity Verification ---\n";
$appt8 = $db->query("SELECT appointment_id FROM appointment WHERE appointment_id = 8")->fetchColumn();
assertTest($appt8 === false, "Synthetic test appointment #8 (conflicting 10:30 duplicate) is confirmed removed");

$apptsNov10 = $db->query("SELECT appointment_id, preferred_time FROM appointment WHERE business_unit_id = 3 AND preferred_date = '2026-11-10' ORDER BY preferred_time ASC")->fetchAll(PDO::FETCH_ASSOC);
$timesNov10 = array_column($apptsNov10, 'preferred_time');
$uniqueTimes = array_unique($timesNov10);
assertTest(count($timesNov10) === count($uniqueTimes), "Grooming schedule on 2026-11-10 has 100% unique start times (no duplicates)");

echo "\n========================================================\n";
echo "Test Suite Execution Completed!\n";
echo "Total Passed: $passCount\n";
echo "Total Failed: $failCount\n";
echo "========================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
