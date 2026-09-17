<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

/**
 * One run of text with its position on the page.
 *
 * Coordinates are in PDF points with a TOP-LEFT origin (y grows downwards),
 * whatever engine produced them. y is the baseline of the run.
 */
final class PositionedText
{
    public function __construct(
        public readonly int $page,
        public readonly float $x,
        public readonly float $y,
        public readonly float $xEnd,
        public readonly string $text,
        public readonly float $fontSize,
    ) {
    }

    /**
     * @return array{page:int, x:float, y:float, x_end:float, text:string, font_size:float}
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'x' => round($this->x, 2),
            'y' => round($this->y, 2),
            'x_end' => round($this->xEnd, 2),
            'text' => $this->text,
            'font_size' => round($this->fontSize, 2),
        ];
    }
}
