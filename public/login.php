<?php

declare(strict_types=1);

use App\Auth\AuthManager;
use App\Auth\LoginResult;
use App\Core\Response;
use App\Core\SecurityManager;
use App\Core\SessionManager;
use App\Core\View;

require dirname(__DIR__) . '/app/bootstrap.php';

$auth = auth();

if ($auth->check()) {
    Response::redirect(url('dashboard.php'));
}

$username = '';
$notice = null;

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (!SecurityManager::verifyCsrf(SecurityManager::requestCsrfToken())) {
        \App\Core\Logger::warning('security', 'CSRF token rejected on login', ['ip' => SecurityManager::clientIp()]);
        http_response_code(419);
        $notice = [
            'type' => 'warning',
            'title' => 'Your session has expired.',
            'body' => 'Please try logging in again.',
        ];
    } else {
        $result = $auth->attempt($username, $password, SecurityManager::clientIp());
        unset($password);

        if ($result->ok) {
            Response::redirect(url('dashboard.php'));
        }

        http_response_code($result->code === LoginResult::LOCKED ? 429 : 401);
        if ($result->code === LoginResult::LOCKED && $result->retryAfter > 0) {
            header('Retry-After: ' . $result->retryAfter);
        }

        $notice = match ($result->code) {
            LoginResult::LOCKED => ['type' => 'warning', 'title' => $result->message, 'body' => 'For your security, logins for this account are paused.', 'contact' => false],
            LoginResult::EMPTY => ['type' => 'error', 'title' => $result->message],
            default => ['type' => 'error', 'title' => AuthManager::MSG_INVALID, 'contact' => true],
        };
    }
} elseif (isset($_GET['expired'])) {
    $minutes = intdiv((int) config('session_timeout', 1800), 60);
    $notice = [
        'type' => 'info',
        'title' => 'Your session has expired.',
        'body' => $minutes >= 1
            ? sprintf('For your security you were signed out after %d minute%s of inactivity. Please log in again.', $minutes, $minutes === 1 ? '' : 's')
            : 'For your security you were signed out after a period of inactivity. Please log in again.',
    ];
} elseif (isset($_GET['logged_out'])) {
    $notice = ['type' => 'success', 'title' => 'You have been logged out.', 'body' => 'See you next time.'];
}

View::render('pages/login', [
    'pageTitle' => 'Login',
    'username' => $username,
    'notice' => $notice,
], 'auth');
