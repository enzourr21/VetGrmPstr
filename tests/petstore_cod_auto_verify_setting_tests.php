<?php
declare(strict_types=1);

/**
 * PawCariño — Checkpoint 4: Pet Store Auto-Verify COD Remittance Setting Regression Suite
 *
 * Targets only the explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  1. Default Off behavior, Pet Store-only rendering, and accurate persisted values.
 *  2. Authorized Pet Store BM can toggle auto_verify_cod_remittance On (1) and Off (0) with audit logging.
 *  3. Rejection of invalid values and missing/invalid CSRF tokens without DB mutation.
 *  4. Strict operational authorization (Inventory Manager, Receptionist, Courier, cross-unit BM,
 *     inactive employee/account, and Super Admin without explicit active Pet Store BM assignment blocked;
 *     Super Admin without explicit assignment gets read-only GET UI; Super Admin WITH explicit active
 *     Pet Store BM assignment follows the same operational rules and preserves unit query/route redirects).
 *  5. Non-retroactive behavior: toggling never alters existing allocations; future Receptionist handovers
 *     honor current setting (Off -> completed+pending, On -> completed+verified with auto_setting).
 *  6. BM direct collection, BM manual verification, and customer order/payment states remain intact.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/process_cleanup_helper.php';

use App\Core\Database;
use App\Services\OrderPaymentService;
use App\Services\OrderReservationService;

$pdo = Database::pdo();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$allowedTestDatabases = ['vetgrpet_test'];
if ($dbName === '' || !in_array($dbName, $allowedTestDatabases, true)) {
    fwrite(STDERR, "Refusing to run against non-allowlisted database: {$dbName}\n");
    exit(1);
}

$passed = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        echo "FAIL: {$label}" . ($detail !== '' ? " [{$detail}]" : '') . "\n";
    }
}

final class AutoVerifyHttpClient
{
    public array $cookies = [];
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->send('POST', $path, http_build_query($data));
    }

    public function extractCsrf(string $html): string
    {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        throw new RuntimeException('CSRF token not found in HTML response.');
    }

    public function staffLogin(string $email, string $password): array
    {
        $this->cookies = [];
        $page = $this->get('/staff/login');
        $csrf = $this->extractCsrf($page['body']);
        return $this->post('/staff/login', [
            'csrf_token' => $csrf,
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function send(string $method, string $path, ?string $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [];
        if ($this->cookies) {
            $parts = [];
            foreach ($this->cookies as $k => $v) {
                $parts[] = "{$k}={$v}";
            }
            $headers[] = 'Cookie: ' . implode('; ', $parts);
        }
        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $respBody = substr($raw, $headerSize);

        if (preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;\r\n]+)/mi', $rawHeaders, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $this->cookies[trim($m[1])] = trim($m[2]);
            }
        }

        $location = '';
        if (preg_match('/^Location:\s*(\S+)/mi', $rawHeaders, $lm)) {
            $location = trim($lm[1]);
        }

        return [
            'status' => $status,
            'headers' => $rawHeaders,
            'body' => $respBody,
            'location' => $location,
        ];
    }
}

function startBuiltInServer(int $port): array
{
    $phpBin = 'D:\\VETGRPETproj\\tools\\php83\\php.exe';
    if (!is_file($phpBin)) {
        $phpBin = PHP_BINARY;
    }
    $docRoot = dirname(__DIR__) . '/public';
    $router = $docRoot . '/index.php';
    $cmd = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', $phpBin, $port, $docRoot, $router);
    $spec = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', 'NUL', 'w'],
        2 => ['file', 'NUL', 'w'],
    ];
    $proc = proc_open($cmd, $spec, $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start built-in PHP server.');
    }

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($fp)) {
            fclose($fp);
            return [$proc, $pipes];
        }
        usleep(100_000);
    }
    safelyTerminateAndClose($proc, $pipes, 1.0);
    throw new RuntimeException("Built-in server did not become ready on port {$port}.");
}

function extractSectionById(string $html, string $id): string
{
    $quoted = preg_quote($id, '#');
    if (preg_match('#<div\b[^>]*\bid="' . $quoted . '"[^>]*>.*?(?=</section>)#s', $html, $m)) {
        return $m[0];
    }
    return '';
}

$port = 18096;
[$serverProc, $serverPipes] = startBuiltInServer($port);
$baseUrl = "http://127.0.0.1:{$port}";
$suffix = bin2hex(random_bytes(4));
$pwPlain = 'AutoVerifyTest!2026';
$pwHash = password_hash($pwPlain, PASSWORD_BCRYPT);
$unitId = 1;

$reservationService = new OrderReservationService($pdo);
$paymentService = new OrderPaymentService($pdo);

$cleanup = [
    'remittances' => [],
    'orders' => [],
    'products' => [],
    'branches' => [],
    'customers' => [],
    'employees' => [],
    'users' => [],
    'created_unit1_settings' => false,
    'orig_unit1_auto_verify' => 0,
    'orig_unit2_auto_verify' => null,
];

try {
    echo "====================================================================\n";
    echo "PET STORE AUTO-VERIFY COD REMITTANCE SETTING SUITE (DB: {$dbName})\n";
    echo "====================================================================\n";

    $bsStmt = $pdo->prepare('SELECT setting_id, auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = ? LIMIT 1');
    $bsStmt->execute([$unitId]);
    $bsRow = $bsStmt->fetch(PDO::FETCH_ASSOC);
    if ($bsRow) {
        $cleanup['orig_unit1_auto_verify'] = (int)$bsRow['auto_verify_cod_remittance'];
        $pdo->prepare('UPDATE business_settings SET auto_verify_cod_remittance = 0 WHERE business_unit_id = ?')->execute([$unitId]);
    } else {
        $pdo->prepare(
            "INSERT INTO business_settings (business_unit_id, business_name, business_email, contact_number, cod_enabled, auto_verify_cod_remittance)
             VALUES (?, 'Pet Store Test Settings', 'petstore@vetgrpet.local', '09175550100', 1, 0)"
        )->execute([$unitId]);
        $cleanup['created_unit1_settings'] = true;
    }

    $bs2Stmt = $pdo->prepare('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 2 LIMIT 1');
    $bs2Stmt->execute();
    $bs2Val = $bs2Stmt->fetchColumn();
    $cleanup['orig_unit2_auto_verify'] = $bs2Val !== false ? (int)$bs2Val : null;

    $branchId = (int)$pdo->query("SELECT branch_id FROM branch WHERE business_unit_id = 1 LIMIT 1")->fetchColumn();
    if ($branchId <= 0) {
        $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, city, phone) VALUES (1, 'AutoVerify Branch', 'Manila', '000-0000')");
        $branchId = (int)$pdo->lastInsertId();
        $cleanup['branches'][] = $branchId;
    }

    $roleIds = [];
    foreach (['DELIVERY_PERSONNEL', 'RECEPTIONIST', 'BUSINESS_MANAGER', 'INVENTORY_MANAGER', 'SUPER_ADMINISTRATOR'] as $rName) {
        $roleIds[$rName] = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = '{$rName}' LIMIT 1")->fetchColumn();
    }

    $createStaff = static function (
        string $tag,
        string $first,
        string $last,
        int $empUnitId,
        array $unitRoles,
        string $empStatus = 'active',
        string $accStatus = 'active',
        int $isSuperAdmin = 0
    ) use ($pdo, $suffix, $pwHash, $roleIds, &$cleanup): array {
        $email = "avcod_{$tag}_{$suffix}@test.invalid";
        $pdo->prepare('INSERT INTO user_account (email, password_hash, account_status, is_super_admin) VALUES (?, ?, ?, ?)')
            ->execute([$email, $pwHash, $accStatus, $isSuperAdmin]);
        $uid = (int)$pdo->lastInsertId();
        $cleanup['users'][] = $uid;

        $empId = 0;
        if ($empUnitId > 0) {
            $pdo->prepare(
                'INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, phone, employee_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$uid, $empUnitId, $first, $last, $tag, '0917-000-0000', $empStatus]);
            $empId = (int)$pdo->lastInsertId();
            $cleanup['employees'][] = $empId;
        }

        foreach ($unitRoles as [$rName, $rUnitId]) {
            $pdo->prepare('INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)')
                ->execute([$uid, $roleIds[$rName], $rUnitId]);
        }

        return [$uid, $empId, $email];
    };

    [$bmUid, $bmEmpId, $bmEmail] = $createStaff('bm', 'Ava', 'PetManager', 1, [['BUSINESS_MANAGER', 1]]);
    [$recUid, $recEmpId, $recEmail] = $createStaff('rec', 'Rex', 'Recept', 1, [['RECEPTIONIST', 1]]);
    [$courierUid, $courierEmpId, $courierEmail] = $createStaff('courier', 'Cody', 'Courier', 1, [['DELIVERY_PERSONNEL', 1]]);
    [$imUid, $imEmpId, $imEmail] = $createStaff('im', 'Ira', 'InvMgr', 1, [['INVENTORY_MANAGER', 1]]);
    [$vetBmUid, $vetBmEmpId, $vetBmEmail] = $createStaff('vetbm', 'Vera', 'VetBm', 2, [['BUSINESS_MANAGER', 2], ['BUSINESS_MANAGER', 1]]);
    [$inactEmpUid, $inactEmpId, $inactEmpEmail] = $createStaff('inactemp', 'Ian', 'InactEmp', 1, [['BUSINESS_MANAGER', 1]], 'inactive', 'active');
    [$inactAccUid, $inactAccEmpId, $inactAccEmail] = $createStaff('inactacc', 'Ida', 'InactAcc', 1, [['BUSINESS_MANAGER', 1]], 'active', 'inactive');
    [$superNoBmUid, $superNoBmEmpId, $superNoBmEmail] = $createStaff('super_nobm', 'Sam', 'SuperNoBm', 2, [['SUPER_ADMINISTRATOR', 1]], 'active', 'active', 1);
    [$superWithBmUid, $superWithBmEmpId, $superWithBmEmail] = $createStaff('super_bm', 'Sue', 'SuperBm', 1, [['SUPER_ADMINISTRATOR', 1], ['BUSINESS_MANAGER', 1]], 'active', 'active', 1);

    // Customer & Product
    $pdo->prepare('INSERT INTO user_account (email, password_hash, account_status) VALUES (?, ?, ?)')
        ->execute(["avcod_cust_{$suffix}@test.invalid", $pwHash, 'active']);
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->prepare('INSERT INTO customer (user_account_id, first_name, last_name, phone, customer_status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$custUid, 'Nina', "Buyer{$suffix}", '0917-555-6666', 'active']);
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    $pdo->prepare("INSERT INTO product (product_name, product_category, sku) VALUES (?, 'Food', ?)")
        ->execute(["AVCOD Kibble {$suffix}", "SKU-AVCOD-{$suffix}"]);
    $prodId = (int)$pdo->lastInsertId();
    $cleanup['products'][] = $prodId;
    $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status) VALUES (1, ?, 80.00, 0, 'active')")
        ->execute([$prodId]);
    $bpId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, 500, 0, 0)')
        ->execute([$bpId]);

    $createCollectedDeliveryOrder = static function (string $subtotal, string $fee) use (
        $pdo,
        $unitId,
        $branchId,
        $custId,
        $bpId,
        $courierEmpId,
        $courierUid,
        $recUid,
        $reservationService,
        $paymentService,
        &$cleanup
    ): array {
        $total = number_format((float)$subtotal + (float)$fee, 2, '.', '');
        $pdo->prepare(
            "INSERT INTO customer_order
             (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, subtotal_amount, delivery_fee, total_amount)
             VALUES (?, ?, ?, 'delivery', 'pending', ?, ?, ?)"
        )->execute([$custId, $unitId, $branchId, $subtotal, $fee, $total]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $orderId;

        $pdo->prepare('INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, 1, ?)')
            ->execute([$orderId, $bpId, $subtotal]);

        $pdo->prepare(
            "INSERT INTO payment (order_id, payment_type, amount, payment_date, payment_method, payment_status)
             VALUES (?, 'payment', ?, NOW(), 'cod', 'pending')"
        )->execute([$orderId, $total]);
        $paymentId = (int)$pdo->lastInsertId();

        $reservationService->confirmDeliveryOrderAndReserve($orderId, $unitId, $recUid);
        $reservationService->markDeliveryOrderProcessing($orderId, $unitId, $recUid);
        $reservationService->dispatchDeliveryOrder($orderId, $unitId, $courierEmpId, $courierUid);
        $reservationService->completeDeliveryOrder(
            $orderId,
            $unitId,
            $courierEmpId,
            $courierUid,
            static function () use ($paymentService, $orderId, $unitId, $courierEmpId, $courierUid): void {
                $paymentService->collectDeliveryCodInTransaction($orderId, $unitId, $courierEmpId, $courierUid);
            }
        );

        return ['order_id' => $orderId, 'payment_id' => $paymentId];
    };

    // Seed 5 collected COD allocations across batches to test non-retroactive toggling & both setting modes
    $oOff = $createCollectedDeliveryOrder('100.00', '40.00');
    $oPreExisting = $createCollectedDeliveryOrder('120.00', '40.00');
    $oOnFuture = $createCollectedDeliveryOrder('140.00', '40.00');
    $oBmCollect = $createCollectedDeliveryOrder('160.00', '40.00');
    $oManualVerify = $createCollectedDeliveryOrder('180.00', '40.00');

    $remitRes = $paymentService->submitCourierCodRemittance(
        $unitId,
        $courierEmpId,
        $courierUid,
        [$oOff['payment_id'], $oPreExisting['payment_id'], $oOnFuture['payment_id'], $oBmCollect['payment_id'], $oManualVerify['payment_id']],
        '700.00',
        "AutoVerify Test Batch {$suffix}"
    );
    $remitId = (int)$remitRes['remittance_id'];
    $cleanup['remittances'][] = $remitId;
    [$allocOff, $allocPreExisting, $allocOnFuture, $allocBmCollect, $allocManualVerify] = array_map(
        'intval',
        $pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remitId} ORDER BY allocation_id ASC")->fetchAll(PDO::FETCH_COLUMN)
    );

    // Complete $allocManualVerify now under Off (0) and leave $allocPreExisting submitted+pending
    $paymentService->completeRemittanceAllocation($allocManualVerify, $recUid);

    // =========================================================================
    // 1. Default Off UI & Pet Store BM Operational Toggle (On -> Off -> On -> Off)
    // =========================================================================
    $bmClient = new AutoVerifyHttpClient($baseUrl);
    $bmClient->staffLogin($bmEmail, $pwPlain);
    $settingsPage = $bmClient->get('/manager/settings');
    $bmCsrf = $bmClient->extractCsrf($settingsPage['body']);
    $codSection = extractSectionById($settingsPage['body'], 'cod-remittance-verification-section');

    check(
        'Pet Store Business Settings displays COD Remittance Verification defaulting to Off with future-only guidance and enabled controls',
        $settingsPage['status'] === 200
            && str_contains($codSection, 'COD Remittance Verification')
            && str_contains($codSection, 'Off (Manual Verification)')
            && str_contains($codSection, 'Future handovers only:')
            && str_contains($codSection, 'Toggling this setting only applies to Receptionist handover completions performed after the change.')
            && str_contains($codSection, 'action="/manager/settings/cod-remittance-verification"')
            && str_contains($codSection, 'id="save-cod-auto-verify-btn"')
    );

    // Receptionist completes $allocOff while setting is Off -> completed + pending
    $recClient = new AutoVerifyHttpClient($baseUrl);
    $recClient->staffLogin($recEmail, $pwPlain);
    $recRemitPage = $recClient->get('/petstore/receptionist/remittances');
    $recCsrf = $recClient->extractCsrf($recRemitPage['body']);
    $recOffPost = $recClient->post("/petstore/receptionist/remittances/allocations/{$allocOff}/complete", ['csrf_token' => $recCsrf]);
    $dbAllocOff = $pdo->query("SELECT handover_status, completed_by_employee_id, verification_status, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocOff}")->fetch(PDO::FETCH_ASSOC);

    check(
        'With setting Off, Receptionist handover completion produces completed + pending with NULL verification_mode',
        $recOffPost['status'] === 302
            && $dbAllocOff['handover_status'] === 'completed'
            && (int)$dbAllocOff['completed_by_employee_id'] === $recEmpId
            && $dbAllocOff['verification_status'] === 'pending'
            && $dbAllocOff['verification_mode'] === null
    );

    // Snapshot pre-existing allocations before turning On
    $preExistingBefore = $pdo->query("SELECT handover_status, verification_status, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocPreExisting}")->fetch(PDO::FETCH_ASSOC);
    $manualBefore = $pdo->query("SELECT handover_status, verification_status, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocManualVerify}")->fetch(PDO::FETCH_ASSOC);

    // Authorized Pet Store BM switches setting ON (1)
    $turnOnPost = $bmClient->post('/manager/settings/cod-remittance-verification', [
        'csrf_token' => $bmCsrf,
        'auto_verify_cod_remittance' => '1',
    ]);
    $afterOnPage = $bmClient->get('/manager/settings');
    $dbSettingOn = (int)$pdo->query('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 1')->fetchColumn();
    $auditOnCnt = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE actor_user_id = {$bmUid} AND action_code = 'business_settings.cod_remittance_auto_verify' AND new_value = 'auto_verify_cod_remittance:1'")->fetchColumn();

    $preExistingAfterOn = $pdo->query("SELECT handover_status, verification_status, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocPreExisting}")->fetch(PDO::FETCH_ASSOC);
    $manualAfterOn = $pdo->query("SELECT handover_status, verification_status, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocManualVerify}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Pet Store BM turning setting On persists 1 for unit 1, audits, renders On badge, and leaves existing submitted & completed allocations unchanged',
        $turnOnPost['status'] === 302
            && $turnOnPost['location'] === '/manager/settings'
            && $dbSettingOn === 1
            && $auditOnCnt >= 1
            && str_contains($afterOnPage['body'], 'On (Auto-Verify)')
            && $preExistingAfterOn === $preExistingBefore
            && $manualAfterOn === $manualBefore
    );

    // Future Receptionist handover under setting On -> completed + verified with auto_setting
    $recOnPost = $recClient->post("/petstore/receptionist/remittances/allocations/{$allocOnFuture}/complete", ['csrf_token' => $recCsrf]);
    $dbAllocOnFuture = $pdo->query("SELECT handover_status, completed_by_employee_id, verification_status, verified_by_employee_id, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocOnFuture}")->fetch(PDO::FETCH_ASSOC);

    check(
        'With setting On, future Receptionist handover completes and auto-verifies with verification_mode=auto_setting and NULL verifier',
        $recOnPost['status'] === 302
            && $dbAllocOnFuture['handover_status'] === 'completed'
            && (int)$dbAllocOnFuture['completed_by_employee_id'] === $recEmpId
            && $dbAllocOnFuture['verification_status'] === 'verified'
            && $dbAllocOnFuture['verified_by_employee_id'] === null
            && $dbAllocOnFuture['verification_mode'] === 'auto_setting'
    );

    // BM direct collection & manual verification remain unchanged regardless of setting = 1
    $bmRemitPage = $bmClient->get('/manager/remittances');
    $bmRemitCsrf = $bmClient->extractCsrf($bmRemitPage['body']);
    $bmClient->post("/manager/remittances/allocations/{$allocBmCollect}/collect", ['csrf_token' => $bmRemitCsrf]);
    $bmClient->post("/manager/remittances/allocations/{$allocManualVerify}/verify", ['csrf_token' => $bmRemitCsrf]);
    $dbBmCollect = $pdo->query("SELECT verification_status, verified_by_employee_id, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocBmCollect}")->fetch(PDO::FETCH_ASSOC);
    $dbManualVerify = $pdo->query("SELECT handover_status, completed_by_employee_id, verification_status, verified_by_employee_id, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocManualVerify}")->fetch(PDO::FETCH_ASSOC);

    check(
        'BM direct collection (manager_collection) and manual verification (manual) remain unchanged when auto-verify is On',
        $dbBmCollect['verification_status'] === 'verified'
            && (int)$dbBmCollect['verified_by_employee_id'] === $bmEmpId
            && $dbBmCollect['verification_mode'] === 'manager_collection'
            && (int)$dbManualVerify['completed_by_employee_id'] === $recEmpId
            && $dbManualVerify['verification_status'] === 'verified'
            && (int)$dbManualVerify['verified_by_employee_id'] === $bmEmpId
            && $dbManualVerify['verification_mode'] === 'manual'
    );

    // Turn setting back Off -> does not downgrade $allocOnFuture; persists 0
    $turnOffPost = $bmClient->post('/manager/settings/cod-remittance-verification', [
        'csrf_token' => $bmCsrf,
        'auto_verify_cod_remittance' => '0',
    ]);
    $dbSettingOff = (int)$pdo->query('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 1')->fetchColumn();
    $dbAllocOnFutureAfterOff = $pdo->query("SELECT verification_status, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocOnFuture}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Pet Store BM can toggle setting back to Off (0) without retroactively altering previously auto-verified allocations',
        $turnOffPost['status'] === 302
            && $dbSettingOff === 0
            && $dbAllocOnFutureAfterOff['verification_status'] === 'verified'
            && $dbAllocOnFutureAfterOff['verification_mode'] === 'auto_setting'
    );

    // =========================================================================
    // 2. Invalid Values & Invalid/Missing CSRF Rejection
    // =========================================================================
    foreach (['2', 'yes', 'true', 'on', '-1', ''] as $badVal) {
        $badPost = $bmClient->post('/manager/settings/cod-remittance-verification', [
            'csrf_token' => $bmCsrf,
            'auto_verify_cod_remittance' => $badVal,
        ]);
        $dbAfterBad = (int)$pdo->query('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 1')->fetchColumn();
        if ($badPost['status'] !== 302 || $dbAfterBad !== 0) {
            check("Invalid value '{$badVal}' rejected without mutating DB", false);
        }
    }
    $missingFieldPost = $bmClient->post('/manager/settings/cod-remittance-verification', ['csrf_token' => $bmCsrf]);
    $badCsrfPost = $bmClient->post('/manager/settings/cod-remittance-verification', [
        'csrf_token' => 'invalid_csrf_token_xyz',
        'auto_verify_cod_remittance' => '1',
    ]);
    $noCsrfPost = $bmClient->post('/manager/settings/cod-remittance-verification', [
        'auto_verify_cod_remittance' => '1',
    ]);
    $dbAfterInvalidChecks = (int)$pdo->query('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 1')->fetchColumn();

    check(
        'Invalid values, missing value, and missing/invalid CSRF are rejected without changing auto_verify_cod_remittance',
        $missingFieldPost['status'] === 302
            && $badCsrfPost['status'] === 419
            && $noCsrfPost['status'] === 419
            && $dbAfterInvalidChecks === 0
    );

    // =========================================================================
    // 3. Strict Authorization Guards (Unauthorized Roles, Cross-Unit, Inactive, Super Admin)
    // =========================================================================
    foreach ([
        'Inventory Manager' => [$imEmail, true],
        'Pet Store Receptionist' => [$recEmail, true],
        'Delivery Personnel' => [$courierEmail, true],
        'Cross-unit Vet Clinic BM (with unit-1 BM role but unit-2 employee)' => [$vetBmEmail, true],
        'Inactive employee' => [$inactEmpEmail, false],
        'Inactive user_account' => [$inactAccEmail, false],
    ] as $label => [$uEmail, $canLogin]) {
        $c = new AutoVerifyHttpClient($baseUrl);
        $c->staffLogin($uEmail, $pwPlain);
        $home = $c->get('/');
        $csrfAttempt = preg_match('/name="csrf_token"\s+value="([^"]+)"/', $home['body'], $cm)
            ? $cm[1]
            : $c->extractCsrf($c->get('/staff/login')['body']);
        $p = $c->post('/manager/settings/cod-remittance-verification', [
            'csrf_token' => $csrfAttempt,
            'auto_verify_cod_remittance' => '1',
        ]);
        $dbCheck = (int)$pdo->query('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 1')->fetchColumn();
        $ok = $canLogin
            ? ($p['status'] === 403 && $dbCheck === 0)
            : (str_contains($p['location'], '/login') && $dbCheck === 0);
        check("Unauthorized actor ({$label}) cannot update COD auto-verify setting", $ok);
    }

    // Vet Clinic BM requesting unit 2 settings never sees COD Remittance Verification and gets 403 on unit 2 POST
    $vetClient = new AutoVerifyHttpClient($baseUrl);
    $vetClient->staffLogin($vetBmEmail, $pwPlain);
    $vetSettings2 = $vetClient->get('/manager/units/2/settings');
    $vetCsrf2 = $vetClient->extractCsrf($vetSettings2['body']);
    $vetPost2 = $vetClient->post('/manager/units/2/settings/cod-remittance-verification', [
        'csrf_token' => $vetCsrf2,
        'auto_verify_cod_remittance' => '1',
    ]);
    check(
        'Non-Pet Store unit settings page hides COD Remittance Verification and rejects unit-2 POST (403)',
        $vetSettings2['status'] === 200
            && !str_contains($vetSettings2['body'], 'cod-remittance-verification-section')
            && $vetPost2['status'] === 403
    );

    // Super Administrator WITHOUT explicit active Pet Store BM assignment -> read-only view, 403 on POST
    $saNoBmClient = new AutoVerifyHttpClient($baseUrl);
    $saNoBmClient->staffLogin($superNoBmEmail, $pwPlain);
    $saNoBmGet = $saNoBmClient->get('/manager/settings?unit_id=1');
    $saNoBmSection = extractSectionById($saNoBmGet['body'], 'cod-remittance-verification-section');
    $saNoBmCsrf = $saNoBmClient->extractCsrf($saNoBmGet['body']);
    $saNoBmPost = $saNoBmClient->post('/manager/settings/cod-remittance-verification?unit_id=1', [
        'csrf_token' => $saNoBmCsrf,
        'auto_verify_cod_remittance' => '1',
    ]);
    $dbAfterSaNoBm = (int)$pdo->query('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 1')->fetchColumn();

    check(
        'Super Admin without explicit active Pet Store BM assignment sees read-only disabled COD controls and receives 403 on POST',
        $saNoBmGet['status'] === 200
            && str_contains($saNoBmSection, 'Administrative Oversight Mode (Read-Only)')
            && str_contains($saNoBmSection, 'disabled')
            && !str_contains($saNoBmSection, 'id="save-cod-auto-verify-btn"')
            && $saNoBmPost['status'] === 403
            && $dbAfterSaNoBm === 0
    );

    // Also verify Super Admin viewing unit 2 settings does not see COD Remittance Verification
    $saUnit2Get = $saNoBmClient->get('/manager/settings?unit_id=2');
    check(
        'Super Admin viewing Vet Clinic settings (unit_id=2) does not see COD Remittance Verification',
        $saUnit2Get['status'] === 200 && !str_contains($saUnit2Get['body'], 'cod-remittance-verification-section')
    );

    // Super Administrator WITH explicit active Pet Store employee + unit-1 BUSINESS_MANAGER role -> operational access + unit redirect preservation
    $saWithBmClient = new AutoVerifyHttpClient($baseUrl);
    $saWithBmClient->staffLogin($superWithBmEmail, $pwPlain);
    $saWithBmGet = $saWithBmClient->get('/manager/settings?unit_id=1');
    $saWithBmSection = extractSectionById($saWithBmGet['body'], 'cod-remittance-verification-section');
    $saWithBmCsrf = $saWithBmClient->extractCsrf($saWithBmGet['body']);
    $saWithBmPostQuery = $saWithBmClient->post('/manager/settings/cod-remittance-verification?unit_id=1', [
        'csrf_token' => $saWithBmCsrf,
        'auto_verify_cod_remittance' => '1',
    ]);
    $saWithBmPostRoute = $saWithBmClient->post('/manager/units/1/settings/cod-remittance-verification', [
        'csrf_token' => $saWithBmCsrf,
        'auto_verify_cod_remittance' => '0',
    ]);

    $dbAfterSaWithBm = (int)$pdo->query('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 1')->fetchColumn();
    check(
        'Super Admin with explicit active Pet Store BM assignment sees enabled control, can toggle On/Off, and preserves ?unit_id=1 / unit route redirects',
        $saWithBmGet['status'] === 200
            && str_contains($saWithBmSection, 'id="save-cod-auto-verify-btn"')
            && !str_contains($saWithBmSection, 'Administrative Oversight Mode')
            && $saWithBmPostQuery['status'] === 302
            && $saWithBmPostQuery['location'] === '/manager/settings?unit_id=1'
            && $saWithBmPostRoute['status'] === 302
            && $saWithBmPostRoute['location'] === '/manager/units/1/settings'
            && $dbAfterSaWithBm === 0
    );

    // Ensure unit 2 business_settings (if it existed) was never modified
    if ($cleanup['orig_unit2_auto_verify'] !== null) {
        $u2After = (int)$pdo->query('SELECT auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = 2')->fetchColumn();
        check('Unit 2 business_settings.auto_verify_cod_remittance untouched', $u2After === $cleanup['orig_unit2_auto_verify']);
    }

    // Verify customer order & payment states remained completely untouched across all operations
    $ordFinal = $pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$oOnFuture['order_id']}")->fetchColumn();
    $payFinal = $pdo->query("SELECT payment_status FROM payment WHERE payment_id = {$oOnFuture['payment_id']}")->fetchColumn();
    check(
        'Customer order_status and payment_status remain completed throughout setting & remittance operations',
        $ordFinal === 'completed' && $payFinal === 'completed'
    );

    echo "\n====================================================================\n";
    echo "PET STORE AUTO-VERIFY SETTING RESULTS: {$passed} PASSED, {$failed} FAILED\n";
    echo "====================================================================\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        if ($cleanup['created_unit1_settings']) {
            $pdo->prepare('DELETE FROM business_settings WHERE business_unit_id = ?')->execute([$unitId]);
        } else {
            $pdo->prepare('UPDATE business_settings SET auto_verify_cod_remittance = ? WHERE business_unit_id = ?')
                ->execute([$cleanup['orig_unit1_auto_verify'], $unitId]);
        }
        if ($cleanup['remittances']) {
            $rList = implode(',', array_map('intval', $cleanup['remittances']));
            $pdo->exec("DELETE al FROM audit_log al JOIN courier_cod_remittance_allocation a ON a.allocation_id = al.target_id WHERE al.target_type = 'courier_cod_remittance_allocation' AND a.remittance_id IN ({$rList})");
            $pdo->exec("DELETE FROM audit_log WHERE target_type = 'courier_cod_remittance' AND target_id IN ({$rList})");
            $pdo->exec("DELETE FROM courier_cod_remittance_allocation WHERE remittance_id IN ({$rList})");
            $pdo->exec("DELETE FROM courier_cod_remittance WHERE remittance_id IN ({$rList})");
        }
        if ($cleanup['orders']) {
            $oList = implode(',', array_map('intval', $cleanup['orders']));
            $pdo->exec("DELETE al FROM audit_log al JOIN payment p ON p.payment_id = al.target_id WHERE al.target_type = 'payment' AND p.order_id IN ({$oList})");
            $pdo->exec("DELETE FROM payment WHERE payment_type = 'refund' AND order_id IN ({$oList})");
            $pdo->exec("DELETE FROM payment WHERE order_id IN ({$oList})");
            $pdo->exec("DELETE im FROM inventory_movement im JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id JOIN order_item oi ON oi.order_item_id = ir.order_item_id WHERE oi.order_id IN ({$oList})");
            $pdo->exec("DELETE ir FROM inventory_reservation ir JOIN order_item oi ON oi.order_item_id = ir.order_item_id WHERE oi.order_id IN ({$oList})");
            $pdo->exec("DELETE FROM audit_log WHERE target_type = 'customer_order' AND target_id IN ({$oList})");
            $pdo->exec("DELETE FROM order_item WHERE order_id IN ({$oList})");
            $pdo->exec("DELETE FROM customer_order WHERE order_id IN ({$oList})");
        }
        if ($cleanup['products']) {
            $pList = implode(',', array_map('intval', $cleanup['products']));
            $pdo->exec("DELETE ib FROM inventory_batch ib JOIN inventory i ON i.inventory_id = ib.inventory_id JOIN business_product bp ON bp.business_product_id = i.business_product_id WHERE bp.product_id IN ({$pList})");
            $pdo->exec("DELETE i FROM inventory i JOIN business_product bp ON bp.business_product_id = i.business_product_id WHERE bp.product_id IN ({$pList})");
            $pdo->exec("DELETE FROM business_product WHERE product_id IN ({$pList})");
            $pdo->exec("DELETE FROM product WHERE product_id IN ({$pList})");
        }
        if ($cleanup['branches']) {
            $pdo->exec('DELETE FROM branch WHERE branch_id IN (' . implode(',', array_map('intval', $cleanup['branches'])) . ')');
        }
        if ($cleanup['customers']) {
            $pdo->exec('DELETE FROM customer WHERE customer_id IN (' . implode(',', array_map('intval', $cleanup['customers'])) . ')');
        }
        if ($cleanup['employees']) {
            $pdo->exec('DELETE FROM employee WHERE employee_id IN (' . implode(',', array_map('intval', $cleanup['employees'])) . ')');
        }
        if ($cleanup['users']) {
            $uList = implode(',', array_map('intval', $cleanup['users']));
            $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$uList})");
            $pdo->exec("DELETE FROM account_role WHERE user_account_id IN ({$uList})");
            $pdo->exec("DELETE FROM user_account WHERE user_account_id IN ({$uList})");
        }
    } catch (Throwable $cleanupEx) {
        fwrite(STDERR, "Cleanup warning: {$cleanupEx->getMessage()}\n");
    }

    safelyTerminateAndClose($serverProc, $serverPipes, 2);
}

exit($failed > 0 ? 1 : 0);
