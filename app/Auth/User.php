<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Immutable user record loaded from config/users.csv.
 */
final class User
{
    public function __construct(
        public string $id,
        public string $username,
        public string $passwordHash,
        public string $name,
        public string $email,
        public string $status,
    ) {
    }

    /**
     * @param array<string, string> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) ($row['user_id'] ?? ''),
            (string) ($row['username'] ?? ''),
            (string) ($row['password_hash'] ?? ''),
            (string) ($row['name'] ?? ''),
            (string) ($row['email'] ?? ''),
            strtolower((string) ($row['status'] ?? '')),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->username;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->displayName())) ?: [];
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
        }

        return function_exists('mb_strtoupper') ? mb_strtoupper($initials) : strtoupper($initials);
    }

    /**
     * Internal ID, e.g. USR_8f92ac12 — never derived from the username.
     */
    public static function generateId(): string
    {
        return 'USR_' . bin2hex(random_bytes(4));
    }

    public static function isValidId(string $id): bool
    {
        return (bool) preg_match('/^USR_[a-f0-9]{8,32}$/', $id);
    }
}
