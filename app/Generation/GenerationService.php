<?php

declare(strict_types=1);

namespace App\Generation;

use App\Core\Config;
use App\Core\JsonStore;
use App\Core\Logger;
use App\Extraction\DataNormalizer;
use App\Extraction\SourceDataRepository;
use App\Files\GeneratedRepository;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Helpers\Text;
use App\Pdf\GenerationException;
use App\Pdf\PdfGenerator;
use App\Pdf\PdfGeneratorInterface;
use App\Profiles\Branding;
use App\Templates\Template;
use App\Templates\TemplateManager;
use App\Templates\TemplateRenderer;
use Throwable;

/**
 * Working data → template → mPDF → storage/generated/USR_xxx/ + generated.json (Part D §9–§10).
 *
 * A generation is a job with four real steps (ProgressTracker), run one request per step by
 * generate.php so the browser can show each state as the server reaches it:
 *   read → template → pdf → save
 */
final class GenerationService
{
    public const STEPS = ProgressTracker::STEPS;

    private UploadRepository $uploads;
    private SourceDataRepository $data;
    private GeneratedRepository $generated;
    private Branding $branding;
    private ProgressTracker $tracker;

    /** Id of the job created by the last start() call (also when its first step failed). */
    public ?string $lastJobId = null;

    public function __construct(
        private UserStorage $storage,
        private ?TemplateManager $templates = null,
        private ?PdfGeneratorInterface $generator = null,
        private ?TemplateRenderer $renderer = null,
        private ?ReportComposer $composer = null,
    ) {
        $this->uploads = new UploadRepository($storage);
        $this->data = new SourceDataRepository($storage);
        $this->generated = new GeneratedRepository($storage);
        $this->templates ??= new TemplateManager();
        $this->generator ??= new PdfGenerator();
        $this->renderer ??= new TemplateRenderer();
        $this->composer ??= new ReportComposer($this->renderer);
        $this->branding = new Branding($storage);
        $this->tracker = new ProgressTracker();
    }

    /**
     * Whole generation in one call (CLI, tests, fallback): start + every step.
     *
     * @param (callable(string, string): void)|null $onStep called with (step, 'active'|'done')
     * @return array<string, mixed> the generated.json entry
     * @throws GenerationException with a user-safe message
     */
    public function generate(string $sourceId, ?callable $onStep = null): array
    {
        $job = $this->start($sourceId, $onStep);
        while (($next = ProgressTracker::nextStep($job)) !== null) {
            $job = $this->step($job['id'], $next, $onStep);
        }
        $entry = $this->generated->find($job['result']['generated_id'] ?? null);
        if ($entry === null) {
            throw new GenerationException(GenerationException::FRIENDLY, 'job finished without an entry');
        }

        return $entry;
    }

    /**
     * Create a job and run its first step ("Reading PDF data").
     *
     * @param (callable(string, string): void)|null $onStep
     * @return array<string, mixed> the job (with server-only work data)
     * @throws GenerationException
     */
    public function start(string $sourceId, ?callable $onStep = null): array
    {
        $record = $this->uploads->find($sourceId);
        if ($record === null || !$this->data->exists($sourceId)) {
            throw new GenerationException('This file has no extracted data yet. Please extract it first.', 'no working data for ' . $sourceId);
        }
        $job = $this->tracker->create($this->storage->userId(), $sourceId);
        $this->lastJobId = $job['id'];
        Logger::info('generation', 'Generation started', ['user_id' => $this->storage->userId(), 'source' => $sourceId, 'job' => $job['id']]);

        return $this->step($job['id'], 'read', $onStep);
    }

