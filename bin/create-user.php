<?php

declare(strict_types=1);

/*
 * Create a PDF Regenerator user account (CLI only).
 *
 *   php bin/create-user.php
 *
 * Asks for Username / Password / Name / Email, enforces a minimum password
 * length of 10, generates the internal user id (USR_xxxxxxxx), hashes the
 * password with password_hash() and appends the row to config/users.csv
 * under an exclusive file lock. No plain-text password is ever stored.
 */

use App\Auth\CsvUserRepository;
use App\Core\Config;
use App\Core\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

const MIN_PASSWORD_LENGTH = 10;

$in = STDIN;
$interactive = function_exists('stream_isatty') ? stream_isatty($in) : true;

function out(string $text = ''): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

function fail(string $text): never
{
    fwrite(STDERR, "\n  ✗ " . $text . PHP_EOL . PHP_EOL);
    exit(1);
}

function ask(string $label, bool $interactive): string
{
    fwrite(STDOUT, '  ' . $label . ': ');
    $line = fgets(STDIN);
    if ($line === false) {
        fail('Input ended unexpectedly.');
    }

    return trim($line);
}

function askSecret(string $label, bool $interactive): string
{
    fwrite(STDOUT, '  ' . $label . ': ');

    $canHide = $interactive && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec');
    if ($canHide) {
        @shell_exec('stty -echo 2>/dev/null');
    }

    $line = fgets(STDIN);

    if ($canHide) {
        @shell_exec('stty echo 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    if ($line === false) {
        fail('Input ended unexpectedly.');
    }

    return rtrim($line, "\r\n");
}

$csv = (string) Config::get('users_csv');
$repo = new CsvUserRepository($csv);

out();
out('  PDF Regenerator — create user');
out('  ─────────────────────────────');
out('  Users file: ' . $csv);
if ($interactive && DIRECTORY_SEPARATOR === '\\') {
    out('  Note: on Windows the password is visible while you type.');
}
out();

$username = ask('Username', $interactive);
if (!CsvUserRepository::isValidUsername($username)) {
    fail('Username must be 3–32 characters: letters, numbers, dot, dash or underscore.');
}
if ($repo->findByUsername($username) !== null) {
    fail('That username already exists.');
}

$password = askSecret('Password (min. ' . MIN_PASSWORD_LENGTH . ' characters)', $interactive);
$length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
if ($length < MIN_PASSWORD_LENGTH) {
    fail('Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters.');
}
if (strlen($password) > 4096) {
    fail('Password is too long.');
}

$confirm = askSecret('Confirm password', $interactive);
if (!hash_equals($password, $confirm)) {
    fail('Passwords do not match.');
}

$name = ask('Full name', $interactive);
if ($name === '' || strlen($name) > 120) {
    fail('Please enter a name (max. 120 characters).');
}

$email = ask('Email', $interactive);
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fail('Please enter a valid email address.');
}

// Make sure the file exists with a header before the locked append.
if (!is_file($csv)) {
    if (@file_put_contents($csv, implode(',', CsvUserRepository::HEADER) . "\n", LOCK_EX) === false) {
        fail('Cannot create ' . $csv . ' — check folder permissions.');
    }
}

try {
    $user = $repo->create($username, password_hash($password, PASSWORD_DEFAULT), $name, $email);
} catch (Throwable $e) {
    fail($e->getMessage());
}

unset($password, $confirm);
Logger::info('auth', 'User created via CLI', ['user_id' => $user->id]);

out();
out('  ✓ User created');
out('    ID:       ' . $user->id);
out('    Username: ' . $user->username);
out('    Name:     ' . $user->name);
out('    Email:    ' . $user->email);
out();
