<?php

declare(strict_types=1);

namespace App\Templates;

use App\Core\Config;
use App\Data\FieldDictionary;
use App\Extraction\DataNormalizer;
use App\Extraction\Transforms;

/**
 * Builds the flat placeholder data for a template from a normalized document:
 * included rows only, display formatting, render-only derived keys, theme colors.
 */
final class TemplateData
{
    public const PAGESPEED_BAD = '#ef4444';
    public const PAGESPEED_MID = '#fbbf24';
    public const PAGESPEED_GOOD = '#34d399';
    public const PATH_DISPLAY_MAX = 48;

    /**
     * @param array<string, mixed> $document normalized document (working copy)
     * @param array<string, string> $theme theme_* colors from PaletteManager
     * @param array<string, string> $system logo_src, pagespeed_ring_src …
     * @return array<string, mixed>
     */
    public static function build(array $document, array $theme, array $system = []): array
    {
        $dateFormat = (string) Config::get('display_date_format', 'M d, Y');
        $view = [];

        foreach ((array) ($document['fields'] ?? []) as $row) {
            if (!DataNormalizer::isActive($row) || !is_string($row['key'] ?? null)) {
                continue;
            }
            $key = $row['key'];
            if (!FieldDictionary::isScalar($key) && !FieldDictionary::isCustom($key)) {
                continue;
            }
            $value = trim((string) ($row['value'] ?? ''));
            if (FieldDictionary::type($key) === 'date') {
                $value = Transforms::formatDate($value, FieldDictionary::dateFormat($key, $dateFormat));
            }
            $view[$key] = $value;
        }

        foreach ((array) ($document['lists'] ?? []) as $key => $rows) {
            if (!is_string($key) || !FieldDictionary::isList($key) || FieldDictionary::isDerived($key) || !is_array($rows)) {
                continue;
            }
            $columns = FieldDictionary::columns($key);
            $out = [];
            foreach ($rows as $row) {
                if (!DataNormalizer::isActive($row)) {
                    continue;
                }
                $clean = [];
                foreach ($columns as $column => $type) {
                    $value = trim((string) ($row[$column] ?? ''));
                    if ($type === 'date') {
                        $iso = Transforms::dateIso($value);
                        $value = $iso !== null ? Transforms::formatDate($iso, $dateFormat) : $value;
                    }
                    $clean[$column] = $value;
                }
                if (implode('', $clean) === '') {
                    continue;
                }
                if ($key === 'top_pages') {
                    $clean['path_display'] = self::shortPath($clean['path']);
                }
                if ($key === 'site_health') {
                    // Text statuses ("OK") are drawn as badges, versions ("7.1") as plain values.
                    $clean['is_status'] = preg_match('/^v?\d/i', $clean['value']) || $clean['value'] === '' ? '' : '1';
                }
                $out[] = $clean;
            }
            $view[$key] = $out;
        }

        // Render-only derived keys
        $tasks = $view['maintenance_tasks'] ?? [];
        $half = (int) ceil(count($tasks) / 2);
        $view['maintenance_tasks_col1'] = array_slice($tasks, 0, $half);
        $view['maintenance_tasks_col2'] = array_slice($tasks, $half);

        $score = self::score($view['pagespeed_score'] ?? '');
        $view['pagespeed_color'] = self::pagespeedColor($score);
        $view['uptime_color'] = self::statusColor((string) ($view['uptime_percent'] ?? ''), static fn (string $v): bool => (bool) preg_match('/^100(\.0+)?\s*%$/', $v), false);
        $view['security_status_color'] = self::statusColor((string) ($view['security_status'] ?? ''), static fn (string $v): bool => strcasecmp($v, 'Protected') === 0, false);
        $view['backup_status_color'] = self::statusColor((string) ($view['backup_status'] ?? ''), static fn (string $v): bool => strcasecmp($v, 'Created') === 0, strcasecmp((string) ($view['backup_status'] ?? ''), 'Not Created') === 0);

        $custom = [];
        foreach ($view as $key => $value) {
            if (is_string($value) && $value !== '' && FieldDictionary::isCustom($key)) {
                $custom[] = ['label' => self::labelFor($document, $key), 'value' => $value];
            }
        }
        $view['custom_fields'] = $custom;
        $view['custom_fields_count'] = $custom === [] ? '' : (string) count($custom);

        $details = [];
        foreach (['client_name', 'email', 'phone', 'website_url', 'address', 'amount', 'status', 'date'] as $key) {
            if (($view[$key] ?? '') !== '') {
                $details[] = ['label' => self::labelFor($document, $key), 'value' => $view[$key], 'is_status' => $key === 'status' ? '1' : ''];
            }
        }
        $view['detail_rows'] = array_merge($details, array_map(static fn (array $r): array => $r + ['is_status' => ''], $custom));
        $view['detail_rows_count'] = $view['detail_rows'] === [] ? '' : (string) count($view['detail_rows']);
        $view['line_items_count'] = empty($view['line_items']) ? '' : (string) count($view['line_items']);
        // missing_<key> = "1" when a single value is empty (a real 0 is not missing) — for "—" placeholders
        foreach ($view as $key => $value) {
            if (is_string($value) && FieldDictionary::isScalar($key)) {
                $view['missing_' . $key] = trim($value) === '' ? '1' : '';
            }
        }
        $view['plugin_updates_count'] = empty($view['plugin_updates']) ? '' : (string) count($view['plugin_updates']);
        $view['theme_updates_count'] = empty($view['theme_updates']) ? '' : (string) count($view['theme_updates']);
        $view['brand_name'] = (string) ($view['signature_company'] ?? '');

        foreach ($theme as $key => $color) {
            if (FieldDictionary::isColorKey($key) && PaletteManager::isHex($color)) {
                $view[$key] = $color;
            }
        }
        // "good" values follow the success color
        $success = $theme['theme_success'] ?? self::PAGESPEED_GOOD;
        if (PaletteManager::isHex($success) && strtolower($success) !== self::PAGESPEED_GOOD) {
            foreach (['pagespeed_color', 'uptime_color', 'security_status_color', 'backup_status_color'] as $key) {
                if ($view[$key] === self::PAGESPEED_GOOD) {
                    $view[$key] = strtolower($success);
                }
            }
        }
        // footer texts show a bare host: "https://goodtechies.com/" → "goodtechies.com"
        foreach (['footer_left', 'footer_right'] as $key) {
            if (isset($view[$key]) && is_string($view[$key])) {
                $view[$key] = self::bareUrls($view[$key]);
            }
        }
        foreach ($system as $key => $value) {
            if (FieldDictionary::isSystem($key)) {
                $view[$key] = $value;
            }
        }

        return $view;
    }

