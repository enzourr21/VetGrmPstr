<?php
declare(strict_types=1);

/**
 * Isolated foundation verification for Employee Invitation Checkpoint 1:
 *  - EnvConfig::appUrl() validation and normalization
 *  - MailService::sendEmployeeInvitation() input validation, fail-closed config handling,
 *    and null-driver SMTP submission reporting (no live SMTP or DB connections)
 *  - Static structure & migrate.php parser check for 029_employee_invitations.sql + down script
 */

require dirname(__DIR__) . '/app/Core/EnvConfig.php';
require dirname(__DIR__) . '/app/Services/MailService.php';

use App\Core\EnvConfig;
use App\Services\MailService;

$tempFile = tempnam(sys_get_temp_dir(), 'pawcarino-inv-cp1-');
if ($tempFile === false) {
    throw new RuntimeException('Could not create temporary test env file.');
}

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "[PASS] {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
    }
}

function expect503(callable $test): void
{
    try {
        $test();
    } catch (RuntimeException $e) {
        if ($e->getCode() === 503) {
            return;
        }
        throw new RuntimeException("Expected 503, got {$e->getCode()}: {$e->getMessage()}");
    }
    throw new RuntimeException('Expected RuntimeException with code 503.');
}

function loadEnv(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not write temporary env file.');
    }
    EnvConfig::setEnvPathForTesting($path);
}

// Clear process env overrides for deterministic testing
foreach (['APP_URL', 'MAIL_DRIVER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'] as $k) {
    putenv($k);
    unset($_ENV[$k]);
}

