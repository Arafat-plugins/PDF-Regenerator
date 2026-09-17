<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Core\Config;
use DateTimeImmutable;

/**
 * The finite transform catalogue of source profiles (Part D §4).
 *
 * Scalar transforms return null when the input cannot be converted — the caller
 * keeps the raw value and flags the field for review instead of guessing.
 */
final class Transforms
{
    public const SCALAR = [
        'int', 'decimal1', 'decimal2_percent', 'decimal3', 'seconds1', 'strip_trailing_zero_percent',
        'score_0_100', 'date_iso', 'title_case', 'yes_no_online', 'traffic_label_short', 'sentence',
    ];

    public const LIST_ONLY = ['sort_desc', 'limit'];

    /** Which list column a scalar transform works on when used in a list rule. */
    public const LIST_COLUMN = [
        'traffic_label_short' => 'label',
        'decimal2_percent' => 'percent',
        'date_iso' => 'date',
        'title_case' => 'label',
        'int' => 'views',
    ];

    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Validate a transform spec such as "int", "sort_desc:views" or "limit:max_top_pages".
     */
    public static function isValid(string $spec, bool $inList): bool
    {
        [$name, $arg] = self::split($spec);
        if (in_array($name, self::SCALAR, true)) {
            return $arg === null || ($inList && (bool) preg_match('/^[a-z_]+$/', $arg));
        }
        if ($inList && in_array($name, self::LIST_ONLY, true)) {
            return $arg !== null && (bool) preg_match('/^[a-z0-9_]+$/', $arg);
        }

        return false;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    public static function split(string $spec): array
    {
        $parts = explode(':', $spec, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    public static function apply(string $name, string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return match ($name) {
            'int' => self::toInt($value),
            'decimal1' => self::decimal($value, 1),
            'decimal3' => self::decimal($value, 3),
            'decimal2_percent' => self::percent($value, 2),
            'seconds1' => self::seconds($value),
            'strip_trailing_zero_percent' => self::stripZeroPercent($value),
            'score_0_100' => self::score($value),
            'date_iso' => self::dateIso($value),
            'title_case' => self::titleCase($value),
            'yes_no_online' => self::yesNoOnline($value),
            'traffic_label_short' => self::trafficLabel($value),
            'sentence' => mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1),
            default => $value,
        };
    }

    /**
     * Apply list transforms to rows (scalar transforms on their column, then sort/limit).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string> $specs
     * @return list<array<string, mixed>>
     */
    public static function applyToRows(array $rows, array $specs): array
    {
        foreach ($specs as $spec) {
            [$name, $arg] = self::split($spec);

            if ($name === 'sort_desc' && $arg !== null) {
                // Stable sort, numeric when possible.
                $indexed = [];
                foreach ($rows as $i => $row) {
                    $indexed[] = [$i, $row];
                }
                usort($indexed, static function (array $a, array $b) use ($arg): int {
                    $cmp = self::sortValue($b[1][$arg] ?? '') <=> self::sortValue($a[1][$arg] ?? '');

                    return $cmp !== 0 ? $cmp : $a[0] <=> $b[0];
                });
                $rows = array_map(static fn (array $pair): array => $pair[1], $indexed);
                continue;
            }

            if ($name === 'limit' && $arg !== null) {
                $limit = ctype_digit($arg) ? (int) $arg : (int) Config::get($arg, 0);
                if ($limit > 0) {
                    $rows = array_slice($rows, 0, $limit);
                }
                continue;
            }

            $column = $arg ?? (self::LIST_COLUMN[$name] ?? null);
            if ($column === null) {
                continue;
            }
            foreach ($rows as $i => $row) {
                if (!array_key_exists($column, $row)) {
                    continue;
                }
                $converted = self::apply($name, (string) $row[$column]);
                if ($converted === null) {
                    $rows[$i]['needs_review'] = true;
                } else {
                    $rows[$i][$column] = $converted;
                }
            }
        }

        return array_values($rows);
    }

    private static function sortValue(mixed $value): float
    {
        $clean = str_replace(',', '', (string) $value);

        return preg_match('/-?\d+(?:\.\d+)?/', $clean, $m) ? (float) $m[0] : -INF;
    }

    private static function number(string $value): ?float
    {
        $clean = str_replace([',', "\u{00A0}", ' '], '', $value);
        if (!preg_match('/-?\d+(?:\.\d+)?/', $clean, $m)) {
            return null;
        }

        return (float) $m[0];
    }

    private static function toInt(string $value): ?string
    {
        // "1,234", "786", "8 plugins" → digits only; reject decimals like "1.9".
        $clean = str_replace([',', "\u{00A0}", ' '], '', $value);
        // Compact counts as the report prints them ("1.3k", "2M") are kept as they are — the exact number is not in the PDF.
        if (preg_match('/^(\d{1,4}(?:\.\d{1,2})?)([kKmM])$/', $clean, $c)) {
            return $c[1] . ($c[2] === 'k' || $c[2] === 'K' ? 'k' : 'M');
        }
        if (!preg_match('/^\D*?(-?\d+)(?:\.0+)?(?!\.\d)\D*$/', $clean, $m)) {
            return null;
        }

        return (string) (int) $m[1];
    }

    private static function decimal(string $value, int $places): ?string
    {
        $n = self::number($value);

        return $n === null ? null : number_format($n, $places, '.', '');
    }

    private static function percent(string $value, int $places): ?string
    {
        $n = self::number($value);

        return $n === null ? null : number_format($n, $places, '.', '') . '%';
    }

    private static function seconds(string $value): ?string
    {
        $n = self::number($value);
        if ($n === null) {
            return null;
        }
        if (preg_match('/\d\s*ms\b/i', $value)) {
            $n /= 1000;
        } elseif (preg_match('/\d\s*(min|m)\b/i', $value)) {
            $n *= 60;
        }

        return number_format($n, 1, '.', '') . ' s';
    }

    private static function stripZeroPercent(string $value): ?string
    {
        if (!preg_match('/^(\d{1,3})(?:\.(\d+))?\s*%$/', $value, $m)) {
            return null;
        }
        $decimals = $m[2] ?? '';
        if ($decimals === '' || trim($decimals, '0') === '') {
            return $m[1] . '%';
        }

        return $m[1] . '.' . $decimals . '%';
    }

    private static function score(string $value): ?string
    {
        if (!preg_match('/^(\d{1,3})(?:\s*\/\s*100)?$/', $value, $m)) {
            return null;
        }
        $score = (int) $m[1];

        return $score <= 100 ? (string) $score : null;
    }

    public static function dateIso(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        // 2026-09-01
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        // September 01, 2026 / Sept 1 2026 / Sep. 1, 2026
        if (preg_match('/^([A-Za-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/', $value, $m)) {
            $month = self::MONTHS[strtolower(substr($m[1], 0, 3))] ?? 0;

            return self::ymd((int) $m[3], $month, (int) $m[2]);
        }
        // 1 September 2026 / 01 Sep 2026
        if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]{3,9})\.?,?\s+(\d{4})$/', $value, $m)) {
            $month = self::MONTHS[strtolower(substr($m[2], 0, 3))] ?? 0;

            return self::ymd((int) $m[3], $month, (int) $m[1]);
        }
        // dd/mm/yyyy or dd.mm.yyyy (day first, as used outside the US); mm/dd when day > 12 is impossible
        if (preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})$/', $value, $m)) {
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            if ($b > 12 && $a <= 12) {
                [$a, $b] = [$b, $a];
            }

            return self::ymd((int) $m[3], $b, $a);
        }

        return null;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        if ($m < 1 || !checkdate($m, $d, $y) || $y < 1970 || $y > 2200) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /**
     * Format an ISO date for display; non-ISO values are returned unchanged.
     */
    public static function formatDate(string $value, string $format): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? $value : $date->format($format);
    }

    private static function titleCase(string $value): string
    {
        $words = preg_split('/(\s+)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$value];
        foreach ($words as $i => $word) {
            if ($word === '' || ctype_space($word)) {
                continue;
            }
            // Keep acronyms/versions ("PHP", "v7.1") as they are.
            if (preg_match('/^[\p{Lu}\d.]+$/u', $word) && mb_strlen($word) > 1) {
                continue;
            }
            $words[$i] = mb_strtoupper(mb_substr($word, 0, 1)) . mb_strtolower(mb_substr($word, 1));
        }

        return implode('', $words);
    }

    private static function yesNoOnline(string $value): string
    {
        return match (strtolower($value)) {
            'yes', 'up', 'online', 'true' => 'Online',
            'no', 'down', 'offline', 'false' => 'Offline',
            default => $value,
        };
    }

    private static function trafficLabel(string $value): string
    {
        $map = [
            'direct traffic' => 'Direct',
            'organic traffic' => 'Organic',
            'organic search' => 'Organic',
            'other sources' => 'Other',
            'referral traffic' => 'Referral',
            'social traffic' => 'Social',
            'paid traffic' => 'Paid',
        ];

        return $map[strtolower(trim($value))] ?? $value;
    }
}
