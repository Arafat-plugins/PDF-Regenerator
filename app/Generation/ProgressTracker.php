<?php

declare(strict_types=1);

namespace App\Generation;

use App\Auth\User;
use App\Core\Config;
use App\Core\JsonStore;

/**
 * Generation jobs: storage/progress/{jobId}.json (Part D §9).
 *
 * Every state the browser shows comes from this file: a step is "active" only after the
 * server has started it and "done" only after it has finished. Jobs belong to one user.
 *
 *   {id, user_id, source_id, status: running|done|failed, steps: {read|template|pdf|save: {state, started_at, ms}},
 *    error, result, work (server-only), created_at, updated_at}
 */
final class ProgressTracker
{
    public const STEPS = [
        'read' => 'Reading PDF data',
        'template' => 'Processing template',
        'pdf' => 'Generating PDF',
        'save' => 'Saving file',
    ];

    public static function newId(): string
    {
        return 'job_' . bin2hex(random_bytes(8));
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^job_[a-f0-9]{16}$/', $id);
    }

    public static function dir(): string
    {
        return Config::storagePath('progress');
    }

    public static function file(string $jobId): string
    {
        if (!self::isValidId($jobId)) {
            throw new \InvalidArgumentException('Invalid job id.');
        }

        return self::dir() . DIRECTORY_SEPARATOR . $jobId . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $userId, string $sourceId): array
    {
        if (!User::isValidId($userId)) {
            throw new \InvalidArgumentException('Invalid user id.');
        }
        $now = date('Y-m-d H:i:s');
        $steps = [];
        foreach (array_keys(self::STEPS) as $step) {
            $steps[$step] = ['state' => 'pending', 'started_at' => null, 'ms' => null];
        }
        $job = [
            'id' => self::newId(),
            'user_id' => $userId,
            'source_id' => $sourceId,
            'status' => 'running',
            'steps' => $steps,
            'error' => null,
            'result' => null,
            'work' => [],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        JsonStore::ensureDirectory(self::dir());
        JsonStore::write(self::file($job['id']), $job);

        return $job;
    }

    /**
     * The job, only for its owner.
     *
     * @return array<string, mixed>|null
     */
    public function find(mixed $jobId, string $userId): ?array
    {
        if (!self::isValidId($jobId) || !is_file(self::file($jobId))) {
            return null;
        }
        $job = JsonStore::read(self::file($jobId));

        return ($job['id'] ?? null) === $jobId && ($job['user_id'] ?? null) === $userId ? $job : null;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed>
     */
    public function update(string $jobId, callable $change): array
    {
        return JsonStore::update(self::file($jobId), static function (array $job) use ($change): array {
            $job = $change($job);
            $job['updated_at'] = date('Y-m-d H:i:s');

            return $job;
        });
    }

    /**
     * The next step that has not finished, or null when all are done.
     *
     * @param array<string, mixed> $job
     */
    public static function nextStep(array $job): ?string
    {
        foreach (array_keys(self::STEPS) as $step) {
            if (($job['steps'][$step]['state'] ?? 'pending') !== 'done') {
                return $step;
            }
        }

        return null;
    }

    /**
     * What the browser may see (no server paths).
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function view(array $job): array
    {
        $steps = [];
        foreach (self::STEPS as $step => $label) {
            $s = $job['steps'][$step] ?? [];
            $ms = isset($s['ms']) ? (int) $s['ms'] : null;
            $steps[] = [
                'key' => $step,
                'label' => $label,
                'state' => (string) ($s['state'] ?? 'pending'),
                'ms' => $ms,
                'time' => $ms === null ? '' : self::duration($ms),
            ];
        }

        return [
            'id' => (string) $job['id'],
            'source_id' => (string) ($job['source_id'] ?? ''),
            'status' => (string) ($job['status'] ?? 'running'),
            'next' => ($job['status'] ?? '') === 'running' ? self::nextStep($job) : null,
            'steps' => $steps,
            'error' => $job['error'] ?? null,
            'created_at' => (string) ($job['created_at'] ?? ''),
            'updated_at' => (string) ($job['updated_at'] ?? ''),
        ];
    }

    public static function duration(int $ms): string
    {
        if ($ms < 1000) {
            return '<1s';
        }

        return $ms < 10000 ? rtrim(rtrim(number_format($ms / 1000, 1, '.', ''), '0'), '.') . 's' : (int) round($ms / 1000) . 's';
    }
}
