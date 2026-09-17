<?php

declare(strict_types=1);

namespace App\Profiles;

use App\Core\JsonStore;
use App\Core\Logger;
use App\Data\FieldDictionary;
use App\Extraction\DataNormalizer;
use App\Files\UserStorage;
use App\Helpers\Text;
use App\Templates\FieldMapper;
use App\Templates\PaletteManager;
use App\Templates\Template;

/**
 * Website profiles ("blueprints", Part D §14) — storage/metadata/USR_xxx/blueprints.json.
 *
 * Lifecycle: a new website lives only in the source's working copy (`profile`, draft logo)
 * until its first successful generation; then record() creates the profile, and later
 * generations update it. Profile logos live in storage/metadata/USR_xxx/assets/{bp_id}/.
 */
final class BlueprintManager
{
    public const MAX_NAME = 60;
    public const MAX_PROFILES = 200;

    /**
     * Texts remembered per website (values the user typed, never PDF values).
     * PDF-extracted values always win when the next report is read.
     */
    public const TEXT_DEFAULT_KEYS = [
        'report_kicker', 'report_subtitle', 'summary_text', 'client_name', 'backup_note',
        'signature_name', 'signature_title', 'signature_company', 'footer_left', 'footer_right',
        'address', 'email', 'phone',
    ];

    /** Sources that come from the PDF itself. */
    private const EXTRACTED_SOURCES = ['profile_rule', 'kv', 'date', 'money', 'contact', 'table', 'title', 'inferred'];

    public function __construct(private UserStorage $storage)
    {
    }

