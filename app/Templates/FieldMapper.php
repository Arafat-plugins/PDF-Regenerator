<?php

declare(strict_types=1);

namespace App\Templates;

use App\Data\FieldDictionary;
use App\Extraction\DataNormalizer;

/**
 * Connects the fields a template needs with the data a document has (Part D §8).
 *
 * Order for every scalar template field:
 *   1. the user's choice (`field_mappings[template][field]`: a source key, or "-" = leave empty)
 *   2. exact key            (report_title → report_title)
 *   3. normalized key/label (template "client_name" ← custom row labelled "Client name")
 *   4. similar field        (small table of related dictionary keys, then label similarity ≥ 80 %)
 *
 * Lists are matched by exact key only. Mappings are always { template_key: source_key }.
 */
final class FieldMapper
{
    public const NONE = '-';

    /** Related dictionary keys, best first (used when the exact key has no value). */
    private const RELATED = [
        'title' => ['report_title'],
        'subtitle' => ['report_subtitle', 'report_kicker'],
        'date' => ['reporting_end', 'site_check_date', 'last_backup_date'],
        'description' => ['site_check_summary'],
        'status' => ['security_status', 'uptime_status'],
        'client_name' => ['website_name'],
        'report_title' => ['title'],
        'report_subtitle' => ['subtitle'],
        'summary_text' => ['description'],
        'website_name' => ['title'],
    ];

    private const FUZZY_MIN = 80.0;

    /**
     * @param array<string, mixed> $doc working copy
     * @return array{
     *   fields: list<array{field: string, label: string, source: ?string, method: string, value: string, missing: bool, choice: ?string}>,
     *   lists: list<array{field: string, label: string, rows: int, found: bool}>,
     *   sources: list<array{key: string, label: string, section: string, value: string}>,
     *   missing: int
     * }
     */
    public static function map(Template $template, array $doc): array
    {
        $sources = self::sources($doc);
        $saved = self::saved($doc, $template->id);
        $fields = [];
        $missing = 0;
        $taken = [];

        foreach ($template->scalarFields() as $field) {
            $taken[$field] = true; // a template field's own key is never "borrowed" for another field
        }

        foreach ($template->scalarFields() as $field) {
            $choice = $saved[$field] ?? null;
            $source = null;
            $method = 'none';

            if ($choice === self::NONE) {
                $method = 'empty';
            } elseif ($choice !== null && isset($sources[$choice])) {
                $source = $choice;
                $method = 'manual';
            } else {
                $choice = null;
                [$source, $method] = self::suggest($field, $sources, $taken);
            }

            $value = $source !== null ? $sources[$source]['value'] : '';
            $isMissing = $method !== 'empty' && trim($value) === '' && !FieldDictionary::isDerived($field);
            $missing += $isMissing ? 1 : 0;
            $fields[] = [
                'field' => $field,
                'label' => FieldDictionary::label($field),
                'source' => $source,
                'method' => $method,
                'value' => $value,
                'missing' => $isMissing,
                'choice' => $choice,
            ];
        }

        $lists = [];
        foreach ($template->listFields() as $list) {
            $rows = FieldDictionary::isDerived($list) ? null : count(array_filter((array) ($doc['lists'][$list] ?? []), [DataNormalizer::class, 'isActive']));
            $lists[] = [
                'field' => $list,
                'label' => FieldDictionary::label($list),
                'rows' => (int) $rows,
                'found' => $rows === null || isset($doc['lists'][$list]),
                'derived' => FieldDictionary::isDerived($list),
            ];
        }

        return ['fields' => $fields, 'lists' => $lists, 'sources' => array_values($sources), 'missing' => $missing];
    }

