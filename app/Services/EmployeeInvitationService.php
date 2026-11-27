<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\EnvConfig;
use App\Models\AdminAccountService;
use App\Models\Audit;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Employee Invitation Lifecycle & Dual-Path Acceptance Service.
 *
 * Architectural Invariants:
 *  - Invitations are completely independent of user_account, employee, customer, and account_role
 *    prior to acceptance: creating, resending, revoking, or expiring an invitation NEVER creates
 *    permanent account, employee, customer, or role records.
 *  - Intended employee profile (first_name, last_name, optional username, phone), position snapshot
 *    (position_id, position_name), and role assignments (employee_invitation_role) live on the
 *    invitation until acceptance.
 *  - Dual-path acceptance:
 *      * Workflow A (unregistered email): atomically provisions user_account, employee, customer,
 *        and account_role on acceptance.
 *      * Workflow B (existing eligible customer-only account): requires authenticated session
 *        matching the invited email + explicit confirmation; reuses existing user_account and
 *        customer 100% untouched (never mutating password, email, username, or customer data)
 *        and creates only employee + account_role.
 *  - Stores only SHA-256 token hashes; builds URLs strictly from EnvConfig::appUrl(); serializes
 *    concurrent identity/invitation mutations via MySQL GET_LOCK (pc_em_ -> pc_un_) + InnoDB
 *    row locks (user_account -> customer -> employee -> employee_invitation); runs SMTP outside
 *    any database transaction.
 */
final class EmployeeInvitationService
{
    /** Cryptographic entropy in bytes for raw single-use tokens (32 bytes = 64 hex chars). */
    public const TOKEN_BYTES = 32;

    /** Standard invitation validity window in hours (matches MailService::INVITATION_EXPIRY_HOURS). */
    public const EXPIRY_HOURS = 48;

    /** Standard invitation validity window in seconds (48 * 3600). */
    public const EXPIRY_SECONDS = 172800;

    /** Public acceptance path appended to EnvConfig::appUrl(). */
    public const ACCEPT_PATH = '/invitations/accept';

    private PDO $db;

    /**
     * @var callable(string, string, string, string, string, int): array{submitted:bool,driver:string,error:?string,status_code:int}
     */
    private $mailSender;

    /** @var (callable(): int)|null */
    private $clock;

    /**
     * @param PDO|null $db Database connection (defaults to Database::pdo()).
     * @param callable|null $mailSender Optional transport adapter (defaults to MailService::sendEmployeeInvitation).
     * @param callable|null $clock Optional UTC Unix timestamp provider for deterministic tests.
     */
    public function __construct(?PDO $db = null, ?callable $mailSender = null, ?callable $clock = null)
    {
        $this->db = $db ?? Database::pdo();
        $this->mailSender = $mailSender ?? static function (
            string $recipientEmail,
            string $recipientName,
            string $businessUnitName,
            string $position,
            string $invitationUrl,
            int $expiryHours
        ): array {
            return MailService::sendEmployeeInvitation(
                $recipientEmail,
                $recipientName,
                $businessUnitName,
                $position,
                $invitationUrl,
                $expiryHours
            );
        };
        $this->clock = $clock;
    }

