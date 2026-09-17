<?php

declare(strict_types=1);

/*
 * Generated Files (Part D §11, mockup 09_24_48): list (GET), rename / delete (POST, AJAX, JSON envelope).
 *
 *   downloads.php                       page
 *   downloads.php?new=gen_…             page with the "PDF Generated Successfully!" banner
 *   downloads.php?format=json           {items: [...]} for the Refresh button
 *   POST action=rename, id=gen_…, title=…
 *   POST action=delete, id=gen_…
 *
 * View / Download go through preview.php (owner check). Every action looks the id up in the
 * current user's own generated.json.
 */

use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Core\View;
use App\Files\GeneratedFileManager;
use App\Files\GeneratedPresenter;
use App\Files\UploadException;
use App\Files\UserStorage;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
$storage = new UserStorage($user->id);
$files = new GeneratedFileManager($storage);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    SecurityManager::requireCsrf();
    SessionManager::close();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $id = $_POST['id'] ?? null;

    if ($action === 'rename') {
        try {
            $entry = $files->rename($id, $_POST['title'] ?? '');
        } catch (UploadException $e) {
            Response::json(false, $e->getMessage(), [], [$e->reason], $e->status);
        }
        $entry['missing'] = $files->repository()->path($entry['id']) === null;
        Response::json(true, 'Title updated.', ['item' => GeneratedPresenter::row($entry)]);
    }
    if ($action === 'delete') {
        $removed = $files->delete($id);
        if ($removed === null) {
            Response::json(false, 'File not found.', [], ['not_found'], 404);
        }
        Response::json(true, 'File deleted.', ['id' => (string) $removed['id']]);
    }
    Response::json(false, 'Unknown action.', [], ['action'], 400);
}

$rows = array_map([GeneratedPresenter::class, 'row'], $files->all());

if (($_GET['format'] ?? '') === 'json') {
    SessionManager::close();
    header('Cache-Control: no-store');
    Response::json(true, 'OK', ['items' => $rows]);
}

// success banner after a generation (only for a file that exists and is recent)
$flash = null;
$new = $_GET['new'] ?? null;
foreach ($rows as $row) {
    if ($row['id'] === $new && strtotime($row['created_at']) >= time() - 3600) {
        $flash = $row;
    }
}

$templates = [];
foreach ($rows as $row) {
    $templates[$row['template']] = $row['template_name'];
}
asort($templates);

View::render('pages/downloads', [
    'pageTitle' => 'Generated Files',
    'activeNav' => 'downloads',
    'user' => $user,
    'rows' => $rows,
    'flash' => $flash,
    'templates' => $templates,
    'scripts' => ['downloads.js'],
]);
