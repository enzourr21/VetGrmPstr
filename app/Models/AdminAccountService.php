<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use Throwable;

final class AdminAccountService
{
    /** Clinical roles restricted strictly to Veterinary Clinic. */
    public const CLINICAL_ROLES = [
        'VETERINARIAN',
        'NURSE',
        'TECHNICIAN',
        'VETERINARIAN_WORKSPACE',
        'VETERINARY_NURSE_WORKSPACE',
        'VETERINARY_TECHNICIAN_WORKSPACE',
    ];

    /** Three veterinary-specific workspace roles. */
    public const VET_WORKSPACE_ROLES = [
        'VETERINARIAN_WORKSPACE',
        'VETERINARY_NURSE_WORKSPACE',
        'VETERINARY_TECHNICIAN_WORKSPACE',
    ];

    /** Roles that exist only for the Pet Store business unit. */
    public const PET_STORE_ROLES = ['DELIVERY_PERSONNEL'];

    /** Explicit mapping of default position title to designated initial system role name. */
    public const POSITION_INITIAL_ROLE_MAP = [
        // Pet Store
        'System Administrator' => 'SYSTEM_ADMINISTRATOR',
        'Business Manager — Pet Store' => 'BUSINESS_MANAGER',
        'Inventory Manager' => 'INVENTORY_MANAGER',
        'Receptionist' => 'RECEPTIONIST',
        // Veterinary Clinic
        'Veterinarian (DVM)' => 'VETERINARIAN_WORKSPACE',
        'Veterinary Nurse' => 'VETERINARY_NURSE_WORKSPACE',
        'Veterinary Technician' => 'VETERINARY_TECHNICIAN_WORKSPACE',
        'Veterinary Receptionist' => 'RECEPTIONIST',
        'Lead Veterinarian' => 'VETERINARIAN_WORKSPACE',
        'Associate Veterinarian' => 'VETERINARIAN_WORKSPACE',
        'Business Manager — Veterinary Clinic' => 'BUSINESS_MANAGER',
        // Grooming Services
        'Master Pet Stylist & Reception Coordinator' => 'RECEPTIONIST',
        'Business Manager — Grooming Services' => 'BUSINESS_MANAGER',
    ];

    public static function normalizeRoleName(string $role): string
    {
        $role = trim($role);
        $upper = strtoupper(str_replace([' ', '-'], '_', $role));
        return match ($upper) {
            'VETERINARIAN', 'VETERINARIAN_WORKSPACE' => 'VETERINARIAN_WORKSPACE',
            'NURSE', 'VETERINARY_NURSE', 'VETERINARY_NURSE_WORKSPACE' => 'VETERINARY_NURSE_WORKSPACE',
            'TECHNICIAN', 'VETERINARY_TECHNICIAN', 'VETERINARY_TECHNICIAN_WORKSPACE' => 'VETERINARY_TECHNICIAN_WORKSPACE',
            'SYSTEM_ADMINISTRATOR', 'SYSADMIN' => 'SYSTEM_ADMINISTRATOR',
            'BUSINESS_MANAGER' => 'BUSINESS_MANAGER',
            'RECEPTIONIST', 'VETERINARY_RECEPTIONIST' => 'RECEPTIONIST',
            'INVENTORY_MANAGER' => 'INVENTORY_MANAGER',
            'DELIVERY_PERSONNEL' => 'DELIVERY_PERSONNEL',
            default => $upper,
        };
    }

    public static function initialRoleForPosition(string $position, ?string $unitName = null): ?string
    {
        $role = self::POSITION_INITIAL_ROLE_MAP[$position] ?? null;
        if ($role === null) {
            return null;
        }
        if ($unitName !== null) {
            $allowed = self::assignableRolesForUnit($unitName);
            $allowedNames = array_column($allowed, 'role_name');
            if (!in_array($role, $allowedNames, true)) {
                return null;
            }
        }
        return $role;
    }

    /** Assignable roles for employee / System Administrator creation (never SUPER_ADMINISTRATOR). */
    public const ASSIGNABLE_ROLES = [
        'SYSTEM_ADMINISTRATOR',
        'BUSINESS_MANAGER',
        'RECEPTIONIST',
        'VETERINARIAN_WORKSPACE',
        'VETERINARY_NURSE_WORKSPACE',
        'VETERINARY_TECHNICIAN_WORKSPACE',
        'VETERINARIAN',
        'NURSE',
        'TECHNICIAN',
    ];

