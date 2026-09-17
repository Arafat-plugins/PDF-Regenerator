<?php

declare(strict_types=1);

namespace App\Core;

/**
 * CSRF protection and server-side login lockout (Part B §5, Part C §11).
 *
 * Lockout state lives in storage/logs/login-attempts.json — never in the session.
 * Keys are SHA-256 hashes, so the file contains no usernames or IP addresses in clear text.
 *
 * Two counters are kept:
 *   - "u:" username + IP  → login_max_attempts (5) failures → login_lockout_seconds (15 min)
 *   - "ip:" IP only       → login_max_attempts_per_ip (20) failures → same lockout (stops username spraying)
 */
final class SecurityManager
{
    public const CSRF_FIELD = '_csrf';
    public const CSRF_HEADER = 'HTTP_X_CSRF_TOKEN';

    /** @var (callable(): int)|null test hook for the clock */
    private static $clock = null;

    // ------------------------------------------------------------------ CSRF

    public static function csrfToken(): string
    {
        $token = $_SESSION['csrf_token'] ?? null;

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['csrf_token'] = $token;
        }

        return $token;
    }

    public static function verifyCsrf(?string $token): bool
    {
        $expected = $_SESSION['csrf_token'] ?? null;

        return is_string($expected) && $expected !== ''
            && is_string($token) && $token !== ''
            && hash_equals($expected, $token);
    }

    /**
     * Token from the POST field or the X-CSRF-Token header.
     */
    public static function requestCsrfToken(): ?string
    {
        $token = $_POST[self::CSRF_FIELD] ?? ($_SERVER[self::CSRF_HEADER] ?? null);

        return is_string($token) ? $token : null;
    }

    /**
     * Abort the request when the CSRF token is missing or wrong.
     */
    public static function requireCsrf(): void
    {
        if (self::verifyCsrf(self::requestCsrfToken())) {
            return;
        }

        Logger::warning('security', 'CSRF token rejected', ['uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''), 'ip' => self::clientIp()]);

        if (Response::wantsJson()) {
            Response::json(false, 'Your session has expired. Please refresh the page and try again.', [], ['csrf'], 419);
        }

        http_response_code(419);
        View::render('errors/error', [
            'pageTitle' => 'Session expired',
            'heading' => 'Your session has expired.',
            'message' => 'Please go back, refresh the page and try again.',
        ], 'bare');
        exit;
    }

    public static function requirePost(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            if (Response::wantsJson()) {
                Response::json(false, 'Method not allowed.', [], ['method'], 405);
            }
            header('Allow: POST');
            http_response_code(405);
            exit;
        }
    }

    // --------------------------------------------------------------- Lockout

    /**
     * Seconds until the username/IP pair may try again (0 = not locked).
     */
    public static function lockoutRemaining(string $username, string $ip): int
    {
        $attempts = JsonStore::read(self::attemptsFile());
        $now = self::now();
        $remaining = 0;

        foreach ([self::userKey($username, $ip), self::ipKey($ip)] as $key) {
            $until = (int) ($attempts[$key]['locked_until'] ?? 0);
            if ($until > $now) {
                $remaining = max($remaining, $until - $now);
            }
        }

        return $remaining;
    }

    /**
     * Record a failed login. Returns true when this failure triggered a lockout.
     */
    public static function recordFailedLogin(string $username, string $ip): bool
    {
        $now = self::now();
        $window = (int) Config::get('login_lockout_seconds', 900);
        $limits = [
            self::userKey($username, $ip) => (int) Config::get('login_max_attempts', 5),
            self::ipKey($ip) => (int) Config::get('login_max_attempts_per_ip', 20),
        ];
        $locked = false;

        JsonStore::update(self::attemptsFile(), static function (array $attempts) use ($limits, $now, $window, &$locked): array {
            $attempts = self::prune($attempts, $now, $window);

            foreach ($limits as $key => $max) {
                $entry = $attempts[$key] ?? ['count' => 0, 'first_failed_at' => $now, 'last_failed_at' => $now, 'locked_until' => 0];

                // A new counting window starts after an expired lockout or a long quiet period.
                if ((int) $entry['locked_until'] > 0 && (int) $entry['locked_until'] <= $now) {
                    $entry = ['count' => 0, 'first_failed_at' => $now, 'last_failed_at' => $now, 'locked_until' => 0];
                }

                $entry['count'] = (int) $entry['count'] + 1;
                $entry['last_failed_at'] = $now;

                if ($entry['count'] >= $max && (int) $entry['locked_until'] <= $now) {
                    $entry['locked_until'] = $now + $window;
                    $locked = true;
                }

                $attempts[$key] = $entry;
            }

            return $attempts;
        });

        return $locked;
    }

    public static function clearFailedLogins(string $username, string $ip): void
    {
        $key = self::userKey($username, $ip);
        $now = self::now();
        $window = (int) Config::get('login_lockout_seconds', 900);

        JsonStore::update(self::attemptsFile(), static function (array $attempts) use ($key, $now, $window): array {
            unset($attempts[$key]);

            return self::prune($attempts, $now, $window);
        });
    }

    public static function attemptsFile(): string
    {
        return Config::storagePath('logs' . DIRECTORY_SEPARATOR . 'login-attempts.json');
    }

    public static function clientIp(): string
    {
        // REMOTE_ADDR only: forwarded headers are client-controlled and would let an attacker dodge the lockout.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    /**
     * @param (callable(): int)|null $clock
     */
    public static function setClock(?callable $clock): void
    {
        self::$clock = $clock;
    }

    private static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    private static function userKey(string $username, string $ip): string
    {
        return 'u:' . hash('sha256', self::normalizeUsername($username) . '|' . $ip);
    }

    private static function ipKey(string $ip): string
    {
        return 'ip:' . hash('sha256', $ip);
    }

    public static function normalizeUsername(string $username): string
    {
        $username = trim($username);

        return function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    /**
     * Drop entries that are neither locked nor inside the counting window.
     *
     * @param array<string, mixed> $attempts
     * @return array<string, mixed>
     */
    private static function prune(array $attempts, int $now, int $window): array
    {
        foreach ($attempts as $key => $entry) {
            $lockedUntil = (int) ($entry['locked_until'] ?? 0);
            $last = (int) ($entry['last_failed_at'] ?? 0);

            if ($lockedUntil <= $now && $now - $last > $window) {
                unset($attempts[$key]);
            }
        }

        return $attempts;
    }
}
