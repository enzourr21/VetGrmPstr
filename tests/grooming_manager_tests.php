<?php
declare(strict_types=1);

/**
 * Grooming Services Business Manager Overview & Appointments Verification Suite
 * Usage: tools\php83\php.exe tests\grooming_manager_tests.php
 */

require_once __DIR__ . '/../app/bootstrap.php';
restore_exception_handler();

use App\Core\Database;

$pdo = Database::pdo();
$base = getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8080';
$passed = 0;
$failed = 0;

function assertCheck(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failed++;
        echo "  FAIL: $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

final class GroomingManagerHttpClient
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
        return '';
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
        $raw = (string)@file_get_contents($this->base . $path, false, $ctx);
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

    public function loginStaff(string $email, string $password): array
    {
        $lp = $this->get('/staff/login');
        return $this->post('/staff/login', [
            'csrf_token' => $this->csrf($lp['body']),
            'email' => $email,
            'password' => $password,
        ]);
    }
}

echo "====================================================================\n";
echo "GROOMING SERVICES BUSINESS MANAGER OVERVIEW & APPOINTMENTS SUITE\n";
echo "====================================================================\n\n";

// 1. Login as Grooming Services Business Manager (Marcus Chen)
$gm = new GroomingManagerHttpClient($base);
$loginRes = $gm->loginStaff('marcus.chen@staff.sample.vetgrpet', 'Staff123!');
assertCheck('Grooming Business Manager (Marcus Chen) logs in via /staff/login (302 -> /manager)', $loginRes['status'] === 302 && $loginRes['location'] === '/manager');

// 2. Fetch Grooming Overview (/manager)
$ov = $gm->get('/manager');
assertCheck('Grooming Overview renders HTTP 200', $ov['status'] === 200);
assertCheck('Grooming Overview heading is Grooming Services Overview', str_contains($ov['body'], 'Grooming Services Overview'));

// 3. Navigation labels preserved
$navLabels = ['Overview', 'Appointments', 'Employees', 'Services', 'Inventory', 'Business Settings'];
foreach ($navLabels as $lbl) {
    assertCheck("Grooming Business Manager navigation contains '$lbl'", str_contains($ov['body'], '>' . $lbl . '<'));
}

// 4. Vertical 3-Tier Overview Layout

// A. Top Section — Wide Current/Upcoming Appointment Card (Full Content Width)
assertCheck('Top Section is wide Current / Upcoming Appointment card (#3)',
    str_contains($ov['body'], 'id="grooming-overview-appointment-card"') &&
    str_contains($ov['body'], 'grooming-wide-appointment-card') &&
    str_contains($ov['body'], '#3') &&
    str_contains($ov['body'], 'Jordan Cruz') &&
    str_contains($ov['body'], 'Buddy') &&
    str_contains($ov['body'], 'Golden Retriever') &&
    (str_contains($ov['body'], 'Basic Bath &amp; Dry') || str_contains($ov['body'], 'Basic Bath & Dry')) &&
    str_contains($ov['body'], 'De-shedding Treatment') &&
    str_contains($ov['body'], 'Owen Bennett') &&
    str_contains($ov['body'], 'Confirmed')
);

// D. Catalog Items Removed Entirely
assertCheck('Catalog Items card is removed from Grooming Overview entirely',
    !str_contains($ov['body'], 'Catalog Items') &&
    !str_contains($ov['body'], 'id="grooming-overview-first-card"')
);

// B. Middle Section — Side-by-Side 2-Column Grid (Pending Refunds & Stock Alerts)
assertCheck('Middle section has Pending Refunds and Stock Alerts side-by-side directly below Appointment card with equal-height stretch layout',
    str_contains($ov['body'], 'id="grooming-overview-pending-refunds-card"') &&
    str_contains($ov['body'], 'id="grooming-stock-alerts"') &&
    str_contains($ov['body'], 'grooming-overview-middle-grid') &&
    str_contains($ov['body'], 'grooming-overview-equal-card') &&
    str_contains($ov['body'], 'align-items: stretch') &&
    strpos($ov['body'], 'id="grooming-overview-appointment-card"') < strpos($ov['body'], 'id="grooming-overview-pending-refunds-card"') &&
    strpos($ov['body'], 'id="grooming-overview-appointment-card"') < strpos($ov['body'], 'id="grooming-stock-alerts"')
);