    public static function assignableRolesForUnit(string $unitName): array
    {
        $db = Database::pdo();
        $rows = $db->query(
            "SELECT role_id, role_name, description
             FROM role
             WHERE role_name <> 'SUPER_ADMINISTRATOR'
             ORDER BY FIELD(role_name,
                'SYSTEM_ADMINISTRATOR',
                'BUSINESS_MANAGER',
                'INVENTORY_MANAGER',
                'RECEPTIONIST',
                'VETERINARIAN_WORKSPACE',
                'VETERINARY_NURSE_WORKSPACE',
                'VETERINARY_TECHNICIAN_WORKSPACE',
                'DELIVERY_PERSONNEL',
                'VETERINARIAN',
                'NURSE',
                'TECHNICIAN'
             ), role_name"
        )->fetchAll();

        if ($unitName === Units::VET_CLINIC) {
            // Veterinary Clinic: retain applicable shared roles (SYSTEM_ADMINISTRATOR, BUSINESS_MANAGER, INVENTORY_MANAGER, RECEPTIONIST)
            // and the three veterinary-specific workspace roles; exclude DELIVERY_PERSONNEL and legacy unaliased roles
            $rows = array_values(array_filter(
                $rows,
                fn(array $r): bool => in_array($r['role_name'], [
                    'SYSTEM_ADMINISTRATOR',
                    'BUSINESS_MANAGER',
                    'INVENTORY_MANAGER',
                    'RECEPTIONIST',
                    'VETERINARIAN_WORKSPACE',
                    'VETERINARY_NURSE_WORKSPACE',
                    'VETERINARY_TECHNICIAN_WORKSPACE',
                ], true)
            ));
        } elseif ($unitName === Units::PET_STORE) {
            $rows = array_values(array_filter(
                $rows,
                fn(array $r): bool => in_array($r['role_name'], [
                    'SYSTEM_ADMINISTRATOR',
                    'BUSINESS_MANAGER',
                    'INVENTORY_MANAGER',
                    'RECEPTIONIST',
                    'DELIVERY_PERSONNEL',
                ], true)
            ));
        } else {
            $rows = array_values(array_filter(
                $rows,
                fn(array $r): bool => in_array($r['role_name'], [
                    'SYSTEM_ADMINISTRATOR',
                    'BUSINESS_MANAGER',
                    'RECEPTIONIST',
                ], true)
            ));
        }
        return $rows;
    }

    public static function positionsForUnit(int $unitId, bool $activeOnly = true): array
    {
        $db = Database::pdo();
        $sql = 'SELECT position_id, business_unit_id, position_name, description, position_status
                FROM business_position
                WHERE business_unit_id = ?';
        if ($activeOnly) {
            $sql .= ' AND position_status = "active"';
        }
        $sql .= ' ORDER BY position_name';
        $stmt = $db->prepare($sql);
        $stmt->execute([$unitId]);
        return $stmt->fetchAll();
    }

    public static function allPositionsByUnit(bool $activeOnly = true): array
    {
        $db = Database::pdo();
        $sql = 'SELECT bp.position_id, bp.business_unit_id, bp.position_name, bp.description, bp.position_status, bu.business_unit_name
                FROM business_position bp
                JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id';
        if ($activeOnly) {
            $sql .= ' WHERE bp.position_status = "active"';
        }
        $sql .= ' ORDER BY bp.business_unit_id, bp.position_name';
        return $db->query($sql)->fetchAll();
    }

    /**
     * Validate and create an employee / System Administrator account atomically inside a DB transaction.
     *
     * @param array $input [full_name, first_name, last_name, email, username, business_unit_id, position, roles (ids or names), password, phone]
     * @param int|null $enforcedUnitId If set (for System Admin), forces and validates business_unit_id === $enforcedUnitId
     * @param int|null $actorUserId User ID performing the creation
     * @return array{ok:bool, errors:array<string,string>, user_id?:int, employee_id?:int}
     */
    public static function createEmployeeAccount(array $input, ?int $enforcedUnitId = null, ?int $actorUserId = null): array
    {
        $db = Database::pdo();
        $errors = [];

        // 1. Parse full name or first_name + last_name
        $fullName = trim((string)($input['full_name'] ?? ''));
        $firstName = trim((string)($input['first_name'] ?? ''));
        $lastName = trim((string)($input['last_name'] ?? ''));

        if ($fullName !== '' && ($firstName === '' || $lastName === '')) {
            $parts = preg_split('/\s+/u', $fullName, 2);
            $firstName = trim($parts[0] ?? '');
            $lastName = trim($parts[1] ?? '');
        } elseif ($fullName === '' && ($firstName !== '' || $lastName !== '')) {
            $fullName = trim($firstName . ' ' . $lastName);
        }

        if ($firstName === '' || $lastName === '') {
            $errors['full_name'] = 'Enter the employee\'s full name (both first and last name).';
        } elseif (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            $errors['full_name'] = 'First and last name must each be at most 100 characters.';
        }

        // 2. Email validation & uniqueness
        $email = strtolower(trim((string)($input['email'] ?? '')));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address (for example, name@vetgrpet.local).';
        } else {
            $chk = $db->prepare('SELECT user_account_id FROM user_account WHERE email = ?');
            $chk->execute([$email]);
            if ($chk->fetchColumn()) {
                $errors['email'] = 'An account with this email address already exists.';
            }
        }

