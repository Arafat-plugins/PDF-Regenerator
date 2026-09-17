<?php

declare(strict_types=1);

namespace App\Pdf;

/**
 * A PDF that must not be accepted (bomb guard, encryption, unreadable file).
 * getMessage() is safe to show to the user; $detail is for the log only.
 */
final class PdfRejectedException extends \RuntimeException
{
    public const TOO_MANY_PAGES = 'too_many_pages';
    public const TEXT_TOO_LARGE = 'text_too_large';
    public const ENCRYPTED = 'encrypted';
    public const UNREADABLE = 'unreadable';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly string $detail = '',
    ) {
        parent::__construct($message);
    }
}
