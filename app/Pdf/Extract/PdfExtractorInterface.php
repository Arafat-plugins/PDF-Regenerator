<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

/**
 * Extension point (Part D §4): poppler and smalot today, OCR later.
 */
interface PdfExtractorInterface
{
    public function name(): string;

    public function isAvailable(): bool;

    /**
     * @throws ExtractionException when the file cannot be read
     */
    public function extract(string $filePath): ExtractionResult;
}
