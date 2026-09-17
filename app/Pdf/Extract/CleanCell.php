<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

final class CleanCell
{
    public function __construct(
        public readonly string $text,
        public readonly float $x,
        public readonly float $xEnd,
        public readonly float $fontSize,
    ) {
    }

    public function overlaps(CleanCell $other, float $slack = 2.0): bool
    {
        return $this->x <= $other->xEnd + $slack && $other->x <= $this->xEnd + $slack;
    }
}
