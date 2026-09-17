<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Run an external program safely (no shell, argument array, timeout, output cap).
 *
 * Output goes to temporary files instead of pipes so it works the same on Windows,
 * where stream_select() is not available for process pipes.
 */
final class Process
{
    /**
     * @param list<string> $command program + arguments (never passed through a shell)
     * @return array{exit: int, stdout: string, truncated: bool, timed_out: bool, stdout_bytes: int}
     */
    public static function run(array $command, int $timeoutSeconds = 20, int $maxOutputBytes = 10485760): array
    {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('proc_open is disabled.');
        }

        $tmpDir = Config::storagePath('tmp');
        JsonStore::ensureDirectory($tmpDir);
        $out = tempnam($tmpDir, 'proc_out_');
        $err = tempnam($tmpDir, 'proc_err_');
        if ($out === false || $err === false) {
            throw new \RuntimeException('Unable to create temporary files.');
        }

        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $proc = @proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($proc)) {
            @unlink($out);
            @unlink($err);
            throw new \RuntimeException('Unable to start process.');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $exit = -1;

        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = (int) $status['exitcode'];
                break;
            }
            clearstatcache(false, $out);
            if (microtime(true) > $deadline || (int) @filesize($out) > $maxOutputBytes + 65536) {
                $timedOut = microtime(true) > $deadline;
                self::kill($proc, (int) $status['pid']);
                break;
            }
            usleep(20000);
        }

        $closed = proc_close($proc);
        if ($exit === -1 && $closed !== -1 && !$timedOut) {
            $exit = $closed;
        }

        clearstatcache(false, $out);
        $bytes = (int) @filesize($out);
        $stdout = (string) @file_get_contents($out, false, null, 0, $maxOutputBytes);

        @unlink($out);
        @unlink($err);

        return [
            'exit' => $exit,
            'stdout' => $stdout,
            'truncated' => $bytes > $maxOutputBytes,
            'timed_out' => $timedOut,
            'stdout_bytes' => $bytes,
        ];
    }

    public static function isExecutable(?string $path): bool
    {
        return is_string($path) && $path !== '' && is_file($path) && is_executable($path);
    }

    /**
     * @param resource $proc
     */
    private static function kill($proc, int $pid): void
    {
        if (DIRECTORY_SEPARATOR === '\\' && $pid > 0) {
            @exec('taskkill /F /T /PID ' . $pid . ' >NUL 2>&1');
        } else {
            @proc_terminate($proc, 9);
        }
    }
}