    public static function newId(): string
    {
        return 'bp_' . bin2hex(random_bytes(6));
    }

    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^bp_[a-f0-9]{12}$/', $id);
    }

    public function file(): string
    {
        return $this->storage->metadataFile('blueprints');
    }

    /**
     * @return list<array<string, mixed>> newest use first
     */
    public function all(): array
    {
        $items = array_values(array_filter(JsonStore::read($this->file()), static fn ($p): bool => is_array($p) && self::isValidId($p['id'] ?? null)));
        usort($items, static fn (array $a, array $b): int => [(string) ($b['last_used_at'] ?? ''), Text::lower((string) ($a['name'] ?? ''))]
            <=> [(string) ($a['last_used_at'] ?? ''), Text::lower((string) ($b['name'] ?? ''))]);

        return $items;
    }

    public function count(): int
    {
        return count($this->all());
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

    public static function cleanName(mixed $name): string
    {
        return is_string($name) ? Text::cleanTitle($name, self::MAX_NAME) : '';
    }

    /**
     * @throws ProfileException
     */
    public function assertNameAvailable(string $name, ?string $exceptId = null): void
    {
        if ($name === '') {
            throw new ProfileException('Please enter a website name.', 'name');
        }
        foreach ($this->all() as $item) {
            if ($item['id'] !== $exceptId && Text::lower((string) $item['name']) === Text::lower($name)) {
                throw new ProfileException('A website profile named “' . $name . '” already exists. Please choose it under Existing Profile or use another name.', 'name');
            }
        }
    }

    /**
     * @return array<string, mixed> the renamed profile
     * @throws ProfileException
     */
    public function rename(string $id, mixed $name): array
    {
        $clean = self::cleanName($name);
        if ($this->find($id) === null) {
            throw new ProfileException('This website profile no longer exists.', 'not_found', 404);
        }
        $this->assertNameAvailable($clean, $id);
        $result = null;
        JsonStore::update($this->file(), static function (array $all) use ($id, $clean, &$result): array {
            if (isset($all[$id]) && is_array($all[$id])) {
                $all[$id]['name'] = $clean;
                $all[$id]['updated_at'] = date('Y-m-d H:i:s');
                $result = $all[$id];
            }

            return $all;
        });
        if ($result === null) {
            throw new ProfileException('This website profile no longer exists.', 'not_found', 404);
        }
        Logger::info('profiles', 'Website profile renamed', ['user_id' => $this->storage->userId(), 'id' => $id]);

        return $result;
    }

    /**
     * Remove the profile and its logo folder. Generated PDFs are never touched.
     *
     * @return array<string, mixed>|null the removed profile
     */
    public function delete(string $id): ?array
    {
        if (!self::isValidId($id)) {
            return null;
        }
        $removed = null;
        JsonStore::update($this->file(), static function (array $all) use ($id, &$removed): array {
            if (isset($all[$id])) {
                $removed = $all[$id];
                unset($all[$id]);
            }

            return $all;
        });
        if ($removed !== null) {
            LogoStore::removeDirectory($this->assetsDir($id));
            Logger::info('profiles', 'Website profile deleted', ['user_id' => $this->storage->userId(), 'id' => $id]);
        }

        return is_array($removed) ? $removed : null;
    }

    /**
     * A generated report was deleted by the user: the profile's report count follows the
     * remaining reports, and a profile without any report left is removed.
     *
     * @param list<array<string, mixed>> $remaining generated entries still stored
     * @return 'deleted'|'updated'|null
     */
    public function reportDeleted(mixed $id, array $remaining): ?string
    {
        if (!self::isValidId($id) || $this->find($id) === null) {
            return null;
        }
        $left = count(array_filter($remaining, static fn (array $g): bool => ($g['blueprint_id'] ?? null) === $id));
        if ($left === 0) {
            $this->delete((string) $id);

            return 'deleted';
        }
        JsonStore::update($this->file(), static function (array $all) use ($id, $left): array {
            if (isset($all[$id]) && is_array($all[$id])) {
                $all[$id]['reports'] = $left;
            }

            return $all;
        });

        return 'updated';
    }

    /**
     * One-time clean-up (profiles saved before reportDeleted existed): removes profiles whose
     * reports were all deleted. Runs once per user; later retention clean-ups never remove profiles.
     *
     * @param list<array<string, mixed>> $generated
     */
    public function pruneOrphansOnce(array $generated): int
    {
        $marker = $this->storage->metadataFile('profiles_synced');
        if (is_file($marker)) {
            return 0;
        }
        $used = array_flip(array_filter(array_map(static fn (array $g): string => (string) ($g['blueprint_id'] ?? ''), $generated)));
        $removed = 0;
        foreach ($this->all() as $p) {
            if (!isset($used[$p['id']])) {
                $this->delete((string) $p['id']);
                $removed++;
            }
        }
        JsonStore::ensureDirectory(dirname($marker));
        @file_put_contents($marker, json_encode(['synced_at' => date('Y-m-d H:i:s'), 'removed' => $removed]));

        return $removed;
    }

    public function assetsDir(string $id): string
    {
        if (!self::isValidId($id)) {
            throw new \InvalidArgumentException('Invalid profile id.');
        }

        return $this->storage->metadataDir() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $id;
    }

    /** The profile's logo file, or null. */
    public function logoPath(mixed $id): ?string
    {
        $profile = $this->find($id);
        if ($profile === null || !is_string($profile['custom_logo_path'] ?? null)) {
            return null;
        }

        return LogoStore::existing($this->assetsDir((string) $id));
    }

    /**
     * Profile whose website address matches $url (host, ignoring www. and trailing slash).
     *
     * @return array<string, mixed>|null
     */
    public function matchUrl(string $url): ?array
    {
        $host = self::host($url);
        if ($host === '') {
            return null;
        }
        foreach ($this->all() as $item) {
            if (self::host((string) ($item['website_url'] ?? '')) === $host) {
                return $item;
            }
        }

        return null;
    }

    public static function host(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return (string) preg_replace('/^www\./', '', rtrim($host, '.'));
    }

    /**
     * Pre-apply a saved profile to a working copy: template, colors, logo, field mapping,
     * website name and remembered texts (PDF values win over remembered texts).
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $profile
     * @param (callable(array<string, mixed>, string): array<string, mixed>)|null $setTemplate applies a template choice
     * @return array<string, mixed>
     */
    public static function apply(array $doc, array $profile, ?Template $template, ?callable $setTemplate = null): array
    {
        $doc['blueprint_id'] = $profile['id'];
        $doc['profile'] = [
            'mode' => 'existing',
            'name' => (string) ($profile['name'] ?? ''),
            'url' => (string) ($profile['website_url'] ?? ''),
        ];
        unset($doc['profile_hint_dismissed']);

        if ($template !== null && $setTemplate !== null) {
            $doc = $setTemplate($doc, $template->id);
        }
        $templateId = $template?->id ?? (is_string($doc['template'] ?? null) ? $doc['template'] : '');

        // colors
        $palette = is_string($profile['color_palette'] ?? null) ? $profile['color_palette'] : null;
        $custom = is_array($profile['custom_colors'] ?? null) ? $profile['custom_colors'] : null;
        if ($palette === 'custom' && $custom !== null && PaletteManager::isHex($custom['accent'] ?? null) && PaletteManager::isHex($custom['cover'] ?? null)) {
            $doc['palette'] = 'custom';
            $doc['custom_colors'] = ['accent' => strtolower($custom['accent']), 'cover' => strtolower($custom['cover'])]
                + (PaletteManager::isHex($custom['success'] ?? null) ? ['success' => strtolower($custom['success'])] : []);
            $doc['palette_chosen'] = true;
        } elseif ($palette !== null && $template !== null && isset($template->palettes[$palette])) {
            $doc['palette'] = $palette;
            $doc['palette_chosen'] = true;
        }

        // logo: the profile logo (or the template logo when the profile has none)
        $doc['logo_mode'] = 'profile';

        // field mapping for the profile's template
        if ($templateId !== '' && is_array($profile['field_mappings'] ?? null) && $template !== null) {
            $clean = [];
            foreach ($profile['field_mappings'] as $field => $source) {
                if (is_string($field) && is_string($source) && FieldDictionary::isKnown($field)
                    && ($source === FieldMapper::NONE || FieldDictionary::isKnown($source))) {
                    $clean[$field] = $source;
                }
            }
            $mappings = is_array($doc['field_mappings'] ?? null) ? $doc['field_mappings'] : [];
            $mappings[$templateId] = $clean;
            $doc['field_mappings'] = $mappings;
        }

        // website name (chooser name → profile name → PDF) and address
        $doc = self::setText($doc, 'website_name', (string) ($profile['name'] ?? ''), true);
        $url = (string) ($profile['website_url'] ?? '');
        $i = DataNormalizer::find($doc, 'website_url');
        if ($url !== '' && ($i === null || trim((string) $doc['fields'][$i]['value']) === '')) {
            $doc = self::setText($doc, 'website_url', $url, true);
        }

        // remembered texts
        foreach (is_array($profile['text_defaults'] ?? null) ? $profile['text_defaults'] : [] as $key => $text) {
            if (!is_string($key) || !is_string($text) || !in_array($key, self::TEXT_DEFAULT_KEYS, true)) {
                continue;
            }
            $doc = self::setText($doc, $key, $text, false);
        }

        return DataNormalizer::derive($doc);
    }

    /**
     * Profile-sourced text. $force = true overwrites anything but a value typed in this job.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private static function setText(array $doc, string $key, string $text, bool $force): array
    {
        $text = DataNormalizer::cleanValue($text);
        if ($text === '') {
            return $doc;
        }
        $i = DataNormalizer::find($doc, $key);
        if ($i === null) {
            $doc['fields'][] = DataNormalizer::row($key, $text, 'profile', false);

            return DataNormalizer::sortFields($doc);
        }
        $row = $doc['fields'][$i];
        $source = (string) ($row['source'] ?? '');
        $empty = trim((string) $row['value']) === '';
        $extracted = in_array($source, self::EXTRACTED_SOURCES, true) && !$empty;
        if ($source === 'manual' && !($force && $key === 'website_name')) {
            return $doc; // typed in this job
        }
        if ($extracted && !$force) {
            return $doc; // PDF values win over remembered texts
        }
        $doc['fields'][$i]['value'] = $text;
        $doc['fields'][$i]['source'] = 'profile';
        $doc['fields'][$i]['needs_review'] = false;
        $doc['fields'][$i]['removed'] = false;
        unset($doc['fields'][$i]['note']);

        return $doc;
    }

    /**
     * After a successful generation: create the profile (new website) or update it.
     * Moves a draft logo into the profile, or drops the profile logo when the user
     * removed it for this job.
     *
     * @param array<string, mixed> $doc working copy (with mapping applied or not)
     * @return array{id: string, created: bool, doc: array<string, mixed>}|null null = the job has no profile
     */
    public function record(array $doc, Template $template, string $sourceId, LogoStore $logos): ?array
    {
        $existing = $this->find($doc['blueprint_id'] ?? null);
        $info = is_array($doc['profile'] ?? null) ? $doc['profile'] : [];
        if ($existing === null) {
            $name = self::cleanName($info['name'] ?? '');
            if ($name === '' || ($info['mode'] ?? '') === '') {
                return null;
            }
            // a deleted profile becomes a new one; avoid a name clash with another profile
            $taken = array_map(static fn (array $p): string => Text::lower((string) $p['name']), $this->all());
            $base = $name;
            for ($n = 2; in_array(Text::lower($name), $taken, true) && $n < 50; $n++) {
                $name = Text::limit($base, self::MAX_NAME - 4) . ' (' . $n . ')';
            }
            if (count($taken) >= self::MAX_PROFILES) {
                Logger::warning('profiles', 'Profile limit reached', ['user_id' => $this->storage->userId()]);

                return null;
            }
        }

        $id = $existing['id'] ?? self::newId();
        $now = date('Y-m-d H:i:s');
        $dir = $this->assetsDir($id);

        // logo
        $logoPath = $existing['custom_logo_path'] ?? null;
        $mode = (string) ($doc['logo_mode'] ?? 'profile');
        if ($mode === 'draft') {
            $moved = $logos->promoteDraft($sourceId, $dir);
            if ($moved !== null) {
                $logoPath = 'assets/' . $id . '/' . basename($moved);
                $doc['logo_mode'] = 'profile';
            }
        } elseif ($mode === 'template') {
            LogoStore::removeDirectory($dir);
            $logoPath = null;
            $doc['logo_mode'] = 'profile';
        }

        // remembered texts
        $texts = is_array($existing['text_defaults'] ?? null) ? $existing['text_defaults'] : [];
        foreach ($doc['fields'] as $row) {
            $key = (string) ($row['key'] ?? '');
            if (!in_array($key, self::TEXT_DEFAULT_KEYS, true)) {
                continue;
            }
            $source = (string) ($row['source'] ?? '');
            if (!in_array($source, ['manual', 'profile'], true)) {
                continue;
            }
            if (!DataNormalizer::isActive($row) || trim((string) $row['value']) === '') {
                unset($texts[$key]);
                continue;
            }
            $texts[$key] = (string) $row['value'];
        }

        $mappings = is_array($doc['field_mappings'][$template->id] ?? null) ? $doc['field_mappings'][$template->id] : [];
        $siteUrl = '';
        $i = DataNormalizer::find($doc, 'website_url');
        if ($i !== null && DataNormalizer::isActive($doc['fields'][$i])) {
            $siteUrl = trim((string) $doc['fields'][$i]['value']);
        }
        if ($siteUrl === '') {
            $siteUrl = (string) ($existing['website_url'] ?? ($info['url'] ?? ''));
        }
        $palette = is_string($doc['palette'] ?? null) ? $doc['palette'] : $template->defaultPalette();
        $custom = $palette === 'custom' && is_array($doc['custom_colors'] ?? null) ? $doc['custom_colors'] : null;

        $entry = [
            'id' => $id,
            'name' => $existing['name'] ?? $name,
            'website_url' => $siteUrl,
            'template_id' => $template->id,
            'source_profile' => $doc['source_profile'] ?? ($existing['source_profile'] ?? null),
            'custom_logo_path' => $logoPath,
            'color_palette' => $palette,
            'custom_colors' => $custom,
            'field_mappings' => $mappings === [] ? new \stdClass() : $mappings,
            'text_defaults' => $texts === [] ? new \stdClass() : $texts,
            'reports' => (int) ($existing['reports'] ?? 0) + 1,
            'last_used_at' => $now,
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];
        // JSON round trip turns the stdClass placeholders into {} on disk
        $entry = json_decode((string) json_encode($entry), true);

        JsonStore::update($this->file(), static function (array $all) use ($id, $entry): array {
            $all[$id] = $entry;

            return $all;
        });

        $doc['blueprint_id'] = $id;
        $doc['profile'] = ['mode' => 'existing', 'name' => $entry['name'], 'url' => $siteUrl];

        Logger::info('profiles', $existing === null ? 'Website profile created' : 'Website profile updated', [
            'user_id' => $this->storage->userId(), 'id' => $id, 'source' => $sourceId,
        ]);

        return ['id' => $id, 'created' => $existing === null, 'doc' => $doc];
    }
}
