<?php

declare(strict_types=1);

namespace App\Data;

use App\Core\Config;
use App\Extraction\DataNormalizer;
use App\Extraction\Transforms;

/**
 * View model for the Extracted Data page: fields grouped by section, list tables,
 * source badges and the needs-review count. Plain strings only — escape on output.
 */
final class DocumentPresenter
{
    public const SOURCES = [
        'profile_rule' => ['label' => 'From PDF', 'tone' => 'blue', 'hint' => 'Read from the PDF by the report rules'],
        'kv' => ['label' => 'Detected', 'tone' => 'purple', 'hint' => 'Found as a "Label: value" line'],
        'date' => ['label' => 'Detected', 'tone' => 'purple', 'hint' => 'First date found in the PDF'],
        'money' => ['label' => 'Detected', 'tone' => 'purple', 'hint' => 'Amount found in the PDF'],
        'contact' => ['label' => 'Detected', 'tone' => 'purple', 'hint' => 'Contact detail found in the PDF'],
        'table' => ['label' => 'Detected', 'tone' => 'purple', 'hint' => 'Table-like rows found in the PDF'],
        'title' => ['label' => 'Detected', 'tone' => 'purple', 'hint' => 'Most prominent line on page 1'],
        'derived' => ['label' => 'Calculated', 'tone' => 'green', 'hint' => 'Calculated from other values'],
        'inferred' => ['label' => 'Inferred', 'tone' => 'amber', 'hint' => 'Shown only as an icon in the PDF — please confirm'],
        'default' => ['label' => 'Template text', 'tone' => 'gray', 'hint' => 'Standard text from the template'],
        'profile' => ['label' => 'Profile', 'tone' => 'blue', 'hint' => 'Remembered from the website profile'],
        'manual' => ['label' => 'Edited', 'tone' => 'blue', 'hint' => 'Entered by you'],
    ];

    public const COLUMN_LABELS = [
        'label' => 'Item', 'percent' => 'Share', 'path' => 'Page', 'views' => 'Views', 'name' => 'Name',
        'version_from' => 'From', 'version_to' => 'To', 'date' => 'Date', 'status' => 'Status', 'value' => 'Value',
        'quantity' => 'Qty', 'price' => 'Price', 'total' => 'Total',
    ];

