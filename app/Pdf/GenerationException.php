<?php

declare(strict_types=1);

namespace App\Pdf;

/**
 * Generation failed. getMessage() is user-safe; $detail goes to the log.
 */
final class GenerationException extends \RuntimeException
{
    public const FRIENDLY = "The PDF could not be generated.\nPlease try again.";

    /**
     * @param string $errorCode 'generation' (failed), 'job' (unknown job) or 'order' (step not due)
     */
    public function __construct(string $message = self::FRIENDLY, public readonly string $detail = '', ?\Throwable $previous = null, public readonly string $errorCode = 'generation')
    {
        parent::__construct($message, 0, $previous);
    }
}
