<?php

declare(strict_types=1);

namespace App\Templates;

/**
 * "design_width_px" (template config): the report is drawn for an HTML design of that width
 * (e.g. 980 px). Every length of the template grows by the same factor so the page is exactly
 * that wide at 96 px per inch — in the generated PDF and in the browser preview alike.
 */
final class DesignScale
{
    private const SIZES = ['A3' => [297.0, 420.0], 'A4' => [210.0, 297.0], 'A5' => [148.0, 210.0], 'LETTER' => [215.9, 279.4], 'LEGAL' => [215.9, 355.6]];

    /** Page size in mm for a page_size / orientation pair. */
    public static function pageSize(string $pageSize, string $orientation): array
    {
        [$w, $h] = self::SIZES[strtoupper($pageSize)] ?? self::SIZES['A4'];

        return in_array(strtolower($orientation), ['landscape', 'l'], true) ? [$h, $w] : [$w, $h];
    }

    /** Scale factor for a design width on a page of $pageWidthMm (1.0 = none). */
    public static function factor(int|float $designWidthPx, float $pageWidthMm): float
    {
        if ($designWidthPx < 300 || $designWidthPx > 3000 || $pageWidthMm <= 0) {
            return 1.0;
        }

        return round($designWidthPx / ($pageWidthMm / 25.4 * 96), 4);
    }

    public static function forTemplate(Template $template): float
    {
        return self::factor($template->designWidthPx, self::pageSize($template->pageSize, $template->orientation)[0]);
    }

    /**
     * Multiply every mm / pt / px / cm / in value inside <style> blocks and inside style, width,
     * height and sheet-size attributes. Unitless numbers (SVG coordinates) are left alone.
     */
    public static function html(string $html, float $factor): string
    {
        if ($factor === 1.0) {
            return $html;
        }
        $lengths = static fn (string $text): string => (string) preg_replace_callback(
            '/(?<![\w#.\-])(\d*\.?\d+)(mm|pt|px|cm|in)\b/i',
            static fn (array $m): string => rtrim(rtrim(sprintf('%.3F', (float) $m[1] * $factor), '0'), '.') . $m[2],
            $text
        );
        $html = (string) preg_replace_callback('~(<style\b[^>]*>)(.*?)(</style>)~is', static fn (array $m): string => $m[1] . $lengths($m[2]) . $m[3], $html);

        return (string) preg_replace_callback(
            '~\b(style|width|height|sheet-size)\s*=\s*"([^"]*)"~i',
            static fn (array $m): string => $m[1] . '="' . $lengths($m[2]) . '"',
            $html
        );
    }
}
