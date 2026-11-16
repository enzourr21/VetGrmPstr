<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Audit;
use App\Models\InventoryBatch;
use PDO;
use RuntimeException;
use Throwable;

final class OrderReservationService
{
    public const ERR_NOT_FOUND = 404;
    public const ERR_FORBIDDEN = 403;
    public const ERR_CONFLICT = 409;
    public const ERR_UNPROCESSABLE = 422;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    /**
     * Atomically confirm a pending Pet Store pickup order and reserve inventory using FEFO batch allocation.
     *
     * @return array{order_id:int, reservations_created:int, total_reserved_quantity:int}
     * @throws RuntimeException on missing order, forbidden fulfillment method, invalid status, duplicate reservation, missing inventory, or insufficient stock
     * @throws Throwable on database failure
     */
    public function confirmPickupOrderAndReserve(
        int $orderId,
        int $businessUnitId,
        int $actorUserId
    ): array {
        return $this->confirmPendingOrderAndReserve($orderId, $businessUnitId, $actorUserId, 'pickup');
    }

    /**
     * Atomically confirm a pending Pet Store delivery order and reserve inventory using FEFO batch allocation.
     *
     * @return array{order_id:int, reservations_created:int, total_reserved_quantity:int}
     * @throws RuntimeException on missing order, forbidden fulfillment method, invalid status, duplicate reservation, missing inventory, or insufficient stock
     * @throws Throwable on database failure
     */
    public function confirmDeliveryOrderAndReserve(
        int $orderId,
        int $businessUnitId,
        int $actorUserId
    ): array {
        return $this->confirmPendingOrderAndReserve($orderId, $businessUnitId, $actorUserId, 'delivery');
    }

