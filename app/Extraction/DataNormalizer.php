<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Data\FieldDictionary;

/**
 * Turns rule / detector results into the normalized document (Part D §4–§5):
 * canonical keys, labels, sources, review flags and derived values.
 *
 * Field row: { key, label, value, source, needs_review, removed, included?, page?, note? }
 * List row:  { <columns…>, source, needs_review, removed, included?, rid? }
 * source ∈ profile_rule, kv, date, money, contact, table, title, derived, inferred, default, profile, manual
 *
 * A row is used in the report only when it is active: not removed (Removed tab) and not
 * unticked in the editor (included === false).
 */
final class DataNormalizer
{
    public const VERSION = 1;
    private const MISMATCH_NOTE = 'Differs from the number of plugin update rows';

    /**
     * @param array{engine: string, page_count: int, text_bytes: int} $meta
     * @return array<string, mixed>
     */
    public function build(?SourceProfile $profile, ?ProfileResult $rules, GenericResult $generic, array $meta): array
    {
        $data = self::skeleton($meta);
        $data['source_profile'] = $profile?->id;
        $data['source_profile_name'] = $profile?->name;

        if ($rules !== null) {
            foreach ($rules->fields as $key => $result) {
                $data['fields'][] = self::row($key, $result['value'], 'profile_rule', $result['needs_review'], $result['page']);
            }
            foreach ($rules->lists as $key => $list) {
                $data['lists'][$key] = array_map([self::class, 'cleanListRow'], $list['rows']);
                $data['list_meta'][$key] = [
                    'source' => 'profile_rule',
                    // optional lists (e.g. theme updates) are simply absent from many reports
                    'needs_review' => !$list['found'] && empty($list['optional']),
                    'note' => $list['found'] || !empty($list['optional']) ? '' : 'Not found in the PDF',
                ];
            }
        }

        foreach ($generic->fields as $key => $result) {
            $row = self::row($key, $result['value'], $result['source'], $result['needs_review'], $result['page']);
            if (($result['label'] ?? null) !== null && FieldDictionary::isCustom($key)) {
                $row['label'] = mb_substr((string) $result['label'], 0, 60);
            }
            $data['fields'][] = $row;
        }

        if ($generic->lineItems !== [] && !isset($data['lists']['line_items'])) {
            $data['lists']['line_items'] = array_map([self::class, 'cleanListRow'], $generic->lineItems);
            $data['list_meta']['line_items'] = ['source' => 'table', 'needs_review' => true, 'note' => 'Detected from table-like rows — please check the columns'];
        }

        $data['unmatched_lines'] = $generic->unmatched;

        if ($profile === null) {
            $data['notices'][] = [
                'type' => 'info',
                'code' => 'generic',
                'message' => 'This PDF did not match a known report layout, so only general details (dates, contacts, "Label: value" lines, tables) were detected. Please review everything carefully.',
            ];
        }

        return self::derive(self::sortFields($data));
    }

    /**
     * Document for an image-only PDF: no values, OCR notice (Part D §4 "Scanned PDFs").
     *
     * @param array{engine: string, page_count: int, text_bytes: int} $meta
     * @return array<string, mixed>
     */
    public function scanned(array $meta): array
    {
        $data = self::skeleton($meta);
        $data['scanned'] = true;
        $data['notices'][] = [
            'type' => 'warning',
            'code' => 'scanned',
            'message' => "This PDF does not contain readable text.\nOCR may be required to extract its information.",
        ];

        return $data;
    }

