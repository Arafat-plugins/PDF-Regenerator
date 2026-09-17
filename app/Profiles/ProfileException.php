<?php

declare(strict_types=1);

namespace App\Profiles;

/**
 * A user-safe website-profile or branding error. getMessage() is shown as-is.
 */
final class ProfileException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'invalid', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
