<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

/**
 * Turns positioned text into clean lines and cells (Part D §4 "Text Cleaning").
 *
 *  1. line rebuild — group runs by page and baseline (±2 pt), order by x
 *  2. cells       — new cell when the horizontal gap is ≥ 24 pt; a space when the
 *                   gap is ≥ 0.2 × font size (any gap for word-level engines)
 *  3. word-join repair — whitelisted regex only (`updatesto` → `updates to`)
 *  4. normalise   — NFC, collapsed spaces, unified hyphens, trimmed,
 *                   line-end de-hyphenation, page-number footers dropped
 */
final class TextCleaner
{
    public const LINE_TOLERANCE = 2.0;
    public const CELL_GAP = 24.0;
    // Spec suggests 0.2 em; measured word gaps in the reference PDF go down to 0.199 em (kerned
    // ". T"), while glyph-level splits stay below 0.02 em — so 0.15 em is the safe cut.
    public const SPACE_RATIO = 0.15;
    public const WORD_JOIN_PATTERN = '/\b(updates|scans|backups|monitoring|testing|conflicts|content|review)(to|for|and|of|the|if|found)\b/u';

    /**
     * @return list<CleanLine>
     */
    public function lines(ExtractionResult $result): array
    {
        $byPage = [];
        foreach ($result->runs as $run) {
            $byPage[$run->page][] = $run;
        }
        ksort($byPage);

        $lines = [];
        foreach ($byPage as $page => $runs) {
            $pageLines = $this->pageLines($runs, $result->wordLevel, (int) $page, $result->pageSizes[$page]['height'] ?? 842.0);
            foreach ($this->dehyphenate($pageLines) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<PositionedText> $runs
     * @return list<CleanLine>
     */
    private function pageLines(array $runs, bool $wordLevel, int $page, float $pageHeight): array
    {
        usort($runs, static fn (PositionedText $a, PositionedText $b): int => $a->y <=> $b->y ?: $a->x <=> $b->x);

        // Group into rows by baseline.
        $rows = [];
        foreach ($runs as $run) {
            $placed = false;
            // Compare with the most recent rows first (they are closest in y).
            for ($i = count($rows) - 1; $i >= 0 && $i >= count($rows) - 3; $i--) {
                if (abs($rows[$i]['y'] - $run->y) <= self::LINE_TOLERANCE) {
                    $rows[$i]['runs'][] = $run;
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                $rows[] = ['y' => $run->y, 'runs' => [$run]];
            }
        }

        $lines = [];
        foreach ($rows as $row) {
            usort($row['runs'], static fn (PositionedText $a, PositionedText $b): int => $a->x <=> $b->x);

            $cells = [];
            $current = null;
            $prev = null;
            foreach ($row['runs'] as $run) {
                if ($prev === null) {
                    $current = ['text' => $run->text, 'x' => $run->x, 'x_end' => $run->xEnd, 'size' => $run->fontSize];
                    $prev = $run;
                    continue;
                }

                $gap = $run->x - $prev->xEnd;
                $size = max($prev->fontSize, $run->fontSize);

                if ($gap >= self::CELL_GAP) {
                    $cells[] = $current;
                    $current = ['text' => $run->text, 'x' => $run->x, 'x_end' => $run->xEnd, 'size' => $run->fontSize];
                } else {
                    $needsSpace = $wordLevel ? $gap > 0.01 : $gap >= self::SPACE_RATIO * $size;
                    $joiner = ($needsSpace && !str_ends_with($current['text'], ' ') && !str_starts_with($run->text, ' ')) ? ' ' : '';
                    $current['text'] .= $joiner . $run->text;
                    $current['x_end'] = max($current['x_end'], $run->xEnd);
                    $current['size'] = max($current['size'], $run->fontSize);
                }
                $prev = $run;
            }
            if ($current !== null) {
                $cells[] = $current;
            }

            $clean = [];
            foreach ($cells as $cell) {
                $text = self::normalize($cell['text']);
                if ($text !== '') {
                    $clean[] = new CleanCell($text, $cell['x'], $cell['x_end'], $cell['size']);
                }
            }
            if ($clean === []) {
                continue;
            }

            $line = new CleanLine($page, $row['y'], $clean);

            // Drop page-number footers ("3", "Page 3", "3 / 11", "Page 3 of 11") at the bottom of the page.
            if ($row['y'] > $pageHeight * 0.92 && preg_match('/^(page\s*)?\d{1,4}(\s*(\/|of)\s*\d{1,4})?$/iu', $line->text)) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Join "exam-\nple" when a single-cell line ends with letter + hyphen and the
     * next line of the same page starts with a lowercase letter.
     *
     * @param list<CleanLine> $lines
     * @return list<CleanLine>
     */
    private function dehyphenate(array $lines): array
    {
        $out = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $next = $lines[$i + 1] ?? null;
            if (
                $next !== null
                && count($line->cells) === 1 && count($next->cells) === 1
                && preg_match('/\p{Ll}-$/u', $line->cells[0]->text)
                && preg_match('/^\p{Ll}/u', $next->cells[0]->text)
                && abs($next->y - $line->y) < 3 * $line->cells[0]->fontSize
            ) {
                $merged = substr($line->cells[0]->text, 0, -1) . $next->cells[0]->text;
                $out[] = new CleanLine($line->page, $line->y, [new CleanCell($merged, $line->cells[0]->x, max($line->cells[0]->xEnd, $next->cells[0]->xEnd), $line->cells[0]->fontSize)]);
                $i++;
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    public static function normalize(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        // Unify hyphen-like characters (keep en/em dashes, they carry meaning in prose).
        $text = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2212}", "\u{00AD}"], ['-', '-', '-', '-', ''], $text);
        // Non-breaking and other exotic spaces → normal space.
        $text = (string) preg_replace('/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}\t]/u', ' ', $text);
        $text = (string) preg_replace('/[\x{200B}\x{FEFF}]/u', '', $text);
        $text = (string) preg_replace('/ {2,}/', ' ', $text);

        return self::repairWordJoins(trim($text));
    }

    public static function repairWordJoins(string $text): string
    {
        return (string) preg_replace(self::WORD_JOIN_PATTERN, '$1 $2', $text);
    }
}
