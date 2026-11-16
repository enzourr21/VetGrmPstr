<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Cart;
use App\Models\Units;
use App\Services\CheckoutHoldService;
use RuntimeException;

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
        $checkoutService = new CheckoutHoldService($this->db);

        $this->render('shop/checkout', [
            'title' => 'Checkout',
            'unit' => $unit,
            'items' => $items,
            'total' => Cart::total($items),
            'addr' => $c->fetch() ?: [],
            'errors' => [],
            'paymentSettings' => $checkoutService->getUnitPaymentSettings($bu),
            'activeHold' => $checkoutService->getActiveHoldForCustomerUnit($cid, $bu),
            'checkoutToken' => bin2hex(random_bytes(16)),
            'minStockThreshold' => CheckoutHoldService::MIN_ONLINE_STOCK_THRESHOLD,
            'holdMinutes' => (int)(CheckoutHoldService::HOLD_DURATION_SECONDS / 60),
            'old' => [],
        ]);
    }

    public function place(int $bu): void
    {
        [$uid, $cid] = $this->requireCustomer();
        CSRF::check();
        $unit = Units::find($bu) ?? $this->notFound();

        $checkoutService = new CheckoutHoldService($this->db);
        $paymentSettings = $checkoutService->getUnitPaymentSettings($bu);

        $method = $this->post('fulfillment_method');
        $paymentMethod = strtolower($this->post('payment_method'));
        $checkoutToken = $this->post('checkout_token');
        $addr = [];
        foreach (['address_line1', 'address_line2', 'city', 'province', 'postal_code'] as $k) {
            $addr[$k] = $this->post($k);
        }
        $notes = $this->post('notes');

        $errors = [];
        if (!in_array($method, ['pickup', 'delivery'], true)) {
            $errors[] = 'Choose pickup or delivery.';
        }
        if (!in_array($paymentMethod, ['cod', 'online'], true)) {
            $errors[] = 'Choose a valid payment method.';
        } elseif ($paymentMethod === 'cod' && !$paymentSettings['cod_enabled']) {
            $errors[] = 'Cash on Delivery is not enabled for this shop.';
        }
        if ($method === 'delivery') {
            if ($addr['address_line1'] === '' || mb_strlen($addr['address_line1']) > 255) $errors[] = 'Enter a delivery address.';
            if ($addr['city'] === '' || mb_strlen($addr['city']) > 100) $errors[] = 'Enter a delivery city.';
            if ($addr['postal_code'] === '' || mb_strlen($addr['postal_code']) > 20) $errors[] = 'Enter a postal code.';
            if (mb_strlen($addr['address_line2']) > 255 || mb_strlen($addr['province']) > 100) $errors[] = 'An address field is too long.';
        }
        if (mb_strlen($notes) > 1000) {
            $errors[] = 'Notes are too long.';
        }
        if ($checkoutToken === '' || mb_strlen($checkoutToken) > 128) {
            $errors[] = 'Missing checkout submission token. Please try submitting again.';
        }

        $httpErrorCode = 422;
        if (!$errors) {
            try {
                if ($paymentMethod === 'cod') {
                    $result = $checkoutService->placeCodOrder(
                        $cid,
                        $bu,
                        $uid,
                        $method,
                        $addr,
                        $notes,
                        $checkoutToken
                    );
                    $orderId = (int)$result['order_id'];
                    Session::flash('success', "Order #{$orderId} confirmed (Cash on Delivery). Stock has been reserved.");
                    $this->redirect("/orders/{$orderId}");
                }

                $result = $checkoutService->initiateOnlineCheckoutHold(
                    $cid,
                    $bu,
                    $uid,
                    $method,
                    $addr,
                    $notes,
                    $checkoutToken
                );
                if (empty($result['idempotent_replay'])) {
                    Session::flash('info', 'Temporary 3-minute stock hold created while awaiting online payment verification.');
                }
                $this->redirect((string)$result['redirect_url']);
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
                $code = $e->getCode();
                if ($code === CheckoutHoldService::ERR_CONFLICT) {
                    $httpErrorCode = 409;
                }
            }
        }

        http_response_code($httpErrorCode);
        $items = Cart::items(Cart::id($cid, $bu));
        $this->render('shop/checkout', [
            'title' => 'Checkout',
            'unit' => $unit,
            'items' => $items,
            'total' => Cart::total($items),
            'addr' => $addr,
            'errors' => $errors,
            'paymentSettings' => $paymentSettings,
            'activeHold' => $checkoutService->getActiveHoldForCustomerUnit($cid, $bu),
            'checkoutToken' => $checkoutToken !== '' ? $checkoutToken : bin2hex(random_bytes(16)),
            'minStockThreshold' => CheckoutHoldService::MIN_ONLINE_STOCK_THRESHOLD,
            'holdMinutes' => (int)(CheckoutHoldService::HOLD_DURATION_SECONDS / 60),
            'old' => [
                'fulfillment_method' => $method,
                'payment_method' => $paymentMethod,
                'notes' => $notes,
            ],
        ]);
    }

    public function checkoutSession(int $attemptId): void
    {
        [, $cid] = $this->requireCustomer();
        $checkoutService = new CheckoutHoldService($this->db);
        $attempt = $checkoutService->getAttemptDetailsForCustomer($attemptId, $cid);
        if (!$attempt) {
            $this->notFound();
        }

        if ($attempt['attempt_status'] === 'completed' && $attempt['order_id'] !== null) {
            $this->redirect('/orders/' . (int)$attempt['order_id']);
        }

        $this->render('shop/checkout_session', [
            'title' => 'Online Checkout Hold #' . $attemptId,
            'attempt' => $attempt,
            'items' => $attempt['items'],
        ]);
    }

    public function cancelCheckoutHold(int $attemptId): void
    {
        [$uid, $cid] = $this->requireCustomer();
        CSRF::check();
        $checkoutService = new CheckoutHoldService($this->db);

        try {
            $res = $checkoutService->cancelActiveHoldByCustomer($attemptId, $cid, $uid);
            if ($res['released']) {
                Session::flash('info', 'Your online checkout hold has been cancelled and the held stock was released.');
            } else {
                Session::flash('info', 'This checkout hold is no longer active.');
            }
            $this->redirect('/checkout/' . (int)$res['business_unit_id']);
        } catch (RuntimeException $e) {
            if ($e->getCode() === CheckoutHoldService::ERR_NOT_FOUND) {
                $this->notFound();
            }
            Session::flash('error', $e->getMessage());
            $this->redirect('/shop');
        }
    }

    public function index(): void
    {
        [, $cid] = $this->requireCustomer();
        $s = $this->db->prepare(
            "SELECT o.order_id, o.order_date, o.fulfillment_method, o.order_status, o.total_amount,
                    bu.business_unit_name,
                    p.payment_method,
                    CASE WHEN COALESCE(ps.has_refunded, 0) = 1 THEN 'refunded' ELSE p.payment_status END AS payment_status
             FROM customer_order o
             JOIN business_unit bu ON bu.business_unit_id=o.business_unit_id
             {$this->paymentSummaryJoinSql()}
             WHERE o.customer_id=?
             ORDER BY o.order_date DESC, o.order_id DESC"
        );
        $s->execute([$cid]);
        $this->render('orders/index', ['title' => 'My orders', 'orders' => $s->fetchAll()]);
    }

    public function show(int $id): void
    {
        [, $cid] = $this->requireCustomer();
        $s = $this->db->prepare(
            "SELECT o.*, bu.business_unit_name,
                    p.payment_method,
                    CASE WHEN COALESCE(ps.has_refunded, 0) = 1 THEN 'refunded' ELSE p.payment_status END AS payment_status
             FROM customer_order o
             JOIN business_unit bu ON bu.business_unit_id=o.business_unit_id
             {$this->paymentSummaryJoinSql()}
             WHERE o.order_id=? AND o.customer_id=?"
        );
        $s->execute([$id, $cid]);
        $o = $s->fetch() ?: $this->notFound();
        $i = $this->db->prepare('SELECT oi.quantity, oi.unit_price, p.product_name FROM order_item oi JOIN business_product bp ON bp.business_product_id=oi.business_product_id JOIN product p ON p.product_id=bp.product_id WHERE oi.order_id=? ORDER BY oi.order_item_id');
        $i->execute([$id]);
        $this->render('orders/show', ['title' => 'Order #' . $id, 'o' => $o, 'lines' => $i->fetchAll()]);
    }

    private function paymentSummaryJoinSql(): string
    {
        return "LEFT JOIN (
                    SELECT p_sub.order_id,
                           MAX(CASE WHEN (p_sub.payment_type = 'refund' AND p_sub.payment_status = 'completed') OR p_sub.payment_status = 'refunded' THEN 1 ELSE 0 END) AS has_refunded,
                           MAX(CASE WHEN p_sub.payment_type = 'payment' AND p_sub.payment_status = 'completed' THEN p_sub.payment_id END) AS latest_completed_payment_id,
                           MAX(CASE WHEN p_sub.payment_type = 'payment' THEN p_sub.payment_id END) AS latest_payment_id
                    FROM payment p_sub
                    WHERE p_sub.order_id IS NOT NULL
                    GROUP BY p_sub.order_id
                ) ps ON ps.order_id = o.order_id
                LEFT JOIN payment p ON p.payment_id = COALESCE(ps.latest_completed_payment_id, ps.latest_payment_id)";
    }
}
