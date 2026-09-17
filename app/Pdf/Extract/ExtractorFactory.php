<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

/**
 * Engine choice (Part D §4): poppler when configured and runnable, smalot otherwise.
 * If the preferred engine fails on a file, the next one is tried.
 */
final class ExtractorFactory
{
    /**
     * @return list<PdfExtractorInterface> in order of preference, available engines only
     */
    public static function engines(): array
    {
        $engines = [];
        foreach ([new PopplerPdfExtractor(), new SmalotPdfExtractor()] as $engine) {
            if ($engine->isAvailable()) {
                $engines[] = $engine;
            }
        }

        return $engines;
    }

    public static function preferredName(): string
    {
        $engines = self::engines();

        return $engines === [] ? 'unavailable' : $engines[0]->name();
    }
}
