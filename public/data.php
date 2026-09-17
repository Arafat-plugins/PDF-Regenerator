<?php

declare(strict_types=1);

/*
 * Review & Edit autosave endpoint (POST, AJAX, JSON envelope).
 *
 * Body: JSON {id, action, …} (or form fields). CSRF token in X-CSRF-Token.
 *
 *   action=load                      → current editor model
 *   action=reset                     → original extraction copied back into the working copy
 *   action=<DocumentEditor::ACTIONS> → one edit, applied atomically to the working copy
 *   action=set_template / set_mapping → template choice and field mapping (TemplateChoice)
 *   action=<BrandingChoice::ACTIONS>  → colors, logo, website profile (BrandingChoice)
 *
 * Every successful response carries the full editor model, so the page always shows
 * what is stored (including recalculated values and the review counter).
 */

use App\Core\Logger;
use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Editor\BrandingChoice;
use App\Editor\DocumentEditor;
use App\Editor\EditorException;
use App\Editor\EditorPresenter;
use App\Editor\TemplateChoice;
use App\Extraction\SourceDataRepository;
use App\Files\UploadRepository;
use App\Files\UserStorage;
use App\Profiles\Branding;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
SecurityManager::requirePost();
SecurityManager::requireCsrf();
SessionManager::close();

$input = $_POST;
$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (str_starts_with($contentType, 'application/json')) {
    $raw = (string) file_get_contents('php://input', false, null, 0, 262145);
    if (strlen($raw) > 262144) {
        Response::json(false, 'This change is too large.', [], ['too_large'], 413);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        Response::json(false, 'The change could not be read. Please try again.', [], ['bad_request'], 400);
    }
    $input = $decoded;
}

$id = $input['id'] ?? null;
$action = is_string($input['action'] ?? null) ? $input['action'] : '';
$isTemplateAction = in_array($action, TemplateChoice::ACTIONS, true);
$isBrandingAction = in_array($action, BrandingChoice::ACTIONS, true);
if ($action !== 'load' && $action !== 'reset' && !$isTemplateAction && !$isBrandingAction && !in_array($action, DocumentEditor::ACTIONS, true)) {
    Response::json(false, 'Unknown action.', [], ['action'], 400);
}

$storage = new UserStorage($user->id);
$uploads = new UploadRepository($storage);
$record = UploadRepository::isValidId($id) ? $uploads->find($id) : null;
if ($record === null) {
    Response::json(false, 'File not found.', [], ['not_found'], 404);
}
$id = (string) $id;
$data = new SourceDataRepository($storage);
if (!$data->exists($id)) {
    Response::json(false, 'This file has no extracted data yet. Please extract it first.', [], ['not_extracted'], 409);
}

$branding = new Branding($storage);
$respond = static function (array $working, string $message, array $result = []) use ($branding, $id): never {
    Response::json(true, $message, [
        'model' => EditorPresenter::model($working, null, $branding, $id),
        'result' => $result === [] ? new stdClass() : $result,
        'saved_at' => date('Y-m-d H:i:s'),
    ]);
};

if ($action === 'load') {
    $working = $data->working($id);
    if ($working === null) {
        Response::json(false, 'This file has no extracted data yet. Please extract it first.', [], ['not_extracted'], 409);
    }
    $respond($working, 'Loaded.');
}

if ($action === 'reset') {
    $working = $data->reset($id);
    $original = $data->original($id);
    if ($working !== null && $original !== null && ($working['template'] ?? null) !== ($original['template'] ?? null)) {
        // the chosen template stays: give the restored data that template's texts again
        $working = $data->updateWorking($id, static function (array $doc): array {
            try {
                return (new TemplateChoice())->apply($doc, 'set_template', ['template' => $doc['template'] ?? ''])['doc'];
            } catch (EditorException) {
                return $doc; // template no longer installed
            }
        });
    }
    if ($working === null) {
        Response::json(false, 'The original data could not be restored. Please extract the PDF again.', [], ['reset'], 409);
    }
    if (($record['status'] ?? '') === 'editing') {
        $uploads->setStatus($id, 'extracted');
    }
    Logger::info('editor', 'Data reset to the extraction', ['user_id' => $user->id, 'source' => $id]);
    $respond($working, 'The original extracted data has been restored.');
}

$result = [];
try {
    $brandingChoice = new BrandingChoice($storage, $id);
    $working = $data->updateWorking($id, static function (array $doc) use ($action, $input, $isTemplateAction, $isBrandingAction, $brandingChoice, &$result): array {
        $applied = match (true) {
            $isTemplateAction => (new TemplateChoice())->apply($doc, $action, $input),
            $isBrandingAction => $brandingChoice->apply($doc, $action, $input),
            default => DocumentEditor::apply($doc, $action, $input),
        };
        $result = $applied['result'];

        return $applied['doc'];
    });
} catch (EditorException $e) {
    Response::json(false, $e->getMessage(), ['field' => $e->field], [$e->errorCode], $e->status());
}

if ($working === null) {
    Response::json(false, 'This file has no extracted data yet. Please extract it first.', [], ['not_extracted'], 409);
}
if (($record['status'] ?? '') === 'extracted') {
    $uploads->setStatus($id, 'editing');
}

$messages = [
    'remove' => 'Moved to Removed.',
    'list_remove' => 'Row moved to Removed.',
    'restore' => 'Restored.',
    'list_restore' => 'Row restored.',
    'add_field' => 'Field added.',
    'list_add' => 'Row added.',
    'set_template' => 'Template changed.',
    'set_mapping' => 'Field mapping saved.',
    'set_palette' => 'Colors changed.',
    'set_custom_colors' => 'Custom colors saved.',
    'remove_logo' => 'Logo removed — the template logo is used.',
    'restore_logo' => 'The profile logo is used again.',
    'apply_profile' => 'Website profile applied.',
    'set_new_website' => 'This report is now for a new website.',
    'dismiss_profile_hint' => 'Saved.',
];
$respond($working, $messages[$action] ?? 'Saved.', $result);