    /** Drops "http://", "https://", "www." and a lone trailing slash from web addresses in a text. */
    public static function bareUrls(string $text): string
    {
        return (string) preg_replace_callback('~\bhttps?://(?:www\.)?([^\s/]+)(/[^\s]*)?~i', static function (array $m): string {
            $path = $m[2] ?? '';

            return $m[1] . ($path === '/' ? '' : $path);
        }, $text);
    }

    public static function score(mixed $value): ?int
    {
        if (is_int($value)) {
            return max(0, min(100, $value));
        }
        if (is_string($value) && preg_match('/^\s*(\d{1,3})\s*$/', $value, $m) && (int) $m[1] <= 100) {
            return (int) $m[1];
        }

        return null;
    }

    public static function pagespeedColor(?int $score): string
    {
        if ($score === null) {
            return '#71717a';
        }

        return $score >= 90 ? self::PAGESPEED_GOOD : ($score >= 50 ? self::PAGESPEED_MID : self::PAGESPEED_BAD);
    }

    /**
     * Semantic color for a status value: green when good, red when clearly bad, amber otherwise.
     *
     * @param callable(string): bool $isGood
     */
    public static function statusColor(string $value, callable $isGood, bool $isBad): string
    {
        $value = trim($value);
        if ($value === '') {
            return '#71717a';
        }
        if ($isGood($value)) {
            return self::PAGESPEED_GOOD;
        }

        return $isBad ? self::PAGESPEED_BAD : self::PAGESPEED_MID;
    }

