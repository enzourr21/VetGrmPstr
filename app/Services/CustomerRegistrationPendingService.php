<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\EnvConfig;
use PDO;
use RuntimeException;
use Throwable;

final class CustomerRegistrationPendingService
{
    /** OTP validity window: 5 minutes. */
    public const OTP_TTL_SECONDS = 300;

    /** Resend cooldown window: 60 seconds. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Maximum allowed attempts per OTP code: 5 attempts. */
    public const MAX_ATTEMPTS_PER_OTP = 5;

    /** Maximum cumulative incorrect verification attempts per pending registration: 15. */
    public const MAX_CUMULATIVE_FAILED_ATTEMPTS = 15;

    /** Maximum successful OTP sends per hour per email address: 5. */
    public const MAX_HOURLY_SENDS_PER_EMAIL = 5;

    /** Maximum successful OTP sends per hour per IP address: 5. */
    public const MAX_HOURLY_SENDS_PER_IP = 5;

    /** Pending registration overall session lifetime: 30 minutes (1800s). */
    public const PENDING_REGISTRATION_LIFETIME_SECONDS = 1800;

    /** Default retention period for send/failure log entries: 30 days. */
    public const LOG_RETENTION_DAYS = 30;

    /** Purpose identifier for HMAC domain separation. */
    public const HMAC_PURPOSE = 'customer_registration_otp';

