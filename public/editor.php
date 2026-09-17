<?php

declare(strict_types=1);

/*
 * Preview & Edit Data page (Part D §6, mockup 09_23_35): extracted data editor with
 * autosave (data.php), template fields (mapping), template choice, live preview
 * (live-preview.php), "Preview as PDF" (draft.php), generated PDFs and Generate PDF.
 *
 *   editor.php?id=src_xxxxxxxxxxxx
 */

use App\Core\View;
use App\Data\DocumentPresenter;
use App\Editor\EditorPresenter;
use App\Extraction\SourceDataRepository;
use App\Files\GeneratedPresenter;
use App\Files\GeneratedRepository;
use App\Files\UploadPresenter;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Profiles\Branding;
use App\Templates\PaletteManager;
use App\Templates\TemplateManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
$storage = new UserStorage($user->id);
$id = $_GET['id'] ?? null;
$record = (new UploadRepository($storage))->find($id);

if ($record === null) {
    http_response_code(404);
    View::render('errors/error', [
        'pageTitle' => 'File not found',
        'heading' => 'File not found.',
        'message' => 'This file does not exist or you do not have access to it.',
    ], 'bare');
    exit;
}

$document = (new SourceDataRepository($storage))->working((string) $id);
$templates = new TemplateManager();
$branding = new Branding($storage);
$template = null;
$palette = null;
$theme = null;
if ($document !== null) {
    $template = $templates->get(is_string($document['template'] ?? null) ? $document['template'] : null)
        ?? $templates->suggest(is_string($document['source_profile'] ?? null) ? $document['source_profile'] : null);
    if ($template !== null) {
        $paletteId = is_string($document['palette'] ?? null) ? $document['palette'] : $template->defaultPalette();
        $palette = $template->palettes[(string) $paletteId] ?? null;
        $theme = PaletteManager::theme($template, $paletteId, is_array($document['custom_colors'] ?? null) ? $document['custom_colors'] : null);
    }
}

View::render('pages/editor', [
    'pageTitle' => 'Preview & Edit Data',
    'activeNav' => 'upload',
    'user' => $user,
    'source' => UploadPresenter::row($record),
    'doc' => $document !== null ? DocumentPresenter::present($document) : null,
    'model' => $document !== null ? EditorPresenter::model($document, $templates, $branding, (string) $id) : null,
    'profiles' => $branding->chooserList($templates),
    'templates' => $templates->all(),
    'template' => $template,
    'palette' => $palette,
    'theme' => $theme,
    'generated' => array_map([GeneratedPresenter::class, 'row'], (new GeneratedRepository($storage))->forSource((string) $id)),
    'scripts' => ['profile-chooser.js', 'generation.js', 'editor.js', 'preview.js', 'branding.js'],
]);