    /** Generate a cryptographically secure 64-character lowercase hex token (256-bit entropy). */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /** Validate that a raw token matches the expected 64-character lowercase hexadecimal format. */
    public static function isValidRawTokenFormat(string $rawToken): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $rawToken) === 1;
    }

    /**
     * Compute the 64-character SHA-256 hex digest of a raw invitation token.
     *
     * @throws InvalidArgumentException If the raw token is empty.
     */
    public static function hashToken(string $rawToken): string
    {
        if ($rawToken === '') {
            throw new InvalidArgumentException('Invitation token cannot be empty.');
        }

        return hash('sha256', $rawToken);
    }

    /**
     * Build the absolute invitation acceptance URL from EnvConfig::appUrl().
     * Never inspects $_SERVER['HTTP_HOST'] or any untrusted request header.
     *
     * @throws InvalidArgumentException If the raw token format is invalid.
     * @throws RuntimeException If APP_URL is missing or invalid (code 503).
     */
    public static function buildAcceptanceUrl(string $rawToken): string
    {
        if (!self::isValidRawTokenFormat($rawToken)) {
            throw new InvalidArgumentException('Invalid invitation token format.');
        }

        return EnvConfig::appUrl() . self::ACCEPT_PATH . '?token=' . $rawToken;
    }

    /**
     * Create an independent employee invitation for $businessUnitId without creating any
     * user_account, employee, customer, or account_role records, commit, and submit email post-commit.
     *
     * Accepts both unregistered recipients (even when customer_registration_pending rows exist)
     * and eligible active customer-only accounts (leaving username NULL/untouched).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createInvitationForUnit(
        int $actorUserId,
        int $businessUnitId,
        array $input
    ): array {
        if ($this->db->inTransaction()) {
            return [
                'ok' => false,
                'status' => 500,
                'errors' => ['general' => 'Invitation creation must not run inside an existing transaction.'],
            ];
        }

        if ($actorUserId <= 0 || $businessUnitId <= 0) {
            return [
                'ok' => false,
                'status' => 422,
                'errors' => ['general' => 'Invalid actor or business unit identifier.'],
            ];
        }

        $authErr = $this->assertAuthorizedSystemAdmin($actorUserId, $businessUnitId);
        if ($authErr !== null) {
            return [
                'ok' => false,
                'status' => 403,
                'actor_unauthorized' => true,
                'errors' => ['general' => $authErr['error']],
            ];
        }

        $validation = AdminAccountService::validateEmployeeProvisioningInput($input, $businessUnitId, $this->db, false);
        if (!$validation['ok'] || !isset($validation['clean'])) {
            return [
                'ok' => false,
                'status' => 422,
                'errors' => $validation['errors'],
            ];
        }

        $clean = $validation['clean'];
        $email = $clean['email'];
        $username = $clean['username'];

        $locks = $this->acquireIdentityLocks($email, $username);
        if ($locks === null) {
            return [
                'ok' => false,
                'status' => 503,
                'errors' => ['general' => 'Another invitation or registration change is currently in progress for this account. Please try again.'],
            ];
        }

        $invitationId = 0;
        $rawToken = '';
        $nowUtc = '';
        $expiresAtUtc = '';
        $recipientMode = 'new_account';

        try {
            $this->db->beginTransaction();

            $elig = $this->evaluateEmailEligibility($email, $username, true);
            if (!$elig['ok']) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => (int)$elig['status'],
                    'errors' => $elig['errors'],
                ];
            }
            $recipientMode = (string)$elig['mode'];

            $nowTs = $this->nowUnix();
            $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);
            $expiresAtUtc = gmdate('Y-m-d H:i:s', $nowTs + self::EXPIRY_SECONDS);

            // Lock existing invitation history for this email and sweep any stale past-due pending rows
            $emailInvRows = $this->lockInvitationsByEmail($email);
            foreach ($emailInvRows as $row) {
                if ((string)$row['status'] !== 'pending') {
                    continue;
                }
                if ((string)$row['expires_at'] > $nowUtc) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 409,
                        'errors' => [
                            'email' => 'An active pending invitation already exists for this email address. Resend or revoke it before creating a new one.',
                        ],
                    ];
                }
                $this->markExpiredInsideTransaction(
                    (int)$row['invitation_id'],
                    (int)$row['business_unit_id'],
                    $actorUserId,
                    $nowUtc,
                    $row['employee_id'] !== null ? (int)$row['employee_id'] : null
                );
            }

            // If optional username specified (new_account mode), ensure no active pending invite reserves it
            if ($username !== null) {
                $userInvRows = $this->lockPendingInvitationsByUsername($username);
                foreach ($userInvRows as $row) {
                    if ((string)$row['expires_at'] > $nowUtc) {
                        $this->db->rollBack();
                        return [
                            'ok' => false,
                            'status' => 409,
                            'errors' => [
                                'username' => 'This username is currently reserved by another active pending invitation.',
                            ],
                        ];
                    }
                    $this->markExpiredInsideTransaction(
                        (int)$row['invitation_id'],
                        (int)$row['business_unit_id'],
                        $actorUserId,
                        $nowUtc,
                        $row['employee_id'] !== null ? (int)$row['employee_id'] : null
                    );
                }
            }

            $rawToken = self::generateToken();
            $tokenHash = self::hashToken($rawToken);

            $invitationId = $this->insertInvitationRow([
                'user_account_id' => null,
                'employee_id' => null,
                'business_unit_id' => $clean['business_unit_id'],
                'invited_email' => $email,
                'first_name' => $clean['first_name'],
                'last_name' => $clean['last_name'],
                'username' => $recipientMode === 'existing_customer' ? null : $username,
                'phone' => $clean['phone'],
                'position_id' => $clean['position_id'],
                'position_name' => $clean['position_name'],
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAtUtc,
                'replaced_invitation_id' => null,
                'created_by_user_id' => $actorUserId,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);

            $this->insertInvitationRoles($invitationId, $clean['role_ids']);

            $roleListStr = implode(', ', $clean['role_names']);
            $modeNote = $recipientMode === 'existing_customer' ? 'existing customer account' : 'new employee onboarding';
            $summary = "Created employee invitation #{$invitationId} for {$clean['full_name']} ({$email}) in {$clean['business_unit_name']} as '{$clean['position_name']}' with role(s): {$roleListStr} ({$modeNote}; expires {$expiresAtUtc} UTC).";

            Audit::record(
                $this->db,
                'invitation.created',
                $summary,
                $businessUnitId,
                $actorUserId,
                null,
                null,
                "Position: {$clean['position_name']}; Roles: {$roleListStr}",
                'employee_invitation',
                $invitationId
            );

            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                return [
                    'ok' => false,
                    'status' => 409,
                    'errors' => [
                        'email' => 'An active pending invitation or account with this email or username already exists.',
                    ],
                ];
            }
            return [
                'ok' => false,
                'status' => 500,
                'errors' => ['general' => 'Could not create employee invitation. No partial records were written.'],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'ok' => false,
                'status' => 500,
                'errors' => ['general' => 'Could not create employee invitation. No partial records were written.'],
            ];
        } finally {
            $this->releaseIdentityLocks($locks);
        }

        $emailOutcome = $this->submitInvitationEmail($invitationId, $rawToken, $email);

        return [
            'ok' => true,
            'status' => 201,
            'errors' => [],
            'is_invitation' => true,
            'invitation_id' => $invitationId,
            'invitation_status' => 'pending',
            'invitation_expires_at' => $expiresAtUtc,
            'expires_at' => $expiresAtUtc,
            'invited_email' => $email,
            'recipient_mode' => $recipientMode,
            'email_submission_status' => $emailOutcome['email_submission_status'],
            'email_submitted' => $emailOutcome['submitted'],
            'email_error' => $emailOutcome['error'],
            'email_status_code' => $emailOutcome['status_code'],
        ];
    }

    /**
     * Resend an existing pending or unreplaced expired invitation by $invitationId within $businessUnitId.
     * Atomically invalidates any active pending row (revoked:resent or expired), issues a fresh
     * replacement invitation preserving profile/position/roles, commits, and submits email post-commit.
     *
     * @return array<string, mixed>
     */
    public function resendInvitation(
        int $actorUserId,
        int $invitationId,
        int $businessUnitId
    ): array {
        if ($this->db->inTransaction()) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Resend invitation must not be invoked inside an active transaction.',
            ];
        }

        if ($actorUserId <= 0 || $invitationId <= 0 || $businessUnitId <= 0) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Invalid actor, invitation, or business unit identifier.',
            ];
        }

        $authErr = $this->assertAuthorizedSystemAdmin($actorUserId, $businessUnitId);
        if ($authErr !== null) {
            return $authErr;
        }

        $preStmt = $this->db->prepare(
            "SELECT invitation_id, business_unit_id, invited_email, username
             FROM employee_invitation
             WHERE invitation_id = ?
             LIMIT 1"
        );
        $preStmt->execute([$invitationId]);
        $stub = $preStmt->fetch(PDO::FETCH_ASSOC);

        if (!$stub) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Employee invitation record was not found.',
            ];
        }

        if ((int)$stub['business_unit_id'] !== $businessUnitId) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Access denied: this invitation belongs to another business unit.',
            ];
        }

        $stubEmail = strtolower(trim((string)$stub['invited_email']));
        $stubUsername = $stub['username'] !== null && trim((string)$stub['username']) !== ''
            ? strtolower(trim((string)$stub['username']))
            : null;

        $locks = $this->acquireIdentityLocks($stubEmail, $stubUsername);
        if ($locks === null) {
            return [
                'ok' => false,
                'status' => 503,
                'error' => 'Another invitation or registration change is currently in progress. Please try again.',
            ];
        }

        $newInvitationId = 0;
        $rawToken = '';
        $expiresAtUtc = '';
        $revokedCount = 0;
        $expiredCount = 0;

        try {
            $this->db->beginTransaction();

            $authErrTx = $this->assertAuthorizedSystemAdmin($actorUserId, $businessUnitId);
            if ($authErrTx !== null) {
                $this->db->rollBack();
                return $authErrTx;
            }

            $historyRows = $this->lockInvitationsByEmail($stubEmail, 'DESC');
            $target = null;
            foreach ($historyRows as $r) {
                if ((int)$r['invitation_id'] === $invitationId) {
                    $target = $r;
                    break;
                }
            }

            if (!$target) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 404,
                    'error' => 'Employee invitation record was not found.',
                ];
            }

            if ((int)$target['business_unit_id'] !== $businessUnitId) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 403,
                    'error' => 'Access denied: this invitation belongs to another business unit.',
                ];
            }

            // Ensure target has not already been superseded, accepted, or revoked
            $latestInUnit = null;
            foreach ($historyRows as $r) {
                if ((int)$r['business_unit_id'] === $businessUnitId && $latestInUnit === null) {
                    $latestInUnit = $r;
                }
                if ((string)$r['status'] === 'accepted') {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 409,
                        'error' => 'This invitation has already been accepted and cannot be resent.',
                    ];
                }
            }

            if ($latestInUnit !== null && (int)$latestInUnit['invitation_id'] !== $invitationId) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'A newer invitation already exists for this recipient. Use the latest active or expired invitation instead.',
                ];
            }

            if (!in_array((string)$target['status'], ['pending', 'expired'], true)) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'This invitation is ' . (string)$target['status'] . ' and cannot be resent. Create a new invitation instead.',
                ];
            }

            $targetUsername = $target['username'] !== null && trim((string)$target['username']) !== ''
                ? strtolower(trim((string)$target['username']))
                : null;

            $elig = $this->evaluateEmailEligibility($stubEmail, null, true);
            if (!$elig['ok']) {
                $firstErr = reset($elig['errors']) ?: 'This recipient is no longer eligible to receive an employee invitation.';
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => (string)$firstErr,
                ];
            }

            $replacementUsername = ($elig['mode'] === 'existing_customer') ? null : $targetUsername;
            if ($replacementUsername !== null) {
                $uChk = $this->db->prepare('SELECT user_account_id FROM user_account WHERE username = ? LIMIT 1');
                $uChk->execute([$replacementUsername]);
                if ($uChk->fetchColumn()) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 409,
                        'error' => 'The pre-assigned username on this invitation is no longer available. Create a new invitation instead.',
                    ];
                }
            }

            // Re-verify active business unit, active position, and unit role compatibility
            $buStmt = $this->db->prepare(
                "SELECT business_unit_id, business_unit_name, business_unit_status
                 FROM business_unit
                 WHERE business_unit_id = ?
                 LIMIT 1"
            );
            $buStmt->execute([$businessUnitId]);
            $bu = $buStmt->fetch(PDO::FETCH_ASSOC);
            if (!$bu || (string)$bu['business_unit_status'] !== 'active') {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'Target business unit is no longer active.',
                ];
            }

            $positionName = (string)$target['position_name'];
            $posId = $this->resolveActivePositionId($businessUnitId, $positionName, $target['position_id'] !== null ? (int)$target['position_id'] : null);
            $rolesMeta = $this->loadAndValidateInvitationRoles($invitationId, (string)$bu['business_unit_name'], $target['user_account_id'] !== null ? (int)$target['user_account_id'] : null, $businessUnitId);
            if (!$rolesMeta['ok']) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => (string)$rolesMeta['error'],
                ];
            }
            if ($posId === null && !in_array('SYSTEM_ADMINISTRATOR', $rolesMeta['role_names'], true)) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => "The position '{$positionName}' is no longer active in {$bu['business_unit_name']}. Create a new invitation with an active position.",
                ];
            }

            $nowTs = $this->nowUnix();
            $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);
            $expiresAtUtc = gmdate('Y-m-d H:i:s', $nowTs + self::EXPIRY_SECONDS);

            // Invalidate all pending rows for this email before inserting replacement (keeps unique active_pending_email valid)
            foreach ($historyRows as $r) {
                if ((string)$r['status'] !== 'pending') {
                    continue;
                }
                $priorId = (int)$r['invitation_id'];
                $priorEmpId = $r['employee_id'] !== null ? (int)$r['employee_id'] : null;
                if ((string)$r['expires_at'] <= $nowUtc) {
                    $this->markExpiredInsideTransaction($priorId, (int)$r['business_unit_id'], $actorUserId, $nowUtc, $priorEmpId);
                    $expiredCount++;
                } else {
                    $this->markRevokedInsideTransaction($priorId, (int)$r['business_unit_id'], $actorUserId, 'resent', $nowUtc, false, $priorEmpId);
                    $revokedCount++;
                }
            }

            // Ensure no other active pending row reserves $replacementUsername
            if ($replacementUsername !== null) {
                $userInvRows = $this->lockPendingInvitationsByUsername($replacementUsername);
                foreach ($userInvRows as $ur) {
                    if ((string)$ur['expires_at'] > $nowUtc) {
                        $this->db->rollBack();
                        return [
                            'ok' => false,
                            'status' => 409,
                            'error' => 'This username is currently reserved by another active pending invitation.',
                        ];
                    }
                    $this->markExpiredInsideTransaction(
                        (int)$ur['invitation_id'],
                        (int)$ur['business_unit_id'],
                        $actorUserId,
                        $nowUtc,
                        $ur['employee_id'] !== null ? (int)$ur['employee_id'] : null
                    );
                }
            }

            $rawToken = self::generateToken();
            $tokenHash = self::hashToken($rawToken);

            $newInvitationId = $this->insertInvitationRow([
                'user_account_id' => null,
                'employee_id' => null,
                'business_unit_id' => $businessUnitId,
                'invited_email' => $stubEmail,
                'first_name' => (string)$target['first_name'],
                'last_name' => (string)$target['last_name'],
                'username' => $replacementUsername,
                'phone' => $target['phone'] !== null ? (string)$target['phone'] : null,
                'position_id' => $posId,
                'position_name' => $positionName,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAtUtc,
                'replaced_invitation_id' => $invitationId,
                'created_by_user_id' => $actorUserId,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);

            $this->insertInvitationRoles($newInvitationId, $rolesMeta['role_ids']);

            $unitName = (string)$bu['business_unit_name'];
            Audit::record(
                $this->db,
                'invitation.resent',
                "Issued replacement employee invitation #{$newInvitationId} for {$stubEmail} in {$unitName} (replaced #{$invitationId}; expires {$expiresAtUtc} UTC; invalidated {$revokedCount} pending, {$expiredCount} expired).",
                $businessUnitId,
                $actorUserId,
                null,
                $revokedCount > 0 ? 'revoked:resent' : 'expired',
                'pending',
                'employee_invitation',
                $newInvitationId
            );

            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'ok' => false,
                'status' => (string)$e->getCode() === '23000' ? 409 : 500,
                'error' => 'Could not resend employee invitation. No partial records were written.',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Could not resend employee invitation. No partial records were written.',
            ];
        } finally {
            $this->releaseIdentityLocks($locks);
        }

        $emailOutcome = $this->submitInvitationEmail($newInvitationId, $rawToken, $stubEmail);

        return [
            'ok' => true,
            'status' => 200,
            'invitation_id' => $newInvitationId,
            'replaced_invitation_id' => $invitationId,
            'revoked_count' => $revokedCount,
            'expired_count' => $expiredCount,
            'invitation_status' => 'pending',
            'expires_at' => $expiresAtUtc,
            'invited_email' => $stubEmail,
            'email_submission_status' => $emailOutcome['email_submission_status'],
            'email_submitted' => $emailOutcome['submitted'],
            'email_error' => $emailOutcome['error'],
            'email_status_code' => $emailOutcome['status_code'],
        ];
    }

    /**
     * Revoke an active unexpired pending invitation by $invitationId within $businessUnitId.
     * Past-due pending invitations transition to 'expired' (commits) and return 409 so resend stays possible.
     *
     * @return array<string, mixed>
     */
    public function revokeInvitation(
        int $actorUserId,
        int $invitationId,
        int $businessUnitId,
        string $reason = 'admin_revoked'
    ): array {
        if ($this->db->inTransaction()) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Revoke invitation must not be invoked inside an active transaction.',
            ];
        }

        if ($actorUserId <= 0 || $invitationId <= 0 || $businessUnitId <= 0) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Invalid actor, invitation, or business unit identifier.',
            ];
        }

        $authErr = $this->assertAuthorizedSystemAdmin($actorUserId, $businessUnitId);
        if ($authErr !== null) {
            return $authErr;
        }

        $preStmt = $this->db->prepare(
            "SELECT invitation_id, business_unit_id, invited_email
             FROM employee_invitation
             WHERE invitation_id = ?
             LIMIT 1"
        );
        $preStmt->execute([$invitationId]);
        $stub = $preStmt->fetch(PDO::FETCH_ASSOC);

        if (!$stub) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Employee invitation record was not found.',
            ];
        }

        if ((int)$stub['business_unit_id'] !== $businessUnitId) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Access denied: this invitation belongs to another business unit.',
            ];
        }

        $cleanReason = self::sanitizeReason($reason);
        $stubEmail = strtolower(trim((string)$stub['invited_email']));
        $locks = $this->acquireIdentityLocks($stubEmail, null);
        if ($locks === null) {
            return [
                'ok' => false,
                'status' => 503,
                'error' => 'Another invitation change is currently in progress. Please try again.',
            ];
        }

        try {
            $this->db->beginTransaction();

            $authErrTx = $this->assertAuthorizedSystemAdmin($actorUserId, $businessUnitId);
            if ($authErrTx !== null) {
                $this->db->rollBack();
                return $authErrTx;
            }

            $invStmt = $this->db->prepare(
                "SELECT invitation_id, user_account_id, employee_id, business_unit_id, invited_email, status, expires_at
                 FROM employee_invitation
                 WHERE invitation_id = ?
                 FOR UPDATE"
            );
            $invStmt->execute([$invitationId]);
            $row = $invStmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 404,
                    'error' => 'Employee invitation record was not found.',
                ];
            }

            if ((int)$row['business_unit_id'] !== $businessUnitId) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 403,
                    'error' => 'Access denied: this invitation belongs to another business unit.',
                ];
            }

            if ((string)$row['status'] !== 'pending') {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'No active pending invitation exists for this record.',
                ];
            }

            $nowUtc = gmdate('Y-m-d H:i:s', $this->nowUnix());
            $empId = $row['employee_id'] !== null ? (int)$row['employee_id'] : null;

            if ((string)$row['expires_at'] <= $nowUtc) {
                $this->markExpiredInsideTransaction($invitationId, $businessUnitId, $actorUserId, $nowUtc, $empId);
                $this->db->commit();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'This invitation has already expired and cannot be revoked.',
                ];
            }

            $this->markRevokedInsideTransaction($invitationId, $businessUnitId, $actorUserId, $cleanReason, $nowUtc, true, $empId);
            $this->db->commit();

            return [
                'ok' => true,
                'status' => 200,
                'invitation_id' => $invitationId,
                'invited_email' => strtolower(trim((string)$row['invited_email'])),
                'revoked_count' => 1,
                'revoked_invitation_ids' => [$invitationId],
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Could not revoke employee invitation.',
            ];
        } finally {
            $this->releaseIdentityLocks($locks);
        }
    }

    /**
     * Validate a raw token for read-only display on GET /invitations/accept.
     * Dynamically classifies the acceptance path as 'new_account' or 'existing_customer'.
     *
     * @return array<string, mixed>
     */
    public function validateTokenForAcceptance(string $rawToken): array
    {
        $genericError = 'This invitation link is invalid, expired, or has already been used.';
        $rawToken = trim($rawToken);
        if (!self::isValidRawTokenFormat($rawToken)) {
            return ['ok' => false, 'status' => 400, 'error' => $genericError];
        }

        $tokenHash = self::hashToken($rawToken);
        $stmt = $this->db->prepare(
            "SELECT ei.invitation_id, ei.user_account_id, ei.employee_id, ei.business_unit_id,
                    ei.invited_email, ei.first_name, ei.last_name, ei.username, ei.phone,
                    ei.position_id, ei.position_name, ei.token_hash, ei.status, ei.expires_at,
                    ei.accepted_at, ei.revoked_at, ei.is_legacy_quarantined,
                    bu.business_unit_name, bu.business_unit_status,
                    (SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ')
                     FROM employee_invitation_role eir
                     JOIN role r ON r.role_id = eir.role_id
                     WHERE eir.invitation_id = ei.invitation_id) AS invited_roles
             FROM employee_invitation ei
             JOIN business_unit bu ON bu.business_unit_id = ei.business_unit_id
             WHERE ei.token_hash = ?
             LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !hash_equals((string)$row['token_hash'], $tokenHash)) {
            return ['ok' => false, 'status' => 404, 'error' => $genericError];
        }

        $nowUtc = gmdate('Y-m-d H:i:s', $this->nowUnix());
        if (
            (string)$row['status'] !== 'pending' ||
            $row['accepted_at'] !== null ||
            $row['revoked_at'] !== null ||
            (string)$row['expires_at'] <= $nowUtc ||
            (string)$row['business_unit_status'] !== 'active'
        ) {
            return ['ok' => false, 'status' => 410, 'error' => $genericError];
        }

        $invitedEmail = strtolower(trim((string)$row['invited_email']));
        $invUsername = $row['username'] !== null && trim((string)$row['username']) !== ''
            ? strtolower(trim((string)$row['username']))
            : null;
        $businessUnitId = (int)$row['business_unit_id'];
        $unitName = (string)$row['business_unit_name'];
        $positionName = (string)$row['position_name'];

        // Verify roles and active position remain valid
        $rolesMeta = $this->loadAndValidateInvitationRoles(
            (int)$row['invitation_id'],
            $unitName,
            $row['user_account_id'] !== null ? (int)$row['user_account_id'] : null,
            $businessUnitId
        );
        if (!$rolesMeta['ok']) {
            return ['ok' => false, 'status' => 409, 'error' => (string)$rolesMeta['error']];
        }
        $posId = $this->resolveActivePositionId($businessUnitId, $positionName, $row['position_id'] !== null ? (int)$row['position_id'] : null);
        if ($posId === null && !in_array('SYSTEM_ADMINISTRATOR', $rolesMeta['role_names'], true)) {
            return [
                'ok' => false,
                'status' => 409,
                'error' => "This invitation can no longer be accepted because the position '{$positionName}' is no longer active.",
            ];
        }

        // Reject quarantined legacy placeholder records unless cleaned up first
        if (!empty($row['is_legacy_quarantined'])) {
            return [
                'ok' => false,
                'status' => 409,
                'error' => 'This legacy invitation requires administrator re-issuance before acceptance.',
            ];
        }

        $elig = $this->evaluateEmailEligibility($invitedEmail, $invUsername, false, true);
        if (!$elig['ok']) {
            return [
                'ok' => false,
                'status' => 409,
                'error' => 'This invitation cannot be accepted because the associated email or username is not eligible for employee onboarding.',
            ];
        }

        $rolesDisplay = (string)($row['invited_roles'] ?? '');
        if ($rolesDisplay === '' && !empty($rolesMeta['role_names'])) {
            $rolesDisplay = implode(', ', $rolesMeta['role_names']);
        }

        return [
            'ok' => true,
            'status' => 200,
            'mode' => (string)$elig['mode'],
            'existing_user_account_id' => $elig['existing_user_account_id'] ?? null,
            'invitation' => [
                'invitation_id' => (int)$row['invitation_id'],
                'full_name' => trim((string)$row['first_name'] . ' ' . (string)$row['last_name']),
                'first_name' => (string)$row['first_name'],
                'last_name' => (string)$row['last_name'],
                'email' => $invitedEmail,
                'username' => $elig['mode'] === 'existing_customer' ? null : $invUsername,
                'business_unit_id' => $businessUnitId,
                'business_unit_name' => $unitName,
                'position' => $positionName,
                'roles' => $rolesDisplay,
                'expires_at' => (string)$row['expires_at'],
            ],
        ];
    }

    /**
     * Accept a single-use employee invitation atomically via one of two paths resolved
     * authoritatively under advisory + InnoDB locks:
     *
     *  - Workflow A (no existing user_account for invited_email):
     *      Validates password policy, creates user_account (email_verified_at=UTC), employee,
     *      customer, and account_role, and links + accepts employee_invitation.
     *  - Workflow B (existing eligible customer-only account for invited_email):
     *      Requires $authenticatedUserId matching the existing user_account and
     *      $confirmedExistingAccount === true; leaves user_account and customer 100% untouched
     *      (zero password/email/username mutation) and inserts only employee + account_role.
     *
     * @return array<string, mixed>
     */
    public function acceptInvitation(
        string $rawToken,
        string $password = '',
        string $passwordConfirm = '',
        ?int $authenticatedUserId = null,
        bool $confirmedExistingAccount = false
    ): array {
        $genericError = 'This invitation link is invalid, expired, or has already been used.';

        if ($this->db->inTransaction()) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Accept invitation must not be invoked inside an active transaction.',
            ];
        }

        $rawToken = trim($rawToken);
        if (!self::isValidRawTokenFormat($rawToken)) {
            return ['ok' => false, 'status' => 400, 'error' => $genericError];
        }

        $tokenHash = self::hashToken($rawToken);

        $lookup = $this->db->prepare(
            "SELECT invitation_id, invited_email, username, business_unit_id
             FROM employee_invitation
             WHERE token_hash = ?
             LIMIT 1"
        );
        $lookup->execute([$tokenHash]);
        $stub = $lookup->fetch(PDO::FETCH_ASSOC);

        if (!$stub) {
            return ['ok' => false, 'status' => 404, 'error' => $genericError];
        }

        $invitationId = (int)$stub['invitation_id'];
        $invitedEmail = strtolower(trim((string)$stub['invited_email']));
        $stubUsername = $stub['username'] !== null && trim((string)$stub['username']) !== ''
            ? strtolower(trim((string)$stub['username']))
            : null;

        $locks = $this->acquireIdentityLocks($invitedEmail, $stubUsername);
        if ($locks === null) {
            return [
                'ok' => false,
                'status' => 503,
                'error' => 'Account activation is temporarily busy. Please try again.',
            ];
        }

        try {
            $this->db->beginTransaction();

            // 1. Canonical lock order: user_account -> customer -> employee -> employee_invitation
            $uaStmt = $this->db->prepare(
                "SELECT ua.user_account_id, ua.email, ua.username, ua.account_status, ua.is_super_admin, ua.email_verified_at,
                        EXISTS (
                            SELECT 1
                            FROM account_role ar_sa
                            JOIN role r_sa ON r_sa.role_id = ar_sa.role_id
                            WHERE ar_sa.user_account_id = ua.user_account_id
                              AND r_sa.role_name = 'SUPER_ADMINISTRATOR'
                        ) AS has_super_role
                 FROM user_account ua
                 WHERE ua.email = ?
                 FOR UPDATE"
            );
            $uaStmt->execute([$invitedEmail]);
            $ua = $uaStmt->fetch(PDO::FETCH_ASSOC);

            $existingCustomer = false;
            $existingEmployee = false;
            if ($ua) {
                $existingUid = (int)$ua['user_account_id'];
                $custStmt = $this->db->prepare(
                    "SELECT customer_id, user_account_id, first_name, last_name, phone, customer_status
                     FROM customer
                     WHERE user_account_id = ?
                     FOR UPDATE"
                );
                $custStmt->execute([$existingUid]);
                $existingCustomer = $custStmt->fetch(PDO::FETCH_ASSOC);

                $empStmt = $this->db->prepare(
                    "SELECT employee_id, user_account_id, business_unit_id, employee_status
                     FROM employee
                     WHERE user_account_id = ?
                     FOR UPDATE"
                );
                $empStmt->execute([$existingUid]);
                $existingEmployee = $empStmt->fetch(PDO::FETCH_ASSOC);
            }

            $allInvRows = $this->lockInvitationsByEmail($invitedEmail, 'DESC');
            $targetInv = null;
            foreach ($allInvRows as $row) {
                if ((int)$row['invitation_id'] === $invitationId) {
                    $targetInv = $row;
                    break;
                }
            }

            if (!$targetInv || !hash_equals((string)$targetInv['token_hash'], $tokenHash)) {
                $this->db->rollBack();
                return ['ok' => false, 'status' => 404, 'error' => $genericError];
            }

            if (
                (string)$targetInv['status'] !== 'pending' ||
                $targetInv['accepted_at'] !== null ||
                $targetInv['revoked_at'] !== null
            ) {
                $this->db->rollBack();
                return ['ok' => false, 'status' => 409, 'error' => $genericError];
            }

            $nowUtc = gmdate('Y-m-d H:i:s', $this->nowUnix());
            $businessUnitId = (int)$targetInv['business_unit_id'];

            if ((string)$targetInv['expires_at'] <= $nowUtc) {
                $this->markExpiredInsideTransaction(
                    $invitationId,
                    $businessUnitId,
                    null,
                    $nowUtc,
                    $targetInv['employee_id'] !== null ? (int)$targetInv['employee_id'] : null
                );
                $this->db->commit();
                return ['ok' => false, 'status' => 410, 'error' => $genericError];
            }

            if (!empty($targetInv['is_legacy_quarantined'])) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'This legacy invitation requires administrator re-issuance before acceptance.',
                ];
            }

            $buStmt = $this->db->prepare(
                "SELECT business_unit_id, business_unit_name, business_unit_status
                 FROM business_unit
                 WHERE business_unit_id = ?
                 LIMIT 1"
            );
            $buStmt->execute([$businessUnitId]);
            $bu = $buStmt->fetch(PDO::FETCH_ASSOC);

            if (!$bu || (string)$bu['business_unit_status'] !== 'active') {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'This invitation can no longer be completed because the assigned business unit is inactive.',
                ];
            }

            $unitName = (string)$bu['business_unit_name'];
            $positionName = (string)$targetInv['position_name'];
            $rolesMeta = $this->loadAndValidateInvitationRoles($invitationId, $unitName, null, $businessUnitId);
            if (!$rolesMeta['ok']) {
                $this->db->rollBack();
                return ['ok' => false, 'status' => 409, 'error' => (string)$rolesMeta['error']];
            }
            $posId = $this->resolveActivePositionId(
                $businessUnitId,
                $positionName,
                $targetInv['position_id'] !== null ? (int)$targetInv['position_id'] : null
            );
            if ($posId === null && !in_array('SYSTEM_ADMINISTRATOR', $rolesMeta['role_names'], true)) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => "This invitation can no longer be completed because the position '{$positionName}' is no longer active in {$unitName}.",
                ];
            }

            $firstName = (string)$targetInv['first_name'];
            $lastName = (string)$targetInv['last_name'];
            $invPhone = $targetInv['phone'] !== null && trim((string)$targetInv['phone']) !== ''
                ? trim((string)$targetInv['phone'])
                : null;
            $invUsername = $targetInv['username'] !== null && trim((string)$targetInv['username']) !== ''
                ? strtolower(trim((string)$targetInv['username']))
                : null;

            $roleIds = $rolesMeta['role_ids'];
            $roleNames = $rolesMeta['role_names'];
            $roleListStr = implode(', ', $roleNames);
            $empActionCode = in_array('SYSTEM_ADMINISTRATOR', $roleNames, true) ? 'sysadmin.created' : 'employee.created';

            // ------------------------------------------------------------------
            // WORKFLOW B: Existing user_account for invited_email
            // ------------------------------------------------------------------
            if ($ua) {
                $existingUid = (int)$ua['user_account_id'];
                if (
                    (bool)$ua['is_super_admin'] ||
                    (bool)$ua['has_super_role'] ||
                    $existingEmployee !== false ||
                    (string)$ua['account_status'] !== 'active' ||
                    !$existingCustomer ||
                    (string)$existingCustomer['customer_status'] !== 'active'
                ) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 409,
                        'error' => 'This account is not eligible to accept an employee invitation.',
                    ];
                }

                if ($authenticatedUserId === null || $authenticatedUserId <= 0) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 401,
                        'mode' => 'existing_customer',
                        'requires_auth' => true,
                        'error' => 'Please sign in to your existing PawCariño account before accepting this employee invitation.',
                    ];
                }

                if ($authenticatedUserId !== $existingUid) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 403,
                        'mode' => 'existing_customer',
                        'wrong_account' => true,
                        'error' => 'You are signed in to a different account. Sign in with the invited email address to accept this invitation.',
                    ];
                }

                if (strtolower(trim((string)$ua['email'])) !== $invitedEmail) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 409,
                        'error' => 'Your account email address no longer matches this invitation.',
                    ];
                }

                if (!$confirmedExistingAccount) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 422,
                        'mode' => 'existing_customer',
                        'error' => 'Please confirm that you want to join as an employee using your existing account.',
                        'errors' => ['confirm_accept' => 'Confirm acceptance to continue.'],
                    ];
                }

                // NEVER update user_account or customer in Workflow B.
                // Create ONLY the missing employee record and unit role assignments.
                $effectivePhone = $invPhone ?? ($existingCustomer['phone'] !== null ? (string)$existingCustomer['phone'] : null);
                $insEmp = $this->db->prepare(
                    "INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, phone, employee_status)
                     VALUES (?, ?, ?, ?, ?, ?, 'active')"
                );
                $insEmp->execute([$existingUid, $businessUnitId, $firstName, $lastName, $positionName, $effectivePhone]);
                $eid = (int)$this->db->lastInsertId();

                $insRole = $this->db->prepare(
                    'INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)'
                );
                foreach ($roleIds as $rid) {
                    $insRole->execute([$existingUid, $rid, $businessUnitId]);
                }

                $consumeStmt = $this->db->prepare(
                    "UPDATE employee_invitation
                     SET status = 'accepted',
                         accepted_at = ?,
                         user_account_id = ?,
                         employee_id = ?,
                         is_legacy_quarantined = 0,
                         updated_at = ?
                     WHERE invitation_id = ?
                       AND status = 'pending'
                       AND token_hash = ?"
                );
                $consumeStmt->execute([$nowUtc, $existingUid, $eid, $nowUtc, $invitationId, $tokenHash]);
                if ($consumeStmt->rowCount() !== 1) {
                    $this->db->rollBack();
                    return ['ok' => false, 'status' => 409, 'error' => $genericError];
                }

                Audit::record(
                    $this->db,
                    $empActionCode,
                    "Attached employee profile $firstName $lastName ($invitedEmail) to existing customer account in $unitName as '$positionName' with role(s): $roleListStr.",
                    $businessUnitId,
                    $existingUid,
                    $eid,
                    'customer_only',
                    "Position: $positionName; Roles: $roleListStr",
                    'employee',
                    $eid
                );

                Audit::record(
                    $this->db,
                    'invitation.accepted',
                    "Existing customer $firstName $lastName ({$invitedEmail}) accepted employee invitation #{$invitationId} in {$unitName}.",
                    $businessUnitId,
                    $existingUid,
                    $eid,
                    'pending',
                    'accepted',
                    'employee_invitation',
                    $invitationId
                );

                $this->db->commit();

                return [
                    'ok' => true,
                    'status' => 200,
                    'mode' => 'existing_customer',
                    'invitation_id' => $invitationId,
                    'user_account_id' => $existingUid,
                    'employee_id' => $eid,
                    'customer_id' => (int)$existingCustomer['customer_id'],
                ];
            }

            // ------------------------------------------------------------------
            // WORKFLOW A: New account creation (no existing user_account)
            // ------------------------------------------------------------------
            $fieldErrors = [];
            if (strlen($password) < 10) {
                $fieldErrors['password'] = 'Password must be at least 10 characters long.';
            } elseif (strlen($password) > 200) {
                $fieldErrors['password'] = 'Password must be at most 200 characters long.';
            }
            if ($passwordConfirm === '' || $password !== $passwordConfirm) {
                $fieldErrors['password_confirm'] = 'Passwords do not match.';
            }
            if ($fieldErrors) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 422,
                    'mode' => 'new_account',
                    'error' => 'Please review the password requirements below.',
                    'errors' => $fieldErrors,
                ];
            }

            if ($invUsername !== null) {
                $uLock = $this->db->prepare('SELECT user_account_id FROM user_account WHERE username = ? FOR UPDATE');
                $uLock->execute([$invUsername]);
                if ($uLock->fetchColumn()) {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 409,
                        'error' => 'The pre-assigned username on this invitation is already in use. Please ask your System Administrator for a new invitation.',
                    ];
                }
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $insUa = $this->db->prepare(
                "INSERT INTO user_account (email, username, password_hash, account_status, is_super_admin, email_verified_at)
                 VALUES (?, ?, ?, 'active', 0, ?)"
            );
            $insUa->execute([$invitedEmail, $invUsername, $passwordHash, $nowUtc]);
            $uid = (int)$this->db->lastInsertId();

            $insEmp = $this->db->prepare(
                "INSERT INTO employee (user_account_id, business_unit_id, first_name, last_name, position, phone, employee_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'active')"
            );
            $insEmp->execute([$uid, $businessUnitId, $firstName, $lastName, $positionName, $invPhone]);
            $eid = (int)$this->db->lastInsertId();

            // Provision matching customer profile so unified customer/username workflows remain available
            $insCust = $this->db->prepare(
                "INSERT INTO customer (user_account_id, first_name, last_name, phone, customer_status)
                 VALUES (?, ?, ?, ?, 'active')"
            );
            $insCust->execute([$uid, $firstName, $lastName, $invPhone]);
            $cid = (int)$this->db->lastInsertId();

            $insRole = $this->db->prepare(
                'INSERT INTO account_role (user_account_id, role_id, business_unit_id) VALUES (?, ?, ?)'
            );
            foreach ($roleIds as $rid) {
                $insRole->execute([$uid, $rid, $businessUnitId]);
            }

            $consumeStmt = $this->db->prepare(
                "UPDATE employee_invitation
                 SET status = 'accepted',
                     accepted_at = ?,
                     user_account_id = ?,
                     employee_id = ?,
                     is_legacy_quarantined = 0,
                     updated_at = ?
                 WHERE invitation_id = ?
                   AND status = 'pending'
                   AND token_hash = ?"
            );
            $consumeStmt->execute([$nowUtc, $uid, $eid, $nowUtc, $invitationId, $tokenHash]);
            if ($consumeStmt->rowCount() !== 1) {
                $this->db->rollBack();
                return ['ok' => false, 'status' => 409, 'error' => $genericError];
            }

            Audit::record(
                $this->db,
                $empActionCode,
                "Created employee account $firstName $lastName ($invitedEmail) via invitation acceptance in $unitName as '$positionName' with role(s): $roleListStr.",
                $businessUnitId,
                $uid,
                $eid,
                null,
                "Position: $positionName; Roles: $roleListStr",
                'employee',
                $eid
            );

            Audit::record(
                $this->db,
                'invitation.accepted',
                "Employee {$firstName} {$lastName} ({$invitedEmail}) accepted invitation #{$invitationId} in {$unitName}.",
                $businessUnitId,
                $uid,
                $eid,
                'pending',
                'accepted',
                'employee_invitation',
                $invitationId
            );

            $this->db->commit();

            return [
                'ok' => true,
                'status' => 200,
                'mode' => 'new_account',
                'invitation_id' => $invitationId,
                'user_account_id' => $uid,
                'employee_id' => $eid,
                'customer_id' => $cid,
            ];
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'An account with this email or username already exists. Sign in to continue or request a new invitation.',
                ];
            }
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Unable to activate your account at this time. Please try again later.',
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Unable to activate your account at this time. Please try again later.',
            ];
        } finally {
            $this->releaseIdentityLocks($locks);
        }
    }

    /**
     * Legacy compatibility guard: returns true ONLY when a user_account still holds unresolved,
     * never-accepted invitation linkage (such as pre-migration quarantined legacy placeholders).
     * Decoupled invitations have user_account_id = NULL prior to acceptance and never block.
     */
    public static function isAccountBlockedByUnacceptedInvitation(PDO $db, int $userAccountId): bool
    {
        if ($userAccountId <= 0) {
            return false;
        }

        $stmt = $db->prepare(
            "SELECT
                EXISTS (
                    SELECT 1
                    FROM employee_invitation ei_any
                    WHERE ei_any.user_account_id = ?
                ) AS has_any_invitation,
                EXISTS (
                    SELECT 1
                    FROM employee_invitation ei_acc
                    WHERE ei_acc.user_account_id = ?
                      AND ei_acc.status = 'accepted'
                ) AS has_accepted_invitation"
        );
        $stmt->execute([$userAccountId, $userAccountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }

        return !empty($row['has_any_invitation']) && empty($row['has_accepted_invitation']);
    }

    /**
     * Submit an invitation email strictly OUTSIDE any database transaction.
     * Serializes concurrent sends per invitation_id via MySQL GET_LOCK('pc_inv_mail_<id>', 0).
     *
     * @return array{
     *   submitted: bool,
     *   driver: string,
     *   email_submission_status: string,
     *   error: ?string,
     *   status_code: int
     * }
     */
    public function submitInvitationEmail(
        int $invitationId,
        string $rawToken,
        string $recipientEmail,
        string $recipientName = '',
        string $businessUnitName = '',
        string $position = ''
    ): array {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('SMTP email submission must never run inside an active database transaction.', 500);
        }

        if ($invitationId <= 0 || !self::isValidRawTokenFormat($rawToken)) {
            return [
                'submitted' => false,
                'driver' => 'unknown',
                'email_submission_status' => 'failed',
                'error' => 'Invalid invitation identifier or token format.',
                'status_code' => 422,
            ];
        }

        $lockName = 'pc_inv_mail_' . $invitationId;
        $lockAcquired = false;

        try {
            $lockStmt = $this->db->prepare('SELECT GET_LOCK(?, 0)');
            $lockStmt->execute([$lockName]);
            $lockVal = $lockStmt->fetchColumn();
            if ($lockVal === false || $lockVal === null) {
                return [
                    'submitted' => false,
                    'driver' => 'unknown',
                    'email_submission_status' => 'pending',
                    'error' => 'Could not acquire email submission lock.',
                    'status_code' => 503,
                ];
            }
            if ((int)$lockVal !== 1) {
                return [
                    'submitted' => false,
                    'driver' => 'unknown',
                    'email_submission_status' => 'pending',
                    'error' => 'Invitation email submission is already in progress.',
                    'status_code' => 409,
                ];
            }
            $lockAcquired = true;

            $verified = $this->validateInvitationForEmailSubmission($invitationId, $rawToken, $recipientEmail);
            if (!$verified['ok']) {
                return [
                    'submitted' => false,
                    'driver' => 'unknown',
                    'email_submission_status' => (string)($verified['email_submission_status'] ?? 'failed'),
                    'error' => (string)$verified['error'],
                    'status_code' => (int)$verified['status'],
                ];
            }

            $row = $verified['row'];
            $expectedTokenHash = (string)$row['token_hash'];
            $verifiedEmail = strtolower(trim((string)$row['invited_email']));
            $verifiedName = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            $verifiedUnitName = (string)$row['business_unit_name'];
            $verifiedPosition = (string)$row['position_name'];

            $driver = 'unknown';
            $submitted = false;
            $error = null;
            $statusCode = 502;

            try {
                $acceptUrl = self::buildAcceptanceUrl($rawToken);
                $mailRes = ($this->mailSender)(
                    $verifiedEmail,
                    $verifiedName,
                    $verifiedUnitName,
                    $verifiedPosition,
                    $acceptUrl,
                    self::EXPIRY_HOURS
                );

                $driver = is_string($mailRes['driver'] ?? null) ? $mailRes['driver'] : 'unknown';
                $statusCode = is_int($mailRes['status_code'] ?? null) ? $mailRes['status_code'] : 502;
                $reportedSubmitted = ($mailRes['submitted'] ?? false) === true;

                if ($reportedSubmitted && $driver !== 'null') {
                    $submitted = true;
                    $error = null;
                    $statusCode = 200;
                } else {
                    $submitted = false;
                    $rawErr = is_string($mailRes['error'] ?? null) && trim($mailRes['error']) !== ''
                        ? $mailRes['error']
                        : ($driver === 'null'
                            ? 'Email submission is disabled (MAIL_DRIVER is set to "null").'
                            : 'Unable to submit employee invitation email via SMTP at this time.');
                    $error = self::sanitizeEmailError($rawErr, $rawToken);
                    if ($driver === 'null') {
                        $statusCode = 503;
                    }
                }
            } catch (RuntimeException $e) {
                $submitted = false;
                $code = $e->getCode();
                $statusCode = in_array($code, [422, 502, 503], true) ? $code : 502;
                $error = self::sanitizeEmailError($e->getMessage(), $rawToken);
            } catch (Throwable $e) {
                $submitted = false;
                $statusCode = 502;
                $error = 'Unable to submit employee invitation email via SMTP at this time.';
            }

            $recorded = $this->recordEmailSubmissionOutcome($invitationId, $submitted, $error, $expectedTokenHash);
            if (!$recorded['ok']) {
                return [
                    'submitted' => false,
                    'driver' => $driver,
                    'email_submission_status' => $recorded['email_submission_status'],
                    'error' => $recorded['error'] ?? 'Invitation state changed while email submission was in progress.',
                    'status_code' => 409,
                ];
            }

            return [
                'submitted' => $submitted,
                'driver' => $driver,
                'email_submission_status' => $recorded['email_submission_status'],
                'error' => $error,
                'status_code' => $statusCode,
            ];
        } finally {
            if ($lockAcquired) {
                try {
                    $relStmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                    $relStmt->execute([$lockName]);
                } catch (Throwable $ignore) {
                }
            }
        }
    }

    /**
     * Persist email-submission status independently of invitation lifecycle status,
     * guarding that the invitation remains pending and matches $expectedTokenHash.
     *
     * @return array{ok: bool, email_submission_status: string, recorded_at: string, error: ?string}
     */
    public function recordEmailSubmissionOutcome(
        int $invitationId,
        bool $submitted,
        ?string $errorMessage = null,
        ?string $expectedTokenHash = null
    ): array {
        if ($invitationId <= 0) {
            throw new InvalidArgumentException('Invalid invitation_id.');
        }

        $nowUtc = gmdate('Y-m-d H:i:s', $this->nowUnix());
        $tokenClause = $expectedTokenHash !== null ? ' AND token_hash = ?' : '';

        if ($submitted) {
            $stmt = $this->db->prepare(
                "UPDATE employee_invitation
                 SET email_submission_status = 'submitted',
                     email_submitted_at = ?,
                     email_failed_at = NULL,
                     email_error = NULL,
                     updated_at = ?
                 WHERE invitation_id = ?
                   AND status = 'pending'{$tokenClause}"
            );
            $params = [$nowUtc, $nowUtc, $invitationId];
            if ($expectedTokenHash !== null) {
                $params[] = $expectedTokenHash;
            }
            $stmt->execute($params);

            $targetStatus = 'submitted';
            $cleanError = null;
        } else {
            $cleanError = self::sanitizeEmailError(
                $errorMessage !== null && trim($errorMessage) !== ''
                    ? $errorMessage
                    : 'Unable to submit employee invitation email via SMTP at this time.'
            );

            $stmt = $this->db->prepare(
                "UPDATE employee_invitation
                 SET email_submission_status = 'failed',
                     email_failed_at = ?,
                     email_submitted_at = NULL,
                     email_error = ?,
                     updated_at = ?
                 WHERE invitation_id = ?
                   AND status = 'pending'{$tokenClause}"
            );
            $params = [$nowUtc, $cleanError, $nowUtc, $invitationId];
            if ($expectedTokenHash !== null) {
                $params[] = $expectedTokenHash;
            }
            $stmt->execute($params);

            $targetStatus = 'failed';
        }

        if ($stmt->rowCount() > 0) {
            return [
                'ok' => true,
                'email_submission_status' => $targetStatus,
                'recorded_at' => $nowUtc,
                'error' => $cleanError,
            ];
        }

        $chk = $this->db->prepare(
            "SELECT status, token_hash, email_submission_status
             FROM employee_invitation
             WHERE invitation_id = ?
             LIMIT 1"
        );
        $chk->execute([$invitationId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'ok' => false,
                'email_submission_status' => 'unknown',
                'recorded_at' => $nowUtc,
                'error' => 'Invitation record was not found.',
            ];
        }

        if ($expectedTokenHash !== null && !hash_equals((string)$row['token_hash'], $expectedTokenHash)) {
            return [
                'ok' => false,
                'email_submission_status' => (string)$row['email_submission_status'],
                'recorded_at' => $nowUtc,
                'error' => 'Invitation token no longer matches; email submission outcome was not recorded.',
            ];
        }

        if ((string)$row['status'] !== 'pending') {
            return [
                'ok' => false,
                'email_submission_status' => (string)$row['email_submission_status'],
                'recorded_at' => $nowUtc,
                'error' => 'Invitation is no longer pending (' . (string)$row['status'] . '); email submission outcome was not recorded.',
            ];
        }

        if ((string)$row['email_submission_status'] === $targetStatus) {
            return [
                'ok' => true,
                'email_submission_status' => $targetStatus,
                'recorded_at' => $nowUtc,
                'error' => $cleanError,
            ];
        }

        return [
            'ok' => false,
            'email_submission_status' => (string)$row['email_submission_status'],
            'recorded_at' => $nowUtc,
            'error' => 'Invitation email submission outcome could not be recorded.',
        ];
    }

    /**
     * Classify an actual employee row's effective administrative action state.
     * Driven by employee.employee_status (never conflating customer/account_status unless globally suspended).
     *
     * @param array<string, mixed> $employee
     */
    public static function classifyEmployeeRosterActionState(array $employee): string
    {
        if ((string)($employee['account_status'] ?? 'active') === 'suspended') {
            return 'suspended';
        }

        if ((string)($employee['employee_status'] ?? '') !== 'active') {
            return 'inactive';
        }

        $invState = isset($employee['invitation_state']) && is_string($employee['invitation_state'])
            ? $employee['invitation_state']
            : self::classifyEmployeeInvitationState($employee);

        return match ($invState) {
            'pending' => 'pending_invitation',
            'expired' => 'expired_invitation',
            'revoked' => 'revoked_invitation',
            default => 'active',
        };
    }

    /**
     * Classify an employee's legacy invitation history state if any unresolved linked rows remain.
     * Returns 'none' | 'accepted' | 'pending' | 'revoked' | 'expired'.
     *
     * @param array<string, mixed> $employee
     */
    public static function classifyEmployeeInvitationState(array $employee): string
    {
        if (!empty($employee['has_accepted_invitation'])) {
            return 'accepted';
        }
        if (!empty($employee['has_pending_invitation'])) {
            return 'pending';
        }

        $hasAny = !empty($employee['has_any_invitation'])
            || (isset($employee['latest_invitation_status']) && $employee['latest_invitation_status'] !== null && $employee['latest_invitation_status'] !== '');
        if (!$hasAny) {
            return 'none';
        }

        $latest = strtolower(trim((string)($employee['latest_invitation_status'] ?? '')));
        if ($latest === 'accepted') {
            return 'accepted';
        }
        if ($latest === 'revoked') {
            return 'revoked';
        }
        return 'expired';
    }

    /**
     * List actual provisioned employees scoped to $unitId (excluding Super Administrators).
     * Decoupled pending/expired/revoked invitations do not appear here; only real employee
     * rows exist in `employee`. Any pre-migration quarantined legacy rows preserve visibility
     * cleanly until detached.
     *
     * @return list<array<string, mixed>>
     */
    public static function listUnitEmployeesWithInvitationState(
        PDO $db,
        int $unitId,
        ?string $nowUtc = null
    ): array {
        $expiryExpr = $nowUtc !== null ? '?' : 'UTC_TIMESTAMP()';
        $sql = "SELECT e.employee_id, e.first_name, e.last_name, e.position, e.phone, e.employee_status, e.created_at,
                       e.business_unit_id AS home_unit_id,
                       ua.user_account_id, ua.email, ua.username, ua.account_status, ua.is_super_admin,
                       (SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ')
                        FROM account_role ar2
                        JOIN role r ON r.role_id = ar2.role_id
                        WHERE ar2.user_account_id = ua.user_account_id AND ar2.business_unit_id = ?) AS unit_roles,
                       EXISTS (
                           SELECT 1
                           FROM employee_invitation ei_any
                           WHERE ei_any.employee_id = e.employee_id
                             AND ei_any.user_account_id = ua.user_account_id
                             AND ei_any.business_unit_id = e.business_unit_id
                       ) AS has_any_invitation,
                       EXISTS (
                           SELECT 1
                           FROM employee_invitation ei_acc
                           WHERE ei_acc.employee_id = e.employee_id
                             AND ei_acc.user_account_id = ua.user_account_id
                             AND ei_acc.business_unit_id = e.business_unit_id
                             AND ei_acc.status = 'accepted'
                       ) AS has_accepted_invitation,
                       EXISTS (
                           SELECT 1
                           FROM employee_invitation ei_pend
                           WHERE ei_pend.employee_id = e.employee_id
                             AND ei_pend.user_account_id = ua.user_account_id
                             AND ei_pend.business_unit_id = e.business_unit_id
                             AND ei_pend.status = 'pending'
                             AND ei_pend.expires_at > {$expiryExpr}
                       ) AS has_pending_invitation,
                       (SELECT CASE
                            WHEN ei_last.status = 'pending' AND ei_last.expires_at <= {$expiryExpr} THEN 'expired'
                            ELSE ei_last.status
                        END
                        FROM employee_invitation ei_last
                        WHERE ei_last.employee_id = e.employee_id
                          AND ei_last.user_account_id = ua.user_account_id
                          AND ei_last.business_unit_id = e.business_unit_id
                        ORDER BY ei_last.invitation_id DESC
                        LIMIT 1) AS latest_invitation_status,
                       (SELECT ei_last_mail.email_submission_status
                        FROM employee_invitation ei_last_mail
                        WHERE ei_last_mail.employee_id = e.employee_id
                          AND ei_last_mail.user_account_id = ua.user_account_id
                          AND ei_last_mail.business_unit_id = e.business_unit_id
                        ORDER BY ei_last_mail.invitation_id DESC
                        LIMIT 1) AS latest_email_submission_status
                FROM employee e
                JOIN user_account ua ON ua.user_account_id = e.user_account_id
                WHERE ua.is_super_admin = 0
                  AND (e.business_unit_id = ? OR EXISTS (
                       SELECT 1 FROM account_role ar WHERE ar.user_account_id = ua.user_account_id AND ar.business_unit_id = ?
                   ))
                ORDER BY e.employee_id DESC";

        $params = [$unitId];
        if ($nowUtc !== null) {
            $params[] = $nowUtc;
            $params[] = $nowUtc;
        }
        $params[] = $unitId;
        $params[] = $unitId;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $state = self::classifyEmployeeInvitationState($row);
            $row['invitation_state'] = $state;
            $row['has_pending_invitation'] = $state === 'pending' ? 1 : 0;
        }
        unset($row);

        return $rows;
    }

    /**
     * List active pending AND unreplaced expired invitations for $unitId directly from
     * employee_invitation + employee_invitation_role (without requiring user_account or employee rows).
     * Strictly excludes 'revoked' and 'accepted' invitations.
     *
     * @return list<array<string, mixed>>
     */
    public static function listUnitInvitations(
        PDO $db,
        int $unitId,
        ?string $nowUtc = null
    ): array {
        $expiryExpr = $nowUtc !== null ? '?' : 'UTC_TIMESTAMP()';
        $sql = "SELECT ei.invitation_id, ei.business_unit_id, ei.invited_email,
                       ei.first_name, ei.last_name, ei.username, ei.phone,
                       ei.position_id, ei.position_name,
                       ei.status, ei.expires_at, ei.email_submission_status,
                       ei.email_submitted_at, ei.email_failed_at, ei.created_at,
                       ei.is_legacy_quarantined,
                       CASE
                           WHEN ei.status = 'pending' AND ei.expires_at > {$expiryExpr} THEN 'pending'
                           ELSE 'expired'
                       END AS invitation_state,
                       CASE
                           WHEN ei.status = 'pending' AND ei.expires_at > {$expiryExpr} THEN 'pending_invitation'
                           ELSE 'expired_invitation'
                       END AS action_state,
                       (SELECT GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ')
                        FROM employee_invitation_role eir
                        JOIN role r ON r.role_id = eir.role_id
                        WHERE eir.invitation_id = ei.invitation_id) AS unit_roles,
                       EXISTS (
                           SELECT 1 FROM user_account ua_ex WHERE ua_ex.email = ei.invited_email
                       ) AS is_existing_customer_email
                FROM employee_invitation ei
                WHERE ei.business_unit_id = ?
                  AND (
                      (ei.status = 'pending' AND ei.expires_at > {$expiryExpr})
                      OR (
                          (ei.status = 'expired' OR (ei.status = 'pending' AND ei.expires_at <= {$expiryExpr}))
                          AND ei.invitation_id = (
                              SELECT MAX(ei2.invitation_id)
                              FROM employee_invitation ei2
                              WHERE ei2.business_unit_id = ei.business_unit_id
                                AND ei2.invited_email = ei.invited_email
                          )
                          AND NOT EXISTS (
                              SELECT 1
                              FROM user_account ua_emp
                              JOIN employee e_emp ON e_emp.user_account_id = ua_emp.user_account_id
                              WHERE ua_emp.email = ei.invited_email
                          )
                      )
                  )
                ORDER BY
                    CASE WHEN ei.status = 'pending' AND ei.expires_at > {$expiryExpr} THEN 0 ELSE 1 END ASC,
                    ei.created_at DESC,
                    ei.invitation_id DESC";

        $params = [];
        if ($nowUtc !== null) {
            $params[] = $nowUtc;
            $params[] = $nowUtc;
        }
        $params[] = $unitId;
        if ($nowUtc !== null) {
            $params[] = $nowUtc;
            $params[] = $nowUtc;
            $params[] = $nowUtc;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List genuinely pending, unexpired employee invitations for $unitId directly from
     * employee_invitation (zero dependency on employee or user_account rows).
     *
     * @return list<array<string, mixed>>
     */
    public static function listPendingInvitationsForUnit(
        PDO $db,
        int $unitId,
        ?string $nowUtc = null
    ): array {
        $expiryExpr = $nowUtc !== null ? '?' : 'UTC_TIMESTAMP()';
        $sql = "SELECT ei.invitation_id, ei.business_unit_id,
                       ei.invited_email, ei.invited_email AS email,
                       ei.first_name, ei.last_name, ei.position_name AS position,
                       ei.status AS invitation_status, ei.expires_at,
                       ei.email_submission_status, ei.created_at
                FROM employee_invitation ei
                WHERE ei.business_unit_id = ?
                  AND ei.status = 'pending'
                  AND ei.expires_at > {$expiryExpr}
                ORDER BY ei.created_at DESC, ei.invitation_id DESC";

        $params = [$unitId];
        if ($nowUtc !== null) {
            $params[] = $nowUtc;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Summarize unit employee and invitation counts.
     * Counts only actual employees as total/active employees and uses $pendingInvitations (or
     * unresolved legacy markers if supplied) for pending_invitation_count.
     *
     * @param list<array<string, mixed>> $employees
     * @param list<array<string, mixed>> $pendingInvitations
     * @return array{
     *   total_employees:int,
     *   active_employees:int,
     *   active_sysadmin_count:int,
     *   pending_invitation_count:int,
     *   unaccepted_invitation_count:int
     * }
     */
    public static function summarizeUnitEmployeeCounts(array $employees, array $pendingInvitations = []): array
    {
        $total = 0;
        $active = 0;
        $activeSysAdmins = 0;
        $legacyPending = 0;
        $unacceptedLegacy = 0;

        foreach ($employees as $e) {
            $invState = isset($e['invitation_state']) && is_string($e['invitation_state'])
                ? $e['invitation_state']
                : self::classifyEmployeeInvitationState($e);

            if (in_array($invState, ['pending', 'expired', 'revoked'], true)) {
                $unacceptedLegacy++;
                if ($invState === 'pending') {
                    $legacyPending++;
                }
                continue;
            }

            $total++;
            $isAccountActive = (string)($e['account_status'] ?? 'active') === 'active';
            $isEmployeeActive = (string)($e['employee_status'] ?? '') === 'active';

            if ($isAccountActive && $isEmployeeActive) {
                $active++;
                $unitRoles = array_map('trim', explode(',', (string)($e['unit_roles'] ?? '')));
                if (in_array('SYSTEM_ADMINISTRATOR', $unitRoles, true)) {
                    $activeSysAdmins++;
                }
            }
        }

        $pendingCount = $pendingInvitations !== [] ? count($pendingInvitations) : $legacyPending;

        return [
            'total_employees' => $total,
            'active_employees' => $active,
            'active_sysadmin_count' => $activeSysAdmins,
            'pending_invitation_count' => $pendingCount,
            'unaccepted_invitation_count' => max($pendingCount, $unacceptedLegacy),
        ];
    }

    /**
     * Non-locking pre-send validation executed immediately before SMTP submission.
     * Reads profile/unit/position directly from employee_invitation + business_unit.
     *
     * @return array<string, mixed>
     */
    private function validateInvitationForEmailSubmission(
        int $invitationId,
        string $rawToken,
        string $recipientEmail
    ): array {
        $stmt = $this->db->prepare(
            "SELECT ei.invitation_id, ei.user_account_id, ei.employee_id, ei.business_unit_id,
                    ei.invited_email, ei.first_name, ei.last_name, ei.username, ei.position_name,
                    ei.token_hash, ei.status, ei.expires_at, ei.accepted_at, ei.revoked_at,
                    ei.email_submission_status, ei.is_legacy_quarantined,
                    bu.business_unit_name, bu.business_unit_status
             FROM employee_invitation ei
             JOIN business_unit bu ON bu.business_unit_id = ei.business_unit_id
             WHERE ei.invitation_id = ?
             LIMIT 1"
        );
        $stmt->execute([$invitationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'ok' => false,
                'status' => 404,
                'email_submission_status' => 'failed',
                'error' => 'Employee invitation record was not found.',
            ];
        }

        $currentEmailStatus = (string)$row['email_submission_status'];
        $computedHash = self::hashToken($rawToken);
        if (!hash_equals((string)$row['token_hash'], $computedHash)) {
            return [
                'ok' => false,
                'status' => 422,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Invitation token does not match the persisted invitation record.',
            ];
        }

        if ((string)$row['status'] !== 'pending' || $row['accepted_at'] !== null || $row['revoked_at'] !== null) {
            return [
                'ok' => false,
                'status' => 409,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Invitation is not pending (' . (string)$row['status'] . ') and cannot be emailed.',
            ];
        }

        if ($currentEmailStatus === 'submitted') {
            return [
                'ok' => false,
                'status' => 409,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Invitation email has already been submitted. Use resendInvitation() to issue a replacement invitation.',
            ];
        }

        $nowUtc = gmdate('Y-m-d H:i:s', $this->nowUnix());
        if ((string)$row['expires_at'] <= $nowUtc) {
            return [
                'ok' => false,
                'status' => 409,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Invitation has expired and cannot be emailed.',
            ];
        }

        $normalizedRecipient = strtolower(trim($recipientEmail));
        $invitedEmail = strtolower(trim((string)$row['invited_email']));
        if ($normalizedRecipient !== $invitedEmail) {
            return [
                'ok' => false,
                'status' => 422,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Recipient email does not match the invitation email address.',
            ];
        }

        if ((string)$row['business_unit_status'] !== 'active') {
            return [
                'ok' => false,
                'status' => 409,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Business unit is no longer active.',
            ];
        }

        if (!empty($row['is_legacy_quarantined'])) {
            return [
                'ok' => false,
                'status' => 409,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Quarantined legacy invitation cannot be emailed.',
            ];
        }

        $elig = $this->evaluateEmailEligibility($invitedEmail, null, false);
        if (!$elig['ok']) {
            $errMsg = reset($elig['errors']) ?: 'Recipient account is not eligible for invitation email submission.';
            return [
                'ok' => false,
                'status' => 409,
                'email_submission_status' => $currentEmailStatus,
                'error' => (string)$errMsg,
            ];
        }

        return ['ok' => true, 'row' => $row];
    }

    /**
     * Evaluate whether $email (and optional $username) is eligible for an employee invitation.
     * Never treats customer_registration_pending rows as blocking.
     *
     * @return array{
     *   ok: bool,
     *   status?: int,
     *   mode?: string,
     *   existing_user_account_id?: int,
     *   existing_customer_id?: int,
     *   errors?: array<string, string>
     * }
     */
    private function evaluateEmailEligibility(
        string $normalizedEmail,
        ?string $username,
        bool $forUpdate,
        bool $allowUsernameIgnoredForExistingCustomer = false
    ): array {
        $lockClause = $forUpdate ? ' FOR UPDATE' : '';
        $uaStmt = $this->db->prepare(
            "SELECT ua.user_account_id, ua.email, ua.username, ua.account_status, ua.is_super_admin,
                    EXISTS (
                        SELECT 1
                        FROM account_role ar_sa
                        JOIN role r_sa ON r_sa.role_id = ar_sa.role_id
                        WHERE ar_sa.user_account_id = ua.user_account_id
                          AND r_sa.role_name = 'SUPER_ADMINISTRATOR'
                    ) AS has_super_role
             FROM user_account ua
             WHERE ua.email = ?
             LIMIT 1{$lockClause}"
        );
        $uaStmt->execute([$normalizedEmail]);
        $ua = $uaStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ua) {
            if ($username !== null && $username !== '') {
                $uStmt = $this->db->prepare("SELECT user_account_id FROM user_account WHERE username = ? LIMIT 1{$lockClause}");
                $uStmt->execute([$username]);
                if ($uStmt->fetchColumn()) {
                    return [
                        'ok' => false,
                        'status' => 422,
                        'errors' => ['username' => 'This username is already taken.'],
                    ];
                }
            }

            return [
                'ok' => true,
                'mode' => 'new_account',
            ];
        }

        $uid = (int)$ua['user_account_id'];
        if (!empty($ua['is_super_admin']) || !empty($ua['has_super_role'])) {
            return [
                'ok' => false,
                'status' => 422,
                'errors' => ['email' => 'Super Administrator accounts cannot be invited as business-unit employees.'],
            ];
        }

        $empStmt = $this->db->prepare("SELECT employee_id FROM employee WHERE user_account_id = ? LIMIT 1{$lockClause}");
        $empStmt->execute([$uid]);
        if ($empStmt->fetchColumn()) {
            return [
                'ok' => false,
                'status' => 422,
                'errors' => ['email' => 'An employee account with this email address already exists.'],
            ];
        }

        $acctStatus = (string)$ua['account_status'];
        if ($acctStatus !== 'active') {
            return [
                'ok' => false,
                'status' => 422,
                'errors' => ['email' => "This account is currently {$acctStatus} and cannot receive an employee invitation."],
            ];
        }

        $custStmt = $this->db->prepare("SELECT customer_id, customer_status FROM customer WHERE user_account_id = ? LIMIT 1{$lockClause}");
        $custStmt->execute([$uid]);
        $cust = $custStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cust || (string)$cust['customer_status'] !== 'active') {
            return [
                'ok' => false,
                'status' => 422,
                'errors' => ['email' => 'This account does not have an active customer profile eligible for employee conversion.'],
            ];
        }

        if (!$allowUsernameIgnoredForExistingCustomer && $username !== null && $username !== '') {
            return [
                'ok' => false,
                'status' => 422,
                'errors' => [
                    'username' => 'This email belongs to an existing customer account; leave Username blank because existing account credentials are not modified.',
                ],
            ];
        }

        return [
            'ok' => true,
            'mode' => 'existing_customer',
            'existing_user_account_id' => $uid,
            'existing_customer_id' => (int)$cust['customer_id'],
        ];
    }

    /**
     * Verify that $actorUserId is authorized to manage employee invitations for $businessUnitId.
     *
     * @return array{ok:false,status:int,error:string}|null
     */
    private function assertAuthorizedSystemAdmin(int $actorUserId, int $businessUnitId): ?array
    {
        if (self::isAccountBlockedByUnacceptedInvitation($this->db, $actorUserId)) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Only an active Super Administrator or a System Administrator assigned to this business unit may manage employee invitations.',
            ];
        }

        $stmt = $this->db->prepare(
            "SELECT ua.user_account_id, ua.account_status, ua.is_super_admin,
                    e.employee_id, e.employee_status,
                    bu.business_unit_id, bu.business_unit_status,
                    EXISTS (
                        SELECT 1
                        FROM account_role ar_sa
                        JOIN role r_sa ON r_sa.role_id = ar_sa.role_id
                        WHERE ar_sa.user_account_id = ua.user_account_id
                          AND r_sa.role_name = 'SUPER_ADMINISTRATOR'
                    ) AS has_super_role,
                    EXISTS (
                        SELECT 1
                        FROM account_role ar_sys
                        JOIN role r_sys ON r_sys.role_id = ar_sys.role_id
                        WHERE ar_sys.user_account_id = ua.user_account_id
                          AND ar_sys.business_unit_id = bu.business_unit_id
                          AND r_sys.role_name = 'SYSTEM_ADMINISTRATOR'
                    ) AS has_unit_sysadmin_role
             FROM user_account ua
             JOIN business_unit bu ON bu.business_unit_id = ?
             LEFT JOIN employee e ON e.user_account_id = ua.user_account_id
             WHERE ua.user_account_id = ?
             LIMIT 1"
        );
        $stmt->execute([$businessUnitId, $actorUserId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$actor ||
            (string)$actor['account_status'] !== 'active' ||
            (string)$actor['business_unit_status'] !== 'active'
        ) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Only an active Super Administrator or a System Administrator assigned to this business unit may manage employee invitations.',
            ];
        }

        $isSuperAdmin = (bool)$actor['is_super_admin'] || (bool)$actor['has_super_role'];
        $isUnitSystemAdmin = $actor['employee_id'] !== null
            && (string)$actor['employee_status'] === 'active'
            && (bool)$actor['has_unit_sysadmin_role'];

        if (!$isSuperAdmin && !$isUnitSystemAdmin) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Only an active Super Administrator or a System Administrator assigned to this business unit may manage employee invitations.',
            ];
        }

        return null;
    }

    /**
     * Acquire shared identity advisory locks in canonical order: pc_em_ -> pc_un_.
     *
     * @return list<string>|null
     */
    private function acquireIdentityLocks(string $normalizedEmail, ?string $normalizedUsername = null): ?array
    {
        $acquired = [];
        $emailLock = 'pc_em_' . md5($normalizedEmail);
        $stmt1 = $this->db->prepare('SELECT GET_LOCK(?, 10)');
        $stmt1->execute([$emailLock]);
        if ((int)$stmt1->fetchColumn() !== 1) {
            return null;
        }
        $acquired[] = $emailLock;

        if ($normalizedUsername !== null && $normalizedUsername !== '') {
            $userLock = 'pc_un_' . md5($normalizedUsername);
            $stmt2 = $this->db->prepare('SELECT GET_LOCK(?, 10)');
            $stmt2->execute([$userLock]);
            if ((int)$stmt2->fetchColumn() !== 1) {
                $this->releaseIdentityLocks($acquired);
                return null;
            }
            $acquired[] = $userLock;
        }

        return $acquired;
    }

    /**
     * Release MySQL advisory locks strictly outside any transaction.
     *
     * @param list<string> $locks
     */
    private function releaseIdentityLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lockName) {
            try {
                $stmt = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->execute([$lockName]);
            } catch (Throwable $ignore) {
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function lockInvitationsByEmail(string $normalizedEmail, string $orderDir = 'ASC'): array
    {
        $dir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
        $stmt = $this->db->prepare(
            "SELECT invitation_id, user_account_id, employee_id, business_unit_id, invited_email,
                    first_name, last_name, username, phone, position_id, position_name,
                    token_hash, status, expires_at, accepted_at, revoked_at, is_legacy_quarantined
             FROM employee_invitation
             WHERE invited_email = ?
             ORDER BY invitation_id {$dir}
             FOR UPDATE"
        );
        $stmt->execute([$normalizedEmail]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function lockPendingInvitationsByUsername(string $normalizedUsername): array
    {
        $stmt = $this->db->prepare(
            "SELECT invitation_id, user_account_id, employee_id, business_unit_id, invited_email,
                    username, status, expires_at
             FROM employee_invitation
             WHERE username = ?
               AND status = 'pending'
             ORDER BY invitation_id ASC
             FOR UPDATE"
        );
        $stmt->execute([$normalizedUsername]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resolveActivePositionId(int $businessUnitId, string $positionName, ?int $fallbackId = null): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT position_id
             FROM business_position
             WHERE business_unit_id = ? AND position_name = ? AND position_status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$businessUnitId, $positionName]);
        $pid = $stmt->fetchColumn();
        if ($pid !== false && $pid !== null) {
            return (int)$pid;
        }
        return null;
    }

    /**
     * Load intended roles from employee_invitation_role (falling back to legacy account_role only
     * if migrating a quarantined pre-030 row) and verify Super Admin exclusion + unit compatibility.
     *
     * @return array{ok:true,role_ids:list<int>,role_names:list<string>}|array{ok:false,error:string}
     */
    private function loadAndValidateInvitationRoles(
        int $invitationId,
        string $unitName,
        ?int $legacyUserAccountId,
        int $businessUnitId
    ): array {
        $stmt = $this->db->prepare(
            "SELECT r.role_id, r.role_name
             FROM employee_invitation_role eir
             JOIN role r ON r.role_id = eir.role_id
             WHERE eir.invitation_id = ?
             ORDER BY r.role_id ASC"
        );
        $stmt->execute([$invitationId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows && $legacyUserAccountId !== null && $legacyUserAccountId > 0) {
            $legStmt = $this->db->prepare(
                "SELECT r.role_id, r.role_name
                 FROM account_role ar
                 JOIN role r ON r.role_id = ar.role_id
                 WHERE ar.user_account_id = ? AND ar.business_unit_id = ?
                 ORDER BY r.role_id ASC"
            );
            $legStmt->execute([$legacyUserAccountId, $businessUnitId]);
            $rows = $legStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (!$rows) {
            return [
                'ok' => false,
                'error' => 'This invitation has no assignable business-unit roles.',
            ];
        }

        $roleIds = [];
        $roleNames = [];
        foreach ($rows as $r) {
            $rName = (string)$r['role_name'];
            $err = AdminAccountService::validateRoleCompatibility($rName, $unitName);
            if ($err !== null) {
                return ['ok' => false, 'error' => $err];
            }
            $roleIds[] = (int)$r['role_id'];
            $roleNames[] = $rName;
        }

        return [
            'ok' => true,
            'role_ids' => array_values(array_unique($roleIds)),
            'role_names' => array_values(array_unique($roleNames)),
        ];
    }

    /** @param array<string, mixed> $row */
    private function insertInvitationRow(array $row): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO employee_invitation (
                user_account_id,
                employee_id,
                business_unit_id,
                invited_email,
                first_name,
                last_name,
                username,
                phone,
                position_id,
                position_name,
                token_hash,
                status,
                expires_at,
                replaced_invitation_id,
                email_submission_status,
                created_by_user_id,
                is_legacy_quarantined,
                created_at,
                updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, 'pending', ?, 0, ?, ?)"
        );
        $stmt->execute([
            $row['user_account_id'],
            $row['employee_id'],
            $row['business_unit_id'],
            $row['invited_email'],
            $row['first_name'],
            $row['last_name'],
            $row['username'],
            $row['phone'],
            $row['position_id'],
            $row['position_name'],
            $row['token_hash'],
            $row['expires_at'],
            $row['replaced_invitation_id'],
            $row['created_by_user_id'],
            $row['created_at'],
            $row['updated_at'],
        ]);

        return (int)$this->db->lastInsertId();
    }

    /** @param list<int> $roleIds */
    private function insertInvitationRoles(int $invitationId, array $roleIds): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO employee_invitation_role (invitation_id, role_id) VALUES (?, ?)'
        );
        foreach (array_values(array_unique($roleIds)) as $rid) {
            $stmt->execute([$invitationId, $rid]);
        }
    }

    private function markRevokedInsideTransaction(
        int $invitationId,
        int $businessUnitId,
        int $actorUserId,
        string $reason,
        string $nowUtc,
        bool $writeAudit,
        ?int $affectedEmployeeId = null
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE employee_invitation
             SET status = 'revoked',
                 revoked_at = ?,
                 revoke_reason = ?,
                 updated_at = ?
             WHERE invitation_id = ? AND status = 'pending'"
        );
        $stmt->execute([$nowUtc, $reason, $nowUtc, $invitationId]);

        if ($writeAudit) {
            Audit::record(
                $this->db,
                'invitation.revoked',
                "Revoked pending employee invitation #{$invitationId} (reason: {$reason}).",
                $businessUnitId,
                $actorUserId,
                $affectedEmployeeId,
                'pending',
                'revoked',
                'employee_invitation',
                $invitationId
            );
        }
    }

    private function markExpiredInsideTransaction(
        int $invitationId,
        int $businessUnitId,
        ?int $actorUserId,
        string $nowUtc,
        ?int $affectedEmployeeId = null
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE employee_invitation
             SET status = 'expired',
                 updated_at = ?
             WHERE invitation_id = ? AND status = 'pending'"
        );
        $stmt->execute([$nowUtc, $invitationId]);

        Audit::record(
            $this->db,
            'invitation.expired',
            "Marked expired employee invitation #{$invitationId}.",
            $businessUnitId,
            $actorUserId,
            $affectedEmployeeId,
            'pending',
            'expired',
            'employee_invitation',
            $invitationId
        );
    }

    private function nowUnix(): int
    {
        if ($this->clock !== null) {
            return (int)($this->clock)();
        }

        $stmt = $this->db->query('SELECT UNIX_TIMESTAMP(UTC_TIMESTAMP())');
        $ts = $stmt !== false ? $stmt->fetchColumn() : false;
        if ($ts === false || $ts === null) {
            return time();
        }
        return (int)$ts;
    }

    private static function sanitizeReason(string $reason): string
    {
        $clean = trim(preg_replace('/[\r\n]+/', ' ', $reason) ?? '');
        if ($clean === '') {
            return 'admin_revoked';
        }
        return mb_substr($clean, 0, 100);
    }

    private static function sanitizeEmailError(string $message, ?string $rawToken = null): string
    {
        $clean = trim(preg_replace('/[\r\n]+/', ' ', $message) ?? '');
        if ($rawToken !== null && $rawToken !== '') {
            $clean = str_replace($rawToken, '[redacted]', $clean);
        }
        $clean = preg_replace('#https?://\S+#i', '[redacted-url]', $clean) ?? $clean;
        if ($clean === '') {
            $clean = 'Unable to submit employee invitation email via SMTP at this time.';
        }
        return mb_substr($clean, 0, 255);
    }
}
