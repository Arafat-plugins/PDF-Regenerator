<?php

declare(strict_types=1);

namespace App\Files;

use App\Core\Config;
use App\Core\Logger;
use App\Core\JsonStore;

/**
 * Daily storage cleanup without a cron job.
 *
 * On a web request, at most once every 24 hours (config `auto_cleanup`), the same cleanup as
 * `bin/cleanup-storage.php` runs after the response has been sent. A stamp file remembers the
 * last run; the CLI lock file keeps two cleanups from running at the same time.
 */
final class AutoCleanup
{
    public const INTERVAL = 86400;

    public static function stampFile(): string
    {
        return Config::storagePath('logs') . DIRECTORY_SEPARATOR . '.auto-cleanup';
    }

    /** Is a run due now? (config on, stamp missing or older than the interval) */
    public static function due(?int $now = null): bool
    {
        if (!Config::get('auto_cleanup', true)) {
            return false;
        }
        $now ??= time();
        $stamp = self::stampFile();
        clearstatcache(true, $stamp);
        $last = is_file($stamp) ? (int) @filemtime($stamp) : 0;

        return $last <= $now - self::INTERVAL;
    }

    /** Called from the bootstrap for web requests: schedules the run after the response. */
    public static function schedule(): void
    {
        try {
            if (!self::due()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        register_shutdown_function(static function (): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            // send the page to the browser first (PHP-FPM / LiteSpeed)
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
            self::runNow();
        });
    }

    /**
     * Runs the cleanup if it is due and no other cleanup is running.
     *
     * @return int|null number of report rows, or null when nothing ran
     */
    public static function runNow(?int $now = null): ?int
    {
        try {
            if (!self::due($now)) {
                return null;
            }
            $tmp = Config::storagePath('tmp');
            JsonStore::ensureDirectory($tmp);
            JsonStore::ensureDirectory(dirname(self::stampFile()));
            $lock = @fopen($tmp . DIRECTORY_SEPARATOR . '.cleanup.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            try {
                if (!self::due($now)) {   // another request finished a run meanwhile
                    return null;
                }
                // stamp first: a failing cleanup must not be retried on every request
                @touch(self::stampFile(), $now ?? time());
                @ignore_user_abort(true);
                @set_time_limit(120);
                $started = microtime(true);
                $report = (new StorageCleaner(false, $now))->run();
                $bytes = array_sum(array_column($report, 'bytes'));
                $failed = count(array_filter($report, static fn (array $r): bool => $r['action'] === 'failed'));
                Logger::info('cleanup', 'Automatic cleanup finished', [
                    'items' => count($report), 'bytes' => $bytes, 'failed' => $failed,
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                ]);

                return count($report);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable $e) {
            try {
                Logger::error('cleanup', 'Automatic cleanup failed', ['error' => $e->getMessage()]);
            } catch (\Throwable) {
            }

            return null;
        }
    }
}
