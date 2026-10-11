<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The ONE canonical field-key list (Part E §17.1a).
 *
 * Source profiles, template config.json files, the editor and website-profile
 * field mappings may only use keys defined here (plus `custom_*`).
 *
 * Types: text, longtext, int, decimal, percent, score, duration, date, url, email, phone, money.
 */
final class FieldDictionary
{
    public const SECTIONS = [
        'cover' => 'Cover',
        'summary' => 'Weekly Maintenance Summary',
        'glance' => 'Website At a Glance',
        'traffic' => 'Traffic & Analytics',
        'performance' => 'Performance & Availability',
        'updates' => 'Updates',
        'backups' => 'Backups',
        'security' => 'Security & Site Health',
        'site_check' => 'Page & Form Checking',
        'closing' => 'Closing & Footer',
        'general' => 'General',
        'custom' => 'Custom Fields',
    ];

    /**
     * key => [label, section, type, derived?, display date format?]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3?: bool, 4?: string}>
     */
    private const SCALARS = [
        // Cover
        'report_title' => ['Report Title', 'cover', 'text'],
        'report_kicker' => ['Report Kicker', 'cover', 'text'],
        'report_subtitle' => ['Report Subtitle', 'cover', 'text'],
        'website_name' => ['Website Name', 'cover', 'text'],
        'website_url' => ['Website URL', 'cover', 'url'],
        'reporting_start' => ['Reporting Start', 'cover', 'date'],
        'reporting_end' => ['Reporting End', 'cover', 'date'],
        'reporting_period' => ['Reporting Period', 'cover', 'text', true],
        // Summary
        'summary_text' => ['Summary Text', 'summary', 'longtext'],
        'client_name' => ['Client Name', 'summary', 'text'],
        'uptime_percent' => ['Uptime', 'summary', 'percent'],
        'uptime_status' => ['Uptime Status', 'summary', 'text'],
        'security_status' => ['Security Status', 'summary', 'text', true],
        'backup_status' => ['Backup Status', 'summary', 'text', true],
        'maintenance_tasks_count' => ['Checks Completed', 'summary', 'int', true],
        // Glance
        'total_sessions' => ['Total Sessions', 'glance', 'int'],
        'organic_traffic' => ['Organic Traffic', 'glance', 'int'],
        'pagespeed_score' => ['PageSpeed Score', 'glance', 'score'],
        'plugins_updated_count' => ['Plugins Updated', 'glance', 'int'],
        'backups_count' => ['Backups Created', 'glance', 'int'],
        'uptime_note' => ['Uptime Note', 'glance', 'text', true],
        'backup_note' => ['Backup Note', 'glance', 'text'],
        // Traffic
        'unique_visitors' => ['Unique Visitors', 'traffic', 'int'],
        'pages_per_session' => ['Pages / Session', 'traffic', 'decimal'],
        // Performance
        'response_time' => ['Response Time', 'performance', 'duration'],
        'fcp' => ['First Contentful Paint (FCP)', 'performance', 'duration'],
        'lcp' => ['Largest Contentful Paint (LCP)', 'performance', 'duration'],
        'tti' => ['Time to Interactive (TTI)', 'performance', 'duration'],
        'speed_index' => ['Speed Index', 'performance', 'duration'],
        'cls' => ['Cumulative Layout Shift (CLS)', 'performance', 'decimal'],
        'tbt' => ['Total Blocking Time (TBT)', 'performance', 'duration'],
        // Updates
        'themes_updated_count' => ['Themes Updated', 'updates', 'int'],
        'custom_tasks_count' => ['Custom Work Tasks', 'updates', 'int'],
        'wp_version' => ['WordPress Version', 'updates', 'text'],
        'wp_core_status' => ['WordPress Core Status', 'updates', 'text'],
        // Backups
        'backups_available' => ['Backups Available', 'backups', 'int'],
        'last_backup_date' => ['Last Backup', 'backups', 'date'],
        'backup_frequency' => ['Backup Frequency', 'backups', 'text'],
        // Security
        'vulnerability_monitoring_frequency' => ['Vulnerability Monitoring', 'security', 'text'],
        'php_version' => ['PHP Version', 'security', 'text'],
        // Site check
        'site_check_date' => ['Checked On', 'site_check', 'date', false, 'F d, Y'],
        'site_check_summary' => ['Check Summary', 'site_check', 'longtext'],
        'site_check_result' => ['Check Result', 'site_check', 'text'],
        // Closing & footer
        'signature_name' => ['Signature Name', 'closing', 'text'],
        'signature_title' => ['Signature Title', 'closing', 'text'],
        'signature_company' => ['Signature Company', 'closing', 'text'],
        'footer_left' => ['Footer (left)', 'closing', 'text', true],
        'footer_right' => ['Footer (right)', 'closing', 'text'],
        // Generic keys (generic detectors, custom rows, simple templates)
        'title' => ['Title', 'general', 'text'],
        'subtitle' => ['Subtitle', 'general', 'text'],
        'date' => ['Date', 'general', 'date'],
        'address' => ['Address', 'general', 'longtext'],
        'description' => ['Description', 'general', 'longtext'],
        'status' => ['Status', 'general', 'text'],
        'email' => ['Email', 'general', 'email'],
        'phone' => ['Phone', 'general', 'phone'],
        'amount' => ['Amount', 'general', 'money'],
        'total' => ['Total', 'general', 'money'],
    ];

