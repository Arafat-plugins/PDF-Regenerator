<?php

declare(strict_types=1);

/*
 * Generate a premium PDF from a source's working data (POST, AJAX, JSON envelope) — Part D §9.
 *
 * The generation runs as a job with four real steps, one request per step, so the page can show
 * every state as the server reaches it (this also works on single-worker servers such as php -S):
 *
 *   action=start, id=src_…                      → creates the job, runs "Reading PDF data"
 *   action=step,  job=job_…, step=template|pdf|save
 *   action=generate, id=src_…                   → all steps in one request (fallback)
 *
 * Every answer carries the job as recorded on the server ({job}); the last step also returns
 * the generated file ({item}) and the website profile ({profile}).
 * progress.php?job=… reads the same state at any time.
 */

use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Extraction\SourceDataRepository;
use App\Files\GeneratedPresenter;
use App\Files\GeneratedRepository;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Generation\GenerationService;
use App\Generation\ProgressTracker;
use App\Pdf\GenerationException;
use App\Profiles\BlueprintManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
SecurityManager::requirePost();
SecurityManager::requireCsrf();
SessionManager::close(); // never block progress polls or other tabs of this user

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
if (!in_array($action, ['start', 'step', 'generate'], true)) {
    Response::json(false, 'Unknown action.', [], ['action'], 400);
}

$storage = new UserStorage($user->id);
$service = new GenerationService($storage);
$tracker = new ProgressTracker();

$finished = static function (array $job, int $status = 200) use ($storage): never {
    $data = ['job' => ProgressTracker::view($job)];
    $message = 'Step completed.';
    if (($job['status'] ?? '') === 'done') {
        $entry = (new GeneratedRepository($storage))->find($job['result']['generated_id'] ?? null);
        $data['item'] = $entry !== null ? GeneratedPresenter::row($entry) : null;
        $data['profile'] = null;
        $bp = $job['result']['profile'] ?? null;
        if (is_array($bp) && ($saved = (new BlueprintManager($storage))->find($bp['id'] ?? null)) !== null) {
            $data['profile'] = ['id' => $saved['id'], 'name' => (string) $saved['name'], 'created' => (bool) ($bp['created'] ?? false)];
        }
        $message = 'Your PDF is ready.';
        $status = 201;
    }
    Response::json(true, $message, $data, [], $status);
};

$failed = static function (GenerationException $e, ?string $jobId) use ($tracker, $user): never {
    $job = $jobId !== null ? $tracker->find($jobId, $user->id) : null;
    $status = match ($e->errorCode) {
        'job' => 404,
        'order' => 409,
        default => 500,
    };
    Response::json(false, $e->getMessage(), $job !== null ? ['job' => ProgressTracker::view($job)] : [], [$e->errorCode], $status);
};

@set_time_limit(180);

if ($action === 'step') {
    $jobId = $_POST['job'] ?? null;
    $step = is_string($_POST['step'] ?? null) ? $_POST['step'] : '';
    if (!ProgressTracker::isValidId($jobId) || $tracker->find($jobId, $user->id) === null) {
        Response::json(false, 'This generation could not be found. Please try again.', [], ['job'], 404);
    }
    if (!array_key_exists($step, ProgressTracker::STEPS) || $step === 'read') {
        Response::json(false, 'Unknown step.', [], ['step'], 400);
    }
    try {
        $finished($service->step((string) $jobId, $step));
    } catch (GenerationException $e) {
        $failed($e, (string) $jobId);
    }
}

$id = $_POST['id'] ?? null;
if (!UploadRepository::isValidId($id) || (new UploadRepository($storage))->find($id) === null) {
    Response::json(false, 'File not found.', [], ['not_found'], 404);
}
if (!(new SourceDataRepository($storage))->exists((string) $id)) {
    Response::json(false, 'This file has no extracted data yet. Please extract it first.', [], ['not_extracted'], 409);
}

try {
    $job = $service->start((string) $id);
    if ($action === 'generate') {
        while (($next = ProgressTracker::nextStep($job)) !== null) {
            $job = $service->step($job['id'], $next);
        }
    }
    $finished($job);
} catch (GenerationException $e) {
    $failed($e, $service->lastJobId);
}
