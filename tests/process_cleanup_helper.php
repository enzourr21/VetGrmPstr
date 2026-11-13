<?php
declare(strict_types=1);

/**
 * Shared bounded process cleanup helper for test suites.
 *
 * Never calls proc_close() while the child process may still be running,
 * because proc_close() blocks until the child exits.
 */
function safelyTerminateAndClose(
    $proc,
    array &$pipes = [],
    float $timeoutSeconds = 2.0,
    ?callable $terminateFn = null
): int {
    if (!is_resource($proc)) {
        return -1;
    }

    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            @fclose($pipe);
        }
    }
    $pipes = [];

    $status = @proc_get_status($proc);
    if (!is_array($status)) {
        $msg = 'Process cleanup failed: proc_get_status() returned false; skipped proc_close() to avoid blocking.';
        fwrite(STDERR, "[ERROR] {$msg}\n");
        if (function_exists('assertTest')) {
            assertTest('Bounded child process cleanup succeeded without hanging', false, $msg);
        }
        return -1;
    }

    if (!$status['running']) {
        $closeExit = (int)@proc_close($proc);
        return ($status['exitcode'] !== -1) ? (int)$status['exitcode'] : $closeExit;
    }

    $budget = max(0.05, $timeoutSeconds);
    $start = microtime(true);
    $deadline = $start + $budget;
    $forceDeadline = $start + ($budget * 0.5);
    $forcedKillSent = false;

    $terminate = $terminateFn ?? static function ($handle, ?int $signal = null): bool {
        return $signal === null ? @proc_terminate($handle) : @proc_terminate($handle, $signal);
    };

    $invokeTerminate = static function ($handle, ?int $signal) use ($terminate): bool {
        try {
            return (bool)$terminate($handle, $signal);
        } catch (Throwable $e) {
            $phase = $signal === null ? 'normal' : 'forced(' . $signal . ')';
            $errMsg = sprintf(
                'Process cleanup failed: termination callback threw %s during %s termination: %s',
                get_class($e),
                $phase,
                $e->getMessage()
            );
            fwrite(STDERR, "[ERROR] {$errMsg}\n");
            if (function_exists('assertTest')) {
                assertTest('Bounded child process cleanup succeeded without hanging', false, $errMsg);
            }
            return false;
        }
    };

    $terminateOk = $invokeTerminate($proc, null);

    while (microtime(true) < $deadline) {
        $remainingUs = (int)(($deadline - microtime(true)) * 1000000);
        if ($remainingUs <= 0) {
            break;
        }
        usleep(min(20000, $remainingUs)); // 20ms max poll interval

        $status = @proc_get_status($proc);
        if (!is_array($status) || !$status['running']) {
            break;
        }

        if (!$forcedKillSent && microtime(true) >= $forceDeadline) {
            $forcedKillSent = true;
            $killSignal = defined('SIGKILL') ? SIGKILL : 9;
            $terminateOk = $invokeTerminate($proc, $killSignal) || $terminateOk;
        }
    }

    $status = @proc_get_status($proc);
    if (!is_array($status)) {
        $msg = 'Process cleanup failed: proc_get_status() failed after termination attempt; skipped proc_close() to avoid blocking.';
        fwrite(STDERR, "[ERROR] {$msg}\n");
        if (function_exists('assertTest')) {
            assertTest('Bounded child process cleanup succeeded without hanging', false, $msg);
        }
        return -1;
    }

    // Never call proc_close() while the child process may still be running, as proc_close() blocks until exit.
    if ($status['running']) {
        $pid = $status['pid'] ?? 'unknown';
        $msg = sprintf(
            'Process cleanup failed: child PID %s still running after %.2fs bounded timeout (proc_terminate=%s); skipped proc_close() to avoid blocking.',
            (string)$pid,
            $budget,
            $terminateOk ? 'true' : 'false'
        );
        fwrite(STDERR, "[ERROR] {$msg}\n");
        if (function_exists('assertTest')) {
            assertTest('Bounded child process cleanup succeeded without hanging', false, $msg);
        }
        return -1;
    }

    $closeExit = (int)@proc_close($proc);
    return ($status['exitcode'] !== -1) ? (int)$status['exitcode'] : $closeExit;
}