try {
    // --- 1. EnvConfig::appUrl() ---
    check('Missing APP_URL fails closed with 503', function () use ($tempFile) {
        loadEnv($tempFile, "MAIL_DRIVER=null\n");
        expect503(fn() => EnvConfig::appUrl());
    });

    check('Empty APP_URL fails closed with 503', function () use ($tempFile) {
        loadEnv($tempFile, "APP_URL=\n");
        expect503(fn() => EnvConfig::appUrl());
    });

    foreach ([
        'ftp://example.test' => 'unsupported scheme',
        'javascript:alert(1)' => 'javascript scheme',
        'http://' => 'missing host',
        'not-a-url' => 'malformed URL',
        'https://user:pass@example.test' => 'embedded credentials',
        'https://example.test?foo=bar' => 'query string',
        'https://example.test#frag' => 'fragment',
        'https://example.test/../../etc' => 'path traversal',
        'https://example.test:99999' => 'port out of range',
        'https://replace_with_domain.example' => 'placeholder value',
        'https://your-domain.com' => 'your-domain placeholder',
        'http://example.test/a b' => 'whitespace in URL',
    ] as $badUrl => $label) {
        check("Invalid APP_URL rejected ({$label})", function () use ($tempFile, $badUrl) {
            loadEnv($tempFile, "APP_URL=\"{$badUrl}\"\n");
            expect503(fn() => EnvConfig::appUrl());
        });
    }

    check('Valid http APP_URL with port and trailing slash is normalized', function () use ($tempFile) {
        loadEnv($tempFile, "APP_URL=HTTP://127.0.0.1:8080/\n");
        $url = EnvConfig::appUrl();
        if ($url !== 'http://127.0.0.1:8080') {
            throw new RuntimeException("Unexpected normalized URL: {$url}");
        }
    });

    check('Valid https APP_URL with subpath strips trailing slash', function () use ($tempFile) {
        loadEnv($tempFile, "APP_URL=https://Portal.PawCarino.test/app/\n");
        $url = EnvConfig::appUrl();
        if ($url !== 'https://portal.pawcarino.test/app') {
            throw new RuntimeException("Unexpected normalized URL: {$url}");
        }
    });

    // --- 2. MailService::sendEmployeeInvitation() ---
    check('sendEmployeeInvitation returns submitted=true under null driver', function () use ($tempFile) {
        loadEnv($tempFile, "MAIL_DRIVER=null\n");
        $res = MailService::sendEmployeeInvitation(
            'carla@example.test',
            'Carla Mendoza',
            'Pet Store',
            'Receptionist',
            'http://127.0.0.1:8080/invitations/accept?token=abcdef0123456789'
        );
        if ($res['submitted'] !== true || $res['driver'] !== 'null' || $res['error'] !== null || $res['status_code'] !== 200) {
            throw new RuntimeException('Unexpected result: ' . json_encode($res));
        }
    });

    check('sendEmployeeInvitation fails closed with 503 when MAIL_DRIVER is missing', function () use ($tempFile) {
        loadEnv($tempFile, "APP_URL=http://127.0.0.1:8080\n");
        $rawToken = 'secret_raw_token_should_never_leak_999';
        $res = MailService::sendEmployeeInvitation(
            'carla@example.test',
            'Carla Mendoza',
            'Pet Store',
            'Receptionist',
            "http://127.0.0.1:8080/invitations/accept?token={$rawToken}"
        );
        if ($res['submitted'] !== false || $res['status_code'] !== 503 || $res['error'] === null) {
            throw new RuntimeException('Expected 503 failure result, got: ' . json_encode($res));
        }
        if (str_contains($res['error'], $rawToken)) {
            throw new RuntimeException('Raw token leaked into error string.');
        }
    });

    check('sendEmployeeInvitation rejects invalid email with 422', function () use ($tempFile) {
        loadEnv($tempFile, "MAIL_DRIVER=null\n");
        $res = MailService::sendEmployeeInvitation(
            'not-an-email',
            'Carla Mendoza',
            'Pet Store',
            'Receptionist',
            'http://127.0.0.1:8080/invitations/accept?token=abcdef'
        );
        if ($res['submitted'] !== false || $res['status_code'] !== 422) {
            throw new RuntimeException('Expected 422 for invalid email.');
        }
    });

    check('sendEmployeeInvitation rejects non-http invitation URL with 422 and no URL leak', function () use ($tempFile) {
        loadEnv($tempFile, "MAIL_DRIVER=null\n");
        $res = MailService::sendEmployeeInvitation(
            'carla@example.test',
            'Carla Mendoza',
            'Pet Store',
            'Receptionist',
            'javascript:alert(1)'
        );
        if ($res['submitted'] !== false || $res['status_code'] !== 422 || str_contains((string)$res['error'], 'javascript:')) {
            throw new RuntimeException('Expected sanitized 422 for invalid invitation URL.');
        }
    });

    check('sendEmployeeInvitation rejects missing unit/position or non-positive expiry with 422', function () use ($tempFile) {
        loadEnv($tempFile, "MAIL_DRIVER=null\n");
        $r1 = MailService::sendEmployeeInvitation('carla@example.test', 'Carla', '', 'Receptionist', 'http://127.0.0.1:8080/invitations/accept?token=abc');
        $r2 = MailService::sendEmployeeInvitation('carla@example.test', 'Carla', 'Pet Store', 'Receptionist', 'http://127.0.0.1:8080/invitations/accept?token=abc', 0);
        if ($r1['status_code'] !== 422 || $r2['status_code'] !== 422) {
            throw new RuntimeException('Expected 422 for empty unit or zero expiry.');
        }
    });

    // --- 3. Static Migration Structure & migrate.php Splitter Check ---
    check('Migration 029 parses cleanly with migrate.php statement splitter and defines required schema', function () {
        $upPath = dirname(__DIR__) . '/database/migrations/029_employee_invitations.sql';
        $downPath = dirname(__DIR__) . '/database/migrations/down/029_employee_invitations.down.sql';
        if (!is_file($upPath) || !is_file($downPath)) {
            throw new RuntimeException('Migration or down migration file missing.');
        }

        $upRaw = file_get_contents($upPath);
        $downRaw = file_get_contents($downPath);
        if ($upRaw === false || $downRaw === false) {
            throw new RuntimeException('Could not read migration files.');
        }

        $stripAndSplit = static function (string $sql): array {
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
            return array_values(array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql) . "\n"))));
        };

        $upStmts = $stripAndSplit($upRaw);
        $downStmts = $stripAndSplit($downRaw);

        if (count($upStmts) !== 1 || count($downStmts) !== 1) {
            throw new RuntimeException('Expected 1 statement in up and 1 in down migration.');
        }

        $ddl = $upStmts[0];
        foreach ([
            'CREATE TABLE IF NOT EXISTS employee_invitation',
            'user_account_id INT UNSIGNED NOT NULL',
            'employee_id INT UNSIGNED NOT NULL',
            'business_unit_id INT UNSIGNED NOT NULL',
            'invited_email VARCHAR(255) NOT NULL',
            'token_hash CHAR(64) NOT NULL',
            "ENUM('pending', 'accepted', 'revoked', 'expired')",
            'expires_at DATETIME NOT NULL',
            'accepted_at DATETIME NULL DEFAULT NULL',
            'revoked_at DATETIME NULL DEFAULT NULL',
            "ENUM('pending', 'submitted', 'failed')",
            'email_submitted_at DATETIME NULL DEFAULT NULL',
            'email_failed_at DATETIME NULL DEFAULT NULL',
            'email_error VARCHAR(255) NULL DEFAULT NULL',
            'created_by_user_id INT UNSIGNED NULL DEFAULT NULL',
            'UNIQUE KEY uk_emp_inv_token_hash (token_hash)',
            'CONSTRAINT fk_emp_inv_user',
            'CONSTRAINT fk_emp_inv_employee',
            'CONSTRAINT fk_emp_inv_business_unit',
            'CONSTRAINT fk_emp_inv_creator',
            'COLLATE=utf8mb4_0900_ai_ci',
        ] as $needle) {
            if (!str_contains($ddl, $needle)) {
                throw new RuntimeException("Missing expected DDL fragment: {$needle}");
            }
        }

        if (preg_match('/\braw_token\b|\binvitation_token\b/i', $ddl) === 1) {
            throw new RuntimeException('Migration must never define a raw token column.');
        }

        if (!str_contains($downStmts[0], 'DROP TABLE IF EXISTS employee_invitation')) {
            throw new RuntimeException('Down migration does not drop employee_invitation.');
        }
    });
} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempFile);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
