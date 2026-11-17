<?php
declare(strict_types=1);

/**
 * PawCariño — Checkpoint 3: Pet Store Business Manager COD Remittances Regression Suite
 *
 * Targets only the explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  1. Navigation & Page: /manager/remittances displays batch ref, courier, submitted date,
 *     expected total (subtotal excluding delivery fees), allocations, statuses, and actor/timestamps;
 *     Remittances link is exclusive to Pet Store Business Manager; /manager/orders stays read-only.
 *  2. Individual Manager actions:
 *     - Collect & Verify (/collect -> manager_collection).
 *     - Verify Handover (/verify -> manual, preserving Receptionist completion actor & timestamp).
 *  3. Transactional batch-wide processing (/process -> OrderPaymentService::processRemittanceBatch()):
 *     - Mixed batch: already-verified left unchanged, completed+pending verified manually (preserving
 *       original completion metadata), submitted+pending collected+verified (manager_collection).
 *     - Correct batch header & single vs. mixed verifier metadata.
 *     - Full transaction rollback if any allocation fails or mid-transaction fault occurs.
 *  4. Authorization & invariants:
 *     - Unauthorized roles (IM, Receptionist, Courier), cross-unit staff, inactive accounts/employees -> 403.
 *     - Super Administrator without explicit unit-1 BM role -> 200 read-only oversight, 403 on POST.
 *     - Super Administrator with explicit unit-1 active employee + BM role -> operational access works.
 *     - Courier self-verification rejected (403) on individual & batch endpoints.
 *     - Customer payment status, order status, COD collection metadata, and amounts remain untouched.
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

final class BmRemitFaultInjector
{
    public ?Closure $beforeExecute = null;
}

final class BmRemitFailureInjectingStatement extends PDOStatement
{
    private BmRemitFaultInjector $injector;

    protected function __construct(BmRemitFaultInjector $injector)
    {
        $this->injector = $injector;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->injector->beforeExecute !== null) {
            ($this->injector->beforeExecute)($this->queryString, $params);
        }
        return parent::execute($params);
    }
}

$openIsolatedPdo = static function (): PDO {
    require APP_ROOT . '/config/database.php';
    /** @var PDO $pdo */
    return $pdo;
};

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

final class BmRemitHttpClient
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

function extractElementById(string $html, string $id): string
{
    $quoted = preg_quote($id, '#');
    if (preg_match('#<(section|tr)\b[^>]*\bid="' . $quoted . '"[^>]*>(.*?)</\1>#s', $html, $m)) {
        return $m[0];
    }
    return '';
}

