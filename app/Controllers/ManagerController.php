<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\OtpService;
use App\Models\Units;
use PDO;

final class ManagerController extends Controller
{
    private function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }
    /**
     * Helper to enforce Business Manager privileges.
     * Returns: [$uid, $employeeId, $unitId, $unitName, $isSuper]
     */
    private function checkBusinessManager(?int $routeUnitId = null): array
    {
        return $this->requireBusinessManager($routeUnitId);
    }

    /**
     * Helper for Inventory access: permits Business Manager, Inventory Manager, or Super Admin.
     * Returns: [$uid, $employeeId, $unitId, $unitName, $isSuper, $isInventoryManagerOnly]
     */
    private function checkManagerOrInventoryManager(?int $routeUnitId = null): array
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            Session::set('return_to', $_SERVER['REQUEST_URI'] ?? '/manager/inventory');
            $this->redirect('/login');
        }

        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');
        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated session is required.');
        }

        $queryUnitId = isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
        $targetUnitId = $routeUnitId ?? $queryUnitId;

        if ($this->isSuperAdmin()) {
            $u = $targetUnitId !== null ? (Units::find($targetUnitId) ?: $this->notFound()) : (Units::find(Units::petStoreId()) ?: Units::all()[0]);
            return [(int)$uid, (int)Session::get('employee_id', 0), (int)$u['business_unit_id'], (string)$u['business_unit_name'], true, false];
        }

        $s = $this->db->prepare(
            "SELECT e.employee_id, bu.business_unit_id, bu.business_unit_name, r.role_name
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id AND e.employee_status = 'active'
             JOIN account_role ar ON ar.user_account_id = ua.user_account_id
             JOIN role r ON r.role_id = ar.role_id AND r.role_name IN ('BUSINESS_MANAGER', 'INVENTORY_MANAGER')
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id AND bu.business_unit_status = 'active'
             WHERE ua.user_account_id = ? AND ua.account_status = 'active'
             ORDER BY bu.business_unit_id"
        );
        $s->execute([(int)$uid]);
        $rows = $s->fetchAll();

        if (!$rows) {
            $this->forbidden('Business Manager or Inventory Manager privileges are required.');
        }

        $byUnitId = [];
        $rolesByUnit = [];
        foreach ($rows as $r) {
            $bId = (int)$r['business_unit_id'];
            $byUnitId[$bId] = $r;
            $rolesByUnit[$bId][] = (string)$r['role_name'];
        }

        if ($targetUnitId !== null && !isset($byUnitId[$targetUnitId])) {
            $this->forbidden('Access denied: you cannot access another business unit.');
        }

        $chosen = $targetUnitId !== null ? $byUnitId[$targetUnitId] : $rows[0];
        $chosenUnitId = (int)$chosen['business_unit_id'];
        $unitRoles = $rolesByUnit[$chosenUnitId] ?? [];
        $isInvOnly = !in_array('BUSINESS_MANAGER', $unitRoles, true) && in_array('INVENTORY_MANAGER', $unitRoles, true);

        return [(int)$uid, (int)$chosen['employee_id'], $chosenUnitId, (string)$chosen['business_unit_name'], false, $isInvOnly];
    }

    // ==========================================
    // 1. OVERVIEW PAGE
    // ==========================================
    public function dashboard(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);

        // If not Pet Store (e.g. Veterinary Clinic or Grooming Services), preserve existing legacy dashboard
        if ((int)$unitId !== 1) {
            $this->renderLegacyDashboard($unitId, $unitName, $isSuper);
            return;
        }

        // Pet Store Overview Queries
        // 1. Employees On Duty (work_status IN ('On Duty', 'Free'), exclude 'Not Present')
        $dutyStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position,
                    COALESCE(ews.work_status, 'Free') AS work_status,
                    ua.email
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
             WHERE e.business_unit_id = ?
               AND e.employee_status = 'active'
               AND COALESCE(ews.work_status, 'Free') IN ('On Duty', 'Free')
             ORDER BY CASE WHEN COALESCE(ews.work_status, 'Free') = 'On Duty' THEN 0 ELSE 1 END,
                      e.last_name ASC, e.first_name ASC"
        );
        $dutyStmt->execute([$unitId]);
        $employeesOnDuty = $dutyStmt->fetchAll();

        // 2. Pending Refund Requests
        $refundsStmt = $this->db->prepare(
            "SELECT rr.refund_request_id, rr.refund_reference, rr.business_unit_id,
                    rr.customer_id, rr.order_id, rr.sale_id, rr.channel,
                    rr.requested_amount, rr.request_status, rr.created_at,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                    c.phone AS customer_phone,
                    CASE WHEN rr.channel = 'online' THEN CONCAT('#ORD-', rr.order_id)
                         ELSE CONCAT('#REC-S-', rr.sale_id) END AS transaction_ref,
                    CASE WHEN rr.channel = 'online' THEN 'Online Order' ELSE 'Walk-in Sale' END AS channel_label
             FROM refund_request rr
             JOIN customer c ON c.customer_id = rr.customer_id
             WHERE rr.business_unit_id = ?
               AND rr.request_status = 'pending'
             ORDER BY rr.created_at DESC"
        );
        $refundsStmt->execute([$unitId]);
        $pendingRefunds = $refundsStmt->fetchAll();

        // 3. Recent Sales: Unified List of Walk-in Sales & Online Orders
        $walkInStmt = $this->db->prepare(
            "SELECT s.sale_id, s.sale_date, s.total_amount, s.sale_status,
                    COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Walk-in Customer') AS customer_name,
                    (SELECT COUNT(*) FROM sale_item si WHERE si.sale_id = s.sale_id) AS item_count
             FROM sale s
             LEFT JOIN customer c ON c.customer_id = s.customer_id
             WHERE s.business_unit_id = ? AND s.sale_status = 'completed'
             ORDER BY s.sale_date DESC, s.sale_id DESC
             LIMIT 10"
        );
        $walkInStmt->execute([$unitId]);
        $recentWalkInSales = $walkInStmt->fetchAll();

        $onlineStmt = $this->db->prepare(
            "SELECT co.order_id, co.order_date, co.total_amount, co.order_status, co.fulfillment_method,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                    (SELECT COUNT(*) FROM order_item oi WHERE oi.order_id = co.order_id) AS item_count
             FROM customer_order co
             JOIN customer c ON c.customer_id = co.customer_id
             WHERE co.business_unit_id = ? AND co.order_status IN ('completed', 'confirmed', 'processing', 'ready_for_pickup', 'out_for_delivery')
             ORDER BY co.order_date DESC, co.order_id DESC
             LIMIT 10"
        );
        $onlineStmt->execute([$unitId]);
        $recentOnlineOrders = $onlineStmt->fetchAll();

        // Build unified Recent Sales list (one row per sale, newest first)
        $recentSales = [];
        foreach ($recentWalkInSales as $ws) {
            $recentSales[] = [
                'channel' => 'Walk-in',
                'ref_code' => '#REC-S-' . $ws['sale_id'],
                'ref_id' => (int)$ws['sale_id'],
                'tab' => 'walkin',
                'customer_name' => $ws['customer_name'],
                'item_count' => (int)$ws['item_count'],
                'total_amount' => (float)$ws['total_amount'],
                'date' => $ws['sale_date'],
                'status' => $ws['sale_status'],
            ];
        }
        foreach ($recentOnlineOrders as $oo) {
            $recentSales[] = [
                'channel' => 'Online',
                'ref_code' => '#ORD-' . $oo['order_id'],
                'ref_id' => (int)$oo['order_id'],
                'tab' => 'online',
                'customer_name' => $oo['customer_name'],
                'item_count' => (int)$oo['item_count'],
                'total_amount' => (float)$oo['total_amount'],
                'date' => $oo['order_date'],
                'status' => $oo['order_status'],
            ];
        }

        // Sort unified list newest first
        usort($recentSales, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });

        $this->render('manager/overview', [
            'title' => "$unitName · Business Manager Overview",
            'activeTab' => 'overview',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'employeesOnDuty' => $employeesOnDuty,
            'pendingRefunds' => $pendingRefunds,
            'recentWalkInSales' => $recentWalkInSales,
            'recentOnlineOrders' => $recentOnlineOrders,
            'recentSales' => $recentSales,
        ]);
    }

    /**
     * Legacy dashboard for non-petstore units (Veterinary Clinic, Grooming Services)
     */
    private function renderLegacyDashboard(int $unitId, string $unitName, bool $isSuper): void
    {
        $productsStmt = $this->db->prepare(
            "SELECT bp.business_product_id, bp.selling_price, bp.product_status,
                    p.product_name, p.product_category,
                    COALESCE(i.on_hand, 0) AS on_hand,
                    COALESCE(i.committed, 0) AS committed,
                    GREATEST(COALESCE(i.on_hand, 0) - COALESCE(i.committed, 0), 0) AS available
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_unit_id = ?
             ORDER BY p.product_name"
        );
        $productsStmt->execute([$unitId]);
        $products = $productsStmt->fetchAll();

        $ordersStmt = $this->db->prepare(
            "SELECT co.order_id, co.order_date, co.fulfillment_method, co.order_status, co.total_amount,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name
             FROM customer_order co
             JOIN customer c ON c.customer_id = co.customer_id
             WHERE co.business_unit_id = ?
             ORDER BY co.order_date DESC, co.order_id DESC
             LIMIT 20"
        );
        $ordersStmt->execute([$unitId]);
        $orders = $ordersStmt->fetchAll();

        $apptsStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_type, st.status_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             WHERE a.business_unit_id = ?
             ORDER BY a.preferred_date DESC, a.appointment_id DESC
             LIMIT 20"
        );
        $apptsStmt->execute([$unitId]);
        $appointments = $apptsStmt->fetchAll();

        $staffStmt = $this->db->prepare(
            "SELECT e.first_name, e.last_name, e.position, e.employee_status,
                    GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS roles
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN account_role ar ON ar.user_account_id = ua.user_account_id AND ar.business_unit_id = ?
             LEFT JOIN role r ON r.role_id = ar.role_id
             WHERE ua.is_super_admin = 0
               AND (e.business_unit_id = ? OR ar.business_unit_id = ?)
             GROUP BY e.employee_id, e.first_name, e.last_name, e.position, e.employee_status
             ORDER BY e.last_name"
        );
        $staffStmt->execute([$unitId, $unitId, $unitId]);
        $unitStaff = $staffStmt->fetchAll();

        $this->render('manager/dashboard', [
            'title' => "$unitName · Business Manager",
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'products' => $products,
            'orders' => $orders,
            'appointments' => $appointments,
            'unitStaff' => $unitStaff,
        ]);
    }

    // ==========================================
    // 2. SALES PAGE & REFUND MANAGEMENT
    // ==========================================
    public function sales(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);

        $tab = isset($_GET['tab']) && in_array($_GET['tab'], ['online', 'refunds'], true) ? $_GET['tab'] : 'walkin';
        $q = trim((string)($_GET['q'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $viewId = isset($_GET['view']) && ctype_digit((string)$_GET['view']) ? (int)$_GET['view'] : null;

        // Fetch Walk-in Sales
        $walkInSales = [];
        $walkSql = "SELECT s.sale_id, s.sale_date, s.total_amount, s.sale_status,
                           COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Walk-in Customer') AS customer_name,
                           (SELECT COUNT(*) FROM sale_item si WHERE si.sale_id = s.sale_id) AS item_count,
                           p.payment_method, p.payment_status
                    FROM sale s
                    LEFT JOIN customer c ON c.customer_id = s.customer_id
                    LEFT JOIN payment p ON p.sale_id = s.sale_id
                    WHERE s.business_unit_id = ?";
        $walkParams = [$unitId];
        if ($tab === 'walkin' && $q !== '') {
            $walkSql .= " AND (s.sale_id = ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
            $walkParams[] = ctype_digit($q) ? (int)$q : 0;
            $walkParams[] = "%$q%";
            $walkParams[] = "%$q%";
        }
        $walkSql .= " ORDER BY s.sale_date DESC, s.sale_id DESC";
        $wStmt = $this->db->prepare($walkSql);
        $wStmt->execute($walkParams);
        $walkInSales = $wStmt->fetchAll();

        // Attach items for each walk-in sale
        foreach ($walkInSales as &$ws) {
            $itStmt = $this->db->prepare(
                "SELECT si.sale_item_id, si.quantity, si.unit_price,
                        p.product_name, p.sku
                 FROM sale_item si
                 JOIN business_product bp ON bp.business_product_id = si.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE si.sale_id = ?
                 ORDER BY si.sale_item_id"
            );
            $itStmt->execute([(int)$ws['sale_id']]);
            $ws['items'] = $itStmt->fetchAll();
        }
        unset($ws);

        // Fetch Online Orders
        $onlineOrders = [];
        $onSql = "SELECT co.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                         ua.email AS customer_email, c.phone AS customer_phone,
                         p.payment_method, p.payment_status
                  FROM customer_order co
                  JOIN customer c ON c.customer_id = co.customer_id
                  LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                  LEFT JOIN payment p ON p.order_id = co.order_id
                  WHERE co.business_unit_id = ?";
        $onParams = [$unitId];
        if ($tab === 'online' && $q !== '') {
            $onSql .= " AND (co.order_id = ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR ua.email LIKE ?)";
            $onParams[] = ctype_digit($q) ? (int)$q : 0;
            $onParams[] = "%$q%";
            $onParams[] = "%$q%";
            $onParams[] = "%$q%";
        }
        $onSql .= " ORDER BY co.order_date DESC, co.order_id DESC";
        $oStmt = $this->db->prepare($onSql);
        $oStmt->execute($onParams);
        $onlineOrders = $oStmt->fetchAll();

        // Attach items for each online order
        foreach ($onlineOrders as &$oo) {
            $itStmt = $this->db->prepare(
                "SELECT oi.order_item_id, oi.quantity, oi.unit_price,
                        p.product_name, p.sku
                 FROM order_item oi
                 JOIN business_product bp ON bp.business_product_id = oi.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE oi.order_id = ?
                 ORDER BY oi.order_item_id"
            );
            $itStmt->execute([(int)$oo['order_id']]);
            $oo['items'] = $itStmt->fetchAll();
        }
        unset($oo);

        // Fetch Refund Requests
        $refundRequests = [];
        $refSql = "SELECT rr.*,
                          CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                          c.phone AS customer_phone,
                          ua.email AS customer_email,
                          CONCAT(emp.first_name, ' ', emp.last_name) AS decided_by_name,
                          emp.position AS decided_by_position,
                          CASE WHEN rr.channel = 'online' THEN CONCAT('#ORD-', rr.order_id)
                               ELSE CONCAT('#REC-S-', rr.sale_id) END AS transaction_ref,
                          CASE WHEN rr.channel = 'online' THEN co.total_amount
                               ELSE s.total_amount END AS original_total_amount,
                          CASE WHEN rr.channel = 'online' THEN co.order_date
                               ELSE s.sale_date END AS original_transaction_date,
                          CASE WHEN rr.channel = 'online' THEN 'Online Order' ELSE 'Walk-in Sale' END AS channel_label
                   FROM refund_request rr
                   JOIN customer c ON c.customer_id = rr.customer_id
                   LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                   LEFT JOIN employee emp ON emp.employee_id = rr.decided_by_employee_id
                   LEFT JOIN customer_order co ON co.order_id = rr.order_id
                   LEFT JOIN sale s ON s.sale_id = rr.sale_id
                   WHERE rr.business_unit_id = ?";
        $refParams = [$unitId];
        if ($tab === 'refunds' && $q !== '') {
            $refSql .= " AND (rr.refund_reference LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR rr.order_id = ? OR rr.sale_id = ?)";
            $refParams[] = "%$q%";
            $refParams[] = "%$q%";
            $refParams[] = "%$q%";
            $refParams[] = ctype_digit($q) ? (int)$q : 0;
            $refParams[] = ctype_digit($q) ? (int)$q : 0;
        }
        if ($tab === 'refunds' && $statusFilter !== '' && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
            $refSql .= " AND rr.request_status = ?";
            $refParams[] = $statusFilter;
        }
        $refSql .= " ORDER BY CASE WHEN rr.request_status = 'pending' THEN 0 ELSE 1 END, rr.created_at DESC, rr.refund_request_id DESC";
        $rStmt = $this->db->prepare($refSql);
        $rStmt->execute($refParams);
        $refundRequests = $rStmt->fetchAll();

        // Attach line items to refund requests
        foreach ($refundRequests as &$rf) {
            $rf['items'] = [];
            if (!empty($rf['order_id'])) {
                $itStmt = $this->db->prepare(
                    "SELECT oi.order_item_id AS line_item_id, oi.quantity, oi.unit_price,
                            p.product_name, p.sku
                     FROM order_item oi
                     JOIN business_product bp ON bp.business_product_id = oi.business_product_id
                     JOIN product p ON p.product_id = bp.product_id
                     WHERE oi.order_id = ?
                     ORDER BY oi.order_item_id"
                );
                $itStmt->execute([(int)$rf['order_id']]);
                $rf['items'] = $itStmt->fetchAll();
            } elseif (!empty($rf['sale_id'])) {
                $itStmt = $this->db->prepare(
                    "SELECT si.sale_item_id AS line_item_id, si.quantity, si.unit_price,
                            p.product_name, p.sku
                     FROM sale_item si
                     JOIN business_product bp ON bp.business_product_id = si.business_product_id
                     JOIN product p ON p.product_id = bp.product_id
                     WHERE si.sale_id = ?
                     ORDER BY si.sale_item_id"
                );
                $itStmt->execute([(int)$rf['sale_id']]);
                $rf['items'] = $itStmt->fetchAll();
            }
        }
        unset($rf);

        $pendingCntStmt = $this->db->prepare("SELECT COUNT(*) FROM refund_request WHERE business_unit_id = ? AND request_status = 'pending'");
        $pendingCntStmt->execute([$unitId]);
        $pendingRefundsCount = (int)$pendingCntStmt->fetchColumn();

        $this->render('manager/sales', [
            'title' => "Sales · $unitName",
            'activeTab' => 'sales',
            'salesTab' => $tab,
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'walkInSales' => $walkInSales,
            'onlineOrders' => $onlineOrders,
            'refundRequests' => $refundRequests,
            'pendingRefundsCount' => $pendingRefundsCount,
            'searchQuery' => $q,
            'statusFilter' => $statusFilter,
            'viewId' => $viewId,
        ]);
    }

    public function approveRefund(int $id): void
    {
        $this->handleRefundDecision($id, 'approved');
    }

    public function approveRefundUnit(int $unitId, int $id): void
    {
        $this->handleRefundDecision($id, 'approved', $unitId);
    }

    public function rejectRefund(int $id): void
    {
        $this->handleRefundDecision($id, 'rejected');
    }

    public function rejectRefundUnit(int $unitId, int $id): void
    {
        $this->handleRefundDecision($id, 'rejected', $unitId);
    }

    private function handleRefundDecision(int $id, string $decision, ?int $routeUnitId = null): void
    {
        [$uid, $employeeId, $unitId] = $this->checkBusinessManager($routeUnitId);
        CSRF::check();

        $stmt = $this->db->prepare(
            "SELECT * FROM refund_request WHERE refund_request_id = ? AND business_unit_id = ?"
        );
        $stmt->execute([$id, $unitId]);
        $req = $stmt->fetch();

        $redirectQuery = !empty($routeUnitId) ? "?unit_id=$routeUnitId&tab=refunds" : "?tab=refunds";

        if (!$req) {
            $this->flash('error', 'Refund request not found or does not belong to your business unit.');
            $this->redirect("/manager/sales" . $redirectQuery);
        }

        // Prevent duplicate approval/rejection or modification of already finalized decisions
        if ($req['request_status'] !== 'pending') {
            $this->flash('error', "This refund request has already been finalized as " . ucfirst((string)$req['request_status']) . ".");
            $this->redirect("/manager/sales{$redirectQuery}&view=$id");
        }

        if ($decision === 'approved') {
            $approvedAmount = (float)$req['requested_amount'];
            if (isset($_POST['approved_amount']) && is_numeric($_POST['approved_amount']) && (float)$_POST['approved_amount'] > 0) {
                $approvedAmount = min((float)$_POST['approved_amount'], (float)$req['requested_amount']);
            }

            $upd = $this->db->prepare(
                "UPDATE refund_request
                 SET request_status = 'approved',
                     approved_amount = ?,
                     decided_by_employee_id = ?,
                     decided_at = NOW()
                 WHERE refund_request_id = ? AND request_status = 'pending'"
            );
            $upd->execute([$approvedAmount, $employeeId ?: null, $id]);

            Audit::log('refund_request.approve', "Approved refund request {$req['refund_reference']} for amount " . number_format($approvedAmount, 2), $unitId, null, null, $uid);

            $this->flash('success', "Refund request {$req['refund_reference']} has been approved for " . peso($approvedAmount) . ". Original transaction is preserved. Payment reversal and stock inspection follow separate workflows.");
        } elseif ($decision === 'rejected') {
            $reason = trim((string)($_POST['rejection_reason'] ?? ''));
            if ($reason === '') {
                $this->flash('error', 'A rejection reason is required before rejecting a refund request.');
                $this->redirect("/manager/sales{$redirectQuery}&view=$id");
            }

            $upd = $this->db->prepare(
                "UPDATE refund_request
                 SET request_status = 'rejected',
                     rejection_reason = ?,
                     decided_by_employee_id = ?,
                     decided_at = NOW()
                 WHERE refund_request_id = ? AND request_status = 'pending'"
            );
            $upd->execute([$reason, $employeeId ?: null, $id]);

            Audit::log('refund_request.reject', "Rejected refund request {$req['refund_reference']}. Reason: $reason", $unitId, null, null, $uid);

            $this->flash('success', "Refund request {$req['refund_reference']} has been rejected.");
        }

        $this->redirect("/manager/sales{$redirectQuery}&view=$id");
    }

    // ==========================================
    // 3. PRODUCTS PAGE & ACTIONS
    // ==========================================
    public function products(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);

        $q = trim((string)($_GET['q'] ?? ''));
        $catFilter = trim((string)($_GET['category'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? ''));

        $sql = "SELECT bp.business_product_id, bp.selling_price, bp.product_status,
                       p.product_id, p.product_name, p.description, p.product_category, p.sku, p.image_url
                FROM business_product bp
                JOIN product p ON p.product_id = bp.product_id
                WHERE bp.business_unit_id = ?";
        $params = [$unitId];

        if ($q !== '') {
            $sql .= " AND (p.product_name LIKE ? OR p.sku LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($catFilter !== '') {
            $sql .= " AND p.product_category = ?";
            $params[] = $catFilter;
        }
        if ($statusFilter !== '') {
            $sql .= " AND bp.product_status = ?";
            $params[] = $statusFilter;
        }

        $sql .= " ORDER BY p.product_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Extract distinct categories
        $catStmt = $this->db->prepare(
            "SELECT DISTINCT p.product_category
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             WHERE bp.business_unit_id = ?
             ORDER BY p.product_category"
        );
        $catStmt->execute([$unitId]);
        $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

        $this->render('manager/products', [
            'title' => "Products Catalog · $unitName",
            'activeTab' => 'products',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'products' => $products,
            'categories' => $categories,
            'searchQuery' => $q,
            'categoryFilter' => $catFilter,
            'statusFilter' => $statusFilter,
        ]);
    }

    /**
     * Handle uploaded product image with size and MIME validation.
     * Preserves existing product image if no replacement is selected during editing.
     */
    private function handleUploadedProductImage(?string $currentUrl = null): ?string
    {
        if (empty($_FILES['product_image']['name']) || ($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            // If fallback image_url passed in POST (e.g. from existing automated test calls), preserve or use it
            $postUrl = trim((string)($_POST['image_url'] ?? ''));
            return $postUrl !== '' ? $postUrl : $currentUrl;
        }

        $file = $_FILES['product_image'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload error code: ' . $file['error']);
        }

        $maxSize = 5 * 1024 * 1024; // 5 MB
        if ($file['size'] > $maxSize) {
            throw new \InvalidArgumentException('Uploaded image exceeds maximum allowed size of 5MB.');
        }

        $tmpPath = $file['tmp_name'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];

        if (!isset($allowedMimes[$mime])) {
            throw new \InvalidArgumentException('Invalid image format. Allowed formats: PNG, JPG, WebP, GIF.');
        }

        $ext = $allowedMimes[$mime];
        $filename = 'prod_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $uploadDir = dirname(__DIR__, 2) . '/public/uploads/products';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $destPath = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmpPath, $destPath)) {
            throw new \RuntimeException('Failed to save uploaded image file.');
        }

        return '/uploads/products/' . $filename;
    }

    public function storeProduct(): void
    {
        [$uid, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $name = trim((string)($_POST['product_name'] ?? ''));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $category = trim((string)($_POST['product_category'] ?? ''));
        $price = (float)($_POST['selling_price'] ?? 0);
        $desc = trim((string)($_POST['description'] ?? ''));

        if ($name === '' || $sku === '' || $category === '' || $price <= 0) {
            $this->flash('error', 'Product name, SKU, category, and a positive selling price are required.');
            $this->redirect('/manager/products');
        }

        // Check SKU uniqueness
        $chk = $this->db->prepare('SELECT product_id FROM product WHERE sku = ?');
        $chk->execute([$sku]);
        if ($chk->fetch()) {
            $this->flash('error', "SKU '$sku' is already assigned to another catalog product.");
            $this->redirect('/manager/products');
        }

        try {
            $imageUrl = $this->handleUploadedProductImage(null);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/manager/products');
        }

        $this->db->beginTransaction();
        try {
            // 1. Insert into product
            $pStmt = $this->db->prepare(
                'INSERT INTO product (product_name, description, product_category, sku, image_url)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $pStmt->execute([$name, $desc ?: null, $category, $sku, $imageUrl ?: null]);
            $productId = (int)$this->db->lastInsertId();

            // 2. Insert into business_product
            $bpStmt = $this->db->prepare(
                'INSERT INTO business_product (business_unit_id, product_id, selling_price, product_status, requires_batch_tracking)
                 VALUES (?, ?, ?, "active", 0)'
            );
            $bpStmt->execute([$unitId, $productId, $price]);
            $bpId = (int)$this->db->lastInsertId();

            // 3. Initialize inventory
            $invStmt = $this->db->prepare(
                'INSERT INTO inventory (business_product_id, on_hand, committed)
                 VALUES (?, 0, 0)'
            );
            $invStmt->execute([$bpId]);

            Audit::log('product.create', "Created product '$name' ($sku) in Pet Store", $unitId, 'business_product', $bpId, $uid);

            $this->db->commit();
            $this->flash('success', "Product '$name' added to catalog successfully.");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to create product: ' . $e->getMessage());
        }

        $this->redirect('/manager/products');
    }

    public function updateProduct(int $businessProductId): void
    {
        [$uid, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $name = trim((string)($_POST['product_name'] ?? ''));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $category = trim((string)($_POST['product_category'] ?? ''));
        $price = (float)($_POST['selling_price'] ?? 0);
        $desc = trim((string)($_POST['description'] ?? ''));

        if ($name === '' || $sku === '' || $category === '' || $price <= 0) {
            $this->flash('error', 'Product name, SKU, category, and a positive selling price are required.');
            $this->redirect('/manager/products');
        }

        $bpStmt = $this->db->prepare(
            'SELECT bp.product_id, p.image_url
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             WHERE bp.business_product_id = ? AND bp.business_unit_id = ?'
        );
        $bpStmt->execute([$businessProductId, $unitId]);
        $bp = $bpStmt->fetch();
        if (!$bp) {
            $this->notFound();
        }
        $productId = (int)$bp['product_id'];
        $currentImageUrl = $bp['image_url'] ?? null;

        // Check SKU uniqueness on other products
        $chk = $this->db->prepare('SELECT product_id FROM product WHERE sku = ? AND product_id <> ?');
        $chk->execute([$sku, $productId]);
        if ($chk->fetch()) {
            $this->flash('error', "SKU '$sku' is already in use by another product.");
            $this->redirect('/manager/products');
        }

        try {
            $imageUrl = $this->handleUploadedProductImage($currentImageUrl);
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect('/manager/products');
        }

        $this->db->beginTransaction();
        try {
            $updP = $this->db->prepare(
                'UPDATE product SET product_name = ?, description = ?, product_category = ?, sku = ?, image_url = ?
                 WHERE product_id = ?'
            );
            $updP->execute([$name, $desc ?: null, $category, $sku, $imageUrl ?: null, $productId]);

            $updBp = $this->db->prepare('UPDATE business_product SET selling_price = ? WHERE business_product_id = ?');
            $updBp->execute([$price, $businessProductId]);

            Audit::log('product.update', "Updated product '$name' ($sku)", $unitId, 'business_product', $businessProductId, $uid);

            $this->db->commit();
            $this->flash('success', "Product '$name' updated successfully.");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to update product: ' . $e->getMessage());
        }

        $this->redirect('/manager/products');
    }

    public function toggleProductStatus(int $businessProductId): void
    {
        [$uid, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $newStatus = trim((string)($_POST['status'] ?? ''));
        if (!in_array($newStatus, ['active', 'inactive'], true)) {
            $this->flash('error', 'Invalid status transition.');
            $this->redirect('/manager/products');
        }

        $stmt = $this->db->prepare('SELECT p.product_name FROM business_product bp JOIN product p ON p.product_id = bp.product_id WHERE bp.business_product_id = ? AND bp.business_unit_id = ?');
        $stmt->execute([$businessProductId, $unitId]);
        $prod = $stmt->fetch();
        if (!$prod) {
            $this->notFound();
        }

        // Soft deactivation / re-activation (preserves historical records and identity)
        $upd = $this->db->prepare('UPDATE business_product SET product_status = ? WHERE business_product_id = ?');
        $upd->execute([$newStatus, $businessProductId]);

        Audit::log('product.status_toggle', "Changed status of '{$prod['product_name']}' to $newStatus (historical records preserved)", $unitId, 'business_product', $businessProductId, $uid);

        $actionText = $newStatus === 'active' ? 'activated' : 'deactivated';
        $this->flash('success', "Product '{$prod['product_name']}' has been $actionText. Historical records remain preserved.");
        $this->redirect('/manager/products');
    }

    // ==========================================
    // 4. INVENTORY PAGE & ACTIONS
    // ==========================================
    public function inventory(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper, $isInvOnly] = $this->checkManagerOrInventoryManager($routeUnitId);

        $tab = isset($_GET['tab']) && $_GET['tab'] === 'orders' ? 'orders' : 'stock';
        $q = trim((string)($_GET['q'] ?? ''));

        // Stock list query: Prioritize out-of-stock items (A–Z), then in-stock items (A–Z)
        $stockSql = "SELECT bp.business_product_id, bp.selling_price, bp.product_status,
                            p.product_name, p.product_category, p.sku,
                            COALESCE(i.on_hand, 0) AS on_hand,
                            COALESCE(i.committed, 0) AS committed,
                            GREATEST(COALESCE(i.on_hand, 0) - COALESCE(i.committed, 0), 0) AS available
                     FROM business_product bp
                     JOIN product p ON p.product_id = bp.product_id
                     LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
                     WHERE bp.business_unit_id = ?";
        $stockParams = [$unitId];
        if ($q !== '') {
            $stockSql .= " AND (p.product_name LIKE ? OR p.sku LIKE ?)";
            $stockParams[] = "%$q%";
            $stockParams[] = "%$q%";
        }
        $stockSql .= " ORDER BY (CASE WHEN COALESCE(i.on_hand, 0) <= 0 THEN 0 ELSE 1 END) ASC, p.product_name ASC";
        $stkStmt = $this->db->prepare($stockSql);
        $stkStmt->execute($stockParams);
        $inventoryItems = $stkStmt->fetchAll();

        // Catalog products for PO dropdown
        $catStmt = $this->db->prepare(
            "SELECT bp.business_product_id, bp.selling_price, p.product_name, p.sku
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             WHERE bp.business_unit_id = ? AND bp.product_status = 'active'
             ORDER BY p.product_name ASC"
        );
        $catStmt->execute([$unitId]);
        $catalogProducts = $catStmt->fetchAll();

        // Purchase Orders
        $poStmt = $this->db->prepare(
            "SELECT po.*, CONCAT(e.first_name, ' ', e.last_name) AS created_by_name,
                    (SELECT COALESCE(SUM(poi.quantity_ordered), 0) FROM purchase_order_item poi WHERE poi.purchase_order_id = po.purchase_order_id) AS total_ordered_qty
             FROM purchase_order po
             JOIN employee e ON e.employee_id = po.created_by_employee_id
             WHERE po.business_unit_id = ?
             ORDER BY po.order_date DESC, po.purchase_order_id DESC"
        );
        $poStmt->execute([$unitId]);
        $purchaseOrders = $poStmt->fetchAll();

        // Attach items & receiving records per PO
        foreach ($purchaseOrders as &$po) {
            $poiStmt = $this->db->prepare(
                "SELECT poi.*, p.product_name, p.sku
                 FROM purchase_order_item poi
                 JOIN business_product bp ON bp.business_product_id = poi.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE poi.purchase_order_id = ?
                 ORDER BY poi.po_item_id"
            );
            $poiStmt->execute([(int)$po['purchase_order_id']]);
            $po['items'] = $poiStmt->fetchAll();

            $rcvStmt = $this->db->prepare(
                "SELECT sr.receiving_reference, sr.receiving_date, CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                        p.product_name, sri.quantity_received, sri.quantity_accepted, sri.quantity_rejected, sri.rejection_reason
                 FROM stock_receiving sr
                 JOIN employee e ON e.employee_id = sr.employee_id
                 JOIN stock_receiving_item sri ON sri.receiving_id = sr.receiving_id
                 JOIN business_product bp ON bp.business_product_id = sri.business_product_id
                 JOIN product p ON p.product_id = bp.product_id
                 WHERE sr.purchase_order_id = ?
                 ORDER BY sr.receiving_date DESC, sri.receiving_item_id DESC"
            );
            $rcvStmt->execute([(int)$po['purchase_order_id']]);
            $po['receivings'] = $rcvStmt->fetchAll();
        }
        unset($po);

        $this->render('manager/inventory', [
            'title' => "Inventory · $unitName",
            'activeTab' => 'inventory',
            'inventoryTab' => $tab,
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'isInventoryManagerOnly' => $isInvOnly,
            'allUnits' => $isSuper ? Units::all() : [],
            'inventoryItems' => $inventoryItems,
            'catalogProducts' => $catalogProducts,
            'purchaseOrders' => $purchaseOrders,
            'searchQuery' => $q,
        ]);
    }

    public function adjustStock(): void
    {
        [$uid, $employeeId, $unitId] = $this->checkManagerOrInventoryManager();
        CSRF::check();

        $bpId = (int)($_POST['business_product_id'] ?? 0);
        $type = trim((string)($_POST['adjustment_type'] ?? ''));
        $qty = (int)($_POST['quantity'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $inspectionConfirmed = !empty($_POST['inspection_confirmed']);
        $relatedRef = trim((string)($_POST['related_reference'] ?? ''));

        if ($bpId <= 0 || !in_array($type, ['increase', 'decrease'], true) || $qty <= 0 || $reason === '') {
            $this->flash('error', 'Valid product, adjustment direction, positive quantity, and mandatory reason are required.');
            $this->redirect('/manager/inventory');
        }

        // Return reason requires inspection check
        $isReturn = str_contains($reason, 'Returned product');
        if ($type === 'increase' && $isReturn && !$inspectionConfirmed) {
            $this->flash('error', 'Returned products must only be added back to sellable inventory after inspection confirms that they are undamaged and suitable for resale.');
            $this->redirect('/manager/inventory');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT i.on_hand, p.product_name
                 FROM business_product bp
                 JOIN product p ON p.product_id = bp.product_id
                 LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
                 WHERE bp.business_product_id = ? AND bp.business_unit_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$bpId, $unitId]);
            $row = $stmt->fetch();
            if (!$row) {
                $this->db->rollBack();
                $this->notFound();
            }

            $prevOnHand = (int)$row['on_hand'];
            $prodName = (string)$row['product_name'];

            if ($type === 'decrease') {
                if ($prevOnHand <= 0) {
                    $this->db->rollBack();
                    $this->flash('error', 'Cannot decrease stock when on-hand quantity is zero.');
                    $this->redirect('/manager/inventory');
                }
                if ($qty > $prevOnHand) {
                    $this->db->rollBack();
                    $this->flash('error', "Cannot deduct $qty units: current on-hand quantity is only $prevOnHand.");
                    $this->redirect('/manager/inventory');
                }
                $newOnHand = $prevOnHand - $qty;
            } else {
                $newOnHand = $prevOnHand + $qty;
            }

            // Update inventory
            $upd = $this->db->prepare('UPDATE inventory SET on_hand = ? WHERE business_product_id = ?');
            $upd->execute([$newOnHand, $bpId]);

            // Insert audit record in stock_adjustment
            $adjStmt = $this->db->prepare(
                'INSERT INTO stock_adjustment (business_unit_id, business_product_id, employee_id, adjustment_type, quantity_change, previous_on_hand, new_on_hand, reason, related_reference)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $adjStmt->execute([$unitId, $bpId, $employeeId, $type, $qty, $prevOnHand, $newOnHand, $reason, $relatedRef ?: null]);

            Audit::log('inventory.stock_adjustment', "Stock {$type} of {$qty} for '$prodName' ($prevOnHand -> $newOnHand). Reason: $reason", $unitId, 'business_product', $bpId, $uid);

            $this->db->commit();
            $this->flash('success', "Stock adjusted for '$prodName': {$prevOnHand} → {$newOnHand} ($reason).");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Stock adjustment failed: ' . $e->getMessage());
        }

        $this->redirect('/manager/inventory');
    }

    public function storePurchaseOrder(): void
    {
        [$uid, $employeeId, $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $items = $_POST['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            $this->flash('error', 'At least one product must be added to create a purchase order.');
            $this->redirect('/manager/inventory?tab=orders');
        }

        $supplierName = trim((string)($_POST['supplier_name'] ?? ''));
        $contactPerson = trim((string)($_POST['contact_person'] ?? ''));
        $supplierEmail = trim((string)($_POST['supplier_email'] ?? ''));
        $supplierPhone = trim((string)($_POST['supplier_phone'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($supplierName === '' || $contactPerson === '' || ($supplierEmail === '' && $supplierPhone === '')) {
            $this->flash('error', 'Supplier name, contact person, and at least one contact method (email or phone) are required.');
            $this->redirect('/manager/inventory?tab=orders');
        }

        $this->db->beginTransaction();
        try {
            // Auto-generated fields: PO Number, Order Date, Created By, Status = 'Ordered'
            do {
                $poNumber = 'PO-PS-' . date('Ymd') . '-' . str_pad((string)random_int(100, 99999), 5, '0', STR_PAD_LEFT);
                $checkPo = $this->db->prepare('SELECT COUNT(*) FROM purchase_order WHERE po_number = ?');
                $checkPo->execute([$poNumber]);
            } while ((int)$checkPo->fetchColumn() > 0);
            $orderDate = date('Y-m-d H:i:s');

            $calcTotalCost = 0.0;
            $validatedItems = [];
            foreach ($items as $it) {
                $bpId = (int)($it['business_product_id'] ?? 0);
                $qtyOrdered = (int)($it['quantity_ordered'] ?? 0);
                $unitCost = (float)($it['unit_cost'] ?? 0.0);
                if ($bpId > 0 && $qtyOrdered > 0) {
                    $validatedItems[] = [
                        'bp_id' => $bpId,
                        'qty' => $qtyOrdered,
                        'cost' => $unitCost,
                    ];
                    $calcTotalCost += ($unitCost * $qtyOrdered);
                }
            }

            if (empty($validatedItems)) {
                $this->db->rollBack();
                $this->flash('error', 'At least one valid product with quantity > 0 must be included.');
                $this->redirect('/manager/inventory?tab=orders');
            }

            $poIns = $this->db->prepare(
                'INSERT INTO purchase_order (po_number, business_unit_id, supplier_name, contact_person, supplier_email, supplier_phone, order_date, po_status, created_by_employee_id, total_cost, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "Ordered", ?, ?, ?)'
            );
            $poIns->execute([
                $poNumber,
                $unitId,
                $supplierName,
                $contactPerson,
                $supplierEmail ?: null,
                $supplierPhone ?: null,
                $orderDate,
                $employeeId,
                $calcTotalCost,
                $notes ?: null
            ]);
            $poId = (int)$this->db->lastInsertId();

            $itemIns = $this->db->prepare(
                'INSERT INTO purchase_order_item (purchase_order_id, business_product_id, quantity_ordered, quantity_received, unit_cost)
                 VALUES (?, ?, ?, 0, ?)'
            );
            foreach ($validatedItems as $vi) {
                $itemIns->execute([$poId, $vi['bp_id'], $vi['qty'], $vi['cost']]);
            }

            Audit::log('purchase_order.create', "Created purchase order $poNumber with status 'Ordered'", $unitId, 'purchase_order', $poId, $uid);

            $this->db->commit();
            $this->flash('success', "Purchase order $poNumber created successfully with status 'Ordered'. Stock will be updated upon accepted receiving.");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to create purchase order: ' . $e->getMessage());
        }

        $this->redirect('/manager/inventory?tab=orders');
    }

    public function receiveStock(int $purchaseOrderId): void
    {
        [$uid, $employeeId, $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $poStmt = $this->db->prepare('SELECT * FROM purchase_order WHERE purchase_order_id = ? AND business_unit_id = ?');
        $poStmt->execute([$purchaseOrderId, $unitId]);
        $po = $poStmt->fetch();
        if (!$po) {
            $this->notFound();
        }

        if (!in_array($po['po_status'], ['Ordered', 'Partially Received'], true)) {
            $this->flash('error', 'Stock can only be received for orders in Ordered or Partially Received status.');
            $this->redirect('/manager/inventory?tab=orders');
        }

        $itemsInput = $_POST['items'] ?? [];
        $notes = trim((string)($_POST['notes'] ?? ''));

        if (!is_array($itemsInput) || empty($itemsInput)) {
            $this->flash('error', 'Receiving quantities must be specified.');
            $this->redirect('/manager/inventory?tab=orders');
        }

        $this->db->beginTransaction();
        try {
            $receivingRef = 'RCV-' . $po['po_number'] . '-' . date('His') . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            $receivingDate = date('Y-m-d H:i:s');

            $rcvIns = $this->db->prepare(
                'INSERT INTO stock_receiving (receiving_reference, purchase_order_id, business_unit_id, employee_id, receiving_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $rcvIns->execute([$receivingRef, $purchaseOrderId, $unitId, $employeeId, $receivingDate, $notes ?: null]);
            $receivingId = (int)$this->db->lastInsertId();

            $totalAcceptedInShipment = 0;

            foreach ($itemsInput as $it) {
                $poItemId = (int)($it['po_item_id'] ?? 0);
                $bpId = (int)($it['business_product_id'] ?? 0);
                $qtyRecv = (int)($it['quantity_received'] ?? 0);
                $qtyAcc = (int)($it['quantity_accepted'] ?? 0);
                $qtyRej = (int)($it['quantity_rejected'] ?? 0);
                $rejReason = trim((string)($it['rejection_reason'] ?? ''));

                if ($qtyRecv <= 0 && $qtyAcc <= 0) {
                    continue;
                }

                if ($qtyAcc + $qtyRej > $qtyRecv) {
                    $qtyRej = max(0, $qtyRecv - $qtyAcc);
                }

                // 1. Record item in stock_receiving_item
                $sriIns = $this->db->prepare(
                    'INSERT INTO stock_receiving_item (receiving_id, po_item_id, business_product_id, quantity_received, quantity_accepted, quantity_rejected, rejection_reason, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $sriIns->execute([$receivingId, $poItemId, $bpId, $qtyRecv, $qtyAcc, $qtyRej, $rejReason ?: null, null]);

                // 2. Update purchase_order_item total received count
                $poiUpd = $this->db->prepare('UPDATE purchase_order_item SET quantity_received = quantity_received + ? WHERE po_item_id = ?');
                $poiUpd->execute([$qtyRecv, $poItemId]);

                // 3. ONLY ACCEPTED QUANTITIES INCREASE ON-HAND INVENTORY!
                if ($qtyAcc > 0) {
                    $invUpd = $this->db->prepare('UPDATE inventory SET on_hand = on_hand + ? WHERE business_product_id = ?');
                    $invUpd->execute([$qtyAcc, $bpId]);
                    $totalAcceptedInShipment += $qtyAcc;
                }
            }

            // 4. Update overall PO status based on progress
            $chkStmt = $this->db->prepare(
                'SELECT SUM(quantity_ordered) AS tot_ordered, SUM(quantity_received) AS tot_received
                 FROM purchase_order_item
                 WHERE purchase_order_id = ?'
            );
            $chkStmt->execute([$purchaseOrderId]);
            $counts = $chkStmt->fetch();
            $totOrd = (int)($counts['tot_ordered'] ?? 0);
            $totRec = (int)($counts['tot_received'] ?? 0);

            $newPoStatus = $totRec >= $totOrd ? 'Fully Received' : ($totRec > 0 ? 'Partially Received' : 'Ordered');
            $poUpd = $this->db->prepare('UPDATE purchase_order SET po_status = ? WHERE purchase_order_id = ?');
            $poUpd->execute([$newPoStatus, $purchaseOrderId]);

            Audit::log('stock_receiving.create', "Received stock for PO {$po['po_number']} under $receivingRef. Accepted: $totalAcceptedInShipment. Status: $newPoStatus", $unitId, 'purchase_order', $purchaseOrderId, $uid);

            $this->db->commit();
            $this->flash('success', "Stock receiving recorded under $receivingRef. $totalAcceptedInShipment accepted units added to inventory. PO status is now $newPoStatus.");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to record stock receiving: ' . $e->getMessage());
        }

        $this->redirect('/manager/inventory?tab=orders');
    }

    // ==========================================
    // 5. EMPLOYEES PAGE (READ-ONLY)
    // ==========================================
    public function employees(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);

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
            $sql .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR ua.email LIKE ?)";
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
        $allEmployees = $stmt->fetchAll();

        // Pagination
        $totalEmployees = count($allEmployees);
        $perPage = 6;
        $totalPages = max(1, (int)ceil($totalEmployees / $perPage));
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }
        $offset = ($currentPage - 1) * $perPage;
        $employees = array_slice($allEmployees, $offset, $perPage);

        // Extract distinct positions
        $posStmt = $this->db->prepare('SELECT DISTINCT position FROM employee WHERE business_unit_id = ? ORDER BY position');
        $posStmt->execute([$unitId]);
        $positions = $posStmt->fetchAll(PDO::FETCH_COLUMN);

        $this->render('manager/employees', [
            'title' => "Employees · $unitName",
            'activeTab' => 'employees',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'employees' => $employees,
            'allEmployees' => $allEmployees,
            'totalEmployees' => $totalEmployees,
            'totalPages' => $totalPages,
            'currentPage' => $currentPage,
            'perPage' => $perPage,
            'positions' => $positions,
            'searchQuery' => $q,
            'positionFilter' => $posFilter,
            'workStatusFilter' => $wsFilter,
        ]);
    }

    // ==========================================
    // 6. BUSINESS SETTINGS PAGE & ACTIONS
    // ==========================================
    public function settings(?int $routeUnitId = null): void
    {
        // Exclusively Business Manager
        [, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);

        $setStmt = $this->db->prepare('SELECT * FROM business_settings WHERE business_unit_id = ?');
        $setStmt->execute([$unitId]);
        $settings = $setStmt->fetch() ?: [];

        $hrsStmt = $this->db->prepare('SELECT * FROM operating_hours WHERE business_unit_id = ? ORDER BY day_of_week ASC');
        $hrsStmt->execute([$unitId]);
        $operatingHours = $hrsStmt->fetchAll();

        $this->render('manager/settings', [
            'title' => "Business Settings · $unitName",
            'activeTab' => 'settings',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'settings' => $settings,
            'operatingHours' => $operatingHours,
        ]);
    }

    public function updateProfile(): void
    {
        [$uid, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $name = trim((string)($_POST['business_name'] ?? ''));
        $email = trim((string)($_POST['business_email'] ?? ''));
        $phone = trim((string)($_POST['contact_number'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Please provide a valid business name and business email address.');
            $this->redirect('/manager/settings');
        }

        // Contact Number validation: accepts either 8-digit number or 11-digit phone number
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) !== 8 && strlen($cleanPhone) !== 11) {
            $this->flash('error', 'Contact Number must be either an 8-digit landline number or an 11-digit mobile phone number.');
            $this->redirect('/manager/settings');
        }

        $stmt = $this->db->prepare(
            'UPDATE business_settings SET business_name = ?, business_email = ?, contact_number = ? WHERE business_unit_id = ?'
        );
        $stmt->execute([$name, $email, $phone, $unitId]);

        Audit::log('business_profile.update', "Updated Pet Store profile (Phone: $phone)", $unitId, null, null, $uid);

        $this->flash('success', 'Business profile updated successfully.');
        $this->redirect('/manager/settings');
    }

    public function updateOperatingHours(): void
    {
        [$uid, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $hours = $_POST['hours'] ?? [];
        if (!is_array($hours)) {
            $this->flash('error', 'Invalid operating hours submitted.');
            $this->redirect('/manager/settings');
        }

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                'UPDATE operating_hours SET is_open = ?, open_time = ?, close_time = ?
                 WHERE business_unit_id = ? AND day_of_week = ?'
            );

            for ($d = 1; $d <= 7; $d++) {
                $dayData = $hours[$d] ?? [];
                $isOpen = !empty($dayData['is_open']) ? 1 : 0;
                $openTime = $isOpen ? ($dayData['open_time'] ?? '08:00:00') : '00:00:00';
                $closeTime = $isOpen ? ($dayData['close_time'] ?? '19:00:00') : '00:00:00';
                $upd->execute([$isOpen, $openTime, $closeTime, $unitId, $d]);
            }

            Audit::log('operating_hours.update', 'Updated 7-day operating hours schedule', $unitId, null, null, $uid);

            $this->db->commit();
            $this->flash('success', 'Operating hours schedule updated successfully.');
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to update operating hours: ' . $e->getMessage());
        }

        $this->redirect('/manager/settings');
    }

    public function sendOtp(): void
    {
        // Require Business Manager
        [, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $service = trim((string)($_POST['service_type'] ?? ''));
        $mobile = trim((string)($_POST['mobile_number'] ?? ''));

        $res = OtpService::requestOtp($unitId, $service, $mobile);

        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }

    public function verifyOtp(): void
    {
        // Require Business Manager
        [, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $service = trim((string)($_POST['service_type'] ?? ''));
        $mobile = trim((string)($_POST['mobile_number'] ?? ''));
        $otp = trim((string)($_POST['otp'] ?? ''));

        $res = OtpService::verifyOtp($unitId, $service, $mobile, $otp);

        header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }

    public function updatePaymentSettings(): void
    {
        [$uid, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $service = trim((string)($_POST['service_type'] ?? ''));

        if ($service === 'cod') {
            $codEnabled = !empty($_POST['cod_enabled']) ? 1 : 0;
            $stmt = $this->db->prepare('UPDATE business_settings SET cod_enabled = ? WHERE business_unit_id = ?');
            $stmt->execute([$codEnabled, $unitId]);

            Audit::log('payment_setting.update', 'Updated Cash on Delivery to ' . ($codEnabled ? 'enabled' : 'disabled'), $unitId, null, null, $uid);

            $this->flash('success', 'Cash on Delivery configuration updated.');
            $this->redirect('/manager/settings');
        }

        if (in_array($service, ['gcash', 'maya'], true)) {
            $mobile = trim((string)($_POST['mobile_number'] ?? ''));

            // Server-side validation: Confirm action MUST be rejected if OTP verification has not succeeded!
            if (!OtpService::isVerified($unitId, $service, $mobile)) {
                $this->flash('error', 'Cannot confirm ' . strtoupper($service) . ' setup: Mobile number has not been verified with a valid OTP.');
                $this->redirect('/manager/settings');
            }

            if ($service === 'gcash') {
                $stmt = $this->db->prepare('UPDATE business_settings SET gcash_mobile = ?, gcash_verified = 1 WHERE business_unit_id = ?');
                $stmt->execute([$mobile, $unitId]);
            } else {
                $stmt = $this->db->prepare('UPDATE business_settings SET maya_mobile = ?, maya_verified = 1 WHERE business_unit_id = ?');
                $stmt->execute([$mobile, $unitId]);
            }

            Audit::log('payment_gateway.verified', "Verified and confirmed " . strtoupper($service) . " ($mobile)", $unitId, null, null, $uid);

            $this->flash('success', strtoupper($service) . ' merchant payment gateway confirmed and enabled.');
            $this->redirect('/manager/settings');
        }

        $this->flash('error', 'Invalid payment configuration request.');
        $this->redirect('/manager/settings');
    }
}