    /**
     * @param array{engine: string, page_count: int, text_bytes: int} $meta
     * @return array<string, mixed>
     */
    private static function skeleton(array $meta): array
    {
        return [
            'version' => self::VERSION,
            'source_profile' => null,
            'source_profile_name' => null,
            'engine' => $meta['engine'],
            'page_count' => $meta['page_count'],
            'text_bytes' => $meta['text_bytes'],
            'scanned' => false,
            'extracted_at' => date('Y-m-d H:i:s'),
            'template' => null,
            'palette' => null,
            'fields' => [],
            'lists' => [],
            'list_meta' => [],
            'notices' => [],
            'unmatched_lines' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(string $key, string $value, string $source, bool $needsReview, ?int $page = null): array
    {
        $row = [
            'key' => $key,
            'label' => FieldDictionary::label($key),
            'value' => self::cleanValue($value),
            'source' => $source,
            'needs_review' => $needsReview,
            'removed' => false,
        ];
        if ($page !== null) {
            $row['page'] = $page;
        }

        return $row;
    }

    public static function cleanValue(string $value, int $max = 5000): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|[\x{202A}-\x{202E}]|[\x{2066}-\x{2069}]/u', '', $value);

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cleanListRow(array $row): array
    {
        $out = [];
        foreach ($row as $column => $value) {
            if ($column === 'needs_review' || $column === 'removed') {
                $out[$column] = (bool) $value;
            } elseif (is_scalar($value)) {
                $out[$column] = self::cleanValue((string) $value, 1000);
            }
        }
        $out['removed'] = $out['removed'] ?? false;
        $out['needs_review'] = $out['needs_review'] ?? false;
        $out['source'] = $out['source'] ?? 'profile_rule';

        return $out;
    }

    /** Row is used in the report: not removed and not excluded in the editor. */
    public static function isActive(mixed $row): bool
    {
        return is_array($row) && empty($row['removed']) && ($row['included'] ?? true) !== false;
    }

    /**
     * Grouped by section. Within a section: dictionary order (custom rows last, in detection
     * order) — or, once the user has reordered rows in the editor (field_order = manual),
     * the stored order.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sortFields(array $data): array
    {
        $fields = array_values(array_filter((array) ($data['fields'] ?? []), static fn ($r): bool => is_array($r) && is_string($r['key'] ?? null)));
        $manual = ($data['field_order'] ?? null) === 'manual';
        // usort is stable (PHP 8), so equal rows keep their stored order.
        usort($fields, static function (array $a, array $b) use ($manual): int {
            $sa = FieldDictionary::sectionOrder(FieldDictionary::section($a['key']));
            $sb = FieldDictionary::sectionOrder(FieldDictionary::section($b['key']));
            if ($manual) {
                return $sa <=> $sb;
            }

            return [$sa, FieldDictionary::order($a['key'])] <=> [$sb, FieldDictionary::order($b['key'])];
        });
        $data['fields'] = $fields;

        return $data;
    }

    /**
     * Recompute derived values and consistency checks (idempotent).
     * A derived row the user has edited (source "manual") is never overwritten.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function derive(array $data): array
    {
        $has = static function (string $key) use (&$data): bool {
            return self::find($data, $key) !== null;
        };
        $val = static function (string $key) use (&$data): string {
            $i = self::find($data, $key);

            return $i === null || !self::isActive($data['fields'][$i]) ? '' : trim((string) $data['fields'][$i]['value']);
        };
        $listRows = static function (string $key) use (&$data): ?array {
            if (!isset($data['lists'][$key]) || !is_array($data['lists'][$key])) {
                return null;
            }

            return array_values(array_filter($data['lists'][$key], [self::class, 'isActive']));
        };

        // reporting_period = d M Y – d M Y
        if ($has('reporting_start') || $has('reporting_end')) {
            $start = $val('reporting_start');
            $end = $val('reporting_end');
            $ok = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end);
            $period = $ok ? Transforms::formatDate($start, 'd M Y') . ' – ' . Transforms::formatDate($end, 'd M Y') : '';
            $data = self::setDerived($data, 'reporting_period', $period);
        }

        // security_status
        $checks = $listRows('security_checks');
        if ($checks !== null) {
            $vulnerability = null;
            $allActive = $checks !== [];
            foreach ($checks as $row) {
                $label = strtolower((string) ($row['label'] ?? ''));
                if ($vulnerability === null && str_contains($label, 'vulnerab')) {
                    $vulnerability = trim((string) ($row['status'] ?? ''));
                    continue;
                }
                if (strtolower(trim((string) ($row['status'] ?? ''))) !== 'active') {
                    $allActive = false;
                }
            }
            $status = $allActive && $vulnerability !== null && $vulnerability !== '' ? 'Protected' : 'Attention Needed';
            $data = self::setDerived($data, 'security_status', $status);
        }

        // backup_status
        if ($has('backups_count')) {
            $count = $val('backups_count');
            $status = ctype_digit($count) ? ((int) $count >= 1 ? 'Created' : 'Not Created') : '';
            $data = self::setDerived($data, 'backup_status', $status);
        }

        // uptime_note
        if ($has('uptime_percent')) {
            $uptime = $val('uptime_percent');
            $note = $uptime === '' ? '' : (preg_match('/^100(?:\.0+)?\s*%$/', $uptime) ? 'Fully available' : 'Minor downtime detected');
            $data = self::setDerived($data, 'uptime_note', $note);
        }

        // maintenance_tasks_count
        $tasks = $listRows('maintenance_tasks');
        if ($tasks !== null) {
            $data = self::setDerived($data, 'maintenance_tasks_count', (string) count($tasks));
        }

        // footer_left = {website address} • Confidential Maintenance Report (website name when there is no address)
        if ($has('website_url') || $has('website_name')) {
            $site = self::footerSite($has('website_url') ? $val('website_url') : '', $has('website_name') ? $val('website_name') : '');
            $data = self::setDerived($data, 'footer_left', $site === '' ? '' : $site . ' • Confidential Maintenance Report');
        }

        // plugins_updated_count must equal the number of plugin_updates rows
        // (a mismatch the user has confirmed in the editor stays noted but no longer asks for review)
        $plugins = $listRows('plugin_updates');
        $countIndex = self::find($data, 'plugins_updated_count');
        if ($plugins !== null && $countIndex !== null) {
            $count = $val('plugins_updated_count');
            $mismatch = $count !== '' && ctype_digit($count) && (int) $count !== count($plugins);
            $acknowledged = ($data['list_meta']['plugin_updates']['ack'] ?? null) === self::pluginCountSignature($data);
            $row = &$data['fields'][$countIndex];
            if ($mismatch) {
                $row['needs_review'] = !$acknowledged;
                $row['note'] = self::MISMATCH_NOTE . ' (' . count($plugins) . ')';
                $data['list_meta']['plugin_updates']['needs_review'] = !$acknowledged;
                $data['list_meta']['plugin_updates']['note'] = 'The report says ' . $count . ' plugin updates but lists ' . count($plugins);
            } elseif (str_starts_with((string) ($row['note'] ?? ''), self::MISMATCH_NOTE)) {
                $row['needs_review'] = false;
                unset($row['note']);
                $data['list_meta']['plugin_updates']['needs_review'] = false;
                $data['list_meta']['plugin_updates']['note'] = '';
            }
            unset($row);
        }

        return $data;
    }

    /**
     * "count:rows" for the plugin-count check — what the user confirmed when accepting a mismatch.
     *
     * @param array<string, mixed> $data
     */
    public static function pluginCountSignature(array $data): string
    {
        $i = self::find($data, 'plugins_updated_count');
        $count = $i === null || !self::isActive($data['fields'][$i]) ? '' : trim((string) $data['fields'][$i]['value']);
        $rows = count(array_filter((array) ($data['lists']['plugin_updates'] ?? []), [self::class, 'isActive']));

        return $count . ':' . $rows;
    }

    /**
     * Template boilerplate for keys the source has no value for (source "default").
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $defaults
     * @return array<string, mixed>
     */
    public static function applyDefaults(array $data, array $defaults): array
    {
        foreach ($defaults as $key => $text) {
            if (!is_string($key) || !is_string($text) || !FieldDictionary::isScalar($key) || FieldDictionary::isDerived($key)) {
                continue;
            }
            $i = self::find($data, $key);
            if ($i === null) {
                $data['fields'][] = self::row($key, $text, 'default', false);
                continue;
            }
            $row = $data['fields'][$i];
            if (trim((string) $row['value']) === '' && !in_array($row['source'], ['manual', 'profile'], true)) {
                $data['fields'][$i]['value'] = self::cleanValue($text);
                $data['fields'][$i]['source'] = 'default';
                $data['fields'][$i]['needs_review'] = false;
            }
        }

        return self::sortFields($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function find(array $data, string $key): ?int
    {
        foreach ($data['fields'] ?? [] as $i => $row) {
            if (is_array($row) && ($row['key'] ?? null) === $key) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function setDerived(array $data, string $key, string $value): array
    {
        $i = self::find($data, $key);
        if ($i === null) {
            $data['fields'][] = self::row($key, $value, 'derived', $value === '');

            return self::sortFields($data);
        }
        if (($data['fields'][$i]['source'] ?? '') === 'manual') {
            return $data;
        }
        $data['fields'][$i]['value'] = $value;
        $data['fields'][$i]['source'] = 'derived';
        $data['fields'][$i]['needs_review'] = $value === '';

        return $data;
    }

    /** "https://www.brandsec.com.au/" → "www.brandsec.com.au"; falls back to the website name. */
    public static function footerSite(string $url, string $name): string
    {
        $url = trim($url);
        if ($url !== '') {
            $host = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $url);
            $host = strtolower((string) preg_replace('~[/?#].*$~', '', $host));
            if ($host !== '') {
                return $host;
            }
        }

        return trim($name);
    }
}
