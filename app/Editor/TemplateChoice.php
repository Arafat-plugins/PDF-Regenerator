<?php

declare(strict_types=1);

namespace App\Editor;

use App\Extraction\DataNormalizer;
use App\Templates\FieldMapper;
use App\Templates\TemplateManager;

/**
 * Template-related edits of the working copy (Part D §6, §8):
 *
 *   set_template  template            choose another stock template
 *   set_mapping   field, source       template field ← source key ("" = automatic, "-" = leave empty)
 */
final class TemplateChoice
{
    public const ACTIONS = ['set_template', 'set_mapping'];

    public function __construct(private ?TemplateManager $templates = null)
    {
        $this->templates ??= new TemplateManager();
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $input
     * @return array{doc: array<string, mixed>, result: array<string, mixed>}
     * @throws EditorException
     */
    public function apply(array $doc, string $action, array $input): array
    {
        $doc = DocumentEditor::prepare($doc);

        if ($action === 'set_template') {
            $template = $this->templates->get(is_string($input['template'] ?? null) ? $input['template'] : null);
            if ($template === null) {
                throw new EditorException('This template is not available. Please reload the page.', 'stale', 'template');
            }
            // Untouched template texts take the new template's wording; nothing else is removed,
            // so switching back and forth never loses data.
            foreach ($doc['fields'] as $i => $row) {
                if (($row['source'] ?? '') === 'default' && array_key_exists($row['key'], $template->defaults)) {
                    $doc['fields'][$i]['value'] = DataNormalizer::cleanValue($template->defaults[$row['key']]);
                }
            }
            $doc['template'] = $template->id;
            // Colors follow the template until the user picks colors himself (palette_chosen, Phase 6).
            if (empty($doc['palette_chosen']) || !is_string($doc['palette'] ?? null)
                || ($doc['palette'] !== 'custom' && !isset($template->palettes[$doc['palette']]))) {
                $doc['palette'] = $template->defaultPalette();
            }
            $doc = DataNormalizer::applyDefaults($doc, $template->defaults);

            return ['doc' => DataNormalizer::derive($doc), 'result' => ['template' => $template->id, 'name' => $template->name]];
        }

        if ($action === 'set_mapping') {
            $template = $this->templates->get(is_string($doc['template'] ?? null) ? $doc['template'] : null);
            $field = is_string($input['field'] ?? null) ? $input['field'] : '';
            $source = is_string($input['source'] ?? null) ? $input['source'] : '';
            if ($template === null || !FieldMapper::isValidChoice($template, $doc, $field, $source)) {
                throw new EditorException('This choice is not available any more. Please reload the page.', 'stale', 'field');
            }
            $mappings = is_array($doc['field_mappings'] ?? null) ? $doc['field_mappings'] : [];
            $current = is_array($mappings[$template->id] ?? null) ? $mappings[$template->id] : [];
            if ($source === '') {
                unset($current[$field]);
            } else {
                $current[$field] = $source;
            }
            $mappings[$template->id] = $current;
            $doc['field_mappings'] = $mappings;

            return ['doc' => $doc, 'result' => ['field' => $field, 'source' => $source]];
        }

        throw new EditorException('Unknown action.', 'invalid', 'action');
    }
}
