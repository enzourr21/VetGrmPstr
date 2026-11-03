<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Cart;
use App\Models\Units;

final class CartController extends Controller
{
    public function show(int $bu): void
    {
        [, $cid] = $this->requireCustomer();
        $unit = Units::find($bu) ?? $this->notFound();
        $items = Cart::items(Cart::id($cid, $bu));
        $this->render('shop/cart', ['title' => 'Cart', 'unit' => $unit, 'items' => $items, 'total' => Cart::total($items)]);
    }

    private function qty(): int
    {
        $q = $this->post('quantity', '1');
        return (ctype_digit($q) && (int)$q >= 1 && (int)$q <= Cart::MAX_QTY) ? (int)$q : 0;
    }

    public function add(int $bu): void
    {
        [, $cid] = $this->requireCustomer();
        CSRF::check();
        Units::find($bu) ?? $this->notFound();
        $bpId = (int)$this->post('business_product_id');
        $qty = $this->qty();
        if ($qty === 0) {
            Session::flash('error', 'Enter a quantity between 1 and ' . Cart::MAX_QTY . '.');
            $this->redirect("/shop/$bu");
        }
        // product must be active AND belong to this business unit
        $s = $this->db->prepare('SELECT GREATEST(COALESCE(i.on_hand,0)-COALESCE(i.committed,0),0) AS available
            FROM business_product bp LEFT JOIN inventory i ON i.business_product_id=bp.business_product_id
            WHERE bp.business_product_id=? AND bp.business_unit_id=? AND bp.product_status="active"');
        $s->execute([$bpId, $bu]);
        $row = $s->fetch();
        if (!$row) {
            Session::flash('error', 'That product is not available in this shop.');
            $this->redirect("/shop/$bu");
        }
        if ((int)$row['available'] < 1) {
            Session::flash('error', 'That product is currently out of stock.');
            $this->redirect("/shop/$bu");
        }
        $cartId = Cart::id($cid, $bu);
        $this->db->prepare('INSERT INTO cart_item (cart_id, business_product_id, quantity) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE quantity = LEAST(' . Cart::MAX_QTY . ', quantity + VALUES(quantity))')->execute([$cartId, $bpId, $qty]);
        Session::flash('success', 'Added to your cart. Items in a cart are not reserved.');
        $this->redirect("/cart/$bu");
    }

    public function update(int $bu): void
    {
        [, $cid] = $this->requireCustomer();
        CSRF::check();
        $qty = $this->qty();
        if ($qty === 0) {
            Session::flash('error', 'Enter a quantity between 1 and ' . Cart::MAX_QTY . '. Use Remove to delete an item.');
            $this->redirect("/cart/$bu");
        }
        $u = $this->db->prepare('UPDATE cart_item ci JOIN cart c ON c.cart_id=ci.cart_id SET ci.quantity=?
            WHERE ci.cart_item_id=? AND c.customer_id=? AND c.business_unit_id=?');
        $u->execute([$qty, (int)$this->post('cart_item_id'), $cid, $bu]);
        Session::flash('success', 'Cart updated.');
        $this->redirect("/cart/$bu");
    }

    public function remove(int $bu): void
    {
        [, $cid] = $this->requireCustomer();
        CSRF::check();
        $d = $this->db->prepare('DELETE ci FROM cart_item ci JOIN cart c ON c.cart_id=ci.cart_id
            WHERE ci.cart_item_id=? AND c.customer_id=? AND c.business_unit_id=?');
        $d->execute([(int)$this->post('cart_item_id'), $cid, $bu]);
        Session::flash('success', 'Item removed.');
        $this->redirect("/cart/$bu");
    }
}

