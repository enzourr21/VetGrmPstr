<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Audit;
use PDO;
use RuntimeException;
use Throwable;

final class PosSaleService
{
    public const ERR_FORBIDDEN = 403;
    public const ERR_CONFLICT = 409;
    public const ERR_UNPROCESSABLE = 422;

    private const ALLOWED_PAYMENT_METHODS = [
        'Cash',
        'Credit Card',
        'Debit Card',
        'GCash',
        'Maya',
    ];

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    /**
     * Atomically validate, lock inventory and FEFO batches (excluding active reservation holds),
     * deduct physical stock, and record a Pet Store POS sale, items, movements, payment, and audit entry.
     *
     * Lock-order & consistency strategy:
     * - OrderReservationService::confirmPickupOrderAndReserve() locks:
     *     inventory (ASC) -> inventory_batch -> inventory_reservation
     * - OrderReservationService::fulfillPickupOrder() locks:
     *     inventory_reservation -> inventory_movement -> inventory (ASC) -> inventory_batch (ASC)
     * - To avoid lock-order inversion deadlocks against pickup fulfillment without blind helper reuse,
     *   this service locks ONLY inventory (ASC) -> inventory_batch (never taking locks on
     *   inventory_reservation or inventory_movement).
     * - Using READ COMMITTED for this single transaction guarantees that non-locking reads of active
     *   reservations and fulfillment movements inside Step 4 observe the latest committed state AFTER
     *   all target inventory rows are exclusively locked (avoiding a stale REPEATABLE READ snapshot),
     *   while holding inventory FOR UPDATE prevents any concurrent reservation/fulfillment commit on
     *   those inventories until this transaction finishes.
     *
     * @param array<int,array{business_product_id:int|string,quantity:int|string}> $rawLines
     * @return array{sale_id:int, total_amount:float, items_count:int, movements_created:int}
     * @throws RuntimeException on validation failure, cross-unit access, missing inventory, or insufficient stock
     * @throws Throwable on database failure (including lock/serialization conflicts; always rolled back)
     */
    public function recordSale(
        int $businessUnitId,
        int $actorUserId,
        int $employeeId,
        ?int $customerId,
        string $paymentMethod,
        array $rawLines
    ): array {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('POS sale recording must own a top-level database transaction.', self::ERR_CONFLICT);
        }

        if ($businessUnitId <= 0 || $actorUserId <= 0 || $employeeId <= 0) {
            throw new RuntimeException('Valid business unit, actor user, and employee context are required.', self::ERR_FORBIDDEN);
        }

        if (!in_array($paymentMethod, self::ALLOWED_PAYMENT_METHODS, true)) {
            throw new RuntimeException("Unsupported payment method '{$paymentMethod}'.", self::ERR_UNPROCESSABLE);
        }

        $parsedLines = $this->parseSaleLines($rawLines);
        $bpIds = [];
        foreach ($parsedLines as $pLine) {
            $bpIds[$pLine['bp_id']] = $pLine['bp_id'];
        }
        $bpIds = array_values($bpIds);
        sort($bpIds, SORT_NUMERIC);

        // Next-transaction isolation only (does not alter session isolation); ensures Step 4's non-locking
        // reservation/movement reads see a fresh committed snapshot after acquiring inventory FOR UPDATE.
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            // 1. Resolve unit's active primary branch and verify employee / optional customer FK targets.
            $branchId = $this->resolveMainBranchId($businessUnitId);
            $this->assertActiveEmployee($employeeId);
            if ($customerId !== null) {
                $this->assertActiveCustomer($customerId);
            }

            // 2. Load unit-scoped product metadata without locking inventory rows out of order.
            $metaByBpId = $this->loadUnitProductMetadata($businessUnitId, $bpIds);
            foreach ($bpIds as $bpId) {
                $meta = $metaByBpId[$bpId] ?? null;
                if (!$meta) {
                    throw new RuntimeException(
                        "Selected product (ID #{$bpId}) does not belong to this business unit or could not be found.",
                        self::ERR_UNPROCESSABLE
                    );
                }
                if ((string)$meta['product_status'] !== 'active') {
                    throw new RuntimeException(
                        "Selected product '{$meta['product_name']}' is inactive and cannot be sold.",
                        self::ERR_UNPROCESSABLE
                    );
                }
                if ($meta['inventory_id'] === null) {
                    throw new RuntimeException(
                        "Missing inventory record for '{$meta['product_name']}'.",
                        self::ERR_UNPROCESSABLE
                    );
                }
            }