        // 3. Optional username validation & uniqueness
        $usernameRaw = trim((string)($input['username'] ?? ''));
        $username = $usernameRaw !== '' ? strtolower($usernameRaw) : null;
        if ($username !== null) {
            if (!preg_match('/^[a-z0-9_.]{3,60}$/', $username)) {
                $errors['username'] = 'Username must be 3–60 characters using only letters, numbers, underscores, or dots.';
            } else {
                $uChk = $db->prepare('SELECT user_account_id FROM user_account WHERE username = ?');
                $uChk->execute([$username]);
                if ($uChk->fetchColumn()) {
                    $errors['username'] = 'This username is already taken.';
                }
            }
        }

        // 4. Business unit validation
        $unitId = (int)($input['business_unit_id'] ?? 0);
        if ($enforcedUnitId !== null && $unitId !== $enforcedUnitId) {
            $errors['business_unit_id'] = 'You are only authorized to create accounts within your assigned business unit.';
        }
        $unit = $unitId > 0 ? Units::find($unitId) : null;
        if (!$unit) {
            $errors['business_unit_id'] = 'Select a valid business unit (Pet Store, Veterinary Clinic, or Grooming Services).';
        }

        // 5. Default position validation against the assigned business unit
        $position = trim((string)($input['position'] ?? ''));
        if ($position === '' || mb_strlen($position) > 100) {
            $errors['position'] = 'Select a valid default position for the assigned business unit.';
        } elseif ($unit) {
            $posChk = $db->prepare(
                'SELECT position_name FROM business_position WHERE business_unit_id = ? AND position_name = ? AND position_status = "active"'
            );
            $posChk->execute([$unit['business_unit_id'], $position]);
            $matchedPos = $posChk->fetchColumn();
            if (!$matchedPos) {
                $errors['position'] = "The position '$position' is not an active position in {$unit['business_unit_name']}.";
            } else {
                $position = (string)$matchedPos;
            }
        }

