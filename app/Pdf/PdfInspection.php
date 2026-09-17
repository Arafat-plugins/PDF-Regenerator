<?php

declare(strict_types=1);

namespace App\Pdf;

/**
 * Result of the upload-time PDF inspection.
 */
final class PdfInspection
{
    public function __construct(
        public readonly int $pages,
        public readonly int $textBytes,
        public readonly bool $hasText,
        public readonly string $engine,
    ) {
    }
}
