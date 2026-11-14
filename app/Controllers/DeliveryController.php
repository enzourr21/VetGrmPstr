<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\Units;
use DateTimeImmutable;
use DateTimeZone;
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

        $isSuper = $this->isSuperAdmin();

        $stmt = $this->db->prepare(
            "SELECT e.employee_id
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id AND e.employee_status = 'active'
             JOIN account_role ar ON ar.user_account_id = ua.user_account_id
             JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'DELIVERY_PERSONNEL'
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id AND bu.business_unit_status = 'active'
             WHERE ua.user_account_id = ? AND ar.business_unit_id = ? AND ua.account_status = 'active'
             LIMIT 1"
        );
        $stmt->execute([(int)$uid, self::PET_STORE_UNIT_ID]);
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
             ORDER BY payment_id DESC
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
                       p.payment_method,
                       p.payment_status,
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
                    ORDER BY p2.payment_id DESC
                    LIMIT 1
                )
                LEFT JOIN employee deliv_emp ON deliv_emp.employee_id = co.delivery_employee_id
                WHERE co.business_unit_id = ?
                  AND co.fulfillment_method = 'delivery'";
        $params = [self::PET_STORE_UNIT_ID];

        if ($isCourier) {
            if ($tab === 'queue') {
                $sql .= " AND ((co.order_status IN ('pending','confirmed','processing','delivery_failed') AND co.delivery_employee_id IS NULL)
                               OR (co.order_status = 'delivery_failed' AND co.delivery_employee_id = ?))";
                $params[] = $empId;
            } elseif ($tab === 'active') {
                $sql .= " AND co.order_status = 'out_for_delivery' AND co.delivery_employee_id = ?";
                $params[] = $empId;
            } else {
                $sql .= " AND co.order_status = 'completed' AND co.delivery_employee_id = ?";
                $params[] = $empId;
            }
        } else {
            if ($tab === 'queue') {
                $sql .= " AND co.order_status IN ('pending','confirmed','processing','delivery_failed')";
            } elseif ($tab === 'active') {
                $sql .= " AND co.order_status = 'out_for_delivery'";
            } else {
                $sql .= " AND co.order_status = 'completed'";
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
                            p.payment_method,
                            p.payment_status,
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
                         ORDER BY p2.payment_id DESC
                         LIMIT 1
                     )
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
                        || ($candEmp === null && in_array($candStatus, ['pending', 'confirmed', 'processing', 'delivery_failed'], true))
                        || ($candEmp === $empId && in_array($candStatus, ['out_for_delivery', 'delivery_failed', 'completed'], true));
                    if ($allowedView) {
                        $selectedOrder = $hydrateOrder($candidate);
                    }
                }
            }
        }

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
        ]);
    }

    public function dispatch(int $orderId): void
    {
        CSRF::check();
        $auth = $this->requireCourierOperator();
        $uid = $auth['uid'];
        $empId = $auth['emp_id'];

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
                $this->forbidden('Only delivery orders may be dispatched by Delivery Personnel.');
            }

            $currentStatus = (string)$order['order_status'];
            $assignedEmpId = $order['delivery_employee_id'] !== null ? (int)$order['delivery_employee_id'] : null;

            if (in_array($currentStatus, ['pending', 'confirmed', 'processing'], true)) {
                if ($assignedEmpId !== null && $assignedEmpId !== $empId) {
                    $this->db->rollBack();
                    $this->flash('error', "Order #ORD-{$orderId} has already been claimed by another courier.");
                    $this->redirect('/delivery/orders?tab=queue');
                    return;
                }
                $actionCode = 'delivery.dispatched';
            } elseif ($currentStatus === 'delivery_failed') {
                if ($assignedEmpId !== null && $assignedEmpId !== $empId) {
                    $this->db->rollBack();
                    $this->forbidden('Only the originally assigned courier may re-dispatch this failed delivery.');
                }
                $reschedDate = !empty($order['rescheduled_date']) ? (string)$order['rescheduled_date'] : null;
                if ($reschedDate !== null && $reschedDate > $this->philippineTodayDate()) {
                    $this->db->rollBack();
                    $schedDisplay = DateTimeImmutable::createFromFormat('!Y-m-d', $reschedDate, new DateTimeZone('Asia/Manila'));
                    $schedFormatted = $schedDisplay ? $schedDisplay->format('M j, Y') : $reschedDate;
                    $this->flash(
                        'error',
                        "Order #ORD-{$orderId} is scheduled for re-delivery on {$schedFormatted} and cannot be dispatched early."
                    );
                    $this->redirect('/delivery/orders?tab=queue&view=' . $orderId);
                    return;
                }
                $actionCode = 'delivery.redispatched';
            } else {
                $this->db->rollBack();
                $this->flash('error', "Order #ORD-{$orderId} cannot be dispatched from status '{$currentStatus}'.");
                $this->redirect('/delivery/orders?tab=queue&view=' . $orderId);
                return;
            }

            $eligibility = $this->evaluatePaymentEligibility($orderId);
            if (!$eligibility['eligible']) {
                $this->db->rollBack();
                $this->flash('error', (string)$eligibility['reason']);
                $this->redirect('/delivery/orders?tab=queue&view=' . $orderId);
                return;
            }

            $up = $this->db->prepare(
                "UPDATE customer_order
                 SET order_status = 'out_for_delivery', delivery_employee_id = ?, updated_at = NOW()
                 WHERE order_id = ?"
            );
            $up->execute([$empId, $orderId]);

            $oldCourierLabel = $assignedEmpId !== null ? (string)$assignedEmpId : 'none';
            $summary = $actionCode === 'delivery.redispatched'
                ? "Re-dispatched failed delivery order #ORD-{$orderId} (out for delivery)."
                : "Claimed and dispatched delivery order #ORD-{$orderId} (out for delivery).";

            Audit::record(
                $this->db,
                $actionCode,
                $summary,
                self::PET_STORE_UNIT_ID,
                $uid,
                $empId,
                "status:{$currentStatus}; courier:{$oldCourierLabel}",
                "status:out_for_delivery; courier:{$empId}",
                'customer_order',
                $orderId
            );

            $this->db->commit();
            $this->flash('success', "Order #ORD-{$orderId} is now Out for Delivery.");
            $this->redirect('/delivery/orders?tab=active&view=' . $orderId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function complete(int $orderId): void
    {
        CSRF::check();
        $auth = $this->requireCourierOperator();
        $uid = $auth['uid'];
        $empId = $auth['emp_id'];

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
                $this->forbidden('Only delivery orders may be completed by Delivery Personnel.');
            }

            if ((int)($order['delivery_employee_id'] ?? 0) !== $empId) {
                $this->db->rollBack();
                $this->forbidden('Only the assigned courier may complete this delivery.');
            }

            if ($order['order_status'] !== 'out_for_delivery') {
                $this->db->rollBack();
                $this->flash('error', "Order #ORD-{$orderId} must be Out for Delivery before it can be marked completed.");
                $this->redirect('/delivery/orders?tab=active&view=' . $orderId);
                return;
            }

            $up = $this->db->prepare(
                "UPDATE customer_order SET order_status = 'completed', updated_at = NOW() WHERE order_id = ?"
            );
            $up->execute([$orderId]);

            Audit::record(
                $this->db,
                'delivery.completed',
                "Marked delivery order #ORD-{$orderId} as delivered and completed.",
                self::PET_STORE_UNIT_ID,
                $uid,
                $empId,
                "status:out_for_delivery; courier:{$empId}",
                "status:completed; courier:{$empId}",
                'customer_order',
                $orderId
            );

            $this->db->commit();
            $this->flash('success', "Order #ORD-{$orderId} marked as Completed.");
            $this->redirect('/delivery/orders?tab=history&view=' . $orderId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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
            $this->redirect('/delivery/orders?tab=queue&view=' . $orderId);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
