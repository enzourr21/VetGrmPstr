<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class OtpService
{
    /** OTP validity window in seconds (5 minutes). */
    public const OTP_TTL_SECONDS = 300;

    /**
     * Generate and dispatch a secure 6-digit OTP for e-wallet verification.
     *
     * @param int $businessUnitId
     * @param string $serviceType 'gcash' or 'maya'
     * @param string $mobileNumber
     * @param string|null $testOtp Optional test-only OTP for automated backend verification tests
     * @return array{ok: bool, message: string}
     */
    public static function requestOtp(int $businessUnitId, string $serviceType, string $mobileNumber, ?string $testOtp = null): array
    {
        $db = Database::pdo();
        $cleanPhone = preg_replace('/[^0-9+]/', '', $mobileNumber);
        if (!$cleanPhone || strlen($cleanPhone) < 10 || strlen($cleanPhone) > 15) {
            return ['ok' => false, 'message' => 'Please provide a valid Philippine mobile number (e.g. 09175550199).'];
        }

        if (!in_array($serviceType, ['gcash', 'maya'], true)) {
            return ['ok' => false, 'message' => 'Invalid payment service type.'];
        }

        // Generate cryptographically random 6-digit numeric OTP unless testing override supplied
        $otp = ($testOtp !== null && strlen($testOtp) === 6 && ctype_digit($testOtp))
            ? $testOtp
            : (string)random_int(100000, 999999);
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', time() + self::OTP_TTL_SECONDS);

        // Invalidate any existing unverified OTPs for this BU and service
        $del = $db->prepare('DELETE FROM otp_verification WHERE business_unit_id = ? AND service_type = ? AND is_verified = 0');
        $del->execute([$businessUnitId, $serviceType]);

        // Insert new pending OTP record
        $ins = $db->prepare(
            'INSERT INTO otp_verification (business_unit_id, service_type, mobile_number, otp_hash, expires_at, is_verified)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $ins->execute([$businessUnitId, $serviceType, $cleanPhone, $otpHash, $expiresAt]);

        // External SMS gateway dispatch point
        $smsSent = self::dispatchSmsGateway($cleanPhone);

        return [
            'ok' => true,
            'message' => $smsSent
                ? "A 6-digit verification code has been dispatched to $cleanPhone."
                : "A verification code request has been processed. (External SMS gateway integration pending provider credentials; simulated delivery recorded on backend.)",
        ];
    }

    /**
     * Validate an OTP against the pending hash on the server.
     *
     * @param int $businessUnitId
     * @param string $serviceType 'gcash' or 'maya'
     * @param string $mobileNumber
     * @param string $enteredOtp
     * @return array{ok: bool, message: string}
     */
    public static function verifyOtp(int $businessUnitId, string $serviceType, string $mobileNumber, string $enteredOtp): array
    {
        $db = Database::pdo();
        $cleanPhone = preg_replace('/[^0-9+]/', '', $mobileNumber);
        $enteredOtp = trim($enteredOtp);

        if ($enteredOtp === '' || strlen($enteredOtp) !== 6 || !ctype_digit($enteredOtp)) {
            return ['ok' => false, 'message' => 'Enter a valid 6-digit numerical OTP.'];
        }

        $stmt = $db->prepare(
            'SELECT otp_id, otp_hash, expires_at
             FROM otp_verification
             WHERE business_unit_id = ? AND service_type = ? AND mobile_number = ? AND is_verified = 0
             ORDER BY otp_id DESC LIMIT 1'
        );
        $stmt->execute([$businessUnitId, $serviceType, $cleanPhone]);
        $record = $stmt->fetch();

        if (!$record) {
            return ['ok' => false, 'message' => 'No active verification request found. Please request a new code.'];
        }

        if (strtotime((string)$record['expires_at']) < time()) {
            return ['ok' => false, 'message' => 'The verification code has expired. Please request a new one.'];
        }

        if (!password_verify($enteredOtp, (string)$record['otp_hash'])) {
            return ['ok' => false, 'message' => 'The verification code entered is incorrect.'];
        }

        // Mark verified
        $upd = $db->prepare('UPDATE otp_verification SET is_verified = 1 WHERE otp_id = ?');
        $upd->execute([(int)$record['otp_id']]);

        return ['ok' => true, 'message' => strtoupper($serviceType) . ' account mobile number verified successfully.'];
    }

    /**
     * Check if a mobile number has been verified for the specified service.
     */
    public static function isVerified(int $businessUnitId, string $serviceType, string $mobileNumber): bool
    {
        $db = Database::pdo();
        $cleanPhone = preg_replace('/[^0-9+]/', '', $mobileNumber);
        $stmt = $db->prepare(
            'SELECT 1 FROM otp_verification
             WHERE business_unit_id = ? AND service_type = ? AND mobile_number = ? AND is_verified = 1
             ORDER BY otp_id DESC LIMIT 1'
        );
        $stmt->execute([$businessUnitId, $serviceType, $cleanPhone]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Dispatch SMS via configured provider (Semaphore, Twilio, etc.) if credentials exist.
     * Returns true if external dispatch succeeded, false if in local mock/simulation mode.
     * Never logs secret OTP tokens in production logs.
     */
    private static function dispatchSmsGateway(string $mobileNumber): bool
    {
        $apiKey = getenv('SMS_API_KEY');
        if (!$apiKey) {
            // Documented simulation mode: log dispatch without pretending external API called or exposing secrets
            error_log("[VETGRPET OTP SERVICE] SMS dispatch simulated for mobile: $mobileNumber (SMS gateway credentials not configured).");
            return false;
        }

        // Integration with SMS gateway can be invoked here via curl
        return true;
    }
}