    /**
     * key => [label, section, columns (name => type), derived?]
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, string>, 3?: bool}>
     */
    private const LISTS = [
        'maintenance_tasks' => ['Completed Maintenance', 'summary', ['label' => 'text']],
        'maintenance_tasks_col1' => ['Maintenance (column 1)', 'summary', ['label' => 'text'], true],
        'maintenance_tasks_col2' => ['Maintenance (column 2)', 'summary', ['label' => 'text'], true],
        'traffic_sources' => ['Traffic Sources', 'traffic', ['label' => 'text', 'percent' => 'percent']],
        'top_pages' => ['Top Performing Pages', 'traffic', ['path' => 'text', 'path_display' => 'text', 'views' => 'int']],
        'plugin_updates' => ['Plugin Updates', 'updates', ['name' => 'text', 'version_from' => 'text', 'version_to' => 'text', 'date' => 'date']],
        'theme_updates' => ['Theme Updates', 'updates', ['name' => 'text', 'version_from' => 'text', 'version_to' => 'text', 'date' => 'date']],
        'security_checks' => ['Security Monitoring', 'security', ['label' => 'text', 'status' => 'text']],
        'site_health' => ['Site Health', 'security', ['label' => 'text', 'value' => 'text', 'is_status' => 'flag']],
        'line_items' => ['Line Items', 'general', ['name' => 'text', 'quantity' => 'text', 'price' => 'money', 'total' => 'money']],
        'custom_fields' => ['Custom Fields', 'custom', ['label' => 'text', 'value' => 'text'], true],
        'detail_rows' => ['Details', 'general', ['label' => 'text', 'value' => 'text'], true],
    ];

    /** Columns that are computed, never stored or edited. */
    private const DERIVED_COLUMNS = ['top_pages' => ['path_display'], 'site_health' => ['is_status']];

    /** Injected by the app at render time. */
    private const SYSTEM = [
        'logo_src', 'pagespeed_ring_src', 'pagespeed_color', 'uptime_color', 'security_status_color', 'backup_status_color',
        'security_status_label', 'security_status_note', 'backup_status_label', 'backup_status_note',
        'backup_headline', 'backup_text', 'core_headline', 'core_text',
        'custom_fields_count', 'line_items_count', 'theme_updates_count', 'plugin_updates_count', 'detail_rows_count', 'brand_name', 'theme_primary_light',
        'theme_primary', 'theme_primary_dark', 'theme_primary_bright', 'theme_primary_soft',
        'theme_primary_faint', 'theme_cover_start', 'theme_cover_mid', 'theme_cover_end',
    ];

    public const CUSTOM_PATTERN = '/^custom_[a-z0-9]+(?:_[a-z0-9]+)*$/';

    public static function isScalar(string $key): bool
    {
        return isset(self::SCALARS[$key]);
    }

    public static function isList(string $key): bool
    {
        return isset(self::LISTS[$key]);
    }

    public static function isSystem(string $key): bool
    {
        return in_array($key, self::SYSTEM, true) || self::isSizeKey($key) || self::isSectionKey($key)
            || (bool) preg_match('/^missing_[a-z][a-z0-9_]{0,40}$/', $key)
            || (bool) preg_match('/^(?:card|brk)_[a-z][a-z0-9]{0,19}_[a-z][a-z0-9_]{0,63}$/', $key);
    }

