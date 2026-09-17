<?php

declare(strict_types=1);

namespace App\Files;

use App\Core\JsonStore;
use App\Core\Logger;
use App\Helpers\Text;

/**
 * Generated PDFs of one user (Part D §10–§11): list, rename (display title only), delete.
 * Every operation looks the id up in the user's own generated.json — a known file name
 * is never enough to reach a file.
 */
final class GeneratedFileManager
{
    public const MAX_TITLE = 160;

    private GeneratedRepository $repo;

    public function __construct(private UserStorage $storage)
    {
        $this->repo = new GeneratedRepository($storage);
    }

    public function repository(): GeneratedRepository
    {
        return $this->repo;
    }

    /**
     * @return list<array<string, mixed>> newest first; entries whose file is gone are marked
     */
    public function all(): array
    {
        $sources = JsonStore::read((new UploadRepository($this->storage))->file());

        return array_map(function (array $item) use ($sources): array {
            $item['missing'] = $this->repo->path($item['id']) === null;
            $item['source_available'] = isset($sources[(string) ($item['source_id'] ?? '')]);

            return $item;
        }, $this->repo->all());
    }

    /**
     * @return array<string, mixed> the renamed entry
     * @throws UploadException
     */
    public function rename(mixed $id, mixed $title): array
    {
        $clean = is_string($title) ? Text::cleanTitle($title, self::MAX_TITLE) : '';
        if ($clean === '') {
            throw new UploadException('Please enter a title.', 'title');
        }
        if ($this->repo->find($id) === null) {
            throw new UploadException('File not found.', 'not_found', '', 404);
        }
        $result = null;
        JsonStore::update($this->repo->file(), static function (array $items) use ($id, $clean, &$result): array {
            if (isset($items[$id]) && is_array($items[$id])) {
                $items[$id]['title'] = $clean;
                $items[$id]['renamed_at'] = date('Y-m-d H:i:s');
                $result = $items[$id];
            }

            return $items;
        });
        if ($result === null) {
            throw new UploadException('File not found.', 'not_found', '', 404);
        }
        Logger::info('files', 'Generated PDF renamed', ['user_id' => $this->storage->userId(), 'id' => $id]);

        return $result;
    }

    /**
     * Delete the PDF and its entry. The stored file name stays unchanged by renames,
     * so the file on disk is always the one the entry points to.
     *
     * @return array<string, mixed>|null the removed entry
     */
    public function delete(mixed $id): ?array
    {
        if (!GeneratedRepository::isValidId($id)) {
            return null;
        }
        $path = $this->repo->path($id);
        $removed = $this->repo->remove((string) $id);
        if ($removed === null) {
            return null;
        }
        if ($path !== null && is_file($path) && !@unlink($path)) {
            Logger::error('files', 'Could not delete generated PDF', ['user_id' => $this->storage->userId(), 'id' => $id]);
        }
        Logger::info('files', 'Generated PDF deleted', ['user_id' => $this->storage->userId(), 'id' => $id]);
        try {
            // the uploaded source PDF goes with its last report
            (new SourceFiles($this->storage, new UploadRepository($this->storage)))->removeWithoutReports($this->repo->all());
        } catch (\Throwable $e) {
            Logger::error('files', 'Source PDF could not be removed after a delete', ['user_id' => $this->storage->userId(), 'error' => $e->getMessage()]);
        }
        try {
            // the website profile follows its reports: gone with the last one
            (new \App\Profiles\BlueprintManager($this->storage))->reportDeleted($removed['blueprint_id'] ?? null, $this->repo->all());
        } catch (\Throwable $e) {
            Logger::error('profiles', 'Profile could not be updated after a delete', ['user_id' => $this->storage->userId(), 'error' => $e->getMessage()]);
        }

        return $removed;
    }
}
