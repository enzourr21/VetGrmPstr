<?php
declare(strict_types=1);

/**
 * PawCariño — Checkpoint D: Pet Store POS Sale Concurrency & FEFO Deduction Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Uses isolated fixture setup + cleanup scoped strictly to records created by this run.
 * Note: True simultaneous multi-connection race/deadlock testing requires independent DB
 * connections (e.g., separate PHP worker processes against vetgrpet_test).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\PosSaleService;

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

$service = new PosSaleService($pdo);
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
    // Dedicated business units prevent pre-existing branch rows from affecting branch_id selection.
    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkD Pet Store Unit A', 'active')");
    $unitA = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $unitA;

    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkD Other Unit B', 'active')");
    $unitB = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $unitB;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, branch_status) VALUES ({$unitA}, 'ChkD Main Branch', 'active')");
    $branchA = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchA;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, branch_status) VALUES ({$unitB}, 'ChkD Other Branch', 'active')");
    $branchB = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchB;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_d_emp@test.invalid', 'hash')");
    $empUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $empUid;

    $pdo->exec("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position) VALUES ({$empUid}, {$unitA}, 'Pos', 'Cashier', 'Pet Store Receptionist')");
    $empId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $empId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_d_cust@test.invalid', 'hash')");
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;

    $pdo->exec("INSERT INTO customer (user_account_id, first_name, last_name) VALUES ({$custUid}, 'Walkin', 'Shopper')");
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    $createGlobalProduct = static function (string $name) use ($pdo, &$cleanup): int {
        $stmtP = $pdo->prepare("INSERT INTO product (product_name, product_category) VALUES (?, 'Food')");
        $stmtP->execute([$name]);
        $prodId = (int)$pdo->lastInsertId();
        $cleanup['products'][] = $prodId;
        return $prodId;
    };

    $addBusinessProduct = static function (
        int $prodId,
        int $unitId,
        float $price,
        int $onHand,
        int $committed,
        int $inTransit,
        int $batchTracked,
        string $status = 'active'
    ) use ($pdo): array {
        $stmtBp = $pdo->prepare(
            "INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmtBp->execute([$unitId, $prodId, $price, $batchTracked, $status]);
        $bpId = (int)$pdo->lastInsertId();

        $stmtInv = $pdo->prepare(
            "INSERT INTO inventory (business_product_id, on_hand, committed, in_transit)
             VALUES (?, ?, ?, ?)"
        );
        $stmtInv->execute([$bpId, $onHand, $committed, $inTransit]);
        $invId = (int)$pdo->lastInsertId();

        return [$bpId, $invId];
    };

    $addBatch = static function (int $invId, string $batchNo, ?string $expirySql, int $qty) use ($pdo): int {
        $stmt = $pdo->prepare(
            "INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity)
             VALUES (?, ?, {$expirySql}, ?)"
        );
        $stmt->execute([$invId, $batchNo, $qty]);
        return (int)$pdo->lastInsertId();
    };

    $createSeededReservationOrder = static function (
        int $unitId,
        int $branchId,
        int $bpId,
        int $invId,
        int $batchId,
        int $orderQty,
        int $reservedQty
    ) use ($pdo, $custId, &$cleanup): array {
        $pdo->exec(
            "INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, total_amount)
             VALUES ({$custId}, {$unitId}, {$branchId}, 'pickup', 'confirmed', 100.00)"
        );
        $ordId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $ordId;

        $pdo->exec(
            "INSERT INTO order_item (order_id, business_product_id, quantity, unit_price)
             VALUES ({$ordId}, {$bpId}, {$orderQty}, 100.00)"
        );
        $oiId = (int)$pdo->lastInsertId();

        $pdo->exec(
            "INSERT INTO inventory_reservation (order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status)
             VALUES ({$oiId}, {$invId}, {$batchId}, {$reservedQty}, 'active')"
        );
        $resId = (int)$pdo->lastInsertId();

        return [$ordId, $oiId, $resId];
    };

    // 1. Non-batch POS sale: on_hand deduction, sale movement, completed payment, atomic Audit::record()
    $pNonBatch = $createGlobalProduct('ChkD NonBatch Toy');
    [$bpNonBatch, $invNonBatch] = $addBusinessProduct($pNonBatch, $unitA, 125.00, 10, 0, 0, 0);

    $res1 = $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
        ['business_product_id' => $bpNonBatch, 'quantity' => 3],
    ]);
    $cleanup['sales'][] = $res1['sale_id'];

    $sale1 = $pdo->query("SELECT branch_id, customer_id, total_amount, sale_status FROM sale WHERE sale_id = {$res1['sale_id']}")->fetch(PDO::FETCH_ASSOC);
    $invNonBatchAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invNonBatch}")->fetchColumn();
    $si1 = $pdo->query("SELECT sale_item_id, quantity FROM sale_item WHERE sale_id = {$res1['sale_id']}")->fetch(PDO::FETCH_ASSOC);
    $mov1 = $pdo->query("SELECT batch_id, movement_type, quantity FROM inventory_movement WHERE sale_item_id = {$si1['sale_item_id']}")->fetch(PDO::FETCH_ASSOC);
    $pay1 = $pdo->query("SELECT amount, payment_method, payment_status FROM payment WHERE sale_id = {$res1['sale_id']}")->fetch(PDO::FETCH_ASSOC);
    $audit1 = $pdo->query("SELECT actor_user_id, affected_employee_id, action_code FROM audit_log WHERE target_type = 'sale' AND target_id = {$res1['sale_id']} ORDER BY log_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    check(
        'Non-batch POS sale deducts on_hand, links movement, creates payment & Audit::record() with null affected_employee_id',
        $res1['movements_created'] === 1
            && (int)$sale1['branch_id'] === $branchA
            && $sale1['customer_id'] === null
            && (float)$sale1['total_amount'] === 375.00
            && $sale1['sale_status'] === 'completed'
            && $invNonBatchAfter === 7
            && (int)$si1['quantity'] === 3
            && $mov1['batch_id'] === null
            && $mov1['movement_type'] === 'sale'
            && (int)$mov1['quantity'] === -3
            && (float)$pay1['amount'] === 375.00
            && $pay1['payment_method'] === 'Cash'
            && $pay1['payment_status'] === 'completed'
            && (int)$audit1['actor_user_id'] === $empUid
            && $audit1['affected_employee_id'] === null
            && $audit1['action_code'] === 'petstore_receptionist.sale.create'
    );

    // 2. Batch-tracked FEFO multi-batch split (skips expired batch, orders soonest expiry -> later -> NULL)
    $pFefo = $createGlobalProduct('ChkD FEFO Treats');
    [$bpFefo, $invFefo] = $addBusinessProduct($pFefo, $unitA, 50.00, 14, 0, 0, 1);
    $bExpired = $addBatch($invFefo, 'CHK-EXP', 'DATE_SUB(CURDATE(), INTERVAL 1 DAY)', 4);
    $bNullExp = $addBatch($invFefo, 'CHK-NULL', 'NULL', 3);
    $bLater   = $addBatch($invFefo, 'CHK-LATER', 'DATE_ADD(CURDATE(), INTERVAL 30 DAY)', 5);
    $bSoonest = $addBatch($invFefo, 'CHK-SOON', 'DATE_ADD(CURDATE(), INTERVAL 5 DAY)', 2);

    // Total on_hand = 14, eligible non-expired = 10 (2 soonest + 5 later + 3 null). Request 8 -> 2 soonest + 5 later + 1 null.
    $res2 = $service->recordSale($unitA, $empUid, $empId, $custId, 'GCash', [
        ['business_product_id' => $bpFefo, 'quantity' => 8],
    ]);
    $cleanup['sales'][] = $res2['sale_id'];

    $si2Id = (int)$pdo->query("SELECT sale_item_id FROM sale_item WHERE sale_id = {$res2['sale_id']}")->fetchColumn();
    $mov2Rows = $pdo->query("SELECT batch_id, quantity FROM inventory_movement WHERE sale_item_id = {$si2Id} AND movement_type = 'sale' ORDER BY movement_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $mov2Map = [];
    $mov2Sum = 0;
    foreach ($mov2Rows as $mr) {
        $mov2Map[(int)$mr['batch_id']] = (int)$mr['quantity'];
        $mov2Sum += (int)$mr['quantity'];
    }
    $qExp   = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bExpired}")->fetchColumn();
    $qSoon  = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bSoonest}")->fetchColumn();
    $qLater = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bLater}")->fetchColumn();
    $qNull  = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bNullExp}")->fetchColumn();
    $invFefoAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invFefo}")->fetchColumn();

    check(
        'Batch-tracked POS sale skips expired batch and splits FEFO movements across soonest -> later -> NULL',
        $res2['movements_created'] === 3
            && count($mov2Rows) === 3
            && $mov2Sum === -8
            && ($mov2Map[$bSoonest] ?? 0) === -2
            && ($mov2Map[$bLater] ?? 0) === -5
            && ($mov2Map[$bNullExp] ?? 0) === -1
            && !isset($mov2Map[$bExpired])
            && $qExp === 4
            && $qSoon === 0
            && $qLater === 0
            && $qNull === 2
            && $invFefoAfter === 6
    );

    // 3a. Duplicate non-batch lines: aggregated stock check blocks oversell (3 + 3 > 5); valid 2 + 2 preserves separate sale_item rows & movements
    $pDup = $createGlobalProduct('ChkD Duplicate NonBatch Item');
    [$bpDup, $invDup] = $addBusinessProduct($pDup, $unitA, 40.00, 5, 0, 0, 0);

    try {
        $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
            ['business_product_id' => $bpDup, 'quantity' => 3],
            ['business_product_id' => $bpDup, 'quantity' => 3],
        ]);
        check('Duplicate split non-batch lines exceeding available stock rejected', false);
    } catch (RuntimeException $e) {
        $invDupCheck = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invDup}")->fetchColumn();
        check(
            'Duplicate split non-batch lines exceeding available stock rejected cleanly with ERR_UNPROCESSABLE and 0 deduction',
            $e->getCode() === PosSaleService::ERR_UNPROCESSABLE && $invDupCheck === 5
        );
    }

    $res3 = $service->recordSale($unitA, $empUid, $empId, null, 'Debit Card', [
        ['business_product_id' => $bpDup, 'quantity' => 2],
        ['business_product_id' => $bpDup, 'quantity' => 2],
    ]);
    $cleanup['sales'][] = $res3['sale_id'];

    $si3Rows = $pdo->query("SELECT sale_item_id, quantity FROM sale_item WHERE sale_id = {$res3['sale_id']} ORDER BY sale_item_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $mov3Rows = $pdo->query(
        "SELECT sale_item_id, batch_id, quantity
         FROM inventory_movement
         WHERE sale_item_id IN (" . implode(',', array_column($si3Rows, 'sale_item_id')) . ")
         ORDER BY movement_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $invDupAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invDup}")->fetchColumn();
    check(
        'Duplicate non-batch lines within available stock preserve separate sale_item rows and traceable null-batch movements',
        $res3['items_count'] === 2
            && count($si3Rows) === 2
            && (int)$si3Rows[0]['quantity'] === 2
            && (int)$si3Rows[1]['quantity'] === 2
            && count($mov3Rows) === 2
            && $mov3Rows[0]['batch_id'] === null && (int)$mov3Rows[0]['quantity'] === -2 && (int)$mov3Rows[0]['sale_item_id'] === (int)$si3Rows[0]['sale_item_id']
            && $mov3Rows[1]['batch_id'] === null && (int)$mov3Rows[1]['quantity'] === -2 && (int)$mov3Rows[1]['sale_item_id'] === (int)$si3Rows[1]['sale_item_id']
            && (float)$res3['total_amount'] === 160.00
            && $invDupAfter === 1
    );

    // 3b. Duplicate batch-tracked lines preserve separate sale_item rows and distribute FEFO slices across them
    $pDupFefo = $createGlobalProduct('ChkD Duplicate FEFO Item');
    [$bpDupFefo, $invDupFefo] = $addBusinessProduct($pDupFefo, $unitA, 35.00, 5, 0, 0, 1);
    $bDup1 = $addBatch($invDupFefo, 'CHK-DUP1', 'DATE_ADD(CURDATE(), INTERVAL 5 DAY)', 3);
    $bDup2 = $addBatch($invDupFefo, 'CHK-DUP2', 'DATE_ADD(CURDATE(), INTERVAL 10 DAY)', 2);

    try {
        $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
            ['business_product_id' => $bpDupFefo, 'quantity' => 3],
            ['business_product_id' => $bpDupFefo, 'quantity' => 3],
        ]);
        check('Duplicate split FEFO lines exceeding eligible batch stock rejected', false);
    } catch (RuntimeException $e) {
        $bDup1Before = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bDup1}")->fetchColumn();
        check(
            'Duplicate split FEFO lines exceeding eligible batch stock rejected with ERR_UNPROCESSABLE and 0 batch deduction',
            $e->getCode() === PosSaleService::ERR_UNPROCESSABLE && $bDup1Before === 3
        );
    }

    $res3b = $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
        ['business_product_id' => $bpDupFefo, 'quantity' => 2],
        ['business_product_id' => $bpDupFefo, 'quantity' => 2],
    ]);
    $cleanup['sales'][] = $res3b['sale_id'];

    $si3bRows = $pdo->query("SELECT sale_item_id, quantity FROM sale_item WHERE sale_id = {$res3b['sale_id']} ORDER BY sale_item_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $mov3bRows = $pdo->query(
        "SELECT sale_item_id, batch_id, quantity
         FROM inventory_movement
         WHERE sale_item_id IN (" . implode(',', array_column($si3bRows, 'sale_item_id')) . ")
         ORDER BY movement_id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $bDup1After = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bDup1}")->fetchColumn();
    $bDup2After = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bDup2}")->fetchColumn();
    $invDupFefoAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invDupFefo}")->fetchColumn();
    check(
        'Duplicate batch-tracked lines preserve separate sale_item rows and distribute FEFO movements across items',
        count($si3bRows) === 2
            && count($mov3bRows) === 3
            && (int)$mov3bRows[0]['sale_item_id'] === (int)$si3bRows[0]['sale_item_id'] && (int)$mov3bRows[0]['batch_id'] === $bDup1 && (int)$mov3bRows[0]['quantity'] === -2
            && (int)$mov3bRows[1]['sale_item_id'] === (int)$si3bRows[1]['sale_item_id'] && (int)$mov3bRows[1]['batch_id'] === $bDup1 && (int)$mov3bRows[1]['quantity'] === -1
            && (int)$mov3bRows[2]['sale_item_id'] === (int)$si3bRows[1]['sale_item_id'] && (int)$mov3bRows[2]['batch_id'] === $bDup2 && (int)$mov3bRows[2]['quantity'] === -1
            && $bDup1After === 0
            && $bDup2After === 1
            && $invDupFefoAfter === 1
    );

    // 4. Active reservation hold protection on batch-tracked product
    $pHold = $createGlobalProduct('ChkD Reserved Hold Item');
    [$bpHold, $invHold] = $addBusinessProduct($pHold, $unitA, 80.00, 6, 4, 0, 1);
    $bHold = $addBatch($invHold, 'CHK-HOLD', 'DATE_ADD(CURDATE(), INTERVAL 15 DAY)', 6);
    $createSeededReservationOrder($unitA, $branchA, $bpHold, $invHold, $bHold, 4, 4);

    try {
        $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
            ['business_product_id' => $bpHold, 'quantity' => 3],
        ]);
        check('POS sale encroaching active reservation hold rejected', false);
    } catch (RuntimeException $e) {
        $bHoldBefore = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bHold}")->fetchColumn();
        check(
            'POS sale exceeding unreserved capacity rejected with ERR_UNPROCESSABLE and batch untouched',
            $e->getCode() === PosSaleService::ERR_UNPROCESSABLE && $bHoldBefore === 6
        );
    }

    $res4 = $service->recordSale($unitA, $empUid, $empId, null, 'Maya', [
        ['business_product_id' => $bpHold, 'quantity' => 2],
    ]);
    $cleanup['sales'][] = $res4['sale_id'];
    $bHoldAfter = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bHold}")->fetchColumn();
    $invHoldAfter = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invHold}")->fetch(PDO::FETCH_ASSOC);
    check(
        'POS sale of unreserved 2 succeeds and preserves 4 pinned units on batch',
        $bHoldAfter === 4 && (int)$invHoldAfter['on_hand'] === 4 && (int)$invHoldAfter['committed'] === 4
    );

    // 5. Partially fulfilled active reservation batch parity: remaining hold = reserved_quantity - outbound order_fulfillment
    // Physical batch starts at 10; reservation reserved_quantity = 5; 2 units already handed over (-2 movement),
    // leaving physical batch = 8, inventory on_hand = 8, committed = 3, remaining hold = 3, unreserved available = 5.
    $pPartial = $createGlobalProduct('ChkD Partial Fulfillment Parity');
    [$bpPartial, $invPartial] = $addBusinessProduct($pPartial, $unitA, 60.00, 8, 3, 0, 1);
    $bPartial = $addBatch($invPartial, 'CHK-PART', 'DATE_ADD(CURDATE(), INTERVAL 20 DAY)', 8);
    [, , $resPartialId] = $createSeededReservationOrder($unitA, $branchA, $bpPartial, $invPartial, $bPartial, 5, 5);
    $pdo->exec(
        "INSERT INTO inventory_movement (inventory_id, batch_id, sale_item_id, reservation_id, movement_type, quantity, notes)
         VALUES ({$invPartial}, {$bPartial}, NULL, {$resPartialId}, 'order_fulfillment', -2, 'Prior partial pickup')"
    );

    $res5 = $service->recordSale($unitA, $empUid, $empId, null, 'Credit Card', [
        ['business_product_id' => $bpPartial, 'quantity' => 5],
    ]);
    $cleanup['sales'][] = $res5['sale_id'];
    $bPartialAfter = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bPartial}")->fetchColumn();
    $invPartialAfter = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invPartial}")->fetch(PDO::FETCH_ASSOC);
    check(
        'Partially fulfilled active reservation subtracts remaining hold (3) rather than gross reserved_quantity (5)',
        $bPartialAfter === 3 && (int)$invPartialAfter['on_hand'] === 3 && (int)$invPartialAfter['committed'] === 3
    );

    // 6. Multi-line rollback when line 2 has physical on_hand from expired batch only (0 non-expired batch capacity)
    $pLineOk = $createGlobalProduct('ChkD MultiLine Ok');
    [$bpLineOk, $invLineOk] = $addBusinessProduct($pLineOk, $unitA, 25.00, 5, 0, 0, 0);
    $pLineExp = $createGlobalProduct('ChkD MultiLine Expired');
    [$bpLineExp, $invLineExp] = $addBusinessProduct($pLineExp, $unitA, 30.00, 5, 0, 0, 1);
    $bLineExp = $addBatch($invLineExp, 'CHK-EXPONLY', 'DATE_SUB(CURDATE(), INTERVAL 3 DAY)', 5);

    $salesBeforeFail = (int)$pdo->query("SELECT COUNT(*) FROM sale WHERE business_unit_id = {$unitA}")->fetchColumn();
    try {
        $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
            ['business_product_id' => $bpLineOk, 'quantity' => 3],
            ['business_product_id' => $bpLineExp, 'quantity' => 1],
        ]);
        check('Multi-line sale with expired line 2 rejected', false);
    } catch (RuntimeException $e) {
        $lineOkAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invLineOk}")->fetchColumn();
        $bLineExpAfter = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = {$bLineExp}")->fetchColumn();
        $salesAfterFail = (int)$pdo->query("SELECT COUNT(*) FROM sale WHERE business_unit_id = {$unitA}")->fetchColumn();
        check(
            'Multi-line failure rolls back line 1 on_hand, keeps expired batch untouched, and creates 0 sale rows',
            $e->getCode() === PosSaleService::ERR_UNPROCESSABLE
                && $lineOkAfter === 5
                && $bLineExpAfter === 5
                && $salesAfterFail === $salesBeforeFail
        );
    }

    // 7. Business-unit isolation & inactive product rejection
    $pShared = $createGlobalProduct('ChkD Shared Cross-Unit');
    [$bpSharedB, $invSharedB] = $addBusinessProduct($pShared, $unitB, 90.00, 10, 0, 0, 0);
    $pInactive = $createGlobalProduct('ChkD Inactive Product');
    [$bpInactive, $invInactive] = $addBusinessProduct($pInactive, $unitA, 45.00, 10, 0, 0, 0, 'inactive');

    $crossUnitRejected = false;
    try {
        $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
            ['business_product_id' => $bpSharedB, 'quantity' => 1],
        ]);
    } catch (RuntimeException $e) {
        $crossUnitRejected = $e->getCode() === PosSaleService::ERR_UNPROCESSABLE;
    }

    $inactiveRejected = false;
    try {
        $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
            ['business_product_id' => $bpInactive, 'quantity' => 1],
        ]);
    } catch (RuntimeException $e) {
        $inactiveRejected = $e->getCode() === PosSaleService::ERR_UNPROCESSABLE;
    }

    $invSharedBAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invSharedB}")->fetchColumn();
    $invInactiveAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invInactive}")->fetchColumn();
    check(
        'Cross-unit and inactive products rejected with ERR_UNPROCESSABLE and 0 stock changes',
        $crossUnitRejected && $inactiveRejected && $invSharedBAfter === 10 && $invInactiveAfter === 10
    );

    // 8. OD-09 physically sellable on-hand stock: in_transit excluded, shelf_committed = committed - in_transit
    // 8a. on_hand = 6, committed = 7, in_transit = 4 => shelf_committed = 3, sellable = 6 - 3 = 3
    $pTransit = $createGlobalProduct('ChkD InTransit Shelf Commitment');
    [$bpTransit, $invTransit] = $addBusinessProduct($pTransit, $unitA, 55.00, 6, 7, 4, 0);

    $transitOversellRejected = false;
    try {
        $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
            ['business_product_id' => $bpTransit, 'quantity' => 4],
        ]);
    } catch (RuntimeException $e) {
        $transitOversellRejected = $e->getCode() === PosSaleService::ERR_UNPROCESSABLE;
    }

    $res8 = $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
        ['business_product_id' => $bpTransit, 'quantity' => 3],
    ]);
    $cleanup['sales'][] = $res8['sale_id'];
    $invTransitAfter = $pdo->query("SELECT on_hand, committed, in_transit FROM inventory WHERE inventory_id = {$invTransit}")->fetch(PDO::FETCH_ASSOC);
    check(
        'POS enforces shelf_committed = committed - in_transit (sellable = 3, blocks 4, permits 3)',
        $transitOversellRejected
            && (int)$invTransitAfter['on_hand'] === 3
            && (int)$invTransitAfter['committed'] === 7
            && (int)$invTransitAfter['in_transit'] === 4
    );

    // 8b. Corrupted invariant (committed < in_transit) fails closed with ERR_CONFLICT without counting excess in_transit
    $pCorrupt = $createGlobalProduct('ChkD Corrupt Invariant');
    [$bpCorrupt, $invCorrupt] = $addBusinessProduct($pCorrupt, $unitA, 75.00, 10, 2, 4, 0);

    $invariantRejected = false;
    try {
        $service->recordSale($unitA, $empUid, $empId, null, 'Cash', [
            ['business_product_id' => $bpCorrupt, 'quantity' => 1],
        ]);
    } catch (RuntimeException $e) {
        $invariantRejected = $e->getCode() === PosSaleService::ERR_CONFLICT;
    }
    $invCorruptAfter = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = {$invCorrupt}")->fetchColumn();
    check(
        'Violated Committed >= In Transit invariant fails closed with ERR_CONFLICT and 0 stock changes',
        $invariantRejected && $invCorruptAfter === 10
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
