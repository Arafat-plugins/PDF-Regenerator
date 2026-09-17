<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * CSV-backed user store (Part C §14).
 *
 * Format: user_id,username,password_hash,name,email,status
 *
 * - read-only requests only read (shared lock), never rewrite the file
 * - every modification happens under an exclusive lock
 * - passwords are stored as password_hash() values only
 */
final class CsvUserRepository
{
    public const HEADER = ['user_id', 'username', 'password_hash', 'name', 'email', 'status'];

    public function __construct(private string $file)
    {
    }

    public function file(): string
    {
        return $this->file;
    }

    public function findByUsername(string $username): ?User
    {
        $needle = self::normalize($username);
        if ($needle === '') {
            return null;
        }

        foreach ($this->all() as $user) {
            if (self::normalize($user->username) === $needle) {
                return $user;
            }
        }

        return null;
    }

    public function getUser(string $userId): ?User
    {
        if (!User::isValidId($userId)) {
            return null;
        }

        foreach ($this->all() as $user) {
            if (hash_equals($user->id, $userId)) {
                return $user;
            }
        }

        return null;
    }

    public function verifyPassword(User $user, string $password): bool
    {
        return $user->passwordHash !== '' && password_verify($password, $user->passwordHash);
    }

    /**
     * Replace a user's password hash (locked read-modify-write).
     */
    public function updatePassword(string $userId, string $newHash): bool
    {
        if (password_get_info($newHash)['algo'] === null || password_get_info($newHash)['algo'] === 0) {
            throw new InvalidArgumentException('A password hash is required.');
        }

        $updated = false;

        $this->modify(function (array $rows) use ($userId, $newHash, &$updated): array {
            foreach ($rows as $i => $row) {
                if (($row['user_id'] ?? '') === $userId) {
                    $rows[$i]['password_hash'] = $newHash;
                    $updated = true;
                }
            }

            return $rows;
        });

        return $updated;
    }

    /**
     * Append a new user (used by bin/create-user.php). Username must be unique.
     */
    public function create(string $username, string $passwordHash, string $name, string $email, string $status = 'active'): User
    {
        $username = trim($username);
        if (!self::isValidUsername($username)) {
            throw new InvalidArgumentException('Username must be 3–32 characters: letters, numbers, dot, dash or underscore.');
        }

        $user = null;

        $this->modify(function (array $rows) use ($username, $passwordHash, $name, $email, $status, &$user): array {
            foreach ($rows as $row) {
                if (self::normalize((string) ($row['username'] ?? '')) === self::normalize($username)) {
                    throw new InvalidArgumentException('That username already exists.');
                }
            }

            $ids = array_column($rows, 'user_id');
            do {
                $id = User::generateId();
            } while (in_array($id, $ids, true));

            $row = [
                'user_id' => $id,
                'username' => $username,
                'password_hash' => $passwordHash,
                'name' => self::cleanCell($name),
                'email' => self::cleanCell($email),
                'status' => $status === 'active' ? 'active' : 'inactive',
            ];
            $rows[] = $row;
            $user = User::fromRow($row);

            return $rows;
        });

        return $user;
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $handle = @fopen($this->file, 'rb');
        if ($handle === false) {
            Logger::error('auth', 'users.csv could not be opened');

            return [];
        }

        try {
            flock($handle, LOCK_SH);
            $rows = $this->readRows($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        $users = [];
        foreach ($rows as $row) {
            if (User::isValidId((string) ($row['user_id'] ?? ''))) {
                $users[] = User::fromRow($row);
            }
        }

        return $users;
    }

    public static function isValidUsername(string $username): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username);
    }

    /**
     * @param callable(list<array<string,string>>): list<array<string,string>> $callback
     */
    private function modify(callable $callback): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            throw new RuntimeException('The users.csv directory does not exist.');
        }

        $handle = @fopen($this->file, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('users.csv is not writable by PHP.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock users.csv.');
            }

            $rows = $callback($this->readRows($handle));

            $buffer = fopen('php://temp', 'w+b');
            fputcsv($buffer, self::HEADER, ',', '"', '');
            foreach ($rows as $row) {
                $line = [];
                foreach (self::HEADER as $column) {
                    $line[] = (string) ($row[$column] ?? '');
                }
                fputcsv($buffer, $line, ',', '"', '');
            }
            rewind($buffer);
            $csv = (string) stream_get_contents($buffer);
            fclose($buffer);

            ftruncate($handle, 0);
            rewind($handle);
            if (fwrite($handle, $csv) === false) {
                throw new RuntimeException('Unable to write users.csv.');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return list<array<string,string>>
     */
    private function readRows($handle): array
    {
        rewind($handle);
        $rows = [];
        $header = null;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($cells === [null] || $cells === []) {
                continue; // blank line
            }

            $cells = array_map(static fn ($c): string => trim((string) $c), $cells);

            if ($header === null) {
                $cells[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]); // strip UTF-8 BOM
                $header = $cells;
                if ($header !== self::HEADER) {
                    // Tolerate files without a header line: treat the first row as data.
                    if (User::isValidId($cells[0])) {
                        $header = self::HEADER;
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            $row = [];
            foreach (self::HEADER as $i => $column) {
                $row[$column] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function normalize(string $username): string
    {
        $username = trim($username);

        return function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    private static function cleanCell(string $value): string
    {
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));

        // Neutralise spreadsheet formula injection if the CSV is ever opened in Excel.
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            $value = "'" . $value;
        }

        return $value;
    }
}
