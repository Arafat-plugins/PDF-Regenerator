<?php

declare(strict_types=1);

/*
 * Upload page (GET) and upload/rename/delete actions (POST, AJAX, JSON envelope).
 */

use App\Core\Logger;
use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Core\View;
use App\Files\PdfUploader;
use App\Files\SourceFiles;
use App\Files\UploadException;
use App\Files\UploadPresenter;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Pdf\PdfInspector;
use App\Profiles\Branding;
use App\Templates\TemplateManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
$storage = new UserStorage($user->id);
$uploads = new UploadRepository($storage);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    // A body larger than post_max_size arrives with empty $_POST/$_FILES.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMax = ini_bytes((string) ini_get('post_max_size'));
    if ($postMax > 0 && $contentLength > $postMax) {
        SecurityManager::requireCsrf(); // header token still works
        Logger::warning('upload', 'Upload rejected: request larger than post_max_size', ['user_id' => $user->id, 'bytes' => $contentLength]);
        Response::json(false, 'This file is too large. The maximum size is ' . format_bytes((int) config('max_upload_size')) . '.', [], ['too_large'], 413);
    }

    SecurityManager::requireCsrf();
    SessionManager::close(); // long request: do not block other tabs

    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $files = new SourceFiles($storage, $uploads);

    switch ($action) {
        case 'upload':
            try {
                $uploader = new PdfUploader($storage, $uploads, new PdfInspector());
                $record = $uploader->handle($_FILES['pdf'] ?? null);
            } catch (UploadException $e) {
                Response::json(false, $e->getMessage(), [], [$e->reason], $e->status);
            } catch (\Throwable $e) {
                Logger::error('upload', 'Upload failed unexpectedly', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                Response::json(false, PdfUploader::MSG_GENERIC, [], ['server'], 500);
            }
            $row = UploadPresenter::row($record);
            $message = $record['has_text']
                ? 'PDF uploaded successfully.'
                : 'PDF uploaded. It does not seem to contain readable text — OCR may be required to extract its information.';
            Response::json(true, $message, ['item' => $row], [], 201);

        case 'rename':
            $title = is_string($_POST['title'] ?? null) ? $_POST['title'] : '';
            if (trim($title) === '') {
                Response::json(false, 'Please enter a title.', [], ['title'], 422);
            }
            $record = $files->rename($_POST['id'] ?? null, $title);
            if ($record === null) {
                Response::json(false, 'File not found.', [], ['not_found'], 404);
            }
            Response::json(true, 'Title updated.', ['item' => UploadPresenter::row($record)]);

        case 'delete':
            $record = $files->delete($_POST['id'] ?? null);
            if ($record === null) {
                Response::json(false, 'File not found.', [], ['not_found'], 404);
            }
            Response::json(true, 'File deleted.', ['id' => (string) $record['id']]);

        default:
            Response::json(false, 'Unknown action.', [], ['action'], 400);
    }
}

$rows = array_map([UploadPresenter::class, 'row'], $uploads->all());
$profiles = (new Branding($storage))->chooserList(new TemplateManager());

View::render('pages/upload', [
    'pageTitle' => 'Upload PDF',
    'activeNav' => 'upload',
    'user' => $user,
    'rows' => $rows,
    'maxSize' => (int) config('max_upload_size'),
    'profiles' => $profiles,
    'scripts' => ['profile-chooser.js', 'upload.js'],
]);
