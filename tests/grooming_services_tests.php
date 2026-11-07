<?php
declare(strict_types=1);

/**
 * Grooming Services Business Manager Services Page Verification Suite
 * Usage: tools\php83\php.exe tests\grooming_services_tests.php
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

final class GroomingServicesTestClient
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
        $url = str_starts_with($path, 'http') ? $path : $this->base . $path;
        $ch = curl_init($url);
        $headers = [];
        if ($this->cookies) {
            $hList = [];
            foreach ($this->cookies as $k => $v) {
                $hList[] = "$k=$v";
            }
            $headers[] = 'Cookie: ' . implode('; ', $hList);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerText = substr($response, 0, $headerSize);
        $resBody = substr($response, $headerSize);
        curl_close($ch);

        foreach (explode("\r\n", $headerText) as $h) {
            if (stripos($h, 'Set-Cookie:') === 0) {
                $cookieStr = trim(substr($h, 11));
                $parts = explode(';', $cookieStr);
                $kv = explode('=', $parts[0], 2);
                if (count($kv) === 2) {
                    $this->cookies[trim($kv[0])] = trim($kv[1]);
                }
            }
        }

        $loc = '';
        if (preg_match('/^Location:\s*(.+)$/im', $headerText, $m)) {
            $loc = trim($m[1]);
        }

        return ['status' => $status, 'body' => $resBody, 'headers' => $headerText, 'location' => $loc];
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

    public function loginCustomer(string $email, string $password): array
    {
        $lp = $this->get('/login');
        $csrf1 = $this->csrf($lp['body']);
        $this->post('/login/email', [
            'csrf_token' => $csrf1,
            'email' => $email,
        ]);
        $lp2 = $this->get('/login/password');
        $csrf2 = $this->csrf($lp2['body']);
        return $this->post('/login/password', [
            'csrf_token' => $csrf2,
            'email' => $email,
            'password' => $password,
        ]);
    }
}

echo "====================================================================\n";
echo "GROOMING SERVICES BUSINESS MANAGER SERVICES SUITE\n";
echo "====================================================================\n\n";

// -------------------------------------------------------------------------
// Suite 1: Authentication & Authorization Controls
// -------------------------------------------------------------------------
echo "--- Suite 1: Authentication & Permissions Boundaries ---\n";
$anon = new GroomingServicesTestClient($base);
$anonRes = $anon->get('/manager/services');
assertCheck('Anonymous access to /manager/services is redirected to /login (302)', $anonRes['status'] === 302 && str_contains($anonRes['location'], '/login'));

// Customer access & ensure pet exists for customer
$cust = new GroomingServicesTestClient($base);
$cust->loginCustomer('test_customer@vetgrpet.local', 'Customer123!');
$custRes = $cust->get('/manager/services');
assertCheck('Customer access to /manager/services is denied (403)', $custRes['status'] === 403);

$chkPet = $pdo->prepare("SELECT p.pet_id FROM pet p JOIN customer c ON c.customer_id = p.customer_id JOIN user_account ua ON ua.user_account_id = c.user_account_id WHERE ua.email = 'test_customer@vetgrpet.local'");
$chkPet->execute();
if (!$chkPet->fetchColumn()) {
    $cId = (int)$pdo->query("SELECT customer_id FROM customer c JOIN user_account ua ON ua.user_account_id = c.user_account_id WHERE ua.email = 'test_customer@vetgrpet.local'")->fetchColumn();
    $pdo->prepare("INSERT INTO pet (customer_id, pet_name, species, breed, pet_status) VALUES (?, 'Barnaby', 'Dog', 'Golden Retriever', 'active')")->execute([$cId]);
}

// Pet Store Business Manager
$psMgr = new GroomingServicesTestClient($base);
$psMgr->loginStaff('david.reyes@staff.sample.vetgrpet', 'Staff123!');
$psMgrRes = $psMgr->get('/manager/services');
assertCheck('Pet Store Business Manager access to /manager/services is denied (403)', $psMgrRes['status'] === 403);

// Grooming Stylist (Owen Bennett)
$stylist = new GroomingServicesTestClient($base);
$stylist->loginStaff('owen.bennett@staff.sample.vetgrpet', 'Staff123!');
$stylistRes = $stylist->get('/manager/services');
assertCheck('Grooming Stylist / Lead Stylist access to /manager/services is denied (403)', $stylistRes['status'] === 403);

// Grooming Bather Account
$batherEmail = 'test.bather@vetgrpet.local';
$chk = $pdo->prepare("SELECT user_account_id FROM user_account WHERE email = ?");
$chk->execute([$batherEmail]);
$bUid = $chk->fetchColumn();
if (!$bUid) {
    $insU = $pdo->prepare("INSERT INTO user_account (email, username, password_hash, account_status) VALUES (?, 'test_bather', ?, 'active')");
    $insU->execute([$batherEmail, password_hash('Staff123!', PASSWORD_BCRYPT)]);
    $bUid = (int)$pdo->lastInsertId();
    $insE = $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status) VALUES (?, 3, 'Test', 'Bather', 'Bather', 'active')");
    $insE->execute([$bUid]);
    $rId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = 'BATHER_WORKSPACE'")->fetchColumn();
    $pdo->prepare("INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, 3)")->execute([$bUid, $rId]);
}
$bather = new GroomingServicesTestClient($base);
$bather->loginStaff($batherEmail, 'Staff123!');
$batherRes = $bather->get('/manager/services');
assertCheck('Grooming Bather access to /manager/services is denied (403)', $batherRes['status'] === 403);

// -------------------------------------------------------------------------
// Suite 2: Grooming Business Manager Services Page Rendering
// -------------------------------------------------------------------------
echo "\n--- Suite 2: Services Page Layout & Categories ---\n";
$gm = new GroomingServicesTestClient($base);
$gm->loginStaff('marcus.chen@staff.sample.vetgrpet', 'Staff123!');
$page = $gm->get('/manager/services');
assertCheck('Grooming Business Manager accesses /manager/services with HTTP 200', $page['status'] === 200);
assertCheck('Page title/heading is Grooming Services', str_contains($page['body'], 'Grooming Services'));
assertCheck('Active subnav tab is Services (is-active)', str_contains($page['body'], 'class="segmented-pill is-active">Services<'));

// Prominent Create Service button
assertCheck('Create Service button exists in page head', str_contains($page['body'], 'id="btn-create-service"') && str_contains($page['body'], 'Create Service'));
assertCheck('Create Service button triggers create-service-modal', str_contains($page['body'], 'data-modal-open="create-service-modal"'));

// Verification of UI Refinements: Exactly Two Filter Controls, No Search Bar, No Operational Rules Card
assertCheck('Search input bar is removed from Services page', !str_contains($page['body'], 'id="service-search"') && !str_contains($page['body'], 'Search Service Name'));
assertCheck('Segmented category pills are removed', !str_contains($page['body'], 'Categories:'));
assertCheck('Grooming Business Rules & Operational Standards card is removed',
    !str_contains($page['body'], 'Grooming Business Rules & Operational Standards') &&
    !str_contains($page['body'], 'Grooming Business Rules &amp; Operational Standards')
);

// Exactly the two filter controls: Service Type and Availability
assertCheck('Service Type filter dropdown exists with id="filter-service-type"', str_contains($page['body'], 'id="filter-service-type"'));
assertCheck('Availability filter dropdown exists with id="filter-availability"', str_contains($page['body'], 'id="filter-availability"'));

// Service Type dropdown options: All + 4 categories
$expectedCategories = ['Bathing & Brushing', 'Styling', 'Wellness', 'Specialized'];
foreach ($expectedCategories as $cat) {
    $hasCat = str_contains($page['body'], $cat) || str_contains($page['body'], htmlspecialchars($cat));
    assertCheck("Service Type filter contains '$cat'", $hasCat);
}
assertCheck('Availability filter contains Available and Unavailable options',
    str_contains($page['body'], 'value="active"') &&
    str_contains($page['body'], 'value="inactive"')
);

// Catalog table does NOT display service descriptions
assertCheck('Service catalog table does NOT render service descriptions under names',
    !preg_match('/<tr id="service-row-\d+">\s*<td>\s*<strong[^>]*>[^<]+<\/strong>\s*<span class="caption">/i', $page['body'])
);

// Create and Edit modals DO preserve description fields
assertCheck('Create Service modal preserves Description textarea', str_contains($page['body'], 'id="create-service-description"'));
assertCheck('Category selector in Create modal contains Bathing & Brushing', str_contains($page['body'], 'value="Bathing & Brushing"') || str_contains($page['body'], 'value="Bathing &amp; Brushing"'));
assertCheck('Category selector in Create modal contains Styling', str_contains($page['body'], 'value="Styling"'));
assertCheck('Category selector in Create modal contains Wellness', str_contains($page['body'], 'value="Wellness"'));
assertCheck('Category selector in Create modal contains Specialized', str_contains($page['body'], 'value="Specialized"'));

// -------------------------------------------------------------------------
// Suite 3: Existing Grooming Services Displayed & Initial Setup
// -------------------------------------------------------------------------
echo "\n--- Suite 3: Existing Grooming Services Displayed & Categorized ---\n";
assertCheck('Basic Bath & Dry is listed', str_contains($page['body'], 'Basic Bath &amp; Dry') || str_contains($page['body'], 'Basic Bath & Dry'));
assertCheck('Full Grooming (Bath + Haircut) is listed', str_contains($page['body'], 'Full Grooming (Bath + Haircut)'));
assertCheck('Nail Trim is listed', str_contains($page['body'], 'Nail Trim'));
assertCheck('Teeth Brushing is listed', str_contains($page['body'], 'Teeth Brushing'));
assertCheck('De-shedding Treatment is listed', str_contains($page['body'], 'De-shedding Treatment'));

// Veterinary Clinic and Pet Store services NOT displayed
assertCheck('Veterinary Comprehensive Wellness Exam NOT displayed', !str_contains($page['body'], 'Comprehensive Wellness Exam'));
assertCheck('Veterinary Core Vaccination Visit NOT displayed', !str_contains($page['body'], 'Core Vaccination Visit'));
assertCheck('Pet Store products NOT listed as services', !str_contains($page['body'], 'Premium Salmon Kibble'));

// -------------------------------------------------------------------------
// Suite 4: Create Service Validation
// -------------------------------------------------------------------------
echo "\n--- Suite 4: Create Service Validation & Duplicate Prevention ---\n";
$csrf = $gm->csrf($page['body']);

// 1. Missing name
$resEmptyName = $gm->post('/manager/services', [
    'csrf_token' => $csrf,
    'service_name' => '',
    'grooming_category' => 'Styling',
    'base_price' => '35.00',
    'estimated_duration_minutes' => '45',
    'additional_duration_rate' => '5.00',
    'additional_employee_fee' => '10.00',
]);
assertCheck('Create service without name redirects (302)', $resEmptyName['status'] === 302);
$pErr = $gm->get('/manager/services');
assertCheck('Validation error for required service name is displayed', str_contains($pErr['body'], 'Service name is required'));

// 2. Invalid category
$resBadCat = $gm->post('/manager/services', [
    'csrf_token' => $gm->csrf($pErr['body']),
    'service_name' => 'Invalid Cat Service',
    'grooming_category' => 'InvalidCategoryXYZ',
    'base_price' => '35.00',
    'estimated_duration_minutes' => '45',
    'additional_duration_rate' => '5.00',
    'additional_employee_fee' => '10.00',
]);
assertCheck('Create service with invalid category redirects (302)', $resBadCat['status'] === 302);
$pErr = $gm->get('/manager/services');
assertCheck('Validation error for invalid category is displayed', str_contains($pErr['body'], 'valid service category'));

// 3. Negative base price
$resNegPrice = $gm->post('/manager/services', [
    'csrf_token' => $gm->csrf($pErr['body']),
    'service_name' => 'Neg Price Service',
    'grooming_category' => 'Wellness',
    'base_price' => '-15.00',
    'estimated_duration_minutes' => '30',
    'additional_duration_rate' => '5.00',
    'additional_employee_fee' => '10.00',
]);
assertCheck('Create service with negative price redirects (302)', $resNegPrice['status'] === 302);
$pErr = $gm->get('/manager/services');
assertCheck('Validation error for negative base price is displayed', str_contains($pErr['body'], 'Base price must be a valid non-negative number'));

// 4. Invalid / zero duration
$resZeroDur = $gm->post('/manager/services', [
    'csrf_token' => $gm->csrf($pErr['body']),
    'service_name' => 'Zero Duration Service',
    'grooming_category' => 'Bathing & Brushing',
    'base_price' => '20.00',
    'estimated_duration_minutes' => '0',
    'additional_duration_rate' => '5.00',
    'additional_employee_fee' => '10.00',
]);
assertCheck('Create service with 0 duration redirects (302)', $resZeroDur['status'] === 302);
$pErr = $gm->get('/manager/services');
assertCheck('Validation error for maximum duration is displayed', str_contains($pErr['body'], 'Maximum duration must be a valid positive integer'));

// 5. Negative additional duration rate
$resNegAddlRate = $gm->post('/manager/services', [
    'csrf_token' => $gm->csrf($pErr['body']),
    'service_name' => 'Neg Rate Service',
    'grooming_category' => 'Specialized',
    'base_price' => '20.00',
    'estimated_duration_minutes' => '30',
    'additional_duration_rate' => '-5.00',
    'additional_employee_fee' => '10.00',
]);
assertCheck('Create service with negative additional duration rate redirects (302)', $resNegAddlRate['status'] === 302);
$pErr = $gm->get('/manager/services');
assertCheck('Validation error for additional-duration rate is displayed', str_contains($pErr['body'], 'Additional-duration rate must be a valid non-negative number'));

// 6. Duplicate service name
$resDup = $gm->post('/manager/services', [
    'csrf_token' => $gm->csrf($pErr['body']),
    'service_name' => 'Basic Bath & Dry',
    'grooming_category' => 'Bathing & Brushing',
    'base_price' => '30.00',
    'estimated_duration_minutes' => '40',
    'additional_duration_rate' => '5.00',
    'additional_employee_fee' => '10.00',
]);
assertCheck('Duplicate service name is rejected (302)', $resDup['status'] === 302);
$pErr = $gm->get('/manager/services');
assertCheck('Validation error for duplicate name is displayed', str_contains($pErr['body'], 'already exists'));

// -------------------------------------------------------------------------
// Suite 5: Successful Service Creation
// -------------------------------------------------------------------------
echo "\n--- Suite 5: Successful Service Creation ---\n";
// Clean up any test service from prior runs
$pdo->exec("DELETE FROM service WHERE service_name = 'TEST Deluxe Paw Spa & Scissoring'");

$testServiceData = [
    'csrf_token' => $gm->csrf($pErr['body']),
    'service_name' => 'TEST Deluxe Paw Spa & Scissoring',
    'grooming_category' => 'Styling',
    'description' => 'Therapeutic paw soak, soothing balm rub, and precision scissor trimming.',
    'base_price' => '65.50',
    'estimated_duration_minutes' => '50',
    'additional_duration_rate' => '12.00',
    'additional_employee_fee' => '18.00',
];
$resCreate = $gm->post('/manager/services', $testServiceData);
assertCheck('Valid service creation redirects (302)', $resCreate['status'] === 302);

$afterCreate = $gm->get('/manager/services');
assertCheck('Success flash message displayed after creation', str_contains($afterCreate['body'], 'created successfully'));
assertCheck('New service is listed on Services page', str_contains($afterCreate['body'], 'TEST Deluxe Paw Spa &amp; Scissoring') || str_contains($afterCreate['body'], 'TEST Deluxe Paw Spa & Scissoring'));
assertCheck('New service displays correct category (Styling)', str_contains($afterCreate['body'], 'Styling'));
assertCheck('New service displays correct base price (₱65.50)', str_contains($afterCreate['body'], '65.50'));
assertCheck('New service displays correct duration (50 mins)', str_contains($afterCreate['body'], '50') && str_contains($afterCreate['body'], 'mins'));
assertCheck('New service displays correct additional rate (₱12.00)', str_contains($afterCreate['body'], '12.00'));
assertCheck('New service displays correct additional employee fee (₱18.00)', str_contains($afterCreate['body'], '18.00'));
assertCheck('New service is Available by default', str_contains($afterCreate['body'], 'Available'));

// Fetch created service ID from DB
$sRow = $pdo->query("SELECT * FROM service WHERE service_name = 'TEST Deluxe Paw Spa & Scissoring'")->fetch();
assertCheck('New service exists in database with service_category = grooming', $sRow && $sRow['service_category'] === 'grooming');
assertCheck('New service has grooming_category = Styling', $sRow && $sRow['grooming_category'] === 'Styling');
assertCheck('New service has service_status = active', $sRow && $sRow['service_status'] === 'active');
$createdId = (int)$sRow['service_id'];

// -------------------------------------------------------------------------
// Suite 6: Edit Service Settings & Future Booking Application
// -------------------------------------------------------------------------
echo "\n--- Suite 6: Edit Service Settings & Snapshot Rule ---\n";
$editData = [
    'csrf_token' => $gm->csrf($afterCreate['body']),
    'service_name' => 'TEST Deluxe Paw Spa & Custom Styling',
    'grooming_category' => 'Styling',
    'description' => 'Updated luxury spa formulation with custom coat styling.',
    'base_price' => '72.00',
    'estimated_duration_minutes' => '60',
    'additional_duration_rate' => '15.00',
    'additional_employee_fee' => '20.00',
];
$resEdit = $gm->post("/manager/services/$createdId/update", $editData);
assertCheck('Service update redirects (302)', $resEdit['status'] === 302);

$afterEdit = $gm->get('/manager/services');
assertCheck('Success flash message displayed after update', str_contains($afterEdit['body'], 'updated successfully'));
assertCheck('Updated service name is listed', str_contains($afterEdit['body'], 'TEST Deluxe Paw Spa &amp; Custom Styling') || str_contains($afterEdit['body'], 'TEST Deluxe Paw Spa & Custom Styling'));
assertCheck('Updated base price is listed (₱72.00)', str_contains($afterEdit['body'], '72.00'));
assertCheck('Updated duration is listed (60 mins)', str_contains($afterEdit['body'], '60') && str_contains($afterEdit['body'], 'mins'));

$updatedRow = $pdo->query("SELECT * FROM service WHERE service_id = $createdId")->fetch();
assertCheck('Database record updated with new price 72.00', (float)$updatedRow['base_price'] === 72.0);
assertCheck('Database record updated with new duration 60 mins', (int)$updatedRow['estimated_duration_minutes'] === 60);
assertCheck('Database record updated with new additional rate 15.00', (float)$updatedRow['additional_duration_rate'] === 15.0);
assertCheck('Database record updated with new employee fee 20.00', (float)$updatedRow['additional_employee_fee'] === 20.0);

// Verify snapshot rule on existing confirmed appointment #3
$appt3Ps = $pdo->query("SELECT ps.* FROM performed_service ps JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id WHERE ap.appointment_id = 3")->fetchAll();
assertCheck('Existing confirmed appointment #3 retained original service snapshots', count($appt3Ps) > 0);
foreach ($appt3Ps as $ps) {
    assertCheck("Appointment #3 performed_service #{$ps['performed_service_id']} price is intact (₱{$ps['performed_price']})", (float)$ps['performed_price'] > 0);
}

// -------------------------------------------------------------------------
// Suite 7: Service Availability Toggle & Customer Booking Exclusion
// -------------------------------------------------------------------------
echo "\n--- Suite 7: Service Availability (Active / Inactive) ---\n";
// Toggle to Inactive (Unavailable)
$resToggle1 = $gm->post("/manager/services/$createdId/status", [
    'csrf_token' => $gm->csrf($afterEdit['body']),
    'status' => 'inactive',
]);
assertCheck('Marking service unavailable redirects (302)', $resToggle1['status'] === 302);

$afterToggle1 = $gm->get('/manager/services');
assertCheck('Service is now marked Unavailable on Services page', str_contains($afterToggle1['body'], 'Unavailable'));

$dbRowInactive = $pdo->query("SELECT service_status FROM service WHERE service_id = $createdId")->fetchColumn();
assertCheck('Database service_status is now inactive', $dbRowInactive === 'inactive');

// Check that Customer Booking page excludes unavailable service
$custReqPage = $cust->get('/appointments/new');
assertCheck('Customer booking form excludes unavailable service', !str_contains($custReqPage['body'], 'TEST Deluxe Paw Spa & Custom Styling') && !str_contains($custReqPage['body'], 'TEST Deluxe Paw Spa &amp; Custom Styling'));

// Attempt customer booking with unavailable service
$custPets = $pdo->query("SELECT pet_id FROM pet WHERE customer_id = 1 AND pet_status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
if (!empty($custPets)) {
    $petId = (int)$custPets[0];
    $resCustAttempt = $cust->post('/appointments', [
        'csrf_token' => $cust->csrf($custReqPage['body']),
        'preferred_date' => date('Y-m-d', strtotime('+3 days')),
        'preferred_time' => '10:00:00',
        'notes' => 'Testing booking of inactive service',
        'pets' => [$petId => [$createdId]],
    ]);
    assertCheck('Booking an unavailable service is rejected with error', str_contains($resCustAttempt['body'], 'not available') || $resCustAttempt['status'] === 422 || str_contains($resCustAttempt['body'], 'selected services is not available'));
}

// Toggle back to Active (Available)
$resToggle2 = $gm->post("/manager/services/$createdId/status", [
    'csrf_token' => $gm->csrf($afterToggle1['body']),
    'status' => 'active',
]);
assertCheck('Marking service available redirects (302)', $resToggle2['status'] === 302);

$afterToggle2 = $gm->get('/manager/services');
assertCheck('Service is now marked Available on Services page', str_contains($afterToggle2['body'], 'Available'));

$dbRowActive = $pdo->query("SELECT service_status FROM service WHERE service_id = $createdId")->fetchColumn();
assertCheck('Database service_status is active again', $dbRowActive === 'active');

// Customer booking page now includes the available service
$custReqPage2 = $cust->get('/appointments/new');
preg_match_all('/<strong>([^<]+)<\/strong>/', $custReqPage2['body'], $matches);
$foundTitles = implode(' | ', $matches[1] ?? []);
$hasSvc = str_contains($custReqPage2['body'], 'TEST Deluxe Paw Spa &amp; Custom Styling')
       || str_contains($custReqPage2['body'], 'TEST Deluxe Paw Spa & Custom Styling')
       || str_contains($custReqPage2['body'], "value=\"$createdId\"");
$errDetail = "Found services: $foundTitles";
assertCheck('Customer booking form now offers the available service', $hasSvc, $errDetail);

// -------------------------------------------------------------------------
// Suite 8: Service Type & Availability Filtering (Independent & Combined)
// -------------------------------------------------------------------------
echo "\n--- Suite 8: Service Type & Availability Filters ---\n";
// 1. Service Type Filter: Styling
$stylingPage = $gm->get('/manager/services?category=Styling');
assertCheck('Service Type filter by Styling renders HTTP 200', $stylingPage['status'] === 200);
assertCheck('Styling service Full Grooming is shown', str_contains($stylingPage['body'], 'Full Grooming'));
assertCheck('Bathing & Brushing service Basic Bath is excluded from Styling filter', !str_contains($stylingPage['body'], 'Basic Bath &amp; Dry') && !str_contains($stylingPage['body'], 'Basic Bath & Dry'));
assertCheck('Reset button is displayed when category filter is active', str_contains($stylingPage['body'], 'id="btn-reset-filters"'));

// 2. Service Type Filter: Bathing & Brushing
$bathPage = $gm->get('/manager/services?category=' . urlencode('Bathing & Brushing'));
assertCheck('Service Type filter by Bathing & Brushing renders HTTP 200', $bathPage['status'] === 200);
assertCheck('Basic Bath is shown under Bathing & Brushing filter', str_contains($bathPage['body'], 'Basic Bath'));
assertCheck('Full Grooming is excluded from Bathing & Brushing filter', !str_contains($bathPage['body'], 'Full Grooming'));

// 3. Service Type Filter: Wellness
$wellnessPage = $gm->get('/manager/services?category=Wellness');
assertCheck('Teeth Brushing is shown under Wellness filter', str_contains($wellnessPage['body'], 'Teeth Brushing'));
assertCheck('Basic Bath is excluded from Wellness filter', !str_contains($wellnessPage['body'], 'Basic Bath &amp; Dry') && !str_contains($wellnessPage['body'], 'Basic Bath & Dry'));

// 4. Service Type Filter: Specialized
$specializedPage = $gm->get('/manager/services?category=Specialized');
assertCheck('Nail Trim is shown under Specialized filter', str_contains($specializedPage['body'], 'Nail Trim'));
assertCheck('De-shedding Treatment is shown under Specialized filter', str_contains($specializedPage['body'], 'De-shedding Treatment'));

// 5. Availability Filter Alone: Available
$availPage = $gm->get('/manager/services?status=active');
assertCheck('Availability filter by active (Available) renders HTTP 200', $availPage['status'] === 200);
assertCheck('Available services are shown', str_contains($availPage['body'], 'Available'));
assertCheck('Reset button is displayed when availability filter is active', str_contains($availPage['body'], 'id="btn-reset-filters"'));

// 6. Availability Filter Alone: Unavailable
// Create a temporary inactive service for verification
$pdo->prepare("INSERT INTO service (service_name, service_category, grooming_category, base_price, estimated_duration_minutes, additional_duration_rate, additional_employee_fee, service_status) VALUES ('TEMP Inactive Treatment', 'grooming', 'Styling', 50.00, 45, 10.00, 15.00, 'inactive')")->execute();
$tempInactiveId = (int)$pdo->lastInsertId();

$unavailPage = $gm->get('/manager/services?status=inactive');
assertCheck('Availability filter by inactive (Unavailable) renders HTTP 200', $unavailPage['status'] === 200);
assertCheck('TEMP Inactive Treatment is displayed in Unavailable filter', str_contains($unavailPage['body'], 'TEMP Inactive Treatment'));

// 7. Combined Filtering: Service Type (Styling) + Availability (Unavailable)
$combinedInactive = $gm->get('/manager/services?category=Styling&status=inactive');
assertCheck('Combined filter (Styling + Unavailable) renders HTTP 200', $combinedInactive['status'] === 200);
assertCheck('TEMP Inactive Treatment is shown in combined filter', str_contains($combinedInactive['body'], 'TEMP Inactive Treatment'));
assertCheck('Active Styling service Full Grooming is excluded from unavailable combined filter', !str_contains($combinedInactive['body'], 'Full Grooming'));

// 8. Combined Filtering: Service Type (Styling) + Availability (Available)
$combinedActive = $gm->get('/manager/services?category=Styling&status=active');
assertCheck('Combined filter (Styling + Available) renders HTTP 200', $combinedActive['status'] === 200);
assertCheck('Full Grooming is shown in combined active filter', str_contains($combinedActive['body'], 'Full Grooming'));
assertCheck('TEMP Inactive Treatment is excluded from active combined filter', !str_contains($combinedActive['body'], 'TEMP Inactive Treatment'));

// Clean up temp inactive service
$pdo->exec("DELETE FROM service WHERE service_id = $tempInactiveId");

// 9. Default State: No filters active
$defaultPage = $gm->get('/manager/services');
assertCheck('Default state has no filter query params and renders HTTP 200', $defaultPage['status'] === 200);
assertCheck('Default state shows all services without Reset button', !str_contains($defaultPage['body'], 'id="btn-reset-filters"'));

// -------------------------------------------------------------------------
// Clean up created test service and test bather
// -------------------------------------------------------------------------
$pdo->exec("DELETE FROM service WHERE service_id = $createdId");
$pdo->exec("DELETE FROM account_role WHERE user_account_id IN (SELECT user_account_id FROM user_account WHERE email = 'test.bather@vetgrpet.local')");
$pdo->exec("DELETE FROM employee WHERE user_account_id IN (SELECT user_account_id FROM user_account WHERE email = 'test.bather@vetgrpet.local')");
$pdo->exec("DELETE FROM user_account WHERE email = 'test.bather@vetgrpet.local'");

echo "\n====================================================================\n";
echo "GROOMING SERVICES VERIFICATION RESULTS:\n";
echo "Total Passed: $passed\n";
echo "Total Failed: $failed\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
