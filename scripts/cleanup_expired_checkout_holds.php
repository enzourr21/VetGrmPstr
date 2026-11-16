<?php
declare(strict_types=1);

/**
 * PawCariño — Expired Checkout Hold Cleanup Entry Point
 *
 * Transitions active 3-minute online checkout holds whose expires_at <= UTC_TIMESTAMP()
 * to 'expired' in deterministic inventory lock order (inventory_id ASC -> inventory_hold -> checkout_attempt).
 *
 * Note: Inventory availability queries already ignore expired holds in real time via
 * `expires_at > UTC_TIMESTAMP()`, so stock is never trapped even between cleanup runs.
 * Running this script periodically keeps `inventory_hold.hold_status` and
 * `checkout_attempt.attempt_status` tidy and releases `active_guard_key` cleanly.
 *
 * Scheduling examples (every 1 minute):
 *   Linux/macOS cron:
 *     * * * * * /usr/bin/php /path/to/VETGRPETproj/scripts/cleanup_expired_checkout_holds.php >> /path/to/VETGRPETproj/storage/logs/hold_cleanup.log 2>&1
 *   Windows Task Scheduler (every 1 minute):
 *     Program/script: D:\VETGRPETproj\tools\php83\php.exe
 *     Arguments: D:\VETGRPETproj\scripts\cleanup_expired_checkout_holds.php
 *
 * Optional CLI flags:
 *   --batch-size=100   Holds processed per batch (1..500)
 *   --max-batches=20   Maximum batches per invocation (1..200)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This maintenance script must be executed from the CLI.\n");
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\CheckoutHoldService;

$batchSize = 100;
$maxBatches = 20;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--batch-size=')) {
        $val = substr($arg, 13);
        if (ctype_digit($val) && (int)$val >= 1 && (int)$val <= 500) {
            $batchSize = (int)$val;
        }
    } elseif (str_starts_with($arg, '--max-batches=')) {
        $val = substr($arg, 14);
        if (ctype_digit($val) && (int)$val >= 1 && (int)$val <= 200) {
            $maxBatches = (int)$val;
        }
    }
}

try {
    $service = new CheckoutHoldService(Database::pdo());
    $result = $service->releaseAllExpiredHolds($batchSize, $maxBatches);
    echo json_encode([
        'ok' => true,
        'ran_at_utc' => gmdate('Y-m-d H:i:s'),
        'expired_count' => $result['expired_count'],
        'batches' => $result['batches'],
        'has_more' => $result['has_more'],
        'hold_ids' => $result['hold_ids'],
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'ran_at_utc' => gmdate('Y-m-d H:i:s'),
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
