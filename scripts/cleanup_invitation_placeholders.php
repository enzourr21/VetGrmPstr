<?php
declare(strict_types=1);

/**
 * CLI tool: Provenance-verified legacy invitation placeholder report, cleanup, and rollback archiver.
 *
 * Usage:
 *   php scripts/cleanup_invitation_placeholders.php --dry-run
 *   php scripts/cleanup_invitation_placeholders.php --apply --confirm-cleanup
 *   php scripts/cleanup_invitation_placeholders.php --archive-for-rollback [--detach-archived-for-down]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: CLI execution only.\n");
}

$args = array_slice($argv ?? [], 1);

$allowedFlags = [
    '--dry-run',
    '--apply',
    '--confirm-cleanup',
    '--archive-for-rollback',
    '--detach-archived-for-down',
];

$seen = [];
foreach ($args as $arg) {
    if (!in_array($arg, $allowedFlags, true)) {
        fwrite(STDERR, "Invalid or unknown flag: {$arg}\nUse --dry-run, --apply --confirm-cleanup, or --archive-for-rollback [--detach-archived-for-down].\n");
        exit(2);
    }
    if (isset($seen[$arg])) {
        fwrite(STDERR, "Duplicate flag supplied: {$arg}\n");
        exit(2);
    }
    $seen[$arg] = true;
}

$hasDryRun = isset($seen['--dry-run']);
$hasApply = isset($seen['--apply']);
$hasConfirm = isset($seen['--confirm-cleanup']);
$hasArchive = isset($seen['--archive-for-rollback']);
$hasDetachArchived = isset($seen['--detach-archived-for-down']);

// Primary modes are strictly mutually exclusive
$modeCount = ($hasDryRun ? 1 : 0) + ($hasApply ? 1 : 0) + ($hasArchive ? 1 : 0);
if ($modeCount > 1) {
    fwrite(STDERR, "Conflicting modes supplied. Specify only one of --dry-run, --apply, or --archive-for-rollback.\n");
    exit(2);
}

// Modifier flags must match their owning primary mode
if ($hasConfirm && !$hasApply) {
    fwrite(STDERR, "Invalid flag combination: --confirm-cleanup can only be used with --apply.\n");
    exit(2);
}
if ($hasApply && !$hasConfirm) {
    fwrite(STDERR, "Refusing --apply without explicit --confirm-cleanup flag.\n");
    exit(2);
}
if ($hasDetachArchived && !$hasArchive) {
    fwrite(STDERR, "Invalid flag combination: --detach-archived-for-down can only be used with --archive-for-rollback.\n");
    exit(2);
}

// Only bootstrap DB connection after all CLI arguments pass strict validation
require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\InvitationPlaceholderCleanupService;

$pdo = Database::pdo();
$service = new InvitationPlaceholderCleanupService($pdo);

if ($hasArchive) {
    $res = $service->archiveForRollback($hasDetachArchived);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($hasApply) {
    $res = $service->applyCleanup(true, null);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$report = $service->analyze();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
