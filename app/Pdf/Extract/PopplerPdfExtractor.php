<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

use App\Core\Config;
use App\Core\Process;

/**
 * Positioned text via `pdftotext -bbox-layout` (poppler applies the full transform stack).
 * Arguments are passed as an array (no shell), 20 s timeout.
 */
final class PopplerPdfExtractor implements PdfExtractorInterface
{
    private ?string $binary;

    public function __construct(?string $pdftotextPath = null)
    {
        $this->binary = $pdftotextPath ?? (Config::get('pdftotext_path') ?: null);
    }

    public function name(): string
    {
        return 'poppler';
    }

    public function isAvailable(): bool
    {
        return function_exists('proc_open') && Process::isExecutable($this->binary);
    }

    public function extract(string $filePath): ExtractionResult
    {
        if (!$this->isAvailable()) {
            throw new ExtractionException('The PDF text engine is not available.', 'pdftotext not executable');
        }

        $maxBytes = (int) Config::get('max_extracted_text_bytes', 5 * 1024 * 1024);
        // The XHTML wrapper is ~10x larger than the text itself.
        $out = Process::run([(string) $this->binary, '-bbox-layout', '-enc', 'UTF-8', '-q', $filePath, '-'], 20, $maxBytes * 12);

        if ($out['timed_out']) {
            throw new ExtractionException('This PDF took too long to read.', 'pdftotext timeout');
        }
        if ($out['truncated']) {
            throw new ExtractionException('This PDF contains too much text to process.', 'bbox output too large');
        }
        if ($out['exit'] !== 0 || trim($out['stdout']) === '') {
            throw new ExtractionException("We couldn't extract readable data from this PDF.", 'pdftotext exit ' . $out['exit']);
        }

        return $this->parse($out['stdout']);
    }

    /**
     * Parse the XHTML produced by -bbox-layout.
     */
    public function parse(string $xhtml): ExtractionResult
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // LIBXML_NONET: never fetch the DTD; no entity substitution.
        $loaded = $doc->loadXML($xhtml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new ExtractionException("We couldn't extract readable data from this PDF.", 'bbox xhtml not parseable');
        }

        $runs = [];
        $sizes = [];
        $pageNo = 0;

        foreach ($doc->getElementsByTagName('page') as $page) {
            $pageNo++;
            $sizes[$pageNo] = [
                'width' => (float) $page->getAttribute('width'),
                'height' => (float) $page->getAttribute('height'),
            ];

            foreach ($page->getElementsByTagName('word') as $word) {
                $text = $word->textContent;
                if ($text === '') {
                    continue;
                }
                $yMin = (float) $word->getAttribute('yMin');
                $yMax = (float) $word->getAttribute('yMax');
                $height = max(0.1, $yMax - $yMin);
                // bbox height ≈ ascent + descent ≈ 1.2 × font size; baseline ≈ yMax − descent.
                $fontSize = $height / 1.2;
                $runs[] = new PositionedText(
                    $pageNo,
                    (float) $word->getAttribute('xMin'),
                    $yMax - 0.2 * $fontSize,
                    (float) $word->getAttribute('xMax'),
                    $text,
                    $fontSize,
                );
            }
        }

        return new ExtractionResult('poppler', $pageNo, $runs, $sizes, true);
    }
}
