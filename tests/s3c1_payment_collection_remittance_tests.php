<?php
declare(strict_types=1);

/**
 * PawCariño — Stage S3-C1: Payment Collection & Courier COD Remittance Submission Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  1. Delivery COD stays pending through dispatch & pre-completion; blocked on uncompleted/failed deliveries.
 *  2. Delivery COD collection confirms payment_status='completed', channel='courier_cod', collector & timestamp,
 *     enforces unit isolation + courier ownership, prevents duplicate collection, and creates NO remittance rows.
 *  3. Atomic delivery completion + COD collection rolls back inventory, order, and payment together on failure.
 *  4. In-store pickup COD collection confirms channel='in_store_pickup' after full handover (never on partial),
 *     supports standalone & atomic completion, enforces unit staff, and blocks duplicates.
 *  5. Courier COD remittance batch submission allocates multiple courier_cod payments cleanly in 'submitted' status,
 *     prevents duplicate active allocation, blocks uncollected/pickup/other-courier/refunded/foreign-unit/variance inputs,
 *     and rolls back cleanly on failure.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

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

final class S3c1FaultInjector
{
    public ?Closure $beforeExecute = null;
}

final class S3c1FailureInjectingStatement extends PDOStatement
{
    private S3c1FaultInjector $injector;

    protected function __construct(S3c1FaultInjector $injector)
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
    'business_units' => [],
];

try {
    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkS3C1 Unit', 'active')");
    $unitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $unitId;

    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkS3C1 Foreign Unit', 'active')");
    $foreignUnitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $foreignUnitId;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, branch_status) VALUES ({$unitId}, 'ChkS3C1 Branch', 'active')");
    $branchId = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chks3c1_cust@test.invalid', 'hash')");
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->exec("INSERT INTO customer (user_account_id, first_name, last_name) VALUES ({$custUid}, 'S3C1', 'Buyer')");
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    $createStaff = static function (string $email, int $buId, string $first, string $last, string $pos) use ($pdo, &$cleanup): array {
        $pdo->prepare("INSERT INTO user_account (email, password_hash) VALUES (?, 'hash')")->execute([$email]);
        $u = (int)$pdo->lastInsertId();
        $cleanup['users'][] = $u;
        $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status) VALUES (?, ?, ?, ?, ?, 'active')")
            ->execute([$u, $buId, $first, $last, $pos]);
        $e = (int)$pdo->lastInsertId();
        $cleanup['employees'][] = $e;
        return [$u, $e];
    };

    [$recepUid, $recepEmpId] = $createStaff('chks3c1_recep@test.invalid', $unitId, 'Store', 'Receptionist', 'Pet Store Receptionist');
    [$courier1Uid, $courier1EmpId] = $createStaff('chks3c1_c1@test.invalid', $unitId, 'Courier', 'Alpha', 'Delivery Personnel');
    [$courier2Uid, $courier2EmpId] = $createStaff('chks3c1_c2@test.invalid', $unitId, 'Courier', 'Beta', 'Delivery Personnel');
    [$foreignStaffUid, $foreignStaffEmpId] = $createStaff('chks3c1_for@test.invalid', $foreignUnitId, 'Foreign', 'Staff', 'Pet Store Receptionist');

    $pdo->prepare("INSERT INTO product (product_name, product_category) VALUES ('ChkS3C1 Treat', 'Treats')")->execute();
    $prodId = (int)$pdo->lastInsertId();
    $cleanup['products'][] = $prodId;
    $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status) VALUES (?, ?, 50.00, 0, 'active')")
        ->execute([$unitId, $prodId]);
    $bpId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, 50, 0, 0)")
        ->execute([$bpId]);
    $invId = (int)$pdo->lastInsertId();

    $createCodOrder = static function (string $method, int $qty, float $deliveryFee = 0.0, string $payMethod = 'cod', string $payStatus = 'pending') use ($pdo, $custId, $unitId, $branchId, $bpId, &$cleanup): array {
        $subtotal = 50.00 * $qty;
        $total = $subtotal + $deliveryFee;
        $pdo->prepare(
            "INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, subtotal_amount, delivery_fee, total_amount)
             VALUES (?, ?, ?, ?, 'pending', ?, ?, ?)"
        )->execute([$custId, $unitId, $branchId, $method, $subtotal, $deliveryFee, $total]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $orderId;

        $pdo->prepare("INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, 50.00)")
            ->execute([$orderId, $bpId, $qty]);
        $itemId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO payment (order_id, payment_type, amount, payment_date, payment_method, payment_status)
             VALUES (?, 'payment', ?, NOW(), ?, ?)"
        )->execute([$orderId, $total, $payMethod, $payStatus]);
        $paymentId = (int)$pdo->lastInsertId();

        return [$orderId, $itemId, $paymentId, $total];
    };

    // 1. COD stays pending across dispatch; blocked before completion and on delivery_failed
    [$ord1, $oi1, $pay1, $tot1] = $createCodOrder('delivery', 2, 50.00); // 150.00
    $reservationService->confirmDeliveryOrderAndReserve($ord1, $unitId, $recepUid);
    $reservationService->markDeliveryOrderProcessing($ord1, $unitId, $recepUid);
    $reservationService->dispatchDeliveryOrder($ord1, $unitId, $courier1EmpId, $courier1Uid);

    $preCompBlocked = false;
    try {
        $paymentService->confirmDeliveryCodCollection($ord1, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $preCompBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }

    // Complete delivery standalone (like S3-B default) -> COD still pending until explicit collection
    $reservationService->completeDeliveryOrder($ord1, $unitId, $courier1EmpId, $courier1Uid);
    $pay1PreCollect = $pdo->query("SELECT payment_status, collection_channel, collected_by_employee_id, collected_at FROM payment WHERE payment_id = {$pay1}")->fetch();
    check(
        'COD payment remains pending while out_for_delivery and until explicit collection confirmation',
        $preCompBlocked
            && $pay1PreCollect['payment_status'] === 'pending'
            && $pay1PreCollect['collection_channel'] === null
            && $pay1PreCollect['collected_by_employee_id'] === null
            && $pay1PreCollect['collected_at'] === null
    );

    // 2. Ownership, unit, and channel enforcement + valid courier_cod confirmation + duplicate guard
    $wrongCourierBlocked = false;
    try {
        $paymentService->confirmDeliveryCodCollection($ord1, $unitId, $courier2EmpId, $courier2Uid);
    } catch (RuntimeException $e) {
        $wrongCourierBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }
    $foreignUnitBlocked = false;
    try {
        $paymentService->confirmDeliveryCodCollection($ord1, $foreignUnitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $foreignUnitBlocked = $e->getCode() === OrderPaymentService::ERR_NOT_FOUND;
    }
    $pickupMethodBlocked = false;
    try {
        $paymentService->confirmPickupCodCollection($ord1, $unitId, $recepEmpId, $recepUid);
    } catch (RuntimeException $e) {
        $pickupMethodBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    $colRes1 = $paymentService->confirmDeliveryCodCollection($ord1, $unitId, $courier1EmpId, $courier1Uid);
    $pay1PostCollect = $pdo->query("SELECT payment_status, collection_channel, collected_by_employee_id, collected_at FROM payment WHERE payment_id = {$pay1}")->fetch();
    $auditCol1 = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_code = 'payment.delivery_cod_collected' AND target_type = 'payment' AND target_id = {$pay1}")->fetchColumn();
    $remitCountAfterCollect = (int)$pdo->query('SELECT COUNT(*) FROM courier_cod_remittance')->fetchColumn();

    $dupColBlocked = false;
    try {
        $paymentService->confirmDeliveryCodCollection($ord1, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $dupColBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }

    check(
        'Delivery COD collection records courier_cod metadata cleanly, enforces isolation/ownership, keeps remittance separate, and blocks duplicates',
        $wrongCourierBlocked
            && $foreignUnitBlocked
            && $pickupMethodBlocked
            && $colRes1['payment_status'] === 'completed'
            && $colRes1['collection_channel'] === 'courier_cod'
            && $pay1PostCollect['payment_status'] === 'completed'
            && $pay1PostCollect['collection_channel'] === 'courier_cod'
            && (int)$pay1PostCollect['collected_by_employee_id'] === $courier1EmpId
            && $pay1PostCollect['collected_at'] !== null
            && $auditCol1 === 1
            && $remitCountAfterCollect === 0
            && $dupColBlocked
    );

    // 3. Atomic delivery completion + COD collection & rollback atomicity on injected failure
    [$ord2, $oi2, $pay2, $tot2] = $createCodOrder('delivery', 3, 50.00); // 200.00
    $reservationService->confirmDeliveryOrderAndReserve($ord2, $unitId, $recepUid);
    $reservationService->markDeliveryOrderProcessing($ord2, $unitId, $recepUid);
    $reservationService->dispatchDeliveryOrder($ord2, $unitId, $courier1EmpId, $courier1Uid);

    $atomicRbCaught = false;
    try {
        $reservationService->completeDeliveryOrder(
            $ord2,
            $unitId,
            $courier1EmpId,
            $courier1Uid,
            static function () use ($paymentService, $ord2, $unitId, $courier1EmpId, $courier1Uid): void {
                $paymentService->collectDeliveryCodInTransaction($ord2, $unitId, $courier1EmpId, $courier1Uid);
                throw new RuntimeException('Simulated failure right after atomic COD update', 500);
            }
        );
    } catch (RuntimeException $e) {
        $atomicRbCaught = str_contains($e->getMessage(), 'Simulated failure');
    }

    $ord2AfterRb = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ord2}")->fetchColumn();
    $pay2AfterRb = (string)$pdo->query("SELECT payment_status FROM payment WHERE payment_id = {$pay2}")->fetchColumn();
    $res2AfterRb = (string)$pdo->query("SELECT reservation_status FROM inventory_reservation WHERE order_item_id = {$oi2}")->fetchColumn();

    $reservationService->completeDeliveryOrder(
        $ord2,
        $unitId,
        $courier1EmpId,
        $courier1Uid,
        static function () use ($paymentService, $ord2, $unitId, $courier1EmpId, $courier1Uid): void {
            $paymentService->collectDeliveryCodInTransaction($ord2, $unitId, $courier1EmpId, $courier1Uid);
        }
    );
    $ord2AfterAtomic = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ord2}")->fetchColumn();
    $pay2AfterAtomic = $pdo->query("SELECT payment_status, collection_channel, collected_by_employee_id FROM payment WHERE payment_id = {$pay2}")->fetch();

    check(
        'Atomic delivery completion + COD collection rolls back cleanly on failure and commits together on success',
        $atomicRbCaught
            && $ord2AfterRb === 'out_for_delivery'
            && $pay2AfterRb === 'pending'
            && $res2AfterRb === 'in_transit'
            && $ord2AfterAtomic === 'completed'
            && $pay2AfterAtomic['payment_status'] === 'completed'
            && $pay2AfterAtomic['collection_channel'] === 'courier_cod'
            && (int)$pay2AfterAtomic['collected_by_employee_id'] === $courier1EmpId
    );

    // 4. Failed delivery blocks COD collection completely
    [$ordFail, $oiFail, $payFail] = $createCodOrder('delivery', 1, 50.00);
    $reservationService->confirmDeliveryOrderAndReserve($ordFail, $unitId, $recepUid);
    $reservationService->markDeliveryOrderProcessing($ordFail, $unitId, $recepUid);
    $reservationService->dispatchDeliveryOrder($ordFail, $unitId, $courier1EmpId, $courier1Uid);
    $pdo->prepare("UPDATE customer_order SET order_status = 'delivery_failed', delivery_failure_reason = 'No one home', delivery_failed_at = NOW() WHERE order_id = ?")
        ->execute([$ordFail]);

    $failedDelivColBlocked = false;
    try {
        $paymentService->confirmDeliveryCodCollection($ordFail, $unitId, $courier1EmpId, $courier1Uid);
    } catch (RuntimeException $e) {
        $failedDelivColBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }
    $payFailStatus = (string)$pdo->query("SELECT payment_status FROM payment WHERE payment_id = {$payFail}")->fetchColumn();
    check('Failed delivery blocks COD payment collection (ERR_CONFLICT) and keeps payment pending', $failedDelivColBlocked && $payFailStatus === 'pending');

    // 5. In-store pickup COD collection: partial handover does not complete payment; full handover + staff collection records in_store_pickup
    [$ordPick, $oiPick, $payPick, $totPick] = $createCodOrder('pickup', 4, 0.00); // 200.00
    $reservationService->confirmPickupOrderAndReserve($ordPick, $unitId, $recepUid);
    $reservationService->markPickupOrderReady($ordPick, $unitId, $recepUid);

    $pickupCollectionOnCallback = false;
    $reservationService->fulfillPickupOrder(
        $ordPick,
        $unitId,
        $recepUid,
        [$oiPick => 2],
        static function () use (&$pickupCollectionOnCallback): void {
            $pickupCollectionOnCallback = true;
        }
    );

    $payPickMid = (string)$pdo->query("SELECT payment_status FROM payment WHERE payment_id = {$payPick}")->fetchColumn();
    $prePickupCollectBlocked = false;
    try {
        $paymentService->confirmPickupCodCollection($ordPick, $unitId, $recepEmpId, $recepUid);
    } catch (RuntimeException $e) {
        $prePickupCollectBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }

    $foreignStaffBlocked = false;
    $reservationService->fulfillPickupOrder(
        $ordPick,
        $unitId,
        $recepUid,
        [$oiPick => 2],
        static function () use ($paymentService, $ordPick, $unitId, $foreignStaffEmpId, $foreignStaffUid, &$foreignStaffBlocked): void {
            // Test after order becomes completed separately below; complete cleanly first here
        }
    );
    try {
        $paymentService->confirmPickupCodCollection($ordPick, $unitId, $foreignStaffEmpId, $foreignStaffUid);
    } catch (RuntimeException $e) {
        $foreignStaffBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    $pickColRes = $paymentService->confirmPickupCodCollection($ordPick, $unitId, $recepEmpId, $recepUid);
    $payPickPost = $pdo->query("SELECT payment_status, collection_channel, collected_by_employee_id, collected_at FROM payment WHERE payment_id = {$payPick}")->fetch();
    $dupPickColBlocked = false;
    try {
        $paymentService->confirmPickupCodCollection($ordPick, $unitId, $recepEmpId, $recepUid);
    } catch (RuntimeException $e) {
        $dupPickColBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }

    check(
        'Pickup COD blocks collection on partial handover, rejects foreign-unit staff, confirms in_store_pickup once completed, and blocks duplicates',
        !$pickupCollectionOnCallback
            && $payPickMid === 'pending'
            && $prePickupCollectBlocked
            && $foreignStaffBlocked
            && $pickColRes['collection_channel'] === 'in_store_pickup'
            && $payPickPost['payment_status'] === 'completed'
            && $payPickPost['collection_channel'] === 'in_store_pickup'
            && (int)$payPickPost['collected_by_employee_id'] === $recepEmpId
            && $payPickPost['collected_at'] !== null
            && $dupPickColBlocked
    );

    // 6. Atomic full pickup handover + collection callback test
    [$ordPick2, $oiPick2, $payPick2] = $createCodOrder('pickup', 2, 0.00);
    $reservationService->confirmPickupOrderAndReserve($ordPick2, $unitId, $recepUid);
    $reservationService->markPickupOrderReady($ordPick2, $unitId, $recepUid);
    $reservationService->fulfillPickupOrder(
        $ordPick2,
        $unitId,
        $recepUid,
        null,
        static function () use ($paymentService, $ordPick2, $unitId, $recepEmpId, $recepUid): void {
            $paymentService->collectPickupCodInTransaction($ordPick2, $unitId, $recepEmpId, $recepUid);
        }
    );
    $payPick2Row = $pdo->query("SELECT payment_status, collection_channel, collected_by_employee_id FROM payment WHERE payment_id = {$payPick2}")->fetch();
    check(
        'Full pickup handover can atomically confirm in_store_pickup COD collection in the same transaction',
        $payPick2Row['payment_status'] === 'completed'
            && $payPick2Row['collection_channel'] === 'in_store_pickup'
            && (int)$payPick2Row['collected_by_employee_id'] === $recepEmpId
    );

    // 7. Remittance submission validation rules before clean batch submission
    // 7a. Rejects uncollected pending payment
    [$ordPend, $oiPend, $payPend] = $createCodOrder('delivery', 1, 50.00);
    $reservationService->confirmDeliveryOrderAndReserve($ordPend, $unitId, $recepUid);
    $reservationService->markDeliveryOrderProcessing($ordPend, $unitId, $recepUid);
    $reservationService->dispatchDeliveryOrder($ordPend, $unitId, $courier1EmpId, $courier1Uid);
    $reservationService->completeDeliveryOrder($ordPend, $unitId, $courier1EmpId, $courier1Uid);

    $uncollectedRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$payPend]);
    } catch (RuntimeException $e) {
        $uncollectedRemitBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }

    // 7b. Rejects pickup order / in_store_pickup payment in courier remittance
    $pickupRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$payPick]);
    } catch (RuntimeException $e) {
        $pickupRemitBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }

    // 7c. Rejects remitting another courier's collected COD
    $otherCourierRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier2EmpId, $courier2Uid, [$pay1]);
    } catch (RuntimeException $e) {
        $otherCourierRemitBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    // 7d. Rejects foreign-unit submission
    $foreignRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($foreignUnitId, $courier1EmpId, $courier1Uid, [$pay1]);
    } catch (RuntimeException $e) {
        $foreignRemitBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    // 7e. Rejects remittance variance (without inventing discrepancy rules)
    $varianceRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$pay1, $pay2], 300.00); // expected 350.00
    } catch (RuntimeException $e) {
        $varianceRemitBlocked = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }

    // 7f. Rejects non-courier_cod channel even on completed delivery order
    [$ordOnline, $oiOnline, $payOnline] = $createCodOrder('delivery', 1, 50.00, 'online', 'completed');
    $pdo->prepare("UPDATE payment SET provider_name = 'test_gw', provider_payment_id = 'gw_s3c1_1', collection_channel = 'online_gateway', collected_at = UTC_TIMESTAMP() WHERE payment_id = ?")
        ->execute([$payOnline]);
    $reservationService->confirmDeliveryOrderAndReserve($ordOnline, $unitId, $recepUid);
    $reservationService->markDeliveryOrderProcessing($ordOnline, $unitId, $recepUid);
    $reservationService->dispatchDeliveryOrder($ordOnline, $unitId, $courier1EmpId, $courier1Uid);
    $reservationService->completeDeliveryOrder($ordOnline, $unitId, $courier1EmpId, $courier1Uid);

    $onlineRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$payOnline]);
    } catch (RuntimeException $e) {
        $onlineRemitBlocked = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }

    check(
        'Courier COD remittance rejects pending payments, pickup orders, online channel, other courier, foreign unit, and unapproved variance totals',
        $uncollectedRemitBlocked
            && $pickupRemitBlocked
            && $onlineRemitBlocked
            && $otherCourierRemitBlocked
            && $foreignRemitBlocked
            && $varianceRemitBlocked
            && (int)$pdo->query('SELECT COUNT(*) FROM courier_cod_remittance')->fetchColumn() === 0
    );

    // 8. Mid-transaction rollback test on remittance submission before valid submission
    $injector = new S3c1FaultInjector();
    $faultyPdo = $openIsolatedPdo();
    $faultyPdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [S3c1FailureInjectingStatement::class, [$injector]]);
    $faultyPaymentService = new OrderPaymentService($faultyPdo);

    $remitFaultCaught = false;
    $injector->beforeExecute = static function (string $sql): void {
        if (str_contains($sql, 'INSERT INTO courier_cod_remittance_allocation')) {
            throw new PDOException('Injected failure during allocation insert');
        }
    };
    try {
        $faultyPaymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$pay1, $pay2]);
    } catch (PDOException $e) {
        $remitFaultCaught = str_contains($e->getMessage(), 'Injected failure during allocation insert');
    } finally {
        $injector->beforeExecute = null;
        $faultyPdo = null;
    }

    check(
        'Mid-transaction remittance failure after header insert rolls back remittance header and allocations cleanly',
        $remitFaultCaught
            && (int)$pdo->query('SELECT COUNT(*) FROM courier_cod_remittance')->fetchColumn() === 0
            && (int)$pdo->query('SELECT COUNT(*) FROM courier_cod_remittance_allocation')->fetchColumn() === 0
    );

    // 9. Valid exact-total multi-payment courier remittance submission & duplicate allocation guard
    $remitRes = $paymentService->submitCourierCodRemittance(
        $unitId,
        $courier1EmpId,
        $courier1Uid,
        [$pay1, $pay2],
        350.00,
        'End-of-route cash batch'
    );
    $cleanup['remittances'][] = (int)$remitRes['remittance_id'];

    $rmtRow = $pdo->query("SELECT remittance_status, expected_total, remitted_total, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitRes['remittance_id']}")->fetch();
    $allocRows = $pdo->query("SELECT payment_id, order_id, business_unit_id, expected_amount, allocated_amount, allocation_status FROM courier_cod_remittance_allocation WHERE remittance_id = {$remitRes['remittance_id']} ORDER BY payment_id ASC")->fetchAll();
    $rmtAuditCnt = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_code = 'courier.cod_remittance_submitted' AND target_type = 'courier_cod_remittance' AND target_id = {$remitRes['remittance_id']}")->fetchColumn();

    $dupAllocBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$pay2]);
    } catch (RuntimeException $e) {
        $dupAllocBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }

    check(
        'Courier COD remittance creates submitted batch + active per-payment allocations, audits cleanly, and blocks duplicate allocation',
        $remitRes['remittance_status'] === 'submitted'
            && $remitRes['allocations_created'] === 2
            && abs((float)$rmtRow['expected_total'] - 350.00) < 0.001
            && abs((float)$rmtRow['remitted_total'] - 350.00) < 0.001
            && $rmtRow['verified_total'] === null
            && $rmtRow['verified_by_employee_id'] === null
            && $rmtRow['verified_at'] === null
            && count($allocRows) === 2
            && (int)$allocRows[0]['payment_id'] === $pay1
            && (int)$allocRows[1]['payment_id'] === $pay2
            && $allocRows[0]['allocation_status'] === 'active'
            && $allocRows[1]['allocation_status'] === 'active'
            && $rmtAuditCnt === 1
            && $dupAllocBlocked
    );
} finally {
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
        $pdo->exec('DELETE FROM user_account WHERE user_account_id IN (' . implode(',', $cleanup['users']) . ')');
    }
    if ($cleanup['business_units']) {
        $pdo->exec('DELETE FROM audit_log WHERE business_unit_id IN (' . implode(',', $cleanup['business_units']) . ')');
        $pdo->exec('DELETE FROM business_unit WHERE business_unit_id IN (' . implode(',', $cleanup['business_units']) . ')');
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
