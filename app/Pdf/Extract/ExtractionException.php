<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

/**
 * Extraction failed. getMessage() is user-safe; $detail is for the log.
 */
final class ExtractionException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $detail = '')
    {
        parent::__construct($message);
    }
}
