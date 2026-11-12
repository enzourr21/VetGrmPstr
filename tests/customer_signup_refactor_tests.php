<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$pdo = \App\Core\Database::pdo();
$base = 'http://127.0.0.1:8080';

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] $desc\n";
    } else {
        $failed++;
        echo "  [FAIL] $desc\n";
    }
}

echo "====================================================================\n";
echo "PAWCARIÑO CUSTOMER SIGNUP REFACTOR TEST SUITE\n";
echo "====================================================================\n\n";

// Section 1: 4-Step Registration Structure & Layout
echo "--- Section 1: Registration Order & Step Tracker Baseline ---\n";
$html = file_get_contents($base . '/register');

assertTest('Registration page loads with HTTP 200', $html !== false);
assertTest('Registration page has data-progressive-register form', str_contains($html, 'data-progressive-register'));

preg_match_all('/data-step-chip="(\d+)"/', $html, $chipMatches);
assertTest('Step tracker contains exactly 4 progress steps', count($chipMatches[1]) === 4);

assertTest('Step 1 chip is labeled "1. Full Name"', str_contains($html, '1. Full Name'));
assertTest('Step 2 chip is labeled "2. Details"', str_contains($html, '2. Details'));
assertTest('Step 3 chip is labeled "3. Verify Email"', str_contains($html, '3. Verify Email'));
assertTest('Step 4 chip is labeled "4. Set Password"', str_contains($html, '4. Set Password'));

// Section 2: Step 1 – Full Name
echo "\n--- Section 2: Step 1 (Full Name) Inspection ---\n";
assertTest('Step 1 header is "Step 1 · Full Name"', str_contains($html, 'Step 1 · Full Name'));
assertTest('Step 1 contains first_name input', str_contains($html, 'id="first_name"') && str_contains($html, 'name="first_name"'));
assertTest('Step 1 contains optional middle_name input', str_contains($html, 'id="middle_name"') && str_contains($html, 'name="middle_name"'));
assertTest('Step 1 contains last_name input', str_contains($html, 'id="last_name"') && str_contains($html, 'name="last_name"'));
assertTest('Step 1 contains name error container', str_contains($html, 'data-reg-name-error'));
assertTest('Step 1 contains Continue button with data-reg-unlock-2', str_contains($html, 'data-reg-unlock-2'));
assertTest('Step 1 is visible initially', preg_match('/id="reg-stage-1"[^>]*hidden/', $html) === 0);

// Section 3: Step 2 – Additional Details & Postal Code Validation
echo "\n--- Section 3: Step 2 (Additional Details) & Province/Postal Adjustments ---\n";
assertTest('Step 2 header is "Step 2 · Additional Details"', str_contains($html, 'Step 2 · Additional Details'));
assertTest('Step 2 is hidden initially', (bool)preg_match('/id="reg-stage-2"[^>]*hidden/', $html));
assertTest('Step 2 contains phone input', str_contains($html, 'id="phone"') && str_contains($html, 'name="phone"'));
assertTest('Step 2 contains street address input', str_contains($html, 'id="address_line1"') && str_contains($html, 'name="address_line1"'));
assertTest('Step 2 contains city input', str_contains($html, 'id="city"') && str_contains($html, 'name="city"'));
assertTest('Step 2 contains province input', str_contains($html, 'id="province"') && str_contains($html, 'name="province"'));
assertTest('Province label is strictly "Province" (without "/ State")', str_contains($html, '>Province</label>') && !str_contains($html, '>Province / State</label>'));
assertTest('Postal code input has inputmode="numeric"', str_contains($html, 'id="postal_code"') && str_contains($html, 'inputmode="numeric"'));
assertTest('Postal code input enforces pattern="[0-9]{4}"', str_contains($html, 'pattern="[0-9]{4}"'));
assertTest('Postal code input has maxlength="4"', str_contains($html, 'maxlength="4"'));
assertTest('Step 2 contains postal code error container', str_contains($html, 'data-reg-postal-error'));
assertTest('Step 2 contains Continue button with data-reg-unlock-3', str_contains($html, 'data-reg-unlock-3'));

