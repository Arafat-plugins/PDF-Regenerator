<?php

declare(strict_types=1);

namespace App\Profiles;

use App\Extraction\DataNormalizer;
use App\Files\UserStorage;
use App\Templates\PaletteManager;
use App\Templates\Template;

/**
 * Which logo, colors and website profile a job uses (Part D §6, §14) — resolved from the
 * working copy, the draft logo folder and blueprints.json.
 *
 * Working-copy keys:
 *   blueprint_id            chosen profile (null = new website / none)
 *   profile                 {mode: existing|new, name, url}
 *   logo_mode               profile (default) | draft (uploaded for this job) | template (removed)
 *   palette, custom_colors, palette_chosen
 *   profile_hint_dismissed  profile id the user ignored in the auto-match banner
 */
final class Branding
{
    /** Relative luminance above which white cover text is hard to read. */
    public const LIGHT_COVER = 0.45;

    private BlueprintManager $profiles;
    private LogoStore $logos;

    public function __construct(private UserStorage $storage)
    {
        $this->profiles = new BlueprintManager($storage);
        $this->logos = new LogoStore($storage);
    }

    public function profiles(): BlueprintManager
    {
        return $this->profiles;
    }

    public function logos(): LogoStore
    {
        return $this->logos;
    }

    /**
     * The logo for this job.
     *
     * @param array<string, mixed> $doc
     * @return array{mode: string, path: ?string, url: string, version: int}
     *   mode draft|profile|template|none; url is an asset.php URL for the preview ('' = none)
     */
    public function logo(array $doc, ?Template $template, string $sourceId): array
    {
        $mode = (string) ($doc['logo_mode'] ?? 'profile');
        if ($mode === 'draft') {
            $path = $this->logos->draftPath($sourceId);
            if ($path !== null) {
                return ['mode' => 'draft', 'path' => $path, 'url' => 'asset.php?kind=logo&src=' . rawurlencode($sourceId) . '&v=' . filemtime($path), 'version' => (int) filemtime($path)];
            }
        }
        if ($mode !== 'template') {
            $bp = $doc['blueprint_id'] ?? null;
            $path = $this->profiles->logoPath($bp);
            if ($path !== null) {
                return ['mode' => 'profile', 'path' => $path, 'url' => 'asset.php?kind=logo&bp=' . rawurlencode((string) $bp) . '&v=' . filemtime($path), 'version' => (int) filemtime($path)];
            }
        }
        $name = $template?->defaultLogo();
        if ($template !== null && $name !== null) {
            return ['mode' => 'template', 'path' => $template->assetPath($name), 'url' => 'asset.php?kind=tpl&t=' . rawurlencode($template->id) . '&f=' . rawurlencode($name), 'version' => 0];
        }

        return ['mode' => 'none', 'path' => null, 'url' => '', 'version' => 0];
    }

    /**
     * The saved profile for this job, or null (new website, none, or deleted).
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>|null
     */
    public function profile(array $doc): ?array
    {
        return $this->profiles->find($doc['blueprint_id'] ?? null);
    }

