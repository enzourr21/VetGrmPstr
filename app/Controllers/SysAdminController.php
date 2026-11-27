<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\AdminAccountService;
use App\Models\Audit;
use App\Models\Units;
use App\Services\EmployeeInvitationService;

final class SysAdminController extends Controller
{
    private function unitEmployees(int $unitId): array
    {
        return EmployeeInvitationService::listUnitEmployeesWithInvitationState($this->db, $unitId);
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

    private function isPetOrVet(string $unitName): bool
    {
        return in_array($unitName, [Units::PET_STORE, Units::VET_CLINIC, Units::GROOMING], true);
    }

    private function isPetStore(string $unitName): bool
    {
        return $this->isPetOrVet($unitName);
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
        $assignableRoles = AdminAccountService::assignableRolesForUnit($unitName);

        $counts = EmployeeInvitationService::summarizeUnitEmployeeCounts($employees);
        $activeEmployees = $counts['active_employees'];
        $totalEmployees = $counts['total_employees'];
        $totalRoles = count($assignableRoles);
        $activePositionCount = count(array_filter($positions, fn($p) => $p['position_status'] === 'active'));
        $totalPositionCount = count($positions);
        $sysAdminCount = $counts['active_sysadmin_count'];

        // Administrative Attention items (scoped strictly to current business unit)
        $attentionItems = [];

        // 1. Genuinely pending, unexpired employee invitations from employee_invitation
        $pendingInvites = EmployeeInvitationService::listPendingInvitationsForUnit($this->db, $unitId);
        foreach ($pendingInvites as $inv) {
            $attentionItems[] = [
                'type' => 'pending_invitation',
                'badge' => 'Pending Invitation',
                'badge_class' => 'warn',
                'title' => "Invitation pending for {$inv['first_name']} {$inv['last_name']}",
                'subtitle' => "{$inv['email']} · Assigned position: {$inv['position']}",
                'time' => $inv['created_at'],
                'action_url' => '/sysadmin/employees' . $this->suffix($isSuper, $unitId),
                'action_label' => 'View in Employees',
            ];
        }

        // 2. Inactive employee accounts in this unit
        $inactiveAccounts = $this->db->prepare(
            "SELECT e.employee_id, e.first_name, e.last_name, e.position, ua.email, ua.updated_at
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             WHERE e.business_unit_id = ? AND (ua.account_status = 'inactive' OR e.employee_status = 'inactive')
             ORDER BY ua.updated_at DESC"
        );
        $inactiveAccounts->execute([$unitId]);
        foreach ($inactiveAccounts->fetchAll() as $inact) {
            $attentionItems[] = [
                'type' => 'inactive_account',
                'badge' => 'Inactive Account',
                'badge_class' => 'neutral',
                'title' => "Inactive account: {$inact['first_name']} {$inact['last_name']}",
                'subtitle' => "{$inact['email']} · Position: {$inact['position']}",
                'time' => $inact['updated_at'],
                'action_url' => '/sysadmin/employees' . $this->suffix($isSuper, $unitId),
                'action_label' => 'Review Account',
            ];
        }

        // Recent administrative activity (most recent relevant events from audit_log for this unit)
        $recentActivityData = Audit::activityForUnit($unitId, [], 1, 6);
        $recentActivity = $recentActivityData['rows'];

        $data = $this->employeesData($unitId, $unitName, $isSuper);
        $data['title'] = "$unitName System Administrator · Overview";
        $data['activeTab'] = 'dashboard';
        $data['positions'] = $this->isPetStore($unitName) ? $data['positions'] : $positions;
        $data['activePositionCount'] = $activePositionCount;
        $data['totalPositionCount'] = $totalPositionCount;
        $data['totalEmployees'] = $totalEmployees;
        $data['activeEmployees'] = $activeEmployees;
        $data['totalRoles'] = $totalRoles;
        $data['sysAdminCount'] = $sysAdminCount;
        $data['attentionItems'] = $attentionItems;
        $data['recentActivity'] = $recentActivity;
        $data['recentLogs'] = Audit::recent(10, $unitId);
        $this->render('sysadmin/dashboard', $data);
    }

    /** Render the unit employees page with consistent pagination and view state. */
    private function renderEmployeesPage(int $unitId, string $unitName, bool $isSuper, array $errors = [], array $old = []): void
    {
        $data = $this->employeesData($unitId, $unitName, $isSuper, $errors, $old);
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

    public function employees(?int $routeUnitId = null): void
    {
        [, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin($routeUnitId);
        $this->renderEmployeesPage($unitId, $unitName, $isSuper);
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

        // Automatically assign initial role(s) for position if not already present
        if ($position !== '') {
            $initRoles = AdminAccountService::initialRolesForPosition($position, $unitName);
            foreach (array_reverse($initRoles) as $initRole) {
                if (!in_array($initRole, $rawRoles, true)) {
                    array_unshift($rawRoles, $initRole);
                }
            }
        }

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
            'is_invitation' => true,
        ];

        $res = AdminAccountService::createEmployeeAccount($input, $unitId, $actorUid);
        if (!$res['ok']) {
            if (($res['status'] ?? 422) === 403) {
                $this->forbidden((string)($res['errors']['general'] ?? 'You are not authorized to create employee invitations for this business unit.'));
            }
            http_response_code(422);
            Session::flash('error', 'Could not create employee invitation. Please check the form errors.');
            $oldSafe = $input;
            unset($oldSafe['password']);
            $this->renderEmployeesPage($unitId, $unitName, $isSuper, $res['errors'], $oldSafe);
            return;
        }

        if (!empty($res['email_submitted'])) {
            Session::flash('success', "Pending employee account created and invitation email submitted for {$input['email']}.");
        } else {
            Session::flash('error', "Pending employee account and invitation were created for {$input['email']}, but the invitation email could not be submitted right now. You can retry sending it later.");
        }
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
            $norm = AdminAccountService::normalizeRoleName($rv);
            $rObj = ctype_digit($rv) ? ($allowedById[$rv] ?? null) : ($allowedByName[$norm] ?? ($allowedByName[strtoupper($rv)] ?? null));
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

        if (EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($this->db, (int)$emp['user_account_id'])) {
            http_response_code(409);
            Session::flash('error', 'Account activation status cannot be changed while the employee has an unaccepted invitation. Use invitation actions instead.');
            $this->renderEmployeesPage($unitId, $unitName, $isSuper);
            return;
        }

        $target = $this->post('account_status');
        if (!in_array($target, ['active', 'inactive'], true)) {
            http_response_code(422);
            Session::flash('error', 'Invalid account status.');
            $this->redirect($back);
        }
        $current = (string)$emp['account_status'];
        $currentEmpStatus = (string)$emp['employee_status'];
        if ($current === $target && $currentEmpStatus === $target) {
            Session::flash('info', 'The account already has that status.');
            $this->redirect($back);
        }

        $wasInactive = $current === 'inactive' || $currentEmpStatus === 'inactive';
        if ($target === 'inactive') {
            $code = 'employee.deactivated';
            $verb = 'Deactivated';
        } else {
            $code = $wasInactive ? 'employee.reactivated' : 'employee.activated';
            $verb = $wasInactive ? 'Reactivated' : 'Activated';
        }

        $this->db->beginTransaction();
        try {
            $uaLock = $this->db->prepare('SELECT account_status FROM user_account WHERE user_account_id = ? FOR UPDATE');
            $uaLock->execute([(int)$emp['user_account_id']]);
            $lockedAccountStatus = (string)$uaLock->fetchColumn();

            $empLock = $this->db->prepare('SELECT employee_status FROM employee WHERE employee_id = ? FOR UPDATE');
            $empLock->execute([$employeeId]);
            $lockedEmpStatus = (string)$empLock->fetchColumn();

            if (EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($this->db, (int)$emp['user_account_id'])) {
                $this->db->rollBack();
                http_response_code(409);
                Session::flash('error', 'Account activation status cannot be changed while the employee has an unaccepted invitation. Use invitation actions instead.');
                $this->renderEmployeesPage($unitId, $unitName, $isSuper);
                return;
            }

            if ($lockedAccountStatus === $target && $lockedEmpStatus === $target) {
                $this->db->rollBack();
                Session::flash('info', 'The account already has that status.');
                $this->redirect($back);
            }

            $lockedWasInactive = $lockedAccountStatus === 'inactive' || $lockedEmpStatus === 'inactive';
            $auditOld = ($target === 'active' && $lockedWasInactive) ? 'inactive' : $lockedAccountStatus;

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
                $auditOld,
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

        if (EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($this->db, (int)$emp['user_account_id'])) {
            http_response_code(409);
            Session::flash('error', 'Default position cannot be changed while the employee has an unaccepted invitation.');
            $this->renderEmployeesPage($unitId, $unitName, $isSuper);
            return;
        }

        if ((string)$emp['account_status'] !== 'active' || (string)$emp['employee_status'] !== 'active') {
            http_response_code(409);
            Session::flash('error', 'Inactive employee accounts must be reactivated before changing their default position.');
            $this->renderEmployeesPage($unitId, $unitName, $isSuper);
            return;
        }

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
            $uaLock = $this->db->prepare('SELECT account_status FROM user_account WHERE user_account_id = ? FOR UPDATE');
            $uaLock->execute([(int)$emp['user_account_id']]);
            $lockedAccountStatus = $uaLock->fetchColumn();

            $empLock = $this->db->prepare('SELECT employee_status, position FROM employee WHERE employee_id = ? FOR UPDATE');
            $empLock->execute([$employeeId]);
            $lockedEmp = $empLock->fetch();

            if (EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($this->db, (int)$emp['user_account_id'])) {
                $this->db->rollBack();
                http_response_code(409);
                Session::flash('error', 'Default position cannot be changed while the employee has an unaccepted invitation.');
                $this->renderEmployeesPage($unitId, $unitName, $isSuper);
                return;
            }

            if (
                $lockedAccountStatus !== 'active' ||
                !$lockedEmp ||
                (string)$lockedEmp['employee_status'] !== 'active'
            ) {
                $this->db->rollBack();
                http_response_code(409);
                Session::flash('error', 'Inactive employee accounts must be reactivated before changing their default position.');
                $this->renderEmployeesPage($unitId, $unitName, $isSuper);
                return;
            }

            $oldPos = (string)$lockedEmp['position'];
            if ($oldPos === (string)$matched) {
                $this->db->rollBack();
                Session::flash('info', 'The employee already holds that default position.');
                $this->redirect($back);
            }

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

    /** Resend an employee's pending or expired invitation (home-unit employees only). */
    public function resendEmployeeInvitation(int $id): void
    {
        [$actorUid, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin();
        CSRF::check();

        $emp = $this->verifyEmployeeInUnit($id, $unitId);
        if ((int)$emp['business_unit_id'] !== $unitId) {
            $this->forbidden('Access denied: only employees assigned to this business unit can have invitations managed here.');
        }

        $res = (new EmployeeInvitationService($this->db))->resendInvitation($actorUid, $id, $unitId);
        if (!$res['ok']) {
            $status = (int)($res['status'] ?? 409);
            if ($status === 403) {
                $this->forbidden((string)($res['error'] ?? 'You are not authorized to resend employee invitations for this business unit.'));
            }
            http_response_code($status);
            Session::flash('error', (string)($res['error'] ?? 'Could not resend employee invitation.'));
            $this->renderEmployeesPage($unitId, $unitName, $isSuper);
            return;
        }

        if (!empty($res['email_submitted'])) {
            Session::flash('success', "Replacement invitation created and email submitted for {$emp['email']}.");
        } else {
            Session::flash('error', "Replacement invitation was created for {$emp['email']}, but the invitation email could not be submitted right now. You can retry sending it later.");
        }
        $this->redirect('/sysadmin/employees' . $this->suffix($isSuper, $unitId));
    }

    /** Revoke an employee's pending invitation without deactivating the account (home-unit employees only). */
    public function revokeEmployeeInvitation(int $id): void
    {
        [$actorUid, , $unitId, $unitName, $isSuper] = $this->requireSystemAdmin();
        CSRF::check();

        $emp = $this->verifyEmployeeInUnit($id, $unitId);
        if ((int)$emp['business_unit_id'] !== $unitId) {
            $this->forbidden('Access denied: only employees assigned to this business unit can have invitations managed here.');
        }

        $res = (new EmployeeInvitationService($this->db))->revokeInvitation($actorUid, $id, $unitId, 'admin_revoked');
        if (!$res['ok']) {
            $status = (int)($res['status'] ?? 409);
            if ($status === 403) {
                $this->forbidden((string)($res['error'] ?? 'You are not authorized to revoke employee invitations for this business unit.'));
            }
            http_response_code($status);
            Session::flash('error', (string)($res['error'] ?? 'Could not revoke employee invitation.'));
            $this->renderEmployeesPage($unitId, $unitName, $isSuper);
            return;
        }

        Session::flash('success', "Pending invitation revoked for {$emp['first_name']} {$emp['last_name']} ({$emp['email']}).");
        $this->redirect('/sysadmin/employees' . $this->suffix($isSuper, $unitId));
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
                $this->renderEmployeesPage($unitId, $unitName, $isSuper);
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

    /** Administrative Activity Log (Pet Store & Veterinary Clinic); always scoped to the authorized business unit. */
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
