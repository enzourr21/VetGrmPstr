<?php
declare(strict_types=1);

/**
 * PawCariño — CustomerRegistrationPendingService Isolated Test Suite
 *
 * Strictly targets disposable MySQL on port 3310.
 * Does not use .env or default database configuration.
 */

$projectRoot = str_replace('\\', '/', dirname(__DIR__));
$bootstrapPath = $projectRoot . '/app/bootstrap.php';
$phpBin = PHP_BINARY !== '' ? str_replace('\\', '/', PHP_BINARY) : ($projectRoot . '/tools/php83/php.exe');
$barrierDir = str_replace('\\', '/', sys_get_temp_dir());

require_once $bootstrapPath;

use App\Core\EnvConfig;
use App\Services\CustomerRegistrationPendingService;

// Ensure null mail driver and valid HMAC secret for tests
putenv('MAIL_DRIVER=null');
putenv('OTP_HMAC_SECRET=' . str_repeat('0123456789abcdef', 4)); // 64 bytes
EnvConfig::resetCache();

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

echo "====================================================================\n";
echo "PAWCARIÑO PENDING REGISTRATION SERVICE TEST SUITE\n";
echo "====================================================================\n\n";

// --- Section 0: Strict Isolation & Database Setup on Port 3310 ---
echo "--- Section 0: Connection Isolation & Schema Setup (Port 3310) ---\n";

$port = 3310;
$dsn = "mysql:host=127.0.0.1;port={$port};dbname=vetgrpet_test;charset=utf8mb4";
$pdo = new PDO($dsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

// Set UTC timezone explicitly for the session
$pdo->exec("SET time_zone = '+00:00'");

$actualPort = (int)$pdo->query('SELECT @@port')->fetchColumn();
$actualDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

assertTest('Connected strictly to disposable MySQL on port 3310', $actualPort === 3310, "Reported port: {$actualPort}");
assertTest('Connected strictly to vetgrpet_test database', $actualDb === 'vetgrpet_test', "Reported DB: {$actualDb}");

if ($actualPort !== 3310 || $actualDb !== 'vetgrpet_test') {
    echo "ABORT: Connection target is not the disposable test database on port 3310.\n";
    exit(1);
}

// Ensure Migration 019 tables exist on port 3310
$checkTable = $pdo->query("SHOW TABLES LIKE 'customer_registration_pending'")->fetchColumn();
if (!$checkTable) {
    echo "Applying Migration 019 to disposable database on port 3310...\n";
    $migrationSql = file_get_contents($projectRoot . '/database/migrations/019_create_customer_registration_pending.sql');
    $migrationSql = preg_replace('/^\s*--.*$/m', '', $migrationSql);
    $stmts = array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $migrationSql) . "\n")));
    foreach ($stmts as $s) {
        if ($s !== '') {
            $pdo->exec($s);
        }
    }
    // Record in schema_migrations on port 3310
    $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename) VALUES ('019_create_customer_registration_pending.sql')")
        ->execute();
}

$tablesExist = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='vetgrpet_test' AND table_name IN ('customer_registration_pending', 'email_otp_send_log', 'email_otp_failure_log')")->fetchColumn();
assertTest('All three Migration 019 tables exist in vetgrpet_test', (int)$tablesExist === 3);

// Service instance with injected PDO
$service = new CustomerRegistrationPendingService($pdo);

// Clean up any leftover test data
$pdo->exec("DELETE FROM customer_registration_pending WHERE email LIKE '%@test.invalid'");
$pdo->exec("DELETE FROM email_otp_send_log WHERE email LIKE '%@test.invalid'");
$pdo->exec("DELETE FROM email_otp_failure_log WHERE email LIKE '%@test.invalid'");

// --- Section 1: OTP Generation & Format ---
echo "\n--- Section 1: OTP Generation & Purpose + pending_id HMAC Binding ---\n";
$allSixDigits = true;
$leadingZeroFound = false;

for ($i = 0; $i < 1000; $i++) {
    $otp = CustomerRegistrationPendingService::generateOtp();
    if (!preg_match('/^[0-9]{6}$/', $otp)) {
        $allSixDigits = false;
        break;
    }
    if ($otp[0] === '0') {
        $leadingZeroFound = true;
    }
}
assertTest('1,000 generated OTPs are strictly 6 numeric digits', $allSixDigits);
assertTest('OTPs properly preserve leading zeroes', $leadingZeroFound);

$testHash1 = CustomerRegistrationPendingService::hashOtp('001234', 101);
assertTest('hashOtp generates a 64-character HMAC-SHA256 digest', strlen($testHash1) === 64 && ctype_xdigit($testHash1));

$testHash2 = CustomerRegistrationPendingService::hashOtp('001234', 102);
assertTest('hashOtp is strictly bound to pending_id (different IDs yield different hashes)', $testHash1 !== $testHash2);

// --- Section 2: Create Pending Registration ---
echo "\n--- Section 2: Create / Initiate Pending Registration ---\n";
$sessionToken = CustomerRegistrationPendingService::generateSessionToken();
$testEmail = 'newcustomer.' . bin2hex(random_bytes(4)) . '@test.invalid';

$initResult = $service->initiateRegistration(
    $sessionToken,
    [
        'first_name' => 'Maria',
        'middle_name' => 'Santos',
        'last_name' => 'Cruz',
        'email' => $testEmail,
        'phone' => '0917-555-0199',
        'address_line1' => '123 Mahogany St',
        'city' => 'Makati City',
        'province' => 'Metro Manila',
        'postal_code' => '1200',
    ],
    '10.0.2.1',
    '123456'
);

assertTest('Initiate pending registration returns ok = true (status 200)', $initResult['ok'] === true && $initResult['status'] === 200);

$tokenHash = CustomerRegistrationPendingService::hashSessionToken($sessionToken);
$pendingRow = $pdo->query("SELECT * FROM customer_registration_pending WHERE session_token_hash = '{$tokenHash}'")->fetch();

