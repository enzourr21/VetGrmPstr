<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class EnvConfig
{
    private static ?array $envCache = null;
    private static ?string $customEnvPath = null;

    /**
     * Override the .env file path (used strictly by isolated test harnesses) and clear the cache.
     */
    public static function setEnvPathForTesting(?string $path): void
    {
        self::$customEnvPath = $path;
        self::$envCache = null;
    }

    /**
     * Clear the cached environment array.
     */
    public static function resetCache(): void
    {
        self::$envCache = null;
    }

    /**
     * Parse .env line-by-line without using parse_ini_file(), safely preserving
     * special characters (;, #, =, !, $, ^, &, *, parentheses) and quoted strings
     * in SMTP passwords and 32+ byte HMAC secrets.
     *
     * @return array<string, string>
     */
    private static function loadEnvFile(): array
    {
        if (self::$envCache !== null) {
            return self::$envCache;
        }

        $envPath = self::$customEnvPath ?? (defined('APP_ROOT') ? APP_ROOT . '/.env' : dirname(__DIR__, 2) . '/.env');
        if (!is_file($envPath) || !is_readable($envPath)) {
            self::$envCache = [];
            return self::$envCache;
        }

        $lines = @file($envPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            self::$envCache = [];
            return self::$envCache;
        }

        $parsed = [];
        foreach ($lines as $rawLine) {
            // Strip UTF-8 BOM if present on the first line
            if (str_starts_with($rawLine, "\xEF\xBB\xBF")) {
                $rawLine = substr($rawLine, 3);
            }

            $trimmed = trim($rawLine);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = ltrim(substr($trimmed, 7));
            }

            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $rawVal = substr($trimmed, $eqPos + 1);
            $parsed[$key] = self::parseEnvValue($rawVal, $key);
        }

        self::$envCache = $parsed;
        return self::$envCache;
    }

    /**
     * Parse an individual .env value, handling single quotes, double quotes,
     * escaped characters, and unquoted values with special characters.
     * Rejects unterminated quotes or unexpected trailing characters after a closing quote.
     */
    private static function parseEnvValue(string $rawVal, string $key = ''): string
    {
        $val = trim($rawVal);
        if ($val === '') {
            return '';
        }

        $firstChar = $val[0];
        $keyLabel = $key !== '' ? " for {$key}" : '';

        // Single-quoted or double-quoted value
        if ($firstChar === "'" || $firstChar === '"') {
            $endQuote = self::findClosingQuote($val, $firstChar);
            if ($endQuote === null) {
                throw new RuntimeException(
                    "Invalid environment configuration{$keyLabel}: unterminated quoted value.",
                    503
                );
            }

            // Validate remaining characters after the closing quote: only whitespace or a '#...' comment is allowed
            $remainder = trim(substr($val, $endQuote + 1));
            if ($remainder !== '' && !str_starts_with($remainder, '#')) {
                throw new RuntimeException(
                    "Invalid environment configuration{$keyLabel}: unexpected trailing characters after closing quote.",
                    503
                );
            }

            $inner = substr($val, 1, $endQuote - 1);
            if ($firstChar === "'") {
                return str_replace(["\\'", "\\\\"], ["'", "\\"], $inner);
            }

            return str_replace(['\\"', '\\\\', '\\$'], ['"', '\\', '$'], $inner);
        }

        // Unquoted value: strip trailing inline comment only if preceded by whitespace (e.g. "value # comment")
        // Preserves '#' characters that are part of an unquoted secret/password (e.g. "MyP@ss#123!").
        $commentPos = preg_match('/\s+#/', $val, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        if ($commentPos !== false) {
            $val = rtrim(substr($val, 0, $commentPos));
        }

        return $val;
    }

    /**
     * Locate the index of the closing quote character, ignoring escaped quotes.
     */
    private static function findClosingQuote(string $str, string $quoteChar): ?int
    {
        $len = strlen($str);
        $escaped = false;
        for ($i = 1; $i < $len; $i++) {
            $ch = $str[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                continue;
            }
            if ($ch === $quoteChar) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Retrieve a configuration string.
     * Precedence: Process environment (getenv / $_ENV) first, then .env file, then $default.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $proc = getenv($key);
        if ($proc !== false) {
            return (string)$proc;
        }

        if (isset($_ENV[$key]) && is_scalar($_ENV[$key])) {
            return (string)$_ENV[$key];
        }

        $fileEnv = self::loadEnvFile();
        if (array_key_exists($key, $fileEnv)) {
            return $fileEnv[$key];
        }

        return $default;
    }

    /**
     * Retrieve and validate the server-side HMAC secret for customer email OTP digests.
     * Fails closed (RuntimeException with code 503) if missing, placeholder, or shorter than 32 bytes.
     */
    public static function otpHmacSecret(): string
    {
        $secret = self::get('OTP_HMAC_SECRET', '');
        if ($secret === null || $secret === '') {
            throw new RuntimeException('Email verification is temporarily unavailable (missing OTP_HMAC_SECRET).', 503);
        }

        $lower = strtolower(trim($secret));
        if (
            str_contains($lower, 'replace_with') ||
            str_contains($lower, 'changeme') ||
            str_contains($lower, 'your_secret') ||
            str_contains($lower, 'example')
        ) {
            throw new RuntimeException('Email verification is temporarily unavailable (placeholder OTP_HMAC_SECRET).', 503);
        }

        if (strlen($secret) < 32) {
            throw new RuntimeException('Email verification is temporarily unavailable (OTP_HMAC_SECRET must be at least 32 bytes).', 503);
        }

        return $secret;
    }

    /**
     * Retrieve and validate mail service configuration.
     * Fails closed (RuntimeException with code 503) if MAIL_DRIVER is not explicitly set or if SMTP settings are incomplete/insecure.
     *
     * @return array{
     *   driver: string,
     *   host: string,
     *   port: int,
     *   username: string,
     *   password: string,
     *   encryption: string,
     *   from_address: string,
     *   from_name: string,
     *   verify_peer: bool
     * }
     */
    public static function mailConfig(): array
    {
        $driverRaw = self::get('MAIL_DRIVER', '');
        $driver = strtolower(trim((string)$driverRaw));

        if (!in_array($driver, ['smtp', 'null'], true)) {
            throw new RuntimeException('Email service is temporarily unavailable (MAIL_DRIVER must be explicitly set to "smtp" or "null").', 503);
        }

        $fromAddress = trim((string)self::get('MAIL_FROM_ADDRESS', ''));
        $fromName = trim((string)self::get('MAIL_FROM_NAME', 'PawCariño'));

        if ($driver === 'null') {
            $nullFrom = ($fromAddress !== '' && filter_var($fromAddress, FILTER_VALIDATE_EMAIL) !== false)
                ? $fromAddress
                : 'noreply@pawcarino.local';

            return [
                'driver' => 'null',
                'host' => '',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from_address' => $nullFrom,
                'from_name' => $fromName !== '' ? $fromName : 'PawCariño',
                'verify_peer' => true,
            ];
        }

        // SMTP driver: enforce authenticated TLS/SSL transport, strict certificate verification, and valid sender identity
        $host = trim((string)self::get('MAIL_HOST', ''));
        $portRaw = trim((string)self::get('MAIL_PORT', ''));
        $username = trim((string)self::get('MAIL_USERNAME', ''));
        $password = (string)self::get('MAIL_PASSWORD', '');
        $encryption = strtolower(trim((string)self::get('MAIL_ENCRYPTION', '')));

        if ($host === '') {
            throw new RuntimeException('Email service is temporarily unavailable (missing MAIL_HOST for SMTP).', 503);
        }

        if ($portRaw === '' || !ctype_digit($portRaw)) {
            throw new RuntimeException('Email service is temporarily unavailable (invalid MAIL_PORT for SMTP).', 503);
        }
        $port = (int)$portRaw;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Email service is temporarily unavailable (MAIL_PORT out of range).', 503);
        }

        if ($username === '' || $password === '') {
            throw new RuntimeException('Email service is temporarily unavailable (authenticated SMTP credentials required).', 503);
        }

        if (!in_array($encryption, ['tls', 'ssl', 'starttls', 'smtps'], true)) {
            throw new RuntimeException('Email service is temporarily unavailable (MAIL_ENCRYPTION must be "tls" or "ssl").', 503);
        }

        // Normalize to 'tls' (STARTTLS) or 'ssl' (implicit TLS/SMTPS) for PHPMailer
        $normalizedEncryption = ($encryption === 'ssl' || $encryption === 'smtps') ? 'ssl' : 'tls';

        if (
            $fromAddress === '' ||
            strlen($fromAddress) > 255 ||
            filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new RuntimeException('Email service is temporarily unavailable (valid MAIL_FROM_ADDRESS required for SMTP).', 503);
        }

        return [
            'driver' => 'smtp',
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => $normalizedEncryption,
            'from_address' => $fromAddress,
            'from_name' => $fromName !== '' ? $fromName : 'PawCariño',
            'verify_peer' => true,
        ];
    }

    /**
     * Check whether both OTP HMAC secret and Mail configuration are valid and ready.
     */
    public static function assertOtpRegistrationReady(): void
    {
        self::otpHmacSecret();
        self::mailConfig();
    }
}