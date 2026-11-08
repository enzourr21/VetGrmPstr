<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

class InventoryBatch
{
    /**
     * Get all batches for a specific product, ordered by FEFO (earliest expiry first).
     */
    public static function getBatchesForProduct(PDO $db, int $businessProductId): array
    {
        $stmt = $db->prepare(
            "SELECT ib.*, i.inventory_id, i.business_product_id
             FROM inventory_batch ib
             JOIN inventory i ON i.inventory_id = ib.inventory_id
             WHERE i.business_product_id = ?
             ORDER BY
               (CASE WHEN ib.expiration_date IS NULL THEN 1 ELSE 0 END) ASC,
               ib.expiration_date ASC,
               ib.batch_id ASC"
        );
        $stmt->execute([$businessProductId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = date('Y-m-d');
        foreach ($rows as &$b) {
            $exp = $b['expiration_date'] ? (string)$b['expiration_date'] : null;
            $b['is_expired'] = ($exp !== null && $exp < $today);
            $b['is_expiring_soon'] = (!$b['is_expired'] && $exp !== null && (strtotime($exp) - strtotime($today) <= 30 * 86400));
            $b['status_label'] = $b['is_expired'] ? 'Expired' : ($b['is_expiring_soon'] ? 'Expiring Soon' : 'Active');
            $b['badge_class'] = $b['is_expired'] ? 'badge-danger' : ($b['is_expiring_soon'] ? 'badge-warning' : 'secondary');
        }
        unset($b);

        return $rows;
    }

    /**
     * Calculate stock metrics distinguishing physical stock from usable non-expired available stock.
     */
    public static function calculateStockMetrics(PDO $db, int $businessProductId): array
    {
        $stmt = $db->prepare(
            "SELECT i.inventory_id, i.on_hand, i.committed, bp.requires_batch_tracking
             FROM inventory i
             JOIN business_product bp ON bp.business_product_id = i.business_product_id
             WHERE i.business_product_id = ?"
        );
        $stmt->execute([$businessProductId]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return [
                'inventory_id' => 0,
                'on_hand' => 0,
                'committed' => 0,
                'expired_quantity' => 0,
                'usable_on_hand' => 0,
                'available' => 0,
                'batches' => [],
            ];
        }

        $onHand = (int)$inv['on_hand'];
        $committed = (int)$inv['committed'];
        $requiresBatch = !empty($inv['requires_batch_tracking']);
        $batches = $requiresBatch ? self::getBatchesForProduct($db, $businessProductId) : [];

        $expiredQty = 0;
        foreach ($batches as $b) {
            if (!empty($b['is_expired'])) {
                $expiredQty += (int)$b['quantity'];
            }
        }

        $usableOnHand = max(0, $onHand - $expiredQty);
        $available = max(0, $usableOnHand - $committed);

        return [
            'inventory_id' => (int)$inv['inventory_id'],
            'on_hand' => $onHand,
            'committed' => $committed,
            'expired_quantity' => $expiredQty,
            'usable_on_hand' => $usableOnHand,
            'available' => $available,
            'batches' => $batches,
            'requires_batch_tracking' => $requiresBatch,
        ];
    }

    /**
     * Perform FEFO (First Expired, First Out) stock allocation across eligible non-expired batches.
     * Expired batches are strictly excluded from allocation.
     *
     * @throws RuntimeException if stock is insufficient or operation fails
     */
    public static function allocateFefo(
        PDO $db,
        int $businessProductId,
        int $quantityRequested,
        string $movementType = 'fefo_allocation',
        ?int $employeeId = null,
        ?string $reference = null
    ): array {
        if ($quantityRequested <= 0) {
            throw new RuntimeException("Quantity requested must be greater than zero.");
        }

        // Lock inventory record
        $invStmt = $db->prepare(
            "SELECT i.inventory_id, i.on_hand, i.committed, bp.requires_batch_tracking, p.product_name
             FROM inventory i
             JOIN business_product bp ON bp.business_product_id = i.business_product_id
             JOIN product p ON p.product_id = bp.product_id
             WHERE i.business_product_id = ?
             FOR UPDATE"
        );
        $invStmt->execute([$businessProductId]);
        $inv = $invStmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            throw new RuntimeException("Inventory record not found for product ID $businessProductId.");
        }

        $invId = (int)$inv['inventory_id'];
        $requiresBatch = !empty($inv['requires_batch_tracking']);

        // Non-batch tracked product aggregate allocation
        if (!$requiresBatch) {
            $currentOnHand = (int)$inv['on_hand'];
            if ($quantityRequested > $currentOnHand) {
                throw new RuntimeException("Insufficient stock for product '{$inv['product_name']}'. Requested: $quantityRequested, On Hand: $currentOnHand.");
            }

            $db->prepare("UPDATE inventory SET on_hand = on_hand - ? WHERE inventory_id = ?")
               ->execute([$quantityRequested, $invId]);

            $notes = "FEFO allocation: $quantityRequested units" . ($reference ? " (Ref: $reference)" : '') . ($employeeId ? " by Employee #$employeeId" : '');
            $movStmt = $db->prepare(
                "INSERT INTO inventory_movement (inventory_id, batch_id, movement_type, quantity, notes)
                 VALUES (?, NULL, ?, ?, ?)"
            );
            $movStmt->execute([$invId, $movementType, -$quantityRequested, $notes]);

            return [
                'product_id' => $businessProductId,
                'product_name' => $inv['product_name'],
                'allocated_quantity' => $quantityRequested,
                'allocations' => [
                    ['batch_id' => null, 'batch_number' => null, 'quantity' => $quantityRequested, 'expiration_date' => null]
                ],
            ];
        }

        // Batch-tracked product: Lock only eligible NON-EXPIRED batches with positive quantity
        $batchStmt = $db->prepare(
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
        $batchStmt->execute([$invId]);
        $eligibleBatches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalEligible = array_sum(array_column($eligibleBatches, 'quantity'));
        if ($totalEligible < $quantityRequested) {
            throw new RuntimeException(
                "Insufficient eligible non-expired stock for product '{$inv['product_name']}'. Requested: $quantityRequested, Eligible available: $totalEligible."
            );
        }

        $remainingToAllocate = $quantityRequested;
        $allocations = [];

        foreach ($eligibleBatches as $batch) {
            if ($remainingToAllocate <= 0) {
                break;
            }

            $bId = (int)$batch['batch_id'];
            $bQty = (int)$batch['quantity'];
            $deductQty = min($remainingToAllocate, $bQty);

            // Deduct from batch
            $db->prepare("UPDATE inventory_batch SET quantity = quantity - ?, updated_at = NOW() WHERE batch_id = ?")
               ->execute([$deductQty, $bId]);

            // Record movement
            $notes = "FEFO allocation: $deductQty units from Batch {$batch['batch_number']}" . ($reference ? " (Ref: $reference)" : '') . ($employeeId ? " by Employee #$employeeId" : '');
            $movStmt = $db->prepare(
                "INSERT INTO inventory_movement (inventory_id, batch_id, movement_type, quantity, notes)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $movStmt->execute([$invId, $bId, $movementType, -$deductQty, $notes]);

            $allocations[] = [
                'batch_id' => $bId,
                'batch_number' => $batch['batch_number'],
                'quantity' => $deductQty,
                'expiration_date' => $batch['expiration_date'],
            ];

            $remainingToAllocate -= $deductQty;
        }

        // Deduct aggregate inventory
        $db->prepare("UPDATE inventory SET on_hand = on_hand - ? WHERE inventory_id = ?")
           ->execute([$quantityRequested, $invId]);

        return [
            'product_id' => $businessProductId,
            'product_name' => $inv['product_name'],
            'allocated_quantity' => $quantityRequested,
            'allocations' => $allocations,
        ];
    }

    /**
     * Get real-time summary statistics for a business unit's inventory overview.
     */
    public static function getSummaryForUnit(PDO $db, int $businessUnitId): array
    {
        // 1. Total products & total on-hand
        $prodStmt = $db->prepare(
            "SELECT bp.business_product_id, bp.requires_batch_tracking,
                    COALESCE(i.inventory_id, 0) AS inventory_id,
                    COALESCE(i.on_hand, 0) AS on_hand,
                    COALESCE(i.committed, 0) AS committed
             FROM business_product bp
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_unit_id = ? AND bp.product_status = 'active'"
        );
        $prodStmt->execute([$businessUnitId]);
        $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalProducts = count($products);
        $totalPhysicalStock = 0;
        $lowStockCount = 0;
        $outOfStockCount = 0;

        $invIds = array_filter(array_map('intval', array_column($products, 'inventory_id')));
        $batchesByInv = [];
        if (!empty($invIds)) {
            $inClause = implode(',', $invIds);
            $bStmt = $db->query("SELECT * FROM inventory_batch WHERE inventory_id IN ($inClause)");
            foreach ($bStmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
                $batchesByInv[(int)$b['inventory_id']][] = $b;
            }
        }

        $today = date('Y-m-d');
        $in30Days = date('Y-m-d', strtotime('+30 days'));
        $expiringSoonCount = 0;
        $expiredCount = 0;

        foreach ($products as $p) {
            $onHand = (int)$p['on_hand'];
            $committed = (int)$p['committed'];
            $totalPhysicalStock += $onHand;

            $invId = (int)$p['inventory_id'];
            $pBatches = $batchesByInv[$invId] ?? [];
            $pExpiredQty = 0;

            foreach ($pBatches as $b) {
                $bQty = (int)$b['quantity'];
                if ($bQty <= 0) continue;

                $exp = $b['expiration_date'] ? (string)$b['expiration_date'] : null;
                if ($exp !== null) {
                    if ($exp < $today) {
                        $expiredCount++;
                        $pExpiredQty += $bQty;
                    } elseif ($exp <= $in30Days) {
                        $expiringSoonCount++;
                    }
                }
            }

            $usableOnHand = max(0, $onHand - $pExpiredQty);
            $available = max(0, $usableOnHand - $committed);

            if ($available <= 0) {
                $outOfStockCount++;
            } elseif ($available <= 10) {
                $lowStockCount++;
            }
        }

        // Recent inventory movements (latest 5)
        $movStmt = $db->prepare(
            "SELECT im.*, p.product_name, p.sku, ib.batch_number
             FROM inventory_movement im
             JOIN inventory i ON i.inventory_id = im.inventory_id
             JOIN business_product bp ON bp.business_product_id = i.business_product_id
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory_batch ib ON ib.batch_id = im.batch_id
             WHERE bp.business_unit_id = ?
             ORDER BY im.movement_date DESC, im.movement_id DESC
             LIMIT 5"
        );
        $movStmt->execute([$businessUnitId]);
        $recentMovements = $movStmt->fetchAll(PDO::FETCH_ASSOC);

        // Total movements count
        $cntStmt = $db->prepare(
            "SELECT COUNT(*)
             FROM inventory_movement im
             JOIN inventory i ON i.inventory_id = im.inventory_id
             JOIN business_product bp ON bp.business_product_id = i.business_product_id
             WHERE bp.business_unit_id = ?"
        );
        $cntStmt->execute([$businessUnitId]);
        $totalMovements = (int)$cntStmt->fetchColumn();

        return [
            'total_products' => $totalProducts,
            'total_physical_stock' => $totalPhysicalStock,
            'low_stock_count' => $lowStockCount,
            'out_of_stock_count' => $outOfStockCount,
            'expiring_soon_count' => $expiringSoonCount,
            'expired_count' => $expiredCount,
            'recent_movements' => $recentMovements,
            'total_movements' => $totalMovements,
        ];
    }
}

