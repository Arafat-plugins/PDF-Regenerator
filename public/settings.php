<?php

declare(strict_types=1);

/*
 * Settings (Part B §2): read-only account info, change own password, read-only system check.
 *
 *   POST action=password, current_password, new_password, confirm_password (+ CSRF)
 *
 * On success the session id is regenerated and other sessions of the account are signed out.
 */

use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Core\SystemCheck;
use App\Core\View;

require dirname(__DIR__) . '/app/bootstrap.php';

$user = auth()->requireAuth();
$errors = [];
$message = null;
$status = 200;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    SecurityManager::requireCsrf();
    if (($_POST['action'] ?? '') !== 'password') {
        if (Response::wantsJson()) {
            Response::json(false, 'Unknown action.', [], ['action'], 400);
        }
        Response::redirect(url('settings.php'));
    }

    $result = auth()->changePassword($user, $_POST['current_password'] ?? '', $_POST['new_password'] ?? '', $_POST['confirm_password'] ?? '', SecurityManager::clientIp());
    if (Response::wantsJson()) {
        Response::json($result['ok'], $result['message'], ['errors' => (object) $result['errors']], $result['ok'] ? [] : ['invalid'], $result['ok'] ? 200 : 422);
    }
    if ($result['ok']) {
        SessionManager::flash('success', $result['message']);
        Response::redirect(url('settings.php') . '#password');
    }
    $errors = $result['errors'];
    $message = $result['message'];
    $status = 422;
}

http_response_code($status);
$checks = SystemCheck::run();

View::render('pages/settings', [
    'pageTitle' => 'Settings',
    'activeNav' => 'settings',
    'user' => $user,
    'errors' => $errors,
    'errorMessage' => $message,
    'checks' => $checks,
    'engine' => \App\Pdf\PdfInspector::availableEngine(),
    'scripts' => ['settings.js'],
]);