    /** section_<name> — "1" when a template section has data (see Template::$sections). */
    public static function isSectionKey(string $key): bool
    {
        return (bool) preg_match('/^section_[a-z][a-z0-9_]{0,29}$/', $key);
    }

    /** logo_<box>_width / logo_<box>_height — the only size placeholders allowed in CSS (values like "12.5mm"). */
    public static function isSizeKey(string $key): bool
    {
        return (bool) preg_match('/^logo_[a-z]{1,20}_(?:width|height)$/', $key);
    }

    public static function isSizeValue(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^\d{1,3}(?:\.\d{1,2})?mm$/', $value);
    }

    public static function isCustom(string $key): bool
    {
        return (bool) preg_match(self::CUSTOM_PATTERN, $key) && strlen($key) <= 64
            && !self::isList($key) && !self::isScalar($key) && !self::isSystem($key);
    }

    /** Any key a template or mapping may reference. */
    public static function isKnown(string $key): bool
    {
        return self::isScalar($key) || self::isList($key) || self::isSystem($key) || self::isCustom($key);
    }

    /** Keys a source profile rule may write (no derived / system keys). */
    public static function isExtractable(string $key): bool
    {
        return (self::isScalar($key) || self::isList($key)) && !self::isDerived($key);
    }

    public static function isDerived(string $key): bool
    {
        return (bool) (self::SCALARS[$key][3] ?? self::LISTS[$key][3] ?? false);
    }

    /** Color keys are the only placeholders allowed in CSS (Part D §7). */
    public static function isColorKey(string $key): bool
    {
        return str_starts_with($key, 'theme_') || str_ends_with($key, '_color');
    }

    public static function label(string $key): string
    {
        if (isset(self::SCALARS[$key])) {
            return self::SCALARS[$key][0];
        }
        if (isset(self::LISTS[$key])) {
            return self::LISTS[$key][0];
        }

        return ucwords(str_replace('_', ' ', (string) preg_replace('/^custom_/', '', $key)));
    }

    public static function section(string $key): string
    {
        if (isset(self::SCALARS[$key])) {
            return self::SCALARS[$key][1];
        }
        if (isset(self::LISTS[$key])) {
            return self::LISTS[$key][1];
        }

        return self::isCustom($key) ? 'custom' : 'general';
    }

    public static function type(string $key): string
    {
        return self::SCALARS[$key][2] ?? 'text';
    }

    /** PHP date() format used to display a date key. */
    public static function dateFormat(string $key, string $default): string
    {
        return self::SCALARS[$key][4] ?? $default;
    }

    /**
     * @return array<string, string> column => type (stored columns only)
     */
    public static function columns(string $listKey): array
    {
        $columns = self::LISTS[$listKey][2] ?? [];
        foreach (self::DERIVED_COLUMNS[$listKey] ?? [] as $derived) {
            unset($columns[$derived]);
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    public static function scalarKeys(): array
    {
        return array_keys(self::SCALARS);
    }

    /**
     * @return list<string>
     */
    public static function listKeys(): array
    {
        return array_keys(self::LISTS);
    }

    /** Position of a key in dictionary order (used to sort fields). */
    public static function order(string $key): int
    {
        static $order = null;
        $order ??= array_flip(array_merge(array_keys(self::SCALARS), array_keys(self::LISTS)));

        return $order[$key] ?? 10000;
    }

    public static function sectionOrder(string $section): int
    {
        static $order = null;
        $order ??= array_flip(array_keys(self::SECTIONS));

        return $order[$section] ?? 999;
    }

    /**
     * `custom_` + slug of a label, unique against $taken.
     *
     * @param array<string, mixed> $taken keys already used
     */
    public static function customKey(string $label, array $taken = []): string
    {
        $ascii = $label;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
            if (is_string($converted)) {
                $ascii = $converted;
            }
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii)), '_');
        $slug = substr($slug !== '' ? $slug : 'field', 0, 40);
        $slug = rtrim($slug, '_');

        $key = 'custom_' . $slug;
        $n = 2;
        while (isset($taken[$key]) || !self::isCustom($key)) {
            $key = 'custom_' . $slug . '_' . $n++;
        }

        return $key;
    }
}
