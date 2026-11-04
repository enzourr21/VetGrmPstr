<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\Units;

final class StaffController extends Controller
{
    /** Provisional receptionist transitions (BUSINESS_RULES 8.1: final transitions are still UNRESOLVED). */
    public const TRANSITIONS = [
        'Pending' => ['Awaiting Customer Response', 'Confirmed', 'Rejected'],
        'Awaiting Customer Response' => ['Confirmed', 'Rejected', 'Cancelled'],
        'Confirmed' => ['Cancelled', 'No-Show'],
    ];

    public function loginForm(): void
    {
        $this->render('staff/login', ['title' => 'Staff sign in']);
    }

    public function login(): void
    {
        CSRF::check();
        $raw = $this->post('email') !== '' ? $this->post('email') : ($this->post('username') !== '' ? $this->post('username') : $this->post('identifier'));
        $identifier = strtolower($raw);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $isEmail = str_contains($identifier, '@');

        if ($isEmail) {
            $s = $this->db->prepare('SELECT ua.user_account_id, ua.email, ua.username, ua.password_hash, ua.account_status, ua.is_super_admin,
                    (SELECT customer_id FROM customer c WHERE c.user_account_id=ua.user_account_id AND c.customer_status="active") AS customer_id,
                    (SELECT employee_id FROM employee e WHERE e.user_account_id=ua.user_account_id AND e.employee_status="active") AS employee_id
                FROM user_account ua WHERE ua.email=?');
        } else {
            $s = $this->db->prepare('SELECT ua.user_account_id, ua.email, ua.username, ua.password_hash, ua.account_status, ua.is_super_admin,
                    (SELECT customer_id FROM customer c WHERE c.user_account_id=ua.user_account_id AND c.customer_status="active") AS customer_id,
                    (SELECT employee_id FROM employee e WHERE e.user_account_id=ua.user_account_id AND e.employee_status="active") AS employee_id
                FROM user_account ua WHERE ua.username=?');
        }
        $s->execute([$identifier]);
        $a = $s->fetch();
        static $dummy = null;
        $dummy ??= password_hash('not-a-real-password', PASSWORD_DEFAULT);
        $ok = password_verify($password, $a['password_hash'] ?? $dummy);

        // Priority 3.3 & 3.4: If an employee or System Administrator signs in using their username,
        // the resulting session must be customer-only (never exposing employee/admin privileges).
        if ($a && $ok && $a['account_status'] === 'active' && !$isEmail) {
            $uid = (int)$a['user_account_id'];
            $cid = AuthController::ensureCustomerProfile(
                $this->db,
                $uid,
                $a['customer_id'] !== null ? (int)$a['customer_id'] : null
            );
            Session::login([
                'user_id' => $uid,
                'email' => (string)$a['email'],
                'username' => $a['username'] ?? null,
                'customer_id' => $cid,
                'employee_id' => null,
                'auth_method' => 'username',
                'base_context' => 'customer',
                'active_context' => 'customer',
                'is_super_admin' => false,
            ]);
            Audit::log('auth.login.customer_via_username', 'Username sign-in established customer-only session.', null, 'user_account', $uid, $uid);
            $this->redirect('/');
        }

        if (!$a || !$ok || $a['account_status'] !== 'active' || ($a['employee_id'] === null && !(bool)$a['is_super_admin'])) {
            http_response_code(401);
            Session::flash('error', 'Incorrect email or password, or this account has no staff access.');
            $this->render('staff/login', ['title' => 'Staff sign in']);
            return;
        }

        $uid = (int)$a['user_account_id'];
        $isSuper = (bool)$a['is_super_admin'];
        $landing = AuthController::resolveEmployeeLandingFor($this->db, $uid, $isSuper);

        Session::login([
            'user_id' => $uid,
            'email' => (string)$a['email'],
            'username' => $a['username'] ?? null,
            'customer_id' => $a['customer_id'] !== null ? (int)$a['customer_id'] : null,
            'employee_id' => $a['employee_id'] !== null ? (int)$a['employee_id'] : null,
            'auth_method' => 'email',
            'base_context' => 'employee',
            'active_context' => 'employee',
            'is_super_admin' => $isSuper,
        ]);
        Audit::log('auth.login.staff', "Staff email sign-in ($landing).", null, 'user_account', $uid, $uid);
        $this->redirect($landing);
    }

    public function workspace(): void
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            $this->redirect('/staff/login');
        }
        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');
        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('Customer sessions cannot access the employee workspace.');
        }

