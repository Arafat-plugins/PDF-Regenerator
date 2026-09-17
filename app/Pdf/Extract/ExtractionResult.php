<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

/**
 * Output of a PdfExtractorInterface: positioned text for every page.
 */
final class ExtractionResult
{
    /**
     * @param list<PositionedText> $runs
     * @param array<int, array{width: float, height: float}> $pageSizes 1-based page number => size
     * @param bool $wordLevel true when runs are whole words (poppler) — any gap between them is a space
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $engine,
        public readonly int $pageCount,
        public readonly array $runs,
        public readonly array $pageSizes,
        public readonly bool $wordLevel,
        public readonly array $warnings = [],
    ) {
    }

    public function textBytes(): int
    {
        $bytes = 0;
        foreach ($this->runs as $run) {
            $bytes += strlen($run->text);
        }

        return $bytes;
    }

    public function hasReadableText(): bool
    {
        $letters = 0;
        foreach ($this->runs as $run) {
            $letters += (int) preg_match_all('/[\p{L}\p{N}]/u', $run->text);
            if ($letters >= 20) {
                return true;
            }
        }

        return false;
    }
}
