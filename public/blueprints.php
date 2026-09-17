<?php

declare(strict_types=1);

/*
 * Website profiles (Part D §14) — AJAX, JSON envelope, session + CSRF, owner only.
 *
 *   GET  blueprints.php                         → {profiles: [...]}
 *   POST action=rename, id=bp_…, name=…         → {profile, profiles}
 *   POST action=delete, id=bp_…                 → {id, profiles}  (generated PDFs are kept)
 *
 * Profiles are created by the first successful generation of a "new website" report.
 */

use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Files\UserStorage;
use App\Profiles\BlueprintManager;
use App\Profiles\Branding;
use App\Profiles\ProfileException;
use App\Templates\TemplateManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
$storage = new UserStorage($user->id);
$branding = new Branding($storage);
$profiles = $branding->profiles();
$list = static function () use ($branding): array {
    return array_map(static function (array $p): array {
        $p['logo_url'] = $p['logo_url'] !== '' ? url($p['logo_url']) : '';

        return $p;
    }, $branding->chooserList(new TemplateManager()));
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    SessionManager::close();
    header('Cache-Control: no-store');
    Response::json(true, 'Loaded.', ['profiles' => $list()]);
}

SecurityManager::requireCsrf();
SessionManager::close();

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$id = $_POST['id'] ?? null;
if (!in_array($action, ['rename', 'delete'], true)) {
    Response::json(false, 'Unknown action.', [], ['action'], 400);
}
if (!BlueprintManager::isValidId($id) || $profiles->find($id) === null) {
    Response::json(false, 'This website profile no longer exists.', [], ['not_found'], 404);
}

if ($action === 'rename') {
    try {
        $profile = $profiles->rename((string) $id, $_POST['name'] ?? '');
    } catch (ProfileException $e) {
        Response::json(false, $e->getMessage(), ['field' => 'name'], [$e->errorCode], $e->status);
    }
    Response::json(true, 'Profile renamed to “' . $profile['name'] . '”.', ['profile' => ['id' => $profile['id'], 'name' => $profile['name']], 'profiles' => $list()]);
}

$removed = $profiles->delete((string) $id);
if ($removed === null) {
    Response::json(false, 'This website profile no longer exists.', [], ['not_found'], 404);
}
Response::json(true, 'Profile “' . $removed['name'] . '” deleted. Your generated PDFs were kept.', ['id' => (string) $id, 'profiles' => $list()]);
