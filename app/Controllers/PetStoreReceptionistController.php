<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\Units;
use DateTime;
use PDO;
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

        // 3. Recent Sales (Paginated server-side directly inside the card, sorted newest first)
        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM sale s WHERE s.business_unit_id = ? AND s.sale_status = 'completed'"
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
                    p.payment_method, p.payment_status,
                    (SELECT COUNT(*) FROM sale_item si WHERE si.sale_id = s.sale_id) AS item_count
             FROM sale s
             LEFT JOIN customer c ON c.customer_id = s.customer_id
             LEFT JOIN payment p ON p.sale_id = s.sale_id
             WHERE s.business_unit_id = ? AND s.sale_status = 'completed'
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
                    (COALESCE(i.on_hand, 0) - COALESCE(i.committed, 0)) AS available_stock
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_unit_id = ? AND bp.product_status = 'active'
             ORDER BY p.product_name ASC"
        );
        $prodStmt->execute([$unitId]);
        $availableProducts = $prodStmt->fetchAll();

        $this->render('petstore_receptionist/sales', [
            'title' => 'Sales · Pet Store Receptionist',
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
                       (COALESCE(i.on_hand, 0) - COALESCE(i.committed, 0)) AS available_stock
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
            $stock = max(0, (int)$r['available_stock']);
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
                $this->redirect('/petstore/receptionist/sales');
                return;
            }
            $chkCust = $this->db->prepare("SELECT customer_id, first_name, last_name FROM customer WHERE customer_id = ? AND customer_status = 'active' LIMIT 1");
            $chkCust->execute([(int)$rawCustId]);
            $foundCust = $chkCust->fetch(PDO::FETCH_ASSOC);
            if (!$foundCust) {
                $this->flash('error', 'The selected registered customer could not be found or is inactive.');
                $this->redirect('/petstore/receptionist/sales');
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
            $this->redirect('/petstore/receptionist/sales');
            return;
        }

        $lineItems = [];
        $totalAmount = 0.0;

        foreach ($productIds as $idx => $rawBpId) {
            $bpId = (int)$rawBpId;
            $qty = isset($quantities[$idx]) ? (int)$quantities[$idx] : 0;

            if ($bpId <= 0 || $qty <= 0) {
                continue;
            }

            // Verify product belongs to Pet Store and fetch current price & inventory
            $pStmt = $this->db->prepare(
                "SELECT bp.business_product_id, bp.selling_price, p.product_name,
                        i.inventory_id, COALESCE(i.on_hand, 0) AS on_hand, COALESCE(i.committed, 0) AS committed
                 FROM business_product bp
                 JOIN product p ON p.product_id = bp.product_id
                 LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
                 WHERE bp.business_product_id = ? AND bp.business_unit_id = ? AND bp.product_status = 'active'"
            );
            $pStmt->execute([$bpId, $unitId]);
            $prod = $pStmt->fetch();

            if (!$prod) {
                $this->flash('error', "Selected product (ID #{$bpId}) does not belong to the Pet Store or is inactive.");
                $this->redirect('/petstore/receptionist/sales');
                return;
            }

            $availStock = (int)$prod['on_hand'] - (int)$prod['committed'];
            if ($qty > $availStock) {
                $this->flash('error', "Insufficient stock for '{$prod['product_name']}'. Available: {$availStock}, requested: {$qty}.");
                $this->redirect('/petstore/receptionist/sales');
                return;
            }

            $unitPrice = (float)$prod['selling_price'];
            $lineTotal = round($unitPrice * $qty, 2);
            $totalAmount += $lineTotal;

            $lineItems[] = [
                'bp_id' => $bpId,
                'product_name' => (string)$prod['product_name'],
                'inventory_id' => (int)$prod['inventory_id'],
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        if (empty($lineItems)) {
            $this->flash('error', 'At least one valid product line with quantity greater than zero is required.');
            $this->redirect('/petstore/receptionist/sales');
            return;
        }

        try {
            $this->db->beginTransaction();

            // 1. Insert sale record
            $sStmt = $this->db->prepare(
                "INSERT INTO sale (business_unit_id, branch_id, customer_id, employee_id, sale_date, total_amount, sale_status)
                 VALUES (?, 1, ?, ?, NOW(), ?, 'completed')"
            );
            $sStmt->execute([$unitId, $customerId, $empId ?: 9, $totalAmount]);
            $saleId = (int)$this->db->lastInsertId();

            // 2. Insert sale items, update inventory on_hand, and record inventory_movement
            $siStmt = $this->db->prepare(
                "INSERT INTO sale_item (sale_id, business_product_id, quantity, unit_price)
                 VALUES (?, ?, ?, ?)"
            );
            $invStmt = $this->db->prepare(
                "UPDATE inventory SET on_hand = on_hand - ?, updated_at = NOW() WHERE inventory_id = ?"
            );
            $movStmt = $this->db->prepare(
                "INSERT INTO inventory_movement (inventory_id, sale_item_id, movement_type, quantity, notes)
                 VALUES (?, ?, 'sale', ?, ?)"
            );

            foreach ($lineItems as $item) {
                $siStmt->execute([$saleId, $item['bp_id'], $item['qty'], $item['unit_price']]);
                $saleItemId = (int)$this->db->lastInsertId();

                $invStmt->execute([$item['qty'], $item['inventory_id']]);
                $movStmt->execute([$item['inventory_id'], $saleItemId, -$item['qty'], "Pet Store Receptionist sale #{$saleId}"]);
            }

            // 3. Insert payment record
            $payStmt = $this->db->prepare(
                "INSERT INTO payment (sale_id, payment_type, amount, payment_date, payment_method, payment_status)
                 VALUES (?, 'payment', ?, NOW(), ?, 'completed')"
            );
            $payStmt->execute([$saleId, $totalAmount, $paymentMethod]);

            $this->db->commit();

            Audit::log('petstore_receptionist.sale.create', "Recorded new sale #{$saleId} for ₱" . number_format($totalAmount, 2), $unitId, 'sale', $saleId, $uid);
            $this->flash('success', "Sale #{$saleId} confirmed successfully! Total: ₱" . number_format($totalAmount, 2));
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->flash('error', 'Failed to confirm sale: ' . $e->getMessage());
        }

        $this->redirect('/petstore/receptionist/sales');
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
                       p.payment_method, p.payment_status,
                       CONCAT(deliv_emp.first_name, ' ', deliv_emp.last_name) AS delivery_employee_name
                FROM customer_order co
                JOIN customer c ON c.customer_id = co.customer_id
                LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                LEFT JOIN payment p ON p.order_id = co.order_id
                LEFT JOIN employee deliv_emp ON deliv_emp.employee_id = co.delivery_employee_id
                WHERE co.business_unit_id = ?";
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
                            p.payment_method, p.payment_status,
                            CONCAT(deliv_emp.first_name, ' ', deliv_emp.last_name) AS delivery_employee_name
                     FROM customer_order co
                     JOIN customer c ON c.customer_id = co.customer_id
                     LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                     LEFT JOIN payment p ON p.order_id = co.order_id
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
                    p.payment_method, p.payment_status,
                    CONCAT(deliv_emp.first_name, ' ', deliv_emp.last_name) AS delivery_employee_name
             FROM customer_order co
             JOIN customer c ON c.customer_id = co.customer_id
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             LEFT JOIN payment p ON p.order_id = co.order_id
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
        [$uid, , $unitId] = $this->checkPetStoreReceptionist();
        CSRF::check();

        $stmt = $this->db->prepare("SELECT * FROM customer_order WHERE order_id = ? AND business_unit_id = ?");
        $stmt->execute([$orderId, $unitId]);
        $order = $stmt->fetch();

        if (!$order) {
            $this->notFound();
        }

        // Strict Server-Side Guard: Delivery orders are blocked from this action
        if ($order['fulfillment_method'] !== 'pickup') {
            $this->forbidden('Only pickup orders may be updated by the receptionist. Delivery workflows belong to delivery personnel.');
        }

        $targetStatus = strtolower(trim((string)($_POST['status'] ?? '')));
        $currentStatus = (string)$order['order_status'];

        // Strict Pickup state machine
        $validTransitions = [
            'pending' => ['confirmed'],
            'confirmed' => ['ready_for_pickup'],
            'ready_for_pickup' => ['completed'],
        ];

        if (!isset($validTransitions[$currentStatus]) || !in_array($targetStatus, $validTransitions[$currentStatus], true)) {
            $this->flash('error', "Invalid transition for pickup order from '{$currentStatus}' to '{$targetStatus}'. Permitted flow: Pending → Confirmed → Ready for Pickup → Completed.");
            $this->redirect('/petstore/receptionist/orders?tab=pickup&view=' . $orderId);
            return;
        }

        $up = $this->db->prepare("UPDATE customer_order SET order_status = ?, updated_at = NOW() WHERE order_id = ?");
        $up->execute([$targetStatus, $orderId]);

        Audit::log('petstore_receptionist.order.pickup_status', "Updated pickup order #{$orderId} status from {$currentStatus} to {$targetStatus}.", $unitId, 'customer_order', $orderId, $uid);
        $this->flash('success', "Order #{$orderId} status updated to " . ucwords(str_replace('_', ' ', $targetStatus)) . '.');
        $this->redirect('/petstore/receptionist/orders?tab=pickup&view=' . $orderId);
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

        $today = new DateTime('today');
        $maxDate = (clone $today)->modify('+2 days');
        $targetDate = DateTime::createFromFormat('Y-m-d', $rescheduledDateStr);

        if (!$targetDate || $targetDate < $today || $targetDate > $maxDate) {
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
}
