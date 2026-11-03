<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Cart;
use App\Models\Units;

final class OrderController extends Controller
{
    public function checkout(int $bu): void
    {
        [$uid, $cid] = $this->requireCustomer();
        $unit = Units::find($bu) ?? $this->notFound();
        $items = Cart::items(Cart::id($cid, $bu));
        if (!$items) {
            Session::flash('info', 'Your cart is empty.');
            $this->redirect("/cart/$bu");
        }
        $c = $this->db->prepare('SELECT address_line1, address_line2, city, province, postal_code FROM customer WHERE customer_id=?');
        $c->execute([$cid]);
        $this->render('shop/checkout', ['title' => 'Checkout', 'unit' => $unit, 'items' => $items, 'total' => Cart::total($items), 'addr' => $c->fetch() ?: [], 'errors' => []]);
    }

    public function place(int $bu): void
    {
        [, $cid] = $this->requireCustomer();
        CSRF::check();
        $unit = Units::find($bu) ?? $this->notFound();
        $method = $this->post('fulfillment_method');
        $addr = [];
        foreach (['address_line1', 'address_line2', 'city', 'province', 'postal_code'] as $k) {
            $addr[$k] = $this->post($k);
        }
        $errors = [];
        if (!in_array($method, ['pickup', 'delivery'], true)) {
            $errors[] = 'Choose pickup or delivery.';
        }
        if ($method === 'delivery') {
            if ($addr['address_line1'] === '' || mb_strlen($addr['address_line1']) > 255) $errors[] = 'Enter a delivery address.';
            if ($addr['city'] === '' || mb_strlen($addr['city']) > 100) $errors[] = 'Enter a delivery city.';
            if ($addr['postal_code'] === '' || mb_strlen($addr['postal_code']) > 20) $errors[] = 'Enter a postal code.';
            if (mb_strlen($addr['address_line2']) > 255 || mb_strlen($addr['province']) > 100) $errors[] = 'An address field is too long.';
        }
        $notes = $this->post('notes');
        if (mb_strlen($notes) > 1000) $errors[] = 'Notes are too long.';

        $orderId = 0;
        if (!$errors) {
            try {
                $this->db->beginTransaction();
                $cartId = Cart::id($cid, $bu);
                // Prices and stock are read from the DB inside the transaction; nothing comes from the browser.
                $s = $this->db->prepare('SELECT ci.cart_item_id, ci.quantity, bp.business_product_id, bp.business_unit_id, bp.selling_price, bp.product_status,
                        p.product_name, i.inventory_id, COALESCE(i.on_hand,0) AS on_hand, COALESCE(i.committed,0) AS committed
                    FROM cart_item ci
                    JOIN business_product bp ON bp.business_product_id=ci.business_product_id
                    JOIN product p ON p.product_id=bp.product_id
                    LEFT JOIN inventory i ON i.business_product_id=bp.business_product_id
                    WHERE ci.cart_id=? FOR UPDATE');
                $s->execute([$cartId]);
                $lines = $s->fetchAll();
                if (!$lines) {
                    $errors[] = 'Your cart is empty.';
                }
                $total = 0.0;
                foreach ($lines as $l) {
                    if ((int)$l['business_unit_id'] !== $bu) {
                        $errors[] = $l['product_name'] . ' does not belong to this shop and cannot be ordered here.';
                    } elseif ($l['product_status'] !== 'active') {
                        $errors[] = $l['product_name'] . ' is no longer available.';
                    } elseif ((int)$l['quantity'] < 1 || (int)$l['quantity'] > Cart::MAX_QTY) {
                        $errors[] = 'Invalid quantity for ' . $l['product_name'] . '.';
                    } elseif ($l['inventory_id'] === null || (int)$l['on_hand'] - (int)$l['committed'] < (int)$l['quantity']) {
                        $errors[] = 'Not enough stock for ' . $l['product_name'] . ' (' . max(0, (int)$l['on_hand'] - (int)$l['committed']) . ' available).';
                    }
                    $total += (float)$l['selling_price'] * (int)$l['quantity'];
                }
                if ($errors) {
                    $this->db->rollBack();
                } else {
                    $delivery = $method === 'delivery';
                    $n = fn($v) => $v === '' ? null : $v;
                    $this->db->prepare('INSERT INTO customer_order (customer_id, business_unit_id, branch_id, fulfillment_method, order_status, total_amount,
                            delivery_address_line1, delivery_address_line2, delivery_city, delivery_province, delivery_postal_code, notes)
                        VALUES (?,?,?,?,"pending",?,?,?,?,?,?,?)')
                        ->execute([$cid, $bu, Units::mainBranchId($bu), $method, round($total, 2),
                            $delivery ? $addr['address_line1'] : null, $delivery ? $n($addr['address_line2']) : null, $delivery ? $addr['city'] : null,
                            $delivery ? $n($addr['province']) : null, $delivery ? $addr['postal_code'] : null, $n($notes)]);
                    $orderId = (int)$this->db->lastInsertId();
                    $ins = $this->db->prepare('INSERT INTO order_item (order_id, business_product_id, quantity, unit_price) VALUES (?,?,?,?)');
                    foreach ($lines as $l) {
                        $ins->execute([$orderId, $l['business_product_id'], $l['quantity'], $l['selling_price']]);
                    }
                    $this->db->prepare('DELETE FROM cart_item WHERE cart_id=?')->execute([$cartId]);
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e; // handled globally: generic error, no success message, nothing saved
            }
        }

        if ($errors) {
            http_response_code(422);
            $items = Cart::items(Cart::id($cid, $bu));
            $this->render('shop/checkout', ['title' => 'Checkout', 'unit' => $unit, 'items' => $items, 'total' => Cart::total($items), 'addr' => $addr, 'errors' => $errors]);
            return;
        }
        Session::flash('success', 'Order #' . $orderId . ' submitted. Status: pending. Stock has not been reserved yet.');
        $this->redirect("/orders/$orderId");
    }

    public function index(): void
    {
        [, $cid] = $this->requireCustomer();
        $s = $this->db->prepare('SELECT o.order_id, o.order_date, o.fulfillment_method, o.order_status, o.total_amount, bu.business_unit_name
            FROM customer_order o JOIN business_unit bu ON bu.business_unit_id=o.business_unit_id WHERE o.customer_id=? ORDER BY o.order_date DESC, o.order_id DESC');
        $s->execute([$cid]);
        $this->render('orders/index', ['title' => 'My orders', 'orders' => $s->fetchAll()]);
    }

    public function show(int $id): void
    {
        [, $cid] = $this->requireCustomer();
        $s = $this->db->prepare('SELECT o.*, bu.business_unit_name FROM customer_order o JOIN business_unit bu ON bu.business_unit_id=o.business_unit_id WHERE o.order_id=? AND o.customer_id=?');
        $s->execute([$id, $cid]);
        $o = $s->fetch() ?: $this->notFound();
        $i = $this->db->prepare('SELECT oi.quantity, oi.unit_price, p.product_name FROM order_item oi JOIN business_product bp ON bp.business_product_id=oi.business_product_id JOIN product p ON p.product_id=bp.product_id WHERE oi.order_id=? ORDER BY oi.order_item_id');
        $i->execute([$id]);
        $this->render('orders/show', ['title' => 'Order #' . $id, 'o' => $o, 'lines' => $i->fetchAll()]);
    }
}

