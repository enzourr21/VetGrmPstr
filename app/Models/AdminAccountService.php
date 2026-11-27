<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Services\EmployeeInvitationService;
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

    /** Workspace roles specific to Grooming Services. */
    public const GROOMING_WORKSPACE_ROLES = [
        'STYLIST_WORKSPACE',
        'BATHER_WORKSPACE',
    ];

    /** Explicit mapping of default position title to designated initial system role name(s). */
    public const POSITION_INITIAL_ROLE_MAP = [
        // Pet Store
        'System Administrator' => 'SYSTEM_ADMINISTRATOR',
        'Pet Store System Administrator' => 'SYSTEM_ADMINISTRATOR',
        'Veterinary Clinic System Administrator' => 'SYSTEM_ADMINISTRATOR',
        'Grooming Services System Administrator' => 'SYSTEM_ADMINISTRATOR',
        'Business Manager — Pet Store' => 'BUSINESS_MANAGER',
        'Inventory Manager' => 'INVENTORY_MANAGER',
        'Receptionist' => 'RECEPTIONIST',
        'Delivery Personnel' => 'DELIVERY_PERSONNEL',
        // Veterinary Clinic
        'Veterinarian (DVM)' => 'VETERINARIAN_WORKSPACE',
        'Veterinary Nurse' => 'VETERINARY_NURSE_WORKSPACE',
        'Assistant Nurse' => 'VETERINARY_NURSE_WORKSPACE',
        'Veterinary Technician' => 'VETERINARY_TECHNICIAN_WORKSPACE',
        'Veterinary Receptionist' => 'RECEPTIONIST',
        'Lead Veterinarian' => 'VETERINARIAN_WORKSPACE',
        'Associate Veterinarian' => 'VETERINARIAN_WORKSPACE',
        'Business Manager — Veterinary Clinic' => 'BUSINESS_MANAGER',
        // Grooming Services
        'Business Manager — Grooming Services' => 'BUSINESS_MANAGER',
        'Inventory Manager' => 'INVENTORY_MANAGER',
        'Receptionist' => 'RECEPTIONIST',
        'Stylist' => 'STYLIST_WORKSPACE',
        'Bather' => 'BATHER_WORKSPACE',
        'Master Stylist' => ['STYLIST_WORKSPACE', 'BATHER_WORKSPACE'],
    ];

    public static function systemAdminPositionForUnit(string $unitName): string
    {
        return match ($unitName) {
            Units::PET_STORE => 'Pet Store System Administrator',
            Units::VET_CLINIC => 'Veterinary Clinic System Administrator',
            Units::GROOMING => 'Grooming Services System Administrator',
            default => trim($unitName) !== '' ? trim($unitName) . ' System Administrator' : 'System Administrator',
        };
    }

    public static function isValidEmail(string $email): bool
    {
        if ($email === '' || strlen($email) > 255) {
            return false;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        return preg_match('/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i', $email) === 1;
    }

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
            'STYLIST', 'STYLIST_WORKSPACE' => 'STYLIST_WORKSPACE',
            'BATHER', 'BATHER_WORKSPACE' => 'BATHER_WORKSPACE',
            default => $upper,
        };
    }

    public static function initialRolesForPosition(string $position, ?string $unitName = null, ?PDO $db = null): array
    {
        $roles = self::POSITION_INITIAL_ROLE_MAP[$position] ?? null;
        if ($roles === null) {
            return [];
        }
        $roles = is_array($roles) ? $roles : [$roles];
        if ($unitName !== null) {
            $allowed = self::assignableRolesForUnit($unitName, $db);
            $allowedNames = array_column($allowed, 'role_name');
            $roles = array_values(array_filter($roles, fn($r) => in_array($r, $allowedNames, true)));
        }
        return $roles;
    }

    public static function initialRoleForPosition(string $position, ?string $unitName = null, ?PDO $db = null): ?string
    {
        $roles = self::initialRolesForPosition($position, $unitName, $db);
        return $roles[0] ?? null;
    }

    /** Assignable roles for employee / System Administrator creation (never SUPER_ADMINISTRATOR). */
    public const ASSIGNABLE_ROLES = [
        'SYSTEM_ADMINISTRATOR',
        'BUSINESS_MANAGER',
        'INVENTORY_MANAGER',
        'RECEPTIONIST',
        'DELIVERY_PERSONNEL',
        'VETERINARIAN_WORKSPACE',
        'VETERINARY_NURSE_WORKSPACE',
        'VETERINARY_TECHNICIAN_WORKSPACE',
        'STYLIST_WORKSPACE',
        'BATHER_WORKSPACE',
        'VETERINARIAN',
        'NURSE',
        'TECHNICIAN',
    ];

    public static function assignableRolesForUnit(string $unitName, ?PDO $db = null): array
    {
        $db = $db ?? Database::pdo();
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
                'STYLIST_WORKSPACE',
                'BATHER_WORKSPACE',
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
        } elseif ($unitName === Units::GROOMING) {
            $rows = array_values(array_filter(
                $rows,
                fn(array $r): bool => in_array($r['role_name'], [
                    'SYSTEM_ADMINISTRATOR',
                    'BUSINESS_MANAGER',
                    'INVENTORY_MANAGER',
                    'RECEPTIONIST',
                    'STYLIST_WORKSPACE',
                    'BATHER_WORKSPACE',
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
     * Invitation provisioning is used by unit-scoped System Administrator interface flows
     * ($enforcedUnitId !== null and !empty($input['is_invitation'])), authorized for both
     * business-unit System Administrators and Super Administrators via EmployeeInvitationService.
     * Direct Super Administrator provisioning ($enforcedUnitId === null) always uses direct
     * password-based creation without invitations.
     *
     * @param array $input [full_name, first_name, last_name, email, username, business_unit_id, position, roles (ids or names), password, phone, is_invitation]
     * @param int|null $enforcedUnitId If set (for System Admin), forces and validates business_unit_id === $enforcedUnitId
     * @param int|null $actorUserId User ID performing the creation
     * @param PDO|null $db Optional PDO connection override (defaults to Database::pdo())
     * @param EmployeeInvitationService|null $invitationService Optional invitation service override for testing
     * @return array{
     *   ok:bool,
     *   status?:int,
     *   errors:array<string,string>,
     *   user_id?:int,
     *   employee_id?:int,
     *   is_invitation?:bool,
     *   invitation_id?:int,
     *   invitation_status?:string,
     *   invitation_expires_at?:string,
     *   email_submission_status?:string,
     *   email_submitted?:bool,
     *   email_error?:?string,
     *   email_status_code?:int
     * }
     */
    public static function createEmployeeAccount(
        array $input,
        ?int $enforcedUnitId = null,
        ?int $actorUserId = null,
        ?PDO $db = null,
        ?EmployeeInvitationService $invitationService = null
    ): array {
        $db = $db ?? Database::pdo();
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
        if (!self::isValidEmail($email)) {
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
        $unit = null;
        if ($unitId > 0) {
            $buStmt = $db->prepare('SELECT business_unit_id, business_unit_name, business_unit_status FROM business_unit WHERE business_unit_id = ? AND business_unit_status = "active"');
            $buStmt->execute([$unitId]);
            $unit = $buStmt->fetch() ?: null;
        }
        if (!$unit) {
            $errors['business_unit_id'] = 'Select a valid business unit (Pet Store, Veterinary Clinic, or Grooming Services).';
        }

        // 5. Default position validation against the assigned business unit
        $isSysAdminProvisioning = !empty($input['is_system_admin_provisioning']);
        if ($isSysAdminProvisioning) {
            // Authoritatively derive position from the validated business unit (never trust client-submitted position)
            $position = $unit ? self::systemAdminPositionForUnit((string)$unit['business_unit_name']) : '';
        } else {
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
        }

        // 6. System roles validation against business unit & non-Super-Admin rule
        if ($isSysAdminProvisioning) {
            // System Administrator provisioning always assigns SYSTEM_ADMINISTRATOR scoped to the selected business unit
            $rawRoles = ['SYSTEM_ADMINISTRATOR'];
        } else {
            $rawRoles = $input['roles'] ?? ($input['role_ids'] ?? []);
            if (!is_array($rawRoles)) {
                $rawRoles = $rawRoles !== '' && $rawRoles !== null ? [$rawRoles] : [];
            }
            $rawRoles = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), $rawRoles), fn($v) => $v !== '')));

            // Automatic initial system role assignment during employee creation
            if ($position !== '' && $unit) {
                $initRoles = self::initialRolesForPosition($position, $unit['business_unit_name'], $db);
                foreach (array_reverse($initRoles) as $initRole) {
                    if (!in_array($initRole, $rawRoles, true)) {
                        array_unshift($rawRoles, $initRole);
                    }
                }
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
                if ($unit && $unit['business_unit_name'] !== Units::GROOMING && in_array($rObj['role_name'], self::GROOMING_WORKSPACE_ROLES, true)) {
                    $errors['roles'] = "The role {$rObj['role_name']} can only be assigned in the Grooming Services business unit.";
                    break;
                }
                if ($unit && $unit['business_unit_name'] === Units::VET_CLINIC && $rObj['role_name'] === 'DELIVERY_PERSONNEL') {
                    $errors['roles'] = "The DELIVERY_PERSONNEL role cannot be assigned in the Veterinary Clinic business unit.";
                    break;
                }
                if ($unit && $unit['business_unit_name'] === Units::GROOMING && $rObj['role_name'] === 'DELIVERY_PERSONNEL') {
                    $errors['roles'] = "The DELIVERY_PERSONNEL role cannot be assigned in the Grooming Services business unit.";
                    break;
                }
                $resolvedRoleIds[] = (int)$rObj['role_id'];
                $resolvedRoleNames[] = (string)$rObj['role_name'];
            }
            $resolvedRoleIds = array_values(array_unique($resolvedRoleIds));
            $resolvedRoleNames = array_values(array_unique($resolvedRoleNames));
        }

        // 7. Password validation (skipped ONLY for unit-scoped invitation flow)
        $isInvitation = ($enforcedUnitId !== null) && !empty($input['is_invitation']);
        if ($isInvitation && ($actorUserId === null || $actorUserId <= 0)) {
            $errors['general'] = 'An authorized Super Administrator or System Administrator actor is required to create an employee invitation.';
        }
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
        $invService = null;
        $invRes = null;

        try {
            $db->beginTransaction();

            if ($isInvitation) {
                // Secure unguessable random password hash; pending invitee cannot log in until setting password via invitation
                $randomBytes = bin2hex(random_bytes(32));
                $hash = password_hash($randomBytes, PASSWORD_DEFAULT);
                $emailVerified = null;
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $emailVerified = date('Y-m-d H:i:s');
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
                $invService = $invitationService ?? new EmployeeInvitationService($db);
                $invRes = $invService->createInvitationInTransaction(
                    (int)$actorUserId,
                    $eid,
                    $uid,
                    (int)$unit['business_unit_id'],
                    $email
                );
                if (!$invRes['ok']) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    return [
                        'ok' => false,
                        'status' => (int)($invRes['status'] ?? 422),
                        'errors' => [
                            'general' => (string)($invRes['error'] ?? 'Could not create employee invitation. No partial records were written.'),
                        ],
                    ];
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return [
                'ok' => false,
                'errors' => ['general' => 'Could not save the account. No partial records were written.'],
            ];
        }

        // 9. Post-commit invitation email submission (never holds DB locks; SMTP failure preserves pending account/invitation)
        if ($isInvitation && $invService !== null && is_array($invRes)) {
            $emailOutcome = $invService->submitInvitationEmail(
                (int)$invRes['invitation_id'],
                (string)$invRes['raw_token'],
                (string)$invRes['invited_email'],
                (string)$invRes['recipient_name'],
                (string)$invRes['business_unit_name'],
                (string)$invRes['position']
            );

            return [
                'ok' => true,
                'errors' => [],
                'user_id' => $uid,
                'employee_id' => $eid,
                'is_invitation' => true,
                'invitation_id' => (int)$invRes['invitation_id'],
                'invitation_status' => 'pending',
                'invitation_expires_at' => (string)$invRes['expires_at'],
                'email_submission_status' => $emailOutcome['email_submission_status'],
                'email_submitted' => $emailOutcome['submitted'],
                'email_error' => $emailOutcome['error'],
                'email_status_code' => $emailOutcome['status_code'],
            ];
        }

        return [
            'ok' => true,
            'errors' => [],
            'user_id' => $uid,
            'employee_id' => $eid,
            'is_invitation' => false,
        ];
    }
}

