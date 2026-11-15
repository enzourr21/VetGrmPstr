<?php
declare(strict_types=1);

/**
 * PawCariño — Checkpoint B: Pickup Order FEFO Reservation Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Uses clean fixture setup + cleanup scoped to records created by this run.
 * Note: True simultaneous confirmation concurrency requires two independent DB
 * connections (e.g., separate PHP worker processes) and is left as a follow-up.
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

    $createGlobalProduct = static function (string $name) use ($pdo, &$cleanup): int {
        $stmtP = $pdo->prepare("INSERT INTO product (product_name, product_category) VALUES (?, 'Food')");
        $stmtP->execute([$name]);
        $prodId = (int)$pdo->lastInsertId();
        $cleanup['products'][] = $prodId;
        return $prodId;
    };

    $addBusinessProduct = static function (int $prodId, int $unitId, int $onHand, int $committed, int $inTransit, int $batchTracked) use ($pdo): array {
        $stmtBp = $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking) VALUES (?, ?, 100.00, ?)");
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
    $res1 = $service->confirmPickupOrderAndReserve($ord1, 1, $empUid);
    $rows1 = $pdo->query("SELECT batch_id, reserved_quantity, reservation_status FROM inventory_reservation WHERE order_item_id = {$oi1} ORDER BY reservation_id")->fetchAll();
    $invRow1 = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invSplit}")->fetch();
    $bSoonQty = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bSoon}")->fetchColumn();
    $audit1 = $pdo->query("SELECT actor_user_id, affected_employee_id FROM audit_log WHERE target_type = 'customer_order' AND target_id = {$ord1} ORDER BY audit_id DESC LIMIT 1")->fetch();

    check('Multi-batch FEFO split creates 2 active reservations skipping expired', count($rows1) === 2 && (int)$rows1[0]['batch_id'] === $bSoon && (int)$rows1[0]['reserved_quantity'] === 4 && (int)$rows1[1]['batch_id'] === $bLater && (int)$rows1[1]['reserved_quantity'] === 3);
    check('Confirmation increases committed without changing on_hand or batch.quantity', (int)$invRow1['on_hand'] === 15 && (int)$invRow1['committed'] === 7 && (int)$invRow1['in_transit'] === 0 && $bSoonQty === 4 && $res1['reservations_created'] === 2);
    check('Audit record preserves actor_user_id and leaves affected_employee_id NULL', (int)$audit1['actor_user_id'] === $empUid && $audit1['affected_employee_id'] === null);

    // 2. Subsequent confirmation respects already-active reservations on shared batches
    [$ord2, [$oi2]] = $createOrder('pickup', 'pending', [[$bpSplit, 3]]);
    $service->confirmPickupOrderAndReserve($ord2, 1, $empUid);
    $rows2 = $pdo->query("SELECT batch_id, reserved_quantity FROM inventory_reservation WHERE order_item_id = {$oi2}")->fetchAll();
    $invRow2 = $pdo->query("SELECT committed, in_transit FROM inventory WHERE inventory_id = {$invSplit}")->fetch();
    check('Second order allocates remaining unreserved capacity from B-LATER only', count($rows2) === 1 && (int)$rows2[0]['batch_id'] === $bLater && (int)$rows2[0]['reserved_quantity'] === 3);
    check('committed increases cleanly and preserves committed >= in_transit >= 0', (int)$invRow2['committed'] === 10 && (int)$invRow2['in_transit'] === 0);

    // 3. Duplicate confirmation rejected
    try {
        $service->confirmPickupOrderAndReserve($ord1, 1, $empUid);
        check('Duplicate confirmation on already-confirmed order is rejected', false);
    } catch (RuntimeException $e) {
        check('Duplicate confirmation rejected with ERR_CONFLICT', $e->getCode() === OrderReservationService::ERR_CONFLICT);
    }

    // 4. Delivery order rejected
    [$ordDeliv] = $createOrder('delivery', 'pending', [[$bpSplit, 1]]);
    try {
        $service->confirmPickupOrderAndReserve($ordDeliv, 1, $empUid);
        check('Delivery order rejected by pickup confirmation service', false);
    } catch (RuntimeException $e) {
        check('Delivery order rejected with ERR_FORBIDDEN', $e->getCode() === OrderReservationService::ERR_FORBIDDEN);
    }

    // 5. Partial reservation rollback across multi-line order when line 2 lacks stock
    [$bpOk, $invOk] = $createProduct('ChkB Line1 Ok', 5, 0, 0, 0);
    [$bpLow, $invLow] = $createProduct('ChkB Line2 Low', 1, 0, 0, 0);
    [$ordFail, $oiFail] = $createOrder('pickup', 'pending', [[$bpOk, 3], [$bpLow, 2]]);
    try {
        $service->confirmPickupOrderAndReserve($ordFail, 1, $empUid);
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
        $service->confirmPickupOrderAndReserve($ordGuard, 1, $empUid);
        check('Prior non-cancelled reservation rejects confirmation', false);
    } catch (RuntimeException $e) {
        check('Prior non-cancelled reservation rejected with ERR_CONFLICT', $e->getCode() === OrderReservationService::ERR_CONFLICT);
    }

    // 7. Cancelled prior reservation does NOT block confirmation and non-batch sets batch_id = NULL
    $pdo->exec("UPDATE inventory_reservation SET reservation_status = 'cancelled' WHERE order_item_id = {$oiGuard}");
    $service->confirmPickupOrderAndReserve($ordGuard, 1, $empUid);
    $guardBatchId = $pdo->query("SELECT batch_id FROM inventory_reservation WHERE order_item_id = {$oiGuard} AND reservation_status = 'active'")->fetchColumn();
    check('Cancelled prior reservation allows fresh confirmation and sets batch_id = NULL for non-batch product', $guardBatchId === null);

    // 8. Cross-unit isolation: shared global product listed in another unit cannot be reserved by a unit 1 order
    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('Checkpoint B Other Unit', 'active')");
    $otherUnitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $otherUnitId;

    $sharedProdId = $createGlobalProduct('ChkB Shared Global Cross-Unit');
    [$bpSharedU1, $invSharedU1] = $addBusinessProduct($sharedProdId, 1, 10, 0, 0, 0);
    [$bpSharedU2, $invSharedU2] = $addBusinessProduct($sharedProdId, $otherUnitId, 10, 0, 0, 0);

    [$ordCross, [$oiCross]] = $createOrder('pickup', 'pending', [[$bpSharedU2, 2]]);
    try {
        $service->confirmPickupOrderAndReserve($ordCross, 1, $empUid);
        check('Cross-unit item reference rejects confirmation', false);
    } catch (RuntimeException $e) {
        $crossStatus = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordCross}")->fetchColumn();
        $u1Committed = (int)$pdo->query("SELECT committed FROM inventory WHERE inventory_id = {$invSharedU1}")->fetchColumn();
        $u2Committed = (int)$pdo->query("SELECT committed FROM inventory WHERE inventory_id = {$invSharedU2}")->fetchColumn();
        $crossResCnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory_reservation WHERE order_item_id = {$oiCross}")->fetchColumn();
        $crossAuditCnt = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE target_type = 'customer_order' AND target_id = {$ordCross}")->fetchColumn();
        check(
            'Cross-unit reference rolls back with ERR_UNPROCESSABLE and leaves status, inventory, reservations, and audit unchanged',
            $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE
                && $crossStatus === 'pending'
                && $u1Committed === 0
                && $u2Committed === 0
                && $crossResCnt === 0
                && $crossAuditCnt === 0
        );
    }

    // 9. Mixed order (one valid unit 1 item + one unit 2 item) rejects and rolls back completely
    [$ordMixed, $oiMixed] = $createOrder('pickup', 'pending', [[$bpSharedU1, 3], [$bpSharedU2, 1]]);
    try {
        $service->confirmPickupOrderAndReserve($ordMixed, 1, $empUid);
        check('Mixed valid-unit and cross-unit order rejects confirmation', false);
    } catch (RuntimeException $e) {
        $mixedStatus = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordMixed}")->fetchColumn();
        $mixU1Committed = (int)$pdo->query("SELECT committed FROM inventory WHERE inventory_id = {$invSharedU1}")->fetchColumn();
        $mixU2Committed = (int)$pdo->query("SELECT committed FROM inventory WHERE inventory_id = {$invSharedU2}")->fetchColumn();
        $mixResCnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory_reservation WHERE order_item_id IN (" . implode(',', $oiMixed) . ")")->fetchColumn();
        $mixAuditCnt = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE target_type = 'customer_order' AND target_id = {$ordMixed}")->fetchColumn();
        check(
            'Mixed cross-unit order rolls back completely without reserving valid unit 1 line',
            $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE
                && $mixedStatus === 'pending'
                && $mixU1Committed === 0
                && $mixU2Committed === 0
                && $mixResCnt === 0
                && $mixAuditCnt === 0
        );
    }

    // 10. Full pickup handover fulfillment (ready_for_pickup -> completed) across batch + non-batch items
    [$bpFulBatch, $invFulBatch] = $createProduct('ChkC Fulfill Batch', 10, 0, 0, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invFulBatch}, 'C-B1', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 10)");
    $bFul1 = (int)$pdo->lastInsertId();
    [$bpFulNonBatch, $invFulNonBatch] = $createProduct('ChkC Fulfill NonBatch', 8, 0, 0, 0);

    [$ordFull, [$oiFulBatch, $oiFulNonBatch]] = $createOrder('pickup', 'pending', [[$bpFulBatch, 4], [$bpFulNonBatch, 3]]);
    $service->confirmPickupOrderAndReserve($ordFull, 1, $empUid);
    $readyRes = $service->markPickupOrderReady($ordFull, 1, $empUid);
    $fullRes = $service->fulfillPickupOrder($ordFull, 1, $empUid, null, true);

    $invFulBatchRow = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invFulBatch}")->fetch();
    $invFulNbRow = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invFulNonBatch}")->fetch();
    $bFul1Qty = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bFul1}")->fetchColumn();
    $fulResRows = $pdo->query("SELECT reservation_id, order_item_id, batch_id, reserved_quantity, reservation_status FROM inventory_reservation WHERE order_item_id IN ({$oiFulBatch}, {$oiFulNonBatch}) ORDER BY reservation_id ASC")->fetchAll();
    $fulMovRows = $pdo->query("SELECT reservation_id, batch_id, movement_type, quantity FROM inventory_movement WHERE reservation_id IN ({$fulResRows[0]['reservation_id']}, {$fulResRows[1]['reservation_id']}) ORDER BY movement_id ASC")->fetchAll();
    $ordFullStatus = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordFull}")->fetchColumn();
    $fulAudit = $pdo->query("SELECT actor_user_id, affected_employee_id FROM audit_log WHERE target_type = 'customer_order' AND target_id = {$ordFull} ORDER BY audit_id DESC LIMIT 1")->fetch();

    check(
        'Full handover transitions ready_for_pickup -> completed, deducts on_hand/committed/batch, keeps reserved_quantity, and inserts negative order_fulfillment movements',
        $readyRes['order_status'] === 'ready_for_pickup'
            && $fullRes['order_status'] === 'completed'
            && $fullRes['partial'] === false
            && $fullRes['fulfilled_quantity'] === 7
            && $fullRes['remaining_quantity'] === 0
            && (int)$invFulBatchRow['on_hand'] === 6
            && (int)$invFulBatchRow['committed'] === 0
            && (int)$invFulNbRow['on_hand'] === 5
            && (int)$invFulNbRow['committed'] === 0
            && $bFul1Qty === 6
            && $ordFullStatus === 'completed'
            && count($fulMovRows) === 2
            && $fulMovRows[0]['movement_type'] === 'order_fulfillment'
            && $fulMovRows[1]['movement_type'] === 'order_fulfillment'
            && ((int)$fulMovRows[0]['quantity'] + (int)$fulMovRows[1]['quantity']) === -7
            && $fulResRows[0]['reservation_status'] === 'fulfilled'
            && $fulResRows[1]['reservation_status'] === 'fulfilled'
            && ((int)$fulResRows[0]['reserved_quantity'] + (int)$fulResRows[1]['reserved_quantity']) === 7
            && (int)$fulAudit['actor_user_id'] === $empUid
            && $fulAudit['affected_employee_id'] === null
    );

    // 11. Duplicate full handover submission is rejected without double-deduction or duplicate movements
    try {
        $service->fulfillPickupOrder($ordFull, 1, $empUid, null, true);
        check('Repeated pickup handover rejects duplicate submission', false);
    } catch (RuntimeException $e) {
        $dupOnHand = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invFulBatch}")->fetchColumn();
        $dupMovCount = (int)$pdo->query("SELECT COUNT(*) FROM inventory_movement WHERE reservation_id IN ({$fulResRows[0]['reservation_id']}, {$fulResRows[1]['reservation_id']})")->fetchColumn();
        check(
            'Repeated pickup handover fails with ERR_CONFLICT and creates zero extra deductions or movements',
            $e->getCode() === OrderReservationService::ERR_CONFLICT && $dupOnHand === 6 && $dupMovCount === 2
        );
    }

    // 12. Partial fulfillment across split batches preserves reserved_quantity, keeps partially fulfilled reservation active, then completes remaining
    [$bpPart, $invPart] = $createProduct('ChkC Partial Split', 10, 0, 0, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invPart}, 'P-B1', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 4)");
    $bPart1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invPart}, 'P-B2', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 6)");
    $bPart2 = (int)$pdo->lastInsertId();

    [$ordPart, [$oiPart]] = $createOrder('pickup', 'pending', [[$bpPart, 7]]);
    $service->confirmPickupOrderAndReserve($ordPart, 1, $empUid);
    $service->markPickupOrderReady($ordPart, 1, $empUid);

    $partStep1 = $service->fulfillPickupOrder($ordPart, 1, $empUid, [$oiPart => 5], true);
    $partResStep1 = $pdo->query("SELECT reservation_id, batch_id, reserved_quantity, reservation_status FROM inventory_reservation WHERE order_item_id = {$oiPart} ORDER BY reservation_id ASC")->fetchAll();
    $partInvStep1 = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invPart}")->fetch();
    $bPart1QtyStep1 = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bPart1}")->fetchColumn();
    $bPart2QtyStep1 = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bPart2}")->fetchColumn();
    $partOrdStatus1 = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordPart}")->fetchColumn();

    check(
        'Partial handover of 5/7 marks first reservation fulfilled, keeps second reservation active with original reserved_quantity=3, and keeps order ready_for_pickup',
        $partStep1['partial'] === true
            && $partStep1['fulfilled_quantity'] === 5
            && $partStep1['remaining_quantity'] === 2
            && $partStep1['order_status'] === 'ready_for_pickup'
            && $partOrdStatus1 === 'ready_for_pickup'
            && (int)$partInvStep1['on_hand'] === 5
            && (int)$partInvStep1['committed'] === 2
            && $bPart1QtyStep1 === 0
            && $bPart2QtyStep1 === 5
            && $partResStep1[0]['reservation_status'] === 'fulfilled'
            && (int)$partResStep1[0]['reserved_quantity'] === 4
            && $partResStep1[1]['reservation_status'] === 'active'
            && (int)$partResStep1[1]['reserved_quantity'] === 3
    );

    // Over-fulfillment exceeding remaining reserved quantity (3 > 2) must fail and roll back
    try {
        $service->fulfillPickupOrder($ordPart, 1, $empUid, [$oiPart => 3], true);
        check('Over-fulfillment exceeding remaining reserved quantity is rejected', false);
    } catch (RuntimeException $e) {
        $afterOverOnHand = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invPart}")->fetchColumn();
        $afterOverCommitted = (int)$pdo->query("SELECT committed FROM inventory WHERE inventory_id = {$invPart}")->fetchColumn();
        check(
            'Over-fulfillment rejects with ERR_UNPROCESSABLE and preserves stock state',
            $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE && $afterOverOnHand === 5 && $afterOverCommitted === 2
        );
    }

    // Complete remaining 2 units
    $partStep2 = $service->fulfillPickupOrder($ordPart, 1, $empUid, null, true);
    $partResStep2 = $pdo->query("SELECT reservation_status, reserved_quantity FROM inventory_reservation WHERE order_item_id = {$oiPart} ORDER BY reservation_id ASC")->fetchAll();
    $partInvStep2 = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invPart}")->fetch();
    $bPart2QtyStep2 = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bPart2}")->fetchColumn();
    $partMovSum = (int)$pdo->query("SELECT COALESCE(SUM(quantity), 0) FROM inventory_movement WHERE reservation_id IN ({$partResStep1[0]['reservation_id']}, {$partResStep1[1]['reservation_id']}) AND movement_type = 'order_fulfillment'")->fetchColumn();

    check(
        'Completing remaining 2 units deducts only remaining quantity, finalizes reservation, keeps reserved_quantity=3, and completes order',
        $partStep2['partial'] === false
            && $partStep2['fulfilled_quantity'] === 2
            && $partStep2['remaining_quantity'] === 0
            && $partStep2['order_status'] === 'completed'
            && (int)$partInvStep2['on_hand'] === 3
            && (int)$partInvStep2['committed'] === 0
            && $bPart2QtyStep2 === 3
            && $partResStep2[1]['reservation_status'] === 'fulfilled'
            && (int)$partResStep2[1]['reserved_quantity'] === 3
            && $partMovSum === -7
    );

    // 13. Multi-line handover rolls back completely when pinned batch stock is insufficient for line 2
    [$bpRb1, $invRb1] = $createProduct('ChkC Rollback Line1', 10, 0, 0, 0);
    [$bpRb2, $invRb2] = $createProduct('ChkC Rollback Line2 Batch', 5, 0, 0, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invRb2}, 'RB-B1', DATE_ADD(CURDATE(), INTERVAL 12 DAY), 5)");
    $bRb2 = (int)$pdo->lastInsertId();

    [$ordRb, [$oiRb1, $oiRb2]] = $createOrder('pickup', 'pending', [[$bpRb1, 2], [$bpRb2, 3]]);
    $service->confirmPickupOrderAndReserve($ordRb, 1, $empUid);
    $service->markPickupOrderReady($ordRb, 1, $empUid);
    $pdo->exec("UPDATE inventory_batch SET quantity = 1 WHERE batch_id = {$bRb2}");

    try {
        $service->fulfillPickupOrder($ordRb, 1, $empUid, null, true);
        check('Insufficient pinned batch quantity rolls back whole handover', false);
    } catch (RuntimeException $e) {
        $rbInv1 = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invRb1}")->fetch();
        $rbOrdStatus = (string)$pdo->query("SELECT order_status FROM customer_order WHERE order_id = {$ordRb}")->fetchColumn();
        $rbMovCnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory_movement im JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id WHERE ir.order_item_id IN ({$oiRb1}, {$oiRb2})")->fetchColumn();
        $rbActiveCnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory_reservation WHERE order_item_id IN ({$oiRb1}, {$oiRb2}) AND reservation_status = 'active'")->fetchColumn();
        check(
            'Handover rollback restores line 1 stock, keeps reservations active, writes 0 movements, and keeps ready_for_pickup',
            $e->getCode() === OrderReservationService::ERR_UNPROCESSABLE
                && (int)$rbInv1['on_hand'] === 10
                && (int)$rbInv1['committed'] === 2
                && $rbOrdStatus === 'ready_for_pickup'
                && $rbMovCnt === 0
                && $rbActiveCnt === 2
        );
    }

    // 14. Cross-unit handover isolation rejects wrong business unit
    try {
        $service->fulfillPickupOrder($ordRb, $otherUnitId, $empUid, null, true);
        check('Wrong business unit handover attempt is rejected', false);
    } catch (RuntimeException $e) {
        check('Wrong business unit handover rejected with ERR_NOT_FOUND', $e->getCode() === OrderReservationService::ERR_NOT_FOUND);
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
