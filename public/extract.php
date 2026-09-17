<?php

declare(strict_types=1);

/*
 * Start data extraction for one of the current user's uploads (POST, AJAX, JSON envelope).
 *
 *   action=extract, id=src_xxxxxxxxxxxx
 *   profile_mode=existing, profile=bp_xxxxxxxxxxxx      use a saved website profile
 *   profile_mode=new, website_name=…, website_url=…     a new website (profile saved on first generation)
 *   profile_mode=keep                                   extract again, keep profile / template / colors
 *   (no profile_mode: no website profile)
 */

use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Editor\EditorException;
use App\Editor\FieldValidator;
use App\Extraction\ExtractionService;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Pdf\Extract\ExtractionException;
use App\Profiles\BlueprintManager;
use App\Profiles\ProfileException;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
SecurityManager::requirePost();
SecurityManager::requireCsrf();
SessionManager::close(); // long-running: never block other requests of this user

$id = $_POST['id'] ?? null;
if (($_POST['action'] ?? '') !== 'extract') {
    Response::json(false, 'Unknown action.', [], ['action'], 400);
}
if (!UploadRepository::isValidId($id)) {
    Response::json(false, 'File not found.', [], ['not_found'], 404);
}

$storage = new UserStorage($user->id);
if ((new UploadRepository($storage))->find($id) === null) {
    Response::json(false, 'File not found.', [], ['not_found'], 404);
}

$choice = null;
$mode = is_string($_POST['profile_mode'] ?? null) ? $_POST['profile_mode'] : '';
$profiles = new BlueprintManager($storage);
if ($mode === 'existing') {
    if ($profiles->find($_POST['profile'] ?? null) === null) {
        Response::json(false, 'This website profile no longer exists. Please choose another one.', ['field' => 'profile'], ['profile'], 422);
    }
    $choice = ['mode' => 'existing', 'id' => (string) $_POST['profile']];
} elseif ($mode === 'new') {
    $name = BlueprintManager::cleanName($_POST['website_name'] ?? '');
    try {
        $profiles->assertNameAvailable($name);
        $url = FieldValidator::scalar('website_url', $_POST['website_url'] ?? '');
    } catch (ProfileException $e) {
        Response::json(false, $e->getMessage(), ['field' => 'website_name'], ['profile'], 422);
    } catch (EditorException $e) {
        Response::json(false, $e->getMessage(), ['field' => 'website_url'], ['profile'], 422);
    }
    $choice = ['mode' => 'new', 'name' => $name, 'url' => $url];
} elseif ($mode === 'keep') {
    $choice = ['mode' => 'keep'];
} elseif ($mode !== '') {
    Response::json(false, 'Please choose a website profile.', ['field' => 'profile'], ['profile'], 422);
}

@set_time_limit(120);

try {
    $document = (new ExtractionService($storage))->extract((string) $id, $choice);
} catch (ExtractionException $e) {
    Response::json(false, $e->getMessage(), [], ['extraction'], 422);
}

$review = 0;
foreach ($document['fields'] as $row) {
    $review += !empty($row['needs_review']) ? 1 : 0;
}
foreach ($document['lists'] as $rows) {
    foreach ($rows as $row) {
        $review += !empty($row['needs_review']) ? 1 : 0;
    }
}

$message = $document['scanned']
    ? 'This PDF does not contain readable text. OCR may be required to extract its information.'
    : 'Data extracted.';

Response::json(true, $message, [
    'id' => $id,
    'redirect' => url('editor.php?id=' . rawurlencode((string) $id)),
    'scanned' => $document['scanned'],
    'profile' => $document['source_profile'],
    'fields' => count($document['fields']),
    'review' => $review,
]);
