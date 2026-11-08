<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Audit;
use App\Models\InventoryAccessKey;
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

        $empUnitId = (int)($emp['business_unit_id'] ?? 0);
        $roleNames = array_column($roles, 'role_name');
        $hasPermanentInventoryAccess = $isSuper
            || in_array('BUSINESS_MANAGER', $roleNames, true)
            || in_array('INVENTORY_MANAGER', $roleNames, true);
        $isSysAdminOnly = !empty($roleNames) && count(array_diff($roleNames, ['SYSTEM_ADMINISTRATOR'])) === 0;
        $supportsTempInventoryKey = !$hasPermanentInventoryAccess
            && !$isSysAdminOnly
            && $empUnitId > 0
            && InventoryAccessKey::isUnitSupported($empUnitId);

        $activeTempInventoryGrant = null;
        if ($supportsTempInventoryKey) {
            $tempMap = Session::get('temp_inventory_access', []);
            $grant = is_array($tempMap) ? ($tempMap[$empUnitId] ?? null) : null;
            if (is_array($grant) && (int)($grant['employee_id'] ?? 0) === (int)$emp['employee_id']) {
                $activeTempInventoryGrant = InventoryAccessKey::validateSessionGrant(
                    $this->db,
                    (int)($grant['key_id'] ?? 0),
                    $empUnitId
                );
                if ($activeTempInventoryGrant === null) {
                    unset($tempMap[$empUnitId]);
                    Session::set('temp_inventory_access', $tempMap);
                }
            }
        }

        $this->render('staff/workspace', [
            'title' => 'Employee Workspace',
            'emp' => $emp,
            'roles' => $roles,
            'permissions' => $permissions,
            'isSuper' => $isSuper,
            'hasPermanentInventoryAccess' => $hasPermanentInventoryAccess && InventoryAccessKey::isUnitSupported($empUnitId),
            'supportsTempInventoryKey' => $supportsTempInventoryKey,
            'activeTempInventoryGrant' => $activeTempInventoryGrant,
        ]);
    }

    public function redeemInventoryKey(): void
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            $this->redirect('/staff/login');
        }
        $baseContext = Session::get('base_context', Session::get('employee_id') ? 'employee' : 'customer');
        $activeContext = Session::get('active_context', $baseContext);
        $authMethod = Session::get('auth_method', 'email');
        if ($baseContext !== 'employee' || $activeContext === 'customer' || $authMethod === 'username') {
            $this->forbidden('An email-authenticated employee session is required.');
        }

        CSRF::check();

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
            $this->forbidden('Active employee assignment is required.');
        }

        $empUnitId = (int)$emp['business_unit_id'];
        $empId = (int)$emp['employee_id'];

        $queryUnitId = isset($_GET['unit_id']) && ctype_digit((string)$_GET['unit_id']) ? (int)$_GET['unit_id'] : null;
        $postUnitId = isset($_POST['business_unit_id']) && ctype_digit((string)$_POST['business_unit_id']) ? (int)$_POST['business_unit_id'] : null;
        foreach ([$queryUnitId, $postUnitId] as $chkUnit) {
            if ($chkUnit !== null && $chkUnit !== $empUnitId) {
                $this->forbidden('Access denied: you cannot redeem an Inventory Access Key for another business unit.');
            }
        }

        $empRoles = $this->getStaffRoles((int)$uid, $empUnitId);
        if (!empty($empRoles) && count(array_diff($empRoles, ['SYSTEM_ADMINISTRATOR'])) === 0) {
            $this->forbidden('System Administrators do not have operational inventory access.');
        }

        $keyInput = $this->post('inventory_key');
        $verification = InventoryAccessKey::verifyKeyForUnit($this->db, $keyInput, $empUnitId);

        if (empty($verification['ok'])) {
            $reason = (string)($verification['reason'] ?? 'invalid');
            $msg = (string)($verification['message'] ?? 'Invalid or expired Inventory Access Key.');

            if ($reason === 'cross_unit') {
                Audit::log(
                    'inventory.temp_access_denied_cross_unit',
                    "Rejected cross-business-unit Inventory Access Key attempt by employee #{$empId}",
                    $empUnitId,
                    'employee',
                    $empId,
                    (int)$uid
                );
                $this->forbidden($msg);
            }

            if ($reason === 'unsupported_unit') {
                $this->forbidden($msg);
            }

            if ($reason === 'revoked') {
                Audit::log(
                    'inventory.temp_access_denied_revoked',
                    "Rejected attempt to redeem revoked Inventory Access Key by employee #{$empId}",
                    $empUnitId,
                    'employee',
                    $empId,
                    (int)$uid
                );
            }

            Session::flash('error', $msg);
            $this->redirect('/staff/workspace');
        }

        $verifiedKey = $verification['key'];
        $tempMap = Session::get('temp_inventory_access', []);
        if (!is_array($tempMap)) {
            $tempMap = [];
        }
        $tempMap[$empUnitId] = [
            'key_id' => (int)$verifiedKey['key_id'],
            'employee_id' => $empId,
            'unit_id' => $empUnitId,
        ];
        Session::set('temp_inventory_access', $tempMap);

        Audit::log(
            'inventory.temp_access_granted',
            "Granted temporary inventory access to employee #{$empId} via key #{$verifiedKey['key_id']}",
            $empUnitId,
            'inventory_access_key',
            (int)$verifiedKey['key_id'],
            (int)$uid
        );

        Session::flash('success', "Temporary Inventory Access unlocked for {$emp['business_unit_name']}.");
        $this->redirect('/manager/inventory');
    }

    public function exitInventoryAccess(): void
    {
        $uid = Session::get('user_id');
        if (!$uid) {
            $this->redirect('/staff/login');
        }
        CSRF::check();
        Session::forget('temp_inventory_access');
        Session::flash('info', 'Temporary inventory access ended.');
        $this->redirect('/staff/workspace');
    }

    private function getStaffRoles(int $userId, int $unitId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.role_name
             FROM account_role ar
             JOIN role r ON r.role_id = ar.role_id
             WHERE ar.user_account_id = ? AND ar.business_unit_id = ?"
        );
        $stmt->execute([$userId, $unitId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function appointments(): void
    {
        $unit = Units::groomingId();
        $eid = $this->requireStaff('appointment.view', $unit);
        $uid = (int)Session::get('user_id');
        $roles = $this->getStaffRoles($uid, $unit);

        // System Administrator and Inventory Manager have no operational appointment access by default
        if (!$this->isSuperAdmin()) {
            $hasOperationalAccess = in_array('RECEPTIONIST', $roles, true)
                || in_array('BUSINESS_MANAGER', $roles, true)
                || in_array('STYLIST_WORKSPACE', $roles, true)
                || in_array('BATHER_WORKSPACE', $roles, true);
            if (!$hasOperationalAccess) {
                $this->forbidden('Operational appointment access is not granted to this role.');
            }
        }

        $isAllAccess = $this->isSuperAdmin()
            || in_array('RECEPTIONIST', $roles, true)
            || in_array('BUSINESS_MANAGER', $roles, true);

        $filter = $this->get('status');
        $sql = 'SELECT a.appointment_id, a.preferred_date, a.preferred_time, a.created_at, st.status_name,
                    CONCAT(c.first_name, " ", c.last_name) AS customer_name,
                    (SELECT GROUP_CONCAT(DISTINCT p.pet_name SEPARATOR ", ") FROM appointment_pet ap JOIN pet p ON p.pet_id=ap.pet_id WHERE ap.appointment_id=a.appointment_id) AS pets
                FROM appointment a
                JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id
                JOIN customer c ON c.customer_id=a.customer_id
                WHERE a.business_unit_id=?';
        $args = [$unit];

        if (!$isAllAccess) {
            // Stylist, Bather, Master Stylist view assigned appointments only
            $sql .= ' AND EXISTS (
                SELECT 1 FROM appointment_pet ap
                JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
                WHERE ap.appointment_id = a.appointment_id AND ps.performer_employee_id = ?
            )';
            $args[] = $eid;
        }

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
        $eid = $this->requireStaff('appointment.view', $unit);
        $uid = (int)Session::get('user_id');
        $roles = $this->getStaffRoles($uid, $unit);

        if (!$this->isSuperAdmin()) {
            $hasOperationalAccess = in_array('RECEPTIONIST', $roles, true)
                || in_array('BUSINESS_MANAGER', $roles, true)
                || in_array('STYLIST_WORKSPACE', $roles, true)
                || in_array('BATHER_WORKSPACE', $roles, true);
            if (!$hasOperationalAccess) {
                $this->forbidden('Operational appointment access is not granted to this role.');
            }
        }

        $isAllAccess = $this->isSuperAdmin()
            || in_array('RECEPTIONIST', $roles, true)
            || in_array('BUSINESS_MANAGER', $roles, true);

        $s = $this->db->prepare('SELECT a.*, st.status_name, c.first_name, c.last_name, c.phone, ua.email
            FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id
            JOIN customer c ON c.customer_id=a.customer_id JOIN user_account ua ON ua.user_account_id=c.user_account_id
            WHERE a.appointment_id=? AND a.business_unit_id=?');
        $s->execute([$id, $unit]);
        $a = $s->fetch() ?: $this->notFound();

        if (!$isAllAccess) {
            $chkAssigned = $this->db->prepare(
                'SELECT 1 FROM appointment_pet ap
                 JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
                 WHERE ap.appointment_id = ? AND ps.performer_employee_id = ? LIMIT 1'
            );
            $chkAssigned->execute([$id, $eid]);
            if (!$chkAssigned->fetchColumn()) {
                $this->forbidden('Access denied: you are not assigned to this appointment.');
            }
        }

        $pets = $this->db->prepare('SELECT p.pet_name, p.species, p.breed, p.sex, p.weight_kg, p.notes FROM appointment_pet ap JOIN pet p ON p.pet_id=ap.pet_id WHERE ap.appointment_id=?');
        $pets->execute([$id]);
        $this->render('staff/appointment', [
            'title' => 'Request #' . $id, 'a' => $a, 'pets' => $pets->fetchAll(),
            'lines' => AppointmentController::lines($this->db, $id),
            'next' => $isAllAccess ? (self::TRANSITIONS[$a['status_name']] ?? []) : [],
            'canManage' => $isAllAccess,
        ]);
    }

    public function setStatus(int $id): void
    {
        $unit = Units::groomingId();
        $eid = $this->requireStaff('appointment.process', $unit);
        CSRF::check();
        $uid = (int)Session::get('user_id');
        $roles = $this->getStaffRoles($uid, $unit);

        // Only Receptionist and Business Manager can change status
        $canProcess = $this->isSuperAdmin()
            || in_array('RECEPTIONIST', $roles, true)
            || in_array('BUSINESS_MANAGER', $roles, true);
        if (!$canProcess) {
            $this->forbidden('Only Receptionist and Business Manager can change appointment status.');
        }

        $target = $this->post('status');
        $s = $this->db->prepare('SELECT a.appointment_status_id, st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=? AND a.business_unit_id=?');
        $s->execute([$id, $unit]);
        $cur = $s->fetch() ?: $this->notFound();
        if (!in_array($target, self::TRANSITIONS[$cur['status_name']] ?? [], true)) {
            Session::flash('error', "Cannot change a request from {$cur['status_name']} to that status.");
            $this->redirect("/staff/appointments/$id");
        }

        // Cancellation requires non-empty cancellation reason
        $reason = trim((string)($this->post('cancellation_reason') ?: $this->post('reason')));
        if ($target === 'Cancelled' && $reason === '') {
            http_response_code(422);
            Session::flash('error', 'A non-empty cancellation reason is required to cancel an appointment.');
            $this->redirect("/staff/appointments/$id");
        }

        $t = $this->db->prepare('SELECT appointment_status_id FROM appointment_status WHERE status_name=?');
        $t->execute([$target]);
        $tid = (int)$t->fetchColumn();

        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare('UPDATE appointment SET appointment_status_id=? WHERE appointment_id=? AND business_unit_id=? AND appointment_status_id=?');
            $u->execute([$tid, $id, $unit, $cur['appointment_status_id']]);

            if ($u->rowCount()) {
                if ($target === 'Cancelled') {
                    Audit::record(
                        $this->db,
                        'appointment.cancelled',
                        "Grooming appointment #$id cancelled: $reason",
                        $unit,
                        $uid,
                        null,
                        $cur['status_name'],
                        'Cancelled',
                        'appointment',
                        $id
                    );
                } else {
                    Audit::record(
                        $this->db,
                        'appointment.status_changed',
                        "Grooming appointment #$id status changed from {$cur['status_name']} to $target.",
                        $unit,
                        $uid,
                        null,
                        $cur['status_name'],
                        $target,
                        'appointment',
                        $id
                    );
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash($u->rowCount() ? 'success' : 'error', $u->rowCount() ? "Status changed to $target." : 'The request was changed by someone else. Please review it again.');
        $this->redirect("/staff/appointments/$id");
    }

    public function cancelAppointment(int $id): void
    {
        $unit = Units::groomingId();
        $eid = $this->requireStaff('appointment.process', $unit);
        CSRF::check();
        $uid = (int)Session::get('user_id');
        $roles = $this->getStaffRoles($uid, $unit);

        $canProcess = $this->isSuperAdmin()
            || in_array('RECEPTIONIST', $roles, true)
            || in_array('BUSINESS_MANAGER', $roles, true);
        if (!$canProcess) {
            $this->forbidden('Only Receptionist and Business Manager can cancel appointments.');
        }

        $reason = trim((string)($this->post('cancellation_reason') ?: $this->post('reason')));
        if ($reason === '') {
            http_response_code(422);
            Session::flash('error', 'A non-empty cancellation reason is required to cancel an appointment.');
            $this->redirect("/staff/appointments/$id");
        }

        $s = $this->db->prepare('SELECT a.appointment_status_id, st.status_name FROM appointment a JOIN appointment_status st ON st.appointment_status_id=a.appointment_status_id WHERE a.appointment_id=? AND a.business_unit_id=?');
        $s->execute([$id, $unit]);
        $cur = $s->fetch() ?: $this->notFound();

        $t = $this->db->prepare('SELECT appointment_status_id FROM appointment_status WHERE status_name="Cancelled"');
        $t->execute();
        $cancelledId = (int)$t->fetchColumn();

        $this->db->beginTransaction();
        try {
            $u = $this->db->prepare('UPDATE appointment SET appointment_status_id=? WHERE appointment_id=? AND business_unit_id=?');
            $u->execute([$cancelledId, $id, $unit]);

            Audit::record(
                $this->db,
                'appointment.cancelled',
                "Grooming appointment #$id cancelled: $reason",
                $unit,
                $uid,
                null,
                $cur['status_name'],
                'Cancelled',
                'appointment',
                $id
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash('success', "Appointment #$id has been cancelled.");
        $this->redirect("/staff/appointments/$id");
    }

    public function reassignAppointment(int $id): void
    {
        $unit = Units::groomingId();
        $eid = $this->requireStaff('appointment.process', $unit);
        CSRF::check();
        $uid = (int)Session::get('user_id');
        $roles = $this->getStaffRoles($uid, $unit);

        $canProcess = $this->isSuperAdmin()
            || in_array('RECEPTIONIST', $roles, true)
            || in_array('BUSINESS_MANAGER', $roles, true);
        if (!$canProcess) {
            $this->forbidden('Only Receptionist and Business Manager can reassign appointments.');
        }

        $targetEmployeeId = (int)$this->post('employee_id');
        $targetEmpStmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.employee_status,
                    (SELECT GROUP_CONCAT(r.role_name) FROM account_role ar JOIN role r ON r.role_id = ar.role_id WHERE ar.user_account_id = e.user_account_id AND ar.business_unit_id = ?) AS roles
             FROM employee e
             WHERE e.employee_id = ? AND e.business_unit_id = ? AND e.employee_status = 'active'"
        );
        $targetEmpStmt->execute([$unit, $targetEmployeeId, $unit]);
        $targetEmp = $targetEmpStmt->fetch();
        if (!$targetEmp) {
            http_response_code(422);
            Session::flash('error', 'Selected employee is not an active staff member in Grooming Services.');
            $this->redirect("/staff/appointments/$id");
        }

        $s = $this->db->prepare('SELECT appointment_id, preferred_date, preferred_time FROM appointment WHERE appointment_id = ? AND business_unit_id = ?');
        $s->execute([$id, $unit]);
        $appt = $s->fetch() ?: $this->notFound();

        $targetRoles = array_filter(explode(',', (string)$targetEmp['roles']));
        $targetPosition = (string)$targetEmp['position'];

        // Availability check: Ensure the employee is not assigned to an overlapping appointment at the same preferred date/time
        if (!empty($appt['preferred_date']) && !empty($appt['preferred_time'])) {
            $conflictStmt = $this->db->prepare(
                "SELECT a.appointment_id FROM appointment a
                 JOIN appointment_pet ap ON ap.appointment_id = a.appointment_id
                 JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
                 JOIN appointment_status ast ON ast.appointment_status_id = a.appointment_status_id
                 WHERE ps.performer_employee_id = ?
                   AND a.preferred_date = ?
                   AND a.preferred_time = ?
                   AND a.appointment_id <> ?
                   AND ast.status_name NOT IN ('Cancelled', 'Rejected')
                 LIMIT 1"
            );
            $conflictStmt->execute([$targetEmployeeId, $appt['preferred_date'], $appt['preferred_time'], $id]);
            if ($conflictStmt->fetchColumn()) {
                http_response_code(422);
                Session::flash('error', "Employee {$targetEmp['first_name']} {$targetEmp['last_name']} has a scheduling conflict at this date and time.");
                $this->redirect("/staff/appointments/$id");
            }
        }

        // Get current performer if any
        $prevPerformer = $this->db->prepare(
            "SELECT ps.performer_employee_id, e.first_name, e.last_name
             FROM appointment_pet ap
             JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
             JOIN employee e ON e.employee_id = ps.performer_employee_id
             WHERE ap.appointment_id = ? LIMIT 1"
        );
        $prevPerformer->execute([$id]);
        $prev = $prevPerformer->fetch();
        $prevName = $prev ? "{$prev['first_name']} {$prev['last_name']} (ID #{$prev['performer_employee_id']})" : "None";
        $newName = "{$targetEmp['first_name']} {$targetEmp['last_name']} (ID #{$targetEmployeeId})";

        $this->db->beginTransaction();
        try {
            // Update performer_employee_id on performed_service rows for this appointment
            $updatePs = $this->db->prepare(
                "UPDATE performed_service ps
                 JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
                 SET ps.performer_employee_id = ?
                 WHERE ap.appointment_id = ?"
            );
            $updatePs->execute([$targetEmployeeId, $id]);

            Audit::record(
                $this->db,
                'appointment.reassigned',
                "Reassigned Grooming appointment #$id: previous employee was $prevName, new employee is $newName.",
                $unit,
                $uid,
                $targetEmployeeId,
                $prevName,
                $newName,
                'appointment',
                $id
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash('success', "Appointment #$id reassigned to {$targetEmp['first_name']} {$targetEmp['last_name']}.");
        $this->redirect("/staff/appointments/$id");
    }

    public function updateProgress(int $id): void
    {
        $unit = Units::groomingId();
        $eid = $this->requireStaff('appointment.view', $unit);
        CSRF::check();
        $uid = (int)Session::get('user_id');
        $roles = $this->getStaffRoles($uid, $unit);

        // Receptionist and Business Manager cannot update grooming progress as though they personally performed the service
        // Stylist, Bather, Master Stylist can update progress for assigned services only
        $canUpdate = in_array('STYLIST_WORKSPACE', $roles, true) || in_array('BATHER_WORKSPACE', $roles, true);
        if (!$canUpdate) {
            $this->forbidden('Only assigned Stylists and Bathers can update grooming progress.');
        }

        // Verify assignment
        $chkAssigned = $this->db->prepare(
            'SELECT 1 FROM appointment_pet ap
             JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
             WHERE ap.appointment_id = ? AND ps.performer_employee_id = ? LIMIT 1'
        );
        $chkAssigned->execute([$id, $eid]);
        if (!$chkAssigned->fetchColumn()) {
            $this->forbidden('Access denied: you can only update progress on services assigned to you.');
        }

        $progressNotes = trim((string)$this->post('notes'));
        $this->db->prepare(
            "UPDATE performed_service ps
             JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
             SET ps.notes = ?, ps.updated_at = NOW()
             WHERE ap.appointment_id = ? AND ps.performer_employee_id = ?"
        )->execute([$progressNotes, $id, $eid]);

        Session::flash('success', 'Grooming progress updated.');
        $this->redirect("/staff/appointments/$id");
    }

    public function completeService(int $id): void
    {
        $unit = Units::groomingId();
        $eid = $this->requireStaff('appointment.view', $unit);
        CSRF::check();
        $uid = (int)Session::get('user_id');
        $roles = $this->getStaffRoles($uid, $unit);

        $isManagerOrReception = $this->isSuperAdmin()
            || in_array('RECEPTIONIST', $roles, true)
            || in_array('BUSINESS_MANAGER', $roles, true);
        $isWorker = in_array('STYLIST_WORKSPACE', $roles, true) || in_array('BATHER_WORKSPACE', $roles, true);

        if (!$isManagerOrReception && !$isWorker) {
            $this->forbidden('Access denied.');
        }

        if (!$isManagerOrReception) {
            // Worker can only mark assigned services completed
            $chkAssigned = $this->db->prepare(
                'SELECT 1 FROM appointment_pet ap
                 JOIN performed_service ps ON ps.appointment_pet_id = ap.appointment_pet_id
                 WHERE ap.appointment_id = ? AND ps.performer_employee_id = ? LIMIT 1'
            );
            $chkAssigned->execute([$id, $eid]);
            if (!$chkAssigned->fetchColumn()) {
                $this->forbidden('Access denied: you can only mark completed for services assigned to you.');
            }
        }

        $this->db->prepare(
            "UPDATE performed_service ps
             JOIN appointment_pet ap ON ap.appointment_pet_id = ps.appointment_pet_id
             SET ps.performed_status = 'completed', ps.updated_at = NOW()
             WHERE ap.appointment_id = ?" . (!$isManagerOrReception ? " AND ps.performer_employee_id = ?" : "")
        )->execute(!$isManagerOrReception ? [$id, $eid] : [$id]);

        Session::flash('success', 'Service marked as completed.');
        $this->redirect("/staff/appointments/$id");
    }

    private function get(string $k): string
    {
        $v = $_GET[$k] ?? '';
        return is_string($v) ? trim($v) : '';
    }
}
