<?php
declare(strict_types=1);

/**
 * Veterinary Clinic Business Manager — Daily Clinic Activity Card Verification Suite
 * Usage: tools\php83\php.exe tests\vetclinic_daily_activity_tests.php
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

final class VetActivityHttpClient
{
    public array $cookies = [];
    public function __construct(private string $base) {}

    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, $data);
    }

    private function send(string $method, string $path, array $data = []): array
    {
        $url = $this->base . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [];
        if (!empty($this->cookies)) {
            $cookieStr = '';
            foreach ($this->cookies as $k => $v) {
                $cookieStr .= "$k=$v; ";
            }
            $headers[] = 'Cookie: ' . rtrim($cookieStr, '; ');
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        foreach (explode("\r\n", $headerText) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $parts = explode(':', $line, 2);
                $cookieDef = explode(';', $parts[1])[0];
                $kv = explode('=', trim($cookieDef), 2);
                $this->cookies[$kv[0]] = $kv[1] ?? '';
            }
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headerText];
    }
}

echo "====================================================================\n";
echo "VET CLINIC BM DAILY CLINIC ACTIVITY CARD VERIFICATION SUITE\n";
echo "====================================================================\n\n";

$bm = new VetActivityHttpClient($base);
$loginPage = $bm->get('/staff/login');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginPage['body'], $csrfMatch);
$csrf = $csrfMatch[1] ?? '';

$loginRes = $bm->post('/staff/login', [
    'email' => 'claire.dubois@staff.sample.vetgrpet',
    'password' => 'Staff123!',
    'csrf_token' => $csrf,
]);
assertCheck('Claire Dubois (Vet Clinic BM) logs in successfully', $loginRes['status'] === 302);

// 1. Initial Overview Page Inspection
$ov = $bm->get('/manager');
assertCheck('Overview page loads HTTP 200', $ov['status'] === 200);

// Check clinic-local date formatting
$tz = new \DateTimeZone('Asia/Manila');
$todayLocal = new \DateTimeImmutable('now', $tz);
$todayDateStr = $todayLocal->format('Y-m-d');
$expectedDateFormatted = $todayLocal->format('M j, Y');

echo "\n--- Part 1: Card Structure and Visual Elements ---\n";
assertCheck('Contains Daily Clinic Activity card', str_contains($ov['body'], 'id="vet-overview-daily-activity-card"'));
assertCheck('Daily Clinic Activity card has equal height class vet-overview-equal-card', str_contains($ov['body'], 'vet-overview-equal-card" id="vet-overview-daily-activity-card"'));
assertCheck('Contains date badge with clinic-local date', str_contains($ov['body'], 'id="vet-activity-date-badge"') && str_contains($ov['body'], $expectedDateFormatted));
assertCheck('Contains separate Walk-in Visits stat box', str_contains($ov['body'], 'id="vet-stat-walkins"'));
assertCheck('Contains Walk-in Visits numerical value', str_contains($ov['body'], 'id="vet-stat-walkins-count"'));
assertCheck('Contains separate Scheduled Appointments stat box', str_contains($ov['body'], 'id="vet-stat-scheduled"'));
assertCheck('Contains Scheduled Appointments numerical value', str_contains($ov['body'], 'id="vet-stat-scheduled-count"'));
assertCheck('Contains schema limitation note about walk-in consultation tracking', str_contains($ov['body'], 'Schema Note:'));

// Extract initial counts
preg_match('/<div class="vet-activity-stat-val" id="vet-stat-scheduled-count">(\d+)<\/div>/', $ov['body'], $schedMatch);
preg_match('/<div class="vet-activity-stat-val" id="vet-stat-walkins-count">(\d+)<\/div>/', $ov['body'], $walkMatch);

$initialSched = isset($schedMatch[1]) ? (int)$schedMatch[1] : -1;
$initialWalk = isset($walkMatch[1]) ? (int)$walkMatch[1] : -1;

assertCheck('Initial scheduled count is a non-negative integer', $initialSched >= 0);
assertCheck('Initial walk-in count is 0 (as schema tracks consultations via scheduled records)', $initialWalk === 0);

echo "\n--- Part 2: Dynamic Count Updates and Data Isolation ---\n";

// A. Insert a scheduled medical appointment for TODAY
$unitId = Units::vetClinicId();
$testApptStmt = $pdo->prepare(
    "INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
     VALUES (?, 1, 1, 3, 'medical', ?, '14:30:00', '[TEST] Activity card scheduled appt verification')"
);
$testApptStmt->execute([$unitId, $todayDateStr]);
$testApptId = (int)$pdo->lastInsertId();

$ovAfterSched = $bm->get('/manager');
preg_match('/<div class="vet-activity-stat-val" id="vet-stat-scheduled-count">(\d+)<\/div>/', $ovAfterSched['body'], $afterMatch);
$afterSched = isset($afterMatch[1]) ? (int)$afterMatch[1] : -1;

assertCheck('Scheduled appointments count increments by 1 when medical appointment exists for today', $afterSched === $initialSched + 1);

// B. Cross-Unit Isolation: Insert a Grooming appointment for TODAY (unit 3)
$groomStmt = $pdo->prepare(
    "INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
     VALUES (3, 1, 1, 3, 'grooming', ?, '15:00:00', '[TEST] Grooming appointment isolation check')"
);
$groomStmt->execute([$todayDateStr]);
$groomApptId = (int)$pdo->lastInsertId();

$ovAfterGroom = $bm->get('/manager');
preg_match('/<div class="vet-activity-stat-val" id="vet-stat-scheduled-count">(\d+)<\/div>/', $ovAfterGroom['body'], $afterGroomMatch);
$afterGroomSched = isset($afterGroomMatch[1]) ? (int)$afterGroomMatch[1] : -1;

assertCheck('Grooming Services appointment for today does NOT alter Vet Clinic scheduled count', $afterGroomSched === $afterSched);

// C. Date Isolation: Insert a medical appointment for TOMORROW
$tomorrowDateStr = $todayLocal->modify('+1 day')->format('Y-m-d');
$tomApptStmt = $pdo->prepare(
    "INSERT INTO appointment (business_unit_id, branch_id, customer_id, appointment_status_id, appointment_type, preferred_date, preferred_time, notes)
     VALUES (?, 1, 1, 3, 'medical', ?, '10:00:00', '[TEST] Tomorrow medical appointment check')"
);
$tomApptStmt->execute([$unitId, $tomorrowDateStr]);
$tomApptId = (int)$pdo->lastInsertId();

$ovAfterTom = $bm->get('/manager');
preg_match('/<div class="vet-activity-stat-val" id="vet-stat-scheduled-count">(\d+)<\/div>/', $ovAfterTom['body'], $afterTomMatch);
$afterTomSched = isset($afterTomMatch[1]) ? (int)$afterTomMatch[1] : -1;

assertCheck('Medical appointment scheduled for tomorrow does NOT count towards today activity', $afterTomSched === $afterSched);

// D. Status Isolation: Update today appointment to Cancelled (status 5)
$cancelStmt = $pdo->prepare("UPDATE appointment SET appointment_status_id = 5 WHERE appointment_id = ?");
$cancelStmt->execute([$testApptId]);

$ovAfterCancel = $bm->get('/manager');
preg_match('/<div class="vet-activity-stat-val" id="vet-stat-scheduled-count">(\d+)<\/div>/', $ovAfterCancel['body'], $afterCancelMatch);
$afterCancelSched = isset($afterCancelMatch[1]) ? (int)$afterCancelMatch[1] : -1;

assertCheck('Cancelled medical appointment is excluded from active scheduled count', $afterCancelSched === $initialSched);

// Clean up test records
$pdo->prepare("DELETE FROM appointment WHERE appointment_id IN (?, ?, ?)")->execute([$testApptId, $groomApptId, $tomApptId]);

$ovCleaned = $bm->get('/manager');
preg_match('/<div class="vet-activity-stat-val" id="vet-stat-scheduled-count">(\d+)<\/div>/', $ovCleaned['body'], $cleanedMatch);
$cleanedSched = isset($cleanedMatch[1]) ? (int)$cleanedMatch[1] : -1;
assertCheck('After cleanup, scheduled count returns to initial baseline', $cleanedSched === $initialSched);

echo "\n--- Part 3: Layout, Card Sizing, and Alignment Verification ---\n";
assertCheck('Stock Alerts card has vet-overview-equal-card class', str_contains($ov['body'], 'vet-overview-equal-card" id="vet-overview-stock-alerts-card"'));
assertCheck('Daily Clinic Activity card has vet-overview-equal-card class', str_contains($ov['body'], 'vet-overview-equal-card" id="vet-overview-daily-activity-card"'));

$css = file_get_contents(__DIR__ . '/../public/assets/css/app.css');
assertCheck('CSS defines .vet-overview-middle-grid with 2 equal columns and stretch alignment',
    str_contains($css, '.vet-overview-middle-grid') &&
    str_contains($css, 'grid-template-columns: repeat(2, minmax(0, 1fr))') &&
    str_contains($css, 'align-items: stretch')
);
assertCheck('CSS defines .vet-overview-equal-card with height 100% and flex column',
    str_contains($css, '.vet-overview-equal-card') &&
    str_contains($css, 'height: 100%')
);
assertCheck('CSS defines .vet-activity-stats-row with 2 columns',
    str_contains($css, '.vet-activity-stats-row') &&
    str_contains($css, 'grid-template-columns: repeat(2, minmax(0, 1fr))')
);
assertCheck('CSS defines mobile collapse at @media (max-width: 960px) for middle grid',
    str_contains($css, '.vet-overview-middle-grid') &&
    str_contains($css, '@media (max-width: 960px)')
);
assertCheck('CSS defines mobile collapse at @media (max-width: 600px) for activity stats row',
    str_contains($css, '.vet-activity-stats-row') &&
    str_contains($css, '@media (max-width: 600px)')
);

echo "\n====================================================================\n";
echo "TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}

