<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\AdminAccountService;
use App\Models\Audit;
use App\Models\Units;

final class SysAdminController extends Controller
{
    private function unitEmployees(int $unitId): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status, e.created_at,
                    e.business_unit_id AS home_unit_id,
                    ua.user_account_id, ua.email, ua.username, ua.account_status, ua.is_super_admin,
                    (SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ')
                     FROM account_role ar2
                     JOIN role r ON r.role_id = ar2.role_id
                     WHERE ar2.user_account_id = ua.user_account_id AND ar2.business_unit_id = ?) AS unit_roles
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             WHERE ua.is_super_admin = 0
               AND (e.business_unit_id = ? OR EXISTS (
                    SELECT 1 FROM account_role ar WHERE ar.user_account_id = ua.user_account_id AND ar.business_unit_id = ?
                ))
             ORDER BY e.employee_id DESC"
        );
        $stmt->execute([$unitId, $unitId, $unitId]);
        return $stmt->fetchAll();
    }

    /**
     * Verify that the target employee belongs to $unitId and is not a Super Administrator.
     * Enforces backend business-unit isolation (403 Forbidden if cross-unit).
     */
    private function verifyEmployeeInUnit(int $employeeId, int $unitId): array
    {
        $stmt = $this->db->prepare(
            "SELECT e.employee_id, e.user_account_id, e.business_unit_id, e.first_name, e.last_name, e.position, e.employee_status,
                    ua.email, ua.account_status, ua.is_super_admin
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             WHERE e.employee_id = ?"
        );
        $stmt->execute([$employeeId]);
        $emp = $stmt->fetch();
        if (!$emp) {
            $this->notFound();
        }
        if ((bool)$emp['is_super_admin']) {
            $this->forbidden('System Administrators cannot modify the Super Administrator account.');
        }

        $roleInUnit = $this->db->prepare(
            'SELECT 1 FROM account_role WHERE user_account_id = ? AND business_unit_id = ? LIMIT 1'
        );
        $roleInUnit->execute([(int)$emp['user_account_id'], $unitId]);
        $belongs = ((int)$emp['business_unit_id'] === $unitId) || (bool)$roleInUnit->fetchColumn();

        if (!$belongs) {
            $this->forbidden('Access denied: you cannot manage an employee belonging to another business unit.');
        }
        return $emp;
    }

    private function isPetStore(string $unitName): bool
    {
        return $unitName === Units::PET_STORE;
    }

    private function suffix(bool $isSuper, int $unitId): string
    {
        return $isSuper ? '?unit_id=' . $unitId : '';
    }

    /** Shared view data for the employees page (and the Pet Store dashboard employee form). */
    private function employeesData(int $unitId, string $unitName, bool $isSuper, array $errors = [], array $old = []): array
    {
        $all = $this->unitEmployees($unitId);
        return [
            'title' => "Employees · $unitName System Admin",
            'activeTab' => 'employees',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'isPetStore' => $this->isPetStore($unitName),
            'allUnits' => $isSuper ? Units::all() : [],
            'allEmployees' => $all,
            'employees' => $all,
            'positions' => AdminAccountService::positionsForUnit($unitId, true),
            'allPositions' => AdminAccountService::positionsForUnit($unitId, false),
            'assignableRoles' => AdminAccountService::assignableRolesForUnit($unitName),
            'errors' => $errors,
            'old' => $old,
        ];
    }

    public function dashboard(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin($routeUnitId);
        $employees = $this->unitEmployees($unitId);
        $positions = AdminAccountService::positionsForUnit($unitId, false);

        $sysAdminCount = 0;
        foreach ($employees as $e) {
            if (str_contains((string)$e['unit_roles'], 'SYSTEM_ADMINISTRATOR') && $e['account_status'] === 'active') {
                $sysAdminCount++;
            }
        }

        $data = $this->employeesData($unitId, $unitName, $isSuper);
        $data['title'] = "$unitName · System Administrator";
        $data['activeTab'] = 'dashboard';
        $data['positions'] = $this->isPetStore($unitName) ? $data['positions'] : $positions;
        $data['activePositionCount'] = count(array_filter($positions, fn($p) => $p['position_status'] === 'active'));
        $data['totalPositionCount'] = count($positions);
        $data['sysAdminCount'] = $sysAdminCount;
        $data['recentLogs'] = Audit::recent(10, $unitId);
        $this->render('sysadmin/dashboard', $data);
    }

    public function employees(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin($routeUnitId);
        $data = $this->employeesData($unitId, $unitName, $isSuper);
        if ($this->isPetStore($unitName)) {
            $perPage = 6;
            $all = $data['allEmployees'];
            $totalEmployees = count($all);
            $totalPages = max(1, (int)ceil($totalEmployees / $perPage));
            $currentPage = max(1, (int)($_GET['page'] ?? 1));
            if ($currentPage > $totalPages) {
                $currentPage = $totalPages;
            }
            $offset = ($currentPage - 1) * $perPage;
            $data['currentPage'] = $currentPage;
            $data['totalPages'] = $totalPages;
            $data['perPage'] = $perPage;
            $data['totalEmployees'] = $totalEmployees;
            $data['employees'] = array_slice($all, $offset, $perPage);
        }
        $this->render('sysadmin/employees', $data);
    }

    public function storeEmployee(?int $routeUnitId = null): void
    {
        [$actorUid, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin($routeUnitId);
        CSRF::check();

        $rawRoles = $_POST['roles'] ?? ($_POST['role_ids'] ?? []);
        if (!is_array($rawRoles)) {
            $rawRoles = $rawRoles !== '' ? [$rawRoles] : [];
        }
        $position = $this->post('position');

        // Automatically assign initial role for position if not already present
        if ($position !== '') {
            $initRole = AdminAccountService::initialRoleForPosition($position, $unitName);
            if ($initRole !== null && !in_array($initRole, $rawRoles, true)) {
                array_unshift($rawRoles, $initRole);
            }
        }

        $isPetStore = $this->isPetStore($unitName);
        $input = [
            'full_name' => $this->post('full_name'),
            'first_name' => $this->post('first_name'),
            'last_name' => $this->post('last_name'),
            'email' => $this->post('email'),
            'username' => $this->post('username'),
            'business_unit_id' => $unitId,
            'position' => $position,
            'roles' => $rawRoles,
            'phone' => $this->post('phone'),
            'is_invitation' => $isPetStore,
        ];
        if (!$isPetStore && isset($_POST['password'])) {
            $input['password'] = $_POST['password'];
        }

        $res = AdminAccountService::createEmployeeAccount($input, $unitId, $actorUid);
        if (!$res['ok']) {
            http_response_code(422);
            Session::flash('error', $isPetStore ? 'Could not send employee invitation. Please check the form errors.' : 'Could not create employee account. Please check the form errors.');
            $oldSafe = $input;
            unset($oldSafe['password']);
            $data = $this->employeesData($unitId, $unitName, $isSuper, $res['errors'], $oldSafe);
            if ($isPetStore) {
                $perPage = 6;
                $all = $data['allEmployees'];
                $totalEmployees = count($all);
                $totalPages = max(1, (int)ceil($totalEmployees / $perPage));
                $currentPage = max(1, (int)($_GET['page'] ?? 1));
                if ($currentPage > $totalPages) {
                    $currentPage = $totalPages;
                }
                $offset = ($currentPage - 1) * $perPage;
                $data['currentPage'] = $currentPage;
                $data['totalPages'] = $totalPages;
                $data['perPage'] = $perPage;
                $data['totalEmployees'] = $totalEmployees;
                $data['employees'] = array_slice($all, $offset, $perPage);
            }
            $this->render('sysadmin/employees', $data);
            return;
        }

        Session::flash('success', $isPetStore ? "Invitation link sent to {$input['email']}." : "Employee account created in $unitName.");
        $this->redirect('/sysadmin/employees' . $this->suffix($isSuper, $unitId));
    }

    public function updateEmployeeRoles(int $id): void
    {
        $employeeId = $id;
        [$actorUid, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin();
        CSRF::check();

        $emp = $this->verifyEmployeeInUnit($employeeId, $unitId);
        $targetUid = (int)$emp['user_account_id'];

        $rawRoles = $_POST['roles'] ?? [];
        if (!is_array($rawRoles)) {
            $rawRoles = $rawRoles !== '' ? [$rawRoles] : [];
        }
        $rawRoles = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $rawRoles), fn($v) => $v !== '')));

        if (!$rawRoles) {
            http_response_code(422);
            Session::flash('error', 'Select at least one role for the employee within this business unit.');
            $this->redirect('/sysadmin/roles' . $this->suffix($isSuper, $unitId));
        }

        $allowedRoles = AdminAccountService::assignableRolesForUnit($unitName);
        $allowedByName = [];
        $allowedById = [];
        foreach ($allowedRoles as $ar) {
            $allowedByName[strtoupper((string)$ar['role_name'])] = $ar;
            $allowedById[(string)$ar['role_id']] = $ar;
        }

        $resolvedIds = [];
        $resolvedNames = [];
        foreach ($rawRoles as $rv) {
            if (strtoupper($rv) === 'SUPER_ADMINISTRATOR') {
                $this->forbidden('System Administrators cannot grant Super Administrator access.');
            }
            $rObj = ctype_digit($rv) ? ($allowedById[$rv] ?? null) : ($allowedByName[strtoupper($rv)] ?? null);
            if (!$rObj) {
                http_response_code(422);
                Session::flash('error', "Role '$rv' is not valid for $unitName.");
                $this->redirect('/sysadmin/roles' . $this->suffix($isSuper, $unitId));
            }
            $resolvedIds[] = (int)$rObj['role_id'];
            $resolvedNames[] = (string)$rObj['role_name'];
        }
        $resolvedNames = array_values(array_unique($resolvedNames));
        sort($resolvedNames);

        $this->db->beginTransaction();
        try {
            $cur = $this->db->prepare(
                'SELECT r.role_name FROM account_role ar JOIN role r ON r.role_id = ar.role_id
                 WHERE ar.user_account_id = ? AND ar.business_unit_id = ? ORDER BY r.role_name'
            );
            $cur->execute([$targetUid, $unitId]);
            $oldNames = $cur->fetchAll(\PDO::FETCH_COLUMN);

            $added = array_values(array_diff($resolvedNames, $oldNames));
            $removed = array_values(array_diff($oldNames, $resolvedNames));

            if ($added || $removed) {
                $this->db->prepare('DELETE FROM account_role WHERE user_account_id = ? AND business_unit_id = ?')
                    ->execute([$targetUid, $unitId]);
                $ins = $this->db->prepare('INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)');
                foreach (array_unique($resolvedIds) as $rid) {
                    $ins->execute([$targetUid, $rid, $unitId]);
                }

                $who = "{$emp['first_name']} {$emp['last_name']}";
                $oldList = $oldNames ? implode(', ', $oldNames) : 'none';
                $newList = implode(', ', $resolvedNames);
                foreach ($added as $name) {
                    Audit::record($this->db, 'role.assigned', "Assigned role $name to $who in $unitName.", $unitId, $actorUid, $employeeId, $oldList, $newList, 'employee', $employeeId);
                }
                foreach ($removed as $name) {
                    Audit::record($this->db, 'role.removed', "Removed role $name from $who in $unitName.", $unitId, $actorUid, $employeeId, $oldList, $newList, 'employee', $employeeId);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash('success', ($added || $removed)
            ? "Updated roles for {$emp['first_name']} {$emp['last_name']} in $unitName."
            : 'No role changes were made.');
        $this->redirect('/sysadmin/roles' . $this->suffix($isSuper, $unitId));
    }

    /** Activate / deactivate an employee account (home-unit employees only). */
    public function updateEmployeeStatus(int $id): void
    {
        $employeeId = $id;
        [$actorUid, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin();
        CSRF::check();

        $emp = $this->verifyEmployeeInUnit($employeeId, $unitId);
        if ((int)$emp['business_unit_id'] !== $unitId) {
            $this->forbidden('Access denied: only employees assigned to this business unit can be activated or deactivated here.');
        }
        $back = '/sysadmin/employees' . $this->suffix($isSuper, $unitId);

        if ((int)$emp['user_account_id'] === $actorUid) {
            http_response_code(422);
            Session::flash('error', 'You cannot change the status of your own account.');
            $this->redirect($back);
        }

        $target = $this->post('account_status');
        if (!in_array($target, ['active', 'inactive'], true)) {
            http_response_code(422);
            Session::flash('error', 'Invalid account status.');
            $this->redirect($back);
        }
        $current = (string)$emp['account_status'];
        if ($current === $target) {
            Session::flash('info', 'The account already has that status.');
            $this->redirect($back);
        }

        if ($target === 'inactive') {
            $code = 'employee.deactivated';
            $verb = 'Deactivated';
        } else {
            $code = $current === 'inactive' ? 'employee.reactivated' : 'employee.activated';
            $verb = $current === 'inactive' ? 'Reactivated' : 'Activated';
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE user_account SET account_status = ? WHERE user_account_id = ?')
                ->execute([$target, (int)$emp['user_account_id']]);
            $this->db->prepare('UPDATE employee SET employee_status = ? WHERE employee_id = ?')
                ->execute([$target, $employeeId]);
            Audit::record(
                $this->db,
                $code,
                "$verb account of {$emp['first_name']} {$emp['last_name']} ({$emp['email']}) in $unitName.",
                $unitId,
                $actorUid,
                $employeeId,
                $current,
                $target,
                'employee',
                $employeeId
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash('success', "$verb account for {$emp['first_name']} {$emp['last_name']}.");
        $this->redirect($back);
    }

    /** Change an employee's default position (must be an active position of the same unit). */
    public function updateEmployeePosition(int $id): void
    {
        $employeeId = $id;
        [$actorUid, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin();
        CSRF::check();

        $emp = $this->verifyEmployeeInUnit($employeeId, $unitId);
        if ((int)$emp['business_unit_id'] !== $unitId) {
            $this->forbidden('Access denied: only employees assigned to this business unit can be changed here.');
        }
        $back = '/sysadmin/employees' . $this->suffix($isSuper, $unitId);

        $newPos = $this->post('position');
        $chk = $this->db->prepare('SELECT position_name FROM business_position WHERE business_unit_id = ? AND position_name = ? AND position_status = "active"');
        $chk->execute([$unitId, $newPos]);
        $matched = $chk->fetchColumn();
        if (!$matched) {
            http_response_code(422);
            Session::flash('error', "'$newPos' is not an active position in $unitName.");
            $this->redirect($back);
        }
        $oldPos = (string)$emp['position'];
        if ($oldPos === (string)$matched) {
            Session::flash('info', 'The employee already holds that default position.');
            $this->redirect($back);
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE employee SET position = ? WHERE employee_id = ?')->execute([$matched, $employeeId]);
            Audit::record(
                $this->db,
                'employee.position_changed',
                "Changed default position of {$emp['first_name']} {$emp['last_name']} in $unitName.",
                $unitId,
                $actorUid,
                $employeeId,
                $oldPos,
                (string)$matched,
                'employee',
                $employeeId
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash('success', "Default position updated for {$emp['first_name']} {$emp['last_name']}.");
        $this->redirect($back);
    }

    public function positions(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin($routeUnitId);
        if ($this->isPetStore($unitName)) {
            // Pet Store: position management lives in the employee administration area.
            $this->redirect('/sysadmin/employees' . $this->suffix($isSuper, $unitId));
        }
        $this->render('sysadmin/positions', [
            'title' => "Positions · $unitName System Admin",
            'activeTab' => 'positions',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'isPetStore' => false,
            'allUnits' => $isSuper ? Units::all() : [],
            'positions' => AdminAccountService::positionsForUnit($unitId, false),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function storePosition(?int $routeUnitId = null): void
    {
        [$actorUid, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin($routeUnitId);
        CSRF::check();

        $name = $this->post('position_name');
        $desc = $this->post('description');
        $errors = [];
        $petStore = $this->isPetStore($unitName);

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['position_name'] = 'Enter a position title (1–100 characters).';
        }
        if (mb_strlen($desc) > 255) {
            $errors['description'] = 'Description must be at most 255 characters.';
        }

        if (!$errors) {
            $dup = $this->db->prepare('SELECT position_id FROM business_position WHERE business_unit_id = ? AND position_name = ?');
            $dup->execute([$unitId, $name]);
            if ($dup->fetchColumn()) {
                $errors['position_name'] = "Position '$name' already exists in $unitName.";
            }
        }

        if ($errors) {
            http_response_code(422);
            if ($petStore) {
                Session::flash('error', implode(' ', $errors));
                $this->render('sysadmin/employees', $this->employeesData($unitId, $unitName, $isSuper, [], []));
                return;
            }
            $this->render('sysadmin/positions', [
                'title' => "Positions · $unitName System Admin",
                'activeTab' => 'positions',
                'unitId' => $unitId,
                'unitName' => $unitName,
                'isSuper' => $isSuper,
                'isPetStore' => false,
                'allUnits' => $isSuper ? Units::all() : [],
                'positions' => AdminAccountService::positionsForUnit($unitId, false),
                'errors' => $errors,
                'old' => ['position_name' => $name, 'description' => $desc],
            ]);
            return;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO business_position (business_unit_id, position_name, description, position_status) VALUES (?, ?, ?, "active")'
            )->execute([$unitId, $name, $desc !== '' ? $desc : null]);
            $pid = (int)$this->db->lastInsertId();
            Audit::record($this->db, 'sysadmin.position_created', "Created position '$name' in $unitName.", $unitId, $actorUid, null, null, 'active', 'business_position', $pid);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash('success', "Position '$name' added to $unitName.");
        $this->redirect(($petStore ? '/sysadmin/employees' : '/sysadmin/positions') . $this->suffix($isSuper, $unitId));
    }

    public function togglePosition(int $id): void
    {
        $positionId = $id;
        [$actorUid, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin();
        CSRF::check();

        $stmt = $this->db->prepare('SELECT * FROM business_position WHERE position_id = ?');
        $stmt->execute([$positionId]);
        $pos = $stmt->fetch() ?: $this->notFound();

        if ((int)$pos['business_unit_id'] !== $unitId) {
            $this->forbidden('Access denied: cannot modify a position belonging to another business unit.');
        }

        $next = $pos['position_status'] === 'active' ? 'inactive' : 'active';

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE business_position SET position_status = ? WHERE position_id = ? AND business_unit_id = ?')
                ->execute([$next, $positionId, $unitId]);
            Audit::record(
                $this->db,
                'sysadmin.position_toggled',
                "Position '{$pos['position_name']}' in $unitName set to $next.",
                $unitId,
                $actorUid,
                null,
                (string)$pos['position_status'],
                $next,
                'business_position',
                $positionId
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        Session::flash('success', "Position '{$pos['position_name']}' is now $next.");
        $this->redirect(($this->isPetStore($unitName) ? '/sysadmin/employees' : '/sysadmin/positions') . $this->suffix($isSuper, $unitId));
    }

    public function roles(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin($routeUnitId);
        $assignableRoles = AdminAccountService::assignableRolesForUnit($unitName);

        // Attach permission descriptions to each role
        $rolePermsStmt = $this->db->prepare(
            "SELECT p.permission_code, p.description
             FROM role_permission rp
             JOIN permission p ON p.permission_id = rp.permission_id
             WHERE rp.role_id = ?
             ORDER BY p.permission_code"
        );
        foreach ($assignableRoles as &$r) {
            $rolePermsStmt->execute([(int)$r['role_id']]);
            $r['permissions'] = $rolePermsStmt->fetchAll();
        }
        unset($r);

        $this->render('sysadmin/roles', [
            'title' => "Roles & Permissions · $unitName System Admin",
            'activeTab' => 'roles',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'isPetStore' => $this->isPetStore($unitName),
            'allUnits' => $isSuper ? Units::all() : [],
            'assignableRoles' => $assignableRoles,
            'employees' => $this->unitEmployees($unitId),
        ]);
    }

    /** Administrative Activity Log (Pet Store only); always scoped to the authorized business unit. */
    public function activity(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin($routeUnitId);
        if (!$this->isPetStore($unitName)) {
            $this->notFound();
        }

        $filters = [
            'type' => isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '',
            'employee_id' => isset($_GET['employee_id']) && ctype_digit((string)$_GET['employee_id']) ? (int)$_GET['employee_id'] : 0,
            'date_from' => isset($_GET['date_from']) && is_string($_GET['date_from']) ? $_GET['date_from'] : '',
            'date_to' => isset($_GET['date_to']) && is_string($_GET['date_to']) ? $_GET['date_to'] : '',
        ];
        $page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? (int)$_GET['page'] : 1;

        $this->render('sysadmin/activity', [
            'title' => "Activity Log · $unitName System Admin",
            'activeTab' => 'activity',
            'unitId' => $unitId,
            'unitName' => $unitName,
            'isSuper' => $isSuper,
            'isPetStore' => true,
            'allUnits' => $isSuper ? Units::all() : [],
            'filters' => $filters,
            'result' => Audit::activityForUnit($unitId, $filters, $page, 15),
            'employeeOptions' => $this->unitEmployees($unitId),
        ]);
    }
}
