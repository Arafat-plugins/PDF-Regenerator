<?php

declare(strict_types=1);

namespace App\Templates;

use App\Core\Config;
use App\Core\Logger;
use App\Data\FieldDictionary;

/**
 * Auto-discovers stock-templates/* — no registration step (Part D §7).
 */
final class TemplateManager
{
    /** @var array<string, Template>|null */
    private ?array $templates = null;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (string) Config::get('template_path'), '/\\');
    }

    /**
     * @return array<string, Template> sorted by name
     */
    public function all(): array
    {
        if ($this->templates !== null) {
            return $this->templates;
        }

        $this->templates = [];
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $folder) {
            $id = basename($folder);
            if (!self::isValidId($id)) {
                continue;
            }
            $template = $this->load($id, $folder);
            if ($template !== null) {
                $this->templates[$id] = $template;
            }
        }
        uasort($this->templates, static fn (Template $a, Template $b): int => strcasecmp($a->name, $b->name));

        return $this->templates;
    }

    public function get(?string $id): ?Template
    {
        return $id !== null && self::isValidId($id) ? ($this->all()[$id] ?? null) : null;
    }

    /**
     * Template to pre-select for a document: one that declares the source profile, else the first.
     */
    public function suggest(?string $sourceProfile): ?Template
    {
        $all = $this->all();
        if ($sourceProfile !== null) {
            foreach ($all as $template) {
                if (in_array($sourceProfile, $template->sourceProfiles, true)) {
                    return $template;
                }
            }
        }

        // no matching layout: a general-purpose template (one that is not tied to a PDF family)
        foreach ($all as $template) {
            if ($template->sourceProfiles === []) {
                return $template;
            }
        }

        return $all === [] ? null : reset($all);
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $id);
    }

    private function load(string $id, string $folder): ?Template
    {
        $configFile = $folder . DIRECTORY_SEPARATOR . 'config.json';
        if (!is_file($folder . DIRECTORY_SEPARATOR . 'template.html') || !is_file($configFile)) {
            Logger::warning('templates', 'Template folder ignored (template.html or config.json missing)', ['template' => $id]);

            return null;
        }

        try {
            $config = json_decode((string) file_get_contents($configFile), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Logger::warning('templates', 'Template config.json is not valid JSON', ['template' => $id, 'error' => $e->getMessage()]);

            return null;
        }
        if (!is_array($config)) {
            return null;
        }

        $fields = [];
        foreach ((array) ($config['fields'] ?? []) as $field) {
            if (is_string($field) && FieldDictionary::isKnown($field)) {
                $fields[] = $field;
            } else {
                Logger::warning('templates', 'Unknown field in template config ignored', ['template' => $id, 'field' => is_scalar($field) ? (string) $field : gettype($field)]);
            }
        }

        $defaults = [];
        foreach ((array) ($config['defaults'] ?? []) as $key => $text) {
            if (is_string($key) && is_string($text) && FieldDictionary::isScalar($key)) {
                $defaults[$key] = $text;
            }
        }

        $palettes = [];
        foreach ((array) ($config['palettes'] ?? []) as $paletteId => $palette) {
            if (is_string($paletteId) && preg_match('/^[a-z0-9-]{1,40}$/', $paletteId) && is_array($palette)) {
                $valid = PaletteManager::validatePreset($palette);
                if ($valid !== null) {
                    $palettes[$paletteId] = $valid;
                    continue;
                }
            }
            Logger::warning('templates', 'Invalid palette ignored', ['template' => $id, 'palette' => (string) $paletteId]);
        }

        $orientation = strtolower((string) ($config['orientation'] ?? 'portrait')) === 'landscape' ? 'landscape' : 'portrait';
        $pageSize = preg_match('/^(A[3-5]|Letter|Legal)$/i', (string) ($config['page_size'] ?? '')) ? (string) $config['page_size'] : 'A4';

        $margins = null;
        if (is_array($config['margins'] ?? null)) {
            $m = $config['margins'];
            $num = static fn ($v, float $d): float => is_numeric($v) && (float) $v >= 0 && (float) $v <= 60 ? (float) $v : $d;
            $margins = [
                'top' => $num($m['top'] ?? null, 18), 'right' => $num($m['right'] ?? null, 16),
                'bottom' => $num($m['bottom'] ?? null, 20), 'left' => $num($m['left'] ?? null, 16),
                'footer' => $num($m['footer'] ?? null, 9),
            ];
        }
        $logoBoxes = [];
        foreach (is_array($config['logo_boxes'] ?? null) ? $config['logo_boxes'] : [] as $boxName => $box) {
            if (is_string($boxName) && preg_match('/^[a-z]{1,20}$/', $boxName) && is_array($box) && count($box) === 2
                && is_numeric($box[0] ?? null) && is_numeric($box[1] ?? null)
                && (float) $box[0] > 0 && (float) $box[0] <= 300 && (float) $box[1] > 0 && (float) $box[1] <= 300) {
                $logoBoxes[$boxName] = [(float) $box[0], (float) $box[1]];
            }
        }
        $preferred = is_string($config['default_palette'] ?? null) && isset($palettes[$config['default_palette']]) ? $config['default_palette'] : null;

        return new Template(
            $id,
            $folder,
            trim((string) ($config['name'] ?? $id)) ?: $id,
            trim((string) ($config['description'] ?? '')),
            $pageSize,
            $orientation,
            $fields,
            $defaults,
            $palettes,
            array_values(array_filter((array) ($config['source_profiles'] ?? []), 'is_string')),
            $preferred,
            $margins,
            $logoBoxes,
            strtolower((string) ($config['page_height'] ?? 'fixed')) === 'fit' ? 'fit' : 'fixed',
            is_int($config['design_width_px'] ?? null) && $config['design_width_px'] >= 300 && $config['design_width_px'] <= 3000 ? $config['design_width_px'] : 0,
            self::sections($config['sections'] ?? null, $id),
            self::cardGroups($config['card_groups'] ?? null, $id),
        );
    }

    /**
     * "sections": {"glance": ["total_sessions", …]} — only known scalar / list keys are kept.
     *
     * @return array<string, list<string>>
     */
    private static function sections(mixed $config, string $id): array
    {
        $out = [];
        foreach (is_array($config) ? $config : [] as $name => $keys) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,29}$/', $name) || !is_array($keys)) {
                Logger::warning('templates', 'Invalid section ignored', ['template' => $id, 'section' => (string) $name]);
                continue;
            }
            $valid = array_values(array_filter($keys, static fn ($k): bool => is_string($k)
                && (FieldDictionary::isScalar($k) || FieldDictionary::isList($k) || FieldDictionary::isCustom($k))));
            if ($valid !== []) {
                $out[$name] = $valid;
            }
        }

        return $out;
    }

    /**
     * "card_groups": {"glance": {"class": "glance", "per_row": 3, "keys": ["total_sessions", …]},
     *                 "pm": {"stack": true, "class": "pm-card", "last_class": "last", "keys": […]}}
     *
     * @return array<string, array{keys: list<string>, class: string, per_row: int, stack: bool, first_class: string, last_class: string}>
     */
    private static function cardGroups(mixed $config, string $id): array
    {
        $out = [];
        $cls = static fn (mixed $v): string => is_string($v) && preg_match('/^[a-z][a-z0-9-]{0,30}$/', $v) ? $v : '';
        foreach (is_array($config) ? $config : [] as $name => $group) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9]{0,19}$/', $name) || !is_array($group) || $cls($group['class'] ?? null) === '') {
                Logger::warning('templates', 'Invalid card group ignored', ['template' => $id, 'group' => (string) $name]);
                continue;
            }
            $keys = array_values(array_filter((array) ($group['keys'] ?? []), static fn ($k): bool => is_string($k)
                && (FieldDictionary::isScalar($k) || FieldDictionary::isCustom($k))));
            if ($keys === []) {
                continue;
            }
            $perRow = is_int($group['per_row'] ?? null) ? max(1, min(6, $group['per_row'])) : 3;
            $out[$name] = [
                'keys' => $keys,
                'class' => $cls($group['class']),
                'per_row' => $perRow,
                'stack' => ($group['stack'] ?? false) === true,
                'first_class' => $cls($group['first_class'] ?? null),
                'last_class' => $cls($group['last_class'] ?? null),
            ];
        }

        return $out;
    }
}
