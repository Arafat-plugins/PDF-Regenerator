<?php

declare(strict_types=1);

namespace App\Files;

use App\Auth\User;
use App\Core\Config;
use App\Core\JsonStore;
use App\Core\Logger;
use App\Extraction\SourceDataRepository;
use App\Generation\GenerationService;
use App\Profiles\LogoStore;

/**
 * Storage maintenance (Part D §15) — used by bin/cleanup-storage.php.
 *
 *   uploaded source PDFs      older than retention_uploads_days   (+ working data, draft logo, draft PDF)
 *   generated PDFs            older than retention_generated_days (+ generated.json entry)
 *   temporary files           older than retention_temp_days      (progress jobs, storage/tmp, stale
 *                                                                  sessions, rotated logs, drafts/ logos)
 *   index entries whose file is gone, and stored files no index knows about
 *
 * NEVER touched: storage/metadata/USR_xxx/blueprints.json and storage/metadata/USR_xxx/assets/
 * (website profiles and their logos), uploads.json / generated.json themselves, login-attempts.json,
 * mPDF's font cache.
 */
final class StorageCleaner
{
    /** @var list<array{action: string, type: string, path: string, reason: string, bytes: int}> */
    private array $report = [];

    private int $now;

    public function __construct(private bool $dryRun = false, ?int $now = null)
    {
        $this->now = $now ?? time();
    }

