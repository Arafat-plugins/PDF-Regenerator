<?php

declare(strict_types=1);

namespace App\Editor;

use App\Extraction\DataNormalizer;
use App\Files\UserStorage;
use App\Profiles\BlueprintManager;
use App\Profiles\LogoStore;
use App\Templates\PaletteManager;
use App\Templates\TemplateManager;

/**
 * Branding and website-profile edits of the working copy (Part D §6, §14):
 *
 *   set_palette         palette                 one of the template's preset palettes
 *   set_custom_colors   accent, cover[, success] #rrggbb each (derived shades in PaletteManager)
 *   remove_logo         –                       this report uses the template logo
 *   restore_logo        –                       back to the profile logo
 *   apply_profile       profile                 pre-apply a saved website profile
 *   set_new_website     name, url               this report is for a new website
 *   dismiss_profile_hint profile                hide the "This looks like …" banner
 *
 * The draft logo upload itself goes through logo.php (multipart).
 */
final class BrandingChoice
{
    public const ACTIONS = ['set_palette', 'set_custom_colors', 'remove_logo', 'restore_logo', 'apply_profile', 'set_new_website', 'dismiss_profile_hint'];

    private BlueprintManager $profiles;

    public function __construct(private UserStorage $storage, private string $sourceId, private ?TemplateManager $templates = null)
    {
        $this->templates ??= new TemplateManager();
        $this->profiles = new BlueprintManager($storage);
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $input
     * @return array{doc: array<string, mixed>, result: array<string, mixed>}
     * @throws EditorException
     */
    public function apply(array $doc, string $action, array $input): array
    {
        $doc = DocumentEditor::prepare($doc);
        $template = $this->templates->get(is_string($doc['template'] ?? null) ? $doc['template'] : null)
            ?? $this->templates->suggest(is_string($doc['source_profile'] ?? null) ? $doc['source_profile'] : null);

        switch ($action) {
            case 'set_palette':
                $palette = is_string($input['palette'] ?? null) ? $input['palette'] : '';
                if ($template === null || !isset($template->palettes[$palette])) {
                    throw new EditorException('This color palette is not available. Please reload the page.', 'stale', 'palette');
                }
                $doc['palette'] = $palette;
                $doc['palette_chosen'] = true;

                return ['doc' => $doc, 'result' => ['palette' => $palette]];

            case 'set_custom_colors':
                $accent = self::hex($input['accent'] ?? null, 'Accent color');
                $cover = self::hex($input['cover'] ?? null, 'Cover color');
                $success = ($input['success'] ?? '') === '' ? PaletteManager::SUCCESS : self::hex($input['success'], 'Success color');
                $doc['palette'] = 'custom';
                $doc['custom_colors'] = ['accent' => $accent, 'cover' => $cover, 'success' => $success];
                $doc['palette_chosen'] = true;

                return ['doc' => $doc, 'result' => [
                    'palette' => 'custom',
                    'light_cover' => PaletteManager::luminance($cover) > \App\Profiles\Branding::LIGHT_COVER,
                ]];

            case 'remove_logo':
                (new LogoStore($this->storage))->deleteDraft($this->sourceId);
                $doc['logo_mode'] = 'template';

                return ['doc' => $doc, 'result' => ['logo' => 'template']];

            case 'restore_logo':
                if ($this->profiles->logoPath($doc['blueprint_id'] ?? null) === null) {
                    throw new EditorException('This website profile has no logo any more. Please reload the page.', 'stale', 'logo');
                }
                (new LogoStore($this->storage))->deleteDraft($this->sourceId);
                $doc['logo_mode'] = 'profile';

                return ['doc' => $doc, 'result' => ['logo' => 'profile']];

            case 'apply_profile':
                $profile = $this->profiles->find($input['profile'] ?? null);
                if ($profile === null) {
                    throw new EditorException('This website profile no longer exists. Please reload the page.', 'stale', 'profile');
                }
                $profileTemplate = $this->templates->get(is_string($profile['template_id'] ?? null) ? $profile['template_id'] : null) ?? $template;
                (new LogoStore($this->storage))->deleteDraft($this->sourceId);
                $doc = BlueprintManager::apply($doc, $profile, $profileTemplate, self::templateSetter($this->templates));

                return ['doc' => $doc, 'result' => ['profile' => $profile['id'], 'name' => $profile['name'], 'template' => $profileTemplate?->id]];

            case 'set_new_website':
                $name = BlueprintManager::cleanName($input['name'] ?? '');
                try {
                    $this->profiles->assertNameAvailable($name);
                } catch (\App\Profiles\ProfileException $e) {
                    throw new EditorException($e->getMessage(), 'invalid', 'name');
                }
                try {
                    $url = FieldValidator::scalar('website_url', $input['url'] ?? '');
                } catch (EditorException $e) {
                    throw new EditorException($e->getMessage(), 'invalid', 'url');
                }
                $doc = self::newWebsite($doc, $name, $url);

                return ['doc' => $doc, 'result' => ['name' => $name]];

            case 'dismiss_profile_hint':
                $id = $input['profile'] ?? null;
                if (!BlueprintManager::isValidId($id)) {
                    throw new EditorException('Unknown profile.', 'stale', 'profile');
                }
                $doc['profile_hint_dismissed'] = $id;

                return ['doc' => $doc, 'result' => []];
        }

        throw new EditorException('Unknown action.', 'invalid', 'action');
    }

    /**
     * Mark the job as a new website: its name leads the report, the address is used when given.
     *
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    public static function newWebsite(array $doc, string $name, string $url): array
    {
        $previous = $doc['blueprint_id'] ?? null;
        $doc['blueprint_id'] = null;
        $doc['profile'] = ['mode' => 'new', 'name' => $name, 'url' => $url];
        if (($doc['logo_mode'] ?? 'profile') === 'profile' && $previous !== null) {
            $doc['logo_mode'] = 'template';
        }
        foreach (['website_name' => $name, 'website_url' => $url] as $key => $value) {
            if ($value === '') {
                continue;
            }
            $i = DataNormalizer::find($doc, $key);
            if ($i === null) {
                $doc['fields'][] = DataNormalizer::row($key, $value, 'manual', false);
                $doc = DataNormalizer::sortFields($doc);
                continue;
            }
            $doc['fields'][$i]['value'] = $value;
            $doc['fields'][$i]['source'] = 'manual';
            $doc['fields'][$i]['needs_review'] = false;
            $doc['fields'][$i]['removed'] = false;
            unset($doc['fields'][$i]['note']);
        }

        return DataNormalizer::derive($doc);
    }

    /**
     * @return callable(array<string, mixed>, string): array<string, mixed>
     */
    public static function templateSetter(TemplateManager $templates): callable
    {
        return static function (array $doc, string $templateId) use ($templates): array {
            try {
                return (new TemplateChoice($templates))->apply($doc, 'set_template', ['template' => $templateId])['doc'];
            } catch (EditorException) {
                return $doc;
            }
        };
    }

    private static function hex(mixed $value, string $label): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (!PaletteManager::isHex($value)) {
            throw new EditorException($label . ' must be a color like #2563eb.', 'invalid', match ($label) {
                'Accent color' => 'accent',
                'Success color' => 'success',
                default => 'cover',
            });
        }

        return strtolower($value);
    }
}
