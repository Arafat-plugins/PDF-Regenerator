<?php

declare(strict_types=1);

namespace App\Files;

use App\Core\Logger;

/**
 * Owner-scoped operations on uploaded source PDFs (rename, delete, resolve path).
 *
 * Every lookup goes through the CURRENT user's uploads.json, so another user's
 * id — even when known — resolves to nothing.
 */
final class SourceFiles
{
    public function __construct(private UserStorage $storage, private UploadRepository $uploads)
    {
    }

    public function repository(): UploadRepository
    {
        return $this->uploads;
    }

    /**
     * Absolute path of the owner's stored PDF, or null.
     */
    public function path(mixed $id): ?string
    {
        $item = $this->uploads->find($id);
        if ($item === null) {
            return null;
        }

        return $this->storage->resolveFile($this->storage->uploadsDir(), (string) ($item['stored_filename'] ?? ''));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rename(mixed $id, string $title): ?array
    {
        if (!UploadRepository::isValidId($id)) {
            return null;
        }

        return $this->uploads->rename((string) $id, $title);
    }

    /**
     * Uploads marked "Generated" whose generated reports were all deleted are removed too
     * (the report was the reason to keep them).
     *
     * @param list<array<string, mixed>> $generated remaining generated entries
     * @return int removed uploads
     */
    public function removeWithoutReports(array $generated): int
    {
        $used = [];
        foreach ($generated as $g) {
            $used[(string) ($g['source_id'] ?? '')] = true;
        }
        $removed = 0;
        foreach ($this->uploads->all() as $item) {
            if (($item['status'] ?? '') === 'generated' && !isset($used[(string) $item['id']]) && $this->delete($item['id']) !== null) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Delete the PDF, its metadata entry and its extracted working data.
     *
     * @return array<string, mixed>|null the removed record
     */
    public function delete(mixed $id): ?array
    {
        if (!UploadRepository::isValidId($id)) {
            return null;
        }

        $path = $this->path($id);
        $record = $this->uploads->remove((string) $id);
        if ($record === null) {
            return null;
        }

        if ($path !== null && is_file($path) && !@unlink($path)) {
            Logger::error('files', 'Could not delete source PDF', ['user_id' => $this->storage->userId(), 'id' => $id]);
        }

        $working = $this->storage->metadataFile('source_' . $id);
        if (is_file($working) && !@unlink($working)) {
            Logger::error('files', 'Could not delete source working data', ['user_id' => $this->storage->userId(), 'id' => $id]);
        }

        $draft = \App\Generation\GenerationService::draftPath($this->storage, (string) $id);
        if (is_file($draft)) {
            @unlink($draft);
        }
        (new \App\Profiles\LogoStore($this->storage))->deleteDraft((string) $id);

        Logger::info('files', 'Source PDF deleted', ['user_id' => $this->storage->userId(), 'id' => $id]);

        return $record;
    }
}
