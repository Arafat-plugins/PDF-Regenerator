<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Read-only access to config/app.php values.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /**
     * @param array<string, mixed> $items
     */
    public static function load(array $items): void
    {
        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, self::$items) ? self::$items[$key] : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$items[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$items;
    }

    public static function storagePath(string $sub = ''): string
    {
        $base = rtrim((string) self::get('storage_path'), '/\\');

        return $sub === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($sub, '/\\');
    }
}
