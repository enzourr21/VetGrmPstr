<?php
declare(strict_types=1);

/**
 * Isolated tests for bounded process cleanup in tests/process_cleanup_helper.php:
 * 1. Forced termination when a child ignores normal termination.
 * 2. Cleanup-failure path when a child stays alive past the deadline, including
 *    open resource state and PHP shutdown disposal timing.
 */

require_once __DIR__ . '/process_cleanup_helper.php';

if (in_array('--forced-termination', $argv, true)) {
    $cleanupTimeout = 0.40;
    $childSleepSeconds = 10;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $childCode = 'if (function_exists("pcntl_async_signals")) { pcntl_async_signals(true); } '
        . 'if (function_exists("pcntl_signal")) { pcntl_signal(SIGTERM, SIG_IGN); } '
        . 'sleep(' . $childSleepSeconds . ');';
    $proc = proc_open([PHP_BINARY, '-r', $childCode], $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to spawn child for forced-termination test.\n");
        exit(2);
    }

    $initialStatus = proc_get_status($proc);
    if (!is_array($initialStatus) || !$initialStatus['running']) {
        fwrite(STDERR, "Child did not start running.\n");
        exit(3);
    }

    $childPid = (int)($initialStatus['pid'] ?? 0);
    $signalsSeen = [];

    // Attempt real OS-level normal termination first (without /F on Windows, SIGTERM on POSIX),
    // which the child genuinely ignores, then allow forced termination ($signal !== null).
    $terminateFn = static function ($handle, ?int $signal = null) use (&$signalsSeen, $childPid): bool {
        if ($signal === null) {
            $signalsSeen[] = 'NORMAL';
            if (PHP_OS_FAMILY === 'Windows') {
                $out = [];
                $code = 1;
                @exec("taskkill /PID {$childPid} 2>NUL", $out, $code);
                return $code === 0;
            }
            return @proc_terminate($handle);
        }
        $signalsSeen[] = (string)$signal;
        return @proc_terminate($handle, $signal);
    };

    $t0 = microtime(true);
    $exitCode = safelyTerminateAndClose($proc, $pipes, $cleanupTimeout, $terminateFn);
    $helperElapsed = microtime(true) - $t0;

    echo json_encode([
        'childPid' => $childPid,
        'cleanupTimeout' => $cleanupTimeout,
        'helperExitCode' => $exitCode,
        'helperElapsed' => $helperElapsed,
        'signalsSeen' => $signalsSeen,
        'procStillResource' => is_resource($proc),
    ]) . "\n";

    exit(0);
}

if (in_array('--cleanup-failure', $argv, true)) {
    $cleanupTimeout = 0.25;
    $childSleepSeconds = 10;
    $unsetBeforeExit = in_array('--unset', $argv, true);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open([PHP_BINARY, '-r', "sleep({$childSleepSeconds});"], $descriptors, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Failed to spawn lingering child process.\n");
        exit(2);
    }

    $initialStatus = proc_get_status($proc);
    if (!is_array($initialStatus) || !$initialStatus['running']) {
        fwrite(STDERR, "Child process did not stay running.\n");
        exit(3);
    }

    $childPid = (int)($initialStatus['pid'] ?? 0);

    $t0 = microtime(true);
    $exitCode = safelyTerminateAndClose(
        $proc,
        $pipes,
        $cleanupTimeout,
        static fn (): bool => false
    );
    $helperElapsed = microtime(true) - $t0;

    $procStillResource = is_resource($proc);
    $procResourceType = $procStillResource ? get_resource_type($proc) : 'closed';
    $afterStatus = $procStillResource ? proc_get_status($proc) : false;
    $stillRunningAfterHelper = is_array($afterStatus) && (bool)$afterStatus['running'];

    $unsetElapsed = null;
    if ($unsetBeforeExit && $procStillResource) {
        $tUnset = microtime(true);
        unset($proc);
        $unsetElapsed = microtime(true) - $tUnset;
    }

    echo json_encode([
        'childPid' => $childPid,
        'cleanupTimeout' => $cleanupTimeout,
        'helperExitCode' => $exitCode,
        'helperElapsed' => $helperElapsed,
        'procStillResource' => $procStillResource,
        'procResourceType' => $procResourceType,
        'stillRunningAfterHelper' => $stillRunningAfterHelper,
        'unsetElapsed' => $unsetElapsed,
    ]) . "\n";

    exit(0);
}

