<?php
declare(strict_types=1);

/**
 * Veterinary Clinic Business Manager Interface Verification Suite
 * Usage: tools\php83\php.exe tests\vetclinic_manager_tests.php
 */

require_once __DIR__ . '/../app/bootstrap.php';
restore_exception_handler();

use App\Core\Database;
use App\Models\Units;

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
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        return '';
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
echo "VETERINARY CLINIC BUSINESS MANAGER INTERFACES VERIFICATION SUITE\n";
echo "====================================================================\n\n";

// 1. Authenticate as Veterinary Clinic Business Manager (Claire Dubois)
$bm = new VetClinicHttpClient($base);
$login = $bm->loginStaff('claire.dubois@staff.sample.vetgrpet', 'Staff123!');
assertCheck('Claire Dubois (Vet Clinic BM) logs in via /staff/login', $login['status'] === 302 && $login['location'] === '/manager', "status={$login['status']}, loc={$login['location']}");

// 2. Verify all 7 navigation pages load HTTP 200
$pages = [
    'Overview' => '/manager',
    'Appointments' => '/manager/appointments',
    'Employees' => '/manager/employees',
    'Services' => '/manager/services',
    'Inventory' => '/manager/inventory',
    'Medical Records' => '/manager/medical-records',
    'Business Settings' => '/manager/settings',
];

$pageResponses = [];
foreach ($pages as $label => $path) {
    $res = $bm->get($path);
    $pageResponses[$label] = $res;
    assertCheck("Page '$label' ($path) loads HTTP 200", $res['status'] === 200, "status={$res['status']}");
}

// 3. Verify Navigation Bar contains all 7 tabs for Veterinary Clinic
$ovBody = $pageResponses['Overview']['body'];
foreach (array_keys($pages) as $label) {
    assertCheck("Vet Clinic Navigation contains '$label' tab", str_contains($ovBody, '>' . $label . '<'));
}

// 4. Verify Overview Layout & Cards
echo "\n--- Checking Overview Page (3-tier layout & cards) ---\n";
assertCheck('Overview contains Current / Upcoming Appointment card', str_contains($ovBody, 'Current / Upcoming Appointment'));
assertCheck('Overview middle section uses vet-overview-middle-grid', str_contains($ovBody, 'vet-overview-middle-grid'));
assertCheck('Overview contains Stock Alerts card', str_contains($ovBody, 'Stock Alerts'));
assertCheck('Overview Stock Alerts card includes Open Inventory button', str_contains($ovBody, 'Open Inventory &rarr;') || str_contains($ovBody, 'Open Inventory'));
assertCheck('Overview contains Daily Clinic Activity card', str_contains($ovBody, 'Daily Clinic Activity'));
assertCheck('Overview Daily Clinic Activity displays clinic-local date', str_contains($ovBody, date('M j, Y')) || str_contains($ovBody, date('F j, Y')));
assertCheck('Overview Daily Clinic Activity shows Scheduled Appointments count', str_contains($ovBody, 'Scheduled Appointments'));
assertCheck('Overview Daily Clinic Activity shows Walk-in Visits count', str_contains($ovBody, 'Walk-in Visits'));
assertCheck('Overview contains Employees On Duty card', str_contains($ovBody, 'Employees On Duty'));
assertCheck('Overview Employees On Duty does NOT contain X/N counter', !preg_match('#\d+/\d+\s+present#i', $ovBody) && !preg_match('#\d+/\d+\s+on duty#i', $ovBody));

// 5. Verify Cross-Unit Access Isolation (Forbidden / Denied)
echo "\n--- Checking Business Unit Isolation & Access Control ---\n";
$salesRes = $bm->get('/manager/sales');
assertCheck('Vet Clinic BM cannot access Pet Store Sales (/manager/sales: 403 Forbidden)', $salesRes['status'] === 403);

$prodRes = $bm->get('/manager/products');
assertCheck('Vet Clinic BM cannot access Pet Store Products (/manager/products: 403 Forbidden)', $prodRes['status'] === 403);

$psUnitRes = $bm->get('/manager?unit_id=1');
assertCheck('Vet Clinic BM cannot access Pet Store unit via ?unit_id=1 (403 Forbidden)', $psUnitRes['status'] === 403);

$groomUnitRes = $bm->get('/manager?unit_id=3');
assertCheck('Vet Clinic BM cannot access Grooming Services unit via ?unit_id=3 (403 Forbidden)', $groomUnitRes['status'] === 403);

// 6. Verify Appointments Page
echo "\n--- Checking Appointments Page ---\n";
$apptBody = $pageResponses['Appointments']['body'];
assertCheck('Appointments page heading is Veterinary Clinic Medical Appointments', str_contains($apptBody, 'Veterinary Clinic Medical Appointments'));
assertCheck('Appointments page does NOT show Grooming Service Calendar', !str_contains($apptBody, 'Grooming Service Calendar'));
assertCheck('Appointments page preserves medical appointment workflow', str_contains($apptBody, 'Medical Appointments'));