    public const SECTION_ICONS = [
        'cover' => 'file-text', 'summary' => 'list-checks', 'glance' => 'gauge', 'traffic' => 'chart-column',
        'performance' => 'zap', 'updates' => 'refresh-cw', 'backups' => 'hard-drive', 'security' => 'shield-check',
        'site_check' => 'file-search', 'closing' => 'pencil', 'general' => 'layers', 'custom' => 'plus',
    ];

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function present(array $doc): array
    {
        $dateFormat = (string) Config::get('display_date_format', 'M d, Y');
        $sections = [];
        $review = 0;

        foreach ((array) ($doc['fields'] ?? []) as $row) {
            if (!DataNormalizer::isActive($row) || !is_string($row['key'] ?? null)) {
                continue;
            }
            $key = $row['key'];
            $section = FieldDictionary::section($key);
            $value = (string) ($row['value'] ?? '');
            if (FieldDictionary::type($key) === 'date' && $value !== '') {
                $value = Transforms::formatDate($value, FieldDictionary::dateFormat($key, $dateFormat));
            }
            $needs = (bool) ($row['needs_review'] ?? false);
            $review += $needs ? 1 : 0;
            $sections[$section]['rows'][] = [
                'kind' => 'field',
                'key' => $key,
                'label' => (string) ($row['label'] ?? FieldDictionary::label($key)),
                'value' => $value,
                'long' => FieldDictionary::type($key) === 'longtext' || mb_strlen($value) > 90,
                'empty' => trim($value) === '',
                'source' => self::source((string) ($row['source'] ?? '')),
                'needs_review' => $needs,
                'note' => (string) ($row['note'] ?? ''),
                'page' => isset($row['page']) ? (int) $row['page'] : null,
            ];
        }

        foreach ((array) ($doc['lists'] ?? []) as $key => $rows) {
            if (!is_string($key) || !is_array($rows) || !FieldDictionary::isList($key)) {
                continue;
            }
            $columns = FieldDictionary::columns($key);
            $meta = is_array($doc['list_meta'][$key] ?? null) ? $doc['list_meta'][$key] : [];
            $out = [];
            $rowReview = 0;
            foreach ($rows as $row) {
                if (!DataNormalizer::isActive($row)) {
                    continue;
                }
                $cells = [];
                foreach ($columns as $column => $type) {
                    $cell = (string) ($row[$column] ?? '');
                    if ($type === 'date' && ($iso = Transforms::dateIso($cell)) !== null) {
                        $cell = Transforms::formatDate($iso, $dateFormat);
                    }
                    $cells[] = $cell;
                }
                $needs = (bool) ($row['needs_review'] ?? false);
                $rowReview += $needs ? 1 : 0;
                $out[] = ['cells' => $cells, 'source' => self::source((string) ($row['source'] ?? 'profile_rule')), 'needs_review' => $needs];
            }
            $listNeeds = (bool) ($meta['needs_review'] ?? false);
            $review += $rowReview + ($listNeeds ? 1 : 0);

            $section = FieldDictionary::section($key);
            $sections[$section]['rows'][] = [
                'kind' => 'list',
                'key' => $key,
                'label' => FieldDictionary::label($key),
                'columns' => array_map(static fn (string $c): string => self::COLUMN_LABELS[$c] ?? ucfirst(str_replace('_', ' ', $c)), array_keys($columns)),
                'numeric' => array_map(static fn (string $t): bool => in_array($t, ['int', 'percent', 'money'], true), array_values($columns)),
                'rows' => $out,
                'count' => count($out),
                'needs_review' => $listNeeds,
                'note' => (string) ($meta['note'] ?? ''),
                'review_rows' => $rowReview,
            ];
        }

        uksort($sections, static fn (string $a, string $b): int => FieldDictionary::sectionOrder($a) <=> FieldDictionary::sectionOrder($b));
        $groups = [];
        foreach ($sections as $id => $section) {
            $groups[] = [
                'id' => $id,
                'title' => FieldDictionary::SECTIONS[$id] ?? ucfirst($id),
                'icon' => self::SECTION_ICONS[$id] ?? 'layers',
                'rows' => $section['rows'],
                'review' => count(array_filter($section['rows'], static fn (array $r): bool => $r['needs_review'] || ($r['review_rows'] ?? 0) > 0)),
            ];
        }

        $notices = [];
        foreach ((array) ($doc['notices'] ?? []) as $notice) {
            if (is_array($notice) && is_string($notice['message'] ?? null)) {
                $notices[] = [
                    'type' => in_array($notice['type'] ?? '', ['warning', 'info', 'error'], true) ? $notice['type'] : 'info',
                    'code' => (string) ($notice['code'] ?? ''),
                    'lines' => explode("\n", $notice['message']),
                ];
            }
        }

        $unmatched = [];
        foreach ((array) ($doc['unmatched_lines'] ?? []) as $line) {
            if (is_array($line) && is_string($line['text'] ?? null)) {
                $unmatched[] = ['page' => (int) ($line['page'] ?? 0), 'text' => $line['text']];
            }
        }

        return [
            'groups' => $groups,
            'review_count' => $review,
            'field_count' => count(array_filter((array) ($doc['fields'] ?? []), static fn ($r): bool => is_array($r) && empty($r['removed']))),
            'list_count' => count((array) ($doc['lists'] ?? [])),
            'notices' => $notices,
            'unmatched' => $unmatched,
            'scanned' => (bool) ($doc['scanned'] ?? false),
            'engine' => (string) ($doc['engine'] ?? ''),
            'engine_label' => match ((string) ($doc['engine'] ?? '')) {
                'poppler' => 'Poppler (pdftotext)',
                'smalot' => 'Built-in PHP reader',
                default => 'Unknown',
            },
            'profile_name' => (string) ($doc['source_profile_name'] ?? ''),
            'page_count' => (int) ($doc['page_count'] ?? 0),
            'extracted_label' => format_datetime((string) ($doc['extracted_at'] ?? '')),
        ];
    }

    /**
     * @return array{label: string, tone: string, hint: string}
     */
    public static function source(string $source): array
    {
        return self::SOURCES[$source] ?? ['label' => ucfirst($source ?: 'unknown'), 'tone' => 'gray', 'hint' => ''];
    }
}
