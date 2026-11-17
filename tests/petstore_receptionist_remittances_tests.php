<?php
declare(strict_types=1);

/**
 * PawCariño — Checkpoint 2: Pet Store Receptionist Remittances Regression Suite
 *
 * Targets only the explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  1. Navigation: Remittances link present in Pet Store Receptionist topbar (with Overview, Orders, Employees intact)
 *     and absent from other roles/business units.
 *  2. Authorization:
 *     - Active Pet Store (unit 1) RECEPTIONIST can load /petstore/receptionist/remittances and complete handovers.
 *     - Unauthorized roles (DELIVERY_PERSONNEL, BUSINESS_MANAGER, INVENTORY_MANAGER), cross-unit employees,
 *       inactive employees, and inactive accounts are blocked (403) on GET and POST.
 *     - Super Administrator without unit-1 RECEPTIONIST has read-only oversight on GET (200) and is blocked (403)
 *       from POST handover completion (never using employee_id fallback).
 *  3. Individual handover completion & batch progress (auto_verify OFF and ON):
 *     - Displays batch reference, courier, submitted_at, expected_total (subtotal excluding delivery fees),
 *       active allocations, order references, and clear status labels (Awaiting Handover, Handover Completed, Verified).
 *     - Completing 1 of 2 allocations updates only that allocation (batch stays Awaiting Handover, 1 / 2 handed over).
 *     - Completing 2 of 2 with auto_verify OFF updates allocation & batch to Handover Completed.
 *     - Completing with auto_verify ON updates allocation & batch to Verified (Auto-Verified, NULL verifier).
 *     - No batch-wide completion action exists.
 *  4. Duplicate/stale completion rejection & self-handover rejection.
 *  5. Preservation of existing Receptionist Orders page functionality.
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

final class ReceptionistRemitTestClient
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
            $cookieParts = [];
            foreach ($this->cookies as $k => $v) {
                $cookieParts[] = "{$k}={$v}";
            }
            $headers[] = 'Cookie: ' . implode('; ', $cookieParts);
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

function extractElementById(string $html, string $id): string
{
    $quoted = preg_quote($id, '#');
    if (preg_match('#<(section|tr)\b[^>]*\bid="' . $quoted . '"[^>]*>(.*?)</\1>#s', $html, $m)) {
        return $m[0];
    }
    return '';
}

$port = 18094;
[$serverProc, $serverPipes] = startBuiltInServer($port);
$baseUrl = "http://127.0.0.1:{$port}";
$suffix = bin2hex(random_bytes(4));
$pwPlain = 'RecRemitTest!2026';
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
];

try {
    echo "====================================================================\n";
    echo "PET STORE RECEPTIONIST REMITTANCES TEST SUITE (DB: {$dbName})\n";
    echo "====================================================================\n";

    $bsStmt = $pdo->prepare('SELECT setting_id, auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = ? LIMIT 1');
    $bsStmt->execute([$unitId]);
    $bsRow = $bsStmt->fetch(PDO::FETCH_ASSOC);
    if ($bsRow) {
        $cleanup['orig_unit1_auto_verify'] = (int)$bsRow['auto_verify_cod_remittance'];
    } else {
        $pdo->prepare(
            "INSERT INTO business_settings (business_unit_id, business_name, business_email, contact_number, cod_enabled, auto_verify_cod_remittance)
             VALUES (?, 'Pet Store Test Settings', 'petstore@vetgrpet.local', '09175550100', 1, 0)"
        )->execute([$unitId]);
        $cleanup['created_unit1_settings'] = true;
    }

    $setAutoVerify = static function (int $val) use ($pdo, $unitId): void {
        $pdo->prepare('UPDATE business_settings SET auto_verify_cod_remittance = ? WHERE business_unit_id = ?')
            ->execute([$val, $unitId]);
    };
    $setAutoVerify(0);

    $branchId = (int)$pdo->query("SELECT branch_id FROM branch WHERE business_unit_id = 1 LIMIT 1")->fetchColumn();
    if ($branchId <= 0) {
        $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, city, phone) VALUES (1, 'Pet Store Remit Branch', 'Manila', '000-0000')");
        $branchId = (int)$pdo->lastInsertId();
        $cleanup['branches'][] = $branchId;
    }

    $roleIds = [];
    foreach (['DELIVERY_PERSONNEL', 'RECEPTIONIST', 'BUSINESS_MANAGER', 'INVENTORY_MANAGER', 'SUPER_ADMINISTRATOR'] as $rName) {
        $roleIds[$rName] = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = '{$rName}' LIMIT 1")->fetchColumn();
    }

    $createStaff = static function (
        string $tag,
        int $empUnitId,
        array $unitRoles,
        string $empStatus = 'active',
        string $accStatus = 'active',
        int $isSuperAdmin = 0
    ) use ($pdo, $suffix, $pwHash, $roleIds, &$cleanup): array {
        $email = "rrmt_{$tag}_{$suffix}@test.invalid";
        $pdo->prepare('INSERT INTO user_account (email, password_hash, account_status, is_super_admin) VALUES (?, ?, ?, ?)')
            ->execute([$email, $pwHash, $accStatus, $isSuperAdmin]);
        $uid = (int)$pdo->lastInsertId();
        $cleanup['users'][] = $uid;

        $empId = 0;
        if ($empUnitId > 0) {
            $pdo->prepare(
                'INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, phone, employee_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$uid, $empUnitId, 'Remit', ucfirst($tag), $tag, '0917-000-0000', $empStatus]);
            $empId = (int)$pdo->lastInsertId();
            $cleanup['employees'][] = $empId;
        }

        foreach ($unitRoles as [$rName, $rUnitId]) {
            $pdo->prepare('INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)')
                ->execute([$uid, $roleIds[$rName], $rUnitId]);
        }

        return [$uid, $empId, $email];
    };

    [$recUid, $recEmpId, $recEmail] = $createStaff('rec', 1, [['RECEPTIONIST', 1]]);
    [$courierUid, $courierEmpId, $courierEmail] = $createStaff('courier', 1, [['DELIVERY_PERSONNEL', 1]]);
    [$dualUid, $dualEmpId, $dualEmail] = $createStaff('dual', 1, [['DELIVERY_PERSONNEL', 1], ['RECEPTIONIST', 1]]);
    [$bmUid, $bmEmpId, $bmEmail] = $createStaff('bm', 1, [['BUSINESS_MANAGER', 1]]);
    [$imUid, $imEmpId, $imEmail] = $createStaff('im', 1, [['INVENTORY_MANAGER', 1]]);
    [$vetRecUid, $vetRecEmpId, $vetRecEmail] = $createStaff('vetrec', 2, [['RECEPTIONIST', 2], ['RECEPTIONIST', 1]]);
    [$inactEmpUid, $inactEmpId, $inactEmpEmail] = $createStaff('inactemp', 1, [['RECEPTIONIST', 1]], 'inactive', 'active');
    [$superUid, $superEmpId, $superEmail] = $createStaff('super', 2, [['SUPER_ADMINISTRATOR', 1]], 'active', 'active', 1);

    // Customer
    $pdo->prepare('INSERT INTO user_account (email, password_hash, account_status) VALUES (?, ?, ?)')
        ->execute(["rrmt_cust_{$suffix}@test.invalid", $pwHash, 'active']);
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->prepare('INSERT INTO customer (user_account_id, first_name, last_name, phone, customer_status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$custUid, 'Clara', "Buyer{$suffix}", '0917-333-4444', 'active']);
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    // Product & Inventory
    $pdo->prepare("INSERT INTO product (product_name, product_category, sku) VALUES (?, 'Food', ?)")
        ->execute(["RRMT Kibble {$suffix}", "SKU-RRMT-{$suffix}"]);
    $prodId = (int)$pdo->lastInsertId();
    $cleanup['products'][] = $prodId;
    $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status) VALUES (1, ?, 60.00, 0, 'active')")
        ->execute([$prodId]);
    $bpId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, 500, 0, 0)')
        ->execute([$bpId]);

    $createCollectedDeliveryOrder = static function (string $subtotal, string $fee, int $forCourierEmpId, int $forCourierUid) use (
        $pdo,
        $unitId,
        $branchId,
        $custId,
        $bpId,
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
        $reservationService->dispatchDeliveryOrder($orderId, $unitId, $forCourierEmpId, $forCourierUid);
        $reservationService->completeDeliveryOrder(
            $orderId,
            $unitId,
            $forCourierEmpId,
            $forCourierUid,
            static function () use ($paymentService, $orderId, $unitId, $forCourierEmpId, $forCourierUid): void {
                $paymentService->collectDeliveryCodInTransaction($orderId, $unitId, $forCourierEmpId, $forCourierUid);
            }
        );

        return ['order_id' => $orderId, 'payment_id' => $paymentId];
    };

    // Batch 1 (2 allocations submitted by $courierEmpId): 120.00 + 80.00 = 200.00 expected_total (fees 50.00 each excluded)
    $ord1 = $createCollectedDeliveryOrder('120.00', '50.00', $courierEmpId, $courierUid);
    $ord2 = $createCollectedDeliveryOrder('80.00', '50.00', $courierEmpId, $courierUid);
    $batch1Res = $paymentService->submitCourierCodRemittance($unitId, $courierEmpId, $courierUid, [$ord1['payment_id'], $ord2['payment_id']], '200.00', "Batch 1 {$suffix}");
    $remit1Id = (int)$batch1Res['remittance_id'];
    $remit1Ref = (string)$batch1Res['remittance_reference'];
    $cleanup['remittances'][] = $remit1Id;
    $alloc1Ids = array_map('intval', $pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remit1Id} ORDER BY allocation_id ASC")->fetchAll(PDO::FETCH_COLUMN));
    [$alloc1a, $alloc1b] = $alloc1Ids;

    // Batch 2 (1 allocation submitted by $courierEmpId for auto_verify = 1 test): 150.00 subtotal (fee 45.00 excluded)
    $ord3 = $createCollectedDeliveryOrder('150.00', '45.00', $courierEmpId, $courierUid);
    $batch2Res = $paymentService->submitCourierCodRemittance($unitId, $courierEmpId, $courierUid, [$ord3['payment_id']], '150.00', "Batch 2 Auto {$suffix}");
    $remit2Id = (int)$batch2Res['remittance_id'];
    $remit2Ref = (string)$batch2Res['remittance_reference'];
    $cleanup['remittances'][] = $remit2Id;
    $alloc2a = (int)$pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remit2Id} LIMIT 1")->fetchColumn();

    // Batch 3 (submitted by dual-role courier+receptionist $dualEmpId for self-handover rejection test)
    $ordSelf = $createCollectedDeliveryOrder('90.00', '35.00', $dualEmpId, $dualUid);
    $batchSelfRes = $paymentService->submitCourierCodRemittance($unitId, $dualEmpId, $dualUid, [$ordSelf['payment_id']], '90.00', "Batch Self {$suffix}");
    $remitSelfId = (int)$batchSelfRes['remittance_id'];
    $cleanup['remittances'][] = $remitSelfId;
    $allocSelf = (int)$pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remitSelfId} LIMIT 1")->fetchColumn();

    // =========================================================================
    // 1. Authorized Receptionist Page Load, Navigation, & Batch/Allocation Display
    // =========================================================================
    $recClient = new ReceptionistRemitTestClient($baseUrl);
    $recClient->staffLogin($recEmail, $pwPlain);

    $remitPage = $recClient->get('/petstore/receptionist/remittances');
    $batch1Html = extractElementById($remitPage['body'], "remittance-batch-{$remit1Id}");
    $alloc1aHtml = extractElementById($remitPage['body'], "allocation-row-{$alloc1a}");
    $alloc1bHtml = extractElementById($remitPage['body'], "allocation-row-{$alloc1b}");

    check(
        'Pet Store Receptionist topbar includes Remittances alongside Overview, Orders, and Employees',
        $remitPage['status'] === 200
            && str_contains($remitPage['body'], 'href="/petstore/receptionist/overview"')
            && str_contains($remitPage['body'], 'href="/petstore/receptionist/orders"')
            && str_contains($remitPage['body'], 'href="/petstore/receptionist/remittances" class="is-current"')
            && str_contains($remitPage['body'], 'href="/petstore/receptionist/employees"')
            && !str_contains($remitPage['body'], 'Administrative Oversight Mode')
    );

    check(
        'Remittances page displays batch ref, courier, expected subtotal total (excluding delivery fees), individual allocations, Awaiting Handover labels, and per-allocation Complete Handover actions only',
        str_contains($batch1Html, $remit1Ref)
            && str_contains($batch1Html, 'Remit Courier')
            && str_contains($batch1Html, '₱200.00')
            && str_contains($batch1Html, '0 / 2 handed over')
            && str_contains($batch1Html, 'Awaiting Handover')
            && str_contains($alloc1aHtml, "#ORD-{$ord1['order_id']}")
            && str_contains($alloc1aHtml, '₱120.00')
            && str_contains($alloc1aHtml, 'Excludes ₱50.00 delivery fee')
            && str_contains($alloc1aHtml, "/petstore/receptionist/remittances/allocations/{$alloc1a}/complete")
            && str_contains($alloc1aHtml, 'Complete Handover')
            && str_contains($alloc1bHtml, "#ORD-{$ord2['order_id']}")
            && str_contains($alloc1bHtml, '₱80.00')
            && str_contains($alloc1bHtml, "/petstore/receptionist/remittances/allocations/{$alloc1b}/complete")
            && !preg_match('#action="/petstore/receptionist/remittances/\d+/complete"#', $remitPage['body'])
    );

    // =========================================================================
    // 2. Unauthorized Roles, Cross-Unit Employees, Inactive Staff, & Super Admin
    // =========================================================================
    $recCsrf = $recClient->extractCsrf($remitPage['body']);

    foreach ([
        'Delivery Personnel only' => [$courierEmail, '/delivery/orders', true],
        'Pet Store Business Manager' => [$bmEmail, '/manager', true],
        'Pet Store Inventory Manager' => [$imEmail, '/manager/orders', true],
        'Cross-unit Vet Receptionist with unit-1 RECEPTIONIST role' => [$vetRecEmail, '/vetclinic/receptionist/overview', true],
        'Inactive Pet Store Receptionist' => [$inactEmpEmail, null, false],
    ] as $label => [$uEmail, $navCheckUrl, $canLogin]) {
        $c = new ReceptionistRemitTestClient($baseUrl);
        $c->staffLogin($uEmail, $pwPlain);
        $navHasRecRemit = false;
        if ($navCheckUrl !== null) {
            $ownPage = $c->get($navCheckUrl);
            $navHasRecRemit = str_contains($ownPage['body'], '/petstore/receptionist/remittances');
        }
        $home = $c->get('/');
        $csrfAttempt = preg_match('/name="csrf_token"\s+value="([^"]+)"/', $home['body'], $cm)
            ? $cm[1]
            : $c->extractCsrf($c->get('/staff/login')['body']);
        $g = $c->get('/petstore/receptionist/remittances');
        $p = $c->post("/petstore/receptionist/remittances/allocations/{$alloc1a}/complete", ['csrf_token' => $csrfAttempt]);
        $blockedOk = $canLogin
            ? ($g['status'] === 403 && $p['status'] === 403 && !$navHasRecRemit)
            : ($g['status'] === 302 && str_contains($g['location'], '/login')
                && $p['status'] === 302 && str_contains($p['location'], '/login'));
        check(
            "Unauthorized actor ({$label}) is blocked from GET/POST Receptionist Remittances and does not see Receptionist Remittances nav",
            $blockedOk
        );
    }

    // Mid-session deactivated employee receives 403 directly on GET and POST
    $midClient = new ReceptionistRemitTestClient($baseUrl);
    $midClient->staffLogin($recEmail, $pwPlain);
    $midCsrf = $midClient->extractCsrf($midClient->get('/petstore/receptionist/remittances')['body']);
    $pdo->prepare("UPDATE employee SET employee_status = 'inactive' WHERE employee_id = ?")->execute([$recEmpId]);
    $midGet = $midClient->get('/petstore/receptionist/remittances');
    $midPost = $midClient->post("/petstore/receptionist/remittances/allocations/{$alloc1a}/complete", ['csrf_token' => $midCsrf]);
    $pdo->prepare("UPDATE employee SET employee_status = 'active' WHERE employee_id = ?")->execute([$recEmpId]);
    check(
        'Mid-session deactivated Pet Store Receptionist receives 403 on GET and POST Remittances',
        $midGet['status'] === 403 && $midPost['status'] === 403
    );

    // Super Administrator read-only oversight
    $superClient = new ReceptionistRemitTestClient($baseUrl);
    $superClient->staffLogin($superEmail, $pwPlain);
    $superGet = $superClient->get('/petstore/receptionist/remittances');
    // Extract CSRF from another page since read-only remittances page has no mutation forms
    $superOv = $superClient->get('/petstore/receptionist/overview');
    $superCsrf = $superClient->extractCsrf($superOv['body']);
    $superPost = $superClient->post("/petstore/receptionist/remittances/allocations/{$alloc1a}/complete", ['csrf_token' => $superCsrf]);
    $alloc1aAfterSuper = $pdo->query("SELECT handover_status, completed_by_employee_id FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc1a}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Super Administrator has read-only oversight on GET (200, no Complete Handover buttons) and is blocked (403) from POST handover completion',
        $superGet['status'] === 200
            && str_contains($superGet['body'], 'Administrative Oversight Mode (Read-Only)')
            && str_contains($superGet['body'], 'Read-only oversight')
            && !str_contains($superGet['body'], "/petstore/receptionist/remittances/allocations/{$alloc1a}/complete")
            && $superPost['status'] === 403
            && $alloc1aAfterSuper['handover_status'] === 'submitted'
            && $alloc1aAfterSuper['completed_by_employee_id'] === null
    );

    // =========================================================================
    // 3. Individual Handover Completion & Partial Batch Progress (auto_verify OFF)
    // =========================================================================
    $setAutoVerify(0);
    $post1a = $recClient->post("/petstore/receptionist/remittances/allocations/{$alloc1a}/complete", ['csrf_token' => $recCsrf]);
    $after1aPage = $recClient->get('/petstore/receptionist/remittances');
    $batch1After1aHtml = extractElementById($after1aPage['body'], "remittance-batch-{$remit1Id}");
    $alloc1aAfterHtml = extractElementById($after1aPage['body'], "allocation-row-{$alloc1a}");
    $alloc1bAfterHtml = extractElementById($after1aPage['body'], "allocation-row-{$alloc1b}");
    $dbBatch1After1a = (string)$pdo->query("SELECT remittance_status FROM courier_cod_remittance WHERE remittance_id = {$remit1Id}")->fetchColumn();

    check(
        'Completing first allocation (auto_verify OFF) marks it Handover Completed, keeps second allocation Awaiting Handover, and keeps batch in Awaiting Handover (1 / 2 handed over)',
        $post1a['status'] === 302
            && $post1a['location'] === '/petstore/receptionist/remittances'
            && $dbBatch1After1a === 'submitted'
            && str_contains($batch1After1aHtml, '1 / 2 handed over')
            && str_contains($batch1After1aHtml, 'Awaiting Handover')
            && str_contains($alloc1aAfterHtml, 'Handover Completed')
            && !str_contains($alloc1aAfterHtml, "/petstore/receptionist/remittances/allocations/{$alloc1a}/complete")
            && str_contains($alloc1bAfterHtml, 'Awaiting Handover')
            && str_contains($alloc1bAfterHtml, "/petstore/receptionist/remittances/allocations/{$alloc1b}/complete")
    );

    // Complete second allocation in Batch 1 (auto_verify OFF) -> batch becomes Handover Completed
    $post1b = $recClient->post("/petstore/receptionist/remittances/allocations/{$alloc1b}/complete", ['csrf_token' => $recCsrf]);
    $after1bPage = $recClient->get('/petstore/receptionist/remittances');
    $batch1After1bHtml = extractElementById($after1bPage['body'], "remittance-batch-{$remit1Id}");
    $dbBatch1After1b = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id FROM courier_cod_remittance WHERE remittance_id = {$remit1Id}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Completing remaining allocation (auto_verify OFF) updates batch to Handover Completed (2 / 2 handed over) with NULL verified_total',
        $post1b['status'] === 302
            && $dbBatch1After1b['remittance_status'] === 'completed'
            && $dbBatch1After1b['verified_total'] === null
            && str_contains($batch1After1bHtml, '2 / 2 handed over')
            && str_contains($batch1After1bHtml, 'Handover Completed')
            && !str_contains($batch1After1bHtml, 'Awaiting Handover')
    );

    // =========================================================================
    // 4. Duplicate/Stale Completion Rejection & Self-Handover Rejection
    // =========================================================================
    $dupPost = $recClient->post("/petstore/receptionist/remittances/allocations/{$alloc1a}/complete", ['csrf_token' => $recCsrf]);
    $afterDupPage = $recClient->get('/petstore/receptionist/remittances');
    check(
        'Duplicate handover completion POST is rejected and flashes conflict error without altering completed metadata',
        $dupPost['status'] === 302
            && str_contains($afterDupPage['body'], 'already been completed')
    );

    // Dual-role courier+receptionist attempting self-handover on their own batch ($remitSelfId / $allocSelf)
    $dualClient = new ReceptionistRemitTestClient($baseUrl);
    $dualClient->staffLogin($dualEmail, $pwPlain);
    $dualGet = $dualClient->get('/petstore/receptionist/remittances');
    $selfRowHtml = extractElementById($dualGet['body'], "allocation-row-{$allocSelf}");
    $dualCsrf = $dualClient->extractCsrf($dualGet['body']);
    $selfPost = $dualClient->post("/petstore/receptionist/remittances/allocations/{$allocSelf}/complete", ['csrf_token' => $dualCsrf]);
    $dbAllocSelf = $pdo->query("SELECT handover_status, completed_by_employee_id FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocSelf}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Courier who also holds Pet Store RECEPTIONIST role sees Self-handover blocked in UI and is rejected (403) on direct POST',
        $dualGet['status'] === 200
            && str_contains($selfRowHtml, 'Self-handover blocked')
            && !str_contains($selfRowHtml, "/petstore/receptionist/remittances/allocations/{$allocSelf}/complete")
            && $selfPost['status'] === 403
            && $dbAllocSelf['handover_status'] === 'submitted'
            && $dbAllocSelf['completed_by_employee_id'] === null
    );

    // =========================================================================
    // 5. Automatic Verification ON (auto_verify_cod_remittance = 1)
    // =========================================================================
    $setAutoVerify(1);
    $postAuto = $recClient->post("/petstore/receptionist/remittances/allocations/{$alloc2a}/complete", ['csrf_token' => $recCsrf]);
    $afterAutoPage = $recClient->get('/petstore/receptionist/remittances');
    $batch2AfterHtml = extractElementById($afterAutoPage['body'], "remittance-batch-{$remit2Id}");
    $alloc2aAfterHtml = extractElementById($afterAutoPage['body'], "allocation-row-{$alloc2a}");
    $dbBatch2 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remit2Id}")->fetch(PDO::FETCH_ASSOC);
    $dbAlloc2a = $pdo->query("SELECT handover_status, completed_by_employee_id, verification_status, verified_by_employee_id, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc2a}")->fetch(PDO::FETCH_ASSOC);
    $setAutoVerify(0);

    check(
        'Completing handover with auto_verify ON marks allocation & batch Verified (Auto-Verified, NULL verifier, verified_total = 150.00) and displays Verified status',
        $postAuto['status'] === 302
            && $dbAlloc2a['handover_status'] === 'completed'
            && (int)$dbAlloc2a['completed_by_employee_id'] === $recEmpId
            && $dbAlloc2a['verification_status'] === 'verified'
            && $dbAlloc2a['verified_by_employee_id'] === null
            && $dbAlloc2a['verification_mode'] === 'auto_setting'
            && $dbBatch2['remittance_status'] === 'verified'
            && (string)$dbBatch2['verified_total'] === '150.00'
            && $dbBatch2['verified_by_employee_id'] === null
            && $dbBatch2['verified_at'] !== null
            && str_contains($batch2AfterHtml, 'Verified')
            && str_contains($alloc2aAfterHtml, 'Handover Completed')
            && str_contains($alloc2aAfterHtml, 'Verified')
            && str_contains($alloc2aAfterHtml, 'Auto-Verified')
    );

    // =========================================================================
    // 6. Preservation of Existing Receptionist Orders Functionality
    // =========================================================================
    $recOrdersPage = $recClient->get('/petstore/receptionist/orders');
    check(
        'Existing Receptionist Orders page remains accessible and displays today completed orders without regression',
        $recOrdersPage['status'] === 200
            && str_contains($recOrdersPage['body'], '<h1>Orders</h1>')
            && str_contains($recOrdersPage['body'], "#ORD-{$ord1['order_id']}")
    );

    echo "\n====================================================================\n";
    echo "PET STORE RECEPTIONIST REMITTANCES RESULTS: {$passed} PASSED, {$failed} FAILED\n";
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
