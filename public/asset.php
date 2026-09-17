<?php

declare(strict_types=1);

/*
 * Authorised read-through endpoint for images (Part C §12).
 *
 *   asset.php?kind=thumb&t=<template-id>              → the template's preview.png (cover)
 *   asset.php?kind=full&t=<template-id>               → preview-full.png (all pages), else preview.png
 *   asset.php?kind=tpl&t=<template-id>&f=<asset>      → an image from the template's assets/ folder
 *
 * Templates live outside the web root; only a logged-in session is required for them.
 * No path is ever taken from the request: the template id is looked up among the
 * discovered templates and the asset name must resolve inside that template's assets/.
 *   asset.php?kind=logo&bp=<profile-id>                → a website profile's logo (owner only)
 *   asset.php?kind=logo&src=<source-id>                → the draft logo uploaded for one report (owner only)
 *
 * Logos: PNG / JPEG only, the ids are opaque and looked up in the current user's own storage.
 */

use App\Core\Logger;
use App\Core\SessionManager;
use App\Files\FileStreamer;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Profiles\BlueprintManager;
use App\Profiles\LogoStore;
use App\Templates\TemplateManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
SessionManager::close();

$kind = is_string($_GET['kind'] ?? null) ? $_GET['kind'] : '';
$templateId = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$file = is_string($_GET['f'] ?? null) ? $_GET['f'] : '';

$template = TemplateManager::isValidId($templateId) ? (new TemplateManager())->get($templateId) : null;
$path = null;
if ($kind === 'logo') {
    $storage = new UserStorage($user->id);
    $bp = $_GET['bp'] ?? null;
    $src = $_GET['src'] ?? null;
    if (BlueprintManager::isValidId($bp)) {
        $path = (new BlueprintManager($storage))->logoPath($bp);
    } elseif (UploadRepository::isValidId($src) && (new UploadRepository($storage))->find($src) !== null) {
        $path = (new LogoStore($storage))->draftPath((string) $src);
    }
} elseif ($template !== null && $kind === 'thumb') {
    $path = $template->previewFile();
} elseif ($template !== null && $kind === 'full') {
    $path = $template->fullPreviewFile() ?? $template->previewFile();
} elseif ($template !== null && $kind === 'tpl') {
    $path = $template->assetPath($file);
}

if ($path !== null && FileStreamer::image($path, $kind === 'tpl' || $kind === 'logo')) {
    exit;
}

Logger::warning('files', 'Image refused or missing', ['user_id' => $user->id, 'kind' => substr($kind, 0, 20), 'template' => substr($templateId, 0, 64), 'file' => substr($file, 0, 80)]);
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
echo 'Not found';
