<?php

declare(strict_types=1);

namespace App\Editor;

use RuntimeException;

/**
 * A user-safe editor error (validation or stale item). The message is shown as-is.
 *
 *   code  — 'invalid' (422, the value was not saved) or 'stale' (409, the item no longer exists)
 *   field — the input the message belongs to ("value", "label", a list column …)
 */
final class EditorException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'invalid', public readonly string $field = 'value')
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->errorCode === 'stale' ? 409 : 422;
    }
}