    /** Default fallback IP when client IP is empty or unresolvable. */
    public const DEFAULT_FALLBACK_IP = '127.0.0.1';

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::pdo();
    }

    /**
     * Normalize client IP address, falling back consistently to DEFAULT_FALLBACK_IP when empty.
     */
    public static function sanitizeIp(string $ipAddress): string
    {
        $clean = trim($ipAddress);
        return $clean !== '' ? substr($clean, 0, 45) : self::DEFAULT_FALLBACK_IP;
    }

    /**
     * Generate a cryptographically secure 64-character hexadecimal session token.
     */
    public static function generateSessionToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Compute SHA-256 hash of a registration session token.
     */
    public static function hashSessionToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Generate a 6-digit cryptographically secure OTP string, preserving leading zeroes.
     */
    public static function generateOtp(): string
    {
        return sprintf('%06d', random_int(0, 999999));
    }

    /**
     * Compute HMAC-SHA256 digest of an OTP string bound to purpose and pending_id.
     */
    public static function hashOtp(string $otp, int $pendingId): string
    {
        $secret = EnvConfig::otpHmacSecret();
        return hash_hmac('sha256', self::HMAC_PURPOSE . ':' . $pendingId . ':' . $otp, $secret);
    }

    /**
     * Create or update a pending customer registration and dispatch an OTP verification email.
     * Enforces concurrency-safe rate limits, 60s cooldown, and failure tracking.
     *
     * @param string $sessionToken Plain registration session token bound to the user session
     * @param array<string, mixed> $data Candidate profile fields
     * @param string $ipAddress Client IP address (uses DEFAULT_FALLBACK_IP if empty)
     * @param string|null $testOtp Optional test-only OTP override for deterministic testing
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   error?: string,
     *   errors?: array<string, string>,
     *   status: int,
     *   retry_after?: int,
     *   already_verified?: bool
     * }
     */
    public function initiateRegistration(
        string $sessionToken,
        array $data,
        string $ipAddress = '',
        ?string $testOtp = null
    ): array {
        if (trim($sessionToken) === '') {
            return ['ok' => false, 'error' => 'Invalid registration session token.', 'status' => 400];
        }

        // 1. Sanitize and validate registration input fields
        $validation = $this->validateProfileInput($data);
        if (!$validation['ok']) {
            return [
                'ok' => false,
                'error' => 'Please correct the highlighted errors.',
                'errors' => $validation['errors'],
                'status' => 422,
            ];
        }
        $clean = $validation['clean'];
        $email = $clean['email'];
        $cleanIp = self::sanitizeIp($ipAddress);

        // 2. Concurrency-safe rate limit locking across separate pending registrations
        // MySQL user-level lock names must not exceed 64 characters
        $emailLock = 'pc_em_' . md5($email);
        $ipLock = 'pc_ip_' . md5($cleanIp);
        $locksAcquired = [];

        try {
            $stmtLock1 = $this->db->prepare('SELECT GET_LOCK(?, 10)');
            $stmtLock1->execute([$emailLock]);
            if ((int)$stmtLock1->fetchColumn() !== 1) {
                return ['ok' => false, 'error' => 'Rate limit verification timeout. Please try again.', 'status' => 429];
            }
            $locksAcquired[] = $emailLock;

            $stmtLock2 = $this->db->prepare('SELECT GET_LOCK(?, 10)');
            $stmtLock2->execute([$ipLock]);
            if ((int)$stmtLock2->fetchColumn() !== 1) {
                return ['ok' => false, 'error' => 'Rate limit verification timeout. Please try again.', 'status' => 429];
            }
            $locksAcquired[] = $ipLock;

            // Enforce database-backed hourly send limits under advisory lock
            $oneHourAgo = gmdate('Y-m-d H:i:s', time() - 3600);

            $chkEmail = $this->db->prepare('SELECT COUNT(*) FROM email_otp_send_log WHERE email = ? AND sent_at >= ?');
            $chkEmail->execute([$email, $oneHourAgo]);
            if ((int)$chkEmail->fetchColumn() >= self::MAX_HOURLY_SENDS_PER_EMAIL) {
                return [
                    'ok' => false,
                    'error' => 'Too many verification code requests for this email. Please try again in an hour.',
                    'status' => 429,
                ];
            }

            $chkIp = $this->db->prepare('SELECT COUNT(*) FROM email_otp_send_log WHERE ip_address = ? AND sent_at >= ?');
            $chkIp->execute([$cleanIp, $oneHourAgo]);
            if ((int)$chkIp->fetchColumn() >= self::MAX_HOURLY_SENDS_PER_IP) {
                return [
                    'ok' => false,
                    'error' => 'Too many verification code requests from this network. Please try again in an hour.',
                    'status' => 429,
                ];
            }

            // 3. Check permanent user_account table for existing accounts (non-enumeration detection)
            $conflictField = null;
            $chkExistingEmail = $this->db->prepare('SELECT user_account_id FROM user_account WHERE email = ?');
            $chkExistingEmail->execute([$email]);
            if ($chkExistingEmail->fetchColumn()) {
                $conflictField = 'email';
            } elseif ($clean['username'] !== null) {
                $chkExistingUser = $this->db->prepare('SELECT user_account_id FROM user_account WHERE username = ?');
                $chkExistingUser->execute([$clean['username']]);
                if ($chkExistingUser->fetchColumn()) {
                    $conflictField = 'username';
                }
            }

            // 4. Begin transaction with row-level locking
            $tokenHash = self::hashSessionToken($sessionToken);
            $this->db->beginTransaction();

            $sel = $this->db->prepare('SELECT * FROM customer_registration_pending WHERE session_token_hash = ? FOR UPDATE');
            $sel->execute([$tokenHash]);
            $existing = $sel->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Email-change behavior: if the session changes email, delete previous pending row, retain send logs
                if ($existing['email'] !== $email) {
                    $del = $this->db->prepare('DELETE FROM customer_registration_pending WHERE session_token_hash = ?');
                    $del->execute([$tokenHash]);
                    $existing = null;
                } else {
                    // If already verified for this exact email, preserve verified state
                    if ($existing['otp_verified_at'] !== null) {
                        $upd = $this->db->prepare(
                            'UPDATE customer_registration_pending
                             SET first_name = ?, middle_name = ?, last_name = ?, username = ?, phone = ?,
                                 address_line1 = ?, city = ?, province = ?, postal_code = ?, conflict_field = ?
                             WHERE session_token_hash = ?'
                        );
                        $upd->execute([
                            $clean['first_name'],
                            $clean['middle_name'],
                            $clean['last_name'],
                            $clean['username'],
                            $clean['phone'],
                            $clean['address_line1'],
                            $clean['city'],
                            $clean['province'],
                            $clean['postal_code'],
                            $conflictField,
                            $tokenHash,
                        ]);
                        $this->db->commit();
                        return [
                            'ok' => true,
                            'message' => 'Email is already verified.',
                            'already_verified' => true,
                            'status' => 200,
                        ];
                    }

                    // Check cumulative failure limit across resends
                    if ((int)$existing['cumulative_failed_attempts'] >= self::MAX_CUMULATIVE_FAILED_ATTEMPTS) {
                        $this->db->rollBack();
                        return [
                            'ok' => false,
                            'error' => 'Maximum verification attempts exceeded. Please start registration over.',
                            'status' => 429,
                        ];
                    }

                    // Enforce 60-second cooldown
                    if (!empty($existing['last_sent_at'])) {
                        $lastSent = strtotime($existing['last_sent_at'] . ' UTC');
                        $elapsed = time() - $lastSent;
                        if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
                            $retryAfter = self::RESEND_COOLDOWN_SECONDS - $elapsed;
                            $this->db->rollBack();
                            return [
                                'ok' => false,
                                'error' => "Please wait {$retryAfter} seconds before requesting a new verification code.",
                                'retry_after' => $retryAfter,
                                'status' => 429,
                            ];
                        }
                    }
                }
            }

            $otp = ($testOtp !== null && preg_match('/^[0-9]{6}$/', $testOtp)) ? $testOtp : self::generateOtp();
            $nowUtc = gmdate('Y-m-d H:i:s');
            $otpExpiresAtUtc = gmdate('Y-m-d H:i:s', time() + self::OTP_TTL_SECONDS);
            $pendingExpiresAtUtc = gmdate('Y-m-d H:i:s', time() + self::PENDING_REGISTRATION_LIFETIME_SECONDS);

            // 5. Establish pending record to acquire pending_id for HMAC binding
            if ($existing) {
                $pendingId = (int)$existing['pending_id'];
                $otpHash = ($conflictField === 'email') ? null : self::hashOtp($otp, $pendingId);

                $upd = $this->db->prepare(
                    'UPDATE customer_registration_pending
                     SET email = ?, username = ?, first_name = ?, middle_name = ?, last_name = ?,
                         phone = ?, address_line1 = ?, city = ?, province = ?, postal_code = ?,
                         otp_hash = ?, otp_expires_at = ?, otp_attempts = 0, last_sent_at = ?,
                         conflict_field = ?, expires_at = ?
                     WHERE session_token_hash = ?'
                );
                $upd->execute([
                    $email,
                    $clean['username'],
                    $clean['first_name'],
                    $clean['middle_name'],
                    $clean['last_name'],
                    $clean['phone'],
                    $clean['address_line1'],
                    $clean['city'],
                    $clean['province'],
                    $clean['postal_code'],
                    $otpHash,
                    $conflictField === 'email' ? null : $otpExpiresAtUtc,
                    $nowUtc,
                    $conflictField,
                    $pendingExpiresAtUtc,
                    $tokenHash,
                ]);
            } else {
                $ins = $this->db->prepare(
                    'INSERT INTO customer_registration_pending (
                        session_token_hash, email, username, first_name, middle_name, last_name,
                        phone, address_line1, city, province, postal_code,
                        otp_hash, otp_expires_at, otp_attempts, cumulative_failed_attempts,
                        last_sent_at, conflict_field, created_at, expires_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 0, 0, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $tokenHash,
                    $email,
                    $clean['username'],
                    $clean['first_name'],
                    $clean['middle_name'],
                    $clean['last_name'],
                    $clean['phone'],
                    $clean['address_line1'],
                    $clean['city'],
                    $clean['province'],
                    $clean['postal_code'],
                    $nowUtc,
                    $conflictField,
                    $nowUtc,
                    $pendingExpiresAtUtc,
                ]);
                $pendingId = (int)$this->db->lastInsertId();

                $otpHash = ($conflictField === 'email') ? null : self::hashOtp($otp, $pendingId);
                if ($otpHash !== null) {
                    $upd = $this->db->prepare(
                        'UPDATE customer_registration_pending
                         SET otp_hash = ?, otp_expires_at = ?
                         WHERE pending_id = ?'
                    );
                    $upd->execute([$otpHash, $otpExpiresAtUtc, $pendingId]);
                }
            }

            // 6. Attempt email dispatch via MailService
            // If conflictField === 'email', we do not send email to avoid spamming existing accounts (non-enumeration)
            if ($conflictField !== 'email') {
                MailService::sendCustomerRegistrationOtp($email, $otp, $clean['first_name']);
            }

            // 7. Record outbound send in log with consistent cleanIp
            $insLog = $this->db->prepare('INSERT INTO email_otp_send_log (email, ip_address, sent_at) VALUES (?, ?, ?)');
            $insLog->execute([$email, $cleanIp, $nowUtc]);

            $this->db->commit();

            // 8. Opportunistically clean expired records
            $this->cleanExpiredRecords();

            return [
                'ok' => true,
                'message' => 'A 6-digit verification code has been sent to your email.',
                'status' => 200,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof RuntimeException && ($e->getCode() === 502 || $e->getCode() === 503)) {
                return [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'status' => $e->getCode(),
                ];
            }
            return [
                'ok' => false,
                'error' => 'Unable to deliver verification code at this time. Please try again later.',
                'status' => 502,
            ];
        } finally {
            foreach ($locksAcquired as $lockName) {
                try {
                    $stmtRel = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                    $stmtRel->execute([$lockName]);
                } catch (Throwable $ignore) {
                }
            }
        }
    }

    /**
     * Verify a submitted 6-digit OTP code against the pending registration.
     * Enforces per-OTP attempts (5), cumulative failures (15), purpose + pending_id HMAC, and row locking.
     *
     * @param string $sessionToken Plain registration session token
     * @param string $submittedOtp 6-digit verification code entered by user
     * @param string $ipAddress Client IP address (uses DEFAULT_FALLBACK_IP if empty)
     * @return array{
     *   ok: bool,
     *   message?: string,
     *   error?: string,
     *   status: int,
     *   attempts_remaining?: int,
     *   invalidated?: bool,
     *   locked?: bool,
     *   expired?: bool,
     *   already_verified?: bool
     * }
     */
    public function verifyOtp(string $sessionToken, string $submittedOtp, string $ipAddress = ''): array
    {
        $submittedOtp = trim($submittedOtp);
        if (!preg_match('/^[0-9]{6}$/', $submittedOtp)) {
            return [
                'ok' => false,
                'error' => 'Verification code must be exactly 6 numeric digits.',
                'status' => 422,
            ];
        }

        $cleanIp = self::sanitizeIp($ipAddress);
        $tokenHash = self::hashSessionToken($sessionToken);

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare('SELECT * FROM customer_registration_pending WHERE session_token_hash = ? FOR UPDATE');
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'No pending registration found. Please start registration again.',
                    'status' => 404,
                ];
            }

            // Check if already verified
            if ($row['otp_verified_at'] !== null) {
                $this->db->rollBack();
                return [
                    'ok' => true,
                    'message' => 'Email is already verified.',
                    'already_verified' => true,
                    'status' => 200,
                ];
            }

            $now = time();
            $expiresAt = strtotime($row['expires_at'] . ' UTC');

            // Check overall pending session expiration
            if ($now > $expiresAt) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Registration session has expired. Please register again.',
                    'status' => 410,
                    'expired' => true,
                ];
            }

            // Check cumulative failure limit across resends
            if ((int)$row['cumulative_failed_attempts'] >= self::MAX_CUMULATIVE_FAILED_ATTEMPTS) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Maximum verification attempts exceeded across resends. Please start registration over.',
                    'status' => 429,
                    'locked' => true,
                ];
            }

            // Check active OTP existence and expiry
            if (empty($row['otp_hash']) || empty($row['otp_expires_at'])) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'No active verification code. Please request a new one.',
                    'status' => 400,
                ];
            }

            $otpExpiresAt = strtotime($row['otp_expires_at'] . ' UTC');
            if ($now > $otpExpiresAt) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Verification code has expired. Please request a new one.',
                    'status' => 410,
                    'expired' => true,
                ];
            }

            $currentAttempts = (int)$row['otp_attempts'];
            if ($currentAttempts >= self::MAX_ATTEMPTS_PER_OTP) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Verification code is no longer valid. Please request a new code.',
                    'status' => 410,
                    'invalidated' => true,
                ];
            }

            // Increment attempt counter before comparison
            $newAttempts = $currentAttempts + 1;
            $pendingId = (int)$row['pending_id'];

            // Timing-safe HMAC digest comparison bound to purpose and pending_id
            $submittedHash = self::hashOtp($submittedOtp, $pendingId);
            $isMatch = hash_equals((string)$row['otp_hash'], $submittedHash);

            $nowUtc = gmdate('Y-m-d H:i:s');

            if ($isMatch) {
                // Correct code succeeds (even on attempt 5)
                $upd = $this->db->prepare(
                    'UPDATE customer_registration_pending
                     SET otp_attempts = ?, otp_verified_at = ?
                     WHERE pending_id = ?'
                );
                $upd->execute([$newAttempts, $nowUtc, $pendingId]);
                $this->db->commit();

                return [
                    'ok' => true,
                    'message' => 'Email verified successfully.',
                    'status' => 200,
                ];
            }

            // Code is incorrect: record in failure log
            $insFail = $this->db->prepare(
                'INSERT INTO email_otp_failure_log (email, ip_address, failed_at) VALUES (?, ?, ?)'
            );
            $insFail->execute([$row['email'], $cleanIp, $nowUtc]);

            $newCumulative = (int)$row['cumulative_failed_attempts'] + 1;

            // 5th incorrect attempt invalidates the OTP
            if ($newAttempts >= self::MAX_ATTEMPTS_PER_OTP) {
                $upd = $this->db->prepare(
                    'UPDATE customer_registration_pending
                     SET otp_attempts = ?, cumulative_failed_attempts = ?, otp_hash = NULL, otp_expires_at = NULL
                     WHERE pending_id = ?'
                );
                $upd->execute([$newAttempts, $newCumulative, $pendingId]);
                $this->db->commit();

                return [
                    'ok' => false,
                    'error' => 'Verification code has been invalidated due to too many incorrect attempts. Please request a new code.',
                    'status' => 410,
                    'invalidated' => true,
                    'attempts_remaining' => 0,
                ];
            }

            // 15 cumulative incorrect attempts locks the pending registration
            if ($newCumulative >= self::MAX_CUMULATIVE_FAILED_ATTEMPTS) {
                $upd = $this->db->prepare(
                    'UPDATE customer_registration_pending
                     SET otp_attempts = ?, cumulative_failed_attempts = ?, otp_hash = NULL, otp_expires_at = NULL
                     WHERE pending_id = ?'
                );
                $upd->execute([$newAttempts, $newCumulative, $pendingId]);
                $this->db->commit();

                return [
                    'ok' => false,
                    'error' => 'Maximum verification attempts exceeded across resends. Please start registration over.',
                    'status' => 429,
                    'locked' => true,
                    'attempts_remaining' => 0,
                ];
            }

            // Standard incorrect attempt
            $remaining = self::MAX_ATTEMPTS_PER_OTP - $newAttempts;
            $upd = $this->db->prepare(
                'UPDATE customer_registration_pending
                 SET otp_attempts = ?, cumulative_failed_attempts = ?
                 WHERE pending_id = ?'
            );
            $upd->execute([$newAttempts, $newCumulative, $pendingId]);
            $this->db->commit();

            return [
                'ok' => false,
                'error' => "Incorrect verification code. You have {$remaining} attempt(s) remaining.",
                'status' => 422,
                'attempts_remaining' => $remaining,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return [
                'ok' => false,
                'error' => 'An error occurred during verification. Please try again.',
                'status' => 500,
            ];
        }
    }

    /**
     * Resend an OTP code for an active pending registration subject to 60s cooldown and hourly limits.
     * Enforces concurrency-safe rate limit locking and pending_id bound HMAC hashing.
     *
     * @param string $sessionToken Plain registration session token
     * @param string $ipAddress Client IP address (uses DEFAULT_FALLBACK_IP if empty)
     * @param string|null $testOtp Optional test-only OTP override
     * @return array{ok: bool, message?: string, error?: string, status: int, retry_after?: int}
     */
    public function resendOtp(string $sessionToken, string $ipAddress = '', ?string $testOtp = null): array
    {
        $cleanIp = self::sanitizeIp($ipAddress);
        $tokenHash = self::hashSessionToken($sessionToken);

        // Pre-fetch pending row email for rate limit lock
        $pre = $this->db->prepare('SELECT email FROM customer_registration_pending WHERE session_token_hash = ?');
        $pre->execute([$tokenHash]);
        $preEmail = (string)$pre->fetchColumn();

        $emailLock = $preEmail !== '' ? ('pc_em_' . md5($preEmail)) : null;
        $ipLock = 'pc_ip_' . md5($cleanIp);
        $locksAcquired = [];

        try {
            if ($emailLock !== null) {
                $stmtLock1 = $this->db->prepare('SELECT GET_LOCK(?, 10)');
                $stmtLock1->execute([$emailLock]);
                if ((int)$stmtLock1->fetchColumn() !== 1) {
                    return ['ok' => false, 'error' => 'Rate limit verification timeout. Please try again.', 'status' => 429];
                }
                $locksAcquired[] = $emailLock;
            }

            $stmtLock2 = $this->db->prepare('SELECT GET_LOCK(?, 10)');
            $stmtLock2->execute([$ipLock]);
            if ((int)$stmtLock2->fetchColumn() !== 1) {
                return ['ok' => false, 'error' => 'Rate limit verification timeout. Please try again.', 'status' => 429];
            }
            $locksAcquired[] = $ipLock;

            $this->db->beginTransaction();

            $stmt = $this->db->prepare('SELECT * FROM customer_registration_pending WHERE session_token_hash = ? FOR UPDATE');
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'No pending registration found. Please start registration again.',
                    'status' => 404,
                ];
            }

            if ($row['otp_verified_at'] !== null) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Email is already verified.',
                    'status' => 400,
                ];
            }

            $now = time();
            $expiresAt = strtotime($row['expires_at'] . ' UTC');
            if ($now > $expiresAt) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Registration session has expired. Please register again.',
                    'status' => 410,
                ];
            }

            if ((int)$row['cumulative_failed_attempts'] >= self::MAX_CUMULATIVE_FAILED_ATTEMPTS) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Maximum verification attempts exceeded. Please start registration over.',
                    'status' => 429,
                ];
            }

            // Enforce 60-second resend cooldown
            if (!empty($row['last_sent_at'])) {
                $lastSent = strtotime($row['last_sent_at'] . ' UTC');
                $elapsed = $now - $lastSent;
                if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
                    $retryAfter = self::RESEND_COOLDOWN_SECONDS - $elapsed;
                    $this->db->rollBack();
                    return [
                        'ok' => false,
                        'error' => "Please wait {$retryAfter} seconds before requesting a new verification code.",
                        'retry_after' => $retryAfter,
                        'status' => 429,
                    ];
                }
            }

            // Enforce hourly send limits
            $oneHourAgo = gmdate('Y-m-d H:i:s', $now - 3600);
            $chkEmail = $this->db->prepare('SELECT COUNT(*) FROM email_otp_send_log WHERE email = ? AND sent_at >= ?');
            $chkEmail->execute([$row['email'], $oneHourAgo]);
            if ((int)$chkEmail->fetchColumn() >= self::MAX_HOURLY_SENDS_PER_EMAIL) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Too many verification code requests for this email. Please try again in an hour.',
                    'status' => 429,
                ];
            }

            $chkIp = $this->db->prepare('SELECT COUNT(*) FROM email_otp_send_log WHERE ip_address = ? AND sent_at >= ?');
            $chkIp->execute([$cleanIp, $oneHourAgo]);
            if ((int)$chkIp->fetchColumn() >= self::MAX_HOURLY_SENDS_PER_IP) {
                $this->db->rollBack();
                return [
                    'ok' => false,
                    'error' => 'Too many verification code requests from this network. Please try again in an hour.',
                    'status' => 429,
                ];
            }

            $otp = ($testOtp !== null && preg_match('/^[0-9]{6}$/', $testOtp)) ? $testOtp : self::generateOtp();
            $pendingId = (int)$row['pending_id'];
            $otpHash = self::hashOtp($otp, $pendingId);
            $nowUtc = gmdate('Y-m-d H:i:s');
            $otpExpiresAtUtc = gmdate('Y-m-d H:i:s', $now + self::OTP_TTL_SECONDS);

            // Dispatch email via MailService (if not email conflict decoy)
            if (($row['conflict_field'] ?? null) !== 'email') {
                MailService::sendCustomerRegistrationOtp($row['email'], $otp, $row['first_name']);
            }

            // Update pending record and reset per-OTP attempts (preserve cumulative)
            $upd = $this->db->prepare(
                'UPDATE customer_registration_pending
                 SET otp_hash = ?, otp_expires_at = ?, otp_attempts = 0, last_sent_at = ?
                 WHERE pending_id = ?'
            );
            $upd->execute([
                ($row['conflict_field'] ?? null) === 'email' ? null : $otpHash,
                ($row['conflict_field'] ?? null) === 'email' ? null : $otpExpiresAtUtc,
                $nowUtc,
                $pendingId,
            ]);

            // Log outbound send with consistent cleanIp
            $insLog = $this->db->prepare('INSERT INTO email_otp_send_log (email, ip_address, sent_at) VALUES (?, ?, ?)');
            $insLog->execute([$row['email'], $cleanIp, $nowUtc]);

            $this->db->commit();
            $this->cleanExpiredRecords();

            return [
                'ok' => true,
                'message' => 'A new 6-digit verification code has been sent to your email.',
                'status' => 200,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof RuntimeException && ($e->getCode() === 502 || $e->getCode() === 503)) {
                return [
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'status' => $e->getCode(),
                ];
            }
            return [
                'ok' => false,
                'error' => 'Unable to deliver verification code at this time. Please try again later.',
                'status' => 502,
            ];
        } finally {
            foreach ($locksAcquired as $lockName) {
                try {
                    $stmtRel = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                    $stmtRel->execute([$lockName]);
                } catch (Throwable $ignore) {
                }
            }
        }
    }

    /**
     * Retrieve a pending registration record by session token.
     *
     * @param string $sessionToken
     * @return array<string, mixed>|null
     */
    public function getPendingRegistration(string $sessionToken): ?array
    {
        $tokenHash = self::hashSessionToken($sessionToken);
        $stmt = $this->db->prepare('SELECT * FROM customer_registration_pending WHERE session_token_hash = ?');
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Check whether an active, non-expired registration is verified.
     */
    public function isVerified(string $sessionToken): bool
    {
        $row = $this->getPendingRegistration($sessionToken);
        if (!$row) {
            return false;
        }
        if ($row['otp_verified_at'] === null) {
            return false;
        }
        $now = time();
        $expiresAt = strtotime($row['expires_at'] . ' UTC');
        return $now <= $expiresAt;
    }

    /**
     * Delete a pending registration by session token.
     */
    public function deletePendingRegistration(string $sessionToken): bool
    {
        $tokenHash = self::hashSessionToken($sessionToken);
        $stmt = $this->db->prepare('DELETE FROM customer_registration_pending WHERE session_token_hash = ?');
        return $stmt->execute([$tokenHash]);
    }

    /**
     * Clean expired pending registrations and purge old log entries.
     */
    public function cleanExpiredRecords(int $logRetentionDays = self::LOG_RETENTION_DAYS): int
    {
        $deleted = 0;
        $nowUtc = gmdate('Y-m-d H:i:s');

        try {
            $delPending = $this->db->prepare('DELETE FROM customer_registration_pending WHERE expires_at < ?');
            $delPending->execute([$nowUtc]);
            $deleted += $delPending->rowCount();

            $logCutoff = gmdate('Y-m-d H:i:s', time() - ($logRetentionDays * 86400));

            $delSendLogs = $this->db->prepare('DELETE FROM email_otp_send_log WHERE sent_at < ?');
            $delSendLogs->execute([$logCutoff]);
            $deleted += $delSendLogs->rowCount();

            $delFailLogs = $this->db->prepare('DELETE FROM email_otp_failure_log WHERE failed_at < ?');
            $delFailLogs->execute([$logCutoff]);
            $deleted += $delFailLogs->rowCount();
        } catch (Throwable $e) {
            // Retention cleanup is best-effort and non-fatal
        }

        return $deleted;
    }

    /**
     * Validate profile input fields according to application schema rules.
     *
     * @param array<string, mixed> $data
     * @return array{ok: bool, clean: array<string, mixed>, errors: array<string, string>}
     */
    private function validateProfileInput(array $data): array
    {
        $errors = [];

        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address, for example name@example.com.';
        }

        $firstName = trim((string)($data['first_name'] ?? ''));
        if ($firstName === '' || mb_strlen($firstName) > 100) {
            $errors['first_name'] = 'Enter your first name (max 100 characters).';
        }

        $lastName = trim((string)($data['last_name'] ?? ''));
        if ($lastName === '' || mb_strlen($lastName) > 100) {
            $errors['last_name'] = 'Enter your last name (max 100 characters).';
        }

        $middleName = trim((string)($data['middle_name'] ?? ''));
        if ($middleName !== '' && mb_strlen($middleName) > 100) {
            $errors['middle_name'] = 'Maximum 100 characters.';
        }

        $username = trim((string)($data['username'] ?? ''));
        if ($username !== '') {
            $username = strtolower($username);
            if (!preg_match('/^[a-z0-9_.]{3,60}$/', $username)) {
                $errors['username'] = 'Username must be 3–60 characters (letters, digits, underscores, dots).';
            }
        }

        $phone = trim((string)($data['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^[0-9+()\-\s]{6,30}$/', $phone)) {
            $errors['phone'] = 'Enter a valid phone number.';
        }

        $addressLine1 = trim((string)($data['address_line1'] ?? ''));
        if (mb_strlen($addressLine1) > 255) {
            $errors['address_line1'] = 'Maximum 255 characters.';
        }

        $city = trim((string)($data['city'] ?? ''));
        if (mb_strlen($city) > 100) {
            $errors['city'] = 'Maximum 100 characters.';
        }

        $province = trim((string)($data['province'] ?? ''));
        if (mb_strlen($province) > 100) {
            $errors['province'] = 'Maximum 100 characters.';
        }

        $postalCode = trim((string)($data['postal_code'] ?? ''));
        if ($postalCode !== '' && !preg_match('/^[0-9]{4}$/', $postalCode)) {
            $errors['postal_code'] = 'Postal code must be exactly 4 numeric digits.';
        }

        return [
            'ok' => empty($errors),
            'clean' => [
                'email' => $email,
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'username' => $username !== '' ? $username : null,
                'phone' => $phone !== '' ? $phone : null,
                'address_line1' => $addressLine1 !== '' ? $addressLine1 : null,
                'city' => $city !== '' ? $city : null,
                'province' => $province !== '' ? $province : null,
                'postal_code' => $postalCode !== '' ? $postalCode : null,
            ],
            'errors' => $errors,
        ];
    }
}
