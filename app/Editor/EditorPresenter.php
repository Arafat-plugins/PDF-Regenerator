<?php

declare(strict_types=1);

namespace App\Editor;

use App\Data\DocumentPresenter;
use App\Data\FieldDictionary;
use App\Extraction\DataNormalizer;
use App\Extraction\Transforms;
use App\Profiles\Branding;
use App\Templates\FieldMapper;
use App\Templates\PaletteManager;
use App\Templates\Template;
use App\Templates\TemplateManager;

/**
 * JSON model for the Review & Edit page (rendered by editor.js).
 *
 * Plain strings only — the browser inserts every value with textContent / input.value.
 * Section icons are trusted server-side SVG.
 */
final class EditorPresenter
{
    /** Column headings per list (falls back to DocumentPresenter::COLUMN_LABELS). */
    private const COLUMN_LABELS = [
        'maintenance_tasks' => ['label' => 'Task'],
        'traffic_sources' => ['label' => 'Source', 'percent' => 'Share'],
        'top_pages' => ['path' => 'Page path', 'views' => 'Views'],
        'plugin_updates' => ['name' => 'Plugin', 'version_from' => 'From', 'version_to' => 'To', 'date' => 'Date'],
        'theme_updates' => ['name' => 'Theme', 'version_from' => 'From', 'version_to' => 'To', 'date' => 'Date'],
        'security_checks' => ['label' => 'Check', 'status' => 'Status'],
        'site_health' => ['label' => 'Item', 'value' => 'Value'],
        'line_items' => ['name' => 'Item', 'quantity' => 'Qty', 'price' => 'Price', 'total' => 'Total'],
    ];

    /** Column width classes used by the list tables. */
    private const COLUMN_SIZES = [
        'version_from' => 'xs', 'version_to' => 'xs', 'views' => 'sm', 'percent' => 'sm', 'quantity' => 'xs',
        'price' => 'sm', 'total' => 'sm', 'date' => 'md', 'status' => 'md', 'value' => 'md',
    ];

    /**
     * @param array<string, mixed> $doc working copy
     * @return array<string, mixed>
     */
    public static function model(array $doc, ?TemplateManager $templates = null, ?Branding $branding = null, string $sourceId = ''): array
    {
        $doc = DocumentEditor::prepare($doc);
        $templates ??= new TemplateManager();
        $template = $templates->get(is_string($doc['template'] ?? null) ? $doc['template'] : null)
            ?? $templates->suggest(is_string($doc['source_profile'] ?? null) ? $doc['source_profile'] : null);
        $sections = [];
        $fields = [];
        $lists = [];
        $review = 0;
        $removed = 0;
        $items = 0;

        foreach ($doc['fields'] as $row) {
            $key = $row['key'];
            $type = FieldDictionary::isCustom($key) ? 'text' : FieldDictionary::type($key);
            $value = (string) ($row['value'] ?? '');
            $input = FieldValidator::input($type);
            if ($type === 'date' && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $input['input'] = 'text'; // unparsed legacy value — keep it visible
            }
            $source = (string) ($row['source'] ?? '');
            $derived = FieldDictionary::isDerived($key);
            $isRemoved = !empty($row['removed']);
            $included = ($row['included'] ?? true) !== false;
            $needs = (bool) ($row['needs_review'] ?? false);

            $section = FieldDictionary::section($key);
            $sections[$section] = true;
            if ($isRemoved) {
                $removed++;
            } else {
                $items++;
                $review += $needs && $included ? 1 : 0;
            }

            $fields[] = [
                'key' => $key,
                'label' => (string) ($row['label'] ?? FieldDictionary::label($key)),
                'value' => $value,
                'section' => $section,
                'type' => $type,
                'input' => $input['input'],
                'inputmode' => $input['inputmode'],
                'placeholder' => $input['placeholder'],
                'suggest' => FieldValidator::suggestions($key),
                'badge' => DocumentPresenter::source($source),
                'needs_review' => $needs,
                'can_confirm' => $needs && !($derived && $source === 'derived'),
                'note' => (string) ($row['note'] ?? ''),
                'page' => isset($row['page']) ? (int) $row['page'] : null,
                'included' => $included,
                'removed' => $isRemoved,
                'custom' => FieldDictionary::isCustom($key),
                'derived' => $derived,
                'recalculable' => $derived && $source === 'manual',
            ];
        }

        foreach ($doc['lists'] as $key => $rows) {
            if (!FieldDictionary::isList($key) || FieldDictionary::isDerived($key)) {
                continue;
            }
            $meta = is_array($doc['list_meta'][$key] ?? null) ? $doc['list_meta'][$key] : [];
            $columns = [];
            foreach (FieldDictionary::columns($key) as $column => $type) {
                $input = FieldValidator::input($type);
                $columns[] = [
                    'name' => $column,
                    'label' => self::COLUMN_LABELS[$key][$column] ?? DocumentPresenter::COLUMN_LABELS[$column] ?? ucfirst(str_replace('_', ' ', $column)),
                    'type' => $type,
                    'input' => $input['input'] === 'textarea' ? 'text' : $input['input'],
                    'inputmode' => $input['inputmode'],
                    'placeholder' => $input['placeholder'],
                    'numeric' => in_array($type, ['int', 'percent', 'money'], true),
                    'size' => self::COLUMN_SIZES[$column] ?? 'auto',
                    'suggest' => FieldValidator::suggestions($key . '.' . $column),
                ];
            }

            $outRows = [];
            foreach ($rows as $row) {
                $cells = [];
                $textCells = [];
                foreach (FieldDictionary::columns($key) as $column => $type) {
                    $cell = (string) ($row[$column] ?? '');
                    if ($type === 'date' && $cell !== '') {
                        $iso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $cell) ? $cell : Transforms::dateIso($cell);
                        if ($iso !== null) {
                            $cell = $iso;
                        } else {
                            $textCells[] = $column;
                        }
                    }
                    $cells[$column] = $cell;
                }
                $isRemoved = !empty($row['removed']);
                $included = ($row['included'] ?? true) !== false;
                $needs = (bool) ($row['needs_review'] ?? false);
                if ($isRemoved) {
                    $removed++;
                } else {
                    $items++;
                    $review += $needs && $included ? 1 : 0;
                }
                $outRows[] = [
                    'rid' => (string) $row['rid'],
                    'cells' => $cells,
                    'text_cells' => $textCells,
                    'badge' => DocumentPresenter::source((string) ($row['source'] ?? 'profile_rule')),
                    'needs_review' => $needs,
                    'included' => $included,
                    'removed' => $isRemoved,
                ];
            }

            $listNeeds = (bool) ($meta['needs_review'] ?? false);
            $review += $listNeeds ? 1 : 0;
            $section = FieldDictionary::section($key);
            $sections[$section] = true;
            $lists[] = [
                'key' => $key,
                'label' => FieldDictionary::label($key),
                'section' => $section,
                'columns' => $columns,
                'rows' => $outRows,
                'needs_review' => $listNeeds,
                'note' => (string) ($meta['note'] ?? ''),
                'max_rows' => DocumentEditor::MAX_LIST_ROWS,
            ];
        }