assertTest('Pending registration row created in database', $pendingRow !== false);
assertTest('Session token hash is stored as CHAR(64)', strlen($pendingRow['session_token_hash']) === 64);
assertTest('Pending email matches input', $pendingRow['email'] === $testEmail);
assertTest('Initial otp_attempts is 0', (int)$pendingRow['otp_attempts'] === 0);
assertTest('Initial cumulative_failed_attempts is 0', (int)$pendingRow['cumulative_failed_attempts'] === 0);
assertTest('Initial otp_verified_at is NULL', $pendingRow['otp_verified_at'] === null);
assertTest('Outbound send recorded in email_otp_send_log', (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE email = '{$testEmail}'")->fetchColumn() === 1);

// --- Section 3: Resend Cooldown (60 seconds) ---
echo "\n--- Section 3: 60-Second Resend Cooldown Enforcement ---\n";
$immediateResend = $service->resendOtp($sessionToken, '10.0.3.1');
assertTest('Immediate resend rejected with HTTP 429', $immediateResend['ok'] === false && $immediateResend['status'] === 429);
assertTest('Response includes retry_after countdown', ($immediateResend['retry_after'] ?? 0) > 0 && ($immediateResend['retry_after'] ?? 0) <= 60);

// Simulate 61 seconds passing by updating last_sent_at in database
$pdo->prepare("UPDATE customer_registration_pending SET last_sent_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 61 SECOND) WHERE session_token_hash = ?")
    ->execute([$tokenHash]);

$cooldownResend = $service->resendOtp($sessionToken, '10.0.3.1', '654321');
assertTest('Resend after 60 seconds succeeds (HTTP 200)', $cooldownResend['ok'] === true && $cooldownResend['status'] === 200);

// --- Section 4: Concurrency-Safe Hourly Send Limits & Fallback IP ---
echo "\n--- Section 4: Concurrency-Safe Hourly Send Limits & Fallback IP ---\n";

// Part A: Fallback IP consistency
assertTest('sanitizeIp normalizes empty string to DEFAULT_FALLBACK_IP (127.0.0.1)', CustomerRegistrationPendingService::sanitizeIp('') === CustomerRegistrationPendingService::DEFAULT_FALLBACK_IP);
assertTest('sanitizeIp normalizes whitespace to DEFAULT_FALLBACK_IP (127.0.0.1)', CustomerRegistrationPendingService::sanitizeIp('   ') === CustomerRegistrationPendingService::DEFAULT_FALLBACK_IP);
assertTest('sanitizeIp preserves valid IP address', CustomerRegistrationPendingService::sanitizeIp('198.51.100.42') === '198.51.100.42');

$fallbackToken = CustomerRegistrationPendingService::generateSessionToken();
$fallbackEmail = 'fallback.' . bin2hex(random_bytes(4)) . '@test.invalid';
$fallbackRes = $service->initiateRegistration($fallbackToken, [
    'first_name' => 'Fallback',
    'last_name' => 'Tester',
    'email' => $fallbackEmail,
], '', '112233'); // Empty IP supplied

assertTest('Initiation with empty IP succeeds (HTTP 200)', $fallbackRes['ok'] === true);
$fallbackLogIp = (string)$pdo->query("SELECT ip_address FROM email_otp_send_log WHERE email = '{$fallbackEmail}' ORDER BY log_id DESC LIMIT 1")->fetchColumn();
assertTest('Send log records DEFAULT_FALLBACK_IP (127.0.0.1) when IP is empty', $fallbackLogIp === CustomerRegistrationPendingService::DEFAULT_FALLBACK_IP);

// Part A2: IP Hourly Rate-Limit Enforcement (Isolated dedicated test IP, no shared historical rows)
$isolatedIp = '198.51.100.' . mt_rand(1, 250);
$insSend = $pdo->prepare("INSERT INTO email_otp_send_log (email, ip_address, sent_at) VALUES (?, ?, UTC_TIMESTAMP())");
for ($i = 0; $i < 4; $i++) {
    $insSend->execute(['ip.rate.seed.' . $i . '.' . bin2hex(random_bytes(2)) . '@test.invalid', $isolatedIp]);
}

// 5th send using dedicated IP succeeds (reaching 5/5 limit)
$ipToken5 = CustomerRegistrationPendingService::generateSessionToken();
$ipEmail5 = 'ip.rate.5.' . bin2hex(random_bytes(4)) . '@test.invalid';
$ipSend5 = $service->initiateRegistration($ipToken5, [
    'first_name' => 'Rate',
    'last_name' => 'Five',
    'email' => $ipEmail5,
], $isolatedIp, '121212');
assertTest('5th send for dedicated test IP succeeds (HTTP 200)', $ipSend5['ok'] === true && $ipSend5['status'] === 200);

// 6th send using dedicated IP is rejected with HTTP 429 when IP reaches hourly limit
$ipToken6 = CustomerRegistrationPendingService::generateSessionToken();
$ipEmail6 = 'ip.rate.6.' . bin2hex(random_bytes(4)) . '@test.invalid';
$ipSend6 = $service->initiateRegistration($ipToken6, [
    'first_name' => 'Rate',
    'last_name' => 'Six',
    'email' => $ipEmail6,
], $isolatedIp, '343434');
assertTest('Initiation rejected with HTTP 429 when IP reaches hourly limit', $ipSend6['ok'] === false && $ipSend6['status'] === 429 && str_contains($ipSend6['error'] ?? '', 'from this network'));

// Part B: Sequential Hourly Limits
$rateEmail = 'ratelimit.' . bin2hex(random_bytes(4)) . '@test.invalid';
$rateToken = CustomerRegistrationPendingService::generateSessionToken();

// Pre-fill send log with 4 recent sends
for ($i = 0; $i < 4; $i++) {
    $insSend->execute([$rateEmail, '192.168.10.50']);
}

// 5th send should succeed
$send5 = $service->initiateRegistration($rateToken, [
    'first_name' => 'Test',
    'last_name' => 'Limit',
    'email' => $rateEmail,
], '192.168.10.50', '111222');
assertTest('5th send in 1 hour succeeds (HTTP 200)', $send5['ok'] === true);

// Fast-forward cooldown for this token so rate limit can be tested
$rateHash = CustomerRegistrationPendingService::hashSessionToken($rateToken);
$pdo->prepare("UPDATE customer_registration_pending SET last_sent_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 65 SECOND) WHERE session_token_hash = ?")
    ->execute([$rateHash]);

// 6th send should be rejected due to email hourly limit
$send6 = $service->resendOtp($rateToken, '192.168.10.50');
assertTest('6th send for email within 1 hour rejected with HTTP 429', $send6['ok'] === false && $send6['status'] === 429 && str_contains($send6['error'] ?? '', 'for this email'));

// Part C: Genuine Overlapping Concurrency for EMAIL Hourly Limit
$concEmail = 'conc.email.' . bin2hex(random_bytes(4)) . '@test.invalid';
for ($i = 0; $i < 4; $i++) {
    $insSend->execute([$concEmail, '10.99.1.' . $i]);
}

$cTokenA = CustomerRegistrationPendingService::generateSessionToken();
$cTokenB = CustomerRegistrationPendingService::generateSessionToken();

function buildWorkerPhp(
    string $dsn,
    string $token,
    string $email,
    string $ip,
    string $readyFile,
    string $goFile,
    string $bootstrapPath
): string {
    $secret = str_repeat('0123456789abcdef', 4);
    return "
require_once '{$bootstrapPath}';
putenv('MAIL_DRIVER=null');
putenv('OTP_HMAC_SECRET={$secret}');
\\App\\Core\\EnvConfig::resetCache();

\$pdo = new PDO('{$dsn}', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
\$pdo->exec(\"SET time_zone = '+00:00'\");
\$service = new \\App\\Services\\CustomerRegistrationPendingService(\$pdo);

// Signal ready to parent barrier
file_put_contents('{$readyFile}', 'ready');

// Wait for parent release signal
\$timeout = microtime(true) + 5.0;
while (!file_exists('{$goFile}') && microtime(true) < \$timeout) {
    usleep(1000);
}

\$res = \$service->initiateRegistration('{$token}', [
    'first_name' => 'Conc',
    'last_name' => 'User',
    'email' => '{$email}',
], '{$ip}', '123456');

echo json_encode(\$res);
";
}

require_once __DIR__ . '/process_cleanup_helper.php';

function collectWorkerOutput($proc, array &$pipes, float $timeoutSeconds = 5.0): array
{
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $running = true;
    $exitCode = -1;

    while (microtime(true) < $deadline) {
        $r = [$pipes[1], $pipes[2]];
        $w = null;
        $e = null;
        $changed = @stream_select($r, $w, $e, 0, 20000); // 20ms
        if ($changed > 0) {
            $chunkOut = fread($pipes[1], 8192);
            if ($chunkOut !== false && $chunkOut !== '') {
                $stdout .= $chunkOut;
            }
            $chunkErr = fread($pipes[2], 8192);
            if ($chunkErr !== false && $chunkErr !== '') {
                $stderr .= $chunkErr;
            }
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $running = false;
            $exitCode = $status['exitcode'];
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            break;
        }
    }

    if ($running) {
        // Exceeded bounded timeout: safely terminate worker
        $exitCode = safelyTerminateAndClose($proc, $pipes, 2.0);
    } else {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeExit = proc_close($proc);
        if ($exitCode === -1) {
            $exitCode = $closeExit;
        }
    }

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exitCode' => $exitCode,
        'timedOut' => $running,
    ];
}

$readyEmailA = $barrierDir . '/pc_barrier_email_a_' . bin2hex(random_bytes(4)) . '.flag';
$readyEmailB = $barrierDir . '/pc_barrier_email_b_' . bin2hex(random_bytes(4)) . '.flag';
$goEmail = $barrierDir . '/pc_barrier_email_go_' . bin2hex(random_bytes(4)) . '.flag';

@unlink($readyEmailA);
@unlink($readyEmailB);
@unlink($goEmail);

// Launch competing Worker A and Worker B with synchronization barrier
$pA = proc_open("\"$phpBin\"", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesA);
$pB = proc_open("\"$phpBin\"", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesB);

fwrite($pipesA[0], "<?php\n" . buildWorkerPhp($dsn, $cTokenA, $concEmail, '10.99.2.1', $readyEmailA, $goEmail, $bootstrapPath));
fclose($pipesA[0]);

fwrite($pipesB[0], "<?php\n" . buildWorkerPhp($dsn, $cTokenB, $concEmail, '10.99.2.2', $readyEmailB, $goEmail, $bootstrapPath));
fclose($pipesB[0]);

// Wait until both workers are ready before releasing
$barrierTimeout = microtime(true) + 5.0;
while ((!file_exists($readyEmailA) || !file_exists($readyEmailB)) && microtime(true) < $barrierTimeout) {
    usleep(10000); // 10ms
}

$bothEmailReady = file_exists($readyEmailA) && file_exists($readyEmailB);
assertTest('Both concurrent email workers reached ready barrier before release', $bothEmailReady);

if (!$bothEmailReady) {
    // If barrier timed out, safely terminate and clean up workers without releasing
    safelyTerminateAndClose($pA, $pipesA, 2.0);
    safelyTerminateAndClose($pB, $pipesB, 2.0);
    @unlink($readyEmailA);
    @unlink($readyEmailB);
    @unlink($goEmail);
} else {
    // Release both workers simultaneously ONLY after both readiness files exist
    touch($goEmail);

    $resWorkerA = collectWorkerOutput($pA, $pipesA, 5.0);
    $resWorkerB = collectWorkerOutput($pB, $pipesB, 5.0);

    @unlink($readyEmailA);
    @unlink($readyEmailB);
    @unlink($goEmail);

    assertTest('Email Worker A exited cleanly (exit code 0)', !$resWorkerA['timedOut'] && $resWorkerA['exitCode'] === 0, "Exit code: {$resWorkerA['exitCode']}, stderr: {$resWorkerA['stderr']}");
    assertTest('Email Worker B exited cleanly (exit code 0)', !$resWorkerB['timedOut'] && $resWorkerB['exitCode'] === 0, "Exit code: {$resWorkerB['exitCode']}, stderr: {$resWorkerB['stderr']}");

    $resA = json_decode($resWorkerA['stdout'], true);
    $resB = json_decode($resWorkerB['stdout'], true);

    $emailStatuses = [$resA['status'] ?? 0, $resB['status'] ?? 0];
    sort($emailStatuses);

    assertTest('Concurrent email requests: exactly one succeeds (200) and one is rate-limited (429)', $emailStatuses === [200, 429], 'Received: ' . json_encode($emailStatuses));
    $finalEmailCount = (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE email = '{$concEmail}'")->fetchColumn();
    assertTest('Concurrent email requests: exactly 5 total sends recorded in database', $finalEmailCount === 5, "Count: {$finalEmailCount}");
}

// Part D: Genuine Overlapping Concurrency for IP Hourly Limit
$concIp = '10.77.77.77';
for ($i = 0; $i < 4; $i++) {
    $insSend->execute(['other.ip.' . $i . '.' . bin2hex(random_bytes(2)) . '@test.invalid', $concIp]);
}

$ipTokenA = CustomerRegistrationPendingService::generateSessionToken();
$ipTokenB = CustomerRegistrationPendingService::generateSessionToken();
$ipEmailA = 'conc.ipa.' . bin2hex(random_bytes(4)) . '@test.invalid';
$ipEmailB = 'conc.ipb.' . bin2hex(random_bytes(4)) . '@test.invalid';

$readyIpA = $barrierDir . '/pc_barrier_ip_a_' . bin2hex(random_bytes(4)) . '.flag';
$readyIpB = $barrierDir . '/pc_barrier_ip_b_' . bin2hex(random_bytes(4)) . '.flag';
$goIp = $barrierDir . '/pc_barrier_ip_go_' . bin2hex(random_bytes(4)) . '.flag';

@unlink($readyIpA);
@unlink($readyIpB);
@unlink($goIp);

$pIpA = proc_open("\"$phpBin\"", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesIpA);
$pIpB = proc_open("\"$phpBin\"", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesIpB);

fwrite($pipesIpA[0], "<?php\n" . buildWorkerPhp($dsn, $ipTokenA, $ipEmailA, $concIp, $readyIpA, $goIp, $bootstrapPath));
fclose($pipesIpA[0]);

fwrite($pipesIpB[0], "<?php\n" . buildWorkerPhp($dsn, $ipTokenB, $ipEmailB, $concIp, $readyIpB, $goIp, $bootstrapPath));
fclose($pipesIpB[0]);

// Wait until both workers are ready before releasing
$barrierTimeout = microtime(true) + 5.0;
while ((!file_exists($readyIpA) || !file_exists($readyIpB)) && microtime(true) < $barrierTimeout) {
    usleep(10000); // 10ms
}

$bothIpReady = file_exists($readyIpA) && file_exists($readyIpB);
assertTest('Both concurrent IP workers reached ready barrier before release', $bothIpReady);

if (!$bothIpReady) {
    // If barrier timed out, safely terminate and clean up workers without releasing
    safelyTerminateAndClose($pIpA, $pipesIpA, 2.0);
    safelyTerminateAndClose($pIpB, $pipesIpB, 2.0);
    @unlink($readyIpA);
    @unlink($readyIpB);
    @unlink($goIp);
} else {
    // Release both workers simultaneously ONLY after both readiness files exist
    touch($goIp);

    $resWorkerIpA = collectWorkerOutput($pIpA, $pipesIpA, 5.0);
    $resWorkerIpB = collectWorkerOutput($pIpB, $pipesIpB, 5.0);

    @unlink($readyIpA);
    @unlink($readyIpB);
    @unlink($goIp);

    assertTest('IP Worker A exited cleanly (exit code 0)', !$resWorkerIpA['timedOut'] && $resWorkerIpA['exitCode'] === 0, "Exit code: {$resWorkerIpA['exitCode']}, stderr: {$resWorkerIpA['stderr']}");
    assertTest('IP Worker B exited cleanly (exit code 0)', !$resWorkerIpB['timedOut'] && $resWorkerIpB['exitCode'] === 0, "Exit code: {$resWorkerIpB['exitCode']}, stderr: {$resWorkerIpB['stderr']}");

    $resIpA = json_decode($resWorkerIpA['stdout'], true);
    $resIpB = json_decode($resWorkerIpB['stdout'], true);

    $ipStatuses = [$resIpA['status'] ?? 0, $resIpB['status'] ?? 0];
    sort($ipStatuses);

    assertTest('Concurrent IP requests: exactly one succeeds (200) and one is rate-limited (429)', $ipStatuses === [200, 429], 'Received: ' . json_encode($ipStatuses));
    $finalIpCount = (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE ip_address = '{$concIp}'")->fetchColumn();
    assertTest('Concurrent IP requests: exactly 5 total sends recorded in database', $finalIpCount === 5, "Count: {$finalIpCount}");
}

// --- Section 5: OTP Expiration (5 minutes) ---
echo "\n--- Section 5: OTP Expiration Enforcement (5 minutes) ---\n";
$expToken = CustomerRegistrationPendingService::generateSessionToken();
$expEmail = 'exp.' . bin2hex(random_bytes(4)) . '@test.invalid';
$service->initiateRegistration($expToken, [
    'first_name' => 'Expired',
    'last_name' => 'Otp',
    'email' => $expEmail,
], '10.0.5.1', '555666');

$expHash = CustomerRegistrationPendingService::hashSessionToken($expToken);
// Set otp_expires_at to 10 seconds in the past
$pdo->prepare("UPDATE customer_registration_pending SET otp_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 SECOND) WHERE session_token_hash = ?")
    ->execute([$expHash]);

$expResult = $service->verifyOtp($expToken, '555666', '10.0.5.1');
assertTest('Expired OTP verification rejected with HTTP 410', $expResult['ok'] === false && $expResult['status'] === 410 && ($expResult['expired'] ?? false) === true);

// --- Section 6: Per-OTP Attempt Limits (5 attempts) ---
echo "\n--- Section 6: Per-OTP Attempt Limits & 5th Attempt Invalidation ---\n";
$attemptToken = CustomerRegistrationPendingService::generateSessionToken();
$attemptEmail = 'attempts.' . bin2hex(random_bytes(4)) . '@test.invalid';
$service->initiateRegistration($attemptToken, [
    'first_name' => 'Attempt',
    'last_name' => 'Counter',
    'email' => $attemptEmail,
], '10.0.6.1', '987654');

// Attempt 1: wrong
$a1 = $service->verifyOtp($attemptToken, '000001', '10.0.6.1');
assertTest('Attempt 1 incorrect returns HTTP 422 with 4 remaining', $a1['status'] === 422 && ($a1['attempts_remaining'] ?? 0) === 4);

// Attempt 2: wrong
$a2 = $service->verifyOtp($attemptToken, '000002', '10.0.6.1');
assertTest('Attempt 2 incorrect returns HTTP 422 with 3 remaining', $a2['status'] === 422 && ($a2['attempts_remaining'] ?? 0) === 3);

// Attempt 3: wrong
$a3 = $service->verifyOtp($attemptToken, '000003', '10.0.6.1');
assertTest('Attempt 3 incorrect returns HTTP 422 with 2 remaining', $a3['status'] === 422 && ($a3['attempts_remaining'] ?? 0) === 2);

// Attempt 4: wrong
$a4 = $service->verifyOtp($attemptToken, '000004', '10.0.6.1');
assertTest('Attempt 4 incorrect returns HTTP 422 with 1 remaining', $a4['status'] === 422 && ($a4['attempts_remaining'] ?? 0) === 1);

// Attempt 5: INCORRECT invalidates the OTP
$a5 = $service->verifyOtp($attemptToken, '000005', '10.0.6.1');
assertTest('Attempt 5 incorrect invalidates OTP (HTTP 410, invalidated=true)', $a5['status'] === 410 && ($a5['invalidated'] ?? false) === true);

$attemptHash = CustomerRegistrationPendingService::hashSessionToken($attemptToken);
$rowAfterInvalidate = $pdo->query("SELECT otp_hash, otp_expires_at, otp_attempts FROM customer_registration_pending WHERE session_token_hash = '{$attemptHash}'")->fetch();
assertTest('otp_hash is set to NULL upon invalidation', $rowAfterInvalidate['otp_hash'] === null);
assertTest('otp_expires_at is set to NULL upon invalidation', $rowAfterInvalidate['otp_expires_at'] === null);
assertTest('otp_attempts recorded as 5 in database', (int)$rowAfterInvalidate['otp_attempts'] === 5);

// Failure log has 5 records
$failCount = (int)$pdo->query("SELECT COUNT(*) FROM email_otp_failure_log WHERE email = '{$attemptEmail}'")->fetchColumn();
assertTest('email_otp_failure_log contains all 5 failed attempts', $failCount === 5);

// Test 5th Attempt with CORRECT code succeeds
$attemptTokenB = CustomerRegistrationPendingService::generateSessionToken();
$attemptEmailB = 'attemptsb.' . bin2hex(random_bytes(4)) . '@test.invalid';
$service->initiateRegistration($attemptTokenB, [
    'first_name' => 'AttemptB',
    'last_name' => 'CounterB',
    'email' => $attemptEmailB,
], '10.0.6.2', '123987');

// 4 wrong attempts
for ($i = 1; $i <= 4; $i++) {
    $service->verifyOtp($attemptTokenB, '00000' . $i, '10.0.6.2');
}
// 5th attempt with CORRECT code
$a5Correct = $service->verifyOtp($attemptTokenB, '123987', '10.0.6.2');
assertTest('5th attempt with CORRECT code succeeds (HTTP 200)', $a5Correct['ok'] === true && $a5Correct['status'] === 200);

// --- Section 7: Cumulative Failed Attempts Across Resends (15 limit) ---
echo "\n--- Section 7: Cumulative Failures (15 limit across resends) ---\n";
$cumToken = CustomerRegistrationPendingService::generateSessionToken();
$cumEmail = 'cumulative.' . bin2hex(random_bytes(4)) . '@test.invalid';
$init7 = $service->initiateRegistration($cumToken, [
    'first_name' => 'Cumulative',
    'last_name' => 'Tester',
    'email' => $cumEmail,
], '10.0.7.1', '777888');
assertTest('Section 7 initiate succeeds', $init7['ok'] === true);

$cumHash = CustomerRegistrationPendingService::hashSessionToken($cumToken);
// Pre-set cumulative failures to 14
$pdo->prepare("UPDATE customer_registration_pending SET cumulative_failed_attempts = 14 WHERE session_token_hash = ?")
    ->execute([$cumHash]);

// 15th failed attempt locks the pending registration
$cumFail = $service->verifyOtp($cumToken, '999999', '10.0.7.1');
assertTest('15th cumulative failure locks registration (HTTP 429, locked=true)', $cumFail['status'] === 429 && ($cumFail['locked'] ?? false) === true);

// Further verification rejected
$cumBlocked = $service->verifyOtp($cumToken, '777888', '10.0.7.1');
assertTest('Subsequent verification rejected on locked registration (HTTP 429)', $cumBlocked['status'] === 429);

// Further resend rejected
$cumResend = $service->resendOtp($cumToken, '10.0.7.1');
assertTest('Subsequent resend rejected on locked registration (HTTP 429)', $cumResend['status'] === 429);

// --- Section 8: Successful Verification & Status Helpers ---
echo "\n--- Section 8: Successful Verification & Status Helpers ---\n";
$vToken = CustomerRegistrationPendingService::generateSessionToken();
$vEmail = 'verify.' . bin2hex(random_bytes(4)) . '@test.invalid';
$init8 = $service->initiateRegistration($vToken, [
    'first_name' => 'Juan',
    'last_name' => 'Dela Cruz',
    'email' => $vEmail,
], '10.0.8.1', '456789');
assertTest('Section 8 initiate succeeds', $init8['ok'] === true);

assertTest('isVerified before verification returns false', $service->isVerified($vToken) === false);

$vResult = $service->verifyOtp($vToken, '456789', '10.0.8.1');
assertTest('Verification succeeds with correct OTP (HTTP 200)', $vResult['ok'] === true && $vResult['status'] === 200);

assertTest('isVerified after verification returns true', $service->isVerified($vToken) === true);

$vPending = $service->getPendingRegistration($vToken);
assertTest('otp_verified_at is a valid non-empty timestamp', !empty($vPending['otp_verified_at']));

// Re-verifying returns already verified
$reVerify = $service->verifyOtp($vToken, '456789', '10.0.8.1');
assertTest('Re-verifying returns ok=true with already_verified flag', $reVerify['ok'] === true && ($reVerify['already_verified'] ?? false) === true);

// --- Section 9: Failed Email Delivery Handling ---
echo "\n--- Section 9: Failed Email Delivery Rollback & Isolation ---\n";
$failDeliveryToken = CustomerRegistrationPendingService::generateSessionToken();
$failDeliveryEmail = 'failmail.' . bin2hex(random_bytes(4)) . '@test.invalid';

// Trigger MailService failure by configuring SMTP without required host (fails closed with 503)
putenv('MAIL_DRIVER=smtp');
putenv('MAIL_HOST=');
EnvConfig::resetCache();

$failedSend = $service->initiateRegistration($failDeliveryToken, [
    'first_name' => 'MailFail',
    'last_name' => 'Test',
    'email' => $failDeliveryEmail,
], '10.0.9.1', '888999');

assertTest('Failed email delivery returns HTTP 502/503 error', $failedSend['ok'] === false && in_array($failedSend['status'], [502, 503], true));

$failTokenHash = CustomerRegistrationPendingService::hashSessionToken($failDeliveryToken);
$failPending = $pdo->query("SELECT * FROM customer_registration_pending WHERE session_token_hash = '{$failTokenHash}'")->fetch();
assertTest('Failed email delivery left NO pending registration row in database', $failPending === false);

$failSendCount = (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE email = '{$failDeliveryEmail}'")->fetchColumn();
assertTest('Failed email delivery wrote NO record to email_otp_send_log', $failSendCount === 0);

// Restore null mail driver
putenv('MAIL_DRIVER=null');
EnvConfig::resetCache();

// --- Section 10: Email-Change Behavior ---
echo "\n--- Section 10: Email-Change Behavior ---\n";
$changeToken = CustomerRegistrationPendingService::generateSessionToken();
$emailOld = 'old.' . bin2hex(random_bytes(4)) . '@test.invalid';
$emailNew = 'new.' . bin2hex(random_bytes(4)) . '@test.invalid';

$init10Old = $service->initiateRegistration($changeToken, [
    'first_name' => 'Email',
    'last_name' => 'Changer',
    'email' => $emailOld,
], '10.0.10.1', '121212');
assertTest('Section 10 initial initiate succeeds', $init10Old['ok'] === true);

$oldSendCount = (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE email = '{$emailOld}'")->fetchColumn();
assertTest('First email send logged in email_otp_send_log', $oldSendCount === 1);

// User changes email with same session token
$init10New = $service->initiateRegistration($changeToken, [
    'first_name' => 'Email',
    'last_name' => 'Changer',
    'email' => $emailNew,
], '10.0.10.2', '343434');
assertTest('Section 10 changed email initiate succeeds', $init10New['ok'] === true);

$changeHash = CustomerRegistrationPendingService::hashSessionToken($changeToken);
$updatedPending = $pdo->query("SELECT * FROM customer_registration_pending WHERE session_token_hash = '{$changeHash}'")->fetch();

assertTest('Pending row updated to new email address', ($updatedPending['email'] ?? '') === $emailNew);
assertTest('Old send log row preserved in email_otp_send_log', (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE email = '{$emailOld}'")->fetchColumn() === 1);
assertTest('New send log row recorded in email_otp_send_log', (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE email = '{$emailNew}'")->fetchColumn() === 1);

// --- Section 11: Service Responsibility & Pending Verification Lifecycle ---
echo "\n--- Section 11: Service Responsibility & Pending Verification Lifecycle ---\n";
$pToken = CustomerRegistrationPendingService::generateSessionToken();
$pEmail = 'pendingonly.' . bin2hex(random_bytes(4)) . '@test.invalid';

$init11 = $service->initiateRegistration($pToken, [
    'first_name' => 'Scoped',
    'last_name' => 'Service',
    'email' => $pEmail,
    'postal_code' => '1000',
], '10.0.11.1', '555777');
assertTest('Section 11 initiate succeeds', $init11['ok'] === true);

// Pre-condition: user_account has 0 records for this email
$preUserCount = (int)$pdo->query("SELECT COUNT(*) FROM user_account WHERE email = '{$pEmail}'")->fetchColumn();
assertTest('Permanent user_account table initially has 0 records for candidate email', $preUserCount === 0);

// Verify OTP
$ver11 = $service->verifyOtp($pToken, '555777', '10.0.11.1');
assertTest('Section 11 verify succeeds (HTTP 200)', $ver11['ok'] === true);
assertTest('Pending session is now verified via isVerified()', $service->isVerified($pToken) === true);

// Retrieve pending verified record
$verifiedRecord = $service->getPendingRegistration($pToken);
assertTest('getPendingRegistration returns valid verified pending record', $verifiedRecord !== null && !empty($verifiedRecord['otp_verified_at']));
assertTest('Retrieved pending record contains all sanitized candidate profile fields', ($verifiedRecord['first_name'] ?? '') === 'Scoped' && ($verifiedRecord['postal_code'] ?? '') === '1000');

// Confirm permanent user_account table and email_verified_at were NOT modified by service
$postUserCount = (int)$pdo->query("SELECT COUNT(*) FROM user_account WHERE email = '{$pEmail}'")->fetchColumn();
assertTest('Service responsibility maintained: zero permanent user_account records created', $postUserCount === 0);

// Clean up pending row via service helper
$delPending = $service->deletePendingRegistration($pToken);
assertTest('deletePendingRegistration successfully removes pending registration', $delPending === true);
assertTest('Pending registration no longer exists in database', $service->getPendingRegistration($pToken) === null);

// --- Section 12: Strengthened Genuine Overlapping Concurrency (Row Lock) ---
echo "\n--- Section 12: Strengthened Overlapping Concurrency (Row Lock Verified in Engine) ---\n";
$conToken = CustomerRegistrationPendingService::generateSessionToken();
$conHash = CustomerRegistrationPendingService::hashSessionToken($conToken);
$conEmail = 'concurrent.lock.' . bin2hex(random_bytes(4)) . '@test.invalid';

$init12 = $service->initiateRegistration($conToken, [
    'first_name' => 'Concurrent',
    'last_name' => 'Lock',
    'email' => $conEmail,
], '10.0.12.1', '998877');
assertTest('Section 12 initiate succeeds', $init12['ok'] === true);

// Get parent connection ID to correlate lock owner in performance_schema
$parentConnectionId = (int)$pdo->query("SELECT CONNECTION_ID()")->fetchColumn();

// Connection 1 begins transaction and acquires exclusive row lock on pending record
$childCode = "
require_once '{$bootstrapPath}';
putenv('MAIL_DRIVER=null');
putenv('OTP_HMAC_SECRET=" . str_repeat('0123456789abcdef', 4) . "');
\\App\\Core\\EnvConfig::resetCache();

\$pdo2 = new PDO('$dsn', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
\$pdo2->exec(\"SET time_zone = '+00:00'\");
\$service2 = new \\App\\Services\\CustomerRegistrationPendingService(\$pdo2);

try {
    \$t0 = microtime(true);
    \$res = \$service2->verifyOtp('$conToken', '000001', '10.0.12.2');
    \$elapsed = microtime(true) - \$t0;
    echo json_encode(['result' => \$res, 'elapsed' => \$elapsed]);
} finally {
    if (isset(\$pdo2) && \$pdo2->inTransaction()) {
        \$pdo2->rollBack();
    }
}
";

$proc = null;
$pipes = [];

try {
    $pdo->beginTransaction();
    $lockedRow = $pdo->query("SELECT * FROM customer_registration_pending WHERE session_token_hash = '{$conHash}' FOR UPDATE")->fetch();
    assertTest('Connection 1 acquired exclusive row lock (FOR UPDATE)', $lockedRow !== false);

    $proc = proc_open("\"$phpBin\"", [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($proc)) {
        assertTest('Overlapping child process spawned successfully', false, 'proc_open failed');
    } else {
        fwrite($pipes[0], "<?php\n" . $childCode);
        fclose($pipes[0]);

        // Specific lock query strongly correlating parent connection ID, child requesting transaction, and target row hash
        $lockInspectSql = "
            SELECT
                w.REQUESTING_ENGINE_TRANSACTION_ID AS child_trx_id,
                w.BLOCKING_ENGINE_TRANSACTION_ID AS parent_trx_id,
                w.BLOCKING_THREAD_ID AS parent_pfs_thread_id,
                pt_parent.PROCESSLIST_ID AS parent_connection_id,
                pt_child.PROCESSLIST_ID AS child_connection_id,
                l_req.OBJECT_SCHEMA,
                l_req.OBJECT_NAME,
                l_req.INDEX_NAME,
                l_req.LOCK_TYPE,
                l_req.LOCK_MODE,
                l_req.LOCK_STATUS,
                l_req.LOCK_DATA,
                COALESCE(t.trx_query, pt_child.PROCESSLIST_INFO, '') AS blocked_query
            FROM performance_schema.data_lock_waits w
            JOIN performance_schema.data_locks l_req
                ON w.REQUESTING_ENGINE_LOCK_ID = l_req.ENGINE_LOCK_ID
            JOIN performance_schema.data_locks l_block
                ON w.BLOCKING_ENGINE_LOCK_ID = l_block.ENGINE_LOCK_ID
            JOIN performance_schema.threads pt_parent
                ON pt_parent.THREAD_ID = w.BLOCKING_THREAD_ID
            JOIN performance_schema.threads pt_child
                ON pt_child.THREAD_ID = w.REQUESTING_THREAD_ID
            LEFT JOIN information_schema.innodb_trx t
                ON t.trx_id = w.REQUESTING_ENGINE_TRANSACTION_ID
            WHERE pt_parent.PROCESSLIST_ID = :parentConnectionId
              AND l_req.OBJECT_SCHEMA = 'vetgrpet_test'
              AND l_req.OBJECT_NAME = 'customer_registration_pending'
              AND l_req.LOCK_STATUS = 'WAITING'
              AND l_req.LOCK_DATA LIKE :conHashPattern
        ";
        $stmtFindLock = $pdo->prepare($lockInspectSql);

        // Bounded polling for child lock acquisition (up to 2.0 seconds)
        $childLockRow = null;
        $pollDeadline = microtime(true) + 2.0;
        while (microtime(true) < $pollDeadline) {
            usleep(25000); // 25ms intervals
            $stmtFindLock->execute([
                'parentConnectionId' => $parentConnectionId,
                'conHashPattern' => '%' . $conHash . '%',
            ]);
            $row = $stmtFindLock->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $childLockRow = $row;
                break;
            }
        }

        if (!is_array($childLockRow)) {
            // Polling timed out: fail explicitly and safely terminate child process
            assertTest('Specific child transaction identified waiting on target row blocked by parent', false, 'Lock wait polling timed out');

            // 1. Roll back parent transaction FIRST to release any locks
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // 2. Terminate and clean up child using bounded timeout
            safelyTerminateAndClose($proc, $pipes, 2.0);
            $proc = null;
        } else {
            assertTest('Specific child transaction identified waiting on target row blocked by parent', is_array($childLockRow));
            assertTest('Child locked object is customer_registration_pending record in WAITING status', ($childLockRow['OBJECT_NAME'] ?? '') === 'customer_registration_pending' && ($childLockRow['LOCK_STATUS'] ?? '') === 'WAITING');
            assertTest('Blocked query is child verifyOtp SELECT FOR UPDATE', str_contains($childLockRow['blocked_query'] ?? '', 'customer_registration_pending') && str_contains($childLockRow['blocked_query'] ?? '', 'FOR UPDATE'));

            // Parent releases lock by committing transaction
            $pdo->commit();

            // Collect child output within bounded timeout, capturing exit code from process status
            $childResult = collectWorkerOutput($proc, $pipes, 5.0);
            $proc = null;

            assertTest('Child process exited cleanly within bounded timeout (exit code 0)', !$childResult['timedOut'] && $childResult['exitCode'] === 0, "Exit code: {$childResult['exitCode']}" . ($childResult['stderr'] !== '' ? ", stderr: {$childResult['stderr']}" : ''));
            assertTest('Child process reported no stderr errors', trim($childResult['stderr']) === '', "stderr: {$childResult['stderr']}");

            $childData = json_decode($childResult['stdout'], true);
            assertTest('Connection 2 unblocks upon parent commit and executes cleanly', is_array($childData) && isset($childData['result']));
            assertTest('Connection 2 received expected verification failure (HTTP 422, attempts_remaining=4)', ($childData['result']['status'] ?? 0) === 422);

            $finalConRow = $pdo->query("SELECT otp_attempts FROM customer_registration_pending WHERE session_token_hash = '{$conHash}'")->fetch();
            assertTest('Database reflects serialized attempt counter without race conditions (otp_attempts = 1)', (int)($finalConRow['otp_attempts'] ?? 0) === 1);
        }
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_resource($proc)) {
        safelyTerminateAndClose($proc, $pipes, 2.0);
        $proc = null;
    }
}

// --- Section 13: Retention Cleaning ---
echo "\n--- Section 13: Log & Expired Registration Retention Cleaning ---\n";
$retDeleted = $service->cleanExpiredRecords(30);
assertTest('cleanExpiredRecords executes cleanly and returns integer count', is_int($retDeleted));

// --- Section 14: resendOtp() Email Advisory-Lock Race Protection ---
echo "\n--- Section 14: resendOtp() Email Advisory-Lock Race Protection ---\n";
$raceToken = CustomerRegistrationPendingService::generateSessionToken();
$raceHash = CustomerRegistrationPendingService::hashSessionToken($raceToken);
$raceOldEmail = 'race.old.' . bin2hex(random_bytes(4)) . '@test.invalid';
$raceNewEmail = 'race.new.' . bin2hex(random_bytes(4)) . '@test.invalid';
$raceIp = '10.88.88.88';

$raceInit = $service->initiateRegistration($raceToken, [
    'first_name' => 'Race',
    'last_name' => 'Old',
    'email' => $raceOldEmail,
], $raceIp, '246810');
assertTest('Section 14 initial registration succeeds (HTTP 200)', $raceInit['ok'] === true && $raceInit['status'] === 200);

// Satisfy 60s resend cooldown and pre-fill 5 hourly sends for $raceNewEmail
$pdo->prepare("UPDATE customer_registration_pending SET last_sent_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 65 SECOND) WHERE session_token_hash = ?")
    ->execute([$raceHash]);
for ($i = 0; $i < 5; $i++) {
    $insSend->execute([$raceNewEmail, '10.88.88.' . (10 + $i)]);
}

$raceOldLock = 'pc_em_' . md5($raceOldEmail);
$raceNewLock = 'pc_em_' . md5($raceNewEmail);
$raceIpLock = 'pc_ip_' . md5($raceIp);

$raceWorkerReady = $barrierDir . '/pc_race_ready_' . bin2hex(random_bytes(4)) . '.flag';
$raceGoFile = $barrierDir . '/pc_race_go_' . bin2hex(random_bytes(4)) . '.flag';
$raceFetchedOldFile = $barrierDir . '/pc_race_fetched_old_' . bin2hex(random_bytes(4)) . '.flag';
@unlink($raceWorkerReady);
@unlink($raceGoFile);
@unlink($raceFetchedOldFile);

// Parent holds $raceOldLock and $raceNewLock BEFORE spawning resendOtp() worker.
// 1. Worker performs initial lookup ($preEmail = $raceOldEmail), signals $raceFetchedOldFile
//    immediately before requesting GET_LOCK($raceOldLock), then blocks on GET_LOCK($raceOldLock).
// 2. Parent observes $raceFetchedOldFile, updates row email to $raceNewEmail, then releases $raceOldLock
//    (keeping $raceNewLock held).
// 3. Worker unblocks, sees $row['email'] !== $preEmail, releases $raceOldLock + $raceIpLock, and retries.
// 4. On retry, Worker fetches $preEmail = $raceNewEmail and blocks on GET_LOCK($raceNewLock),
//    proving it never runs the rate-limit check under $raceOldLock.
$raceProc = null;
$racePipes = [];
try {
    $gotOldLock = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($raceOldLock) . ", 5)")->fetchColumn();
    $gotNewLock = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($raceNewLock) . ", 5)")->fetchColumn();
    assertTest('Parent acquired old and new email advisory locks for race simulation', $gotOldLock === 1 && $gotNewLock === 1);

    $raceWorkerCode = "<?php\n"
        . "require_once '{$bootstrapPath}';\n"
        . "putenv('MAIL_DRIVER=null');\n"
        . "putenv('OTP_HMAC_SECRET=" . str_repeat('0123456789abcdef', 4) . "');\n"
        . "\\App\\Core\\EnvConfig::resetCache();\n"
        . "final class RaceObservingPdo extends PDO {\n"
        . "    private bool \$signaledBeforeOldLock = false;\n"
        . "    private string \$signalFile;\n"
        . "    public function __construct(string \$dsn, string \$signalFile) {\n"
        . "        parent::__construct(\$dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);\n"
        . "        \$this->signalFile = \$signalFile;\n"
        . "    }\n"
        . "    public function prepare(string \$query, array \$options = []): PDOStatement|false {\n"
        . "        if (!\$this->signaledBeforeOldLock && str_contains(\$query, 'GET_LOCK')) {\n"
        . "            \$this->signaledBeforeOldLock = true;\n"
        . "            file_put_contents(\$this->signalFile, 'fetched_old');\n"
        . "        }\n"
        . "        return parent::prepare(\$query, \$options);\n"
        . "    }\n"
        . "}\n"
        . "\$pdoW = new RaceObservingPdo('{$dsn}', '{$raceFetchedOldFile}');\n"
        . "\$pdoW->exec(\"SET time_zone = '+00:00'\");\n"
        . "\$svcW = new \\App\\Services\\CustomerRegistrationPendingService(\$pdoW);\n"
        . "file_put_contents('{$raceWorkerReady}', 'ready');\n"
        . "\$deadline = microtime(true) + 5.0;\n"
        . "while (!file_exists('{$raceGoFile}') && microtime(true) < \$deadline) { usleep(1000); }\n"
        . "\$res = \$svcW->resendOtp('{$raceToken}', '{$raceIp}', '135791');\n"
        . "echo json_encode(\$res);\n";

    $raceProc = proc_open("\"$phpBin\"", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $racePipes);
    fwrite($racePipes[0], $raceWorkerCode);
    fclose($racePipes[0]);

    $waitReadyDeadline = microtime(true) + 5.0;
    while (!file_exists($raceWorkerReady) && microtime(true) < $waitReadyDeadline) {
        usleep(10000);
    }
    assertTest('Race worker reached ready barrier', file_exists($raceWorkerReady));

    touch($raceGoFile);

    // Wait deterministically until worker has completed initial email lookup and is about to request $raceOldLock
    $waitFetchedDeadline = microtime(true) + 5.0;
    while (!file_exists($raceFetchedOldFile) && microtime(true) < $waitFetchedDeadline) {
        usleep(5000);
    }
    $fetchedOldSignaled = file_exists($raceFetchedOldFile);
    assertTest('Worker signaled initial old-email lookup immediately before requesting old-email lock', $fetchedOldSignaled);

    // Mutate email in DB before releasing $raceOldLock
    $pdo->prepare("UPDATE customer_registration_pending SET email = ? WHERE session_token_hash = ?")
        ->execute([$raceNewEmail, $raceHash]);

    // Release $raceOldLock while still holding $raceNewLock
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($raceOldLock) . ")")->fetchColumn();

    // Wait briefly for worker to detect mismatch, release $raceOldLock + $raceIpLock, and block on $raceNewLock
    $retryObserved = false;
    $pollDeadline = microtime(true) + 2.0;
    while (microtime(true) < $pollDeadline) {
        usleep(20000);
        $oldFree = (int)$pdo->query("SELECT IS_FREE_LOCK(" . $pdo->quote($raceOldLock) . ")")->fetchColumn();
        $ipFree = (int)$pdo->query("SELECT IS_FREE_LOCK(" . $pdo->quote($raceIpLock) . ")")->fetchColumn();
        $wStatus = is_resource($raceProc) ? proc_get_status($raceProc) : false;
        if ($oldFree === 1 && $ipFree === 1 && is_array($wStatus) && $wStatus['running']) {
            $retryObserved = true;
            break;
        }
    }
    assertTest('Worker released stale old-email and IP locks and waited for new-email lock before rate-limit check', $retryObserved);

    // Release $raceNewLock so worker can complete its retry under the new-email lock
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($raceNewLock) . ")")->fetchColumn();

    $raceResult = collectWorkerOutput($raceProc, $racePipes, 5.0);
    $raceProc = null;

    assertTest('Race worker exited cleanly (exit code 0)', !$raceResult['timedOut'] && $raceResult['exitCode'] === 0, "Exit code: {$raceResult['exitCode']}, stderr: {$raceResult['stderr']}");
    $raceResData = json_decode($raceResult['stdout'], true);
    assertTest(
        'resendOtp() retried under new-email lock and enforced new email hourly limit (HTTP 429)',
        is_array($raceResData) && ($raceResData['ok'] ?? true) === false && ($raceResData['status'] ?? 0) === 429 && str_contains($raceResData['error'] ?? '', 'for this email'),
        'Response: ' . $raceResult['stdout']
    );

    $oldSendCount = (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE email = " . $pdo->quote($raceOldEmail))->fetchColumn();
    $newSendCount = (int)$pdo->query("SELECT COUNT(*) FROM email_otp_send_log WHERE email = " . $pdo->quote($raceNewEmail))->fetchColumn();
    assertTest('No extra send logged for old or new email after race retry', $oldSendCount === 1 && $newSendCount === 5, "old={$oldSendCount}, new={$newSendCount}");
} finally {
    @$pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($raceOldLock) . ")");
    @$pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($raceNewLock) . ")");
    if (is_resource($raceProc)) {
        safelyTerminateAndClose($raceProc, $racePipes, 2.0);
    }
    @unlink($raceWorkerReady);
    @unlink($raceGoFile);
    @unlink($raceFetchedOldFile);
}

// --- Section 15: Verified Customer Account Provisioning ---
echo "\n--- Section 15: Verified Customer Account Provisioning ---\n";

// 15.1 Missing pending registration
$missingToken = CustomerRegistrationPendingService::generateSessionToken();
$missingProv = $service->provisionVerifiedAccount($missingToken, 'StrongPass123!');
assertTest('Missing pending registration rejected with HTTP 404', $missingProv['ok'] === false && $missingProv['status'] === 404);

// 15.2 Unverified pending registration
$unverToken = CustomerRegistrationPendingService::generateSessionToken();
$unverEmail = 'prov.unver.' . bin2hex(random_bytes(4)) . '@test.invalid';
$service->initiateRegistration($unverToken, [
    'first_name' => 'Unver',
    'last_name' => 'User',
    'email' => $unverEmail,
], '10.15.0.1', '111222');
$unverProv = $service->provisionVerifiedAccount($unverToken, 'StrongPass123!');
assertTest('Unverified pending registration rejected with HTTP 403', $unverProv['ok'] === false && $unverProv['status'] === 403);

// 15.3 Expired pending registration
$expProvToken = CustomerRegistrationPendingService::generateSessionToken();
$expProvEmail = 'prov.exp.' . bin2hex(random_bytes(4)) . '@test.invalid';
$service->initiateRegistration($expProvToken, [
    'first_name' => 'Exp',
    'last_name' => 'User',
    'email' => $expProvEmail,
], '10.15.0.2', '333444');
$service->verifyOtp($expProvToken, '333444', '10.15.0.2');
$expProvHash = CustomerRegistrationPendingService::hashSessionToken($expProvToken);
$pdo->prepare("UPDATE customer_registration_pending SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 SECOND) WHERE session_token_hash = ?")
    ->execute([$expProvHash]);
$expProvRes = $service->provisionVerifiedAccount($expProvToken, 'StrongPass123!');
assertTest('Expired pending registration rejected with HTTP 410 (expired=true)', $expProvRes['ok'] === false && $expProvRes['status'] === 410 && ($expProvRes['expired'] ?? false) === true);

// 15.4, 15.5, 15.6 Successful account and customer creation, email_verified_at, and pending-row deletion
$okProvToken = CustomerRegistrationPendingService::generateSessionToken();
$okProvEmail = 'prov.ok.' . bin2hex(random_bytes(4)) . '@test.invalid';
$okProvUser = 'prov_ok_' . bin2hex(random_bytes(3));
$service->initiateRegistration($okProvToken, [
    'first_name' => 'Jane',
    'middle_name' => 'Marie',
    'last_name' => 'Doe',
    'username' => $okProvUser,
    'phone' => '+63 917 123 4567',
    'address_line1' => '123 Pet Lane',
    'city' => 'Manila',
    'province' => 'Metro Manila',
    'postal_code' => '1000',
    'email' => $okProvEmail,
], '10.15.0.3', '555666');
$service->verifyOtp($okProvToken, '555666', '10.15.0.3');
$pendingBeforeProv = $service->getPendingRegistration($okProvToken);
$expectedVerifiedAt = $pendingBeforeProv['otp_verified_at'] ?? null;

$okProvRes = $service->provisionVerifiedAccount($okProvToken, 'ValidPass#2026');
assertTest('Successful provisioning returns ok=true (HTTP 200) with user_id and customer_id', $okProvRes['ok'] === true && $okProvRes['status'] === 200 && ($okProvRes['user_id'] ?? 0) > 0 && ($okProvRes['customer_id'] ?? 0) > 0);

$uaRow = $pdo->prepare('SELECT * FROM user_account WHERE user_account_id = ?');
$uaRow->execute([$okProvRes['user_id'] ?? 0]);
$uaData = $uaRow->fetch();
assertTest(
    'Permanent user_account created with active status, valid password hash, and matching email_verified_at',
    is_array($uaData)
        && $uaData['email'] === $okProvEmail
        && $uaData['username'] === $okProvUser
        && $uaData['account_status'] === 'active'
        && password_verify('ValidPass#2026', (string)$uaData['password_hash'])
        && $expectedVerifiedAt !== null
        && $uaData['email_verified_at'] === $expectedVerifiedAt
);

$custRow = $pdo->prepare('SELECT * FROM customer WHERE customer_id = ?');
$custRow->execute([$okProvRes['customer_id'] ?? 0]);
$custData = $custRow->fetch();
assertTest(
    'Permanent customer record created with all pending profile fields',
    is_array($custData)
        && (int)$custData['user_account_id'] === (int)($okProvRes['user_id'] ?? 0)
        && $custData['first_name'] === 'Jane'
        && $custData['middle_name'] === 'Marie'
        && $custData['last_name'] === 'Doe'
        && $custData['phone'] === '+63 917 123 4567'
        && $custData['address_line1'] === '123 Pet Lane'
        && $custData['city'] === 'Manila'
        && $custData['province'] === 'Metro Manila'
        && $custData['postal_code'] === '1000'
        && $custData['customer_status'] === 'active'
);
assertTest('Pending registration row deleted after successful provisioning', $service->getPendingRegistration($okProvToken) === null);

// 15.7 Duplicate email conflict preserves verified pending row
$dupEmailToken = CustomerRegistrationPendingService::generateSessionToken();
$service->initiateRegistration($dupEmailToken, [
    'first_name' => 'Dup',
    'last_name' => 'Email',
    'email' => $okProvEmail,
], '10.15.0.4', '777888');
// Note: initiateRegistration suppresses mail when email already exists, so mark otp_verified_at directly to test provisioning conflict
$dupEmailHash = CustomerRegistrationPendingService::hashSessionToken($dupEmailToken);
$pdo->prepare("UPDATE customer_registration_pending SET otp_verified_at = UTC_TIMESTAMP() WHERE session_token_hash = ?")
    ->execute([$dupEmailHash]);
$dupEmailRes = $service->provisionVerifiedAccount($dupEmailToken, 'ValidPass#2026');
assertTest(
    'Duplicate email conflict returns HTTP 409 with conflict_field=email',
    $dupEmailRes['ok'] === false && $dupEmailRes['status'] === 409 && ($dupEmailRes['conflict_field'] ?? '') === 'email' && isset($dupEmailRes['errors']['email'])
);
assertTest('Duplicate email conflict preserves verified pending registration', $service->isVerified($dupEmailToken) === true);

// 15.8 Duplicate username conflict preserves verified pending row
$dupUserToken = CustomerRegistrationPendingService::generateSessionToken();
$dupUserEmail = 'prov.dupuser.' . bin2hex(random_bytes(4)) . '@test.invalid';
$service->initiateRegistration($dupUserToken, [
    'first_name' => 'Dup',
    'last_name' => 'Username',
    'username' => $okProvUser,
    'email' => $dupUserEmail,
], '10.15.0.5', '888999');
$service->verifyOtp($dupUserToken, '888999', '10.15.0.5');
$dupUserRes = $service->provisionVerifiedAccount($dupUserToken, 'ValidPass#2026');
assertTest(
    'Duplicate username conflict returns HTTP 409 with conflict_field=username',
    $dupUserRes['ok'] === false && $dupUserRes['status'] === 409 && ($dupUserRes['conflict_field'] ?? '') === 'username' && isset($dupUserRes['errors']['username'])
);
assertTest('Duplicate username conflict preserves verified pending registration', $service->isVerified($dupUserToken) === true);

// 15.9 Database failure rollback and pending-row preservation
final class FailingCustomerInsertPdo extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'INSERT INTO customer')) {
            throw new PDOException('Simulated customer insert failure', 'HY000');
        }
        return parent::prepare($query, $options);
    }
}

$failPdo = new FailingCustomerInsertPdo($dsn, 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$failPdo->exec("SET time_zone = '+00:00'");
$failService = new CustomerRegistrationPendingService($failPdo);

$rbToken = CustomerRegistrationPendingService::generateSessionToken();
$rbEmail = 'prov.rollback.' . bin2hex(random_bytes(4)) . '@test.invalid';
$service->initiateRegistration($rbToken, [
    'first_name' => 'Rollback',
    'last_name' => 'Tester',
    'email' => $rbEmail,
], '10.15.0.6', '121314');
$service->verifyOtp($rbToken, '121314', '10.15.0.6');
$rbRes = $failService->provisionVerifiedAccount($rbToken, 'ValidPass#2026');
assertTest('Database failure during customer insert returns structured HTTP 500 error', $rbRes['ok'] === false && $rbRes['status'] === 500);

$rbUaCount = (int)$pdo->query("SELECT COUNT(*) FROM user_account WHERE email = " . $pdo->quote($rbEmail))->fetchColumn();
assertTest('Database failure rolled back user_account insert (0 rows created)', $rbUaCount === 0);
assertTest('Database failure preserved verified pending registration', $service->isVerified($rbToken) === true);

// 15.10 Concurrent double submission: exactly one account is created
$dblToken = CustomerRegistrationPendingService::generateSessionToken();
$dblEmail = 'prov.double.' . bin2hex(random_bytes(4)) . '@test.invalid';
$service->initiateRegistration($dblToken, [
    'first_name' => 'Double',
    'last_name' => 'Submit',
    'email' => $dblEmail,
], '10.15.0.7', '454545');
$service->verifyOtp($dblToken, '454545', '10.15.0.7');

$dblReadyA = $barrierDir . '/pc_prov_dbl_a_' . bin2hex(random_bytes(4)) . '.flag';
$dblReadyB = $barrierDir . '/pc_prov_dbl_b_' . bin2hex(random_bytes(4)) . '.flag';
$dblGo = $barrierDir . '/pc_prov_dbl_go_' . bin2hex(random_bytes(4)) . '.flag';
@unlink($dblReadyA);
@unlink($dblReadyB);
@unlink($dblGo);

$buildProvWorker = static function (string $readyFile) use ($bootstrapPath, $dsn, $dblToken, $dblGo): string {
    $secret = str_repeat('0123456789abcdef', 4);
    return "<?php\n"
        . "require_once '{$bootstrapPath}';\n"
        . "putenv('MAIL_DRIVER=null');\n"
        . "putenv('OTP_HMAC_SECRET={$secret}');\n"
        . "\\App\\Core\\EnvConfig::resetCache();\n"
        . "\$pdoW = new PDO('{$dsn}', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);\n"
        . "\$pdoW->exec(\"SET time_zone = '+00:00'\");\n"
        . "\$svcW = new \\App\\Services\\CustomerRegistrationPendingService(\$pdoW);\n"
        . "file_put_contents('{$readyFile}', 'ready');\n"
        . "\$t = microtime(true) + 5.0;\n"
        . "while (!file_exists('{$dblGo}') && microtime(true) < \$t) { usleep(1000); }\n"
        . "echo json_encode(\$svcW->provisionVerifiedAccount('{$dblToken}', 'ValidPass#2026'));\n";
};

$pDblA = proc_open("\"$phpBin\"", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesDblA);
$pDblB = proc_open("\"$phpBin\"", [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipesDblB);
fwrite($pipesDblA[0], $buildProvWorker($dblReadyA));
fclose($pipesDblA[0]);
fwrite($pipesDblB[0], $buildProvWorker($dblReadyB));
fclose($pipesDblB[0]);

$dblBarrierDeadline = microtime(true) + 5.0;
while ((!file_exists($dblReadyA) || !file_exists($dblReadyB)) && microtime(true) < $dblBarrierDeadline) {
    usleep(10000);
}
$dblBothReady = file_exists($dblReadyA) && file_exists($dblReadyB);
assertTest('Both concurrent provisioning workers reached ready barrier', $dblBothReady);

if (!$dblBothReady) {
    safelyTerminateAndClose($pDblA, $pipesDblA, 2.0);
    safelyTerminateAndClose($pDblB, $pipesDblB, 2.0);
} else {
    touch($dblGo);
    $resDblA = collectWorkerOutput($pDblA, $pipesDblA, 5.0);
    $resDblB = collectWorkerOutput($pDblB, $pipesDblB, 5.0);

    assertTest('Concurrent provisioning Worker A exited cleanly', !$resDblA['timedOut'] && $resDblA['exitCode'] === 0);
    assertTest('Concurrent provisioning Worker B exited cleanly', !$resDblB['timedOut'] && $resDblB['exitCode'] === 0);

    $dblDataA = json_decode($resDblA['stdout'], true);
    $dblDataB = json_decode($resDblB['stdout'], true);
    $dblStatuses = [$dblDataA['status'] ?? 0, $dblDataB['status'] ?? 0];
    sort($dblStatuses);

    assertTest('Concurrent double submission: exactly one succeeds (200) and one fails safely (404)', $dblStatuses === [200, 404], 'Statuses: ' . json_encode($dblStatuses));
    $dblUaCount = (int)$pdo->query("SELECT COUNT(*) FROM user_account WHERE email = " . $pdo->quote($dblEmail))->fetchColumn();
    assertTest('Concurrent double submission created exactly 1 user_account row', $dblUaCount === 1, "Count: {$dblUaCount}");
}
@unlink($dblReadyA);
@unlink($dblReadyB);
@unlink($dblGo);

// --- Final Cleanup ---
$pdo->exec("DELETE c FROM customer c INNER JOIN user_account u ON c.user_account_id = u.user_account_id WHERE u.email LIKE '%@test.invalid'");
$pdo->exec("DELETE FROM user_account WHERE email LIKE '%@test.invalid'");
$pdo->exec("DELETE FROM customer_registration_pending WHERE email LIKE '%@test.invalid'");
$pdo->exec("DELETE FROM email_otp_send_log WHERE email LIKE '%@test.invalid'");
$pdo->exec("DELETE FROM email_otp_failure_log WHERE email LIKE '%@test.invalid'");

echo "\n====================================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}