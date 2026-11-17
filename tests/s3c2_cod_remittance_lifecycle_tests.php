<?php
declare(strict_types=1);

/**
 * PawCariño — Stage S3-C2: Shared COD Remittance Lifecycle Verification Suite
 *
 * Targets only the explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies:
 *  1. Receptionist handover completion with auto_verify_cod_remittance OFF (0) and ON (1).
 *  2. Business Manager direct collection (verification_mode = 'manager_collection').
 *  3. Business Manager manual verification (verification_mode = 'manual') preserving original completion metadata.
 *  4. Partial batch progress and all three derived header states ('submitted', 'completed', 'verified').
 *  5. Single verifier vs. mixed verifier identities and NULL batch verifier behavior.
 *  6. Self-handover / self-verification rejection and strict Pet Store (unit 1) role/account/employee guards.
 *  7. Duplicate, out-of-order, and concurrent transition protection (ERR_CONFLICT).
 *  8. Full transaction rollback on mid-transaction failure after allocation update.
 *  9. Unchanged customer payment status, customer order status, COD collection metadata, and allocation amounts.
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

final class S3c2FaultInjector
{
    public ?Closure $beforeExecute = null;
}

final class S3c2FailureInjectingStatement extends PDOStatement
{
    private S3c2FaultInjector $injector;

    protected function __construct(S3c2FaultInjector $injector)
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

$unitId = OrderPaymentService::PET_STORE_UNIT_ID; // 1
$reservationService = new OrderReservationService($pdo);
$paymentService = new OrderPaymentService($pdo);
$suffix = bin2hex(random_bytes(4));

$cleanup = [
    'remittances' => [],
    'orders' => [],
    'products' => [],
    'branches' => [],
    'customers' => [],
    'employees' => [],
    'users' => [],
    'business_units' => [],
    'created_unit1_settings' => false,
    'orig_unit1_auto_verify' => 0,
];

try {
    // Snapshot Pet Store (unit 1) business_settings so we restore it identically in finally
    $bsStmt = $pdo->prepare('SELECT setting_id, auto_verify_cod_remittance FROM business_settings WHERE business_unit_id = ? LIMIT 1');
    $bsStmt->execute([$unitId]);
    $bsRow = $bsStmt->fetch(PDO::FETCH_ASSOC);
    if ($bsRow) {
        $cleanup['orig_unit1_auto_verify'] = (int)$bsRow['auto_verify_cod_remittance'];
    } else {
        $pdo->prepare(
            "INSERT INTO business_settings (business_unit_id, business_name, business_email, contact_number, cod_enabled, auto_verify_cod_remittance)
             VALUES (?, 'Pet Store Test Settings', 'petstore@vetgrpet.local', '09175550100', 1, 0)"
        )->execute([$unitId]);
        $cleanup['created_unit1_settings'] = true;
    }

    $setAutoVerify = static function (int $val) use ($pdo, $unitId): void {
        $pdo->prepare('UPDATE business_settings SET auto_verify_cod_remittance = ? WHERE business_unit_id = ?')
            ->execute([$val, $unitId]);
    };
    $setAutoVerify(0);

    // Foreign unit for cross-unit isolation checks
    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkS3C2 Foreign Unit {$suffix}', 'active')");
    $foreignUnitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $foreignUnitId;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, branch_status) VALUES ({$unitId}, 'ChkS3C2 Pet Store Branch {$suffix}', 'active')");
    $branchId = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchId;

    $roleIds = [];
    foreach (['DELIVERY_PERSONNEL', 'RECEPTIONIST', 'BUSINESS_MANAGER', 'SUPER_ADMINISTRATOR'] as $rName) {
        $rId = (int)$pdo->query("SELECT role_id FROM role WHERE role_name = '{$rName}' LIMIT 1")->fetchColumn();
        if ($rId <= 0) {
            throw new RuntimeException("Required role {$rName} missing in database.");
        }
        $roleIds[$rName] = $rId;
    }

    $createStaff = static function (
        string $tag,
        int $empUnitId,
        array $unitRoles,
        string $empStatus = 'active',
        string $accStatus = 'active',
        int $isSuperAdmin = 0
    ) use ($pdo, $suffix, $roleIds, &$cleanup): array {
        $email = "s3c2_{$tag}_{$suffix}@test.invalid";
        $pdo->prepare('INSERT INTO user_account (email, password_hash, account_status, is_super_admin) VALUES (?, ?, ?, ?)')
            ->execute([$email, 'hash', $accStatus, $isSuperAdmin]);
        $uid = (int)$pdo->lastInsertId();
        $cleanup['users'][] = $uid;

        $empId = 0;
        if ($empUnitId > 0) {
            $pdo->prepare(
                'INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, employee_status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$uid, $empUnitId, 'S3C2', $tag, $tag, $empStatus]);
            $empId = (int)$pdo->lastInsertId();
            $cleanup['employees'][] = $empId;
        }

        foreach ($unitRoles as [$rName, $rUnitId]) {
            $pdo->prepare('INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)')
                ->execute([$uid, $roleIds[$rName], $rUnitId]);
        }

        return [$uid, $empId];
    };

    // Customer
    $pdo->prepare('INSERT INTO user_account (email, password_hash, account_status) VALUES (?, ?, ?)')
        ->execute(["s3c2_cust_{$suffix}@test.invalid", 'hash', 'active']);
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->prepare('INSERT INTO customer (user_account_id, first_name, last_name) VALUES (?, ?, ?)')
        ->execute([$custUid, 'S3C2', 'Customer']);
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    // Staff actors
    [$courierUid, $courierEmpId] = $createStaff('courier_dual', $unitId, [
        ['DELIVERY_PERSONNEL', $unitId],
        ['RECEPTIONIST', $unitId],
        ['BUSINESS_MANAGER', $unitId],
    ]);
    [$courierOnlyUid, $courierOnlyEmpId] = $createStaff('courier_only', $unitId, [
        ['DELIVERY_PERSONNEL', $unitId],
    ]);
    [$rec1Uid, $rec1EmpId] = $createStaff('rec1', $unitId, [
        ['RECEPTIONIST', $unitId],
    ]);
    [$rec2Uid, $rec2EmpId] = $createStaff('rec2', $unitId, [
        ['RECEPTIONIST', $unitId],
    ]);
    [$bm1Uid, $bm1EmpId] = $createStaff('bm1', $unitId, [
        ['BUSINESS_MANAGER', $unitId],
    ]);
    [$bm2Uid, $bm2EmpId] = $createStaff('bm2', $unitId, [
        ['BUSINESS_MANAGER', $unitId],
    ]);
    [$foreignStaffUid] = $createStaff('foreign_staff', $foreignUnitId, [
        ['RECEPTIONIST', $unitId],
        ['BUSINESS_MANAGER', $unitId],
        ['RECEPTIONIST', $foreignUnitId],
        ['BUSINESS_MANAGER', $foreignUnitId],
    ]);
    [$inactiveEmpUid] = $createStaff('inactive_emp', $unitId, [
        ['RECEPTIONIST', $unitId],
        ['BUSINESS_MANAGER', $unitId],
    ], 'inactive', 'active');
    [$inactiveAccUid] = $createStaff('inactive_acc', $unitId, [
        ['RECEPTIONIST', $unitId],
        ['BUSINESS_MANAGER', $unitId],
    ], 'active', 'inactive');
    [$superNoEmpUid] = $createStaff('super_no_emp', 0, [
        ['SUPER_ADMINISTRATOR', $unitId],
    ], 'active', 'active', 1);

    // Product & Inventory for order creation
    $pdo->prepare("INSERT INTO product (product_name, product_category, sku) VALUES (?, 'Food', ?)")
        ->execute(["S3C2 Kibble {$suffix}", "SKU-S3C2-{$suffix}"]);
    $prodId = (int)$pdo->lastInsertId();
    $cleanup['products'][] = $prodId;
    $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, requires_batch_tracking, product_status) VALUES (?, ?, 50.00, 0, 'active')")
        ->execute([$unitId, $prodId]);
    $bpId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO inventory (business_product_id, on_hand, committed, in_transit) VALUES (?, 500, 0, 0)')
        ->execute([$bpId]);

    /**
     * Create a completed delivery order with confirmed courier_cod payment.
     *
     * @return array{order_id:int, payment_id:int}
     */
    $createCollectedDeliveryOrder = static function (string $subtotal, string $fee) use (
        $pdo,
        $unitId,
        $branchId,
        $custId,
        $bpId,
        $courierEmpId,
        $courierUid,
        $rec1Uid,
        $reservationService,
        $paymentService,
        &$cleanup
    ): array {
        $total = number_format((float)$subtotal + (float)$fee, 2, '.', '');
        $pdo->prepare(
            "INSERT INTO customer_order
             (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, subtotal_amount, delivery_fee, total_amount)
             VALUES (?, ?, ?, 'delivery', 'pending', ?, ?, ?)"
        )->execute([$custId, $unitId, $branchId, $subtotal, $fee, $total]);
        $orderId = (int)$pdo->lastInsertId();
        $cleanup['orders'][] = $orderId;

        $pdo->prepare('INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, 1, ?)')
            ->execute([$orderId, $bpId, $subtotal]);

        $pdo->prepare(
            "INSERT INTO payment (order_id, payment_type, amount, payment_date, payment_method, payment_status)
             VALUES (?, 'payment', ?, NOW(), 'cod', 'pending')"
        )->execute([$orderId, $total]);
        $paymentId = (int)$pdo->lastInsertId();

        $reservationService->confirmDeliveryOrderAndReserve($orderId, $unitId, $rec1Uid);
        $reservationService->markDeliveryOrderProcessing($orderId, $unitId, $rec1Uid);
        $reservationService->dispatchDeliveryOrder($orderId, $unitId, $courierEmpId, $courierUid);
        $reservationService->completeDeliveryOrder(
            $orderId,
            $unitId,
            $courierEmpId,
            $courierUid,
            static function () use ($paymentService, $orderId, $unitId, $courierEmpId, $courierUid): void {
                $paymentService->collectDeliveryCodInTransaction($orderId, $unitId, $courierEmpId, $courierUid);
            }
        );

        return ['order_id' => $orderId, 'payment_id' => $paymentId];
    };

    /**
     * Submit a courier COD remittance batch and return batch + sorted allocation IDs.
     *
     * @param list<int> $paymentIds
     * @return array{remittance_id:int, allocation_ids:list<int>}
     */
    $createSubmittedBatch = static function (array $paymentIds) use (
        $pdo,
        $unitId,
        $courierEmpId,
        $courierUid,
        $paymentService,
        &$cleanup
    ): array {
        $res = $paymentService->submitCourierCodRemittance($unitId, $courierEmpId, $courierUid, $paymentIds);
        $remitId = (int)$res['remittance_id'];
        $cleanup['remittances'][] = $remitId;

        $stmt = $pdo->prepare('SELECT allocation_id FROM courier_cod_remittance_allocation WHERE remittance_id = ? ORDER BY allocation_id ASC');
        $stmt->execute([$remitId]);
        $allocIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return ['remittance_id' => $remitId, 'allocation_ids' => $allocIds];
    };

    // =========================================================================
    // 1 & 2. Receptionist handover completion (auto_verify OFF) + Partial batch
    //        progress ('submitted' -> 'completed') + BM Manual Verification
    //        preserving original completion metadata ('completed' -> 'verified')
    // =========================================================================
    $setAutoVerify(0);
    $oA1 = $createCollectedDeliveryOrder('100.00', '50.00');
    $oA2 = $createCollectedDeliveryOrder('150.00', '50.00');
    $batchA = $createSubmittedBatch([$oA1['payment_id'], $oA2['payment_id']]);
    [$allocA1, $allocA2] = $batchA['allocation_ids'];
    $remitA = $batchA['remittance_id'];

    // Step 1a: Receptionist 1 completes allocA1 with auto_verify OFF -> batch stays 'submitted'
    $resA1 = $paymentService->completeRemittanceAllocation($allocA1, $rec1Uid);
    $hdrAfterA1 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitA}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Receptionist handover (auto_verify OFF) marks first allocation completed/pending and keeps partial batch in submitted state',
        $resA1['handover_status'] === 'completed'
            && $resA1['completed_by_employee_id'] === $rec1EmpId
            && $resA1['completed_at'] !== null
            && $resA1['verification_status'] === 'pending'
            && $resA1['verified_by_employee_id'] === null
            && $resA1['verified_at'] === null
            && $resA1['verification_mode'] === null
            && $resA1['remittance_status'] === 'submitted'
            && $hdrAfterA1['remittance_status'] === 'submitted'
            && $hdrAfterA1['verified_total'] === null
            && $hdrAfterA1['verified_by_employee_id'] === null
            && $hdrAfterA1['verified_at'] === null
    );

    // Pin a distinct historical completed_at on allocA1 so we can prove manual verification preserves it
    $pinnedCompletedAtA1 = '2026-01-10 08:30:00';
    $pdo->prepare('UPDATE courier_cod_remittance_allocation SET completed_at = ? WHERE allocation_id = ?')
        ->execute([$pinnedCompletedAtA1, $allocA1]);

    // Step 1b: Receptionist 2 completes allocA2 with auto_verify OFF -> batch transitions to 'completed'
    $resA2 = $paymentService->completeRemittanceAllocation($allocA2, $rec2Uid);
    $hdrAfterA2 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitA}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Completing remaining allocation handover with pending verification transitions batch header to completed with NULL verified metadata',
        $resA2['handover_status'] === 'completed'
            && $resA2['completed_by_employee_id'] === $rec2EmpId
            && $resA2['verification_status'] === 'pending'
            && $resA2['remittance_status'] === 'completed'
            && $hdrAfterA2['remittance_status'] === 'completed'
            && $hdrAfterA2['verified_total'] === null
            && $hdrAfterA2['verified_by_employee_id'] === null
            && $hdrAfterA2['verified_at'] === null
    );

    // Step 2a: BM 1 manually verifies allocA1 -> preserves rec1EmpId + pinnedCompletedAtA1; batch stays 'completed' while allocA2 is pending
    $verA1 = $paymentService->verifyRemittanceAllocation($allocA1, $bm1Uid);
    $hdrAfterVerA1 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitA}")->fetch(PDO::FETCH_ASSOC);

    check(
        'BM manual verification sets verification_mode=manual, preserves original completion actor & timestamp, and keeps partially verified batch in completed state',
        $verA1['handover_status'] === 'completed'
            && $verA1['completed_by_employee_id'] === $rec1EmpId
            && $verA1['completed_at'] === $pinnedCompletedAtA1
            && $verA1['verification_status'] === 'verified'
            && $verA1['verified_by_employee_id'] === $bm1EmpId
            && $verA1['verified_at'] !== null
            && $verA1['verified_at'] !== $pinnedCompletedAtA1
            && $verA1['verification_mode'] === 'manual'
            && $verA1['remittance_status'] === 'completed'
            && $hdrAfterVerA1['remittance_status'] === 'completed'
            && $hdrAfterVerA1['verified_total'] === null
            && $hdrAfterVerA1['verified_by_employee_id'] === null
            && $hdrAfterVerA1['verified_at'] === null
    );

    // Step 2b: BM 1 manually verifies allocA2 -> all active allocations verified by same BM1 -> batch becomes 'verified' with verified_by_employee_id = bm1EmpId
    $origCompletedAtA2 = $resA2['completed_at'];
    $verA2 = $paymentService->verifyRemittanceAllocation($allocA2, $bm1Uid);
    $hdrAfterVerA2 = $pdo->query("SELECT remittance_status, expected_total, remitted_total, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitA}")->fetch(PDO::FETCH_ASSOC);

    check(
        'BM manual verification of final allocation transitions batch to verified, sets verified_total=expected_total, and populates shared batch verifier',
        $verA2['completed_by_employee_id'] === $rec2EmpId
            && $verA2['completed_at'] === $origCompletedAtA2
            && $verA2['verification_status'] === 'verified'
            && $verA2['verified_by_employee_id'] === $bm1EmpId
            && $verA2['verification_mode'] === 'manual'
            && $verA2['remittance_status'] === 'verified'
            && $hdrAfterVerA2['remittance_status'] === 'verified'
            && (string)$hdrAfterVerA2['verified_total'] === '250.00'
            && (string)$hdrAfterVerA2['expected_total'] === '250.00'
            && (int)$hdrAfterVerA2['verified_by_employee_id'] === $bm1EmpId
            && $hdrAfterVerA2['verified_at'] !== null
    );

    // =========================================================================
    // 3. Receptionist handover completion with auto_verify ON (1)
    // =========================================================================
    $setAutoVerify(1);
    $oB1 = $createCollectedDeliveryOrder('120.00', '40.00');
    $oB2 = $createCollectedDeliveryOrder('80.00', '40.00');
    $batchB = $createSubmittedBatch([$oB1['payment_id'], $oB2['payment_id']]);
    [$allocB1, $allocB2] = $batchB['allocation_ids'];
    $remitB = $batchB['remittance_id'];

    $resB1 = $paymentService->completeRemittanceAllocation($allocB1, $rec1Uid);
    $hdrAfterB1 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitB}")->fetch(PDO::FETCH_ASSOC);

    $resB2 = $paymentService->completeRemittanceAllocation($allocB2, $rec1Uid);
    $hdrAfterB2 = $pdo->query("SELECT remittance_status, expected_total, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitB}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Receptionist handover (auto_verify ON) auto-verifies with mode=auto_setting & NULL verifier, keeps partial batch submitted, then marks full batch verified with NULL batch verifier',
        $resB1['handover_status'] === 'completed'
            && $resB1['completed_by_employee_id'] === $rec1EmpId
            && $resB1['verification_status'] === 'verified'
            && $resB1['verified_by_employee_id'] === null
            && $resB1['verified_at'] !== null
            && $resB1['verification_mode'] === 'auto_setting'
            && $hdrAfterB1['remittance_status'] === 'submitted'
            && $hdrAfterB1['verified_total'] === null
            && $resB2['verification_status'] === 'verified'
            && $resB2['verification_mode'] === 'auto_setting'
            && $resB2['verified_by_employee_id'] === null
            && $hdrAfterB2['remittance_status'] === 'verified'
            && (string)$hdrAfterB2['verified_total'] === '200.00'
            && $hdrAfterB2['verified_by_employee_id'] === null
            && $hdrAfterB2['verified_at'] !== null
    );

    // =========================================================================
    // 4. Business Manager direct collection & Mixed Verifier Identities
    // =========================================================================
    $setAutoVerify(0);
    // 4a. Single-manager direct collection across batch -> populates batch verified_by_employee_id
    $oC1 = $createCollectedDeliveryOrder('110.00', '35.00');
    $batchC = $createSubmittedBatch([$oC1['payment_id']]);
    [$allocC1] = $batchC['allocation_ids'];
    $remitC = $batchC['remittance_id'];

    $colC1 = $paymentService->collectRemittanceAllocation($allocC1, $bm1Uid);
    $hdrC = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitC}")->fetch(PDO::FETCH_ASSOC);

    check(
        'BM direct collection marks submitted allocation completed+verified with mode=manager_collection and records manager as both completion & verification actor',
        $colC1['handover_status'] === 'completed'
            && $colC1['completed_by_employee_id'] === $bm1EmpId
            && $colC1['completed_at'] !== null
            && $colC1['verification_status'] === 'verified'
            && $colC1['verified_by_employee_id'] === $bm1EmpId
            && $colC1['verified_at'] !== null
            && $colC1['verification_mode'] === 'manager_collection'
            && $hdrC['remittance_status'] === 'verified'
            && (string)$hdrC['verified_total'] === '110.00'
            && (int)$hdrC['verified_by_employee_id'] === $bm1EmpId
            && $hdrC['verified_at'] !== null
    );

    // 4b. Mixed non-NULL verifiers (BM1 direct collection + Rec1 handover & BM2 manual verify) -> batch verified_by_employee_id = NULL
    $oD1 = $createCollectedDeliveryOrder('90.00', '30.00');
    $oD2 = $createCollectedDeliveryOrder('140.00', '30.00');
    $batchD = $createSubmittedBatch([$oD1['payment_id'], $oD2['payment_id']]);
    [$allocD1, $allocD2] = $batchD['allocation_ids'];
    $remitD = $batchD['remittance_id'];

    $paymentService->collectRemittanceAllocation($allocD1, $bm1Uid);
    $hdrDAfter1 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id FROM courier_cod_remittance WHERE remittance_id = {$remitD}")->fetch(PDO::FETCH_ASSOC);

    $paymentService->completeRemittanceAllocation($allocD2, $rec1Uid);
    $hdrDAfter2 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id FROM courier_cod_remittance WHERE remittance_id = {$remitD}")->fetch(PDO::FETCH_ASSOC);

    $paymentService->verifyRemittanceAllocation($allocD2, $bm2Uid);
    $hdrDAfter3 = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitD}")->fetch(PDO::FETCH_ASSOC);

    // 4c. Mixed auto_setting (NULL verifier) + BM1 direct collection (non-NULL verifier) -> batch verified_by_employee_id = NULL
    $setAutoVerify(1);
    $oE1 = $createCollectedDeliveryOrder('75.00', '25.00');
    $oE2 = $createCollectedDeliveryOrder('125.00', '25.00');
    $batchE = $createSubmittedBatch([$oE1['payment_id'], $oE2['payment_id']]);
    [$allocE1, $allocE2] = $batchE['allocation_ids'];
    $remitE = $batchE['remittance_id'];

    $paymentService->completeRemittanceAllocation($allocE1, $rec1Uid); // auto_setting (NULL verifier)
    $paymentService->collectRemittanceAllocation($allocE2, $bm1Uid);   // manager_collection (bm1EmpId)
    $hdrE = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$remitE}")->fetch(PDO::FETCH_ASSOC);
    $setAutoVerify(0);

    check(
        'Mixed verifier identities (BM1 vs BM2, or auto_setting NULL vs BM1) leave batch verified_by_employee_id NULL when batch reaches verified',
        $hdrDAfter1['remittance_status'] === 'submitted'
            && $hdrDAfter1['verified_total'] === null
            && $hdrDAfter2['remittance_status'] === 'completed'
            && $hdrDAfter2['verified_total'] === null
            && $hdrDAfter3['remittance_status'] === 'verified'
            && (string)$hdrDAfter3['verified_total'] === '230.00'
            && $hdrDAfter3['verified_by_employee_id'] === null
            && $hdrDAfter3['verified_at'] !== null
            && $hdrE['remittance_status'] === 'verified'
            && (string)$hdrE['verified_total'] === '200.00'
            && $hdrE['verified_by_employee_id'] === null
            && $hdrE['verified_at'] !== null
    );

    // =========================================================================
    // 5. Self-handover / Self-verification & Role / Unit Authorization Guards
    // =========================================================================
    $oF1 = $createCollectedDeliveryOrder('130.00', '40.00');
    $oF2 = $createCollectedDeliveryOrder('160.00', '40.00');
    $batchF = $createSubmittedBatch([$oF1['payment_id'], $oF2['payment_id']]);
    [$allocF1, $allocF2] = $batchF['allocation_ids'];

    $expectCode = static function (callable $fn, int $expectedCode): bool {
        try {
            $fn();
            return false;
        } catch (RuntimeException $e) {
            return $e->getCode() === $expectedCode;
        }
    };

    // Courier holds RECEPTIONIST and BUSINESS_MANAGER in unit 1, but is the courier on batchF -> must be rejected (403)
    $selfHandoverBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $courierUid), OrderPaymentService::ERR_FORBIDDEN);
    $selfCollectBlocked = $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $courierUid), OrderPaymentService::ERR_FORBIDDEN);

    // Unauthorized roles / units / accounts on submitted allocF1
    $bmCallingRecHandoverBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $bm1Uid), OrderPaymentService::ERR_FORBIDDEN);
    $recCallingBmCollectBlocked = $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $rec1Uid), OrderPaymentService::ERR_FORBIDDEN);
    $courierOnlyBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $courierOnlyUid), OrderPaymentService::ERR_FORBIDDEN)
        && $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $courierOnlyUid), OrderPaymentService::ERR_FORBIDDEN);
    $foreignEmpBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $foreignStaffUid), OrderPaymentService::ERR_FORBIDDEN)
        && $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $foreignStaffUid), OrderPaymentService::ERR_FORBIDDEN);
    $inactiveEmpBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $inactiveEmpUid), OrderPaymentService::ERR_FORBIDDEN)
        && $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $inactiveEmpUid), OrderPaymentService::ERR_FORBIDDEN);
    $inactiveAccBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $inactiveAccUid), OrderPaymentService::ERR_FORBIDDEN)
        && $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $inactiveAccUid), OrderPaymentService::ERR_FORBIDDEN);
    $superNoEmpBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $superNoEmpUid), OrderPaymentService::ERR_FORBIDDEN)
        && $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $superNoEmpUid), OrderPaymentService::ERR_FORBIDDEN);
    $foreignUnitArgBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $rec1Uid, $foreignUnitId), OrderPaymentService::ERR_FORBIDDEN)
        && $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $bm1Uid, $foreignUnitId), OrderPaymentService::ERR_FORBIDDEN);

    // Complete allocF1 handover validly via rec1Uid, then test manual verification self-verify & role guards
    $paymentService->completeRemittanceAllocation($allocF1, $rec1Uid);
    $selfManualVerifyBlocked = $expectCode(fn() => $paymentService->verifyRemittanceAllocation($allocF1, $courierUid), OrderPaymentService::ERR_FORBIDDEN);
    $recCallingManualVerifyBlocked = $expectCode(fn() => $paymentService->verifyRemittanceAllocation($allocF1, $rec1Uid), OrderPaymentService::ERR_FORBIDDEN);
    $foreignManualVerifyBlocked = $expectCode(fn() => $paymentService->verifyRemittanceAllocation($allocF1, $foreignStaffUid), OrderPaymentService::ERR_FORBIDDEN);

    check(
        'Self-handover, self-collection, and self-verification by assigned courier are rejected (403), along with wrong role, cross-unit, inactive, and unlinked Super Admin actors',
        $selfHandoverBlocked
            && $selfCollectBlocked
            && $selfManualVerifyBlocked
            && $bmCallingRecHandoverBlocked
            && $recCallingBmCollectBlocked
            && $recCallingManualVerifyBlocked
            && $courierOnlyBlocked
            && $foreignEmpBlocked
            && $foreignManualVerifyBlocked
            && $inactiveEmpBlocked
            && $inactiveAccBlocked
            && $superNoEmpBlocked
            && $foreignUnitArgBlocked
    );

    // =========================================================================
    // 6. Duplicate, Out-of-Order, and Concurrent Transition Protection (409)
    // =========================================================================
    // allocF1 is currently handover='completed', verification='pending'
    // allocF2 is currently handover='submitted', verification='pending'
    $dupHandoverBlocked = $expectCode(fn() => $paymentService->completeRemittanceAllocation($allocF1, $rec2Uid), OrderPaymentService::ERR_CONFLICT);
    $collectAfterHandoverBlocked = $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF1, $bm1Uid), OrderPaymentService::ERR_CONFLICT);
    $verifyBeforeHandoverBlocked = $expectCode(fn() => $paymentService->verifyRemittanceAllocation($allocF2, $bm1Uid), OrderPaymentService::ERR_CONFLICT);

    // Now verify allocF1 and collect allocF2, then attempt duplicate verify/collect/handover
    $paymentService->verifyRemittanceAllocation($allocF1, $bm1Uid);
    $paymentService->collectRemittanceAllocation($allocF2, $bm1Uid);

    $dupManualVerifyBlocked = $expectCode(fn() => $paymentService->verifyRemittanceAllocation($allocF1, $bm2Uid), OrderPaymentService::ERR_CONFLICT);
    $verifyAfterDirectCollectBlocked = $expectCode(fn() => $paymentService->verifyRemittanceAllocation($allocF2, $bm2Uid), OrderPaymentService::ERR_CONFLICT);
    $collectAfterDirectCollectBlocked = $expectCode(fn() => $paymentService->collectRemittanceAllocation($allocF2, $bm2Uid), OrderPaymentService::ERR_CONFLICT);

    // Simulate concurrent transition race right before guarded UPDATE executes (rowCount() === 0 -> ERR_CONFLICT)
    $oRace = $createCollectedDeliveryOrder('95.00', '25.00');
    $batchRace = $createSubmittedBatch([$oRace['payment_id']]);
    [$allocRace] = $batchRace['allocation_ids'];

    $raceInjector = new S3c2FaultInjector();
    $racePdo = $openIsolatedPdo();
    $racePdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [S3c2FailureInjectingStatement::class, [$raceInjector]]);
    $raceService = new OrderPaymentService($racePdo);

    $raceInjector->beforeExecute = static function (string $sql) use ($racePdo, $allocRace, $rec2EmpId): void {
        if (str_contains($sql, 'UPDATE courier_cod_remittance_allocation')) {
            // Simulate a concurrent state flip inside the transaction before the guarded UPDATE runs
            $racePdo->exec(
                "UPDATE courier_cod_remittance_allocation
                 SET handover_status = 'completed', completed_by_employee_id = {$rec2EmpId}, completed_at = UTC_TIMESTAMP()
                 WHERE allocation_id = {$allocRace}"
            );
        }
    };
    $concurrentRaceConflict = false;
    try {
        $raceService->completeRemittanceAllocation($allocRace, $rec1Uid);
    } catch (RuntimeException $e) {
        $concurrentRaceConflict = $e->getCode() === OrderPaymentService::ERR_CONFLICT;
    } finally {
        $raceInjector->beforeExecute = null;
        $racePdo = null;
    }
    $allocRaceStateAfterRollback = $pdo->query("SELECT handover_status, completed_by_employee_id, completed_at, verification_status FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocRace}")->fetch(PDO::FETCH_ASSOC);

    check(
        'Duplicate, out-of-order, and concurrent allocation transitions are rejected with ERR_CONFLICT (409) without partial mutation',
        $dupHandoverBlocked
            && $collectAfterHandoverBlocked
            && $verifyBeforeHandoverBlocked
            && $dupManualVerifyBlocked
            && $verifyAfterDirectCollectBlocked
            && $collectAfterDirectCollectBlocked
            && $concurrentRaceConflict
            && $allocRaceStateAfterRollback['handover_status'] === 'submitted'
            && $allocRaceStateAfterRollback['completed_by_employee_id'] === null
            && $allocRaceStateAfterRollback['completed_at'] === null
            && $allocRaceStateAfterRollback['verification_status'] === 'pending'
    );

    // =========================================================================
    // 7. Transaction Rollback on Mid-Transaction Failure
    // =========================================================================
    $rbInjector = new S3c2FaultInjector();
    $rbPdo = $openIsolatedPdo();
    $rbPdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [S3c2FailureInjectingStatement::class, [$rbInjector]]);
    $rbService = new OrderPaymentService($rbPdo);

    $rbInjector->beforeExecute = static function (string $sql): void {
        if (str_contains($sql, 'UPDATE courier_cod_remittance') && !str_contains($sql, 'courier_cod_remittance_allocation')) {
            throw new PDOException('Injected failure during batch header update');
        }
    };

    $rbHandoverCaught = false;
    try {
        $rbService->completeRemittanceAllocation($allocRace, $rec1Uid);
    } catch (PDOException $e) {
        $rbHandoverCaught = str_contains($e->getMessage(), 'Injected failure during batch header update');
    }

    $rbCollectCaught = false;
    try {
        $rbService->collectRemittanceAllocation($allocRace, $bm1Uid);
    } catch (PDOException $e) {
        $rbCollectCaught = str_contains($e->getMessage(), 'Injected failure during batch header update');
    } finally {
        $rbInjector->beforeExecute = null;
        $rbPdo = null;
    }

    $allocAfterRb = $pdo->query("SELECT handover_status, completed_by_employee_id, completed_at, verification_status, verified_by_employee_id, verified_at, verification_mode FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocRace}")->fetch(PDO::FETCH_ASSOC);
    $hdrAfterRb = $pdo->query("SELECT remittance_status, verified_total, verified_by_employee_id, verified_at FROM courier_cod_remittance WHERE remittance_id = {$batchRace['remittance_id']}")->fetch(PDO::FETCH_ASSOC);
    $auditCountAfterRb = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE target_type = 'courier_cod_remittance_allocation' AND target_id = {$allocRace}")->fetchColumn();

    check(
        'Mid-transaction failure after allocation UPDATE rolls back allocation state, batch header, and audit_log completely',
        $rbHandoverCaught
            && $rbCollectCaught
            && $allocAfterRb['handover_status'] === 'submitted'
            && $allocAfterRb['completed_by_employee_id'] === null
            && $allocAfterRb['completed_at'] === null
            && $allocAfterRb['verification_status'] === 'pending'
            && $allocAfterRb['verified_by_employee_id'] === null
            && $allocAfterRb['verified_at'] === null
            && $allocAfterRb['verification_mode'] === null
            && $hdrAfterRb['remittance_status'] === 'submitted'
            && $hdrAfterRb['verified_total'] === null
            && $auditCountAfterRb === 0
    );

    // =========================================================================
    // 8. Unchanged Customer Payment Status, Order Status, COD Metadata, & Amounts
    // =========================================================================
    $ordA1Final = $pdo->query("SELECT order_status, subtotal_amount, delivery_fee, total_amount FROM customer_order WHERE order_id = {$oA1['order_id']}")->fetch(PDO::FETCH_ASSOC);
    $payA1Final = $pdo->query("SELECT payment_status, amount, collection_channel, collected_by_employee_id, collected_at FROM payment WHERE payment_id = {$oA1['payment_id']}")->fetch(PDO::FETCH_ASSOC);
    $allocA1Final = $pdo->query("SELECT expected_amount, allocated_amount, allocation_status, active_payment_id FROM courier_cod_remittance_allocation WHERE allocation_id = {$allocA1}")->fetch(PDO::FETCH_ASSOC);

    check(
        'All remittance transitions preserve customer order status, customer payment status, COD collection metadata, and allocation financial amounts',
        $ordA1Final['order_status'] === 'completed'
            && (string)$ordA1Final['subtotal_amount'] === '100.00'
            && (string)$ordA1Final['delivery_fee'] === '50.00'
            && (string)$ordA1Final['total_amount'] === '150.00'
            && $payA1Final['payment_status'] === 'completed'
            && (string)$payA1Final['amount'] === '150.00'
            && $payA1Final['collection_channel'] === 'courier_cod'
            && (int)$payA1Final['collected_by_employee_id'] === $courierEmpId
            && $payA1Final['collected_at'] !== null
            && (string)$allocA1Final['expected_amount'] === '100.00'
            && (string)$allocA1Final['allocated_amount'] === '100.00'
            && $allocA1Final['allocation_status'] === 'active'
            && (int)$allocA1Final['active_payment_id'] === $oA1['payment_id']
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        if ($cleanup['created_unit1_settings']) {
            $pdo->prepare('DELETE FROM business_settings WHERE business_unit_id = ?')->execute([$unitId]);
        } else {
            $pdo->prepare('UPDATE business_settings SET auto_verify_cod_remittance = ? WHERE business_unit_id = ?')
                ->execute([$cleanup['orig_unit1_auto_verify'], $unitId]);
        }
        if ($cleanup['remittances']) {
            $rList = implode(',', array_map('intval', $cleanup['remittances']));
            $pdo->exec("DELETE al FROM audit_log al JOIN courier_cod_remittance_allocation a ON a.allocation_id = al.target_id WHERE al.target_type = 'courier_cod_remittance_allocation' AND a.remittance_id IN ({$rList})");
            $pdo->exec("DELETE FROM audit_log WHERE target_type = 'courier_cod_remittance' AND target_id IN ({$rList})");
            $pdo->exec("DELETE FROM courier_cod_remittance_allocation WHERE remittance_id IN ({$rList})");
            $pdo->exec("DELETE FROM courier_cod_remittance WHERE remittance_id IN ({$rList})");
        }
        if ($cleanup['orders']) {
            $oList = implode(',', array_map('intval', $cleanup['orders']));
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
            $pList = implode(',', array_map('intval', $cleanup['products']));
            $pdo->exec("DELETE ib FROM inventory_batch ib JOIN inventory i ON i.inventory_id = ib.inventory_id JOIN business_product bp ON bp.business_product_id = i.business_product_id WHERE bp.product_id IN ({$pList})");
            $pdo->exec("DELETE i FROM inventory i JOIN business_product bp ON bp.business_product_id = i.business_product_id WHERE bp.product_id IN ({$pList})");
            $pdo->exec("DELETE FROM business_product WHERE product_id IN ({$pList})");
            $pdo->exec("DELETE FROM product WHERE product_id IN ({$pList})");
        }
        if ($cleanup['branches']) {
            $pdo->exec('DELETE FROM branch WHERE branch_id IN (' . implode(',', array_map('intval', $cleanup['branches'])) . ')');
        }
        if ($cleanup['customers']) {
            $pdo->exec('DELETE FROM customer WHERE customer_id IN (' . implode(',', array_map('intval', $cleanup['customers'])) . ')');
        }
        if ($cleanup['employees']) {
            $pdo->exec('DELETE FROM employee WHERE employee_id IN (' . implode(',', array_map('intval', $cleanup['employees'])) . ')');
        }
        if ($cleanup['users']) {
            $uList = implode(',', array_map('intval', $cleanup['users']));
            $pdo->exec("DELETE FROM audit_log WHERE actor_user_id IN ({$uList})");
            $pdo->exec("DELETE FROM account_role WHERE user_account_id IN ({$uList})");
            $pdo->exec("DELETE FROM user_account WHERE user_account_id IN ({$uList})");
        }
        if ($cleanup['business_units']) {
            $buList = implode(',', array_map('intval', $cleanup['business_units']));
            $pdo->exec("DELETE FROM audit_log WHERE business_unit_id IN ({$buList})");
            $pdo->exec("DELETE FROM business_unit WHERE business_unit_id IN ({$buList})");
        }
    } catch (Throwable $cleanupErr) {
        fwrite(STDERR, "Cleanup warning: {$cleanupErr->getMessage()}\n");
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
