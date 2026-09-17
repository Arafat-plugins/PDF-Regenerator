<?php

declare(strict_types=1);

/*
 * Application bootstrap — included by every entry script in public/ and bin/.
 */

use App\Auth\AuthManager;
use App\Auth\CsvUserRepository;
use App\Core\Config;
use App\Core\Logger;
use App\Core\Response;
use App\Core\SessionManager;
use App\Core\View;

define('APP_ROOT', dirname(__DIR__));

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('PDF Regenerator requires PHP 8.1 or newer.');
}

// ---------------------------------------------------------------- autoload
$composer = APP_ROOT . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;
} else {
    // Fallback PSR-4 loader so the app shell runs before `composer install`.
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
    require APP_ROOT . '/app/Helpers/functions.php';
}

// ------------------------------------------------------------------ config
$config = require APP_ROOT . '/config/app.php';

// Test harness overrides (only honoured when explicitly running the test suite).
if (getenv('PDFREG_ENV') === 'testing' && ($testDir = getenv('PDFREG_TEST_DIR'))) {
    $config['storage_path'] = $testDir . '/storage';
    $config['users_csv'] = $testDir . '/users.csv';
    $config['mpdf_temp_path'] = $testDir . '/storage/tmp/mpdf';
    if (($timeout = getenv('PDFREG_SESSION_TIMEOUT')) !== false) {
        $config['session_timeout'] = (int) $timeout;
    }
    if (($pdftotext = getenv('PDFREG_PDFTOTEXT')) !== false) {
        $config['pdftotext_path'] = $pdftotext === 'none' ? null : $pdftotext;
    }
}

// 'auto' → first pdftotext found in the usual places (null = pure-PHP reader). Errors from
// open_basedir restrictions on shared hosting are suppressed: nothing found simply means null.
if (($config['pdftotext_path'] ?? null) === 'auto') {
    $config['pdftotext_path'] = null;
    if (function_exists('proc_open')) {
        $candidates = DIRECTORY_SEPARATOR === '\\'
            ? ['C:\\poppler\\Library\\bin\\pdftotext.exe', 'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe']
            : ['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext', '/bin/pdftotext'];
        foreach ($candidates as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                $config['pdftotext_path'] = $candidate;
                break;
            }
        }
    }
}

Config::load($config);
date_default_timezone_set((string) Config::get('timezone', 'UTC'));
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// ---------------------------------------------------------- error handling
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    Logger::error('php', $message, ['file' => str_replace(APP_ROOT, '', $file), 'line' => $line, 'severity' => $severity]);

    return true; // never print PHP warnings
});

set_exception_handler(static function (Throwable $e): void {
    Logger::error('app', 'Uncaught exception: ' . $e->getMessage(), [
        'type' => get_class($e),
        'file' => str_replace(APP_ROOT, '', $e->getFile()),
        'line' => $e->getLine(),
    ]);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Error: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    if (Response::wantsJson()) {
        Response::json(false, 'Something went wrong. Please try again.', [], ['server'], 500);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    try {
        View::render('errors/error', [
            'pageTitle' => 'Something went wrong',
            'heading' => 'Something went wrong.',
            'message' => 'Please try again. If the problem continues, contact the administrator.',
        ], 'bare');
    } catch (Throwable) {
        echo 'Something went wrong. Please try again.';
    }
});

/**
 * Shared services.
 */
function auth(): AuthManager
{
    static $auth = null;

    return $auth ??= new AuthManager(new CsvUserRepository((string) Config::get('users_csv')));
}

// ------------------------------------------------------------ web requests
if (PHP_SAPI !== 'cli') {
    // First-run guard: a clear message instead of a blank error when the host is not ready.
    $problems = [];
    foreach (['mbstring', 'gd', 'zlib', 'iconv', 'json', 'session', 'ctype'] as $ext) {
        if (!extension_loaded($ext)) {
            $problems[] = 'PHP extension "' . $ext . '" is not enabled (cPanel → Select PHP Version → Extensions).';
        }
    }
    if (!is_file(APP_ROOT . '/vendor/autoload.php')) {
        $problems[] = 'The vendor/ folder is missing. Upload the complete deploy package again.';
    }
    $storage = Config::storagePath();
    if (!is_dir($storage) && !@mkdir($storage, 0775, true)) {
        $problems[] = 'The storage/ folder is missing and cannot be created.';
    } elseif (!is_writable($storage)) {
        $problems[] = 'PHP cannot write to the storage/ folder (File Manager → storage → Permissions 755, owner = your cPanel user).';
    }
    if ($problems !== []) {
        http_response_code(503);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Setup needed</title><body style="font-family:system-ui,sans-serif;max-width:640px;margin:48px auto;padding:0 16px;color:#1e293b">'
            . '<h1 style="font-size:22px">PDF Regenerator: one more setup step</h1><ul style="line-height:1.6">';
        foreach ($problems as $problem) {
            echo '<li>' . htmlspecialchars($problem, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul><p>Reload this page after fixing it.</p></body>';
        exit;
    }

    SessionManager::start();
    Response::sendSecurityHeaders();

    if (getenv('PDFREG_ENV') !== 'testing' || getenv('PDFREG_AUTO_CLEANUP') === '1') {
        \App\Files\AutoCleanup::schedule();
    }
}