    public static function shortPath(string $path): string
    {
        if (mb_strlen($path) <= self::PATH_DISPLAY_MAX) {
            return $path;
        }

        return mb_substr($path, 0, 22) . '…' . mb_substr($path, -(self::PATH_DISPLAY_MAX - 23));
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function labelFor(array $document, string $key): string
    {
        foreach ((array) ($document['fields'] ?? []) as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $key && is_string($row['label'] ?? null) && $row['label'] !== '') {
                return $row['label'];
            }
        }

        return FieldDictionary::label($key);
    }

    /**
     * section_<name> = "1" when at least one of the section's keys has data, else "" (the template
     * leaves the whole section out). "No data" = empty, or a zero such as 0, 0%, 0.0 or 0 ms;
     * a list has data when it has at least one row that is still included.
     *
     * @param array<string, mixed> $view
     * @param array<string, list<string>> $sections
     * @return array<string, string>
     */
    public static function sectionFlags(array $view, array $sections): array
    {
        $flags = [];
        foreach ($sections as $name => $keys) {
            $has = false;
            foreach ($keys as $key) {
                $value = $view[$key] ?? '';
                if (is_array($value) ? $value !== [] : self::hasData((string) $value)) {
                    $has = true;
                    break;
                }
            }
            $flags['section_' . $name] = $has ? '1' : '';
        }

        return $flags;
    }

    /**
     * Per-box flags for card groups: a box whose value is missing is left out and the others
     * close up.
     *
     *   card_<group>_<key>  grid:  "<class>-<boxes in this row>" (+ " gap" when not first in the row)
     *                       stack: "<class>" (+ first_class on the first box, + last_class on the last)
     *                       ""     when the value is missing (the template leaves the box out)
     *   brk_<group>_<key>   "1" on the first box of the 2nd, 3rd … row (grid only)
     *
     * Rows are balanced: 4 boxes with 3 per row → 2 + 2, 5 → 3 + 2.
     *
     * @param array<string, mixed> $view
     * @param array<string, array{keys: list<string>, class: string, per_row: int, stack: bool, first_class: string, last_class: string}> $groups
     * @return array<string, string>
     */
    public static function cardFlags(array $view, array $groups): array
    {
        $flags = [];
        foreach ($groups as $name => $group) {
            $present = [];
            foreach ($group['keys'] as $key) {
                $flags['card_' . $name . '_' . $key] = '';
                $flags['brk_' . $name . '_' . $key] = '';
                if (self::isPresent($view[$key] ?? '')) {
                    $present[] = $key;
                }
            }
            $count = count($present);
            if ($count === 0) {
                continue;
            }
            if ($group['stack']) {
                foreach ($present as $i => $key) {
                    $classes = [$group['class']];
                    if ($i === 0 && $group['first_class'] !== '') {
                        $classes[] = $group['first_class'];
                    }
                    if ($i === $count - 1 && $group['last_class'] !== '') {
                        $classes[] = $group['last_class'];
                    }
                    $flags['card_' . $name . '_' . $key] = implode(' ', $classes);
                }
                continue;
            }
            $rows = (int) ceil($count / $group['per_row']);
            $base = intdiv($count, $rows);
            $extra = $count % $rows;
            $offset = 0;
            for ($r = 0; $r < $rows; $r++) {
                $size = $base + ($r < $extra ? 1 : 0);
                for ($c = 0; $c < $size; $c++) {
                    $key = $present[$offset + $c];
                    $flags['card_' . $name . '_' . $key] = $group['class'] . '-' . $size . ($c > 0 ? ' gap' : '');
                    $flags['brk_' . $name . '_' . $key] = $r > 0 && $c === 0 ? '1' : '';
                }
                $offset += $size;
            }
        }

        return $flags;
    }

    /** A single value that can be shown (a real 0 counts; empty, "—" or "N/A" do not). */
    public static function isPresent(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        $value = trim((string) $value);

        return $value !== '' && !preg_match('~^(?:[-–—]+|n/?a)$~iu', $value);
    }

    public static function hasData(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && !preg_match('/^[-–—]?$|^0+(?:[.,]0+)?\s*(?:%|s|ms|sec|seconds?)?$/iu', $value);
    }
}