            // Preserve separate submitted sale_item lines while grouping them by inventory for aggregated checks.
            $itemsByInventory = [];
            $inventoryIds = [];
            $linePlan = [];
            $totalAmount = 0.0;

            foreach ($parsedLines as $pLine) {
                $lineIndex = $pLine['line_index'];
                $bpId = $pLine['bp_id'];
                $qty = $pLine['qty'];
                $meta = $metaByBpId[$bpId];

                $invId = (int)$meta['inventory_id'];
                $unitPrice = (float)$meta['selling_price'];
                $totalAmount = round($totalAmount + round($unitPrice * $qty, 2), 2);

                $lineData = [
                    'line_index' => $lineIndex,
                    'bp_id' => $bpId,
                    'inventory_id' => $invId,
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'requires_batch_tracking' => !empty($meta['requires_batch_tracking']),
                    'product_name' => (string)$meta['product_name'],
                ];

                $linePlan[$lineIndex] = $lineData;
                $inventoryIds[$invId] = $invId;
                $itemsByInventory[$invId][] = $lineData;
            }

            // 3. Lock distinct inventory rows in deterministic inventory_id ASC order.
            $sortedInvIds = array_values($inventoryIds);
            sort($sortedInvIds, SORT_NUMERIC);
            $lockedInventory = $this->lockInventories($sortedInvIds);

            // 4. Revalidate physically sellable on-hand stock (excluding shelf commitments) and distribute FEFO batches.
            $allocationsByLineIndex = [];
            $batchDeductionByBatchId = [];
            $onHandDeductionByInv = [];

            foreach ($sortedInvIds as $invId) {
                $inv = $lockedInventory[$invId] ?? null;
                if (!$inv) {
                    throw new RuntimeException("Inventory record #{$invId} could not be locked.", self::ERR_UNPROCESSABLE);
                }

                $onHand = (int)$inv['on_hand'];
                $committed = (int)$inv['committed'];
                $inTransit = (int)$inv['in_transit'];

                if ($onHand < 0 || $committed < 0 || $inTransit < 0 || $committed < $inTransit) {
                    throw new RuntimeException(
                        "Inventory state invariant violated for inventory #{$invId}.",
                        self::ERR_CONFLICT
                    );
                }

                // Committed >= In Transit >= 0; in_transit is quarantined/dispatched and excluded from shelf commitments.
                $shelfCommitted = $committed - $inTransit;
                $physicallySellableOnHand = $onHand - $shelfCommitted;

                $invLines = $itemsByInventory[$invId];
                $productName = $invLines[0]['product_name'];
                $requiresBatch = $invLines[0]['requires_batch_tracking'];

                $totalRequestedForInv = 0;
                foreach ($invLines as $invLine) {
                    $totalRequestedForInv += $invLine['qty'];
                }

                if ($physicallySellableOnHand < $totalRequestedForInv) {
                    $sellableDisplay = max(0, $physicallySellableOnHand);
                    throw new RuntimeException(
                        "Insufficient physically sellable stock for '{$productName}'. Available: {$sellableDisplay}, requested: {$totalRequestedForInv}.",
                        self::ERR_UNPROCESSABLE
                    );
                }

                if ($requiresBatch) {
                    $batchPool = $this->lockEligibleFefoBatchesForSale($invId);
                    $totalUnreserved = 0;
                    foreach ($batchPool as $batch) {
                        $totalUnreserved += $batch['unreserved_quantity'];
                    }

                    if ($totalUnreserved < $totalRequestedForInv) {
                        throw new RuntimeException(
                            "Insufficient eligible non-expired unreserved batch stock for '{$productName}'. Available: {$totalUnreserved}, requested: {$totalRequestedForInv}.",
                            self::ERR_UNPROCESSABLE
                        );
                    }

                    foreach ($invLines as $invLine) {
                        $remaining = $invLine['qty'];
                        $slices = [];

                        foreach ($batchPool as &$batch) {
                            if ($remaining <= 0) {
                                break;
                            }
                            if ($batch['unreserved_quantity'] <= 0) {
                                continue;
                            }

                            $take = min($remaining, $batch['unreserved_quantity']);
                            $batchId = (int)$batch['batch_id'];
                            $slices[] = [
                                'batch_id' => $batchId,
                                'batch_number' => $batch['batch_number'],
                                'quantity' => $take,
                            ];
                            $batchDeductionByBatchId[$batchId] = ($batchDeductionByBatchId[$batchId] ?? 0) + $take;
                            $batch['unreserved_quantity'] -= $take;
                            $remaining -= $take;
                        }
                        unset($batch);

                        if ($remaining > 0) {
                            throw new RuntimeException(
                                "Failed to allocate full FEFO quantity for '{$productName}'.",
                                self::ERR_UNPROCESSABLE
                            );
                        }

                        $allocationsByLineIndex[$invLine['line_index']] = $slices;
                    }
                } else {
                    foreach ($invLines as $invLine) {
                        $allocationsByLineIndex[$invLine['line_index']] = [[
                            'batch_id' => null,
                            'batch_number' => null,
                            'quantity' => $invLine['qty'],
                        ]];
                    }
                }

                $onHandDeductionByInv[$invId] = $totalRequestedForInv;
            }

