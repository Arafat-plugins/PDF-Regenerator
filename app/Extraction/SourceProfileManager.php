<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Core\Config;
use App\Core\Logger;
use App\Pdf\Extract\CleanLine;

/**
 * Loads source-profiles/*.json and fingerprints extracted documents (Part D §4).
 * Supporting a new PDF family = adding one JSON file; no code changes.
 */
final class SourceProfileManager
{
    /** @var array<string, SourceProfile>|null */
    private ?array $profiles = null;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? (string) Config::get('source_profile_path', APP_ROOT . '/source-profiles'), '/\\');
    }

    /**
     * @return array<string, SourceProfile>
     */
    public function all(): array
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        $this->profiles = [];
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $id = basename($file, '.json');
            try {
                $json = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($json)) {
                    throw new ProfileException('Profile file must contain an object.');
                }
                $this->profiles[$id] = SourceProfile::fromArray($json, $id);
            } catch (\JsonException | ProfileException $e) {
                Logger::warning('extraction', 'Source profile ignored', ['profile' => $id, 'reason' => $e->getMessage()]);
            }
        }

        return $this->profiles;
    }

    public function get(?string $id): ?SourceProfile
    {
        return $id === null ? null : ($this->all()[$id] ?? null);
    }

    /**
     * The profile whose fingerprint matches the document (most specific first), or null.
     *
     * @param list<CleanLine> $lines
     */
    public function match(array $lines): ?SourceProfile
    {
        $text = ' ' . self::normalize(implode("\n", array_map(static fn (CleanLine $l): string => $l->plain(), $lines))) . ' ';

        $best = null;
        $bestScore = -1;
        foreach ($this->all() as $profile) {
            $score = 0;
            foreach ($profile->fingerprint['all_of'] as $phrase) {
                if (!str_contains($text, self::normalize($phrase))) {
                    continue 2;
                }
                $score++;
            }
            if ($profile->fingerprint['any_of'] !== []) {
                $any = false;
                foreach ($profile->fingerprint['any_of'] as $phrase) {
                    if (str_contains($text, self::normalize($phrase))) {
                        $any = true;
                        $score++;
                    }
                }
                if (!$any) {
                    continue;
                }
            }
            if ($score > $bestScore) {
                $best = $profile;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public static function normalize(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
