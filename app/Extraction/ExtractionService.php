<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Core\Logger;
use App\Editor\BrandingChoice;
use App\Files\SourceFiles;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Pdf\Extract\ExtractionException;
use App\Pdf\Extract\ExtractionResult;
use App\Pdf\Extract\ExtractorFactory;
use App\Pdf\Extract\PdfExtractorInterface;
use App\Pdf\Extract\TextCleaner;
use App\Profiles\BlueprintManager;
use App\Templates\TemplateManager;
use Throwable;

/**
 * The extraction pipeline (Part D §4):
 * text extraction → cleaning → profile match → rules → generic detectors →
 * normalization → template defaults → source_src_xxx.json
 */
final class ExtractionService
{
    public const FRIENDLY_ERROR = "We couldn't extract readable data from this PDF.\nPlease try another PDF or enter the information manually.";

    private UploadRepository $uploads;
    private SourceFiles $files;
    private SourceDataRepository $data;

    /**
     * @param list<PdfExtractorInterface>|null $engines
     */
    public function __construct(
        private UserStorage $storage,
        private ?SourceProfileManager $profiles = null,
        private ?TemplateManager $templates = null,
        private ?array $engines = null,
    ) {
        $this->uploads = new UploadRepository($storage);
        $this->files = new SourceFiles($storage, $this->uploads);
        $this->data = new SourceDataRepository($storage);
        $this->profiles ??= new SourceProfileManager();
        $this->templates ??= new TemplateManager();
    }

    public function dataRepository(): SourceDataRepository
    {
        return $this->data;
    }

