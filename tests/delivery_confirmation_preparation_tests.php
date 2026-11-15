<?php
declare(strict_types=1);

/**
 * PawCariño — Checkpoint E1: Delivery Order Confirmation & Preparation Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies store-side delivery acceptance (pending -> confirmed + FEFO reservation),
 * store preparation (confirmed -> processing), strict fulfillment-method boundaries,
 * dispatch pre-check blocking pending and unprepared confirmed orders, and rollback/unit safety.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
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

$service = new OrderReservationService($pdo);
$cleanup = [
    'orders' => [],
    'products' => [],
    'branches' => [],
    'customers' => [],
    'employees' => [],
    'users' => [],
    'business_units' => [],
];

try {
    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_e1_cust@test.invalid', 'hash')");
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->exec("INSERT INTO customer (user_account_id, first_name, last_name) VALUES ({$custUid}, 'Delivery', 'Customer')");
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_e1_emp@test.invalid', 'hash')");
    $empUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $empUid;
    $pdo->exec("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position) VALUES ({$empUid}, 1, 'Store', 'Receptionist', 'Pet Store Receptionist')");
    $empId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $empId;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name) VALUES (1, 'Checkpoint E1 Branch')");
    $branchId = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchId;

    $createGlobalProduct = static function (string $name) use ($pdo, &$cleanup): int {
        $stmtP = $pdo->prepare("INSERT INTO product (product_name, product_category) VALUES (?, 'Food')");
        $stmtP->execute([$name]);
        $prodId = (int)$pdo->lastInsertId();
        $cleanup['products'][] = $prodId;
        return $prodId;
    };

    $addBusinessProduct = static function (int $prodId, int $unitId, int $onHand, int $committed, int $inTransit, int $batchTracked) use ($pdo): array {
        $stmtBp = $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking) VALUES (?, ?, 120.00, ?)");
        $stmtBp->execute([$unitId, $prodId, $batchTracked]);
        $bpId = (int)$pdo->lastInsertId();

        $stmtInv = $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, ?, ?, ?)");
        $stmtInv->execute([$bpId, $onHand, $committed, $inTransit]);
        $invId = (int)$pdo->lastInsertId();

        return [$bpId, $invId];
    };

    $createProduct = static function (string $name, int $onHand, int $committed, int $inTransit, int $batchTracked) use ($createGlobalProduct, $addBusinessProduct): array {
        $prodId = $createGlobalProduct($name);
        return $addBusinessProduct($prodId, 1, $onHand, $committed, $inTransit, $batchTracked);
    };

    $createBatch = static function (int $invId, string $batchNo, ?string $expDate, int $qty) use ($pdo): int {
        $stmt = $pdo->prepare("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES (?, ?, ?, ?)");
        $stmt->execute([$invId, $batchNo, $expDate, $qty]);
        return (int)$pdo->lastInsertId();
    };

    $createOrder = static function (string $method, string $status, array $lines, int $unitId = 1) use ($pdo, $custId, $branchId, &$cleanup): array {
        $stmt = $pdo->prepare(
            "INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, delivery_address_line1, delivery_city, total_amount)
             VALUES (?, ?, ?, ?, ?, '123 Pet St', 'Makati', 240.00)"
        );
        $stmt->execute([$custId, $unitId, $branchId, $method, $status]);
        $ordId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $ordId;

        $oiIds = [];
        $stmtOi = $pdo->prepare("INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, 120.00)");
        foreach ($lines as [$bpId, $qty]) {
            $stmtOi->execute([$ordId, $bpId, $qty]);
            $oiIds[] = (int)$pdo->lastInsertId();
        }
        return [$ordId, $oiIds];
    };

    // 1. Pending delivery order creates no reservation and cannot be dispatched early
    [$bpNonBatch, $invNonBatch] = $createProduct('ChkE1 Leash', 10, 0, 0, 0);
    [$bpBatch, $invBatch] = $createProduct('ChkE1 Treats', 12, 0, 0, 1);
    $bExpired = $createBatch($invBatch, 'E1-EXP', date('Y-m-d', strtotime('-5 days')), 4);
    $bSoon = $createBatch($invBatch, 'E1-SOON', date('Y-m-d', strtotime('+10 days')), 3);
    $bLater = $createBatch($invBatch, 'E1-LATER', date('Y-m-d', strtotime('+45 days')), 3);
    $bNull = $createBatch($invBatch, 'E1-NULL', null, 2);

    [$ordDeliv, [$oiNonBatch, $oiBatch]] = $createOrder('delivery', 'pending', [[$bpNonBatch, 4], [$bpBatch, 5]]);

    $pdo->beginTransaction();
    try {
        $service->assertDeliveryOrderReservedForDispatch($ordDeliv, 1);
        $pdo->rollBack();
        check('Pending delivery order rejected by dispatch pre-check', false);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        check('Pending delivery order rejected by dispatch pre-check', $e->getCode() === OrderReservationService::ERR_CONFLICT);
    }

    // 2. Pickup confirmation still rejects delivery orders; delivery confirmation rejects pickup orders
    try {
        $service->confirmPickupOrderAndReserve($ordDeliv, 1, $empUid);
        check('confirmPickupOrderAndReserve strictly rejects delivery orders', false);
    } catch (RuntimeException $e) {
        check('confirmPickupOrderAndReserve strictly rejects delivery orders', $e->getCode() === OrderReservationService::ERR_FORBIDDEN);
    }

    [$ordPickup, [$oiPickup]] = $createOrder('pickup', 'pending', [[$bpNonBatch, 2]]);
    try {
        $service->confirmDeliveryOrderAndReserve($ordPickup, 1, $empUid);
        check('confirmDeliveryOrderAndReserve strictly rejects pickup orders', false);
    } catch (RuntimeException $e) {
        check('confirmDeliveryOrderAndReserve strictly rejects pickup orders', $e->getCode() === OrderReservationService::ERR_FORBIDDEN);
    }

    // 3. Store confirmation of pending delivery order creates FEFO reservations & updates committed atomically
    $confirmRes = $service->confirmDeliveryOrderAndReserve($ordDeliv, 1, $empUid);
    $ordRowAfterConfirm = $pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordDeliv}")->fetch();
    $invNonBatchAfter = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invNonBatch}")->fetch();
    $invBatchAfter = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invBatch}")->fetch();

    $batchResRows = $pdo->query(
        "SELECT batch_id, reserved_quantity, reservation_status
         FROM inventory_reservation
         WHERE order_item_id = {$oiBatch}
         ORDER BY reservation_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $nonBatchResRows = $pdo->query(
        "SELECT batch_id, reserved_quantity, reservation_status
         FROM inventory_reservation
         WHERE order_item_id = {$oiNonBatch}
         ORDER BY reservation_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $auditConfirmCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
         WHERE target_type = 'customer_order' AND target_id = {$ordDeliv}
           AND action_code = 'petstore_receptionist.order.delivery_status'
           AND old_value = 'status:pending' AND new_value = 'status:confirmed'"
    )->fetchColumn();

    check(
        'Delivery confirm transitions pending->confirmed, pins FEFO non-expired batches, increases committed, keeps on_hand/in_transit intact',
        $confirmRes['total_reserved_quantity'] === 9
            && $confirmRes['reservations_created'] === 3
            && ($ordRowAfterConfirm['order_status'] ?? '') === 'confirmed'
            && (int)$invNonBatchAfter['on_hand'] === 10
            && (int)$invNonBatchAfter['committed'] === 4
            && (int)$invNonBatchAfter['in_transit'] === 0
            && (int)$invBatchAfter['on_hand'] === 12
            && (int)$invBatchAfter['committed'] === 5
            && (int)$invBatchAfter['in_transit'] === 0
            && count($nonBatchResRows) === 1
            && $nonBatchResRows[0]['batch_id'] === null
            && (int)$nonBatchResRows[0]['reserved_quantity'] === 4
            && $nonBatchResRows[0]['reservation_status'] === 'active'
            && count($batchResRows) === 2
            && (int)$batchResRows[0]['batch_id'] === $bSoon
            && (int)$batchResRows[0]['reserved_quantity'] === 3
            && (int)$batchResRows[1]['batch_id'] === $bLater
            && (int)$batchResRows[1]['reserved_quantity'] === 2
            && $auditConfirmCount === 1
    );

    // 4. Duplicate confirmation and unprepared confirmed dispatch are rejected
    try {
        $service->confirmDeliveryOrderAndReserve($ordDeliv, 1, $empUid);
        check('Confirmed delivery order cannot be confirmed twice', false);
    } catch (RuntimeException $e) {
        check('Confirmed delivery order cannot be confirmed twice', $e->getCode() === OrderReservationService::ERR_CONFLICT);
    }

    $pdo->beginTransaction();
    try {
        $service->assertDeliveryOrderReservedForDispatch($ordDeliv, 1);
        $pdo->rollBack();
        check('Confirmed but unprepared delivery order rejected by dispatch pre-check', false);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        check('Confirmed but unprepared delivery order rejected by dispatch pre-check', $e->getCode() === OrderReservationService::ERR_CONFLICT);
    }

    // 5. Store preparation (confirmed -> processing) verifies reservations and allows dispatch pre-check
    $prepRes = $service->markDeliveryOrderProcessing($ordDeliv, 1, $empUid);
    $ordRowAfterPrep = $pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordDeliv}")->fetch();
    $auditPrepCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM audit_log
         WHERE target_type = 'customer_order' AND target_id = {$ordDeliv}
           AND action_code = 'petstore_receptionist.order.delivery_status'
           AND old_value = 'status:confirmed' AND new_value = 'status:processing'"
    )->fetchColumn();

    $pdo->beginTransaction();
    $dispatchPrecheckOk = true;
    try {
        $service->assertDeliveryOrderReservedForDispatch($ordDeliv, 1);
        $pdo->commit();
    } catch (Throwable $e) {
        $dispatchPrecheckOk = false;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    check(
        'markDeliveryOrderProcessing moves confirmed->processing with audit log and passes dispatch pre-check',
        $prepRes['order_status'] === 'processing'
            && ($ordRowAfterPrep['order_status'] ?? '') === 'processing'
            && $auditPrepCount === 1
            && $dispatchPrecheckOk
    );

    // 6. Preparation rejects confirmed delivery orders without complete active reservations
    [$ordNoRes] = $createOrder('delivery', 'confirmed', [[$bpNonBatch, 1]]);
    try {
        $service->markDeliveryOrderProcessing($ordNoRes, 1, $empUid);
        check('markDeliveryOrderProcessing rejects confirmed order missing reservation', false);
    } catch (RuntimeException $e) {
        $stNoRes = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordNoRes}")->fetchColumn();
        check(
            'markDeliveryOrderProcessing rejects confirmed order missing reservation and keeps confirmed status',
            $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE && $stNoRes === 'confirmed'
        );
    }

    [$ordNonActiveRes, [$oiNonActive]] = $createOrder('delivery', 'confirmed', [[$bpNonBatch, 2]]);
    $pdo->exec("INSERT INTO inventory_reservation (order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status) VALUES ({$oiNonActive}, {$invNonBatch}, NULL, 2, 'fulfilled')");
    try {
        $service->markDeliveryOrderProcessing($ordNonActiveRes, 1, $empUid);
        check('markDeliveryOrderProcessing rejects non-active reservation status', false);
    } catch (RuntimeException $e) {
        check('markDeliveryOrderProcessing rejects non-active reservation status', $e->getCode() === OrderReservationService::ERR_CONFLICT);
    }

    // 7. Dispatch pre-check rejects processing delivery order if active reservation coverage is incomplete
    [$ordBadProc, [$oiBadProc]] = $createOrder('delivery', 'processing', [[$bpNonBatch, 3]]);
    $pdo->exec("INSERT INTO inventory_reservation (order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status) VALUES ({$oiBadProc}, {$invNonBatch}, NULL, 2, 'active')");
    $pdo->beginTransaction();
    try {
        $service->assertDeliveryOrderReservedForDispatch($ordBadProc, 1);
        $pdo->rollBack();
        check('Dispatch pre-check rejects processing order with incomplete reservation', false);
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        check('Dispatch pre-check rejects processing order with incomplete reservation', $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE);
    }

    // 8. Insufficient eligible stock rolls back delivery confirmation atomically
    [$bpPartial, $invPartial] = $createProduct('ChkE1 Partial Fail', 5, 0, 0, 0);
    [$ordFailStock, [$oiFailA, $oiFailB]] = $createOrder('delivery', 'pending', [[$bpNonBatch, 2], [$bpPartial, 6]]);
    $committedBeforeFail = (int)$pdo->query("SELECT committed FROM inventory WHERE inventory_id = {$invNonBatch}")->fetchColumn();
    try {
        $service->confirmDeliveryOrderAndReserve($ordFailStock, 1, $empUid);
        check('Insufficient stock delivery confirmation rejects and rolls back completely', false);
    } catch (RuntimeException $e) {
        $stFail = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordFailStock}")->fetchColumn();
        $committedAfterFail = (int)$pdo->query("SELECT committed FROM inventory WHERE inventory_id = {$invNonBatch}")->fetchColumn();
        $resCntFail = (int)$pdo->query("SELECT COUNT(*) FROM inventory_reservation WHERE order_item_id IN ({$oiFailA}, {$oiFailB})")->fetchColumn();
        check(
            'Insufficient stock delivery confirmation rejects and rolls back completely',
            $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE
                && $stFail === 'pending'
                && $committedAfterFail === $committedBeforeFail
                && $resCntFail === 0
        );
    }

    // 9. Business-unit isolation blocks cross-unit delivery confirmation and preparation
    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('Checkpoint E1 Foreign Unit', 'active')");
    $otherUnitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $otherUnitId;
    try {
        $service->confirmDeliveryOrderAndReserve($ordDeliv, $otherUnitId, $empUid);
        check('Foreign business unit cannot access Pet Store delivery order', false);
    } catch (RuntimeException $e) {
        check('Foreign business unit cannot access Pet Store delivery order', $e->getCode() === OrderReservationService::ERR_NOT_FOUND);
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($cleanup['orders']) {
        $oList = implode(',', $cleanup['orders']);
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
        $pdo->exec("DELETE FROM branch WHERE branch_id IN (" . implode(',', $cleanup['branches']) . ")");
    }
    if ($cleanup['customers']) {
        $pdo->exec("DELETE FROM customer WHERE customer_id IN (" . implode(',', $cleanup['customers']) . ")");
    }
    if ($cleanup['employees']) {
        $pdo->exec("DELETE FROM employee WHERE employee_id IN (" . implode(',', $cleanup['employees']) . ")");
    }
    if ($cleanup['users']) {
        $pdo->exec("DELETE FROM user_account WHERE user_account_id IN (" . implode(',', $cleanup['users']) . ")");
    }
    if ($cleanup['business_units']) {
        $pdo->exec("DELETE FROM business_unit WHERE business_unit_id IN (" . implode(',', $cleanup['business_units']) . ")");
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
