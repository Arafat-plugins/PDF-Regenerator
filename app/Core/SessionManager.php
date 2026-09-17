<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Secure session handling (Part C §11).
 *
 * - session files in storage/sessions/
 * - cookie: HttpOnly; SameSite=Lax; Secure only when HTTPS is detected
 * - strict mode, cookies only
 * - idle timeout (config session_timeout)
 * - id regeneration on login / password change
 */
final class SessionManager
{
    public const COOKIE_NAME = 'PDFREGSESSID';

    private static bool $expired = false;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $path = Config::storagePath('sessions');
        JsonStore::ensureDirectory($path);

        $timeout = (int) Config::get('session_timeout', 1800);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) max($timeout, 60));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        session_save_path($path);
        session_name(self::COOKIE_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (function_exists('base_path_url') ? base_path_url() : '') . '/', // only this app's folder (e.g. /pdf-tool/)
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        self::enforceIdleTimeout($timeout);
    }

    /**
     * Log a user in: new session id, store the INTERNAL user id only.
     */
    public static function login(string $userId): void
    {
        self::start();
        session_regenerate_id(true);

        $_SESSION = [
            'user_id' => $userId,
            'logged_in_at' => time(),
            'last_activity' => time(),
            'ua' => self::userAgentHash(),
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function userId(): ?string
    {
        $id = $_SESSION['user_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * One-time flash messages (shown once on the next page).
     */
    public static function flash(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;

            return null;
        }

        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);

        return is_string($value) ? $value : null;
    }

    /**
     * Destroy the session completely (data, cookie, server file).
     */
    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }

        $_SESSION = [];

        if (!headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(self::COOKIE_NAME, '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
    }

    /**
     * Release the session write lock so parallel requests (progress polling) are not blocked.
     */
    public static function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function wasExpired(): bool
    {
        return self::$expired;
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    private static function enforceIdleTimeout(int $timeout): void
    {
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        $last = (int) ($_SESSION['last_activity'] ?? 0);
        $uaMismatch = isset($_SESSION['ua']) && !hash_equals((string) $_SESSION['ua'], self::userAgentHash());

        if (($timeout > 0 && time() - $last > $timeout) || $uaMismatch) {
            self::$expired = !$uaMismatch;
            $_SESSION = [];
            session_regenerate_id(true);

            return;
        }

        $_SESSION['last_activity'] = time();
    }

    private static function userAgentHash(): string
    {
        return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }
}
