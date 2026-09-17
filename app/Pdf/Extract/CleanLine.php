<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

/**
 * A rebuilt text line: cells joined by TAB in $text.
 */
final class CleanLine
{
    public readonly string $text;

    /**
     * @param list<CleanCell> $cells
     */
    public function __construct(
        public readonly int $page,
        public readonly float $y,
        public readonly array $cells,
    ) {
        $this->text = implode("\t", array_map(static fn (CleanCell $c): string => $c->text, $cells));
    }

    /**
     * Cells joined with " | " (for display / gate checks).
     */
    public function display(): string
    {
        return implode(' | ', array_map(static fn (CleanCell $c): string => $c->text, $this->cells));
    }

    public function plain(): string
    {
        return implode(' ', array_map(static fn (CleanCell $c): string => $c->text, $this->cells));
    }
}
