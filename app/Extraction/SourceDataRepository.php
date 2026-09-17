<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Core\JsonStore;
use App\Editor\DocumentEditor;
use App\Files\UploadRepository;
use App\Files\UserStorage;

/**
 * storage/metadata/USR_xxx/source_src_xxx.json
 *
 *   original — the untouched extraction result (the editor's Reset copies it back)
 *   working  — the user's edited copy (templates render from this)
 */
final class SourceDataRepository
{
    public function __construct(private UserStorage $storage)
    {
    }

    public function file(string $sourceId): string
    {
        if (!UploadRepository::isValidId($sourceId)) {
            throw new \InvalidArgumentException('Invalid source id.');
        }

        return $this->storage->metadataFile('source_' . $sourceId);
    }

    public function exists(string $sourceId): bool
    {
        return UploadRepository::isValidId($sourceId) && is_file($this->file($sourceId));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveExtraction(string $sourceId, array $data): void
    {
        $now = date('Y-m-d H:i:s');
        JsonStore::update($this->file($sourceId), static fn (): array => [
            'source_id' => $sourceId,
            'created_at' => $now,
            'updated_at' => $now,
            'original' => $data,
            'working' => $data,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function working(string $sourceId): ?array
    {
        return $this->part($sourceId, 'working');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function original(string $sourceId): ?array
    {
        return $this->part($sourceId, 'original');
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed>|null the new working copy
     */
    public function updateWorking(string $sourceId, callable $change): ?array
    {
        if (!$this->exists($sourceId)) {
            return null;
        }
        $result = null;
        JsonStore::update($this->file($sourceId), static function (array $doc) use ($change, &$result): array {
            if (!isset($doc['working']) || !is_array($doc['working'])) {
                return $doc;
            }
            $doc['working'] = $change($doc['working']);
            $doc['updated_at'] = date('Y-m-d H:i:s');
            $result = $doc['working'];

            return $doc;
        });

        return $result;
    }

    /**
     * Reset = copy original → working (the template / palette / profile choices stay).
     * Never re-extracts.
     */
    public function reset(string $sourceId): ?array
    {
        if (!$this->exists($sourceId)) {
            return null;
        }
        $result = null;
        JsonStore::update($this->file($sourceId), static function (array $doc) use (&$result): array {
            if (isset($doc['original']) && is_array($doc['original'])) {
                $doc['working'] = DocumentEditor::reset($doc['original'], is_array($doc['working'] ?? null) ? $doc['working'] : []);
                $doc['updated_at'] = date('Y-m-d H:i:s');
                $result = $doc['working'];
            }

            return $doc;
        });

        return $result;
    }

    public function delete(string $sourceId): void
    {
        if ($this->exists($sourceId)) {
            @unlink($this->file($sourceId));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function part(string $sourceId, string $part): ?array
    {
        if (!$this->exists($sourceId)) {
            return null;
        }
        $doc = JsonStore::read($this->file($sourceId));
        if (($doc['source_id'] ?? null) !== $sourceId || !isset($doc[$part]) || !is_array($doc[$part])) {
            return null;
        }

        return $doc[$part];
    }
}
