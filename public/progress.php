<?php

declare(strict_types=1);

/*
 * Progress of a generation job (GET, AJAX, JSON envelope) — Part D §9.
 *
 *   progress.php?job=job_xxxxxxxxxxxxxxxx
 *
 * Returns only what the server has recorded: each step is pending, active, done or failed.
 * A job is visible to its owner only.
 */

use App\Core\Response;
use App\Core\SessionManager;
use App\Files\GeneratedPresenter;
use App\Files\GeneratedRepository;
use App\Files\UserStorage;
use App\Generation\ProgressTracker;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
SessionManager::close();
header('Cache-Control: no-store');

$job = (new ProgressTracker())->find($_GET['job'] ?? null, $user->id);
if ($job === null) {
    Response::json(false, 'This generation could not be found.', [], ['not_found'], 404);
}

$data = ['job' => ProgressTracker::view($job)];
if (($job['status'] ?? '') === 'done') {
    $entry = (new GeneratedRepository(new UserStorage($user->id)))->find($job['result']['generated_id'] ?? null);
    $data['item'] = $entry !== null ? GeneratedPresenter::row($entry) : null;
}

Response::json(true, 'OK', $data);
