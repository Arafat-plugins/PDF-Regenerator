<?php

declare(strict_types=1);

namespace App\Files;

use App\Core\JsonStore;
use App\Helpers\Text;

/**
 * Per-user index of uploaded source PDFs: storage/metadata/USR_xxx/uploads.json
 *
 * Every write is a locked read-modify-write (JsonStore::update).
 */
final class UploadRepository
{
    public const STATUSES = ['uploaded', 'processing', 'extracted', 'editing', 'generated', 'failed'];

    public function __construct(private UserStorage $storage)
    {
    }

    public static function newId(): string
    {
        return 'src_' . bin2hex(random_bytes(6));
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^src_[a-f0-9]{12}$/', $id);
    }

    public function file(): string
    {
        return $this->storage->metadataFile('uploads');
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

        // same second → the later-added entry first (uploads.json keeps insertion order)
        $order = array_flip(array_column($items, 'id'));
        usort($items, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''))
            ?: $order[$b['id']] <=> $order[$a['id']]);

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(mixed $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $items = JsonStore::read($this->file());
        $item = $items[$id] ?? null;

        return is_array($item) && ($item['id'] ?? null) === $id ? $item : null;
    }

    /**
     * Add a record; the display title is made unique ("Report", "Report (2)" …).
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function add(array $record): array
    {
        $saved = $record;

        JsonStore::update($this->file(), function (array $items) use ($record, &$saved): array {
            $record['title'] = self::uniqueTitle((string) $record['title'], $items);
            $items[(string) $record['id']] = $record;
            $saved = $record;

            return $items;
        });

        return $saved;
    }

    /**
     * Change the DISPLAY title only — the stored file name never changes.
     *
     * @return array<string, mixed>|null
     */
    public function rename(string $id, string $title): ?array
    {
        return $this->update($id, function (array $item, array $items) use ($title, $id): array {
            $others = $items;
            unset($others[$id]);
            $item['title'] = self::uniqueTitle($title, $others);

            return $item;
        });
    }

    public function setStatus(string $id, string $status): ?array
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown status: ' . $status);
        }

        return $this->update($id, static function (array $item) use ($status): array {
            $item['status'] = $status;

            return $item;
        });
    }

    /**
     * @param callable(array<string,mixed>, array<string,mixed>): array<string,mixed> $change
     * @return array<string, mixed>|null
     */
    public function update(string $id, callable $change): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }

        $result = null;
        JsonStore::update($this->file(), static function (array $items) use ($id, $change, &$result): array {
            if (!isset($items[$id]) || !is_array($items[$id])) {
                return $items;
            }
            $item = $change($items[$id], $items);
            $item['id'] = $id; // id and stored file name are immutable
            $item['stored_filename'] = $items[$id]['stored_filename'];
            $item['updated_at'] = date('Y-m-d H:i:s');
            $items[$id] = $item;
            $result = $item;

            return $items;
        });

        return $result;
    }

    /**
     * Remove the metadata entry and return it (the caller deletes the file).
     *
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
     * @param array<string, mixed> $items
     */
    private static function uniqueTitle(string $title, array $items): string
    {
        $title = Text::cleanTitle($title);
        if ($title === '') {
            $title = 'Untitled PDF';
        }

        $taken = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['title'])) {
                $taken[Text::lower((string) $item['title'])] = true;
            }
        }

        if (!isset($taken[Text::lower($title)])) {
            return $title;
        }

        $base = (string) preg_replace('/\s\(\d+\)$/', '', $title);
        for ($n = 2; $n < 1000; $n++) {
            $candidate = Text::limit($base, 110) . ' (' . $n . ')';
            if (!isset($taken[Text::lower($candidate)])) {
                return $candidate;
            }
        }

        return $title . ' ' . bin2hex(random_bytes(2));
    }
}
