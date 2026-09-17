<?php

declare(strict_types=1);

namespace App\Auth;

final class LoginResult
{
    public const OK = 'ok';
    public const EMPTY = 'empty';
    public const INVALID = 'invalid';
    public const LOCKED = 'locked';

    private function __construct(
        public bool $ok,
        public string $code,
        public string $message,
        public ?User $user = null,
        public int $retryAfter = 0,
    ) {
    }

    public static function success(User $user): self
    {
        return new self(true, self::OK, '', $user);
    }

    public static function fail(string $code, string $message, int $retryAfter = 0): self
    {
        return new self(false, $code, $message, null, $retryAfter);
    }
}