    /**
     * @return list<array{action: string, type: string, path: string, reason: string, bytes: int}>
     */
    public function run(): array
    {
        $this->report = [];
        $uploadsDays = max(1, (int) Config::get('retention_uploads_days', 30));
        $generatedDays = max(1, (int) Config::get('retention_generated_days', 90));
        $tempDays = max(1, (int) Config::get('retention_temp_days', 7));

        foreach ($this->userIds() as $userId) {
            $storage = new UserStorage($userId);
            $this->cleanUploads($storage, $uploadsDays, $tempDays);
            $this->cleanGenerated($storage, $generatedDays, $tempDays);
            $this->cleanDrafts($storage, $tempDays);
        }
        $this->cleanTemp($tempDays);

        return $this->report;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * @return list<string>
     */
    private function userIds(): array
    {
        $ids = [];
        foreach (['uploads', 'generated', 'metadata'] as $area) {
            foreach ((array) @scandir(Config::storagePath($area)) as $name) {
                if (is_string($name) && User::isValidId($name) && is_dir(Config::storagePath($area . '/' . $name))) {
                    $ids[$name] = true;
                }
            }
        }
        ksort($ids);

        return array_keys($ids);
    }

    // ------------------------------------------------------------ uploads
    private function cleanUploads(UserStorage $storage, int $days, int $tempDays): void
    {
        $repo = new UploadRepository($storage);
        $data = new SourceDataRepository($storage);
        $logos = new LogoStore($storage);
        $cutoff = $this->now - $days * 86400;
        $index = JsonStore::read($repo->file());
        $remove = [];

        foreach ($index as $id => $item) {
            if (!is_array($item) || !UploadRepository::isValidId($id)) {
                $remove[$id] = 'invalid entry';
                continue;
            }
            $path = $storage->resolveFile($storage->uploadsDir(), (string) ($item['stored_filename'] ?? ''));
            $created = $this->time($item['created_at'] ?? null, $path);
            if ($path === null) {
                $remove[$id] = 'file missing';
            } elseif ($created < $cutoff) {
                $remove[$id] = 'older than ' . $days . ' days';
                $this->delete('upload', $path, $remove[$id]);
            }
        }

        foreach ($remove as $id => $reason) {
            $this->note('index', $storage->userId() . '/uploads.json#' . $id, $reason);
            if (UploadRepository::isValidId($id)) {
                if ($data->exists($id)) {
                    $this->delete('working data', $data->file($id), 'source removed');
                }
                $draft = GenerationService::draftPath($storage, $id);
                if (is_file($draft)) {
                    $this->delete('draft pdf', $draft, 'source removed');
                }
                $dir = $logos->draftDir($id);
                if (is_dir($dir)) {
                    $this->deleteTree('draft logo', $dir, 'source removed');
                }
            }
        }
        if ($remove !== [] && !$this->dryRun) {
            JsonStore::update($repo->file(), static function (array $items) use ($remove): array {
                foreach (array_keys($remove) as $id) {
                    unset($items[$id]);
                }

                return $items;
            });
        }

        // working data without an upload entry
        $known = array_diff_key($index, $remove);
        foreach ((array) glob($storage->metadataDir() . DIRECTORY_SEPARATOR . 'source_src_*.json') as $file) {
            $id = substr(basename((string) $file, '.json'), 7);
            if (!isset($known[$id]) && !isset($remove[$id])) {
                $this->delete('working data', (string) $file, 'no upload entry');
            }
        }

        // stored PDFs no entry points to (e.g. an interrupted upload)
        $names = [];
        foreach ($index as $item) {
            $names[(string) (is_array($item) ? ($item['stored_filename'] ?? '') : '')] = true;
        }
        $this->orphanFiles($storage->uploadsDir(), $names, $tempDays, 'upload');
    }

    // ---------------------------------------------------------- generated
    private function cleanGenerated(UserStorage $storage, int $days, int $tempDays): void
    {
        $repo = new GeneratedRepository($storage);
        $cutoff = $this->now - $days * 86400;
        $index = JsonStore::read($repo->file());
        $remove = [];

        foreach ($index as $id => $item) {
            if (!is_array($item) || !GeneratedRepository::isValidId($id)) {
                $remove[$id] = 'invalid entry';
                continue;
            }
            $path = $storage->resolveFile($storage->generatedDir(), (string) ($item['stored_filename'] ?? ''));
            $created = $this->time($item['created_at'] ?? null, $path);
            if ($path === null) {
                $remove[$id] = 'file missing';
            } elseif ($created < $cutoff) {
                $remove[$id] = 'older than ' . $days . ' days';
                $this->delete('generated', $path, $remove[$id]);
            }
        }
        foreach ($remove as $id => $reason) {
            $this->note('index', $storage->userId() . '/generated.json#' . $id, $reason);
        }
        if ($remove !== [] && !$this->dryRun) {
            JsonStore::update($repo->file(), static function (array $items) use ($remove): array {
                foreach (array_keys($remove) as $id) {
                    unset($items[$id]);
                }

                return $items;
            });
        }

        $names = [];
        foreach ($index as $item) {
            $names[(string) (is_array($item) ? ($item['stored_filename'] ?? '') : '')] = true;
        }
        $this->orphanFiles($storage->generatedDir(), $names, $tempDays, 'generated');
    }

    // ------------------------------------------------------------- drafts
    private function cleanDrafts(UserStorage $storage, int $days): void
    {
        $root = $storage->metadataDir() . DIRECTORY_SEPARATOR . 'drafts';
        $cutoff = $this->now - $days * 86400;
        $uploads = JsonStore::read((new UploadRepository($storage))->file());
        foreach ((array) @scandir($root) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..') {
                continue;
            }
            $dir = $root . DIRECTORY_SEPARATOR . $name;
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            $newest = 0;
            foreach ((array) @scandir($dir) as $file) {
                if (is_string($file) && is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                    $newest = max($newest, (int) filemtime($dir . DIRECTORY_SEPARATOR . $file));
                }
            }
            if ($newest === 0) {
                $newest = (int) filemtime($dir);
            }
            if (!UploadRepository::isValidId($name) || !isset($uploads[$name])) {
                $this->deleteTree('draft logo', $dir, 'source removed');
            } elseif ($newest < $cutoff) {
                $this->deleteTree('draft logo', $dir, 'older than ' . $days . ' days');
            }
        }
    }