    /**
     * Run one step of a job. Steps run strictly in order; each one records "active" before it
     * starts and "done" (with its real duration) after it has finished.
     *
     * @param (callable(string, string): void)|null $onStep
     * @return array<string, mixed> the updated job
     * @throws GenerationException
     */
    public function step(string $jobId, string $step, ?callable $onStep = null): array
    {
        $job = $this->tracker->find($jobId, $this->storage->userId());
        if ($job === null) {
            throw new GenerationException('This generation could not be found. Please try again.', 'unknown job ' . $jobId, null, 'job');
        }
        $started = microtime(true);
        $job = $this->tracker->update($jobId, static function (array $j) use ($step, $jobId): array {
            // checked under the file lock, so a step can never run twice
            if (($j['status'] ?? '') !== 'running' || ProgressTracker::nextStep($j) !== $step || ($j['steps'][$step]['state'] ?? '') !== 'pending') {
                throw new GenerationException('This step is not due. Please start the generation again.', 'step ' . $step . ' out of order in ' . $jobId, null, 'order');
            }
            $j['steps'][$step]['state'] = 'active';
            $j['steps'][$step]['started_at'] = date('Y-m-d H:i:s');

            return $j;
        });
        if ($onStep !== null) {
            $onStep($step, 'active');
        }

        try {
            $work = match ($step) {
                'read' => $this->stepRead($job),
                'template' => $this->stepTemplate($job),
                'pdf' => $this->stepPdf($job),
                'save' => $this->stepSave($job),
            };
        } catch (Throwable $e) {
            $message = $e instanceof GenerationException ? $e->getMessage() : GenerationException::FRIENDLY;
            $detail = $e instanceof GenerationException ? ($e->detail ?: $e->getMessage()) : get_class($e) . ': ' . $e->getMessage();
            Logger::error('generation', 'Generation failed', ['user_id' => $this->storage->userId(), 'source' => $job['source_id'], 'job' => $jobId, 'step' => $step, 'detail' => $detail]);
            $this->cleanup($job);
            $this->tracker->update($jobId, static function (array $j) use ($step, $message, $started): array {
                $j['steps'][$step]['state'] = 'failed';
                $j['steps'][$step]['ms'] = (int) round((microtime(true) - $started) * 1000);
                $j['status'] = 'failed';
                $j['error'] = $message;
                $j['work'] = [];

                return $j;
            });
            throw $e instanceof GenerationException ? $e : new GenerationException(GenerationException::FRIENDLY, $e->getMessage(), $e);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $job = $this->tracker->update($jobId, static function (array $j) use ($step, $work, $ms): array {
            $j['steps'][$step]['state'] = 'done';
            $j['steps'][$step]['ms'] = $ms;
            $j['work'] = array_merge(is_array($j['work'] ?? null) ? $j['work'] : [], $work['work'] ?? []);
            if (isset($work['result'])) {
                $j['result'] = $work['result'];
                $j['status'] = 'done';
                $j['work'] = [];
            }

            return $j;
        });
        if ($onStep !== null) {
            $onStep($step, 'done');
        }

        return $job;
    }

    /** Working files of a job (snapshot, HTML, PDF) — deleted when the job ends. */
    public function workDir(string $jobId): string
    {
        return Config::storagePath('tmp/jobs/' . $this->storage->userId() . '/' . $jobId);
    }

    /**
     * 1. Reading PDF data: a snapshot of the edited data, so later edits cannot change a running job.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function stepRead(array $job): array
    {
        $sourceId = (string) $job['source_id'];
        $record = $this->uploads->find($sourceId);
        $document = $record !== null ? $this->data->working($sourceId) : null;
        if ($record === null || $document === null) {
            throw new GenerationException('This file has no extracted data yet. Please extract it first.', 'no working data for ' . $sourceId);
        }
        $document = DataNormalizer::derive($document);
        $dir = $this->workDir((string) $job['id']);
        JsonStore::ensureDirectory($dir);
        JsonStore::write($dir . DIRECTORY_SEPARATOR . 'working.json', $document);

        return ['work' => ['record' => [
            'original_name' => (string) ($record['original_name'] ?? ''),
            'title' => (string) ($record['title'] ?? ''),
        ]]];
    }

    /**
     * 2. Processing template: data + template + branding → HTML.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function stepTemplate(array $job): array
    {
        $dir = $this->workDir((string) $job['id']);
        $document = $this->snapshot($job);
        $template = $this->template($document);
        $logo = $this->branding->logo($document, $template, (string) $job['source_id']);
        $composed = $this->composer->compose($document, $template, TemplateRenderer::PDF, null, '', $logo);
        if (@file_put_contents($dir . DIRECTORY_SEPARATOR . 'report.html', $composed['html'], LOCK_EX) === false) {
            foreach ($composed['temp'] as $file) {
                @unlink($file);
            }
            throw new GenerationException(GenerationException::FRIENDLY, 'cannot write job html');
        }
        $titles = $this->titles($composed['document'], $template, (array) ($job['work']['record'] ?? []));

        return ['work' => [
            'template' => $template->id,
            'titles' => $titles,
            'temp' => array_map(static fn (string $f): string => basename($f), $composed['temp']),
            'reporting_period' => self::fieldValue($composed['document'], 'reporting_period'),
            'palette' => $document['palette'] ?? null,
        ]];
    }

    /**
     * 3. Generating PDF: mPDF renders the HTML into the job folder.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function stepPdf(array $job): array
    {
        $dir = $this->workDir((string) $job['id']);
        $html = (string) @file_get_contents($dir . DIRECTORY_SEPARATOR . 'report.html');
        $template = $this->templates->get((string) ($job['work']['template'] ?? ''));
        if ($html === '' || $template === null) {
            throw new GenerationException(GenerationException::FRIENDLY, 'job html or template missing');
        }
        try {
            $this->generator->generate($html, $this->options($template, (string) $job['work']['titles']['display']), $dir . DIRECTORY_SEPARATOR . 'report.pdf');
        } finally {
            $this->removeTemp($job);
        }
        clearstatcache();
        if (!is_file($dir . DIRECTORY_SEPARATOR . 'report.pdf') || filesize($dir . DIRECTORY_SEPARATOR . 'report.pdf') < 100) {
            throw new GenerationException(GenerationException::FRIENDLY, 'mPDF produced no file');
        }

        return ['work' => ['temp' => []]];
    }

    /**
     * 4. Saving file: dated unique name in storage/generated/USR_xxx/, generated.json, website profile.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function stepSave(array $job): array
    {
        $sourceId = (string) $job['source_id'];
        $work = (array) $job['work'];
        $src = $this->workDir((string) $job['id']) . DIRECTORY_SEPARATOR . 'report.pdf';
        $template = $this->templates->get((string) ($work['template'] ?? ''));
        if (!is_file($src) || $template === null) {
            throw new GenerationException(GenerationException::FRIENDLY, 'job pdf missing');
        }

        $dir = $this->storage->generatedDir();
        JsonStore::ensureDirectory($dir);
        $fileTitle = (string) ($work['titles']['file'] ?? $work['titles']['main']);
        $fileName = GeneratedRepository::fileName($dir, $fileTitle);
        $target = $dir . DIRECTORY_SEPARATOR . $fileName;
        // Reserve the name so a parallel request cannot pick it too.
        $handle = @fopen($target, 'x');
        if ($handle === false) {
            $fileName = GeneratedRepository::fileName($dir, $fileTitle . ' ' . substr((string) $job['id'], -4));
            $target = $dir . DIRECTORY_SEPARATOR . $fileName;
            $handle = @fopen($target, 'x');
        }
        if ($handle === false) {
            throw new GenerationException(GenerationException::FRIENDLY, 'cannot reserve ' . $fileName);
        }
        fclose($handle);
        if (!@copy($src, $target)) {
            @unlink($target);
            throw new GenerationException(GenerationException::FRIENDLY, 'cannot store ' . $fileName);
        }
        @chmod($target, 0640);
        clearstatcache(true, $target);

        $record = (array) ($work['record'] ?? []);
        $entry = [
            'id' => GeneratedRepository::newId(),
            'title' => (string) $work['titles']['display'],
            'stored_filename' => $fileName,
            'template' => $template->id,
            'template_name' => $template->name,
            'palette' => $work['palette'] ?? null,
            'source_id' => $sourceId,
            'source_original_name' => (string) ($record['original_name'] ?? ''),
            'source_title' => (string) ($record['title'] ?? ''),
            'blueprint_id' => null,
            'reporting_period' => (string) ($work['reporting_period'] ?? ''),
            'size' => (int) filesize($target),
            'status' => 'generated',
            'job_id' => (string) $job['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        // website profile: created on the first generation, updated afterwards (Part D §14)
        $profile = null;
        try {
            $recorded = $this->branding->profiles()->record($this->snapshot($job), $template, $sourceId, $this->branding->logos());
            if ($recorded !== null) {
                $entry['blueprint_id'] = $recorded['id'];
                $profile = ['id' => $recorded['id'], 'created' => $recorded['created']];
                $keep = $recorded['doc'];
                $this->data->updateWorking($sourceId, static function (array $doc) use ($keep): array {
                    foreach (['blueprint_id', 'profile', 'logo_mode'] as $key) {
                        $doc[$key] = $keep[$key] ?? null;
                    }

                    return $doc;
                });
            }
        } catch (Throwable $e) {
            // the PDF exists — a profile problem must not turn it into a failure
            Logger::error('profiles', 'Profile could not be saved', ['user_id' => $this->storage->userId(), 'source' => $sourceId, 'detail' => $e->getMessage()]);
        }

        $this->generated->add($entry);
        if ($this->uploads->find($sourceId) !== null) {
            $this->uploads->setStatus($sourceId, 'generated');
        }
        $this->cleanup($job);

        Logger::info('generation', 'PDF generated', [
            'user_id' => $this->storage->userId(),
            'source' => $sourceId,
            'job' => $job['id'],
            'id' => $entry['id'],
            'template' => $template->id,
            'bytes' => $entry['size'],
        ]);

        return ['result' => ['generated_id' => $entry['id'], 'profile' => $profile]];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function snapshot(array $job): array
    {
        $document = JsonStore::read($this->workDir((string) $job['id']) . DIRECTORY_SEPARATOR . 'working.json');
        if (!isset($document['fields'])) {
            throw new GenerationException(GenerationException::FRIENDLY, 'job snapshot missing');
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $job
     */
    private function removeTemp(array $job): void
    {
        foreach ((array) ($job['work']['temp'] ?? []) as $name) {
            if (is_string($name) && preg_match('/^[a-z]+_[a-f0-9]{16}\.[a-z]{3}$/', $name)) {
                @unlink(Config::storagePath('tmp') . DIRECTORY_SEPARATOR . $name);
            }
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function cleanup(array $job): void
    {
        $this->removeTemp($job);
        $dir = $this->workDir((string) $job['id']);
        foreach (['working.json', 'report.html', 'report.pdf'] as $file) {
            @unlink($dir . DIRECTORY_SEPARATOR . $file);
        }
        @rmdir($dir);
    }

    /**
     * "Preview as PDF": the same render into storage/tmp/drafts/USR_xxx/<source>.pdf.
     * Not listed under generated files; the upload status does not change.
     *
     * @return array{path: string, size: int, template: string}
     * @throws GenerationException
     */
    public function draft(string $sourceId): array
    {
        $temp = [];
        try {
            $record = $this->uploads->find($sourceId);
            $document = $record !== null ? $this->data->working($sourceId) : null;
            if ($record === null || $document === null) {
                throw new GenerationException('This file has no extracted data yet. Please extract it first.', 'no working data for ' . $sourceId);
            }
            $template = $this->template($document);
            $logo = $this->branding->logo($document, $template, $sourceId);
            $composed = $this->composer->compose($document, $template, TemplateRenderer::PDF, null, '', $logo);
            $temp = $composed['temp'];
            $titles = $this->titles($composed['document'], $template, $record);
            $target = self::draftPath($this->storage, $sourceId);
            JsonStore::ensureDirectory(dirname($target));
            $this->generator->generate($composed['html'], $this->options($template, 'Draft — ' . $titles['display']), $target);
            clearstatcache(true, $target);

            return ['path' => $target, 'size' => (int) filesize($target), 'template' => $template->id];
        } catch (GenerationException $e) {
            Logger::error('generation', 'Draft failed', ['user_id' => $this->storage->userId(), 'source' => $sourceId, 'detail' => $e->detail ?: $e->getMessage()]);
            throw $e;
        } catch (Throwable $e) {
            Logger::error('generation', 'Draft failed', ['user_id' => $this->storage->userId(), 'source' => $sourceId, 'detail' => get_class($e) . ': ' . $e->getMessage()]);
            throw new GenerationException(GenerationException::FRIENDLY, $e->getMessage(), $e);
        } finally {
            foreach ($temp as $file) {
                @unlink($file);
            }
        }
    }

    public static function draftPath(UserStorage $storage, string $sourceId): string
    {
        if (!UploadRepository::isValidId($sourceId)) {
            throw new \InvalidArgumentException('Invalid source id.');
        }

        return Config::storagePath('tmp/drafts/' . $storage->userId()) . DIRECTORY_SEPARATOR . $sourceId . '.pdf';
    }

    /**
     * @param array<string, mixed> $document
     */
    private function template(array $document): Template
    {
        $template = $this->templates->get(is_string($document['template'] ?? null) ? $document['template'] : null)
            ?? $this->templates->suggest(is_string($document['source_profile'] ?? null) ? $document['source_profile'] : null);
        if ($template === null) {
            throw new GenerationException('No templates are available.', 'no templates');
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Template $template, string $title): array
    {
        return [
            'page_size' => $template->pageSize,
            'orientation' => $template->orientation,
            'margins' => $template->margins,
            'page_height' => $template->pageHeight,
            'design_width_px' => $template->designWidthPx,
            'title' => $title,
            'subject' => $template->name,
        ];
    }

    /**
     * main    → file name part: "{website/profile name} {report_title}" or the best available title
     * display → "{website} — {report title} ({period})"
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $record
     * @return array{main: string, display: string, file: string}
     */
    private function titles(array $document, Template $template, array $record): array
    {
        // with a website profile the profile name leads the file name (Part D §10)
        $profile = $this->branding->profile($document);
        $info = is_array($document['profile'] ?? null) ? $document['profile'] : [];
        $site = $profile !== null ? (string) $profile['name'] : trim((string) ($info['name'] ?? ''));
        if ($site === '') {
            $site = self::fieldValue($document, 'website_name');
        }
        $reportTitle = self::fieldValue($document, 'report_title') ?: self::fieldValue($document, 'title') ?: $template->name;
        $period = self::fieldValue($document, 'reporting_period');

        $main = trim(($site !== '' ? $site . ' ' : '') . $reportTitle);
        $display = ($site !== '' ? $site . ' — ' : '') . $reportTitle . ($period !== '' ? ' (' . $period . ')' : '');
        if (trim($main) === '') {
            $main = (string) ($record['title'] ?? 'Report');
        }

        // file name: "Plcworld Maintenance Report 14 Sept 2026"
        $kind = stripos($reportTitle, 'maintenance report') !== false ? 'Maintenance Report' : $reportTitle;
        $end = \App\Extraction\Transforms::dateIso(self::fieldValue($document, 'reporting_end'))
            ?? \App\Extraction\Transforms::dateIso(self::fieldValue($document, 'date'));
        $file = trim(self::ucfirst($site) . ' ' . $kind . ' ' . GeneratedRepository::fileDate($end));

        return ['main' => Text::limit($main, 90), 'display' => Text::cleanTitle($display, 160), 'file' => Text::limit($file, 120)];
    }

    private static function ucfirst(string $value): string
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1) : ucfirst($value);
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function fieldValue(array $document, string $key): string
    {
        $i = DataNormalizer::find($document, $key);
        if ($i === null || !DataNormalizer::isActive($document['fields'][$i])) {
            return '';
        }

        return trim((string) $document['fields'][$i]['value']);
    }
}
