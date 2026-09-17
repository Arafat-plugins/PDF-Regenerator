<?php

declare(strict_types=1);

/*
 * Custom logo for one report (POST multipart, AJAX, JSON envelope) — Part C §12, Part D §6.
 *
 *   action=upload, id=src_xxxxxxxxxxxx, logo=<JPG/PNG/WebP/SVG ≤ max_logo_size>
 *
 * The file becomes this report's draft logo (storage/metadata/USR_xxx/drafts/{src}/) and is
 * moved into the website profile when the report is generated. "Remove logo" is the
 * remove_logo action of data.php. The answer carries the editor model, like data.php.
 */

use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Editor\EditorPresenter;
use App\Extraction\SourceDataRepository;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Profiles\Branding;
use App\Profiles\LogoStore;
use App\Profiles\ProfileException;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMax = ini_bytes((string) ini_get('post_max_size'));
if ($postMax > 0 && $contentLength > $postMax) {
    SecurityManager::requireCsrf();
    Response::json(false, 'This logo is too large. The maximum size is ' . format_bytes(LogoStore::maxSize()) . '.', [], ['too_large'], 413);
}

SecurityManager::requirePost();
SecurityManager::requireCsrf();
SessionManager::close();

if (($_POST['action'] ?? '') !== 'upload') {
    Response::json(false, 'Unknown action.', [], ['action'], 400);
}

$storage = new UserStorage($user->id);
$uploads = new UploadRepository($storage);
$id = $_POST['id'] ?? null;
if (!UploadRepository::isValidId($id) || ($record = $uploads->find($id)) === null) {
    Response::json(false, 'File not found.', [], ['not_found'], 404);
}
$id = (string) $id;
$data = new SourceDataRepository($storage);
if (!$data->exists($id)) {
    Response::json(false, 'This file has no extracted data yet. Please extract it first.', [], ['not_extracted'], 409);
}

$branding = new Branding($storage);
try {
    $branding->logos()->saveDraft($id, $_FILES['logo'] ?? null);
} catch (ProfileException $e) {
    Response::json(false, $e->getMessage(), ['field' => 'logo'], [$e->errorCode], $e->status);
}

$working = $data->updateWorking($id, static function (array $doc): array {
    $doc['logo_mode'] = 'draft';

    return $doc;
});
if ($working === null) {
    Response::json(false, 'This file has no extracted data yet. Please extract it first.', [], ['not_extracted'], 409);
}
if (($record['status'] ?? '') === 'extracted') {
    $uploads->setStatus($id, 'editing');
}

Response::json(true, 'Logo uploaded.', [
    'model' => EditorPresenter::model($working, null, $branding, $id),
    'result' => ['logo' => 'draft'],
    'saved_at' => date('Y-m-d H:i:s'),
]);
