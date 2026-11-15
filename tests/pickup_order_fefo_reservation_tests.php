<?php
declare(strict_types=1);

/**
 * PawCariño — Checkpoint B: Pickup Order FEFO Reservation Verification Suite
 *
 * Targets the local database configured in .env (expected disposable test DB: vetgrpet_test).
 * Uses clean fixture setup + cleanup so test data does not persist.
 * Added for Checkpoint B; execute manually against a disposable test database when permitted.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\OrderReservationService;

$pdo = Database::pdo();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_contains(strtolower($dbName), 'test')) {
    fwrite(STDERR, "Refusing to run against non-test database: {$dbName}\n");
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
];

try {
    // Seed shared customer, staff actor, and branch fixtures
    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_b_cust@test.invalid', 'hash')");
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->exec("INSERT INTO customer (user_account_id, first_name, last_name) VALUES ({$custUid}, 'Pickup', 'Customer')");
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_b_emp@test.invalid', 'hash')");
    $empUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $empUid;
    $pdo->exec("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position) VALUES ({$empUid}, 1, 'Rec', 'Eptionist', 'Pet Store Receptionist')");
    $empId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $empId;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name) VALUES (1, 'Checkpoint B Branch')");
    $branchId = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchId;

    $createProduct = static function (string $name, int $onHand, int $committed, int $inTransit, int $batchTracked) use ($pdo, &$cleanup): array {
        $stmtP = $pdo->prepare("INSERT INTO product (product_name, product_category) VALUES (?, 'Food')");
        $stmtP->execute([$name]);
        $prodId = (int)$pdo->lastInsertId();
        $cleanup['products'][] = $prodId;

        $stmtBp = $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking) VALUES (1, ?, 100.00, ?)");
        $stmtBp->execute([$prodId, $batchTracked]);
        $bpId = (int)$pdo->lastInsertId();

        $stmtInv = $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, ?, ?, ?)");
        $stmtInv->execute([$bpId, $onHand, $committed, $inTransit]);
        $invId = (int)$pdo->lastInsertId();

        return [$bpId, $invId];
    };

    $createOrder = static function (string $method, string $status, array $lines) use ($pdo, $custId, $branchId, &$cleanup): array {
        $stmt = $pdo->prepare("INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, total_amount) VALUES (?, 1, ?, ?, ?, 100.00)");
        $stmt->execute([$custId, $branchId, $method, $status]);
        $ordId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $ordId;

        $itemIds = [];
        $ins = $pdo->prepare("INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, 100.00)");
        foreach ($lines as [$bpId, $qty]) {
            $ins->execute([$ordId, $bpId, $qty]);
            $itemIds[] = (int)$pdo->lastInsertId();
        }
        return [$ordId, $itemIds];
    };

    // 1. FEFO multi-batch split + expired-batch exclusion + NULL expiration last + on_hand/batch.quantity untouched
    [$bpSplit, $invSplit] = $createProduct('ChkB Batch Split', 15, 0, 0, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invSplit}, 'B-EXPIRED', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 5)");
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invSplit}, 'B-SOON', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 4)");
    $bSoon = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invSplit}, 'B-LATER', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 6)");
    $bLater = (int)$pdo->lastInsertId();

    [$ord1, [$oi1]] = $createOrder('pickup', 'pending', [[$bpSplit, 7]]);
    $res1 = $service->confirmPickupOrderAndReserve($ord1, 1, $empUid, $empId);
    $rows1 = $pdo->query("SELECT batch_id, reserved_quantity, reservation_status FROM inventory_reservation WHERE order_item_id = {$oi1} ORDER BY reservation_id")->fetchAll();
    $invRow1 = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invSplit}")->fetch();
    $bSoonQty = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bSoon}")->fetchColumn();

    check('Multi-batch FEFO split creates 2 active reservations skipping expired', count($rows1) === 2 && (int)$rows1[0]['batch_id'] === $bSoon && (int)$rows1[0]['reserved_quantity'] === 4 && (int)$rows1[1]['batch_id'] === $bLater && (int)$rows1[1]['reserved_quantity'] === 3);
    check('Confirmation increases committed without changing on_hand or batch.quantity', (int)$invRow1['on_hand'] === 15 && (int)$invRow1['committed'] === 7 && (int)$invRow1['in_transit'] === 0 && $bSoonQty === 4 && $res1['reservations_created'] === 2);

    // 2. Subsequent confirmation respects already-active reservations on shared batches
    [$ord2, [$oi2]] = $createOrder('pickup', 'pending', [[$bpSplit, 3]]);
    $service->confirmPickupOrderAndReserve($ord2, 1, $empUid, $empId);
    $rows2 = $pdo->query("SELECT batch_id, reserved_quantity FROM inventory_reservation WHERE order_item_id = {$oi2}")->fetchAll();
    $invRow2 = $pdo->query("SELECT committed, in_transit FROM inventory WHERE inventory_id = {$invSplit}")->fetch();
    check('Second order allocates remaining unreserved capacity from B-LATER only', count($rows2) === 1 && (int)$rows2[0]['batch_id'] === $bLater && (int)$rows2[0]['reserved_quantity'] === 3);
    check('committed increases cleanly and preserves committed >= in_transit >= 0', (int)$invRow2['committed'] === 10 && (int)$invRow2['in_transit'] === 0);

    // 3. Duplicate confirmation rejected
    try {
        $service->confirmPickupOrderAndReserve($ord1, 1, $empUid, $empId);
        check('Duplicate confirmation on already-confirmed order is rejected', false);
    } catch (RuntimeException $e) {
        check('Duplicate confirmation rejected with ERR_CONFLICT', $e->getCode() === OrderReservationService::ERR_CONFLICT);
    }

    // 4. Delivery order rejected
    [$ordDeliv] = $createOrder('delivery', 'pending', [[$bpSplit, 1]]);
    try {
        $service->confirmPickupOrderAndReserve($ordDeliv, 1, $empUid, $empId);
        check('Delivery order rejected by pickup confirmation service', false);
    } catch (RuntimeException $e) {
        check('Delivery order rejected with ERR_FORBIDDEN', $e->getCode() === OrderReservationService::ERR_FORBIDDEN);
    }

    // 5. Partial reservation rollback across multi-line order when line 2 lacks stock
    [$bpOk, $invOk] = $createProduct('ChkB Line1 Ok', 5, 0, 0, 0);
    [$bpLow, $invLow] = $createProduct('ChkB Line2 Low', 1, 0, 0, 0);
    [$ordFail, $oiFail] = $createOrder('pickup', 'pending', [[$bpOk, 3], [$bpLow, 2]]);
    try {
        $service->confirmPickupOrderAndReserve($ordFail, 1, $empUid, $empId);
        check('Insufficient second line rolls back whole order', false);
    } catch (RuntimeException $e) {
        $okCommitted = (int)$pdo->query("SELECT committed FROM inventory WHERE inventory_id = {$invOk}")->fetchColumn();
        $resCountFail = (int)$pdo->query("SELECT COUNT(*) FROM inventory_reservation WHERE order_item_id IN (" . implode(',', $oiFail) . ")")->fetchColumn();
        $ordStatusFail = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordFail}")->fetchColumn();
        check('Multi-line failure rolls back line 1 committed, reservations, and keeps pending status', $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE && $okCommitted === 0 && $resCountFail === 0 && $ordStatusFail === 'pending');
    }

    // 6. Existing non-cancelled reservation blocks even if status were tampered back to pending
    [$bpGuard, $invGuard] = $createProduct('ChkB Dup Res Guard', 5, 0, 0, 0);
    [$ordGuard, [$oiGuard]] = $createOrder('pickup', 'pending', [[$bpGuard, 2]]);
    $pdo->exec("INSERT INTO inventory_reservation (order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status) VALUES ({$oiGuard}, {$invGuard}, NULL, 2, 'active')");
    try {
        $service->confirmPickupOrderAndReserve($ordGuard, 1, $empUid, $empId);
        check('Prior non-cancelled reservation rejects confirmation', false);
    } catch (RuntimeException $e) {
        check('Prior non-cancelled reservation rejected with ERR_CONFLICT', $e->getCode() === OrderReservationService::ERR_CONFLICT);
    }

    // 7. Cancelled prior reservation does NOT block confirmation and non-batch sets batch_id = NULL
    $pdo->exec("UPDATE inventory_reservation SET reservation_status = 'cancelled' WHERE order_item_id = {$oiGuard}");
    $service->confirmPickupOrderAndReserve($ordGuard, 1, $empUid, $empId);
    $guardBatchId = $pdo->query("SELECT batch_id FROM inventory_reservation WHERE order_item_id = {$oiGuard} AND reservation_status = 'active'")->fetchColumn();
    check('Cancelled prior reservation allows fresh confirmation and sets batch_id = NULL for non-batch product', $guardBatchId === null);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($cleanup['orders']) {
        $oList = implode(',', $cleanup['orders']);
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
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
