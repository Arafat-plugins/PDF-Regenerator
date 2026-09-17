<?php

declare(strict_types=1);

namespace App\Templates;

/**
 * One stock template folder: template.html + style.css + config.json (+ preview.png, assets/).
 */
final class Template
{
    /**
     * @param list<string> $fields
     * @param array<string, string> $defaults
     * @param array<string, array<string, string>> $palettes
     * @param list<string> $sourceProfiles
     * @param array{top: float, right: float, bottom: float, left: float, footer: float}|null $margins page margins in mm (null = generator default)
     * @param array<string, array{0: float, 1: float}> $logoBoxes name => [max width, max height] in mm
     * @param int $designWidthPx width of the source HTML design in px (0 = none); the PDF page gets this width and every length grows with it
     * @param array<string, list<string>> $sections section name => keys; the template gets section_<name> = "1" when any key has data
     * @param array<string, array{keys: list<string>, class: string, per_row: int, stack: bool, first_class: string, last_class: string}> $cardGroups
     *        boxes that are left out one by one when their value is missing (see TemplateData::cardFlags)
     * @param string $pageHeight 'fixed' (every page has the page_size height) or 'fit' (each `<div class="page">` section gets a page as tall as its content)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $dir,
        public readonly string $name,
        public readonly string $description,
        public readonly string $pageSize,
        public readonly string $orientation,
        public readonly array $fields,
        public readonly array $defaults,
        public readonly array $palettes,
        public readonly array $sourceProfiles,
        public readonly ?string $preferredPalette = null,
        public readonly ?array $margins = null,
        public readonly array $logoBoxes = [],
        public readonly string $pageHeight = 'fixed',
        public readonly int $designWidthPx = 0,
        public readonly array $sections = [],
        public readonly array $cardGroups = [],
    ) {
    }

    /** Scalar (single value) fields the template uses — the ones the field mapping is about. */
    public function scalarFields(): array
    {
        return array_values(array_filter($this->fields, static fn (string $f): bool => \App\Data\FieldDictionary::isScalar($f) || \App\Data\FieldDictionary::isCustom($f)));
    }

    /** List fields the template loops over. */
    public function listFields(): array
    {
        return array_values(array_filter($this->fields, static fn (string $f): bool => \App\Data\FieldDictionary::isList($f)));
    }

    public function htmlFile(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'template.html';
    }

    public function cssFile(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . 'style.css';
    }

    public function previewFile(): ?string
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'preview.png';

        return is_file($file) ? $file : null;
    }

    /** "980 px wide · pages fit their content" / "A4 · Portrait" for the template facts. */
    public static function pageLabel(self $tpl): string
    {
        $size = $tpl->designWidthPx > 0 ? $tpl->designWidthPx . ' px wide' : $tpl->pageSize . ' · ' . ucfirst($tpl->orientation);

        return $size . ($tpl->pageHeight === 'fit' ? ' · pages fit their content' : '');
    }

    /** preview-full.png: every page of a sample report, stacked (optional; shown in the large preview). */
    public function fullPreviewFile(): ?string
    {
        $file = $this->dir . DIRECTORY_SEPARATOR . 'preview-full.png';

        return is_file($file) ? $file : null;
    }

    public function defaultPalette(): ?string
    {
        if ($this->palettes === []) {
            return null;
        }

        if ($this->preferredPalette !== null && array_key_exists($this->preferredPalette, $this->palettes)) {
            return $this->preferredPalette;
        }

        return array_key_exists('red-default', $this->palettes) ? 'red-default' : (string) array_key_first($this->palettes);
    }

    /** The template's own default logo (assets/logo.jpg, logo.png or logo.svg), or null. */
    public function defaultLogo(): ?string
    {
        foreach (['logo.jpg', 'logo.png', 'logo.svg'] as $name) {
            if ($this->assetPath($name) !== null) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Absolute path of a file inside assets/, or null (no traversal, images only).
     */
    public function assetPath(string $relative): ?string
    {
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]{0,80}(?:/[A-Za-z0-9][A-Za-z0-9._-]{0,80}){0,2}$~', $relative) || str_contains($relative, '..')) {
            return null;
        }
        if (!preg_match('/\.(png|jpe?g|svg|gif)$/i', $relative)) {
            return null;
        }
        $base = realpath($this->dir . DIRECTORY_SEPARATOR . 'assets');
        $path = realpath($this->dir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $relative);
        if ($base === false || $path === false || !is_file($path)) {
            return null;
        }

        return str_starts_with($path, rtrim($base, '/\\') . DIRECTORY_SEPARATOR) ? $path : null;
    }
}
