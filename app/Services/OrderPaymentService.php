<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Audit;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Stage S3-C1 Service:
 * - COD/cash payment collection confirmation (delivery courier_cod & pickup in_store_pickup).
 * - Courier COD remittance batch submission (`submitted` + active per-payment allocations).
 *
 * Intentionally excludes remittance verification/approval transitions and discrepancy-resolution
 * workflows pending unresolved verifier-permission and variance-policy confirmation.
 */
final class OrderPaymentService
{
    public const ERR_NOT_FOUND = 404;
    public const ERR_FORBIDDEN = 403;
    public const ERR_CONFLICT = 409;
    public const ERR_UNPROCESSABLE = 422;
    private const MAX_MONEY_CENTS = 999999999999; // DECIMAL(12,2) max: 9,999,999,999.99

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    /**
     * Confirm COD collection by the assigned courier after successful delivery completion.
     *
     * @return array{payment_id:int, order_id:int, amount:float, payment_status:string, collection_channel:string, collected_by_employee_id:int}
     */
    public function confirmDeliveryCodCollection(
        int $orderId,
        int $businessUnitId,
        int $courierEmployeeId,
        int $actorUserId
    ): array {
        return $this->runInTransaction(function () use ($orderId, $businessUnitId, $courierEmployeeId, $actorUserId): array {
            return $this->collectDeliveryCodInTransaction($orderId, $businessUnitId, $courierEmployeeId, $actorUserId);
        });
    }

    /**
     * Confirm COD/cash collection by authorized Pet Store staff after full pickup handover completion.
     *
     * @return array{payment_id:int, order_id:int, amount:float, payment_status:string, collection_channel:string, collected_by_employee_id:int}
     */
    public function confirmPickupCodCollection(
        int $orderId,
        int $businessUnitId,
        int $staffEmployeeId,
        int $actorUserId
    ): array {
        return $this->runInTransaction(function () use ($orderId, $businessUnitId, $staffEmployeeId, $actorUserId): array {
            return $this->collectPickupCodInTransaction($orderId, $businessUnitId, $staffEmployeeId, $actorUserId);
        });
    }

    /**
     * Record courier COD collection inside an already-open transaction (e.g. atomic delivery completion).
     *
     * @return array{payment_id:int, order_id:int, amount:float, payment_status:string, collection_channel:string, collected_by_employee_id:int}
     */
    public function collectDeliveryCodInTransaction(
        int $orderId,
        int $businessUnitId,
        int $courierEmployeeId,
        int $actorUserId
    ): array {
        $this->assertInTransaction('Delivery COD collection');
        return $this->collectOrderCodInTransaction(
            $orderId,
            $businessUnitId,
            $courierEmployeeId,
            $actorUserId,
            'delivery',
            'courier_cod',
            true,
            'payment.delivery_cod_collected'
        );
    }

    /**
     * Record pickup COD collection inside an already-open transaction (e.g. atomic pickup handover).
     *
     * @return array{payment_id:int, order_id:int, amount:float, payment_status:string, collection_channel:string, collected_by_employee_id:int}
     */
    public function collectPickupCodInTransaction(
        int $orderId,
        int $businessUnitId,
        int $staffEmployeeId,
        int $actorUserId
    ): array {
        $this->assertInTransaction('Pickup COD collection');
        return $this->collectOrderCodInTransaction(
            $orderId,
            $businessUnitId,
            $staffEmployeeId,
            $actorUserId,
            'pickup',
            'in_store_pickup',
            false,
            'payment.pickup_cod_collected'
        );
    }

