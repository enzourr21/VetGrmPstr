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
 * Stage S3 Service:
 * - COD/cash payment collection confirmation (delivery courier_cod & pickup in_store_pickup).
 * - Courier COD remittance batch submission (`submitted` + active per-payment allocations).
 * - Per-allocation COD remittance handover completion, Business Manager direct collection,
 *   manual verification, and deterministic batch header status derivation.
 */
final class OrderPaymentService
{
    public const PET_STORE_UNIT_ID = 1;
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
     * Reject delivery completion without explicit COD confirmation when a pending COD/cash payment exists.
     */
    public function assertNoPendingDeliveryCodInTransaction(int $orderId): void
    {
        $this->assertInTransaction('Delivery payment verification');

        $payStmt = $this->db->prepare(
            "SELECT payment_id, payment_type, payment_method, payment_status
             FROM payment
             WHERE order_id = ?
             ORDER BY payment_id ASC
             FOR UPDATE"
        );
        $payStmt->execute([$orderId]);
        $payments = $payStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasCompletedPayment = false;
        $hasPendingCod = false;
        foreach ($payments as $p) {
            if ((string)$p['payment_type'] !== 'payment') {
                continue;
            }
            $status = (string)$p['payment_status'];
            if ($status === 'completed') {
                $hasCompletedPayment = true;
                break;
            }
            if ($status === 'pending' && in_array(strtolower(trim((string)$p['payment_method'])), ['cod', 'cash'], true)) {
                $hasPendingCod = true;
            }
        }

        if (!$hasCompletedPayment && $hasPendingCod) {
            throw new RuntimeException(
                "COD cash collection confirmation is required before marking Order #ORD-{$orderId} as completed.",
                self::ERR_UNPROCESSABLE
            );
        }
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
        $sortedPaymentIds = self::validatePositivePaymentIds($paymentIds);

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

            // Step 2: Lock active employee strictly belonging to this business unit and actor user.
            $this->lockAuthorizedRemittanceEmployee($courierEmployeeId, $businessUnitId, $actorUserId);

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
     * Complete courier COD cash handover for a single active allocation as an authorized Pet Store Receptionist.
     * - Reads and locks Pet Store `business_settings.auto_verify_cod_remittance`.
     * - Setting OFF (0): transitions allocation to handover_status='completed', verification_status='pending'.
     * - Setting ON  (1): transitions allocation to handover_status='completed', verification_status='verified'
     *                    with verification_mode='auto_setting' and verified_by_employee_id=NULL.
     * - Recalculates parent courier_cod_remittance batch header from its active allocations.
     *
     * @return array<string, mixed>
     */
    public function completeRemittanceAllocation(
        int $allocationId,
        int $actorUserId,
        int $businessUnitId = self::PET_STORE_UNIT_ID
    ): array {
        return $this->transitionRemittanceAllocation(
            $allocationId,
            $actorUserId,
            $businessUnitId,
            'receptionist_handover'
        );
    }

    /**
     * Directly collect and verify a single active submitted COD remittance allocation as an authorized
     * Pet Store Business Manager (`verification_mode = 'manager_collection'`).
     *
     * @return array<string, mixed>
     */
    public function collectRemittanceAllocation(
        int $allocationId,
        int $actorUserId,
        int $businessUnitId = self::PET_STORE_UNIT_ID
    ): array {
        return $this->transitionRemittanceAllocation(
            $allocationId,
            $actorUserId,
            $businessUnitId,
            'manager_collection'
        );
    }

    /**
     * Alias for collectRemittanceAllocation().
     *
     * @return array<string, mixed>
     */
    public function collectAndVerifyRemittanceAllocation(
        int $allocationId,
        int $actorUserId,
        int $businessUnitId = self::PET_STORE_UNIT_ID
    ): array {
        return $this->collectRemittanceAllocation($allocationId, $actorUserId, $businessUnitId);
    }

    /**
     * Manually verify a single active COD remittance allocation whose handover is already completed
     * (`handover_status = 'completed'`, `verification_status = 'pending'`) as an authorized Pet Store
     * Business Manager (`verification_mode = 'manual'`), preserving original completion metadata.
     *
     * @return array<string, mixed>
     */
    public function verifyRemittanceAllocation(
        int $allocationId,
        int $actorUserId,
        int $businessUnitId = self::PET_STORE_UNIT_ID
    ): array {
        return $this->transitionRemittanceAllocation(
            $allocationId,
            $actorUserId,
            $businessUnitId,
            'manager_manual_verify'
        );
    }

    /**
     * Shared transactional implementation for per-allocation COD remittance lifecycle transitions.
     *
     * Lock hierarchy:
     *   customer_order -> employee -> payment -> business_settings (if receptionist handover)
     *   -> courier_cod_remittance (batch header) -> courier_cod_remittance_allocation (ASC).
     *
     * @return array<string, mixed>
     */
    private function transitionRemittanceAllocation(
        int $allocationId,
        int $actorUserId,
        int $businessUnitId,
        string $operationType
    ): array {
        if ($allocationId <= 0) {
            throw new RuntimeException('Allocation ID must be a positive integer.', self::ERR_UNPROCESSABLE);
        }
        if ($businessUnitId !== self::PET_STORE_UNIT_ID) {
            throw new RuntimeException(
                'COD remittance lifecycle operations are restricted to Pet Store (business unit #1).',
                self::ERR_FORBIDDEN
            );
        }
        if ($actorUserId <= 0) {
            throw new RuntimeException('A valid authenticated user account is required.', self::ERR_FORBIDDEN);
        }

        $requiredRole = $operationType === 'receptionist_handover' ? 'RECEPTIONIST' : 'BUSINESS_MANAGER';

        return $this->runInTransaction(function () use (
            $allocationId,
            $actorUserId,
            $businessUnitId,
            $operationType,
            $requiredRole
        ): array {
            // Probe target allocation PK to determine parent order_id, payment_id, and remittance_id for deterministic lock ordering.
            $probeStmt = $this->db->prepare(
                "SELECT allocation_id, remittance_id, payment_id, order_id, business_unit_id
                 FROM courier_cod_remittance_allocation
                 WHERE allocation_id = ?
                 LIMIT 1"
            );
            $probeStmt->execute([$allocationId]);
            $probe = $probeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$probe) {
                $this->lockAuthorizedRemittanceActorByUser($actorUserId, $businessUnitId, $requiredRole);
                throw new RuntimeException("COD remittance allocation #{$allocationId} was not found.", self::ERR_NOT_FOUND);
            }

            $orderId = (int)$probe['order_id'];
            $paymentId = (int)$probe['payment_id'];
            $remittanceId = (int)$probe['remittance_id'];

            // 1. Lock customer_order row first.
            $ordStmt = $this->db->prepare(
                "SELECT order_id, business_unit_id, fulfillment_method, order_status, delivery_employee_id,
                        subtotal_amount, delivery_fee, total_amount
                 FROM customer_order
                 WHERE order_id = ?
                 FOR UPDATE"
            );
            $ordStmt->execute([$orderId]);
            $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

            // 2. Lock and authorize the active Pet Store employee record for $actorUserId and $requiredRole.
            $actorEmployeeId = $this->lockAuthorizedRemittanceActorByUser($actorUserId, $businessUnitId, $requiredRole);

            if (!$order) {
                throw new RuntimeException("Order #{$orderId} for allocation #{$allocationId} was not found.", self::ERR_NOT_FOUND);
            }
            if ((int)$probe['business_unit_id'] !== $businessUnitId || (int)$order['business_unit_id'] !== $businessUnitId) {
                throw new RuntimeException(
                    "Allocation #{$allocationId} does not belong to business unit #{$businessUnitId}.",
                    self::ERR_FORBIDDEN
                );
            }

            // 3. Lock payment row and verify no completed refund exists.
            $payStmt = $this->db->prepare(
                "SELECT payment_id, order_id, sale_id, invoice_id, payment_type, amount, payment_method, payment_status,
                        collection_channel, collected_by_employee_id, collected_at
                 FROM payment
                 WHERE payment_id = ?
                 FOR UPDATE"
            );
            $payStmt->execute([$paymentId]);
            $payment = $payStmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment || (int)($payment['order_id'] ?? 0) !== $orderId) {
                throw new RuntimeException("Payment #{$paymentId} for allocation #{$allocationId} was not found.", self::ERR_NOT_FOUND);
            }

            $refCheckStmt = $this->db->prepare(
                "SELECT payment_id
                 FROM payment
                 WHERE original_payment_id = ?
                   AND payment_type = 'refund'
                   AND payment_status = 'completed'
                 ORDER BY payment_id ASC
                 FOR UPDATE"
            );
            $refCheckStmt->execute([$paymentId]);
            if ((string)$payment['payment_status'] === 'refunded' || $refCheckStmt->fetchColumn() !== false) {
                throw new RuntimeException(
                    "Payment #{$paymentId} has been refunded and its COD remittance allocation cannot be transitioned.",
                    self::ERR_CONFLICT
                );
            }

            if ((string)$order['fulfillment_method'] !== 'delivery' || (string)$order['order_status'] !== 'completed') {
                throw new RuntimeException(
                    "Order #ORD-{$orderId} must remain a completed delivery order.",
                    self::ERR_CONFLICT
                );
            }
            if (
                (string)$payment['payment_type'] !== 'payment'
                || (string)$payment['payment_status'] !== 'completed'
                || (string)($payment['collection_channel'] ?? '') !== 'courier_cod'
                || $payment['collected_at'] === null
            ) {
                throw new RuntimeException(
                    "Payment #{$paymentId} is not a completed courier_cod payment.",
                    self::ERR_CONFLICT
                );
            }

            // 4. For Receptionist handover completion, read and lock Pet Store auto_verify_cod_remittance setting.
            $autoVerifyEnabled = false;
            if ($operationType === 'receptionist_handover') {
                $setStmt = $this->db->prepare(
                    "SELECT setting_id, auto_verify_cod_remittance
                     FROM business_settings
                     WHERE business_unit_id = ?
                     LIMIT 1
                     FOR UPDATE"
                );
                $setStmt->execute([$businessUnitId]);
                $settingsRow = $setStmt->fetch(PDO::FETCH_ASSOC);
                $autoVerifyEnabled = !empty($settingsRow) && (int)($settingsRow['auto_verify_cod_remittance'] ?? 0) === 1;
            }

            // 5. Lock parent courier_cod_remittance batch header to serialize concurrent transitions on the same batch.
            $hdrStmt = $this->db->prepare(
                "SELECT remittance_id, remittance_reference, business_unit_id, courier_employee_id,
                        expected_total, remitted_total, verified_total, remittance_status,
                        submitted_at, verified_by_employee_id, verified_at, notes
                 FROM courier_cod_remittance
                 WHERE remittance_id = ?
                 FOR UPDATE"
            );
            $hdrStmt->execute([$remittanceId]);
            $header = $hdrStmt->fetch(PDO::FETCH_ASSOC);

            if (!$header) {
                throw new RuntimeException("Parent COD remittance batch #{$remittanceId} was not found.", self::ERR_NOT_FOUND);
            }
            if ((int)$header['business_unit_id'] !== $businessUnitId) {
                throw new RuntimeException(
                    "Remittance batch #{$remittanceId} does not belong to business unit #{$businessUnitId}.",
                    self::ERR_FORBIDDEN
                );
            }

            // 6. Lock all allocation rows for this batch in ascending allocation_id order.
            $allocLockStmt = $this->db->prepare(
                "SELECT allocation_id, remittance_id, payment_id, order_id, business_unit_id,
                        expected_amount, allocated_amount, allocation_status,
                        handover_status, completed_by_employee_id, completed_at,
                        verification_status, verified_by_employee_id, verified_at, verification_mode
                 FROM courier_cod_remittance_allocation
                 WHERE remittance_id = ?
                 ORDER BY allocation_id ASC
                 FOR UPDATE"
            );
            $allocLockStmt->execute([$remittanceId]);
            $batchAllocations = [];
            foreach ($allocLockStmt->fetchAll(PDO::FETCH_ASSOC) as $aRow) {
                $batchAllocations[(int)$aRow['allocation_id']] = $aRow;
            }

            $alloc = $batchAllocations[$allocationId] ?? null;
            if (!$alloc) {
                throw new RuntimeException("COD remittance allocation #{$allocationId} was not found in batch #{$remittanceId}.", self::ERR_NOT_FOUND);
            }

            // Enforce non-self courier restriction across batch header, payment collector, and order delivery courier.
            $courierEmpId = (int)$header['courier_employee_id'];
            $collectorEmpId = (int)($payment['collected_by_employee_id'] ?? 0);
            $deliveryEmpId = (int)($order['delivery_employee_id'] ?? 0);
            if ($actorEmployeeId === $courierEmpId || $actorEmployeeId === $collectorEmpId || $actorEmployeeId === $deliveryEmpId) {
                throw new RuntimeException(
                    "Employee #{$actorEmployeeId} is the courier assigned to Order #ORD-{$orderId} and cannot complete or verify its COD remittance allocation.",
                    self::ERR_FORBIDDEN
                );
            }

            if ((string)$alloc['allocation_status'] !== 'active') {
                throw new RuntimeException(
                    "Allocation #{$allocationId} is not active (current: {$alloc['allocation_status']}).",
                    self::ERR_CONFLICT
                );
            }

            $batchStatus = (string)$header['remittance_status'];
            if (!in_array($batchStatus, ['submitted', 'completed'], true)) {
                throw new RuntimeException(
                    "Remittance batch {$header['remittance_reference']} is already '{$batchStatus}' and cannot be transitioned.",
                    self::ERR_CONFLICT
                );
            }

            // Verify allocation financial amount parity before transitioning.
            $subtotalCents = $this->parseDecimalToCents((string)($order['subtotal_amount'] ?? ''), "Order #ORD-{$orderId} subtotal", true);
            $expectedAllocCents = $this->parseDecimalToCents((string)$alloc['expected_amount'], "Allocation #{$allocationId} expected amount", true);
            $allocatedCents = $this->parseDecimalToCents((string)$alloc['allocated_amount'], "Allocation #{$allocationId} allocated amount", true);
            if ($subtotalCents <= 0 || $expectedAllocCents !== $subtotalCents || $allocatedCents !== $expectedAllocCents) {
                throw new RuntimeException(
                    "Allocation #{$allocationId} financial amounts do not match Order #ORD-{$orderId} purchase subtotal.",
                    self::ERR_UNPROCESSABLE
                );
            }

            $curHandover = (string)$alloc['handover_status'];
            $curVerification = (string)$alloc['verification_status'];
            $oldStateSummary = "handover:{$curHandover}; verification:{$curVerification}; batch:{$batchStatus}";

            if ($operationType === 'receptionist_handover') {
                if ($curHandover !== 'submitted' || $curVerification !== 'pending' || $alloc['completed_by_employee_id'] !== null || $alloc['completed_at'] !== null) {
                    throw new RuntimeException(
                        "Allocation #{$allocationId} handover has already been completed.",
                        self::ERR_CONFLICT
                    );
                }

                if ($autoVerifyEnabled) {
                    $updStmt = $this->db->prepare(
                        "UPDATE courier_cod_remittance_allocation
                         SET handover_status = 'completed',
                             completed_by_employee_id = ?,
                             completed_at = UTC_TIMESTAMP(),
                             verification_status = 'verified',
                             verified_by_employee_id = NULL,
                             verified_at = UTC_TIMESTAMP(),
                             verification_mode = 'auto_setting',
                             updated_at = NOW()
                         WHERE allocation_id = ?
                           AND remittance_id = ?
                           AND business_unit_id = ?
                           AND allocation_status = 'active'
                           AND handover_status = 'submitted'
                           AND completed_by_employee_id IS NULL
                           AND completed_at IS NULL
                           AND verification_status = 'pending'
                           AND verified_by_employee_id IS NULL
                           AND verified_at IS NULL
                           AND verification_mode IS NULL"
                    );
                    $updStmt->execute([$actorEmployeeId, $allocationId, $remittanceId, $businessUnitId]);
                } else {
                    $updStmt = $this->db->prepare(
                        "UPDATE courier_cod_remittance_allocation
                         SET handover_status = 'completed',
                             completed_by_employee_id = ?,
                             completed_at = UTC_TIMESTAMP(),
                             updated_at = NOW()
                         WHERE allocation_id = ?
                           AND remittance_id = ?
                           AND business_unit_id = ?
                           AND allocation_status = 'active'
                           AND handover_status = 'submitted'
                           AND completed_by_employee_id IS NULL
                           AND completed_at IS NULL
                           AND verification_status = 'pending'
                           AND verified_by_employee_id IS NULL
                           AND verified_at IS NULL
                           AND verification_mode IS NULL"
                    );
                    $updStmt->execute([$actorEmployeeId, $allocationId, $remittanceId, $businessUnitId]);
                }

                if ($updStmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        "Allocation #{$allocationId} could not be marked completed due to a concurrent state change.",
                        self::ERR_CONFLICT
                    );
                }
                $auditActionCode = 'petstore_receptionist.cod_remittance_allocation_completed';
            } elseif ($operationType === 'manager_collection') {
                if ($curHandover !== 'submitted' || $curVerification !== 'pending' || $alloc['completed_by_employee_id'] !== null || $alloc['completed_at'] !== null) {
                    throw new RuntimeException(
                        "Allocation #{$allocationId} is no longer awaiting handover and cannot use direct manager collection.",
                        self::ERR_CONFLICT
                    );
                }

                $updStmt = $this->db->prepare(
                    "UPDATE courier_cod_remittance_allocation
                     SET handover_status = 'completed',
                         completed_by_employee_id = ?,
                         completed_at = UTC_TIMESTAMP(),
                         verification_status = 'verified',
                         verified_by_employee_id = ?,
                         verified_at = UTC_TIMESTAMP(),
                         verification_mode = 'manager_collection',
                         updated_at = NOW()
                     WHERE allocation_id = ?
                       AND remittance_id = ?
                       AND business_unit_id = ?
                       AND allocation_status = 'active'
                       AND handover_status = 'submitted'
                       AND completed_by_employee_id IS NULL
                       AND completed_at IS NULL
                       AND verification_status = 'pending'
                       AND verified_by_employee_id IS NULL
                       AND verified_at IS NULL
                       AND verification_mode IS NULL"
                );
                $updStmt->execute([$actorEmployeeId, $actorEmployeeId, $allocationId, $remittanceId, $businessUnitId]);

                if ($updStmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        "Allocation #{$allocationId} could not be collected due to a concurrent state change.",
                        self::ERR_CONFLICT
                    );
                }
                $auditActionCode = 'manager.cod_remittance_allocation_collected';
            } elseif ($operationType === 'manager_manual_verify') {
                if ($curHandover !== 'completed' || $alloc['completed_by_employee_id'] === null || $alloc['completed_at'] === null) {
                    throw new RuntimeException(
                        "Allocation #{$allocationId} must complete handover before manual verification.",
                        self::ERR_CONFLICT
                    );
                }
                if ($curVerification !== 'pending' || $alloc['verified_by_employee_id'] !== null || $alloc['verified_at'] !== null || $alloc['verification_mode'] !== null) {
                    throw new RuntimeException(
                        "Allocation #{$allocationId} has already been verified.",
                        self::ERR_CONFLICT
                    );
                }

                $updStmt = $this->db->prepare(
                    "UPDATE courier_cod_remittance_allocation
                     SET verification_status = 'verified',
                         verified_by_employee_id = ?,
                         verified_at = UTC_TIMESTAMP(),
                         verification_mode = 'manual',
                         updated_at = NOW()
                     WHERE allocation_id = ?
                       AND remittance_id = ?
                       AND business_unit_id = ?
                       AND allocation_status = 'active'
                       AND handover_status = 'completed'
                       AND completed_by_employee_id = ?
                       AND completed_at = ?
                       AND verification_status = 'pending'
                       AND verified_by_employee_id IS NULL
                       AND verified_at IS NULL
                       AND verification_mode IS NULL"
                );
                $updStmt->execute([
                    $actorEmployeeId,
                    $allocationId,
                    $remittanceId,
                    $businessUnitId,
                    (int)$alloc['completed_by_employee_id'],
                    (string)$alloc['completed_at'],
                ]);

                if ($updStmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        "Allocation #{$allocationId} could not be verified due to a concurrent state change.",
                        self::ERR_CONFLICT
                    );
                }
                $auditActionCode = 'manager.cod_remittance_allocation_verified';
            } else {
                throw new RuntimeException("Unsupported remittance operation: {$operationType}", self::ERR_UNPROCESSABLE);
            }

            // 7. Recalculate and persist parent batch header state from all active allocations.
            $batchState = $this->recalculateAndPersistBatchHeaderInTransaction($remittanceId, $businessUnitId, $header);

            // 8. Fetch updated allocation snapshot.
            $freshAllocStmt = $this->db->prepare(
                "SELECT allocation_id, remittance_id, payment_id, order_id, business_unit_id,
                        expected_amount, allocated_amount, allocation_status,
                        handover_status, completed_by_employee_id, completed_at,
                        verification_status, verified_by_employee_id, verified_at, verification_mode
                 FROM courier_cod_remittance_allocation
                 WHERE allocation_id = ?"
            );
            $freshAllocStmt->execute([$allocationId]);
            $updatedAlloc = $freshAllocStmt->fetch(PDO::FETCH_ASSOC);

            $newMode = $updatedAlloc['verification_mode'] !== null ? (string)$updatedAlloc['verification_mode'] : 'none';
            $newStateSummary = "handover:{$updatedAlloc['handover_status']}; verification:{$updatedAlloc['verification_status']}; mode:{$newMode}; batch:{$batchState['remittance_status']}";
            $auditTargetId = $allocationId <= 4294967295 ? $allocationId : null;

            Audit::record(
                $this->db,
                $auditActionCode,
                "Updated COD remittance allocation #{$allocationId} (Order #ORD-{$orderId}, Batch {$header['remittance_reference']}) to {$newStateSummary}.",
                $businessUnitId,
                $actorUserId,
                $actorEmployeeId,
                $oldStateSummary,
                $newStateSummary,
                'courier_cod_remittance_allocation',
                $auditTargetId
            );

            return [
                'allocation_id' => (int)$updatedAlloc['allocation_id'],
                'remittance_id' => (int)$updatedAlloc['remittance_id'],
                'payment_id' => (int)$updatedAlloc['payment_id'],
                'order_id' => (int)$updatedAlloc['order_id'],
                'business_unit_id' => (int)$updatedAlloc['business_unit_id'],
                'expected_amount' => (string)$updatedAlloc['expected_amount'],
                'allocated_amount' => (string)$updatedAlloc['allocated_amount'],
                'allocation_status' => (string)$updatedAlloc['allocation_status'],
                'handover_status' => (string)$updatedAlloc['handover_status'],
                'completed_by_employee_id' => $updatedAlloc['completed_by_employee_id'] !== null ? (int)$updatedAlloc['completed_by_employee_id'] : null,
                'completed_at' => $updatedAlloc['completed_at'] !== null ? (string)$updatedAlloc['completed_at'] : null,
                'verification_status' => (string)$updatedAlloc['verification_status'],
                'verified_by_employee_id' => $updatedAlloc['verified_by_employee_id'] !== null ? (int)$updatedAlloc['verified_by_employee_id'] : null,
                'verified_at' => $updatedAlloc['verified_at'] !== null ? (string)$updatedAlloc['verified_at'] : null,
                'verification_mode' => $updatedAlloc['verification_mode'] !== null ? (string)$updatedAlloc['verification_mode'] : null,
                'remittance_reference' => (string)$batchState['remittance_reference'],
                'remittance_status' => (string)$batchState['remittance_status'],
                'batch_verified_total' => $batchState['verified_total'],
                'batch_verified_by_employee_id' => $batchState['verified_by_employee_id'],
                'batch_verified_at' => $batchState['verified_at'],
                'batch' => $batchState,
            ];
        });
    }

    /**
     * Derive and persist parent courier_cod_remittance header state from its active allocations.
     * Assumes parent header and active allocations are already locked FOR UPDATE in the current transaction.
     *
     * @param array<string, mixed> $lockedHeader
     * @return array{remittance_id:int, remittance_reference:string, remittance_status:string, expected_total:string, remitted_total:string, verified_total:?string, verified_by_employee_id:?int, verified_at:?string}
     */
    private function recalculateAndPersistBatchHeaderInTransaction(
        int $remittanceId,
        int $businessUnitId,
        array $lockedHeader
    ): array {
        $this->assertInTransaction('Remittance batch header derivation');

        $allocStmt = $this->db->prepare(
            "SELECT allocation_id, expected_amount, allocated_amount,
                    handover_status, completed_by_employee_id, completed_at,
                    verification_status, verified_by_employee_id, verified_at, verification_mode
             FROM courier_cod_remittance_allocation
             WHERE remittance_id = ?
               AND business_unit_id = ?
               AND allocation_status = 'active'
             ORDER BY allocation_id ASC
             FOR UPDATE"
        );
        $allocStmt->execute([$remittanceId, $businessUnitId]);
        $activeAllocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$activeAllocations) {
            throw new RuntimeException(
                "Remittance batch #{$remittanceId} has no active allocations.",
                self::ERR_UNPROCESSABLE
            );
        }

        $headerExpectedCents = $this->parseDecimalToCents((string)$lockedHeader['expected_total'], "Batch #{$remittanceId} expected_total", true);
        $headerRemittedCents = $this->parseDecimalToCents((string)$lockedHeader['remitted_total'], "Batch #{$remittanceId} remitted_total", true);
        if ($headerExpectedCents <= 0 || $headerRemittedCents !== $headerExpectedCents) {
            throw new RuntimeException(
                "Remittance batch #{$remittanceId} header totals are inconsistent.",
                self::ERR_UNPROCESSABLE
            );
        }

        $sumExpectedCents = 0;
        $submittedHandoverCount = 0;
        $completedHandoverCount = 0;
        $pendingVerificationCount = 0;
        $verifiedCount = 0;
        $hasNullVerifierOnVerified = false;
        $nonNullVerifierIds = [];
        $latestVerifiedAt = null;

        foreach ($activeAllocations as $a) {
            $aid = (int)$a['allocation_id'];
            $expCents = $this->parseDecimalToCents((string)$a['expected_amount'], "Allocation #{$aid} expected_amount", true);
            $allocCents = $this->parseDecimalToCents((string)$a['allocated_amount'], "Allocation #{$aid} allocated_amount", true);
            if ($expCents <= 0 || $allocCents !== $expCents) {
                throw new RuntimeException(
                    "Allocation #{$aid} in batch #{$remittanceId} has invalid amount bounds.",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($expCents > self::MAX_MONEY_CENTS - $sumExpectedCents) {
                throw new RuntimeException('Remittance batch total exceeds maximum supported DECIMAL(12,2) limit.', self::ERR_UNPROCESSABLE);
            }
            $sumExpectedCents += $expCents;

            $hStatus = (string)$a['handover_status'];
            $vStatus = (string)$a['verification_status'];

            if ($hStatus === 'submitted') {
                $submittedHandoverCount++;
            } elseif ($hStatus === 'completed') {
                $completedHandoverCount++;
            } else {
                throw new RuntimeException("Allocation #{$aid} has unsupported handover_status '{$hStatus}'.", self::ERR_UNPROCESSABLE);
            }

            if ($vStatus === 'pending') {
                $pendingVerificationCount++;
            } elseif ($vStatus === 'verified') {
                $verifiedCount++;
                if ($a['verified_by_employee_id'] === null) {
                    $hasNullVerifierOnVerified = true;
                } else {
                    $nonNullVerifierIds[(int)$a['verified_by_employee_id']] = true;
                }
                $vAt = $a['verified_at'] !== null ? (string)$a['verified_at'] : null;
                if ($vAt !== null && ($latestVerifiedAt === null || $vAt > $latestVerifiedAt)) {
                    $latestVerifiedAt = $vAt;
                }
            } else {
                throw new RuntimeException("Allocation #{$aid} has unsupported verification_status '{$vStatus}'.", self::ERR_UNPROCESSABLE);
            }
        }

        if ($sumExpectedCents !== $headerExpectedCents) {
            throw new RuntimeException(
                "Active allocation sum does not match Batch #{$remittanceId} expected_total.",
                self::ERR_UNPROCESSABLE
            );
        }

        $totalActive = count($activeAllocations);
        $expectedFormatted = $this->formatCents($headerExpectedCents);
        $remittedFormatted = $this->formatCents($headerRemittedCents);

        if ($submittedHandoverCount > 0) {
            $derivedStatus = 'submitted';
            $derivedVerifiedTotal = null;
            $derivedVerifiedByEmpId = null;
            $derivedVerifiedAt = null;
        } elseif ($completedHandoverCount === $totalActive && $pendingVerificationCount > 0) {
            $derivedStatus = 'completed';
            $derivedVerifiedTotal = null;
            $derivedVerifiedByEmpId = null;
            $derivedVerifiedAt = null;
        } elseif ($completedHandoverCount === $totalActive && $verifiedCount === $totalActive && $pendingVerificationCount === 0) {
            $derivedStatus = 'verified';
            $derivedVerifiedTotal = $expectedFormatted;
            if (!$hasNullVerifierOnVerified && count($nonNullVerifierIds) === 1) {
                $derivedVerifiedByEmpId = (int)array_key_first($nonNullVerifierIds);
                if ($derivedVerifiedByEmpId === (int)$lockedHeader['courier_employee_id']) {
                    throw new RuntimeException(
                        'Batch verifier cannot match the submitting courier employee.',
                        self::ERR_FORBIDDEN
                    );
                }
            } else {
                $derivedVerifiedByEmpId = null;
            }
            $derivedVerifiedAt = $latestVerifiedAt ?? gmdate('Y-m-d H:i:s');
        } else {
            throw new RuntimeException(
                "Inconsistent allocation lifecycle state in batch #{$remittanceId}.",
                self::ERR_UNPROCESSABLE
            );
        }

        $updHdr = $this->db->prepare(
            "UPDATE courier_cod_remittance
             SET remittance_status = ?,
                 verified_total = ?,
                 verified_by_employee_id = ?,
                 verified_at = ?,
                 updated_at = NOW()
             WHERE remittance_id = ?
               AND business_unit_id = ?"
        );
        $updHdr->execute([
            $derivedStatus,
            $derivedVerifiedTotal,
            $derivedVerifiedByEmpId,
            $derivedVerifiedAt,
            $remittanceId,
            $businessUnitId,
        ]);

        return [
            'remittance_id' => $remittanceId,
            'remittance_reference' => (string)$lockedHeader['remittance_reference'],
            'remittance_status' => $derivedStatus,
            'expected_total' => $expectedFormatted,
            'remitted_total' => $remittedFormatted,
            'verified_total' => $derivedVerifiedTotal,
            'verified_by_employee_id' => $derivedVerifiedByEmpId,
            'verified_at' => $derivedVerifiedAt,
        ];
    }

    /**
     * Resolve and lock the authenticated actor's active employee record in Pet Store (business_unit_id = 1)
     * and verify active user_account, active business_unit, and unit-scoped role assignment.
     * Never accepts an arbitrary employee_id from request parameters.
     */
    private function lockAuthorizedRemittanceActorByUser(
        int $actorUserId,
        int $businessUnitId,
        string $requiredRole
    ): int {
        if ($actorUserId <= 0) {
            throw new RuntimeException('A valid authenticated user account is required.', self::ERR_FORBIDDEN);
        }
        if ($businessUnitId !== self::PET_STORE_UNIT_ID) {
            throw new RuntimeException(
                'COD remittance lifecycle operations are restricted to Pet Store (business unit #1).',
                self::ERR_FORBIDDEN
            );
        }

        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.user_account_id, e.business_unit_id, e.employee_status,
                    ua.account_status, bu.business_unit_status
             FROM employee e
             LEFT JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
             WHERE e.user_account_id = ?
             ORDER BY e.employee_id ASC
             FOR UPDATE"
        );
        $empStmt->execute([$actorUserId]);
        $empRows = $empStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$empRows) {
            throw new RuntimeException(
                "User #{$actorUserId} does not have an employee record for COD remittance operations.",
                self::ERR_FORBIDDEN
            );
        }

        $matchedEmp = null;
        foreach ($empRows as $row) {
            if ((int)($row['business_unit_id'] ?? 0) === $businessUnitId && (string)($row['employee_status'] ?? '') === 'active') {
                $matchedEmp = $row;
                break;
            }
        }

        if (!$matchedEmp) {
            throw new RuntimeException(
                "User #{$actorUserId} does not have an active employee assignment in business unit #{$businessUnitId}.",
                self::ERR_FORBIDDEN
            );
        }

        if ((string)($matchedEmp['account_status'] ?? '') !== 'active') {
            throw new RuntimeException("User account #{$actorUserId} is inactive or suspended.", self::ERR_FORBIDDEN);
        }

        if ((string)($matchedEmp['business_unit_status'] ?? '') !== 'active') {
            throw new RuntimeException("Business unit #{$businessUnitId} is inactive.", self::ERR_FORBIDDEN);
        }

        if (!$this->userHasUnitRole($actorUserId, $businessUnitId, $requiredRole)) {
            throw new RuntimeException(
                "This COD remittance action requires an active {$requiredRole} role assignment in business unit #{$businessUnitId}.",
                self::ERR_FORBIDDEN
            );
        }

        return (int)$matchedEmp['employee_id'];
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

        // 2. Lock employee record and verify active account, employee, business-unit, and role authorization.
        $this->lockAuthorizedCollectorEmployee($collectorEmployeeId, $businessUnitId, $actorUserId, $collectionChannel);

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

    private function lockAuthorizedCollectorEmployee(
        int $employeeId,
        int $businessUnitId,
        int $actorUserId,
        string $collectionChannel
    ): void {
        if ($employeeId <= 0) {
            throw new RuntimeException('A valid active staff employee record is required to record payment collection.', self::ERR_FORBIDDEN);
        }
        if ($actorUserId <= 0) {
            throw new RuntimeException('A valid authenticated user account is required to record payment collection.', self::ERR_FORBIDDEN);
        }
        if ($businessUnitId <= 0) {
            throw new RuntimeException('A valid business unit is required to record payment collection.', self::ERR_FORBIDDEN);
        }

        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.user_account_id, e.business_unit_id, e.employee_status,
                    ua.account_status, COALESCE(ua.is_super_admin, 0) AS is_super_admin,
                    bu.business_unit_status
             FROM employee e
             LEFT JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
             WHERE e.employee_id = ?
             FOR UPDATE"
        );
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

        if (!$emp || (string)$emp['employee_status'] !== 'active') {
            throw new RuntimeException("Employee #{$employeeId} is missing or inactive.", self::ERR_FORBIDDEN);
        }

        $empUserId = $emp['user_account_id'] !== null ? (int)$emp['user_account_id'] : 0;
        if ($empUserId <= 0 || $empUserId !== $actorUserId) {
            throw new RuntimeException(
                "Employee #{$employeeId} does not belong to authenticated user #{$actorUserId}.",
                self::ERR_FORBIDDEN
            );
        }

        if ((string)($emp['account_status'] ?? '') !== 'active') {
            throw new RuntimeException("User account #{$actorUserId} is inactive or suspended.", self::ERR_FORBIDDEN);
        }

        $empBuId = $emp['business_unit_id'] !== null ? (int)$emp['business_unit_id'] : 0;

        if ($collectionChannel === 'courier_cod') {
            if ($empBuId !== $businessUnitId) {
                throw new RuntimeException("Employee #{$employeeId} does not belong to business unit #{$businessUnitId}.", self::ERR_FORBIDDEN);
            }
            if ((string)($emp['business_unit_status'] ?? '') !== 'active') {
                throw new RuntimeException("Business unit #{$businessUnitId} is inactive.", self::ERR_FORBIDDEN);
            }
            if (!$this->userHasUnitRole($actorUserId, $businessUnitId, 'DELIVERY_PERSONNEL')) {
                throw new RuntimeException(
                    "Operational courier COD collection requires an active Delivery Personnel role assignment in business unit #{$businessUnitId}.",
                    self::ERR_FORBIDDEN
                );
            }
            return;
        }

        $isSuper = !empty($emp['is_super_admin']) || $this->userHasAnyRole($actorUserId, 'SUPER_ADMINISTRATOR');
        if ($isSuper) {
            if (!$this->isBusinessUnitActive($businessUnitId)) {
                throw new RuntimeException("Business unit #{$businessUnitId} is inactive.", self::ERR_FORBIDDEN);
            }
            return;
        }

        if ($empBuId !== $businessUnitId) {
            throw new RuntimeException("Employee #{$employeeId} does not belong to business unit #{$businessUnitId}.", self::ERR_FORBIDDEN);
        }
        if ((string)($emp['business_unit_status'] ?? '') !== 'active') {
            throw new RuntimeException("Business unit #{$businessUnitId} is inactive.", self::ERR_FORBIDDEN);
        }
        if (!$this->userHasUnitRole($actorUserId, $businessUnitId, 'RECEPTIONIST')) {
            throw new RuntimeException(
                "In-store pickup COD collection requires an active Receptionist role assignment in business unit #{$businessUnitId}.",
                self::ERR_FORBIDDEN
            );
        }
    }

    /**
     * Validate, normalize, deduplicate, and sort positive integer payment IDs without silent coercion.
     *
     * @param mixed $paymentIds
     * @return list<int>
     */
    public static function validatePositivePaymentIds(mixed $paymentIds): array
    {
        if (!is_array($paymentIds)) {
            throw new RuntimeException('Selected payment IDs must be provided as a list.', self::ERR_UNPROCESSABLE);
        }

        $normalizedIds = [];
        foreach ($paymentIds as $rawId) {
            if (is_int($rawId)) {
                $id = $rawId;
            } elseif (is_string($rawId) && preg_match('/^[1-9]\d*$/', $rawId) === 1 && (string)(int)$rawId === $rawId) {
                $id = (int)$rawId;
            } else {
                throw new RuntimeException('Every selected payment ID must be a valid positive integer.', self::ERR_UNPROCESSABLE);
            }

            if ($id <= 0) {
                throw new RuntimeException('Every selected payment ID must be a positive integer.', self::ERR_UNPROCESSABLE);
            }

            $normalizedIds[$id] = $id;
        }

        $sortedPaymentIds = array_values($normalizedIds);
        sort($sortedPaymentIds, SORT_NUMERIC);

        if (!$sortedPaymentIds) {
            throw new RuntimeException('Select at least one collected COD payment to remit.', self::ERR_UNPROCESSABLE);
        }

        return $sortedPaymentIds;
    }

    /**
     * Strict unit-scoped active employee ownership lock for remittance submission (no cross-unit Super Admin bypass).
     */
    private function lockAuthorizedRemittanceEmployee(int $employeeId, int $businessUnitId, int $actorUserId): void
    {
        if ($employeeId <= 0) {
            throw new RuntimeException('A valid active employee record is required for COD remittance submission.', self::ERR_FORBIDDEN);
        }
        if ($actorUserId <= 0) {
            throw new RuntimeException('A valid authenticated user account is required for COD remittance submission.', self::ERR_FORBIDDEN);
        }
        if ($businessUnitId <= 0) {
            throw new RuntimeException('A valid business unit is required for COD remittance submission.', self::ERR_FORBIDDEN);
        }

        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.user_account_id, e.business_unit_id, e.employee_status,
                    ua.account_status, bu.business_unit_status
             FROM employee e
             LEFT JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
             WHERE e.employee_id = ?
             FOR UPDATE"
        );
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

        if (!$emp || (string)$emp['employee_status'] !== 'active') {
            throw new RuntimeException("Employee #{$employeeId} is missing or inactive.", self::ERR_FORBIDDEN);
        }

        $empUserId = $emp['user_account_id'] !== null ? (int)$emp['user_account_id'] : 0;
        if ($empUserId <= 0 || $empUserId !== $actorUserId) {
            throw new RuntimeException(
                "Employee #{$employeeId} does not belong to authenticated user #{$actorUserId}.",
                self::ERR_FORBIDDEN
            );
        }

        if ((string)($emp['account_status'] ?? '') !== 'active') {
            throw new RuntimeException("User account #{$actorUserId} is inactive or suspended.", self::ERR_FORBIDDEN);
        }

        $empBuId = $emp['business_unit_id'] !== null ? (int)$emp['business_unit_id'] : 0;
        if ($empBuId !== $businessUnitId) {
            throw new RuntimeException("Employee #{$employeeId} does not belong to business unit #{$businessUnitId}.", self::ERR_FORBIDDEN);
        }

        if ((string)($emp['business_unit_status'] ?? '') !== 'active') {
            throw new RuntimeException("Business unit #{$businessUnitId} is inactive.", self::ERR_FORBIDDEN);
        }

        if (!$this->userHasUnitRole($actorUserId, $businessUnitId, 'DELIVERY_PERSONNEL')) {
            throw new RuntimeException(
                "COD remittance submission requires an active Delivery Personnel role assignment in business unit #{$businessUnitId}.",
                self::ERR_FORBIDDEN
            );
        }
    }

    private function userHasUnitRole(int $userId, int $businessUnitId, string $roleName): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1
             FROM account_role ar
             JOIN role r ON r.role_id = ar.role_id
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id AND bu.business_unit_status = 'active'
             WHERE ar.user_account_id = ?
               AND ar.business_unit_id = ?
               AND r.role_name = ?
             LIMIT 1"
        );
        $stmt->execute([$userId, $businessUnitId, $roleName]);
        return (bool)$stmt->fetchColumn();
    }

    private function userHasAnyRole(int $userId, string $roleName): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1
             FROM account_role ar
             JOIN role r ON r.role_id = ar.role_id
             WHERE ar.user_account_id = ?
               AND r.role_name = ?
             LIMIT 1"
        );
        $stmt->execute([$userId, $roleName]);
        return (bool)$stmt->fetchColumn();
    }

    private function isBusinessUnitActive(int $businessUnitId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM business_unit WHERE business_unit_id = ? AND business_unit_status = 'active' LIMIT 1"
        );
        $stmt->execute([$businessUnitId]);
        return (bool)$stmt->fetchColumn();
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
