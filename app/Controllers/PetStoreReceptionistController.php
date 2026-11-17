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
use App\Services\PosSaleService;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class PetStoreReceptionistController extends Controller
{
    private const PET_STORE_UNIT_ID = 1;

    /**
     * Helper to flash messages to session.
     */
    private function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /**
     * Checks if current request expects JSON.
     */
    private function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return str_contains($accept, 'application/json') || strtolower($xhr) === 'xmlhttprequest';
    }

    /**
     * Strict Authorization Guard:
     * Permits Super Administrator OR an active employee assigned the RECEPTIONIST role for Pet Store (business_unit_id = 1).
     * Enforces email-authenticated session and employee active context.
     *
     * @return array{0:int, 1:int, 2:int, 3:string, 4:bool} [user_id, employee_id, business_unit_id, business_unit_name, is_super_admin]
     */
    private function checkPetStoreReceptionist(): array
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/petstore/receptionist/sales');
            $this->redirect('/login');
        }

        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');

        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated Pet Store Receptionist session is required.');
        }

        $unitId = self::PET_STORE_UNIT_ID;
        $unitName = Units::PET_STORE;

        if ($this->isSuperAdmin()) {
            $empId = (int)Session::get('employee_id', 9);
            if ($empId === 0) {
                $sEmp = $this->db->prepare('SELECT employee_id FROM employee WHERE user_account_id = ? LIMIT 1');
                $sEmp->execute([(int)$uid]);
                $empId = (int)($sEmp->fetchColumn() ?: 9);
            }
            return [(int)$uid, $empId, $unitId, $unitName, true];
        }

        // Verify active employee record and RECEPTIONIST role for Pet Store (unit 1)
        $stmt = $this->db->prepare(
            "SELECT e.employee_id, bu.business_unit_id, bu.business_unit_name
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id AND e.employee_status = 'active'
             JOIN account_role ar ON ar.user_account_id = ua.user_account_id
             JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'RECEPTIONIST'
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id AND bu.business_unit_status = 'active'
             WHERE ua.user_account_id = ? AND ar.business_unit_id = ? AND ua.account_status = 'active'
             LIMIT 1"
        );
        $stmt->execute([(int)$uid, $unitId]);
        $row = $stmt->fetch();

        if (!$row) {
            $this->forbidden('Access denied: Pet Store Receptionist privileges are required.');
        }

        return [(int)$uid, (int)$row['employee_id'], $unitId, $unitName, false];
    }

    /**
     * Build a one-row-per-parent subquery join for `payment` so order/sale listings never duplicate parent rows
     * and never pick an arbitrary payment row when multiple payments or refunds exist.
     */
    private function paymentSummaryJoinSql(string $foreignKey, string $parentAlias, string $paymentAlias = 'p', string $summaryAlias = 'ps'): string
    {
        if (!in_array($foreignKey, ['order_id', 'sale_id'], true)) {
            throw new \InvalidArgumentException('Unsupported payment foreign key: ' . $foreignKey);
        }

        return "LEFT JOIN (
                    SELECT p_sub.{$foreignKey},
                           MAX(CASE WHEN (p_sub.payment_type = 'refund' AND p_sub.payment_status = 'completed') OR p_sub.payment_status = 'refunded' THEN 1 ELSE 0 END) AS has_refunded,
                           MAX(CASE WHEN p_sub.payment_type = 'payment' AND p_sub.payment_status = 'completed' THEN p_sub.payment_id END) AS latest_completed_payment_id,
                           MAX(CASE WHEN p_sub.payment_type = 'payment' THEN p_sub.payment_id END) AS latest_payment_id
                    FROM payment p_sub
                    WHERE p_sub.{$foreignKey} IS NOT NULL
                    GROUP BY p_sub.{$foreignKey}
                ) {$summaryAlias} ON {$summaryAlias}.{$foreignKey} = {$parentAlias}.{$foreignKey}
                LEFT JOIN payment {$paymentAlias} ON {$paymentAlias}.payment_id = COALESCE({$summaryAlias}.latest_completed_payment_id, {$summaryAlias}.latest_payment_id)";
    }

    // ==========================================
    // 1. SALES PAGE & ACTIONS
    // ==========================================

    /**
     * Sales Page:
     * - Daily Total Sales card
     * - Daily Pending Orders card
     * - Recent Sales card (3 newest successful sales with View More history)
     * - New Sale modal data
     */
    public function sales(): void
    {
        [$uid, $empId, $unitId, $unitName, $isSuper] = $this->checkPetStoreReceptionist();

        // 1. Daily Total Sales (successful sales today)
        $totStmt = $this->db->prepare(
            "SELECT COALESCE(SUM(s.total_amount), 0) AS daily_total
             FROM sale s
             WHERE s.business_unit_id = ?
               AND s.sale_status = 'completed'
               AND DATE(s.sale_date) = CURDATE()"
        );
        $totStmt->execute([$unitId]);
        $dailyTotalSales = (float)$totStmt->fetchColumn();

        // 2. Daily Sales Orders breakdown (orders placed today: pending, ready for pickup, out for delivery)
        $ordStmt = $this->db->prepare(
            "SELECT 
                COALESCE(SUM(CASE WHEN co.order_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN co.order_status = 'ready_for_pickup' THEN 1 ELSE 0 END), 0) AS ready_for_pickup_count,
                COALESCE(SUM(CASE WHEN co.order_status = 'out_for_delivery' THEN 1 ELSE 0 END), 0) AS out_for_delivery_count,
                COUNT(*) AS total_daily_orders
             FROM customer_order co
             WHERE co.business_unit_id = ?
               AND DATE(co.order_date) = CURDATE()
               AND co.order_status NOT IN ('cancelled')"
        );
        $ordStmt->execute([$unitId]);
        $orderCounts = $ordStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'pending_count' => 0,
            'ready_for_pickup_count' => 0,
            'out_for_delivery_count' => 0,
            'total_daily_orders' => 0,
        ];

        $dailyPendingOrders = (int)$orderCounts['pending_count'];
        $dailyReadyPickupOrders = (int)$orderCounts['ready_for_pickup_count'];
        $dailyOutDeliveryOrders = (int)$orderCounts['out_for_delivery_count'];
        $totalDailyOrders = (int)$orderCounts['total_daily_orders'];

        // 3. Recent Sales (Today's walk-in sales only, paginated server-side, sorted newest first)
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM sale s WHERE s.business_unit_id = ? AND s.sale_status = 'completed' AND DATE(s.sale_date) = CURDATE()"
        );
        $countStmt->execute([$unitId]);
        $totalSalesCount = (int)$countStmt->fetchColumn();

        $perPage = 5;
        $totalPages = max(1, (int)ceil($totalSalesCount / $perPage));
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }
        $offset = ($currentPage - 1) * $perPage;

        $recStmt = $this->db->prepare(
            "SELECT s.sale_id, s.sale_date, s.total_amount, s.sale_status, s.customer_id,
                    COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Walk-in Customer') AS customer_name,
                    c.phone AS customer_phone,
                    p.payment_method,
                    CASE WHEN COALESCE(ps.has_refunded, 0) = 1 THEN 'refunded' ELSE p.payment_status END AS payment_status,
                    (SELECT COUNT(*) FROM sale_item si WHERE si.sale_id = s.sale_id) AS item_count
             FROM sale s
             LEFT JOIN customer c ON c.customer_id = s.customer_id
             {$this->paymentSummaryJoinSql('sale_id', 's')}
             WHERE s.business_unit_id = ? AND s.sale_status = 'completed' AND DATE(s.sale_date) = CURDATE()
             ORDER BY s.sale_date DESC, s.sale_id DESC
             LIMIT ? OFFSET ?"
        );
        $recStmt->bindValue(1, $unitId, PDO::PARAM_INT);
        $recStmt->bindValue(2, $perPage, PDO::PARAM_INT);
        $recStmt->bindValue(3, $offset, PDO::PARAM_INT);
        $recStmt->execute();
        $recentSales = $recStmt->fetchAll();

        foreach ($recentSales as &$sale) {
            $itStmt = $this->db->prepare(
                "SELECT si.sale_item_id, si.quantity, si.unit_price,
                        p.product_name, p.sku
                 FROM sale_item si
                 JOIN business_product bp ON bp.business_product_id = si.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE si.sale_id = ?
                 ORDER BY si.sale_item_id ASC"
            );
            $itStmt->execute([(int)$sale['sale_id']]);
            $sale['items'] = $itStmt->fetchAll();
        }
        unset($sale);

        // Available Pet Store products for the + New Sale modal
        $prodStmt = $this->db->prepare(
            "SELECT bp.business_product_id, bp.selling_price,
                    p.product_name, p.sku, p.product_category,
                    i.inventory_id, COALESCE(i.on_hand, 0) AS on_hand, COALESCE(i.committed, 0) AS committed,
                    COALESCE(i.in_transit, 0) AS in_transit,
                    (COALESCE(i.on_hand, 0) + COALESCE(i.in_transit, 0) - COALESCE(i.committed, 0)) AS available_stock
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_unit_id = ? AND bp.product_status = 'active'
             ORDER BY p.product_name ASC"
        );
        $prodStmt->execute([$unitId]);
        $availableProducts = $prodStmt->fetchAll();

        $this->render('petstore_receptionist/sales', [
            'title' => 'Overview · Pet Store Receptionist',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'dailyTotalSales' => $dailyTotalSales,
            'dailyPendingOrders' => $dailyPendingOrders,
            'dailyReadyPickupOrders' => $dailyReadyPickupOrders,
            'dailyOutDeliveryOrders' => $dailyOutDeliveryOrders,
            'totalDailyOrders' => $totalDailyOrders,
            'recentSales' => $recentSales,
            'totalSalesCount' => $totalSalesCount,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
            'availableProducts' => $availableProducts,
        ]);
    }

    /**
     * AJAX Search for Pet Store products:
     * Returns JSON list of active Pet Store products with availability and stock.
     */
    public function searchProducts(): void
    {
        [$uid, $empId, $unitId] = $this->checkPetStoreReceptionist();

        $q = trim((string)($_GET['q'] ?? ''));

        $sql = "SELECT bp.business_product_id, bp.selling_price,
                       p.product_name, p.sku, p.product_category,
                       COALESCE(i.on_hand, 0) AS on_hand, COALESCE(i.committed, 0) AS committed,
                       COALESCE(i.in_transit, 0) AS in_transit,
                       (COALESCE(i.on_hand, 0) + COALESCE(i.in_transit, 0) - COALESCE(i.committed, 0)) AS available_stock
                FROM business_product bp
                JOIN product p ON p.product_id = bp.product_id
                LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
                WHERE bp.business_unit_id = ? AND bp.product_status = 'active'";
        $params = [$unitId];

        if ($q !== '') {
            $sql .= " AND (p.product_name LIKE ? OR p.product_category LIKE ? OR p.sku LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }

        $sql .= " ORDER BY p.product_name ASC LIMIT 25";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $r) {
            $stock = (int)$r['available_stock'];
            $results[] = [
                'business_product_id' => (int)$r['business_product_id'],
                'product_name' => $r['product_name'],
                'product_category' => $r['product_category'] ?? '',
                'sku' => $r['sku'] ?? '',
                'selling_price' => (float)$r['selling_price'],
                'available_stock' => $stock,
                'in_stock' => $stock > 0,
            ];
        }

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'products' => $results]);
        exit;
    }

    /**
     * AJAX Search for registered customers:
     * Returns JSON list of customers formatted as: `Customer Name #CustomerID`
     */
    public function searchCustomers(): void
    {
        $this->checkPetStoreReceptionist();

        $q = trim((string)($_GET['q'] ?? ''));
        if ($q === '') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'customers' => []]);
            exit;
        }

        $cleanQ = ltrim($q, '#');
        $idNum = ctype_digit($cleanQ) ? (int)$cleanQ : 0;

        $stmt = $this->db->prepare(
            "SELECT c.customer_id, c.first_name, c.last_name, c.phone, ua.email
             FROM customer c
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             WHERE c.customer_status = 'active'
               AND (
                 c.first_name LIKE ?
                 OR c.last_name LIKE ?
                 OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?
                 OR ua.email LIKE ?
                 OR c.phone LIKE ?
                 OR c.customer_id = ?
               )
             ORDER BY c.first_name ASC, c.last_name ASC
             LIMIT 10"
        );
        $likeQ = "%$q%";
        $stmt->execute([$likeQ, $likeQ, $likeQ, $likeQ, $likeQ, $idNum]);
        $rows = $stmt->fetchAll();

        $results = [];
        foreach ($rows as $r) {
            $name = trim($r['first_name'] . ' ' . $r['last_name']);
            $cid = (int)$r['customer_id'];
            $results[] = [
                'customer_id' => $cid,
                'customer_name' => $name,
                'label' => "{$name} #{$cid}",
                'email' => $r['email'] ?? '',
                'phone' => $r['phone'] ?? '',
            ];
        }

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'customers' => $results]);
        exit;
    }

    /**
     * Confirm and record a New Sale:
     * - Validates customer info (registered customer ID populated ONLY via search suggestion; walk-in customer_id = NULL)
     * - Validates product selection, positive quantities, business-unit ownership, stock availability
     * - Atomic transaction: inserts sale, sale_items, payment, updates inventory, logs inventory_movement
     */
    public function createSale(): void
    {
        [$uid, $empId, $unitId, $unitName, $isSuper] = $this->checkPetStoreReceptionist();
        CSRF::check();

        $customerType = trim((string)($this->post('customer_type') ?? 'walkin'));
        $rawCustId = trim((string)($this->post('customer_id') ?? ''));
        $customerName = trim((string)($this->post('customer_name') ?? ''));
        $paymentMethod = trim((string)($this->post('payment_method') ?? 'Cash'));
        if (!in_array($paymentMethod, ['Cash', 'Credit Card', 'Debit Card', 'GCash', 'Maya'], true)) {
            $paymentMethod = 'Cash';
        }

        // Customer ID resolution based on customer_type:
        $customerId = null;
        if ($customerType === 'registered') {
            if ($rawCustId === '' || !ctype_digit($rawCustId)) {
                $this->flash('error', 'Please search and select a valid registered customer before confirming the sale.');
                $this->redirect('/petstore/receptionist/overview');
                return;
            }
            $chkCust = $this->db->prepare("SELECT customer_id, first_name, last_name FROM customer WHERE customer_id = ? AND customer_status = 'active' LIMIT 1");
            $chkCust->execute([(int)$rawCustId]);
            $foundCust = $chkCust->fetch(PDO::FETCH_ASSOC);
            if (!$foundCust) {
                $this->flash('error', 'The selected registered customer could not be found or is inactive.');
                $this->redirect('/petstore/receptionist/overview');
                return;
            }
            $customerId = (int)$foundCust['customer_id'];
            $customerName = trim($foundCust['first_name'] . ' ' . $foundCust['last_name']);
        } else {
            // Walk-in customer: Customer ID must remain strictly NULL
            $customerId = null;
            if ($customerName === '') {
                $customerName = 'Walk-in Customer';
            }
            // Do NOT create permanent customer account or pet profile.
        }

        $productIds = $_POST['product_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if (!is_array($productIds) || !is_array($quantities) || empty($productIds)) {
            $this->flash('error', 'Please add at least one product to the sale.');
            $this->redirect('/petstore/receptionist/overview');
            return;
        }

        $rawLines = [];
        foreach ($productIds as $idx => $rawBpId) {
            $bpId = (int)$rawBpId;
            $qty = isset($quantities[$idx]) ? (int)$quantities[$idx] : 0;

            if ($qty < 0) {
                $this->flash('error', 'Sale product quantities must be greater than zero.');
                $this->redirect('/petstore/receptionist/overview');
                return;
            }
            if ($bpId <= 0 || $qty === 0) {
                continue;
            }

            $rawLines[] = [
                'business_product_id' => $bpId,
                'quantity' => $qty,
            ];
        }

        if (empty($rawLines)) {
            $this->flash('error', 'At least one valid product line with quantity greater than zero is required.');
            $this->redirect('/petstore/receptionist/overview');
            return;
        }

        try {
            $posService = new PosSaleService($this->db);
            $result = $posService->recordSale(
                (int)$unitId,
                (int)$uid,
                (int)$empId,
                $customerId !== null ? (int)$customerId : null,
                (string)$paymentMethod,
                $rawLines
            );

            $saleId = (int)$result['sale_id'];
            $totalAmount = (float)$result['total_amount'];
            $this->flash('success', "Sale #{$saleId} confirmed successfully! Total: ₱" . number_format($totalAmount, 2));
        } catch (RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        } catch (Throwable $e) {
            $this->flash('error', 'Failed to confirm sale: ' . $e->getMessage());
        }

        $this->redirect('/petstore/receptionist/overview');
    }

    // ==========================================
    // 2. ORDERS PAGE & ACTIONS
    // ==========================================

    /**
     * Orders Page:
     * - Exactly three tabs: All Orders, Delivery Orders, Pickup Orders
     * - Sorted newest first
     * - Detailed view for each order
     */
    public function orders(): void
    {
        [$uid, $empId, $unitId, $unitName, $isSuper] = $this->checkPetStoreReceptionist();

        $tab = strtolower(trim((string)($_GET['tab'] ?? 'all')));
        if (!in_array($tab, ['all', 'delivery', 'pickup'], true)) {
            $tab = 'all';
        }

        $q = trim((string)($_GET['q'] ?? ''));
        $statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
        $allowedStatuses = ['all', 'pending', 'confirmed', 'ready_for_pickup', 'out_for_delivery', 'delivery_failed', 'completed', 'cancelled', 'processing'];
        if (!in_array($statusFilter, $allowedStatuses, true)) {
            $statusFilter = 'all';
        }

        $viewOrderId = isset($_GET['view']) && ctype_digit((string)$_GET['view']) ? (int)$_GET['view'] : null;

        $sql = "SELECT co.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       c.phone AS customer_phone, ua.email AS customer_email,
                       p.payment_id, p.payment_method, p.collection_channel, p.collected_at,
                       CASE WHEN COALESCE(ps.has_refunded, 0) = 1 THEN 'refunded' ELSE p.payment_status END AS payment_status,
                       CONCAT(deliv_emp.first_name, ' ', deliv_emp.last_name) AS delivery_employee_name
                FROM customer_order co
                JOIN customer c ON c.customer_id = co.customer_id
                LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                {$this->paymentSummaryJoinSql('order_id', 'co')}
                LEFT JOIN employee deliv_emp ON deliv_emp.employee_id = co.delivery_employee_id
                WHERE co.business_unit_id = ?
                  AND (
                      DATE(co.order_date) = CURDATE()
                      OR (
                          co.fulfillment_method = 'delivery'
                          AND (co.delivery_failed_at IS NOT NULL OR co.delivery_failure_reason IS NOT NULL)
                          AND (
                              (co.order_status IN ('processing', 'out_for_delivery')
                               AND DATE(co.updated_at) = CURDATE()
                               AND (co.rescheduled_date IS NULL OR co.rescheduled_date <= CURDATE()))
                              OR (co.order_status = 'delivery_failed' AND co.rescheduled_date = CURDATE())
                          )
                      )
                      OR (
                          co.order_status = 'completed'
                          AND co.order_completed_at IS NOT NULL
                          AND DATE(co.order_completed_at) = CURDATE()
                          AND NOT EXISTS (
                              SELECT 1
                              FROM refund_request rr
                              LEFT JOIN payment rp ON rp.payment_id = rr.payment_id
                              WHERE rr.order_id = co.order_id
                                 OR (rr.payment_id IS NOT NULL AND rp.order_id = co.order_id)
                          )
                      )
                  )";
        $params = [$unitId];

        if ($tab === 'delivery') {
            $sql .= " AND co.fulfillment_method = 'delivery'";
        } elseif ($tab === 'pickup') {
            $sql .= " AND co.fulfillment_method = 'pickup'";
        }

        if ($statusFilter !== 'all') {
            $sql .= " AND co.order_status = ?";
            $params[] = $statusFilter;
        }

        if ($q !== '') {
            $cleanQ = ltrim($q, '#');
            if (str_starts_with(strtoupper($cleanQ), 'ORD-')) {
                $cleanQ = substr($cleanQ, 4);
            }
            $isNum = ctype_digit($cleanQ);
            $sql .= " AND (co.order_id = ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR ua.email LIKE ? OR c.phone LIKE ?)";
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

        foreach ($orders as &$o) {
            $itStmt = $this->db->prepare(
                "SELECT oi.order_item_id, oi.quantity, oi.unit_price,
                        p.product_name, p.sku
                 FROM order_item oi
                 JOIN business_product bp ON bp.business_product_id = oi.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE oi.order_id = ?
                 ORDER BY oi.order_item_id ASC"
            );
            $itStmt->execute([(int)$o['order_id']]);
            $o['items'] = $itStmt->fetchAll();
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
                // Fetch directly if not in filtered list
                $sOrd = $this->db->prepare(
                    "SELECT co.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                            c.phone AS customer_phone, ua.email AS customer_email,
                            p.payment_id, p.payment_method, p.collection_channel, p.collected_at,
                            CASE WHEN COALESCE(ps.has_refunded, 0) = 1 THEN 'refunded' ELSE p.payment_status END AS payment_status,
                            CONCAT(deliv_emp.first_name, ' ', deliv_emp.last_name) AS delivery_employee_name
                     FROM customer_order co
                     JOIN customer c ON c.customer_id = co.customer_id
                     LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                     {$this->paymentSummaryJoinSql('order_id', 'co')}
                     LEFT JOIN employee deliv_emp ON deliv_emp.employee_id = co.delivery_employee_id
                     WHERE co.order_id = ? AND co.business_unit_id = ?
                     LIMIT 1"
                );
                $sOrd->execute([$viewOrderId, $unitId]);
                $selectedOrder = $sOrd->fetch() ?: null;
                if ($selectedOrder) {
                    $itStmt = $this->db->prepare(
                        "SELECT oi.order_item_id, oi.quantity, oi.unit_price,
                                p.product_name, p.sku
                         FROM order_item oi
                         JOIN business_product bp ON bp.business_product_id = oi.business_product_id
                         JOIN product p ON p.product_id = bp.product_id
                         WHERE oi.order_id = ?
                         ORDER BY oi.order_item_id ASC"
                    );
                    $itStmt->execute([$viewOrderId]);
                    $selectedOrder['items'] = $itStmt->fetchAll();
                }
            }
        }

        $this->render('petstore_receptionist/orders', [
            'title' => 'Orders · Pet Store Receptionist',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'orders' => $orders,
            'activeTab' => $tab,
            'statusFilter' => $statusFilter,
            'searchQuery' => $q,
            'selectedOrder' => $selectedOrder,
        ]);
    }

    /**
     * JSON / Modal endpoint to view an order's details.
     */
    public function showOrder(int $orderId): void
    {
        [$uid, , $unitId] = $this->checkPetStoreReceptionist();

        $stmt = $this->db->prepare(
            "SELECT co.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                    c.phone AS customer_phone, ua.email AS customer_email,
                    p.payment_id, p.payment_method, p.collection_channel, p.collected_at,
                    CASE WHEN COALESCE(ps.has_refunded, 0) = 1 THEN 'refunded' ELSE p.payment_status END AS payment_status,
                    CONCAT(deliv_emp.first_name, ' ', deliv_emp.last_name) AS delivery_employee_name
             FROM customer_order co
             JOIN customer c ON c.customer_id = co.customer_id
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             {$this->paymentSummaryJoinSql('order_id', 'co')}
             LEFT JOIN employee deliv_emp ON deliv_emp.employee_id = co.delivery_employee_id
             WHERE co.order_id = ? AND co.business_unit_id = ?
             LIMIT 1"
        );
        $stmt->execute([$orderId, $unitId]);
        $order = $stmt->fetch();

        if (!$order) {
            if ($this->wantsJson()) {
                http_response_code(404);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode(['ok' => false, 'error' => 'Order not found']);
                exit;
            }
            $this->notFound();
        }

        $itStmt = $this->db->prepare(
            "SELECT oi.order_item_id, oi.quantity, oi.unit_price,
                    p.product_name, p.sku
             FROM order_item oi
             JOIN business_product bp ON bp.business_product_id = oi.business_product_id
             JOIN product p ON p.product_id = bp.product_id
             WHERE oi.order_id = ?
             ORDER BY oi.order_item_id ASC"
        );
        $itStmt->execute([$orderId]);
        $order['items'] = $itStmt->fetchAll();

        if ($this->wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => true, 'order' => $order]);
            exit;
        }

        $tab = $order['fulfillment_method'] === 'delivery' ? 'delivery' : 'pickup';
        $this->redirect("/petstore/receptionist/orders?tab={$tab}&view={$orderId}");
    }

    /**
     * Pickup Order status transitions:
     * Pending → Confirmed → Ready for Pickup → Completed
     * Strictly restricted to pickup orders.
     */
    public function updatePickupStatus(int $orderId): void
    {
        [$uid, $empId, $unitId, , $isSuper] = $this->checkPetStoreReceptionist();
        CSRF::check();

        $targetStatus = strtolower(trim((string)($_POST['status'] ?? '')));

        if (in_array($targetStatus, ['confirmed', 'ready_for_pickup', 'completed'], true)) {
            $confirmCodCollected = $targetStatus === 'completed' && !empty($_POST['confirm_cod_collected']);
            $this->runOrderTransitionAction(function () use ($orderId, $unitId, $uid, $empId, $isSuper, $targetStatus, $confirmCodCollected): string {
                $service = new OrderReservationService($this->db);
                if ($targetStatus === 'confirmed') {
                    $result = $service->confirmPickupOrderAndReserve($orderId, $unitId, $uid);
                    return "Order #{$orderId} status updated to Confirmed ({$result['total_reserved_quantity']} unit(s) reserved).";
                }
                if ($targetStatus === 'ready_for_pickup') {
                    $service->markPickupOrderReady($orderId, $unitId, $uid);
                    return "Order #{$orderId} status updated to Ready For Pickup.";
                }

                $onCompleted = null;
                if ($confirmCodCollected) {
                    $staffEmpId = $this->resolveCollectorEmployeeId($uid, $empId, $isSuper);
                    $paymentService = new OrderPaymentService($this->db);
                    $onCompleted = static function () use ($paymentService, $orderId, $unitId, $staffEmpId, $uid): void {
                        $paymentService->collectPickupCodInTransaction($orderId, $unitId, $staffEmpId, $uid);
                    };
                }
                $result = $service->fulfillPickupOrder($orderId, $unitId, $uid, null, $onCompleted);
                $suffix = ($confirmCodCollected && empty($result['partial'])) ? ' COD payment confirmed as collected.' : '';
                return "Order #{$orderId} status updated to Completed ({$result['fulfilled_quantity']} unit(s) handed over).{$suffix}";
            }, 'pickup', $orderId);
            return;
        }

        $this->rejectUnsupportedOrderTransition(
            $orderId,
            $unitId,
            'pickup',
            $targetStatus,
            'pickup',
            'Only pickup orders may be updated by the receptionist. Delivery workflows belong to delivery personnel.',
            'Pending → Confirmed → Ready for Pickup → Completed'
        );
    }

    /**
     * Confirm COD cash collection on a completed in-store pickup order.
     */
    public function collectPickupCod(int $orderId): void
    {
        [$uid, $empId, $unitId, , $isSuper] = $this->checkPetStoreReceptionist();
        CSRF::check();

        $this->runOrderTransitionAction(function () use ($orderId, $unitId, $uid, $empId, $isSuper): string {
            $staffEmpId = $this->resolveCollectorEmployeeId($uid, $empId, $isSuper);
            $paymentService = new OrderPaymentService($this->db);
            $result = $paymentService->confirmPickupCodCollection($orderId, $unitId, $staffEmpId, $uid);
            return "Confirmed in-store pickup COD collection (₱" . number_format((float)$result['amount'], 2) . ") for Order #{$orderId}.";
        }, 'pickup', $orderId);
    }

    private function resolveCollectorEmployeeId(int $uid, int $empId, bool $isSuper): int
    {
        if (!$isSuper) {
            return $empId;
        }
        $stmt = $this->db->prepare("SELECT employee_id FROM employee WHERE user_account_id = ? AND employee_status = 'active' LIMIT 1");
        $stmt->execute([$uid]);
        $realEmpId = (int)($stmt->fetchColumn() ?: 0);
        if ($realEmpId <= 0) {
            throw new RuntimeException(
                'An active staff employee record is required to record payment collection.',
                OrderPaymentService::ERR_FORBIDDEN
            );
        }
        return $realEmpId;
    }

    /**
     * Delivery Order store-side status transitions:
     * Pending -> Confirmed (reserves FEFO stock) -> Processing (store preparation verified)
     * Strictly restricted to delivery orders.
     */
    public function updateDeliveryStatus(int $orderId): void
    {
        [$uid, , $unitId] = $this->checkPetStoreReceptionist();
        CSRF::check();

        $targetStatus = strtolower(trim((string)($_POST['status'] ?? '')));

        if (in_array($targetStatus, ['confirmed', 'processing'], true)) {
            $this->runOrderTransitionAction(function () use ($orderId, $unitId, $uid, $targetStatus): string {
                $service = new OrderReservationService($this->db);
                if ($targetStatus === 'confirmed') {
                    $result = $service->confirmDeliveryOrderAndReserve($orderId, $unitId, $uid);
                    return "Delivery order #{$orderId} confirmed ({$result['total_reserved_quantity']} unit(s) reserved).";
                }
                $service->markDeliveryOrderProcessing($orderId, $unitId, $uid);
                return "Delivery order #{$orderId} marked as Processing and ready for Delivery Personnel dispatch.";
            }, 'delivery', $orderId);
            return;
        }

        $this->rejectUnsupportedOrderTransition(
            $orderId,
            $unitId,
            'delivery',
            $targetStatus,
            'delivery',
            'Only delivery orders may use delivery confirmation and preparation actions.',
            'Pending → Confirmed → Processing'
        );
    }

    /**
     * Execute a reservation-backed order status transition with uniform 404/403/flash handling.
     */
    private function runOrderTransitionAction(callable $action, string $tab, int $orderId): void
    {
        try {
            $successMessage = (string)$action();
            $this->flash('success', $successMessage);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            if ($code === OrderReservationService::ERR_NOT_FOUND) {
                $this->notFound();
            }
            if ($code === OrderReservationService::ERR_FORBIDDEN) {
                $this->forbidden($e->getMessage());
            }
            $this->flash('error', $e->getMessage());
        }

        $this->redirect("/petstore/receptionist/orders?tab={$tab}&view=" . $orderId);
    }

    /**
     * Enforce existence, unit scope, and fulfillment method when an unsupported target status is posted.
     */
    private function rejectUnsupportedOrderTransition(
        int $orderId,
        int $unitId,
        string $expectedMethod,
        string $targetStatus,
        string $redirectTab,
        string $forbiddenMessage,
        string $flowDescription
    ): void {
        $stmt = $this->db->prepare("SELECT fulfillment_method, order_status FROM customer_order WHERE order_id = ? AND business_unit_id = ?");
        $stmt->execute([$orderId, $unitId]);
        $order = $stmt->fetch();

        if (!$order) {
            $this->notFound();
        }

        if ($order['fulfillment_method'] !== $expectedMethod) {
            $this->forbidden($forbiddenMessage);
        }

        $currentStatus = (string)$order['order_status'];
        $this->flash(
            'error',
            "Invalid transition for {$expectedMethod} order from '{$currentStatus}' to '{$targetStatus}'. Permitted flow: {$flowDescription}."
        );
        $this->redirect("/petstore/receptionist/orders?tab={$redirectTab}&view=" . $orderId);
    }

    /**
     * Coordinate delivery rescheduling:
     * - Order must be a Pet Store delivery order
     * - Current status must be Delivery Failed
     * - Maximum rescheduling period is two calendar days from today
     * - Order status remains Delivery Failed until Delivery Personnel dispatches it
     * - Receptionist cannot dispatch or retry delivery
     */
    public function rescheduleDelivery(int $orderId): void
    {
        [$uid, , $unitId] = $this->checkPetStoreReceptionist();
        CSRF::check();

        $stmt = $this->db->prepare("SELECT * FROM customer_order WHERE order_id = ? AND business_unit_id = ?");
        $stmt->execute([$orderId, $unitId]);
        $order = $stmt->fetch();

        if (!$order) {
            $this->notFound();
        }

        if ($order['fulfillment_method'] !== 'delivery') {
            $this->flash('error', 'Only delivery orders can be rescheduled.');
            $this->redirect('/petstore/receptionist/orders?tab=delivery');
            return;
        }

        if ($order['order_status'] !== 'delivery_failed') {
            $this->flash('error', 'Only orders in Delivery Failed status can be rescheduled.');
            $this->redirect('/petstore/receptionist/orders?tab=delivery&view=' . $orderId);
            return;
        }

        $rescheduledDateStr = trim((string)($_POST['rescheduled_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $rescheduledDateStr)) {
            $this->flash('error', 'Please enter a valid rescheduling date in YYYY-MM-DD format.');
            $this->redirect('/petstore/receptionist/orders?tab=delivery&view=' . $orderId);
            return;
        }

        $phTz = new DateTimeZone('Asia/Manila');
        $today = new DateTimeImmutable('today', $phTz);
        $maxDate = $today->modify('+2 days');
        $targetDate = DateTimeImmutable::createFromFormat('!Y-m-d', $rescheduledDateStr, $phTz);

        if (!$targetDate || $targetDate->format('Y-m-d') !== $rescheduledDateStr || $targetDate < $today || $targetDate > $maxDate) {
            $this->flash('error', 'Rescheduled date must be within 2 calendar days from today (between ' . $today->format('M j, Y') . ' and ' . $maxDate->format('M j, Y') . ').');
            $this->redirect('/petstore/receptionist/orders?tab=delivery&view=' . $orderId);
            return;
        }

        // Update rescheduled_date; preserve order_status as 'delivery_failed'
        $up = $this->db->prepare("UPDATE customer_order SET rescheduled_date = ?, updated_at = NOW() WHERE order_id = ?");
        $up->execute([$rescheduledDateStr, $orderId]);

        Audit::log('petstore_receptionist.delivery.reschedule', "Coordinated delivery rescheduling for Order #{$orderId} to {$rescheduledDateStr}. Status remains Delivery Failed until dispatched.", $unitId, 'customer_order', $orderId, $uid);
        $this->flash('success', "Order #{$orderId} delivery rescheduled to " . $targetDate->format('M j, Y') . ". Order status remains Delivery Failed until dispatched by delivery personnel.");
        $this->redirect('/petstore/receptionist/orders?tab=delivery&view=' . $orderId);
    }

    // ==========================================
    // 3. EMPLOYEES PAGE (READ-ONLY)
    // ==========================================

    /**
     * Employees Page:
     * - Pet Store employees only (business_unit_id = 1)
     * - Read-only personnel roster
     * - No role, position, or status editing controls
     */
    public function employees(): void
    {
        [$uid, , $unitId, $unitName, $isSuper] = $this->checkPetStoreReceptionist();

        $q = trim((string)($_GET['q'] ?? ''));
        $posFilter = trim((string)($_GET['position'] ?? ''));
        $wsFilter = trim((string)($_GET['work_status'] ?? ''));

        $sql = "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status, e.created_at,
                       ua.email,
                       COALESCE(ews.work_status, 'Free') AS work_status
                FROM employee e
                JOIN user_account ua ON ua.user_account_id = e.user_account_id
                LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
                WHERE e.business_unit_id = ?";
        $params = [$unitId];

        if ($q !== '') {
            $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR ua.email LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($posFilter !== '') {
            $sql .= " AND e.position = ?";
            $params[] = $posFilter;
        }
        if ($wsFilter !== '') {
            $sql .= " AND COALESCE(ews.work_status, 'Free') = ?";
            $params[] = $wsFilter;
        }

        $sql .= " ORDER BY e.last_name ASC, e.first_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $employees = $stmt->fetchAll();

        // Calculate daily appointment assignments count for current date
        $dailyApptStmt = $this->db->prepare(
            "SELECT ps.performer_employee_id, COUNT(DISTINCT a.appointment_id) AS appt_cnt
             FROM performed_service ps
             JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
             JOIN appointment a ON a.appointment_id = ap.appointment_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date = CURDATE()
               AND ps.performer_employee_id IS NOT NULL
             GROUP BY ps.performer_employee_id"
        );
        $dailyApptStmt->execute([$unitId]);
        $dailyCounts = [];
        foreach ($dailyApptStmt->fetchAll() as $row) {
            $dailyCounts[(int)$row['performer_employee_id']] = (int)$row['appt_cnt'];
        }

        foreach ($employees as &$empRow) {
            $empRow['daily_assignment_count'] = $dailyCounts[(int)$empRow['employee_id']] ?? 0;
        }
        unset($empRow);

        // Fetch distinct positions for filtering
        $posStmt = $this->db->prepare(
            "SELECT DISTINCT position FROM employee WHERE business_unit_id = ? AND position IS NOT NULL AND position != '' ORDER BY position ASC"
        );
        $posStmt->execute([$unitId]);
        $positions = $posStmt->fetchAll(PDO::FETCH_COLUMN);

        $this->render('petstore_receptionist/employees', [
            'title' => 'Employees · Pet Store Receptionist',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'employees' => $employees,
            'positions' => $positions,
            'searchQuery' => $q,
            'positionFilter' => $posFilter,
            'workStatusFilter' => $wsFilter,
        ]);
    }

    // ==========================================
    // 4. COD REMITTANCES PAGE
    // ==========================================

    /**
     * Authorize Pet Store Receptionist Remittances viewer:
     * - Super Administrators always remain in read-only oversight mode (`is_receptionist = false`, `emp_id = 0`),
     *   even if the account also holds an active Pet Store employee record and RECEPTIONIST role.
     * - Permits non-Super-Admin active Pet Store employee (unit 1) with active RECEPTIONIST role as operational receptionist.
     *
     * @return array{uid:int, emp_id:int, unit_id:int, unit_name:string, is_receptionist:bool, is_super:bool}
     */
    private function requireReceptionistRemittanceViewer(): array
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/petstore/receptionist/remittances');
            $this->redirect('/login');
        }

        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');

        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated Pet Store Receptionist session is required.');
        }

        if ($this->isSuperAdmin()) {
            $accStmt = $this->db->prepare(
                "SELECT 1
                 FROM user_account ua
                 JOIN business_unit bu ON bu.business_unit_id = ? AND bu.business_unit_status = 'active'
                 WHERE ua.user_account_id = ? AND ua.account_status = 'active'
                 LIMIT 1"
            );
            $accStmt->execute([self::PET_STORE_UNIT_ID, (int)$uid]);
            if (!$accStmt->fetchColumn()) {
                $this->forbidden('Access denied: active account and business unit are required.');
            }

            return [
                'uid' => (int)$uid,
                'emp_id' => 0,
                'unit_id' => self::PET_STORE_UNIT_ID,
                'unit_name' => Units::PET_STORE,
                'is_receptionist' => false,
                'is_super' => true,
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT e.employee_id
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id
                              AND e.employee_status = 'active'
                              AND e.business_unit_id = ?
             JOIN account_role ar ON ar.user_account_id = ua.user_account_id
                                  AND ar.business_unit_id = e.business_unit_id
             JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'RECEPTIONIST'
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
                'unit_name' => Units::PET_STORE,
                'is_receptionist' => true,
                'is_super' => false,
            ];
        }

        $this->forbidden('Access denied: Pet Store Receptionist privileges are required.');
    }

    /**
     * Authorize operational Receptionist remittance handover mutations.
     * Rejects all Super Administrators as well as accounts lacking an active Pet Store Receptionist assignment.
     *
     * @return array{uid:int, emp_id:int, unit_id:int, unit_name:string, is_receptionist:bool, is_super:bool}
     */
    private function requireReceptionistOperator(): array
    {
        $auth = $this->requireReceptionistRemittanceViewer();
        if ($auth['is_super'] || !$auth['is_receptionist'] || $auth['emp_id'] <= 0) {
            $this->forbidden('Operational remittance handover actions require an active Pet Store Receptionist role assignment.');
        }
        return $auth;
    }

    /**
     * Dedicated Pet Store Receptionist COD Remittances page:
     * Displays courier-submitted COD remittance batches and their active per-order allocations
     * (using remittable purchase subtotal excluding delivery fees) for individual handover completion.
     */
    public function remittances(): void
    {
        $auth = $this->requireReceptionistRemittanceViewer();
        $unitId = $auth['unit_id'];
        $unitName = $auth['unit_name'];

        $q = trim((string)($_GET['q'] ?? ''));

        $batchSql = "SELECT r.remittance_id, r.remittance_reference, r.business_unit_id,
                            r.courier_employee_id, r.expected_total, r.remitted_total,
                            r.verified_total, r.remittance_status, r.submitted_at,
                            r.verified_by_employee_id, r.verified_at, r.notes,
                            CONCAT(ce.first_name, ' ', ce.last_name) AS courier_name
                     FROM courier_cod_remittance r
                     JOIN employee ce ON ce.employee_id = r.courier_employee_id
                     WHERE r.business_unit_id = ?
                       AND r.remittance_status IN ('submitted', 'completed', 'verified')
                       AND EXISTS (
                           SELECT 1
                           FROM courier_cod_remittance_allocation a_ex
                           WHERE a_ex.remittance_id = r.remittance_id
                             AND a_ex.allocation_status = 'active'
                       )";
        $batchParams = [$unitId];

        if ($q !== '') {
            $cleanQ = ltrim($q, '#');
            if (str_starts_with(strtoupper($cleanQ), 'ORD-')) {
                $cleanQ = substr($cleanQ, 4);
            }
            $numId = ctype_digit($cleanQ) ? (int)$cleanQ : 0;
            $batchSql .= " AND (
                r.remittance_reference LIKE ?
                OR ce.first_name LIKE ?
                OR ce.last_name LIKE ?
                OR CONCAT(ce.first_name, ' ', ce.last_name) LIKE ?
                OR EXISTS (
                    SELECT 1
                    FROM courier_cod_remittance_allocation a_q
                    WHERE a_q.remittance_id = r.remittance_id
                      AND a_q.allocation_status = 'active'
                      AND a_q.order_id = ?
                )
            )";
            $batchParams[] = "%$q%";
            $batchParams[] = "%$q%";
            $batchParams[] = "%$q%";
            $batchParams[] = "%$q%";
            $batchParams[] = $numId;
        }

        $batchSql .= " ORDER BY CASE r.remittance_status
                                    WHEN 'submitted' THEN 0
                                    WHEN 'completed' THEN 1
                                    ELSE 2
                                END ASC,
                                r.submitted_at DESC,
                                r.remittance_id DESC";

        $batchStmt = $this->db->prepare($batchSql);
        $batchStmt->execute($batchParams);
        $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($batches) {
            $batchIds = array_map(static fn(array $b): int => (int)$b['remittance_id'], $batches);
            $placeholders = implode(',', array_fill(0, count($batchIds), '?'));

            $allocStmt = $this->db->prepare(
                "SELECT a.allocation_id, a.remittance_id, a.payment_id, a.order_id, a.business_unit_id,
                        a.expected_amount, a.allocated_amount, a.allocation_status,
                        a.handover_status, a.completed_by_employee_id, a.completed_at,
                        a.verification_status, a.verified_by_employee_id, a.verified_at, a.verification_mode,
                        co.order_date, co.subtotal_amount, co.delivery_fee, co.total_amount,
                        CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        CONCAT(ec.first_name, ' ', ec.last_name) AS completed_by_name,
                        CONCAT(ev.first_name, ' ', ev.last_name) AS verified_by_name
                 FROM courier_cod_remittance_allocation a
                 JOIN customer_order co ON co.order_id = a.order_id AND co.business_unit_id = a.business_unit_id
                 JOIN customer c ON c.customer_id = co.customer_id
                 LEFT JOIN employee ec ON ec.employee_id = a.completed_by_employee_id
                 LEFT JOIN employee ev ON ev.employee_id = a.verified_by_employee_id
                 WHERE a.remittance_id IN ({$placeholders})
                   AND a.business_unit_id = ?
                   AND a.allocation_status = 'active'
                 ORDER BY a.allocation_id ASC"
            );
            $allocStmt->execute([...$batchIds, $unitId]);
            $allocsByBatch = [];
            foreach ($allocStmt->fetchAll(PDO::FETCH_ASSOC) as $aRow) {
                $allocsByBatch[(int)$aRow['remittance_id']][] = $aRow;
            }

            foreach ($batches as &$b) {
                $bAllocs = $allocsByBatch[(int)$b['remittance_id']] ?? [];
                $b['allocations'] = $bAllocs;
                $b['allocation_count'] = count($bAllocs);
                $awaitingCnt = 0;
                $completedCnt = 0;
                $verifiedCnt = 0;
                foreach ($bAllocs as $al) {
                    if ((string)$al['handover_status'] === 'submitted') {
                        $awaitingCnt++;
                    } else {
                        $completedCnt++;
                    }
                    if ((string)$al['verification_status'] === 'verified') {
                        $verifiedCnt++;
                    }
                }
                $b['awaiting_handover_count'] = $awaitingCnt;
                $b['completed_handover_count'] = $completedCnt;
                $b['verified_count'] = $verifiedCnt;
            }
            unset($b);
        }

        $this->render('petstore_receptionist/remittances', [
            'title' => 'Remittances · Pet Store Receptionist',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isReceptionist' => $auth['is_receptionist'],
            'isSuper' => $auth['is_super'],
            'empId' => $auth['emp_id'],
            'remittanceBatches' => $batches,
            'searchQuery' => $q,
        ]);
    }

    /**
     * Complete individual COD remittance allocation handover as an active Pet Store Receptionist.
     */
    public function completeRemittanceAllocation(int $allocationId): void
    {
        CSRF::check();
        $auth = $this->requireReceptionistOperator();

        if ($allocationId <= 0) {
            $this->flash('error', 'Invalid COD remittance allocation selected.');
            $this->redirect('/petstore/receptionist/remittances');
            return;
        }

        try {
            $paymentService = new OrderPaymentService($this->db);
            $result = $paymentService->completeRemittanceAllocation(
                $allocationId,
                $auth['uid'],
                self::PET_STORE_UNIT_ID
            );

            $amtFormatted = number_format((float)$result['allocated_amount'], 2);
            if (($result['verification_status'] ?? '') === 'verified') {
                $this->flash(
                    'success',
                    "Handover completed and Verified for Order #ORD-{$result['order_id']} (₱{$amtFormatted}) in batch {$result['remittance_reference']}."
                );
            } else {
                $this->flash(
                    'success',
                    "Handover Completed for Order #ORD-{$result['order_id']} (₱{$amtFormatted}) in batch {$result['remittance_reference']}."
                );
            }
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            if ($code === OrderPaymentService::ERR_NOT_FOUND) {
                $this->notFound();
            }
            if ($code === OrderPaymentService::ERR_FORBIDDEN) {
                $this->forbidden($e->getMessage());
            }
            $this->flash('error', $e->getMessage());
        }

        $this->redirect('/petstore/receptionist/remittances');
    }
}
