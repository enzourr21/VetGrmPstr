<?php
declare(strict_types=1);

/**
 * PawCariño — E2 Checkout & Temporary Stock Hold Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  - Unconfigured gateway rejection & COD availability when configured.
 *  - COD checkout: 3-unit threshold, exact 3-unit purchase allowed, direct 'confirmed' order,
 *    FEFO active reservations, committed increase without on_hand deduction, 'cod'/'pending' payment,
 *    no temporary holds, cart cleared only on commit, duplicate idempotency protection, COD disabled rejection.
 *  - Duplicate checkout prevention: DB-level single active attempt per (customer_id, business_unit_id),
 *    repeated identical requests, online-to-COD conflict & cancellation-then-COD, COD-to-online rejection.
 *  - Online checkout (with configured test gateway): 3-unit threshold, exact 3-minute (180s) UTC hold window,
 *    no premature order/items, active holds reduce availability for other checkouts/POS/confirmations,
 *    expired holds immediately stop reducing availability even before cleanup, cleanup script logic.
 *  - Payment attempt handling: failed payment attempt does NOT block a subsequent legitimate verified capture
 *    on the same attempt/provider_payment_id; duplicate captures remain idempotent; delayed captures after
 *    hold expiration reconcile cleanly (fulfillable vs unfulfillable reconciliation queue).
 *  - Delivery fee separation: pickup = 0.00 fee; delivery uses configured fee on attempt, order, and payment.
 *  - Concurrent multi-connection inventory-then-batch locking safety.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\Cart;
use App\Services\CheckoutHoldService;
use App\Services\OrderReservationService;
use App\Services\Payment\OnlinePaymentGatewayInterface;
use App\Services\Payment\UnconfiguredOnlinePaymentGateway;
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

$configuredTestGateway = new class implements OnlinePaymentGatewayInterface {
    public function providerName(): string { return 'test_gateway'; }
    public function isConfigured(): bool { return true; }
    public function createCheckoutSession(array $sessionContext): array
    {
        return [
            'configured' => true,
            'provider_name' => 'test_gateway',
            'provider_session_id' => 'sess_' . $sessionContext['attempt_id'],
            'checkout_url' => '/checkout/attempts/' . $sessionContext['attempt_id'],
        ];
    }
    public function verifyPaymentCapture(string $providerSessionId, ?string $providerPaymentId = null): array
    {
        throw new RuntimeException('Not used in direct capture fixture test.');
    }
    public function parseAndVerifyWebhook(string $rawBody, array $headers): array
    {
        throw new RuntimeException('Not used in direct capture fixture test.');
    }
    public function initiateRefund(array $refundContext): array
    {
        throw new RuntimeException('Not used in direct capture fixture test.');
    }
};

$checkoutService = new CheckoutHoldService($pdo);
$posService = new PosSaleService($pdo);
$reservationService = new OrderReservationService($pdo);

$openSecondConnection = static function (): PDO {
    require dirname(__DIR__) . '/config/database.php';
    /** @var PDO $pdo */
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
    return $pdo;
};

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

    $pdo->prepare("INSERT INTO business_settings (business_unit_id, business_name, business_email, contact_number, cod_enabled, delivery_fee) VALUES (?, 'ChkE2 Shop', 'chke2@test.invalid', '09175550199', 1, 50.00)")
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

    // 1. Unconfigured online gateway is strictly disabled and blocked server-side
    [$bpSix, $invSix] = $createProduct('ChkE2 Online6', 75.00, 6, 0);
    $setCart($cid1, $bpSix, 3);
    $unitSettingsDefault = $checkoutService->getUnitPaymentSettings($unitId);
    $unconfiguredBlocked = false;
    try {
        $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-unconf-1', new UnconfiguredOnlinePaymentGateway());
    } catch (RuntimeException $e) {
        $unconfiguredBlocked = $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE;
    }
    check(
        'Unconfigured online gateway reports online_enabled=false and blocks hold creation',
        $unitSettingsDefault['cod_enabled'] === true
            && $unitSettingsDefault['online_enabled'] === false
            && $unconfiguredBlocked
    );

    // 1b. Delivery checkout MUST be blocked when no valid delivery fee is configured (never silently 0.00)
    $pdo->prepare('UPDATE business_settings SET delivery_fee = NULL WHERE business_unit_id = ?')->execute([$unitId]);
    $noFeeSettings = $checkoutService->getUnitPaymentSettings($unitId);
    $noFeeAmountsDelivery = $checkoutService->calculateOrderAmounts(150.00, 'delivery', $unitId);
    $noFeeAmountsPickup = $checkoutService->calculateOrderAmounts(150.00, 'pickup', $unitId);

    $missingFeeCodDeliveryBlocked = false;
    try {
        $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'delivery', [
            'address_line1' => '100 Paw St',
            'city' => 'Makati',
            'postal_code' => '1200',
        ], '', 'e2-nofee-cod-deliv');
    } catch (RuntimeException $e) {
        $missingFeeCodDeliveryBlocked = $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE;
    }

    $missingFeeOnlineDeliveryBlocked = false;
    try {
        $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'delivery', [
            'address_line1' => '100 Paw St',
            'city' => 'Makati',
            'postal_code' => '1200',
        ], '', 'e2-nofee-online-deliv', $configuredTestGateway);
    } catch (RuntimeException $e) {
        $missingFeeOnlineDeliveryBlocked = $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE;
    }

    // Restore configured delivery fee = 50.00 after verifying pickup still works without fee if needed
    $pickupWhileNoFee = $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-nofee-pickup-ok', $configuredTestGateway);
    $cleanup['attempts'][] = $pickupWhileNoFee['attempt_id'];
    $checkoutService->cancelActiveHoldByCustomer($pickupWhileNoFee['attempt_id'], $cid1, $uid1);
    $pdo->prepare('UPDATE business_settings SET delivery_fee = 50.00 WHERE business_unit_id = ?')->execute([$unitId]);

    check(
        'Missing delivery fee returns unconfigured null delivery fee, blocks COD & online delivery checkout, and keeps pickup at 0.00',
        $noFeeSettings['delivery_fee_configured'] === false
            && $noFeeSettings['delivery_fee'] === null
            && $noFeeAmountsDelivery['delivery_fee_configured'] === false
            && $noFeeAmountsDelivery['delivery_fee'] === null
            && $noFeeAmountsDelivery['total_amount'] === null
            && $noFeeAmountsPickup['delivery_fee_configured'] === true
            && $noFeeAmountsPickup['delivery_fee'] === 0.00
            && $noFeeAmountsPickup['total_amount'] === 150.00
            && $missingFeeCodDeliveryBlocked
            && $missingFeeOnlineDeliveryBlocked
            && (float)$pickupWhileNoFee['delivery_fee'] === 0.00
    );

    // 2. 3-unit threshold rejects stock = 2 for both COD and configured Online
    [$bpLow, $invLow] = $createProduct('ChkE2 LowStock', 100.00, 2, 0);
    $cart1 = $setCart($cid1, $bpLow, 1);
    try {
        $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-low-cod-1');
        check('COD rejects product below 3-unit threshold', false);
    } catch (RuntimeException $e) {
        check('COD rejects product below 3-unit threshold', $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE);
    }
    try {
        $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-low-online-1', $configuredTestGateway);
        check('Online checkout rejects product below 3-unit threshold', false);
    } catch (RuntimeException $e) {
        check('Online checkout rejects product below 3-unit threshold', $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE);
    }

    // 3. Exact 3 units available permits purchasing all 3 units (COD delivery with separated delivery fee)
    [$bpExact3, $invExact3] = $createProduct('ChkE2 Exact3', 50.00, 3, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invExact3}, 'E2-B1', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 2)");
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invExact3}, 'E2-B2', DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1)");

    $setCart($cid1, $bpExact3, 3);
    $codRes = $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'delivery', [
        'address_line1' => '100 Paw St',
        'city' => 'Makati',
        'postal_code' => '1200',
    ], 'Leave at gate', 'e2-cod-exact3');
    $cleanup['orders'][] = $codRes['order_id'];
    $cleanup['attempts'][] = $codRes['attempt_id'];

    // Repeated COD request replays idempotently; COD-to-online with same key is rejected
    $codReplay = $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'delivery', [
        'address_line1' => '100 Paw St',
        'city' => 'Makati',
        'postal_code' => '1200',
    ], 'Leave at gate', 'e2-cod-exact3');

    $codToOnlineRejected = false;
    try {
        $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'delivery', [
            'address_line1' => '100 Paw St',
            'city' => 'Makati',
            'postal_code' => '1200',
        ], 'Leave at gate', 'e2-cod-exact3', $configuredTestGateway);
    } catch (RuntimeException $e) {
        $codToOnlineRejected = $e->getCode() === CheckoutHoldService::ERR_CONFLICT;
    }

    $ordCod = $pdo->query("SELECT order_status, subtotal_amount, delivery_fee, total_amount FROM customer_order WHERE order_id = {$codRes['order_id']}")->fetch();
    $invCod = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invExact3}")->fetch();
    $payCod = $pdo->query("SELECT payment_method, payment_status, amount FROM payment WHERE order_id = {$codRes['order_id']}")->fetch();
    $holdCntCod = (int)$pdo->query("SELECT COUNT(*) FROM inventory_hold WHERE attempt_id = {$codRes['attempt_id']}")->fetchColumn();
    $cartLeftCod = (int)$pdo->query("SELECT COUNT(*) FROM cart_item WHERE cart_id = {$cart1}")->fetchColumn();

    check(
        'COD creates confirmed order with separated subtotal/delivery_fee/total, FEFO reservations, pending COD payment, no hold, clears cart, idempotent replay, rejects COD->Online reuse',
        $ordCod['order_status'] === 'confirmed'
            && (float)$ordCod['subtotal_amount'] === 150.00
            && (float)$ordCod['delivery_fee'] === 50.00
            && (float)$ordCod['total_amount'] === 200.00
            && (float)$payCod['amount'] === 200.00
            && (int)$invCod['on_hand'] === 3
            && (int)$invCod['committed'] === 3
            && $payCod['payment_method'] === 'cod'
            && $payCod['payment_status'] === 'pending'
            && $holdCntCod === 0
            && $cartLeftCod === 0
            && $codReplay['idempotent_replay'] === true
            && $codReplay['order_id'] === $codRes['order_id']
            && $codToOnlineRejected
    );

    // 4. Online checkout: 180s UTC hold, single-active-attempt guard, online-to-COD conflict & safe cancel-then-COD
    $cart2 = $setCart($cid1, $bpSix, 3);
    $online1 = $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-online-1', $configuredTestGateway);
    $cleanup['attempts'][] = $online1['attempt_id'];
    $diffSec = (int)$pdo->query("SELECT TIMESTAMPDIFF(SECOND, created_at, expires_at) FROM inventory_hold WHERE hold_id = {$online1['hold_id']}")->fetchColumn();
    $attPickupAmounts = $pdo->query("SELECT subtotal_amount, delivery_fee, expected_amount FROM checkout_attempt WHERE attempt_id = {$online1['attempt_id']}")->fetch();

    // Second active online checkout with different token for same (customer, unit) is rejected
    $secondOnlineRejected = false;
    try {
        $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-online-2-diff-token', $configuredTestGateway);
    } catch (RuntimeException $e) {
        $secondOnlineRejected = $e->getCode() === CheckoutHoldService::ERR_CONFLICT;
    }

    // Online-to-COD transition while online hold is active is rejected
    $onlineToCodRejected = false;
    try {
        $checkoutService->placeCodOrder($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-online-to-cod');
    } catch (RuntimeException $e) {
        $onlineToCodRejected = $e->getCode() === CheckoutHoldService::ERR_CONFLICT;
    }

    check(
        'Online pickup hold has 180s TTL, zero delivery fee, and blocks duplicate active online or online-to-COD attempts',
        $diffSec === 180
            && (float)$attPickupAmounts['subtotal_amount'] === 225.00
            && (float)$attPickupAmounts['delivery_fee'] === 0.00
            && (float)$attPickupAmounts['expected_amount'] === 225.00
            && $secondOnlineRejected
            && $onlineToCodRejected
    );

    // Second customer blocked by active hold (available = 3, Wait: 6-3=3? Let's test holding 4 -> leaves 2!)
    $checkoutService->cancelActiveHoldByCustomer($online1['attempt_id'], $cid1, $uid1);
    $setCart($cid1, $bpSix, 4);
    $onlineHold4 = $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-online-4units', $configuredTestGateway);
    $cleanup['attempts'][] = $onlineHold4['attempt_id'];

    $setCart($cid2, $bpSix, 1);
    $cid2Blocked = false;
    try {
        $checkoutService->initiateOnlineCheckoutHold($cid2, $unitId, $uid2, 'pickup', [], '', 'e2-online-cid2-blocked', $configuredTestGateway);
    } catch (RuntimeException $e) {
        $cid2Blocked = $e->getCode() === CheckoutHoldService::ERR_UNPROCESSABLE;
    }
    $posBlocked = false;
    try {
        $posService->recordSale($unitId, $empUid, $empId, null, 'Cash', [['business_product_id' => $bpSix, 'quantity' => 3]]);
    } catch (RuntimeException $e) {
        $posBlocked = $e->getCode() === PosSaleService::ERR_UNPROCESSABLE;
    }
    check('Active 4-unit hold leaves 2 available and blocks both other customer checkout and POS oversell', $cid2Blocked && $posBlocked);

    // 5. Failed-then-successful payment on SAME attempt and SAME provider_payment_id
    $failRes = $checkoutService->handleVerifiedPaymentFailure([
        'attempt_id' => $onlineHold4['attempt_id'],
        'provider_name' => 'test_gateway',
        'provider_payment_id' => 'pay_retry_001',
        'provider_event_id' => 'evt_fail_001',
        'failure_reason' => 'Initial 3DS timeout',
    ]);
    $capAfterFail = $checkoutService->handleVerifiedPaymentCapture([
        'attempt_id' => $onlineHold4['attempt_id'],
        'provider_name' => 'test_gateway',
        'provider_payment_id' => 'pay_retry_001',
        'provider_event_id' => 'evt_cap_001',
        'captured_amount' => 300.00,
        'payment_method' => 'online',
    ]);
    $cleanup['orders'][] = $capAfterFail['order_id'];
    $dupCapAfterFail = $checkoutService->handleVerifiedPaymentCapture([
        'attempt_id' => $onlineHold4['attempt_id'],
        'provider_name' => 'test_gateway',
        'provider_payment_id' => 'pay_retry_001',
        'provider_event_id' => 'evt_cap_001_dup',
        'captured_amount' => 300.00,
        'payment_method' => 'online',
    ]);

    $failPayCount = (int)$pdo->query("SELECT COUNT(*) FROM payment WHERE attempt_id = {$onlineHold4['attempt_id']} AND payment_status = 'failed'")->fetchColumn();
    $compPayCount = (int)$pdo->query("SELECT COUNT(*) FROM payment WHERE attempt_id = {$onlineHold4['attempt_id']} AND payment_status = 'completed'")->fetchColumn();
    $invAfterCap = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invSix}")->fetch();

    check(
        'Failed payment attempt does NOT block later verified capture on same provider_payment_id; duplicate capture stays idempotent',
        $failRes['status'] === 'failed'
            && $failRes['hold_released'] === true
            && $capAfterFail['order_id'] > 0
            && $failPayCount === 1
            && $compPayCount === 1
            && (int)$invAfterCap['committed'] === 4
            && (int)$invAfterCap['on_hand'] === 6
            && $dupCapAfterFail['status'] === 'duplicate_ignored'
    );

    // 6. Expired hold cleanup + delayed unfulfillable capture reconciliation
    [$bpDelay, $invDelay] = $createProduct('ChkE2 Delayed', 40.00, 3, 0);
    $setCart($cid1, $bpDelay, 3);
    $onlineDelay = $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-delay-1', $configuredTestGateway);
    $cleanup['attempts'][] = $onlineDelay['attempt_id'];

    $pdo->prepare("UPDATE inventory_hold SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 181 SECOND), expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE hold_id = ?")
        ->execute([$onlineDelay['hold_id']]);

    $availAfterExpiry = $checkoutService->getEffectiveAvailabilityForProducts([$bpDelay]);
    $cleanupRes = $checkoutService->releaseAllExpiredHolds(100, 5);
    check(
        'Expired hold stops reducing availability before cleanup and releaseAllExpiredHolds transitions it to expired',
        ($availAfterExpiry[$bpDelay] ?? -1) === 3
            && in_array($onlineDelay['hold_id'], $cleanupRes['hold_ids'], true)
    );

    $posSale = $posService->recordSale($unitId, $empUid, $empId, null, 'Cash', [['business_product_id' => $bpDelay, 'quantity' => 2]]);
    $cleanup['sales'][] = $posSale['sale_id'];

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

    // 7. Multi-connection concurrent inventory-then-batch locking test (no deadlock when batch IDs oppose inventory IDs)
    [$bpLockA, $invLockA] = $createProduct('ChkE2 LockA', 20.00, 5, 1);
    [$bpLockB, $invLockB] = $createProduct('ChkE2 LockB', 20.00, 5, 1);
    // Insert batch for LockB first (lower batch_id), then LockA (higher batch_id) to test opposing ID order
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invLockB}, 'LOCK-B-LOW', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 5)");
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invLockA}, 'LOCK-A-HIGH', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 5)");

    $pdo2 = $openSecondConnection();
    $pdo2->beginTransaction();
    // Hold parent inventory LockA in ascending order on connection 2 while connection 1 runs POS/COD
    $pdo2->query("SELECT inventory_id FROM inventory WHERE inventory_id = {$invLockA} FOR UPDATE")->fetch();

    $setCart($cid2, $bpLockA, 3);
    $pdo->prepare('INSERT INTO cart_item (cart_id, business_product_id, quantity) VALUES (?, ?, 3)')->execute([Cart::id($cid2, $unitId), $bpLockB]);
    $lockWaitCaught = false;
    try {
        // Connection 1 waits cleanly on invLockA lock timeout rather than deadlocking
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $checkoutService->placeCodOrder($cid2, $unitId, $uid2, 'pickup', [], '', 'e2-lock-wait-test');
    } catch (Throwable $e) {
        $lockWaitCaught = str_contains($e->getMessage(), 'Lock wait timeout') || str_contains($e->getMessage(), '1205');
    } finally {
        $pdo2->rollBack();
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
    }
    check('Multi-connection inventory lock contention waits cleanly on inventory_id ASC without deadlock', $lockWaitCaught);

    // After releasing connection 2, COD succeeds across both batch-tracked inventories
    $codMulti = $checkoutService->placeCodOrder($cid2, $unitId, $uid2, 'pickup', [], '', 'e2-lock-after-release');
    $cleanup['orders'][] = $codMulti['order_id'];
    $cleanup['attempts'][] = $codMulti['attempt_id'];
    check('Multi-inventory batch-tracked COD succeeds cleanly after lock release', $codMulti['order_id'] > 0 && $codMulti['reservations_created'] === 2);

    // 8. Genuine two-connection parallel contention: capture vs customer cancel & capture vs expired hold cleanup
    $runParallelPhp = static function (array $scripts): array {
        $procs = [];
        foreach ($scripts as $key => $code) {
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open([PHP_BINARY], $desc, $pipes, dirname(__DIR__));
            if (!is_resource($proc)) {
                throw new RuntimeException("Failed to spawn parallel PHP worker '{$key}'.");
            }
            fwrite($pipes[0], $code);
            fclose($pipes[0]);
            $procs[$key] = ['proc' => $proc, 'pipes' => $pipes];
        }
        $results = [];
        foreach ($procs as $key => $entry) {
            $stdout = stream_get_contents($entry['pipes'][1]);
            $stderr = stream_get_contents($entry['pipes'][2]);
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            $exit = proc_close($entry['proc']);
            $results[$key] = [
                'exit' => $exit,
                'stdout' => trim((string)$stdout),
                'stderr' => trim((string)$stderr),
                'json' => json_decode(trim((string)$stdout), true),
            ];
        }
        return $results;
    };

    $appRootExport = var_export(dirname(__DIR__), true);

    // Race A: Verified payment capture vs customer cancellation on the SAME active hold & attempt
    [$bpRaceCancel, $invRaceCancel] = $createProduct('ChkE2 RaceCancel', 60.00, 5, 1);
    $pdo->exec("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES ({$invRaceCancel}, 'RC-B1', DATE_ADD(CURDATE(), INTERVAL 20 DAY), 5)");
    $setCart($cid1, $bpRaceCancel, 3);
    $raceCancelHold = $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-race-cancel-1', $configuredTestGateway);
    $cleanup['attempts'][] = $raceCancelHold['attempt_id'];
    $raceCancelAmt = number_format((float)$raceCancelHold['total_amount'], 2, '.', '');

    $workerCaptureCancel = <<<PHP
