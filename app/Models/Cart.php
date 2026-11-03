<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

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

    /** Cart lines with live price/availability from the database. */
    public static function items(int $cartId): array
    {
        $s = Database::pdo()->prepare('SELECT ci.cart_item_id, ci.quantity, bp.business_product_id, bp.business_unit_id, bp.selling_price, bp.product_status,
                p.product_name, i.on_hand, i.committed, GREATEST(COALESCE(i.on_hand,0) - COALESCE(i.committed,0), 0) AS available
            FROM cart_item ci
            JOIN business_product bp ON bp.business_product_id = ci.business_product_id
            JOIN product p ON p.product_id = bp.product_id
            LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
            WHERE ci.cart_id=? ORDER BY ci.cart_item_id');
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

