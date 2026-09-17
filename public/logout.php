<?php

declare(strict_types=1);

use App\Core\Response;
use App\Core\SecurityManager;

require dirname(__DIR__) . '/app/bootstrap.php';

$auth = auth();

// Logout is a state change: POST + CSRF only. A plain GET just goes back.
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    Response::redirect(url($auth->check() ? 'dashboard.php' : 'login.php'));
}

if (!$auth->check()) {
    Response::redirect(url('login.php'));
}

SecurityManager::requireCsrf();

$auth->logout();

Response::redirect(url('login.php?logged_out=1'));