    /**
     * Run extraction for one of the current user's uploads.
     *
     * @param array<string, mixed>|null $choice website profile chosen before extraction (Part D §14):
     *   ['mode' => 'existing', 'id' => bp_…] | ['mode' => 'new', 'name' => …, 'url' => …]
     *   | ['mode' => 'keep'] (extract again: keep the current profile, template and branding)
     * @return array<string, mixed> the normalized document
     * @throws ExtractionException with a user-safe message
     */
    public function extract(string $sourceId, ?array $choice = null): array
    {
        $record = $this->uploads->find($sourceId);
        $path = $this->files->path($sourceId);
        if ($record === null || $path === null) {
            throw new ExtractionException('This file could not be found.', 'unknown source ' . $sourceId);
        }

        $previous = ($choice['mode'] ?? '') === 'keep' ? $this->data->working($sourceId) : null;
        $this->uploads->setStatus($sourceId, 'processing');
        $started = microtime(true);

        try {
            $result = $this->readText($path);
            $lines = (new TextCleaner())->lines($result);
            $meta = ['engine' => $result->engine, 'page_count' => $result->pageCount, 'text_bytes' => $result->textBytes()];

            $normalizer = new DataNormalizer();
            if (!$result->hasReadableText() || $lines === []) {
                $document = $normalizer->scanned($meta);
                $profile = null;
            } else {
                $profile = $this->profiles->match($lines);
                $rules = $profile !== null ? (new ProfileRuleEngine())->apply($profile, $lines) : null;

                $filled = [];
                foreach ($rules?->fields ?? [] as $key => $field) {
                    if ($field['value'] !== '') {
                        $filled[$key] = true;
                    }
                }
                foreach ($rules?->lists ?? [] as $key => $list) {
                    if ($list['rows'] !== []) {
                        $filled[$key] = true;
                    }
                }

                $generic = (new GenericDetector())->detect($lines, $rules?->consumed ?? [], $filled, $profile !== null);
                $document = $normalizer->build($profile, $rules, $generic, $meta);
            }

            // the suggested template is applied right away (the user may change it in the editor)
            $template = $this->templates->suggest($document['source_profile']);
            if ($template !== null) {
                $document['template'] = $template->id;
                $document['palette'] = $template->defaultPalette();
                $document = DataNormalizer::applyDefaults($document, $template->defaults);
            }
            $document = $this->applyChoice($document, $choice, $previous);

            $this->data->saveExtraction($sourceId, $document);
            $this->uploads->update($sourceId, static function (array $item) use ($document): array {
                $item['status'] = 'extracted';
                $item['source_profile'] = $document['source_profile'];
                $item['extracted_with'] = $document['engine'];
                $item['extracted_at'] = $document['extracted_at'];

                return $item;
            });

            Logger::info('extraction', 'Extraction completed', [
                'user_id' => $this->storage->userId(),
                'id' => $sourceId,
                'engine' => $document['engine'],
                'profile' => $document['source_profile'],
                'scanned' => $document['scanned'],
                'fields' => count($document['fields']),
                'ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $document;
        } catch (ExtractionException $e) {
            $this->fail($sourceId, $e->detail !== '' ? $e->detail : $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            $this->fail($sourceId, get_class($e) . ': ' . $e->getMessage());
            throw new ExtractionException(self::FRIENDLY_ERROR, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed>|null $choice
     * @param array<string, mixed>|null $previous working copy before "Extract again"
     * @return array<string, mixed>
     */
    private function applyChoice(array $document, ?array $choice, ?array $previous): array
    {
        $profiles = new BlueprintManager($this->storage);
        $setTemplate = BrandingChoice::templateSetter($this->templates);
        $mode = (string) ($choice['mode'] ?? '');

        if ($mode === 'keep' && $previous !== null) {
            $profile = $profiles->find($previous['blueprint_id'] ?? null);
            $info = is_array($previous['profile'] ?? null) ? $previous['profile'] : [];
            if ($profile !== null) {
                $document = $this->withProfile($document, $profile, $setTemplate);
            } elseif (($info['mode'] ?? '') !== '' && trim((string) ($info['name'] ?? '')) !== '') {
                $document = BrandingChoice::newWebsite($document, (string) $info['name'], (string) ($info['url'] ?? ''));
            }
            // the template and branding chosen in the editor stay
            if (is_string($previous['template'] ?? null) && $previous['template'] !== ($document['template'] ?? null)) {
                $document = $setTemplate($document, $previous['template']);
            }
            foreach (['palette', 'palette_chosen', 'custom_colors', 'field_mappings', 'logo_mode', 'profile_hint_dismissed'] as $key) {
                if (array_key_exists($key, $previous)) {
                    $document[$key] = $previous[$key];
                }
            }

            return $document;
        }

        if ($mode === 'existing') {
            $profile = $profiles->find($choice['id'] ?? null);

            return $profile !== null ? $this->withProfile($document, $profile, $setTemplate) : $document;
        }

        if ($mode === 'new') {
            $name = BlueprintManager::cleanName($choice['name'] ?? '');
            if ($name !== '') {
                return BrandingChoice::newWebsite($document, $name, is_string($choice['url'] ?? null) ? $choice['url'] : '');
            }
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function withProfile(array $document, array $profile, callable $setTemplate): array
    {
        $template = $this->templates->get(is_string($profile['template_id'] ?? null) ? $profile['template_id'] : null)
            ?? $this->templates->get(is_string($document['template'] ?? null) ? $document['template'] : null);

        return BlueprintManager::apply($document, $profile, $template, $setTemplate);
    }

    private function readText(string $path): ExtractionResult
    {
        $engines = $this->engines ?? ExtractorFactory::engines();
        if ($engines === []) {
            throw new ExtractionException(self::FRIENDLY_ERROR, 'no extraction engine available');
        }

        $last = null;
        foreach ($engines as $engine) {
            try {
                return $engine->extract($path);
            } catch (ExtractionException $e) {
                $last = $e;
                Logger::warning('extraction', 'Engine failed, trying the next one', ['engine' => $engine->name(), 'detail' => $e->detail]);
            }
        }

        throw $last ?? new ExtractionException(self::FRIENDLY_ERROR, 'all engines failed');
    }

    private function fail(string $sourceId, string $detail): void
    {
        $this->uploads->setStatus($sourceId, 'failed');
        Logger::error('extraction', 'Extraction failed', ['user_id' => $this->storage->userId(), 'id' => $sourceId, 'detail' => $detail]);
    }
}