    // --------------------------------------------------------------- temp
    private function cleanTemp(int $days): void
    {
        $cutoff = $this->now - $days * 86400;

        foreach ((array) glob(Config::storagePath('progress') . DIRECTORY_SEPARATOR . 'job_*.json') as $file) {
            if (filemtime((string) $file) < $cutoff) {
                $this->delete('progress', (string) $file, 'older than ' . $days . ' days');
            }
        }

        foreach ((array) glob(Config::storagePath('sessions') . DIRECTORY_SEPARATOR . 'sess_*') as $file) {
            if (is_file((string) $file) && filemtime((string) $file) < $cutoff) {
                $this->delete('session', (string) $file, 'older than ' . $days . ' days');
            }
        }

        foreach ((array) glob(Config::storagePath('logs') . DIRECTORY_SEPARATOR . '*.log') as $file) {
            if (preg_match('/-\d{4}-\d{2}-\d{2}\.log$/', (string) $file) && filemtime((string) $file) < $cutoff) {
                $this->delete('log', (string) $file, 'older than ' . $days . ' days');
            }
        }

        $tmp = Config::storagePath('tmp');
        if (is_dir($tmp)) {
            $mpdf = realpath((string) Config::get('mpdf_temp_path', $tmp . '/mpdf')) ?: '';
            $emptied = [];
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                $path = $item->getPathname();
                if ($item->isLink()) {
                    continue;
                }
                // mPDF's font cache is rebuilt slowly — keep it
                if (str_contains(str_replace('\\', '/', $path), '/ttfontdata') || ($mpdf !== '' && $item->isDir() && realpath($path) === $mpdf)) {
                    continue;
                }
                if ($item->isFile() && $item->getMTime() < $cutoff && $item->getFilename() !== '.htaccess') {
                    $this->delete('temp', $path, 'older than ' . $days . ' days');
                    $emptied[dirname($path)] = true;
                } elseif ($item->isDir() && !$this->dryRun && $this->isEmptyDir($path) && (isset($emptied[$path]) || $item->getMTime() < $cutoff)) {
                    @rmdir($path);
                }
            }
        }
    }

    // ------------------------------------------------------------ helpers
    /**
     * @param array<string, bool> $known stored file names that belong to an entry
     */
    private function orphanFiles(string $dir, array $known, int $days, string $type): void
    {
        $cutoff = $this->now - $days * 86400;
        foreach ((array) @scandir($dir) as $name) {
            if (!is_string($name) || $name === '.' || $name === '..' || $name === '.htaccess' || isset($known[$name])) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path) && !is_link($path) && filemtime($path) < $cutoff) {
                $this->delete($type, $path, 'no index entry');
            }
        }
    }

    private function time(mixed $createdAt, ?string $path): int
    {
        $time = is_string($createdAt) ? strtotime($createdAt) : false;
        if ($time === false && $path !== null) {
            $time = (int) filemtime($path);
        }

        return $time === false ? $this->now : $time;
    }

    private function delete(string $type, string $path, string $reason): void
    {
        if ($this->protectedPath($path)) {
            return;
        }
        $bytes = is_file($path) ? (int) filesize($path) : 0;
        $this->report[] = ['action' => $this->dryRun ? 'would delete' : 'deleted', 'type' => $type, 'path' => $this->relative($path), 'reason' => $reason, 'bytes' => $bytes];
        if ($this->dryRun) {
            return;
        }
        if (!@unlink($path)) {
            $this->report[count($this->report) - 1]['action'] = 'failed';
            Logger::error('cleanup', 'Could not delete', ['type' => $type, 'path' => $this->relative($path)]);

            return;
        }
        Logger::info('cleanup', 'Deleted', ['type' => $type, 'path' => $this->relative($path), 'reason' => $reason, 'bytes' => $bytes]);
    }

    private function deleteTree(string $type, string $dir, string $reason): void
    {
        if ($this->protectedPath($dir)) {
            return;
        }
        foreach ((array) @scandir($dir) as $name) {
            if (is_string($name) && $name !== '.' && $name !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                $this->delete($type, $dir . DIRECTORY_SEPARATOR . $name, $reason);
            }
        }
        if (!$this->dryRun) {
            @rmdir($dir);
        }
    }

    private function note(string $type, string $what, string $reason): void
    {
        $this->report[] = ['action' => $this->dryRun ? 'would remove' : 'removed', 'type' => $type, 'path' => $what, 'reason' => $reason, 'bytes' => 0];
        if (!$this->dryRun) {
            Logger::info('cleanup', 'Index entry removed', ['entry' => $what, 'reason' => $reason]);
        }
    }

    /** Website profiles and their logos are never deleted by cleanup. */
    private function protectedPath(string $path): bool
    {
        $p = str_replace('\\', '/', $path);

        return (bool) preg_match('~/metadata/USR_[A-Za-z0-9]+/(blueprints\.json|assets(/|$))~', $p)
            || str_ends_with($p, '/uploads.json') || str_ends_with($p, '/generated.json') || str_ends_with($p, '/login-attempts.json');
    }

    private function isEmptyDir(string $dir): bool
    {
        return is_dir($dir) && count((array) @scandir($dir)) <= 2;
    }

    private function relative(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', Config::storagePath()), '/') . '/';
        $p = str_replace('\\', '/', $path);

        return 'storage/' . (str_starts_with($p, $root) ? substr($p, strlen($root)) : basename($p));
    }
}
