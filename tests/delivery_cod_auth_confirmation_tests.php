<?php
declare(strict_types=1);

/**
 * PawCariño — Courier Authorization & Required COD Confirmation Regression Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  1. Issue B (Authorization across AuthController::switchInterface, DeliveryController, OrderPaymentService):
 *     - Active Pet Store (unit 1) employee with active account and DELIVERY_PERSONNEL role succeeds end-to-end.
 *     - Cross-unit employees (even with DELIVERY_PERSONNEL role), inactive employees, inactive accounts,
 *       inactive units, and unauthorized roles (e.g., RECEPTIONIST) are blocked (403 / ERR_FORBIDDEN).
 *     - Super Administrator retains read-only oversight on /delivery/orders and is blocked from operational
 *       courier mutations and service-level COD collection/remittance even across business units.
 *  2. Issue A (Mandatory COD collection confirmation + recovery preservation):
 *     - UI requires confirm_cod_collected on pending COD out_for_delivery orders, and omits it on online-paid orders.
 *     - Server-side DeliveryController::complete() rejects pending COD completion when confirm_cod_collected is missing
 *       with zero state/inventory/payment mutation, succeeds when confirmed, and completes online-paid orders without it.
 *     - Preserves standalone /delivery/orders/{id}/collect-cod recovery for pre-existing completed orders with pending COD.
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

final class DeliveryTestHttpClient
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

        $headers = ['Accept: text/html', 'Connection: close'];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("HTTP request failed for {$method} {$path}: {$err}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerText = substr($raw, 0, $headerSize);
        $respBody = substr($raw, $headerSize);

        preg_match_all('/^Set-Cookie:\s*([^=;]+)=([^;\r\n]+)/mi', $headerText, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $this->cookies[trim($m[1])] = trim($m[2]);
        }

        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $headerText, $locMatch)) {
            $location = trim($locMatch[1]);
        }

        return ['status' => $status, 'location' => $location, 'body' => $respBody];
    }
}

$serverPort = 18089;
$baseUrl = "http://127.0.0.1:{$serverPort}";
$phpExe = dirname(__DIR__) . '/tools/php83/php.exe';
if (!is_file($phpExe)) {
    $phpExe = PHP_BINARY;
}

$serverProc = null;
$serverPipes = [];

$cleanup = [
    'remittances' => [],
    'orders' => [],
    'products' => [],
    'branches' => [],
    'customers' => [],
    'employees' => [],
    'users' => [],
    'business_units' => [],
];

try {
    $unitId = 1;
    $otherUnitId = (int)$pdo->query("SELECT business_unit_id FROM business_unit WHERE business_unit_id <> 1 AND business_unit_status = 'active' ORDER BY business_unit_id ASC LIMIT 1")->fetchColumn();
    if ($otherUnitId <= 0) {
        $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkDelivAuth Other Unit', 'active')");
        $otherUnitId = (int)$pdo->lastInsertId();
        $cleanup['business_units'][] = $otherUnitId;
    }

    $branchId = (int)$pdo->query("SELECT branch_id FROM branch WHERE business_unit_id = {$unitId} ORDER BY branch_id ASC LIMIT 1")->fetchColumn();
    if ($branchId <= 0) {
        $pdo->prepare("INSERT INTO branch (business_unit_id, branch_name, branch_status) VALUES (?, ?, 'active')")
            ->execute([$unitId, 'ChkDelivAuth Branch ' . bin2hex(random_bytes(3))]);
        $branchId = (int)$pdo->lastInsertId();
        $cleanup['branches'][] = $branchId;
    }

    $pdo->exec("INSERT IGNORE INTO role (role_name, description) VALUES ('DELIVERY_PERSONNEL', 'Delivery Personnel'), ('RECEPTIONIST', 'Receptionist')");
    $roleIds = [];
    foreach ($pdo->query("SELECT role_id, role_name FROM role WHERE role_name IN ('DELIVERY_PERSONNEL', 'RECEPTIONIST')")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $roleIds[(string)$r['role_name']] = (int)$r['role_id'];
    }

    $passwordPlain = 'CourierPass!2026';
    $passwordHash = password_hash($passwordPlain, PASSWORD_DEFAULT);

    $createAccount = static function (
        string $email,
        ?int $empBuId,
        string $empStatus,
        string $accStatus,
        bool $isSuperAdmin,
        ?int $roleBuId,
        ?string $roleName
    ) use ($pdo, $passwordHash, $roleIds, &$cleanup): array {
        $pdo->prepare(
            "INSERT INTO user_account (email, password_hash, account_status, is_super_admin, email_verified_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
        )->execute([$email, $passwordHash, $accStatus, $isSuperAdmin ? 1 : 0]);
        $uid = (int)$pdo->lastInsertId();
        $cleanup['users'][] = $uid;

        $empId = 0;
        if ($empBuId !== null) {
            $pdo->prepare(
                "INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status)
                 VALUES (?, ?, 'Test', 'User', 'Delivery Personnel', ?)"
            )->execute([$uid, $empBuId, $empStatus]);
            $empId = (int)$pdo->lastInsertId();
            $cleanup['employees'][] = $empId;
        }

        if ($roleBuId !== null && $roleName !== null) {
            $pdo->prepare(
                "INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)"
            )->execute([$uid, $roleIds[$roleName], $roleBuId]);
        }

        return [$uid, $empId];
    };

    $suffix = bin2hex(random_bytes(4));
    [$courierUid, $courierEmpId] = $createAccount("courier_ok_{$suffix}@test.invalid", 1, 'active', 'active', false, 1, 'DELIVERY_PERSONNEL');
    [$crossUnitWithRoleUid, $crossUnitWithRoleEmpId] = $createAccount("courier_cross_role_{$suffix}@test.invalid", $otherUnitId, 'active', 'active', false, 1, 'DELIVERY_PERSONNEL');
    [$crossUnitForeignRoleUid, $crossUnitForeignRoleEmpId] = $createAccount("courier_cross_for_{$suffix}@test.invalid", $otherUnitId, 'active', 'active', false, $otherUnitId, 'DELIVERY_PERSONNEL');
    [$inactiveEmpUid, $inactiveEmpId] = $createAccount("courier_inact_emp_{$suffix}@test.invalid", 1, 'inactive', 'active', false, 1, 'DELIVERY_PERSONNEL');
    [$inactiveAccUid, $inactiveAccEmpId] = $createAccount("courier_inact_acc_{$suffix}@test.invalid", 1, 'active', 'inactive', false, 1, 'DELIVERY_PERSONNEL');
    [$wrongRoleUid, $wrongRoleEmpId] = $createAccount("courier_recep_{$suffix}@test.invalid", 1, 'active', 'active', false, 1, 'RECEPTIONIST');
    [$superNoRoleUid, $superNoRoleEmpId] = $createAccount("super_norole_{$suffix}@test.invalid", 1, 'active', 'active', true, null, null);
    [$superCrossUnitRoleUid, $superCrossUnitRoleEmpId] = $createAccount("super_cross_{$suffix}@test.invalid", $otherUnitId, 'active', 'active', true, 1, 'DELIVERY_PERSONNEL');

    // Customer + product fixture
    $pdo->prepare("INSERT INTO user_account (email, password_hash, account_status) VALUES (?, ?, 'active')")
        ->execute(["cust_deliv_auth_{$suffix}@test.invalid", $passwordHash]);
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->prepare("INSERT INTO customer (user_account_id, first_name, last_name, phone) VALUES (?, 'Deliv', 'Buyer', '09171234567')")
        ->execute([$custUid]);
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    $pdo->prepare("INSERT INTO product (product_name, product_category) VALUES (?, 'Food')")
        ->execute(["ChkDelivAuth Kibble {$suffix}"]);
    $prodId = (int)$pdo->lastInsertId();
    $cleanup['products'][] = $prodId;
    $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status) VALUES (?, ?, 120.00, 0, 'active')")
        ->execute([$unitId, $prodId]);
    $bpId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, 50, 0, 0)")
        ->execute([$bpId]);
    $invId = (int)$pdo->lastInsertId();

    $reservationService = new OrderReservationService($pdo);
    $paymentService = new OrderPaymentService($pdo);

    $createPreparedOrder = static function (string $payMethod, string $payStatus, int $qty = 2, float $fee = 50.00) use ($pdo, $custId, $unitId, $branchId, $bpId, $courierUid, $reservationService, &$cleanup): array {
        $subtotal = 120.00 * $qty;
        $total = $subtotal + $fee;
        $pdo->prepare(
            "INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, subtotal_amount, delivery_fee, total_amount, delivery_address_line1, delivery_city, delivery_postal_code)
             VALUES (?, ?, ?, 'delivery', 'pending', ?, ?, ?, '123 Pet St', 'Makati', '1200')"
        )->execute([$custId, $unitId, $branchId, $subtotal, $fee, $total]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $orderId;

        $pdo->prepare("INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, 120.00)")
            ->execute([$orderId, $bpId, $qty]);

        if ($payStatus === 'completed') {
            $pdo->prepare(
                "INSERT INTO payment (order_id, payment_type, amount, payment_date, payment_method, payment_status, provider_name, provider_payment_id)
                 VALUES (?, 'payment', ?, NOW(), ?, 'completed', 'test_gw', ?)"
            )->execute([$orderId, $total, $payMethod, 'gw_' . $orderId]);
        } else {
            $pdo->prepare(
                "INSERT INTO payment (order_id, payment_type, amount, payment_date, payment_method, payment_status)
                 VALUES (?, 'payment', ?, NOW(), ?, ?)"
            )->execute([$orderId, $total, $payMethod, $payStatus]);
        }
        $paymentId = (int)$pdo->lastInsertId();

        $reservationService->confirmDeliveryOrderAndReserve($orderId, $unitId, $courierUid);
        $reservationService->markDeliveryOrderProcessing($orderId, $unitId, $courierUid);
        return [$orderId, $paymentId, $subtotal, $total];
    };

    [$ordCod, $payCod, $subCod, $totCod] = $createPreparedOrder('cod', 'pending');
    [$ordOnline, $payOnline, $subOnline, $totOnline] = $createPreparedOrder('online', 'completed');
    [$ordLegacyCompleted, $payLegacyCompleted] = $createPreparedOrder('cod', 'pending');
    [$ordSvcTest, $paySvcTest] = $createPreparedOrder('cod', 'pending');
    [$ordFailTarget] = $createPreparedOrder('cod', 'pending');

    // Start temporary built-in HTTP server for controller/UI/switchInterface tests
    $docRoot = dirname(__DIR__) . '/public';
    $cmd = [
        $phpExe,
        '-S',
        "127.0.0.1:{$serverPort}",
        '-t',
        $docRoot,
    ];
    $nullSink = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $serverProc = proc_open($cmd, [0 => ['file', $nullSink, 'r'], 1 => ['file', $nullSink, 'w'], 2 => ['file', $nullSink, 'w']], $serverPipes, dirname(__DIR__));
    if (!is_resource($serverProc)) {
        throw new RuntimeException('Failed to start temporary PHP built-in server.');
    }

    $ready = false;
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen('127.0.0.1', $serverPort, $errno, $errstr, 0.2);
        if (is_resource($fp)) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(50000);
    }
    if (!$ready) {
        throw new RuntimeException('Temporary PHP built-in server did not become ready in time.');
    }

    $http = new DeliveryTestHttpClient($baseUrl);

    // Helper: get CSRF from an already-authenticated session page (e.g. /staff/workspace or /delivery/orders)
    $fetchCsrfFrom = static function (DeliveryTestHttpClient $client, string $path): string {
        $resp = $client->get($path);
        return $client->extractCsrf($resp['body']);
    };

    // -----------------------------------------------------------------
    // 1. Issue B HTTP tests: Valid courier vs Unauthorized / Cross-unit / Inactive / SuperAdmin
    // -----------------------------------------------------------------
    $loginOk = $http->staffLogin("courier_ok_{$suffix}@test.invalid", $passwordPlain);
    $switchOk = $http->get('/switch-interface?target=delivery');
    $portalOk = $http->get('/delivery/orders?tab=queue');
    $csrfCourier = $http->extractCsrf($portalOk['body']);

    check(
        'Active unit-1 DELIVERY_PERSONNEL courier can switch to delivery interface and load portal without oversight banner',
        $loginOk['status'] === 302
            && $switchOk['status'] === 302
            && $switchOk['location'] === '/delivery/orders'
            && $portalOk['status'] === 200
            && !str_contains($portalOk['body'], 'Administrative Oversight Mode')
    );

    // Dispatch COD, Online, and Fail-target orders as valid courier
    $dispCodResp = $http->post("/delivery/orders/{$ordCod}/dispatch", ['csrf_token' => $csrfCourier]);
    $dispOnlineResp = $http->post("/delivery/orders/{$ordOnline}/dispatch", ['csrf_token' => $csrfCourier]);
    $dispFailTarget = $http->post("/delivery/orders/{$ordFailTarget}/dispatch", ['csrf_token' => $csrfCourier]);
    check(
        'Valid unit-1 courier can dispatch prepared delivery orders',
        $dispCodResp['status'] === 302
            && $dispOnlineResp['status'] === 302
            && $dispFailTarget['status'] === 302
            && (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordCod}")->fetchColumn() === 'out_for_delivery'
    );

    // Negative HTTP authorization checks
    $unauthorizedCases = [
        'Cross-unit employee with unit-1 DELIVERY_PERSONNEL role' => ["courier_cross_role_{$suffix}@test.invalid", true],
        'Cross-unit employee with foreign DELIVERY_PERSONNEL role' => ["courier_cross_for_{$suffix}@test.invalid", true],
        'Inactive employee with unit-1 DELIVERY_PERSONNEL role' => ["courier_inact_emp_{$suffix}@test.invalid", false],
        'Inactive user_account with active unit-1 DELIVERY_PERSONNEL' => ["courier_inact_acc_{$suffix}@test.invalid", false],
        'Active unit-1 employee with RECEPTIONIST role only' => ["courier_recep_{$suffix}@test.invalid", true],
    ];

    foreach ($unauthorizedCases as $label => [$email, $canLogin]) {
        $c = new DeliveryTestHttpClient($baseUrl);
        $c->staffLogin($email, $passwordPlain);
        $home = $c->get('/');
        $csrf = preg_match('/name="csrf_token"\s+value="([^"]+)"/', $home['body'], $cm)
            ? $cm[1]
            : $c->extractCsrf($c->get('/staff/login')['body']);
        $sw = $c->get('/switch-interface?target=delivery');
        $view = $c->get('/delivery/orders');
        $pDisp = $c->post("/delivery/orders/{$ordLegacyCompleted}/dispatch", ['csrf_token' => $csrf]);
        $pComp = $c->post("/delivery/orders/{$ordCod}/complete", ['csrf_token' => $csrf, 'confirm_cod_collected' => '1']);
        $pCol = $c->post("/delivery/orders/{$ordCod}/collect-cod", ['csrf_token' => $csrf]);
        $pFail = $c->post("/delivery/orders/{$ordFailTarget}/fail", ['csrf_token' => $csrf, 'delivery_failure_reason' => 'Blocked']);
        $pRmt = $c->post('/delivery/remittances', ['csrf_token' => $csrf, 'payment_ids' => [(string)$payCod]]);

        $blockedCorrectly = $canLogin
            ? ($sw['status'] === 403
                && $view['status'] === 403
                && $pDisp['status'] === 403
                && $pComp['status'] === 403
                && $pCol['status'] === 403
                && $pFail['status'] === 403
                && $pRmt['status'] === 403)
            : ($sw['status'] === 302 && str_contains((string)$sw['location'], '/login')
                && $view['status'] === 302 && str_contains((string)$view['location'], '/login')
                && $pDisp['status'] === 302 && str_contains((string)$pDisp['location'], '/login')
                && $pComp['status'] === 302 && str_contains((string)$pComp['location'], '/login')
                && $pCol['status'] === 302 && str_contains((string)$pCol['location'], '/login')
                && $pFail['status'] === 302 && str_contains((string)$pFail['location'], '/login')
                && $pRmt['status'] === 302 && str_contains((string)$pRmt['location'], '/login'));

        check(
            "HTTP blocks {$label} from switchInterface, delivery portal, and all courier POST mutations",
            $blockedCorrectly
        );
    }

    // Already-logged-in session whose employee or user_account is subsequently deactivated must get 403 directly
    foreach ([
        'deactivated employee mid-session' => static function () use ($pdo, $courierEmpId): void {
            $pdo->prepare("UPDATE employee SET employee_status = 'inactive' WHERE employee_id = ?")->execute([$courierEmpId]);
        },
        'deactivated user_account mid-session' => static function () use ($pdo, $courierUid): void {
            $pdo->prepare("UPDATE user_account SET account_status = 'inactive' WHERE user_account_id = ?")->execute([$courierUid]);
        },
    ] as $label => $deactivate) {
        $mid = new DeliveryTestHttpClient($baseUrl);
        $mid->staffLogin("courier_ok_{$suffix}@test.invalid", $passwordPlain);
        $midCsrf = $mid->extractCsrf($mid->get('/delivery/orders')['body']);
        $deactivate();
        $sw = $mid->get('/switch-interface?target=delivery');
        $view = $mid->get('/delivery/orders');
        $pComp = $mid->post("/delivery/orders/{$ordCod}/complete", ['csrf_token' => $midCsrf, 'confirm_cod_collected' => '1']);
        $pdo->prepare("UPDATE employee SET employee_status = 'active' WHERE employee_id = ?")->execute([$courierEmpId]);
        $pdo->prepare("UPDATE user_account SET account_status = 'active' WHERE user_account_id = ?")->execute([$courierUid]);
        check(
            "HTTP returns 403 for {$label}",
            $sw['status'] === 403 && $view['status'] === 403 && $pComp['status'] === 403
        );
    }

    // Super Administrator oversight checks (both no-role and cross-unit employee with DELIVERY_PERSONNEL role)
    foreach ([
        'Super Admin without DELIVERY_PERSONNEL role' => "super_norole_{$suffix}@test.invalid",
        'Super Admin with cross-unit employee and unit-1 DELIVERY_PERSONNEL role' => "super_cross_{$suffix}@test.invalid",
    ] as $label => $email) {
        $sa = new DeliveryTestHttpClient($baseUrl);
        $sa->staffLogin($email, $passwordPlain);
        $sw = $sa->get('/switch-interface?target=delivery');
        $view = $sa->get('/delivery/orders?tab=active');
        $csrf = $sa->extractCsrf($view['body']);

        $pDisp = $sa->post("/delivery/orders/{$ordLegacyCompleted}/dispatch", ['csrf_token' => $csrf]);
        $pComp = $sa->post("/delivery/orders/{$ordCod}/complete", ['csrf_token' => $csrf, 'confirm_cod_collected' => '1']);
        $pCol = $sa->post("/delivery/orders/{$ordCod}/collect-cod", ['csrf_token' => $csrf]);
        $pFail = $sa->post("/delivery/orders/{$ordFailTarget}/fail", ['csrf_token' => $csrf, 'delivery_failure_reason' => 'Blocked']);
        $pRmt = $sa->post('/delivery/remittances', ['csrf_token' => $csrf, 'payment_ids' => [(string)$payCod]]);

        check(
            "{$label} keeps read-only oversight (200) and is blocked from operational courier mutations (403)",
            $sw['status'] === 302
                && $sw['location'] === '/delivery/orders'
                && $view['status'] === 200
                && str_contains($view['body'], 'Administrative Oversight Mode (Read-Only)')
                && !str_contains($view['body'], 'Claim &amp; Dispatch Delivery')
                && !str_contains($view['body'], 'Mark Delivered (Completed)')
                && $pDisp['status'] === 403
                && $pComp['status'] === 403
                && $pCol['status'] === 403
                && $pFail['status'] === 403
                && $pRmt['status'] === 403
        );
    }

    // -----------------------------------------------------------------
    // 2. Issue A UI & HTTP completion tests: Mandatory COD confirmation + Online bypass + Recovery
    // -----------------------------------------------------------------
    $activeCodPage = $http->get("/delivery/orders?tab=active&view={$ordCod}");
    $activeOnlinePage = $http->get("/delivery/orders?tab=active&view={$ordOnline}");

    $extractModalHtml = static function (string $html, int $orderId): string {
        if (preg_match('#<dialog[^>]*id="delivery-modal-' . $orderId . '"[^>]*>(.*?)</dialog>#s', $html, $m)) {
            return $m[1];
        }
        return '';
    };
    $codModalHtml = $extractModalHtml($activeCodPage['body'], $ordCod);
    $onlineModalHtml = $extractModalHtml($activeOnlinePage['body'], $ordOnline);

    check(
        'Delivery modal renders required confirm_cod_collected checkbox for pending COD and omits it for online-paid orders',
        $codModalHtml !== ''
            && $onlineModalHtml !== ''
            && str_contains($codModalHtml, 'name="confirm_cod_collected" value="1" required')
            && !str_contains($onlineModalHtml, 'name="confirm_cod_collected"')
    );

    // Unchecked/missing confirm_cod_collected on pending COD order must be rejected
    $invBeforeFailComp = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invId}")->fetch(PDO::FETCH_ASSOC);
    $compUnchecked = $http->post("/delivery/orders/{$ordCod}/complete", ['csrf_token' => $csrfCourier]);
    $ordCodAfterUnchecked = $pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordCod}")->fetchColumn();
    $payCodAfterUnchecked = $pdo->query("SELECT payment_status, collection_channel, collected_at FROM payment WHERE payment_id = {$payCod}")->fetch(PDO::FETCH_ASSOC);
    $invAfterFailComp = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invId}")->fetch(PDO::FETCH_ASSOC);
    $followUnchecked = $http->get((string)$compUnchecked['location']);

    check(
        'Server rejects pending COD delivery completion when confirm_cod_collected is missing without mutating order, inventory, or payment',
        $compUnchecked['status'] === 302
            && str_contains((string)$compUnchecked['location'], "tab=active&view={$ordCod}")
            && str_contains($followUnchecked['body'], 'COD cash collection confirmation is required')
            && $ordCodAfterUnchecked === 'out_for_delivery'
            && $payCodAfterUnchecked['payment_status'] === 'pending'
            && $payCodAfterUnchecked['collection_channel'] === null
            && $payCodAfterUnchecked['collected_at'] === null
            && $invAfterFailComp === $invBeforeFailComp
    );

    // Checked confirm_cod_collected completes order and records courier_cod payment
    $compChecked = $http->post("/delivery/orders/{$ordCod}/complete", ['csrf_token' => $csrfCourier, 'confirm_cod_collected' => '1']);
    $ordCodAfterChecked = $pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordCod}")->fetchColumn();
    $payCodAfterChecked = $pdo->query("SELECT payment_status, collection_channel, collected_by_employee_id, collected_at FROM payment WHERE payment_id = {$payCod}")->fetch(PDO::FETCH_ASSOC);
    check(
        'COD delivery completes and confirms courier_cod payment when confirm_cod_collected=1 is submitted',
        $compChecked['status'] === 302
            && str_contains((string)$compChecked['location'], "tab=history&view={$ordCod}")
            && $ordCodAfterChecked === 'completed'
            && $payCodAfterChecked['payment_status'] === 'completed'
            && $payCodAfterChecked['collection_channel'] === 'courier_cod'
            && (int)$payCodAfterChecked['collected_by_employee_id'] === $courierEmpId
            && $payCodAfterChecked['collected_at'] !== null
    );

    // Online-paid order completes WITHOUT confirm_cod_collected
    $compOnline = $http->post("/delivery/orders/{$ordOnline}/complete", ['csrf_token' => $csrfCourier]);
    $ordOnlineAfter = $pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordOnline}")->fetchColumn();
    $payOnlineAfter = $pdo->query("SELECT payment_status, collection_channel FROM payment WHERE payment_id = {$payOnline}")->fetch(PDO::FETCH_ASSOC);
    check(
        'Prepaid online delivery completes cleanly without confirm_cod_collected',
        $compOnline['status'] === 302
            && str_contains((string)$compOnline['location'], "tab=history&view={$ordOnline}")
            && $ordOnlineAfter === 'completed'
            && $payOnlineAfter['payment_status'] === 'completed'
            && $payOnlineAfter['collection_channel'] === null
    );

    // Remittance submission via HTTP still works cleanly for subtotal excluding delivery fee
    $remitResp = $http->post('/delivery/remittances', [
        'csrf_token' => $csrfCourier,
        'payment_ids' => [(string)$payCod],
        'notes' => 'Checkpoint remittance',
    ]);
    $rmtRow = $pdo->query(
        "SELECT r.remittance_id, r.remittance_status, r.expected_total, r.remitted_total
         FROM courier_cod_remittance r
         JOIN courier_cod_remittance_allocation a ON a.remittance_id = r.remittance_id
         WHERE a.payment_id = {$payCod} AND a.allocation_status = 'active'
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($rmtRow) {
        $cleanup['remittances'][] = (int)$rmtRow['remittance_id'];
    }
    check(
        'Valid courier remittance submission succeeds for exact subtotal excluding delivery fee',
        $remitResp['status'] === 302
            && $rmtRow !== false
            && $rmtRow['remittance_status'] === 'submitted'
            && (float)$rmtRow['expected_total'] === $subCod
            && (float)$rmtRow['remitted_total'] === $subCod
    );

    // Preserve standalone collect-cod recovery for pre-existing completed order with pending COD
    $reservationService->dispatchDeliveryOrder($ordLegacyCompleted, $unitId, $courierEmpId, $courierUid);
    $reservationService->completeDeliveryOrder($ordLegacyCompleted, $unitId, $courierEmpId, $courierUid);
    $historyRecoveryPage = $http->get("/delivery/orders?tab=history&view={$ordLegacyCompleted}");
    $recoverResp = $http->post("/delivery/orders/{$ordLegacyCompleted}/collect-cod", ['csrf_token' => $csrfCourier]);
    $payLegacyAfter = $pdo->query("SELECT payment_status, collection_channel, collected_by_employee_id FROM payment WHERE payment_id = {$payLegacyCompleted}")->fetch(PDO::FETCH_ASSOC);
    check(
        'Standalone /collect-cod recovery path remains intact for completed delivery orders with pending COD',
        str_contains($historyRecoveryPage['body'], "/delivery/orders/{$ordLegacyCompleted}/collect-cod")
            && $recoverResp['status'] === 302
            && $payLegacyAfter['payment_status'] === 'completed'
            && $payLegacyAfter['collection_channel'] === 'courier_cod'
            && (int)$payLegacyAfter['collected_by_employee_id'] === $courierEmpId
    );

    // -----------------------------------------------------------------
    // 3. Service-level authorization parity in OrderPaymentService (bypassing controller)
    // -----------------------------------------------------------------
    $reservationService->dispatchDeliveryOrder($ordSvcTest, $unitId, $courierEmpId, $courierUid);
    $reservationService->completeDeliveryOrder($ordSvcTest, $unitId, $courierEmpId, $courierUid);

    $serviceAuthChecks = [
        'cross-unit employee with DELIVERY_PERSONNEL role' => [$crossUnitWithRoleEmpId, $crossUnitWithRoleUid],
        'inactive employee' => [$inactiveEmpId, $inactiveEmpUid],
        'inactive user account' => [$inactiveAccEmpId, $inactiveAccUid],
        'RECEPTIONIST role without DELIVERY_PERSONNEL' => [$wrongRoleEmpId, $wrongRoleUid],
        'Super Admin without DELIVERY_PERSONNEL role' => [$superNoRoleEmpId, $superNoRoleUid],
        'Super Admin with cross-unit employee + DELIVERY_PERSONNEL role' => [$superCrossUnitRoleEmpId, $superCrossUnitRoleUid],
        'actor/employee ownership mismatch' => [$courierEmpId, $wrongRoleUid],
    ];

    foreach ($serviceAuthChecks as $desc => [$testEmpId, $testUid]) {
        // Temporarily test delivery_employee_id match only where relevant; even if assigned on order, service must still forbid!
        $pdo->prepare("UPDATE customer_order SET delivery_employee_id = ? WHERE order_id = ?")->execute([$testEmpId, $ordSvcTest]);
        $colBlocked = false;
        try {
            $paymentService->confirmDeliveryCodCollection($ordSvcTest, $unitId, $testEmpId, $testUid);
        } catch (RuntimeException $e) {
            $colBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
        }
        check("OrderPaymentService::confirmDeliveryCodCollection blocks {$desc} (ERR_FORBIDDEN)", $colBlocked);
    }
    $pdo->prepare("UPDATE customer_order SET delivery_employee_id = ? WHERE order_id = ?")->execute([$courierEmpId, $ordSvcTest]);

    // Also test remittance service authorization directly
    $paymentService->confirmDeliveryCodCollection($ordSvcTest, $unitId, $courierEmpId, $courierUid);
    $remitServiceBlocked = true;
    foreach ([
        'inactive account' => [$inactiveAccEmpId, $inactiveAccUid],
        'inactive employee' => [$inactiveEmpId, $inactiveEmpUid],
        'wrong role (RECEPTIONIST)' => [$wrongRoleEmpId, $wrongRoleUid],
        'Super Admin without DELIVERY_PERSONNEL' => [$superNoRoleEmpId, $superNoRoleUid],
        'Super Admin cross-unit' => [$superCrossUnitRoleEmpId, $superCrossUnitRoleUid],
    ] as $desc => [$testEmpId, $testUid]) {
        $pdo->prepare("UPDATE payment SET collected_by_employee_id = ? WHERE payment_id = ?")->execute([$testEmpId, $paySvcTest]);
        $blocked = false;
        try {
            $paymentService->submitCourierCodRemittance($unitId, $testEmpId, $testUid, [$paySvcTest]);
        } catch (RuntimeException $e) {
            $blocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
        }
        if (!$blocked) {
            $remitServiceBlocked = false;
            echo "  DETAIL: submitCourierCodRemittance did not forbid {$desc}\n";
        }
    }
    $pdo->prepare("UPDATE payment SET collected_by_employee_id = ? WHERE payment_id = ?")->execute([$courierEmpId, $paySvcTest]);
    check('OrderPaymentService::submitCourierCodRemittance enforces active account, employee, unit, and DELIVERY_PERSONNEL role', $remitServiceBlocked);
} catch (Throwable $e) {
    $failed++;
    echo "FAIL: Unhandled exception: " . get_class($e) . ": {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
} finally {
    if ($serverProc !== null) {
        safelyTerminateAndClose($serverProc, $serverPipes, 2.0);
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($cleanup['remittances']) {
        $rList = implode(',', $cleanup['remittances']);
        $pdo->exec("DELETE FROM courier_cod_remittance_allocation WHERE remittance_id IN ({$rList})");
        $pdo->exec("DELETE FROM courier_cod_remittance WHERE remittance_id IN ({$rList})");
    }
    if ($cleanup['orders']) {
        $oList = implode(',', $cleanup['orders']);
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
        $pList = implode(',', $cleanup['products']);
        $pdo->exec("DELETE ib FROM inventory_batch ib JOIN inventory i ON i.inventory_id = ib.inventory_id JOIN business_product bp ON bp.business_product_id = i.business_product_id WHERE bp.product_id IN ({$pList})");
        $pdo->exec("DELETE i FROM inventory i JOIN business_product bp ON bp.business_product_id = i.business_product_id WHERE bp.product_id IN ({$pList})");
        $pdo->exec("DELETE FROM business_product WHERE product_id IN ({$pList})");
        $pdo->exec("DELETE FROM product WHERE product_id IN ({$pList})");
    }
    if ($cleanup['branches']) {
        $pdo->exec('DELETE FROM branch WHERE branch_id IN (' . implode(',', $cleanup['branches']) . ')');
    }
    if ($cleanup['customers']) {
        $pdo->exec('DELETE FROM customer WHERE customer_id IN (' . implode(',', $cleanup['customers']) . ')');
    }
    if ($cleanup['employees']) {
        $pdo->exec('DELETE FROM employee WHERE employee_id IN (' . implode(',', $cleanup['employees']) . ')');
    }
    if ($cleanup['users']) {
        $uList = implode(',', $cleanup['users']);
        $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$uList})");
        $pdo->exec("DELETE FROM account_role WHERE user_account_id IN ({$uList})");
        $pdo->exec("DELETE FROM user_account WHERE user_account_id IN ({$uList})");
    }
    if ($cleanup['business_units']) {
        $pdo->exec('DELETE FROM business_unit WHERE business_unit_id IN (' . implode(',', $cleanup['business_units']) . ')');
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
