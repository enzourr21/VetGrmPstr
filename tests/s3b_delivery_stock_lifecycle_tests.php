<?php
declare(strict_types=1);

/**
 * PawCariño — Stage S3-B: Delivery Stock Lifecycle Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  1. Delivery dispatch (processing -> out_for_delivery) across non-batch and batch-tracked FEFO items:
 *     deducts inventory.on_hand and pinned inventory_batch.quantity, increments inventory.in_transit,
 *     retains inventory.committed, moves reservations active -> in_transit, logs order_dispatch movements,
 *     assigns courier, records audit, and preserves available stock / batch unreserved parity.
 *  2. Successful delivery completion (out_for_delivery -> completed):
 *     decreases inventory.in_transit and inventory.committed, leaves on_hand & batch quantities untouched,
 *     marks reservations fulfilled, logs order_fulfillment movements, records audit, leaves payment untouched.
 *  3. Duplicate dispatch & duplicate completion blocked cleanly with ERR_CONFLICT and zero side effects.
 *  4. Missing / incomplete / corrupted reservation coverage rejected on dispatch and completion.
 *  5. Insufficient stock / batch balances and payment-callback failure roll back completely.
 *  6. Failed delivery preserves in_transit stock/reservations (no restock, no fulfill, no redispatch).
 *  7. Business-unit isolation, pickup rejection, and courier ownership enforcement.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\CheckoutHoldService;
use App\Services\OrderReservationService;
use App\Services\PosSaleService;

$pdo = Database::pdo();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$allowedTestDatabases = ['vetgrpet_test'];
if ($dbName === '' || !in_array($dbName, $allowedTestDatabases, true)) {
    fwrite(STDERR, "Refusing to run against non-allowlisted database: {$dbName}\n");
    exit(1);
}

final class S3bFaultInjector
{
    public ?Closure $beforeExecute = null;
    public ?Closure $afterExecute = null;
}

final class S3bFailureInjectingStatement extends PDOStatement
{
    private S3bFaultInjector $injector;

    protected function __construct(S3bFaultInjector $injector)
    {
        $this->injector = $injector;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->injector->beforeExecute !== null) {
            ($this->injector->beforeExecute)($this->queryString, $params);
        }
        $result = parent::execute($params);
        if ($this->injector->afterExecute !== null) {
            ($this->injector->afterExecute)($this->queryString, $params);
        }
        return $result;
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

$service = new OrderReservationService($pdo);
$posService = new PosSaleService($pdo);
$checkoutService = new CheckoutHoldService($pdo);

$cleanup = [
    'sales' => [],
    'orders' => [],
    'products' => [],
    'branches' => [],
    'customers' => [],
    'employees' => [],
    'users' => [],
    'business_units' => [],
];

try {
    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkS3B Test Unit', 'active')");
    $unitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $unitId;

    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkS3B Foreign Unit', 'active')");
    $foreignUnitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $foreignUnitId;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, branch_status) VALUES ({$unitId}, 'ChkS3B Main Branch', 'active')");
    $branchId = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chks3b_cust@test.invalid', 'hash')");
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->exec("INSERT INTO customer (user_account_id, first_name, last_name) VALUES ({$custUid}, 'S3B', 'Customer')");
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chks3b_recep@test.invalid', 'hash')");
    $recepUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $recepUid;
    $pdo->exec("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position) VALUES ({$recepUid}, {$unitId}, 'S3B', 'Receptionist', 'Pet Store Receptionist')");
    $recepEmpId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $recepEmpId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chks3b_courier1@test.invalid', 'hash')");
    $courier1Uid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $courier1Uid;
    $pdo->exec("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position) VALUES ({$courier1Uid}, {$unitId}, 'Courier', 'One', 'Delivery Personnel')");
    $courier1EmpId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $courier1EmpId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chks3b_courier2@test.invalid', 'hash')");
    $courier2Uid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $courier2Uid;
    $pdo->exec("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position) VALUES ({$courier2Uid}, {$unitId}, 'Courier', 'Two', 'Delivery Personnel')");
    $courier2EmpId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $courier2EmpId;

    $createProduct = static function (string $name, int $onHand, int $committed, int $inTransit, int $batchTracked) use ($pdo, $unitId, &$cleanup): array {
        $stmtP = $pdo->prepare("INSERT INTO product (product_name, product_category) VALUES (?, 'Food')");
        $stmtP->execute([$name]);
        $prodId = (int)$pdo->lastInsertId();
        $cleanup['products'][] = $prodId;

        $stmtBp = $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status) VALUES (?, ?, 40.00, ?, 'active')");
        $stmtBp->execute([$unitId, $prodId, $batchTracked]);
        $bpId = (int)$pdo->lastInsertId();

        $stmtInv = $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, ?, ?, ?)");
        $stmtInv->execute([$bpId, $onHand, $committed, $inTransit]);
        $invId = (int)$pdo->lastInsertId();

        return [$bpId, $invId];
    };

    $createOrder = static function (string $method, string $status, array $lines, ?string $paymentStatus = 'pending', string $paymentMethod = 'cod') use ($pdo, $custId, $unitId, $branchId, &$cleanup): array {
        $total = 0.0;
        foreach ($lines as [$bpId, $qty]) {
            $total += 40.00 * $qty;
        }
        $pdo->prepare(
            "INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, subtotal_amount, delivery_fee, total_amount)
             VALUES (?, ?, ?, ?, ?, ?, 0.00, ?)"
        )->execute([$custId, $unitId, $branchId, $method, $status, $total, $total]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $orderId;

        $itemIds = [];
        $oiStmt = $pdo->prepare('INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, 40.00)');
        foreach ($lines as [$bpId, $qty]) {
            $oiStmt->execute([$orderId, $bpId, $qty]);
            $itemIds[] = (int)$pdo->lastInsertId();
        }

        if ($paymentStatus !== null) {
            $pdo->prepare(
                "INSERT INTO payment (order_id, payment_type, amount, payment_date, payment_method, payment_status)
                 VALUES (?, 'payment', ?, NOW(), ?, ?)"
            )->execute([$orderId, $total, $paymentMethod, $paymentStatus]);
        }

        return [$orderId, $itemIds];
    };

    $createBatch = static function (int $inventoryId, string $batchNumber, int $qty, int $daysAhead) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity)
             VALUES (?, ?, DATE_ADD(CURDATE(), INTERVAL ? DAY), ?)"
        );
        $stmt->execute([$inventoryId, $batchNumber, $daysAhead, $qty]);
        return (int)$pdo->lastInsertId();
    };

    // 1. Successful dispatch with non-batch and multi-batch FEFO inventory
    [$bpOrd, $invOrd] = $createProduct('ChkS3B NonBatch', 10, 0, 0, 0);
    [$bpBat, $invBat] = $createProduct('ChkS3B BatchFEFO', 10, 0, 0, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invBat}, 'S3B-B1', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 3)");
    $b1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invBat}, 'S3B-B2', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 7)");
    $b2 = (int)$pdo->lastInsertId();

    [$ord1, [$oiOrd1, $oiBat1]] = $createOrder('delivery', 'pending', [[$bpOrd, 4], [$bpBat, 5]]);
    $service->confirmDeliveryOrderAndReserve($ord1, $unitId, $recepUid);
    $service->markDeliveryOrderProcessing($ord1, $unitId, $recepUid);

    $dispRes = $service->dispatchDeliveryOrder($ord1, $unitId, $courier1EmpId, $courier1Uid);

    $ordAfterDisp = $pdo->query("SELECT order_status, delivery_employee_id FROM customer_order WHERE order_id = {$ord1}")->fetch();
    $invOrdDisp = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invOrd}")->fetch();
    $invBatDisp = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invBat}")->fetch();
    $b1AfterDisp = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$b1}")->fetchColumn();
    $b2AfterDisp = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$b2}")->fetchColumn();
    $resDispRows = $pdo->query("SELECT reservation_id, batch_id, reserved_quantity, reservation_status FROM inventory_reservation WHERE order_item_id IN ({$oiOrd1}, {$oiBat1}) ORDER BY reservation_id ASC")->fetchAll();
    $dispMovSum = (int)$pdo->query("SELECT COALESCE(SUM(im.quantity), 0) FROM inventory_movement im JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id WHERE ir.order_item_id IN ({$oiOrd1}, {$oiBat1}) AND im.movement_type = 'order_dispatch'")->fetchColumn();
    $payStatusAfterDisp = (string)$pdo->query("SELECT payment_status FROM payment WHERE order_id = {$ord1}")->fetchColumn();
    $availAfterDisp = $checkoutService->getEffectiveAvailabilityForProducts([$bpOrd, $bpBat]);

    check(
        'Dispatch moves on_hand (and pinned batch quantities) into in_transit, retains committed, updates reservations to in_transit, logs order_dispatch movements, preserves availability parity, and leaves COD payment pending',
        $dispRes['order_status'] === 'out_for_delivery'
            && $dispRes['dispatched_quantity'] === 9
            && $dispRes['reservations_dispatched'] === 3
            && $dispRes['movements_created'] === 3
            && ($ordAfterDisp['order_status'] ?? '') === 'out_for_delivery'
            && (int)$ordAfterDisp['delivery_employee_id'] === $courier1EmpId
            && (int)$invOrdDisp['on_hand'] === 6 && (int)$invOrdDisp['in_transit'] === 4 && (int)$invOrdDisp['committed'] === 4
            && (int)$invBatDisp['on_hand'] === 5 && (int)$invBatDisp['in_transit'] === 5 && (int)$invBatDisp['committed'] === 5
            && $b1AfterDisp === 0 && $b2AfterDisp === 5
            && count($resDispRows) === 3
            && $resDispRows[0]['reservation_status'] === 'in_transit'
            && $resDispRows[1]['reservation_status'] === 'in_transit'
            && $resDispRows[2]['reservation_status'] === 'in_transit'
            && $dispMovSum === -9
            && $payStatusAfterDisp === 'pending'
            && ($availAfterDisp[$bpOrd] ?? -1) === 6
            && ($availAfterDisp[$bpBat] ?? -1) === 5
    );

    // Verify post-dispatch POS parity: remaining 5 units on S3B-B2 are sellable without oversell
    $posOversellRejected = false;
    try {
        $posService->recordSale($unitId, $recepUid, $recepEmpId, null, 'Cash', [['business_product_id' => $bpBat, 'quantity' => 6]]);
    } catch (RuntimeException $e) {
        $posOversellRejected = $e->getCode() === PosSaleService::ERR_UNPROCESSABLE;
    }
    $posSaleOk = $posService->recordSale($unitId, $recepUid, $recepEmpId, null, 'Cash', [['business_product_id' => $bpBat, 'quantity' => 2]]);
    $cleanup['sales'][] = $posSaleOk['sale_id'];
    $b2AfterPos = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$b2}")->fetchColumn();
    check(
        'After dispatch deducts batch quantity, POS blocks overselling dispatched units and accurately deducts remaining shelf batch',
        $posOversellRejected && $b2AfterPos === 3
    );

    // 2. Duplicate dispatch blocked cleanly
    $dupDispRejected = false;
    try {
        $service->dispatchDeliveryOrder($ord1, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $dupDispRejected = $e->getCode() === OrderReservationService::ERR_CONFLICT;
    }
    $dispMovCnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory_movement im JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id WHERE ir.order_item_id IN ({$oiOrd1}, {$oiBat1}) AND im.movement_type = 'order_dispatch'")->fetchColumn();
    check('Duplicate dispatch rejected with ERR_CONFLICT and zero extra movements', $dupDispRejected && $dispMovCnt === 3);

    // 3. Courier ownership & unit isolation on completion before legitimate completion
    $wrongCourierComp = false;
    try {
        $service->completeDeliveryOrder($ord1, $unitId, $courier2EmpId, $courier2Uid);
    } catch (RuntimeException $e) {
        $wrongCourierComp = $e->getCode() === OrderReservationService::ERR_FORBIDDEN;
    }
    $foreignUnitComp = false;
    try {
        $service->completeDeliveryOrder($ord1, $foreignUnitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $foreignUnitComp = $e->getCode() === OrderReservationService::ERR_NOT_FOUND;
    }
    check('Completion enforces assigned courier ownership (ERR_FORBIDDEN) and business unit isolation (ERR_NOT_FOUND)', $wrongCourierComp && $foreignUnitComp);

    // 4. Successful delivery completion settles in_transit & committed WITHOUT re-deducting on_hand or batches
    $compRes = $service->completeDeliveryOrder($ord1, $unitId, $courier1EmpId, $courier1Uid);
    $ordAfterComp = $pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ord1}")->fetch();
    $invOrdComp = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invOrd}")->fetch();
    $invBatComp = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invBat}")->fetch();
    $b1AfterComp = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$b1}")->fetchColumn();
    $b2AfterComp = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$b2}")->fetchColumn();
    $fulResCnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory_reservation WHERE order_item_id IN ({$oiOrd1}, {$oiBat1}) AND reservation_status = 'fulfilled'")->fetchColumn();
    $fulMovSum = (int)$pdo->query("SELECT COALESCE(SUM(im.quantity), 0) FROM inventory_movement im JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id WHERE ir.order_item_id IN ({$oiOrd1}, {$oiBat1}) AND im.movement_type = 'order_fulfillment'")->fetchColumn();
    $payStatusAfterComp = (string)$pdo->query("SELECT payment_status FROM payment WHERE order_id = {$ord1}")->fetchColumn();

    check(
        'Delivery completion deducts in_transit and committed without double-deducting on_hand or batch quantities, marks reservations fulfilled, and preserves payment for S3-C',
        $compRes['order_status'] === 'completed'
            && $compRes['fulfilled_quantity'] === 9
            && $compRes['reservations_fulfilled'] === 3
            && ($ordAfterComp['order_status'] ?? '') === 'completed'
            && (int)$invOrdComp['on_hand'] === 6 && (int)$invOrdComp['in_transit'] === 0 && (int)$invOrdComp['committed'] === 0
            && (int)$invBatComp['on_hand'] === 3 && (int)$invBatComp['in_transit'] === 0 && (int)$invBatComp['committed'] === 0
            && $b1AfterComp === 0 && $b2AfterComp === 3
            && $fulResCnt === 3
            && $fulMovSum === -9
            && $payStatusAfterComp === 'pending'
    );

    // 5. Duplicate completion blocked cleanly
    $dupCompRejected = false;
    try {
        $service->completeDeliveryOrder($ord1, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $dupCompRejected = $e->getCode() === OrderReservationService::ERR_CONFLICT;
    }
    $fulMovCnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory_movement im JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id WHERE ir.order_item_id IN ({$oiOrd1}, {$oiBat1}) AND im.movement_type = 'order_fulfillment'")->fetchColumn();
    check('Duplicate completion rejected with ERR_CONFLICT and zero extra movements', $dupCompRejected && $fulMovCnt === 3);

    // 6. Rollback atomicity when dispatch pre-commit payment check fails (zero partial stock, batch, reservation, or order changes)
    [$bpRb, $invRb] = $createProduct('ChkS3B Rollback', 8, 0, 0, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invRb}, 'S3B-RB', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 8)");
    $bRb = (int)$pdo->lastInsertId();
    [$ordRb, [$oiRb]] = $createOrder('delivery', 'pending', [[$bpRb, 3]], 'failed', 'online');
    $service->confirmDeliveryOrderAndReserve($ordRb, $unitId, $recepUid);
    $service->markDeliveryOrderProcessing($ordRb, $unitId, $recepUid);

    $rbRejected = false;
    try {
        $service->dispatchDeliveryOrder($ordRb, $unitId, $courier1EmpId, $courier1Uid, static function (): void {
            throw new RuntimeException('Order payment status is failed and is not eligible for dispatch.', OrderReservationService::ERR_UNPROCESSABLE);
        });
    } catch (RuntimeException $e) {
        $rbRejected = $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE;
    }
    $ordAfterRb = $pdo->query("SELECT order_status, delivery_employee_id FROM customer_order WHERE order_id = {$ordRb}")->fetch();
    $invAfterRb = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invRb}")->fetch();
    $bRbAfter = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bRb}")->fetchColumn();
    $resRbStatus = (string)$pdo->query("SELECT reservation_status FROM inventory_reservation WHERE order_item_id = {$oiRb}")->fetchColumn();
    $rbMovCnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory_movement im JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id WHERE ir.order_item_id = {$oiRb}")->fetchColumn();
    check(
        'Transaction rolls back completely when payment/dispatch pre-check throws after stock validation',
        $rbRejected
            && ($ordAfterRb['order_status'] ?? '') === 'processing'
            && $ordAfterRb['delivery_employee_id'] === null
            && (int)$invAfterRb['on_hand'] === 8 && (int)$invAfterRb['committed'] === 3 && (int)$invAfterRb['in_transit'] === 0
            && $bRbAfter === 8
            && $resRbStatus === 'active'
            && $rbMovCnt === 0
    );

    // 7. Incomplete reservation & inconsistent stock/batch balance checks reject cleanly
    [$bpInc, $invInc] = $createProduct('ChkS3B Incomplete', 5, 3, 0, 0);
    [$ordInc, [$oiInc]] = $createOrder('delivery', 'processing', [[$bpInc, 3]]);
    $pdo->exec("INSERT INTO inventory_reservation (order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status) VALUES ({$oiInc}, {$invInc}, NULL, 2, 'active')");
    $incDispBlocked = false;
    try {
        $service->dispatchDeliveryOrder($ordInc, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $incDispBlocked = $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE;
    }

    [$bpLowBatch, $invLowBatch] = $createProduct('ChkS3B LowBatch', 5, 0, 0, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invLowBatch}, 'S3B-LB', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 5)");
    $bLow = (int)$pdo->lastInsertId();
    [$ordLowB, [$oiLowB]] = $createOrder('delivery', 'pending', [[$bpLowBatch, 4]]);
    $service->confirmDeliveryOrderAndReserve($ordLowB, $unitId, $recepUid);
    $service->markDeliveryOrderProcessing($ordLowB, $unitId, $recepUid);
    // Corrupt batch physical quantity below pinned reservation to prove dispatch aborts without partial inventory deduction
    $pdo->exec("UPDATE inventory_batch SET quantity = 2 WHERE batch_id = {$bLow}");
    $lowBatchBlocked = false;
    try {
        $service->dispatchDeliveryOrder($ordLowB, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $lowBatchBlocked = $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE;
    }
    $invLowAfter = $pdo->query("SELECT on_hand, in_transit, committed FROM inventory WHERE inventory_id = {$invLowBatch}")->fetch();

    // Missing order_dispatch movement on in_transit reservation rejected on completion
    [$bpNoMov, $invNoMov] = $createProduct('ChkS3B NoDispatchMov', 0, 2, 2, 0);
    [$ordNoMov, [$oiNoMov]] = $createOrder('delivery', 'out_for_delivery', [[$bpNoMov, 2]]);
    $pdo->prepare("UPDATE customer_order SET delivery_employee_id = ? WHERE order_id = ?")->execute([$courier1EmpId, $ordNoMov]);
    $pdo->exec("INSERT INTO inventory_reservation (order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status) VALUES ({$oiNoMov}, {$invNoMov}, NULL, 2, 'in_transit')");
    $noMovCompBlocked = false;
    try {
        $service->completeDeliveryOrder($ordNoMov, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $noMovCompBlocked = $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE;
    }

    check(
        'Incomplete reservation, insufficient pinned batch quantity, and missing dispatch movement reject cleanly without partial mutation',
        $incDispBlocked
            && $lowBatchBlocked
            && (int)$invLowAfter['on_hand'] === 5 && (int)$invLowAfter['in_transit'] === 0 && (int)$invLowAfter['committed'] === 4
            && $noMovCompBlocked
    );

    // 8. Failed delivery keeps stock in in_transit/committed (no restock, no fulfillment, no redispatch)
    [$bpFail, $invFail] = $createProduct('ChkS3B FailedDeliv', 6, 0, 0, 0);
    [$ordFail, [$oiFail]] = $createOrder('delivery', 'pending', [[$bpFail, 2]]);
    $service->confirmDeliveryOrderAndReserve($ordFail, $unitId, $recepUid);
    $service->markDeliveryOrderProcessing($ordFail, $unitId, $recepUid);
    $service->dispatchDeliveryOrder($ordFail, $unitId, $courier1EmpId, $courier1Uid);

    // Simulate DeliveryController::fail() status transition
    $pdo->prepare("UPDATE customer_order SET order_status = 'delivery_failed', delivery_failure_reason = 'Customer unreachable', delivery_failed_at = NOW() WHERE order_id = ?")
        ->execute([$ordFail]);

    $redispatchBlocked = false;
    try {
        $service->dispatchDeliveryOrder($ordFail, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $redispatchBlocked = $e->getCode() === OrderReservationService::ERR_CONFLICT;
    }
    $compFailedBlocked = false;
    try {
        $service->completeDeliveryOrder($ordFail, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $compFailedBlocked = $e->getCode() === OrderReservationService::ERR_CONFLICT;
    }
    $invFailAfter = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invFail}")->fetch();
    $resFailStatus = (string)$pdo->query("SELECT reservation_status FROM inventory_reservation WHERE order_item_id = {$oiFail}")->fetchColumn();

    check(
        'Failed delivery does not restock on_hand, does not fulfill reservation, and blocks both redispatch and completion',
        $redispatchBlocked
            && $compFailedBlocked
            && (int)$invFailAfter['on_hand'] === 4
            && (int)$invFailAfter['in_transit'] === 2
            && (int)$invFailAfter['committed'] === 2
            && $resFailStatus === 'in_transit'
    );

    // 9. Pickup order rejected from delivery dispatch & completion
    [$ordPick] = $createOrder('pickup', 'processing', [[$bpFail, 1]]);
    $pickupDispForbidden = false;
    try {
        $service->dispatchDeliveryOrder($ordPick, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $pickupDispForbidden = $e->getCode() === OrderReservationService::ERR_FORBIDDEN;
    }
    $pickupCompForbidden = false;
    try {
        $service->completeDeliveryOrder($ordPick, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $pickupCompForbidden = $e->getCode() === OrderReservationService::ERR_FORBIDDEN;
    }
    check('Delivery dispatch and completion strictly reject pickup orders (ERR_FORBIDDEN)', $pickupDispForbidden && $pickupCompForbidden);

    // 10. Expired pinned-batch protection: blocks dispatch without reallocating stock or mutating state
    [$bpExp, $invExp] = $createProduct('ChkS3B ExpiredBatch', 9, 0, 0, 1);
    $batchPinnedExp = $createBatch($invExp, 'B-PIN-EXP', 4, 3);
    $batchFreshAlt = $createBatch($invExp, 'B-FRESH-ALT', 5, 20);

    [$ordExp, [$oiExp]] = $createOrder('delivery', 'pending', [[$bpExp, 3]]);
    $service->confirmDeliveryOrderAndReserve($ordExp, $unitId, $recepUid);
    $service->markDeliveryOrderProcessing($ordExp, $unitId, $recepUid);

    // Age the pinned batch past expiration after reservation/preparation
    $pdo->prepare("UPDATE inventory_batch SET expiration_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE batch_id = ?")
        ->execute([$batchPinnedExp]);

    $expRejected = false;
    $expMsgHasReason = false;
    try {
        $service->dispatchDeliveryOrder($ordExp, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $expRejected = $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE;
        $expMsgHasReason = stripos($e->getMessage(), 'expired') !== false;
    }

    $ordExpAfter = $pdo->query("SELECT order_status, delivery_employee_id FROM customer_order WHERE order_id = {$ordExp}")->fetch();
    $invExpAfter = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invExp}")->fetch();
    $bPinnedQtyAfter = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$batchPinnedExp}")->fetchColumn();
    $bFreshQtyAfter = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$batchFreshAlt}")->fetchColumn();
    $resExpRows = $pdo->query("SELECT reservation_id, batch_id, reserved_quantity, reservation_status FROM inventory_reservation WHERE order_item_id = {$oiExp}")->fetchAll();
    $movExpCnt = (int)$pdo->query(
        "SELECT COUNT(*) FROM inventory_movement im
         JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id
         WHERE ir.order_item_id = {$oiExp}"
    )->fetchColumn();
    $auditExpDispatchCnt = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log WHERE target_type = 'customer_order' AND target_id = {$ordExp} AND action_code = 'delivery.dispatched'"
    )->fetchColumn();

    check(
        'Expired pinned batch blocks dispatch (ERR_UNPROCESSABLE) and preserves order, inventory, batches, reservation, movements, and audit without reallocation',
        $expRejected
            && $expMsgHasReason
            && $ordExpAfter['order_status'] === 'processing'
            && $ordExpAfter['delivery_employee_id'] === null
            && (int)$invExpAfter['on_hand'] === 9
            && (int)$invExpAfter['committed'] === 3
            && (int)$invExpAfter['in_transit'] === 0
            && $bPinnedQtyAfter === 4
            && $bFreshQtyAfter === 5
            && count($resExpRows) === 1
            && (int)$resExpRows[0]['batch_id'] === $batchPinnedExp
            && (int)$resExpRows[0]['reserved_quantity'] === 3
            && $resExpRows[0]['reservation_status'] === 'active'
            && $movExpCnt === 0
            && $auditExpDispatchCnt === 0
    );

    // 11. Mid-transaction failure injection: dispatch failure after stock/batch updates rolls back completely
    [$bpRbNb, $invRbNb] = $createProduct('ChkS3B Rollback NonBatch', 7, 0, 0, 0);
    [$bpRbB, $invRbB] = $createProduct('ChkS3B Rollback Batch', 8, 0, 0, 1);
    $batchRb = $createBatch($invRbB, 'B-RB-1', 8, 12);
    [$ordRb, [$oiRbNb, $oiRbB]] = $createOrder('delivery', 'pending', [[$bpRbNb, 2], [$bpRbB, 3]]);
    $service->confirmDeliveryOrderAndReserve($ordRb, $unitId, $recepUid);
    $service->markDeliveryOrderProcessing($ordRb, $unitId, $recepUid);

    $snapshotOrder = static function (int $orderId, array $invIds, array $batchIds) use ($pdo): array {
        $ord = $pdo->query("SELECT order_status, delivery_employee_id FROM customer_order WHERE order_id = {$orderId}")->fetch();
        $invList = implode(',', $invIds);
        $invs = $pdo->query("SELECT inventory_id, on_hand, committed, in_transit FROM inventory WHERE inventory_id IN ({$invList}) ORDER BY inventory_id ASC")->fetchAll();
        $bList = implode(',', $batchIds);
        $batches = $pdo->query("SELECT batch_id, quantity FROM inventory_batch WHERE batch_id IN ({$bList}) ORDER BY batch_id ASC")->fetchAll();
        $res = $pdo->query(
            "SELECT ir.reservation_id, ir.batch_id, ir.reserved_quantity, ir.reservation_status
             FROM inventory_reservation ir
             JOIN order_item oi ON oi.order_item_id = ir.order_item_id
             WHERE oi.order_id = {$orderId}
             ORDER BY ir.reservation_id ASC"
        )->fetchAll();
        $movs = $pdo->query(
            "SELECT im.movement_type, im.quantity, im.reservation_id
             FROM inventory_movement im
             JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id
             JOIN order_item oi ON oi.order_item_id = ir.order_item_id
             WHERE oi.order_id = {$orderId}
             ORDER BY im.movement_id ASC"
        )->fetchAll();
        $audits = $pdo->query(
            "SELECT action_code, target_id
             FROM audit_log
             WHERE target_type = 'customer_order' AND target_id = {$orderId}
             ORDER BY log_id ASC"
        )->fetchAll();
        return compact('ord', 'invs', 'batches', 'res', 'movs', 'audits');
    };

    $preDispatchSnap = $snapshotOrder($ordRb, [$invRbNb, $invRbB], [$batchRb]);

    $injector = new S3bFaultInjector();
    $faultyPdo = $openIsolatedPdo();
    $faultyPdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [S3bFailureInjectingStatement::class, [$injector]]);
    $faultyService = new OrderReservationService($faultyPdo);

    $seenDispBatchUpd = false;
    $seenDispInvUpd = false;
    $dispFaultCaught = false;
    $injector->afterExecute = static function (string $sql) use (&$seenDispBatchUpd, &$seenDispInvUpd): void {
        if (str_contains($sql, 'UPDATE inventory_batch') && str_contains($sql, 'quantity = quantity - ?')) {
            $seenDispBatchUpd = true;
        }
        if (str_contains($sql, 'UPDATE inventory') && str_contains($sql, 'in_transit = in_transit + ?')) {
            $seenDispInvUpd = true;
        }
    };
    $injector->beforeExecute = static function (string $sql) use (&$seenDispBatchUpd, &$seenDispInvUpd): void {
        if (
            str_contains($sql, 'UPDATE inventory_reservation')
            && str_contains($sql, "reservation_status = 'in_transit'")
            && $seenDispBatchUpd
            && $seenDispInvUpd
        ) {
            throw new PDOException('Injected mid-transaction failure after dispatch stock/batch updates');
        }
    };

    try {
        $faultyService->dispatchDeliveryOrder($ordRb, $unitId, $courier1EmpId, $courier1Uid);
    } catch (PDOException $e) {
        $dispFaultCaught = str_contains($e->getMessage(), 'Injected mid-transaction failure after dispatch');
    } finally {
        $injector->beforeExecute = null;
        $injector->afterExecute = null;
    }

    $postFailedDispSnap = $snapshotOrder($ordRb, [$invRbNb, $invRbB], [$batchRb]);
    check(
        'Mid-transaction dispatch failure after stock & batch updates rolls back order, inventory, batches, reservations, movements, and audit cleanly',
        $seenDispBatchUpd
            && $seenDispInvUpd
            && $dispFaultCaught
            && !$faultyPdo->inTransaction()
            && $postFailedDispSnap === $preDispatchSnap
    );

    // Confirm dispatch still succeeds cleanly after rollback
    $dispCleanAfterRb = $service->dispatchDeliveryOrder($ordRb, $unitId, $courier1EmpId, $courier1Uid);

    // 12. Mid-transaction failure injection: completion failure after inventory updates rolls back completely
    $preCompSnap = $snapshotOrder($ordRb, [$invRbNb, $invRbB], [$batchRb]);

    $seenCompInvUpd = false;
    $compFaultCaught = false;
    $injector->afterExecute = static function (string $sql) use (&$seenCompInvUpd): void {
        if (str_contains($sql, 'UPDATE inventory') && str_contains($sql, 'in_transit = in_transit - ?')) {
            $seenCompInvUpd = true;
        }
    };
    $injector->beforeExecute = static function (string $sql) use (&$seenCompInvUpd): void {
        if (
            str_contains($sql, 'UPDATE inventory_reservation')
            && str_contains($sql, "reservation_status = 'fulfilled'")
            && $seenCompInvUpd
        ) {
            throw new PDOException('Injected mid-transaction failure after completion inventory updates');
        }
    };

    try {
        $faultyService->completeDeliveryOrder($ordRb, $unitId, $courier1EmpId, $courier1Uid);
    } catch (PDOException $e) {
        $compFaultCaught = str_contains($e->getMessage(), 'Injected mid-transaction failure after completion');
    } finally {
        $injector->beforeExecute = null;
        $injector->afterExecute = null;
        $faultyPdo = null;
    }

    $postFailedCompSnap = $snapshotOrder($ordRb, [$invRbNb, $invRbB], [$batchRb]);
    $compCleanAfterRb = $service->completeDeliveryOrder($ordRb, $unitId, $courier1EmpId, $courier1Uid);

    check(
        'Mid-transaction completion failure after inventory updates rolls back order, inventory, batches, reservations, movements, and audit cleanly',
        $dispCleanAfterRb['order_status'] === 'out_for_delivery'
            && $seenCompInvUpd
            && $compFaultCaught
            && $postFailedCompSnap === $preCompSnap
            && $compCleanAfterRb['order_status'] === 'completed'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($cleanup['sales']) {
        $sList = implode(',', $cleanup['sales']);
        $pdo->exec("DELETE im FROM inventory_movement im JOIN sale_item si ON si.sale_item_id = im.sale_item_id WHERE si.sale_id IN ({$sList})");
        $pdo->exec("DELETE FROM payment WHERE sale_id IN ({$sList})");
        $pdo->exec("DELETE FROM audit_log WHERE target_type = 'sale' AND target_id IN ({$sList})");
        $pdo->exec("DELETE FROM sale_item WHERE sale_id IN ({$sList})");
        $pdo->exec("DELETE FROM sale WHERE sale_id IN ({$sList})");
    }
    if ($cleanup['orders']) {
        $oList = implode(',', $cleanup['orders']);
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
        $pdo->exec('DELETE FROM user_account WHERE user_account_id IN (' . implode(',', $cleanup['users']) . ')');
    }
    if ($cleanup['business_units']) {
        $pdo->exec('DELETE FROM audit_log WHERE business_unit_id IN (' . implode(',', $cleanup['business_units']) . ')');
        $pdo->exec('DELETE FROM business_unit WHERE business_unit_id IN (' . implode(',', $cleanup['business_units']) . ')');
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