// Section 4: Step 3 – Email Verification & 6-Box OTP Interface
echo "\n--- Section 4: Step 3 (Set & Verify Email) & Six-Box OTP Interface ---\n";
assertTest('Step 3 header is "Step 3 · Set and Verify Email"', str_contains($html, 'Step 3 · Set and Verify Email'));
assertTest('Step 3 is hidden initially', (bool)preg_match('/id="reg-stage-3"[^>]*hidden/', $html));
assertTest('Step 3 contains email input inside .email-input-group', str_contains($html, 'class="email-input-group"'));
assertTest('Step 3 contains Send Verification Code button beside email input', str_contains($html, 'data-send-otp-btn') && str_contains($html, 'Send Verification Code'));
assertTest('Step 3 contains OTP section with data-otp-section', str_contains($html, 'data-otp-section'));

preg_match_all('/data-otp-box="(\d+)"/', $html, $otpBoxes);
assertTest('Step 3 contains exactly 6 individual OTP input boxes', count($otpBoxes[1]) === 6);
assertTest('All 6 OTP boxes have inputmode="numeric"', substr_count($html, 'class="otp-digit-box" data-otp-box=') === 6);
assertTest('Step 3 contains hidden email_otp input', str_contains($html, 'id="email_otp"') && str_contains($html, 'name="email_otp"'));
assertTest('Step 3 contains OTP feedback message container', str_contains($html, 'data-otp-feedback'));
assertTest('Step 3 contains Continue button with data-reg-unlock-4', str_contains($html, 'data-reg-unlock-4'));

// Check positioning: OTP section precedes Continue button
$otpPos = strpos($html, 'data-otp-section');
$btnPos = strpos($html, 'data-reg-unlock-4');
assertTest('OTP boxes are positioned immediately above the Continue button', $otpPos !== false && $btnPos !== false && $otpPos < $btnPos);

// Section 5: Step 4 – Set Password & Inaccessibility Guard
echo "\n--- Section 5: Step 4 (Set Password) & Password Live Feedback ---\n";
assertTest('Step 4 header is "Step 4 · Set Password"', str_contains($html, 'Step 4 · Set Password'));
assertTest('Step 4 is hidden initially', (bool)preg_match('/id="reg-stage-4"[^>]*hidden/', $html));
assertTest('Step 4 contains password input', str_contains($html, 'id="password"') && str_contains($html, 'name="password"'));
assertTest('Step 4 contains confirm password input', str_contains($html, 'id="password_confirm"') && str_contains($html, 'name="password_confirm"'));
assertTest('Step 4 contains password length checklist rule', str_contains($html, 'data-pw-rule="length"'));
assertTest('Step 4 contains password match checklist rule', str_contains($html, 'data-pw-rule="match"'));
assertTest('Step 4 contains Create Account submit button', str_contains($html, 'id="reg-submit-btn"'));
assertTest('Create Account submit button is disabled initially', str_contains($html, 'id="reg-submit-btn" class="btn full" type="submit" disabled'));

// Section 6: Server-Side Validation (Postal Code & Registration)
echo "\n--- Section 6: Server-Side Validation & Boundary Enforcement ---\n";

function postRegistration(string $base, array $fields): array {
    $jar = tempnam(sys_get_temp_dir(), 'ps_test');
    $ch = curl_init($base . '/register');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    $page = curl_exec($ch);
    preg_match('/name="csrf_token" value="([^"]+)"/', $page, $m);
    $csrf = $m[1] ?? '';

    $fields['csrf_token'] = $csrf;
    curl_setopt($ch, CURLOPT_URL, $base . '/register');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    @unlink($jar);
    return ['status' => $status, 'body' => $resp];
}

$basePayload = [
    'first_name' => 'Carino',
    'middle_name' => 'Paw',
    'last_name' => 'Customer',
    'phone' => '0917-123-4567',
    'address_line1' => '742 Evergreen Terrace',
    'city' => 'Quezon City',
    'province' => 'Metro Manila',
    'password' => 'ValidPassword123!',
    'password_confirm' => 'ValidPassword123!',
];

// Test 1: Postal code with 5 digits
$p1 = postRegistration($base, array_merge($basePayload, [
    'email' => 'post1.' . bin2hex(random_bytes(3)) . '@test.invalid',
    'postal_code' => '12345',
]));
assertTest('Postal code with 5 digits rejected with HTTP 422', $p1['status'] === 422 && str_contains($p1['body'], 'Postal code must be exactly 4 numeric digits.'));

