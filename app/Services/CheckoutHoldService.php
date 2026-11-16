<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Audit;
use App\Models\Cart;
use App\Models\InventoryBatch;
use App\Models\Units;
use App\Services\Payment\OnlinePaymentGatewayInterface;
use App\Services\Payment\UnconfiguredOnlinePaymentGateway;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Coordinates E2 checkout inventory validation, 3-minute online temporary holds,
 * immediate COD confirmation/reservation, and provider-neutral verified capture
 * conversion / delayed-capture reconciliation.
 */
final class CheckoutHoldService
{
    public const MIN_ONLINE_STOCK_THRESHOLD = 3;
    public const HOLD_DURATION_SECONDS = 180;

    public const ERR_FORBIDDEN = 403;
    public const ERR_NOT_FOUND = 404;
    public const ERR_CONFLICT = 409;
    public const ERR_UNPROCESSABLE = 422;

    private PDO $db;
    private OrderReservationService $reservationService;

    public function __construct(?PDO $db = null, ?OrderReservationService $reservationService = null)
    {
        $this->db = $db ?? Database::pdo();
        $this->reservationService = $reservationService ?? new OrderReservationService($this->db);
    }

    /**
     * Reusable SQL expression computing effective customer-available stock for an inventory row,
     * excluding expired batches (for batch-tracked items), committed reservations, and active
     * unexpired temporary checkout holds.
     */
    public static function availableStockSql(string $invAlias = 'i', string $bpAlias = 'bp'): string
    {
        return "GREATEST(0, (COALESCE({$invAlias}.on_hand, 0)"
            . " - CASE WHEN {$bpAlias}.requires_batch_tracking = 1 THEN COALESCE(("
            . "SELECT SUM(ib_exp.quantity) FROM inventory_batch ib_exp"
            . " WHERE ib_exp.inventory_id = {$invAlias}.inventory_id"
            . " AND ib_exp.quantity > 0"
            . " AND ib_exp.expiration_date IS NOT NULL"
            . " AND ib_exp.expiration_date < CURDATE()"
            . "), 0) ELSE 0 END"
            . " + COALESCE({$invAlias}.in_transit, 0)"
            . " - COALESCE({$invAlias}.committed, 0)"
            . " - COALESCE(("
            . "SELECT SUM(ihi.quantity) FROM inventory_hold_item ihi"
            . " JOIN inventory_hold ih ON ih.hold_id = ihi.hold_id"
            . " WHERE ihi.inventory_id = {$invAlias}.inventory_id"
            . " AND ih.hold_status = 'active'"
            . " AND ih.expires_at > UTC_TIMESTAMP()"
            . "), 0)))";
    }

    /**
     * Read business-unit checkout payment configuration from business_settings.
     * Defaults cod_enabled to true when no settings row exists yet (matching schema default 1).
     *
     * @return array{cod_enabled:bool, online_enabled:bool}
     */
    public function getUnitPaymentSettings(int $businessUnitId): array
    {
        $stmt = $this->db->prepare(
            'SELECT cod_enabled FROM business_settings WHERE business_unit_id = ? LIMIT 1'
        );
        $stmt->execute([$businessUnitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'cod_enabled' => $row ? !empty($row['cod_enabled']) : true,
            'online_enabled' => true,
        ];
    }

    /**
     * Compute effective availability for a set of business_product_ids, subtracting active
     * unexpired temporary holds and expired batches (for batch-tracked items).
     *
     * @param array<int,int> $bpIds
     * @return array<int,int> Map of business_product_id => effective available quantity
     */
    public function getEffectiveAvailabilityForProducts(array $bpIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $bpIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT bp.business_product_id,
                    bp.requires_batch_tracking,
                    i.inventory_id,
                    COALESCE(i.on_hand, 0) AS on_hand,
                    COALESCE(i.committed, 0) AS committed,
                    COALESCE(i.in_transit, 0) AS in_transit,
                    COALESCE((
                        SELECT SUM(ihi.quantity)
                        FROM inventory_hold_item ihi
                        JOIN inventory_hold ih ON ih.hold_id = ihi.hold_id
                        WHERE ihi.inventory_id = i.inventory_id
                          AND ih.hold_status = 'active'
                          AND ih.expires_at > UTC_TIMESTAMP()
                    ), 0) AS held_qty
             FROM business_product bp
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_product_id IN ({$placeholders})"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $batchInvIds = [];
        foreach ($rows as $r) {
            if (!empty($r['requires_batch_tracking']) && $r['inventory_id'] !== null) {
                $batchInvIds[] = (int)$r['inventory_id'];
            }
        }

        $expiredByInv = [];
        if ($batchInvIds) {
            $bPh = implode(',', array_fill(0, count($batchInvIds), '?'));
            $expStmt = $this->db->prepare(
                "SELECT inventory_id, COALESCE(SUM(quantity), 0) AS expired_qty
                 FROM inventory_batch
                 WHERE inventory_id IN ({$bPh})
                   AND quantity > 0
                   AND expiration_date IS NOT NULL
                   AND expiration_date < CURDATE()
                 GROUP BY inventory_id"
            );
            $expStmt->execute($batchInvIds);
            foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
                $expiredByInv[(int)$er['inventory_id']] = (int)$er['expired_qty'];
            }
        }

        $availByBp = [];
        foreach ($rows as $r) {
            $bpId = (int)$r['business_product_id'];
            if ($r['inventory_id'] === null) {
                $availByBp[$bpId] = 0;
                continue;
            }
            $invId = (int)$r['inventory_id'];
            $onHand = (int)$r['on_hand'];
            $committed = (int)$r['committed'];
            $inTransit = (int)$r['in_transit'];
            $heldQty = (int)$r['held_qty'];
            $expiredQty = !empty($r['requires_batch_tracking']) ? ($expiredByInv[$invId] ?? 0) : 0;
            $usableOnHand = max(0, $onHand - $expiredQty);
            $avail = (($usableOnHand + $inTransit) - $committed) - $heldQty;
            $availByBp[$bpId] = max(0, $avail);
        }

        return $availByBp;
    }

