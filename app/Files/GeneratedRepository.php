<?php

declare(strict_types=1);

namespace App\Files;

use App\Core\JsonStore;
use App\Helpers\Text;

/**
 * Per-user index of generated PDFs: storage/metadata/USR_xxx/generated.json (Part D §10).
 */
final class GeneratedRepository
{
    public function __construct(private UserStorage $storage)
    {
    }

    public static function newId(): string
    {
        return 'gen_' . bin2hex(random_bytes(6));
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^gen_[a-f0-9]{12}$/', $id);
    }

    public function file(): string
    {
        return $this->storage->metadataFile('generated');
    }

    /**
     * Newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $items = array_values(array_filter(
            JsonStore::read($this->file()),
            static fn ($item): bool => is_array($item) && self::isValidId($item['id'] ?? null)
        ));
        // same second → the later-added entry first (generated.json keeps insertion order)
        $order = array_flip(array_column($items, 'id'));
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
            ?: $order[$b['id']] <=> $order[$a['id']]);

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forSource(string $sourceId): array
    {
        return array_values(array_filter($this->all(), static fn (array $item): bool => ($item['source_id'] ?? null) === $sourceId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(mixed $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $item = JsonStore::read($this->file())[$id] ?? null;

        return is_array($item) && ($item['id'] ?? null) === $id ? $item : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function add(array $record): void
    {
        JsonStore::update($this->file(), static function (array $items) use ($record): array {
            $items[(string) $record['id']] = $record;

            return $items;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function remove(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $removed = null;
        JsonStore::update($this->file(), static function (array $items) use ($id, &$removed): array {
            if (isset($items[$id])) {
                $removed = $items[$id];
                unset($items[$id]);
            }

            return $items;
        });

        return is_array($removed) ? $removed : null;
    }

    /**
     * Absolute path of the owner's generated PDF, or null.
     */
    public function path(mixed $id): ?string
    {
        $item = $this->find($id);

        return $item === null ? null : $this->storage->resolveFile($this->storage->generatedDir(), (string) ($item['stored_filename'] ?? ''));
    }

    /**
     * `Plcworld Maintenance Report 14 Sept 2026.pdf` (letters, digits, spaces, dot, dash),
     * with ` 2`, ` 3` … when the name is taken.
     */
    public static function fileName(string $dir, string $title): string
    {
        $base = Text::readableFileName($title, 'Report');
        $name = $base . '.pdf';
        $n = 2;
        while (file_exists($dir . DIRECTORY_SEPARATOR . $name)) {
            $name = $base . ' ' . $n++ . '.pdf';
        }

        return $name;
    }

    /** "14 Sept 2026" for an ISO date (today when null). */
    public static function fileDate(?string $iso, ?int $now = null): string
    {
        $time = $iso !== null ? strtotime($iso . ' 12:00') : false;
        $time = $time === false ? ($now ?? time()) : $time;
        $month = date('n', $time) === '9' ? 'Sept' : date('M', $time);

        return date('j', $time) . ' ' . $month . ' ' . date('Y', $time);
    }
}
