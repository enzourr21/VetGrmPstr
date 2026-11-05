 <?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\AdminAccountService;
use App\Models\Audit;
use App\Models\Units;

final class SuperAdminController extends Controller
{
    private function unitOverviews(): array
    {
        return $this->db->query(
            "SELECT bu.business_unit_id, bu.business_unit_name, bu.business_unit_status, bu.created_at,
                    (SELECT COUNT(*) FROM branch b WHERE b.business_unit_id = bu.business_unit_id AND b.branch_status = 'active') AS branch_count,
                    (SELECT COUNT(DISTINCT e.employee_id)
                     FROM employee e
                     LEFT JOIN account_role ar ON ar.user_account_id = e.user_account_id
                     WHERE (e.business_unit_id = bu.business_unit_id OR ar.business_unit_id = bu.business_unit_id)
                       AND e.employee_status = 'active'
                       AND e.position <> 'Super Administrator') AS employee_count,
                    (SELECT COUNT(DISTINCT ar.user_account_id)
                     FROM account_role ar
                     JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'SYSTEM_ADMINISTRATOR'
                     JOIN user_account ua ON ua.user_account_id = ar.user_account_id AND ua.account_status = 'active'
                     WHERE ar.business_unit_id = bu.business_unit_id) AS sysadmin_count,
                    (SELECT GROUP_CONCAT(DISTINCT CONCAT(e.first_name, ' ', e.last_name) ORDER BY e.last_name SEPARATOR ', ')
                     FROM account_role ar
                     JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'SYSTEM_ADMINISTRATOR'
                     JOIN employee e ON e.user_account_id = ar.user_account_id
                     WHERE ar.business_unit_id = bu.business_unit_id) AS sysadmin_names,
                    (SELECT GROUP_CONCAT(DISTINCT CONCAT(e.first_name, ' ', e.last_name) ORDER BY e.last_name SEPARATOR ', ')
                     FROM account_role ar
                     JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'BUSINESS_MANAGER'
                     JOIN employee e ON e.user_account_id = ar.user_account_id
                     WHERE ar.business_unit_id = bu.business_unit_id) AS manager_names,
                    (SELECT COUNT(*) FROM business_product bp WHERE bp.business_unit_id = bu.business_unit_id AND bp.product_status = 'active') AS product_count,
                    (SELECT COUNT(*) FROM appointment a WHERE a.business_unit_id = bu.business_unit_id) AS appointment_count,
                    (SELECT COUNT(*) FROM customer_order co WHERE co.business_unit_id = bu.business_unit_id) AS order_count,
                    (SELECT COALESCE(SUM(co.total_amount), 0) FROM customer_order co WHERE co.business_unit_id = bu.business_unit_id AND co.order_status <> 'cancelled') AS order_revenue
             FROM business_unit bu
             ORDER BY bu.business_unit_id"
        )->fetchAll();
    }

    private function fetchSystemAdmins(): array
    {
        return $this->db->query(
            "SELECT ua.user_account_id, ua.email, ua.username, ua.account_status, ua.created_at,
                    e.employee_id, e.first_name, e.last_name, e.position, e.employee_status,
                    bu.business_unit_id, bu.business_unit_name
             FROM account_role ar
             JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'SYSTEM_ADMINISTRATOR'
             JOIN user_account ua ON ua.user_account_id = ar.user_account_id
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id
             LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
             ORDER BY bu.business_unit_id, ua.user_account_id"
        )->fetchAll();
    }