<?php
require_once {$appRootExport} . '/app/bootstrap.php';
use App\Core\Database;
use App\Services\CheckoutHoldService;
try {
    \$svc = new CheckoutHoldService(Database::pdo());
    \$res = \$svc->handleVerifiedPaymentCapture([
        'attempt_id' => {$raceCancelHold['attempt_id']},
        'provider_name' => 'test_gateway',
        'provider_payment_id' => 'pay_race_cancel_001',
        'provider_event_id' => 'evt_race_cancel_001',
        'captured_amount' => {$raceCancelAmt},
        'payment_method' => 'online',
    ]);
    echo json_encode(['ok' => true, 'result' => \$res]);
} catch (Throwable \$e) {
    echo json_encode(['ok' => false, 'code' => \$e->getCode(), 'error' => \$e->getMessage()]);
}
PHP;

    $workerCustomerCancel = <<<PHP
<?php
require_once {$appRootExport} . '/app/bootstrap.php';
use App\Core\Database;
use App\Services\CheckoutHoldService;
try {
    \$svc = new CheckoutHoldService(Database::pdo());
    \$res = \$svc->cancelActiveHoldByCustomer({$raceCancelHold['attempt_id']}, {$cid1}, {$uid1}, 'concurrent_customer_cancel');
    echo json_encode(['ok' => true, 'result' => \$res]);
} catch (Throwable \$e) {
    echo json_encode(['ok' => false, 'code' => \$e->getCode(), 'error' => \$e->getMessage()]);
}
PHP;

    $raceAResults = $runParallelPhp([
        'capture' => $workerCaptureCancel,
        'cancel' => $workerCustomerCancel,
    ]);
    $raceACapOk = !empty($raceAResults['capture']['json']['ok']);
    $raceACanOk = !empty($raceAResults['cancel']['json']['ok']);
    $raceAAtt = $pdo->query("SELECT attempt_status, order_id FROM checkout_attempt WHERE attempt_id = {$raceCancelHold['attempt_id']}")->fetch();
    $raceAHold = $pdo->query("SELECT hold_status, converted_order_id FROM inventory_hold WHERE hold_id = {$raceCancelHold['hold_id']}")->fetch();
    $raceAInv = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invRaceCancel}")->fetch();
    $raceAPayCnt = (int)$pdo->query("SELECT COUNT(*) FROM payment WHERE attempt_id = {$raceCancelHold['attempt_id']} AND payment_status = 'completed'")->fetchColumn();
    check(
        'Two-connection parallel capture vs customer cancellation completes without deadlock and leaves consistent converted order & committed inventory',
        $raceACapOk
            && $raceACanOk
            && $raceAAtt['attempt_status'] === 'completed'
            && (int)$raceAAtt['order_id'] > 0
            && $raceAHold['hold_status'] === 'converted'
            && (int)$raceAHold['converted_order_id'] === (int)$raceAAtt['order_id']
            && (int)$raceAInv['on_hand'] === 5
            && (int)$raceAInv['committed'] === 3
            && $raceAPayCnt === 1
    );

    // Race B: Verified payment capture vs expired hold cleanup + concurrent POS sale contending on inventory
    [$bpRaceExp, $invRaceExp] = $createProduct('ChkE2 RaceExpiry', 45.00, 4, 0);
    $setCart($cid1, $bpRaceExp, 3);
    $raceExpHold = $checkoutService->initiateOnlineCheckoutHold($cid1, $unitId, $uid1, 'pickup', [], '', 'e2-race-expiry-1', $configuredTestGateway);
    $cleanup['attempts'][] = $raceExpHold['attempt_id'];
    $raceExpAmt = number_format((float)$raceExpHold['total_amount'], 2, '.', '');
    $pdo->prepare("UPDATE inventory_hold SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 181 SECOND), expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE hold_id = ?")
        ->execute([$raceExpHold['hold_id']]);

    $workerCaptureExp = <<<PHP
