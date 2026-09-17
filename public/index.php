<?php

declare(strict_types=1);

use App\Core\Response;

require dirname(__DIR__) . '/app/bootstrap.php';

Response::redirect(url(auth()->check() ? 'dashboard.php' : 'login.php'));
