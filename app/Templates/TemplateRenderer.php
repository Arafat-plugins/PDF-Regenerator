<?php

declare(strict_types=1);

namespace App\Templates;

/**
 * Renders a stock template with placeholder data (Part D §6–§7).
 *
 * context "pdf":     asset references become absolute file paths; mPDF-only markup stays active.
 * context "preview": asset references become URLs (callback); preview.css hides mPDF-only markup
 *                    and paints each section like a page.
 *
 * Asset references in template.html / style.css are written as `assets/…` (src/href/url()).
 */
final class TemplateRenderer
{
    public const PDF = 'pdf';
    public const PREVIEW = 'preview';

    private PlaceholderEngine $engine;

    public function __construct(?PlaceholderEngine $engine = null)
    {
        $this->engine = $engine ?? new PlaceholderEngine();
    }

    /**
     * @param array<string, mixed> $view data from TemplateData::build()
     * @param (callable(Template, string): string)|null $assetUrl preview context: relative asset → URL
     * @param string $fontUrl preview context: URL of the Inter web font (mPDF font names are mapped to it)
     * @param array<string, string> $assetOverrides relative asset → file path (pdf) or data: URI (preview), e.g. recolored icons
     * @throws TemplateException
     */
    public function render(Template $template, array $view, string $context = self::PDF, ?callable $assetUrl = null, string $fontUrl = '', array $assetOverrides = []): string
    {
        $html = @file_get_contents($template->htmlFile());
        if ($html === false) {
            throw new TemplateException('Template file could not be read: ' . $template->id);
        }
        $css = is_file($template->cssFile()) ? (string) file_get_contents($template->cssFile()) : '';

        if ($context === self::PREVIEW) {
            $previewCss = $template->dir . DIRECTORY_SEPARATOR . 'preview.css';
            $css .= "\n" . (is_file($previewCss) ? (string) file_get_contents($previewCss) : self::DEFAULT_PREVIEW_CSS);
        }

        $resolver = function (string $relative) use ($template, $context, $assetUrl, $assetOverrides): string {
            if (isset($assetOverrides[$relative])) {
                return $assetOverrides[$relative];
            }
            $path = $template->assetPath($relative);
            if ($path === null) {
                return '';
            }
            if ($context === self::PREVIEW) {
                return $assetUrl !== null ? $assetUrl($template, $relative) : '';
            }

            return str_replace('\\', '/', $path);
        };

        $html = self::resolveAssets($html, $resolver);
        $css = self::resolveAssets($css, $resolver);

        $footer = '';
        if ($context === self::PREVIEW) {
            $css = self::webFonts($css, $fontUrl);
            if (preg_match('~<htmlpagefooter\b[^>]*>(.*?)</htmlpagefooter\s*>~is', $html, $m)) {
                $footer = self::webFonts($m[1], '');
            }
            $html = self::webFonts(self::stripPdfMarkup($html), '');
        }

        $style = $this->engine->render('<style>' . $css . '</style>', $view);
        $body = $this->engine->render($html, $view);
        if ($context === self::PREVIEW && $footer !== '') {
            $body = self::previewFooters($body, $this->engine->render($footer, $view));
        }
        $title = htmlspecialchars((string) ($view['report_title'] ?? $view['title'] ?? $template->name), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $document = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>{$title}</title>\n{$style}\n</head>\n<body>\n{$body}\n</body>\n</html>\n";

        // The PDF is scaled by PdfGenerator; the preview gets the same sizes here.
        return $context === self::PREVIEW ? self::imageUnits(DesignScale::html($document, DesignScale::forTemplate($template))) : $document;
    }

    /**
     * @param callable(string): string $resolver
     */
    public static function resolveAssets(string $source, callable $resolver): string
    {
        // src="assets/x.png", href="assets/…", xlink:href="assets/…"
        $source = (string) preg_replace_callback(
            '~(\s(?:src|href|xlink:href)\s*=\s*)(["\'])assets/([^"\']+)\2~i',
            static fn (array $m): string => $m[1] . $m[2] . htmlspecialchars($resolver($m[3]), ENT_QUOTES, 'UTF-8') . $m[2],
            $source
        );

        // url(assets/x.png) / url('assets/x.png')
        return (string) preg_replace_callback(
            '~url\(\s*(["\']?)assets/([^"\')\s]+)\1\s*\)~i',
            static fn (array $m): string => 'url("' . str_replace(['"', '\\'], ['%22', '/'], $resolver($m[2])) . '")',
            $source
        );
    }

    /**
     * mPDF-only elements have no meaning in a browser (and an unclosed <sethtmlpagefooter …/>
     * would swallow the rest of the page), so the preview drops them.
     */
    public static function stripPdfMarkup(string $html): string
    {
        $html = (string) preg_replace('~<(htmlpage(?:header|footer))\b[^>]*>.*?</\1\s*>~is', '', $html);

        return (string) preg_replace('~<(?:sethtmlpage(?:header|footer)|setpageheader|setpagefooter|pagebreak|tocpagebreak|columnbreak|columns)\b[^>]*/?>~i', '', $html);
    }

    /**
     * mPDF reads width="4.2mm" on <img>; browsers only take pixels there. The preview moves such
     * sizes into the style attribute.
     */
    public static function imageUnits(string $html): string
    {
        return (string) preg_replace_callback('~<img\b[^>]*>~i', static function (array $m): string {
            $tag = $m[0];
            $css = '';
            foreach (['width', 'height'] as $dim) {
                if (preg_match('~\s' . $dim . '\s*=\s*"(\d*\.?\d+(?:mm|pt|cm|in))"~i', $tag, $v)) {
                    $css .= $dim . ': ' . $v[1] . '; ';
                    $tag = str_replace($v[0], '', $tag);
                }
            }
            if ($css === '') {
                return $m[0];
            }
            if (preg_match('~\sstyle\s*=\s*"([^"]*)"~i', $tag, $st)) {
                return str_replace($st[0], ' style="' . $css . $st[1] . '"', $tag);
            }

            return (string) preg_replace('~^<img\b~i', '<img style="' . trim($css) . '"', $tag);
        }, $html);
    }

    /**
     * The PDF repeats the page footer on every inner page; the preview gets a copy inside each
     * `<div class="page">` with the real page number (the cover counts as page 1).
     */
    public static function previewFooters(string $body, string $footer): string
    {
        $pages = substr_count($body, '<div class="page">');
        $total = $pages + substr_count($body, '<div class="cover">');
        $n = $total - $pages;

        return (string) preg_replace_callback('~<div class="page">~', static function () use (&$n, $footer, $total): string {
            $n++;

            return '<div class="page"><div class="pv-footer">' . str_replace(['{PAGENO}', '{nbpg}'], [(string) $n, (string) $total], $footer) . '</div>';
        }, $body);
    }

    /**
     * Browsers do not know mPDF's font family names: map them to the Inter web font
     * (weights instead of separate families) and to generic families.
     */
    public static function webFonts(string $source, string $fontUrl): string
    {
        $source = (string) preg_replace(
            ['/font-family\s*:\s*intersemibold\b/i', '/font-family\s*:\s*interextrabold\b/i', '/font-family\s*:\s*dejavusansmono\b/i', '/font-family\s*:\s*dejavusans\b/i', '/font-family\s*:\s*inter\b(?![-\w])/i'],
            ["font-weight: 600; font-family: 'PdfInter', sans-serif", "font-weight: 800; font-family: 'PdfInter', sans-serif", 'font-family: ui-monospace, Consolas, monospace', "font-family: 'DejaVu Sans', 'Segoe UI Symbol', sans-serif", "font-family: 'PdfInter', sans-serif"],
            $source
        );
        if ($fontUrl !== '' && !preg_match('/["\'()\\\\<>\s]/', $fontUrl)) {
            $source = '@font-face { font-family: "PdfInter"; src: url("' . $fontUrl . '") format("woff2"); font-weight: 100 900; font-style: normal; font-display: block; }' . "\n" . $source;
        }

        return $source;
    }

    private const DEFAULT_PREVIEW_CSS = <<<'CSS'
htmlpagefooter, htmlpageheader { display: none !important; }
body { margin: 0; background: #525659; }
.page { max-width: 210mm; margin: 0 auto 12px; box-sizing: border-box; }
CSS;
}