            // 5. Persist sale, preserved sale items, movements, batch & inventory deductions, payment, and audit record.
            $sStmt = $this->db->prepare(
                "INSERT INTO sale (business_unit_id, branch_id, customer_id, employee_id, sale_date, total_amount, sale_status)
                 VALUES (?, ?, ?, ?, NOW(), ?, 'completed')"
            );
            $sStmt->execute([$businessUnitId, $branchId, $customerId, $employeeId, $totalAmount]);
            $saleId = (int)$this->db->lastInsertId();

            $siStmt = $this->db->prepare(
                "INSERT INTO sale_item (sale_id, business_product_id, quantity, unit_price)
                 VALUES (?, ?, ?, ?)"
            );
            $movStmt = $this->db->prepare(
                "INSERT INTO inventory_movement (inventory_id, batch_id, sale_item_id, reservation_id, movement_type, quantity, notes)
                 VALUES (?, ?, ?, NULL, 'sale', ?, ?)"
            );

            $movementsCreated = 0;
            foreach ($linePlan as $lineIndex => $line) {
                $siStmt->execute([$saleId, $line['bp_id'], $line['qty'], $line['unit_price']]);
                $saleItemId = (int)$this->db->lastInsertId();

                foreach ($allocationsByLineIndex[$lineIndex] as $slice) {
                    $batchSuffix = $slice['batch_number'] !== null ? " (Batch: {$slice['batch_number']})" : '';
                    $notes = mb_substr("Pet Store POS sale #{$saleId}{$batchSuffix}", 0, 255);
                    $movStmt->execute([
                        $line['inventory_id'],
                        $slice['batch_id'],
                        $saleItemId,
                        -$slice['quantity'],
                        $notes,
                    ]);
                    $movementsCreated++;
                }
            }

            if ($batchDeductionByBatchId) {
                ksort($batchDeductionByBatchId, SORT_NUMERIC);
                $batchUpdStmt = $this->db->prepare(
                    "UPDATE inventory_batch
                     SET quantity = quantity - ?, updated_at = NOW()
                     WHERE batch_id = ?"
                );
                foreach ($batchDeductionByBatchId as $batchId => $deductQty) {
                    $batchUpdStmt->execute([$deductQty, $batchId]);
                }
            }

            $invStmt = $this->db->prepare(
                "UPDATE inventory
                 SET on_hand = on_hand - ?, updated_at = NOW()
                 WHERE inventory_id = ?"
            );
            foreach ($onHandDeductionByInv as $invId => $deductQty) {
                $invStmt->execute([$deductQty, $invId]);
            }

            $payStmt = $this->db->prepare(
                "INSERT INTO payment (sale_id, payment_type, amount, payment_date, payment_method, payment_status)
                 VALUES (?, 'payment', ?, NOW(), ?, 'completed')"
            );
            $payStmt->execute([$saleId, $totalAmount, $paymentMethod]);

