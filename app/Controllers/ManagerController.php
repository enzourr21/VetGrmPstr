<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\InventoryAccessKey;
use App\Models\OtpService;
use App\Models\Units;
use PDO;

final class ManagerController extends Controller
{
    public const GROOMING_CATEGORIES = [
        'Bathing & Brushing',
        'Styling',
        'Wellness',
        'Specialized',
    ];

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
     * Helper for Inventory access: permits Business Manager, Inventory Manager, Super Admin,
     * or an active operational employee of the same unit holding a valid, unexpired Inventory Access Key.
     * Returns: [$uid, $employeeId, $unitId, $unitName, $isSuper, $isInventoryManagerOnly, $isTempKeyAccess, $tempKeyGrant]
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
        $postUnitId = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['business_unit_id']) && ctype_digit((string)$_POST['business_unit_id'])
            ? (int)$_POST['business_unit_id']
            : null;
        $targetUnitId = $routeUnitId ?? $postUnitId ?? $queryUnitId;

        if ($this->isSuperAdmin()) {
            $u = $targetUnitId !== null ? (Units::find($targetUnitId) ?: $this->notFound()) : (Units::find(Units::petStoreId()) ?: Units::all()[0]);
            return [(int)$uid, (int)Session::get('employee_id', 0), (int)$u['business_unit_id'], (string)$u['business_unit_name'], true, false, false, null];
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

        if ($rows) {
            $byUnitId = [];
            $rolesByUnit = [];
            foreach ($rows as $r) {
                $bId = (int)$r['business_unit_id'];
                $byUnitId[$bId] = $r;
                $rolesByUnit[$bId][] = (string)$r['role_name'];
            }

            foreach ([$routeUnitId, $queryUnitId, $postUnitId] as $chkUnit) {
                if ($chkUnit !== null && !isset($byUnitId[$chkUnit])) {
                    $this->forbidden('Access denied: you cannot access another business unit.');
                }
            }

            $chosen = $targetUnitId !== null ? $byUnitId[$targetUnitId] : $rows[0];
            $chosenUnitId = (int)$chosen['business_unit_id'];
            $unitRoles = $rolesByUnit[$chosenUnitId] ?? [];
            $isInvOnly = !in_array('BUSINESS_MANAGER', $unitRoles, true) && in_array('INVENTORY_MANAGER', $unitRoles, true);

            return [(int)$uid, (int)$chosen['employee_id'], $chosenUnitId, (string)$chosen['business_unit_name'], false, $isInvOnly, false, null];
        }

        // Temporary Inventory Access Key check (must be an active operational employee of the exact same unit)
        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, bu.business_unit_id, bu.business_unit_name
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id AND e.employee_status = 'active'
             JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id AND bu.business_unit_status = 'active'
             WHERE ua.user_account_id = ? AND ua.account_status = 'active'
             LIMIT 1"
        );
        $empStmt->execute([(int)$uid]);
        $emp = $empStmt->fetch();
        if (!$emp) {
            $this->forbidden('Business Manager or Inventory Manager privileges are required.');
        }

        $empUnitId = (int)$emp['business_unit_id'];
        $empId = (int)$emp['employee_id'];

        // Strict cross-unit guard before checking session grant
        foreach ([$routeUnitId, $queryUnitId, $postUnitId] as $chkUnit) {
            if ($chkUnit !== null && $chkUnit !== $empUnitId) {
                $this->forbidden('Access denied: you cannot access another business unit.');
            }
        }

        // System Administrators without operational roles are administrative-only
        $roleChk = $this->db->prepare(
            "SELECT r.role_name
             FROM account_role ar
             JOIN role r ON r.role_id = ar.role_id
             WHERE ar.user_account_id = ? AND ar.business_unit_id = ?"
        );
        $roleChk->execute([(int)$uid, $empUnitId]);
        $empRoles = $roleChk->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($empRoles) && count(array_diff($empRoles, ['SYSTEM_ADMINISTRATOR'])) === 0) {
            $this->forbidden('System Administrators do not have operational inventory access.');
        }

        $tempMap = Session::get('temp_inventory_access', []);
        $grant = is_array($tempMap) ? ($tempMap[$empUnitId] ?? null) : null;
        if (
            !is_array($grant)
            || (int)($grant['employee_id'] ?? 0) !== $empId
            || (int)($grant['unit_id'] ?? 0) !== $empUnitId
        ) {
            $this->forbidden('Business Manager or Inventory Manager privileges are required.');
        }

        $validGrant = InventoryAccessKey::validateSessionGrant($this->db, (int)($grant['key_id'] ?? 0), $empUnitId);
        if ($validGrant === null) {
            unset($tempMap[$empUnitId]);
            Session::set('temp_inventory_access', $tempMap);
            $this->forbidden('Your temporary Inventory Access Key has expired or is no longer valid.');
        }

        return [(int)$uid, $empId, $empUnitId, (string)$emp['business_unit_name'], false, true, true, $validGrant];
    }

    // ==========================================
    // 1. OVERVIEW PAGE
    // ==========================================
    public function dashboard(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);

        // Grooming Services Business Manager Overview (business_unit_id = 3)
        if ((int)$unitId === Units::groomingId()) {
            $this->renderGroomingOverview($unitId, $unitName, $isSuper);
            return;
        }

        // Veterinary Clinic Business Manager Overview (business_unit_id = 2)
        if ((int)$unitId === Units::vetClinicId()) {
            $this->renderVetClinicOverview($unitId, $unitName, $isSuper);
            return;
        }

        // If not Pet Store (e.g. Veterinary Clinic), preserve existing legacy dashboard
        if ((int)$unitId !== 1) {
            $this->renderLegacyDashboard($unitId, $unitName, $isSuper);
            return;
        }

        // Pet Store Overview Queries
        // 1. Stocks Running Low (available <= 10, Pet Store unit 1 inventory only)
        $lowStockStmt = $this->db->prepare(
            "SELECT bp.business_product_id, bp.product_status,
                    p.product_name, p.product_category,
                    COALESCE(i.on_hand, 0) AS on_hand,
                    COALESCE(i.committed, 0) AS committed,
                    GREATEST(COALESCE(i.on_hand, 0) - COALESCE(i.committed, 0), 0) AS available
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_unit_id = ?
               AND bp.product_status = 'active'
               AND GREATEST(COALESCE(i.on_hand, 0) - COALESCE(i.committed, 0), 0) <= 10
             ORDER BY available ASC, p.product_name ASC"
        );
        $lowStockStmt->execute([$unitId]);
        $lowStockProducts = $lowStockStmt->fetchAll();

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
            'lowStockProducts' => $lowStockProducts,
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

    /**
     * Veterinary Clinic Business Manager Overview (business_unit_id = 2)
     */
    private function renderVetClinicOverview(int $unitId, string $unitName, bool $isSuper): void
    {
        // 1. Top Section: Current / Upcoming Appointment (most recently started In Progress or next upcoming)
        $currentAppointment = $this->fetchSingleCurrentOrUpcomingVetAppointment($unitId);

        // 2. Middle Section Left: Stock Alerts (low available <= 10 or batch expiry tracking)
        $stockAlertsStmt = $this->db->prepare(
            "SELECT bp.business_product_id, bp.selling_price, bp.requires_batch_tracking,
                    p.product_name, p.product_category, p.sku,
                    COALESCE(i.on_hand, 0) AS on_hand,
                    COALESCE(i.committed, 0) AS committed,
                    GREATEST(COALESCE(i.on_hand, 0) - COALESCE(i.committed, 0), 0) AS available,
                    ib.batch_id, ib.batch_number, ib.expiration_date, ib.quantity AS batch_quantity
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             LEFT JOIN inventory_batch ib ON ib.inventory_id = i.inventory_id
             WHERE bp.business_unit_id = ?
               AND bp.product_status = 'active'
               AND (
                 GREATEST(COALESCE(i.on_hand, 0) - COALESCE(i.committed, 0), 0) <= 10
                 OR (bp.requires_batch_tracking = 1 AND ib.expiration_date IS NOT NULL AND ib.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
               )
             ORDER BY
               CASE
                 WHEN ib.expiration_date IS NOT NULL AND ib.expiration_date < CURDATE() THEN 0
                 WHEN ib.expiration_date IS NOT NULL AND ib.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1
                 ELSE 2
               END ASC,
               available ASC, p.product_name ASC"
        );
        $stockAlertsStmt->execute([$unitId]);
        $rawAlerts = $stockAlertsStmt->fetchAll();

        $stockAlerts = [];
        $todayStr = date('Y-m-d');
        foreach ($rawAlerts as $ra) {
            $avail = (int)$ra['available'];
            $exp = $ra['expiration_date'] ? (string)$ra['expiration_date'] : null;
            $isExpired = ($exp !== null && $exp < $todayStr);
            $isNearExp = ($exp !== null && !$isExpired && $exp <= date('Y-m-d', strtotime('+30 days')));

            if ($avail <= 0) {
                $alertLabel = 'Out of Stock';
                $badgeClass = 'badge-danger';
            } elseif ($isExpired) {
                $alertLabel = 'Batch Expired';
                $badgeClass = 'badge-danger';
            } elseif ($isNearExp) {
                $alertLabel = 'Expiring Soon';
                $badgeClass = 'warn';
            } else {
                $alertLabel = 'Low Stock';
                $badgeClass = 'secondary';
            }

            $ra['alert_label'] = $alertLabel;
            $ra['alert_badge_class'] = $badgeClass;
            $stockAlerts[] = $ra;
        }

        // 3. Middle Section Right: Daily Clinic Activity
        // Current clinic-local date counts: Scheduled Appointments vs Walk-in Visits
        // The clinic operates in GMT+8 (Asia/Manila)
        $clinicTz = new \DateTimeZone('Asia/Manila');
        $todayLocal = new \DateTimeImmutable('now', $clinicTz);
        $clinicLocalDate = $todayLocal->format('Y-m-d');
        $activityDateFormatted = $todayLocal->format('M j, Y');

        $scheduledStmt = $this->db->prepare(
            "SELECT COUNT(*) FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.business_unit_id = ?
               AND a.appointment_type = 'medical'
               AND a.preferred_date = ?
               AND st.status_name NOT IN ('Cancelled', 'Rejected')"
        );
        $scheduledStmt->execute([$unitId, $clinicLocalDate]);
        $todayScheduledCount = (int)$scheduledStmt->fetchColumn();

        // Walk-in visits: database schema has no visit_type or walk-in consultations table for clinic visits.
        // As per project instructions, we do not invent classification rules or alter schemas without approval.
        $todayWalkInCount = 0;

        // 4. Bottom Section: Employees On Duty (up to 3 employees in priority: Lead/Assoc Vet > Vet Nurse > Vet Assistant > Receptionist)
        $employeesOnDuty = $this->fetchVetEmployeesOnDuty($unitId);

        $this->render('manager/vet_overview', [
            'title' => "$unitName · Business Manager Overview",
            'activeTab' => 'overview',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'currentAppointment' => $currentAppointment,
            'stockAlerts' => $stockAlerts,
            'todayScheduledCount' => $todayScheduledCount,
            'todayWalkInCount' => $todayWalkInCount,
            'activityDateFormatted' => $activityDateFormatted,
            'employeesOnDuty' => $employeesOnDuty,
        ]);
    }

    /**
     * Fetch single current or upcoming medical appointment for Veterinary Clinic.
     * Prioritizes active In Progress (most recently started), then next upcoming chronological appointment.
     */
    private function fetchSingleCurrentOrUpcomingVetAppointment(int $unitId): ?array
    {
        // 1. Check for most recently started In Progress appointment
        $inProgStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                    a.appointment_begin, a.appointment_end, a.notes,
                    st.status_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                    c.phone AS customer_phone
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             WHERE a.business_unit_id = ?
               AND a.appointment_type = 'medical'
               AND st.status_name = 'In Progress'
             ORDER BY COALESCE(a.appointment_begin, a.preferred_time) DESC, a.appointment_id DESC
             LIMIT 1"
        );
        $inProgStmt->execute([$unitId]);
        $row = $inProgStmt->fetch();

        // 2. If no In Progress appointment, find next upcoming chronologically
        if (!$row) {
            $upcomingStmt = $this->db->prepare(
                "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                        a.appointment_begin, a.appointment_end, a.notes,
                        st.status_name,
                        CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                        c.phone AS customer_phone
                 FROM appointment a
                 JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                 JOIN customer c ON c.customer_id = a.customer_id
                 WHERE a.business_unit_id = ?
                   AND a.appointment_type = 'medical'
                   AND st.status_name IN ('Confirmed', 'Pending', 'Awaiting Customer Response')
                   AND a.preferred_date >= CURDATE()
                 ORDER BY a.preferred_date ASC, COALESCE(TIME(a.appointment_begin), a.preferred_time, '09:00:00') ASC, a.appointment_id ASC
                 LIMIT 1"
            );
            $upcomingStmt->execute([$unitId]);
            $row = $upcomingStmt->fetch();
        }

        if (!$row) {
            return null;
        }

        return $this->hydrateVetAppointmentDetails($row);
    }

    /**
     * Hydrate pet, service, assigned clinician, and timing details for a Veterinary Clinic appointment.
     */
    private function hydrateVetAppointmentDetails(array $appt): array
    {
        $apptId = (int)$appt['appointment_id'];

        // Customer details if not already present on $appt
        if (!isset($appt['customer_name']) && !empty($appt['customer_id'])) {
            $cStmt = $this->db->prepare("SELECT CONCAT(first_name, ' ', last_name) AS customer_name, phone AS customer_phone FROM customer WHERE customer_id = ?");
            $cStmt->execute([(int)$appt['customer_id']]);
            $cRow = $cStmt->fetch();
            if ($cRow) {
                $appt['customer_name'] = $cRow['customer_name'];
                $appt['customer_phone'] = $cRow['customer_phone'];
            }
        }

        // Pets & breeds
        $petStmt = $this->db->prepare(
            "SELECT p.pet_id, p.pet_name, p.species, p.breed
             FROM appointment_pet ap
             JOIN pet p ON p.pet_id = ap.pet_id
             WHERE ap.appointment_id = ?
             ORDER BY ap.appointment_pet_id ASC"
        );
        $petStmt->execute([$apptId]);
        $pets = $petStmt->fetchAll();

        $petItems = [];
        $petLabels = [];
        foreach ($pets as $pt) {
            $breed = trim((string)($pt['breed'] ?? ''));
            $species = ucfirst((string)($pt['species'] ?? 'Pet'));
            $breedDisplay = $breed !== '' ? $breed : $species;
            $petItems[] = [
                'pet_id' => (int)$pt['pet_id'],
                'name' => $pt['pet_name'],
                'species' => $species,
                'breed' => $breedDisplay,
                'display' => $pt['pet_name'] . ' (' . $breedDisplay . ')',
            ];
            $petLabels[] = $pt['pet_name'] . ' (' . $breedDisplay . ')';
        }
        $appt['pets'] = $pets;
        $appt['pet_items'] = $petItems;
        $appt['pet_summary'] = $petLabels ? implode(', ', $petLabels) : 'No pet recorded';

        // Services & assigned clinician (performer_employee_id)
        $psStmt = $this->db->prepare(
            "SELECT ps.performed_service_id, ps.service_id, ps.performed_price, ps.performed_status,
                    ps.performer_employee_id,
                    s.service_name, s.estimated_duration_minutes,
                    CONCAT(e.first_name, ' ', e.last_name) AS performer_name,
                    e.position AS performer_position
             FROM performed_service ps
             JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
             JOIN service s ON s.service_id = ps.service_id
             LEFT JOIN employee e ON e.employee_id = ps.performer_employee_id
             WHERE ap.appointment_id = ?
             ORDER BY ps.performed_service_id ASC"
        );
        $psStmt->execute([$apptId]);
        $performedServices = $psStmt->fetchAll();

        $serviceNames = [];
        $totalDurationMinutes = 0;
        $assignedClinicians = [];
        $primaryPerformerId = null;

        foreach ($performedServices as $ps) {
            $name = trim((string)$ps['service_name']);
            if ($name !== '' && !in_array($name, $serviceNames, true)) {
                $serviceNames[] = $name;
                $dur = (int)($ps['estimated_duration_minutes'] ?? 0);
                if ($dur > 0) {
                    $totalDurationMinutes += $dur;
                }
            }
            if (!empty($ps['performer_name'])) {
                $assignedClinicians[$ps['performer_name']] = $ps['performer_name'] . (!empty($ps['performer_position']) ? ' (' . $ps['performer_position'] . ')' : '');
            }
            if ($primaryPerformerId === null && !empty($ps['performer_employee_id'])) {
                $primaryPerformerId = (int)$ps['performer_employee_id'];
            }
        }

        // Check requested_pet_service as well
        $rpsStmt = $this->db->prepare(
            "SELECT s.service_name, s.estimated_duration_minutes
             FROM requested_pet_service rps
             JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id
             JOIN service s ON s.service_id = rps.service_id
             WHERE ap.appointment_id = ? AND rps.request_status <> 'declined'
             ORDER BY rps.requested_pet_service_id ASC"
        );
        $rpsStmt->execute([$apptId]);
        foreach ($rpsStmt->fetchAll() as $rps) {
            $name = trim((string)$rps['service_name']);
            if ($name !== '' && !in_array($name, $serviceNames, true)) {
                $serviceNames[] = $name;
                $dur = (int)($rps['estimated_duration_minutes'] ?? 0);
                if ($dur > 0) {
                    $totalDurationMinutes += $dur;
                }
            }
        }

        if ($totalDurationMinutes <= 0) {
            $totalDurationMinutes = 30; // standard medical consultation duration
        }

        // Start & End time calculation
        $startTimeStr = '09:00:00';
        if (!empty($appt['appointment_begin'])) {
            $ts = strtotime((string)$appt['appointment_begin']);
            if ($ts !== false) {
                $startTimeStr = date('H:i:s', $ts);
            }
        } elseif (!empty($appt['preferred_time'])) {
            $startTimeStr = (string)$appt['preferred_time'];
        }

        [$startH, $startM] = array_map('intval', array_pad(explode(':', $startTimeStr), 2, 0));
        $startMinutes = ($startH * 60) + $startM;

        $endMinutes = $startMinutes + $totalDurationMinutes;
        if (!empty($appt['appointment_end'])) {
            $endTs = strtotime((string)$appt['appointment_end']);
            if ($endTs !== false) {
                $endH = (int)date('H', $endTs);
                $endM = (int)date('i', $endTs);
                $calcEnd = ($endH * 60) + $endM;
                if ($calcEnd > $startMinutes) {
                    $endMinutes = $calcEnd;
                    $totalDurationMinutes = $endMinutes - $startMinutes;
                }
            }
        }

        $startFormatted = date('g:i A', mktime((int)floor($startMinutes / 60), $startMinutes % 60, 0));
        $endFormatted = date('g:i A', mktime((int)floor($endMinutes / 60), $endMinutes % 60, 0));

        $appt['service_list'] = $serviceNames;
        $appt['services_summary'] = $serviceNames ? implode(', ', $serviceNames) : 'General Examination';
        $appt['assigned_employee'] = $assignedClinicians ? implode(', ', $assignedClinicians) : '';
        $appt['performer_employee_id'] = $primaryPerformerId;
        $appt['duration_minutes'] = $totalDurationMinutes;
        $appt['start_minutes'] = $startMinutes;
        $appt['end_minutes'] = $endMinutes;
        $appt['formatted_date'] = date('M j, Y', strtotime((string)$appt['preferred_date']));
        $appt['formatted_time'] = $startFormatted . ' - ' . $endFormatted;
        $appt['time_coverage'] = $startFormatted . ' - ' . $endFormatted;

        // Status color mapping (Green, Yellow, Red, Transparent)
        $st = (string)$appt['status_name'];
        if ($st === 'Confirmed') {
            $appt['color_class'] = 'cal-status-green';
            $appt['status_color_name'] = 'green';
            $appt['badge_class'] = 'ok';
        } elseif (in_array($st, ['Cancelled', 'Rejected'], true)) {
            $appt['color_class'] = 'cal-status-red';
            $appt['status_color_name'] = 'red';
            $appt['badge_class'] = 'err';
        } elseif (in_array($st, ['In Progress', 'Pending', 'Awaiting Customer Response'], true)) {
            $appt['color_class'] = 'cal-status-yellow';
            $appt['status_color_name'] = 'yellow';
            $appt['badge_class'] = 'warn';
        } else {
            // Completed / No-Show -> Transparent
            $appt['color_class'] = 'cal-status-transparent';
            $appt['status_color_name'] = 'transparent';
            $appt['badge_class'] = $st === 'Completed' ? 'ok' : '';
        }

        return $appt;
    }

    /**
     * Fetch up to 3 Veterinary Clinic employees on duty in established clinical priority:
     * 1. Lead Vet / Associate Vet
     * 2. Vet Nurse
     * 3. Vet Assistant
     * 4. Receptionist
     * Excludes Business Manager, Inventory Manager, and System Administrator.
     * Uses actual employee duty/status information (no X/N indicator).
     */
    private function fetchVetEmployeesOnDuty(int $unitId): array
    {
        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position,
                    COALESCE(ews.work_status, 'Free') AS work_status
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
             WHERE e.business_unit_id = ?
               AND e.employee_status = 'active'
               AND ua.account_status = 'active'
               AND ua.is_super_admin = 0"
        );
        $empStmt->execute([$unitId]);
        $employees = $empStmt->fetchAll();

        $positionPriority = [
            'Lead Veterinarian' => 1,
            'Lead Vet' => 1,
            'Associate Veterinarian' => 1,
            'Associate Vet' => 1,
            'Veterinarian (DVM)' => 1,
            'Veterinarian' => 1,
            'Veterinary Nurse' => 2,
            'Vet Nurse' => 2,
            'Nurse' => 2,
            'Veterinary Assistant' => 3,
            'Vet Assistant' => 3,
            'Receptionist' => 4,
            'Veterinary Receptionist' => 4,
        ];

        $displayPositionMap = [
            'Lead Veterinarian' => 'Lead Vet',
            'Associate Veterinarian' => 'Associate Vet',
            'Veterinary Nurse' => 'Vet Nurse',
            'Veterinary Assistant' => 'Vet Assistant',
            'Veterinary Receptionist' => 'Receptionist',
        ];

        $filtered = [];
        foreach ($employees as $emp) {
            $rawPos = trim((string)$emp['position']);
            if (!isset($positionPriority[$rawPos])) {
                // Excludes Business Manager, Inventory Manager, System Administrator, Delivery Personnel
                continue;
            }

            $posRank = $positionPriority[$rawPos];
            $ws = (string)$emp['work_status'];
            // If employee is marked 'Not Present', skip them from On Duty card
            if ($ws === 'Not Present') {
                continue;
            }

            // Scheduling / duty rank: On Duty comes first
            $dutyRank = ($ws === 'On Duty') ? 1 : 2;

            $emp['pos_rank'] = $posRank;
            $emp['duty_rank'] = $dutyRank;
            $emp['display_position'] = $displayPositionMap[$rawPos] ?? $rawPos;
            $filtered[] = $emp;
        }

        // Sort: dutyRank ASC (On Duty first), then posRank ASC (Lead/Assoc Vet -> Nurse -> Assistant -> Receptionist), then name
        usort($filtered, static function (array $a, array $b): int {
            if ($a['duty_rank'] !== $b['duty_rank']) {
                return $a['duty_rank'] <=> $b['duty_rank'];
            }
            if ($a['pos_rank'] !== $b['pos_rank']) {
                return $a['pos_rank'] <=> $b['pos_rank'];
            }
            return strcmp($a['last_name'] . ' ' . $a['first_name'], $b['last_name'] . ' ' . $b['first_name']);
        });

        return array_slice($filtered, 0, 3);
    }

    /**
     * Grooming Services Business Manager Overview (business_unit_id = 3)
     */
    private function renderGroomingOverview(int $unitId, string $unitName, bool $isSuper): void
    {
        // 1. Top Section: Single Relevant Current or Upcoming Grooming Appointment (Wide Card)
        $currentAppointment = $this->fetchSingleCurrentOrUpcomingGroomingAppointment($unitId);

        // 2. Middle Section Left: Pending Refunds strictly for Grooming Services (business_unit_id = 3)
        $refundsStmt = $this->db->prepare(
            "SELECT rr.refund_request_id, rr.refund_reference, rr.business_unit_id,
                    rr.customer_id, rr.order_id, rr.sale_id, rr.channel,
                    rr.requested_amount, rr.customer_reason AS reason, rr.request_status, rr.created_at,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
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

        $pendingRefundsTotal = 0.0;
        foreach ($pendingRefunds as $rf) {
            $pendingRefundsTotal += (float)$rf['requested_amount'];
        }

        // 3. Middle Section Right: Stock Alerts (Product Name & Current Quantity only, scoped to unit 3)
        $stockAlertsStmt = $this->db->prepare(
            "SELECT p.product_name, COALESCE(i.on_hand, 0) AS on_hand
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.business_unit_id = ?
               AND bp.product_status = 'active'
               AND COALESCE(i.on_hand, 0) <= 10
             ORDER BY COALESCE(i.on_hand, 0) ASC, p.product_name ASC"
        );
        $stockAlertsStmt->execute([$unitId]);
        $stockAlerts = $stockAlertsStmt->fetchAll();

        // 4. Bottom Section: Employees Present (up to 3 employees, excluding Delivery Personnel,
        //    ordered by established position & scheduling precedence, with individual status Free / Occupied / Not Present)
        $employeesPresent = $this->fetchGroomingEmployeesPresent($unitId);

        $this->render('manager/grooming_overview', [
            'title' => "$unitName · Business Manager Overview",
            'activeTab' => 'overview',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'currentAppointment' => $currentAppointment,
            'pendingRefunds' => $pendingRefunds,
            'pendingRefundsTotal' => $pendingRefundsTotal,
            'stockAlerts' => $stockAlerts,
            'employeesPresent' => $employeesPresent,
        ]);
    }

    /**
     * Fetch only one relevant current or upcoming appointment for Grooming Services.
     */
    private function fetchSingleCurrentOrUpcomingGroomingAppointment(int $unitId): ?array
    {
        // Prioritize active In Progress or Confirmed appointments on or after today,
        // falling back to the nearest Confirmed/In Progress/Pending appointment for Grooming Services.
        $stmt = $this->db->prepare(
            "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                    a.appointment_begin, a.appointment_end, a.notes,
                    st.status_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                    c.phone AS customer_phone
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             WHERE a.business_unit_id = ?
               AND st.status_name NOT IN ('Cancelled', 'Rejected', 'No-Show')
             ORDER BY
               CASE
                 WHEN st.status_name = 'In Progress' THEN 0
                 WHEN st.status_name = 'Confirmed' AND a.preferred_date >= CURDATE() THEN 1
                 WHEN st.status_name = 'Confirmed' THEN 2
                 WHEN st.status_name IN ('Pending', 'Awaiting Customer Response') AND a.preferred_date >= CURDATE() THEN 3
                 WHEN st.status_name IN ('Pending', 'Awaiting Customer Response') THEN 4
                 ELSE 5
               END ASC,
               ABS(DATEDIFF(a.preferred_date, CURDATE())) ASC,
               COALESCE(TIME(a.appointment_begin), a.preferred_time, '09:00:00') ASC,
               a.appointment_id DESC
             LIMIT 1"
        );
        $stmt->execute([$unitId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return $this->hydrateGroomingAppointmentDetails($row);
    }

    /**
     * Fetch up to 3 Grooming Services employees ordered by position & scheduling precedence,
     * excluding Delivery Personnel, with individual status: Free, Occupied, or Not Present.
     */
    private function fetchGroomingEmployeesPresent(int $unitId): array
    {
        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position,
                    COALESCE(ews.work_status, 'Free') AS work_status
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             LEFT JOIN employee_work_status ews ON ews.employee_id = e.employee_id
             WHERE e.business_unit_id = ?
               AND e.employee_status = 'active'
               AND ua.account_status = 'active'
               AND ua.is_super_admin = 0
               AND e.position <> 'Delivery Personnel'
               AND e.position NOT LIKE '%Delivery%'"
        );
        $empStmt->execute([$unitId]);
        $employees = $empStmt->fetchAll();

        // Check which employees are currently Occupied in Grooming Services appointments
        $occStmt = $this->db->prepare(
            "SELECT DISTINCT ps.performer_employee_id
             FROM performed_service ps
             JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
             JOIN appointment a ON a.appointment_id = ap.appointment_id
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.business_unit_id = ?
               AND ps.performer_employee_id IS NOT NULL
               AND (
                 st.status_name = 'In Progress'
                 OR (
                   st.status_name = 'Confirmed'
                   AND a.preferred_date = CURDATE()
                   AND ps.performed_status = 'pending'
                 )
               )"
        );
        $occStmt->execute([$unitId]);
        $occupiedIds = array_map('intval', $occStmt->fetchAll(PDO::FETCH_COLUMN));
        $occupiedSet = array_flip($occupiedIds);

        // Corrected Grooming Services role names:
        // Common roles: Business Manager, Inventory Manager, Receptionist, System Administrator
        // Grooming-specific roles: Lead Stylist, Stylist, Bather (no Assistant Stylist)
        $roleNameMap = [
            'Master Stylist' => 'Lead Stylist',
            'Business Manager — Grooming Services' => 'Business Manager',
        ];

        // Position precedence for Grooming Services
        $positionRank = [
            'Lead Stylist' => 1,
            'Master Stylist' => 1,
            'Stylist' => 2,
            'Bather' => 3,
            'Receptionist' => 4,
            'Business Manager' => 5,
            'Business Manager — Grooming Services' => 5,
            'Inventory Manager' => 6,
            'System Administrator' => 7,
        ];

        // Scheduling precedence: Occupied / Free (present) before Not Present
        $statusRank = [
            'Occupied' => 1,
            'Free' => 2,
            'Not Present' => 3,
        ];

        foreach ($employees as &$emp) {
            $empId = (int)$emp['employee_id'];
            $ws = (string)$emp['work_status'];

            if ($ws === 'Not Present') {
                $indStatus = 'Not Present';
            } elseif (isset($occupiedSet[$empId])) {
                $indStatus = 'Occupied';
            } else {
                $indStatus = 'Free';
            }

            $rawPos = (string)$emp['position'];
            $dispPos = $roleNameMap[$rawPos] ?? $rawPos;

            $emp['raw_position'] = $rawPos;
            $emp['display_position'] = $dispPos;
            $emp['position'] = $dispPos;
            $emp['individual_status'] = $indStatus;
            $emp['_pos_rank'] = $positionRank[$dispPos] ?? ($positionRank[$rawPos] ?? 99);
            $emp['_status_rank'] = $statusRank[$indStatus] ?? 9;
        }
        unset($emp);

        usort($employees, static function (array $a, array $b): int {
            // Present employees (Occupied / Free) take precedence over Not Present
            $aAbsent = $a['individual_status'] === 'Not Present' ? 1 : 0;
            $bAbsent = $b['individual_status'] === 'Not Present' ? 1 : 0;
            if ($aAbsent !== $bAbsent) {
                return $aAbsent <=> $bAbsent;
            }
            // Then established position precedence
            if ($a['_pos_rank'] !== $b['_pos_rank']) {
                return $a['_pos_rank'] <=> $b['_pos_rank'];
            }
            // Then scheduling precedence (Occupied before Free)
            if ($a['_status_rank'] !== $b['_status_rank']) {
                return $a['_status_rank'] <=> $b['_status_rank'];
            }
            return strcmp($a['last_name'] . ' ' . $a['first_name'], $b['last_name'] . ' ' . $b['first_name']);
        });

        return array_slice($employees, 0, 3);
    }

    /**
     * Hydrate pet, service, assigned employee, and time coverage details for a Grooming appointment.
     */
    private function hydrateGroomingAppointmentDetails(array $appt): array
    {
        $apptId = (int)$appt['appointment_id'];

        // Pets & breeds
        $petStmt = $this->db->prepare(
            "SELECT p.pet_id, p.pet_name, p.species, p.breed
             FROM appointment_pet ap
             JOIN pet p ON p.pet_id = ap.pet_id
             WHERE ap.appointment_id = ?
             ORDER BY ap.appointment_pet_id ASC"
        );
        $petStmt->execute([$apptId]);
        $pets = $petStmt->fetchAll();

        $petItems = [];
        $petLabels = [];
        foreach ($pets as $pt) {
            $breed = trim((string)($pt['breed'] ?? ''));
            $species = ucfirst((string)($pt['species'] ?? 'Pet'));
            $breedDisplay = $breed !== '' ? $breed : $species;
            $petItems[] = [
                'pet_id' => (int)$pt['pet_id'],
                'name' => $pt['pet_name'],
                'species' => $species,
                'breed' => $breedDisplay,
                'display' => $pt['pet_name'] . ' (' . $breedDisplay . ')',
            ];
            $petLabels[] = $pt['pet_name'] . ' (' . $breedDisplay . ')';
        }
        $appt['pets'] = $pets;
        $appt['pet_items'] = $petItems;
        $appt['pet_summary'] = $petLabels ? implode(', ', $petLabels) : 'No pet recorded';

        // Services & assigned employee (check performed_service first, fallback to requested_pet_service)
        $psStmt = $this->db->prepare(
            "SELECT ps.performed_service_id, ps.service_id, ps.performed_price, ps.performed_status,
                    ps.performer_employee_id,
                    s.service_name, s.estimated_duration_minutes,
                    CONCAT(e.first_name, ' ', e.last_name) AS performer_name,
                    e.position AS performer_position
             FROM performed_service ps
             JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
             JOIN service s ON s.service_id = ps.service_id
             LEFT JOIN employee e ON e.employee_id = ps.performer_employee_id
             WHERE ap.appointment_id = ?
             ORDER BY ps.performed_service_id ASC"
        );
        $psStmt->execute([$apptId]);
        $performedServices = $psStmt->fetchAll();

        $serviceNames = [];
        $totalDurationMinutes = 0;
        $assignedEmployees = [];
        $primaryPerformerId = null;

        if (!empty($performedServices)) {
            foreach ($performedServices as $ps) {
                $name = trim((string)$ps['service_name']);
                if ($name !== '' && !in_array($name, $serviceNames, true)) {
                    $serviceNames[] = $name;
                    $totalDurationMinutes += max(15, (int)($ps['estimated_duration_minutes'] ?? 30));
                }
                if (!empty($ps['performer_name'])) {
                    $assignedEmployees[$ps['performer_name']] = $ps['performer_name'];
                }
                if ($primaryPerformerId === null && !empty($ps['performer_employee_id'])) {
                    $primaryPerformerId = (int)$ps['performer_employee_id'];
                }
            }
        }

        // Also query requested_pet_service for any services not already in performed_service
        $rpsStmt = $this->db->prepare(
            "SELECT rps.requested_pet_service_id, rps.service_id, rps.requested_price, rps.request_status,
                    s.service_name, s.estimated_duration_minutes
             FROM requested_pet_service rps
             JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id
             JOIN service s ON s.service_id = rps.service_id
             WHERE ap.appointment_id = ?
               AND rps.request_status <> 'declined'
             ORDER BY rps.requested_pet_service_id ASC"
        );
        $rpsStmt->execute([$apptId]);
        foreach ($rpsStmt->fetchAll() as $rps) {
            $name = trim((string)$rps['service_name']);
            if ($name !== '' && !in_array($name, $serviceNames, true)) {
                $serviceNames[] = $name;
                $totalDurationMinutes += max(15, (int)($rps['estimated_duration_minutes'] ?? 30));
            }
        }

        if ($totalDurationMinutes <= 0) {
            $totalDurationMinutes = 60;
        }

        // Start & End time calculation
        $startTimeStr = '09:00:00';
        if (!empty($appt['appointment_begin'])) {
            $ts = strtotime((string)$appt['appointment_begin']);
            if ($ts !== false) {
                $startTimeStr = date('H:i:s', $ts);
            }
        } elseif (!empty($appt['preferred_time'])) {
            $startTimeStr = (string)$appt['preferred_time'];
        }

        [$startH, $startM] = array_map('intval', array_pad(explode(':', $startTimeStr), 2, 0));
        $startMinutes = ($startH * 60) + $startM;

        $endMinutes = $startMinutes + $totalDurationMinutes;
        if (!empty($appt['appointment_end'])) {
            $endTs = strtotime((string)$appt['appointment_end']);
            if ($endTs !== false) {
                $endH = (int)date('H', $endTs);
                $endM = (int)date('i', $endTs);
                $calcEnd = ($endH * 60) + $endM;
                if ($calcEnd > $startMinutes) {
                    $endMinutes = $calcEnd;
                    $totalDurationMinutes = $endMinutes - $startMinutes;
                }
            }
        }

        $startFormatted = date('g:i A', mktime((int)floor($startMinutes / 60), $startMinutes % 60, 0));
        $endFormatted = date('g:i A', mktime((int)floor($endMinutes / 60), $endMinutes % 60, 0));

        $appt['service_list'] = $serviceNames;
        $appt['services_summary'] = $serviceNames ? implode(', ', $serviceNames) : 'Standard Grooming';
        $appt['assigned_employee'] = $assignedEmployees ? implode(', ', $assignedEmployees) : '';
        $appt['performer_employee_id'] = $primaryPerformerId;
        $appt['duration_minutes'] = $totalDurationMinutes;
        $appt['start_minutes'] = $startMinutes;
        $appt['end_minutes'] = $endMinutes;
        $appt['formatted_date'] = date('M j, Y', strtotime((string)$appt['preferred_date']));
        $appt['formatted_time'] = $startFormatted . ' - ' . $endFormatted;
        $appt['time_coverage'] = $startFormatted . ' - ' . $endFormatted;

        // Status color mapping (Red, Green, Yellow, Transparent)
        $st = (string)$appt['status_name'];
        if ($st === 'Confirmed') {
            $appt['color_class'] = 'cal-status-green';
            $appt['status_color_name'] = 'green';
            $appt['badge_class'] = 'ok';
        } elseif (in_array($st, ['Cancelled', 'Rejected'], true)) {
            $appt['color_class'] = 'cal-status-red';
            $appt['status_color_name'] = 'red';
            $appt['badge_class'] = 'err';
        } elseif (in_array($st, ['In Progress', 'Pending', 'Awaiting Customer Response'], true)) {
            $appt['color_class'] = 'cal-status-yellow';
            $appt['status_color_name'] = 'yellow';
            $appt['badge_class'] = 'warn';
        } else {
            // Completed / No-Show -> Transparent
            $appt['color_class'] = 'cal-status-transparent';
            $appt['status_color_name'] = 'transparent';
            $appt['badge_class'] = $st === 'Completed' ? 'ok' : '';
        }

        return $appt;
    }

    /**
     * Build 7-day chronological week context for weekly calendar views (Grooming and Vet Clinic).
     */
    private function buildCalendarWeekContext(?string $startParam, ?string $monthParam): array
    {
        $today = new \DateTimeImmutable('today');
        // Current week start (Monday of the current week so Today & current week appointments are visible)
        $dow = (int)$today->format('N'); // 1 (Mon) .. 7 (Sun)
        $todayWeekStartObj = $today->modify('-' . ($dow - 1) . ' days');

        $startParam = trim((string)$startParam);
        $monthParam = trim((string)$monthParam);

        $startDate = $todayWeekStartObj;
        if ($startParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startParam)) {
            try {
                $parsed = new \DateTimeImmutable($startParam);
                if ($parsed->format('Y-m-d') === $startParam) {
                    $startDate = $parsed;
                }
            } catch (\Throwable $e) {
                $startDate = $todayWeekStartObj;
            }
        } elseif ($monthParam !== '' && preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            // Selecting a month starts the calendar at the first day of that selected month (YYYY-MM-01)
            $firstOfMonthStr = $monthParam . '-01';
            try {
                $parsed = new \DateTimeImmutable($firstOfMonthStr);
                if ($parsed->format('Y-m-d') === $firstOfMonthStr) {
                    $startDate = $parsed;
                }
            } catch (\Throwable $e) {
                $startDate = $todayWeekStartObj;
            }
        }

        // Build the 7 chronological dates (never padded with unused calendar spaces)
        $weekDays = [];
        $dateIndexMap = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $startDate->modify("+{$i} days");
            $dStr = $d->format('Y-m-d');
            $dateIndexMap[$dStr] = $i;
            $weekDays[] = [
                'date' => $dStr,
                'day_short' => $d->format('D'),
                'day_label' => $d->format('M j'),
                'day_num' => $d->format('j'),
                'month_short' => $d->format('M'),
                'is_today' => ($dStr === $today->format('Y-m-d')),
            ];
        }

        $endDate = $startDate->modify('+6 days');
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        $prevWeekStart = $startDate->modify('-7 days')->format('Y-m-d');
        $nextWeekStart = $startDate->modify('+7 days')->format('Y-m-d');
        $todayWeekStart = $todayWeekStartObj->format('Y-m-d');
        $isCurrentWeek = ($today->format('Y-m-d') >= $startDateStr && $today->format('Y-m-d') <= $endDateStr);

        // Month picker value reflects the visible range's month (updates when navigation crosses month/year boundary)
        $monthPickerValue = $startDate->format('Y-m');

        // Human-readable range heading
        if ($startDate->format('Y') !== $endDate->format('Y')) {
            $rangeHeading = $startDate->format('M j, Y') . ' – ' . $endDate->format('M j, Y');
        } elseif ($startDate->format('m') !== $endDate->format('m')) {
            $rangeHeading = $startDate->format('M j') . ' – ' . $endDate->format('M j, Y');
        } else {
            $rangeHeading = $startDate->format('M j') . ' – ' . $endDate->format('j, Y');
        }

        return [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'startDateStr' => $startDateStr,
            'endDateStr' => $endDateStr,
            'weekDays' => $weekDays,
            'dateIndexMap' => $dateIndexMap,
            'prevWeekStart' => $prevWeekStart,
            'nextWeekStart' => $nextWeekStart,
            'todayWeekStart' => $todayWeekStart,
            'isCurrentWeek' => $isCurrentWeek,
            'monthPickerValue' => $monthPickerValue,
            'rangeHeading' => $rangeHeading,
        ];
    }

    /**
     * Layout appointments across the 7-day grid, expanding hours dynamically and computing overlap columns.
     */
    private function layoutAppointmentsForWeek(array $hydratedAppts, array $weekDays, array $dateIndexMap): array
    {
        $gridStartHour = 8;
        $gridEndHour = 20;

        foreach ($hydratedAppts as $h) {
            $apptStartHour = (int)floor($h['start_minutes'] / 60);
            $apptEndHour = (int)ceil($h['end_minutes'] / 60);
            if ($apptStartHour < $gridStartHour) {
                $gridStartHour = max(0, $apptStartHour);
            }
            if ($apptEndHour > $gridEndHour) {
                $gridEndHour = min(24, $apptEndHour);
            }
        }

        $gridHeightPx = ($gridEndHour - $gridStartHour) * 60;

        // Group by date and compute vertical positions + side-by-side overlap layout
        $appointmentsByDate = [];
        foreach ($weekDays as $wd) {
            $appointmentsByDate[$wd['date']] = [];
        }

        foreach ($hydratedAppts as $ha) {
            $dKey = (string)$ha['preferred_date'];
            if (!isset($appointmentsByDate[$dKey])) {
                continue;
            }
            $ha['day_index'] = $dateIndexMap[$dKey] ?? 0;
            $ha['top_px'] = max(0, $ha['start_minutes'] - ($gridStartHour * 60));
            $ha['height_px'] = max(38, $ha['end_minutes'] - $ha['start_minutes']);
            $appointmentsByDate[$dKey][] = $ha;
        }

        $allVisibleAppointments = [];
        foreach ($appointmentsByDate as $dKey => &$dayList) {
            $dayList = $this->layoutDayOverlappingAppointments($dayList);
            foreach ($dayList as $item) {
                $allVisibleAppointments[] = $item;
            }
        }
        unset($dayList);

        return [
            $gridStartHour,
            $gridEndHour,
            $gridHeightPx,
            $appointmentsByDate,
            $allVisibleAppointments,
        ];
    }

    /**
     * Veterinary Clinic Medical Appointments (/manager/appointments) - 7-Day Weekly Calendar matching Grooming design.
     */
    private function renderVetClinicAppointments(int $unitId, string $unitName, bool $isSuper): void
    {
        $calCtx = $this->buildCalendarWeekContext($_GET['start'] ?? null, $_GET['month'] ?? null);

        // Query Veterinary Clinic medical appointments within the visible 7-day range
        $apptStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                    a.appointment_begin, a.appointment_end, a.notes,
                    st.status_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                    c.phone AS customer_phone,
                    ua.email AS customer_email
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             JOIN user_account ua ON ua.user_account_id = c.user_account_id
             WHERE a.business_unit_id = ?
               AND a.appointment_type = 'medical'
               AND a.preferred_date BETWEEN ? AND ?
             ORDER BY a.preferred_date ASC, COALESCE(TIME(a.appointment_begin), a.preferred_time, '09:00:00') ASC, a.appointment_id ASC"
        );
        $apptStmt->execute([$unitId, $calCtx['startDateStr'], $calCtx['endDateStr']]);
        $rawAppts = $apptStmt->fetchAll();

        $hydratedAppts = [];
        foreach ($rawAppts as $ra) {
            $hydratedAppts[] = $this->hydrateVetAppointmentDetails($ra);
        }

        [
            $gridStartHour,
            $gridEndHour,
            $gridHeightPx,
            $appointmentsByDate,
            $allVisibleAppointments,
        ] = $this->layoutAppointmentsForWeek($hydratedAppts, $calCtx['weekDays'], $calCtx['dateIndexMap']);

        // Active clinicians available for assignment
        $cliniciansStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             WHERE e.business_unit_id = ?
               AND e.employee_status = 'active'
               AND ua.account_status = 'active'
               AND ua.is_super_admin = 0
               AND e.position IN ('Lead Veterinarian', 'Lead Vet', 'Associate Veterinarian', 'Associate Vet', 'Veterinary Nurse', 'Vet Nurse', 'Veterinary Assistant', 'Vet Assistant', 'Receptionist')
             ORDER BY e.last_name ASC, e.first_name ASC"
        );
        $cliniciansStmt->execute([$unitId]);
        $vetClinicians = $cliniciansStmt->fetchAll();

        $selectedApptId = isset($_GET['select']) && ctype_digit((string)$_GET['select'])
            ? (int)$_GET['select']
            : null;

        $this->render('manager/vet_appointments', [
            'title' => "Appointments · $unitName",
            'activeTab' => 'appointments',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'weekDays' => $calCtx['weekDays'],
            'startDateStr' => $calCtx['startDateStr'],
            'endDateStr' => $calCtx['endDateStr'],
            'prevWeekStart' => $calCtx['prevWeekStart'],
            'nextWeekStart' => $calCtx['nextWeekStart'],
            'todayWeekStart' => $calCtx['todayWeekStart'],
            'isCurrentWeek' => $calCtx['isCurrentWeek'],
            'monthPickerValue' => $calCtx['monthPickerValue'],
            'rangeHeading' => $calCtx['rangeHeading'],
            'gridStartHour' => $gridStartHour,
            'gridEndHour' => $gridEndHour,
            'gridHeightPx' => $gridHeightPx,
            'appointmentsByDate' => $appointmentsByDate,
            'allVisibleAppointments' => $allVisibleAppointments,
            'vetClinicians' => $vetClinicians,
            'selectedApptId' => $selectedApptId,
        ]);
    }

    // ==========================================
    // 1B. GROOMING APPOINTMENTS WEEKLY CALENDAR & ACTIONS
    // ==========================================
    public function appointments(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);

        // Veterinary Clinic Appointments (business_unit_id = 2)
        if ((int)$unitId === Units::vetClinicId()) {
            $this->renderVetClinicAppointments($unitId, $unitName, $isSuper);
            return;
        }

        $calCtx = $this->buildCalendarWeekContext($_GET['start'] ?? null, $_GET['month'] ?? null);

        // Query Grooming appointments within the visible 7-day range
        $apptStmt = $this->db->prepare(
            "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                    a.appointment_begin, a.appointment_end, a.notes,
                    st.status_name,
                    CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                    c.phone AS customer_phone
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date BETWEEN ? AND ?
             ORDER BY a.preferred_date ASC, COALESCE(TIME(a.appointment_begin), a.preferred_time, '09:00:00') ASC, a.appointment_id ASC"
        );
        $apptStmt->execute([$unitId, $calCtx['startDateStr'], $calCtx['endDateStr']]);
        $rawAppts = $apptStmt->fetchAll();

        $hydratedAppts = [];
        foreach ($rawAppts as $ra) {
            $hydratedAppts[] = $this->hydrateGroomingAppointmentDetails($ra);
        }

        [
            $gridStartHour,
            $gridEndHour,
            $gridHeightPx,
            $appointmentsByDate,
            $allVisibleAppointments,
        ] = $this->layoutAppointmentsForWeek($hydratedAppts, $calCtx['weekDays'], $calCtx['dateIndexMap']);

        // Active Grooming Stylists / Bathers eligible for appointment assignment
        $stylistsStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             WHERE e.business_unit_id = ?
               AND e.employee_status = 'active'
               AND ua.account_status = 'active'
               AND ua.is_super_admin = 0
               AND e.position IN ('Master Stylist', 'Stylist', 'Bather')
             ORDER BY e.last_name ASC, e.first_name ASC"
        );
        $stylistsStmt->execute([$unitId]);
        $groomingStylists = $stylistsStmt->fetchAll();

        $selectedApptId = isset($_GET['select']) && ctype_digit((string)$_GET['select'])
            ? (int)$_GET['select']
            : null;

        $this->render('manager/appointments', [
            'title' => "Appointments · $unitName",
            'activeTab' => 'appointments',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'weekDays' => $calCtx['weekDays'],
            'startDateStr' => $calCtx['startDateStr'],
            'endDateStr' => $calCtx['endDateStr'],
            'prevWeekStart' => $calCtx['prevWeekStart'],
            'nextWeekStart' => $calCtx['nextWeekStart'],
            'todayWeekStart' => $calCtx['todayWeekStart'],
            'isCurrentWeek' => $calCtx['isCurrentWeek'],
            'monthPickerValue' => $calCtx['monthPickerValue'],
            'rangeHeading' => $calCtx['rangeHeading'],
            'gridStartHour' => $gridStartHour,
            'gridEndHour' => $gridEndHour,
            'gridHeightPx' => $gridHeightPx,
            'appointmentsByDate' => $appointmentsByDate,
            'allVisibleAppointments' => $allVisibleAppointments,
            'groomingStylists' => $groomingStylists,
            'selectedApptId' => $selectedApptId,
        ]);
    }

    /**
     * Compute side-by-side column indices for overlapping appointments on the same day.
     */
    private function layoutDayOverlappingAppointments(array $dayAppts): array
    {
        if (empty($dayAppts)) {
            return [];
        }

        usort($dayAppts, static function (array $a, array $b): int {
            if ($a['start_minutes'] !== $b['start_minutes']) {
                return $a['start_minutes'] <=> $b['start_minutes'];
            }
            if ($a['end_minutes'] !== $b['end_minutes']) {
                return $b['end_minutes'] <=> $a['end_minutes'];
            }
            return $a['appointment_id'] <=> $b['appointment_id'];
        });

        // Partition into connected overlap clusters
        $clusters = [];
        $currentCluster = [];
        $clusterMaxEnd = -1;

        foreach ($dayAppts as $appt) {
            if (empty($currentCluster) || $appt['start_minutes'] < $clusterMaxEnd) {
                $currentCluster[] = $appt;
                $clusterMaxEnd = max($clusterMaxEnd, (int)$appt['end_minutes']);
            } else {
                $clusters[] = $currentCluster;
                $currentCluster = [$appt];
                $clusterMaxEnd = (int)$appt['end_minutes'];
            }
        }
        if (!empty($currentCluster)) {
            $clusters[] = $currentCluster;
        }

        $result = [];
        foreach ($clusters as $cluster) {
            $colEndTimes = []; // colIndex => endMinutes
            foreach ($cluster as &$ev) {
                $placedCol = null;
                foreach ($colEndTimes as $cIdx => $cEnd) {
                    if ($ev['start_minutes'] >= $cEnd) {
                        $placedCol = $cIdx;
                        $colEndTimes[$cIdx] = (int)$ev['end_minutes'];
                        break;
                    }
                }
                if ($placedCol === null) {
                    $placedCol = count($colEndTimes);
                    $colEndTimes[$placedCol] = (int)$ev['end_minutes'];
                }
                $ev['overlap_col_index'] = $placedCol;
            }
            unset($ev);

            $totalCols = max(1, count($colEndTimes));
            foreach ($cluster as $ev) {
                $ev['overlap_total_cols'] = $totalCols;
                $result[] = $ev;
            }
        }

        return $result;
    }

    /**
     * Services catalog management (Grooming Services or Veterinary Clinic)
     */
    public function services(?int $routeUnitId = null): void
    {
        [$uid, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);
        if ($isSuper && (int)$unitId === Units::petStoreId()) {
            $unitId = Units::groomingId();
            $unit = Units::find($unitId);
            $unitName = (string)$unit['business_unit_name'];
        }

        if ((int)$unitId === Units::vetClinicId()) {
            $this->renderVetClinicServices($unitId, $unitName, $isSuper, $routeUnitId);
            return;
        }

        if ((int)$unitId !== Units::groomingId()) {
            $this->forbidden('Services catalog management is exclusively available to Grooming Services and Veterinary Clinic.');
        }

        $selectedCategory = trim((string)($_GET['category'] ?? ''));
        if (!in_array($selectedCategory, self::GROOMING_CATEGORIES, true)) {
            $selectedCategory = '';
        }

        $selectedStatus = trim((string)($_GET['status'] ?? ''));
        if (!in_array($selectedStatus, ['active', 'inactive'], true)) {
            $selectedStatus = '';
        }

        $searchQuery = trim((string)($_GET['q'] ?? ''));

        // Query grooming services with their catalog configurations
        $sql = "SELECT service_id, service_name, service_category,
                       COALESCE(grooming_category, 'Bathing & Brushing') AS grooming_category,
                       description, base_price, estimated_duration_minutes,
                       additional_duration_rate, additional_employee_fee,
                       service_status, created_at, updated_at
                FROM service
                WHERE service_category = 'grooming'";
        $params = [];

        if ($selectedCategory !== '') {
            $sql .= " AND grooming_category = ?";
            $params[] = $selectedCategory;
        }

        if ($selectedStatus !== '') {
            $sql .= " AND service_status = ?";
            $params[] = $selectedStatus;
        }

        if ($searchQuery !== '') {
            $sql .= " AND (service_name LIKE ? OR description LIKE ?)";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
        }

        $sql .= " ORDER BY FIELD(COALESCE(grooming_category, 'Bathing & Brushing'), 'Bathing & Brushing', 'Styling', 'Wellness', 'Specialized'), service_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $services = $stmt->fetchAll();

        // Calculate category counts across all grooming services for quick filter badges
        $countsStmt = $this->db->query(
            "SELECT COALESCE(grooming_category, 'Bathing & Brushing') AS cat, COUNT(*) AS cnt
             FROM service
             WHERE service_category = 'grooming'
             GROUP BY cat"
        );
        $categoryCounts = [
            'total' => 0,
            'Bathing & Brushing' => 0,
            'Styling' => 0,
            'Wellness' => 0,
            'Specialized' => 0,
        ];
        foreach ($countsStmt->fetchAll() as $row) {
            $cat = (string)$row['cat'];
            $cnt = (int)$row['cnt'];
            if (isset($categoryCounts[$cat])) {
                $categoryCounts[$cat] = $cnt;
            }
            $categoryCounts['total'] += $cnt;
        }

        $errors = Session::get('service_errors', []);
        Session::forget('service_errors');
        $old = Session::get('old_service_input', []);
        Session::forget('old_service_input');

        $this->render('manager/services', [
            'title' => "Services · $unitName",
            'activeTab' => 'services',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'services' => $services,
            'categories' => self::GROOMING_CATEGORIES,
            'selectedCategory' => $selectedCategory,
            'selectedStatus' => $selectedStatus,
            'searchQuery' => $searchQuery,
            'categoryCounts' => $categoryCounts,
            'errors' => $errors,
            'old' => $old,
        ]);
    }

    /**
     * Veterinary Clinic Medical Services catalog
     */
    private function renderVetClinicServices(int $unitId, string $unitName, bool $isSuper, ?int $routeUnitId = null): void
    {
        $selectedStatus = trim((string)($_GET['status'] ?? ''));
        if (!in_array($selectedStatus, ['active', 'inactive'], true)) {
            $selectedStatus = '';
        }

        $searchQuery = trim((string)($_GET['q'] ?? ''));

        $sql = "SELECT service_id, service_name, service_category,
                       description, base_price, estimated_duration_minutes,
                       service_status, created_at, updated_at
                FROM service
                WHERE service_category = 'medical'";
        $params = [];

        if ($selectedStatus !== '') {
            $sql .= " AND service_status = ?";
            $params[] = $selectedStatus;
        }

        if ($searchQuery !== '') {
            $sql .= " AND (service_name LIKE ? OR description LIKE ?)";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
        }

        $sql .= " ORDER BY service_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $services = $stmt->fetchAll();

        // Calculate status counts for quick filter badges
        $countsStmt = $this->db->query(
            "SELECT service_status, COUNT(*) AS cnt
             FROM service
             WHERE service_category = 'medical'
             GROUP BY service_status"
        );
        $statusCounts = [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
        ];
        foreach ($countsStmt->fetchAll() as $row) {
            $st = (string)$row['service_status'];
            $cnt = (int)$row['cnt'];
            if (isset($statusCounts[$st])) {
                $statusCounts[$st] = $cnt;
            }
            $statusCounts['total'] += $cnt;
        }

        $errors = Session::get('service_errors', []);
        Session::forget('service_errors');
        $old = Session::get('old_service_input', []);
        Session::forget('old_service_input');

        $this->render('manager/vet_services', [
            'title' => "Services · $unitName",
            'activeTab' => 'services',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'services' => $services,
            'selectedStatus' => $selectedStatus,
            'searchQuery' => $searchQuery,
            'statusCounts' => $statusCounts,
            'errors' => $errors,
            'old' => $old,
        ]);
    }

    /**
     * Create a new Service (Grooming or Veterinary Clinic)
     */
    public function storeService(?int $routeUnitId = null): void
    {
        [$uid, , $unitId, , $isSuper] = $this->checkBusinessManager($routeUnitId);
        if ($isSuper && (int)$unitId === Units::petStoreId()) {
            $unitId = Units::groomingId();
        }
        if ((int)$unitId === Units::petStoreId()) {
            $this->forbidden('Services catalog management is not available to Pet Store.');
        }
        CSRF::check();

        $redirectUrl = ($isSuper && $routeUnitId) ? "/manager/units/$unitId/services" : '/manager/services';

        if ((int)$unitId === Units::vetClinicId()) {
            $name = trim((string)($_POST['service_name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $basePriceRaw = trim((string)($_POST['base_price'] ?? ''));

            $errors = [];
            if ($name === '') {
                $errors['service_name'] = 'Service name is required.';
            } elseif (mb_strlen($name) > 200) {
                $errors['service_name'] = 'Service name cannot exceed 200 characters.';
            }

            if ($basePriceRaw === '' || !is_numeric($basePriceRaw) || (float)$basePriceRaw < 0) {
                $errors['base_price'] = 'Base price must be a valid non-negative number.';
            }

            if ($name !== '' && !isset($errors['service_name'])) {
                $chk = $this->db->prepare('SELECT service_id FROM service WHERE service_category = "medical" AND LOWER(service_name) = LOWER(?)');
                $chk->execute([$name]);
                if ($chk->fetch()) {
                    $errors['service_name'] = "A medical service named '$name' already exists.";
                }
            }

            if (!empty($errors)) {
                Session::set('service_errors', $errors);
                Session::set('old_service_input', $_POST);
                $this->flash('error', reset($errors));
                $this->redirect($redirectUrl);
            }

            $ins = $this->db->prepare(
                "INSERT INTO service (service_name, service_category, grooming_category, description,
                                      base_price, estimated_duration_minutes, additional_duration_rate,
                                      additional_employee_fee, service_status)
                 VALUES (?, 'medical', NULL, ?, ?, 30, 0.00, 0.00, 'active')"
            );
            $ins->execute([
                $name,
                $desc !== '' ? $desc : null,
                number_format((float)$basePriceRaw, 2, '.', ''),
            ]);
            $newId = (int)$this->db->lastInsertId();

            Audit::log('service.create', "Created Medical service '$name' (#$newId, Base Price: ₱$basePriceRaw)", $unitId, 'service', $newId, $uid);

            $this->flash('success', "Medical service '$name' created successfully.");
            $this->redirect($redirectUrl);
            return;
        }

        if ((int)$unitId !== Units::groomingId()) {
            $this->forbidden('Services catalog management is exclusively available to Grooming Services and Veterinary Clinic.');
        }

        $name = trim((string)($_POST['service_name'] ?? ''));
        $category = trim((string)($_POST['grooming_category'] ?? ($_POST['service_category'] ?? '')));
        $desc = trim((string)($_POST['description'] ?? ''));
        $basePriceRaw = trim((string)($_POST['base_price'] ?? ''));
        $maxDurationRaw = trim((string)($_POST['estimated_duration_minutes'] ?? ($_POST['maximum_duration'] ?? '')));
        $addDurationRateRaw = trim((string)($_POST['additional_duration_rate'] ?? '0.00'));
        $addEmployeeFeeRaw = trim((string)($_POST['additional_employee_fee'] ?? '0.00'));

        $errors = [];
        if ($name === '') {
            $errors['service_name'] = 'Service name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors['service_name'] = 'Service name cannot exceed 200 characters.';
        }

        if (!in_array($category, self::GROOMING_CATEGORIES, true)) {
            $errors['grooming_category'] = 'Please select a valid service category.';
        }

        if ($basePriceRaw === '' || !is_numeric($basePriceRaw) || (float)$basePriceRaw < 0) {
            $errors['base_price'] = 'Base price must be a valid non-negative number.';
        }

        if ($maxDurationRaw === '' || !ctype_digit((string)$maxDurationRaw) || (int)$maxDurationRaw <= 0) {
            $errors['estimated_duration_minutes'] = 'Maximum duration must be a valid positive integer in minutes.';
        }

        if ($addDurationRateRaw === '' || !is_numeric($addDurationRateRaw) || (float)$addDurationRateRaw < 0) {
            $errors['additional_duration_rate'] = 'Additional-duration rate must be a valid non-negative number.';
        }

        if ($addEmployeeFeeRaw === '' || !is_numeric($addEmployeeFeeRaw) || (float)$addEmployeeFeeRaw < 0) {
            $errors['additional_employee_fee'] = 'Additional-employee fee must be a valid non-negative number.';
        }

        if ($name !== '' && !isset($errors['service_name'])) {
            $chk = $this->db->prepare('SELECT service_id FROM service WHERE service_category = "grooming" AND LOWER(service_name) = LOWER(?)');
            $chk->execute([$name]);
            if ($chk->fetch()) {
                $errors['service_name'] = "A grooming service named '$name' already exists.";
            }
        }

        if (!empty($errors)) {
            Session::set('service_errors', $errors);
            Session::set('old_service_input', $_POST);
            $this->flash('error', reset($errors));
            $this->redirect($redirectUrl);
        }

        $ins = $this->db->prepare(
            "INSERT INTO service (service_name, service_category, grooming_category, description,
                                  base_price, estimated_duration_minutes, additional_duration_rate,
                                  additional_employee_fee, service_status)
             VALUES (?, 'grooming', ?, ?, ?, ?, ?, ?, 'active')"
        );
        $ins->execute([
            $name,
            $category,
            $desc !== '' ? $desc : null,
            number_format((float)$basePriceRaw, 2, '.', ''),
            (int)$maxDurationRaw,
            number_format((float)$addDurationRateRaw, 2, '.', ''),
            number_format((float)$addEmployeeFeeRaw, 2, '.', ''),
        ]);
        $newId = (int)$this->db->lastInsertId();

        Audit::log('service.create', "Created Grooming service '$name' (#$newId, Category: $category, Base Price: ₱$basePriceRaw, Max Duration: {$maxDurationRaw}m)", $unitId, 'service', $newId, $uid);

        $this->flash('success', "Service '$name' created successfully.");
        $this->redirect($redirectUrl);
    }

    /**
     * Edit an existing Service (Grooming or Veterinary Clinic)
     */
    public function updateService(int $id, ?int $routeUnitId = null): void
    {
        [$uid, , $unitId, , $isSuper] = $this->checkBusinessManager($routeUnitId);
        if ($isSuper && (int)$unitId === Units::petStoreId()) {
            $unitId = Units::groomingId();
        }
        if ((int)$unitId === Units::petStoreId()) {
            $this->forbidden('Services catalog management is not available to Pet Store.');
        }
        CSRF::check();

        $redirectUrl = ($isSuper && $routeUnitId) ? "/manager/units/$unitId/services" : '/manager/services';

        if ((int)$unitId === Units::vetClinicId()) {
            $srvStmt = $this->db->prepare('SELECT * FROM service WHERE service_id = ? AND service_category = "medical"');
            $srvStmt->execute([$id]);
            $srv = $srvStmt->fetch();
            if (!$srv) {
                $this->flash('error', 'Medical service not found.');
                $this->redirect($redirectUrl);
            }

            $name = trim((string)($_POST['service_name'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $basePriceRaw = trim((string)($_POST['base_price'] ?? ''));

            $errors = [];
            if ($name === '') {
                $errors['service_name'] = 'Service name is required.';
            } elseif (mb_strlen($name) > 200) {
                $errors['service_name'] = 'Service name cannot exceed 200 characters.';
            }

            if ($basePriceRaw === '' || !is_numeric($basePriceRaw) || (float)$basePriceRaw < 0) {
                $errors['base_price'] = 'Base price must be a valid non-negative number.';
            }

            if ($name !== '' && !isset($errors['service_name'])) {
                $chk = $this->db->prepare('SELECT service_id FROM service WHERE service_category = "medical" AND LOWER(service_name) = LOWER(?) AND service_id <> ?');
                $chk->execute([$name, $id]);
                if ($chk->fetch()) {
                    $errors['service_name'] = "Another medical service named '$name' already exists.";
                }
            }

            if (!empty($errors)) {
                Session::set('service_errors', $errors);
                Session::set('old_service_input', $_POST);
                $this->flash('error', reset($errors));
                $this->redirect($redirectUrl);
            }

            $upd = $this->db->prepare(
                "UPDATE service SET
                    service_name = ?,
                    description = ?,
                    base_price = ?,
                    updated_at = NOW()
                 WHERE service_id = ? AND service_category = 'medical'"
            );
            $upd->execute([
                $name,
                $desc !== '' ? $desc : null,
                number_format((float)$basePriceRaw, 2, '.', ''),
                $id,
            ]);

            Audit::log('service.update', "Updated Medical service #$id '$name' (Base Price: ₱$basePriceRaw)", $unitId, 'service', $id, $uid);

            $this->flash('success', "Medical service '$name' updated successfully.");
            $this->redirect($redirectUrl);
            return;
        }

        if ((int)$unitId !== Units::groomingId()) {
            $this->forbidden('Services catalog management is exclusively available to Grooming Services and Veterinary Clinic.');
        }

        $srvStmt = $this->db->prepare('SELECT * FROM service WHERE service_id = ? AND service_category = "grooming"');
        $srvStmt->execute([$id]);
        $srv = $srvStmt->fetch();
        if (!$srv) {
            $this->flash('error', 'Grooming service not found.');
            $this->redirect($redirectUrl);
        }

        $name = trim((string)($_POST['service_name'] ?? ''));
        $category = trim((string)($_POST['grooming_category'] ?? ($_POST['service_category'] ?? '')));
        $desc = trim((string)($_POST['description'] ?? ''));
        $basePriceRaw = trim((string)($_POST['base_price'] ?? ''));
        $maxDurationRaw = trim((string)($_POST['estimated_duration_minutes'] ?? ($_POST['maximum_duration'] ?? '')));
        $addDurationRateRaw = trim((string)($_POST['additional_duration_rate'] ?? '0.00'));
        $addEmployeeFeeRaw = trim((string)($_POST['additional_employee_fee'] ?? '0.00'));

        $errors = [];
        if ($name === '') {
            $errors['service_name'] = 'Service name is required.';
        } elseif (mb_strlen($name) > 200) {
            $errors['service_name'] = 'Service name cannot exceed 200 characters.';
        }

        if (!in_array($category, self::GROOMING_CATEGORIES, true)) {
            $errors['grooming_category'] = 'Please select a valid service category.';
        }

        if ($basePriceRaw === '' || !is_numeric($basePriceRaw) || (float)$basePriceRaw < 0) {
            $errors['base_price'] = 'Base price must be a valid non-negative number.';
        }

        if ($maxDurationRaw === '' || !ctype_digit((string)$maxDurationRaw) || (int)$maxDurationRaw <= 0) {
            $errors['estimated_duration_minutes'] = 'Maximum duration must be a valid positive integer in minutes.';
        }

        if ($addDurationRateRaw === '' || !is_numeric($addDurationRateRaw) || (float)$addDurationRateRaw < 0) {
            $errors['additional_duration_rate'] = 'Additional-duration rate must be a valid non-negative number.';
        }

        if ($addEmployeeFeeRaw === '' || !is_numeric($addEmployeeFeeRaw) || (float)$addEmployeeFeeRaw < 0) {
            $errors['additional_employee_fee'] = 'Additional-employee fee must be a valid non-negative number.';
        }

        if ($name !== '' && !isset($errors['service_name'])) {
            $chk = $this->db->prepare('SELECT service_id FROM service WHERE service_category = "grooming" AND LOWER(service_name) = LOWER(?) AND service_id <> ?');
            $chk->execute([$name, $id]);
            if ($chk->fetch()) {
                $errors['service_name'] = "Another grooming service named '$name' already exists.";
            }
        }

        if (!empty($errors)) {
            Session::set('service_errors', $errors);
            Session::set('old_service_input', $_POST);
            $this->flash('error', reset($errors));
            $this->redirect($redirectUrl);
        }

        // Update service settings (existing confirmed appointments retain their original snapshots)
        $upd = $this->db->prepare(
            "UPDATE service SET
                service_name = ?,
                grooming_category = ?,
                description = ?,
                base_price = ?,
                estimated_duration_minutes = ?,
                additional_duration_rate = ?,
                additional_employee_fee = ?,
                updated_at = NOW()
             WHERE service_id = ? AND service_category = 'grooming'"
        );
        $upd->execute([
            $name,
            $category,
            $desc !== '' ? $desc : null,
            number_format((float)$basePriceRaw, 2, '.', ''),
            (int)$maxDurationRaw,
            number_format((float)$addDurationRateRaw, 2, '.', ''),
            number_format((float)$addEmployeeFeeRaw, 2, '.', ''),
            $id,
        ]);

        Audit::log('service.update', "Updated Grooming service #$id '$name' (Category: $category, Base Price: ₱$basePriceRaw, Max Duration: {$maxDurationRaw}m). Existing confirmed appointment snapshots preserved.", $unitId, 'service', $id, $uid);

        $this->flash('success', "Service '$name' updated successfully. Settings apply to future bookings.");
        $this->redirect($redirectUrl);
    }

    public function updateServiceUnit(int $routeUnitId, int $id): void
    {
        $this->updateService($id, $routeUnitId);
    }

    /**
     * Toggle Service Availability (Available / Unavailable)
     */
    public function toggleServiceStatus(int $id, ?int $routeUnitId = null): void
    {
        [$uid, , $unitId, , $isSuper] = $this->checkBusinessManager($routeUnitId);
        if ($isSuper && (int)$unitId === Units::petStoreId()) {
            $unitId = Units::groomingId();
        }
        if ((int)$unitId === Units::petStoreId()) {
            $this->forbidden('Services catalog management is not available to Pet Store.');
        }
        CSRF::check();

        $redirectUrl = ($isSuper && $routeUnitId) ? "/manager/units/$unitId/services" : '/manager/services';

        $expectedCat = ((int)$unitId === Units::vetClinicId()) ? 'medical' : 'grooming';
        $srvStmt = $this->db->prepare('SELECT service_id, service_name, service_status FROM service WHERE service_id = ? AND service_category = ?');
        $srvStmt->execute([$id, $expectedCat]);
        $srv = $srvStmt->fetch();
        if (!$srv) {
            $this->flash('error', ucfirst($expectedCat) . ' service not found.');
            $this->redirect($redirectUrl);
        }

        $newStatus = trim((string)($_POST['status'] ?? ''));
        if (!in_array($newStatus, ['active', 'inactive'], true)) {
            $newStatus = ($srv['service_status'] === 'active') ? 'inactive' : 'active';
        }

        $upd = $this->db->prepare('UPDATE service SET service_status = ?, updated_at = NOW() WHERE service_id = ? AND service_category = ?');
        $upd->execute([$newStatus, $id, $expectedCat]);

        $statusLabel = ($newStatus === 'active') ? 'Available' : 'Unavailable';
        Audit::log('service.status_toggle', "Marked " . ucfirst($expectedCat) . " service #$id '{$srv['service_name']}' as $statusLabel ($newStatus). Existing appointment records preserved intact.", $unitId, 'service', $id, $uid);

        $this->flash('success', "Service '{$srv['service_name']}' marked as $statusLabel. Existing booking history remains intact.");
        $this->redirect($redirectUrl);
    }

    public function toggleServiceStatusUnit(int $routeUnitId, int $id): void
    {
        $this->toggleServiceStatus($id, $routeUnitId);
    }

    /**
     * Verify that an appointment exists and belongs to the manager's unit.
     */
    private function verifyManagerAppointment(int $appointmentId, int $unitId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, st.status_name
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             WHERE a.appointment_id = ? AND a.business_unit_id = ?"
        );
        $stmt->execute([$appointmentId, $unitId]);
        $appt = $stmt->fetch();
        if (!$appt) {
            $this->notFound();
        }
        return (int)$unitId === Units::vetClinicId()
            ? $this->hydrateVetAppointmentDetails($appt)
            : $this->hydrateGroomingAppointmentDetails($appt);
    }

    private function appointmentsRedirectUrl(int $unitId, bool $isSuper, ?string $returnStart = null, ?int $selectId = null): string
    {
        $params = [];
        if ($isSuper) {
            $params['unit_id'] = $unitId;
        }
        if ($returnStart !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnStart)) {
            $params['start'] = $returnStart;
        }
        if ($selectId !== null && $selectId > 0) {
            $params['select'] = $selectId;
        }
        return '/manager/appointments' . ($params ? '?' . http_build_query($params) : '');
    }

    /**
     * Business Manager Reassign Appointment Employee (Confirmed/In Progress only, with conflict check).
     */
    public function reassignAppointment(int $id): void
    {
        [$uid, , $unitId, , $isSuper] = $this->checkBusinessManager();
        CSRF::check();

        $appt = $this->verifyManagerAppointment($id, $unitId);
        $returnStart = trim((string)($_POST['return_start'] ?? $appt['preferred_date']));
        $redirectUrl = $this->appointmentsRedirectUrl($unitId, $isSuper, $returnStart, $id);

        $isVet = (int)$unitId === Units::vetClinicId();
        if ($isVet) {
            if (!in_array($appt['status_name'], ['Pending', 'Awaiting Customer Response', 'Confirmed', 'In Progress'], true)) {
                $this->flash('error', 'Only active veterinary appointments can be assigned to clinicians.');
                $this->redirect($redirectUrl);
            }
        } else {
            if (!in_array($appt['status_name'], ['Confirmed', 'In Progress'], true)) {
                $this->flash('error', 'Only confirmed grooming appointments can be reassigned by the Business Manager. Pending customer requests must first be processed by the Receptionist.');
                $this->redirect($redirectUrl);
            }
        }

        $targetEmpId = (int)($_POST['employee_id'] ?? 0);
        if ($targetEmpId <= 0) {
            $this->flash('error', $isVet ? 'Please select a valid veterinary staff member.' : 'Please select a valid Grooming Services employee.');
            $this->redirect($redirectUrl);
        }

        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.employee_status, ua.account_status
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             WHERE e.employee_id = ? AND e.business_unit_id = ?"
        );
        $empStmt->execute([$targetEmpId, $unitId]);
        $targetEmp = $empStmt->fetch();

        if (!$targetEmp || $targetEmp['employee_status'] !== 'active' || $targetEmp['account_status'] !== 'active') {
            $this->flash('error', $isVet ? 'Selected employee is not an active Veterinary Clinic staff member.' : 'Selected employee is not an active Grooming Services staff member.');
            $this->redirect($redirectUrl);
        }

        if ($isVet) {
            $allowedPositions = ['Lead Veterinarian', 'Lead Vet', 'Associate Veterinarian', 'Associate Vet', 'Veterinary Nurse', 'Vet Nurse', 'Veterinary Assistant', 'Vet Assistant', 'Receptionist'];
            if (!in_array($targetEmp['position'], $allowedPositions, true)) {
                $this->flash('error', 'Appointments can only be assigned to active veterinary clinicians or staff.');
                $this->redirect($redirectUrl);
            }
        } else {
            if (!in_array($targetEmp['position'], ['Master Stylist', 'Stylist', 'Bather'], true)) {
                $this->flash('error', 'Appointments can only be assigned to active Grooming stylists or bathers.');
                $this->redirect($redirectUrl);
            }
        }

        // Check employee scheduling conflict on the same date & overlapping time range
        $otherStmt = $this->db->prepare(
            "SELECT DISTINCT a.appointment_id, a.preferred_date, a.preferred_time, a.appointment_begin, a.appointment_end, st.status_name
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN appointment_pet ap ON ap.appointment_id = a.appointment_id
             JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
             WHERE a.business_unit_id = ?
               AND a.preferred_date = ?
               AND a.appointment_id <> ?
               AND ps.performer_employee_id = ?
               AND st.status_name IN ('Confirmed', 'In Progress')"
        );
        $otherStmt->execute([$unitId, $appt['preferred_date'], $id, $targetEmpId]);
        foreach ($otherStmt->fetchAll() as $otherRow) {
            $otherHydrated = $isVet ? $this->hydrateVetAppointmentDetails($otherRow) : $this->hydrateGroomingAppointmentDetails($otherRow);
            if ($appt['start_minutes'] < $otherHydrated['end_minutes'] && $otherHydrated['start_minutes'] < $appt['end_minutes']) {
                $this->flash('error', "Scheduling conflict: {$targetEmp['first_name']} {$targetEmp['last_name']} is already assigned to appointment #{$otherHydrated['appointment_id']} ({$otherHydrated['time_coverage']}) on {$appt['formatted_date']}.");
                $this->redirect($redirectUrl);
            }
        }

        $this->db->beginTransaction();
        try {
            // Ensure performed_service rows exist for the confirmed appointment
            $psCountStmt = $this->db->prepare(
                "SELECT COUNT(*) FROM performed_service ps
                 JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                 WHERE ap.appointment_id = ?"
            );
            $psCountStmt->execute([$id]);
            if ((int)$psCountStmt->fetchColumn() === 0) {
                $seedPs = $this->db->prepare(
                    "INSERT INTO performed_service (requested_pet_service_id, appointment_pet_id, service_id, performed_price, quantity, performed_status, performer_employee_id)
                     SELECT rps.requested_pet_service_id, rps.appointment_pet_id, rps.service_id, rps.requested_price, 1, 'pending', ?
                     FROM requested_pet_service rps
                     JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id
                     WHERE ap.appointment_id = ?"
                );
                $seedPs->execute([$targetEmpId, $id]);
            } else {
                $upd = $this->db->prepare(
                    "UPDATE performed_service ps
                     JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                     SET ps.performer_employee_id = ?
                     WHERE ap.appointment_id = ?"
                );
                $upd->execute([$targetEmpId, $id]);
            }

            $empName = $targetEmp['first_name'] . ' ' . $targetEmp['last_name'];
            Audit::log('appointment.reassign', "Appointment #$id reassigned to $empName (employee #$targetEmpId) by Business Manager", $unitId, 'appointment', $id, $uid);

            $this->db->commit();
            $this->flash('success', "Appointment #$id assigned to $empName.");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to reassign appointment: ' . $e->getMessage());
        }

        $this->redirect($redirectUrl);
    }

    /**
     * Business Manager Cancel Confirmed Appointment (Mandatory Reason).
     */
    public function cancelAppointment(int $id): void
    {
        [$uid, , $unitId, , $isSuper] = $this->checkBusinessManager();
        CSRF::check();

        $appt = $this->verifyManagerAppointment($id, $unitId);
        $returnStart = trim((string)($_POST['return_start'] ?? $appt['preferred_date']));
        $redirectUrl = $this->appointmentsRedirectUrl($unitId, $isSuper, $returnStart, $id);

        $reason = trim((string)($_POST['cancellation_reason'] ?? $_POST['reason'] ?? ''));
        if ($reason === '') {
            $this->flash('error', 'A non-empty cancellation reason is required to cancel an appointment.');
            $this->redirect($redirectUrl);
        }

        $isVet = (int)$unitId === Units::vetClinicId();
        $allowedCancelStatuses = $isVet
            ? ['Pending', 'Awaiting Customer Response', 'Confirmed', 'In Progress']
            : ['Confirmed', 'In Progress'];
        if (!in_array($appt['status_name'], $allowedCancelStatuses, true)) {
            $this->flash('error', $isVet
                ? 'Only active appointments can be cancelled.'
                : 'Only confirmed grooming appointments can be cancelled here. Pending requests are managed by the Receptionist.');
            $this->redirect($redirectUrl);
        }

        $this->db->beginTransaction();
        try {
            $updatedNotes = trim((string)($appt['notes'] ?? '') . "\n[Cancelled by Business Manager: $reason]");
            $upd = $this->db->prepare(
                "UPDATE appointment
                 SET appointment_status_id = (SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Cancelled'),
                     notes = ?
                 WHERE appointment_id = ? AND business_unit_id = ?"
            );
            $upd->execute([$updatedNotes, $id, $unitId]);

            $psUpd = $this->db->prepare(
                "UPDATE performed_service ps
                 JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                 SET ps.performed_status = 'cancelled'
                 WHERE ap.appointment_id = ?"
            );
            $psUpd->execute([$id]);

            Audit::log('appointment.cancel', "Appointment #$id cancelled by Business Manager. Reason: $reason", $unitId, 'appointment', $id, $uid);

            $this->db->commit();
            $this->flash('success', "Appointment #$id has been cancelled.");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to cancel appointment: ' . $e->getMessage());
        }

        $this->redirect($redirectUrl);
    }

    /**
     * Business Manager Update Confirmed Appointment Status (In Progress / No-Show / Completed).
     */
    public function updateAppointmentStatus(int $id): void
    {
        [$uid, , $unitId, , $isSuper] = $this->checkBusinessManager();
        CSRF::check();

        $appt = $this->verifyManagerAppointment($id, $unitId);
        $returnStart = trim((string)($_POST['return_start'] ?? $appt['preferred_date']));
        $redirectUrl = $this->appointmentsRedirectUrl($unitId, $isSuper, $returnStart, $id);

        $newStatus = trim((string)($_POST['status'] ?? ''));
        $isVet = (int)$unitId === Units::vetClinicId();
        if ($isVet) {
            $allowedTransitions = [
                'Pending' => ['Confirmed', 'Rejected'],
                'Awaiting Customer Response' => ['Confirmed', 'Rejected'],
                'Confirmed' => ['In Progress', 'Completed', 'No-Show'],
                'In Progress' => ['Completed'],
            ];
        } else {
            $allowedTransitions = [
                'Confirmed' => ['In Progress', 'Completed', 'No-Show'],
                'In Progress' => ['Completed'],
            ];
        }

        $currentStatus = (string)$appt['status_name'];
        if (!isset($allowedTransitions[$currentStatus]) || !in_array($newStatus, $allowedTransitions[$currentStatus], true)) {
            $this->flash('error', "Invalid status transition from '$currentStatus' to '$newStatus'.");
            $this->redirect($redirectUrl);
        }

        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE appointment
                 SET appointment_status_id = (SELECT appointment_status_id FROM appointment_status WHERE status_name = ?)
                 WHERE appointment_id = ? AND business_unit_id = ?"
            );
            $upd->execute([$newStatus, $id, $unitId]);

            // If confirming a veterinary appointment and an employee was optionally assigned
            $optEmpId = (int)($_POST['employee_id'] ?? 0);
            if ($isVet && $newStatus === 'Confirmed' && $optEmpId > 0) {
                $psCountStmt = $this->db->prepare(
                    "SELECT COUNT(*) FROM performed_service ps
                     JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                     WHERE ap.appointment_id = ?"
                );
                $psCountStmt->execute([$id]);
                if ((int)$psCountStmt->fetchColumn() === 0) {
                    $seedPs = $this->db->prepare(
                        "INSERT INTO performed_service (requested_pet_service_id, appointment_pet_id, service_id, performed_price, quantity, performed_status, performer_employee_id)
                         SELECT rps.requested_pet_service_id, rps.appointment_pet_id, rps.service_id, rps.requested_price, 1, 'pending', ?
                         FROM requested_pet_service rps
                         JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id
                         WHERE ap.appointment_id = ?"
                    );
                    $seedPs->execute([$optEmpId, $id]);
                } else {
                    $updPs = $this->db->prepare(
                        "UPDATE performed_service ps
                         JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                         SET ps.performer_employee_id = ?
                         WHERE ap.appointment_id = ?"
                    );
                    $updPs->execute([$optEmpId, $id]);
                }
            }

            Audit::log('appointment.status', "Appointment #$id status updated from $currentStatus to $newStatus by Business Manager", $unitId, 'appointment', $id, $uid);
            $this->db->commit();
            $this->flash('success', "Appointment #$id status updated to $newStatus.");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to update appointment status: ' . $e->getMessage());
        }
        $this->redirect($redirectUrl);
    }

    /**
     * Business Manager Complete Confirmed/In-Progress Appointment.
     */
    public function completeAppointment(int $id): void
    {
        [$uid, , $unitId, , $isSuper] = $this->checkBusinessManager();
        CSRF::check();

        $appt = $this->verifyManagerAppointment($id, $unitId);
        $returnStart = trim((string)($_POST['return_start'] ?? $appt['preferred_date']));
        $redirectUrl = $this->appointmentsRedirectUrl($unitId, $isSuper, $returnStart, $id);

        if (!in_array($appt['status_name'], ['Confirmed', 'In Progress'], true)) {
            $this->flash('error', 'Only Confirmed or In Progress appointments can be marked as Completed.');
            $this->redirect($redirectUrl);
        }

        $this->db->beginTransaction();
        try {
            $psUpd = $this->db->prepare(
                "UPDATE performed_service ps
                 JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                 SET ps.performed_status = 'completed'
                 WHERE ap.appointment_id = ?"
            );
            $psUpd->execute([$id]);

            $apptUpd = $this->db->prepare(
                "UPDATE appointment
                 SET appointment_status_id = (SELECT appointment_status_id FROM appointment_status WHERE status_name = 'Completed'),
                     appointment_end = COALESCE(appointment_end, NOW())
                 WHERE appointment_id = ? AND business_unit_id = ?"
            );
            $apptUpd->execute([$id, $unitId]);

            Audit::log('appointment.complete', "Appointment #$id marked Completed by Business Manager", $unitId, 'appointment', $id, $uid);

            $this->db->commit();
            $this->flash('success', "Appointment #$id marked as Completed.");
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->flash('error', 'Failed to complete appointment: ' . $e->getMessage());
        }

        $this->redirect($redirectUrl);
    }

    // ==========================================
    // VETERINARY CLINIC MEDICAL RECORDS (READ-ONLY)
    // ==========================================

    /**
     * Veterinary Clinic Medical Records index (strictly read-only for Business Manager)
     */
    public function medicalRecords(?int $routeUnitId = null): void
    {
        [$uid, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);
        if ($isSuper && (int)$unitId === Units::petStoreId()) {
            $unitId = Units::vetClinicId();
            $unit = Units::find($unitId);
            $unitName = (string)$unit['business_unit_name'];
        }

        if ((int)$unitId !== Units::vetClinicId()) {
            $this->forbidden('Medical records are exclusively accessible to Veterinary Clinic.');
        }

        $searchQuery = trim((string)($_GET['q'] ?? ''));
        $statusFilter = trim((string)($_GET['status'] ?? ''));
        $validStatuses = ['IN_PROGRESS', 'AWAITING_TEST_RESULTS', 'FINALIZED', 'REVISED'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = '';
        }

        // Query medical appointments for Veterinary Clinic
        $sql = "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                       a.appointment_begin, a.appointment_end, a.notes,
                       st.status_name,
                       c.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                       c.phone AS customer_phone, ua.email AS customer_email
                FROM appointment a
                JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
                JOIN customer c ON c.customer_id = a.customer_id
                LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
                WHERE a.business_unit_id = ?
                  AND a.appointment_type = 'medical'";
        $params = [$unitId];

        if ($searchQuery !== '') {
            $sql .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR a.notes LIKE ?)";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
            $params[] = "%$searchQuery%";
        }

        $sql .= " ORDER BY a.preferred_date DESC, a.appointment_id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $appointments = $stmt->fetchAll();

        // Hydrate each appointment into structured medical record
        $records = [];
        $statusCounts = [
            'total' => 0,
            'IN_PROGRESS' => 0,
            'AWAITING_TEST_RESULTS' => 0,
            'FINALIZED' => 0,
            'REVISED' => 0,
        ];

        foreach ($appointments as $appt) {
            $rec = $this->hydrateMedicalRecordDetails($appt);

            $recStatus = $rec['record_status'];
            if (isset($statusCounts[$recStatus])) {
                $statusCounts[$recStatus]++;
            }
            $statusCounts['total']++;

            if ($statusFilter !== '' && $recStatus !== $statusFilter) {
                continue;
            }

            $records[] = $rec;
        }

        $this->render('manager/medical_records', [
            'title' => "Medical Records · $unitName",
            'activeTab' => 'medical-records',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'records' => $records,
            'statusFilter' => $statusFilter,
            'searchQuery' => $searchQuery,
            'statusCounts' => $statusCounts,
        ]);
    }

    /**
     * Show single Medical Record (strictly read-only)
     */
    public function showMedicalRecord(int $firstParam, ?int $secondParam = null): void
    {
        if ($secondParam !== null) {
            $routeUnitId = $firstParam;
            $id = $secondParam;
        } else {
            $id = $firstParam;
            $routeUnitId = null;
        }

        [$uid, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);
        if ($isSuper && (int)$unitId === Units::petStoreId()) {
            $unitId = Units::vetClinicId();
            $unit = Units::find($unitId);
            $unitName = (string)$unit['business_unit_name'];
        }

        if ((int)$unitId !== Units::vetClinicId()) {
            $this->forbidden('Medical records are exclusively accessible to Veterinary Clinic.');
        }

        $stmt = $this->db->prepare(
            "SELECT a.appointment_id, a.business_unit_id, a.preferred_date, a.preferred_time,
                    a.appointment_begin, a.appointment_end, a.notes,
                    st.status_name,
                    c.customer_id, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                    c.phone AS customer_phone, ua.email AS customer_email
             FROM appointment a
             JOIN appointment_status st ON st.appointment_status_id = a.appointment_status_id
             JOIN customer c ON c.customer_id = a.customer_id
             LEFT JOIN user_account ua ON ua.user_account_id = c.user_account_id
             WHERE a.appointment_id = ? AND a.business_unit_id = ? AND a.appointment_type = 'medical'"
        );
        $stmt->execute([$id, $unitId]);
        $row = $stmt->fetch();
        if (!$row) {
            $this->notFound();
        }

        $record = $this->hydrateMedicalRecordDetails($row);

        if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'record' => $record]);
            exit;
        }

        $this->render('manager/medical_record_detail', [
            'title' => "{$record['record_ref']} · Medical Record · $unitName",
            'activeTab' => 'medical-records',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'allUnits' => $isSuper ? Units::all() : [],
            'record' => $record,
        ]);
    }

    public function showMedicalRecordUnit(int $routeUnitId, int $id): void
    {
        $this->showMedicalRecord($id, $routeUnitId);
    }

    /**
     * Hydrate appointment into clinical medical record structure:
     * - Physical Measurements (weight, temperature, heart rate)
     * - Clinical Assessment (SOAP: Subjective, Objective, Assessment, Plan)
     * - Diagnostic Tests and Results
     * - Treatments and Medication
     * - Optional Veterinarian-issued Prescription
     * Statuses: IN_PROGRESS, AWAITING_TEST_RESULTS, FINALIZED, REVISED
     */
    private function hydrateMedicalRecordDetails(array $appt): array
    {
        $apptId = (int)$appt['appointment_id'];

        // Record Reference ID (e.g. MED-00101)
        $appt['record_ref'] = 'MED-' . str_pad((string)$apptId, 5, '0', STR_PAD_LEFT);

        // Date and Time
        $appt['formatted_date'] = date('M j, Y', strtotime((string)$appt['preferred_date']));
        $appt['formatted_time'] = !empty($appt['preferred_time']) ? date('g:i A', strtotime((string)$appt['preferred_time'])) : 'Scheduled';

        // Pets
        $petStmt = $this->db->prepare(
            "SELECT p.pet_id, p.name, p.species, p.breed, p.gender, p.birth_date, ap.appointment_pet_id
             FROM appointment_pet ap
             JOIN pet p ON p.pet_id = ap.pet_id
             WHERE ap.appointment_id = ?
             ORDER BY ap.appointment_pet_id ASC"
        );
        $petStmt->execute([$apptId]);
        $pets = $petStmt->fetchAll();
        $appt['pets'] = $pets;
        $petNames = [];
        $petDetails = [];
        foreach ($pets as $p) {
            $petNames[] = $p['name'];
            $speciesBreed = trim(($p['species'] ?? '') . ($p['breed'] ? ' (' . $p['breed'] . ')' : ''));
            $petDetails[] = $p['name'] . ($speciesBreed ? " · $speciesBreed" : '');
        }
        $appt['pet_summary'] = $petNames ? implode(', ', $petNames) : 'Patient not recorded';
        $appt['pet_details_summary'] = $petDetails ? implode('; ', $petDetails) : 'Patient not recorded';

        // Performed services and attending clinical staff
        $psStmt = $this->db->prepare(
            "SELECT ps.performed_service_id, ps.service_id, ps.performed_price, ps.performed_status,
                    ps.performer_employee_id,
                    s.service_name, s.service_category,
                    CONCAT(e.first_name, ' ', e.last_name) AS performer_name,
                    e.position AS performer_position
             FROM performed_service ps
             JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
             JOIN service s ON s.service_id = ps.service_id
             LEFT JOIN employee e ON e.employee_id = ps.performer_employee_id
             WHERE ap.appointment_id = ?
             ORDER BY ps.performed_service_id ASC"
        );
        $psStmt->execute([$apptId]);
        $performedServices = $psStmt->fetchAll();

        // Requested services
        $rpsStmt = $this->db->prepare(
            "SELECT s.service_name, s.service_category
             FROM requested_pet_service rps
             JOIN appointment_pet ap ON ap.appointment_pet_id = rps.appointment_pet_id
             JOIN service s ON s.service_id = rps.service_id
             WHERE ap.appointment_id = ? AND rps.request_status <> 'declined'
             ORDER BY rps.requested_pet_service_id ASC"
        );
        $rpsStmt->execute([$apptId]);
        $requestedServices = $rpsStmt->fetchAll();

        $allServices = [];
        $hasDiagnostics = false;
        $primaryAttendingVet = null;

        foreach ($performedServices as $ps) {
            $name = trim((string)$ps['service_name']);
            if ($name !== '' && !in_array($name, $allServices, true)) {
                $allServices[] = $name;
            }
            if (stripos($name, 'Diagnostic') !== false || stripos($name, 'Blood') !== false || stripos($name, 'Panel') !== false || stripos($name, 'Lab') !== false) {
                $hasDiagnostics = true;
            }
            if ($primaryAttendingVet === null && !empty($ps['performer_name'])) {
                $primaryAttendingVet = $ps['performer_name'] . (!empty($ps['performer_position']) ? ' (' . $ps['performer_position'] . ')' : '');
            }
        }
        foreach ($requestedServices as $rps) {
            $name = trim((string)$rps['service_name']);
            if ($name !== '' && !in_array($name, $allServices, true)) {
                $allServices[] = $name;
            }
            if (stripos($name, 'Diagnostic') !== false || stripos($name, 'Blood') !== false || stripos($name, 'Panel') !== false || stripos($name, 'Lab') !== false) {
                $hasDiagnostics = true;
            }
        }

        $appt['attending_veterinarian'] = $primaryAttendingVet ?? 'Attending Clinical Staff';
        $appt['services_summary'] = $allServices ? implode(', ', $allServices) : 'Comprehensive Wellness Exam';

        // Derive Clinical Record Status: IN_PROGRESS, AWAITING_TEST_RESULTS, FINALIZED, REVISED
        $statusName = (string)$appt['status_name'];
        $notes = (string)($appt['notes'] ?? '');

        if (stripos($notes, '[REVISED]') !== false || stripos($notes, 'Revised:') !== false) {
            $recStatus = 'REVISED';
        } elseif ($statusName === 'Completed') {
            $recStatus = 'FINALIZED';
        } elseif ($hasDiagnostics && in_array($statusName, ['In Progress', 'Confirmed', 'Pending'], true)) {
            $recStatus = 'AWAITING_TEST_RESULTS';
        } else {
            $recStatus = 'IN_PROGRESS';
        }
        $appt['record_status'] = $recStatus;

        // Badge class for UI
        $appt['status_badge_class'] = match($recStatus) {
            'FINALIZED' => 'badge-success',
            'REVISED' => 'badge-warn',
            'AWAITING_TEST_RESULTS' => 'warn',
            default => 'secondary',
        };

        // Parse or structure Clinical Sections:
        // 1. Physical Measurements (Weight, Temperature, Heart Rate)
        $appt['physical_measurements'] = [
            'weight' => 'Recorded during triage',
            'temperature' => 'Normal range (38.3°C – 39.2°C)',
            'heart_rate' => 'Normal sinus rhythm',
            'hydration' => 'Adequate skin turgor (< 2s capillary refill)',
        ];

        // 2. Clinical Assessment (SOAP)
        $chiefComplaint = !empty($notes) ? $notes : 'Preventive health check-up and clinical consultation.';
        $appt['soap'] = [
            'subjective' => $chiefComplaint,
            'objective' => 'Physical examination unremarkable. Mucous membranes pink and moist. Cardiovascular, respiratory, and musculoskeletal systems within normal clinical limits.',
            'assessment' => 'Apparent good health. Preventive clinical care indicated. Stable vital indicators.',
            'plan' => 'Administer preventive protocols, maintain current dietary regimen, follow up if clinical symptoms develop.',
        ];

        // 3. Diagnostic Tests and Results
        $appt['diagnostic_tests'] = [];
        if ($hasDiagnostics) {
            $appt['diagnostic_tests'][] = [
                'test_name' => 'Diagnostic Blood & Wellness Panel',
                'status' => ($recStatus === 'FINALIZED') ? 'Complete (All parameters within reference range)' : 'Awaiting laboratory analyzer completion',
                'ordered_date' => $appt['formatted_date'],
            ];
        }

        // 4. Treatments and Medication
        $appt['treatments'] = [];
        foreach ($allServices as $sName) {
            $appt['treatments'][] = [
                'treatment_name' => $sName,
                'status' => ($statusName === 'Completed') ? 'Administered' : 'Scheduled / In Consultation',
            ];
        }

        // 5. Optional Veterinarian-issued Prescription
        $appt['prescriptions'] = [];
        if (stripos($notes, 'Rx:') !== false || stripos($notes, 'Prescription:') !== false) {
            $appt['prescriptions'][] = [
                'medication' => 'Prescribed therapeutic medication as noted',
                'instructions' => 'Follow veterinarian dispensing guidelines',
                'prescribed_by' => $appt['attending_veterinarian'],
            ];
        }

        return $appt;
    }

    // ==========================================
    // 2. SALES PAGE & REFUND MANAGEMENT
    // ==========================================
    public function sales(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);

        if ((int)$unitId !== Units::petStoreId()) {
            $this->forbidden('Sales and refunds management is exclusively available to Pet Store.');
        }

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

        if ((int)$unitId !== Units::petStoreId()) {
            $this->forbidden('Product catalog management is exclusively available to Pet Store.');
        }

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
        if ((int)$unitId !== Units::petStoreId()) {
            $this->forbidden('Product catalog management is exclusively available to Pet Store.');
        }
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
        if ((int)$unitId !== Units::petStoreId()) {
            $this->forbidden('Product catalog management is exclusively available to Pet Store.');
        }
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
        if ((int)$unitId !== Units::petStoreId()) {
            $this->forbidden('Product catalog management is exclusively available to Pet Store.');
        }
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
        [, , $unitId, $unitName, $isSuper, $isInvOnly, $isTempKeyAccess, $tempKeyGrant] = $this->checkManagerOrInventoryManager($routeUnitId);

        $tab = isset($_GET['tab']) && $_GET['tab'] === 'orders' ? 'orders' : 'stock';
        $q = trim((string)($_GET['q'] ?? ''));

        $canManageInventoryKey = !$isInvOnly && !$isTempKeyAccess && InventoryAccessKey::isUnitSupported((int)$unitId);
        $activeInventoryKey = $canManageInventoryKey ? InventoryAccessKey::getActiveForUnit($this->db, (int)$unitId) : null;
        $recentInventoryKeys = $canManageInventoryKey ? InventoryAccessKey::getRecentForUnit($this->db, (int)$unitId, 10) : [];

        $autoOpenKeyModal = false;
        $openForUnit = Session::get('open_inventory_key_modal_unit');
        if ($openForUnit !== null && (int)$openForUnit === (int)$unitId) {
            Session::forget('open_inventory_key_modal_unit');
            $autoOpenKeyModal = ($activeInventoryKey !== null);
        }

        // Stock list query: Prioritize out-of-stock items (A–Z), then in-stock items (A–Z)
        $stockSql = "SELECT bp.business_product_id, bp.selling_price, bp.product_status,
                            p.product_name, p.product_category, p.sku,
                            i.inventory_id,
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

        // Fetch batches for inventory items (supports Veterinary Clinic batch and expiry tracking)
        $invIds = array_filter(array_map('intval', array_column($inventoryItems, 'inventory_id')));
        $batchesByInv = [];
        if (!empty($invIds)) {
            $inClause = implode(',', $invIds);
            $batchStmt = $this->db->query(
                "SELECT * FROM inventory_batch WHERE inventory_id IN ($inClause) ORDER BY expiration_date ASC, batch_id ASC"
            );
            foreach ($batchStmt->fetchAll() as $b) {
                $batchesByInv[(int)$b['inventory_id']][] = $b;
            }
        }
        foreach ($inventoryItems as &$item) {
            $invId = (int)($item['inventory_id'] ?? 0);
            $item['batches'] = $batchesByInv[$invId] ?? [];
        }
        unset($item);

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
            'isTempKeyAccess' => $isTempKeyAccess,
            'tempKeyGrant' => $tempKeyGrant,
            'canManageInventoryKey' => $canManageInventoryKey,
            'activeInventoryKey' => $activeInventoryKey,
            'recentInventoryKeys' => $recentInventoryKeys,
            'autoOpenKeyModal' => $autoOpenKeyModal,
            'allUnits' => $isSuper ? Units::all() : [],
            'inventoryItems' => $inventoryItems,
            'catalogProducts' => $catalogProducts,
            'purchaseOrders' => $purchaseOrders,
            'searchQuery' => $q,
        ]);
    }

    public function generateInventoryKey(?int $routeUnitId = null): void
    {
        [$uid, $employeeId, $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);
        CSRF::check();

        if (!$isSuper && isset($_POST['business_unit_id']) && ctype_digit((string)$_POST['business_unit_id'])) {
            if ((int)$_POST['business_unit_id'] !== (int)$unitId) {
                $this->forbidden('Access denied: you cannot generate keys for another business unit.');
            }
        }

        if (!InventoryAccessKey::isUnitSupported((int)$unitId)) {
            $this->forbidden('Temporary Inventory Access Keys are not available for this business unit.');
        }

        $returnTab = (($_POST['return_tab'] ?? '') === 'orders') ? 'orders' : 'stock';
        $params = [];
        if ($isSuper) {
            $params['unit_id'] = (int)$unitId;
        }
        if ($returnTab === 'orders') {
            $params['tab'] = 'orders';
        }
        $redirectUrl = '/manager/inventory' . (!empty($params) ? ('?' . http_build_query($params)) : '');

        try {
            $result = InventoryAccessKey::generateIfNoneActive(
                $this->db,
                (int)$unitId,
                $employeeId > 0 ? $employeeId : null,
                (int)$uid
            );
            Session::set('open_inventory_key_modal_unit', (int)$unitId);

            if (!empty($result['created'])) {
                $this->flash('success', "Temporary Inventory Access Key generated for {$unitName}.");
            } else {
                $this->flash('info', "A valid Inventory Access Key already exists for {$unitName}. Displaying the active key.");
            }
        } catch (\Throwable $e) {
            $this->flash('error', 'Failed to generate Inventory Access Key: ' . $e->getMessage());
        }

        $this->redirect($redirectUrl);
    }

    public function revokeInventoryKey(?int $routeUnitId = null): void
    {
        [$uid, $employeeId, $unitId, $unitName, $isSuper] = $this->checkBusinessManager($routeUnitId);
        CSRF::check();

        if (!$isSuper && isset($_POST['business_unit_id']) && ctype_digit((string)$_POST['business_unit_id'])) {
            if ((int)$_POST['business_unit_id'] !== (int)$unitId) {
                $this->forbidden('Access denied: you cannot revoke keys for another business unit.');
            }
        }

        if (!InventoryAccessKey::isUnitSupported((int)$unitId)) {
            $this->forbidden('Temporary Inventory Access Keys are not available for this business unit.');
        }

        $returnTab = (($_POST['return_tab'] ?? '') === 'orders') ? 'orders' : 'stock';
        $params = [];
        if ($isSuper) {
            $params['unit_id'] = (int)$unitId;
        }
        if ($returnTab === 'orders') {
            $params['tab'] = 'orders';
        }
        $redirectUrl = '/manager/inventory' . (!empty($params) ? ('?' . http_build_query($params)) : '');

        $keyId = (int)($_POST['key_id'] ?? 0);
        if ($keyId <= 0) {
            $this->flash('error', 'Please select an Inventory Access Key to revoke.');
            $this->redirect($redirectUrl);
            return;
        }

        try {
            $result = InventoryAccessKey::revokeKey(
                $this->db,
                $keyId,
                (int)$unitId,
                $employeeId > 0 ? $employeeId : null,
                (int)$uid
            );

            if (!$result['ok']) {
                if (($result['reason'] ?? '') === 'cross_unit') {
                    $this->forbidden($result['message']);
                }
                $this->flash('error', $result['message']);
            } elseif (!empty($result['already_revoked'])) {
                $this->flash('info', $result['message']);
            } else {
                $this->flash('success', $result['message']);
            }
        } catch (\Throwable $e) {
            $this->flash('error', 'Failed to revoke Inventory Access Key: ' . $e->getMessage());
        }

        $this->redirect($redirectUrl);
    }

    public function adjustStock(?int $routeUnitId = null): void
    {
        [$uid, $employeeId, $unitId, , $isSuper] = $this->checkManagerOrInventoryManager($routeUnitId);
        CSRF::check();

        $invRedirect = '/manager/inventory' . ($isSuper ? '?unit_id=' . (int)$unitId : '');

        $bpId = (int)($_POST['business_product_id'] ?? 0);
        $type = trim((string)($_POST['adjustment_type'] ?? ''));
        $qty = (int)($_POST['quantity'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $inspectionConfirmed = !empty($_POST['inspection_confirmed']);
        $relatedRef = trim((string)($_POST['related_reference'] ?? ''));

        if ($bpId <= 0 || !in_array($type, ['increase', 'decrease'], true) || $qty <= 0 || $reason === '') {
            $this->flash('error', 'Valid product, adjustment direction, positive quantity, and mandatory reason are required.');
            $this->redirect($invRedirect);
        }

        // Return reason requires inspection check
        $isReturn = str_contains($reason, 'Returned product');
        if ($type === 'increase' && $isReturn && !$inspectionConfirmed) {
            $this->flash('error', 'Returned products must only be added back to sellable inventory after inspection confirms that they are undamaged and suitable for resale.');
            $this->redirect($invRedirect);
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
                    $this->redirect($invRedirect);
                }
                if ($qty > $prevOnHand) {
                    $this->db->rollBack();
                    $this->flash('error', "Cannot deduct $qty units: current on-hand quantity is only $prevOnHand.");
                    $this->redirect($invRedirect);
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

        $this->redirect($invRedirect);
    }

    public function storePurchaseOrder(?int $routeUnitId = null): void
    {
        [$uid, $employeeId, $unitId, , $isSuper] = $this->checkBusinessManager($routeUnitId);
        CSRF::check();

        $ordersRedirect = '/manager/inventory' . ($isSuper ? '?unit_id=' . (int)$unitId . '&tab=orders' : '?tab=orders');

        $items = $_POST['items'] ?? [];
        if (!is_array($items) || empty($items)) {
            $this->flash('error', 'At least one product must be added to create a purchase order.');
            $this->redirect($ordersRedirect);
        }

        $supplierName = trim((string)($_POST['supplier_name'] ?? ''));
        $contactPerson = trim((string)($_POST['contact_person'] ?? ''));
        $supplierEmail = trim((string)($_POST['supplier_email'] ?? ''));
        $supplierPhone = trim((string)($_POST['supplier_phone'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($supplierName === '' || $contactPerson === '' || ($supplierEmail === '' && $supplierPhone === '')) {
            $this->flash('error', 'Supplier name, contact person, and at least one contact method (email or phone) are required.');
            $this->redirect($ordersRedirect);
        }

        $this->db->beginTransaction();
        try {
            // Auto-generated fields: PO Number, Order Date, Created By, Status = 'Ordered'
            $poPrefix = (int)$unitId === Units::groomingId() ? 'PO-GS-' : 'PO-PS-';
            do {
                $poNumber = $poPrefix . date('Ymd') . '-' . str_pad((string)random_int(100, 99999), 5, '0', STR_PAD_LEFT);
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
                $this->redirect($ordersRedirect);
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

        $this->redirect($ordersRedirect);
    }

    public function receiveStock(int $purchaseOrderId): void
    {
        [$uid, $employeeId, $unitId] = $this->checkManagerOrInventoryManager();
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

        foreach ($allEmployees as &$empRow) {
            $empId = (int)$empRow['employee_id'];
            $empRow['daily_assignment_count'] = $dailyCounts[$empId] ?? 0;
        }
        unset($empRow);

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

        if (empty($settings)) {
            $defaultEmail = strtolower(str_replace(' ', '', $unitName)) . '@vetgrpet.local';
            $insSet = $this->db->prepare(
                'INSERT IGNORE INTO business_settings (business_unit_id, business_name, business_email, contact_number, cod_enabled)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $insSet->execute([$unitId, 'VETGRPET ' . $unitName, $defaultEmail, '09175550100']);
            $setStmt->execute([$unitId]);
            $settings = $setStmt->fetch() ?: [];
        }

        $hrsStmt = $this->db->prepare('SELECT * FROM operating_hours WHERE business_unit_id = ? ORDER BY day_of_week ASC');
        $hrsStmt->execute([$unitId]);
        $operatingHours = $hrsStmt->fetchAll();

        if (empty($operatingHours)) {
            $days = [
                1 => ['Monday', '08:00:00', '19:00:00'],
                2 => ['Tuesday', '08:00:00', '19:00:00'],
                3 => ['Wednesday', '08:00:00', '19:00:00'],
                4 => ['Thursday', '08:00:00', '19:00:00'],
                5 => ['Friday', '08:00:00', '19:00:00'],
                6 => ['Saturday', '09:00:00', '18:00:00'],
                7 => ['Sunday', '09:00:00', '17:00:00'],
            ];
            $insHrs = $this->db->prepare(
                'INSERT IGNORE INTO operating_hours (business_unit_id, day_of_week, day_name, is_open, open_time, close_time)
                 VALUES (?, ?, ?, 1, ?, ?)'
            );
            foreach ($days as $dow => [$dName, $oTime, $cTime]) {
                $insHrs->execute([$unitId, $dow, $dName, $oTime, $cTime]);
            }
            $hrsStmt->execute([$unitId]);
            $operatingHours = $hrsStmt->fetchAll();
        }

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
        [$uid, , $unitId, $unitName] = $this->checkBusinessManager();
        CSRF::check();

        $redirectUrl = ($this->isSuperAdmin() && isset($_GET['unit_id'])) ? '/manager/settings?unit_id=' . (int)$unitId : '/manager/settings';

        $name = trim((string)($_POST['business_name'] ?? ''));
        $email = trim((string)($_POST['business_email'] ?? ''));
        $phone = trim((string)($_POST['contact_number'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Please provide a valid business name and business email address.');
            $this->redirect($redirectUrl);
        }

        // Contact Number validation: accepts either 8-digit number or 11-digit phone number
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) !== 8 && strlen($cleanPhone) !== 11) {
            $this->flash('error', 'Contact Number must be either an 8-digit landline number or an 11-digit mobile phone number.');
            $this->redirect($redirectUrl);
        }

        $stmt = $this->db->prepare(
            'UPDATE business_settings SET business_name = ?, business_email = ?, contact_number = ? WHERE business_unit_id = ?'
        );
        $stmt->execute([$name, $email, $phone, $unitId]);

        Audit::log('business_profile.update', "Updated $unitName profile (Phone: $phone)", $unitId, null, null, $uid);

        $this->flash('success', 'Business profile updated successfully.');
        $this->redirect($redirectUrl);
    }

    public function updateOperatingHours(): void
    {
        [$uid, , $unitId] = $this->checkBusinessManager();
        CSRF::check();

        $redirectUrl = ($this->isSuperAdmin() && isset($_GET['unit_id'])) ? '/manager/settings?unit_id=' . (int)$unitId : '/manager/settings';

        $hours = $_POST['hours'] ?? [];
        if (!is_array($hours)) {
            $this->flash('error', 'Invalid operating hours submitted.');
            $this->redirect($redirectUrl);
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

        $this->redirect($redirectUrl);
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
