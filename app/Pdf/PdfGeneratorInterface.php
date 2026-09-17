<?php

declare(strict_types=1);

namespace App\Pdf;

/**
 * Rendering engine boundary (Part B §5). mPDF is the pinned implementation;
 * Dompdf could be added behind this interface.
 */
interface PdfGeneratorInterface
{
    /**
     * @param array{page_size?: string, orientation?: string, title?: string, subject?: string} $options
     * @throws GenerationException
     */
    public function generate(string $html, array $options, string $outputFile): void;
}
