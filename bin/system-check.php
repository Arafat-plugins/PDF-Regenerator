<?php

declare(strict_types=1);

/*
 * Print the environment report (PHP version, extensions, engines, permissions, limits).
 *
 *   php bin/system-check.php
 */

use App\Core\SystemCheck;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$marks = ['ok' => '[ OK ]', 'warn' => '[WARN]', 'fail' => '[FAIL]'];
$failed = 0;

fwrite(STDOUT, PHP_EOL . '  System check' . PHP_EOL);
foreach (SystemCheck::run() as $row) {
    if ($row['status'] === 'fail') {
        $failed++;
    }
    fwrite(STDOUT, sprintf("  %s %-22s %s\n", $marks[$row['status']], $row['label'], $row['detail']));
}
fwrite(STDOUT, PHP_EOL);

exit($failed > 0 ? 1 : 0);
