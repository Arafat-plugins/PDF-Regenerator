<?php

declare(strict_types=1);

/*
 * Live HTML preview of the chosen template with the user's working data (Part D §6).
 * Loaded in a sandboxed <iframe> on the Review & Edit page.
 *
 *   live-preview.php?id=src_xxxxxxxxxxxx[&v=cache-buster]
 *
 * The page contains no script; every value is escaped by the template engine.
 * The generated PDF remains the source of truth ("Preview as PDF" renders the real thing).
 */

use App\Core\Logger;
use App\Core\SessionManager;
use App\Extraction\SourceDataRepository;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Generation\ReportComposer;
use App\Profiles\Branding;
use App\Templates\Template;
use App\Templates\TemplateManager;
use App\Templates\TemplateRenderer;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
SessionManager::close();

$send = static function (int $status, string $html): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; font-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    header('Referrer-Policy: same-origin');
    echo $html;
    exit;
};
$message = static fn (string $text): string => '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{margin:0;display:grid;place-items:center;height:100vh;font:15px system-ui,sans-serif;color:#5f6f86;background:#eef1f5}</style></head><body><p>'
    . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></body></html>';

$id = $_GET['id'] ?? null;
$storage = new UserStorage($user->id);
if (!UploadRepository::isValidId($id) || (new UploadRepository($storage))->find($id) === null) {
    $send(404, $message('File not found.'));
}
$document = (new SourceDataRepository($storage))->working((string) $id);
if ($document === null) {
    $send(409, $message('Extract the PDF first to see the preview.'));
}

$templates = new TemplateManager();
$template = $templates->get(is_string($document['template'] ?? null) ? $document['template'] : null)
    ?? $templates->suggest(is_string($document['source_profile'] ?? null) ? $document['source_profile'] : null);
if ($template === null) {
    $send(404, $message('No templates available.'));
}

try {
    $composed = (new ReportComposer())->compose(
        $document,
        $template,
        TemplateRenderer::PREVIEW,
        static fn (Template $t, string $file): string => url('asset.php?kind=tpl&t=' . rawurlencode($t->id) . '&f=' . rawurlencode($file)),
        asset('fonts/inter-latin-wght-normal.woff2'),
        (static function () use ($storage, $document, $template, $id): array {
            $logo = (new Branding($storage))->logo($document, $template, (string) $id);
            $logo['url'] = $logo['url'] !== '' ? url($logo['url']) : '';

            return $logo;
        })(),
    );
} catch (Throwable $e) {
    Logger::error('preview', 'Live preview failed', ['user_id' => $user->id, 'source' => (string) $id, 'template' => $template->id, 'detail' => get_class($e) . ': ' . $e->getMessage()]);
    $send(500, $message('The preview could not be shown. You can still generate the PDF.'));
}

$send(200, $composed['html']);
