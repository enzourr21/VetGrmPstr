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
        int $actorUserId,
        ?int $actorEmployeeId = null
    ): array {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Order confirmation must own a top-level database transaction.', self::ERR_CONFLICT);
        }

        $this->db->beginTransaction();
        try {
            // 1. Lock the target order exclusively and verify scope, fulfillment method, and state.
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

            if ((string)$order['fulfillment_method'] !== 'pickup') {
                throw new RuntimeException(
                    'Only pickup orders may be updated by the receptionist. Delivery workflows belong to delivery personnel.',
                    self::ERR_FORBIDDEN
                );
            }

            $currentStatus = (string)$order['order_status'];
            if ($currentStatus !== 'pending') {
                throw new RuntimeException(
                    "Invalid transition for pickup order from '{$currentStatus}' to 'confirmed'. Permitted flow: Pending → Confirmed → Ready for Pickup → Completed.",
                    self::ERR_CONFLICT
                );
            }

            // 2. Lock the order's line items in deterministic order.
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
                throw new RuntimeException("Order #{$orderId} contains no items to reserve.", self::ERR_UNPROCESSABLE);
            }

            // 3. Reject any order that already has non-cancelled reservations (locking read).
            $oiIds = array_map(static fn(array $r): int => (int)$r['order_item_id'], $orderItems);
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

            // 4. Resolve product metadata and distinct inventory_id values without acquiring out-of-order inventory locks.
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
                 ORDER BY bp.business_product_id ASC"
            );
            $metaStmt->execute($bpIds);

            $metaByBpId = [];
            foreach ($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $mRow) {
                $metaByBpId[(int)$mRow['business_product_id']] = $mRow;
            }

            $itemsByInventory = [];
            $inventoryIds = [];
            foreach ($orderItems as $item) {
                $bpId = (int)$item['business_product_id'];
                $qty = (int)$item['quantity'];
                $meta = $metaByBpId[$bpId] ?? null;

                if (!$meta) {
                    throw new RuntimeException("Product #{$bpId} on Order #{$orderId} could not be resolved.", self::ERR_UNPROCESSABLE);
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

                $invId = (int)$meta['inventory_id'];
                $inventoryIds[$invId] = $invId;
                $itemsByInventory[$invId][] = [
                    'order_item_id' => (int)$item['order_item_id'],
                    'business_product_id' => $bpId,
                    'quantity' => $qty,
                    'requires_batch_tracking' => !empty($meta['requires_batch_tracking']),
                    'product_name' => (string)$meta['product_name'],
                ];
            }

            // 5. Lock distinct inventory rows in deterministic inventory_id ASC order.
            $sortedInvIds = array_values($inventoryIds);
            sort($sortedInvIds, SORT_NUMERIC);

            $invPlaceholders = implode(',', array_fill(0, count($sortedInvIds), '?'));
            $invLockStmt = $this->db->prepare(
                "SELECT inventory_id, on_hand, committed, COALESCE(in_transit, 0) AS in_transit
                 FROM inventory
                 WHERE inventory_id IN ({$invPlaceholders})
                 ORDER BY inventory_id ASC
                 FOR UPDATE"
            );
            $invLockStmt->execute($sortedInvIds);

            $lockedInventory = [];
            foreach ($invLockStmt->fetchAll(PDO::FETCH_ASSOC) as $invRow) {
                $lockedInventory[(int)$invRow['inventory_id']] = $invRow;
            }

            // 6. Revalidate aggregate availability and allocate FEFO batches without partial reservations.
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
                $available = ($onHand + $inTransit) - $committed;

                $invItems = $itemsByInventory[$invId];
                $productName = $invItems[0]['product_name'];
                $requiresBatch = $invItems[0]['requires_batch_tracking'];

                $totalRequestedForInv = 0;
                foreach ($invItems as $invItem) {
                    $totalRequestedForInv += $invItem['quantity'];
                }

                if ($available < $totalRequestedForInv) {
                    throw new RuntimeException(
                        "Insufficient available stock for '{$productName}'. Available: {$available}, requested: {$totalRequestedForInv}.",
                        self::ERR_UNPROCESSABLE
                    );
                }

                if ($requiresBatch) {
                    $batchPool = InventoryBatch::lockEligibleFefoBatchesForReservation($this->db, $invId);
                    $totalUnreservedBatchQty = 0;
                    foreach ($batchPool as $batch) {
                        $totalUnreservedBatchQty += $batch['unreserved_quantity'];
                    }

                    if ($totalUnreservedBatchQty < $totalRequestedForInv) {
                        throw new RuntimeException(
                            "Insufficient eligible non-expired batch stock for '{$productName}'. Available: {$totalUnreservedBatchQty}, requested: {$totalRequestedForInv}.",
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

            // 7. Persist active reservations, increment inventory.committed, update order_status, and log Audit::record().
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

            $updOrdStmt = $this->db->prepare(
                "UPDATE customer_order
                 SET order_status = 'confirmed', updated_at = NOW()
                 WHERE order_id = ? AND order_status = 'pending'"
            );
            $updOrdStmt->execute([$orderId]);
            if ($updOrdStmt->rowCount() !== 1) {
                throw new RuntimeException("Order #{$orderId} could not be transitioned to confirmed.", self::ERR_CONFLICT);
            }

            Audit::record(
                $this->db,
                'petstore_receptionist.order.pickup_status',
                "Confirmed pickup order #{$orderId} and reserved {$totalReservedQty} unit(s).",
                $businessUnitId,
                $actorUserId,
                ($actorEmployeeId !== null && $actorEmployeeId > 0) ? $actorEmployeeId : null,
                'status:pending',
                'status:confirmed',
                'customer_order',
                $orderId
            );

            $this->db->commit();

            return [
                'order_id' => $orderId,
                'reservations_created' => count($plannedReservations),
                'total_reserved_quantity' => $totalReservedQty,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