// Test 2: Postal code with 3 digits
$p2 = postRegistration($base, array_merge($basePayload, [
    'email' => 'post2.' . bin2hex(random_bytes(3)) . '@test.invalid',
    'postal_code' => '123',
]));
assertTest('Postal code with 3 digits rejected with HTTP 422', $p2['status'] === 422 && str_contains($p2['body'], 'Postal code must be exactly 4 numeric digits.'));

// Test 3: Postal code with letters
$p3 = postRegistration($base, array_merge($basePayload, [
    'email' => 'post3.' . bin2hex(random_bytes(3)) . '@test.invalid',
    'postal_code' => '12ab',
]));
assertTest('Postal code with letters rejected with HTTP 422', $p3['status'] === 422 && str_contains($p3['body'], 'Postal code must be exactly 4 numeric digits.'));

// Test 4: Postal code with spaces
$p4 = postRegistration($base, array_merge($basePayload, [
    'email' => 'post4.' . bin2hex(random_bytes(3)) . '@test.invalid',
    'postal_code' => '12 4',
]));
assertTest('Postal code with space rejected with HTTP 422', $p4['status'] === 422 && str_contains($p4['body'], 'Postal code must be exactly 4 numeric digits.'));

// Test 5: Postal code with special characters
$p5 = postRegistration($base, array_merge($basePayload, [
    'email' => 'post5.' . bin2hex(random_bytes(3)) . '@test.invalid',
    'postal_code' => '12-4',
]));
assertTest('Postal code with hyphen/special char rejected with HTTP 422', $p5['status'] === 422 && str_contains($p5['body'], 'Postal code must be exactly 4 numeric digits.'));

// Test 6: Valid exactly 4 numeric digits
$validEmail = 'valid.' . bin2hex(random_bytes(3)) . '@test.invalid';
$p6 = postRegistration($base, array_merge($basePayload, [
    'email' => $validEmail,
    'postal_code' => '1100',
]));
assertTest('Postal code with exactly 4 numeric digits succeeds (302 redirect)', $p6['status'] === 302);

// Verify database record
$savedUser = $pdo->prepare("SELECT u.email, c.postal_code, c.province FROM user_account u JOIN customer c ON c.user_account_id=u.user_account_id WHERE u.email = ?");
$savedUser->execute([$validEmail]);
$userRow = $savedUser->fetch(PDO::FETCH_ASSOC);
assertTest('Customer record accurately stores 4-digit postal code in database', ($userRow['postal_code'] ?? '') === '1100');
assertTest('Customer record accurately stores province in database', ($userRow['province'] ?? '') === 'Metro Manila');

// Clean up test account
$pdo->exec("DELETE c FROM customer c JOIN user_account u ON u.user_account_id=c.user_account_id WHERE u.email LIKE '%@test.invalid'");
$pdo->exec("DELETE FROM user_account WHERE email LIKE '%@test.invalid'");

// Section 7: Design Preservation & Scenic Rails Check
echo "\n--- Section 7: Preserved Design & Scenic Rails Check ---\n";
assertTest('Signup card preserves .auth-shell container', str_contains($html, '<section class="auth-shell">'));
assertTest('Signup card preserves .auth-card container', str_contains($html, '<div class="auth-card">'));
assertTest('Scenic animals are preserved on registration page', str_contains($html, 'data-animal="sunny"') && str_contains($html, 'data-animal="bella"'));
assertTest('Scenic canopy is preserved', str_contains($html, 'scenic-canopy'));

// Section 8: Employee & Admin Authentication Non-Regression
echo "\n--- Section 8: Employee & Admin Authentication Non-Regression ---\n";
$superLoginJar = tempnam(sys_get_temp_dir(), 'sa_test');
$ch = curl_init($base . '/login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $superLoginJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $superLoginJar);
$loginPage = curl_exec($ch);
preg_match('/name="csrf_token" value="([^"]+)"/', $loginPage, $m);
$loginCsrf = $m[1] ?? '';

// Check employee login with email + password
curl_setopt($ch, CURLOPT_URL, $base . '/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'csrf_token' => $loginCsrf,
    'email' => 'superadmin@vetgrpet.local',
    'password' => 'SuperAdmin!2026',
]));
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
$superResp = curl_exec($ch);
$superStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$superLocation = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
assertTest('Super Admin login via /login password succeeds (HTTP 302)', $superStatus === 302);
assertTest('Super Admin redirects to /super-admin', str_contains($superLocation, '/super-admin'));
@unlink($superLoginJar);

// Summary
echo "\n====================================================================\n";
echo "TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}

