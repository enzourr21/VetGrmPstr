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
 *  - Create, resend (invalidating prior pending/expired tokens), and revoke employee invitations.
 *  - Enforce strict unit-scoped System Administrator authorization (never Super Administrator).
 *  - Validate cross-table ownership (user_account <-> employee <-> business_unit <-> email)
 *    under a canonical row-lock order without locking the shared business_unit table.
 *  - Store only SHA-256 token hashes; never persist or log raw tokens.
 *  - Build acceptance URLs strictly from EnvConfig::appUrl(), never the request Host header.
 *  - Validate persisted invitation state before SMTP, serialize concurrent submissions per
 *    invitation via MySQL GET_LOCK(), and conditionally record email_submission_status
 *    strictly after DB commit without holding InnoDB locks during SMTP.
 *
 * Canonical Row-Lock Order (shared with account status updates and future acceptance):
 *   1. user_account (WHERE user_account_id = ? FOR UPDATE)
 *   2. employee (WHERE employee_id = ? FOR UPDATE)
 *   3. employee_invitation (WHERE employee_id = ? ... FOR UPDATE)
 *   (business_unit is always read non-locking after locking user_account and employee)
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
     * @param callable|null $clock Optional UTC Unix timestamp provider for deterministic tests (defaults to MySQL UTC_TIMESTAMP()).
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
     * Resend an employee invitation: requires prior invitation history (pending or latest expired),
     * atomically invalidates any prior pending invitation(s), inserts a replacement with a fresh
     * 48-hour expiry, commits, and submits email strictly after commit.
     *
     * Legacy accounts with zero invitation records and invitations whose latest state is
     * revoked or accepted are strictly rejected.
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

            // Lock the full invitation history for this employee to determine resend eligibility
            $historyRows = $this->lockAllInvitationsForEmployee($employeeId);
            if (!$historyRows) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'No invitation history exists for this employee. Resend requires an existing pending or expired invitation.',
                ];
            }

            $pendingRows = [];
            foreach ($historyRows as $row) {
                if ((string)$row['status'] === 'accepted') {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 409,
                        'error' => 'This employee already has an accepted invitation and cannot be resent one.',
                    ];
                }
                if ((string)$row['status'] === 'pending') {
                    $pendingRows[] = $row;
                }
            }

            if (!$pendingRows) {
                // History is ordered newest-first (invitation_id DESC)
                $latestStatus = (string)$historyRows[0]['status'];
                if ($latestStatus !== 'expired') {
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'status' => 409,
                        'error' => 'The latest invitation for this employee is ' . $latestStatus . ' and cannot be resent.',
                    ];
                }
            }

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
                $revokedCount > 0 ? 'revoked:resent' : 'expired',
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

            // Ownership & unit check (allow revoking pending invites even if account was deactivated)
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
     * Submit an invitation email strictly OUTSIDE any database transaction.
     *
     * Security & Concurrency Design:
     *  - Serializes concurrent submissions for the same invitation_id using MySQL GET_LOCK()
     *    (non-blocking timeout 0, released in finally) without holding InnoDB row locks across SMTP.
     *  - Re-validates the persisted invitation, token_hash (hash_equals), expiry, recipient email,
     *    cross-table ownership, and target eligibility immediately before invoking SMTP.
     *  - Retrieves recipient name, business unit name, and position authoritatively from the
     *    verified DB record rather than trusting caller-supplied display strings.
     *  - Records email outcome conditionally (only if the invitation is still pending with the
     *    same token_hash) so delayed SMTP results cannot overwrite terminal invitation states.
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
            $verifiedPosition = (string)$row['position'];

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
     * guarding that the invitation remains pending (and optionally matches $expectedTokenHash)
     * so delayed SMTP outcomes never overwrite terminal states.
     *
     * Sets email_submitted_at and clears email_failed_at on success;
     * sets email_failed_at and clears email_submitted_at on failure.
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

        // Distinguish same-second no-op UPDATE from missing/terminal/mismatched invitation
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
     * Non-locking pre-send validation executed immediately before SMTP submission.
     *
     * @return array{ok:true,row:array<string,mixed>}|array{ok:false,status:int,error:string,email_submission_status?:string}
     */
    private function validateInvitationForEmailSubmission(
        int $invitationId,
        string $rawToken,
        string $recipientEmail
    ): array {
        $stmt = $this->db->prepare(
            "SELECT ei.invitation_id, ei.user_account_id, ei.employee_id, ei.business_unit_id,
                    ei.invited_email, ei.token_hash, ei.status, ei.expires_at,
                    ei.accepted_at, ei.revoked_at, ei.email_submission_status,
                    e.user_account_id AS emp_user_account_id, e.business_unit_id AS emp_business_unit_id,
                    e.first_name, e.last_name, e.position, e.employee_status,
                    ua.email AS account_email, ua.account_status, ua.is_super_admin, ua.email_verified_at,
                    bu.business_unit_name, bu.business_unit_status,
                    EXISTS (
                        SELECT 1
                        FROM account_role ar_sa
                        JOIN role r_sa ON r_sa.role_id = ar_sa.role_id
                        WHERE ar_sa.user_account_id = ua.user_account_id
                          AND r_sa.role_name = 'SUPER_ADMINISTRATOR'
                    ) AS has_super_role
             FROM employee_invitation ei
             JOIN employee e ON e.employee_id = ei.employee_id
             JOIN user_account ua ON ua.user_account_id = ei.user_account_id
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

        if (
            (int)$row['emp_user_account_id'] !== (int)$row['user_account_id'] ||
            (int)$row['emp_business_unit_id'] !== (int)$row['business_unit_id']
        ) {
            return [
                'ok' => false,
                'status' => 422,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Invitation ownership does not match the employee account or business unit.',
            ];
        }

        $normalizedRecipient = strtolower(trim($recipientEmail));
        $invitedEmail = strtolower(trim((string)$row['invited_email']));
        $accountEmail = strtolower(trim((string)$row['account_email']));
        if ($normalizedRecipient !== $invitedEmail || $invitedEmail !== $accountEmail) {
            return [
                'ok' => false,
                'status' => 422,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Recipient email does not match the invitation and employee account email.',
            ];
        }

        if ((bool)$row['is_super_admin'] || (bool)$row['has_super_role']) {
            return [
                'ok' => false,
                'status' => 403,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Invitations cannot be sent for a Super Administrator account.',
            ];
        }

        if (
            (string)$row['account_status'] !== 'active' ||
            (string)$row['employee_status'] !== 'active' ||
            (string)$row['business_unit_status'] !== 'active' ||
            $row['email_verified_at'] !== null
        ) {
            return [
                'ok' => false,
                'status' => 409,
                'email_submission_status' => $currentEmailStatus,
                'error' => 'Employee account or business unit is no longer eligible for invitation email submission.',
            ];
        }

        return ['ok' => true, 'row' => $row];
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
     * Lock user_account and employee in canonical parent-before-child order without locking
     * the shared business_unit table, and enforce cross-table ownership, Super Admin exclusion,
     * unit scoping, email consistency, and eligibility invariants.
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
        // Non-locking discovery of employee's user_account_id to maintain user_account -> employee lock order
        $lookup = $this->db->prepare(
            "SELECT user_account_id
             FROM employee
             WHERE employee_id = ?
             LIMIT 1"
        );
        $lookup->execute([$employeeId]);
        $discoveredUid = $lookup->fetchColumn();
        if ($discoveredUid === false) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Target employee record was not found.',
            ];
        }

        $lockUid = $expectedUserAccountId ?? (int)$discoveredUid;
        if ((int)$discoveredUid !== $lockUid) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Target employee does not belong to the specified user account.',
            ];
        }

        // 1. Lock user_account first (matches SysAdminController::updateEmployeeStatus & SuperAdminController::updateAccountStatus)
        $uaStmt = $this->db->prepare(
            "SELECT ua.user_account_id, ua.email, ua.account_status, ua.is_super_admin, ua.email_verified_at,
                    EXISTS (
                        SELECT 1
                        FROM account_role ar_sa
                        JOIN role r_sa ON r_sa.role_id = ar_sa.role_id
                        WHERE ar_sa.user_account_id = ua.user_account_id
                          AND r_sa.role_name = 'SUPER_ADMINISTRATOR'
                    ) AS has_super_role
             FROM user_account ua
             WHERE ua.user_account_id = ?
             FOR UPDATE"
        );
        $uaStmt->execute([$lockUid]);
        $ua = $uaStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ua) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Target user account was not found.',
            ];
        }

        // 2. Lock employee second
        $empStmt = $this->db->prepare(
            "SELECT e.employee_id, e.user_account_id, e.business_unit_id, e.first_name, e.last_name,
                    e.position, e.employee_status
             FROM employee e
             WHERE e.employee_id = ?
             FOR UPDATE"
        );
        $empStmt->execute([$employeeId]);
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);

        if (!$emp) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Target employee record was not found.',
            ];
        }

        if ((int)$emp['user_account_id'] !== (int)$ua['user_account_id']) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Target employee does not belong to the specified user account.',
            ];
        }

        if ((bool)$ua['is_super_admin'] || (bool)$ua['has_super_role']) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Invitations cannot be managed for a Super Administrator account.',
            ];
        }

        if ((int)$emp['business_unit_id'] !== $expectedBusinessUnitId) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Target employee does not belong to the specified business unit.',
            ];
        }

        // 3. Read business_unit non-locking (never take X-lock on shared 3-row business_unit table)
        $buStmt = $this->db->prepare(
            "SELECT business_unit_id, business_unit_name, business_unit_status
             FROM business_unit
             WHERE business_unit_id = ?
             LIMIT 1"
        );
        $buStmt->execute([(int)$emp['business_unit_id']]);
        $bu = $buStmt->fetch(PDO::FETCH_ASSOC);

        if (!$bu || (string)$bu['business_unit_status'] !== 'active') {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Target employee business unit is not active.',
            ];
        }

        $accountEmail = strtolower(trim((string)$ua['email']));
        if ($expectedNormalizedEmail !== null && $accountEmail !== $expectedNormalizedEmail) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Invitation email does not match the employee account email address.',
            ];
        }

        if ($requirePendingActiveAccount) {
            if ((string)$ua['account_status'] !== 'active' || (string)$emp['employee_status'] !== 'active') {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'Cannot issue an invitation for an inactive or suspended employee account.',
                ];
            }

            if ($ua['email_verified_at'] !== null) {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'This employee account has already been activated and cannot be invited again.',
                ];
            }
        }

        return [
            'ok' => true,
            'target' => [
                'employee_id' => $emp['employee_id'],
                'user_account_id' => $emp['user_account_id'],
                'business_unit_id' => $emp['business_unit_id'],
                'first_name' => $emp['first_name'],
                'last_name' => $emp['last_name'],
                'position' => $emp['position'],
                'employee_status' => $emp['employee_status'],
                'email' => $ua['email'],
                'account_status' => $ua['account_status'],
                'is_super_admin' => $ua['is_super_admin'],
                'email_verified_at' => $ua['email_verified_at'],
                'business_unit_name' => $bu['business_unit_name'],
                'business_unit_status' => $bu['business_unit_status'],
            ],
        ];
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

    /**
     * Lock the entire invitation history for an employee (newest-first) inside the active transaction.
     *
     * @return list<array<string, mixed>>
     */
    private function lockAllInvitationsForEmployee(int $employeeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT invitation_id, status, expires_at
             FROM employee_invitation
             WHERE employee_id = ?
             ORDER BY invitation_id DESC
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

    /**
     * Resolve authoritative UTC Unix timestamp.
     * Uses MySQL UTC_TIMESTAMP() in production (matching config/database.php time_zone='+00:00')
     * or the injected clock callable in deterministic unit tests.
     */
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