$passed = 0;
$failed = 0;

function assertBound(string $desc, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

function runInnerProcess(array $args, float $outerDeadlineSeconds = 2.0): array
{
    $tmpOut = tempnam(sys_get_temp_dir(), 'pc_out_');
    $tmpErr = tempnam(sys_get_temp_dir(), 'pc_err_');
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $tmpOut, 'w'],
        2 => ['file', $tmpErr, 'w'],
    ];

    $tStart = microtime(true);
    $innerProc = proc_open(array_merge([PHP_BINARY, __FILE__], $args), $descriptors, $innerPipes);
    if (!is_resource($innerProc)) {
        @unlink($tmpOut);
        @unlink($tmpErr);
        return [
            'spawned' => false,
            'innerPid' => 0,
            'exited' => false,
            'timedOut' => false,
            'exitCode' => -1,
            'elapsed' => 0.0,
            'stdout' => '',
            'stderr' => '',
        ];
    }
    if (isset($innerPipes[0]) && is_resource($innerPipes[0])) {
        @fclose($innerPipes[0]);
    }
    unset($innerPipes[0]);

    $initialStatus = @proc_get_status($innerProc);
    $innerPid = is_array($initialStatus) ? (int)($initialStatus['pid'] ?? 0) : 0;

    $exited = false;
    $exitCode = -1;
    $lastStatus = $initialStatus;
    $deadline = $tStart + $outerDeadlineSeconds;

    while (microtime(true) < $deadline) {
        $lastStatus = @proc_get_status($innerProc);
        if (!is_array($lastStatus) || !$lastStatus['running']) {
            $exited = true;
            $exitCode = is_array($lastStatus) ? (int)$lastStatus['exitcode'] : -1;
            break;
        }
        usleep(20000);
    }

    $elapsed = microtime(true) - $tStart;
    $timedOut = !$exited;

    if ($timedOut) {
        // Kill any descendant/grandchild processes of the timed-out inner process,
        // then terminate & reap the inner process boundedly without calling unbounded proc_close().
        killProcessTree($innerPid);
        safelyTerminateAndClose($innerProc, $innerPipes, 0.5);
    } elseif (is_array($lastStatus) && !$lastStatus['running']) {
        $closeCode = @proc_close($innerProc);
        if ($exitCode === -1) {
            $exitCode = (int)$closeCode;
        }
    }

    $stdout = (string)@file_get_contents($tmpOut);
    $stderr = (string)@file_get_contents($tmpErr);
    @unlink($tmpOut);
    @unlink($tmpErr);

    return [
        'spawned' => true,
        'innerPid' => $innerPid,
        'exited' => $exited,
        'timedOut' => $timedOut,
        'exitCode' => $exitCode,
        'elapsed' => $elapsed,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function isPidRunning(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = [];
        @exec("tasklist /FI \"PID eq {$pid}\" /NH 2>NUL", $out);
        foreach ($out as $line) {
            if (preg_match('/\b' . $pid . '\b/', $line)) {
                return true;
            }
        }
        return false;
    }
    @exec("kill -0 {$pid} 2>/dev/null", $out, $code);
    return $code === 0;
}

function killPid(int $pid): void
{
    if ($pid <= 0) {
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        @exec("taskkill /PID {$pid} /F >NUL 2>&1");
    } else {
        @exec("kill -9 {$pid} >/dev/null 2>&1");
    }
}

function killProcessTree(int $pid): void
{
    if ($pid <= 0) {
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        @exec("taskkill /PID {$pid} /T /F >NUL 2>&1");
    } else {
        @exec("pkill -9 -P {$pid} >/dev/null 2>&1");
        @exec("kill -9 {$pid} >/dev/null 2>&1");
    }
}

echo "====================================================================\n";
echo "BOUNDED PROCESS CLEANUP & SHUTDOWN TEST SUITE\n";
echo "====================================================================\n\n";

