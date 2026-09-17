<?php

declare(strict_types=1);

namespace App\Templates;

use App\Data\FieldDictionary;

/**
 * The built-in placeholder engine (Part D §7). Supports exactly:
 *
 *   {{ key }}                 escaped substitution (htmlspecialchars, ENT_QUOTES)
 *   {{#list}} … {{/list}}     loop over an array of rows ({{ @index }} = 1-based row number)
 *   {{#scalar}} … {{/scalar}} conditional: rendered once when the value is non-empty and not "0"
 *   {{^key}} … {{/key}}       inverted: rendered when the value is empty / "0" / an empty array
 *
 * Inside a loop a key missing on the row is looked up in the outer data. Missing keys
 * render as an empty string. Inside <style> blocks and style="…" attributes only color
 * keys (theme_*, *_color) are substituted, and only when they are valid #rrggbb values.
 */
final class PlaceholderEngine
{
    private const TAG = '/\{\{\s*([#^\/]?)\s*(@index|[A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    /** @var array<string, array<int, mixed>> */
    private array $cache = [];

    /**
     * @param array<string, mixed> $data
     * @throws TemplateException on unbalanced or invalid sections
     */
    public function render(string $template, array $data): string
    {
        $key = md5($template);
        $tree = $this->cache[$key] ??= $this->parse(self::guardStyles($template));

        return $this->renderNodes($tree, [$data], null);
    }

    /**
     * Remove every placeholder that is not a color key from CSS contexts.
     */
    public static function guardStyles(string $template): string
    {
        $clean = static function (string $css): string {
            return (string) preg_replace_callback(self::TAG, static function (array $m): string {
                return $m[1] === '' && (FieldDictionary::isColorKey($m[2]) || FieldDictionary::isSizeKey($m[2])) ? $m[0] : '';
            }, $css);
        };

        // <style> … </style>
        $template = (string) preg_replace_callback('~(<style\b[^>]*>)(.*?)(</style>)~is', static fn (array $m): string => $m[1] . $clean($m[2]) . $m[3], $template);

        // style="…" and style='…'
        return (string) preg_replace_callback('~(\sstyle\s*=\s*)("[^"]*"|\'[^\']*\')~i', static fn (array $m): string => $m[1] . $clean($m[2]), $template);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parse(string $template): array
    {
        $root = ['children' => []];
        $stack = [&$root];
        $names = [];
        $offset = 0;

        if (preg_match_all(self::TAG, $template, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new TemplateException('Template could not be parsed.');
        }

        foreach ($matches as $m) {
            [$whole, $pos] = $m[0];
            $type = $m[1][0];
            $name = $m[2][0];
            $current = &$stack[count($stack) - 1];

            if ($pos > $offset) {
                $current['children'][] = ['t' => 'text', 'v' => substr($template, $offset, $pos - $offset)];
            }
            $offset = $pos + strlen($whole);

            if ($type === '') {
                $current['children'][] = ['t' => 'var', 'k' => $name];
                unset($current);
                continue;
            }

            if ($name === '@index') {
                throw new TemplateException('@index cannot be used as a section.');
            }

            if ($type === '#' || $type === '^') {
                if (in_array($name, $names, true)) {
                    throw new TemplateException("Section \"$name\" cannot be nested inside itself.");
                }
                $node = ['t' => 'section', 'k' => $name, 'inverted' => $type === '^', 'children' => []];
                $current['children'][] = $node;
                $index = count($current['children']) - 1;
                $stack[] = &$current['children'][$index];
                $names[] = $name;
                unset($current);
                continue;
            }

            // closing tag
            if ($names === [] || end($names) !== $name) {
                throw new TemplateException("Unexpected closing tag {{/$name}}.");
            }
            array_pop($names);
            array_pop($stack);
            unset($current);
        }

        if ($names !== []) {
            throw new TemplateException('Unclosed section {{#' . end($names) . '}}.');
        }
        if ($offset < strlen($template)) {
            $root['children'][] = ['t' => 'text', 'v' => substr($template, $offset)];
        }

        return $root['children'];
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<mixed> $scopes innermost last
     */
    private function renderNodes(array $nodes, array $scopes, ?int $index): string
    {
        $out = '';
        foreach ($nodes as $node) {
            switch ($node['t']) {
                case 'text':
                    $out .= $node['v'];
                    break;

                case 'var':
                    if ($node['k'] === '@index') {
                        $out .= $index === null ? '' : (string) $index;
                        break;
                    }
                    $value = $this->lookup($node['k'], $scopes);
                    if (FieldDictionary::isColorKey($node['k'])) {
                        $out .= is_string($value) && PaletteManager::isHex($value) ? $value : '';
                        break;
                    }
                    if (FieldDictionary::isSizeKey($node['k'])) {
                        $out .= FieldDictionary::isSizeValue($value) ? (string) $value : '';
                        break;
                    }
                    $out .= is_scalar($value) ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
                    break;

                case 'section':
                    $value = $this->lookup($node['k'], $scopes);
                    $empty = self::isEmpty($value);
                    if ($node['inverted']) {
                        if ($empty) {
                            $out .= $this->renderNodes($node['children'], $scopes, $index);
                        }
                        break;
                    }
                    if ($empty) {
                        break;
                    }
                    if (is_array($value) && array_is_list($value)) {
                        $i = 0;
                        foreach ($value as $row) {
                            $i++;
                            $out .= $this->renderNodes($node['children'], [...$scopes, is_array($row) ? $row : ['.' => $row]], $i);
                        }
                    } elseif (is_array($value)) {
                        $out .= $this->renderNodes($node['children'], [...$scopes, $value], $index);
                    } else {
                        $out .= $this->renderNodes($node['children'], $scopes, $index);
                    }
                    break;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $scopes
     */
    private function lookup(string $key, array $scopes): mixed
    {
        for ($i = count($scopes) - 1; $i >= 0; $i--) {
            if (is_array($scopes[$i]) && array_key_exists($key, $scopes[$i])) {
                return $scopes[$i][$key];
            }
        }

        return null;
    }

    public static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === '0' || $value === 0) {
            return true;
        }
        if (is_array($value)) {
            return $value === [];
        }
        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }
}
