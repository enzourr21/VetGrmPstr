<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Single source of truth for Switch Interface visibility and target authorization.
 * Enforces active user_account, active employee, active business_unit, and unit-scoped role checks.
 */
final class InterfaceSwitcherService
{
    public const UNIT_PET_STORE = 1;
    public const UNIT_VET_CLINIC = 2;
    public const UNIT_GROOMING = 3;

    /**
     * Load active account, employee, business unit, and role context for a signed-in user.
     *
     * @return array{
     *   uid:int,
     *   account_active:bool,
     *   is_super_admin:bool,
     *   employee_active:bool,
     *   employee_id:int,
     *   emp_unit_id:int,
     *   emp_unit_name:string,
     *   position:string,
     *   first_name:string,
     *   last_name:string,
     *   active_units:array<int, array{id:int, name:string}>,
     *   roles_by_unit:array<int, array<string, bool>>,
     *   role_rows:array<int, array<string, mixed>>,
     *   sysadmin_units:array<int, int>,
     *   bm_units:array<int, int>,
     *   im_units:array<int, int>
     * }
     */
    public static function loadContext(PDO $db, int $uid): array
    {
        $stmt = $db->prepare(
            "SELECT ua.user_account_id, ua.account_status, ua.is_super_admin,
                    e.employee_id, e.first_name, e.last_name, e.position, e.employee_status,
                    e.business_unit_id AS emp_unit_id,
                    bu.business_unit_name AS emp_unit_name, bu.business_unit_status AS emp_unit_status
             FROM user_account ua
             LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
             LEFT JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
             WHERE ua.user_account_id = ?
             LIMIT 1"
        );
        $stmt->execute([$uid]);
        $uRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $accountActive = !empty($uRow) && (($uRow['account_status'] ?? '') === 'active');
        $isSuperAdmin = $accountActive && self::isSuperAdminAccount($db, $uid);
        if ($accountActive && !$isSuperAdmin && EmployeeInvitationService::isAccountBlockedByUnacceptedInvitation($db, $uid)) {
            $accountActive = false;
        }
        $employeeActive = $accountActive
            && !empty($uRow['employee_id'])
            && (($uRow['employee_status'] ?? '') === 'active');

        $unitStmt = $db->query(
            "SELECT business_unit_id, business_unit_name
             FROM business_unit
             WHERE business_unit_status = 'active'
             ORDER BY business_unit_id ASC"
        );
        $activeUnits = [];
        foreach ($unitStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $bId = (int)$u['business_unit_id'];
            $activeUnits[$bId] = [
                'id' => $bId,
                'name' => (string)$u['business_unit_name'],
            ];
        }

        $roleRows = [];
        $rolesByUnit = [];
        $sysadminUnits = [];
        $bmUnits = [];
        $imUnits = [];

        if ($accountActive && $employeeActive) {
            $roleStmt = $db->prepare(
                "SELECT ar.business_unit_id, bu.business_unit_name, r.role_name
                 FROM account_role ar
                 JOIN role r ON r.role_id = ar.role_id
                 JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id
                                      AND bu.business_unit_status = 'active'
                 WHERE ar.user_account_id = ?
                 ORDER BY ar.business_unit_id ASC, r.role_name ASC"
            );
            $roleStmt->execute([$uid]);
            $roleRows = $roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($roleRows as $r) {
                $bId = (int)$r['business_unit_id'];
                $rName = (string)$r['role_name'];
                $rolesByUnit[$bId][$rName] = true;
                if ($rName === 'SYSTEM_ADMINISTRATOR' && !in_array($bId, $sysadminUnits, true)) {
                    $sysadminUnits[] = $bId;
                } elseif ($rName === 'BUSINESS_MANAGER' && !in_array($bId, $bmUnits, true)) {
                    $bmUnits[] = $bId;
                } elseif ($rName === 'INVENTORY_MANAGER' && !in_array($bId, $imUnits, true)) {
                    $imUnits[] = $bId;
                }
            }
        }

        return [
            'uid' => $uid,
            'account_active' => $accountActive,
            'is_super_admin' => $isSuperAdmin,
            'employee_active' => $employeeActive,
            'employee_id' => (int)($uRow['employee_id'] ?? 0),
            'emp_unit_id' => (int)($uRow['emp_unit_id'] ?? 0),
            'emp_unit_name' => (string)($uRow['emp_unit_name'] ?? ''),
            'position' => trim((string)($uRow['position'] ?? '')),
            'first_name' => trim((string)($uRow['first_name'] ?? '')),
            'last_name' => trim((string)($uRow['last_name'] ?? '')),
            'active_units' => $activeUnits,
            'roles_by_unit' => $rolesByUnit,
            'role_rows' => $roleRows,
            'sysadmin_units' => $sysadminUnits,
            'bm_units' => $bmUnits,
            'im_units' => $imUnits,
        ];
    }

