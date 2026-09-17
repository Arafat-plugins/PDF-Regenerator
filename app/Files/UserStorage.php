<?php

declare(strict_types=1);

namespace App\Files;

use App\Auth\User;
use App\Core\Config;
use App\Core\JsonStore;
use InvalidArgumentException;

/**
 * Per-user storage layout (Part C §15). Paths are built ONLY from the validated
 * internal user id — never from the username or any request value.
 *
 *   storage/uploads/USR_xxx/
 *   storage/generated/USR_xxx/
 *   storage/metadata/USR_xxx/{uploads,generated,blueprints}.json
 */
final class UserStorage
{
    private string $userId;

    public function __construct(string $userId)
    {
        if (!User::isValidId($userId)) {
            throw new InvalidArgumentException('Invalid user id.');
        }
        $this->userId = $userId;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function uploadsDir(): string
    {
        return Config::storagePath('uploads/' . $this->userId);
    }

    public function generatedDir(): string
    {
        return Config::storagePath('generated/' . $this->userId);
    }

    public function metadataDir(): string
    {
        return Config::storagePath('metadata/' . $this->userId);
    }

    public function metadataFile(string $name): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid metadata name.');
        }

        return $this->metadataDir() . DIRECTORY_SEPARATOR . $name . '.json';
    }

    /**
     * Absolute path of a stored file inside $dir, or null.
     *
     * Only plain generated file names are accepted, and the resolved real path
     * must stay inside the user's directory (defence against traversal/symlinks).
     */
    public function resolveFile(string $dir, string $fileName): ?string
    {
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9._-]| (?! )){0,180}\.pdf$/', $fileName) || str_contains($fileName, '..')) {
            return null;
        }

        $base = realpath($dir);
        $path = realpath($dir . DIRECTORY_SEPARATOR . $fileName);
        if ($base === false || $path === false || !is_file($path)) {
            return null;
        }

        $base = rtrim($base, '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? $path : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function index(string $name): array
    {
        return JsonStore::read($this->metadataFile($name));
    }

    public function count(string $name): int
    {
        return count($this->index($name));
    }
}