    /**
     * Submit an exact-amount courier COD remittance batch in 'submitted' status for collected
     * courier_cod payments owned by the submitting courier within the same business unit.
     * Remittable amounts use customer_order.subtotal_amount (excluding delivery_fee).
     *
     * @param array<int,int|string> $paymentIds
     * @return array{remittance_id:int, remittance_reference:string, remittance_status:string, expected_total:string, remitted_total:string, allocations_created:int}
     */
    public function submitCourierCodRemittance(
        int $businessUnitId,
        int $courierEmployeeId,
        int $actorUserId,
        array $paymentIds,
        ?string $declaredRemittedTotal = null,
        ?string $notes = null
    ): array {
        $normalizedIds = [];
        foreach ($paymentIds as $rawId) {
            $id = (int)$rawId;
            if ($id > 0) {
                $normalizedIds[$id] = $id;
            }
        }
        $sortedPaymentIds = array_values($normalizedIds);
        sort($sortedPaymentIds, SORT_NUMERIC);

        if (!$sortedPaymentIds) {
            throw new RuntimeException('Select at least one collected COD payment to remit.', self::ERR_UNPROCESSABLE);
        }

        $declaredCents = null;
        if ($declaredRemittedTotal !== null) {
            $declaredCents = $this->parseDecimalToCents($declaredRemittedTotal, 'Remitted total', true);
            if ($declaredCents <= 0) {
                throw new RuntimeException('Declared remitted total must be greater than zero.', self::ERR_UNPROCESSABLE);
            }
        }

        $cleanNotes = $notes !== null ? trim($notes) : null;
        if ($cleanNotes === '') {
            $cleanNotes = null;
        }
        if ($cleanNotes !== null && mb_strlen($cleanNotes) > 500) {
            throw new RuntimeException('Remittance notes cannot exceed 500 characters.', self::ERR_UNPROCESSABLE);
        }

        return $this->runInTransaction(function () use (
            $businessUnitId,
            $courierEmployeeId,
            $actorUserId,
            $sortedPaymentIds,
            $declaredCents,
            $cleanNotes
        ): array {
            $payPlaceholders = implode(',', array_fill(0, count($sortedPaymentIds), '?'));

            // Step 1: Resolve candidate order_ids so global lock order stays customer_order -> employee -> payment.
            $ordProbeStmt = $this->db->prepare(
                "SELECT DISTINCT order_id FROM payment WHERE payment_id IN ({$payPlaceholders}) AND order_id IS NOT NULL ORDER BY order_id ASC"
            );
            $ordProbeStmt->execute($sortedPaymentIds);
            $candidateOrderIds = array_map('intval', $ordProbeStmt->fetchAll(PDO::FETCH_COLUMN));

            $lockedOrders = [];
            if ($candidateOrderIds) {
                $ordPlaceholders = implode(',', array_fill(0, count($candidateOrderIds), '?'));
                $lockOrdStmt = $this->db->prepare(
                    "SELECT order_id, business_unit_id, fulfillment_method, order_status, delivery_employee_id,
                            subtotal_amount, delivery_fee, total_amount
                     FROM customer_order
                     WHERE order_id IN ({$ordPlaceholders})
                     ORDER BY order_id ASC
                     FOR UPDATE"
                );
                $lockOrdStmt->execute($candidateOrderIds);
                foreach ($lockOrdStmt->fetchAll(PDO::FETCH_ASSOC) as $ordRow) {
                    $lockedOrders[(int)$ordRow['order_id']] = $ordRow;
                }
            }

            // Step 2: Lock active employee strictly belonging to this business unit.
            $this->lockAuthorizedRemittanceEmployee($courierEmployeeId, $businessUnitId);

            // Step 3: Lock payments (ASC), then any active allocations (ASC).
            $lockPayStmt = $this->db->prepare(
                "SELECT payment_id, order_id, sale_id, invoice_id, payment_type, amount, payment_method, payment_status,
                        collection_channel, collected_by_employee_id, collected_at
                 FROM payment
                 WHERE payment_id IN ({$payPlaceholders})
                 ORDER BY payment_id ASC
                 FOR UPDATE"
            );
            $lockPayStmt->execute($sortedPaymentIds);
            $lockedPayments = [];
            foreach ($lockPayStmt->fetchAll(PDO::FETCH_ASSOC) as $payRow) {
                $lockedPayments[(int)$payRow['payment_id']] = $payRow;
            }

            if (count($lockedPayments) !== count($sortedPaymentIds)) {
                throw new RuntimeException('One or more selected payments could not be found.', self::ERR_NOT_FOUND);
            }

            $allocLockStmt = $this->db->prepare(
                "SELECT allocation_id, payment_id, remittance_id
                 FROM courier_cod_remittance_allocation
                 WHERE payment_id IN ({$payPlaceholders}) AND allocation_status = 'active'
                 ORDER BY allocation_id ASC
                 FOR UPDATE"
            );
            $allocLockStmt->execute($sortedPaymentIds);
            $existingActiveAlloc = [];
            foreach ($allocLockStmt->fetchAll(PDO::FETCH_ASSOC) as $allocRow) {
                $existingActiveAlloc[(int)$allocRow['payment_id']] = $allocRow;
            }

            $refCheckStmt = $this->db->prepare(
                "SELECT payment_id
                 FROM payment
                 WHERE original_payment_id IN ({$payPlaceholders})
                   AND payment_type = 'refund'
                   AND payment_status = 'completed'
                 ORDER BY payment_id ASC
                 FOR UPDATE"
            );
            $refCheckStmt->execute($sortedPaymentIds);
            if ($refCheckStmt->fetchColumn() !== false) {
                throw new RuntimeException('Refunded payments cannot be allocated to a COD remittance batch.', self::ERR_CONFLICT);
            }

            $expectedCents = 0;
            $validatedRows = [];
            foreach ($sortedPaymentIds as $pid) {
                $pay = $lockedPayments[$pid];
                if (isset($existingActiveAlloc[$pid])) {
                    throw new RuntimeException(
                        "Payment #{$pid} is already allocated to remittance #{$existingActiveAlloc[$pid]['remittance_id']}.",
                        self::ERR_CONFLICT
                    );
                }

                if (
                    $pay['order_id'] === null
                    || $pay['sale_id'] !== null
                    || $pay['invoice_id'] !== null
                    || (string)$pay['payment_type'] !== 'payment'
                ) {
                    throw new RuntimeException("Payment #{$pid} is not a customer-order payment.", self::ERR_UNPROCESSABLE);
                }

                $orderId = (int)$pay['order_id'];
                $ord = $lockedOrders[$orderId] ?? null;
                if (!$ord) {
                    throw new RuntimeException("Order #{$orderId} for payment #{$pid} could not be locked.", self::ERR_NOT_FOUND);
                }
                if ((int)$ord['business_unit_id'] !== $businessUnitId) {
                    throw new RuntimeException("Payment #{$pid} does not belong to business unit #{$businessUnitId}.", self::ERR_FORBIDDEN);
                }
                if ((string)$ord['fulfillment_method'] !== 'delivery' || (string)$ord['order_status'] !== 'completed') {
                    throw new RuntimeException(
                        "Order #ORD-{$orderId} must be a completed delivery order before remittance.",
                        self::ERR_CONFLICT
                    );
                }
                if ((string)$pay['payment_status'] !== 'completed') {
                    throw new RuntimeException(
                        "Payment #{$pid} for Order #ORD-{$orderId} is not collected/completed.",
                        self::ERR_CONFLICT
                    );
                }
                if ((string)($pay['collection_channel'] ?? '') !== 'courier_cod' || $pay['collected_at'] === null) {
                    throw new RuntimeException(
                        "Payment #{$pid} was not collected through the courier_cod channel.",
                        self::ERR_UNPROCESSABLE
                    );
                }
                if (!in_array(strtolower(trim((string)$pay['payment_method'])), ['cod', 'cash'], true)) {
                    throw new RuntimeException("Payment #{$pid} is not a COD/cash payment.", self::ERR_UNPROCESSABLE);
                }
                if ((int)($pay['collected_by_employee_id'] ?? 0) !== $courierEmployeeId) {
                    throw new RuntimeException(
                        "Courier #{$courierEmployeeId} cannot remit payment #{$pid} collected by another employee.",
                        self::ERR_FORBIDDEN
                    );
                }

                if ($ord['subtotal_amount'] === null || trim((string)$ord['subtotal_amount']) === '') {
                    throw new RuntimeException("Order #ORD-{$orderId} is missing a valid purchase subtotal.", self::ERR_UNPROCESSABLE);
                }
                $subtotalCents = $this->parseDecimalToCents((string)$ord['subtotal_amount'], "Order #ORD-{$orderId} subtotal", true);
                if ($subtotalCents <= 0) {
                    throw new RuntimeException("Order #ORD-{$orderId} must have a positive purchase subtotal.", self::ERR_UNPROCESSABLE);
                }

                if ($ord['delivery_fee'] === null || trim((string)$ord['delivery_fee']) === '') {
                    throw new RuntimeException("Order #ORD-{$orderId} is missing delivery fee metadata.", self::ERR_UNPROCESSABLE);
                }
                $deliveryFeeCents = $this->parseDecimalToCents((string)$ord['delivery_fee'], "Order #ORD-{$orderId} delivery fee", true);
                if ($deliveryFeeCents < 0) {
                    throw new RuntimeException("Order #ORD-{$orderId} has a negative delivery fee.", self::ERR_UNPROCESSABLE);
                }

                if ($ord['total_amount'] === null || trim((string)$ord['total_amount']) === '') {
                    throw new RuntimeException("Order #ORD-{$orderId} is missing total amount.", self::ERR_UNPROCESSABLE);
                }
                $orderTotalCents = $this->parseDecimalToCents((string)$ord['total_amount'], "Order #ORD-{$orderId} total", true);
                if ($orderTotalCents <= 0) {
                    throw new RuntimeException("Order #ORD-{$orderId} must have a positive total amount.", self::ERR_UNPROCESSABLE);
                }

                if ($subtotalCents > self::MAX_MONEY_CENTS - $deliveryFeeCents || ($subtotalCents + $deliveryFeeCents) !== $orderTotalCents) {
                    throw new RuntimeException(
                        "Order #ORD-{$orderId} purchase subtotal plus delivery fee does not match order total.",
                        self::ERR_UNPROCESSABLE
                    );
                }

                $paymentCents = $this->parseDecimalToCents((string)$pay['amount'], "Payment #{$pid} amount", true);
                if ($paymentCents <= 0 || $paymentCents !== $orderTotalCents) {
                    throw new RuntimeException(
                        "Payment #{$pid} amount does not match Order #ORD-{$orderId} total.",
                        self::ERR_UNPROCESSABLE
                    );
                }

                if ($subtotalCents > self::MAX_MONEY_CENTS - $expectedCents) {
                    throw new RuntimeException('Remittance batch total exceeds maximum supported DECIMAL(12,2) limit.', self::ERR_UNPROCESSABLE);
                }
                $expectedCents += $subtotalCents;

                $validatedRows[] = [
                    'payment_id' => $pid,
                    'order_id' => $orderId,
                    'amount' => $this->formatCents($subtotalCents),
                ];
            }

            $expectedTotalFormatted = $this->formatCents($expectedCents);
            if ($declaredCents !== null && $declaredCents !== $expectedCents) {
                throw new RuntimeException(
                    "Remitted total must match the exact remittable purchase subtotal total ({$expectedTotalFormatted}, excluding delivery fees).",
                    self::ERR_UNPROCESSABLE
                );
            }
            $remittedTotalFormatted = $expectedTotalFormatted;

            $reference = 'RMT-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));

            $insHeader = $this->db->prepare(
                "INSERT INTO courier_cod_remittance
                 (remittance_reference, business_unit_id, courier_employee_id, expected_total, remitted_total, verified_total,
                  remittance_status, submitted_at, verified_by_employee_id, verified_at, notes)
                 VALUES (?, ?, ?, ?, ?, NULL, 'submitted', UTC_TIMESTAMP(), NULL, NULL, ?)"
            );
            $insHeader->execute([
                $reference,
                $businessUnitId,
                $courierEmployeeId,
                $expectedTotalFormatted,
                $remittedTotalFormatted,
                $cleanNotes,
            ]);
            $remittanceId = (int)$this->db->lastInsertId();

            $insAlloc = $this->db->prepare(
                "INSERT INTO courier_cod_remittance_allocation
                 (remittance_id, payment_id, order_id, business_unit_id, expected_amount, allocated_amount, allocation_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active')"
            );
            foreach ($validatedRows as $row) {
                try {
                    $insAlloc->execute([
                        $remittanceId,
                        $row['payment_id'],
                        $row['order_id'],
                        $businessUnitId,
                        $row['amount'],
                        $row['amount'],
                    ]);
                } catch (PDOException $e) {
                    if ($this->isDuplicateEntryException($e)) {
                        throw new RuntimeException(
                            "Payment #{$row['payment_id']} already has an active COD remittance allocation.",
                            self::ERR_CONFLICT,
                            $e
                        );
                    }
                    throw $e;
                }
            }

            $auditTargetId = $remittanceId <= 4294967295 ? $remittanceId : null;
            Audit::record(
                $this->db,
                'courier.cod_remittance_submitted',
                "Courier employee #{$courierEmployeeId} submitted COD remittance {$reference} covering " . count($validatedRows) . " payment(s) totaling {$remittedTotalFormatted}.",
                $businessUnitId,
                $actorUserId,
                $courierEmployeeId,
                'remittance:none',
                "remittance:{$reference}; status:submitted; total:{$remittedTotalFormatted}",
                'courier_cod_remittance',
                $auditTargetId
            );

            return [
                'remittance_id' => $remittanceId,
                'remittance_reference' => $reference,
                'remittance_status' => 'submitted',
                'expected_total' => $expectedTotalFormatted,
                'remitted_total' => $remittedTotalFormatted,
                'allocations_created' => count($validatedRows),
            ];
        });
    }

    /**
     * Shared transactional COD/cash collection confirmation for completed delivery and pickup orders.
     *
     * @return array{payment_id:int, order_id:int, amount:float, payment_status:string, collection_channel:string, collected_by_employee_id:int}
     */
    private function collectOrderCodInTransaction(
        int $orderId,
        int $businessUnitId,
        int $collectorEmployeeId,
        int $actorUserId,
        string $expectedMethod,
        string $collectionChannel,
        bool $requireAssignedCourier,
        string $auditActionCode
    ): array {
        // 1. Lock customer_order row and verify unit, fulfillment method, ownership, and completion status.
        $ordStmt = $this->db->prepare(
            "SELECT order_id, business_unit_id, fulfillment_method, order_status, delivery_employee_id, total_amount
             FROM customer_order
             WHERE order_id = ? AND business_unit_id = ?
             FOR UPDATE"
        );
        $ordStmt->execute([$orderId, $businessUnitId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException("Order #{$orderId} was not found for business unit #{$businessUnitId}.", self::ERR_NOT_FOUND);
        }
        if ((string)$order['fulfillment_method'] !== $expectedMethod) {
            throw new RuntimeException(
                "Order #ORD-{$orderId} is a {$order['fulfillment_method']} order and cannot use the {$collectionChannel} collection workflow.",
                self::ERR_FORBIDDEN
            );
        }
        $assignedEmpId = (int)($order['delivery_employee_id'] ?? 0);
        if ($requireAssignedCourier && $assignedEmpId !== $collectorEmployeeId) {
            throw new RuntimeException(
                "Only the assigned courier may confirm COD collection for Order #ORD-{$orderId}.",
                self::ERR_FORBIDDEN
            );
        }
        if ((string)$order['order_status'] !== 'completed') {
            $requiredStage = $expectedMethod === 'delivery' ? 'Completed (delivered)' : 'Completed (handed over)';
            throw new RuntimeException(
                "Order #ORD-{$orderId} must reach {$requiredStage} before COD collection can be confirmed (current: {$order['order_status']}).",
                self::ERR_CONFLICT
            );
        }

        // 2. Lock employee record and verify active status & business-unit authorization.
        $this->lockAuthorizedCollectorEmployee($collectorEmployeeId, $businessUnitId);

        // 3. Lock payment rows for this order in ascending payment_id order.
        $payStmt = $this->db->prepare(
            "SELECT payment_id, order_id, sale_id, invoice_id, payment_type, amount, payment_method, payment_status,
                    collection_channel, collected_by_employee_id, collected_at
             FROM payment
             WHERE order_id = ?
             ORDER BY payment_id ASC
             FOR UPDATE"
        );
        $payStmt->execute([$orderId]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$payments) {
            throw new RuntimeException("Order #ORD-{$orderId} has no payment record to collect.", self::ERR_UNPROCESSABLE);
        }

        $pendingCodPayments = [];
        foreach ($payments as $p) {
            $type = (string)$p['payment_type'];
            $status = (string)$p['payment_status'];

            if ($status === 'refunded' || ($type === 'refund' && $status === 'completed')) {
                throw new RuntimeException("Order #ORD-{$orderId} has been refunded and cannot be marked collected.", self::ERR_CONFLICT);
            }
            if ($type !== 'payment') {
                continue;
            }
            if ($status === 'completed' || $p['collection_channel'] !== null || $p['collected_at'] !== null) {
                throw new RuntimeException("Payment #{$p['payment_id']} for Order #ORD-{$orderId} has already been collected/completed.", self::ERR_CONFLICT);
            }
            if ($status === 'pending' && in_array(strtolower(trim((string)$p['payment_method'])), ['cod', 'cash'], true)) {
                $pendingCodPayments[] = $p;
            }
        }

        if (count($pendingCodPayments) === 0) {
            throw new RuntimeException("Order #ORD-{$orderId} does not have a pending COD/cash payment eligible for collection.", self::ERR_UNPROCESSABLE);
        }
        if (count($pendingCodPayments) > 1) {
            throw new RuntimeException("Order #ORD-{$orderId} has multiple pending COD payment rows.", self::ERR_CONFLICT);
        }

        $targetPay = $pendingCodPayments[0];
        $paymentId = (int)$targetPay['payment_id'];
        $amount = (float)$targetPay['amount'];

        if ($targetPay['sale_id'] !== null || $targetPay['invoice_id'] !== null) {
            throw new RuntimeException("Payment #{$paymentId} has inconsistent parent links.", self::ERR_UNPROCESSABLE);
        }

        $updPay = $this->db->prepare(
            "UPDATE payment
             SET payment_status = 'completed',
                 collection_channel = ?,
                 collected_by_employee_id = ?,
                 collected_at = UTC_TIMESTAMP(),
                 updated_at = NOW()
             WHERE payment_id = ?
               AND order_id = ?
               AND payment_type = 'payment'
               AND payment_status = 'pending'
               AND collection_channel IS NULL
               AND collected_at IS NULL
               AND collected_by_employee_id IS NULL"
        );
        $updPay->execute([$collectionChannel, $collectorEmployeeId, $paymentId, $orderId]);
        if ($updPay->rowCount() !== 1) {
            throw new RuntimeException("Payment #{$paymentId} could not be confirmed as collected due to a concurrent update.", self::ERR_CONFLICT);
        }

        Audit::record(
            $this->db,
            $auditActionCode,
            "Confirmed {$collectionChannel} COD collection of " . number_format($amount, 2, '.', '') . " for Order #ORD-{$orderId} (Payment #{$paymentId}).",
            $businessUnitId,
            $actorUserId,
            $collectorEmployeeId,
            'payment_status:pending; channel:none',
            "payment_status:completed; channel:{$collectionChannel}",
            'payment',
            $paymentId
        );

        return [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'amount' => $amount,
            'payment_status' => 'completed',
            'collection_channel' => $collectionChannel,
            'collected_by_employee_id' => $collectorEmployeeId,
        ];
    }

    private function lockAuthorizedCollectorEmployee(int $employeeId, int $businessUnitId): void
    {
        if ($employeeId <= 0) {
            throw new RuntimeException('A valid active staff employee record is required to record payment collection.', self::ERR_FORBIDDEN);
        }

        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.business_unit_id, e.employee_status,
                    COALESCE(ua.is_super_admin, 0) AS is_super_admin
             FROM employee e
             LEFT JOIN user_account ua ON ua.user_account_id = e.user_account_id AND ua.account_status = 'active'
             WHERE e.employee_id = ?
             FOR UPDATE"
        );
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

        if (!$emp || (string)$emp['employee_status'] !== 'active') {
            throw new RuntimeException("Employee #{$employeeId} is missing or inactive.", self::ERR_FORBIDDEN);
        }

        $empBuId = $emp['business_unit_id'] !== null ? (int)$emp['business_unit_id'] : 0;
        $isSuper = !empty($emp['is_super_admin']);
        if ($empBuId !== $businessUnitId && !$isSuper) {
            throw new RuntimeException("Employee #{$employeeId} does not belong to business unit #{$businessUnitId}.", self::ERR_FORBIDDEN);
        }
    }

    /**
     * Strict unit-scoped active employee lock for remittance submission (no cross-unit Super Admin bypass).
     */
    private function lockAuthorizedRemittanceEmployee(int $employeeId, int $businessUnitId): void
    {
        if ($employeeId <= 0) {
            throw new RuntimeException('A valid active employee record is required for COD remittance submission.', self::ERR_FORBIDDEN);
        }

        $empStmt = $this->db->prepare(
            "SELECT employee_id, business_unit_id, employee_status
             FROM employee
             WHERE employee_id = ?
             FOR UPDATE"
        );
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

        if (!$emp || (string)$emp['employee_status'] !== 'active') {
            throw new RuntimeException("Employee #{$employeeId} is missing or inactive.", self::ERR_FORBIDDEN);
        }

        $empBuId = $emp['business_unit_id'] !== null ? (int)$emp['business_unit_id'] : 0;
        if ($empBuId !== $businessUnitId) {
            throw new RuntimeException("Employee #{$employeeId} does not belong to business unit #{$businessUnitId}.", self::ERR_FORBIDDEN);
        }
    }

    /**
     * Pure-PHP exact decimal-string parser converting DECIMAL(12,2) values to integer cents
     * without IEEE-754 floating-point conversions.
     */
    private function parseDecimalToCents(string $raw, string $fieldLabel, bool $allowNegative = false): int
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || !preg_match('/^([+-])?(\d+)(?:\.(\d+))?$/', $trimmed, $m)) {
            throw new RuntimeException("{$fieldLabel} must be a valid decimal monetary value.", self::ERR_UNPROCESSABLE);
        }

