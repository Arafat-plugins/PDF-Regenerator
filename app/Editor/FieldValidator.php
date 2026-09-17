<?php

declare(strict_types=1);

namespace App\Editor;

use App\Data\FieldDictionary;
use App\Extraction\DataNormalizer;
use App\Extraction\Transforms;

/**
 * Validates and normalizes values typed in the editor, per FieldDictionary type (Part D §6).
 *
 * Every type accepts an empty value ("" = the user cleared it). Invalid input throws an
 * EditorException with a friendly message and is never stored.
 */
final class FieldValidator
{
    public const MAX_TEXT = 500;
    public const MAX_LONGTEXT = 5000;
    public const MAX_CELL = 500;
    public const MAX_LABEL = 60;

    /**
     * How the editor draws an input for a type: [input, inputmode, placeholder].
     */
    private const INPUTS = [
        'text' => ['text', 'text', ''],
        'longtext' => ['textarea', 'text', ''],
        'int' => ['text', 'numeric', 'e.g. 1250'],
        'decimal' => ['text', 'decimal', 'e.g. 1.9'],
        'percent' => ['text', 'decimal', 'e.g. 99.9%'],
        'score' => ['text', 'numeric', '0–100'],
        'duration' => ['text', 'text', 'e.g. 1.4 s or 250 ms'],
        'date' => ['date', 'text', ''],
        'url' => ['url', 'url', 'https://'],
        'email' => ['email', 'email', 'name@example.com'],
        'phone' => ['tel', 'tel', '+1 555 123 4567'],
        'money' => ['text', 'decimal', 'e.g. $250'],
    ];

    /** Suggestions offered for known status-like values (free text is still allowed). */
    private const SUGGESTIONS = [
        'uptime_status' => ['Online', 'Offline'],
        'wp_core_status' => ['Up to Date', 'Update Available'],
        'backup_frequency' => ['Daily', 'Weekly', 'Monthly'],
        'security_status' => ['Protected', 'Attention Needed'],
        'backup_status' => ['Created', 'Not Created'],
        'security_checks.status' => ['Active', 'Inactive'],
        'site_health.value' => ['OK', 'Warning', 'Critical'],
    ];

    /**
     * @return array{input: string, inputmode: string, placeholder: string}
     */
    public static function input(string $type): array
    {
        [$input, $mode, $placeholder] = self::INPUTS[$type] ?? self::INPUTS['text'];

        return ['input' => $input, 'inputmode' => $mode, 'placeholder' => $placeholder];
    }

    /**
     * @return list<string>
     */
    public static function suggestions(string $key): array
    {
        return self::SUGGESTIONS[$key] ?? [];
    }

    /** Validate a scalar field value (dictionary or custom key). */
    public static function scalar(string $key, mixed $value): string
    {
        $type = FieldDictionary::isCustom($key) ? 'text' : FieldDictionary::type($key);

        return self::value($type, $value, FieldDictionary::isCustom($key) ? 1000 : null);
    }

    /** Validate one cell of a list row. */
    public static function cell(string $list, string $column, mixed $value): string
    {
        $columns = FieldDictionary::columns($list);
        if (!isset($columns[$column])) {
            throw new EditorException('This column cannot be edited.', 'invalid', $column);
        }
        $type = $columns[$column];
        if ($type === 'flag') {
            throw new EditorException('This column cannot be edited.', 'invalid', $column);
        }

        return self::value($type, $value, self::MAX_CELL, $column);
    }

    public static function label(mixed $label): string
    {
        $label = self::singleLine(DataNormalizer::cleanValue(is_scalar($label) ? (string) $label : '', 200));
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
            throw new EditorException('Please enter a field name (up to ' . self::MAX_LABEL . ' characters).', 'invalid', 'label');
        }