    private function fetchAllEmployees(?int $filterUnitId = null): array
    {
        $sql = "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status, e.created_at,
                       ua.user_account_id, ua.email, ua.username, ua.account_status, ua.is_super_admin,
                       COALESCE(bu.business_unit_id, (SELECT MIN(ar2.business_unit_id) FROM account_role ar2 WHERE ar2.user_account_id = ua.user_account_id)) AS business_unit_id,
                       COALESCE(bu.business_unit_name, (SELECT GROUP_CONCAT(DISTINCT bu2.business_unit_name ORDER BY bu2.business_unit_id SEPARATOR ', ') FROM account_role ar2 JOIN business_unit bu2 ON bu2.business_unit_id = ar2.business_unit_id WHERE ar2.user_account_id = ua.user_account_id)) AS business_unit_name,
                       (SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ')
                        FROM account_role ar3 JOIN role r ON r.role_id = ar3.role_id
                        WHERE ar3.user_account_id = ua.user_account_id) AS roles
                FROM employee e
                JOIN user_account ua ON ua.user_account_id = e.user_account_id
                LEFT JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id";
        $args = [];
        if ($filterUnitId !== null) {
            $sql .= " WHERE (e.business_unit_id = ? OR EXISTS (SELECT 1 FROM account_role arf WHERE arf.user_account_id = ua.user_account_id AND arf.business_unit_id = ?))";
            $args[] = $filterUnitId;
            $args[] = $filterUnitId;
        }
        $sql .= " ORDER BY ua.is_super_admin DESC, e.employee_id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public function dashboard(): void
    {
        $this->requireSuperAdmin();

        $stats = [
            'customers' => (int)$this->db->query("SELECT COUNT(*) FROM customer WHERE customer_status = 'active'")->fetchColumn(),
            'employees' => (int)$this->db->query("SELECT COUNT(*) FROM employee WHERE employee_status = 'active'")->fetchColumn(),
            'sysadmins' => (int)$this->db->query(
                "SELECT COUNT(DISTINCT ar.user_account_id)
                 FROM account_role ar
                 JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'SYSTEM_ADMINISTRATOR'
                 JOIN user_account ua ON ua.user_account_id = ar.user_account_id AND ua.account_status = 'active'"
            )->fetchColumn(),
            'managers' => (int)$this->db->query(
                "SELECT COUNT(DISTINCT ar.user_account_id)
                 FROM account_role ar
                 JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'BUSINESS_MANAGER'
                 JOIN user_account ua ON ua.user_account_id = ar.user_account_id AND ua.account_status = 'active'"
            )->fetchColumn(),
            'appointments' => (int)$this->db->query("SELECT COUNT(*) FROM appointment")->fetchColumn(),
            'orders' => (int)$this->db->query("SELECT COUNT(*) FROM customer_order")->fetchColumn(),
            'sales' => (int)$this->db->query("SELECT COUNT(*) FROM sale WHERE sale_status = 'completed'")->fetchColumn(),
            'revenue' => (float)$this->db->query(
                "SELECT COALESCE((SELECT SUM(total_amount) FROM customer_order WHERE order_status <> 'cancelled'), 0)
                      + COALESCE((SELECT SUM(total_amount) FROM sale WHERE sale_status = 'completed'), 0)"
            )->fetchColumn(),
        ];

        $this->render('super_admin/dashboard', [
            'title' => 'Super Administrator Dashboard',
            'activeTab' => 'dashboard',
            'stats' => $stats,
            'units' => $this->unitOverviews(),
            'sysadmins' => $this->fetchSystemAdmins(),
            'recentActivity' => Audit::recent(10),
        ]);
    }

    public function businessUnits(): void
    {
        $this->requireSuperAdmin();
        $branches = $this->db->query(
            "SELECT b.*, bu.business_unit_name
             FROM branch b
             JOIN business_unit bu ON bu.business_unit_id = b.business_unit_id
             ORDER BY b.business_unit_id, b.branch_id"
        )->fetchAll();

        $this->render('super_admin/business_units', [
            'title' => 'Business Units · Super Administrator',
            'activeTab' => 'business-units',
            'units' => $this->unitOverviews(),
            'branches' => $branches,
        ]);
    }

    public function systemAdmins(): void
    {
        $this->requireSuperAdmin();
        $this->render('super_admin/system_admins', [
            'title' => 'System Administrators · Super Administrator',
            'activeTab' => 'system-admins',
            'sysadmins' => $this->fetchSystemAdmins(),
            'units' => Units::all(),
            'positionsByUnit' => AdminAccountService::allPositionsByUnit(true),
            'errors' => [],
            'old' => ['position' => 'System Administrator'],
        ]);
    }

    public function storeSystemAdmin(): void
    {
        $actorId = $this->requireSuperAdmin();
        CSRF::check();

        $input = [
            'full_name' => $this->post('full_name'),
            'first_name' => $this->post('first_name'),
            'last_name' => $this->post('last_name'),
            'email' => $this->post('email'),
            'username' => $this->post('username'),
            'business_unit_id' => (int)$this->post('business_unit_id'),
            'position' => $this->post('position', 'System Administrator'),
            'roles' => ['SYSTEM_ADMINISTRATOR'],
            'password' => $_POST['password'] ?? '',
            'phone' => $this->post('phone'),
        ];

        $res = AdminAccountService::createEmployeeAccount($input, null, $actorId);
        if (!$res['ok']) {
            http_response_code(422);
            Session::flash('error', 'Please review the highlighted fields below.');
            $this->render('super_admin/system_admins', [
                'title' => 'System Administrators · Super Administrator',
                'activeTab' => 'system-admins',
                'sysadmins' => $this->fetchSystemAdmins(),
                'units' => Units::all(),
                'positionsByUnit' => AdminAccountService::allPositionsByUnit(true),
                'errors' => $res['errors'],
                'old' => $input,
            ]);
            return;
        }

        Session::flash('success', 'System Administrator account created and assigned to business unit.');
        $this->redirect('/super-admin/system-admins');
    }

    public function employees(): void
    {
        $this->requireSuperAdmin();
        $filterUnit = isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
        $roles = $this->db->query(
            "SELECT role_id, role_name, description FROM role WHERE role_name <> 'SUPER_ADMINISTRATOR' ORDER BY role_id"
        )->fetchAll();

        $this->render('super_admin/employees', [
            'title' => 'Employee Accounts · Super Administrator',
            'activeTab' => 'employees',
            'employees' => $this->fetchAllEmployees($filterUnit),
            'units' => Units::all(),
            'filterUnit' => $filterUnit,
            'positionsByUnit' => AdminAccountService::allPositionsByUnit(true),
            'assignableRoles' => $roles,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function storeEmployee(): void
    {
        $actorId = $this->requireSuperAdmin();
        CSRF::check();

        $rawRoles = $_POST['roles'] ?? ($_POST['role_ids'] ?? []);
        if (!is_array($rawRoles)) {
            $rawRoles = $rawRoles !== '' ? [$rawRoles] : [];
        }

        $input = [
            'full_name' => $this->post('full_name'),
            'first_name' => $this->post('first_name'),
            'last_name' => $this->post('last_name'),
            'email' => $this->post('email'),
            'username' => $this->post('username'),
            'business_unit_id' => (int)$this->post('business_unit_id'),
            'position' => $this->post('position'),
            'roles' => $rawRoles,
            'password' => $_POST['password'] ?? '',
            'phone' => $this->post('phone'),
        ];

        $res = AdminAccountService::createEmployeeAccount($input, null, $actorId);
        if (!$res['ok']) {
            http_response_code(422);
            $roles = $this->db->query(
                "SELECT role_id, role_name, description FROM role WHERE role_name <> 'SUPER_ADMINISTRATOR' ORDER BY role_id"
            )->fetchAll();
            Session::flash('error', 'Could not create employee account. Please fix the errors below.');
            $this->render('super_admin/employees', [
                'title' => 'Employee Accounts · Super Administrator',
                'activeTab' => 'employees',
                'employees' => $this->fetchAllEmployees(null),
                'units' => Units::all(),
                'filterUnit' => null,
                'positionsByUnit' => AdminAccountService::allPositionsByUnit(true),
                'assignableRoles' => $roles,
                'errors' => $res['errors'],
                'old' => $input,
            ]);
            return;
        }

        Session::flash('success', 'Employee account created and assigned.');
        $this->redirect('/super-admin/employees');
    }

    public function updateAccountStatus(int $userId): void
    {
        $actorId = $this->requireSuperAdmin();
        CSRF::check();

        $acctStmt = $this->db->prepare('SELECT user_account_id, email, account_status, is_super_admin FROM user_account WHERE user_account_id = ?');
        $acctStmt->execute([$userId]);
        $acct = $acctStmt->fetch() ?: $this->notFound();

        if ((bool)$acct['is_super_admin']) {
            http_response_code(422);
            Session::flash('error', 'The permanent Super Administrator account cannot be deactivated or suspended.');
            $this->redirect('/super-admin/employees');
        }

        $targetStatus = $this->post('account_status');
        if (!in_array($targetStatus, ['active', 'inactive', 'suspended'], true)) {
            http_response_code(422);
            Session::flash('error', 'Invalid account status.');
            $this->redirect('/super-admin/employees');
        }

        $empStatus = $targetStatus === 'active' ? 'active' : 'inactive';
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE user_account SET account_status = ? WHERE user_account_id = ?')
                ->execute([$targetStatus, $userId]);
            $this->db->prepare('UPDATE employee SET employee_status = ? WHERE user_account_id = ?')
                ->execute([$empStatus, $userId]);
            Audit::log(
                'account.status_updated',
                "Updated status of {$acct['email']} from {$acct['account_status']} to $targetStatus.",
                null,
                'user_account',
                $userId,
                $actorId
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash('success', "Account {$acct['email']} status updated to $targetStatus.");
        $returnTo = $this->post('return_to', '/super-admin/employees');
        $this->redirect(str_starts_with($returnTo, '/super-admin') ? $returnTo : '/super-admin/employees');
    }

    public function roles(): void
    {
        $this->requireSuperAdmin();
        $roles = $this->db->query(
            "SELECT r.role_id, r.role_name, r.description,
                    GROUP_CONCAT(DISTINCT p.permission_code ORDER BY p.permission_code SEPARATOR ', ') AS permissions,
                    (SELECT COUNT(DISTINCT ar.user_account_id) FROM account_role ar WHERE ar.role_id = r.role_id) AS assigned_count
             FROM role r
             LEFT JOIN role_permission rp ON rp.role_id = r.role_id
             LEFT JOIN permission p ON p.permission_id = rp.permission_id
             GROUP BY r.role_id, r.role_name, r.description
             ORDER BY FIELD(r.role_name, 'SUPER_ADMINISTRATOR', 'SYSTEM_ADMINISTRATOR', 'BUSINESS_MANAGER', 'INVENTORY_MANAGER', 'DELIVERY_PERSONNEL', 'VETERINARIAN_WORKSPACE', 'VETERINARY_NURSE_WORKSPACE', 'VETERINARY_TECHNICIAN_WORKSPACE', 'VETERINARIAN', 'NURSE', 'TECHNICIAN', 'RECEPTIONIST'), r.role_name"
        )->fetchAll();

        $permissions = $this->db->query(
            "SELECT p.permission_id, p.permission_code, p.description,
                    GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS granted_to_roles
             FROM permission p
             LEFT JOIN role_permission rp ON rp.permission_id = p.permission_id
             LEFT JOIN role r ON r.role_id = rp.role_id
             GROUP BY p.permission_id, p.permission_code, p.description
             ORDER BY p.permission_code"
        )->fetchAll();

        $unitRoleBreakdown = $this->db->query(
            "SELECT bu.business_unit_name, r.role_name, COUNT(DISTINCT ar.user_account_id) AS account_count
             FROM business_unit bu
             CROSS JOIN role r
             LEFT JOIN account_role ar ON ar.business_unit_id = bu.business_unit_id AND ar.role_id = r.role_id
             WHERE r.role_name <> 'SUPER_ADMINISTRATOR'
             GROUP BY bu.business_unit_id, bu.business_unit_name, r.role_id, r.role_name
             ORDER BY bu.business_unit_id, r.role_id"
        )->fetchAll();

        $this->render('super_admin/roles', [
            'title' => 'Roles & Permissions · Super Administrator',
            'activeTab' => 'roles',
            'roles' => $roles,
            'permissions' => $permissions,
            'unitRoleBreakdown' => $unitRoleBreakdown,
        ]);
    }

    public function activity(): void
    {
        $this->requireSuperAdmin();
        $filterUnit = isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
        $this->render('super_admin/activity', [
            'title' => 'System Activity · Super Administrator',
            'activeTab' => 'activity',
            'logs' => Audit::recent(100, $filterUnit),
            'units' => Units::all(),
            'filterUnit' => $filterUnit,
        ]);
    }
}