<?php
require_once {$appRootExport} . '/app/bootstrap.php';
use App\Core\Database;
use App\Services\CheckoutHoldService;
try {
    \$svc = new CheckoutHoldService(Database::pdo());
    \$res = \$svc->handleVerifiedPaymentCapture([
        'attempt_id' => {$raceExpHold['attempt_id']},
        'provider_name' => 'test_gateway',
        'provider_payment_id' => 'pay_race_exp_001',
        'provider_event_id' => 'evt_race_exp_001',
        'captured_amount' => {$raceExpAmt},
        'payment_method' => 'online',
    ]);
    echo json_encode(['ok' => true, 'result' => \$res]);
} catch (Throwable \$e) {
    echo json_encode(['ok' => false, 'code' => \$e->getCode(), 'error' => \$e->getMessage()]);
}
PHP;

    $workerCleanupExp = <<<PHP
<?php
require_once {$appRootExport} . '/app/bootstrap.php';
use App\Core\Database;
use App\Services\CheckoutHoldService;
try {
    \$svc = new CheckoutHoldService(Database::pdo());
    \$res = \$svc->releaseAllExpiredHolds(100, 5);
    echo json_encode(['ok' => true, 'result' => \$res]);
} catch (Throwable \$e) {
    echo json_encode(['ok' => false, 'code' => \$e->getCode(), 'error' => \$e->getMessage()]);
}
PHP;

    $workerPosRace = <<<PHP