// Test Pending Refunds card with real database refund row for Grooming Services (unit 3)
$jcCid = (int)$pdo->query("SELECT customer_id FROM customer WHERE first_name = 'Jordan' AND last_name = 'Cruz' LIMIT 1")->fetchColumn() ?: 1;
$pdo->exec("INSERT INTO refund_request (refund_reference, business_unit_id, customer_id, channel, requested_amount, customer_reason, request_status)
            VALUES ('REF-GRM-3001', 3, $jcCid, 'walkin', 25.00, 'Sensitive skin reaction to shampoo', 'pending')");
$ovWithRefund = $gm->get('/manager');
assertCheck('Pending Refunds card dynamically displays real Grooming refund request (REF-GRM-3001, $25.00, Jordan Cruz)',
    str_contains($ovWithRefund['body'], 'REF-GRM-3001') &&
    str_contains($ovWithRefund['body'], '$25.00') &&
    str_contains($ovWithRefund['body'], 'Sensitive skin reaction to shampoo') &&
    !str_contains($ovWithRefund['body'], 'REF-REQ-1001') // Pet Store refund excluded
);
$pdo->exec("DELETE FROM refund_request WHERE refund_reference = 'REF-GRM-3001'");

// Middle Section Right — Stock Alerts
assertCheck('Stock Alerts displays Product Name and Current Quantity only for Grooming low stock items',
    str_contains($ov['body'], 'Leave-in Conditioner Spray') &&
    str_contains($ov['body'], 'Nail Clippers') &&
    !str_contains($ov['body'], 'Adult Dog Kibble') // Pet Store product excluded
);

// C. Bottom Section — Employees Present (below middle cards)
assertCheck('Employees Present section exists at bottom below both middle cards',
    str_contains($ov['body'], 'id="grooming-employees-present"') &&
    strpos($ov['body'], 'id="grooming-overview-pending-refunds-card"') < strpos($ov['body'], 'id="grooming-employees-present"') &&
    strpos($ov['body'], 'id="grooming-stock-alerts"') < strpos($ov['body'], 'id="grooming-employees-present"')
);
$empRowCount = substr_count($ov['body'], 'class="grooming-employee-present-row"');
assertCheck('Employees Present displays up to 3 employees (actual count: ' . $empRowCount . ')', $empRowCount >= 1 && $empRowCount <= 3);
assertCheck('Employees Present shows Owen Bennett with corrected role Lead Stylist and Marcus Chen with Business Manager',
    str_contains($ov['body'], 'Owen Bennett') &&
    str_contains($ov['body'], 'Lead Stylist') &&
    !str_contains($ov['body'], 'Master Stylist') &&
    str_contains($ov['body'], 'Marcus Chen') &&
    str_contains($ov['body'], 'Business Manager')
);
assertCheck('Employees Present excludes Delivery Personnel', !str_contains($ov['body'], 'Delivery Personnel'));

preg_match('/<section[^>]*id="grooming-employees-present"[^>]*>([\s\S]*?)<\/section>/i', $ov['body'], $empSecMatch);
$empSecHtml = $empSecMatch[1] ?? '';
assertCheck('Employees Present does NOT display x/n employee count or percentages',
    $empSecHtml !== '' &&
    !preg_match('/\d+\s*\/\s*\d+/', $empSecHtml) &&
    !str_contains($empSecHtml, '%') &&
    !preg_match('/Employees Present\s*\(\d+/', $empSecHtml)
);

// 7. Removed / Excluded Overview Sections
assertCheck('Overview does NOT include Today\'s Grooming Schedule', !str_contains($ov['body'], "Today's Grooming Schedule"));
assertCheck('Overview does NOT include Salon Stock Advisory', !str_contains($ov['body'], 'Salon Stock Advisory'));

// 8. Grooming Services Appointments Page (/manager/appointments)
echo "\n--- Part 2: Grooming Appointments Weekly Calendar & Speech-Bubble Dialogue ---\n";
$cal = $gm->get('/manager/appointments?start=2026-11-02');
assertCheck('Appointments page renders HTTP 200', $cal['status'] === 200);
assertCheck('Appointments page is a 7-day weekly calendar, NOT a table',
    str_contains($cal['body'], 'id="grooming-weekly-calendar-card"') &&
    !str_contains($cal['body'], '<table')
);
$dayHeadCount = substr_count($cal['body'], 'class="grooming-cal-day-head');
$dayColCount = substr_count($cal['body'], 'class="grooming-cal-day-col');
assertCheck('Weekly calendar always renders exactly 7 chronological day headers and 7 day columns', $dayHeadCount === 7 && $dayColCount === 7);

// Check Previous & Next navigation controls (-7 days / +7 days)
assertCheck('Previous control links to -7 days (2026-10-26) and Next control links to +7 days (2026-11-09)',
    str_contains($cal['body'], 'id="grooming-cal-prev"') &&
    str_contains($cal['body'], 'start=2026-10-26') &&
    str_contains($cal['body'], 'id="grooming-cal-next"') &&
    str_contains($cal['body'], 'start=2026-11-09')
);
assertCheck('Previous and Next navigation buttons display arrows only (no Previous or Next text)',
    !preg_match('/id="grooming-cal-prev"[^>]*>\s*Previous/i', $cal['body']) &&
    !preg_match('/id="grooming-cal-next"[^>]*>\s*Next/i', $cal['body']) &&
    (str_contains($cal['body'], '&lsaquo;') || str_contains($cal['body'], '‹')) &&
    (str_contains($cal['body'], '&rsaquo;') || str_contains($cal['body'], '›'))
);
assertCheck('Today control is present in navigation row', str_contains($cal['body'], 'id="grooming-cal-today"'));
assertCheck('Month picker input is present and synced to 2026-11 without visible Month: label',
    str_contains($cal['body'], 'id="grooming-cal-month-picker"') &&
    str_contains($cal['body'], 'value="2026-11"') &&
    !str_contains($cal['body'], 'Month:')
);
assertCheck('Status legend and indicator boxes are completely removed from Appointments toolbar',
    !str_contains($cal['body'], 'grooming-cal-legend') &&
    !str_contains($cal['body'], 'Green (Confirmed)') &&
    !str_contains($cal['body'], 'Yellow (In Progress / Pending)') &&
    !str_contains($cal['body'], 'Red (Cancelled / Rejected)') &&
    !str_contains($cal['body'], 'Transparent (Completed / No-Show)')
);
assertCheck('Appointment blocks retain status color styling classes',
    str_contains($cal['body'], 'cal-status-green')
);

// Selecting a month starts the calendar at the first day of that selected month (YYYY-MM-01)
$calMonthPick = $gm->get('/manager/appointments?month=2026-11');
assertCheck('Selecting a month (2026-11) starts the 7-day calendar at 2026-11-01 through 2026-11-07',
    str_contains($calMonthPick['body'], 'data-date="2026-11-01"') &&
    str_contains($calMonthPick['body'], 'data-date="2026-11-07"') &&
    substr_count($calMonthPick['body'], 'class="grooming-cal-day-col') === 7
);

// Crossing a month boundary updates the month picker and renders 7 chronological dates across month boundary
$calCrossMonth = $gm->get('/manager/appointments?start=2026-10-28');
assertCheck('Crossing October -> November boundary renders Oct 28..31 and Nov 1..3 with month picker 2026-10',
    str_contains($calCrossMonth['body'], 'data-date="2026-10-28"') &&
    str_contains($calCrossMonth['body'], 'data-date="2026-10-31"') &&
    str_contains($calCrossMonth['body'], 'data-date="2026-11-01"') &&
    str_contains($calCrossMonth['body'], 'data-date="2026-11-03"') &&
    str_contains($calCrossMonth['body'], 'value="2026-10"')
);

// Crossing a year boundary updates the month picker and renders 7 chronological dates across year boundary
$calCrossYear = $gm->get('/manager/appointments?start=2026-12-28');
assertCheck('Crossing 2026 -> 2027 year boundary renders Dec 28..31, 2026 and Jan 1..3, 2027',
    str_contains($calCrossYear['body'], 'data-date="2026-12-28"') &&
    str_contains($calCrossYear['body'], 'data-date="2026-12-31"') &&
    str_contains($calCrossYear['body'], 'data-date="2027-01-01"') &&
    str_contains($calCrossYear['body'], 'data-date="2027-01-03"')
);

// Leap year handling (2028 is a leap year; 2026 is not)
$calLeap = $gm->get('/manager/appointments?start=2028-02-26');
assertCheck('Leap year (Feb 2028) includes 2028-02-29 followed by 2028-03-01..03',
    str_contains($calLeap['body'], 'data-date="2028-02-28"') &&
    str_contains($calLeap['body'], 'data-date="2028-02-29"') &&
    str_contains($calLeap['body'], 'data-date="2028-03-01"') &&
    substr_count($calLeap['body'], 'class="grooming-cal-day-col') === 7
);
$calNonLeap = $gm->get('/manager/appointments?start=2026-02-25');
assertCheck('Non-leap year (Feb 2026) goes directly from 2026-02-28 to 2026-03-01',
    str_contains($calNonLeap['body'], 'data-date="2026-02-28"') &&
    !str_contains($calNonLeap['body'], 'data-date="2026-02-29"') &&
    str_contains($calNonLeap['body'], 'data-date="2026-03-01"')
);

// Appointment block content & status colors (Red, Green, Yellow, Transparent)
assertCheck('Appointment block #3 displays bold Appointment ID (#3) and scheduled time coverage underneath (11:00 AM - 12:25 PM)',
    str_contains($cal['body'], '<strong class="grooming-cal-block-id">#3</strong>') &&
    str_contains($cal['body'], '<span class="grooming-cal-block-time">11:00 AM - 12:25 PM</span>') &&
    str_contains($cal['body'], 'cal-status-green')
);

// Speech-bubble dialogue box with pointer/tail
assertCheck('Speech-bubble dialogue box is rendered with pointer/tail (.grooming-speech-bubble-tail)',
    str_contains($cal['body'], 'id="grooming-speech-bubble-3"') &&
    str_contains($cal['body'], 'class="grooming-speech-bubble-tail"') &&
    str_contains($cal['body'], 'Jordan Cruz') &&
    str_contains($cal['body'], 'Buddy (Golden Retriever)') &&
    str_contains($cal['body'], 'Basic Bath &amp; Dry, De-shedding Treatment')
);

// Test overlapping appointments side-by-side layout on the same day
$buddyId = (int)$pdo->query("SELECT pet_id FROM pet WHERE pet_name = 'Buddy' LIMIT 1")->fetchColumn();
$miloId = (int)$pdo->query("SELECT pet_id FROM pet WHERE pet_name = 'Milo' LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
            VALUES (3, 3, $jcCid, 1, 'grooming', '2026-11-05', '11:15:00', 'Overlap test pending request')");
$overlapApptId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES ($overlapApptId, $buddyId)");
$overlapApPetId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO requested_pet_service (appointment_pet_id, service_id, requested_price, request_status) VALUES ($overlapApPetId, 1, 25.00, 'pending')");

$calOverlap = $gm->get('/manager/appointments?start=2026-11-02');
assertCheck('Overlapping appointments on the same day are laid out side-by-side (width: calc(50% - 4px))',
    str_contains($calOverlap['body'], 'left: calc(0% + 2px); width: calc(50% - 4px);') &&
    str_contains($calOverlap['body'], 'left: calc(50% + 2px); width: calc(50% - 4px);')
);

// Test Appointment Management Permissions:
// 1) Pending appointment cannot be reassigned or cancelled by Business Manager
$csrfCal = $gm->csrf($calOverlap['body']);
$tryReassignPending = $gm->post("/manager/appointments/$overlapApptId/reassign", [
    'csrf_token' => $csrfCal,
    'employee_id' => 6,
    'return_start' => '2026-11-02',
]);
$afterPendingCheck = $gm->get($tryReassignPending['location'] ?? '/manager/appointments?start=2026-11-02');
assertCheck('Business Manager cannot reassign Pending customer request (blocked with Receptionist workflow notice)',
    str_contains($afterPendingCheck['body'], 'Pending customer requests must first be processed by the Receptionist')
);

// 2) Confirmed appointment cancellation requires non-empty reason
$tryCancelEmpty = $gm->post('/manager/appointments/3/cancel', [
    'csrf_token' => $csrfCal,
    'cancellation_reason' => '   ',
    'return_start' => '2026-11-02',
]);
$stAfterEmptyCancel = (string)$pdo->query("SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id = 3")->fetchColumn();
assertCheck('Confirmed appointment cancellation without reason is rejected (status remains Confirmed)', $stAfterEmptyCancel === 'Confirmed');

// 3) Employee scheduling conflict check on reassignment
$pdo->exec("UPDATE appointment SET appointment_status_id = 3, preferred_time = '14:00:00' WHERE appointment_id = $overlapApptId");
$pdo->exec("INSERT INTO performed_service (requested_pet_service_id, appointment_pet_id, service_id, performed_price, quantity, performed_status, performer_employee_id)
            VALUES (NULL, $overlapApPetId, 1, 25.00, 1, 'pending', 6)");

$pdo->exec("INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
            VALUES (3, 3, $jcCid, 3, 'grooming', '2026-11-05', '14:15:00', 'Conflict test confirmed appointment')");
$conflictApptId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES ($conflictApptId, $miloId)");
$conflictApPetId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO requested_pet_service (appointment_pet_id, service_id, requested_price, request_status) VALUES ($conflictApPetId, 1, 25.00, 'approved')");

$tryConflictReassign = $gm->post("/manager/appointments/$conflictApptId/reassign", [
    'csrf_token' => $csrfCal,
    'employee_id' => 6,
    'return_start' => '2026-11-02',
]);
$conflictFollow = $gm->get($tryConflictReassign['location'] ?? '/manager/appointments?start=2026-11-02');
assertCheck('Employee scheduling conflict is detected and blocked when reassigning Owen Bennett to overlapping time',
    str_contains($conflictFollow['body'], 'Scheduling conflict: Owen Bennett is already assigned')
);

// Move $conflictApptId to non-overlapping time 16:00 and reassign to Owen Bennett (employee 6) -> succeeds!
$pdo->exec("UPDATE appointment SET preferred_time = '16:00:00' WHERE appointment_id = $conflictApptId");
$validReassign = $gm->post("/manager/appointments/$conflictApptId/reassign", [
    'csrf_token' => $csrfCal,
    'employee_id' => 6,
    'return_start' => '2026-11-02',
]);
$assignedEmpId = (int)$pdo->query("SELECT ps.performer_employee_id FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id WHERE ap.appointment_id = $conflictApptId LIMIT 1")->fetchColumn();
assertCheck('Confirmed appointment reassignment to available Grooming stylist succeeds', $assignedEmpId === 6);

// Cancel $conflictApptId with a valid non-empty reason -> succeeds!
$validCancel = $gm->post("/manager/appointments/$conflictApptId/cancel", [
    'csrf_token' => $csrfCal,
    'cancellation_reason' => 'Customer requested reschedule due to travel',
    'return_start' => '2026-11-02',
]);
$cancelledStatus = (string)$pdo->query("SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id = $conflictApptId")->fetchColumn();
assertCheck('Confirmed appointment cancellation with valid reason updates status to Cancelled', $cancelledStatus === 'Cancelled');

// Clean up temporary test appointments
$pdo->exec("DELETE ps FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id WHERE ap.appointment_id IN ($overlapApptId, $conflictApptId)");
$pdo->exec("DELETE rps FROM requested_pet_service rps JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id WHERE ap.appointment_id IN ($overlapApptId, $conflictApptId)");
$pdo->exec("DELETE FROM appointment_pet WHERE appointment_id IN ($overlapApptId, $conflictApptId)");
$pdo->exec("DELETE FROM appointment WHERE appointment_id IN ($overlapApptId, $conflictApptId)");
$pdo->exec("DELETE FROM audit_log WHERE target_type = 'appointment' AND target_id IN ($overlapApptId, $conflictApptId)");

// 9. Verify all 6 Grooming Business Manager Navigation pages render 200
foreach (['/manager', '/manager/appointments', '/manager/employees', '/manager/services', '/manager/inventory', '/manager/settings'] as $navPath) {
    $res = $gm->get($navPath);
    assertCheck("Grooming Business Manager route $navPath renders HTTP 200", $res['status'] === 200);
}

// 10. Cross-unit isolation & regression checks for Pet Store (Unit 1) and Vet Clinic (Unit 2)
$crossPet = $gm->get('/manager?unit_id=1');
assertCheck('Grooming Business Manager denied access to Pet Store (403)', $crossPet['status'] === 403);
$crossVet = $gm->get('/manager?unit_id=2');
assertCheck('Grooming Business Manager denied access to Veterinary Clinic (403)', $crossVet['status'] === 403);

$psClient = new GroomingManagerHttpClient($base);
$psClient->loginStaff('david.reyes@staff.sample.vetgrpet', 'Staff123!');
$psOv = $psClient->get('/manager');
assertCheck('Pet Store Business Manager Overview preserved without regression (200)', $psOv['status'] === 200 && str_contains($psOv['body'], 'Pet Store Overview'));

$vcClient = new GroomingManagerHttpClient($base);
$vcClient->loginStaff('claire.dubois@staff.sample.vetgrpet', 'Staff123!');
$vcOv = $vcClient->get('/manager');
assertCheck('Veterinary Clinic Business Manager Dashboard preserved without regression (200)', $vcOv['status'] === 200 && str_contains($vcOv['body'], 'Veterinary Clinic Business Manager Dashboard'));

echo "\n====================================================================\n";
echo "GROOMING MANAGER VERIFICATION RESULTS: $passed passed, $failed failed\n";
echo "====================================================================\n";
exit($failed === 0 ? 0 : 1);

