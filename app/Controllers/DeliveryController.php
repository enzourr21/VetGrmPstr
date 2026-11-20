<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\Units;
use App\Services\OrderPaymentService;
use App\Services\OrderReservationService;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class DeliveryController extends Controller
{
    private const PET_STORE_UNIT_ID = 1;

    private function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /**
     * Current calendar date in Philippine Standard Time (Asia/Manila) for delivery scheduling rules.
     */
    private function philippineTodayDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    }

    /**
     * Authorize Delivery Portal viewer:
     * - Permits active Pet Store employee with DELIVERY_PERSONNEL role (operational courier).
     * - Permits Super Administrator in read-only oversight mode if not holding DELIVERY_PERSONNEL.
     *
     * @return array{uid:int, emp_id:int, unit_id:int, is_courier:bool, is_super:bool}
     */
    private function requireDeliveryViewer(): array
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/delivery/orders');
            $this->redirect('/login');
        }

        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');

        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated employee session is required.');
        }

        $this->requireInvitationAcceptedForSession(
            (int)$uid,
            'Your employee account must complete invitation activation before accessing the delivery workspace.'
        );

        $isSuper = $this->isSuperAdmin();

        $stmt = $this->db->prepare(
            "SELECT e.employee_id
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id
                              AND e.employee_status = 'active'
                              AND e.business_unit_id = ?
             JOIN account_role ar ON ar.user_account_id = ua.user_account_id
                                  AND ar.business_unit_id = e.business_unit_id
             JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'DELIVERY_PERSONNEL'
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id AND bu.business_unit_status = 'active'
             WHERE ua.user_account_id = ? AND ua.account_status = 'active'
             LIMIT 1"
        );
        $stmt->execute([self::PET_STORE_UNIT_ID, (int)$uid]);
        $empId = $stmt->fetchColumn();

        if ($empId !== false && (int)$empId > 0) {
            return [
                'uid' => (int)$uid,
                'emp_id' => (int)$empId,
                'unit_id' => self::PET_STORE_UNIT_ID,
                'is_courier' => true,
                'is_super' => $isSuper,
            ];
        }

        if ($isSuper) {
            return [
                'uid' => (int)$uid,
                'emp_id' => 0,
                'unit_id' => self::PET_STORE_UNIT_ID,
                'is_courier' => false,
                'is_super' => true,
            ];
        }

        $this->forbidden('Access denied: Pet Store Delivery Personnel privileges are required.');
    }

    /**
     * Authorize operational courier mutations (dispatch, complete, fail).
     * Never grants mutation rights solely via Super Administrator status.
     *
     * @return array{uid:int, emp_id:int, unit_id:int, is_courier:bool, is_super:bool}
     */
    private function requireCourierOperator(): array
    {
        $auth = $this->requireDeliveryViewer();
        if (!$auth['is_courier'] || $auth['emp_id'] <= 0) {
            $this->forbidden('Operational courier actions require an active Pet Store Delivery Personnel role assignment.');
        }
        return $auth;
    }

    /**
     * Evaluate payment eligibility from already-fetched payment and refund state.
     * - Blocks any order with a completed refund or refunded payment status.
     * - Blocks orders with no payment row (cannot verify COD vs unpaid online).
     * - Permits completed payments or pending COD/cash payments.
     *
     * @return array{eligible:bool, reason:?string, payment_method:?string, payment_status:?string}
     */
    private function resolvePaymentEligibility(?string $method, ?string $status, bool $isRefunded): array
    {
        if ($isRefunded) {
            return [
                'eligible' => false,
                'reason' => 'This order has been refunded and cannot be dispatched.',
                'payment_method' => null,
                'payment_status' => 'refunded',
            ];
        }

        if ($status === null || $status === '') {
            return [
                'eligible' => false,
                'reason' => 'No payment record exists for this order; payment eligibility cannot be verified.',
                'payment_method' => null,
                'payment_status' => null,
            ];
        }

        $methodStr = (string)($method ?? '');
        $isCod = in_array(strtolower(trim($methodStr)), ['cod', 'cash'], true);

        if ($status === 'completed' || ($status === 'pending' && $isCod)) {
            return [
                'eligible' => true,
                'reason' => null,
                'payment_method' => $methodStr,
                'payment_status' => $status,
            ];
        }

        return [
            'eligible' => false,
            'reason' => "Order payment status is '{$status}' ({$methodStr}) and is not eligible for dispatch.",
            'payment_method' => $methodStr,
            'payment_status' => $status,
        ];
    }

    /**
     * Query and verify an order's payment state inside a transaction before dispatch.
     *
     * @return array{eligible:bool, reason:?string, payment_method:?string, payment_status:?string}
     */
    private function evaluatePaymentEligibility(int $orderId): array
    {
        $refStmt = $this->db->prepare(
            "SELECT 1
             FROM payment
             WHERE order_id = ?
               AND ((payment_type = 'refund' AND payment_status = 'completed') OR payment_status = 'refunded')
             LIMIT 1"
        );
        $refStmt->execute([$orderId]);
        $isRefunded = (bool)$refStmt->fetchColumn();

        if ($isRefunded) {
            return $this->resolvePaymentEligibility(null, 'refunded', true);
        }

        $payStmt = $this->db->prepare(
            "SELECT payment_method, payment_status
             FROM payment
             WHERE order_id = ? AND payment_type = 'payment'
             ORDER BY (payment_status = 'completed') DESC, payment_id DESC
             LIMIT 1"
        );
        $payStmt->execute([$orderId]);
        $payment = $payStmt->fetch();

        if (!$payment) {
            return $this->resolvePaymentEligibility(null, null, false);
        }

        return $this->resolvePaymentEligibility(
            $payment['payment_method'] !== null ? (string)$payment['payment_method'] : null,
            $payment['payment_status'] !== null ? (string)$payment['payment_status'] : null,
            false
        );
    }

    public function orders(): void
    {
        $auth = $this->requireDeliveryViewer();
        $empId = $auth['emp_id'];
        $isCourier = $auth['is_courier'];

        $tab = strtolower(trim((string)($_GET['tab'] ?? 'queue')));
        if (!in_array($tab, ['queue', 'active', 'history'], true)) {
            $tab = 'queue';
        }

        $q = trim((string)($_GET['q'] ?? ''));
        $viewOrderId = isset($_GET['view']) && ctype_digit((string)$_GET['view']) ? (int)$_GET['view'] : null;

        $sql = "SELECT co.*,
                       CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       c.phone AS customer_phone,
                       ua.email AS customer_email,
                       p.payment_id,
                       p.amount AS payment_amount,
                       p.payment_method,
                       p.payment_status,
                       p.collection_channel,
                       p.collected_by_employee_id,
                       p.collected_at,
                       active_alloc.remittance_id AS active_remittance_id,
                       active_rmt.remittance_reference AS active_remittance_reference,
                       active_rmt.remittance_status AS active_remittance_status,
                       EXISTS(
                           SELECT 1 FROM payment pr
                           WHERE pr.order_id = co.order_id
                             AND ((pr.payment_type = 'refund' AND pr.payment_status = 'completed') OR pr.payment_status = 'refunded')
                       ) AS is_refunded,
                       CONCAT(deliv_emp.first_name, ' ', deliv_emp.last_name) AS delivery_employee_name
                FROM customer_order co
                JOIN customer c ON c.customer_id = co.customer_id
                LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                LEFT JOIN payment p ON p.payment_id = (
                    SELECT p2.payment_id
                    FROM payment p2
                    WHERE p2.order_id = co.order_id AND p2.payment_type = 'payment'
                    ORDER BY (p2.payment_status = 'completed') DESC, p2.payment_id DESC
                    LIMIT 1
                )
                LEFT JOIN courier_cod_remittance_allocation active_alloc ON active_alloc.active_payment_id = p.payment_id
                LEFT JOIN courier_cod_remittance active_rmt ON active_rmt.remittance_id = active_alloc.remittance_id
                LEFT JOIN employee deliv_emp ON deliv_emp.employee_id = co.delivery_employee_id
                WHERE co.business_unit_id = ?
                  AND co.fulfillment_method = 'delivery'";
        $params = [self::PET_STORE_UNIT_ID];

        if ($isCourier) {
            if ($tab === 'queue') {
                $sql .= " AND co.order_status = 'processing' AND co.delivery_employee_id IS NULL";
            } elseif ($tab === 'active') {
                $sql .= " AND co.order_status = 'out_for_delivery' AND co.delivery_employee_id = ?";
                $params[] = $empId;
            } else {
                $sql .= " AND co.order_status IN ('completed','delivery_failed') AND co.delivery_employee_id = ?";
                $params[] = $empId;
            }
        } else {
            if ($tab === 'queue') {
                $sql .= " AND co.order_status = 'processing'";
            } elseif ($tab === 'active') {
                $sql .= " AND co.order_status = 'out_for_delivery'";
            } else {
                $sql .= " AND co.order_status IN ('completed','delivery_failed')";
            }
        }

        if ($q !== '') {
            $cleanQ = ltrim($q, '#');
            if (str_starts_with(strtoupper($cleanQ), 'ORD-')) {
                $cleanQ = substr($cleanQ, 4);
            }
            $isNum = ctype_digit($cleanQ);
            $sql .= " AND (co.order_id = ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR c.phone LIKE ? OR co.delivery_city LIKE ?)";
            $params[] = $isNum ? (int)$cleanQ : 0;
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }

        $sql .= " ORDER BY co.order_date DESC, co.order_id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        $todayDate = $this->philippineTodayDate();
        $hydrateOrder = function (array $o) use ($todayDate): array {
            $itStmt = $this->db->prepare(
                "SELECT oi.order_item_id, oi.quantity, oi.unit_price, p.product_name, p.sku
                 FROM order_item oi
                 JOIN business_product bp ON bp.business_product_id = oi.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE oi.order_id = ?
                 ORDER BY oi.order_item_id ASC"
            );
            $itStmt->execute([(int)$o['order_id']]);
            $o['items'] = $itStmt->fetchAll();

            $elig = $this->resolvePaymentEligibility(
                $o['payment_method'] !== null ? (string)$o['payment_method'] : null,
                $o['payment_status'] !== null ? (string)$o['payment_status'] : null,
                !empty($o['is_refunded'])
            );
            $o['payment_eligible'] = $elig['eligible'];
            $o['payment_ineligible_reason'] = $elig['reason'];
            $o['rescheduled_future'] = ($o['order_status'] === 'delivery_failed'
                && !empty($o['rescheduled_date'])
                && (string)$o['rescheduled_date'] > $todayDate);
            return $o;
        };

        foreach ($orders as &$o) {
            $o = $hydrateOrder($o);
        }
        unset($o);

        $selectedOrder = null;
        if ($viewOrderId !== null) {
            foreach ($orders as $o) {
                if ((int)$o['order_id'] === $viewOrderId) {
                    $selectedOrder = $o;
                    break;
                }
            }
            if (!$selectedOrder) {
                $sOrd = $this->db->prepare(
                    "SELECT co.*,
                            CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                            c.phone AS customer_phone,
                            ua.email AS customer_email,
                            p.payment_id,
                            p.amount AS payment_amount,
                            p.payment_method,
                            p.payment_status,
                            p.collection_channel,
                            p.collected_by_employee_id,
                            p.collected_at,
                            active_alloc.remittance_id AS active_remittance_id,
                            active_rmt.remittance_reference AS active_remittance_reference,
                            active_rmt.remittance_status AS active_remittance_status,
                            EXISTS(
                                SELECT 1 FROM payment pr
                                WHERE pr.order_id = co.order_id
                                  AND ((pr.payment_type = 'refund' AND pr.payment_status = 'completed') OR pr.payment_status = 'refunded')
                            ) AS is_refunded,
                            CONCAT(deliv_emp.first_name, ' ', deliv_emp.last_name) AS delivery_employee_name
                     FROM customer_order co
                     JOIN customer c ON c.customer_id = co.customer_id
                     LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                     LEFT JOIN payment p ON p.payment_id = (
                         SELECT p2.payment_id
                         FROM payment p2
                         WHERE p2.order_id = co.order_id AND p2.payment_type = 'payment'
                         ORDER BY (p2.payment_status = 'completed') DESC, p2.payment_id DESC
                         LIMIT 1
                     )
                     LEFT JOIN courier_cod_remittance_allocation active_alloc ON active_alloc.active_payment_id = p.payment_id
                     LEFT JOIN courier_cod_remittance active_rmt ON active_rmt.remittance_id = active_alloc.remittance_id
                     LEFT JOIN employee deliv_emp ON deliv_emp.employee_id = co.delivery_employee_id
                     WHERE co.order_id = ? AND co.business_unit_id = ? AND co.fulfillment_method = 'delivery'
                     LIMIT 1"
                );
                $sOrd->execute([$viewOrderId, self::PET_STORE_UNIT_ID]);
                $candidate = $sOrd->fetch();
                if ($candidate) {
                    $candEmp = $candidate['delivery_employee_id'] !== null ? (int)$candidate['delivery_employee_id'] : null;
                    $candStatus = (string)$candidate['order_status'];
                    $allowedView = !$isCourier
                        || ($candEmp === null && $candStatus === 'processing')
                        || ($candEmp === $empId && in_array($candStatus, ['out_for_delivery', 'delivery_failed', 'completed'], true));
                    if ($allowedView) {
                        $selectedOrder = $hydrateOrder($candidate);
                    }
                }
            }
        }

        $remittanceState = ($isCourier && $empId > 0)
            ? $this->loadCourierCodRemittanceState($empId)
            : ['unremitted' => [], 'batches' => []];

        $this->render('delivery/orders', [
            'title' => 'Delivery Orders · Pet Store',
            'unitId' => self::PET_STORE_UNIT_ID,
            'unitName' => Units::PET_STORE,
            'isCourier' => $isCourier,
            'isSuper' => $auth['is_super'],
            'empId' => $empId,
            'orders' => $orders,
            'activeTab' => $tab,
            'searchQuery' => $q,
            'selectedOrder' => $selectedOrder,
            'todayDate' => $todayDate,
            'unremittedCodPayments' => $remittanceState['unremitted'],
            'courierRemittances' => $remittanceState['batches'],
        ]);
    }

    public function dispatch(int $orderId): void
    {
        CSRF::check();
        $auth = $this->requireCourierOperator();
        $uid = $auth['uid'];
        $empId = $auth['emp_id'];

        try {
            $reservationService = new OrderReservationService($this->db);
            $reservationService->dispatchDeliveryOrder(
                $orderId,
                self::PET_STORE_UNIT_ID,
                $empId,
                $uid,
                function () use ($orderId): void {
                    $eligibility = $this->evaluatePaymentEligibility($orderId);
                    if (!$eligibility['eligible']) {
                        throw new RuntimeException(
                            (string)$eligibility['reason'],
                            OrderReservationService::ERR_UNPROCESSABLE
                        );
                    }
                }
            );

            $this->flash('success', "Order #ORD-{$orderId} is now Out for Delivery.");
            $this->redirect('/delivery/orders?tab=active&view=' . $orderId);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            if ($code === OrderReservationService::ERR_NOT_FOUND) {
                $this->notFound();
            }
            if ($code === OrderReservationService::ERR_FORBIDDEN) {
                $this->forbidden($e->getMessage());
            }
            $this->flash('error', $e->getMessage());
            $tab = str_contains($e->getMessage(), 'Delivery Failed') ? 'history' : 'queue';
            $this->redirect("/delivery/orders?tab={$tab}&view=" . $orderId);
        }
    }

    public function complete(int $orderId): void
    {
        CSRF::check();
        $auth = $this->requireCourierOperator();
        $uid = $auth['uid'];
        $empId = $auth['emp_id'];
        $confirmCodCollected = !empty($_POST['confirm_cod_collected']);

        try {
            $reservationService = new OrderReservationService($this->db);
            $paymentService = new OrderPaymentService($this->db);
            $onCompleted = static function () use ($paymentService, $orderId, $empId, $uid, $confirmCodCollected): void {
                if ($confirmCodCollected) {
                    $paymentService->collectDeliveryCodInTransaction($orderId, self::PET_STORE_UNIT_ID, $empId, $uid);
                } else {
                    $paymentService->assertNoPendingDeliveryCodInTransaction($orderId);
                }
            };
            $reservationService->completeDeliveryOrder($orderId, self::PET_STORE_UNIT_ID, $empId, $uid, $onCompleted);

            $msg = $confirmCodCollected
                ? "Order #ORD-{$orderId} marked as Completed and COD cash collection confirmed."
                : "Order #ORD-{$orderId} marked as Completed.";
            $this->flash('success', $msg);
            $this->redirect('/delivery/orders?tab=history&view=' . $orderId);
        } catch (RuntimeException $e) {
            $this->handleServiceException($e, '/delivery/orders?tab=active&view=' . $orderId);
        }
    }

    public function collectCod(int $orderId): void
    {
        CSRF::check();
        $auth = $this->requireCourierOperator();
        $uid = $auth['uid'];
        $empId = $auth['emp_id'];

        try {
            $paymentService = new OrderPaymentService($this->db);
            $result = $paymentService->confirmDeliveryCodCollection($orderId, self::PET_STORE_UNIT_ID, $empId, $uid);
            $this->flash(
                'success',
                "Confirmed COD cash collection (₱" . number_format((float)$result['amount'], 2) . ") for Order #ORD-{$orderId}."
            );
            $this->redirect('/delivery/orders?tab=history&view=' . $orderId);
        } catch (RuntimeException $e) {
            $this->handleServiceException($e, '/delivery/orders?tab=history&view=' . $orderId);
        }
    }

    public function submitRemittance(): void
    {
        CSRF::check();
        $auth = $this->requireCourierOperator();
        $uid = $auth['uid'];
        $empId = $auth['emp_id'];

        $rawTotal = trim((string)($_POST['remitted_total'] ?? ''));
        $declaredTotal = $rawTotal !== '' ? $rawTotal : null;
        $notes = trim((string)($_POST['notes'] ?? ''));

        try {
            $paymentIds = $this->extractSubmittedRemittancePaymentIds();
            $paymentService = new OrderPaymentService($this->db);
            $result = $paymentService->submitCourierCodRemittance(
                self::PET_STORE_UNIT_ID,
                $empId,
                $uid,
                $paymentIds,
                $declaredTotal,
                $notes !== '' ? $notes : null
            );
            $this->flash(
                'success',
                "Submitted COD remittance {$result['remittance_reference']} ({$result['allocations_created']} payment(s), ₱"
                . $result['remitted_total'] . "). Pending staff remittance verification."
            );
        } catch (RuntimeException $e) {
            $this->handleServiceException($e, '/delivery/orders?tab=history');
            return;
        }
        $this->redirect('/delivery/orders?tab=history');
    }

    /**
     * Validate and normalize submitted remittance payment_ids from POST payload without silent coercion.
     *
     * @return list<int>
     */
    private function extractSubmittedRemittancePaymentIds(): array
    {
        $rawIds = array_key_exists('payment_ids', $_POST) ? $_POST['payment_ids'] : [];
        return OrderPaymentService::validatePositivePaymentIds($rawIds);
    }

    private function handleServiceException(RuntimeException $e, string $fallbackRedirect): void
    {
        $code = $e->getCode();
        if ($code === OrderReservationService::ERR_NOT_FOUND) {
            $this->notFound();
        }
        if ($code === OrderReservationService::ERR_FORBIDDEN) {
            $this->forbidden($e->getMessage());
        }
        $this->flash('error', $e->getMessage());
        $this->redirect($fallbackRedirect);
    }

    /**
     * @return array{unremitted:array<int,array<string,mixed>>, batches:array<int,array<string,mixed>>}
     */
    private function loadCourierCodRemittanceState(int $empId): array
    {
        $unremStmt = $this->db->prepare(
            "SELECT p.payment_id, p.order_id, p.amount, p.collected_at,
                    co.order_date, co.subtotal_amount, co.delivery_fee, co.total_amount,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name
             FROM payment p
             JOIN customer_order co ON co.order_id = p.order_id
             JOIN customer c ON c.customer_id = co.customer_id
             LEFT JOIN courier_cod_remittance_allocation a ON a.active_payment_id = p.payment_id
             WHERE co.business_unit_id = ?
               AND co.fulfillment_method = 'delivery'
               AND co.order_status = 'completed'
               AND p.payment_type = 'payment'
               AND p.payment_status = 'completed'
               AND p.collection_channel = 'courier_cod'
               AND p.collected_by_employee_id = ?
               AND a.allocation_id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM payment pr
                   WHERE pr.original_payment_id = p.payment_id AND pr.payment_type = 'refund' AND pr.payment_status = 'completed'
               )
             ORDER BY p.collected_at DESC, p.payment_id DESC"
        );
        $unremStmt->execute([self::PET_STORE_UNIT_ID, $empId]);
        $unremitted = $unremStmt->fetchAll() ?: [];

        $batchStmt = $this->db->prepare(
            "SELECT r.remittance_id, r.remittance_reference, r.expected_total, r.remitted_total,
                    r.remittance_status, r.submitted_at, r.notes,
                    COUNT(a.allocation_id) AS payment_count
             FROM courier_cod_remittance r
             LEFT JOIN courier_cod_remittance_allocation a ON a.remittance_id = r.remittance_id AND a.allocation_status = 'active'
             WHERE r.business_unit_id = ? AND r.courier_employee_id = ?
             GROUP BY r.remittance_id
             ORDER BY r.submitted_at DESC, r.remittance_id DESC
             LIMIT 20"
        );
        $batchStmt->execute([self::PET_STORE_UNIT_ID, $empId]);
        $batches = $batchStmt->fetchAll() ?: [];

        return ['unremitted' => $unremitted, 'batches' => $batches];
    }

    public function fail(int $orderId): void
    {
        CSRF::check();
        $auth = $this->requireCourierOperator();
        $uid = $auth['uid'];
        $empId = $auth['emp_id'];

        $reason = trim((string)($_POST['delivery_failure_reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            $this->flash('error', 'Please enter a valid delivery failure reason (1–255 characters).');
            $this->redirect('/delivery/orders?tab=active&view=' . $orderId);
            return;
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare(
                "SELECT * FROM customer_order WHERE order_id = ? AND business_unit_id = ? FOR UPDATE"
            );
            $stmt->execute([$orderId, self::PET_STORE_UNIT_ID]);
            $order = $stmt->fetch();

            if (!$order) {
                $this->db->rollBack();
                $this->notFound();
            }

            if ($order['fulfillment_method'] !== 'delivery') {
                $this->db->rollBack();
                $this->forbidden('Only delivery orders may be marked as delivery failed.');
            }

            if ((int)($order['delivery_employee_id'] ?? 0) !== $empId) {
                $this->db->rollBack();
                $this->forbidden('Only the assigned courier may report a delivery failure for this order.');
            }

            if ($order['order_status'] !== 'out_for_delivery') {
                $this->db->rollBack();
                $this->flash('error', "Order #ORD-{$orderId} must be Out for Delivery to report a delivery failure.");
                $this->redirect('/delivery/orders?tab=active&view=' . $orderId);
                return;
            }

            $up = $this->db->prepare(
                "UPDATE customer_order
                 SET order_status = 'delivery_failed',
                     delivery_failure_reason = ?,
                     delivery_failed_at = NOW(),
                     rescheduled_date = NULL,
                     updated_at = NOW()
                 WHERE order_id = ?"
            );
            $up->execute([$reason, $orderId]);

            Audit::record(
                $this->db,
                'delivery.failed',
                "Reported delivery failure for order #ORD-{$orderId}: {$reason}",
                self::PET_STORE_UNIT_ID,
                $uid,
                $empId,
                "status:out_for_delivery; courier:{$empId}",
                "status:delivery_failed; courier:{$empId}; reason:{$reason}",
                'customer_order',
                $orderId
            );

            $this->db->commit();
            $this->flash('success', "Order #ORD-{$orderId} marked as Delivery Failed. Receptionist may now coordinate rescheduling.");
            $this->redirect('/delivery/orders?tab=history&view=' . $orderId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
