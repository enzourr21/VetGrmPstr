<?php
declare(strict_types=1);

/**
 * PawCariño — Stage 3 Checkpoint S3-A: Schema & Invariants Verification Suite
 *
 * Targets only an explicitly allowlisted disposable test database (vetgrpet_test).
 * Verifies migration 023 schema objects, CHECK constraints, foreign keys, and
 * DB-level uniqueness/integrity invariants without modifying application workflows.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

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

$expectConstraintFail = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (PDOException $e) {
        return true;
    }
};

$cleanup = [
    'allocations' => [],
    'remittances' => [],
    'payments' => [],
    'refund_requests' => [],
    'orders' => [],
    'branches' => [],
    'customers' => [],
    'employees' => [],
    'users' => [],
    'business_units' => [],
];

try {
    // 1. Verify migration 023 recorded and required tables/columns/constraints present
    $migApplied = (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE filename = '023_s3_fulfillment_accounting_foundation.sql'")->fetchColumn();
    check('Migration 023 recorded in schema_migrations', $migApplied === 1);

    $remitTableExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance'")->fetchColumn();
    $allocTableExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_cod_remittance_allocation'")->fetchColumn();
    check('Courier COD remittance header & allocation tables exist', $remitTableExists === 1 && $allocTableExists === 1);

    // Seed isolated test fixtures
    $pdo->exec("INSERT INTO business_unit (business_unit_name, business_unit_status) VALUES ('ChkS3A Test Unit', 'active')");
    $unitId = (int)$pdo->lastInsertId();
    $cleanup['business_units'][] = $unitId;

    $pdo->exec("INSERT INTO branch (business_unit_id, branch_name, branch_status) VALUES ({$unitId}, 'ChkS3A Main Branch', 'active')");
    $branchId = (int)$pdo->lastInsertId();
    $cleanup['branches'][] = $branchId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chks3a_cust@test.invalid', 'hash')");
    $custUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $custUid;
    $pdo->exec("INSERT INTO customer (user_account_id, first_name, last_name) VALUES ({$custUid}, 'S3A', 'Buyer')");
    $custId = (int)$pdo->lastInsertId();
    $cleanup['customers'][] = $custId;

    $pdo->exec("INSERT INTO user_account (email, password_hash) VALUES ('chks3a_courier@test.invalid', 'hash')");
    $empUid = (int)$pdo->lastInsertId();
    $cleanup['users'][] = $empUid;
    $pdo->exec("INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position) VALUES ({$empUid}, {$unitId}, 'Courier', 'Tester', 'Delivery Personnel')");
    $courierEmpId = (int)$pdo->lastInsertId();
    $cleanup['employees'][] = $courierEmpId;

    // 2. Customer-order failure classification & dispute constraints
    $pdo->prepare(
        "INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, subtotal_amount, delivery_fee, total_amount)
         VALUES (?, ?, ?, 'delivery', 'delivery_failed', 200.00, 50.00, 250.00)"
    )->execute([$custId, $unitId, $branchId]);
    $ordDelivFailId = (int)$pdo->lastInsertId();
    $cleanup['orders'][] = $ordDelivFailId;

    $custEligibleBlocked = $expectConstraintFail(static function () use ($pdo, $ordDelivFailId): void {
        $pdo->prepare("UPDATE customer_order SET failure_classification = 'customer_caused', refund_eligibility = 'eligible' WHERE order_id = ?")
            ->execute([$ordDelivFailId]);
    });
    $disputedEligibleBlocked = $expectConstraintFail(static function () use ($pdo, $ordDelivFailId): void {
        $pdo->prepare("UPDATE customer_order SET failure_classification = 'disputed', refund_eligibility = 'eligible' WHERE order_id = ?")
            ->execute([$ordDelivFailId]);
    });
    $pdo->prepare(
        "UPDATE customer_order
         SET failure_classification = 'disputed',
             refund_eligibility = 'manual_review',
             failure_classified_by_employee_id = ?,
             failure_classified_at = UTC_TIMESTAMP(),
             dispute_notes = 'Customer disputes carrier attempt timestamp.'
         WHERE order_id = ?"
    )->execute([$courierEmpId, $ordDelivFailId]);
    $pdo->prepare(
        "UPDATE customer_order
         SET failure_classification = 'customer_caused',
             refund_eligibility = 'ineligible',
             dispute_resolved_by_employee_id = ?,
             dispute_resolved_at = UTC_TIMESTAMP()
         WHERE order_id = ?"
    )->execute([$courierEmpId, $ordDelivFailId]);

    $ordRow = $pdo->query("SELECT failure_classification, refund_eligibility FROM customer_order WHERE order_id = {$ordDelivFailId}")->fetch();
    check(
        'Order failure classification enforces customer_caused->ineligible and disputed->manual_review invariants',
        $custEligibleBlocked
            && $disputedEligibleBlocked
            && $ordRow['failure_classification'] === 'customer_caused'
            && $ordRow['refund_eligibility'] === 'ineligible'
    );

    // 3. Payment cash-collection metadata & child refund integrity constraints
    $pdo->prepare(
        "INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, subtotal_amount, delivery_fee, total_amount)
         VALUES (?, ?, ?, 'delivery', 'completed', 300.00, 50.00, 350.00)"
    )->execute([$custId, $unitId, $branchId]);
    $ordCodId = (int)$pdo->lastInsertId();
    $cleanup['orders'][] = $ordCodId;

    $zeroAmountBlocked = $expectConstraintFail(static function () use ($pdo, $ordCodId): void {
        $pdo->prepare("INSERT INTO payment (order_id, payment_type, amount, payment_method, payment_status) VALUES (?, 'payment', 0.00, 'cod', 'pending')")
            ->execute([$ordCodId]);
    });
    $pendingCourierChannelBlocked = $expectConstraintFail(static function () use ($pdo, $ordCodId, $courierEmpId): void {
        $pdo->prepare("INSERT INTO payment (order_id, payment_type, amount, payment_method, payment_status, collection_channel, collected_by_employee_id) VALUES (?, 'payment', 350.00, 'cod', 'pending', 'courier_cod', ?)")
            ->execute([$ordCodId, $courierEmpId]);
    });
    $orphanRefundBlocked = $expectConstraintFail(static function () use ($pdo, $ordCodId): void {
        $pdo->prepare("INSERT INTO payment (order_id, payment_type, amount, payment_method, payment_status) VALUES (?, 'refund', 350.00, 'cod', 'completed')")
            ->execute([$ordCodId]);
    });

    // Insert valid completed courier COD collection preserving collection metadata
    $pdo->prepare(
        "INSERT INTO payment (order_id, payment_type, amount, payment_method, payment_status, collection_channel, collected_by_employee_id, collected_at)
         VALUES (?, 'payment', 350.00, 'cod', 'completed', 'courier_cod', ?, UTC_TIMESTAMP())"
    )->execute([$ordCodId, $courierEmpId]);
    $codPayId = (int)$pdo->lastInsertId();
    $cleanup['payments'][] = $codPayId;

    // 4. refund_request bounds + unique active child refund per refund_request_id
    $pdo->prepare(
        "INSERT INTO refund_request (refund_reference, business_unit_id, customer_id, order_id, payment_id, channel, requested_amount, customer_reason, request_status)
         VALUES ('REF-S3A-001', ?, ?, ?, ?, 'online', 100.00, 'Damaged bag after handover', 'pending')"
    )->execute([$unitId, $custId, $ordCodId, $codPayId]);
    $rrId = (int)$pdo->lastInsertId();
    $cleanup['refund_requests'][] = $rrId;

    $overApprovalBlocked = $expectConstraintFail(static function () use ($pdo, $rrId): void {
        $pdo->prepare("UPDATE refund_request SET approved_amount = 150.00 WHERE refund_request_id = ?")
            ->execute([$rrId]);
    });

    // Historical failed refund attempt followed by single completed child refund works; second active/completed for same refund_request_id is blocked
    $pdo->prepare(
        "INSERT INTO payment (order_id, original_payment_id, payment_type, amount, payment_method, payment_status, refund_request_id, refund_execution_notes)
         VALUES (?, ?, 'refund', 100.00, 'cash', 'failed', ?, 'Cashier register drawer closed')"
    )->execute([$ordCodId, $codPayId, $rrId]);
    $cleanup['payments'][] = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO payment (order_id, original_payment_id, payment_type, amount, payment_method, payment_status, refund_request_id, refund_execution_notes)
         VALUES (?, ?, 'refund', 100.00, 'cash', 'completed', ?, 'Manual store cash refund completed')"
    )->execute([$ordCodId, $codPayId, $rrId]);
    $childRefId = (int)$pdo->lastInsertId();
    $cleanup['payments'][] = $childRefId;

    $dupChildRefundBlocked = $expectConstraintFail(static function () use ($pdo, $ordCodId, $codPayId, $rrId): void {
        $pdo->prepare(
            "INSERT INTO payment (order_id, original_payment_id, payment_type, amount, payment_method, payment_status, refund_request_id)
             VALUES (?, ?, 'refund', 100.00, 'cash', 'completed', ?)"
        )->execute([$ordCodId, $codPayId, $rrId]);
    });

    $origPayStatus = (string)$pdo->query("SELECT payment_status FROM payment WHERE payment_id = {$codPayId}")->fetchColumn();
    check(
        'Payment & refund_request integrity blocks invalid amounts/orphans/over-approval/duplicate payout and preserves original payment',
        $zeroAmountBlocked
            && $pendingCourierChannelBlocked
            && $orphanRefundBlocked
            && $overApprovalBlocked
            && $dupChildRefundBlocked
            && $origPayStatus === 'completed'
    );

    // 5. Courier COD remittance batch & active per-payment allocation uniqueness
    $pdo->prepare(
        "INSERT INTO courier_cod_remittance (remittance_reference, business_unit_id, courier_employee_id, expected_total, remitted_total, remittance_status, submitted_at)
         VALUES ('RMT-S3A-001', ?, ?, 350.00, 350.00, 'submitted', UTC_TIMESTAMP())"
    )->execute([$unitId, $courierEmpId]);
    $remitId1 = (int)$pdo->lastInsertId();
    $cleanup['remittances'][] = $remitId1;

    $pdo->prepare(
        "INSERT INTO courier_cod_remittance_allocation (remittance_id, payment_id, order_id, business_unit_id, expected_amount, allocated_amount, allocation_status)
         VALUES (?, ?, ?, ?, 350.00, 350.00, 'active')"
    )->execute([$remitId1, $codPayId, $ordCodId, $unitId]);
    $allocId1 = (int)$pdo->lastInsertId();
    $cleanup['allocations'][] = $allocId1;

    $overAllocationBlocked = $expectConstraintFail(static function () use ($pdo, $remitId1, $codPayId, $ordCodId, $unitId): void {
        $pdo->prepare(
            "INSERT INTO courier_cod_remittance_allocation (remittance_id, payment_id, order_id, business_unit_id, expected_amount, allocated_amount, allocation_status)
             VALUES (?, ?, ?, ?, 350.00, 400.00, 'active')"
        )->execute([$remitId1, $codPayId, $ordCodId, $unitId]);
    });

    // Second active batch attempting to allocate the same COD payment must fail
    $pdo->prepare(
        "INSERT INTO courier_cod_remittance (remittance_reference, business_unit_id, courier_employee_id, expected_total, remitted_total, remittance_status, submitted_at)
         VALUES ('RMT-S3A-002', ?, ?, 350.00, 350.00, 'submitted', UTC_TIMESTAMP())"
    )->execute([$unitId, $courierEmpId]);
    $remitId2 = (int)$pdo->lastInsertId();
    $cleanup['remittances'][] = $remitId2;

    $dupAllocBlocked = $expectConstraintFail(static function () use ($pdo, $remitId2, $codPayId, $ordCodId, $unitId): void {
        $pdo->prepare(
            "INSERT INTO courier_cod_remittance_allocation (remittance_id, payment_id, order_id, business_unit_id, expected_amount, allocated_amount, allocation_status)
             VALUES (?, ?, ?, ?, 350.00, 350.00, 'active')"
        )->execute([$remitId2, $codPayId, $ordCodId, $unitId]);
    });

    // Cancelling batch 1 and releasing allocation permits allocation into batch 2 cleanly
    $pdo->prepare("UPDATE courier_cod_remittance_allocation SET allocation_status = 'released' WHERE allocation_id = ?")->execute([$allocId1]);
    $pdo->prepare("UPDATE courier_cod_remittance SET remittance_status = 'cancelled' WHERE remittance_id = ?")->execute([$remitId1]);
    $pdo->prepare(
        "INSERT INTO courier_cod_remittance_allocation (remittance_id, payment_id, order_id, business_unit_id, expected_amount, allocated_amount, allocation_status)
         VALUES (?, ?, ?, ?, 350.00, 350.00, 'active')"
    )->execute([$remitId2, $codPayId, $ordCodId, $unitId]);
    $allocId2 = (int)$pdo->lastInsertId();
    $cleanup['allocations'][] = $allocId2;

    check(
        'Remittance allocation blocks duplicate active payment allocation & over-allocation while allowing clean replacement after release',
        $overAllocationBlocked && $dupAllocBlocked && $allocId2 > 0
    );
} finally {
    if ($cleanup['allocations']) {
        $pdo->exec('DELETE FROM courier_cod_remittance_allocation WHERE allocation_id IN (' . implode(',', $cleanup['allocations']) . ')');
    }
    if ($cleanup['remittances']) {
        $pdo->exec('DELETE FROM courier_cod_remittance WHERE remittance_id IN (' . implode(',', $cleanup['remittances']) . ')');
    }
    if ($cleanup['payments']) {
        $pIds = implode(',', array_reverse($cleanup['payments']));
        $pdo->exec("DELETE FROM payment WHERE payment_type = 'refund' AND payment_id IN ({$pIds})");
    }
    if ($cleanup['refund_requests']) {
        $pdo->exec('DELETE FROM refund_request WHERE refund_request_id IN (' . implode(',', $cleanup['refund_requests']) . ')');
    }
    if ($cleanup['payments']) {
        $pIds = implode(',', array_reverse($cleanup['payments']));
        $pdo->exec("DELETE FROM payment WHERE payment_id IN ({$pIds})");
    }
    if ($cleanup['orders']) {
        $pdo->exec('DELETE FROM customer_order WHERE order_id IN (' . implode(',', $cleanup['orders']) . ')');
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
        $pdo->exec('DELETE FROM business_unit WHERE business_unit_id IN (' . implode(',', $cleanup['business_units']) . ')');
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