            Audit::record(
                $this->db,
                'petstore_receptionist.sale.create',
                "Recorded new sale #{$saleId} for ₱" . number_format($totalAmount, 2),
                $businessUnitId,
                $actorUserId,
                null,
                null,
                'sale_status:completed; total:' . number_format($totalAmount, 2, '.', ''),
                'sale',
                $saleId
            );

            $this->db->commit();

            return [
                'sale_id' => $saleId,
                'total_amount' => $totalAmount,
                'items_count' => count($linePlan),
                'movements_created' => $movementsCreated,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Validate and preserve submitted POS sale lines in submission order.
     *
     * @param array<int,array{business_product_id:int|string,quantity:int|string}> $rawLines
     * @return array<int,array{line_index:int,bp_id:int,qty:int}>
     */
    private function parseSaleLines(array $rawLines): array
    {
        $parsedLines = [];
        $lineIndex = 0;

        foreach ($rawLines as $line) {
            $bpId = isset($line['business_product_id']) ? (int)$line['business_product_id'] : 0;
            $qty = isset($line['quantity']) ? (int)$line['quantity'] : 0;

            if ($qty < 0) {
                throw new RuntimeException('Sale product quantities must be greater than zero.', self::ERR_UNPROCESSABLE);
            }
            if ($bpId <= 0 || $qty === 0) {
                continue;
            }

            $parsedLines[] = [
                'line_index' => $lineIndex++,
                'bp_id' => $bpId,
                'qty' => $qty,
            ];
        }

        if (!$parsedLines) {
            throw new RuntimeException('At least one valid product line with quantity greater than zero is required.', self::ERR_UNPROCESSABLE);
        }

        return $parsedLines;
    }

    private function resolveMainBranchId(int $businessUnitId): int
    {
        $stmt = $this->db->prepare(
            "SELECT branch_id
             FROM branch
             WHERE business_unit_id = ? AND branch_status = 'active'
             ORDER BY branch_id ASC
             LIMIT 1"
        );
        $stmt->execute([$businessUnitId]);
        $branchId = $stmt->fetchColumn();
        if (!$branchId) {
            throw new RuntimeException("No active branch found for business unit #{$businessUnitId}.", self::ERR_UNPROCESSABLE);
        }
        return (int)$branchId;
    }

    private function assertActiveEmployee(int $employeeId): void
    {
        $stmt = $this->db->prepare("SELECT employee_id FROM employee WHERE employee_id = ? AND employee_status = 'active' LIMIT 1");
        $stmt->execute([$employeeId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('Active employee record is required to process POS sales.', self::ERR_FORBIDDEN);
        }
    }

    private function assertActiveCustomer(int $customerId): void
    {
        $stmt = $this->db->prepare("SELECT customer_id FROM customer WHERE customer_id = ? AND customer_status = 'active' LIMIT 1");
        $stmt->execute([$customerId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('The selected registered customer could not be found or is inactive.', self::ERR_UNPROCESSABLE);
        }
    }

    /**
     * @param array<int,int> $bpIds
     * @return array<int,array<string,mixed>>
     */
    private function loadUnitProductMetadata(int $businessUnitId, array $bpIds): array
    {
        $placeholders = implode(',', array_fill(0, count($bpIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT bp.business_product_id, bp.selling_price, bp.product_status,
                    bp.requires_batch_tracking, p.product_name, i.inventory_id
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_product_id IN ({$placeholders})
               AND bp.business_unit_id = ?
             ORDER BY bp.business_product_id ASC"
        );
        $stmt->execute(array_merge($bpIds, [$businessUnitId]));

        $metaByBpId = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $metaByBpId[(int)$row['business_product_id']] = $row;
        }
        return $metaByBpId;
    }

    /**
     * @param array<int,int> $sortedInvIds
     * @return array<int,array<string,mixed>>
     */
    private function lockInventories(array $sortedInvIds): array
    {
        $placeholders = implode(',', array_fill(0, count($sortedInvIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT inventory_id, on_hand, committed, COALESCE(in_transit, 0) AS in_transit
             FROM inventory
             WHERE inventory_id IN ({$placeholders})
             ORDER BY inventory_id ASC
             FOR UPDATE"
        );
        $stmt->execute($sortedInvIds);

        $locked = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locked[(int)$row['inventory_id']] = $row;
        }
        return $locked;
    }

    /**
     * Lock eligible non-expired batches in FEFO order and subtract remaining unfulfilled active reservation
     * holds (accounting for any prior partial order_fulfillment outbound movements).
     *
     * @return array<int,array{batch_id:int, batch_number:string, expiration_date:?string, physical_quantity:int, unreserved_quantity:int}>
     */
    private function lockEligibleFefoBatchesForSale(int $inventoryId): array
    {
        $batchStmt = $this->db->prepare(
            "SELECT batch_id, batch_number, expiration_date, quantity
             FROM inventory_batch
             WHERE inventory_id = ?
               AND quantity > 0
               AND (expiration_date IS NULL OR expiration_date >= CURDATE())
             ORDER BY
               (CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END) ASC,
               expiration_date ASC,
               batch_id ASC
             FOR UPDATE"
        );
        $batchStmt->execute([$inventoryId]);
        $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$batches) {
            return [];
        }

        // Non-locking reads under READ COMMITTED: never acquire locks on inventory_reservation or
        // inventory_movement (avoiding lock inversion against fulfillPickupOrder's reservation->inventory
        // order). Because inventory FOR UPDATE is already held for $inventoryId and all reservation and
        // fulfillment writers must hold inventory FOR UPDATE before mutating or committing rows for this
        // inventory, the committed state observed here is stable and quiescent until commit/rollback.
        $resStmt = $this->db->prepare(
            "SELECT reservation_id, batch_id, reserved_quantity
             FROM inventory_reservation
             WHERE inventory_id = ?
               AND reservation_status = 'active'
               AND batch_id IS NOT NULL
             ORDER BY reservation_id ASC"
        );
        $resStmt->execute([$inventoryId]);
        $activeReservations = $resStmt->fetchAll(PDO::FETCH_ASSOC);

        $fulfilledByResId = [];
        if ($activeReservations) {
            $resIds = array_map(static fn(array $r): int => (int)$r['reservation_id'], $activeReservations);
            $resPlaceholders = implode(',', array_fill(0, count($resIds), '?'));
            $movStmt = $this->db->prepare(
                "SELECT movement_id, reservation_id, quantity
                 FROM inventory_movement
                 WHERE reservation_id IN ({$resPlaceholders})
                   AND movement_type = 'order_fulfillment'
                 ORDER BY movement_id ASC"
            );
            $movStmt->execute($resIds);
            foreach ($movStmt->fetchAll(PDO::FETCH_ASSOC) as $mRow) {
                $rId = (int)$mRow['reservation_id'];
                $mQty = (int)$mRow['quantity'];
                if ($mQty >= 0) {
                    throw new RuntimeException("Invalid non-negative order_fulfillment movement #{$mRow['movement_id']}.", self::ERR_UNPROCESSABLE);
                }
                $fulfilledByResId[$rId] = ($fulfilledByResId[$rId] ?? 0) + (-$mQty);
            }
        }

        $activeHoldByBatch = [];
        foreach ($activeReservations as $res) {
            $resId = (int)$res['reservation_id'];
            $bId = (int)$res['batch_id'];
            $reservedQty = (int)$res['reserved_quantity'];
            $fulfilledQty = $fulfilledByResId[$resId] ?? 0;
            if ($fulfilledQty > $reservedQty) {
                throw new RuntimeException("Reservation #{$resId} has outbound fulfillment movements exceeding reserved quantity.", self::ERR_UNPROCESSABLE);
            }
            $remainingHold = $reservedQty - $fulfilledQty;
            $activeHoldByBatch[$bId] = ($activeHoldByBatch[$bId] ?? 0) + $remainingHold;
        }

        $pool = [];
        foreach ($batches as $b) {
            $bId = (int)$b['batch_id'];
            $physicalQty = (int)$b['quantity'];
            $heldQty = $activeHoldByBatch[$bId] ?? 0;
            $unreservedQty = max(0, $physicalQty - $heldQty);

            $pool[] = [
                'batch_id' => $bId,
                'batch_number' => (string)$b['batch_number'],
                'expiration_date' => $b['expiration_date'] !== null ? (string)$b['expiration_date'] : null,
                'physical_quantity' => $physicalQty,
                'unreserved_quantity' => $unreservedQty,
            ];
        }

        return $pool;
    }
}