    /**
     * The document as the template should see it: mapped values copied onto the template keys,
     * "leave empty" choices blanked. Source rows are left as they are.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function apply(Template $template, array $doc): array
    {
        $map = self::map($template, $doc);
        foreach ($map['fields'] as $row) {
            $field = $row['field'];
            if ($row['method'] === 'exact') {
                continue;
            }
            $i = DataNormalizer::find($doc, $field);
            $value = $row['method'] === 'empty' ? '' : $row['value'];
            if ($row['source'] === null && $row['method'] !== 'empty') {
                continue; // nothing to copy — an existing (empty) row stays as it is
            }
            if ($i === null) {
                $virtual = DataNormalizer::row($field, $value, 'mapped', false);
                $virtual['mapped_from'] = $row['source'];
                $doc['fields'][] = $virtual;
            } else {
                $doc['fields'][$i]['value'] = $value;
                $doc['fields'][$i]['removed'] = false;
                $doc['fields'][$i]['included'] = true;
            }
        }

        return $doc;
    }

    /**
     * Validate a mapping choice from the editor.
     *
     * @param array<string, mixed> $doc
     */
    public static function isValidChoice(Template $template, array $doc, string $field, string $source): bool
    {
        if (!in_array($field, $template->scalarFields(), true)) {
            return false;
        }
        if ($source === '' || $source === self::NONE) {
            return true;
        }

        return isset(self::sources($doc)[$source]);
    }

    public static function normalize(string $text): string
    {
        $text = strtolower((string) preg_replace('/^custom_/', '', $text));

        return (string) preg_replace('/[^a-z0-9]+/', '', $text);
    }

    /**
     * Active scalar rows with a value source.
     *
     * @param array<string, mixed> $doc
     * @return array<string, array{key: string, label: string, section: string, value: string}>
     */
    public static function sources(array $doc): array
    {
        $out = [];
        foreach ((array) ($doc['fields'] ?? []) as $row) {
            if (!DataNormalizer::isActive($row) || !is_string($row['key'] ?? null) || ($row['source'] ?? '') === 'mapped') {
                continue;
            }
            $key = $row['key'];
            if (!FieldDictionary::isScalar($key) && !FieldDictionary::isCustom($key)) {
                continue;
            }
            $out[$key] = [
                'key' => $key,
                'label' => (string) ($row['label'] ?? FieldDictionary::label($key)),
                'section' => FieldDictionary::SECTIONS[FieldDictionary::section($key)] ?? 'General',
                'value' => trim((string) ($row['value'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, array{key: string, label: string, section: string, value: string}> $sources
     * @param array<string, bool> $taken
     * @return array{0: ?string, 1: string}
     */
    private static function suggest(string $field, array $sources, array $taken): array
    {
        // 1. exact key (even when empty — the row belongs to this field)
        if (isset($sources[$field]) && $sources[$field]['value'] !== '') {
            return [$field, 'exact'];
        }

        $candidates = array_filter($sources, static fn (array $s): bool => $s['value'] !== '' && !isset($taken[$s['key']]));
        $target = self::normalize($field);
        $targetLabel = self::normalize(FieldDictionary::label($field));

        // 2. normalized key or label
        foreach ($candidates as $key => $s) {
            if (self::normalize($key) === $target || self::normalize($s['label']) === $target || self::normalize($s['label']) === $targetLabel) {
                return [$key, 'matched'];
            }
        }

        // 3. related dictionary keys, then label similarity (custom rows only)
        foreach (self::RELATED[$field] ?? [] as $related) {
            if (isset($sources[$related]) && $sources[$related]['value'] !== '') {
                return [$related, 'similar'];
            }
        }
        $best = null;
        $bestScore = self::FUZZY_MIN;
        foreach ($candidates as $key => $s) {
            if (!FieldDictionary::isCustom($key)) {
                continue;
            }
            similar_text($targetLabel, self::normalize($s['label']), $percent);
            if ($percent >= $bestScore) {
                $best = $key;
                $bestScore = $percent;
            }
        }
        if ($best !== null) {
            return [$best, 'similar'];
        }

        return isset($sources[$field]) ? [$field, 'exact'] : [null, 'none'];
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, string>
     */
    private static function saved(array $doc, string $templateId): array
    {
        $saved = $doc['field_mappings'][$templateId] ?? [];

        return is_array($saved) ? array_filter($saved, static fn ($v, $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH) : [];
    }
}