        // 6. System roles validation against business unit & non-Super-Admin rule
        $rawRoles = $input['roles'] ?? ($input['role_ids'] ?? []);
        if (!is_array($rawRoles)) {
            $rawRoles = $rawRoles !== '' && $rawRoles !== null ? [$rawRoles] : [];
        }
        $rawRoles = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $rawRoles), fn($v) => $v !== '')));

        // Automatic initial system role assignment during employee creation
        if ($position !== '' && $unit) {
            $initRole = self::initialRoleForPosition($position, $unit['business_unit_name']);
            if ($initRole !== null && !in_array($initRole, $rawRoles, true)) {
                array_unshift($rawRoles, $initRole);
            }
        }

        $resolvedRoleIds = [];
        $resolvedRoleNames = [];
        if (!$rawRoles) {
            $errors['roles'] = 'Select at least one system role for this account.';
        } else {
            $allRoles = $db->query('SELECT role_id, role_name FROM role')->fetchAll();
            $byId = [];
            $byName = [];
            foreach ($allRoles as $r) {
                $byId[(string)$r['role_id']] = $r;
                $byName[strtoupper((string)$r['role_name'])] = $r;
            }
            foreach ($rawRoles as $rv) {
                $norm = self::normalizeRoleName($rv);
                $rObj = ctype_digit($rv) ? ($byId[$rv] ?? null) : ($byName[$norm] ?? ($byName[strtoupper($rv)] ?? null));
                if (!$rObj) {
                    $errors['roles'] = 'One or more selected roles do not exist.';
                    break;
                }
                if ($rObj['role_name'] === 'SUPER_ADMINISTRATOR') {
                    $errors['roles'] = 'Cannot assign the Super Administrator role through employee account creation.';
                    break;
                }
                if ($unit && $unit['business_unit_name'] !== Units::VET_CLINIC && in_array($rObj['role_name'], self::CLINICAL_ROLES, true)) {
                    $errors['roles'] = "The clinical role {$rObj['role_name']} can only be assigned in the Veterinary Clinic business unit.";
                    break;
                }
                if ($unit && $unit['business_unit_name'] !== Units::PET_STORE && in_array($rObj['role_name'], self::PET_STORE_ROLES, true)) {
                    $errors['roles'] = "The role {$rObj['role_name']} can only be assigned in the Pet Store business unit.";
                    break;
                }
                if ($unit && $unit['business_unit_name'] === Units::VET_CLINIC && $rObj['role_name'] === 'DELIVERY_PERSONNEL') {
                    $errors['roles'] = "The DELIVERY_PERSONNEL role cannot be assigned in the Veterinary Clinic business unit.";
                    break;
                }
                $resolvedRoleIds[] = (int)$rObj['role_id'];
                $resolvedRoleNames[] = (string)$rObj['role_name'];
            }
            $resolvedRoleIds = array_values(array_unique($resolvedRoleIds));
            $resolvedRoleNames = array_values(array_unique($resolvedRoleNames));
        }

        // 7. Password validation (skipped for invitation flow)
        $isInvitation = !empty($input['is_invitation']) || ($unit && in_array($unit['business_unit_name'], [Units::PET_STORE, Units::VET_CLINIC], true) && !isset($input['password']));
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if (!$isInvitation) {
            if (strlen($password) < 10) {
                $errors['password'] = 'Password must be at least 10 characters long.';
            } elseif (strlen($password) > 200) {
                $errors['password'] = 'Password must be at most 200 characters long.';
            }
        }

        // Optional phone validation
        $phone = trim((string)($input['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $phone)) {
            $errors['phone'] = 'Enter a valid phone number.';
        }

        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }

        // 8. Atomic multi-table creation in a transaction
        try {
            $db->beginTransaction();

            if ($isInvitation) {
                // Secure unguessable random password hash; account cannot be accessed until password set via invitation
                $randomBytes = bin2hex(random_bytes(32));
                $hash = password_hash($randomBytes, PASSWORD_DEFAULT);
                $emailVerified = null;
                $invitationToken = bin2hex(random_bytes(32));
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $emailVerified = date('Y-m-d H:i:s');
                $invitationToken = null;
            }

            $insUa = $db->prepare(
                "INSERT INTO user_account (email, username, password_hash, account_status, is_super_admin, email_verified_at)
                 VALUES (?, ?, ?, 'active', 0, ?)"
            );
            $insUa->execute([$email, $username, $hash, $emailVerified]);
            $uid = (int)$db->lastInsertId();

            $insEmp = $db->prepare(
                "INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, phone, employee_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active')"
            );
            $insEmp->execute([$uid, $unit['business_unit_id'], $firstName, $lastName, $position, $phone !== '' ? $phone : null]);
            $eid = (int)$db->lastInsertId();

            // Also create linked customer record so username login / customer interface context works seamlessly
            $insCust = $db->prepare(
                "INSERT INTO customer (user_account_id, first_name, last_name, phone, customer_status)
                 VALUES (?, ?, ?, ?, 'active')"
            );
            $insCust->execute([$uid, $firstName, $lastName, $phone !== '' ? $phone : null]);

            $insRole = $db->prepare(
                'INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)'
            );
            foreach ($resolvedRoleIds as $rid) {
                $insRole->execute([$uid, $rid, $unit['business_unit_id']]);
            }

            $roleListStr = implode(', ', $resolvedRoleNames);
            $actionCode = in_array('SYSTEM_ADMINISTRATOR', $resolvedRoleNames, true) ? 'sysadmin.created' : 'employee.created';
            $summary = "Created employee account $firstName $lastName ($email) in {$unit['business_unit_name']} as '$position' with role(s): $roleListStr.";

            $db->prepare(
                'INSERT INTO audit_log (actor_user_id, business_unit_id, action_code, target_type, target_id, affected_employee_id, summary, new_value, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $actorUserId,
                $unit['business_unit_id'],
                $actionCode,
                'employee',
                $eid,
                $eid,
                mb_substr($summary, 0, 500),
                mb_substr("Position: $position; Roles: $roleListStr", 0, 255),
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]);

            if ($isInvitation) {
                $invSummary = "Sent invitation link to $email for position '$position' with role(s): $roleListStr.";
                $db->prepare(
                    'INSERT INTO audit_log (actor_user_id, business_unit_id, action_code, target_type, target_id, affected_employee_id, summary, new_value, ip_address)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $actorUserId,
                    $unit['business_unit_id'],
                    'invitation.created',
                    'employee',
                    $eid,
                    $eid,
                    mb_substr($invSummary, 0, 500),
                    mb_substr("Position: $position; Roles: $roleListStr", 0, 255),
                    substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                ]);
            }

            $db->commit();
            return [
                'ok' => true,
                'errors' => [],
                'user_id' => $uid,
                'employee_id' => $eid,
                'is_invitation' => $isInvitation,
                'invitation_token' => $invitationToken,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return [
                'ok' => false,
                'errors' => ['general' => 'Could not save the account. No partial records were written.'],
            ];
        }
    }
}