$port = 18095;
[$serverProc, $serverPipes] = startBuiltInServer($port);
$baseUrl = "http://127.0.0.1:{$port}";
$suffix = bin2hex(random_bytes(4));
$pwPlain = 'BmRemitTest!2026';
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
    echo "PET STORE BUSINESS MANAGER REMITTANCES TEST SUITE (DB: {$dbName})\n";
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
        $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, city, phone) VALUES (1, 'BM Remit Test Branch', 'Manila', '000-0000')");
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
        $email = "bmrmt_{$tag}_{$suffix}@test.invalid";
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

    [$bm1Uid, $bm1EmpId, $bm1Email] = $createStaff('bm1', 'Beth', 'ManagerOne', 1, [['BUSINESS_MANAGER', 1]]);
    [$bm2Uid, $bm2EmpId, $bm2Email] = $createStaff('bm2', 'Ben', 'ManagerTwo', 1, [['BUSINESS_MANAGER', 1]]);
    [$recUid, $recEmpId, $recEmail] = $createStaff('rec', 'Rita', 'Recept', 1, [['RECEPTIONIST', 1]]);
    [$courierUid, $courierEmpId, $courierEmail] = $createStaff('courier', 'Carl', 'Courier', 1, [['DELIVERY_PERSONNEL', 1]]);
    [$dualBmUid, $dualBmEmpId, $dualBmEmail] = $createStaff('dual_bm', 'Dana', 'DualCourierBm', 1, [['DELIVERY_PERSONNEL', 1], ['BUSINESS_MANAGER', 1]]);
    [$imUid, $imEmpId, $imEmail] = $createStaff('im', 'Ian', 'InvMgr', 1, [['INVENTORY_MANAGER', 1]]);
    [$vetOnlyUid, $vetOnlyEmpId, $vetOnlyEmail] = $createStaff('vetonly', 'Vera', 'VetOnly', 2, [['BUSINESS_MANAGER', 2]]);
    [$vetBmUid, $vetBmEmpId, $vetBmEmail] = $createStaff('vetbm', 'Vic', 'VetMgr', 2, [['BUSINESS_MANAGER', 2], ['BUSINESS_MANAGER', 1]]);
    [$inactEmpUid, $inactEmpId, $inactEmpEmail] = $createStaff('inactemp', 'Ivy', 'InactEmp', 1, [['BUSINESS_MANAGER', 1]], 'inactive', 'active');
    [$inactAccUid, $inactAccEmpId, $inactAccEmail] = $createStaff('inactacc', 'Ike', 'InactAcc', 1, [['BUSINESS_MANAGER', 1]], 'active', 'inactive');
    [$superNoBmUid, $superNoBmEmpId, $superNoBmEmail] = $createStaff('super_nobm', 'Sal', 'SuperNoBm', 2, [['SUPER_ADMINISTRATOR', 1]], 'active', 'active', 1);
    [$superWithBmUid, $superWithBmEmpId, $superWithBmEmail] = $createStaff('super_bm', 'Sam', 'SuperExplicitBm', 1, [['SUPER_ADMINISTRATOR', 1], ['BUSINESS_MANAGER', 1]], 'active', 'active', 1);

    // Customer
    $pdo->prepare('INSERT INTO user_account (email, password_hash, account_status) VALUES (?, ?, ?)')
        ->execute(["bmrmt_cust_{$suffix}@test.invalid", $pwHash, 'active']);
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->prepare('INSERT INTO customer (user_account_id, first_name, last_name, phone, customer_status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$custUid, 'Mia', "Customer{$suffix}", '0917-444-5555', 'active']);
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    // Product & Inventory
    $pdo->prepare("INSERT INTO product (product_name, product_category, sku) VALUES (?, 'Food', ?)")
        ->execute(["BMRMT Kibble {$suffix}", "SKU-BMRMT-{$suffix}"]);
    $prodId = (int)$pdo->lastInsertId();
    $cleanup['products'][] = $prodId;
    $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status) VALUES (1, ?, 70.00, 0, 'active')")
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

    // Batch 1 (2 allocations for individual BM Collect & Verify + BM Manual Verification):
    //   alloc1a: submitted+pending -> BM Collect & Verify
    //   alloc1b: Receptionist completed+pending (with pinned historical completed_at) -> BM Verify Handover
    $o1a = $createCollectedDeliveryOrder('110.00', '45.00', $courierEmpId, $courierUid);
    $o1b = $createCollectedDeliveryOrder('140.00', '45.00', $courierEmpId, $courierUid);
    $batch1Res = $paymentService->submitCourierCodRemittance($unitId, $courierEmpId, $courierUid, [$o1a['payment_id'], $o1b['payment_id']], '250.00', "BM Batch 1 {$suffix}");
    $remit1Id = (int)$batch1Res['remittance_id'];
    $remit1Ref = (string)$batch1Res['remittance_reference'];
    $cleanup['remittances'][] = $remit1Id;
    $alloc1Ids = array_map('intval', $pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remit1Id} ORDER BY allocation_id ASC")->fetchAll(PDO::FETCH_COLUMN));
    [$alloc1a, $alloc1b] = $alloc1Ids;

    $paymentService->completeRemittanceAllocation($alloc1b, $recUid);
    $pinnedRecCompletedAt1b = '2026-02-14 09:15:30';
    $pdo->prepare('UPDATE courier_cod_remittance_allocation SET completed_at = ? WHERE allocation_id = ?')
        ->execute([$pinnedRecCompletedAt1b, $alloc1b]);

    // Batch 2 (3 allocations for mixed transactional batch processing):
    //   alloc2a: pre-verified by BM2 (completed+verified) -> must remain unchanged!
    //   alloc2b: Receptionist completed+pending (pinned completed_at) -> manually verified by BM1
    //   alloc2c: submitted+pending -> collected+verified by BM1
    $o2a = $createCollectedDeliveryOrder('100.00', '50.00', $courierEmpId, $courierUid);
    $o2b = $createCollectedDeliveryOrder('125.00', '50.00', $courierEmpId, $courierUid);
    $o2c = $createCollectedDeliveryOrder('175.00', '50.00', $courierEmpId, $courierUid);
    $batch2Res = $paymentService->submitCourierCodRemittance($unitId, $courierEmpId, $courierUid, [$o2a['payment_id'], $o2b['payment_id'], $o2c['payment_id']], '400.00', "BM Batch 2 Mixed {$suffix}");
    $remit2Id = (int)$batch2Res['remittance_id'];
    $remit2Ref = (string)$batch2Res['remittance_reference'];
    $cleanup['remittances'][] = $remit2Id;
    $alloc2Ids = array_map('intval', $pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remit2Id} ORDER BY allocation_id ASC")->fetchAll(PDO::FETCH_COLUMN));
    [$alloc2a, $alloc2b, $alloc2c] = $alloc2Ids;

    $paymentService->collectRemittanceAllocation($alloc2a, $bm2Uid);
    $pinnedVerifiedAt2a = '2026-02-15 11:00:00';
    $pdo->prepare('UPDATE courier_cod_remittance_allocation SET completed_at = ?, verified_at = ? WHERE allocation_id = ?')
        ->execute([$pinnedVerifiedAt2a, $pinnedVerifiedAt2a, $alloc2a]);

    $paymentService->completeRemittanceAllocation($alloc2b, $recUid);
    $pinnedRecCompletedAt2b = '2026-02-16 14:20:00';
    $pdo->prepare('UPDATE courier_cod_remittance_allocation SET completed_at = ? WHERE allocation_id = ?')
        ->execute([$pinnedRecCompletedAt2b, $alloc2b]);

    // Batch 3 (2 allocations processed together by single manager BM1 -> sets batch verified_by_employee_id = bm1EmpId):
    //   alloc3a: Receptionist completed+pending
    //   alloc3b: submitted+pending
    $o3a = $createCollectedDeliveryOrder('85.00', '35.00', $courierEmpId, $courierUid);
    $o3b = $createCollectedDeliveryOrder('115.00', '35.00', $courierEmpId, $courierUid);
    $batch3Res = $paymentService->submitCourierCodRemittance($unitId, $courierEmpId, $courierUid, [$o3a['payment_id'], $o3b['payment_id']], '200.00', "BM Batch 3 SingleVer {$suffix}");
    $remit3Id = (int)$batch3Res['remittance_id'];
    $cleanup['remittances'][] = $remit3Id;
    [$alloc3a, $alloc3b] = array_map('intval', $pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remit3Id} ORDER BY allocation_id ASC")->fetchAll(PDO::FETCH_COLUMN));
    $paymentService->completeRemittanceAllocation($alloc3a, $recUid);

    // Batch 4 (2 allocations for rollback failure testing + Super Admin with explicit BM role test)
    $o4a = $createCollectedDeliveryOrder('90.00', '30.00', $courierEmpId, $courierUid);
    $o4b = $createCollectedDeliveryOrder('110.00', '30.00', $courierEmpId, $courierUid);
    $batch4Res = $paymentService->submitCourierCodRemittance($unitId, $courierEmpId, $courierUid, [$o4a['payment_id'], $o4b['payment_id']], '200.00', "BM Batch 4 Rollback {$suffix}");
    $remit4Id = (int)$batch4Res['remittance_id'];
    $cleanup['remittances'][] = $remit4Id;
    [$alloc4a, $alloc4b] = array_map('intval', $pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remit4Id} ORDER BY allocation_id ASC")->fetchAll(PDO::FETCH_COLUMN));

    // Batch 5 (submitted by dual-role courier+BM $dualBmEmpId for self-verification blocking test)
    $oSelf = $createCollectedDeliveryOrder('95.00', '25.00', $dualBmEmpId, $dualBmUid);
    $batchSelfRes = $paymentService->submitCourierCodRemittance($unitId, $dualBmEmpId, $dualBmUid, [$oSelf['payment_id']], '95.00', "BM Self Block {$suffix}");
    $remitSelfId = (int)$batchSelfRes['remittance_id'];
    $cleanup['remittances'][] = $remitSelfId;
    $allocSelf = (int)$pdo->query("SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = {$remitSelfId} LIMIT 1")->fetchColumn();

    // =========================================================================
    // 1. Authorized Pet Store BM Page Load, Navigation, & Display
    // =========================================================================
    $bm1Client = new BmRemitHttpClient($baseUrl);
    $bm1Client->staffLogin($bm1Email, $pwPlain);

    $remitPage = $bm1Client->get('/manager/remittances');
    $bmCsrf = $bm1Client->extractCsrf($remitPage['body']);
    $batch1Html = extractElementById($remitPage['body'], "bm-remittance-batch-{$remit1Id}");
    $alloc1aHtml = extractElementById($remitPage['body'], "bm-allocation-row-{$alloc1a}");
    $alloc1bHtml = extractElementById($remitPage['body'], "bm-allocation-row-{$alloc1b}");

    // Ensure Manager Orders remains read-only (no remittance actions)
    $bmOrdersPage = $bm1Client->get('/manager/orders');

    check(
        'Pet Store BM topbar links Remittances cleanly while Manager Orders remains read-only without remittance mutations',
        $remitPage['status'] === 200
            && str_contains($remitPage['body'], 'href="/manager/remittances" class="is-current"')
            && !str_contains($remitPage['body'], 'Administrative Oversight Mode')
            && $bmOrdersPage['status'] === 200
            && !str_contains($bmOrdersPage['body'], '/manager/remittances/')
    );

    check(
        'BM Remittances displays batch metadata, subtotal totals (excluding delivery fees), individual Collect & Verify / Verify Handover, and batch Process action',
        str_contains($batch1Html, $remit1Ref)
            && str_contains($batch1Html, 'Carl Courier')
            && str_contains($batch1Html, '₱250.00')
            && str_contains($batch1Html, 'Handover:')
            && str_contains($batch1Html, 'Verified:')
            && str_contains($batch1Html, "/manager/remittances/{$remit1Id}/process")
            && str_contains($alloc1aHtml, "#ORD-{$o1a['order_id']}")
            && str_contains($alloc1aHtml, '₱110.00')
            && str_contains($alloc1aHtml, 'Excludes ₱45.00 delivery fee')
            && str_contains($alloc1aHtml, 'Awaiting Handover')
            && str_contains($alloc1aHtml, 'Pending Verification')
            && str_contains($alloc1aHtml, "/manager/remittances/allocations/{$alloc1a}/collect")
            && str_contains($alloc1bHtml, "#ORD-{$o1b['order_id']}")
            && str_contains($alloc1bHtml, 'Handover Completed')
            && str_contains($alloc1bHtml, 'Rita Recept')
            && str_contains($alloc1bHtml, $pinnedRecCompletedAt1b)
            && str_contains($alloc1bHtml, "/manager/remittances/allocations/{$alloc1b}/verify")
    );

    // =========================================================================
    // 2. Individual Collect & Verify + Verify Handover
    // =========================================================================
    // 2a. Verify alloc1b manually first (handover completed -> verified, preserving recEmpId + pinnedRecCompletedAt1b; batch stays submitted because alloc1a still submitted)
    $postVerify1b = $bm1Client->post("/manager/remittances/allocations/{$alloc1b}/verify", ['csrf_token' => $bmCsrf]);
    $dbAlloc1bAfter = $pdo->query("SELECT handover_status, completed_by_employee_id, completed_at, verification_status, verified_by_employee_id, verified_at, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc1b}")->fetch(PDO::FETCH_ASSOC);
    $dbBatch1Mid = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id FROM courier_cod_remittance WHERE remittance_id = {$remit1Id}")->fetch(PDO::FETCH_ASSOC);

    check(
        'BM Verify Handover transitions completed+pending to verified (mode=manual), preserves Receptionist completion actor & timestamp, and keeps partial batch in submitted',
        $postVerify1b['status'] === 302
            && $dbAlloc1bAfter['handover_status'] === 'completed'
            && (int)$dbAlloc1bAfter['completed_by_employee_id'] === $recEmpId
            && $dbAlloc1bAfter['completed_at'] === $pinnedRecCompletedAt1b
            && $dbAlloc1bAfter['verification_status'] === 'verified'
            && (int)$dbAlloc1bAfter['verified_by_employee_id'] === $bm1EmpId
            && $dbAlloc1bAfter['verified_at'] !== null
            && $dbAlloc1bAfter['verification_mode'] === 'manual'
            && $dbBatch1Mid['remittance_status'] === 'submitted'
            && $dbBatch1Mid['verified_total'] === null
    );

    // 2b. Collect & Verify alloc1a (submitted+pending -> completed+verified with mode=manager_collection; batch reaches verified with bm1EmpId)
    $postCollect1a = $bm1Client->post("/manager/remittances/allocations/{$alloc1a}/collect", ['csrf_token' => $bmCsrf]);
    $afterBatch1Page = $bm1Client->get('/manager/remittances');
    $batch1AfterHtml = extractElementById($afterBatch1Page['body'], "bm-remittance-batch-{$remit1Id}");
    $dbAlloc1aAfter = $pdo->query("SELECT handover_status, completed_by_employee_id, verification_status, verified_by_employee_id, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc1a}")->fetch(PDO::FETCH_ASSOC);
    $dbBatch1Final = $pdo->query("SELECT remittance_status, expected_total, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remit1Id}")->fetch(PDO::FETCH_ASSOC);

    check(
        'BM Collect & Verify completes & verifies submitted allocation (mode=manager_collection) and completes batch to verified (verified_total=250.00, verifier=bm1)',
        $postCollect1a['status'] === 302
            && $dbAlloc1aAfter['handover_status'] === 'completed'
            && (int)$dbAlloc1aAfter['completed_by_employee_id'] === $bm1EmpId
            && $dbAlloc1aAfter['verification_status'] === 'verified'
            && (int)$dbAlloc1aAfter['verified_by_employee_id'] === $bm1EmpId
            && $dbAlloc1aAfter['verification_mode'] === 'manager_collection'
            && $dbBatch1Final['remittance_status'] === 'verified'
            && (string)$dbBatch1Final['verified_total'] === '250.00'
            && (int)$dbBatch1Final['verified_by_employee_id'] === $bm1EmpId
            && $dbBatch1Final['verified_at'] !== null
            && str_contains($batch1AfterHtml, 'Verified')
            && str_contains($batch1AfterHtml, 'Beth ManagerOne')
    );

    // =========================================================================
    // 3. Transactional Batch-Wide Processing (Mixed + Single Verifier)
    // =========================================================================
    // Batch 2: alloc2a (already verified by BM2 @ pinnedVerifiedAt2a), alloc2b (completed by rec @ pinnedRecCompletedAt2b), alloc2c (submitted)
    $postBatch2 = $bm1Client->post("/manager/remittances/{$remit2Id}/process", ['csrf_token' => $bmCsrf]);
    $dbAlloc2a = $pdo->query("SELECT completed_by_employee_id, completed_at, verified_by_employee_id, verified_at, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc2a}")->fetch(PDO::FETCH_ASSOC);
    $dbAlloc2b = $pdo->query("SELECT handover_status, completed_by_employee_id, completed_at, verification_status, verified_by_employee_id, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc2b}")->fetch(PDO::FETCH_ASSOC);
    $dbAlloc2c = $pdo->query("SELECT handover_status, completed_by_employee_id, verification_status, verified_by_employee_id, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc2c}")->fetch(PDO::FETCH_ASSOC);
    $dbBatch2 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remit2Id}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Batch processing leaves pre-verified allocation untouched, manually verifies completed+pending (preserving Receptionist metadata), collects submitted+pending, and leaves mixed batch verifier NULL',
        $postBatch2['status'] === 302
            && (int)$dbAlloc2a['completed_by_employee_id'] === $bm2EmpId
            && $dbAlloc2a['completed_at'] === $pinnedVerifiedAt2a
            && (int)$dbAlloc2a['verified_by_employee_id'] === $bm2EmpId
            && $dbAlloc2a['verified_at'] === $pinnedVerifiedAt2a
            && $dbAlloc2a['verification_mode'] === 'manager_collection'
            && $dbAlloc2b['handover_status'] === 'completed'
            && (int)$dbAlloc2b['completed_by_employee_id'] === $recEmpId
            && $dbAlloc2b['completed_at'] === $pinnedRecCompletedAt2b
            && $dbAlloc2b['verification_status'] === 'verified'
            && (int)$dbAlloc2b['verified_by_employee_id'] === $bm1EmpId
            && $dbAlloc2b['verification_mode'] === 'manual'
            && $dbAlloc2c['handover_status'] === 'completed'
            && (int)$dbAlloc2c['completed_by_employee_id'] === $bm1EmpId
            && $dbAlloc2c['verification_status'] === 'verified'
            && (int)$dbAlloc2c['verified_by_employee_id'] === $bm1EmpId
            && $dbAlloc2c['verification_mode'] === 'manager_collection'
            && $dbBatch2['remittance_status'] === 'verified'
            && (string)$dbBatch2['verified_total'] === '400.00'
            && $dbBatch2['verified_by_employee_id'] === null
            && $dbBatch2['verified_at'] !== null
    );

    // Batch 3: single-verifier batch processing sets batch verified_by_employee_id = bm1EmpId
    $postBatch3 = $bm1Client->post("/manager/remittances/{$remit3Id}/process", ['csrf_token' => $bmCsrf]);
    $dbBatch3 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id FROM courier_cod_remittance WHERE remittance_id = {$remit3Id}")->fetch(PDO::FETCH_ASSOC);
    check(
        'Batch processing by single manager sets shared batch verified_by_employee_id when all active allocations share that verifier',
        $postBatch3['status'] === 302
            && $dbBatch3['remittance_status'] === 'verified'
            && (string)$dbBatch3['verified_total'] === '200.00'
            && (int)$dbBatch3['verified_by_employee_id'] === $bm1EmpId
    );

    // Duplicate batch processing on already verified batch returns clean redirect + error without altering state
    $dupBatch2 = $bm1Client->post("/manager/remittances/{$remit2Id}/process", ['csrf_token' => $bmCsrf]);
    $afterDupBatchPage = $bm1Client->get('/manager/remittances');
    check(
        'Duplicate batch-wide processing on an already verified batch is rejected cleanly',
        $dupBatch2['status'] === 302 && str_contains($afterDupBatchPage['body'], 'already &#039;verified&#039;')
    );

    // =========================================================================
    // 4. Full Rollback on Batch Allocation Failure / Mid-Transaction Fault
    // =========================================================================
    // 4a. Inject fault on second allocation UPDATE during processRemittanceBatch -> first allocation must roll back completely
    $faultInj = new BmRemitFaultInjector();
    $faultPdo = $openIsolatedPdo();
    $faultPdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BmRemitFailureInjectingStatement::class, [$faultInj]]);
    $faultService = new OrderPaymentService($faultPdo);

    $updateCount = 0;
    $faultInj->beforeExecute = static function (string $sql) use (&$updateCount): void {
        if (str_contains($sql, 'UPDATE courier_cod_remittance_allocation')) {
            $updateCount++;
            if ($updateCount === 2) {
                throw new PDOException('Injected failure on second allocation in batch');
            }
        }
    };

    $midFaultCaught = false;
    try {
        $faultService->processRemittanceBatch($remit4Id, $bm1Uid);
    } catch (PDOException $e) {
        $midFaultCaught = str_contains($e->getMessage(), 'Injected failure on second allocation');
    } finally {
        $faultInj->beforeExecute = null;
        $faultPdo = null;
    }

    // 4b. Pre-validation failure on invalid allocation state rolls back without touching valid alloc4a
    $pdo->prepare('UPDATE customer_order SET subtotal_amount = 109.00 WHERE order_id = ?')->execute([$o4b['order_id']]);
    $invalidParityCaught = false;
    try {
        $paymentService->processRemittanceBatch($remit4Id, $bm1Uid);
    } catch (RuntimeException $e) {
        $invalidParityCaught = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }
    $pdo->prepare('UPDATE customer_order SET subtotal_amount = 110.00 WHERE order_id = ?')->execute([$o4b['order_id']]);

    $alloc4aAfterRb = $pdo->query("SELECT handover_status, completed_by_employee_id, completed_at, verification_status, verified_by_employee_id, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc4a}")->fetch(PDO::FETCH_ASSOC);
    $batch4AfterRb = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id FROM courier_cod_remittance WHERE remittance_id = {$remit4Id}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Batch failure on any allocation (mid-transaction fault or validation failure) rolls back entire batch transaction atomically',
        $midFaultCaught
            && $invalidParityCaught
            && $alloc4aAfterRb['handover_status'] === 'submitted'
            && $alloc4aAfterRb['completed_by_employee_id'] === null
            && $alloc4aAfterRb['verification_status'] === 'pending'
            && $alloc4aAfterRb['verified_by_employee_id'] === null
            && $alloc4aAfterRb['verification_mode'] === null
            && $batch4AfterRb['remittance_status'] === 'submitted'
            && $batch4AfterRb['verified_total'] === null
            && $batch4AfterRb['verified_by_employee_id'] === null
    );

    // =========================================================================
    // 5. Authorization, Super Admin Scoping, & Self-Verification Guards
    // =========================================================================
    foreach ([
        'Inventory Manager' => [$imEmail, '/manager/orders', true],
        'Pet Store Receptionist only' => [$recEmail, '/petstore/receptionist/overview', true],
        'Delivery Personnel only' => [$courierEmail, '/delivery/orders', true],
        'Vet Clinic BM (unit 2 only)' => [$vetOnlyEmail, '/manager', true],
        'Cross-unit Vet Clinic BM (with unit-1 BM role but unit-2 employee)' => [$vetBmEmail, '/manager/units/2', true],
        'Inactive Pet Store BM employee' => [$inactEmpEmail, null, false],
        'Inactive Pet Store BM account' => [$inactAccEmail, null, false],
    ] as $label => [$uEmail, $navUrl, $canLogin]) {
        $c = new BmRemitHttpClient($baseUrl);
        $c->staffLogin($uEmail, $pwPlain);
        $navHasBmRemit = false;
        if ($navUrl !== null) {
            $own = $c->get($navUrl);
            $navHasBmRemit = str_contains($own['body'], 'href="/manager/remittances"');
        }
        $home = $c->get('/');
        $csrfAttempt = preg_match('/name="csrf_token"\s+value="([^"]+)"/', $home['body'], $cm)
            ? $cm[1]
            : $c->extractCsrf($c->get('/staff/login')['body']);
        $g = $c->get('/manager/remittances');
        $pCol = $c->post("/manager/remittances/allocations/{$alloc4a}/collect", ['csrf_token' => $csrfAttempt]);
        $pBat = $c->post("/manager/remittances/{$remit4Id}/process", ['csrf_token' => $csrfAttempt]);

        $blockedOk = $canLogin
            ? ($g['status'] === 403 && $pCol['status'] === 403 && $pBat['status'] === 403 && !$navHasBmRemit)
            : (str_contains($g['location'], '/login') && str_contains($pCol['location'], '/login') && str_contains($pBat['location'], '/login'));
        check("Unauthorized actor ({$label}) blocked from BM Remittances GET/POST and nav", $blockedOk);
    }

    // Vet Clinic BM requesting unit 2 /manager/units/2/remittances -> 403
    $vetClient = new BmRemitHttpClient($baseUrl);
    $vetClient->staffLogin($vetBmEmail, $pwPlain);
    check(
        'Cross-unit request (/manager/units/2/remittances) rejected with 403',
        $vetClient->get('/manager/units/2/remittances')['status'] === 403
    );

    // Mid-session deactivated BM gets 403 on GET & POST
    $midBmClient = new BmRemitHttpClient($baseUrl);
    $midBmClient->staffLogin($bm2Email, $pwPlain);
    $midBmCsrf = $midBmClient->extractCsrf($midBmClient->get('/manager/remittances')['body']);
    $pdo->prepare("UPDATE employee SET employee_status = 'inactive' WHERE employee_id = ?")->execute([$bm2EmpId]);
    $midGet = $midBmClient->get('/manager/remittances');
    $midPost = $midBmClient->post("/manager/remittances/allocations/{$alloc4a}/collect", ['csrf_token' => $midBmCsrf]);
    $pdo->prepare("UPDATE employee SET employee_status = 'active' WHERE employee_id = ?")->execute([$bm2EmpId]);
    check('Mid-session deactivated BM receives 403 on GET and POST', $midGet['status'] === 403 && $midPost['status'] === 403);

    // Super Admin WITHOUT explicit active Pet Store BM role -> 200 read-only oversight, 403 on POST
    $saNoBmClient = new BmRemitHttpClient($baseUrl);
    $saNoBmClient->staffLogin($superNoBmEmail, $pwPlain);
    $saGet = $saNoBmClient->get('/manager/remittances');
    $saSettings = $saNoBmClient->get('/manager/settings?unit_id=1');
    $saCsrf = $saNoBmClient->extractCsrf($saSettings['body']);
    $saPostCol = $saNoBmClient->post("/manager/remittances/allocations/{$alloc4a}/collect", ['csrf_token' => $saCsrf]);
    $saPostBat = $saNoBmClient->post("/manager/remittances/{$remit4Id}/process", ['csrf_token' => $saCsrf]);
    check(
        'Super Admin without explicit active Pet Store BM role gets read-only GET (200) and is blocked (403) on POST mutations',
        $saGet['status'] === 200
            && str_contains($saGet['body'], 'Administrative Oversight Mode (Read-Only)')
            && !str_contains($saGet['body'], "/manager/remittances/{$remit4Id}/process")
            && $saPostCol['status'] === 403
            && $saPostBat['status'] === 403
    );

    // Super Admin WITH explicit active Pet Store employee + unit-1 BUSINESS_MANAGER role -> operational access works
    $saWithBmClient = new BmRemitHttpClient($baseUrl);
    $saWithBmClient->staffLogin($superWithBmEmail, $pwPlain);
    $saWithGet = $saWithBmClient->get('/manager/remittances');
    $saWithCsrf = $saWithBmClient->extractCsrf($saWithGet['body']);
    $saWithPost = $saWithBmClient->post("/manager/remittances/{$remit4Id}/process", ['csrf_token' => $saWithCsrf]);
    $dbBatch4AfterSa = $pdo->query("SELECT remittance_status, verified_by_employee_id, verified_total FROM courier_cod_remittance WHERE remittance_id = {$remit4Id}")->fetch(PDO::FETCH_ASSOC);
    check(
        'Super Admin holding explicit active Pet Store employee + BUSINESS_MANAGER role can process batch operational actions',
        $saWithGet['status'] === 200
            && !str_contains($saWithGet['body'], 'Administrative Oversight Mode')
            && $saWithPost['status'] === 302
            && $dbBatch4AfterSa['remittance_status'] === 'verified'
            && (int)$dbBatch4AfterSa['verified_by_employee_id'] === $superWithBmEmpId
            && (string)$dbBatch4AfterSa['verified_total'] === '200.00'
    );

    // Courier self-verification restriction preserved for dual-role courier+BM
    $dualClient = new BmRemitHttpClient($baseUrl);
    $dualClient->staffLogin($dualBmEmail, $pwPlain);
    $dualGet = $dualClient->get('/manager/remittances');
    $dualCsrf = $dualClient->extractCsrf($dualGet['body']);
    $dualSelfRow = extractElementById($dualGet['body'], "bm-allocation-row-{$allocSelf}");
    $dualPostSingle = $dualClient->post("/manager/remittances/allocations/{$allocSelf}/collect", ['csrf_token' => $dualCsrf]);
    $dualPostBatch = $dualClient->post("/manager/remittances/{$remitSelfId}/process", ['csrf_token' => $dualCsrf]);
    $dbBatchSelf = $pdo->query("SELECT remittance_status FROM courier_cod_remittance WHERE remittance_id = {$remitSelfId}")->fetchColumn();
    check(
        'Dual-role courier+BM sees Self-verification blocked in UI and is rejected (403) on both single and batch POST',
        str_contains($dualSelfRow, 'Self-verification blocked')
            && $dualPostSingle['status'] === 403
            && $dualPostBatch['status'] === 403
            && $dbBatchSelf === 'submitted'
    );

    // =========================================================================
    // 6. Customer Payment, Order Status, COD Metadata, & Allocation Amounts Preserved
    // =========================================================================
    $ordCheck = $pdo->query("SELECT order_status, subtotal_amount, delivery_fee, total_amount FROM customer_order WHERE order_id = {$o2c['order_id']}")->fetch(PDO::FETCH_ASSOC);
    $payCheck = $pdo->query("SELECT payment_status, amount, collection_channel, collected_by_employee_id, collected_at FROM payment WHERE payment_id = {$o2c['payment_id']}")->fetch(PDO::FETCH_ASSOC);
    $allocCheck = $pdo->query("SELECT expected_amount, allocated_amount, allocation_status FROM courier_cod_remittance_allocation WHERE allocation_id = {$alloc2c}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Manager remittance collection & verification preserve customer order_status, payment_status, COD collection metadata, and allocation amounts',
        $ordCheck['order_status'] === 'completed'
            && (string)$ordCheck['subtotal_amount'] === '175.00'
            && (string)$ordCheck['delivery_fee'] === '50.00'
            && (string)$ordCheck['total_amount'] === '225.00'
            && $payCheck['payment_status'] === 'completed'
            && (string)$payCheck['amount'] === '225.00'
            && $payCheck['collection_channel'] === 'courier_cod'
            && (int)$payCheck['collected_by_employee_id'] === $courierEmpId
            && $payCheck['collected_at'] !== null
            && (string)$allocCheck['expected_amount'] === '175.00'
            && (string)$allocCheck['allocated_amount'] === '175.00'
            && $allocCheck['allocation_status'] === 'active'
    );

    echo "\n====================================================================\n";
    echo "PET STORE BM REMITTANCES RESULTS: {$passed} PASSED, {$failed} FAILED\n";
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