        $isSuper = $this->isSuperAdmin();
        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status,
                    ua.email, ua.username, bu.business_unit_id, bu.business_unit_name
             FROM user_account ua
             LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
             LEFT JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
             WHERE ua.user_account_id = ? AND ua.account_status = 'active'"
        );
        $empStmt->execute([(int)$uid]);
        $emp = $empStmt->fetch();
        if (!$emp || (!$isSuper && ($emp['employee_id'] === null || $emp['employee_status'] !== 'active'))) {
            $this->forbidden();
        }

        $rolesStmt = $this->db->prepare(
            "SELECT r.role_name, r.description, bu.business_unit_id, bu.business_unit_name
             FROM account_role ar
             JOIN role r ON r.role_id = ar.role_id
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id
             WHERE ar.user_account_id = ?
             ORDER BY bu.business_unit_id, r.role_name"
        );
        $rolesStmt->execute([(int)$uid]);
        $roles = $rolesStmt->fetchAll();

        $permsStmt = $this->db->prepare(
            "SELECT DISTINCT p.permission_code, p.description, bu.business_unit_name
             FROM account_role ar
             JOIN role_permission rp ON rp.role_id = ar.role_id
             JOIN permission p ON p.permission_id = rp.permission_id
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id
             WHERE ar.user_account_id = ?
             ORDER BY bu.business_unit_name, p.permission_code"
        );
        $permsStmt->execute([(int)$uid]);
        $permissions = $permsStmt->fetchAll();

        $this->render('staff/workspace', [
            'title' => 'Employee Workspace',
            'emp' => $emp,
            'roles' => $roles,
            'permissions' => $permissions,
            'isSuper' => $isSuper,
        ]);
    }

    public function appointments(): void
    {
        $unit = Units::groomingId();
        $this->requireStaff('appointment.view', $unit);
        $filter = $this->get('status');
        $sql = 'SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.created_at, st.status_name,
                    CONCAT(c.first_name, " ", c.last_name) AS customer_name,
                    (SELECT GROUP_CONCAT(DISTINCT p.pet_name SEPARATOR ", ") FROM appointment_pet ap JOIN pet p ON p.pet_id=ap.pet_id WHERE ap.appointment_id=a.appointment_id) AS pets
                FROM appointment a
                JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id
                JOIN customer c ON c.customer_id=a.customer_id
                WHERE a.business_unit_id=?';
        $args = [$unit];
        if ($filter !== '') {
            $sql .= ' AND st.status_name=?';
            $args[] = $filter;
        }
        $sql .= ' ORDER BY (st.status_name="Pending") DESC, a.created_at DESC';
        $q = $this->db->prepare($sql);
        $q->execute($args);
        $statuses = $this->db->query('SELECT status_name FROM appointment_status ORDER BY sort_order')->fetchAll(\PDO::FETCH_COLUMN);
        $this->render('staff/appointments', ['title' => 'Grooming appointment requests', 'rows' => $q->fetchAll(), 'statuses' => $statuses, 'filter' => $filter]);
    }

    public function appointment(int $id): void
    {
        $unit = Units::groomingId();
        $this->requireStaff('appointment.view', $unit);
        $s = $this->db->prepare('SELECT a.*, st.status_name, c.first_name, c.last_name, c.phone, ua.email
            FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id
            JOIN customer c ON c.customer_id=a.customer_id JOIN user_account ua ON ua.user_account_id=c.user_account_id
            WHERE a.appointment_id=? AND a.business_unit_id=?');
        $s->execute([$id, $unit]);
        $a = $s->fetch() ?: $this->notFound();
        $pets = $this->db->prepare('SELECT p.pet_name, p.species, p.breed, p.sex, p.weight_kg, p.notes FROM appointment_pet ap JOIN pet p ON p.pet_id=ap.pet_id WHERE ap.appointment_id=?');
        $pets->execute([$id]);
        $this->render('staff/appointment', [
            'title' => 'Request #' . $id, 'a' => $a, 'pets' => $pets->fetchAll(),
            'lines' => AppointmentController::lines($this->db, $id),
            'next' => self::TRANSITIONS[$a['status_name']] ?? [],
        ]);
    }

    public function setStatus(int $id): void
    {
        $unit = Units::groomingId();
        $this->requireStaff('appointment.process', $unit);
        CSRF::check();
        $target = $this->post('status');
        $s = $this->db->prepare('SELECT a.appointment_status_id, st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=? AND a.business_unit_id=?');
        $s->execute([$id, $unit]);
        $cur = $s->fetch() ?: $this->notFound();
        if (!in_array($target, self::TRANSITIONS[$cur['status_name']] ?? [], true)) {
            Session::flash('error', "Cannot change a request from {$cur['status_name']} to that status.");
            $this->redirect("/staff/appointments/$id");
        }
        $t = $this->db->prepare('SELECT appointment_status_id FROM appointment_status WHERE status_name=?');
        $t->execute([$target]);
        $tid = (int)$t->fetchColumn();
        // optimistic check on the current status prevents lost updates
        $u = $this->db->prepare('UPDATE appointment SET appointment_status_id=? WHERE appointment_id=? AND business_unit_id=? AND appointment_status_id=?');
        $u->execute([$tid, $id, $unit, $cur['appointment_status_id']]);
        if ($u->rowCount()) {
            Audit::log('appointment.status_changed', "Grooming appointment #$id status changed from {$cur['status_name']} to $target.", $unit, 'appointment', $id);
        }
        Session::flash($u->rowCount() ? 'success' : 'error', $u->rowCount() ? "Status changed to $target." : 'The request was changed by someone else. Please review it again.');
        $this->redirect("/staff/appointments/$id");
    }

    private function get(string $k): string
    {
        $v = $_GET[$k] ?? '';
        return is_string($v) ? trim($v) : '';
    }
}
