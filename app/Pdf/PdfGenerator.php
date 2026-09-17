<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Core\Config;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * mPDF implementation of PdfGeneratorInterface.
 *
 * Fonts (Part E §17.1c): static Inter TTFs registered as three families —
 * inter (R/B), intersemibold (R), interextrabold (R). DejaVu Sans (bundled with mPDF)
 * covers symbols and is the fallback when Inter is missing.
 */
final class PdfGenerator implements PdfGeneratorInterface
{
    public const INTER_FILES = [
        'inter' => ['R' => 'Inter-Regular.ttf', 'B' => 'Inter-Bold.ttf'],
        'intersemibold' => ['R' => 'Inter-SemiBold.ttf'],
        'interextrabold' => ['R' => 'Inter-ExtraBold.ttf'],
    ];

    private const DEJAVU_FALLBACK = [
        'inter' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf'],
        'intersemibold' => ['R' => 'DejaVuSans-Bold.ttf'],
        'interextrabold' => ['R' => 'DejaVuSans-Bold.ttf'],
    ];

    public static function isAvailable(): bool
    {
        return class_exists(Mpdf::class);
    }

    /** True when every Inter file is present (Settings / system check). */
    public static function interInstalled(?string $fontDir = null): bool
    {
        $fontDir ??= (string) Config::get('font_path');
        foreach (self::INTER_FILES as $styles) {
            foreach ($styles as $file) {
                if (!is_file($fontDir . DIRECTORY_SEPARATOR . $file)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function fontSignature(string $fontDir): string
    {
        $parts = [];
        foreach (self::INTER_FILES as $styles) {
            foreach ($styles as $file) {
                $path = $fontDir . DIRECTORY_SEPARATOR . $file;
                $parts[] = $file . ':' . (int) @filesize($path) . ':' . (int) @filemtime($path);
            }
        }

        return substr(md5(implode('|', $parts)), 0, 10);
    }

    public function generate(string $html, array $options, string $outputFile): void
    {
        if (!self::isAvailable()) {
            throw new GenerationException(GenerationException::FRIENDLY, 'mPDF is not installed (vendor/ missing)');
        }

        $tempDir = (string) Config::get('mpdf_temp_path', Config::storagePath('tmp/mpdf'));
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new GenerationException(GenerationException::FRIENDLY, 'mPDF temp dir not writable');
        }

        $fontDir = (string) Config::get('font_path');
        $inter = self::interInstalled($fontDir);
        // mPDF caches font metrics per family name and only notices a changed file one run later,
        // so every font set gets its own cache folder (keyed by the files' sizes and dates).
        $tempDir .= DIRECTORY_SEPARATOR . ($inter ? 'inter-' . self::fontSignature($fontDir) : 'dejavu');
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            throw new GenerationException(GenerationException::FRIENDLY, 'mPDF font cache dir not writable');
        }
        $defaults = (new ConfigVariables())->getDefaults();
        $fonts = (new FontVariables())->getDefaults();

        $orientation = ($options['orientation'] ?? 'portrait') === 'landscape' ? 'L' : 'P';
        $format = (string) ($options['page_size'] ?? 'A4');

        $tmp = $outputFile . '.part-' . bin2hex(random_bytes(4));

        $settings = [
            'mode' => 'utf-8',
            'format' => $format,
            'orientation' => $orientation,
            'tempDir' => $tempDir,
            'fontDir' => array_merge($defaults['fontDir'], $inter ? [$fontDir] : []),
            'fontdata' => ($inter ? self::INTER_FILES : self::DEJAVU_FALLBACK) + $fonts['fontdata'],
            'default_font' => 'inter',
            'default_font_size' => 10,
            'margin_left' => (float) ($options['margins']['left'] ?? 16),
            'margin_right' => (float) ($options['margins']['right'] ?? 16),
            'margin_top' => (float) ($options['margins']['top'] ?? 18),
            'margin_bottom' => (float) ($options['margins']['bottom'] ?? 20),
            'margin_header' => 0,
            'margin_footer' => (float) ($options['margins']['footer'] ?? 9),
            'useSubstitutions' => true,
            'backupSubsFont' => ['dejavusans'],
            'backupSIPFont' => 'dejavusans',
            'autoScriptToLang' => false,
            'autoLangToFont' => false,
            'shrink_tables_to_fit' => 1,   // never shrink table text
            'img_dpi' => 96,
            'dpi' => 96,
        ];

        $scale = self::designScale($settings, $options);
        if ($scale !== 1.0) {
            [$settings, $html] = self::applyScale($settings, $html, $scale);
        }

        try {
            $mpdf = ($options['page_height'] ?? 'fixed') === 'fit' ? $this->renderFitted($html, $settings, $options) : null;
            if ($mpdf === null) {
                $mpdf = new Mpdf($settings);
                $mpdf->showImageErrors = false;
                $this->describe($mpdf, $options);
                $mpdf->WriteHTML($html);
            }
            $mpdf->Output($tmp, Destination::FILE);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw new GenerationException(GenerationException::FRIENDLY, get_class($e) . ': ' . $e->getMessage(), $e);
        }

        if (!is_file($tmp) || filesize($tmp) < 100 || !@rename($tmp, $outputFile)) {
            @unlink($tmp);
            throw new GenerationException(GenerationException::FRIENDLY, 'mPDF output missing or not movable');
        }
    }

    /** Tall probe page used to measure each fitted section (mm). */
    private const FIT_PROBE_HEIGHT = 2000.0;
    private const FIT_MIN_HEIGHT = 60.0;
    private const FIT_SLACK = 2.0;
    private const FIT_GROW = 6.0;
    private const FIT_TRIES = 4;
    private const FIT_START = 'pdfreg-fit-start-';
    private const FIT_END = 'pdfreg-fit-end-';
    private const FIT_COVER = 'pdfreg-fit-cover';

    /**
     * "page_height": "fit" (template config): every `<div class="page">` section gets a page whose
     * height equals its content — like the sections of the HTML design, without empty space below.
     *
     * 1. Probe: each section is rendered on a very tall page; its end marker gives the content height.
     * 2. Render: each section starts with a `<pagebreak>` carrying its measured sheet size (width,
     *    margins, background and footer still come from the template's named page).
     * 3. Check: the start markers show whether a section still spilled onto a second page; such a
     *    section grows a little and the document is rendered again (a few tries at most).
     * A section that does not fit on the probe page keeps the normal page size.
     *
     * @param array<string, mixed> $settings mPDF constructor settings
     * @param array<string, mixed> $options  generator options
     */
    private function renderFitted(string $html, array $settings, array $options): ?Mpdf
    {
        $parts = preg_split('~<div class="page">~', $html);
        if ($parts === false || count($parts) < 2) {
            return null;
        }
        $pageName = (string) ($options['fit_page'] ?? 'inner');
        if (!preg_match('/^[a-z][a-z0-9-]{0,30}$/i', $pageName)) {
            $pageName = 'inner';
        }
        $width = self::sheetWidth($settings);
        $fullHeight = self::sheetHeight($settings);
        $sections = range(1, count($parts) - 1);

        $build = static function (array $heights, bool $endMarkers) use ($parts, $pageName, $width, $fullHeight): string {
            $out = $parts[0];
            if ($endMarkers) {
                // end of the part before the first section (the cover)
                $out .= '<div class="fit-marker"><a name="' . self::FIT_COVER . '"></a></div>';
            }
            foreach ($heights as $i => $height) {
                $size = sprintf(' sheet-size="%.2fmm %.2fmm"', $width, $height ?? $fullHeight);
                $out .= '<pagebreak page-selector="' . $pageName . '"' . $size . ' />'
                    . '<div class="page-fit"><div class="fit-marker"><a name="' . self::FIT_START . $i . '"></a></div>'
                    . $parts[$i];
                if ($endMarkers) {
                    $marker = '<div class="fit-marker"><a name="' . self::FIT_END . $i . '"></a></div>';
                    $out = isset($parts[$i + 1]) ? $out . $marker : self::appendBeforeBodyEnd($out, $marker);
                }
            }

            return self::fitCss($out);
        };
        $render = function (string $doc, ?float $firstHeight = null) use ($settings, $options, $width): Mpdf {
            if ($firstHeight !== null) {
                $settings['format'] = [$width, $firstHeight];
                $settings['orientation'] = 'P';
            }
            $mpdf = new Mpdf($settings);
            $mpdf->showImageErrors = false;
            $this->describe($mpdf, $options);
            $mpdf->WriteHTML($doc);

            return $mpdf;
        };

        // 1. probe
        $probe = $render($build(array_fill_keys($sections, self::FIT_PROBE_HEIGHT), true));
        $bottom = max((float) $settings['margin_bottom'], (float) $probe->bMargin);
        // the cover page becomes as tall as the cover (its named page has no margins)
        $cover = $probe->internallink[self::FIT_COVER] ?? null;
        $coverHeight = is_array($cover) && (int) $cover['PAGE'] === 1 && (float) $cover['Y'] >= self::FIT_MIN_HEIGHT
            ? min($fullHeight, round((float) $cover['Y'] + 0.3, 2))
            : null;
        $heights = [];
        foreach ($sections as $i) {
            $start = $probe->internallink[self::FIT_START . $i] ?? null;
            $end = $probe->internallink[self::FIT_END . $i] ?? null;
            $fits = is_array($start) && is_array($end) && (int) $start['PAGE'] === (int) $end['PAGE'];
            $heights[$i] = $fits ? round(max(self::FIT_MIN_HEIGHT, (float) $end['Y'] + $bottom + self::FIT_SLACK), 2) : null;
        }
        unset($probe);

        // 2. + 3. render, check, grow
        for ($try = 1; ; $try++) {
            $mpdf = $render($build($heights, false), $coverHeight);
            $grown = false;
            foreach ($sections as $i) {
                if ($heights[$i] === null) {
                    continue;
                }
                $page = (int) ($mpdf->internallink[self::FIT_START . $i]['PAGE'] ?? 0);
                $next = array_key_exists($i + 1, $heights)
                    ? (int) ($mpdf->internallink[self::FIT_START . ($i + 1)]['PAGE'] ?? 0)
                    : count($mpdf->pages) + 1;
                if ($page > 0 && $next - $page > 1) {
                    $heights[$i] = round($heights[$i] + self::FIT_GROW * ($next - $page - 1), 2);
                    $grown = true;
                }
            }
            if (!$grown || $try >= self::FIT_TRIES) {
                return $mpdf;
            }
            unset($mpdf);
        }
    }

    private function describe(Mpdf $mpdf, array $options): void
    {
        // open at actual size (100 %) in PDF readers that honour the document's initial view
        $mpdf->SetDisplayMode('real', 'default');
        $mpdf->SetCreator((string) Config::get('app_name', 'PDF Regenerator'));
        $mpdf->SetTitle((string) ($options['title'] ?? 'Report'));
        if (!empty($options['subject'])) {
            $mpdf->SetSubject((string) $options['subject']);
        }
    }

    private static function fitCss(string $html): string
    {
        $css = '<style>.fit-marker { height: 0; font-size: 1pt; line-height: 1pt; margin: 0; padding: 0; }</style>';

        return str_contains($html, '</head>') ? str_replace('</head>', $css . '</head>', $html) : $css . $html;
    }

    private static function appendBeforeBodyEnd(string $html, string $snippet): string
    {
        $pos = strripos($html, '</body>');

        return $pos === false ? $html . $snippet : substr($html, 0, $pos) . $snippet . substr($html, $pos);
    }

    /**
     * "design_width_px" (template config): the width of the HTML design the template was drawn from,
     * e.g. 980 for a 980 px web page. The PDF page then gets that width (at 96 px per inch) and every
     * length in the template (mm, pt, px, cm, in — fonts, cards, margins, logo, images) grows by the
     * same factor, so the PDF at 100 % looks as big as the HTML page at 100 %. The layout itself does
     * not change. 1.0 = normal page size.
     */
    private static function designScale(array $settings, array $options): float
    {
        $px = (float) ($options['design_width_px'] ?? 0);
        return \App\Templates\DesignScale::factor($px, self::sheetWidth($settings));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function applyScale(array $settings, string $html, float $scale): array
    {
        $width = self::sheetWidth($settings);
        $height = self::sheetHeight($settings);
        $settings['format'] = [round($width * $scale, 2), round($height * $scale, 2)];
        $settings['orientation'] = 'P';
        foreach (['margin_left', 'margin_right', 'margin_top', 'margin_bottom', 'margin_header', 'margin_footer'] as $key) {
            $settings[$key] = round((float) $settings[$key] * $scale, 2);
        }
        $settings['default_font_size'] = round((float) $settings['default_font_size'] * $scale, 2);

        $html = \App\Templates\DesignScale::html($html, $scale);

        return [$settings, $html];
    }

    private static function sheetHeight(array $settings): float
    {
        if (is_array($settings['format'])) {
            return (float) $settings['format'][1];
        }
        $sizes = ['A3' => [297.0, 420.0], 'A4' => [210.0, 297.0], 'A5' => [148.0, 210.0], 'LETTER' => [215.9, 279.4], 'LEGAL' => [215.9, 355.6]];
        [$w, $h] = $sizes[strtoupper((string) $settings['format'])] ?? $sizes['A4'];

        return $settings['orientation'] === 'L' ? $w : $h;
    }

    /** Page width in mm for the configured format/orientation. */
    private static function sheetWidth(array $settings): float
    {
        if (is_array($settings['format'])) {
            return (float) $settings['format'][0];
        }
        $sizes = ['A3' => [297.0, 420.0], 'A4' => [210.0, 297.0], 'A5' => [148.0, 210.0], 'LETTER' => [215.9, 279.4], 'LEGAL' => [215.9, 355.6]];
        [$w, $h] = $sizes[strtoupper((string) $settings['format'])] ?? $sizes['A4'];

        return $settings['orientation'] === 'L' ? $h : $w;
    }
}
