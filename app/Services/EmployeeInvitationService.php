<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\EnvConfig;
use App\Models\Audit;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Employee Invitation Lifecycle Service (System Administrator provisioning only).
 *
 * Responsibilities:
 *  - Create, resend (invalidating prior pending tokens), and revoke employee invitations.
 *  - Enforce strict unit-scoped System Administrator authorization (never Super Administrator).
 *  - Validate cross-table ownership (employee <-> user_account <-> business_unit <-> email)
 *    under transactional row locks (SELECT ... FOR UPDATE).
 *  - Store only SHA-256 token hashes; never persist or log raw tokens.
 *  - Build acceptance URLs strictly from EnvConfig::appUrl(), never the request Host header.
 *  - Submit invitation emails strictly after DB commit and record email_submission_status
 *    independently of invitation lifecycle status.
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

    /** @var callable(): int */
    private $clock;

    /**
     * @param PDO|null $db Database connection (defaults to Database::pdo()).
     * @param callable|null $mailSender Optional transport adapter (defaults to MailService::sendEmployeeInvitation).
     * @param callable|null $clock Optional UTC Unix timestamp provider (defaults to time()).
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
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * Generate a cryptographically secure 64-character lowercase hex token (256-bit entropy).
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Validate that a raw token matches the expected 64-character lowercase hexadecimal format.
     */
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
     * Build the absolute invitation acceptance URL from the trusted EnvConfig::appUrl() setting.
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

        $baseUrl = EnvConfig::appUrl();
        return $baseUrl . self::ACCEPT_PATH . '?token=' . $rawToken;
    }

    /**
     * Create an invitation inside an ALREADY-OPEN database transaction without performing SMTP I/O.
     *
     * Intended for callers (e.g., atomic account + invitation creation) that commit their own
     * transaction and then invoke submitInvitationEmail() strictly after commit().
     *
     * @return array{
     *   ok: bool,
     *   status: int,
     *   error?: string,
     *   invitation_id?: int,
     *   invitation_status?: string,
     *   raw_token?: string,
     *   expires_at?: string,
     *   invited_email?: string,
     *   recipient_name?: string,
     *   business_unit_name?: string,
     *   position?: string
     * }
     */
    public function createInvitationInTransaction(
        int $actorUserId,
        int $employeeId,
        int $userAccountId,
        int $businessUnitId,
        string $invitedEmail
    ): array {
        if (!$this->db->inTransaction()) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'An active database transaction is required to create an invitation in-transaction.',
            ];
        }

        return $this->createInvitationCore(
            $actorUserId,
            $employeeId,
            $userAccountId,
            $businessUnitId,
            $invitedEmail
        );
    }

    /**
     * Create an invitation for an existing pending employee account, commit the transaction,
     * and submit the invitation email post-commit.
     *
     * Never exposes the raw token in the returned payload or audit logs.
     *
     * @return array{
     *   ok: bool,
     *   status: int,
     *   error?: string,
     *   invitation_id?: int,
     *   invitation_status?: string,
     *   expires_at?: string,
     *   invited_email?: string,
     *   email_submission_status?: string,
     *   email_submitted?: bool,
     *   email_error?: ?string,
     *   email_status_code?: int
     * }
     */
    public function createInvitation(
        int $actorUserId,
        int $employeeId,
        int $userAccountId,
        int $businessUnitId,
        string $invitedEmail
    ): array {
        if ($this->db->inTransaction()) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Cannot run standalone invitation creation inside an existing transaction; use createInvitationInTransaction() and submit email after commit.',
            ];
        }

        try {
            $this->db->beginTransaction();
            $created = $this->createInvitationCore(
                $actorUserId,
                $employeeId,
                $userAccountId,
                $businessUnitId,
                $invitedEmail
            );

            if (!$created['ok']) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                return $created;
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Could not create employee invitation. No partial records were written.',
            ];
        }

        $emailOutcome = $this->submitInvitationEmail(
            (int)$created['invitation_id'],
            (string)$created['raw_token'],
            (string)$created['invited_email'],
            (string)$created['recipient_name'],
            (string)$created['business_unit_name'],
            (string)$created['position']
        );

        return [
            'ok' => true,
            'status' => 201,
            'invitation_id' => (int)$created['invitation_id'],
            'invitation_status' => 'pending',
            'expires_at' => (string)$created['expires_at'],
            'invited_email' => (string)$created['invited_email'],
            'email_submission_status' => $emailOutcome['email_submission_status'],
            'email_submitted' => $emailOutcome['submitted'],
            'email_error' => $emailOutcome['error'],
            'email_status_code' => $emailOutcome['status_code'],
        ];
    }

    /**
     * Resend an employee invitation: atomically invalidates any prior pending invitation(s),
     * inserts a new pending invitation with a fresh 48-hour expiry, commits, and submits email
     * strictly after commit.
     *
     * @return array{
     *   ok: bool,
     *   status: int,
     *   error?: string,
     *   invitation_id?: int,
     *   revoked_count?: int,
     *   expired_count?: int,
     *   invitation_status?: string,
     *   expires_at?: string,
     *   invited_email?: string,
     *   email_submission_status?: string,
     *   email_submitted?: bool,
     *   email_error?: ?string,
     *   email_status_code?: int
     * }
     */
    public function resendInvitation(
        int $actorUserId,
        int $employeeId,
        int $businessUnitId
    ): array {
        if ($this->db->inTransaction()) {
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Resend invitation must not be invoked inside an active transaction.',
            ];
        }

        if ($actorUserId <= 0 || $employeeId <= 0 || $businessUnitId <= 0) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Invalid actor, employee, or business unit identifier.',
            ];
        }

        try {
            $this->db->beginTransaction();

            $authErr = $this->assertAuthorizedSystemAdmin($actorUserId, $businessUnitId);
            if ($authErr !== null) {
                $this->db->rollBack();
                return $authErr;
            }

            $targetRes = $this->lockAndValidateTargetEmployee($employeeId, null, $businessUnitId, null, true);
            if (!$targetRes['ok']) {
                $this->db->rollBack();
                return $targetRes;
            }
            $target = $targetRes['target'];

            $nowTs = $this->nowUnix();
            $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);
            $expiresAtUtc = gmdate('Y-m-d H:i:s', $nowTs + self::EXPIRY_SECONDS);

            // Lock all existing pending invitations for this employee and invalidate them before issuing replacement
            $pendingRows = $this->lockPendingInvitations($employeeId);
            $revokedCount = 0;
            $expiredCount = 0;

            foreach ($pendingRows as $row) {
                $priorId = (int)$row['invitation_id'];
                if ((string)$row['expires_at'] <= $nowUtc) {
                    $this->markExpiredInsideTransaction(
                        $priorId,
                        $employeeId,
                        $businessUnitId,
                        $actorUserId,
                        $nowUtc
                    );
                    $expiredCount++;
                } else {
                    $this->markRevokedInsideTransaction(
                        $priorId,
                        $employeeId,
                        $businessUnitId,
                        $actorUserId,
                        'resent',
                        $nowUtc,
                        false
                    );
                    $revokedCount++;
                }
            }

            $rawToken = self::generateToken();
            $tokenHash = self::hashToken($rawToken);
            $normalizedEmail = strtolower(trim((string)$target['email']));
            $userAccountId = (int)$target['user_account_id'];
            $unitName = (string)$target['business_unit_name'];
            $recipientName = trim((string)$target['first_name'] . ' ' . (string)$target['last_name']);
            $position = (string)$target['position'];

            $invitationId = $this->insertInvitationRow(
                $userAccountId,
                $employeeId,
                $businessUnitId,
                $normalizedEmail,
                $tokenHash,
                $expiresAtUtc,
                $actorUserId,
                $nowUtc
            );

            Audit::record(
                $this->db,
                'invitation.resent',
                "Issued replacement employee invitation #{$invitationId} for {$normalizedEmail} in {$unitName} (expires {$expiresAtUtc} UTC; invalidated {$revokedCount} pending, {$expiredCount} expired).",
                $businessUnitId,
                $actorUserId,
                $employeeId,
                $revokedCount > 0 ? 'revoked:resent' : ($expiredCount > 0 ? 'expired' : null),
                'pending',
                'employee_invitation',
                $invitationId
            );

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'ok' => false,
                'status' => 500,
                'error' => 'Could not resend employee invitation. No partial records were written.',
            ];
        }

        $emailOutcome = $this->submitInvitationEmail(
            $invitationId,
            $rawToken,
            $normalizedEmail,
            $recipientName,
            $unitName,
            $position
        );

        return [
            'ok' => true,
            'status' => 200,
            'invitation_id' => $invitationId,
            'revoked_count' => $revokedCount,
            'expired_count' => $expiredCount,
            'invitation_status' => 'pending',
            'expires_at' => $expiresAtUtc,
            'invited_email' => $normalizedEmail,
            'email_submission_status' => $emailOutcome['email_submission_status'],
            'email_submitted' => $emailOutcome['submitted'],
            'email_error' => $emailOutcome['error'],
            'email_status_code' => $emailOutcome['status_code'],
        ];
    }

    /**
     * Revoke all pending invitation(s) for an employee within the System Administrator's business unit.
     *
     * @return array{
     *   ok: bool,
     *   status: int,
     *   error?: string,
     *   revoked_count?: int,
     *   revoked_invitation_ids?: list<int>
     * }
     */
    public function revokeInvitation(
        int $actorUserId,
        int $employeeId,
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

        if ($actorUserId <= 0 || $employeeId <= 0 || $businessUnitId <= 0) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Invalid actor, employee, or business unit identifier.',
            ];
        }

        $cleanReason = self::sanitizeReason($reason);

        try {
            $this->db->beginTransaction();

            $authErr = $this->assertAuthorizedSystemAdmin($actorUserId, $businessUnitId);
            if ($authErr !== null) {
                $this->db->rollBack();
                return $authErr;
            }

            // Ownership & unit check (do not require account to be active so pending invites on deactivated accounts can still be revoked)
            $targetRes = $this->lockAndValidateTargetEmployee($employeeId, null, $businessUnitId, null, false);
            if (!$targetRes['ok']) {
                $this->db->rollBack();
                return $targetRes;
            }

            $pendingRows = $this->lockPendingInvitations($employeeId);
            if (!$pendingRows) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'No pending invitation exists for this employee.',
                ];
            }

            $nowUtc = gmdate('Y-m-d H:i:s', $this->nowUnix());
            $revokedIds = [];
            foreach ($pendingRows as $row) {
                $invId = (int)$row['invitation_id'];
                $this->markRevokedInsideTransaction(
                    $invId,
                    $employeeId,
                    $businessUnitId,
                    $actorUserId,
                    $cleanReason,
                    $nowUtc,
                    true
                );
                $revokedIds[] = $invId;
            }

            $this->db->commit();

            return [
                'ok' => true,
                'status' => 200,
                'revoked_count' => count($revokedIds),
                'revoked_invitation_ids' => $revokedIds,
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
        }
    }

    /**
     * Submit an invitation email strictly OUTSIDE any database transaction and record the
     * SMTP submission outcome independently of the invitation's lifecycle status.
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
        string $recipientName,
        string $businessUnitName,
        string $position
    ): array {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('SMTP email submission must never run inside an active database transaction.', 500);
        }

        $driver = 'unknown';
        $submitted = false;
        $error = null;
        $statusCode = 502;

        try {
            $acceptUrl = self::buildAcceptanceUrl($rawToken);
            $mailRes = ($this->mailSender)(
                $recipientEmail,
                $recipientName,
                $businessUnitName,
                $position,
                $acceptUrl,
                self::EXPIRY_HOURS
            );

            $driver = is_string($mailRes['driver'] ?? null) ? $mailRes['driver'] : 'unknown';
            $statusCode = is_int($mailRes['status_code'] ?? null) ? $mailRes['status_code'] : 502;
            $reportedSubmitted = ($mailRes['submitted'] ?? false) === true;

            // Defense-in-depth: null mail driver must never be treated as successful SMTP submission
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

        $recorded = $this->recordEmailSubmissionOutcome($invitationId, $submitted, $error);

        return [
            'submitted' => $submitted,
            'driver' => $driver,
            'email_submission_status' => $recorded['email_submission_status'],
            'error' => $error,
            'status_code' => $statusCode,
        ];
    }

    /**
     * Persist email-submission status independently of invitation lifecycle status.
     * Never modifies employee_invitation.status, expires_at, accepted_at, or revoked_at.
     *
     * @return array{ok: bool, email_submission_status: string, recorded_at: string, error: ?string}
     */
    public function recordEmailSubmissionOutcome(
        int $invitationId,
        bool $submitted,
        ?string $errorMessage = null
    ): array {
        if ($invitationId <= 0) {
            throw new InvalidArgumentException('Invalid invitation_id.');
        }

        $nowUtc = gmdate('Y-m-d H:i:s', $this->nowUnix());

        if ($submitted) {
            $stmt = $this->db->prepare(
                "UPDATE employee_invitation
                 SET email_submission_status = 'submitted',
                     email_submitted_at = ?,
                     email_error = NULL,
                     updated_at = ?
                 WHERE invitation_id = ?"
            );
            $stmt->execute([$nowUtc, $nowUtc, $invitationId]);

            return [
                'ok' => true,
                'email_submission_status' => 'submitted',
                'recorded_at' => $nowUtc,
                'error' => null,
            ];
        }

        $cleanError = self::sanitizeEmailError(
            $errorMessage !== null && trim($errorMessage) !== ''
                ? $errorMessage
                : 'Unable to submit employee invitation email via SMTP at this time.'
        );

        $stmt = $this->db->prepare(
            "UPDATE employee_invitation
             SET email_submission_status = 'failed',
                 email_failed_at = ?,
                 email_error = ?,
                 updated_at = ?
             WHERE invitation_id = ?"
        );
        $stmt->execute([$nowUtc, $cleanError, $nowUtc, $invitationId]);

        return [
            'ok' => true,
            'email_submission_status' => 'failed',
            'recorded_at' => $nowUtc,
            'error' => $cleanError,
        ];
    }

    /**
     * Core transactional creation logic shared by createInvitation() and createInvitationInTransaction().
     *
     * @return array<string, mixed>
     */
    private function createInvitationCore(
        int $actorUserId,
        int $employeeId,
        int $userAccountId,
        int $businessUnitId,
        string $invitedEmail
    ): array {
        if ($actorUserId <= 0 || $employeeId <= 0 || $userAccountId <= 0 || $businessUnitId <= 0) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Invalid actor, employee, user account, or business unit identifier.',
            ];
        }

        $normalizedEmail = strtolower(trim($invitedEmail));
        if (
            $normalizedEmail === '' ||
            strlen($normalizedEmail) > 255 ||
            filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false
        ) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Enter a valid employee email address.',
            ];
        }

        $authErr = $this->assertAuthorizedSystemAdmin($actorUserId, $businessUnitId);
        if ($authErr !== null) {
            return $authErr;
        }

        $targetRes = $this->lockAndValidateTargetEmployee(
            $employeeId,
            $userAccountId,
            $businessUnitId,
            $normalizedEmail,
            true
        );
        if (!$targetRes['ok']) {
            return $targetRes;
        }
        $target = $targetRes['target'];

        $nowTs = $this->nowUnix();
        $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);
        $expiresAtUtc = gmdate('Y-m-d H:i:s', $nowTs + self::EXPIRY_SECONDS);

        // Lock pending rows for this employee to prevent duplicate/conflicting pending invitations
        $pendingRows = $this->lockPendingInvitations($employeeId);
        foreach ($pendingRows as $row) {
            if ((string)$row['expires_at'] > $nowUtc) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'An active pending invitation already exists for this employee. Resend or revoke it before creating a new one.',
                ];
            }
        }

        // Transition any stale expired rows that were still marked 'pending'
        foreach ($pendingRows as $row) {
            $this->markExpiredInsideTransaction(
                (int)$row['invitation_id'],
                $employeeId,
                $businessUnitId,
                $actorUserId,
                $nowUtc
            );
        }

        $rawToken = self::generateToken();
        $tokenHash = self::hashToken($rawToken);
        $unitName = (string)$target['business_unit_name'];
        $recipientName = trim((string)$target['first_name'] . ' ' . (string)$target['last_name']);
        $position = (string)$target['position'];

        $invitationId = $this->insertInvitationRow(
            $userAccountId,
            $employeeId,
            $businessUnitId,
            $normalizedEmail,
            $tokenHash,
            $expiresAtUtc,
            $actorUserId,
            $nowUtc
        );

        Audit::record(
            $this->db,
            'invitation.created',
            "Created employee invitation #{$invitationId} for {$normalizedEmail} in {$unitName} (expires {$expiresAtUtc} UTC).",
            $businessUnitId,
            $actorUserId,
            $employeeId,
            null,
            'pending',
            'employee_invitation',
            $invitationId
        );

        return [
            'ok' => true,
            'status' => 201,
            'invitation_id' => $invitationId,
            'invitation_status' => 'pending',
            'raw_token' => $rawToken,
            'expires_at' => $expiresAtUtc,
            'invited_email' => $normalizedEmail,
            'recipient_name' => $recipientName,
            'business_unit_name' => $unitName,
            'position' => $position,
        ];
    }

    /**
     * Verify that $actorUserId is an active System Administrator scoped to $businessUnitId
     * and NOT a Super Administrator (Super Administrators do not have an invitation path).
     *
     * @return array{ok:false,status:int,error:string}|null Null when authorized.
     */
    private function assertAuthorizedSystemAdmin(int $actorUserId, int $businessUnitId): ?array
    {
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
                    ) AS has_super_role
             FROM user_account ua
             JOIN employee e ON e.user_account_id = ua.user_account_id
             JOIN account_role ar ON ar.user_account_id = ua.user_account_id AND ar.business_unit_id = ?
             JOIN role r ON r.role_id = ar.role_id AND r.role_name = 'SYSTEM_ADMINISTRATOR'
             JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id
             WHERE ua.user_account_id = ?
             LIMIT 1"
        );
        $stmt->execute([$businessUnitId, $actorUserId]);
        $actor = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$actor ||
            (string)$actor['account_status'] !== 'active' ||
            (string)$actor['employee_status'] !== 'active' ||
            (string)$actor['business_unit_status'] !== 'active' ||
            (bool)$actor['is_super_admin'] ||
            (bool)$actor['has_super_role']
        ) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Only an active System Administrator assigned to this business unit may manage employee invitations.',
            ];
        }

        return null;
    }

    /**
     * Lock the target employee + user_account row (FOR UPDATE) and enforce cross-table ownership,
     * unit scoping, email consistency, and account eligibility invariants.
     *
     * @return array{ok:true,target:array<string,mixed>}|array{ok:false,status:int,error:string}
     */
    private function lockAndValidateTargetEmployee(
        int $employeeId,
        ?int $expectedUserAccountId,
        int $expectedBusinessUnitId,
        ?string $expectedNormalizedEmail,
        bool $requirePendingActiveAccount
    ): array {
        $stmt = $this->db->prepare(
            "SELECT e.employee_id, e.user_account_id, e.business_unit_id, e.first_name, e.last_name,
                    e.position, e.employee_status,
                    ua.email, ua.account_status, ua.is_super_admin, ua.email_verified_at,
                    bu.business_unit_name, bu.business_unit_status
             FROM employee e
             JOIN user_account ua ON ua.user_account_id = e.user_account_id
             JOIN business_unit bu ON bu.business_unit_id = e.business_unit_id
             WHERE e.employee_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$employeeId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Target employee record was not found.',
            ];
        }

        if ((bool)$target['is_super_admin']) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Invitations cannot be managed for a Super Administrator account.',
            ];
        }

        if ((int)$target['business_unit_id'] !== $expectedBusinessUnitId) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Target employee does not belong to the specified business unit.',
            ];
        }

        if ((string)$target['business_unit_status'] !== 'active') {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Target employee business unit is not active.',
            ];
        }

        if ($expectedUserAccountId !== null && (int)$target['user_account_id'] !== $expectedUserAccountId) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Target employee does not belong to the specified user account.',
            ];
        }

        $accountEmail = strtolower(trim((string)$target['email']));
        if ($expectedNormalizedEmail !== null && $accountEmail !== $expectedNormalizedEmail) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Invitation email does not match the employee account email address.',
            ];
        }

        if ($requirePendingActiveAccount) {
            if ((string)$target['account_status'] !== 'active' || (string)$target['employee_status'] !== 'active') {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'Cannot issue an invitation for an inactive or suspended employee account.',
                ];
            }

            if ($target['email_verified_at'] !== null) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'This employee account has already been activated and cannot be invited again.',
                ];
            }
        }

        return ['ok' => true, 'target' => $target];
    }

    /**
     * Lock all pending invitations for an employee inside the active transaction.
     *
     * @return list<array<string, mixed>>
     */
    private function lockPendingInvitations(int $employeeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT invitation_id, expires_at
             FROM employee_invitation
             WHERE employee_id = ? AND status = 'pending'
             ORDER BY invitation_id ASC
             FOR UPDATE"
        );
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function insertInvitationRow(
        int $userAccountId,
        int $employeeId,
        int $businessUnitId,
        string $normalizedEmail,
        string $tokenHash,
        string $expiresAtUtc,
        int $actorUserId,
        string $nowUtc
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO employee_invitation (
                user_account_id,
                employee_id,
                business_unit_id,
                invited_email,
                token_hash,
                status,
                expires_at,
                email_submission_status,
                created_by_user_id,
                created_at,
                updated_at
             ) VALUES (?, ?, ?, ?, ?, 'pending', ?, 'pending', ?, ?, ?)"
        );
        $stmt->execute([
            $userAccountId,
            $employeeId,
            $businessUnitId,
            $normalizedEmail,
            $tokenHash,
            $expiresAtUtc,
            $actorUserId,
            $nowUtc,
            $nowUtc,
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function markRevokedInsideTransaction(
        int $invitationId,
        int $employeeId,
        int $businessUnitId,
        int $actorUserId,
        string $reason,
        string $nowUtc,
        bool $writeAudit
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
                $employeeId,
                'pending',
                'revoked',
                'employee_invitation',
                $invitationId
            );
        }
    }

    private function markExpiredInsideTransaction(
        int $invitationId,
        int $employeeId,
        int $businessUnitId,
        int $actorUserId,
        string $nowUtc
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
            "Marked expired employee invitation #{$invitationId} prior to replacement.",
            $businessUnitId,
            $actorUserId,
            $employeeId,
            'pending',
            'expired',
            'employee_invitation',
            $invitationId
        );
    }

    private function nowUnix(): int
    {
        return (int)($this->clock)();
    }

    private static function sanitizeReason(string $reason): string
    {
        $clean = trim(preg_replace('/[\r\n]+/', ' ', $reason) ?? '');
        if ($clean === '') {
            return 'admin_revoked';
        }
        return mb_substr($clean, 0, 100);
    }

    /**
     * Strip any raw token or absolute URL from an error string and bound length to VARCHAR(255).
     */
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