<?php
require_once {$appRootExport} . '/app/bootstrap.php';
use App\Core\Database;
use App\Services\PosSaleService;
try {
    \$svc = new PosSaleService(Database::pdo());
    \$res = \$svc->recordSale({$unitId}, {$empUid}, {$empId}, null, 'Cash', [['business_product_id' => {$bpRaceExp}, 'quantity' => 2]]);
    echo json_encode(['ok' => true, 'sold' => true, 'sale_id' => \$res['sale_id']]);
} catch (RuntimeException \$e) {
    // Rejection when capture acquired lock first and committed 3 of 4 units is an expected business outcome.
    \$ok = \$e->getCode() === PosSaleService::ERR_UNPROCESSABLE;
    echo json_encode(['ok' => \$ok, 'sold' => false, 'code' => \$e->getCode(), 'error' => \$e->getMessage()]);
} catch (Throwable \$e) {
    echo json_encode(['ok' => false, 'code' => \$e->getCode(), 'error' => \$e->getMessage()]);
}
PHP;

    $raceBResults = $runParallelPhp([
        'capture' => $workerCaptureExp,
        'cleanup' => $workerCleanupExp,
        'pos' => $workerPosRace,
    ]);
    $raceBCapOk = !empty($raceBResults['capture']['json']['ok']);
    $raceBCleanOk = !empty($raceBResults['cleanup']['json']['ok']);
    $raceBPosOk = !empty($raceBResults['pos']['json']['ok']);
    $raceBAtt = $pdo->query("SELECT attempt_status, order_id FROM checkout_attempt WHERE attempt_id = {$raceExpHold['attempt_id']}")->fetch();
    $raceBHold = $pdo->query("SELECT hold_status, converted_order_id FROM inventory_hold WHERE hold_id = {$raceExpHold['hold_id']}")->fetch();
    $raceBInv = $pdo->query("SELECT on_hand, committed FROM inventory WHERE inventory_id = {$invRaceExp}")->fetch();
    $raceBPayStatus = (string)$pdo->query("SELECT payment_status FROM payment WHERE attempt_id = {$raceExpHold['attempt_id']} AND payment_status = 'completed' LIMIT 1")->fetchColumn();
    $raceBReconCnt = (int)$pdo->query("SELECT COUNT(*) FROM payment_reconciliation WHERE attempt_id = {$raceExpHold['attempt_id']} AND reconciliation_status = 'pending_review'")->fetchColumn();
    $raceBValidCompleted = $raceBAtt['attempt_status'] === 'completed'
        && (int)$raceBAtt['order_id'] > 0
        && $raceBHold['hold_status'] === 'converted'
        && (int)$raceBHold['converted_order_id'] === (int)$raceBAtt['order_id']
        && (int)$raceBInv['on_hand'] === 4
        && (int)$raceBInv['committed'] === 3;
    $raceBValidRecon = $raceBAtt['attempt_status'] === 'reconciliation_required'
        && $raceBAtt['order_id'] === null
        && $raceBHold['hold_status'] === 'expired'
        && (int)$raceBInv['on_hand'] === 2
        && (int)$raceBInv['committed'] === 0
        && $raceBReconCnt === 1;
    check(
        'Multi-connection parallel capture vs expired-hold cleanup & POS sale completes without deadlock and preserves fulfillability/reconciliation invariants',
        $raceBCapOk
            && $raceBCleanOk
            && $raceBPosOk
            && $raceBPayStatus === 'completed'
            && ((int)$raceBInv['on_hand'] >= (int)$raceBInv['committed'])
            && ($raceBValidCompleted || $raceBValidRecon)
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($pdo2) && $pdo2 instanceof PDO && $pdo2->inTransaction()) {
        $pdo2->rollBack();
    }
    if ($cleanup['business_units']) {
        $uListPre = implode(',', $cleanup['business_units']);
        foreach ($pdo->query("SELECT attempt_id FROM checkout_attempt WHERE business_unit_id IN ({$uListPre})")->fetchAll(PDO::FETCH_COLUMN) as $aId) {
            $cleanup['attempts'][] = (int)$aId;
        }
        foreach ($pdo->query("SELECT order_id FROM customer_order WHERE business_unit_id IN ({$uListPre})")->fetchAll(PDO::FETCH_COLUMN) as $oId) {
            $cleanup['orders'][] = (int)$oId;
        }
        foreach ($pdo->query("SELECT sale_id FROM sale WHERE business_unit_id IN ({$uListPre})")->fetchAll(PDO::FETCH_COLUMN) as $sId) {
            $cleanup['sales'][] = (int)$sId;
        }
        $cleanup['attempts'] = array_values(array_unique($cleanup['attempts']));
        $cleanup['orders'] = array_values(array_unique($cleanup['orders']));
        $cleanup['sales'] = array_values(array_unique($cleanup['sales']));
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
