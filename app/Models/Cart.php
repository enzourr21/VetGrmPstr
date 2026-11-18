<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Session;
use App\Services\CheckoutHoldService;

final class Cart
{
    public const MAX_QTY = 99;

    /** One persistent cart per (customer, business unit). */
    public static function id(int $customerId, int $unitId): int
    {
        $db = Database::pdo();
        $db->prepare('INSERT IGNORE INTO cart (customer_id, business_unit_id) VALUES (?, ?)')->execute([$customerId, $unitId]);
        $s = $db->prepare('SELECT cart_id FROM cart WHERE customer_id=? AND business_unit_id=?');
        $s->execute([$customerId, $unitId]);
        return (int)$s->fetchColumn();
    }

    /** Add or increment a product quantity inside a customer's unit-scoped cart. */
    public static function addItem(int $customerId, int $unitId, int $bpId, int $qty): void
    {
        $cartId = self::id($customerId, $unitId);
        Database::pdo()->prepare(
            'INSERT INTO cart_item (cart_id, business_product_id, quantity) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE quantity = LEAST(' . self::MAX_QTY . ', quantity + VALUES(quantity))'
        )->execute([$cartId, $bpId, $qty]);
    }

    /** Stage a guest cart item in the session, isolated per business unit. */
    public static function addGuestItem(int $unitId, int $bpId, int $qty): void
    {
        $guestCarts = Session::get('guest_carts', []);
        if (!is_array($guestCarts)) {
            $guestCarts = [];
        }
        $unitCart = isset($guestCarts[$unitId]) && is_array($guestCarts[$unitId]) ? $guestCarts[$unitId] : [];
        $currentQty = (int)($unitCart[$bpId] ?? 0);
        $unitCart[$bpId] = min(self::MAX_QTY, max(1, $currentQty + $qty));
        $guestCarts[$unitId] = $unitCart;
        Session::set('guest_carts', $guestCarts);
    }

    /** Item counts per unit for unauthenticated guest sessions. */
    public static function guestCountsByUnit(): array
    {
        $guestCarts = Session::get('guest_carts', []);
        if (!is_array($guestCarts)) {
            return [];
        }
        $counts = [];
        foreach ($guestCarts as $unitId => $items) {
            if (!is_array($items)) {
                continue;
            }
            $sum = 0;
            foreach ($items as $qty) {
                $sum += max(0, (int)$qty);
            }
            if ($sum > 0) {
                $counts[(int)$unitId] = $sum;
            }
        }
        return $counts;
    }

    /** Merge any session-staged guest unit carts into the signed-in customer's DB carts. */
    public static function mergeGuestCarts(int $customerId): void
    {
        $guestCarts = Session::get('guest_carts', []);
        if (!is_array($guestCarts) || !$guestCarts) {
            Session::forget('guest_carts');
            return;
        }

        $check = Database::pdo()->prepare(
            "SELECT bp.business_product_id
             FROM business_product bp
             JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
             WHERE bp.business_product_id = ?
               AND bp.business_unit_id = ?
               AND bp.product_status = 'active'
               AND bu.business_unit_status = 'active'"
        );

        foreach ($guestCarts as $unitId => $items) {
            $bu = (int)$unitId;
            if ($bu <= 0 || !is_array($items)) {
                continue;
            }
            foreach ($items as $bpIdRaw => $qtyRaw) {
                $bpId = (int)$bpIdRaw;
                $qty = min(self::MAX_QTY, max(0, (int)$qtyRaw));
                if ($bpId <= 0 || $qty <= 0) {
                    continue;
                }
                $check->execute([$bpId, $bu]);
                if ($check->fetchColumn()) {
                    self::addItem($customerId, $bu, $bpId, $qty);
                }
            }
        }

        Session::forget('guest_carts');
    }

    /** Cart lines with live price/availability from the database. */
    public static function items(int $cartId): array
    {
        $availSql = CheckoutHoldService::availableStockSql('i', 'bp');
        $s = Database::pdo()->prepare("SELECT ci.cart_item_id, ci.quantity, bp.business_product_id, bp.business_unit_id, bp.selling_price, bp.product_status,
                p.product_name, COALESCE(i.on_hand,0) AS on_hand, COALESCE(i.committed,0) AS committed, COALESCE(i.in_transit,0) AS in_transit,
                {$availSql} AS available
            FROM cart_item ci
            JOIN business_product bp ON bp.business_product_id = ci.business_product_id
            JOIN product p ON p.product_id = bp.product_id
            LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
            WHERE ci.cart_id=? ORDER BY ci.cart_item_id");
        $s->execute([$cartId]);
        return $s->fetchAll();
    }

    public static function total(array $items): float
    {
        $t = 0.0;
        foreach ($items as $i) {
            $t += (float)$i['selling_price'] * (int)$i['quantity'];
        }
        return round($t, 2);
    }

    /** item counts per unit for the nav/shop landing */
    public static function countsByUnit(int $customerId): array
    {
        $s = Database::pdo()->prepare('SELECT c.business_unit_id, COALESCE(SUM(ci.quantity),0) AS n FROM cart c LEFT JOIN cart_item ci ON ci.cart_id=c.cart_id WHERE c.customer_id=? GROUP BY c.business_unit_id');
        $s->execute([$customerId]);
        return array_column($s->fetchAll(), 'n', 'business_unit_id');
    }
}

