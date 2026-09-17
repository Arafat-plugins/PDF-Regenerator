<?php

declare(strict_types=1);

/*
 * "Preview as PDF" (Part D §6): renders the real mPDF output of the current data into
 * storage/tmp/drafts/USR_xxx/ (not listed under Generated Files, status unchanged).
 *
 *   POST action=draft, id=src_xxxxxxxxxxxx → { url }  (opened through preview.php?type=draft)
 */

use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Extraction\SourceDataRepository;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Generation\GenerationService;
use App\Pdf\GenerationException;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
SecurityManager::requirePost();
SecurityManager::requireCsrf();
SessionManager::close();

$id = $_POST['id'] ?? null;
if (($_POST['action'] ?? '') !== 'draft') {
    Response::json(false, 'Unknown action.', [], ['action'], 400);
}
$storage = new UserStorage($user->id);
if (!UploadRepository::isValidId($id) || (new UploadRepository($storage))->find($id) === null) {
    Response::json(false, 'File not found.', [], ['not_found'], 404);
}
if (!(new SourceDataRepository($storage))->exists((string) $id)) {
    Response::json(false, 'This file has no extracted data yet. Please extract it first.', [], ['not_extracted'], 409);
}

@set_time_limit(180);

try {
    $draft = (new GenerationService($storage))->draft((string) $id);
} catch (GenerationException $e) {
    Response::json(false, $e->getMessage(), [], ['generation'], 500);
}

Response::json(true, 'Draft ready.', [
    'url' => url('preview.php?type=draft&id=' . rawurlencode((string) $id) . '&v=' . time()) . \App\Files\GeneratedPresenter::VIEW_ZOOM,
    'size' => $draft['size'],
    'size_label' => format_bytes($draft['size']),
    'template' => $draft['template'],
]);