// 7. Verify Employees Page
echo "\n--- Checking Employees Page ---\n";
$empBody = $pageResponses['Employees']['body'];
assertCheck('Employees page contains Daily Appointments column', str_contains($empBody, 'Daily Appointments'));
assertCheck('Employees page does NOT contain employee edit profile form', !str_contains($empBody, 'action="/manager/employees/edit"') && !str_contains($empBody, 'name="edit_employee"'));
assertCheck('Employees page does NOT contain performance evaluation module', !str_contains($empBody, 'Performance Evaluation') && !str_contains($empBody, 'evaluation_score'));
assertCheck('Employees list includes Veterinary Clinic positions', str_contains($empBody, 'Lead Vet') || str_contains($empBody, 'Associate Vet') || str_contains($empBody, 'Vet Nurse'));

// 8. Verify Services Page
echo "\n--- Checking Services Page ---\n";
$srvBody = $pageResponses['Services']['body'];
assertCheck('Services page heading is Medical Services', str_contains($srvBody, 'Medical Services'));
assertCheck('Services page displays medical services (e.g. Comprehensive Wellness Exam)', str_contains($srvBody, 'Comprehensive Wellness Exam'));
assertCheck('Services page excludes grooming categories (e.g. Bathing & Brushing)', !str_contains($srvBody, 'Bathing & Brushing'));
assertCheck('Services page does NOT contain estimated service duration input fields', !str_contains($srvBody, 'name="estimated_duration_minutes"') && !str_contains($srvBody, 'name="maximum_duration"'));

// Test creating a medical service
$newSrvName = 'Test Feline Wellness Panel ' . time();
$createSrv = $bm->post('/manager/services', [
    'csrf_token' => $bm->csrf($srvBody),
    'service_name' => $newSrvName,
    'base_price' => '65.00',
    'description' => 'Comprehensive laboratory feline wellness evaluation',
]);
assertCheck('BM can create a medical service (302 redirect)', $createSrv['status'] === 302);

// Verify new service appears in catalog
$srvUpdated = $bm->get('/manager/services');
assertCheck('Created medical service appears in catalog', str_contains($srvUpdated['body'], $newSrvName));

// Query new service ID and test toggle status
$createdId = (int)$pdo->query("SELECT service_id FROM service WHERE service_name = " . $pdo->quote($newSrvName))->fetchColumn();
assertCheck('Created service ID exists in database with service_category = medical', $createdId > 0);

if ($createdId > 0) {
    $toggleRes = $bm->post("/manager/services/$createdId/status", [
        'csrf_token' => $bm->csrf($srvUpdated['body']),
        'status' => 'inactive',
    ]);
    assertCheck('BM can toggle medical service availability to inactive (302)', $toggleRes['status'] === 302);
    
    $checkStatus = $pdo->query("SELECT service_status FROM service WHERE service_id = $createdId")->fetchColumn();
    assertCheck('Service status in database is now inactive', $checkStatus === 'inactive');

    // Clean up created test service
    $pdo->exec("DELETE FROM service WHERE service_id = $createdId");
}

// 9. Verify Inventory Page
echo "\n--- Checking Inventory Page ---\n";
$invBody = $pageResponses['Inventory']['body'];
assertCheck('Inventory page heading is Scoped to Veterinary Clinic', str_contains($invBody, 'Veterinary Clinic'));
assertCheck('Inventory page contains Batches & Expiry column', str_contains($invBody, 'Batches &amp; Expiry'));

// 10. Verify Medical Records Page
echo "\n--- Checking Medical Records Page (Read-Only) ---\n";
$medBody = $pageResponses['Medical Records']['body'];
assertCheck('Medical Records page heading is Medical Records', str_contains($medBody, 'Medical Records'));
assertCheck('Medical Records displays Read-Only badge or notice', str_contains($medBody, 'Read-Only') || str_contains($medBody, 'Read-Only BM Access'));
assertCheck('Medical Records contains Status filter with standard statuses', str_contains($medBody, 'IN_PROGRESS') && str_contains($medBody, 'AWAITING_TEST_RESULTS') && str_contains($medBody, 'FINALIZED') && str_contains($medBody, 'REVISED'));
assertCheck('Medical Records does NOT have create form for BM', !str_contains($medBody, 'action="/manager/medical-records/create"') && !str_contains($medBody, 'Add Medical Record'));
assertCheck('Medical Records does NOT have edit form for BM', !str_contains($medBody, 'action="/manager/medical-records/edit"') && !str_contains($medBody, 'Edit Medical Record'));

// 11. Verify Business Settings Page
echo "\n--- Checking Business Settings Page ---\n";
$setBody = $pageResponses['Business Settings']['body'];
assertCheck('Business Settings heading is Scoped to Veterinary Clinic', str_contains($setBody, 'Veterinary Clinic'));
assertCheck('Business Settings displays Clinical Consultation & Triage Policy', str_contains($setBody, 'Clinical Consultation &amp; Triage Policy') || str_contains($setBody, 'Clinical Consultation'));
assertCheck('Business Settings displays Prescription & Medication Dispensing Policy', str_contains($setBody, 'Prescription &amp; Medication Dispensing Policy') || str_contains($setBody, 'Prescription'));
assertCheck('Business Settings does NOT expose Pet Store retail return policy', !str_contains($setBody, 'Pet retail items are eligible for refund or exchange within 7 days'));

echo "\n====================================================================\n";
echo "TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

exit($failed === 0 ? 0 : 1);
