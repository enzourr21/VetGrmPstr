<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/EnvConfig.php';

use App\Core\EnvConfig;

$tempFile = tempnam(sys_get_temp_dir(), 'pawcarino-env-');

if ($tempFile === false) {
    throw new RuntimeException('Could not create temporary test file.');
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
        throw $e;
    }

    throw new RuntimeException('Expected RuntimeException with code 503.');
}

function loadTestEnv(string $path, string $contents): void
{
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not write temporary environment file.');
    }

    EnvConfig::setEnvPathForTesting($path);
}

// Prevent process environment variables from overriding test values.
foreach (['OTP_HMAC_SECRET', 'MAIL_DRIVER', 'MAIL_HOST',
          'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
          'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS'] as $key) {
    putenv($key);
    unset($_ENV[$key]);
}

try {
    check('Missing HMAC secret is rejected', function () use ($tempFile) {
        loadTestEnv($tempFile, "MAIL_DRIVER=null\n");
        expect503(fn() => EnvConfig::otpHmacSecret());
    });

    check('Short HMAC secret is rejected', function () use ($tempFile) {
        loadTestEnv($tempFile, "OTP_HMAC_SECRET=short\n");
        expect503(fn() => EnvConfig::otpHmacSecret());
    });

    check('Placeholder HMAC secret is rejected', function () use ($tempFile) {
        loadTestEnv(
            $tempFile,
            "OTP_HMAC_SECRET=replace_with_a_random_secret_at_least_32_bytes\n"
        );
        expect503(fn() => EnvConfig::otpHmacSecret());
    });

    check('Valid 32-byte HMAC secret is accepted', function () use ($tempFile) {
        $secret = '0123456789abcdef0123456789abcdef';
        loadTestEnv($tempFile, "OTP_HMAC_SECRET={$secret}\n");

        if (EnvConfig::otpHmacSecret() !== $secret) {
            throw new RuntimeException('Returned secret did not match.');
        }
    });

    check('Invalid mail driver is rejected', function () use ($tempFile) {
        loadTestEnv($tempFile, "MAIL_DRIVER=sendmail\n");
        expect503(fn() => EnvConfig::mailConfig());
    });

    check('Null mail driver is accepted', function () use ($tempFile) {
        loadTestEnv($tempFile, "MAIL_DRIVER=null\n");

        $config = EnvConfig::mailConfig();

        if ($config['driver'] !== 'null') {
            throw new RuntimeException('Expected null driver.');
        }
    });

    check('Unterminated quoted value is rejected', function () use ($tempFile) {
        loadTestEnv(
            $tempFile,
            "OTP_HMAC_SECRET=\"unterminated\n"
        );
        expect503(fn() => EnvConfig::otpHmacSecret());
    });

    check('SMTP configuration preserves special password characters', function () use ($tempFile) {
        loadTestEnv(
            $tempFile,
            "MAIL_DRIVER=smtp\n" .
            "MAIL_HOST=smtp.example.test\n" .
            "MAIL_PORT=587\n" .
            "MAIL_USERNAME=test@example.test\n" .
            "MAIL_PASSWORD=\"p@ss#word=abc\"\n" .
            "MAIL_ENCRYPTION=tls\n" .
            "MAIL_FROM_ADDRESS=test@example.test\n"
        );

        $config = EnvConfig::mailConfig();

        if ($config['password'] !== 'p@ss#word=abc') {
            throw new RuntimeException('Special characters were not preserved.');
        }

        if ($config['verify_peer'] !== true) {
            throw new RuntimeException('TLS peer verification is not enabled.');
        }
    });

    // --- Invalid SMTP configurations (each varies exactly one field from a valid baseline) ---
    $smtpEnv = function (array $overrides = [], array $remove = []): string {
        $vars = array_merge([
            'MAIL_DRIVER' => 'smtp',
            'MAIL_HOST' => 'smtp.example.test',
            'MAIL_PORT' => '587',
            'MAIL_USERNAME' => 'test@example.test',
            'MAIL_PASSWORD' => 'secret-pass',
            'MAIL_ENCRYPTION' => 'tls',
            'MAIL_FROM_ADDRESS' => 'test@example.test',
        ], $overrides);
        foreach ($remove as $k) {
            unset($vars[$k]);
        }
        $out = '';
        foreach ($vars as $k => $v) {
            $out .= "{$k}={$v}\n";
        }
        return $out;
    };

    check('Valid SMTP baseline is accepted', function () use ($tempFile, $smtpEnv) {
        loadTestEnv($tempFile, $smtpEnv());
        $c = EnvConfig::mailConfig();
        if ($c['driver'] !== 'smtp' || $c['port'] !== 587 || $c['encryption'] !== 'tls') {
            throw new RuntimeException('Baseline SMTP config not parsed as expected.');
        }
    });

    check('Missing SMTP host is rejected', function () use ($tempFile, $smtpEnv) {
        loadTestEnv($tempFile, $smtpEnv([], ['MAIL_HOST']));
        expect503(fn() => EnvConfig::mailConfig());
    });

    check('Missing SMTP username is rejected', function () use ($tempFile, $smtpEnv) {
        loadTestEnv($tempFile, $smtpEnv([], ['MAIL_USERNAME']));
        expect503(fn() => EnvConfig::mailConfig());
    });

    check('Missing SMTP password is rejected', function () use ($tempFile, $smtpEnv) {
        loadTestEnv($tempFile, $smtpEnv([], ['MAIL_PASSWORD']));
        expect503(fn() => EnvConfig::mailConfig());
    });

    foreach (['abc' => 'non-numeric', '0' => 'zero', '65536' => 'above range', '99999' => 'far above range', '-1' => 'negative', '' => 'empty'] as $port => $label) {
        check("Invalid SMTP port is rejected ({$label}: '{$port}')", function () use ($tempFile, $smtpEnv, $port) {
            loadTestEnv($tempFile, $smtpEnv(['MAIL_PORT' => (string)$port]));
            expect503(fn() => EnvConfig::mailConfig());
        });
    }

    foreach (['not-an-email', 'a@b', '', 'two words@example.test'] as $addr) {
        check("Invalid sender email is rejected ('{$addr}')", function () use ($tempFile, $smtpEnv, $addr) {
            loadTestEnv($tempFile, $smtpEnv(['MAIL_FROM_ADDRESS' => $addr]));
            expect503(fn() => EnvConfig::mailConfig());
        });
    }

    foreach (['none', 'plain', 'tls1.0'] as $enc) {
        check("Unsupported SMTP encryption is rejected ('{$enc}')", function () use ($tempFile, $smtpEnv, $enc) {
            loadTestEnv($tempFile, $smtpEnv(['MAIL_ENCRYPTION' => $enc]));
            expect503(fn() => EnvConfig::mailConfig());
        });
    }

} finally {
    EnvConfig::setEnvPathForTesting(null);
    @unlink($tempFile);
}

echo "\nResults: {$passed} passed, {$failed} failed.\n";

exit($failed === 0 ? 0 : 1);