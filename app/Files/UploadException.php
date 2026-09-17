<?php

declare(strict_types=1);

namespace App\Files;

/**
 * Upload refused. getMessage() is user-facing; $detail goes to the log only.
 */
final class UploadException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'invalid',
        public readonly string $detail = '',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
