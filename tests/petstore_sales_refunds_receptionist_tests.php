<?php
declare(strict_types=1);

/**
 * PawCariño — Pet Store Sales, Refunds, and Receptionist Interface Regression Suite
 *
 * Targets only the explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  1. Navigation & RBAC/Unit Isolation (BM Sales/Refunds/legacy Orders, IM Orders, Receptionist Overview/Orders).
 *  2. Receptionist Overview (Overview title, Orders Today card, equal-height Pending container, today-only recent sales, New Sale).
 *  3. Receptionist Orders (today's orders, active redelivery today, today's completions incl. redeliveries, excludes future-day & stale orders, no duplicates).
 *  4. BM Sales (Walk-in / Online — Delivery / Online — Pick-up; payment-status inclusion; COD & remittance independence; refunds preserved; item-level refund indicator ONLY inside details when refund occurred).
 *  5. BM Refunds (all statuses, date/amount sorting, search by customer name / customer ID / order ID, approve/reject workflow & order preservation).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/process_cleanup_helper.php';

use App\Core\Database;

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

final class PetStoreTestClient
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

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerText = substr($raw, 0, $headerSize);
        $resBody = substr($raw, $headerSize);
        curl_close($ch);

        preg_match_all('/^Set-Cookie:\s*([^=;]+)=([^;]+)/mi', $headerText, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $this->cookies[trim($m[1])] = trim($m[2]);
        }

        $location = '';
        if (preg_match('/^Location:\s*(.+)$/im', $headerText, $m)) {
            $location = trim($m[1]);
        }

        return ['status' => $status, 'location' => $location, 'body' => $resBody];
    }
}

function startBuiltInServer(int $port): array
{
    $root = dirname(__DIR__);
    $phpBin = 'D:\\VETGRPETproj\\tools\\php83\\php.exe';
    if (!is_file($phpBin)) {
        $phpBin = PHP_BINARY;
    }
    $cmd = sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', $phpBin, $port, $root . DIRECTORY_SEPARATOR . 'public', $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php');
    $nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $desc = [
        0 => ['file', $nul, 'r'],
        1 => ['file', $nul, 'w'],
        2 => ['file', $nul, 'w'],
    ];
    $proc = proc_open($cmd, $desc, $pipes, $root);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start built-in PHP server.');
    }

    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);
        if ($fp !== false) {
            fclose($fp);
            return [$proc, $pipes];
        }
        usleep(100000);
    }
    safelyTerminateAndClose($proc, $pipes, 2);
    throw new RuntimeException("Built-in server did not listen on port {$port} in time.");
}

function extractModal(string $html, string $modalId): string
{
    if (preg_match('#<dialog[^>]*id="' . preg_quote($modalId, '#') . '"[^>]*>(.*?)</dialog>#s', $html, $m)) {
        return $m[1];
    }
    return '';
}

function extractMainTable(string $html): string
{
    if (preg_match('#<table[^>]*>(.*?)</table>#s', $html, $m)) {
        return $m[1];
    }
    return '';
}

function extractProductsTbody(string $modalHtml): string
{
    $pos = strpos($modalHtml, 'Purchased Products');
    $slice = $pos !== false ? substr($modalHtml, $pos) : $modalHtml;
    if (preg_match('#<tbody>(.*?)</tbody>#s', $slice, $m)) {
        return $m[1];
    }
    return '';
}

$port = 18093;
[$serverProc, $serverPipes] = startBuiltInServer($port);
$baseUrl = "http://127.0.0.1:{$port}";
$suffix = bin2hex(random_bytes(4));
$pwPlain = 'PetStoreTest!2026';
$pwHash = password_hash($pwPlain, PASSWORD_BCRYPT);

$createdUserIds = [];
$createdCustomerIds = [];
$createdProductIds = [];
$createdBpIds = [];
$createdInvIds = [];
$createdSaleIds = [];
$createdOrderIds = [];
$createdPaymentIds = [];
$createdRefundReqIds = [];

try {
    echo "====================================================================\n";
    echo "PET STORE SALES, REFUNDS & RECEPTIONIST TEST SUITE (DB: {$dbName})\n";
    echo "====================================================================\n";

    $branchId = (int)$pdo->query("SELECT branch_id FROM branch WHERE business_unit_id = 1 LIMIT 1")->fetchColumn();
    if ($branchId <= 0) {
        $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, city, phone) VALUES (1, 'Pet Store Test Branch', 'Test City', '000-0000')");
        $branchId = (int)$pdo->lastInsertId();
    }

    $createStaff = static function (int $unitId, string $email, string $first, string $last, string $pos, string $roleName) use ($pdo, $pwHash, &$createdUserIds): array {
        $pdo->prepare("INSERT INTO user_account (email, password_hash, account_status) VALUES (?, ?, 'active')")->execute([$email, $pwHash]);
        $uid = (int)$pdo->lastInsertId();
        $createdUserIds[] = $uid;

        $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, phone, employee_status) VALUES (?, ?, ?, ?, ?, '0917-111-1111', 'active')")
            ->execute([$uid, $unitId, $first, $last, $pos]);
        $empId = (int)$pdo->lastInsertId();

        $rId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = " . $pdo->quote($roleName) . " LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)")->execute([$uid, $rId, $unitId]);
        return [$uid, $empId];
    };

    [$bmUid, $bmEmpId] = $createStaff(1, "psr_bm_{$suffix}@test.invalid", 'Pet', 'Manager', 'Business Manager — Pet Store', 'BUSINESS_MANAGER');
    [$imUid, $imEmpId] = $createStaff(1, "psr_im_{$suffix}@test.invalid", 'Pet', 'InvMgr', 'Inventory Manager', 'INVENTORY_MANAGER');
    [$recUid, $recEmpId] = $createStaff(1, "psr_rec_{$suffix}@test.invalid", 'Pet', 'Recept', 'Receptionist', 'RECEPTIONIST');
    [$courierUid, $courierEmpId] = $createStaff(1, "psr_cour_{$suffix}@test.invalid", 'Pet', 'Courier', 'Delivery Personnel', 'DELIVERY_PERSONNEL');
    [$vetBmUid, $vetBmEmpId] = $createStaff(2, "psr_vbm_{$suffix}@test.invalid", 'Vet', 'Manager', 'Business Manager — Veterinary Clinic', 'BUSINESS_MANAGER');

    // Create two test customers for search & customer_id verification
    $createCust = static function (string $email, string $first, string $last) use ($pdo, $pwHash, &$createdUserIds, &$createdCustomerIds): int {
        $pdo->prepare("INSERT INTO user_account (email, password_hash, account_status) VALUES (?, ?, 'active')")->execute([$email, $pwHash]);
        $uid = (int)$pdo->lastInsertId();
        $createdUserIds[] = $uid;
        $pdo->prepare("INSERT INTO customer (user_account_id, first_name, last_name, phone, customer_status) VALUES (?, ?, ?, '0917-222-2222', 'active')")
            ->execute([$uid, $first, $last]);
        $cid = (int)$pdo->lastInsertId();
        $createdCustomerIds[] = $cid;
        return $cid;
    };
    $custAliceId = $createCust("psr_alice_{$suffix}@test.invalid", 'Alice', "TestAlpha{$suffix}");
    $custBobId = $createCust("psr_bob_{$suffix}@test.invalid", 'Bob', "TestBeta{$suffix}");

    // Create active Pet Store product + inventory
    $pdo->prepare("INSERT INTO product (product_name, description, product_category, sku) VALUES (?, 'Test kibble', 'Food', ?)")
        ->execute(["PSR Kibble {$suffix}", "SKU-PSR-{$suffix}"]);
    $prodId = (int)$pdo->lastInsertId();
    $createdProductIds[] = $prodId;
    $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, product_status) VALUES (1, ?, 120.00, 'active')")
        ->execute([$prodId]);
    $bpId = (int)$pdo->lastInsertId();
    $createdBpIds[] = $bpId;
    $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, 100, 0, 0)")
        ->execute([$bpId]);
    $createdInvIds[] = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO product (product_name, description, product_category, sku) VALUES (?, 'Test leash', 'Accessories', ?)")
        ->execute(["PSR Leash {$suffix}", "SKU-PSR-2-{$suffix}"]);
    $prod2Id = (int)$pdo->lastInsertId();
    $createdProductIds[] = $prod2Id;
    $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, product_status) VALUES (1, ?, 80.00, 'active')")
        ->execute([$prod2Id]);
    $bp2Id = (int)$pdo->lastInsertId();
    $createdBpIds[] = $bp2Id;
    $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, 100, 0, 0)")
        ->execute([$bp2Id]);
    $createdInvIds[] = (int)$pdo->lastInsertId();

    $insertSale = static function (int $empId, ?int $custId, string $saleDateSql, float $total, string $method, string $payStatus, bool|float|null $childRefund = false, array $customItems = []) use ($pdo, $branchId, $bpId, &$createdSaleIds, &$createdPaymentIds): int {
        $pdo->exec("INSERT INTO sale (business_unit_id, branch_id, customer_id, employee_id, sale_date, total_amount, sale_status)
                    VALUES (1, {$branchId}, " . ($custId ? $custId : 'NULL') . ", {$empId}, {$saleDateSql}, {$total}, 'completed')");
        $saleId = (int)$pdo->lastInsertId();
        $createdSaleIds[] = $saleId;

        $items = !empty($customItems) ? $customItems : [[$bpId, 1, $total]];
        $stmtSaleItem = $pdo->prepare("INSERT INTO sale_item (sale_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        foreach ($items as [$itemBpId, $qty, $unitPrice]) {
            $stmtSaleItem->execute([$saleId, $itemBpId, $qty, $unitPrice]);
        }

        $pdo->prepare("INSERT INTO payment (sale_id, payment_type, amount, payment_method, payment_status, collection_channel, collected_by_employee_id, collected_at)
                       VALUES (?, 'payment', ?, ?, ?, 'in_store_pos', ?, UTC_TIMESTAMP())")
            ->execute([$saleId, $total, $method, $payStatus === 'refunded' ? 'refunded' : $payStatus, $empId]);
        $payId = (int)$pdo->lastInsertId();
        $createdPaymentIds[] = $payId;

        $refundAmt = $childRefund === true ? $total : (is_numeric($childRefund) && (float)$childRefund > 0 ? (float)$childRefund : null);
        if ($refundAmt !== null) {
            $pdo->prepare("INSERT INTO payment (sale_id, original_payment_id, payment_type, amount, payment_method, payment_status)
                           VALUES (?, ?, 'refund', ?, ?, 'completed')")
                ->execute([$saleId, $payId, $refundAmt, $method]);
            $createdPaymentIds[] = (int)$pdo->lastInsertId();
        }
        return $saleId;
    };

    $insertOrder = static function (array $opts) use ($pdo, $branchId, $bpId, &$createdOrderIds, &$createdPaymentIds): array {
        $custId = (int)$opts['customer_id'];
        $method = (string)$opts['fulfillment_method'];
        $status = (string)$opts['order_status'];
        $orderDateExpr = (string)($opts['order_date_sql'] ?? 'NOW()');
        $updatedAtExpr = (string)($opts['updated_at_sql'] ?? 'NOW()');
        $failedAtExpr = isset($opts['delivery_failed_at_sql']) ? (string)$opts['delivery_failed_at_sql'] : 'NULL';
        $failReason = isset($opts['delivery_failure_reason']) ? $pdo->quote((string)$opts['delivery_failure_reason']) : 'NULL';
        $reschedExpr = isset($opts['rescheduled_date_sql']) ? (string)$opts['rescheduled_date_sql'] : 'NULL';
        $delivEmpExpr = isset($opts['delivery_employee_id']) ? (int)$opts['delivery_employee_id'] : 'NULL';
        $sub = (float)($opts['subtotal'] ?? 120.00);
        $fee = (float)($opts['delivery_fee'] ?? ($method === 'delivery' ? 50.00 : 0.00));
        $total = $sub + $fee;

        $pdo->exec("INSERT INTO customer_order (
                        customer_id, business_unit_id, branch_id, order_date, fulfillment_method, order_status,
                        subtotal_amount, delivery_fee, total_amount,
                        delivery_address_line1, delivery_city, delivery_failure_reason, delivery_employee_id,
                        delivery_failed_at, rescheduled_date, created_at, updated_at
                    ) VALUES (
                        {$custId}, 1, {$branchId}, {$orderDateExpr}, " . $pdo->quote($method) . ", " . $pdo->quote($status) . ",
                        {$sub}, {$fee}, {$total},
                        '100 Test Ave', 'Manila', {$failReason}, {$delivEmpExpr},
                        {$failedAtExpr}, {$reschedExpr}, {$orderDateExpr}, {$updatedAtExpr}
                    )");
        $orderId = (int)$pdo->lastInsertId();
        $createdOrderIds[] = $orderId;

        $items = !empty($opts['items']) && is_array($opts['items']) ? $opts['items'] : [[$bpId, 1, $sub]];
        $stmtOrdItem = $pdo->prepare("INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        foreach ($items as [$itemBpId, $qty, $unitPrice]) {
            $stmtOrdItem->execute([$orderId, $itemBpId, $qty, $unitPrice]);
        }

        $payId = null;
        if (!empty($opts['payment_status'])) {
            $pMethod = (string)($opts['payment_method'] ?? 'COD');
            $pStatus = (string)$opts['payment_status'];
            $channel = null;
            $collEmp = null;
            $collAt = null;
            $provName = null;
            $provPayId = null;
            if (in_array($pStatus, ['completed', 'refunded'], true)) {
                $channel = (string)($opts['collection_channel'] ?? ($method === 'delivery' && strtolower($pMethod) === 'cod' ? 'courier_cod' : 'online_gateway'));
                if ($channel === 'online_gateway') {
                    $provName = 'gcash';
                    $provPayId = 'prov_' . $orderId . '_' . bin2hex(random_bytes(3));
                    $collAt = gmdate('Y-m-d H:i:s');
                } else {
                    $collEmp = (int)($opts['collected_by_employee_id'] ?? $delivEmpExpr);
                    $collAt = gmdate('Y-m-d H:i:s');
                }
            }
            $pdo->prepare(
                "INSERT INTO payment (order_id, payment_type, amount, payment_method, payment_status, provider_name, provider_payment_id, collection_channel, collected_by_employee_id, collected_at)
                 VALUES (?, 'payment', ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([$orderId, $total, $pMethod, $pStatus, $provName, $provPayId, $channel, $collEmp, $collAt]);
            $payId = (int)$pdo->lastInsertId();
            $createdPaymentIds[] = $payId;

            if (!empty($opts['refund_payment_amount'])) {
                $refAmt = (float)$opts['refund_payment_amount'];
                $pdo->prepare("INSERT INTO payment (order_id, original_payment_id, payment_type, amount, payment_method, payment_status) VALUES (?, ?, 'refund', ?, ?, 'completed')")
                    ->execute([$orderId, $payId, $refAmt, $pMethod]);
                $createdPaymentIds[] = (int)$pdo->lastInsertId();
            }
        }

        return ['order_id' => $orderId, 'payment_id' => $payId];
    };

    // -----------------------------------------------------------------
    // Fixtures: Walk-in Sales (today vs past vs refunded)
    // -----------------------------------------------------------------
    $saleTodayId = $insertSale($recEmpId, $custAliceId, 'NOW()', 120.00, 'Cash', 'completed', false);
    $saleTodayRefundedId = $insertSale($recEmpId, $custBobId, 'NOW()', 120.00, 'GCash', 'completed', true);
    $salePastId = $insertSale($recEmpId, $custAliceId, 'DATE_SUB(NOW(), INTERVAL 2 DAY)', 120.00, 'Cash', 'completed', false);
    $salePartialRefundId = $insertSale(
        $recEmpId,
        $custBobId,
        'DATE_SUB(NOW(), INTERVAL 1 DAY)',
        200.00,
        'GCash',
        'completed',
        80.00,
        [[$bpId, 1, 120.00], [$bp2Id, 1, 80.00]]
    );
    $saleRecordedRefundOnlyId = $insertSale(
        $recEmpId,
        $custAliceId,
        'DATE_SUB(NOW(), INTERVAL 1 DAY)',
        200.00,
        'Cash',
        'refunded',
        false,
        [[$bpId, 1, 120.00], [$bp2Id, 1, 80.00]]
    );
    $saleOverRefundedId = $insertSale(
        $recEmpId,
        $custBobId,
        'DATE_SUB(NOW(), INTERVAL 1 DAY)',
        200.00,
        'GCash',
        'completed',
        250.00,
        [[$bpId, 1, 120.00], [$bp2Id, 1, 80.00]]
    );

    // -----------------------------------------------------------------
    // Fixtures: Orders for Receptionist Orders & BM Sales
    // -----------------------------------------------------------------
    // 1. Order placed today, pending COD -> IN Receptionist Orders, EXCLUDED from BM Sales (payment pending)
    $oTodayUnpaidCod = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'pending',
        'order_date_sql' => 'NOW()',
        'payment_method' => 'COD',
        'payment_status' => 'pending',
    ])['order_id'];

    // 2. Order placed today AND completed today (GCash completed) -> IN Receptionist Orders ONCE, IN BM Sales Online — Delivery
    $oTodayCompletedOnline = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'completed',
        'order_date_sql' => 'NOW()',
        'updated_at_sql' => 'NOW()',
        'payment_method' => 'GCash',
        'payment_status' => 'completed',
    ])['order_id'];

    // 3. Previously placed delivery order, failed 2 days ago, actively redelivered today (out_for_delivery) + prepaid completed
    $oPastRedeliveryActive = $insertOrder([
        'customer_id' => $custBobId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'out_for_delivery',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 3 DAY)',
        'updated_at_sql' => 'NOW()',
        'delivery_failed_at_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'delivery_failure_reason' => 'Customer not home on first attempt',
        'rescheduled_date_sql' => 'CURDATE()',
        'delivery_employee_id' => $courierEmpId,
        'payment_method' => 'Maya',
        'payment_status' => 'completed',
    ])['order_id'];

    // 4. Previously placed delivery order, failed 2 days ago, rescheduled for TODAY (delivery_failed, rescheduled_date = CURDATE)
    $oPastFailedDueToday = $insertOrder([
        'customer_id' => $custBobId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'delivery_failed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 3 DAY)',
        'updated_at_sql' => 'DATE_SUB(NOW(), INTERVAL 1 DAY)',
        'delivery_failed_at_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'delivery_failure_reason' => 'Doorbell broken',
        'rescheduled_date_sql' => 'CURDATE()',
        'delivery_employee_id' => $courierEmpId,
        'payment_method' => 'COD',
        'payment_status' => 'pending',
    ])['order_id'];

    // 5. Previously placed delivery order, failed 2 days ago, rescheduled TODAY for TOMORROW -> MUST BE EXCLUDED from Receptionist Orders
    $oPastFutureRescheduled = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'delivery_failed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 3 DAY)',
        'updated_at_sql' => 'NOW()',
        'delivery_failed_at_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'delivery_failure_reason' => 'Requested tomorrow slot',
        'rescheduled_date_sql' => 'DATE_ADD(CURDATE(), INTERVAL 1 DAY)',
        'delivery_employee_id' => $courierEmpId,
        'payment_method' => 'COD',
        'payment_status' => 'pending',
    ])['order_id'];

    // 6. Previously placed failed delivery order that completed via redelivery TODAY (COD completed, NO remittance)
    $oPastRedeliveryCompletedToday = $insertOrder([
        'customer_id' => $custBobId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'completed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 4 DAY)',
        'updated_at_sql' => 'NOW()',
        'delivery_failed_at_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'delivery_failure_reason' => 'Initial miss',
        'rescheduled_date_sql' => 'CURDATE()',
        'delivery_employee_id' => $courierEmpId,
        'payment_method' => 'COD',
        'payment_status' => 'completed',
        'collection_channel' => 'courier_cod',
        'collected_by_employee_id' => $courierEmpId,
    ])['order_id'];

    // 7. Ordinary old completed order (completed 3 days ago) -> EXCLUDED from Receptionist Orders, INCLUDED in BM Sales
    $oOldCompleted = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'pickup',
        'order_status' => 'completed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 3 DAY)',
        'updated_at_sql' => 'DATE_SUB(NOW(), INTERVAL 3 DAY)',
        'payment_method' => 'Cash',
        'payment_status' => 'completed',
        'collection_channel' => 'in_store_pickup',
        'collected_by_employee_id' => $recEmpId,
    ])['order_id'];

    // 8. Future-dated ordinary order -> MUST BE EXCLUDED from Receptionist Orders
    $oFutureOrder = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'pickup',
        'order_status' => 'confirmed',
        'order_date_sql' => 'DATE_ADD(NOW(), INTERVAL 1 DAY)',
        'updated_at_sql' => 'DATE_ADD(NOW(), INTERVAL 1 DAY)',
        'payment_method' => 'COD',
        'payment_status' => 'pending',
    ])['order_id'];

    // 9. Online Delivery with FAILED payment -> EXCLUDED from BM Sales
    $oFailedPayDelivery = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'pending',
        'order_date_sql' => 'NOW()',
        'payment_method' => 'GCash',
        'payment_status' => 'failed',
    ])['order_id'];

    // 10. Online Pick-up with COMPLETED payment while order still in progress ('ready_for_pickup') -> INCLUDED in BM Sales Online — Pick-up
    $oPickupPaidInProgress = $insertOrder([
        'customer_id' => $custBobId,
        'fulfillment_method' => 'pickup',
        'order_status' => 'ready_for_pickup',
        'order_date_sql' => 'NOW()',
        'payment_method' => 'GCash',
        'payment_status' => 'completed',
    ])['order_id'];

    // 11. Online Pick-up with COMPLETED COD payment while order awaiting customer receipt ('confirmed' / 'ready_for_pickup') -> INCLUDED in BM Sales Online — Pick-up
    $oPickupCodPaidInProgress = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'pickup',
        'order_status' => 'ready_for_pickup',
        'order_date_sql' => 'NOW()',
        'payment_method' => 'COD',
        'payment_status' => 'completed',
        'collection_channel' => 'in_store_pickup',
        'collected_by_employee_id' => $recEmpId,
    ])['order_id'];

    // 12. Online Delivery COMPLETED order that WAS actually refunded -> REMAINS in BM Sales Online — Delivery with order_status = 'completed' and item refund indicator ONLY inside its details modal
    $oDeliveryRefundedRes = $insertOrder([
        'customer_id' => $custBobId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'completed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 1 DAY)',
        'updated_at_sql' => 'DATE_SUB(NOW(), INTERVAL 1 DAY)',
        'payment_method' => 'GCash',
        'payment_status' => 'completed',
        'refund_payment_amount' => 170.00,
    ]);
    $oDeliveryRefunded = $oDeliveryRefundedRes['order_id'];
    $oDeliveryRefundedPayId = $oDeliveryRefundedRes['payment_id'];

    // 13. Online Delivery multi-item PARTIAL refund (refund amount 80.00 equals item 2 price -> must NOT mark item 2 as refunded)
    $oDeliveryPartialRefunded = $insertOrder([
        'customer_id' => $custBobId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'completed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'updated_at_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'subtotal' => 200.00,
        'delivery_fee' => 50.00,
        'items' => [[$bpId, 1, 120.00], [$bp2Id, 1, 80.00]],
        'payment_method' => 'GCash',
        'payment_status' => 'completed',
        'refund_payment_amount' => 80.00,
    ])['order_id'];

    // 14. Online Delivery multi-item FULL refund (250.00 of 250.00 paid)
    $oDeliveryFullMultiRefunded = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'completed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'updated_at_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'subtotal' => 200.00,
        'delivery_fee' => 50.00,
        'items' => [[$bpId, 1, 120.00], [$bp2Id, 1, 80.00]],
        'payment_method' => 'GCash',
        'payment_status' => 'completed',
        'refund_payment_amount' => 250.00,
    ])['order_id'];

    // 15. Online Pick-up transaction-level refund without child refund-amount row (payment_status = 'refunded' only)
    $oPickupRecordedRefundOnly = $insertOrder([
        'customer_id' => $custBobId,
        'fulfillment_method' => 'pickup',
        'order_status' => 'completed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'updated_at_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'subtotal' => 200.00,
        'delivery_fee' => 0.00,
        'items' => [[$bpId, 1, 120.00], [$bp2Id, 1, 80.00]],
        'payment_method' => 'GCash',
        'payment_status' => 'refunded',
    ])['order_id'];

    // 16. Online Delivery over-refunded anomaly (300.00 refunded > 250.00 paid) -> generic 'recorded' with inconsistency reported
    $oDeliveryOverRefunded = $insertOrder([
        'customer_id' => $custAliceId,
        'fulfillment_method' => 'delivery',
        'order_status' => 'completed',
        'order_date_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'updated_at_sql' => 'DATE_SUB(NOW(), INTERVAL 2 DAY)',
        'subtotal' => 200.00,
        'delivery_fee' => 50.00,
        'items' => [[$bpId, 1, 120.00], [$bp2Id, 1, 80.00]],
        'payment_method' => 'GCash',
        'payment_status' => 'completed',
        'refund_payment_amount' => 300.00,
    ])['order_id'];

    // -----------------------------------------------------------------
    // Fixtures: Refund Requests (pending, approved, rejected with distinct dates & amounts)
    // -----------------------------------------------------------------
    $insertRefReq = static function (string $ref, int $custId, ?int $ordId, ?int $payId, float $reqAmt, string $status, string $createdAtSql, ?float $appAmt = null, ?string $rejReason = null) use ($pdo, $bmEmpId, &$createdRefundReqIds): int {
        $decEmp = $status === 'pending' ? 'NULL' : (string)$bmEmpId;
        $decAt = $status === 'pending' ? 'NULL' : 'NOW()';
        $appVal = $appAmt !== null ? (string)$appAmt : 'NULL';
        $rejVal = $rejReason !== null ? $pdo->quote($rejReason) : 'NULL';
        $ordVal = $ordId !== null ? (string)$ordId : 'NULL';
        $payVal = $payId !== null ? (string)$payId : 'NULL';
        $pdo->exec("INSERT INTO refund_request (refund_reference, business_unit_id, customer_id, order_id, sale_id, payment_id, channel, requested_amount, approved_amount, customer_reason, request_status, decided_by_employee_id, decided_at, rejection_reason, created_at)
                    VALUES (" . $pdo->quote($ref) . ", 1, {$custId}, {$ordVal}, NULL, {$payVal}, 'online', {$reqAmt}, {$appVal}, 'Test refund reason {$ref}', " . $pdo->quote($status) . ", {$decEmp}, {$decAt}, {$rejVal}, {$createdAtSql})");
        $id = (int)$pdo->lastInsertId();
        $createdRefundReqIds[] = $id;
        return $id;
    };

    $refPendingSmall = $insertRefReq("REF-PSR-1-{$suffix}", $custAliceId, $oTodayCompletedOnline, null, 25.00, 'pending', 'DATE_SUB(NOW(), INTERVAL 3 HOUR)');
    $refApprovedLarge = $insertRefReq("REF-PSR-2-{$suffix}", $custBobId, $oDeliveryRefunded, $oDeliveryRefundedPayId, 170.00, 'approved', 'DATE_SUB(NOW(), INTERVAL 2 DAY)', 170.00);
    $refRejectedMid = $insertRefReq("REF-PSR-3-{$suffix}", $custAliceId, $oOldCompleted, null, 80.00, 'rejected', 'DATE_SUB(NOW(), INTERVAL 1 DAY)', null, 'Opened consumable outside return window');

    // =================================================================
    // TEST GROUP 1: Navigation & Role/Unit Access Control
    // =================================================================
    $bm = new PetStoreTestClient($baseUrl);
    $bm->staffLogin("psr_bm_{$suffix}@test.invalid", $pwPlain);
    $bmOv = $bm->get('/manager');
    check(
        'BM topbar labels Sales (/manager/sales) and Refunds (/manager/refunds), not BM Orders',
        $bmOv['status'] === 200
            && str_contains($bmOv['body'], 'href="/manager/sales"')
            && str_contains($bmOv['body'], '>Sales</a>')
            && str_contains($bmOv['body'], 'href="/manager/refunds"')
            && str_contains($bmOv['body'], '>Refunds</a>')
            && !str_contains($bmOv['body'], 'href="/manager/orders"')
    );
    $bmLegacyOrders = $bm->get('/manager/orders');
    check('BM legacy /manager/orders route preserved (200)', $bmLegacyOrders['status'] === 200);

    $im = new PetStoreTestClient($baseUrl);
    $im->staffLogin("psr_im_{$suffix}@test.invalid", $pwPlain);
    $imOrders = $im->get('/manager/orders');
    check(
        'Inventory Manager preserves Orders nav & excludes BM Sales/Refunds (and blocks /manager/sales, /manager/refunds with 403)',
        $imOrders['status'] === 200
            && str_contains($imOrders['body'], 'href="/manager/orders" class="is-current"')
            && !str_contains($imOrders['body'], 'href="/manager/sales"')
            && !str_contains($imOrders['body'], 'href="/manager/refunds"')
            && $im->get('/manager/sales')['status'] === 403
            && $im->get('/manager/refunds')['status'] === 403
    );

    $vetBm = new PetStoreTestClient($baseUrl);
    $vetBm->staffLogin("psr_vbm_{$suffix}@test.invalid", $pwPlain);
    check(
        'Vet Clinic BM blocked (403) from Pet Store Sales, Refunds, and Receptionist',
        $vetBm->get('/manager/sales')['status'] === 403
            && $vetBm->get('/manager/refunds')['status'] === 403
            && $vetBm->get('/petstore/receptionist/overview')['status'] === 403
    );

    $rec = new PetStoreTestClient($baseUrl);
    $recLogin = $rec->staffLogin("psr_rec_{$suffix}@test.invalid", $pwPlain);
    check('Pet Store Receptionist login redirects to /petstore/receptionist/overview', $recLogin['status'] === 302 && $recLogin['location'] === '/petstore/receptionist/overview');

    $recOv = $rec->get('/petstore/receptionist/overview');
    $recSalesAlias = $rec->get('/petstore/receptionist/sales');
    check(
        'Receptionist Overview nav & page work (with /sales alias preserved) and blocks BM pages (403)',
        $recOv['status'] === 200
            && $recSalesAlias['status'] === 200
            && str_contains($recOv['body'], 'href="/petstore/receptionist/overview" class="is-current"')
            && str_contains($recOv['body'], '>Overview</a>')
            && $rec->get('/manager/sales')['status'] === 403
            && $rec->get('/manager/refunds')['status'] === 403
    );

    // =================================================================
    // TEST GROUP 2: Receptionist Overview
    // =================================================================
    check(
        'Receptionist Overview displays Overview heading, Orders Today card (not Daily Sales), and stretch equal-height status boxes',
        str_contains($recOv['body'], '<h1>Overview</h1>')
            && str_contains($recOv['body'], '>Orders Today<')
            && !str_contains($recOv['body'], '>Daily Sales<')
            && str_contains($recOv['body'], 'align-items: stretch')
            && substr_count($recOv['body'], 'class="orders-today-status-box"') === 3
            && substr_count($recOv['body'], 'height: 100%') >= 5
    );

    check(
        'Receptionist Overview Recent Sales includes ONLY today walk-in sales and excludes past-day sales',
        str_contains($recOv['body'], "#SALE-{$saleTodayId}")
            && str_contains($recOv['body'], "#SALE-{$saleTodayRefundedId}")
            && !str_contains($recOv['body'], "#SALE-{$salePastId}")
    );

    // Test New Sale creation from Overview
    $recCsrf = $rec->extractCsrf($recOv['body']);
    $newSalePost = $rec->post('/petstore/receptionist/overview', [
        'csrf_token' => $recCsrf,
        'customer_type' => 'walkin',
        'customer_name' => "POS Guest {$suffix}",
        'payment_method' => 'Cash',
        'product_id' => [(string)$bpId],
        'quantity' => ['1'],
    ]);
    $createdPosSaleId = (int)$pdo->query("SELECT sale_id FROM sale WHERE business_unit_id = 1 ORDER BY sale_id DESC LIMIT 1")->fetchColumn();
    if ($createdPosSaleId > 0 && !in_array($createdPosSaleId, $createdSaleIds, true)) {
        $createdSaleIds[] = $createdPosSaleId;
        foreach ($pdo->query("SELECT payment_id FROM payment WHERE sale_id = {$createdPosSaleId}")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $createdPaymentIds[] = (int)$pid;
        }
    }
    $recOvAfterSale = $rec->get('/petstore/receptionist/overview');
    check(
        'Receptionist New Sale submission succeeds and appears in today recent sales',
        $newSalePost['status'] === 302
            && $createdPosSaleId > 0
            && str_contains($recOvAfterSale['body'], "#SALE-{$createdPosSaleId}")
    );

    // =================================================================
    // TEST GROUP 3: Receptionist Orders
    // =================================================================
    $recOrd = $rec->get('/petstore/receptionist/orders');
    $recOrdTable = extractMainTable($recOrd['body']);
    check(
        'Receptionist Orders includes today orders, active redelivery today, failed due today, and completed today (incl. redelivery)',
        $recOrd['status'] === 200
            && str_contains($recOrdTable, "#ORD-{$oTodayUnpaidCod}")
            && str_contains($recOrdTable, "#ORD-{$oTodayCompletedOnline}")
            && str_contains($recOrdTable, "#ORD-{$oPastRedeliveryActive}")
            && str_contains($recOrdTable, "#ORD-{$oPastFailedDueToday}")
            && str_contains($recOrdTable, "#ORD-{$oPastRedeliveryCompletedToday}")
    );
    check(
        'Receptionist Orders excludes past unrelated orders, future-day scheduled orders, and future-rescheduled failed orders',
        !str_contains($recOrdTable, "#ORD-{$oOldCompleted}")
            && !str_contains($recOrdTable, "#ORD-{$oFutureOrder}")
            && !str_contains($recOrdTable, "#ORD-{$oPastFutureRescheduled}")
    );
    check(
        'Order placed today and completed today appears exactly once (no duplicate rows)',
        substr_count($recOrdTable, "#ORD-{$oTodayCompletedOnline}") === 1
    );

    // =================================================================
    // TEST GROUP 4: Business Manager — Sales
    // =================================================================
    $salesWalk = $bm->get('/manager/sales?tab=walkin');
    $walkTable = extractMainTable($salesWalk['body']);
    check(
        'BM Sales Walk-in includes successful, partial, full, and transaction-recorded refunded walk-in sales',
        $salesWalk['status'] === 200
            && str_contains($walkTable, "#REC-S-{$saleTodayId}")
            && str_contains($walkTable, "#REC-S-{$saleTodayRefundedId}")
            && str_contains($walkTable, "#REC-S-{$salePastId}")
            && str_contains($walkTable, "#REC-S-{$salePartialRefundId}")
            && str_contains($walkTable, "#REC-S-{$saleRecordedRefundOnlyId}")
            && str_contains($walkTable, 'Partially Refunded')
    );
    $nonRefSaleModal = extractModal($salesWalk['body'], "walkin-modal-{$saleTodayId}");
    $refSaleModal = extractModal($salesWalk['body'], "walkin-modal-{$saleTodayRefundedId}");
    $partialSaleModal = extractModal($salesWalk['body'], "walkin-modal-{$salePartialRefundId}");
    $recordedSaleModal = extractModal($salesWalk['body'], "walkin-modal-{$saleRecordedRefundOnlyId}");
    $overSaleModal = extractModal($salesWalk['body'], "walkin-modal-{$saleOverRefundedId}");

    $nonRefSaleItemsBody = extractProductsTbody($nonRefSaleModal);
    $refSaleItemsBody = extractProductsTbody($refSaleModal);
    $partialSaleItemsBody = extractProductsTbody($partialSaleModal);
    $recordedSaleItemsBody = extractProductsTbody($recordedSaleModal);
    $overSaleItemsBody = extractProductsTbody($overSaleModal);

    check(
        'Walk-in non-refunded modal displays no refund notice or per-item refund badges',
        !str_contains($nonRefSaleModal, 'Refund Status')
            && !str_contains($nonRefSaleModal, 'refund-notice')
            && !str_contains($nonRefSaleModal, 'Refund Recorded')
            && !str_contains($nonRefSaleModal, 'Refunded')
            && str_contains($nonRefSaleItemsBody, "PSR Kibble {$suffix}")
    );
    check(
        'Walk-in full refund modal shows transaction-level Full Refund Recorded notice without marking individual product rows',
        !str_contains($refSaleModal, 'Refund Status')
            && str_contains($refSaleModal, 'refund-notice')
            && str_contains($refSaleModal, 'Full Refund Recorded')
            && str_contains($refSaleModal, '120.00 refunded of')
            && str_contains($refSaleModal, 'item-level refund attribution is not stored')
            && !str_contains($refSaleItemsBody, 'Refund')
            && !str_contains($refSaleItemsBody, 'badge-danger')
            && !str_contains($refSaleItemsBody, 'badge-warning')
    );
    check(
        'Walk-in partial refund modal shows Partial Refund Recorded notice (80.00 of 200.00) without attributing refund to either product row',
        !str_contains($partialSaleModal, 'Refund Status')
            && str_contains($partialSaleModal, 'Partially Refunded')
            && str_contains($partialSaleModal, 'Partial Refund Recorded')
            && str_contains($partialSaleModal, '80.00 refunded of')
            && str_contains($partialSaleModal, '200.00 paid')
            && str_contains($partialSaleModal, 'item-level refund attribution is not stored')
            && str_contains($partialSaleItemsBody, "PSR Kibble {$suffix}")
            && str_contains($partialSaleItemsBody, "PSR Leash {$suffix}")
            && !str_contains($partialSaleItemsBody, 'Refund')
            && !str_contains($partialSaleItemsBody, 'badge-danger')
            && !str_contains($partialSaleItemsBody, 'badge-warning')
    );
    check(
        'Walk-in transaction-level refund without refund amount row shows Refund Recorded notice without guessing partial vs full or items',
        !str_contains($recordedSaleModal, 'Refund Status')
            && str_contains($recordedSaleModal, 'Refund Recorded')
            && str_contains($recordedSaleModal, 'without a separate refund amount or item-level breakdown')
            && !str_contains($recordedSaleModal, 'Partial Refund Recorded')
            && !str_contains($recordedSaleModal, 'Full Refund Recorded')
            && str_contains($recordedSaleItemsBody, "PSR Kibble {$suffix}")
            && str_contains($recordedSaleItemsBody, "PSR Leash {$suffix}")
            && !str_contains($recordedSaleItemsBody, 'Refund')
    );
    check(
        'Walk-in over-refunded sale (250.00 > 200.00 paid) falls back to generic Refund Recorded with inconsistency reported instead of Full Refund',
        !str_contains($overSaleModal, 'Refund Status')
            && str_contains($overSaleModal, 'Refund Recorded')
            && !str_contains($overSaleModal, 'Full Refund Recorded')
            && !str_contains($overSaleModal, 'Partial Refund Recorded')
            && str_contains($overSaleModal, '250.00 refunded vs.')
            && str_contains($overSaleModal, '200.00 paid (inconsistent amount)')
            && str_contains($overSaleModal, 'Recorded refund total exceeds the original paid amount')
            && str_contains($overSaleItemsBody, "PSR Kibble {$suffix}")
            && str_contains($overSaleItemsBody, "PSR Leash {$suffix}")
            && !str_contains($overSaleItemsBody, 'Refund')
    );

    $salesDeliv = $bm->get('/manager/sales?tab=delivery');
    $delivTable = extractMainTable($salesDeliv['body']);
    check(
        'BM Sales Online — Delivery includes completed, partial-refunded, and full-refunded payments (excludes pending COD, failed payment, pickup)',
        $salesDeliv['status'] === 200
            && str_contains($delivTable, "#ORD-{$oTodayCompletedOnline}")
            && str_contains($delivTable, "#ORD-{$oPastRedeliveryActive}")
            && str_contains($delivTable, "#ORD-{$oPastRedeliveryCompletedToday}")
            && str_contains($delivTable, "#ORD-{$oDeliveryRefunded}")
            && str_contains($delivTable, "#ORD-{$oDeliveryPartialRefunded}")
            && str_contains($delivTable, "#ORD-{$oDeliveryFullMultiRefunded}")
            && str_contains($delivTable, 'Partially Refunded')
            && !str_contains($delivTable, "#ORD-{$oTodayUnpaidCod}")
            && !str_contains($delivTable, "#ORD-{$oFailedPayDelivery}")
            && !str_contains($delivTable, "#ORD-{$oPickupPaidInProgress}")
            && !str_contains($delivTable, "#ORD-{$oOldCompleted}")
    );

    $salesPickup = $bm->get('/manager/sales?tab=pickup');
    $pickupTable = extractMainTable($salesPickup['body']);
    check(
        'BM Sales Online — Pick-up includes completed-payment in-progress, COD awaiting receipt, past completed, and refunded pickup orders',
        $salesPickup['status'] === 200
            && str_contains($pickupTable, "#ORD-{$oPickupPaidInProgress}")
            && str_contains($pickupTable, "#ORD-{$oPickupCodPaidInProgress}")
            && str_contains($pickupTable, "#ORD-{$oOldCompleted}")
            && str_contains($pickupTable, "#ORD-{$oPickupRecordedRefundOnly}")
            && !str_contains($pickupTable, "#ORD-{$oTodayCompletedOnline}")
            && !str_contains($pickupTable, "#ORD-{$oFutureOrder}")
    );

    // Check independent order status & transaction-level refund indicators on Online Delivery & Pick-up
    $nonRefDelivModal = extractModal($salesDeliv['body'], "online-modal-{$oTodayCompletedOnline}");
    $refDelivModal = extractModal($salesDeliv['body'], "online-modal-{$oDeliveryRefunded}");
    $partialDelivModal = extractModal($salesDeliv['body'], "online-modal-{$oDeliveryPartialRefunded}");
    $fullMultiDelivModal = extractModal($salesDeliv['body'], "online-modal-{$oDeliveryFullMultiRefunded}");
    $overDelivModal = extractModal($salesDeliv['body'], "online-modal-{$oDeliveryOverRefunded}");
    $recordedPickupModal = extractModal($salesPickup['body'], "online-modal-{$oPickupRecordedRefundOnly}");

    $nonRefDelivItemsBody = extractProductsTbody($nonRefDelivModal);
    $refDelivItemsBody = extractProductsTbody($refDelivModal);
    $partialDelivItemsBody = extractProductsTbody($partialDelivModal);
    $fullMultiDelivItemsBody = extractProductsTbody($fullMultiDelivModal);
    $overDelivItemsBody = extractProductsTbody($overDelivModal);
    $recordedPickupItemsBody = extractProductsTbody($recordedPickupModal);

    $dbOrdRefundedStatus = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$oDeliveryRefunded}")->fetchColumn();
    check(
        'Online refunded order retains real order_status (not refunded) and shows transaction-level notice without Refund Status item column',
        $dbOrdRefundedStatus === 'completed'
            && !str_contains($delivTable, 'Refund Status')
            && !str_contains($delivTable, 'Full Refund Recorded')
            && !str_contains($nonRefDelivModal, 'Refund Status')
            && !str_contains($nonRefDelivModal, 'refund-notice')
            && !str_contains($nonRefDelivModal, 'Refunded')
            && !str_contains($nonRefDelivItemsBody, 'Refund')
            && !str_contains($refDelivModal, 'Refund Status')
            && str_contains($refDelivModal, 'Full Refund Recorded')
            && str_contains($refDelivModal, '170.00 refunded of')
            && preg_match('/badge-success">\s*Completed\s*</s', $refDelivModal) === 1
            && preg_match('/badge-danger">\s*Refunded\s*</s', $refDelivModal) === 1
            && !str_contains($refDelivItemsBody, 'Refund')
            && !str_contains($refDelivItemsBody, 'badge-danger')
    );
    check(
        'Online multi-item partial refund shows Partial Refund Recorded notice (80.00 of 250.00) without marking unrelated or amount-matching items',
        !str_contains($partialDelivModal, 'Refund Status')
            && str_contains($partialDelivModal, 'Partially Refunded')
            && str_contains($partialDelivModal, 'Partial Refund Recorded')
            && str_contains($partialDelivModal, '80.00 refunded of')
            && str_contains($partialDelivModal, '250.00 paid')
            && str_contains($partialDelivModal, 'item-level refund attribution is not stored')
            && preg_match('/badge-success">\s*Completed\s*</s', $partialDelivModal) === 1
            && str_contains($partialDelivItemsBody, "PSR Kibble {$suffix}")
            && str_contains($partialDelivItemsBody, "PSR Leash {$suffix}")
            && !str_contains($partialDelivItemsBody, 'Refund')
            && !str_contains($partialDelivItemsBody, 'badge-danger')
            && !str_contains($partialDelivItemsBody, 'badge-warning')
    );
    check(
        'Online multi-item full refund shows Full Refund Recorded notice (250.00 of 250.00) without per-item refund badges',
        !str_contains($fullMultiDelivModal, 'Refund Status')
            && str_contains($fullMultiDelivModal, 'Full Refund Recorded')
            && str_contains($fullMultiDelivModal, '250.00 refunded of')
            && str_contains($fullMultiDelivItemsBody, "PSR Kibble {$suffix}")
            && str_contains($fullMultiDelivItemsBody, "PSR Leash {$suffix}")
            && !str_contains($fullMultiDelivItemsBody, 'Refund')
            && !str_contains($fullMultiDelivItemsBody, 'badge-danger')
    );
    check(
        'Online transaction-level refund without child refund row shows Refund Recorded notice cleanly without per-item badges or guessed partial/full',
        !str_contains($recordedPickupModal, 'Refund Status')
            && str_contains($recordedPickupModal, 'Refund Recorded')
            && str_contains($recordedPickupModal, 'without a separate refund amount or item-level breakdown')
            && !str_contains($recordedPickupModal, 'Partial Refund Recorded')
            && !str_contains($recordedPickupModal, 'Full Refund Recorded')
            && preg_match('/badge-success">\s*Completed\s*</s', $recordedPickupModal) === 1
            && str_contains($recordedPickupItemsBody, "PSR Kibble {$suffix}")
            && str_contains($recordedPickupItemsBody, "PSR Leash {$suffix}")
            && !str_contains($recordedPickupItemsBody, 'Refund')
    );
    check(
        'Online over-refunded order (300.00 > 250.00 paid) falls back to generic Refund Recorded with inconsistency reported instead of Full Refund',
        !str_contains($overDelivModal, 'Refund Status')
            && str_contains($overDelivModal, 'Refund Recorded')
            && !str_contains($overDelivModal, 'Full Refund Recorded')
            && !str_contains($overDelivModal, 'Partial Refund Recorded')
            && str_contains($overDelivModal, '300.00 refunded vs.')
            && str_contains($overDelivModal, '250.00 paid (inconsistent amount)')
            && str_contains($overDelivModal, 'Recorded refund total exceeds the original paid amount')
            && preg_match('/badge-success">\s*Completed\s*</s', $overDelivModal) === 1
            && str_contains($overDelivItemsBody, "PSR Kibble {$suffix}")
            && str_contains($overDelivItemsBody, "PSR Leash {$suffix}")
            && !str_contains($overDelivItemsBody, 'Refund')
    );

    // =================================================================
    // TEST GROUP 5: Business Manager — Refunds
    // =================================================================
    $refAll = $bm->get('/manager/refunds');
    check(
        'BM Refunds page displays pending, approved, and rejected requests',
        $refAll['status'] === 200
            && str_contains($refAll['body'], "REF-PSR-1-{$suffix}")
            && str_contains($refAll['body'], "REF-PSR-2-{$suffix}")
            && str_contains($refAll['body'], "REF-PSR-3-{$suffix}")
    );

    $refTabRedirect = $bm->get('/manager/sales?tab=refunds');
    check('Legacy /manager/sales?tab=refunds redirects to /manager/refunds (302)', $refTabRedirect['status'] === 302 && str_starts_with($refTabRedirect['location'], '/manager/refunds'));

    // Sorting by date asc vs desc
    $refDateAsc = $bm->get('/manager/refunds?sort=date_asc')['body'];
    $posOld = strpos($refDateAsc, "REF-PSR-2-{$suffix}");
    $posMid = strpos($refDateAsc, "REF-PSR-3-{$suffix}");
    $posNew = strpos($refDateAsc, "REF-PSR-1-{$suffix}");
    check(
        'BM Refunds sorts by request date ascending accurately',
        $posOld !== false && $posMid !== false && $posNew !== false && $posOld < $posMid && $posMid < $posNew
    );

    // Sorting by requested amount desc
    $refAmtDesc = $bm->get('/manager/refunds?sort=amount_desc')['body'];
    $aPosHigh = strpos($refAmtDesc, "REF-PSR-2-{$suffix}");
    $aPosMid = strpos($refAmtDesc, "REF-PSR-3-{$suffix}");
    $aPosLow = strpos($refAmtDesc, "REF-PSR-1-{$suffix}");
    check(
        'BM Refunds sorts by requested amount descending accurately',
        $aPosHigh !== false && $aPosMid !== false && $aPosLow !== false && $aPosHigh < $aPosMid && $aPosMid < $aPosLow
    );

    // Search by customer name, customer ID, and order ID
    $byName = $bm->get('/manager/refunds?q=' . urlencode("TestBeta{$suffix}"))['body'];
    $byCustId = $bm->get('/manager/refunds?q=' . $custBobId)['body'];
    $byOrdId = $bm->get('/manager/refunds?q=' . $oOldCompleted)['body'];
    check(
        'BM Refunds search matches customer name, customer ID, and order ID',
        str_contains($byName, "REF-PSR-2-{$suffix}") && !str_contains($byName, "REF-PSR-1-{$suffix}")
            && str_contains($byCustId, "REF-PSR-2-{$suffix}") && !str_contains($byCustId, "REF-PSR-1-{$suffix}")
            && str_contains($byOrdId, "REF-PSR-3-{$suffix}") && !str_contains($byOrdId, "REF-PSR-1-{$suffix}")
    );

    // Approve pending refund request & confirm order status remains intact
    $refCsrf = $bm->extractCsrf($refAll['body']);
    $appPost = $bm->post("/manager/sales/refunds/{$refPendingSmall}/approve", ['csrf_token' => $refCsrf]);
    $appRow = $pdo->query("SELECT request_status, approved_amount FROM refund_request WHERE refund_request_id = {$refPendingSmall}")->fetch(PDO::FETCH_ASSOC);
    $ordStatusAfterApprove = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$oTodayCompletedOnline}")->fetchColumn();
    check(
        'BM Refunds approval succeeds, redirects to /manager/refunds, and preserves original order_status',
        $appPost['status'] === 302
            && str_contains($appPost['location'], '/manager/refunds')
            && $appRow['request_status'] === 'approved'
            && (float)$appRow['approved_amount'] === 25.00
            && $ordStatusAfterApprove === 'completed'
    );

    echo "\n====================================================================\n";
    echo "PET STORE SALES/REFUNDS/RECEPTIONIST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
    echo "====================================================================\n";
} finally {
    try {
        if ($createdRefundReqIds) {
            $ph = implode(',', array_map('intval', $createdRefundReqIds));
            $pdo->exec("DELETE FROM refund_request WHERE refund_request_id IN ({$ph})");
        }
        if ($createdPaymentIds) {
            $ph = implode(',', array_map('intval', $createdPaymentIds));
            $pdo->exec("DELETE FROM payment WHERE payment_id IN ({$ph}) AND original_payment_id IS NOT NULL");
            $pdo->exec("DELETE FROM payment WHERE payment_id IN ({$ph})");
        }
        if ($createdSaleIds) {
            $ph = implode(',', array_map('intval', $createdSaleIds));
            $pdo->exec("DELETE FROM inventory_movement WHERE sale_item_id IN (SELECT sale_item_id FROM sale_item WHERE sale_id IN ({$ph}))");
            $pdo->exec("DELETE FROM sale_item WHERE sale_id IN ({$ph})");
            $pdo->exec("DELETE FROM sale WHERE sale_id IN ({$ph})");
        }
        if ($createdOrderIds) {
            $ph = implode(',', array_map('intval', $createdOrderIds));
            $pdo->exec("DELETE FROM order_item WHERE order_id IN ({$ph})");
            $pdo->exec("DELETE FROM customer_order WHERE order_id IN ({$ph})");
        }
        if ($createdInvIds) {
            $ph = implode(',', array_map('intval', $createdInvIds));
            $pdo->exec("DELETE FROM inventory_movement WHERE inventory_id IN ({$ph})");
            $pdo->exec("DELETE FROM inventory WHERE inventory_id IN ({$ph})");
        }
        if ($createdBpIds) {
            $ph = implode(',', array_map('intval', $createdBpIds));
            $pdo->exec("DELETE FROM business_product WHERE business_product_id IN ({$ph})");
        }
        if ($createdProductIds) {
            $ph = implode(',', array_map('intval', $createdProductIds));
            $pdo->exec("DELETE FROM product WHERE product_id IN ({$ph})");
        }
        if ($createdUserIds) {
            $ph = implode(',', array_map('intval', $createdUserIds));
            $pdo->exec("DELETE FROM account_role WHERE user_account_id IN ({$ph})");
            $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$ph})");
            $pdo->exec("DELETE FROM customer WHERE user_account_id IN ({$ph})");
            $pdo->exec("DELETE FROM employee WHERE user_account_id IN ({$ph})");
            $pdo->exec("DELETE FROM user_account WHERE user_account_id IN ({$ph})");
        }
    } catch (Throwable $cleanupEx) {
        fwrite(STDERR, "Cleanup warning: {$cleanupEx->getMessage()}\n");
    }

    safelyTerminateAndClose($serverProc, $serverPipes, 2);
}

exit($failed > 0 ? 1 : 0);
