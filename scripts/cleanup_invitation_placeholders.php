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

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\InvitationPlaceholderCleanupService;

$args = array_slice($argv ?? [], 1);
$hasDryRun = in_array('--dry-run', $args, true);
$hasApply = in_array('--apply', $args, true);
$hasConfirm = in_array('--confirm-cleanup', $args, true);
$hasArchive = in_array('--archive-for-rollback', $args, true);
$hasDetachArchived = in_array('--detach-archived-for-down', $args, true);

$pdo = Database::pdo();
$service = new InvitationPlaceholderCleanupService($pdo);

if ($hasArchive) {
    $res = $service->archiveForRollback($hasDetachArchived);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if ($hasApply) {
    if (!$hasConfirm) {
        fwrite(STDERR, "Refusing --apply without explicit --confirm-cleanup flag.\n");
        exit(2);
    }
    $res = $service->applyCleanup(true, null);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

if (!$hasDryRun && $args !== []) {
    fwrite(STDERR, "Unknown flags. Use --dry-run, --apply --confirm-cleanup, or --archive-for-rollback.\n");
    exit(2);
}

$report = $service->analyze();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit(0);