    public static function isSuperAdminAccount(PDO $db, int $uid): bool
    {
        $stmt = $db->prepare(
            "SELECT 1
             FROM user_account ua
             LEFT JOIN account_role ar ON ar.user_account_id = ua.user_account_id
             LEFT JOIN role r ON r.role_id = ar.role_id
             WHERE ua.user_account_id = ?
               AND ua.account_status = 'active'
               AND (ua.is_super_admin = 1 OR r.role_name = 'SUPER_ADMINISTRATOR')
             LIMIT 1"
        );
        $stmt->execute([$uid]);
        return (bool)$stmt->fetchColumn();
    }

    public static function isLeadVetPosition(string $position): bool
    {
        return in_array(strtolower(trim($position)), ['lead veterinarian', 'lead vet'], true);
    }

    public static function hasAnyRoleInUnit(array $ctx, int $unitId, array $roleNames): bool
    {
        if (empty($ctx['employee_active']) || !isset($ctx['active_units'][$unitId])) {
            return false;
        }
        $unitRoles = $ctx['roles_by_unit'][$unitId] ?? [];
        foreach ($roleNames as $roleName) {
            if (!empty($unitRoles[$roleName])) {
                return true;
            }
        }
        return false;
    }

    public static function canAccessDelivery(array $ctx): bool
    {
        if (!empty($ctx['is_super_admin'])) {
            return isset($ctx['active_units'][self::UNIT_PET_STORE]);
        }
        return !empty($ctx['employee_active'])
            && (int)$ctx['emp_unit_id'] === self::UNIT_PET_STORE
            && self::hasAnyRoleInUnit($ctx, self::UNIT_PET_STORE, ['DELIVERY_PERSONNEL']);
    }