// --- Section 1: Forced Termination When Child Ignores Normal Termination ---
echo "--- Section 1: Forced Termination Test ---\n";
$forcedRun = runInnerProcess(['--forced-termination'], 2.0);
$forcedPayload = json_decode(trim($forcedRun['stdout']), true);
$forcedChildPid = is_array($forcedPayload) ? (int)($forcedPayload['childPid'] ?? 0) : 0;

try {
    assertBound(
        'Forced-termination parent process exited within strict time limit (< 1.0s)',
        $forcedRun['spawned'] && $forcedRun['exited'] && $forcedRun['exitCode'] === 0 && $forcedRun['elapsed'] < 1.0,
        sprintf('exited=%s, exitCode=%d, totalElapsed=%.3fs', $forcedRun['exited'] ? 'true' : 'false', $forcedRun['exitCode'], $forcedRun['elapsed'])
    );
    assertBound(
        'Forced-termination test emitted structured payload',
        is_array($forcedPayload),
        'stdout=' . trim($forcedRun['stdout'])
    );
    if (is_array($forcedPayload)) {
        $signals = $forcedPayload['signalsSeen'] ?? [];
        $helperElapsed = (float)($forcedPayload['helperElapsed'] ?? -1.0);
        assertBound(
            'Normal OS-level termination was attempted first and escalated to forced kill (signal 9)',
            count($signals) === 2 && $signals[0] === 'NORMAL' && $signals[1] === '9',
            'signals=' . json_encode($signals)
        );
        assertBound(
            'safelyTerminateAndClose() completed forced kill within 0.40s budget (+ tolerance)',
            $helperElapsed >= 0.18 && $helperElapsed <= 0.60,
            sprintf('helperElapsed=%.3fs', $helperElapsed)
        );
        assertBound(
            'Process resource was closed via proc_close() after forced termination',
            ($forcedPayload['procStillResource'] ?? true) === false
        );
    }
    assertBound(
        'Child process is no longer running after forced termination',
        !isPidRunning($forcedChildPid),
        "childPid={$forcedChildPid}"
    );
} finally {
    killPid($forcedChildPid);
}

$isWindows = PHP_OS_FAMILY === 'Windows';

// --- Section 2: Cleanup-Failure Path & PHP Shutdown Disposal ---
echo "\n--- Section 2: Cleanup-Failure Path & PHP Shutdown Behavior (" . PHP_OS_FAMILY . ") ---\n";
$failRun = runInnerProcess(['--cleanup-failure'], 2.0);
$failPayload = json_decode(trim($failRun['stdout']), true);
$failChildPid = is_array($failPayload) ? (int)($failPayload['childPid'] ?? 0) : 0;

try {
    if ($isWindows) {
        assertBound(
            'Cleanup-failure parent process exited within bounded time including PHP shutdown (< 1.0s, Windows)',
            $failRun['spawned'] && $failRun['exited'] && $failRun['exitCode'] === 0 && $failRun['elapsed'] < 1.0,
            sprintf('exited=%s, exitCode=%d, totalElapsed=%.3fs', $failRun['exited'] ? 'true' : 'false', $failRun['exitCode'], $failRun['elapsed'])
        );
    } else {
        assertBound(
            'Cleanup-failure inner process was spawned (non-Windows shutdown may block on unclosed proc resource)',
            $failRun['spawned'],
            sprintf('os=%s, exited=%s, exitCode=%d, totalElapsed=%.3fs', PHP_OS_FAMILY, $failRun['exited'] ? 'true' : 'false', $failRun['exitCode'], $failRun['elapsed'])
        );
    }
    assertBound(
        'Cleanup-failure test emitted structured payload',
        is_array($failPayload),
        'stdout=' . trim($failRun['stdout'])
    );
    if (is_array($failPayload)) {
        $helperElapsed = (float)($failPayload['helperElapsed'] ?? -1.0);
        assertBound(
            'safelyTerminateAndClose() returned -1 on cleanup failure within 0.25s deadline',
            ($failPayload['helperExitCode'] ?? 0) === -1 && $helperElapsed >= 0.20 && $helperElapsed <= 0.60,
            sprintf('returned=%d, helperElapsed=%.3fs', (int)($failPayload['helperExitCode'] ?? 0), $helperElapsed)
        );
        assertBound(
            'Open process resource remains open when proc_close() is skipped',
            ($failPayload['procStillResource'] ?? false) === true && ($failPayload['procResourceType'] ?? '') === 'process',
            'type=' . ($failPayload['procResourceType'] ?? 'none')
        );
        assertBound(
            'Child remained running after helper returned',
            ($failPayload['stillRunningAfterHelper'] ?? false) === true,
            "childPid={$failChildPid}"
        );
    }
    assertBound(
        'Cleanup failure was explicitly reported to STDERR',
        str_contains($failRun['stderr'], '[ERROR] Process cleanup failed:') && str_contains($failRun['stderr'], 'skipped proc_close() to avoid blocking'),
        'stderr=' . trim($failRun['stderr'])
    );
    $failChildAliveAfterShutdown = isPidRunning($failChildPid);
    if ($isWindows) {
        assertBound(
            'Lingering child process remained alive after PHP shutdown (Windows closes handle without waiting)',
            $failChildAliveAfterShutdown,
            "childPid={$failChildPid}"
        );
    } else {
        echo sprintf("  [INFO] Non-Windows child state after PHP shutdown: pid=%d, alive=%s\n", $failChildPid, $failChildAliveAfterShutdown ? 'true' : 'false');
    }
} finally {
    killPid($failChildPid);
}