        return $label;
    }

    public static function value(string $type, mixed $value, ?int $max = null, string $field = 'value'): string
    {
        if (!is_scalar($value) && $value !== null) {
            throw new EditorException('Please enter a valid value.', 'invalid', $field);
        }
        $raw = (string) $value;
        if (strlen($raw) > 20000) {
            throw new EditorException('This value is too long.', 'invalid', $field);
        }
        $value = DataNormalizer::cleanValue(str_replace(["\r\n", "\r"], "\n", $raw), 20000);
        if ($value === '') {
            return '';
        }
        $fail = static function (string $message) use ($field): never {
            throw new EditorException($message, 'invalid', $field);
        };

        switch ($type) {
            case 'longtext':
                $value = (string) preg_replace("/\n{3,}/", "\n\n", $value);
                $limit = $max ?? self::MAX_LONGTEXT;
                if (mb_strlen($value) > $limit) {
                    $fail('Please keep this text under ' . number_format($limit) . ' characters.');
                }

                return $value;

            case 'int':
                $digits = (string) preg_replace('/[\s,_\x{00A0}\x{202F}]/u', '', $value);
                if (preg_match('/^(\d{1,4}(?:\.\d{1,2})?)([kKmM])$/', $digits, $c)) {
                    return $c[1] . (strtolower($c[2]) === 'k' ? 'k' : 'M'); // compact count, e.g. 1.3k
                }
                if (!preg_match('/^\d{1,12}$/', $digits)) {
                    $fail('Please enter a whole number (for example 1250).');
                }

                return ltrim($digits, '0') === '' ? '0' : ltrim($digits, '0');

            case 'decimal':
                $number = str_replace(',', '.', (string) preg_replace('/\s+/', '', $value));
                if (!preg_match('/^-?\d{1,9}(?:\.\d{1,6})?$/', $number)) {
                    $fail('Please enter a number (for example 1.9).');
                }

                return $number;

            case 'percent':
                if (!preg_match('/^(\d{1,3}(?:[.,]\d{1,4})?)\s*%?$/', $value, $m) || (float) str_replace(',', '.', $m[1]) > 100) {
                    $fail('Please enter a percentage between 0 and 100 (for example 99.9%).');
                }

                return str_replace(',', '.', $m[1]) . '%';

            case 'score':
                if (!preg_match('/^\d{1,3}$/', $value) || (int) $value > 100) {
                    $fail('Please enter a score from 0 to 100.');
                }

                return (string) (int) $value;

            case 'duration':
                if (!preg_match('/^(\d{1,6}(?:[.,]\d{1,3})?)\s*(ms|s|sec|secs|seconds?)$/i', $value, $m)) {
                    $fail('Please enter a time with its unit (for example 1.4 s or 250 ms).');
                }
                $unit = strtolower($m[2]) === 'ms' ? 'ms' : 's';

                return str_replace(',', '.', $m[1]) . ' ' . $unit;

            case 'date':
                $iso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : Transforms::dateIso($value);
                if ($iso === null || !checkdate((int) substr($iso, 5, 2), (int) substr($iso, 8, 2), (int) substr($iso, 0, 4))
                    || (int) substr($iso, 0, 4) < 1900 || (int) substr($iso, 0, 4) > 2200) {
                    $fail('Please enter a valid date.');
                }

                return $iso;

            case 'url':
                $url = self::singleLine($value);
                if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $url) && preg_match('~^[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)+(?:[/?#].*)?$~i', $url)) {
                    $url = 'https://' . $url;
                }
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                if (mb_strlen($url) > 300 || !in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    $fail('Please enter a full web address (for example https://example.com/).');
                }

                return $url;

            case 'email':
                if (strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $fail('Please enter a valid email address.');
                }

                return $value;

            case 'phone':
                if (!preg_match('/^\+?[\d\s().\-\/]{5,30}$/', $value) || preg_match_all('/\d/', $value) < 5) {
                    $fail('Please enter a valid phone number.');
                }

                return (string) preg_replace('/\s+/', ' ', $value);

            case 'money':
                $money = self::singleLine($value);
                if (mb_strlen($money) > 40 || !preg_match('/\d/', $money)
                    || !preg_match('/^[\p{Sc}A-Za-z]{0,4}\s?[-+]?\d[\d.,\s]*(?:\s?[\p{Sc}A-Za-z]{1,4})?$/u', $money)) {
                    $fail('Please enter an amount (for example $250 or 1,200.00 EUR).');
                }

                return $money;

            default: // text
                $value = self::singleLine($value);
                $limit = $max ?? self::MAX_TEXT;
                if (mb_strlen($value) > $limit) {
                    $fail('Please keep this value under ' . number_format($limit) . ' characters.');
                }

                return $value;
        }
    }

    private static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s*\n\s*/u', ' ', $value));
    }
}
