<?php

declare(strict_types=1);

/*
 * Delete expired files from storage/ (Part D §15). Run it daily (cron / Task Scheduler).
 *
 *   php bin/cleanup-storage.php              delete expired files
 *   php bin/cleanup-storage.php --dry-run    only report what WOULD be deleted
 *   php bin/cleanup-storage.php --quiet      print the summary only
 *
 * Retention comes from config/app.php: retention_uploads_days (30), retention_generated_days (90),
 * retention_temp_days (7). Website profiles (blueprints.json) and their logos (assets/) are never deleted.
 */

use App\Core\Config;
use App\Core\Logger;
use App\Files\StorageCleaner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$args = array_slice($argv ?? [], 1);
foreach ($args as $arg) {
    if (!in_array($arg, ['--dry-run', '--quiet', '--help', '-h'], true)) {
        fwrite(STDERR, "Unknown option: {$arg}\nUsage: php bin/cleanup-storage.php [--dry-run] [--quiet]\n");
        exit(2);
    }
}
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    fwrite(STDOUT, "Usage: php bin/cleanup-storage.php [--dry-run] [--quiet]\n\n"
        . "  --dry-run  report what would be deleted, change nothing\n"
        . "  --quiet    print only the summary\n");
    exit(0);
}
$dryRun = in_array('--dry-run', $args, true);
$quiet = in_array('--quiet', $args, true);

$lock = @fopen(Config::storagePath('tmp') . DIRECTORY_SEPARATOR . '.cleanup.lock', 'c');
if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Another cleanup is running. Nothing to do.\n");
    exit(0);
}

$started = microtime(true);
$cleaner = new StorageCleaner($dryRun);
try {
    $report = $cleaner->run();
} catch (Throwable $e) {
    Logger::error('cleanup', 'Cleanup failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "\n  PDF Regenerator storage cleanup%s\n  Retention: uploads %d days · generated %d days · temporary %d days\n\n",
    $dryRun ? ' (dry run — nothing is deleted)' : '',
    (int) Config::get('retention_uploads_days', 30),
    (int) Config::get('retention_generated_days', 90),
    (int) Config::get('retention_temp_days', 7),
));

$byType = [];
$bytes = 0;
$failed = 0;
foreach ($report as $row) {
    $byType[$row['type']] = ($byType[$row['type']] ?? 0) + 1;
    $bytes += $row['bytes'];
    $failed += $row['action'] === 'failed' ? 1 : 0;
    if (!$quiet) {
        fwrite(STDOUT, sprintf("  %-13s %-13s %s  (%s)\n", $row['action'], $row['type'], $row['path'], $row['reason']));
    }
}
if ($report === []) {
    fwrite(STDOUT, "  Nothing to clean up.\n");
} else {
    ksort($byType);
    $parts = [];
    foreach ($byType as $type => $n) {
        $parts[] = $n . ' ' . $type;
    }
    fwrite(STDOUT, sprintf("\n  %s: %s — %s%s\n", $dryRun ? 'Would clean' : 'Cleaned', implode(', ', $parts), format_bytes($bytes), $failed > 0 ? " — {$failed} failed (see storage/logs)" : ''));
}
fwrite(STDOUT, "  Website profiles and their logos are always kept.\n\n");

if (!$dryRun) {
    Logger::info('cleanup', 'Cleanup finished', ['items' => count($report), 'bytes' => $bytes, 'failed' => $failed, 'ms' => (int) round((microtime(true) - $started) * 1000)]);
}
exit($failed > 0 ? 1 : 0);
