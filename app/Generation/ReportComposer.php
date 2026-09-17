<?php

declare(strict_types=1);

namespace App\Generation;

use App\Core\Config;
use App\Core\JsonStore;
use App\Extraction\DataNormalizer;
use App\Templates\FieldMapper;
use App\Templates\PaletteManager;
use App\Templates\RingImage;
use App\Templates\Template;
use App\Templates\TemplateData;
use App\Templates\TemplateRenderer;

/**
 * Working data + template → HTML, shared by the PDF generator, the PDF draft and the
 * live preview (Part D §6):
 *
 *   derived values → template defaults → field mapping → theme → system images → render
 *
 * context "pdf":     images are file paths (PageSpeed ring written to storage/tmp and returned in `temp`)
 * context "preview": images are asset.php URLs, the ring is a data: URI
 */
final class ReportComposer
{
    public function __construct(private ?TemplateRenderer $renderer = null)
    {
        $this->renderer ??= new TemplateRenderer();
    }

    /**
     * @param array<string, mixed> $document working copy
     * @param (callable(Template, string): string)|null $assetUrl preview: template asset → URL
     * @param string $fontUrl preview: URL of the Inter web font
     * @param array{path: ?string, url: string}|null $logo the job's logo (Branding::logo); null = template logo
     * @return array{html: string, document: array<string, mixed>, temp: list<string>}
     */
    public function compose(array $document, Template $template, string $context = TemplateRenderer::PDF, ?callable $assetUrl = null, string $fontUrl = '', ?array $logo = null): array
    {
        $document = DataNormalizer::derive($document);
        $document = DataNormalizer::applyDefaults($document, $template->defaults);
        $mapped = FieldMapper::apply($template, $document);

        $theme = PaletteManager::theme(
            $template,
            is_string($document['palette'] ?? null) ? $document['palette'] : null,
            is_array($document['custom_colors'] ?? null) ? $document['custom_colors'] : null,
        );

        $temp = [];
        $system = ['logo_src' => $logo !== null ? self::logoSource($logo, $context) : $this->logo($template, $context, $assetUrl)];
        $logoFile = $logo !== null ? $logo['path'] : (($name = $template->defaultLogo()) !== null ? $template->assetPath($name) : null);
        $system += self::logoSizes($template, $system['logo_src'] !== '' ? $logoFile : null);
        $html = (string) @file_get_contents($template->htmlFile());
        if (str_contains($html, 'pagespeed_ring_src') && RingImage::isSupported()) {
            $ring = self::tempFile('ring', 'png');
            $score = TemplateData::score(self::value($mapped, 'pagespeed_score'));
            $ringColor = TemplateData::pagespeedColor($score);
            if ($ringColor === TemplateData::PAGESPEED_GOOD) {
                $ringColor = $theme['theme_success'] ?? $ringColor;
            }
            RingImage::create($score, $ringColor, $ring, self::fontFile('Inter-ExtraBold.ttf'));
            if ($context === TemplateRenderer::PREVIEW) {
                $system['pagespeed_ring_src'] = RingImage::dataUri($ring);
                @unlink($ring);
            } else {
                $system['pagespeed_ring_src'] = str_replace('\\', '/', $ring);
                $temp[] = $ring;
            }
        }

        $view = TemplateData::build($mapped, $theme, $system);
        $view = array_merge($view, TemplateData::sectionFlags($view, $template->sections), TemplateData::cardFlags($view, $template->cardGroups));

        $overrides = [];
        if (($theme['theme_success'] ?? PaletteManager::SUCCESS) !== PaletteManager::SUCCESS) {
            foreach (self::tintedAssets($template, $theme) as $name => $svg) {
                if ($context === TemplateRenderer::PREVIEW) {
                    $overrides[$name] = 'data:image/svg+xml;base64,' . base64_encode($svg);
                } else {
                    $file = self::tempFile('tint', 'svg');
                    if (@file_put_contents($file, $svg) !== false) {
                        $overrides[$name] = str_replace('\\', '/', $file);
                        $temp[] = $file;
                    }
                }
            }
        }

        try {
            $rendered = $this->renderer->render($template, $view, $context, $assetUrl, $fontUrl, $overrides);
        } catch (\Throwable $e) {
            foreach ($temp as $file) {
                @unlink($file);
            }
            throw $e;
        }

        return ['html' => $rendered, 'document' => $mapped, 'temp' => $temp];
    }