    /**
     * Branding strip + profile chip model for the editor.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public function model(array $doc, ?Template $template, string $sourceId): array
    {
        $palettes = [];
        foreach ($template?->palettes ?? [] as $id => $palette) {
            $palettes[] = [
                'id' => (string) $id,
                'label' => $palette['label'] !== '' ? $palette['label'] : ucfirst((string) $id),
                'color' => $palette['primary'],
                'cover' => $palette['cover_mid'],
            ];
        }
        $paletteId = is_string($doc['palette'] ?? null) ? $doc['palette'] : (string) $template?->defaultPalette();
        $custom = is_array($doc['custom_colors'] ?? null) ? $doc['custom_colors'] : [];
        $theme = $template !== null ? PaletteManager::theme($template, $paletteId, $custom) : null;
        $accent = PaletteManager::isHex($custom['accent'] ?? null) ? strtolower($custom['accent']) : ($theme['theme_primary'] ?? '#2563eb');
        $cover = PaletteManager::isHex($custom['cover'] ?? null) ? strtolower($custom['cover']) : ($theme['theme_cover_mid'] ?? '#2563eb');
        $coverNow = $theme['theme_cover_mid'] ?? $cover;
        $success = PaletteManager::isHex($custom['success'] ?? null) ? strtolower($custom['success']) : ($theme['theme_success'] ?? PaletteManager::SUCCESS);

        $logo = $this->logo($doc, $template, $sourceId);
        $saved = $this->profile($doc);
        $profileLogo = $saved !== null ? $this->profiles->logoPath($saved['id']) : null;

        $info = is_array($doc['profile'] ?? null) ? $doc['profile'] : [];
        if ($saved !== null) {
            $profile = ['mode' => 'existing', 'id' => $saved['id'], 'name' => (string) $saved['name'], 'url' => (string) ($saved['website_url'] ?? '')];
        } elseif (($info['mode'] ?? '') !== '' && trim((string) ($info['name'] ?? '')) !== '') {
            $profile = ['mode' => 'new', 'id' => null, 'name' => (string) $info['name'], 'url' => (string) ($info['url'] ?? '')];
        } else {
            $profile = ['mode' => 'none', 'id' => null, 'name' => '', 'url' => ''];
        }

        // auto-match: the PDF's website address belongs to a profile the user did not pick
        $match = null;
        $i = DataNormalizer::find($doc, 'website_url');
        $url = $i !== null ? trim((string) $doc['fields'][$i]['value']) : '';
        $found = $url !== '' ? $this->profiles->matchUrl($url) : null;
        if ($found !== null && $found['id'] !== ($saved['id'] ?? null) && ($doc['profile_hint_dismissed'] ?? null) !== $found['id']) {
            $match = ['id' => $found['id'], 'name' => (string) $found['name'], 'host' => BlueprintManager::host((string) $found['website_url'])];
        }

        return [
            'palettes' => $palettes,
            'palette' => $paletteId,
            'custom' => ['accent' => $accent, 'cover' => $cover, 'success' => $success],
            'color' => $theme['theme_primary'] ?? '#2563eb',
            'cover' => $coverNow,
            'light_cover' => PaletteManager::luminance($coverNow) > self::LIGHT_COVER,
            'logo' => [
                'mode' => $logo['mode'],
                'url' => $logo['url'],
                'label' => match ($logo['mode']) {
                    'draft' => 'New logo (this report)',
                    'profile' => 'Profile logo',
                    'template' => 'Template logo',
                    default => 'No logo',
                },
                'can_remove' => in_array($logo['mode'], ['draft', 'profile'], true),
                'can_restore' => $profileLogo !== null && $logo['mode'] !== 'profile',
                'max_label' => format_bytes(LogoStore::maxSize()),
            ],
            'profile' => $profile,
            'match' => $match,
        ];
    }

    /**
     * Profiles for the chooser (upload page, "Use" modal, editor chip).
     *
     * @return list<array<string, mixed>>
     */
    public function chooserList(\App\Templates\TemplateManager $templates): array
    {
        try {
            $this->profiles->pruneOrphansOnce((new \App\Files\GeneratedRepository($this->storage))->all());
        } catch (\Throwable) {
        }
        $out = [];
        foreach ($this->profiles->all() as $p) {
            $tpl = $templates->get(is_string($p['template_id'] ?? null) ? $p['template_id'] : null);
            $paletteId = (string) ($p['color_palette'] ?? '');
            $color = '#94a3b8';
            $paletteLabel = '';
            if ($paletteId === 'custom' && PaletteManager::isHex($p['custom_colors']['accent'] ?? null)) {
                $color = strtolower($p['custom_colors']['accent']);
                $paletteLabel = 'Custom';
            } elseif ($tpl !== null && isset($tpl->palettes[$paletteId])) {
                $color = $tpl->palettes[$paletteId]['primary'];
                $paletteLabel = $tpl->palettes[$paletteId]['label'];
            }
            $logo = $this->profiles->logoPath($p['id']);
            $out[] = [
                'id' => $p['id'],
                'name' => (string) $p['name'],
                'host' => BlueprintManager::host((string) ($p['website_url'] ?? '')),
                'url' => (string) ($p['website_url'] ?? ''),
                'used_label' => is_string($p['last_used_at'] ?? null) ? date('d M', (int) strtotime($p['last_used_at'])) : '',
                'reports' => (int) ($p['reports'] ?? 0),
                'template' => $tpl?->name ?? 'Template not installed',
                'palette' => $paletteLabel !== '' ? $paletteLabel : 'Template colors',
                'color' => $color,
                'logo' => $logo !== null,
                'logo_url' => $logo !== null ? 'asset.php?kind=logo&bp=' . rawurlencode($p['id']) . '&v=' . filemtime($logo) : '',
            ];
        }

        return $out;
    }
}