        $sectionList = [];
        foreach (array_keys(FieldDictionary::SECTIONS) as $id) {
            if (isset($sections[$id])) {
                $sectionList[] = [
                    'id' => $id,
                    'title' => FieldDictionary::SECTIONS[$id],
                    'icon' => icon(DocumentPresenter::SECTION_ICONS[$id] ?? 'layers', '', 16),
                ];
            }
        }

        $brand = null;
        if ($branding !== null && $sourceId !== '') {
            $brand = $branding->model($doc, $template, $sourceId);
            $brand['logo']['url'] = $brand['logo']['url'] !== '' ? url($brand['logo']['url']) : '';
            if ($brand['match'] !== null) {
                $brand['match']['label'] = 'This looks like ' . $brand['match']['name'] . '.';
            }
        }

        return [
            'template' => $template === null ? null : self::templateInfo($template, $doc),
            'branding' => $brand,
            'mapping' => $template === null ? null : self::mapping($template, $doc),
            'sections' => $sectionList,
            'fields' => $fields,
            'lists' => $lists,
            'review_count' => $review,
            'removed_count' => $removed,
            'item_count' => $items,
            'max_custom' => DocumentEditor::MAX_CUSTOM_FIELDS,
            'custom_count' => count(array_filter($fields, static fn (array $f): bool => $f['custom'])),
        ];
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, string>
     */
    private static function templateInfo(Template $template, array $doc): array
    {
        $paletteId = is_string($doc['palette'] ?? null) ? $doc['palette'] : (string) $template->defaultPalette();
        $theme = PaletteManager::theme($template, $paletteId, is_array($doc['custom_colors'] ?? null) ? $doc['custom_colors'] : null);
        $label = $paletteId === 'custom' ? 'Custom colors' : (string) ($template->palettes[$paletteId]['label'] ?? '');

        return [
            'id' => $template->id,
            'name' => $template->name,
            'palette' => $paletteId,
            'palette_label' => $label !== '' ? $label : 'Default colors',
            'palette_color' => $theme['theme_primary'],
        ];
    }

    /**
     * Template fields with their data source (Field Mapping tab).
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private static function mapping(Template $template, array $doc): array
    {
        $doc = DataNormalizer::applyDefaults(DataNormalizer::derive($doc), $template->defaults);
        $map = FieldMapper::map($template, $doc);
        $labels = [];
        foreach ($map['sources'] as $source) {
            $labels[$source['key']] = $source['label'];
        }
        $fields = [];
        foreach ($map['fields'] as $row) {
            $type = FieldDictionary::isCustom($row['field']) ? 'text' : FieldDictionary::type($row['field']);
            $input = FieldValidator::input($type);
            $display = $row['value'];
            $sourceType = $row['source'] !== null && FieldDictionary::isScalar($row['source']) ? FieldDictionary::type($row['source']) : 'text';
            if ($display !== '' && ($type === 'date' || $sourceType === 'date') && ($iso = Transforms::dateIso($display)) !== null) {
                $display = Transforms::formatDate($iso, (string) \App\Core\Config::get('display_date_format', 'M d, Y'));
            }
            $fields[] = $row + [
                'display' => $display,
                'source_label' => $row['source'] !== null ? ($labels[$row['source']] ?? FieldDictionary::label($row['source'])) : '',
                'derived' => FieldDictionary::isDerived($row['field']),
                'type' => $type,
                'input' => $input['input'] === 'textarea' ? 'text' : $input['input'],
                'inputmode' => $input['inputmode'],
                'placeholder' => $input['placeholder'],
            ];
        }
        $sources = array_map(static fn (array $s): array => ['key' => $s['key'], 'label' => $s['label'], 'section' => $s['section']], $map['sources']);

        return ['template' => $template->id, 'fields' => $fields, 'lists' => $map['lists'], 'sources' => $sources, 'missing' => $map['missing']];
    }

    /** JSON for a <script type="application/json"> block (safe against </script> and quotes). */
    public static function json(array $model): string
    {
        return (string) json_encode(
            $model,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }
}
