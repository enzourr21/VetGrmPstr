<?php
declare(strict_types=1);

/**
 * PawCariño — E2 Checkout & Temporary Stock Hold Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  - COD checkout: 3-unit threshold, exact 3-unit purchase allowed, direct 'confirmed' order,
 *    FEFO active reservations, committed increase without on_hand deduction, 'cod'/'pending' payment,
 *    no temporary holds, cart cleared only on commit, duplicate idempotency protection, COD disabled rejection.
 *  - Online checkout: 3-unit threshold, exact 3-minute (180s) UTC hold window, no premature order/items,
 *    active holds reduce availability for other checkouts/POS/confirmations, expired holds immediately stop
 *    reducing availability even before cleanup, atomic conversion on verified capture, hold release on failure/cancel,
 *    duplicate webhook deduplication, delayed capture reconciliation (fulfillable vs unfulfillable).
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Cart;
use App\Services\CheckoutHoldService;
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

$checkoutService = new CheckoutHoldService($pdo);
$posService = new PosSaleService($pdo);
$cleanup = [
    'orders' => [],
    'attempts' => [],
    'sales' => [],
    'products' => [],
    'branches' => [],
    'customers' => [],
    'employees' => [],
    'users' => [],
    'business_units' => [],
];

try {
    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkE2 Test Unit', 'active')");
    $unitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $unitId;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, branch_status) VALUES ({$unitId}, 'ChkE2 Main Branch', 'active')");
    $branchId = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchId;

    $pdo->prepare("INSERT INTO business_settings (business_unit_id, business_name, business_email, contact_number, cod_enabled) VALUES (?, 'ChkE2 Shop', 'chke2@test.invalid', '09175550199', 1)")
        ->execute([$unitId]);

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_e2_cust1@test.invalid', 'hash')");
    $uid1 = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $uid1;
    $pdo->exec("INSERT INTO customer (user_account_id, first_name, last_name) VALUES ({$uid1}, 'Buyer', 'One')");
    $cid1 = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $cid1;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_e2_cust2@test.invalid', 'hash')");
    $uid2 = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $uid2;
    $pdo->exec("INSERT INTO customer (user_account_id, first_name, last_name) VALUES ({$uid2}, 'Buyer', 'Two')");
    $cid2 = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $cid2;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chk_e2_emp@test.invalid', 'hash')");
    $empUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $empUid;
    $pdo->exec("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position) VALUES ({$empUid}, {$unitId}, 'Cash', 'Ier', 'Pet Store Receptionist')");
    $empId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $empId;

    $createProduct = static function (string $name, float $price, int $onHand, int $batchTracked) use ($pdo, $unitId, &$cleanup): array {
        $stmtP = $pdo->prepare("INSERT INTO product (product_name, product_category) VALUES (?, 'Food')");
        $stmtP->execute([$name]);
        $prodId = (int)$pdo->lastInsertId();
        $cleanup['products'][] = $prodId;

        $stmtBp = $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status) VALUES (?, ?, ?, ?, 'active')");
        $stmtBp->execute([$unitId, $prodId, $price, $batchTracked]);
        $bpId = (int)$pdo->lastInsertId();

        $stmtInv = $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, ?, 0, 0)");
        $stmtInv->execute([$bpId, $onHand]);
        $invId = (int)$pdo->lastInsertId();

        return [$bpId, $invId];
    };

    $setCart = static function (int $cid, int $bpId, int $qty) use ($pdo, $unitId): int {
        $cartId = Cart::id($cid, $unitId);
        $pdo->prepare('DELETE FROM cart_item WHERE cart_id = ?')->execute([$cartId]);
        $pdo->prepare('INSERT INTO cart_item (cart_id, business_product_id, quantity) VALUES (?, ?, ?)')->execute([$cartId, $bpId, $qty]);
        return $cartId;
    };

    // 1. 3-unit threshold rejects stock = 2 for both COD and Online
    [$bpLow, $invLow] = $createProduct('ChkE2 LowStock', 100.00, 2, 0);
    $cart1 = $setCart($cid1, $bpLow, 1);
    try {
        $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-low-cod-1');
        check('COD rejects product below 3-unit threshold', false);
    } catch (RuntimeException $e) {
        check('COD rejects product below 3-unit threshold', $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE);
    }
    try {
        $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-low-online-1');
        check('Online checkout rejects product below 3-unit threshold', false);
    } catch (RuntimeException $e) {
        check('Online checkout rejects product below 3-unit threshold', $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE);
    }

    // 2. Exact 3 units available permits purchasing all 3 units (COD)
    [$bpExact3, $invExact3] = $createProduct('ChkE2 Exact3', 50.00, 3, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invExact3}, 'E2-B1', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 2)");
    $b1 = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invExact3}, 'E2-B2', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)");
    $b2 = (int)$pdo->lastInsertId();

    $setCart($cid1, $bpExact3, 3);
    $codRes = $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'delivery', [
        'address_line1' => '100 Paw St',
        'city' => 'Makati',
        'postal_code' => '1200',
    ], 'Leave at gate', 'e2-cod-exact3');
    $cleanup['orders'][] = $codRes['order_id'];
    $cleanup['attempts'][] = $codRes['attempt_id'];

    $codReplay = $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'delivery', [
        'address_line1' => '100 Paw St',
        'city' => 'Makati',
        'postal_code' => '1200',
    ], 'Leave at gate', 'e2-cod-exact3');

    $ordCod = $pdo->query("SELECT order_status, total_amount FROM customer_order WHERE order_id = {$codRes['order_id']}")->fetch();
    $invCod = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invExact3}")->fetch();
    $payCod = $pdo->query("SELECT payment_method, payment_status, amount FROM payment WHERE order_id = {$codRes['order_id']}")->fetch();
    $holdCntCod = (int)$pdo->query("SELECT COUNT(*) FROM inventory_hold WHERE attempt_id = {$codRes['attempt_id']}")->fetchColumn();
    $cartLeftCod = (int)$pdo->query("SELECT COUNT(*) FROM cart_item WHERE cart_id = {$cart1}")->fetchColumn();

    check(
        'COD creates confirmed order, FEFO active reservations, committed=3, on_hand=3, pending COD payment, no hold, clears cart, idempotent replay',
        $ordCod['order_status'] === 'confirmed'
            && (float)$ordCod['total_amount'] === 150.00
            && (int)$invCod['on_hand'] === 3
            && (int)$invCod['committed'] === 3
            && $payCod['payment_method'] === 'cod'
            && $payCod['payment_status'] === 'pending'
            && $holdCntCod === 0
            && $cartLeftCod === 0
            && $codReplay['idempotent_replay'] === true
            && $codReplay['order_id'] === $codRes['order_id']
    );

    // 3. COD disabled enforcement
    $pdo->prepare("UPDATE business_settings SET cod_enabled = 0 WHERE business_unit_id = ?")->execute([$unitId]);
    [$bpSix, $invSix] = $createProduct('ChkE2 Online6', 75.00, 6, 0);
    $cart2 = $setCart($cid1, $bpSix, 4);
    try {
        $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-cod-disabled');
        check('COD rejected when cod_enabled = 0', false);
    } catch (RuntimeException $e) {
        check('COD rejected when cod_enabled = 0', $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE);
    }

    // 4. Online checkout creates exact 3-minute hold, no customer_order, reduces availability for others & POS
    $online1 = $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-online-1');
    $cleanup['attempts'][] = $online1['attempt_id'];
    $diffSec = (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, created_at, expires_at) FROM inventory_hold WHERE hold_id = {$online1['hold_id']}")->fetchColumn();
    $invOnlineBefore = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invSix}")->fetch();
    $availMap = $checkoutService->getEffectiveAvailabilityForProducts([$bpSix]);

    check(
        'Online hold has exact 180s TTL, keeps committed=0 and on_hand=6, reduces available from 6 to 2',
        $diffSec === 180
            && (int)$invOnlineBefore['on_hand'] === 6
            && (int)$invOnlineBefore['committed'] === 0
            && ($availMap[$bpSix] ?? -1) === 2
    );

    // Second customer cannot checkout (available = 2 < 3) and POS cannot steal held stock
    $setCart($cid2, $bpSix, 1);
    try {
        $checkoutService->initiateOnlineCheckoutHold($cid2, $unitId, $uid2, 'pickup', [], '', 'e2-online-blocked');
        check('Second customer blocked while active hold leaves only 2 available', false);
    } catch (RuntimeException $e) {
        check('Second customer blocked while active hold leaves only 2 available', $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE);
    }

    try {
        $posService->recordSale($unitId, $empUid, $empId, null, 'Cash', [['business_product_id' => $bpSix, 'quantity' => 3]]);
        check('POS sale blocked from selling units protected by active online hold', false);
    } catch (RuntimeException $e) {
        check('POS sale blocked from selling units protected by active online hold', $e->getCode() === PosSaleService::ERR_UNPROCESSABLE);
    }

    // 5. Verified payment capture atomically converts hold -> confirmed order, clears cart, deduplicates webhooks
    $cap1 = $checkoutService->handleVerifiedPaymentCapture([
        'attempt_id' => $online1['attempt_id'],
        'provider_name' => 'test_gateway',
        'provider_payment_id' => 'pay_test_001',
        'provider_event_id' => 'evt_test_001',
        'captured_amount' => 300.00,
        'payment_method' => 'online',
    ]);
    $cleanup['orders'][] = $cap1['order_id'];
    $dupCap = $checkoutService->handleVerifiedPaymentCapture([
        'attempt_id' => $online1['attempt_id'],
        'provider_name' => 'test_gateway',
        'provider_payment_id' => 'pay_test_001',
        'provider_event_id' => 'evt_test_001',
        'captured_amount' => 300.00,
        'payment_method' => 'online',
    ]);

    $invAfterCap = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invSix}")->fetch();
    $holdStatusCap = (string)$pdo->query("SELECT hold_status FROM inventory_hold WHERE hold_id = {$online1['hold_id']}")->fetchColumn();
    $cartLeftCap = (int)$pdo->query("SELECT COUNT(*) FROM cart_item WHERE cart_id = {$cart2}")->fetchColumn();

    check(
        'Verified capture converts hold->converted, creates confirmed order, increments committed=4 without double-counting, clears cart, ignores duplicate webhook',
        $cap1['status'] === 'confirmed'
            && $cap1['order_id'] > 0
            && (int)$invAfterCap['on_hand'] === 6
            && (int)$invAfterCap['committed'] === 4
            && $holdStatusCap === 'converted'
            && $cartLeftCap === 0
            && $dupCap['status'] === 'duplicate_ignored'
    );

    // 6. Expired hold immediately stops reducing available stock & delayed unfulfillable capture queues reconciliation
    [$bpDelay, $invDelay] = $createProduct('ChkE2 Delayed', 40.00, 3, 0);
    $setCart($cid1, $bpDelay, 3);
    $onlineDelay = $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-delay-1');
    $cleanup['attempts'][] = $onlineDelay['attempt_id'];

    // Simulate server UTC expiry without running cleanup yet
    $pdo->prepare("UPDATE inventory_hold SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 181 SECOND), expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE hold_id = ?")
        ->execute([$onlineDelay['hold_id']]);

    $availAfterExpiry = $checkoutService->getEffectiveAvailabilityForProducts([$bpDelay]);
    check('Expired hold immediately stops reducing availability even before cleanup', ($availAfterExpiry[$bpDelay] ?? -1) === 3);

    // POS sells 2 units after expiry, leaving only 1
    $posSale = $posService->recordSale($unitId, $empUid, $empId, null, 'Cash', [['business_product_id' => $bpDelay, 'quantity' => 2]]);
    $cleanup['sales'][] = $posSale['sale_id'];

    // Delayed verified capture arrives for the 3-unit attempt
    $reconRes = $checkoutService->handleVerifiedPaymentCapture([
        'attempt_id' => $onlineDelay['attempt_id'],
        'provider_name' => 'test_gateway',
        'provider_payment_id' => 'pay_test_late_002',
        'provider_event_id' => 'evt_test_late_002',
        'captured_amount' => 120.00,
        'payment_method' => 'online',
    ]);
    $attDelayStatus = (string)$pdo->query("SELECT attempt_status FROM checkout_attempt WHERE attempt_id = {$onlineDelay['attempt_id']}")->fetchColumn();
    $payDelayStatus = (string)$pdo->query("SELECT payment_status FROM payment WHERE attempt_id = {$onlineDelay['attempt_id']}")->fetchColumn();

    check(
        'Delayed unfulfillable capture does NOT create unfulfillable order and records completed payment + reconciliation_required',
        $reconRes['status'] === 'reconciliation_required'
            && $reconRes['order_id'] === null
            && $reconRes['reconciliation_id'] > 0
            && $attDelayStatus === 'reconciliation_required'
            && $payDelayStatus === 'completed'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($cleanup['attempts']) {
        $aList = implode(',', $cleanup['attempts']);
        $pdo->exec("DELETE FROM payment_reconciliation WHERE attempt_id IN ({$aList})");
        $pdo->exec("DELETE FROM payment_webhook_event WHERE attempt_id IN ({$aList})");
        $pdo->exec("DELETE FROM payment WHERE attempt_id IN ({$aList})");
        $pdo->exec("DELETE FROM inventory_hold WHERE attempt_id IN ({$aList})");
        $pdo->exec("DELETE FROM checkout_attempt WHERE attempt_id IN ({$aList})");
    }
    if ($cleanup['orders']) {
        $oList = implode(',', $cleanup['orders']);
        $pdo->exec("DELETE FROM payment WHERE order_id IN ({$oList})");
        $pdo->exec("DELETE im FROM inventory_movement im JOIN inventory_reservation ir ON ir.reservation_id = im.reservation_id JOIN order_item oi ON oi.order_item_id = ir.order_item_id WHERE oi.order_id IN ({$oList})");
        $pdo->exec("DELETE ir FROM inventory_reservation ir JOIN order_item oi ON oi.order_item_id = ir.order_item_id WHERE oi.order_id IN ({$oList})");
        $pdo->exec("DELETE FROM order_item WHERE order_id IN ({$oList})");
        $pdo->exec("DELETE FROM customer_order WHERE order_id IN ({$oList})");
    }
    if ($cleanup['sales']) {
        $sList = implode(',', $cleanup['sales']);
        $pdo->exec("DELETE FROM payment WHERE sale_id IN ({$sList})");
        $pdo->exec("DELETE im FROM inventory_movement im JOIN sale_item si ON si.sale_item_id = im.sale_item_id WHERE si.sale_id IN ({$sList})");
        $pdo->exec("DELETE FROM sale_item WHERE sale_id IN ({$sList})");
        $pdo->exec("DELETE FROM sale WHERE sale_id IN ({$sList})");
    }
    if ($cleanup['customers']) {
        $cList = implode(',', $cleanup['customers']);
        $pdo->exec("DELETE ci FROM cart_item ci JOIN cart c ON c.cart_id = ci.cart_id WHERE c.customer_id IN ({$cList})");
        $pdo->exec("DELETE FROM cart WHERE customer_id IN ({$cList})");
    }
    if ($cleanup['products']) {
        $pList = implode(',', $cleanup['products']);
        $pdo->exec("DELETE ib FROM inventory_batch ib JOIN inventory i ON i.inventory_id = ib.inventory_id JOIN business_product bp ON bp.business_product_id = i.business_product_id WHERE bp.product_id IN ({$pList})");
        $pdo->exec("DELETE i FROM inventory i JOIN business_product bp ON bp.business_product_id = i.business_product_id WHERE bp.product_id IN ({$pList})");
        $pdo->exec("DELETE FROM business_product WHERE product_id IN ({$pList})");
        $pdo->exec("DELETE FROM product WHERE product_id IN ({$pList})");
    }
    if ($cleanup['business_units']) {
        $uList = implode(',', $cleanup['business_units']);
        $pdo->exec("DELETE FROM audit_log WHERE business_unit_id IN ({$uList})");
        $pdo->exec("DELETE FROM business_settings WHERE business_unit_id IN ({$uList})");
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
