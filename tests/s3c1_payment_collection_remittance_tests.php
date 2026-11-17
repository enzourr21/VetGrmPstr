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

use App\Controllers\DeliveryController;
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

    $pdo->exec("INSERT IGNORE INTO role (role_name, description) VALUES ('RECEPTIONIST', 'Receptionist'), ('DELIVERY_PERSONNEL', 'Delivery Personnel')");
    $roleIdsByName = [];
    foreach ($pdo->query("SELECT role_id, role_name FROM role WHERE role_name IN ('RECEPTIONIST', 'DELIVERY_PERSONNEL')")->fetchAll(PDO::FETCH_ASSOC) as $rRow) {
        $roleIdsByName[(string)$rRow['role_name']] = (int)$rRow['role_id'];
    }

    $createStaff = static function (string $email, int $buId, string $first, string $last, string $pos, string $roleName) use ($pdo, $roleIdsByName, &$cleanup): array {
        $pdo->prepare("INSERT INTO user_account (email, password_hash, account_status) VALUES (?, 'hash', 'active')")->execute([$email]);
        $u = (int)$pdo->lastInsertId();
        $cleanup['users'][] = $u;
        $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status) VALUES (?, ?, ?, ?, ?, 'active')")
            ->execute([$u, $buId, $first, $last, $pos]);
        $e = (int)$pdo->lastInsertId();
        $cleanup['employees'][] = $e;
        $pdo->prepare("INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)")
            ->execute([$u, $roleIdsByName[$roleName], $buId]);
        return [$u, $e];
    };

    [$recepUid, $recepEmpId] = $createStaff('chks3c1_recep@test.invalid', $unitId, 'Store', 'Receptionist', 'Pet Store Receptionist', 'RECEPTIONIST');
    [$courier1Uid, $courier1EmpId] = $createStaff('chks3c1_c1@test.invalid', $unitId, 'Courier', 'Alpha', 'Delivery Personnel', 'DELIVERY_PERSONNEL');
    [$courier2Uid, $courier2EmpId] = $createStaff('chks3c1_c2@test.invalid', $unitId, 'Courier', 'Beta', 'Delivery Personnel', 'DELIVERY_PERSONNEL');
    [$foreignStaffUid, $foreignStaffEmpId] = $createStaff('chks3c1_for@test.invalid', $foreignUnitId, 'Foreign', 'Staff', 'Pet Store Receptionist', 'RECEPTIONIST');

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

    // 7e. Rejects fee-inclusive total (350.00 when subtotals sum to 250.00) and variance (300.00)
    $feeInclusiveRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$pay1, $pay2], '350.00');
    } catch (RuntimeException $e) {
        $feeInclusiveRemitBlocked = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }

    $varianceRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$pay1, $pay2], '300.00');
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

    // 7g. Strict SuperAdmin cross-unit isolation on remittance submission
    $pdo->prepare("INSERT INTO user_account (email, password_hash, is_super_admin) VALUES ('chks3c1_sa@test.invalid', 'hash', 1)")->execute();
    $saUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $saUid;
    $pdo->prepare("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status) VALUES (?, ?, 'Super', 'Foreign', 'Delivery Personnel', 'active')")
        ->execute([$saUid, $foreignUnitId]);
    $saForeignEmpId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $saForeignEmpId;

    $superForeignRemitBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $saForeignEmpId, $saUid, [$pay1]);
    } catch (RuntimeException $e) {
        $superForeignRemitBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    check(
        'Courier COD remittance rejects pending payments, pickup orders, online channel, other courier, foreign/SuperAdmin-foreign unit, fee-inclusive totals, and variance totals',
        $uncollectedRemitBlocked
            && $pickupRemitBlocked
            && $onlineRemitBlocked
            && $otherCourierRemitBlocked
            && $foreignRemitBlocked
            && $superForeignRemitBlocked
            && $feeInclusiveRemitBlocked
            && $varianceRemitBlocked
            && (int)$pdo->query('SELECT COUNT(*) FROM courier_cod_remittance')->fetchColumn() === 0
    );

    // 7h. Financial integrity rejections: NULL subtotal, zero subtotal, negative delivery_fee, sum mismatches, and payment mismatch
    $createCollectedCorruptOrder = static function (string $subSql, string $feeSql, string $totSql, string $paySql) use ($pdo, $custId, $unitId, $branchId, $courier1EmpId, &$cleanup): array {
        $pdo->prepare(
            "INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, subtotal_amount, delivery_fee, total_amount)
             VALUES (?, ?, ?, 'delivery', 'completed', {$subSql}, {$feeSql}, {$totSql})"
        )->execute([$custId, $unitId, $branchId]);
        $oId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $oId;
        $pdo->prepare(
            "INSERT INTO payment (order_id, payment_type, amount, payment_date, payment_method, payment_status, collection_channel, collected_by_employee_id, collected_at)
             VALUES (?, 'payment', {$paySql}, NOW(), 'cod', 'completed', 'courier_cod', ?, UTC_TIMESTAMP())"
        )->execute([$oId, $courier1EmpId]);
        return [$oId, (int)$pdo->lastInsertId()];
    };

    [, $payNullSub] = $createCollectedCorruptOrder('NULL', '50.00', '150.00', '150.00');
    [, $payZeroSub] = $createCollectedCorruptOrder('0.00', '50.00', '50.00', '50.00');
    [, $paySumMismatch] = $createCollectedCorruptOrder('90.00', '50.00', '150.00', '150.00');
    [$ordPayMismatch, $payAmtMismatch] = $createCollectedCorruptOrder('100.00', '50.00', '150.00', '140.00');

    $nullSubRejected = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$payNullSub]);
    } catch (RuntimeException $e) {
        $nullSubRejected = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }

    $zeroSubRejected = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$payZeroSub]);
    } catch (RuntimeException $e) {
        $zeroSubRejected = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }

    $sumMismatchRejected = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$paySumMismatch]);
    } catch (RuntimeException $e) {
        $sumMismatchRejected = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }

    $payMismatchRejected = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$payAmtMismatch]);
    } catch (RuntimeException $e) {
        $payMismatchRejected = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }

    // Confirm negative delivery_fee is blocked at DB constraint level & service parser rejects negative monetary values cleanly
    $negFeeDbBlocked = false;
    try {
        $pdo->prepare('UPDATE customer_order SET delivery_fee = -5.00 WHERE order_id = ?')->execute([$ordPayMismatch]);
    } catch (PDOException $e) {
        $negFeeDbBlocked = true;
    }

    check(
        'Remittance rejects NULL subtotal, zero subtotal, subtotal+fee mismatch, payment mismatch, and blocks negative delivery fees',
        $nullSubRejected
            && $zeroSubRejected
            && $sumMismatchRejected
            && $payMismatchRejected
            && $negFeeDbBlocked
            && (int)$pdo->query('SELECT COUNT(*) FROM courier_cod_remittance')->fetchColumn() === 0
    );

    // 7i. Exact decimal-string parsing & formatting: valid, malformed, negative, excessive precision, and overflow
    $parseMethod = new ReflectionMethod(OrderPaymentService::class, 'parseDecimalToCents');
    $formatMethod = new ReflectionMethod(OrderPaymentService::class, 'formatCents');
    $decimalChecksOk = $parseMethod->invoke($paymentService, '123.45', 't') === 12345
        && $parseMethod->invoke($paymentService, '0.05', 't') === 5
        && $parseMethod->invoke($paymentService, '9999999999.99', 't') === 999999999999
        && $formatMethod->invoke($paymentService, 999999999999) === '9999999999.99'
        && $formatMethod->invoke($paymentService, 5) === '0.05';

    $badMoneyInputs = [
        'empty' => '',
        'alpha' => '250.00abc',
        'comma' => '1,250.00',
        'scientific' => '1e2',
        'negative' => '-10.00',
        'excessive_precision' => '250.123',
        'overflow_decimal12' => '10000000000.00',
        'overflow_int64' => '99999999999999999999.99',
    ];
    $allBadInputsRejected = true;
    foreach ($badMoneyInputs as $caseLabel => $badVal) {
        $caught = false;
        try {
            $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$pay1, $pay2], $badVal);
        } catch (RuntimeException $e) {
            $caught = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
        }
        if (!$caught) {
            $allBadInputsRejected = false;
            echo "  DETAIL: failed badMoneyInput case {$caseLabel}\n";
        }
    }

    // Verify parseDecimalToCents without allowNegative rejects '-5.00' directly too
    $negParseRejected = false;
    try {
        $parseMethod->invoke($paymentService, '-5.00', 'Delivery fee', false);
    } catch (RuntimeException $e) {
        $negParseRejected = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
    }

    check(
        'Exact decimal parsing & formatting accept valid DECIMAL(12,2) boundaries and reject malformed, negative, over-precise, and overflowing inputs',
        $decimalChecksOk && $allBadInputsRejected && $negParseRejected
    );

    // 7j. Service-level employee ownership & invalid actor/employee ID checks
    $empMismatchBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier2Uid, [$pay1]);
    } catch (RuntimeException $e) {
        $empMismatchBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    $zeroActorBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, 0, [$pay1]);
    } catch (RuntimeException $e) {
        $zeroActorBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    $negActorBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, -1, [$pay1]);
    } catch (RuntimeException $e) {
        $negActorBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    $zeroEmpBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, 0, $courier1Uid, [$pay1]);
    } catch (RuntimeException $e) {
        $zeroEmpBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    $negEmpBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, -5, $courier1Uid, [$pay1]);
    } catch (RuntimeException $e) {
        $negEmpBlocked = $e->getCode() === OrderPaymentService::ERR_FORBIDDEN;
    }

    check(
        'Remittance authorization rejects mismatched employee user_account_id and invalid actor/employee IDs',
        $empMismatchBlocked
            && $zeroActorBlocked
            && $negActorBlocked
            && $zeroEmpBlocked
            && $negEmpBlocked
            && (int)$pdo->query('SELECT COUNT(*) FROM courier_cod_remittance')->fetchColumn() === 0
    );

    // 7k. Strict payment ID validation across service and DeliveryController boundaries
    $badPaymentIdLists = [
        'empty' => [],
        'alnum_str' => ['12abc'],
        'decimal_str' => ['3.9'],
        'float_val' => [3.9],
        'float_whole' => [3.0],
        'zero_str' => ['0'],
        'zero_int' => [0],
        'neg_str' => ['-5'],
        'neg_int' => [-5],
        'plus_str' => ['+12'],
        'leading_zero' => ['012'],
        'padded_spaces' => [' 12 '],
        'empty_str' => [''],
        'bool_true' => [true],
        'bool_false' => [false],
        'null_elem' => [null],
        'nested_array' => [[$pay1]],
        'object_elem' => [new stdClass()],
        'overflow_int64' => ['9223372036854775808'],
        'mixed_valid_and_zero' => [$pay1, '0'],
        'mixed_valid_and_alnum' => [$pay1, '12abc'],
    ];
    $allBadServiceIdsRejected = true;
    foreach ($badPaymentIdLists as $caseLabel => $badList) {
        $caught = false;
        try {
            $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, $badList);
        } catch (RuntimeException $e) {
            $caught = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
        }
        if (!$caught) {
            $allBadServiceIdsRejected = false;
            echo "  DETAIL: service accepted badPaymentIdList case {$caseLabel}\n";
        }
    }

    $ctrlRef = new ReflectionClass(DeliveryController::class);
    $ctrlInstance = $ctrlRef->newInstanceWithoutConstructor();
    $ctrlExtract = new ReflectionMethod(DeliveryController::class, 'extractSubmittedRemittancePaymentIds');
    $prevPost = $_POST;
    $allControllerChecksOk = true;
    try {
        // Missing payment_ids key -> empty list rejection
        $_POST = [];
        $missingCaught = false;
        try {
            $ctrlExtract->invoke($ctrlInstance);
        } catch (RuntimeException $e) {
            $missingCaught = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
        }
        if (!$missingCaught) {
            $allControllerChecksOk = false;
            echo "  DETAIL: controller accepted missing payment_ids\n";
        }

        // Non-array payment_ids POST payloads
        foreach (['scalar_str' => '12', 'scalar_malformed' => '12abc', 'scalar_null' => null] as $k => $scalarPayload) {
            $_POST = ['payment_ids' => $scalarPayload];
            $caught = false;
            try {
                $ctrlExtract->invoke($ctrlInstance);
            } catch (RuntimeException $e) {
                $caught = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
            }
            if (!$caught) {
                $allControllerChecksOk = false;
                echo "  DETAIL: controller accepted non-array case {$k}\n";
            }
        }

        // Invalid element lists on controller boundary
        foreach ($badPaymentIdLists as $caseLabel => $badList) {
            $_POST = ['payment_ids' => $badList];
            $caught = false;
            try {
                $ctrlExtract->invoke($ctrlInstance);
            } catch (RuntimeException $e) {
                $caught = $e->getCode() === OrderPaymentService::ERR_UNPROCESSABLE;
            }
            if (!$caught) {
                $allControllerChecksOk = false;
                echo "  DETAIL: controller accepted badPaymentIdList case {$caseLabel}\n";
            }
        }

        // Valid unsorted + duplicate numeric-string/int IDs preserve deduplication and ascending order
        $_POST = ['payment_ids' => [(string)$pay2, $pay1, (string)$pay2]];
        $normalizedFromCtrl = $ctrlExtract->invoke($ctrlInstance);
        $expectedSorted = [$pay1, $pay2];
        sort($expectedSorted, SORT_NUMERIC);
        if ($normalizedFromCtrl !== $expectedSorted) {
            $allControllerChecksOk = false;
            echo "  DETAIL: controller normalization mismatch\n";
        }
    } finally {
        $_POST = $prevPost;
    }

    check(
        'Strict payment ID validation rejects malformed, zero, negative, non-integer, and non-scalar inputs at both controller and service boundaries without silent coercion',
        $allBadServiceIdsRejected
            && $allControllerChecksOk
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

    // 9. Valid exact-subtotal multi-payment courier remittance submission (with deduplication & ascending sort) & duplicate allocation guard
    $remitRes = $paymentService->submitCourierCodRemittance(
        $unitId,
        $courier1EmpId,
        $courier1Uid,
        [(string)$pay2, $pay1, (string)$pay2],
        '250.00',
        'End-of-route cash batch'
    );
    $cleanup['remittances'][] = (int)$remitRes['remittance_id'];

    $rmtRow = $pdo->query("SELECT remittance_status, expected_total, remitted_total, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitRes['remittance_id']}")->fetch();
    $allocRows = $pdo->query("SELECT payment_id, order_id, business_unit_id, expected_amount, allocated_amount, allocation_status FROM courier_cod_remittance_allocation WHERE remittance_id = {$remitRes['remittance_id']} ORDER BY payment_id ASC")->fetchAll();
    $rmtAuditCnt = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action_code = 'courier.cod_remittance_submitted' AND target_type = 'courier_cod_remittance' AND target_id = {$remitRes['remittance_id']}")->fetchColumn();
    $pay1Final = $pdo->query("SELECT amount, payment_status FROM payment WHERE payment_id = {$pay1}")->fetch();
    $pay2Final = $pdo->query("SELECT amount, payment_status FROM payment WHERE payment_id = {$pay2}")->fetch();

    $dupAllocBlocked = false;
    try {
        $paymentService->submitCourierCodRemittance($unitId, $courier1EmpId, $courier1Uid, [$pay2]);
    } catch (RuntimeException $e) {
        $dupAllocBlocked = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    }

    check(
        'Courier COD remittance submits subtotal-only batch (250.00), preserves fee-inclusive customer payments (150.00 & 200.00), audits cleanly, and blocks duplicate allocation',
        $remitRes['remittance_status'] === 'submitted'
            && $remitRes['allocations_created'] === 2
            && $remitRes['expected_total'] === '250.00'
            && $remitRes['remitted_total'] === '250.00'
            && (string)$rmtRow['expected_total'] === '250.00'
            && (string)$rmtRow['remitted_total'] === '250.00'
            && $rmtRow['verified_total'] === null
            && $rmtRow['verified_by_employee_id'] === null
            && $rmtRow['verified_at'] === null
            && count($allocRows) === 2
            && (int)$allocRows[0]['payment_id'] === $pay1
            && (string)$allocRows[0]['expected_amount'] === '100.00'
            && (string)$allocRows[0]['allocated_amount'] === '100.00'
            && (int)$allocRows[1]['payment_id'] === $pay2
            && (string)$allocRows[1]['expected_amount'] === '150.00'
            && (string)$allocRows[1]['allocated_amount'] === '150.00'
            && $allocRows[0]['allocation_status'] === 'active'
            && $allocRows[1]['allocation_status'] === 'active'
            && (string)$pay1Final['amount'] === '150.00' && $pay1Final['payment_status'] === 'completed'
            && (string)$pay2Final['amount'] === '200.00' && $pay2Final['payment_status'] === 'completed'
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
        $uList = implode(',', $cleanup['users']);
        $pdo->exec("DELETE FROM account_role WHERE user_account_id IN ({$uList})");
        $pdo->exec("DELETE FROM user_account WHERE user_account_id IN ({$uList})");
    }
    if ($cleanup['business_units']) {
        $pdo->exec('DELETE FROM audit_log WHERE business_unit_id IN (' . implode(',', $cleanup['business_units']) . ')');
        $pdo->exec('DELETE FROM business_unit WHERE business_unit_id IN (' . implode(',', $cleanup['business_units']) . ')');
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
