<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * String helpers for display titles and file names.
 */
final class Text
{
    /**
     * Clean a user-supplied display title: no control / bidi-override characters,
     * collapsed whitespace, length-limited. Output is still escaped when printed.
     */
    public static function cleanTitle(string $value, int $max = 120): string
    {
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $value = function_exists('mb_convert_encoding') ? (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8') : '';
        }

        // Control chars, zero-width and bidi override characters (used to disguise extensions).
        $value = (string) preg_replace('/[\x00-\x1F\x7F]|\x{200B}|\x{200C}|\x{200D}|\x{2060}|\x{FEFF}|[\x{202A}-\x{202E}]|[\x{2066}-\x{2069}]/u', '', $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $value = trim($value, " \t.-_");

        return self::limit($value, $max);
    }

    /**
     * Display version of an uploaded file name (never used as a path).
     */
    public static function cleanFileName(string $name, int $max = 150): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);

        return self::limit(self::cleanTitle($name, $max + 10), $max);
    }

    /**
     * Title derived from a file name: "client report (final) v2.pdf" → "client report (final) v2".
     */
    public static function titleFromFileName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = (string) preg_replace('/\.pdf$/i', '', $base);
        $title = self::cleanTitle(str_replace('_', ' ', $base));

        return $title !== '' ? $title : 'Untitled PDF';
    }

    /**
     * ASCII-safe file name for Content-Disposition fallbacks and generated files.
     */
    public static function safeFileName(string $title, string $fallback = 'document'): string
    {
        $ascii = $title;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            if (is_string($converted)) {
                $ascii = $converted;
            }
        }
        $ascii = (string) preg_replace('/[\'"`]+/', '', $ascii);
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $ascii), '_');

        return $ascii !== '' ? rtrim(substr($ascii, 0, 100), '_') : $fallback;
    }

    /**
     * File name with spaces kept: "Plcworld Maintenance Report 14 Sept 2026". Only letters,
     * digits, single spaces, dots, dashes and underscores; no leading dot; max 100 characters.
     */
    public static function readableFileName(string $title, string $fallback = 'document'): string
    {
        $ascii = $title;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title);
            if (is_string($converted)) {
                $ascii = $converted;
            }
        }
        $ascii = (string) preg_replace('/[\'"`]+/', '', $ascii);
        $ascii = (string) preg_replace('/[^A-Za-z0-9._\-]+/', ' ', $ascii);
        $ascii = (string) preg_replace('/\.{2,}/', '.', $ascii);
        $ascii = trim($ascii, " ._-");
        $ascii = rtrim(substr($ascii, 0, 100), " ._-");

        return $ascii !== '' ? $ascii : $fallback;
    }

    public static function limit(string $value, int $max): string
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8') > $max ? rtrim(mb_substr($value, 0, $max, 'UTF-8')) : $value;
        }

        return strlen($value) > $max ? rtrim(substr($value, 0, $max)) : $value;
    }

    public static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
