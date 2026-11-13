<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\EnvConfig;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;
use Throwable;

final class MailService
{
    private const SMTP_TIMEOUT_SECONDS = 15;
    private const SMTP_TIMELIMIT_SECONDS = 15;
    private const OTP_EXPIRY_MINUTES = 5;

    /**
     * Send a 6-digit registration verification OTP email to a prospective customer.
     * The OTP is strictly excluded from the email subject and never written to logs.
     *
     * @throws RuntimeException If mail configuration is invalid/unavailable (code 503) or delivery fails (code 502).
     */
    public static function sendCustomerRegistrationOtp(
        string $recipientEmail,
        string $otpCode,
        string $firstName = ''
    ): bool {
        $recipientEmail = strtolower(trim($recipientEmail));
        if ($recipientEmail === '' || strlen($recipientEmail) > 255 || filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid recipient email address.', 422);
        }

        if (!preg_match('/^[0-9]{6}$/', $otpCode)) {
            throw new RuntimeException('Invalid verification code format.', 500);
        }

        $subject = 'Verify your PawCariño email address';
        $greetingName = trim($firstName) !== '' ? trim($firstName) : 'there';

        $htmlBody = self::buildOtpHtmlBody($greetingName, $otpCode, self::OTP_EXPIRY_MINUTES);
        $textBody = self::buildOtpTextBody($greetingName, $otpCode, self::OTP_EXPIRY_MINUTES);

        return self::deliver($recipientEmail, $firstName, $subject, $htmlBody, $textBody);
    }

    /**
     * Dispatch an email message using the configured driver ('smtp' or 'null').
     *
     * @throws RuntimeException If configuration fails (503) or SMTP transport fails (502).
     */
    private static function deliver(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlBody,
        string $textBody
    ): bool {
        // Retrieve and validate configuration via EnvConfig (throws RuntimeException 503 on invalid config)
        $config = EnvConfig::mailConfig();

        if ($config['driver'] === 'null') {
            // Null driver: intentional no-op for automated tests and local dev without logging bodies or OTPs
            return true;
        }

        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('Email service is temporarily unavailable (mailer library not loaded).', 503);
        }

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;

            // Enforce authenticated SMTP with zero debug output and bounded connection/command timeouts
            $mail->isSMTP();
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->Host = $config['host'];
            $mail->Port = $config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->Timeout = self::SMTP_TIMEOUT_SECONDS;
            $mail->getSMTPInstance()->Timelimit = self::SMTP_TIMELIMIT_SECONDS;

            // Enforce STARTTLS or implicit TLS/SMTPS and prevent silent downgrade to unencrypted SMTP
            $mail->SMTPAutoTLS = true;
            if ($config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            // Strictly enforce TLS peer certificate and hostname verification
            $verifyPeer = (bool)($config['verify_peer'] ?? true);
            if (!$verifyPeer) {
                throw new RuntimeException('Email service is temporarily unavailable (insecure TLS configuration rejected).', 503);
            }

            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'peer_name' => $config['host'],
                ],
            ];

            $mail->setFrom($config['from_address'], $config['from_name']);
            $cleanRecipientName = preg_replace('/[\r\n]+/', ' ', trim($recipientName)) ?? '';
            $mail->addAddress($recipientEmail, $cleanRecipientName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;

            if (!$mail->send()) {
                throw new RuntimeException('Unable to deliver verification email at this time. Please try again later.', 502);
            }

            return true;
        } catch (PHPMailerException $e) {
            // Never log or expose SMTP credentials, recipient payloads, or OTP codes
            throw new RuntimeException('Unable to deliver verification email at this time. Please try again later.', 502);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to deliver verification email at this time. Please try again later.', 502);
        }
    }

    /**
     * Build the escaped HTML body for the 6-digit verification code email.
     */
    private static function buildOtpHtmlBody(string $greetingName, string $otpCode, int $expiryMinutes): string
    {
        $safeName = self::escapeHtml($greetingName);
        $safeOtp = self::escapeHtml($otpCode);
        $safeMinutes = (string)$expiryMinutes;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify your PawCariño email address</title>
</head>
<body style="margin:0;padding:24px;background-color:#f6f7f9;font-family:system-ui,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;line-height:1.55;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background-color:#ffffff;border:1px solid #e5e7eb;border-radius:14px;padding:28px;">
    <tr>
      <td>
        <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;color:#0d6e57;">PawCariño</p>
        <h1 style="margin:0 0 16px;font-size:22px;font-weight:700;color:#111827;">Verify your email address</h1>
        <p style="margin:0 0 16px;font-size:15px;color:#374151;">Hi {$safeName},</p>
        <p style="margin:0 0 20px;font-size:15px;color:#374151;">Use the 6-digit verification code below to confirm your email address and continue creating your PawCariño customer account:</p>
        <div style="margin:0 0 20px;padding:16px;background-color:#f2f7f5;border:1px solid #d1d5db;border-radius:10px;text-align:center;">
          <span style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:28px;font-weight:700;letter-spacing:0.22em;color:#0d6e57;">{$safeOtp}</span>
        </div>
        <p style="margin:0 0 12px;font-size:14px;color:#4b5563;">This code is valid for <strong>{$safeMinutes} minutes</strong>. For your security, do not share this code with anyone.</p>
        <p style="margin:0;font-size:13px;color:#6b7280;">If you did not request this verification code, you can safely ignore this email. No account will be created without verification.</p>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Build the plain-text body for the 6-digit verification code email.
     */
    private static function buildOtpTextBody(string $greetingName, string $otpCode, int $expiryMinutes): string
    {
        $cleanName = preg_replace('/[\r\n]+/', ' ', $greetingName) ?? 'there';

        return "PawCariño — Verify your email address\n\n"
            . "Hi {$cleanName},\n\n"
            . "Use the 6-digit verification code below to confirm your email address and continue creating your PawCariño customer account:\n\n"
            . "Verification Code: {$otpCode}\n\n"
            . "This code is valid for {$expiryMinutes} minutes. For your security, do not share this code with anyone.\n\n"
            . "If you did not request this verification code, you can safely ignore this email. No account will be created without verification.\n";
    }

    /**
     * Escape dynamic strings for safe HTML interpolation (matches global e() helper).
     */
    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}