<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Cart;
use App\Models\Units;
use App\Services\CheckoutHoldService;

final class CartController extends Controller
{
    public function show(int $bu): void
    {
        [, $cid] = $this->requireCustomer();
        $unit = Units::find($bu) ?? $this->notFound();
        $items = Cart::items(Cart::id($cid, $bu));
        $this->render('shop/cart', ['title' => 'Cart', 'unit' => $unit, 'items' => $items, 'total' => Cart::total($items)]);
    }

    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || strtolower($xhr) === 'xmlhttprequest';
    }

    private function jsonResponse(array $payload, int $status = 200): never
    {
        $payload['csrf_token'] = CSRF::token();
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload);
        exit;
    }

    private function qty(): int
    {
        $q = $this->post('quantity', '1');
        return (ctype_digit($q) && (int)$q >= 1 && (int)$q <= Cart::MAX_QTY) ? (int)$q : 0;
    }

    public function add(int $bu): void
    {
        $wantsJson = $this->wantsJson();
        if (!$wantsJson) {
            [, $cid] = $this->requireCustomer();
        } else {
            $cid = Session::get('user_id') ? $this->requireCustomer()[1] : null;
        }
        CSRF::check();
        $unit = Units::find($bu);
        if (!$unit) {
            if ($wantsJson) {
                $this->jsonResponse(['ok' => false, 'error' => 'Business unit not found.'], 404);
            }
            $this->notFound();
        }
        $bpId = (int)$this->post('business_product_id');
        $qty = $this->qty();
        if ($qty === 0) {
            $msg = 'Enter a quantity between 1 and ' . Cart::MAX_QTY . '.';
            if ($wantsJson) {
                $this->jsonResponse(['ok' => false, 'error' => $msg], 422);
            }
            Session::flash('error', $msg);
            $this->redirect("/shop/$bu");
        }
        // product must be active AND belong to this business unit
        $availSql = CheckoutHoldService::availableStockSql('i', 'bp');
        $s = $this->db->prepare("SELECT {$availSql} AS available
            FROM business_product bp LEFT JOIN inventory i ON i.business_product_id=bp.business_product_id
            WHERE bp.business_product_id=? AND bp.business_unit_id=? AND bp.product_status='active'");
        $s->execute([$bpId, $bu]);
        $row = $s->fetch();
        if (!$row) {
            $msg = 'That product is not available in this shop.';
            if ($wantsJson) {
                $this->jsonResponse(['ok' => false, 'error' => $msg], 422);
            }
            Session::flash('error', $msg);
            $this->redirect("/shop/$bu");
        }
        if ((int)$row['available'] < 1) {
            $msg = 'That product is currently out of stock.';
            if ($wantsJson) {
                $this->jsonResponse(['ok' => false, 'error' => $msg], 422);
            }
            Session::flash('error', $msg);
            $this->redirect("/shop/$bu");
        }
        if ($cid !== null) {
            Cart::addItem((int)$cid, $bu, $bpId, $qty);
            $counts = Cart::countsByUnit((int)$cid);
        } else {
            Cart::addGuestItem($bu, $bpId, $qty);
            $counts = Cart::guestCountsByUnit();
        }
        if ($wantsJson) {
            $this->jsonResponse([
                'ok' => true,
                'guest' => $cid === null,
                'business_unit_id' => $bu,
                'count' => (int)($counts[$bu] ?? 0),
                'counts' => $counts,
                'message' => 'Added to your ' . $unit['business_unit_name'] . ' cart.',
            ]);
        }
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

