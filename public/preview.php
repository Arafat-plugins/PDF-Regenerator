<?php

declare(strict_types=1);

/*
 * Authorised read-through endpoint for PDFs (Part C §12).
 *
 *   preview.php?type=source&id=src_xxxxxxxxxxxx              → inline preview
 *   preview.php?type=source&id=src_xxxxxxxxxxxx&download=1   → attachment
 *   preview.php?type=generated&id=gen_xxxxxxxxxxxx[&download=1]
 *   preview.php?type=draft&id=src_xxxxxxxxxxxx               → the latest "Preview as PDF" draft
 *
 * Session + ownership are checked on every request; ids are opaque and are
 * looked up ONLY in the current user's own metadata.
 */

use App\Core\Logger;
use App\Core\SessionManager;
use App\Core\View;
use App\Files\FileStreamer;
use App\Files\SourceFiles;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Generation\GenerationService;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
SessionManager::close(); // streaming must not hold the session lock

$type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
$download = ($_GET['download'] ?? '') === '1';

$storage = new UserStorage($user->id);
$path = null;
$name = 'document.pdf';

if ($type === 'source') {
    $files = new SourceFiles($storage, new UploadRepository($storage));
    $record = $files->repository()->find($id);
    if ($record !== null) {
        $path = $files->path($id);
        $name = $download ? (string) ($record['original_name'] ?? 'document.pdf') : (string) ($record['title'] ?? 'document') . '.pdf';
    }
} elseif ($type === 'generated' && preg_match('/^gen_[a-f0-9]{12}$/', $id)) {
    $index = $storage->index('generated');
    $record = $index[$id] ?? null;
    if (is_array($record) && ($record['id'] ?? null) === $id) {
        $path = $storage->resolveFile($storage->generatedDir(), (string) ($record['stored_filename'] ?? ''));
        $name = (string) ($record['stored_filename'] ?? 'document.pdf');
    }
} elseif ($type === 'draft' && UploadRepository::isValidId($id) && (new UploadRepository($storage))->find($id) !== null) {
    $draft = GenerationService::draftPath($storage, $id);
    if (is_file($draft) && filesize($draft) > 0) {
        $path = $draft;
        $name = 'Draft preview.pdf';
    }
}

if ($path === null) {
    Logger::warning('files', 'Preview/download refused or file missing', ['user_id' => $user->id, 'type' => $type, 'id' => substr($id, 0, 40)]);
    http_response_code(404);
    View::render('errors/error', [
        'pageTitle' => 'File not found',
        'heading' => 'File not found.',
        'message' => 'This file does not exist or you do not have access to it.',
    ], 'bare');
    exit;
}

FileStreamer::pdf($path, $name, !$download);