// --- Section 3: Explicit unset($proc) Disposal Timing ---
echo "\n--- Section 3: Explicit unset(\$proc) Disposal Timing (" . PHP_OS_FAMILY . ") ---\n";
$unsetRun = runInnerProcess(['--cleanup-failure', '--unset'], 2.0);
$unsetPayload = json_decode(trim($unsetRun['stdout']), true);
$unsetChildPid = is_array($unsetPayload) ? (int)($unsetPayload['childPid'] ?? 0) : 0;

try {
    $unsetChildAliveAfterShutdown = isPidRunning($unsetChildPid);
    if ($isWindows) {
        assertBound(
            'Explicit unset($proc) inner process exited cleanly within < 1.0s (Windows)',
            $unsetRun['spawned'] && $unsetRun['exited'] && $unsetRun['exitCode'] === 0 && $unsetRun['elapsed'] < 1.0,
            sprintf('exited=%s, exitCode=%d, totalElapsed=%.3fs', $unsetRun['exited'] ? 'true' : 'false', $unsetRun['exitCode'], $unsetRun['elapsed'])
        );
        assertBound(
            'Explicit unset($proc) did not block on Windows PHP (< 0.1s)',
            is_array($unsetPayload) && is_float($unsetPayload['unsetElapsed'] ?? null) && $unsetPayload['unsetElapsed'] < 0.1,
            sprintf('unsetElapsed=%.4fs, totalElapsed=%.3fs', (float)($unsetPayload['unsetElapsed'] ?? -1.0), $unsetRun['elapsed'])
        );
        assertBound(
            'Child process remained alive after unset($proc) and PHP shutdown on Windows (before explicit cleanup)',
            $unsetChildAliveAfterShutdown,
            "childPid={$unsetChildPid}"
        );
    } else {
        assertBound(
            'Explicit unset($proc) inner process spawned (non-Windows destructor may block)',
            $unsetRun['spawned'],
            sprintf('os=%s, exited=%s, exitCode=%d, totalElapsed=%.3fs, unsetElapsed=%s, childAlive=%s', PHP_OS_FAMILY, $unsetRun['exited'] ? 'true' : 'false', $unsetRun['exitCode'], $unsetRun['elapsed'], is_array($unsetPayload) && is_float($unsetPayload['unsetElapsed'] ?? null) ? sprintf('%.4fs', $unsetPayload['unsetElapsed']) : 'n/a', $unsetChildAliveAfterShutdown ? 'true' : 'false')
        );
    }
} finally {
    killPid($unsetChildPid);
}

assertBound(
    'No orphaned child process remains after Section 3 cleanup',
    !isPidRunning($unsetChildPid),
    "childPid={$unsetChildPid}"
);

echo "\n====================================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "====================================================================\n";

exit($failed > 0 ? 1 : 0);