        $sign = $m[1] ?? '';
        $wholeRaw = ltrim($m[2], '0');
        $wholeStr = $wholeRaw === '' ? '0' : $wholeRaw;
        $fracStr = $m[3] ?? '';

        if (strlen($fracStr) > 2) {
            throw new RuntimeException("{$fieldLabel} cannot exceed 2 decimal places.", self::ERR_UNPROCESSABLE);
        }

        $fracCents = (int)str_pad($fracStr, 2, '0', STR_PAD_RIGHT);
        if (strlen($wholeStr) > 10) {
            throw new RuntimeException("{$fieldLabel} exceeds supported DECIMAL(12,2) range.", self::ERR_UNPROCESSABLE);
        }

        $wholeInt = (int)$wholeStr;
        if ($wholeInt > intdiv(self::MAX_MONEY_CENTS - $fracCents, 100)) {
            throw new RuntimeException("{$fieldLabel} exceeds supported DECIMAL(12,2) range.", self::ERR_UNPROCESSABLE);
        }

        $cents = ($wholeInt * 100) + $fracCents;
        if ($sign === '-') {
            if (!$allowNegative && $cents > 0) {
                throw new RuntimeException("{$fieldLabel} cannot be negative.", self::ERR_UNPROCESSABLE);
            }
            $cents = -$cents;
        }

        return $cents;
    }

    /**
     * Pure-PHP integer-cent formatter producing exact 2-decimal SQL strings.
     */
    private function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function runInTransaction(callable $callback): mixed
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Cannot start nested transaction inside OrderPaymentService.', self::ERR_CONFLICT);
        }

        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            $result = $callback();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function assertInTransaction(string $operation): void
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException("{$operation} requires an active database transaction.", self::ERR_CONFLICT);
        }
    }

    private function isDuplicateEntryException(PDOException $e): bool
    {
        return (string)$e->getCode() === '23000'
            && isset($e->errorInfo[1])
            && (int)$e->errorInfo[1] === 1062;
    }
}