    /**
     * Build business-unit-grouped Switch Interface items filtered strictly by authorization.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function buildAuthorizedMenu(
        array $ctx,
        string $curPath,
        string $activeContext,
        bool $isWorkspaceRoute,
        int $currentUnitId,
        bool $isInvMode,
        string $activeWorkspaceInterface
    ): array {
        $isSuper = !empty($ctx['is_super_admin']);
        $activeUnits = $ctx['active_units'];
        $position = (string)($ctx['position'] ?? '');
        $isLeadVet = self::isLeadVetPosition($position);
        $inStaffArea = (str_starts_with($curPath, '/staff') || $curPath === '/workspace') && $activeContext !== 'customer';

        $menu = [];

        if ($isSuper) {
            $menu[] = [
                'id' => 'super-admin',
                'label' => 'Super Administrator',
                'hasUnits' => false,
                'url' => '/switch-interface?target=super-admin',
                'active' => str_starts_with($curPath, '/super-admin') && $activeContext !== 'customer',
            ];
        }

        // 1. Pet Store (business_unit_id = 1)
        if (isset($activeUnits[self::UNIT_PET_STORE])) {
            $petStoreItems = [];
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_PET_STORE, ['BUSINESS_MANAGER'])) {
                $petStoreItems[] = [
                    'id' => self::UNIT_PET_STORE,
                    'key' => 'petstore-bm',
                    'name' => 'Business Manager',
                    'url' => '/switch-interface?target=manager&unit_id=1',
                    'active' => str_starts_with($curPath, '/manager') && $activeContext !== 'customer' && !$isInvMode && $currentUnitId === self::UNIT_PET_STORE,
                ];
            }
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_PET_STORE, ['RECEPTIONIST'])) {
                $petStoreItems[] = [
                    'id' => self::UNIT_PET_STORE,
                    'key' => 'petstore-receptionist',
                    'name' => 'Receptionist',
                    'url' => '/switch-interface?target=petstore-receptionist',
                    'active' => str_starts_with($curPath, '/petstore/receptionist') && $activeContext !== 'customer',
                ];
            }
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_PET_STORE, ['INVENTORY_MANAGER'])) {
                $petStoreItems[] = [
                    'id' => self::UNIT_PET_STORE,
                    'key' => 'petstore-im',
                    'name' => 'Inventory Manager',
                    'url' => '/switch-interface?target=inventory-manager&unit_id=1',
                    'active' => str_starts_with($curPath, '/manager') && $activeContext !== 'customer' && $isInvMode && $currentUnitId === self::UNIT_PET_STORE,
                ];
            }
            if (self::canAccessDelivery($ctx)) {
                $petStoreItems[] = [
                    'id' => self::UNIT_PET_STORE,
                    'key' => 'petstore-delivery',
                    'name' => 'Delivery Personnel',
                    'url' => '/switch-interface?target=delivery',
                    'active' => str_starts_with($curPath, '/delivery') && $activeContext !== 'customer',
                ];
            }

            if ($petStoreItems !== []) {
                $menu[] = self::buildGroup('petstore', 'Pet Store', $petStoreItems);
            }
        }

        // 2. Veterinary Clinic (business_unit_id = 2)
        if (isset($activeUnits[self::UNIT_VET_CLINIC])) {
            $vetItems = [];
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_VET_CLINIC, ['BUSINESS_MANAGER'])) {
                $vetItems[] = [
                    'id' => self::UNIT_VET_CLINIC,
                    'key' => 'vetclinic-bm',
                    'name' => 'Business Manager',
                    'url' => '/switch-interface?target=manager&unit_id=2',
                    'active' => str_starts_with($curPath, '/manager') && $activeContext !== 'customer' && !$isInvMode && $currentUnitId === self::UNIT_VET_CLINIC,
                ];
            }
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_VET_CLINIC, ['RECEPTIONIST'])) {
                $vetItems[] = [
                    'id' => self::UNIT_VET_CLINIC,
                    'key' => 'vetclinic-receptionist',
                    'name' => 'Receptionist',
                    'url' => '/switch-interface?target=vetclinic-receptionist',
                    'active' => str_starts_with($curPath, '/vetclinic/receptionist') && $activeContext !== 'customer',
                ];
            }
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_VET_CLINIC, ['INVENTORY_MANAGER'])) {
                $vetItems[] = [
                    'id' => self::UNIT_VET_CLINIC,
                    'key' => 'vetclinic-im',
                    'name' => 'Inventory Manager',
                    'url' => '/switch-interface?target=inventory-manager&unit_id=2',
                    'active' => str_starts_with($curPath, '/manager') && $activeContext !== 'customer' && $isInvMode && $currentUnitId === self::UNIT_VET_CLINIC,
                ];
            }

            $hasVetRole = self::hasAnyRoleInUnit($ctx, self::UNIT_VET_CLINIC, ['VETERINARIAN_WORKSPACE', 'VETERINARIAN']);
            $hasNurseRole = self::hasAnyRoleInUnit($ctx, self::UNIT_VET_CLINIC, ['VETERINARY_NURSE_WORKSPACE', 'NURSE']);
            $hasAssistantRole = self::hasAnyRoleInUnit($ctx, self::UNIT_VET_CLINIC, ['VETERINARY_TECHNICIAN_WORKSPACE', 'TECHNICIAN']);

            $vetWorkspaceCandidates = [];
            if ($isSuper || ($hasVetRole && $isLeadVet)) {
                $vetWorkspaceCandidates[] = [
                    'target' => 'vetclinic-lead-vet',
                    'name' => 'Lead Vet',
                ];
            }
            if ($isSuper || ($hasVetRole && !$isLeadVet)) {
                $vetWorkspaceCandidates[] = [
                    'target' => 'vetclinic-associate-vet',
                    'name' => 'Associate Vet',
                ];
            }
            if ($isSuper || $hasNurseRole) {
                $vetWorkspaceCandidates[] = [
                    'target' => 'vetclinic-vet-nurse',
                    'name' => 'Vet Nurse',
                ];
            }
            if ($isSuper || $hasAssistantRole) {
                $vetWorkspaceCandidates[] = [
                    'target' => 'vetclinic-vet-assistant',
                    'name' => 'Vet Assistant',
                ];
            }

            $vetWorkspaceMatched = false;
            foreach ($vetWorkspaceCandidates as $cand) {
                $isActive = $inStaffArea && $currentUnitId === self::UNIT_VET_CLINIC && (
                    $activeWorkspaceInterface === $cand['target']
                    || ($activeWorkspaceInterface === '' && !$vetWorkspaceMatched)
                );
                if ($isActive) {
                    $vetWorkspaceMatched = true;
                }
                $vetItems[] = [
                    'id' => self::UNIT_VET_CLINIC,
                    'key' => $cand['target'],
                    'name' => $cand['name'],
                    'url' => '/switch-interface?target=' . $cand['target'],
                    'active' => $isActive,
                ];
            }

            if ($vetItems !== []) {
                $menu[] = self::buildGroup('vetclinic', 'Veterinary Clinic', $vetItems);
            }
        }

        // 3. Pet Grooming (business_unit_id = 3, Grooming Services)
        if (isset($activeUnits[self::UNIT_GROOMING])) {
            $groomingItems = [];
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_GROOMING, ['BUSINESS_MANAGER'])) {
                $groomingItems[] = [
                    'id' => self::UNIT_GROOMING,
                    'key' => 'grooming-bm',
                    'name' => 'Business Manager',
                    'url' => '/switch-interface?target=manager&unit_id=3',
                    'active' => str_starts_with($curPath, '/manager') && $activeContext !== 'customer' && !$isInvMode && $currentUnitId === self::UNIT_GROOMING,
                ];
            }
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_GROOMING, ['RECEPTIONIST'])) {
                $groomingItems[] = [
                    'id' => self::UNIT_GROOMING,
                    'key' => 'grooming-receptionist',
                    'name' => 'Receptionist',
                    'url' => '/switch-interface?target=grooming-receptionist',
                    'active' => str_starts_with($curPath, '/grooming/receptionist') && $activeContext !== 'customer',
                ];
            }
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_GROOMING, ['INVENTORY_MANAGER'])) {
                $groomingItems[] = [
                    'id' => self::UNIT_GROOMING,
                    'key' => 'grooming-im',
                    'name' => 'Inventory Manager',
                    'url' => '/switch-interface?target=inventory-manager&unit_id=3',
                    'active' => str_starts_with($curPath, '/manager') && $activeContext !== 'customer' && $isInvMode && $currentUnitId === self::UNIT_GROOMING,
                ];
            }

            $groomingWorkspaceCandidates = [];
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_GROOMING, ['STYLIST_WORKSPACE'])) {
                $groomingWorkspaceCandidates[] = [
                    'target' => 'grooming-stylist',
                    'name' => 'Stylist',
                ];
            }
            if ($isSuper || self::hasAnyRoleInUnit($ctx, self::UNIT_GROOMING, ['BATHER_WORKSPACE'])) {
                $groomingWorkspaceCandidates[] = [
                    'target' => 'grooming-bather',
                    'name' => 'Bather',
                ];
            }

            $groomWorkspaceMatched = false;
            foreach ($groomingWorkspaceCandidates as $cand) {
                $isActive = $inStaffArea && $currentUnitId === self::UNIT_GROOMING && (
                    $activeWorkspaceInterface === $cand['target']
                    || ($activeWorkspaceInterface === '' && !$groomWorkspaceMatched)
                );
                if ($isActive) {
                    $groomWorkspaceMatched = true;
                }
                $groomingItems[] = [
                    'id' => self::UNIT_GROOMING,
                    'key' => $cand['target'],
                    'name' => $cand['name'],
                    'url' => '/switch-interface?target=' . $cand['target'],
                    'active' => $isActive,
                ];
            }

            if ($groomingItems !== []) {
                $menu[] = self::buildGroup('grooming', 'Pet Grooming', $groomingItems);
            }
        }

        // 4. System Administrator
        $sysAdminDisplayUnits = [
            self::UNIT_PET_STORE => 'Pet Store',
            self::UNIT_VET_CLINIC => 'Veterinary Clinic',
            self::UNIT_GROOMING => 'Pet Groom Service',
        ];
        $sysUnits = [];
        foreach ($sysAdminDisplayUnits as $uId => $uLabel) {
            if (!isset($activeUnits[$uId])) {
                continue;
            }
            if ($isSuper || self::hasAnyRoleInUnit($ctx, $uId, ['SYSTEM_ADMINISTRATOR'])) {
                $sysUnits[] = [
                    'id' => $uId,
                    'key' => 'sysadmin-' . $uId,
                    'name' => $uLabel,
                    'url' => '/switch-interface?target=sysadmin&unit_id=' . $uId,
                    'active' => str_starts_with($curPath, '/sysadmin') && $activeContext !== 'customer' && $currentUnitId === $uId,
                ];
            }
        }
        if ($sysUnits !== []) {
            $menu[] = self::buildGroup('sysadmin', 'System Administrator', $sysUnits);
        }

        // 5. Customer Interface
        $menu[] = [
            'id' => 'customer',
            'label' => 'Customer Interface',
            'hasUnits' => false,
            'url' => '/switch-interface?target=customer',
            'active' => $activeContext === 'customer' || (!$isWorkspaceRoute),
        ];

        return $menu;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private static function buildGroup(string $id, string $label, array $items): array
    {
        $groupActive = false;
        foreach ($items as $item) {
            if (!empty($item['active'])) {
                $groupActive = true;
                break;
            }
        }
        return [
            'id' => $id,
            'label' => $label,
            'hasUnits' => true,
            'units' => $items,
            'active' => $groupActive,
        ];
    }
}