    /**
     * SVG assets drawn in the success color (check marks, OK pills), recolored for the chosen color.
     *
     * @param array<string, string> $theme
     * @return array<string, string> relative asset name => SVG source
     */
    public static function tintedAssets(Template $template, array $theme): array
    {
        $map = [
            PaletteManager::SUCCESS => $theme['theme_success'] ?? PaletteManager::SUCCESS,
            '#72e7bb' => $theme['theme_success_bright'] ?? '#72e7bb',
            '#1a2a27' => $theme['theme_success_fill'] ?? '#1a2a27',
        ];
        $out = [];
        foreach (glob($template->dir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . '*.svg') ?: [] as $file) {
            $name = basename($file);
            if ($name !== 'check.svg' && !str_starts_with($name, 'pill-green')) {
                continue;
            }
            $svg = (string) @file_get_contents($file);
            if ($svg === '' || str_ireplace(array_keys($map), '', $svg) === $svg) {
                continue;
            }
            $out[$name] = str_ireplace(array_keys($map), array_values($map), $svg);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function value(array $document, string $key): string
    {
        $i = DataNormalizer::find($document, $key);
        if ($i === null || !DataNormalizer::isActive($document['fields'][$i])) {
            return '';
        }

        return trim((string) $document['fields'][$i]['value']);
    }

    /**
     * logo_<box>_width / _height: the logo scaled to fit each box of the template (aspect ratio kept).
     *
     * @return array<string, string>
     */
    public static function logoSizes(Template $template, ?string $file): array
    {
        $ratio = 1.0;
        $size = \App\Profiles\LogoStore::dimensions($file);
        if ($size !== null && $size[0] > 0 && $size[1] > 0) {
            $ratio = $size[0] / $size[1];
        }
        $sizes = [];
        foreach ($template->logoBoxes as $name => [$maxW, $maxH]) {
            $width = min($maxW, $maxH * $ratio);
            $height = $width / $ratio;
            $sizes['logo_' . $name . '_width'] = self::mm($width);
            $sizes['logo_' . $name . '_height'] = self::mm($height);
        }

        return $sizes;
    }

    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format(max(0.5, min(999, $value)), 2, '.', ''), '0'), '.') . 'mm';
    }

    /**
     * @param array{path: ?string, url: string} $logo
     */
    private static function logoSource(array $logo, string $context): string
    {
        if ($context === TemplateRenderer::PREVIEW) {
            return (string) $logo['url'];
        }

        return $logo['path'] !== null && is_file($logo['path']) ? str_replace('\\', '/', $logo['path']) : '';
    }

    private function logo(Template $template, string $context, ?callable $assetUrl): string
    {
        foreach (['logo.jpg', 'logo.png', 'logo.svg'] as $name) {
            $path = $template->assetPath($name);
            if ($path === null) {
                continue;
            }
            if ($context === TemplateRenderer::PREVIEW) {
                return $assetUrl !== null ? $assetUrl($template, $name) : '';
            }

            return str_replace('\\', '/', $path);
        }

        return '';
    }

    public static function tempFile(string $prefix, string $extension): string
    {
        $dir = Config::storagePath('tmp');
        JsonStore::ensureDirectory($dir);

        return $dir . DIRECTORY_SEPARATOR . $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    private static function fontFile(string $name): ?string
    {
        $file = rtrim((string) Config::get('font_path'), '/\\') . DIRECTORY_SEPARATOR . $name;

        return is_file($file) ? $file : null;
    }
}
