<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * Compiles regex strings from source-profile JSON into safe PCRE patterns.
 */
final class Pattern
{
    private const DELIMITER = '~';
    private const MAX_LENGTH = 500;

    /** @var array<string, string|false> */
    private static array $cache = [];

    /**
     * @return string|null compiled pattern, or null when the pattern is invalid
     */
    public static function compile(string $pattern, bool $caseInsensitive): ?string
    {
        $cacheKey = ($caseInsensitive ? 'i:' : 's:') . $pattern;
        if (array_key_exists($cacheKey, self::$cache)) {
            $cached = self::$cache[$cacheKey];

            return $cached === false ? null : $cached;
        }

        if ($pattern === '' || strlen($pattern) > self::MAX_LENGTH || str_contains($pattern, "\0")) {
            self::$cache[$cacheKey] = false;

            return null;
        }

        $compiled = self::DELIMITER . self::escapeDelimiter($pattern) . self::DELIMITER . 'u' . ($caseInsensitive ? 'i' : '');
        $ok = @preg_match($compiled, '') !== false;
        self::$cache[$cacheKey] = $ok ? $compiled : false;

        return $ok ? $compiled : null;
    }

    /**
     * Match with a guard against catastrophic backtracking (preg returns false → no match).
     *
     * @param array<int|string, string> $matches
     */
    public static function match(string $compiled, string $subject, ?array &$matches = null): bool
    {
        $matches = [];

        return @preg_match($compiled, $subject, $matches) === 1;
    }

    private static function escapeDelimiter(string $pattern): string
    {
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $out .= $char . $pattern[++$i];
                continue;
            }
            $out .= $char === self::DELIMITER ? '\\' . $char : $char;
        }

        return $out;
    }
}