    /**
     * Atomically move a confirmed Pet Store delivery order to processing after verifying complete active reservation coverage.
     *
     * @return array{order_id:int, order_status:string}
     * @throws RuntimeException
     * @throws Throwable
     */
    public function markDeliveryOrderProcessing(
        int $orderId,
        int $businessUnitId,
        int $actorUserId
    ): array {
        $this->assertTopLevelTransaction('Delivery order preparation update');

        $this->db->beginTransaction();
        try {
            $order = $this->lockDeliveryOrder($orderId, $businessUnitId);
            $currentStatus = (string)$order['order_status'];
            if ($currentStatus !== 'confirmed') {
                throw new RuntimeException(
                    "Invalid transition for delivery order from '{$currentStatus}' to 'processing'. Permitted flow: Pending → Confirmed → Processing.",
                    self::ERR_CONFLICT
                );
            }

            $orderItems = $this->lockOrderItems($orderId);
            $metaByBpId = $this->resolveUnitProductMetadata($orderId, $businessUnitId, $orderItems);
            $this->lockOrderItemInventories($orderItems, $metaByBpId);
            $this->lockCompleteActiveReservations($orderId, $businessUnitId, $orderItems, 'preparation', $metaByBpId);

            $updOrdStmt = $this->db->prepare(
                "UPDATE customer_order
                 SET order_status = 'processing', updated_at = NOW()
                 WHERE order_id = ? AND order_status = 'confirmed'"
            );
            $updOrdStmt->execute([$orderId]);
            if ($updOrdStmt->rowCount() !== 1) {
                throw new RuntimeException("Order #{$orderId} could not be transitioned to processing.", self::ERR_CONFLICT);
            }

            Audit::record(
                $this->db,
                'petstore_receptionist.order.delivery_status',
                "Prepared delivery order #{$orderId} and updated status from confirmed to processing.",
                $businessUnitId,
                $actorUserId,
                null,
                'status:confirmed',
                'status:processing',
                'customer_order',
                $orderId
            );

            $this->db->commit();

            return [
                'order_id' => $orderId,
                'order_status' => 'processing',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Validate inside an active dispatch transaction that a delivery order is in processing status
     * and holds complete active reservation coverage for every ordered item.
     *
     * @throws RuntimeException
     */
    public function assertDeliveryOrderReservedForDispatch(
        int $orderId,
        int $businessUnitId
    ): void {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException(
                'Delivery dispatch reservation check must run inside an active transaction.',
                self::ERR_CONFLICT
            );
        }

        $order = $this->lockDeliveryOrder($orderId, $businessUnitId);
        $currentStatus = (string)$order['order_status'];
        if ($currentStatus !== 'processing') {
            throw new RuntimeException(
                "Order #ORD-{$orderId} must be confirmed, reserved, and prepared (processing) before dispatch (current status: '{$currentStatus}').",
                self::ERR_CONFLICT
            );
        }

        $orderItems = $this->lockOrderItems($orderId);
        $metaByBpId = $this->resolveUnitProductMetadata($orderId, $businessUnitId, $orderItems);
        $this->lockOrderItemInventories($orderItems, $metaByBpId);
        $this->lockCompleteActiveReservations($orderId, $businessUnitId, $orderItems, 'dispatch', $metaByBpId);
    }

    /**
     * Shared atomic order confirmation and FEFO reservation implementation for store-accepted pending orders.
     *
     * @return array{order_id:int, reservations_created:int, total_reserved_quantity:int}
     * @throws RuntimeException
     * @throws Throwable
     */
    private function confirmPendingOrderAndReserve(
        int $orderId,
        int $businessUnitId,
        int $actorUserId,
        string $expectedMethod
    ): array {
        $this->assertTopLevelTransaction('Order confirmation');

        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            // 1. Lock the target order exclusively and verify scope, fulfillment method, and state.
            $order = $expectedMethod === 'delivery'
                ? $this->lockDeliveryOrder($orderId, $businessUnitId)
                : $this->lockPickupOrder($orderId, $businessUnitId);
            $currentStatus = (string)$order['order_status'];
            if ($currentStatus !== 'pending') {
                $flow = $expectedMethod === 'delivery'
                    ? 'Pending → Confirmed → Processing'
                    : 'Pending → Confirmed → Ready for Pickup → Completed';
                throw new RuntimeException(
                    "Invalid transition for {$expectedMethod} order from '{$currentStatus}' to 'confirmed'. Permitted flow: {$flow}.",
                    self::ERR_CONFLICT
                );
            }

            // 2. Lock the order's line items in deterministic order.
            $orderItems = $this->lockOrderItems($orderId);

            // 3. Resolve unit-scoped product metadata without acquiring out-of-order inventory locks.
            $metaByBpId = $this->resolveUnitProductMetadata($orderId, $businessUnitId, $orderItems);

            $itemsByInventory = [];
            $inventoryIds = [];
            $oiIds = [];
            foreach ($orderItems as $item) {
                $bpId = (int)$item['business_product_id'];
                $qty = (int)$item['quantity'];
                $meta = $metaByBpId[$bpId];
                $invId = (int)$meta['inventory_id'];
                $oiId = (int)$item['order_item_id'];

                $oiIds[] = $oiId;
                $inventoryIds[$invId] = $invId;
                $itemsByInventory[$invId][] = [
                    'order_item_id' => $oiId,
                    'business_product_id' => $bpId,
                    'quantity' => $qty,
                    'requires_batch_tracking' => !empty($meta['requires_batch_tracking']),
                    'product_name' => (string)$meta['product_name'],
                ];
            }

            // 4. Lock distinct inventory rows in deterministic inventory_id ASC order.
            $sortedInvIds = array_values($inventoryIds);
            sort($sortedInvIds, SORT_NUMERIC);
            $lockedInventory = $this->lockInventories($sortedInvIds);

            // 5. Read active unexpired online-checkout holds (non-locking under READ COMMITTED after inventory FOR UPDATE),
            //    lock batches (batch_id ASC) then reservations (reservation_id ASC), check duplicate order reservations,
            //    and persist FEFO reservations + inventory.committed.
            $activeHeldByInventory = self::activeHeldQuantitiesByInventory($this->db, $sortedInvIds);
            $resSummary = $this->reserveLockedOrderItems(
                $orderId,
                $sortedInvIds,
                $itemsByInventory,
                $lockedInventory,
                $activeHeldByInventory,
                $oiIds
            );
            $reservationsCreated = $resSummary['reservations_created'];
            $totalReservedQty = $resSummary['total_reserved_quantity'];

            $updOrdStmt = $this->db->prepare(
                "UPDATE customer_order
                 SET order_status = 'confirmed', updated_at = NOW()
                 WHERE order_id = ? AND order_status = 'pending'"
            );
            $updOrdStmt->execute([$orderId]);
            if ($updOrdStmt->rowCount() !== 1) {
                throw new RuntimeException("Order #{$orderId} could not be transitioned to confirmed.", self::ERR_CONFLICT);
            }

            $auditAction = $expectedMethod === 'delivery'
                ? 'petstore_receptionist.order.delivery_status'
                : 'petstore_receptionist.order.pickup_status';
            $methodLabel = $expectedMethod === 'delivery' ? 'delivery' : 'pickup';

            Audit::record(
                $this->db,
                $auditAction,
                "Confirmed {$methodLabel} order #{$orderId} and reserved {$totalReservedQty} unit(s).",
                $businessUnitId,
                $actorUserId,
                null,
                'status:pending',
                'status:confirmed',
                'customer_order',
                $orderId
            );

            $this->db->commit();

            return [
                'order_id' => $orderId,
                'reservations_created' => $reservationsCreated,
                'total_reserved_quantity' => $totalReservedQty,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Atomically mark a confirmed Pet Store pickup order as ready_for_pickup without modifying stock.
     *
     * @return array{order_id:int, order_status:string}
     * @throws RuntimeException
     * @throws Throwable
     */
    public function markPickupOrderReady(
        int $orderId,
        int $businessUnitId,
        int $actorUserId
    ): array {
        $this->assertTopLevelTransaction('Order ready-for-pickup update');

        $this->db->beginTransaction();
        try {
            $order = $this->lockPickupOrder($orderId, $businessUnitId);
            $currentStatus = (string)$order['order_status'];
            if ($currentStatus !== 'confirmed') {
                throw new RuntimeException(
                    "Invalid transition for pickup order from '{$currentStatus}' to 'ready_for_pickup'. Permitted flow: Pending → Confirmed → Ready for Pickup → Completed.",
                    self::ERR_CONFLICT
                );
            }

            $orderItems = $this->lockOrderItems($orderId);
            $metaByBpId = $this->resolveUnitProductMetadata($orderId, $businessUnitId, $orderItems);
            $this->lockOrderItemInventories($orderItems, $metaByBpId);
            $this->lockActivePickupReservations($orderId, $orderItems);

            $updOrdStmt = $this->db->prepare(
                "UPDATE customer_order
                 SET order_status = 'ready_for_pickup', updated_at = NOW()
                 WHERE order_id = ? AND order_status = 'confirmed'"
            );
            $updOrdStmt->execute([$orderId]);
            if ($updOrdStmt->rowCount() !== 1) {
                throw new RuntimeException("Order #{$orderId} could not be transitioned to ready_for_pickup.", self::ERR_CONFLICT);
            }

            Audit::record(
                $this->db,
                'petstore_receptionist.order.pickup_status',
                "Updated pickup order #{$orderId} status from confirmed to ready_for_pickup.",
                $businessUnitId,
                $actorUserId,
                null,
                'status:confirmed',
                'status:ready_for_pickup',
                'customer_order',
                $orderId
            );

            $this->db->commit();

            return [
                'order_id' => $orderId,
                'order_status' => 'ready_for_pickup',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Atomically record customer pickup handover (full or partial) for a ready-for-pickup order.
     *
     * @param array<int,int>|null $handoverQuantitiesByOrderItemId Map of order_item_id => quantity, or null to fulfill all remaining reserved units.
     * @return array{
     *     order_id:int,
     *     order_status:string,
     *     partial:bool,
     *     movements_created:int,
     *     fulfilled_quantity:int,
     *     remaining_quantity:int,
     *     reservations_fulfilled:int
     * }
     * @throws RuntimeException
     * @throws Throwable
     */
    public function fulfillPickupOrder(
        int $orderId,
        int $businessUnitId,
        int $actorUserId,
        ?array $handoverQuantitiesByOrderItemId = null
    ): array {
        $this->assertTopLevelTransaction('Pickup handover fulfillment');

        $this->db->beginTransaction();
        try {
            // 1. Lock order and verify scope, fulfillment method, and ready_for_pickup state.
            $order = $this->lockPickupOrder($orderId, $businessUnitId);
            $currentStatus = (string)$order['order_status'];
            if ($currentStatus !== 'ready_for_pickup') {
                throw new RuntimeException(
                    "Invalid transition for pickup order from '{$currentStatus}' to 'completed'. Permitted flow: Pending → Confirmed → Ready for Pickup → Completed.",
                    self::ERR_CONFLICT
                );
            }

            // 2. Lock order items, resolve metadata, and acquire inventory (ASC) -> inventory_batch (ASC) locks
            //    BEFORE locking inventory_reservation -> inventory_movement (strict global lock hierarchy).
            $orderItems = $this->lockOrderItems($orderId);
            $metaByBpId = $this->resolveUnitProductMetadata($orderId, $businessUnitId, $orderItems);
            $lockedInventory = $this->lockOrderItemInventories($orderItems, $metaByBpId);

            $batchInvIds = [];
            $itemsById = [];
            foreach ($orderItems as $item) {
                $itemId = (int)$item['order_item_id'];
                $bpId = (int)$item['business_product_id'];
                $invId = (int)$metaByBpId[$bpId]['inventory_id'];
                $reqBatch = !empty($metaByBpId[$bpId]['requires_batch_tracking']);
                if ($reqBatch) {
                    $batchInvIds[$invId] = $invId;
                }
                $itemsById[$itemId] = [
                    'order_item_id' => $itemId,
                    'business_product_id' => $bpId,
                    'inventory_id' => $invId,
                    'requires_batch_tracking' => $reqBatch,
                    'product_name' => (string)$metaByBpId[$bpId]['product_name'],
                ];
            }
            $lockedBatches = $batchInvIds ? $this->lockInventoryBatchesByInventories(array_values($batchInvIds)) : [];

            $reservationState = $this->lockActivePickupReservations($orderId, $orderItems);
            $activeReservations = $reservationState['active'];
            $priorFulfilledByResId = $reservationState['prior_fulfilled'];

            foreach ($activeReservations as $res) {
                $resId = (int)$res['reservation_id'];
                $itemMeta = $itemsById[(int)$res['order_item_id']] ?? null;
                if (!$itemMeta || (int)$res['inventory_id'] !== $itemMeta['inventory_id']) {
                    throw new RuntimeException(
                        "Reservation #{$resId} on Order #{$orderId} does not belong to business unit #{$businessUnitId} inventory.",
                        self::ERR_UNPROCESSABLE
                    );
                }
                if ($itemMeta['requires_batch_tracking'] && $res['batch_id'] === null) {
                    throw new RuntimeException(
                        "Batch-tracked reservation #{$resId} on Order #{$orderId} is missing a pinned batch.",
                        self::ERR_UNPROCESSABLE
                    );
                }
            }

            // 4. Compute remaining unfulfilled quantities from locked outbound movement totals.
            $reservationsByItem = [];
            $remainingByItem = [];
            $totalRemainingBefore = 0;

            foreach ($activeReservations as $res) {
                $resId = (int)$res['reservation_id'];
                $itemId = (int)$res['order_item_id'];
                $reservedQty = (int)$res['reserved_quantity'];
                $priorFulfilled = $priorFulfilledByResId[$resId] ?? 0;
                $remaining = $reservedQty - $priorFulfilled;

                if ($remaining <= 0) {
                    throw new RuntimeException(
                        "Active reservation #{$resId} on Order #{$orderId} has invalid remaining balance ({$remaining}).",
                        self::ERR_UNPROCESSABLE
                    );
                }

                $reservationsByItem[$itemId][] = [
                    'reservation_id' => $resId,
                    'order_item_id' => $itemId,
                    'inventory_id' => (int)$res['inventory_id'],
                    'batch_id' => $res['batch_id'] !== null ? (int)$res['batch_id'] : null,
                    'reserved_quantity' => $reservedQty,
                    'prior_fulfilled' => $priorFulfilled,
                    'remaining' => $remaining,
                ];
                $remainingByItem[$itemId] = ($remainingByItem[$itemId] ?? 0) + $remaining;
                $totalRemainingBefore += $remaining;
            }

            if ($totalRemainingBefore <= 0) {
                throw new RuntimeException("Order #{$orderId} has already been fulfilled.", self::ERR_CONFLICT);
            }

            // 5. Validate requested handover quantities against remaining unfulfilled reservation balances.
            [$requestedByItem, $totalHandoverQty] = $this->parseRequestedHandoverQuantities(
                $orderId,
                $itemsById,
                $remainingByItem,
                $handoverQuantitiesByOrderItemId
            );

            // 6. Plan reservation-level deductions, movements, and state transitions in deterministic reservation_id ASC order.
            $plannedMoves = [];
            $invDeductions = [];
            $batchDeductions = [];
            $expectedBatchInventory = [];
            $resIdsToMarkFulfilled = [];

            foreach ($requestedByItem as $itemId => $qtyToHandOver) {
                if ($qtyToHandOver <= 0) {
                    continue;
                }

                $need = $qtyToHandOver;
                $itemName = $itemsById[$itemId]['product_name'];

                foreach ($reservationsByItem[$itemId] ?? [] as $resSlice) {
                    if ($need <= 0) {
                        break;
                    }

                    $take = min($need, $resSlice['remaining']);
                    $resId = $resSlice['reservation_id'];
                    $invId = $resSlice['inventory_id'];
                    $batchId = $resSlice['batch_id'];

                    $plannedMoves[] = [
                        'reservation_id' => $resId,
                        'inventory_id' => $invId,
                        'batch_id' => $batchId,
                        'product_name' => $itemName,
                        'take' => $take,
                    ];

                    $invDeductions[$invId] = ($invDeductions[$invId] ?? 0) + $take;
                    if ($batchId !== null) {
                        $batchDeductions[$batchId] = ($batchDeductions[$batchId] ?? 0) + $take;
                        $expectedBatchInventory[$batchId] = $invId;
                    }

                    if ($resSlice['prior_fulfilled'] + $take === $resSlice['reserved_quantity']) {
                        $resIdsToMarkFulfilled[] = $resId;
                    }

                    $need -= $take;
                }

                if ($need > 0) {
                    throw new RuntimeException(
                        "Failed to allocate handover quantity for '{$itemName}' on Order #{$orderId}.",
                        self::ERR_UNPROCESSABLE
                    );
                }
            }

            // 7. Revalidate already-locked inventory and pinned inventory_batch balances (no post-reservation locks).
            foreach ($invDeductions as $invId => $deductQty) {
                if (!isset($lockedInventory[$invId])) {
                    throw new RuntimeException("Inventory record #{$invId} could not be locked.", self::ERR_UNPROCESSABLE);
                }
                $inv = $lockedInventory[$invId];
                $onHand = (int)$inv['on_hand'];
                $committed = (int)$inv['committed'];
                $inTransit = (int)$inv['in_transit'];

                if ($onHand < $deductQty) {
                    throw new RuntimeException(
                        "Insufficient on-hand stock for inventory #{$invId} (on_hand: {$onHand}, handover: {$deductQty}).",
                        self::ERR_UNPROCESSABLE
                    );
                }
                if ($committed < $deductQty || ($committed - $deductQty) < $inTransit) {
                    throw new RuntimeException(
                        "Insufficient committed stock for inventory #{$invId} (committed: {$committed}, in_transit: {$inTransit}, handover: {$deductQty}).",
                        self::ERR_UNPROCESSABLE
                    );
                }
            }

            $batchNumbersById = [];
            if ($batchDeductions) {
                foreach ($batchDeductions as $batchId => $deductQty) {
                    $batch = $lockedBatches[$batchId] ?? null;
                    if (!$batch || (int)$batch['inventory_id'] !== $expectedBatchInventory[$batchId]) {
                        throw new RuntimeException(
                            "Pinned inventory batch #{$batchId} was not found for this product inventory.",
                            self::ERR_UNPROCESSABLE
                        );
                    }
                    $batchQty = (int)$batch['quantity'];
                    if ($batchQty < $deductQty) {
                        throw new RuntimeException(
                            "Insufficient quantity in pinned batch '{$batch['batch_number']}' (available: {$batchQty}, handover: {$deductQty}).",
                            self::ERR_UNPROCESSABLE
                        );
                    }
                    $batchNumbersById[$batchId] = (string)$batch['batch_number'];
                }
            }

            // 8. Apply batch, inventory, movement, reservation, and order updates atomically.
            if ($batchDeductions) {
                $updBatchStmt = $this->db->prepare(
                    "UPDATE inventory_batch
                     SET quantity = quantity - ?, updated_at = NOW()
                     WHERE batch_id = ?"
                );
                foreach ($batchDeductions as $batchId => $deductQty) {
                    $updBatchStmt->execute([$deductQty, $batchId]);
                }
            }

            $updInvStmt = $this->db->prepare(
                "UPDATE inventory
                 SET on_hand = on_hand - ?, committed = committed - ?, updated_at = NOW()
                 WHERE inventory_id = ?"
            );
            foreach ($invDeductions as $invId => $deductQty) {
                $updInvStmt->execute([$deductQty, $deductQty, $invId]);
            }

            $movStmt = $this->db->prepare(
                "INSERT INTO inventory_movement (inventory_id, batch_id, sale_item_id, reservation_id, movement_type, quantity, notes)
                 VALUES (?, ?, NULL, ?, 'order_fulfillment', ?, ?)"
            );
            foreach ($plannedMoves as $move) {
                $batchLabel = $move['batch_id'] !== null && isset($batchNumbersById[$move['batch_id']])
                    ? " (Batch: {$batchNumbersById[$move['batch_id']]})"
                    : '';
                $notes = mb_substr("Pickup handover for Order #{$orderId}{$batchLabel}", 0, 255);
                $movStmt->execute([
                    $move['inventory_id'],
                    $move['batch_id'],
                    $move['reservation_id'],
                    -$move['take'],
                    $notes,
                ]);
            }

            if ($resIdsToMarkFulfilled) {
                $updResStmt = $this->db->prepare(
                    "UPDATE inventory_reservation
                     SET reservation_status = 'fulfilled', updated_at = NOW()
                     WHERE reservation_id = ? AND reservation_status = 'active'"
                );
                foreach ($resIdsToMarkFulfilled as $resId) {
                    $updResStmt->execute([$resId]);
                    if ($updResStmt->rowCount() !== 1) {
                        throw new RuntimeException("Reservation #{$resId} could not be finalized.", self::ERR_CONFLICT);
                    }
                }
            }

            $totalRemainingAfter = $totalRemainingBefore - $totalHandoverQty;
            $isPartial = $totalRemainingAfter > 0;
            $newOrderStatus = $isPartial ? $currentStatus : 'completed';

            if (!$isPartial) {
                $updOrdStmt = $this->db->prepare(
                    "UPDATE customer_order
                     SET order_status = 'completed', updated_at = NOW()
                     WHERE order_id = ? AND order_status = ?"
                );
                $updOrdStmt->execute([$orderId, $currentStatus]);
                if ($updOrdStmt->rowCount() !== 1) {
                    throw new RuntimeException("Order #{$orderId} could not be transitioned to completed.", self::ERR_CONFLICT);
                }

                Audit::record(
                    $this->db,
                    'petstore_receptionist.order.pickup_status',
                    "Completed pickup handover for order #{$orderId} ({$totalHandoverQty} unit(s) deducted).",
                    $businessUnitId,
                    $actorUserId,
                    null,
                    "status:{$currentStatus}",
                    'status:completed',
                    'customer_order',
                    $orderId
                );
            } else {
                Audit::record(
                    $this->db,
                    'petstore_receptionist.order.pickup_partial_handover',
                    "Recorded partial pickup handover for order #{$orderId} ({$totalHandoverQty} unit(s) handed over; {$totalRemainingAfter} unit(s) remaining).",
                    $businessUnitId,
                    $actorUserId,
                    null,
                    "status:{$currentStatus}; remaining:{$totalRemainingBefore}",
                    "status:{$newOrderStatus}; remaining:{$totalRemainingAfter}",
                    'customer_order',
                    $orderId
                );
            }

            $this->db->commit();

            return [
                'order_id' => $orderId,
                'order_status' => $newOrderStatus,
                'partial' => $isPartial,
                'movements_created' => count($plannedMoves),
                'fulfilled_quantity' => $totalHandoverQty,
                'remaining_quantity' => $totalRemainingAfter,
                'reservations_fulfilled' => count($resIdsToMarkFulfilled),
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function assertTopLevelTransaction(string $operation): void
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException("{$operation} must own a top-level database transaction.", self::ERR_CONFLICT);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function lockPickupOrder(int $orderId, int $businessUnitId): array
    {
        return $this->lockOrderForFulfillmentMethod(
            $orderId,
            $businessUnitId,
            'pickup',
            'Only pickup orders may be updated by the receptionist. Delivery workflows belong to delivery personnel.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function lockDeliveryOrder(int $orderId, int $businessUnitId): array
    {
        return $this->lockOrderForFulfillmentMethod(
            $orderId,
            $businessUnitId,
            'delivery',
            'Only delivery orders may use delivery confirmation and preparation. Pickup workflows use the pickup confirmation flow.'
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function lockOrderForFulfillmentMethod(
        int $orderId,
        int $businessUnitId,
        string $expectedMethod,
        string $forbiddenMessage
    ): array {
        $ordStmt = $this->db->prepare(
            "SELECT order_id, business_unit_id, fulfillment_method, order_status
             FROM customer_order
             WHERE order_id = ? AND business_unit_id = ?
             FOR UPDATE"
        );
        $ordStmt->execute([$orderId, $businessUnitId]);
        $order = $ordStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException("Order #{$orderId} was not found.", self::ERR_NOT_FOUND);
        }

        if ((string)$order['fulfillment_method'] !== $expectedMethod) {
            throw new RuntimeException($forbiddenMessage, self::ERR_FORBIDDEN);
        }

        return $order;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function lockOrderItems(int $orderId): array
    {
        $oiStmt = $this->db->prepare(
            "SELECT order_item_id, business_product_id, quantity
             FROM order_item
             WHERE order_id = ?
             ORDER BY business_product_id ASC, order_item_id ASC
             FOR UPDATE"
        );
        $oiStmt->execute([$orderId]);
        $orderItems = $oiStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$orderItems) {
            throw new RuntimeException("Order #{$orderId} contains no items.", self::ERR_UNPROCESSABLE);
        }

        return $orderItems;
    }

    /**
     * @param array<int,array<string,mixed>> $orderItems
     * @return array<int,array<string,mixed>>
     */
    private function resolveUnitProductMetadata(int $orderId, int $businessUnitId, array $orderItems): array
    {
        $bpIds = array_values(array_unique(array_map(static fn(array $r): int => (int)$r['business_product_id'], $orderItems)));
        sort($bpIds, SORT_NUMERIC);
        $bpPlaceholders = implode(',', array_fill(0, count($bpIds), '?'));

        $metaStmt = $this->db->prepare(
            "SELECT bp.business_product_id,
                    bp.requires_batch_tracking,
                    p.product_name,
                    i.inventory_id
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_product_id IN ({$bpPlaceholders})
               AND bp.business_unit_id = ?
             ORDER BY bp.business_product_id ASC"
        );
        $metaStmt->execute(array_merge($bpIds, [$businessUnitId]));

        $metaByBpId = [];
        foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $mRow) {
            $metaByBpId[(int)$mRow['business_product_id']] = $mRow;
        }

        foreach ($orderItems as $item) {
            $bpId = (int)$item['business_product_id'];
            $qty = (int)$item['quantity'];
            $meta = $metaByBpId[$bpId] ?? null;

            if (!$meta) {
                throw new RuntimeException(
                    "Product #{$bpId} on Order #{$orderId} does not belong to business unit #{$businessUnitId} or could not be resolved.",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($qty <= 0) {
                throw new RuntimeException(
                    "Invalid quantity ({$qty}) for '{$meta['product_name']}' on Order #{$orderId}.",
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

        return $metaByBpId;
    }

    /**
     * Lock all reservations for the order's items, verify exact non-cancelled (active + fulfilled) coverage
     * matches every order item's quantity (excluding cancelled rows), and validate every non-cancelled
     * reservation's status against its cumulative outbound order_fulfillment movements.
     *
     * @param array<int,array<string,mixed>> $orderItems
     * @return array{active:array<int,array<string,mixed>>, prior_fulfilled:array<int,int>}
     */
    private function lockActivePickupReservations(int $orderId, array $orderItems): array
    {
        $orderedQtyByItemId = [];
        $orderItemIds = [];
        foreach ($orderItems as $item) {
            $itemId = (int)$item['order_item_id'];
            $qty = (int)$item['quantity'];
            if ($qty <= 0) {
                throw new RuntimeException(
                    "Order item #{$itemId} on Order #{$orderId} has invalid ordered quantity ({$qty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
            $orderItemIds[] = $itemId;
            $orderedQtyByItemId[$itemId] = $qty;
        }

        $oiPlaceholders = implode(',', array_fill(0, count($orderItemIds), '?'));
        $resStmt = $this->db->prepare(
            "SELECT reservation_id, order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status
             FROM inventory_reservation
             WHERE order_item_id IN ({$oiPlaceholders})
             ORDER BY reservation_id ASC
             FOR UPDATE"
        );
        $resStmt->execute($orderItemIds);
        $rows = $resStmt->fetchAll(PDO::FETCH_ASSOC);

        $active = [];
        $nonCancelledRows = [];
        $nonCancelledResIds = [];
        $coveredQtyByItemId = [];

        foreach ($rows as $row) {
            $resId = (int)$row['reservation_id'];
            $itemId = (int)$row['order_item_id'];
            $reservedQty = (int)$row['reserved_quantity'];
            $status = (string)$row['reservation_status'];

            if (in_array($status, ['in_transit', 'returned_pending_inspection'], true)) {
                throw new RuntimeException(
                    "Order #{$orderId} has reservation #{$resId} in unsupported pickup state '{$status}'.",
                    self::ERR_CONFLICT
                );
            }
            if ($status === 'cancelled') {
                continue;
            }
            if (!in_array($status, ['active', 'fulfilled'], true)) {
                throw new RuntimeException(
                    "Order #{$orderId} has reservation #{$resId} in unknown state '{$status}'.",
                    self::ERR_CONFLICT
                );
            }
            if ($reservedQty <= 0) {
                throw new RuntimeException(
                    "Reservation #{$resId} on Order #{$orderId} has invalid reserved quantity ({$reservedQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }

            $coveredQtyByItemId[$itemId] = ($coveredQtyByItemId[$itemId] ?? 0) + $reservedQty;
            $nonCancelledRows[] = $row;
            $nonCancelledResIds[] = $resId;
            if ($status === 'active') {
                $active[] = $row;
            }
        }

        foreach ($orderedQtyByItemId as $itemId => $orderedQty) {
            $coveredQty = $coveredQtyByItemId[$itemId] ?? 0;
            if ($coveredQty === 0) {
                throw new RuntimeException(
                    "Order item #{$itemId} on Order #{$orderId} is missing an active or fulfilled inventory reservation.",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($coveredQty < $orderedQty) {
                throw new RuntimeException(
                    "Order item #{$itemId} on Order #{$orderId} has incomplete reservation coverage (ordered: {$orderedQty}, reserved: {$coveredQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($coveredQty > $orderedQty) {
                throw new RuntimeException(
                    "Order item #{$itemId} on Order #{$orderId} has excessive reservation coverage (ordered: {$orderedQty}, reserved: {$coveredQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
        }

        $priorFulfilledByResId = $nonCancelledResIds
            ? $this->loadOutboundFulfillmentTotals($nonCancelledResIds)
            : [];

        foreach ($nonCancelledRows as $row) {
            $resId = (int)$row['reservation_id'];
            $reservedQty = (int)$row['reserved_quantity'];
            $status = (string)$row['reservation_status'];
            $outboundQty = $priorFulfilledByResId[$resId] ?? 0;

            if ($outboundQty < 0) {
                throw new RuntimeException(
                    "Reservation #{$resId} on Order #{$orderId} has negative outbound fulfillment total ({$outboundQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($outboundQty > $reservedQty) {
                throw new RuntimeException(
                    "Reservation #{$resId} on Order #{$orderId} has outbound fulfillment movements ({$outboundQty}) exceeding reserved quantity ({$reservedQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($status === 'fulfilled' && $outboundQty !== $reservedQty) {
                throw new RuntimeException(
                    "Fulfilled reservation #{$resId} on Order #{$orderId} does not match outbound fulfillment movements ({$outboundQty} of {$reservedQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($status === 'active' && $outboundQty >= $reservedQty) {
                throw new RuntimeException(
                    "Active reservation #{$resId} on Order #{$orderId} is inconsistent with outbound fulfillment movements ({$outboundQty} of {$reservedQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
        }

        if (!$active) {
            throw new RuntimeException("Order #{$orderId} has already been fulfilled.", self::ERR_CONFLICT);
        }

        return [
            'active' => $active,
            'prior_fulfilled' => $priorFulfilledByResId,
        ];
    }

    /**
     * Lock outbound order_fulfillment movements for the supplied reservations and return cumulative outbound quantities.
     *
     * @param array<int,int> $reservationIds
     * @return array<int,int>
     */
    private function loadOutboundFulfillmentTotals(array $reservationIds): array
    {
        $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));
        $movStmt = $this->db->prepare(
            "SELECT movement_id, reservation_id, quantity
             FROM inventory_movement
             WHERE reservation_id IN ({$placeholders})
               AND movement_type = 'order_fulfillment'
             ORDER BY movement_id ASC
             FOR UPDATE"
        );
        $movStmt->execute($reservationIds);

        $totals = [];
        foreach ($movStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $resId = (int)$row['reservation_id'];
            $qty = (int)$row['quantity'];
            if ($qty >= 0) {
                throw new RuntimeException("Invalid non-negative order_fulfillment movement #{$row['movement_id']}.", self::ERR_UNPROCESSABLE);
            }
            $totals[$resId] = ($totals[$resId] ?? 0) + (-$qty);
        }

        foreach ($totals as $resId => $total) {
            if ($total < 0) {
                throw new RuntimeException("Reservation #{$resId} has negative outbound fulfillment total ({$total}).", self::ERR_UNPROCESSABLE);
            }
        }

        return $totals;
    }

    /**
     * @param array<int,array<string,mixed>> $itemsById
     * @param array<int,int> $remainingByItem
     * @param array<int,int>|null $requestedMap
     * @return array{0:array<int,int>, 1:int}
     */
    private function parseRequestedHandoverQuantities(
        int $orderId,
        array $itemsById,
        array $remainingByItem,
        ?array $requestedMap
    ): array {
        if ($requestedMap === null) {
            $total = array_sum($remainingByItem);
            return [$remainingByItem, $total];
        }

        if ($requestedMap === []) {
            throw new RuntimeException("Handover quantities must specify at least one order item for Order #{$orderId}.", self::ERR_UNPROCESSABLE);
        }

        $parsed = [];
        $total = 0;

        foreach ($requestedMap as $rawItemId => $rawQty) {
            $itemId = is_int($rawItemId) ? $rawItemId : (ctype_digit((string)$rawItemId) ? (int)$rawItemId : 0);
            if ($itemId <= 0 || !isset($itemsById[$itemId])) {
                throw new RuntimeException("Order item #{$rawItemId} does not belong to Order #{$orderId}.", self::ERR_UNPROCESSABLE);
            }

            if (is_int($rawQty)) {
                $qty = $rawQty;
            } elseif (is_string($rawQty) && ctype_digit($rawQty)) {
                $qty = (int)$rawQty;
            } else {
                throw new RuntimeException("Invalid handover quantity for item #{$itemId} on Order #{$orderId}.", self::ERR_UNPROCESSABLE);
            }

            if ($qty < 0) {
                throw new RuntimeException("Handover quantity cannot be negative for item #{$itemId}.", self::ERR_UNPROCESSABLE);
            }

            $remaining = $remainingByItem[$itemId] ?? 0;
            if ($qty > $remaining) {
                throw new RuntimeException(
                    "Requested handover quantity ({$qty}) for '{$itemsById[$itemId]['product_name']}' exceeds remaining reserved quantity ({$remaining}).",
                    self::ERR_UNPROCESSABLE
                );
            }

            $parsed[$itemId] = $qty;
            $total += $qty;
        }

        if ($total <= 0) {
            throw new RuntimeException("Total handover quantity must be greater than zero for Order #{$orderId}.", self::ERR_UNPROCESSABLE);
        }

        ksort($parsed, SORT_NUMERIC);
        return [$parsed, $total];
    }

    /**
     * Lock and validate complete active reservation coverage for a delivery order prior to dispatch.
     * Rejects missing, partial, excessive, non-active, inventory-mismatched, or movement-corrupted reservations.
     *
     * @param array<int,array<string,mixed>> $orderItems
     */
    private function lockCompleteActiveReservations(
        int $orderId,
        int $businessUnitId,
        array $orderItems,
        string $stage,
        ?array $metaByBpId = null
    ): void {
        $metaByBpId ??= $this->resolveUnitProductMetadata($orderId, $businessUnitId, $orderItems);

        $orderedQtyByItemId = [];
        $expectedMetaByItemId = [];
        $orderItemIds = [];
        foreach ($orderItems as $item) {
            $itemId = (int)$item['order_item_id'];
            $bpId = (int)$item['business_product_id'];
            $qty = (int)$item['quantity'];
            if ($qty <= 0) {
                throw new RuntimeException(
                    "Order item #{$itemId} on Order #{$orderId} has invalid ordered quantity ({$qty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
            $orderItemIds[] = $itemId;
            $orderedQtyByItemId[$itemId] = $qty;
            $expectedMetaByItemId[$itemId] = $metaByBpId[$bpId];
        }

        $oiPlaceholders = implode(',', array_fill(0, count($orderItemIds), '?'));
        $resStmt = $this->db->prepare(
            "SELECT reservation_id, order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status
             FROM inventory_reservation
             WHERE order_item_id IN ({$oiPlaceholders})
             ORDER BY reservation_id ASC
             FOR UPDATE"
        );
        $resStmt->execute($orderItemIds);
        $rows = $resStmt->fetchAll(PDO::FETCH_ASSOC);

        $activeResIds = [];
        $coveredQtyByItemId = [];

        foreach ($rows as $row) {
            $resId = (int)$row['reservation_id'];
            $itemId = (int)$row['order_item_id'];
            $reservedQty = (int)$row['reserved_quantity'];
            $status = (string)$row['reservation_status'];

            if ($status === 'cancelled') {
                continue;
            }
            if ($status !== 'active') {
                throw new RuntimeException(
                    "Order #{$orderId} has reservation #{$resId} in state '{$status}' which is invalid for delivery {$stage}.",
                    self::ERR_CONFLICT
                );
            }
            if ($reservedQty <= 0) {
                throw new RuntimeException(
                    "Reservation #{$resId} on Order #{$orderId} has invalid reserved quantity ({$reservedQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }

            $expectedMeta = $expectedMetaByItemId[$itemId];
            if ((int)$row['inventory_id'] !== (int)$expectedMeta['inventory_id']) {
                throw new RuntimeException(
                    "Reservation #{$resId} on Order #{$orderId} does not match expected inventory record.",
                    self::ERR_UNPROCESSABLE
                );
            }

            $requiresBatch = !empty($expectedMeta['requires_batch_tracking']);
            if ($requiresBatch && $row['batch_id'] === null) {
                throw new RuntimeException(
                    "Reservation #{$resId} on Order #{$orderId} is missing a FEFO batch assignment.",
                    self::ERR_UNPROCESSABLE
                );
            }
            if (!$requiresBatch && $row['batch_id'] !== null) {
                throw new RuntimeException(
                    "Reservation #{$resId} on Order #{$orderId} unexpectedly references a batch for a non-batch product.",
                    self::ERR_UNPROCESSABLE
                );
            }

            $coveredQtyByItemId[$itemId] = ($coveredQtyByItemId[$itemId] ?? 0) + $reservedQty;
            $activeResIds[] = $resId;
        }

        foreach ($orderedQtyByItemId as $itemId => $orderedQty) {
            $coveredQty = $coveredQtyByItemId[$itemId] ?? 0;
            if ($coveredQty === 0) {
                throw new RuntimeException(
                    "Order item #{$itemId} on Order #{$orderId} is missing an active inventory reservation.",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($coveredQty < $orderedQty) {
                throw new RuntimeException(
                    "Order item #{$itemId} on Order #{$orderId} has incomplete reservation coverage (ordered: {$orderedQty}, reserved: {$coveredQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
            if ($coveredQty > $orderedQty) {
                throw new RuntimeException(
                    "Order item #{$itemId} on Order #{$orderId} has excessive reservation coverage (ordered: {$orderedQty}, reserved: {$coveredQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
        }

        $outboundByResId = $activeResIds
            ? $this->loadOutboundFulfillmentTotals($activeResIds)
            : [];

        foreach ($activeResIds as $resId) {
            $outboundQty = $outboundByResId[$resId] ?? 0;
            if ($outboundQty !== 0) {
                throw new RuntimeException(
                    "Active reservation #{$resId} on Order #{$orderId} has unexpected outbound fulfillment movements ({$outboundQty}).",
                    self::ERR_UNPROCESSABLE
                );
            }
        }
    }

    /**
     * Read active unexpired temporary checkout hold quantities grouped by inventory_id.
     * Non-locking read intended for use under READ COMMITTED after parent inventory FOR UPDATE locks are held.
     *
     * @param array<int,int> $inventoryIds
     * @return array<int,int> Map of inventory_id => active held quantity
     */
    public static function activeHeldQuantitiesByInventory(PDO $db, array $inventoryIds, ?int $excludeHoldId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inventoryIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT ihi.inventory_id, COALESCE(SUM(ihi.quantity), 0) AS held_qty
                FROM inventory_hold_item ihi
                JOIN inventory_hold ih ON ih.hold_id = ihi.hold_id
                WHERE ihi.inventory_id IN ({$placeholders})
                  AND ih.hold_status = 'active'
                  AND ih.expires_at > UTC_TIMESTAMP()";
        $params = $ids;
        if ($excludeHoldId !== null && $excludeHoldId > 0) {
            $sql .= " AND ih.hold_id <> ?";
            $params[] = $excludeHoldId;
        }
        $sql .= " GROUP BY ihi.inventory_id";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $heldByInv = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $heldByInv[(int)$row['inventory_id']] = (int)$row['held_qty'];
        }
        return $heldByInv;
    }

    /**
     * Shared FEFO reservation allocator and inventory.committed updater for an order whose
     * inventory rows are already locked FOR UPDATE in ascending inventory_id order inside an active transaction.
     *
     * Lock order maintained here:
     *   inventory (already locked in inventory_id ASC)
     *   -> inventory_batch (locked across all batch-tracked inventories in batch_id ASC)
     *   -> inventory_reservation (locked in reservation_id ASC)
     *
     * @param array<int,int> $sortedInvIds
     * @param array<int,array<int,array{order_item_id:int,business_product_id:int,quantity:int,requires_batch_tracking:bool,product_name:string}>> $itemsByInventory
     * @param array<int,array<string,mixed>> $lockedInventory
     * @param array<int,int> $activeHeldByInventory
     * @param array<int,int> $rejectExistingForOrderItemIds Optional order_item_id list to guard against duplicate non-cancelled reservations after batch locks
     * @return array{reservations_created:int, total_reserved_quantity:int}
     */
    public function reserveLockedOrderItems(
        int $orderId,
        array $sortedInvIds,
        array $itemsByInventory,
        array $lockedInventory,
        array $activeHeldByInventory = [],
        array $rejectExistingForOrderItemIds = []
    ): array {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Reservation allocation must run inside an active transaction.', self::ERR_CONFLICT);
        }

        // Lock all eligible batches across all batch-tracked inventories in strict batch_id ASC order
        // before locking/reading any inventory_reservation rows.
        $batchInvIds = [];
        foreach ($sortedInvIds as $invId) {
            $invItems = $itemsByInventory[$invId] ?? [];
            if ($invItems && !empty($invItems[0]['requires_batch_tracking'])) {
                $batchInvIds[] = $invId;
            }
        }
        $batchPoolsByInv = $batchInvIds
            ? InventoryBatch::lockEligibleFefoBatchesForInventories($this->db, $batchInvIds, true)
            : [];

        if ($rejectExistingForOrderItemIds) {
            $oiIds = array_values(array_unique(array_map('intval', $rejectExistingForOrderItemIds)));
            sort($oiIds, SORT_NUMERIC);
            $oiPlaceholders = implode(',', array_fill(0, count($oiIds), '?'));
            $dupResStmt = $this->db->prepare(
                "SELECT reservation_id
                 FROM inventory_reservation
                 WHERE order_item_id IN ({$oiPlaceholders})
                   AND reservation_status <> 'cancelled'
                 ORDER BY reservation_id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $dupResStmt->execute($oiIds);
            if ($dupResStmt->fetchColumn() !== false) {
                throw new RuntimeException(
                    "Order #{$orderId} already has active or prior inventory reservations and cannot be confirmed again.",
                    self::ERR_CONFLICT
                );
            }
        }

        $plannedReservations = [];
        $committedDeltaByInventory = [];
        $totalReservedQty = 0;

        foreach ($sortedInvIds as $invId) {
            if (!isset($lockedInventory[$invId])) {
                throw new RuntimeException("Inventory record #{$invId} could not be locked.", self::ERR_UNPROCESSABLE);
            }

            $inv = $lockedInventory[$invId];
            $onHand = (int)$inv['on_hand'];
            $committed = (int)$inv['committed'];
            $inTransit = (int)$inv['in_transit'];
            $activeHeld = (int)($activeHeldByInventory[$invId] ?? 0);
            $available = (($onHand + $inTransit) - $committed) - $activeHeld;

            $invItems = $itemsByInventory[$invId];
            $productName = $invItems[0]['product_name'];
            $requiresBatch = $invItems[0]['requires_batch_tracking'];

            $totalRequestedForInv = 0;
            foreach ($invItems as $invItem) {
                $totalRequestedForInv += $invItem['quantity'];
            }

            if ($available < $totalRequestedForInv) {
                $displayAvail = max(0, $available);
                throw new RuntimeException(
                    "Insufficient available stock for '{$productName}'. Available: {$displayAvail}, requested: {$totalRequestedForInv}.",
                    self::ERR_UNPROCESSABLE
                );
            }

            if ($requiresBatch) {
                $batchPool = $batchPoolsByInv[$invId] ?? [];
                $totalUnreservedBatchQty = 0;
                foreach ($batchPool as $batch) {
                    $totalUnreservedBatchQty += $batch['unreserved_quantity'];
                }
                $effectiveBatchAvail = $totalUnreservedBatchQty - $activeHeld;

                if ($effectiveBatchAvail < $totalRequestedForInv) {
                    $displayBatchAvail = max(0, $effectiveBatchAvail);
                    throw new RuntimeException(
                        "Insufficient eligible non-expired batch stock for '{$productName}'. Available: {$displayBatchAvail}, requested: {$totalRequestedForInv}.",
                        self::ERR_UNPROCESSABLE
                    );
                }

                foreach ($invItems as $invItem) {
                    $remaining = $invItem['quantity'];
                    foreach ($batchPool as &$batch) {
                        if ($remaining <= 0) {
                            break;
                        }
                        if ($batch['unreserved_quantity'] <= 0) {
                            continue;
                        }

                        $take = min($remaining, $batch['unreserved_quantity']);
                        $plannedReservations[] = [
                            'order_item_id' => $invItem['order_item_id'],
                            'inventory_id' => $invId,
                            'batch_id' => $batch['batch_id'],
                            'reserved_quantity' => $take,
                        ];
                        $batch['unreserved_quantity'] -= $take;
                        $remaining -= $take;
                    }
                    unset($batch);

                    if ($remaining > 0) {
                        throw new RuntimeException(
                            "Failed to allocate full FEFO quantity for '{$productName}' on Order #{$orderId}.",
                            self::ERR_UNPROCESSABLE
                        );
                    }
                }
            } else {
                foreach ($invItems as $invItem) {
                    $plannedReservations[] = [
                        'order_item_id' => $invItem['order_item_id'],
                        'inventory_id' => $invId,
                        'batch_id' => null,
                        'reserved_quantity' => $invItem['quantity'],
                    ];
                }
            }

            $committedDeltaByInventory[$invId] = $totalRequestedForInv;
            $totalReservedQty += $totalRequestedForInv;
        }

        $insResStmt = $this->db->prepare(
            "INSERT INTO inventory_reservation (order_item_id, inventory_id, batch_id, reserved_quantity, reservation_status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        foreach ($plannedReservations as $res) {
            $insResStmt->execute([
                $res['order_item_id'],
                $res['inventory_id'],
                $res['batch_id'],
                $res['reserved_quantity'],
            ]);
        }

        $updInvStmt = $this->db->prepare(
            "UPDATE inventory
             SET committed = committed + ?, updated_at = NOW()
             WHERE inventory_id = ?"
        );
        foreach ($committedDeltaByInventory as $invId => $delta) {
            $updInvStmt->execute([$delta, $invId]);
        }

        return [
            'reservations_created' => count($plannedReservations),
            'total_reserved_quantity' => $totalReservedQty,
        ];
    }

    /**
     * Lock all distinct inventory rows referenced by $orderItems in ascending inventory_id order.
     *
     * @param array<int,array<string,mixed>> $orderItems
     * @param array<int,array<string,mixed>> $metaByBpId
     * @return array<int,array<string,mixed>>
     */
    private function lockOrderItemInventories(array $orderItems, array $metaByBpId): array
    {
        $invIds = [];
        foreach ($orderItems as $item) {
            $bpId = (int)$item['business_product_id'];
            $invId = (int)$metaByBpId[$bpId]['inventory_id'];
            $invIds[$invId] = $invId;
        }
        $sortedInvIds = array_values($invIds);
        sort($sortedInvIds, SORT_NUMERIC);
        return $sortedInvIds ? $this->lockInventories($sortedInvIds) : [];
    }

    /**
     * Lock all inventory_batch rows belonging to $inventoryIds in ascending batch_id order.
     *
     * @param array<int,int> $inventoryIds
     * @return array<int,array<string,mixed>> Map of batch_id => row
     */
    private function lockInventoryBatchesByInventories(array $inventoryIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $inventoryIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }
        sort($ids, SORT_NUMERIC);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT batch_id, inventory_id, batch_number, quantity
             FROM inventory_batch
             WHERE inventory_id IN ({$placeholders})
             ORDER BY batch_id ASC
             FOR UPDATE"
        );
        $stmt->execute($ids);

        $locked = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locked[(int)$row['batch_id']] = $row;
        }
        return $locked;
    }

    /**
     * @param array<int,int> $sortedInvIds
     * @return array<int,array<string,mixed>>
     */
    private function lockInventories(array $sortedInvIds): array
    {
        $invPlaceholders = implode(',', array_fill(0, count($sortedInvIds), '?'));
        $invLockStmt = $this->db->prepare(
            "SELECT inventory_id, on_hand, committed, COALESCE(in_transit, 0) AS in_transit
             FROM inventory
             WHERE inventory_id IN ({$invPlaceholders})
             ORDER BY inventory_id ASC
             FOR UPDATE"
        );
        $invLockStmt->execute($sortedInvIds);

        $locked = [];
        foreach ($invLockStmt->fetchAll(PDO::FETCH_ASSOC) as $invRow) {
            $locked[(int)$invRow['inventory_id']] = $invRow;
        }

        return $locked;
    }

    /**
     * @param array<int,int> $sortedBatchIds
     * @return array<int,array<string,mixed>>
     */
    private function lockInventoryBatches(array $sortedBatchIds): array
    {
        $placeholders = implode(',', array_fill(0, count($sortedBatchIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT batch_id, inventory_id, batch_number, quantity
             FROM inventory_batch
             WHERE batch_id IN ({$placeholders})
             ORDER BY batch_id ASC
             FOR UPDATE"
        );
        $stmt->execute($sortedBatchIds);

        $locked = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $locked[(int)$row['batch_id']] = $row;
        }

        return $locked;
    }
}