    /**
     * Fetch the customer's latest active unexpired online checkout hold for a business unit, if any.
     *
     * @return array<string,mixed>|null
     */
    public function getActiveHoldForCustomerUnit(int $customerId, int $businessUnitId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ih.hold_id, ih.attempt_id, ih.created_at, ih.expires_at,
                    ca.attempt_token, ca.expected_amount, ca.fulfillment_method, ca.provider_checkout_url,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), ih.expires_at) AS seconds_remaining
             FROM inventory_hold ih
             JOIN checkout_attempt ca ON ca.attempt_id = ih.attempt_id
             WHERE ih.customer_id = ?
               AND ih.business_unit_id = ?
               AND ih.hold_status = 'active'
               AND ih.expires_at > UTC_TIMESTAMP()
               AND ca.attempt_status = 'awaiting_payment'
             ORDER BY ih.hold_id DESC
             LIMIT 1"
        );
        $stmt->execute([$customerId, $businessUnitId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Fetch a customer-owned online checkout attempt and its hold/items for session status rendering.
     *
     * @return array<string,mixed>|null
     */
    public function getAttemptDetailsForCustomer(int $attemptId, int $customerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT ca.*,
                    bu.business_unit_name,
                    ih.hold_id,
                    ih.hold_status,
                    ih.created_at AS hold_created_at,
                    ih.expires_at AS hold_expires_at,
                    ih.converted_at AS hold_converted_at,
                    ih.released_at AS hold_released_at,
                    ih.release_reason,
                    (ih.hold_status = 'active' AND ih.expires_at > UTC_TIMESTAMP()) AS is_hold_currently_active,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), ih.expires_at)) AS seconds_remaining
             FROM checkout_attempt ca
             JOIN business_unit bu ON bu.business_unit_id = ca.business_unit_id
             LEFT JOIN inventory_hold ih ON ih.attempt_id = ca.attempt_id
             WHERE ca.attempt_id = ? AND ca.customer_id = ?
             LIMIT 1"
        );
        $stmt->execute([$attemptId, $customerId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            return null;
        }

        $items = [];
        if ($attempt['hold_id'] !== null) {
            $itStmt = $this->db->prepare(
                "SELECT ihi.hold_item_id, ihi.business_product_id, ihi.quantity, ihi.unit_price, p.product_name
                 FROM inventory_hold_item ihi
                 JOIN business_product bp ON bp.business_product_id = ihi.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE ihi.hold_id = ?
                 ORDER BY ihi.hold_item_id ASC"
            );
            $itStmt->execute([(int)$attempt['hold_id']]);
            $items = $itStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($attempt['order_id'] !== null) {
            $itStmt = $this->db->prepare(
                "SELECT oi.order_item_id, oi.business_product_id, oi.quantity, oi.unit_price, p.product_name
                 FROM order_item oi
                 JOIN business_product bp ON bp.business_product_id = oi.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE oi.order_id = ?
                 ORDER BY oi.order_item_id ASC"
            );
            $itStmt->execute([(int)$attempt['order_id']]);
            $items = $itStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $attempt['items'] = $items;
        return $attempt;
    }

    /**
     * Execute COD checkout:
     * - Verifies COD is enabled for the business unit.
     * - Validates and locks inventory, enforcing the 3-unit minimum-stock threshold.
     * - Creates checkout_attempt (cod/completed), confirmed customer_order, order_items,
     *   active FEFO inventory_reservations, inventory.committed increase (no on_hand deduction),
     *   and payment (method='cod', status='pending').
     * - Clears cart only inside the atomic transaction before commit.
     * - Never creates temporary inventory_hold rows.
     *
     * @param array{address_line1?:string,address_line2?:string,city?:string,province?:string,postal_code?:string} $deliveryAddress
     * @return array{attempt_id:int,attempt_token:string,order_id:int,total_amount:float,reservations_created:int,idempotent_replay:bool}
     */
    public function placeCodOrder(
        int $customerId,
        int $businessUnitId,
        int $actorUserId,
        string $fulfillmentMethod,
        array $deliveryAddress,
        string $notes,
        string $idempotencyKey
    ): array {
        $this->assertTopLevelTransaction('COD checkout');
        $settings = $this->getUnitPaymentSettings($businessUnitId);
        if (!$settings['cod_enabled']) {
            throw new RuntimeException('Cash on Delivery is not enabled for this shop.', self::ERR_UNPROCESSABLE);
        }

        $cleanKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $this->validateCheckoutInput($businessUnitId, $fulfillmentMethod, $deliveryAddress, $notes);

        // Fast idempotency check before cart-emptiness check (cart is empty after initial COD commit).
        $existing = $this->findExistingAttempt($customerId, $businessUnitId, $cleanKey);
        if ($existing !== null) {
            return $this->formatCodReplayResult($existing);
        }

        $cartId = Cart::id($customerId, $businessUnitId);
        $branchId = Units::mainBranchId($businessUnitId);

        return $this->runWithLockedCartSnapshot(
            $customerId,
            $businessUnitId,
            $cartId,
            $cleanKey,
            'cod',
            function (array $snapshot) use ($customerId, $businessUnitId, $branchId, $cartId, $actorUserId, $fulfillmentMethod, $deliveryAddress, $notes, $cleanKey): array {
                if (!empty($snapshot['existing_attempt'])) {
                    return $this->formatCodReplayResult($snapshot['existing_attempt']);
                }

                $attemptToken = bin2hex(random_bytes(32));
                $totalAmount = (float)$snapshot['total_amount'];
                $nowUtc = $this->dbUtcNow();

                $attStmt = $this->db->prepare(
                    "INSERT INTO checkout_attempt (
                        attempt_token, idempotency_key, customer_id, business_unit_id, branch_id, cart_id,
                        payment_channel, fulfillment_method,
                        delivery_address_line1, delivery_address_line2, delivery_city, delivery_province, delivery_postal_code,
                        notes, expected_amount, attempt_status, created_at, updated_at, completed_at
                     ) VALUES (?, ?, ?, ?, ?, ?, 'cod', ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?)"
                );
                $isDelivery = $fulfillmentMethod === 'delivery';
                $nullIfEmpty = static fn(string $v): ?string => $v === '' ? null : $v;
                $attStmt->execute([
                    $attemptToken,
                    $cleanKey,
                    $customerId,
                    $businessUnitId,
                    $branchId,
                    $cartId,
                    $fulfillmentMethod,
                    $isDelivery ? $deliveryAddress['address_line1'] : null,
                    $isDelivery ? $nullIfEmpty((string)($deliveryAddress['address_line2'] ?? '')) : null,
                    $isDelivery ? $deliveryAddress['city'] : null,
                    $isDelivery ? $nullIfEmpty((string)($deliveryAddress['province'] ?? '')) : null,
                    $isDelivery ? $deliveryAddress['postal_code'] : null,
                    $nullIfEmpty($notes),
                    $totalAmount,
                    $nowUtc,
                    $nowUtc,
                    $nowUtc,
                ]);
                $attemptId = (int)$this->db->lastInsertId();

                $ordStmt = $this->db->prepare(
                    "INSERT INTO customer_order (
                        customer_id, business_unit_id, branch_id, fulfillment_method, order_status, total_amount,
                        delivery_address_line1, delivery_address_line2, delivery_city, delivery_province, delivery_postal_code, notes
                     ) VALUES (?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?, ?, ?)"
                );
                $ordStmt->execute([
                    $customerId,
                    $businessUnitId,
                    $branchId,
                    $fulfillmentMethod,
                    $totalAmount,
                    $isDelivery ? $deliveryAddress['address_line1'] : null,
                    $isDelivery ? $nullIfEmpty((string)($deliveryAddress['address_line2'] ?? '')) : null,
                    $isDelivery ? $deliveryAddress['city'] : null,
                    $isDelivery ? $nullIfEmpty((string)($deliveryAddress['province'] ?? '')) : null,
                    $isDelivery ? $deliveryAddress['postal_code'] : null,
                    $nullIfEmpty($notes),
                ]);
                $orderId = (int)$this->db->lastInsertId();

                $oiStmt = $this->db->prepare(
                    'INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
                );
                $itemsByInventory = [];
                foreach ($snapshot['lines'] as $line) {
                    $oiStmt->execute([$orderId, $line['business_product_id'], $line['quantity'], $line['selling_price']]);
                    $orderItemId = (int)$this->db->lastInsertId();
                    $invId = (int)$line['inventory_id'];
                    $itemsByInventory[$invId][] = [
                        'order_item_id' => $orderItemId,
                        'business_product_id' => (int)$line['business_product_id'],
                        'quantity' => (int)$line['quantity'],
                        'requires_batch_tracking' => (bool)$line['requires_batch_tracking'],
                        'product_name' => (string)$line['product_name'],
                    ];
                }

                $resResult = $this->reservationService->reserveLockedOrderItems(
                    $orderId,
                    $snapshot['sorted_inv_ids'],
                    $itemsByInventory,
                    $snapshot['locked_inventory'],
                    $snapshot['active_held_by_inv']
                );

                $payStmt = $this->db->prepare(
                    "INSERT INTO payment (order_id, attempt_id, payment_type, amount, payment_date, payment_method, payment_status)
                     VALUES (?, ?, 'payment', ?, NOW(), 'cod', 'pending')"
                );
                $payStmt->execute([$orderId, $attemptId, $totalAmount]);

                $this->db->prepare('UPDATE checkout_attempt SET order_id = ? WHERE attempt_id = ?')
                    ->execute([$orderId, $attemptId]);

                $this->db->prepare('DELETE FROM cart_item WHERE cart_id = ?')->execute([$cartId]);

                Audit::record(
                    $this->db,
                    'customer.checkout.cod_confirmed',
                    "COD order #{$orderId} created directly as confirmed with {$resResult['total_reserved_quantity']} unit(s) reserved.",
                    $businessUnitId,
                    $actorUserId,
                    null,
                    null,
                    "order_status:confirmed; payment_method:cod; payment_status:pending",
                    'customer_order',
                    $orderId
                );

                return [
                    'attempt_id' => $attemptId,
                    'attempt_token' => $attemptToken,
                    'order_id' => $orderId,
                    'total_amount' => $totalAmount,
                    'reservations_created' => $resResult['reservations_created'],
                    'idempotent_replay' => false,
                ];
            }
        );
    }

    /**
     * Initiate Online Checkout:
     * - Validates and locks latest inventory state, enforcing the 3-unit minimum-stock threshold.
     * - Creates a checkout_attempt ('awaiting_payment') and a 3-minute (180s) inventory_hold + hold items.
     * - Does NOT create customer_order, order_item, inventory_reservation, or clear the cart.
     * - Invokes provider-neutral OnlinePaymentGatewayInterface::createCheckoutSession() after commit.
     *
     * @param array{address_line1?:string,address_line2?:string,city?:string,province?:string,postal_code?:string} $deliveryAddress
     * @return array{
     *   attempt_id:int,
     *   attempt_token:string,
     *   hold_id:int,
     *   created_at:string,
     *   expires_at:string,
     *   total_amount:float,
     *   provider_configured:bool,
     *   redirect_url:string,
     *   idempotent_replay:bool
     * }
     */
    public function initiateOnlineCheckoutHold(
        int $customerId,
        int $businessUnitId,
        int $actorUserId,
        string $fulfillmentMethod,
        array $deliveryAddress,
        string $notes,
        string $idempotencyKey,
        ?OnlinePaymentGatewayInterface $gateway = null
    ): array {
        $this->assertTopLevelTransaction('Online checkout hold');
        $gateway ??= new UnconfiguredOnlinePaymentGateway();

        $cleanKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $this->validateCheckoutInput($businessUnitId, $fulfillmentMethod, $deliveryAddress, $notes);

        $existing = $this->findExistingAttempt($customerId, $businessUnitId, $cleanKey);
        if ($existing !== null) {
            return $this->formatOnlineReplayResult($existing);
        }

        $cartId = Cart::id($customerId, $businessUnitId);
        $branchId = Units::mainBranchId($businessUnitId);

        $holdResult = $this->runWithLockedCartSnapshot(
            $customerId,
            $businessUnitId,
            $cartId,
            $cleanKey,
            'online',
            function (array $snapshot) use ($customerId, $businessUnitId, $branchId, $cartId, $actorUserId, $fulfillmentMethod, $deliveryAddress, $notes, $cleanKey, $gateway): array {
                if (!empty($snapshot['existing_attempt'])) {
                    return $this->formatOnlineReplayResult($snapshot['existing_attempt']);
                }

                $attemptToken = bin2hex(random_bytes(32));
                $totalAmount = (float)$snapshot['total_amount'];

                // Compute exact UTC creation & +180s expiration from a single DB UTC_TIMESTAMP() instant.
                $tsRow = $this->db->query(
                    'SELECT UTC_TIMESTAMP() AS created_utc, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . self::HOLD_DURATION_SECONDS . ' SECOND) AS expires_utc'
                )->fetch(PDO::FETCH_ASSOC);
                $createdUtc = (string)$tsRow['created_utc'];
                $expiresUtc = (string)$tsRow['expires_utc'];

                $attStmt = $this->db->prepare(
                    "INSERT INTO checkout_attempt (
                        attempt_token, idempotency_key, customer_id, business_unit_id, branch_id, cart_id,
                        payment_channel, fulfillment_method,
                        delivery_address_line1, delivery_address_line2, delivery_city, delivery_province, delivery_postal_code,
                        notes, expected_amount, attempt_status, provider_name, created_at, updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, 'online', ?, ?, ?, ?, ?, ?, ?, ?, 'awaiting_payment', ?, ?, ?)"
                );
                $isDelivery = $fulfillmentMethod === 'delivery';
                $nullIfEmpty = static fn(string $v): ?string => $v === '' ? null : $v;
                $attStmt->execute([
                    $attemptToken,
                    $cleanKey,
                    $customerId,
                    $businessUnitId,
                    $branchId,
                    $cartId,
                    $fulfillmentMethod,
                    $isDelivery ? $deliveryAddress['address_line1'] : null,
                    $isDelivery ? $nullIfEmpty((string)($deliveryAddress['address_line2'] ?? '')) : null,
                    $isDelivery ? $deliveryAddress['city'] : null,
                    $isDelivery ? $nullIfEmpty((string)($deliveryAddress['province'] ?? '')) : null,
                    $isDelivery ? $deliveryAddress['postal_code'] : null,
                    $nullIfEmpty($notes),
                    $totalAmount,
                    $gateway->providerName(),
                    $createdUtc,
                    $createdUtc,
                ]);
                $attemptId = (int)$this->db->lastInsertId();

                $holdStmt = $this->db->prepare(
                    "INSERT INTO inventory_hold (attempt_id, customer_id, business_unit_id, hold_status, created_at, expires_at)
                     VALUES (?, ?, ?, 'active', ?, ?)"
                );
                $holdStmt->execute([$attemptId, $customerId, $businessUnitId, $createdUtc, $expiresUtc]);
                $holdId = (int)$this->db->lastInsertId();

                $hiStmt = $this->db->prepare(
                    "INSERT INTO inventory_hold_item (hold_id, inventory_id, business_product_id, quantity, unit_price, created_at)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $sessionItems = [];
                $totalHeldQty = 0;
                foreach ($snapshot['lines'] as $line) {
                    $hiStmt->execute([
                        $holdId,
                        $line['inventory_id'],
                        $line['business_product_id'],
                        $line['quantity'],
                        $line['selling_price'],
                        $createdUtc,
                    ]);
                    $totalHeldQty += (int)$line['quantity'];
                    $sessionItems[] = [
                        'business_product_id' => (int)$line['business_product_id'],
                        'product_name' => (string)$line['product_name'],
                        'quantity' => (int)$line['quantity'],
                        'unit_price' => (float)$line['selling_price'],
                    ];
                }

                Audit::record(
                    $this->db,
                    'customer.checkout.hold_created',
                    "Created 3-minute online checkout hold #{$holdId} (attempt #{$attemptId}, {$totalHeldQty} unit(s), expires UTC {$expiresUtc}).",
                    $businessUnitId,
                    $actorUserId,
                    null,
                    null,
                    "hold_status:active; expires_at:{$expiresUtc}",
                    'inventory_hold',
                    $holdId
                );

                return [
                    'attempt_id' => $attemptId,
                    'attempt_token' => $attemptToken,
                    'hold_id' => $holdId,
                    'created_at' => $createdUtc,
                    'expires_at' => $expiresUtc,
                    'total_amount' => $totalAmount,
                    'session_items' => $sessionItems,
                    'provider_configured' => false,
                    'redirect_url' => "/checkout/attempts/{$attemptId}",
                    'idempotent_replay' => false,
                ];
            }
        );

        if (!empty($holdResult['idempotent_replay'])) {
            return $holdResult;
        }

        // Create external provider checkout session ONLY after the hold transaction is durably committed.
        if ($gateway->isConfigured()) {
            try {
                $session = $gateway->createCheckoutSession([
                    'attempt_id' => $holdResult['attempt_id'],
                    'attempt_token' => $holdResult['attempt_token'],
                    'business_unit_id' => $businessUnitId,
                    'customer_id' => $customerId,
                    'amount' => $holdResult['total_amount'],
                    'currency' => 'PHP',
                    'expires_at_utc' => $holdResult['expires_at'],
                    'items' => $holdResult['session_items'],
                    'success_return_url' => "/checkout/attempts/{$holdResult['attempt_id']}",
                    'cancel_return_url' => "/checkout/attempts/{$holdResult['attempt_id']}",
                ]);

                $upd = $this->db->prepare(
                    "UPDATE checkout_attempt
                     SET provider_name = ?, provider_session_id = ?, provider_checkout_url = ?, updated_at = UTC_TIMESTAMP()
                     WHERE attempt_id = ?"
                );
                $upd->execute([
                    $session['provider_name'],
                    $session['provider_session_id'],
                    $session['checkout_url'],
                    $holdResult['attempt_id'],
                ]);

                if (!empty($session['checkout_url'])) {
                    $holdResult['provider_configured'] = true;
                    $holdResult['redirect_url'] = (string)$session['checkout_url'];
                }
            } catch (Throwable $e) {
                // Provider session creation failed after hold commit: release the hold safely so stock is freed immediately.
                $this->releaseHoldInternal(
                    (int)$holdResult['hold_id'],
                    'released',
                    'failed',
                    'provider_session_failed',
                    'Payment session initialization failed: ' . $e->getMessage(),
                    $actorUserId
                );
                throw $e;
            }
        }

        unset($holdResult['session_items']);
        return $holdResult;
    }

    /**
     * Server-side handler for verified payment captures (including delayed webhook captures arriving after hold expiry).
     * Never call from an unverified browser redirect.
     *
     * @param array{
     *   attempt_id?:int,
     *   attempt_token?:string,
     *   provider_name:string,
     *   provider_payment_id:string,
     *   provider_session_id?:?string,
     *   provider_event_id?:?string,
     *   event_type?:string,
     *   captured_amount:float,
     *   payment_method?:string,
     *   raw_payload?:?string
     * } $capturePayload
     * @return array{
     *   status:string,
     *   attempt_id:int,
     *   order_id:?int,
     *   reconciliation_id:?int,
     *   reconciled_after_expiry:bool
     * }
     */
    public function handleVerifiedPaymentCapture(array $capturePayload): array
    {
        $this->assertTopLevelTransaction('Verified payment capture');

        $providerName = trim((string)($capturePayload['provider_name'] ?? ''));
        $providerPaymentId = trim((string)($capturePayload['provider_payment_id'] ?? ''));
        $providerEventId = isset($capturePayload['provider_event_id']) && trim((string)$capturePayload['provider_event_id']) !== ''
            ? trim((string)$capturePayload['provider_event_id'])
            : null;
        $eventType = trim((string)($capturePayload['event_type'] ?? 'payment.captured'));
        $capturedAmount = round((float)($capturePayload['captured_amount'] ?? 0), 2);
        $paymentMethod = trim((string)($capturePayload['payment_method'] ?? 'online'));
        if ($paymentMethod === '') {
            $paymentMethod = 'online';
        }
        $rawPayload = $capturePayload['raw_payload'] ?? null;

        if ($providerName === '' || $providerPaymentId === '') {
            throw new RuntimeException('Provider name and verified provider payment ID are required.', self::ERR_UNPROCESSABLE);
        }

        // Pre-resolve attempt and its hold's inventory IDs so inventory locks are acquired FIRST in ascending order.
        $targetAttempt = $this->locateAttemptForProviderEvent($capturePayload);
        if (!$targetAttempt) {
            throw new RuntimeException('Matching checkout attempt could not be found for payment capture.', self::ERR_NOT_FOUND);
        }
        $attemptId = (int)$targetAttempt['attempt_id'];
        $sortedInvIds = $this->loadHoldInventoryIdsForAttempt($attemptId);

        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            // 1. Webhook event deduplication inside transaction.
            $webhookEventId = null;
            if ($providerEventId !== null) {
                $webhookEventId = $this->claimWebhookEvent(
                    $providerName,
                    $providerEventId,
                    $providerPaymentId,
                    $attemptId,
                    $eventType,
                    $rawPayload
                );
                if ($webhookEventId === null) {
                    $this->db->commit();
                    return [
                        'status' => 'duplicate_ignored',
                        'attempt_id' => $attemptId,
                        'order_id' => $targetAttempt['order_id'] !== null ? (int)$targetAttempt['order_id'] : null,
                        'reconciliation_id' => null,
                        'reconciled_after_expiry' => false,
                    ];
                }
            }

            // 2. Acquire inventory locks FIRST in ascending order (consistent lock hierarchy across all services).
            $lockedInventory = $sortedInvIds ? $this->lockInventories($sortedInvIds) : [];

            // 3. Lock attempt, hold, and hold items.
            $attStmt = $this->db->prepare('SELECT * FROM checkout_attempt WHERE attempt_id = ? FOR UPDATE');
            $attStmt->execute([$attemptId]);
            $attempt = $attStmt->fetch(PDO::FETCH_ASSOC);
            if (!$attempt) {
                throw new RuntimeException("Checkout attempt #{$attemptId} was not found.", self::ERR_NOT_FOUND);
            }
            if ($attempt['payment_channel'] !== 'online') {
                throw new RuntimeException("Checkout attempt #{$attemptId} is not an online payment attempt.", self::ERR_CONFLICT);
            }

            // Deduplicate by (provider_name, provider_payment_id) across attempts/payments.
            $dupPayStmt = $this->db->prepare(
                'SELECT payment_id, order_id, attempt_id FROM payment WHERE provider_name = ? AND provider_payment_id = ? AND payment_type = "payment" LIMIT 1 FOR UPDATE'
            );
            $dupPayStmt->execute([$providerName, $providerPaymentId]);
            $dupPay = $dupPayStmt->fetch(PDO::FETCH_ASSOC);
            if ($dupPay) {
                $this->finalizeWebhookEvent($webhookEventId, 'ignored_duplicate', 'Duplicate provider_payment_id ignored.');
                $this->db->commit();
                return [
                    'status' => 'duplicate_ignored',
                    'attempt_id' => $attemptId,
                    'order_id' => $dupPay['order_id'] !== null ? (int)$dupPay['order_id'] : ($attempt['order_id'] !== null ? (int)$attempt['order_id'] : null),
                    'reconciliation_id' => null,
                    'reconciled_after_expiry' => false,
                ];
            }

            if ($attempt['attempt_status'] === 'completed' && $attempt['order_id'] !== null) {
                $this->finalizeWebhookEvent($webhookEventId, 'ignored_duplicate', 'Attempt already completed.');
                $this->db->commit();
                return [
                    'status' => 'duplicate_ignored',
                    'attempt_id' => $attemptId,
                    'order_id' => (int)$attempt['order_id'],
                    'reconciliation_id' => null,
                    'reconciled_after_expiry' => false,
                ];
            }

            if ($attempt['attempt_status'] === 'reconciliation_required') {
                $this->finalizeWebhookEvent($webhookEventId, 'ignored_duplicate', 'Attempt already queued for reconciliation.');
                $this->db->commit();
                return [
                    'status' => 'duplicate_ignored',
                    'attempt_id' => $attemptId,
                    'order_id' => null,
                    'reconciliation_id' => $this->findReconciliationId($attemptId),
                    'reconciled_after_expiry' => false,
                ];
            }

            $holdStmt = $this->db->prepare(
                "SELECT *, (expires_at > UTC_TIMESTAMP()) AS is_unexpired
                 FROM inventory_hold
                 WHERE attempt_id = ?
                 FOR UPDATE"
            );
            $holdStmt->execute([$attemptId]);
            $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hold) {
                throw new RuntimeException("Inventory hold missing for online checkout attempt #{$attemptId}.", self::ERR_CONFLICT);
            }
            $holdId = (int)$hold['hold_id'];

            $hiStmt = $this->db->prepare(
                "SELECT ihi.hold_item_id, ihi.inventory_id, ihi.business_product_id, ihi.quantity, ihi.unit_price,
                        bp.requires_batch_tracking, bp.product_status, bp.business_unit_id, p.product_name
                 FROM inventory_hold_item ihi
                 JOIN business_product bp ON bp.business_product_id = ihi.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE ihi.hold_id = ?
                 ORDER BY ihi.inventory_id ASC, ihi.hold_item_id ASC
                 FOR UPDATE"
            );
            $hiStmt->execute([$holdId]);
            $holdItems = $hiStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$holdItems) {
                throw new RuntimeException("Inventory hold #{$holdId} has no items.", self::ERR_UNPROCESSABLE);
            }

            $wasExpiredOrReleased = ($hold['hold_status'] !== 'active') || ((int)$hold['is_unexpired'] !== 1);
            $businessUnitId = (int)$attempt['business_unit_id'];
            $customerId = (int)$attempt['customer_id'];
            $expectedAmount = round((float)$attempt['expected_amount'], 2);

            // Amount mismatch must never auto-confirm an order or silently drop captured funds.
            if (abs($capturedAmount - $expectedAmount) >= 0.01) {
                $reconId = $this->recordReconciliationRequired(
                    $attemptId,
                    $holdId,
                    $businessUnitId,
                    $customerId,
                    $providerName,
                    $providerPaymentId,
                    $capturedAmount,
                    $paymentMethod,
                    $hold['hold_status'] === 'active',
                    "Captured payment amount ({$capturedAmount}) does not match expected checkout amount ({$expectedAmount})."
                );
                $this->finalizeWebhookEvent($webhookEventId, 'reconciliation_required', 'Amount mismatch flagged for reconciliation.');
                $this->db->commit();
                return [
                    'status' => 'reconciliation_required',
                    'attempt_id' => $attemptId,
                    'order_id' => null,
                    'reconciliation_id' => $reconId,
                    'reconciled_after_expiry' => false,
                ];
            }

            // Check whether products are still active & stock is available (excluding this hold itself).
            $otherActiveHeld = OrderReservationService::activeHeldQuantitiesByInventory($this->db, $sortedInvIds, $holdId);
            $fulfillabilityIssue = $this->checkHoldFulfillability($holdItems, $businessUnitId, $lockedInventory, $otherActiveHeld);

            if ($fulfillabilityIssue !== null) {
                // Do NOT silently discard payment and do NOT create an unfulfillable order.
                $reconId = $this->recordReconciliationRequired(
                    $attemptId,
                    $holdId,
                    $businessUnitId,
                    $customerId,
                    $providerName,
                    $providerPaymentId,
                    $capturedAmount,
                    $paymentMethod,
                    $hold['hold_status'] === 'active',
                    $fulfillabilityIssue
                );
                $this->finalizeWebhookEvent($webhookEventId, 'reconciliation_required', $fulfillabilityIssue);
                $this->db->commit();
                return [
                    'status' => 'reconciliation_required',
                    'attempt_id' => $attemptId,
                    'order_id' => null,
                    'reconciliation_id' => $reconId,
                    'reconciled_after_expiry' => false,
                ];
            }

            // Fulfillable! Atomically create confirmed customer_order, order_items, FEFO reservations,
            // increment inventory.committed, mark hold 'converted', record completed payment, and clear cart items.
            $nowUtc = $this->dbUtcNow();
            $ordStmt = $this->db->prepare(
                "INSERT INTO customer_order (
                    customer_id, business_unit_id, branch_id, fulfillment_method, order_status, total_amount,
                    delivery_address_line1, delivery_address_line2, delivery_city, delivery_province, delivery_postal_code, notes
                 ) VALUES (?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, ?, ?, ?)"
            );
            $ordStmt->execute([
                $customerId,
                $businessUnitId,
                (int)$attempt['branch_id'],
                $attempt['fulfillment_method'],
                $expectedAmount,
                $attempt['delivery_address_line1'],
                $attempt['delivery_address_line2'],
                $attempt['delivery_city'],
                $attempt['delivery_province'],
                $attempt['delivery_postal_code'],
                $attempt['notes'],
            ]);
            $orderId = (int)$this->db->lastInsertId();

            $oiStmt = $this->db->prepare(
                'INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
            );
            $itemsByInventory = [];
            foreach ($holdItems as $hi) {
                $oiStmt->execute([$orderId, $hi['business_product_id'], $hi['quantity'], $hi['unit_price']]);
                $orderItemId = (int)$this->db->lastInsertId();
                $invId = (int)$hi['inventory_id'];
                $itemsByInventory[$invId][] = [
                    'order_item_id' => $orderItemId,
                    'business_product_id' => (int)$hi['business_product_id'],
                    'quantity' => (int)$hi['quantity'],
                    'requires_batch_tracking' => !empty($hi['requires_batch_tracking']),
                    'product_name' => (string)$hi['product_name'],
                ];
            }

            $resResult = $this->reservationService->reserveLockedOrderItems(
                $orderId,
                $sortedInvIds,
                $itemsByInventory,
                $lockedInventory,
                $otherActiveHeld
            );

            $payStmt = $this->db->prepare(
                "INSERT INTO payment (order_id, attempt_id, payment_type, amount, payment_date, payment_method, payment_status, provider_name, provider_payment_id)
                 VALUES (?, ?, 'payment', ?, NOW(), ?, 'completed', ?, ?)"
            );
            $payStmt->execute([$orderId, $attemptId, $capturedAmount, $paymentMethod, $providerName, $providerPaymentId]);

            $releaseReason = $wasExpiredOrReleased ? 'reconciled_after_expiry' : null;
            $updHold = $this->db->prepare(
                "UPDATE inventory_hold
                 SET hold_status = 'converted', converted_at = ?, converted_order_id = ?, release_reason = COALESCE(?, release_reason)
                 WHERE hold_id = ?"
            );
            $updHold->execute([$nowUtc, $orderId, $releaseReason, $holdId]);

            $updAtt = $this->db->prepare(
                "UPDATE checkout_attempt
                 SET attempt_status = 'completed', order_id = ?, provider_name = ?, provider_payment_id = ?,
                     updated_at = ?, completed_at = ?
                 WHERE attempt_id = ?"
            );
            $updAtt->execute([$orderId, $providerName, $providerPaymentId, $nowUtc, $nowUtc, $attemptId]);

            if ($attempt['cart_id'] !== null) {
                $this->clearOrderedCartItems((int)$attempt['cart_id'], $holdItems);
            }

            $auditCode = $wasExpiredOrReleased
                ? 'customer.checkout.late_capture_reconciled'
                : 'customer.checkout.online_confirmed';
            $auditMsg = $wasExpiredOrReleased
                ? "Reconciled delayed payment capture for expired hold #{$holdId}; created confirmed order #{$orderId} ({$resResult['total_reserved_quantity']} unit(s) reserved)."
                : "Converted online checkout hold #{$holdId} into confirmed order #{$orderId} ({$resResult['total_reserved_quantity']} unit(s) reserved).";

            $actorUserId = $this->lookupCustomerUserAccountId($customerId);
            Audit::record(
                $this->db,
                $auditCode,
                $auditMsg,
                $businessUnitId,
                $actorUserId,
                null,
                "hold_status:{$hold['hold_status']}",
                "hold_status:converted; order_id:{$orderId}",
                'customer_order',
                $orderId
            );

            $this->finalizeWebhookEvent($webhookEventId, 'processed', "Confirmed order #{$orderId} created.");
            $this->db->commit();

            return [
                'status' => $wasExpiredOrReleased ? 'reconciled_after_expiry' : 'confirmed',
                'attempt_id' => $attemptId,
                'order_id' => $orderId,
                'reconciliation_id' => null,
                'reconciled_after_expiry' => $wasExpiredOrReleased,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Record a server-verified payment failure and safely release any active hold.
     *
     * @param array{
     *   attempt_id?:int,
     *   attempt_token?:string,
     *   provider_name:string,
     *   provider_session_id?:?string,
     *   provider_payment_id?:?string,
     *   provider_event_id?:?string,
     *   event_type?:string,
     *   payment_method?:string,
     *   failure_reason?:string,
     *   raw_payload?:?string
     * } $failurePayload
     * @return array{status:string, attempt_id:int, hold_released:bool}
     */
    public function handleVerifiedPaymentFailure(array $failurePayload): array
    {
        $this->assertTopLevelTransaction('Verified payment failure');

        $providerName = trim((string)($failurePayload['provider_name'] ?? ''));
        $providerPaymentId = isset($failurePayload['provider_payment_id']) && trim((string)$failurePayload['provider_payment_id']) !== ''
            ? trim((string)$failurePayload['provider_payment_id'])
            : null;
        $providerEventId = isset($failurePayload['provider_event_id']) && trim((string)$failurePayload['provider_event_id']) !== ''
            ? trim((string)$failurePayload['provider_event_id'])
            : null;
        $eventType = trim((string)($failurePayload['event_type'] ?? 'payment.failed'));
        $paymentMethod = trim((string)($failurePayload['payment_method'] ?? 'online')) ?: 'online';
        $failureReason = mb_substr(trim((string)($failurePayload['failure_reason'] ?? 'Online payment failed.')), 0, 255);
        $rawPayload = $failurePayload['raw_payload'] ?? null;

        if ($providerName === '') {
            throw new RuntimeException('Provider name is required to record payment failure.', self::ERR_UNPROCESSABLE);
        }

        $targetAttempt = $this->locateAttemptForProviderEvent($failurePayload);
        if (!$targetAttempt) {
            throw new RuntimeException('Matching checkout attempt could not be found.', self::ERR_NOT_FOUND);
        }
        $attemptId = (int)$targetAttempt['attempt_id'];
        $sortedInvIds = $this->loadHoldInventoryIdsForAttempt($attemptId);

        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            $webhookEventId = null;
            if ($providerEventId !== null) {
                $webhookEventId = $this->claimWebhookEvent(
                    $providerName,
                    $providerEventId,
                    $providerPaymentId,
                    $attemptId,
                    $eventType,
                    $rawPayload
                );
                if ($webhookEventId === null) {
                    $this->db->commit();
                    return ['status' => 'duplicate_ignored', 'attempt_id' => $attemptId, 'hold_released' => false];
                }
            }

            if ($sortedInvIds) {
                $this->lockInventories($sortedInvIds);
            }

            $attStmt = $this->db->prepare('SELECT * FROM checkout_attempt WHERE attempt_id = ? FOR UPDATE');
            $attStmt->execute([$attemptId]);
            $attempt = $attStmt->fetch(PDO::FETCH_ASSOC);
            if (!$attempt) {
                throw new RuntimeException("Checkout attempt #{$attemptId} was not found.", self::ERR_NOT_FOUND);
            }
            if ($attempt['payment_channel'] !== 'online') {
                throw new RuntimeException("Checkout attempt #{$attemptId} is not an online payment attempt.", self::ERR_CONFLICT);
            }

            if (in_array($attempt['attempt_status'], ['completed', 'reconciliation_required'], true)) {
                $this->finalizeWebhookEvent($webhookEventId, 'ignored_duplicate', 'Ignored failure after terminal paid state.');
                $this->db->commit();
                return ['status' => 'ignored_already_paid', 'attempt_id' => $attemptId, 'hold_released' => false];
            }

            $holdStmt = $this->db->prepare('SELECT * FROM inventory_hold WHERE attempt_id = ? FOR UPDATE');
            $holdStmt->execute([$attemptId]);
            $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);

            $nowUtc = $this->dbUtcNow();
            $holdReleased = false;
            if ($hold && $hold['hold_status'] === 'active') {
                $this->db->prepare(
                    "UPDATE inventory_hold
                     SET hold_status = 'released', released_at = ?, release_reason = 'payment_failed'
                     WHERE hold_id = ?"
                )->execute([$nowUtc, (int)$hold['hold_id']]);
                $holdReleased = true;
            }

            $this->db->prepare(
                "UPDATE checkout_attempt
                 SET attempt_status = 'failed', failure_reason = ?, updated_at = ?
                 WHERE attempt_id = ? AND attempt_status IN ('initiated', 'awaiting_payment', 'expired')"
            )->execute([$failureReason, $nowUtc, $attemptId]);

            // Record failed payment attempt once if providerPaymentId is provided or no failed row exists yet.
            $existingFailStmt = $this->db->prepare(
                "SELECT payment_id FROM payment WHERE attempt_id = ? AND payment_status = 'failed' LIMIT 1 FOR UPDATE"
            );
            $existingFailStmt->execute([$attemptId]);
            if ($existingFailStmt->fetchColumn() === false) {
                $this->db->prepare(
                    "INSERT INTO payment (order_id, attempt_id, payment_type, amount, payment_date, payment_method, payment_status, provider_name, provider_payment_id)
                     VALUES (NULL, ?, 'payment', ?, NOW(), ?, 'failed', ?, ?)"
                )->execute([$attemptId, (float)$attempt['expected_amount'], $paymentMethod, $providerName, $providerPaymentId]);
            }

            $actorUserId = $this->lookupCustomerUserAccountId((int)$attempt['customer_id']);
            Audit::record(
                $this->db,
                'customer.checkout.online_failed',
                "Recorded online payment failure for checkout attempt #{$attemptId}: {$failureReason}",
                (int)$attempt['business_unit_id'],
                $actorUserId,
                null,
                "attempt_status:{$attempt['attempt_status']}",
                'attempt_status:failed',
                'checkout_attempt',
                $attemptId
            );

            $this->finalizeWebhookEvent($webhookEventId, 'processed', 'Payment failure recorded and hold released.');
            $this->db->commit();

            return ['status' => 'failed', 'attempt_id' => $attemptId, 'hold_released' => $holdReleased];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel and release an active online checkout hold initiated by the owning customer.
     *
     * @return array{attempt_id:int, hold_id:int, released:bool, business_unit_id:int}
     */
    public function cancelActiveHoldByCustomer(int $attemptId, int $customerId, int $actorUserId, string $reason = 'customer_cancelled'): array
    {
        $this->assertTopLevelTransaction('Customer hold cancellation');

        $preStmt = $this->db->prepare(
            "SELECT ca.attempt_id, ca.business_unit_id, ih.hold_id
             FROM checkout_attempt ca
             LEFT JOIN inventory_hold ih ON ih.attempt_id = ca.attempt_id
             WHERE ca.attempt_id = ? AND ca.customer_id = ?
             LIMIT 1"
        );
        $preStmt->execute([$attemptId, $customerId]);
        $pre = $preStmt->fetch(PDO::FETCH_ASSOC);
        if (!$pre || $pre['hold_id'] === null) {
            throw new RuntimeException('Online checkout session was not found.', self::ERR_NOT_FOUND);
        }

        $holdId = (int)$pre['hold_id'];
        $buId = (int)$pre['business_unit_id'];
        $released = $this->releaseHoldInternal($holdId, 'released', 'failed', $reason, 'Customer cancelled pending online checkout.', $actorUserId);

        return [
            'attempt_id' => $attemptId,
            'hold_id' => $holdId,
            'released' => $released,
            'business_unit_id' => $buId,
        ];
    }

    /**
     * Transition expired active holds (expires_at <= UTC_TIMESTAMP()) to 'expired' status
     * in deterministic lock order. Note that availability queries already ignore expired holds
     * immediately even before this cleanup executes.
     *
     * @return array{expired_count:int, hold_ids:array<int,int>}
     */
    public function releaseExpiredHolds(int $limit = 100): array
    {
        $this->assertTopLevelTransaction('Expired hold cleanup');
        $limit = max(1, min(500, $limit));

        $stmt = $this->db->prepare(
            "SELECT hold_id
             FROM inventory_hold
             WHERE hold_status = 'active'
               AND expires_at <= UTC_TIMESTAMP()
             ORDER BY expires_at ASC, hold_id ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        $holdIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $expiredIds = [];
        foreach ($holdIds as $holdId) {
            if ($this->releaseHoldInternal($holdId, 'expired', 'expired', 'expired_cleanup', 'Temporary 3-minute checkout hold expired.', null, true)) {
                $expiredIds[] = $holdId;
            }
        }

        return [
            'expired_count' => count($expiredIds),
            'hold_ids' => $expiredIds,
        ];
    }

    private function releaseHoldInternal(
        int $holdId,
        string $targetHoldStatus,
        string $targetAttemptStatus,
        string $releaseReason,
        string $failureReason,
        ?int $actorUserId,
        bool $requireExpired = false
    ): bool {
        $invIds = $this->loadHoldInventoryIdsByHoldId($holdId);

        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        try {
            if ($invIds) {
                $this->lockInventories($invIds);
            }

            $holdStmt = $this->db->prepare(
                "SELECT *, (expires_at <= UTC_TIMESTAMP()) AS is_expired_now
                 FROM inventory_hold
                 WHERE hold_id = ?
                 FOR UPDATE"
            );
            $holdStmt->execute([$holdId]);
            $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);
            if (!$hold || $hold['hold_status'] !== 'active') {
                $this->db->commit();
                return false;
            }

            if ($requireExpired && empty($hold['is_expired_now'])) {
                $this->db->commit();
                return false;
            }

            $attemptId = (int)$hold['attempt_id'];
            $attStmt = $this->db->prepare('SELECT * FROM checkout_attempt WHERE attempt_id = ? FOR UPDATE');
            $attStmt->execute([$attemptId]);
            $attempt = $attStmt->fetch(PDO::FETCH_ASSOC);

            $nowUtc = $this->dbUtcNow();
            $this->db->prepare(
                "UPDATE inventory_hold
                 SET hold_status = ?, released_at = ?, release_reason = ?
                 WHERE hold_id = ? AND hold_status = 'active'"
            )->execute([$targetHoldStatus, $nowUtc, mb_substr($releaseReason, 0, 100), $holdId]);

            if ($attempt && in_array($attempt['attempt_status'], ['initiated', 'awaiting_payment'], true)) {
                $this->db->prepare(
                    "UPDATE checkout_attempt
                     SET attempt_status = ?, failure_reason = COALESCE(failure_reason, ?), updated_at = ?
                     WHERE attempt_id = ?"
                )->execute([$targetAttemptStatus, mb_substr($failureReason, 0, 255), $nowUtc, $attemptId]);
            }

            $resolvedActor = $actorUserId ?? $this->lookupCustomerUserAccountId((int)$hold['customer_id']);
            Audit::record(
                $this->db,
                $targetHoldStatus === 'expired' ? 'customer.checkout.hold_expired' : 'customer.checkout.hold_released',
                "Hold #{$holdId} transitioned from active to {$targetHoldStatus} ({$releaseReason}).",
                (int)$hold['business_unit_id'],
                $resolvedActor,
                null,
                'hold_status:active',
                "hold_status:{$targetHoldStatus}; reason:{$releaseReason}",
                'inventory_hold',
                $holdId
            );

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Execute a checkout callback under READ COMMITTED with deterministic inventory_id ASC FOR UPDATE locks
     * and full 3-unit minimum-stock threshold & batch-expiry validation.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $fn
     * @return array<string,mixed>
     */
    private function runWithLockedCartSnapshot(
        int $customerId,
        int $businessUnitId,
        int $cartId,
        string $idempotencyKey,
        string $expectedChannel,
        callable $fn
    ): array {
        $maxAttempts = 2;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $preLines = $this->readCartLines($cartId);
            if (!$preLines) {
                throw new RuntimeException('Your cart is empty.', self::ERR_UNPROCESSABLE);
            }

            $preInvIds = [];
            foreach ($preLines as $pl) {
                if ((int)$pl['business_unit_id'] !== $businessUnitId) {
                    throw new RuntimeException($pl['product_name'] . ' does not belong to this shop and cannot be ordered here.', self::ERR_UNPROCESSABLE);
                }
                if ($pl['product_status'] !== 'active') {
                    throw new RuntimeException($pl['product_name'] . ' is no longer available.', self::ERR_UNPROCESSABLE);
                }
                if ((int)$pl['quantity'] < 1 || (int)$pl['quantity'] > Cart::MAX_QTY) {
                    throw new RuntimeException('Invalid quantity for ' . $pl['product_name'] . '.', self::ERR_UNPROCESSABLE);
                }
                if ($pl['inventory_id'] === null) {
                    throw new RuntimeException('Not enough stock for ' . $pl['product_name'] . ' (0 available).', self::ERR_UNPROCESSABLE);
                }
                $preInvIds[(int)$pl['inventory_id']] = (int)$pl['inventory_id'];
            }
            $sortedInvIds = array_values($preInvIds);
            sort($sortedInvIds, SORT_NUMERIC);

            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->db->beginTransaction();
            try {
                // 1. Lock distinct inventory rows FIRST in ascending ID order.
                $lockedInventory = $this->lockInventories($sortedInvIds);

                // 2. Re-check idempotency key under inventory lock (serializes duplicate submits cleanly).
                $existingAttempt = $this->findExistingAttempt($customerId, $businessUnitId, $idempotencyKey, true);
                if ($existingAttempt !== null) {
                    if ($existingAttempt['payment_channel'] !== $expectedChannel) {
                        throw new RuntimeException('This checkout submission token was already used for another payment method. Please refresh checkout.', self::ERR_CONFLICT);
                    }
                    $result = $fn(['existing_attempt' => $existingAttempt]);
                    $this->db->commit();
                    return $result;
                }

                // 3. Lock and re-read cart lines under inventory locks.
                $lines = $this->readCartLines($cartId, true);
                if (!$lines) {
                    throw new RuntimeException('Your cart is empty.', self::ERR_UNPROCESSABLE);
                }

                $lockedInvSet = array_flip($sortedInvIds);
                $needRetry = false;
                foreach ($lines as $l) {
                    if ($l['inventory_id'] === null || !isset($lockedInvSet[(int)$l['inventory_id']])) {
                        $needRetry = true;
                        break;
                    }
                }
                if ($needRetry) {
                    $this->db->rollBack();
                    if ($attempt < $maxAttempts) {
                        continue;
                    }
                    throw new RuntimeException('Your cart contents changed during checkout. Please try again.', self::ERR_CONFLICT);
                }

                // 4. Validate products, quantities, active holds, 3-unit threshold, and FEFO usable batch stock.
                $activeHeldByInv = OrderReservationService::activeHeldQuantitiesByInventory($this->db, $sortedInvIds);
                $linesByInv = [];
                $total = 0.0;
                foreach ($lines as $l) {
                    if ((int)$l['business_unit_id'] !== $businessUnitId) {
                        throw new RuntimeException($l['product_name'] . ' does not belong to this shop and cannot be ordered here.', self::ERR_UNPROCESSABLE);
                    }
                    if ($l['product_status'] !== 'active') {
                        throw new RuntimeException($l['product_name'] . ' is no longer available.', self::ERR_UNPROCESSABLE);
                    }
                    if ((int)$l['quantity'] < 1 || (int)$l['quantity'] > Cart::MAX_QTY) {
                        throw new RuntimeException('Invalid quantity for ' . $l['product_name'] . '.', self::ERR_UNPROCESSABLE);
                    }
                    $invId = (int)$l['inventory_id'];
                    $linesByInv[$invId][] = $l;
                    $total = round($total + round((float)$l['selling_price'] * (int)$l['quantity'], 2), 2);
                }

                foreach ($sortedInvIds as $invId) {
                    if (!isset($linesByInv[$invId])) {
                        continue;
                    }
                    if (!isset($lockedInventory[$invId])) {
                        throw new RuntimeException("Inventory record #{$invId} could not be locked.", self::ERR_UNPROCESSABLE);
                    }

                    $inv = $lockedInventory[$invId];
                    $invLines = $linesByInv[$invId];
                    $productName = (string)$invLines[0]['product_name'];
                    $requiresBatch = !empty($invLines[0]['requires_batch_tracking']);

                    $requestedQty = 0;
                    foreach ($invLines as $il) {
                        $requestedQty += (int)$il['quantity'];
                    }

                    $activeHeld = (int)($activeHeldByInv[$invId] ?? 0);
                    $invAvailable = (((int)$inv['on_hand'] + (int)$inv['in_transit']) - (int)$inv['committed']) - $activeHeld;

                    $effectiveAvailable = $invAvailable;
                    if ($requiresBatch) {
                        $batchPool = InventoryBatch::lockEligibleFefoBatchesForReservation($this->db, $invId);
                        $batchUnreserved = 0;
                        foreach ($batchPool as $batch) {
                            $batchUnreserved += (int)$batch['unreserved_quantity'];
                        }
                        $effectiveAvailable = min($invAvailable, $batchUnreserved - $activeHeld);
                    }

                    $displayAvail = max(0, $effectiveAvailable);
                    if ($effectiveAvailable < self::MIN_ONLINE_STOCK_THRESHOLD) {
                        throw new RuntimeException(
                            "'{$productName}' requires at least " . self::MIN_ONLINE_STOCK_THRESHOLD . " units in available stock for online checkout ({$displayAvail} available).",
                            self::ERR_UNPROCESSABLE
                        );
                    }
                    if ($effectiveAvailable < $requestedQty) {
                        throw new RuntimeException(
                            "Not enough stock for {$productName} ({$displayAvail} available).",
                            self::ERR_UNPROCESSABLE
                        );
                    }
                }

                $result = $fn([
                    'existing_attempt' => null,
                    'lines' => $lines,
                    'total_amount' => $total,
                    'sorted_inv_ids' => $sortedInvIds,
                    'locked_inventory' => $lockedInventory,
                    'active_held_by_inv' => $activeHeldByInv,
                ]);

                $this->db->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }

        throw new RuntimeException('Could not lock cart inventory snapshot.', self::ERR_CONFLICT);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readCartLines(int $cartId, bool $forUpdate = false): array
    {
        $lockClause = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare(
            "SELECT ci.cart_item_id, ci.quantity,
                    bp.business_product_id, bp.business_unit_id, bp.selling_price, bp.product_status, bp.requires_batch_tracking,
                    p.product_name,
                    i.inventory_id
             FROM cart_item ci
             JOIN business_product bp ON bp.business_product_id = ci.business_product_id
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE ci.cart_id = ?
             ORDER BY ci.cart_item_id ASC{$lockClause}"
        );
        $stmt->execute([$cartId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int,int> $sortedInvIds
     * @return array<int,array<string,mixed>>
     */
    private function lockInventories(array $sortedInvIds): array
    {
        if (!$sortedInvIds) {
            return [];
        }
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
     * @return array<string,mixed>|null
     */
    private function findExistingAttempt(int $customerId, int $businessUnitId, string $idempotencyKey, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare(
            "SELECT ca.*, ih.hold_id, ih.created_at AS hold_created_at, ih.expires_at AS hold_expires_at, ih.hold_status
             FROM checkout_attempt ca
             LEFT JOIN inventory_hold ih ON ih.attempt_id = ca.attempt_id
             WHERE ca.customer_id = ? AND ca.business_unit_id = ? AND ca.idempotency_key = ?
             LIMIT 1{$lock}"
        );
        $stmt->execute([$customerId, $businessUnitId, $idempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array{attempt_id:int,attempt_token:string,order_id:int,total_amount:float,reservations_created:int,idempotent_replay:bool}
     */
    private function formatCodReplayResult(array $attempt): array
    {
        if ($attempt['payment_channel'] !== 'cod' || $attempt['order_id'] === null) {
            throw new RuntimeException('This checkout request was already submitted.', self::ERR_CONFLICT);
        }
        return [
            'attempt_id' => (int)$attempt['attempt_id'],
            'attempt_token' => (string)$attempt['attempt_token'],
            'order_id' => (int)$attempt['order_id'],
            'total_amount' => (float)$attempt['expected_amount'],
            'reservations_created' => 0,
            'idempotent_replay' => true,
        ];
    }

    /**
     * @return array{
     *   attempt_id:int,
     *   attempt_token:string,
     *   hold_id:int,
     *   created_at:string,
     *   expires_at:string,
     *   total_amount:float,
     *   provider_configured:bool,
     *   redirect_url:string,
     *   idempotent_replay:bool
     * }
     */
    private function formatOnlineReplayResult(array $attempt): array
    {
        if ($attempt['payment_channel'] !== 'online') {
            throw new RuntimeException('This checkout request was already submitted.', self::ERR_CONFLICT);
        }
        $attemptId = (int)$attempt['attempt_id'];
        $redirectUrl = !empty($attempt['provider_checkout_url'])
            ? (string)$attempt['provider_checkout_url']
            : "/checkout/attempts/{$attemptId}";

        return [
            'attempt_id' => $attemptId,
            'attempt_token' => (string)$attempt['attempt_token'],
            'hold_id' => (int)($attempt['hold_id'] ?? 0),
            'created_at' => (string)($attempt['hold_created_at'] ?? $attempt['created_at']),
            'expires_at' => (string)($attempt['hold_expires_at'] ?? $attempt['created_at']),
            'total_amount' => (float)$attempt['expected_amount'],
            'provider_configured' => !empty($attempt['provider_checkout_url']),
            'redirect_url' => $redirectUrl,
            'idempotent_replay' => true,
        ];
    }

    private function validateCheckoutInput(
        int $businessUnitId,
        string $fulfillmentMethod,
        array $addr,
        string $notes
    ): void {
        if (!Units::find($businessUnitId)) {
            throw new RuntimeException('Selected business unit was not found.', self::ERR_NOT_FOUND);
        }
        if (!in_array($fulfillmentMethod, ['pickup', 'delivery'], true)) {
            throw new RuntimeException('Choose pickup or delivery.', self::ERR_UNPROCESSABLE);
        }
        if ($fulfillmentMethod === 'delivery') {
            $a1 = trim((string)($addr['address_line1'] ?? ''));
            $a2 = trim((string)($addr['address_line2'] ?? ''));
            $city = trim((string)($addr['city'] ?? ''));
            $prov = trim((string)($addr['province'] ?? ''));
            $zip = trim((string)($addr['postal_code'] ?? ''));
            if ($a1 === '' || mb_strlen($a1) > 255) {
                throw new RuntimeException('Enter a delivery address.', self::ERR_UNPROCESSABLE);
            }
            if ($city === '' || mb_strlen($city) > 100) {
                throw new RuntimeException('Enter a delivery city.', self::ERR_UNPROCESSABLE);
            }
            if ($zip === '' || mb_strlen($zip) > 20) {
                throw new RuntimeException('Enter a postal code.', self::ERR_UNPROCESSABLE);
            }
            if (mb_strlen($a2) > 255 || mb_strlen($prov) > 100) {
                throw new RuntimeException('An address field is too long.', self::ERR_UNPROCESSABLE);
            }
        }
        if (mb_strlen($notes) > 1000) {
            throw new RuntimeException('Notes are too long.', self::ERR_UNPROCESSABLE);
        }
    }

    private function normalizeIdempotencyKey(string $key): string
    {
        $clean = trim($key);
        if ($clean === '' || mb_strlen($clean) > 128) {
            throw new RuntimeException('Invalid or missing checkout submission token. Please refresh and try again.', self::ERR_UNPROCESSABLE);
        }
        return $clean;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function locateAttemptForProviderEvent(array $payload): ?array
    {
        if (!empty($payload['attempt_id'])) {
            $s = $this->db->prepare('SELECT * FROM checkout_attempt WHERE attempt_id = ? LIMIT 1');
            $s->execute([(int)$payload['attempt_id']]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!empty($payload['attempt_token'])) {
            $s = $this->db->prepare('SELECT * FROM checkout_attempt WHERE attempt_token = ? LIMIT 1');
            $s->execute([trim((string)$payload['attempt_token'])]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!empty($payload['provider_name']) && !empty($payload['provider_session_id'])) {
            $s = $this->db->prepare('SELECT * FROM checkout_attempt WHERE provider_name = ? AND provider_session_id = ? LIMIT 1');
            $s->execute([trim((string)$payload['provider_name']), trim((string)$payload['provider_session_id'])]);
            return $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return null;
    }

    /**
     * @return array<int,int>
     */
    private function loadHoldInventoryIdsForAttempt(int $attemptId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT ihi.inventory_id
             FROM inventory_hold_item ihi
             JOIN inventory_hold ih ON ih.hold_id = ihi.hold_id
             WHERE ih.attempt_id = ?
             ORDER BY ihi.inventory_id ASC"
        );
        $stmt->execute([$attemptId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int,int>
     */
    private function loadHoldInventoryIdsByHoldId(int $holdId): array
    {
        $stmt = $this->db->prepare(
            'SELECT DISTINCT inventory_id FROM inventory_hold_item WHERE hold_id = ? ORDER BY inventory_id ASC'
        );
        $stmt->execute([$holdId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function claimWebhookEvent(
        string $providerName,
        string $providerEventId,
        ?string $providerPaymentId,
        int $attemptId,
        string $eventType,
        ?string $rawPayload
    ): ?int {
        $nowUtc = $this->dbUtcNow();
        $ins = $this->db->prepare(
            "INSERT IGNORE INTO payment_webhook_event (
                provider_name, provider_event_id, provider_payment_id, attempt_id, event_type,
                processing_status, payload_json, created_at
             ) VALUES (?, ?, ?, ?, ?, 'received', ?, ?)"
        );
        $ins->execute([$providerName, $providerEventId, $providerPaymentId, $attemptId, $eventType, $rawPayload, $nowUtc]);
        if ($ins->rowCount() === 1) {
            return (int)$this->db->lastInsertId();
        }
        return null;
    }

    private function finalizeWebhookEvent(?int $eventId, string $status, string $notes): void
    {
        if ($eventId === null) {
            return;
        }
        $nowUtc = $this->dbUtcNow();
        $this->db->prepare(
            'UPDATE payment_webhook_event SET processing_status = ?, processing_notes = ?, processed_at = ? WHERE event_id = ?'
        )->execute([$status, mb_substr($notes, 0, 255), $nowUtc, $eventId]);
    }

    /**
     * @param array<int,array<string,mixed>> $holdItems
     * @param array<int,array<string,mixed>> $lockedInventory
     * @param array<int,int> $otherActiveHeld
     */
    private function checkHoldFulfillability(
        array $holdItems,
        int $businessUnitId,
        array $lockedInventory,
        array $otherActiveHeld
    ): ?string {
        $byInv = [];
        foreach ($holdItems as $hi) {
            if ((int)$hi['business_unit_id'] !== $businessUnitId) {
                return "Product '{$hi['product_name']}' no longer belongs to this business unit.";
            }
            if ((string)$hi['product_status'] !== 'active') {
                return "Product '{$hi['product_name']}' is no longer active.";
            }
            $invId = (int)$hi['inventory_id'];
            $byInv[$invId][] = $hi;
        }

        foreach ($byInv as $invId => $items) {
            $inv = $lockedInventory[$invId] ?? null;
            if (!$inv) {
                return "Inventory record #{$invId} is missing.";
            }
            $productName = (string)$items[0]['product_name'];
            $requiresBatch = !empty($items[0]['requires_batch_tracking']);
            $requestedQty = 0;
            foreach ($items as $it) {
                $requestedQty += (int)$it['quantity'];
            }

            $activeHeld = (int)($otherActiveHeld[$invId] ?? 0);
            $available = (((int)$inv['on_hand'] + (int)$inv['in_transit']) - (int)$inv['committed']) - $activeHeld;
            if ($available < $requestedQty) {
                $displayAvail = max(0, $available);
                return "Insufficient stock after hold expiry for '{$productName}' (available: {$displayAvail}, paid qty: {$requestedQty}).";
            }

            if ($requiresBatch) {
                $batchPool = InventoryBatch::lockEligibleFefoBatchesForReservation($this->db, $invId);
                $batchUnreserved = 0;
                foreach ($batchPool as $batch) {
                    $batchUnreserved += (int)$batch['unreserved_quantity'];
                }
                $effectiveBatchAvail = $batchUnreserved - $activeHeld;
                if ($effectiveBatchAvail < $requestedQty) {
                    $displayBatchAvail = max(0, $effectiveBatchAvail);
                    return "Insufficient non-expired batch stock after hold expiry for '{$productName}' (available: {$displayBatchAvail}, paid qty: {$requestedQty}).";
                }
            }
        }

        return null;
    }

    private function recordReconciliationRequired(
        int $attemptId,
        int $holdId,
        int $businessUnitId,
        int $customerId,
        string $providerName,
        string $providerPaymentId,
        float $capturedAmount,
        string $paymentMethod,
        bool $markHoldExpired,
        string $reason
    ): int {
        $nowUtc = $this->dbUtcNow();
        if ($markHoldExpired) {
            $this->db->prepare(
                "UPDATE inventory_hold
                 SET hold_status = 'expired', released_at = ?, release_reason = 'expired_unfulfillable_capture'
                 WHERE hold_id = ? AND hold_status = 'active'"
            )->execute([$nowUtc, $holdId]);
        }

        $payStmt = $this->db->prepare(
            "INSERT INTO payment (order_id, attempt_id, payment_type, amount, payment_date, payment_method, payment_status, provider_name, provider_payment_id)
             VALUES (NULL, ?, 'payment', ?, NOW(), ?, 'completed', ?, ?)"
        );
        $payStmt->execute([$attemptId, $capturedAmount, $paymentMethod, $providerName, $providerPaymentId]);
        $paymentId = (int)$this->db->lastInsertId();

        $this->db->prepare(
            "UPDATE checkout_attempt
             SET attempt_status = 'reconciliation_required', provider_name = ?, provider_payment_id = ?,
                 failure_reason = ?, updated_at = ?
             WHERE attempt_id = ?"
        )->execute([$providerName, $providerPaymentId, mb_substr($reason, 0, 255), $nowUtc, $attemptId]);

        $recStmt = $this->db->prepare(
            "INSERT INTO payment_reconciliation (
                attempt_id, hold_id, payment_id, business_unit_id, customer_id,
                provider_name, provider_payment_id, captured_amount, reconciliation_status, reason,
                created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_review', ?, ?, ?)"
        );
        $recStmt->execute([
            $attemptId,
            $holdId,
            $paymentId,
            $businessUnitId,
            $customerId,
            $providerName,
            $providerPaymentId,
            $capturedAmount,
            mb_substr($reason, 0, 255),
            $nowUtc,
            $nowUtc,
        ]);
        $reconId = (int)$this->db->lastInsertId();

        $actorUserId = $this->lookupCustomerUserAccountId($customerId);
        Audit::record(
            $this->db,
            'customer.checkout.capture_reconciliation_required',
            "Captured payment {$providerPaymentId} for attempt #{$attemptId} could not be auto-fulfilled and was queued for reconciliation (#{$reconId}): {$reason}",
            $businessUnitId,
            $actorUserId,
            null,
            null,
            "reconciliation_status:pending_review; payment_id:{$paymentId}",
            'payment_reconciliation',
            $reconId
        );

        return $reconId;
    }

    private function findReconciliationId(int $attemptId): ?int
    {
        $stmt = $this->db->prepare('SELECT reconciliation_id FROM payment_reconciliation WHERE attempt_id = ? LIMIT 1');
        $stmt->execute([$attemptId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * @param array<int,array<string,mixed>> $holdItems
     */
    private function clearOrderedCartItems(int $cartId, array $holdItems): void
    {
        $qtyByBp = [];
        foreach ($holdItems as $hi) {
            $bpId = (int)$hi['business_product_id'];
            $qtyByBp[$bpId] = ($qtyByBp[$bpId] ?? 0) + (int)$hi['quantity'];
        }

        $selStmt = $this->db->prepare('SELECT cart_item_id, quantity FROM cart_item WHERE cart_id = ? AND business_product_id = ? FOR UPDATE');
        $delStmt = $this->db->prepare('DELETE FROM cart_item WHERE cart_item_id = ?');
        $updStmt = $this->db->prepare('UPDATE cart_item SET quantity = ? WHERE cart_item_id = ?');

        foreach ($qtyByBp as $bpId => $qtyPurchased) {
            $selStmt->execute([$cartId, $bpId]);
            $row = $selStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                continue;
            }
            $currentQty = (int)$row['quantity'];
            $ciId = (int)$row['cart_item_id'];
            if ($currentQty <= $qtyPurchased) {
                $delStmt->execute([$ciId]);
            } else {
                $updStmt->execute([$currentQty - $qtyPurchased, $ciId]);
            }
        }
    }

    private function lookupCustomerUserAccountId(int $customerId): ?int
    {
        $stmt = $this->db->prepare('SELECT user_account_id FROM customer WHERE customer_id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    private function dbUtcNow(): string
    {
        return (string)$this->db->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
    }

    private function assertTopLevelTransaction(string $operation): void
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException("{$operation} must own a top-level database transaction.", self::ERR_CONFLICT);
        }
    }
}
