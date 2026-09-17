<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * All JSON metadata reads/writes go through here (Part C §14).
 *
 * Writes are done in place under an exclusive lock (LOCK_EX), and
 * update() performs a full read-modify-write while holding that lock,
 * so concurrent requests can never interleave and lose data.
 */
final class JsonStore
{
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @return array<mixed>
     */
    public static function read(string $path, array $default = []): array
    {
        if (!is_file($path)) {
            return $default;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            Logger::error('storage', 'JSON file could not be opened for reading', ['file' => basename($path)]);

            return $default;
        }

        try {
            flock($handle, LOCK_SH);
            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return self::decode((string) $raw, $path, $default);
    }

    /**
     * @param array<mixed> $data
     */
    public static function write(string $path, array $data): void
    {
        self::update($path, static fn (): array => $data);
    }

    /**
     * Atomically read, transform and write a JSON file.
     *
     * @param callable(array<mixed>): array<mixed> $callback
     * @return array<mixed> the data that was written
     */
    public static function update(string $path, callable $callback, array $default = []): array
    {
        self::ensureDirectory(dirname($path));

        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open JSON store: ' . basename($path));
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock JSON store: ' . basename($path));
            }

            $raw = stream_get_contents($handle);
            $current = self::decode((string) $raw, $path, $default);
            $next = $callback($current);

            $json = json_encode($next, self::FLAGS);

            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $json . "\n") === false) {
                throw new RuntimeException('Unable to write JSON store: ' . basename($path));
            }
            fflush($handle);
            flock($handle, LOCK_UN);

            return $next;
        } finally {
            fclose($handle);
        }
    }

    public static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create directory: ' . basename($dir));
        }
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $raw, string $path, array $default): array
    {
        if (trim($raw) === '') {
            return $default;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Logger::error('storage', 'Invalid JSON in metadata file', ['file' => basename($path), 'error' => $e->getMessage()]);

            return $default;
        }

        return is_array($data) ? $data : $default;
    }
}
