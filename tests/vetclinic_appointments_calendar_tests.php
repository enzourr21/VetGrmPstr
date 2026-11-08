<?php
/**
 * Veterinary Clinic Business Manager Appointments Calendar Verification Suite
 * Verifies that the Vet Clinic BM Appointments page matches the Google Calendar-inspired
 * weekly schedule and speech-bubble dialogue design of Grooming Services BM, while
 * strictly managing medical appointments only.
 */

require __DIR__ . '/../app/bootstrap.php';

$pdo = \App\Core\Database::pdo();

$baseUrl = 'http://127.0.0.1:8080';
$passed = 0;
$failed = 0;

function assertCheck(string $desc, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS: $desc\n";
    } else {
        $failed++;
        echo "  FAIL: $desc" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

final class VetClinicHttpClient
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
        if (preg_match('/name="(?:csrf_token|_csrf)"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/value="([^"]+)"\s+name="(?:csrf_token|_csrf)"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function loginStaff(string $email, string $password): array
    {
        $lp = $this->get('/staff/login');
        $token = $this->csrf($lp['body']);
        return $this->post('/staff/login', [
            'csrf_token' => $token,
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function send(string $method, string $path, ?string $body = null): array
    {
        $headers = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
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
            if ($line && preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
            } elseif ($line && stripos($line, 'Set-Cookie:') === 0 && preg_match('/:\s*([^=]+)=([^;]+)/', $line, $m)) {
                $this->cookies[trim($m[1])] = trim($m[2]);
            } elseif ($line && stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => $raw];
    }
}

echo "====================================================================\n";
echo "VET CLINIC BM APPOINTMENTS CALENDAR REFACTOR VERIFICATION SUITE\n";
echo "====================================================================\n\n";

$vetClient = new VetClinicHttpClient($baseUrl);

// 1. Vet Clinic BM Login
$loginRes = $vetClient->loginStaff('claire.dubois@staff.sample.vetgrpet', 'Staff123!');
assertCheck('Claire Dubois (Vet Clinic BM) logs in via /staff/login', $loginRes['status'] === 302);

// 2. Load Appointments Page
$apptRes = $vetClient->get('/manager/appointments');
assertCheck('Vet Clinic Appointments page loads HTTP 200', $apptRes['status'] === 200, "status={$apptRes['status']}");
$body = $apptRes['body'];

// 3. Verify Page Head & Navigation
assertCheck('Heading contains Veterinary Clinic Medical Appointments', str_contains($body, 'Veterinary Clinic Medical Appointments'));
assertCheck('Eyebrow reflects Schedule Management', str_contains($body, 'Veterinary Clinic Schedule Management'));
assertCheck('Does not claim Grooming Service Calendar', !str_contains($body, 'Grooming Service Calendar'));

// 4. Verify Calendar Navigation Row (matching Grooming design)
echo "\n--- Part 1: Calendar Navigation Controls ---\n";
assertCheck('Contains grooming-cal-toolbar card', str_contains($body, 'grooming-cal-toolbar'));
assertCheck('Contains grooming-cal-nav-row flex layout', str_contains($body, 'grooming-cal-nav-row'));
assertCheck('Contains Previous 7-day button (id="grooming-cal-prev")', str_contains($body, 'id="grooming-cal-prev"'));
assertCheck('Previous button displays arrow only (&lsaquo;)', str_contains($body, '&lsaquo;'));
assertCheck('Contains Next 7-day button (id="grooming-cal-next")', str_contains($body, 'id="grooming-cal-next"'));
assertCheck('Next button displays arrow only (&rsaquo;)', str_contains($body, '&rsaquo;'));
assertCheck('Contains Today button (id="grooming-cal-today")', str_contains($body, 'id="grooming-cal-today"'));
assertCheck('Contains Active 7-Day Range label (id="grooming-cal-range-label")', str_contains($body, 'id="grooming-cal-range-label"'));
assertCheck('Contains Month picker input (id="grooming-cal-month-picker" type="month")', str_contains($body, 'id="grooming-cal-month-picker"') && str_contains($body, 'type="month"'));

// 5. Verify 7-Day Weekly Grid Structure (matching Grooming design)
echo "\n--- Part 2: Weekly Calendar Grid Structure ---\n";
assertCheck('Contains grooming-weekly-calendar-card', str_contains($body, 'grooming-weekly-calendar-card'));
assertCheck('Contains 7-day header grid (grooming-cal-header-grid)', str_contains($body, 'grooming-cal-header-grid'));
assertCheck('Header includes GMT+8 time gutter label', str_contains($body, 'grooming-cal-time-gutter-head') && str_contains($body, 'GMT+8'));
assertCheck('Header contains day column headers (grooming-cal-day-head)', str_contains($body, 'grooming-cal-day-head'));
assertCheck('Contains vertical time grid wrap (id="grooming-cal-body-wrap")', str_contains($body, 'id="grooming-cal-body-wrap"'));
assertCheck('Contains time grid body (grooming-cal-body-grid)', str_contains($body, 'grooming-cal-body-grid'));
assertCheck('Contains left time axis gutter (grooming-cal-time-gutter)', str_contains($body, 'grooming-cal-time-gutter'));
assertCheck('Contains hourly guide cells (grooming-cal-hour-cell)', str_contains($body, 'grooming-cal-hour-cell'));
assertCheck('Contains 7 day columns (grooming-cal-day-col)', substr_count($body, 'grooming-cal-day-col') >= 7);

$allCreatedApptIds = [];

try {
    // 6. Test Medical Appointment Rendering & Speech-Bubble Interaction
    echo "\n--- Part 3: Medical Appointment Rendering & Speech-Bubble ---\n";
    // Insert test confirmed medical appointment for current week
    $today = new \DateTimeImmutable('today');
    $dow = (int)$today->format('N');
    $weekStart = $today->modify('-' . ($dow - 1) . ' days');
    $testApptDate = $weekStart->modify('+2 days')->format('Y-m-d'); // Wednesday of current week

    $pdo->exec("INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
                VALUES (2, 3, 1, 3, 'medical', '$testApptDate', '10:00:00', 'Test consultation intake note')");
    $testApptId = (int)$pdo->lastInsertId();
    $allCreatedApptIds[] = $testApptId;

    $pdo->exec("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES ($testApptId, 1)");
    $testApPetId = (int)$pdo->lastInsertId();

    $pdo->exec("INSERT INTO performed_service (requested_pet_service_id, appointment_pet_id, service_id, performed_price, quantity, performed_status, performer_employee_id)
                VALUES (NULL, $testApPetId, 8, 48.00, 1, 'pending', 1)");

    $viewRes = $vetClient->get('/manager/appointments?start=' . $weekStart->format('Y-m-d'));
    $viewBody = $viewRes['body'];

    assertCheck("Appointment block #$testApptId is rendered in time grid", str_contains($viewBody, 'id="grooming-appt-block-' . $testApptId . '"'));
    assertCheck("Appointment block #$testApptId has green status class (cal-status-green for Confirmed)", str_contains($viewBody, 'grooming-appt-block-' . $testApptId . '"') && str_contains($viewBody, 'cal-status-green'));
    assertCheck("Appointment block #$testApptId displays bold ID and time coverage", str_contains($viewBody, '#' . $testApptId) && str_contains($viewBody, '10:00 AM - 10:30 AM'));
    assertCheck("Speech-bubble dialogue is rendered (id=\"grooming-speech-bubble-$testApptId\")", str_contains($viewBody, 'id="grooming-speech-bubble-' . $testApptId . '"'));
    assertCheck("Speech-bubble includes pointer tail (.grooming-speech-bubble-tail)", str_contains($viewBody, 'class="grooming-speech-bubble-tail"'));
    assertCheck("Speech-bubble displays Customer (Evelyn Sterling)", str_contains($viewBody, 'Evelyn Sterling'));
    assertCheck("Speech-bubble displays Patient (Max)", str_contains($viewBody, 'Max'));
    assertCheck("Speech-bubble displays Medical Services (Comprehensive Wellness Exam)", str_contains($viewBody, 'Comprehensive Wellness Exam'));
    assertCheck("Speech-bubble displays Attending Clinician (Dr. Elena Vance)", str_contains($viewBody, 'Dr. Elena Vance'));
    assertCheck("Speech-bubble displays Intake Notes", str_contains($viewBody, 'Test consultation intake note'));

    // 7. Test Permitted BM Actions: Reassignment
    echo "\n--- Part 4: Clinician Reassignment & Conflict Prevention ---\n";
    $csrf = $vetClient->csrf($viewBody);
    $reassignRes = $vetClient->post("/manager/appointments/$testApptId/reassign", [
        'csrf_token' => $csrf,
        'employee_id' => 5, // Hannah Kim (Veterinary Nurse)
        'return_start' => $weekStart->format('Y-m-d'),
    ]);
    assertCheck("BM can reassign clinician to Hannah Kim (302 redirect)", $reassignRes['status'] === 302);

    // Verify reassignment took effect in database
    $newPerformer = (int)$pdo->query("SELECT performer_employee_id FROM performed_service WHERE appointment_pet_id = $testApPetId")->fetchColumn();
    assertCheck("Performer in database updated to Hannah Kim (employee #5)", $newPerformer === 5);

    // 8. Test Status Progression: Confirmed -> In Progress
    echo "\n--- Part 5: Consultation Status Progression ---\n";
    $viewAfterReassign = $vetClient->get('/manager/appointments?start=' . $weekStart->format('Y-m-d'));
    $csrf2 = $vetClient->csrf($viewAfterReassign['body']);
    $progRes = $vetClient->post("/manager/appointments/$testApptId/status", [
        'csrf_token' => $csrf2,
        'status' => 'In Progress',
        'return_start' => $weekStart->format('Y-m-d'),
    ]);
    assertCheck("BM can progress appointment to 'In Progress' (302 redirect)", $progRes['status'] === 302);
    $currStatus = (string)$pdo->query("SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id = $testApptId")->fetchColumn();
    assertCheck("Status in database is now 'In Progress'", $currStatus === 'In Progress');

    // Verify In Progress appointment renders yellow block in calendar
    $viewInProg = $vetClient->get('/manager/appointments?start=' . $weekStart->format('Y-m-d'));
    assertCheck("Appointment block has yellow status class (cal-status-yellow for In Progress)", str_contains($viewInProg['body'], 'id="grooming-appt-block-' . $testApptId . '"') && str_contains($viewInProg['body'], 'cal-status-yellow'));

    // Complete the appointment
    $csrf3 = $vetClient->csrf($viewInProg['body']);
    $compRes = $vetClient->post("/manager/appointments/$testApptId/complete", [
        'csrf_token' => $csrf3,
        'return_start' => $weekStart->format('Y-m-d'),
    ]);
    assertCheck("BM can mark consultation as Completed (302 redirect)", $compRes['status'] === 302);
    $compStatus = (string)$pdo->query("SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id = $testApptId")->fetchColumn();
    assertCheck("Status in database is now 'Completed'", $compStatus === 'Completed');

    // 9. Test Pending Medical Appointment Confirmation & Cancellation
    echo "\n--- Part 6: Pending Medical Request Confirmation & Cancellation ---\n";
    $pdo->exec("INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
                VALUES (2, 3, 1, 1, 'medical', '$testApptDate', '14:00:00', 'Pending test booking')");
    $pendingApptId = (int)$pdo->lastInsertId();
    $allCreatedApptIds[] = $pendingApptId;

    $pdo->exec("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES ($pendingApptId, 1)");
    $pendingApPetId = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO requested_pet_service (appointment_pet_id, service_id, requested_price, request_status)
                VALUES ($pendingApPetId, 9, 38.00, 'pending')");

    $viewPend = $vetClient->get('/manager/appointments?start=' . $weekStart->format('Y-m-d'));
    assertCheck("Pending appointment #$pendingApptId rendered in calendar", str_contains($viewPend['body'], 'id="grooming-appt-block-' . $pendingApptId . '"'));
    assertCheck("Pending speech bubble contains Confirm Medical Appointment form", str_contains($viewPend['body'], 'action="/manager/appointments/' . $pendingApptId . '/status"'));
    assertCheck("Pending speech bubble contains optional clinician assignment dropdown", str_contains($viewPend['body'], 'Assign Clinician (Optional upon confirmation)'));
    assertCheck("Pending speech bubble contains Decline / Cancel Request form", str_contains($viewPend['body'], 'action="/manager/appointments/' . $pendingApptId . '/cancel"'));

    // Confirm pending appointment with clinician assigned
    $csrfPend = $vetClient->csrf($viewPend['body']);
    $confRes = $vetClient->post("/manager/appointments/$pendingApptId/status", [
        'csrf_token' => $csrfPend,
        'status' => 'Confirmed',
        'employee_id' => 1, // Dr. Elena Vance
        'return_start' => $weekStart->format('Y-m-d'),
    ]);
    assertCheck("BM confirms pending medical appointment with clinician assigned (302)", $confRes['status'] === 302);
    $confStatus = (string)$pdo->query("SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id = $pendingApptId")->fetchColumn();
    assertCheck("Status in database is now 'Confirmed'", $confStatus === 'Confirmed');

    // Cancel the appointment with mandatory reason
    $viewConf = $vetClient->get('/manager/appointments?start=' . $weekStart->format('Y-m-d'));
    $csrfConf = $vetClient->csrf($viewConf['body']);
    $cancelRes = $vetClient->post("/manager/appointments/$pendingApptId/cancel", [
        'csrf_token' => $csrfConf,
        'cancellation_reason' => 'Patient owner requested reschedule to next week',
        'return_start' => $weekStart->format('Y-m-d'),
    ]);
    assertCheck("BM cancels confirmed medical appointment with reason (302)", $cancelRes['status'] === 302);
    $cancelStatus = (string)$pdo->query("SELECT st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id WHERE a.appointment_id = $pendingApptId")->fetchColumn();
    assertCheck("Status in database is now 'Cancelled'", $cancelStatus === 'Cancelled');

    // 10. Test Overlapping Medical Appointments Side-by-Side Layout
    echo "\n--- Part 7: Overlapping Medical Appointments Layout ---\n";
    $overlapDate = $weekStart->modify('+4 days')->format('Y-m-d'); // Friday of current week
    $pdo->exec("INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
                VALUES (2, 3, 1, 3, 'medical', '$overlapDate', '11:00:00', 'Overlap appt 1')");
    $ov1 = (int)$pdo->lastInsertId();
    $allCreatedApptIds[] = $ov1;

    $pdo->exec("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES ($ov1, 1)");
    $ov1Pet = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO performed_service (appointment_pet_id, service_id, performed_price, quantity, performed_status) VALUES ($ov1Pet, 8, 48.00, 1, 'pending')");

    $pdo->exec("INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
                VALUES (2, 3, 1, 3, 'medical', '$overlapDate', '11:15:00', 'Overlap appt 2')");
    $ov2 = (int)$pdo->lastInsertId();
    $allCreatedApptIds[] = $ov2;

    $pdo->exec("INSERT INTO appointment_pet (appointment_id, pet_id) VALUES ($ov2, 1)");
    $ov2Pet = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO performed_service (appointment_pet_id, service_id, performed_price, quantity, performed_status) VALUES ($ov2Pet, 9, 38.00, 1, 'pending')");

    $viewOv = $vetClient->get('/manager/appointments?start=' . $weekStart->format('Y-m-d'));
    assertCheck("Overlapping appointments on the same day are positioned side-by-side (50% width)",
        str_contains($viewOv['body'], 'left: calc(0% + 2px); width: calc(50% - 4px);') &&
        str_contains($viewOv['body'], 'left: calc(50% + 2px); width: calc(50% - 4px);')
    );

    // 11. Cross-Unit Isolation: Grooming Appointments are Excluded
    echo "\n--- Part 8: Cross-Unit Data Isolation ---\n";
    // Any grooming appointments in DB (business_unit_id = 3)
    $groomingIds = $pdo->query("SELECT appointment_id FROM appointment WHERE business_unit_id = 3")->fetchAll(PDO::FETCH_COLUMN);
    $noneLeaked = true;
    foreach ($groomingIds as $gId) {
        if (str_contains($viewOv['body'], 'id="grooming-appt-block-' . $gId . '"')) {
            $noneLeaked = false;
            break;
        }
    }
    assertCheck("Grooming Services appointments (unit #3) do NOT appear on Vet Clinic Appointments page", $noneLeaked);

    // Vet BM cannot manage Grooming appointment #1
    $csrfCross = $vetClient->csrf($viewOv['body']);
    $tryGroomingRes = $vetClient->post("/manager/appointments/1/cancel", [
        'csrf_token' => $csrfCross,
        'cancellation_reason' => 'Unauthorized cross-unit cancel test',
    ]);
    assertCheck("Vet BM cannot cancel Grooming appointment (404 Not Found)", $tryGroomingRes['status'] === 404);

    // 12. Regression Check: Grooming Business Manager Calendar Still Works Intact
    echo "\n--- Part 9: Grooming Business Manager Regression Check ---\n";
    $groomClient = new VetClinicHttpClient($baseUrl);
    $gLogin = $groomClient->loginStaff('marcus.chen@staff.sample.vetgrpet', 'Staff123!');
    assertCheck("Marcus Chen (Grooming BM) logs in via /staff/login", $gLogin['status'] === 302);
    $gApptRes = $groomClient->get('/manager/appointments');
    assertCheck("Grooming Appointments page loads HTTP 200", $gApptRes['status'] === 200);
    assertCheck("Grooming Appointments page has Grooming Appointments Calendar heading", str_contains($gApptRes['body'], 'Grooming Appointments Calendar'));
    assertCheck("Grooming Appointments page contains 7-day weekly calendar grid", str_contains($gApptRes['body'], 'grooming-weekly-calendar-card'));

} finally {
    // Clean up test records properly in safe FK dependency order
    if (!empty($allCreatedApptIds)) {
        $idStr = implode(',', $allCreatedApptIds);
        $apPetIds = $pdo->query("SELECT appointment_pet_id FROM appointment_pet WHERE appointment_id IN ($idStr)")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($apPetIds)) {
            $apPetStr = implode(',', $apPetIds);
            $pdo->exec("DELETE FROM performed_service WHERE appointment_pet_id IN ($apPetStr)");
            $pdo->exec("DELETE FROM requested_pet_service WHERE appointment_pet_id IN ($apPetStr)");
        }
        $pdo->exec("DELETE FROM appointment_pet WHERE appointment_id IN ($idStr)");
        $pdo->exec("DELETE FROM appointment WHERE appointment_id IN ($idStr)");
    }
}

echo "\n====================================================================\n";
echo "TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
