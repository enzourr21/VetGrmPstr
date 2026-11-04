<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Models\Units;

final class ManagerController extends Controller
{
    public function dashboard(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->requireBusinessManager($routeUnitId);

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
}

