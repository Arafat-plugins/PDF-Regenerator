<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP helpers: the standard JSON envelope (Part B §10), redirects and security headers.
 */
final class Response
{
    /**
     * Send the standard envelope and stop.
     *
     * @param array<mixed> $data
     * @param array<mixed> $errors
     */
    public static function json(bool $ok, string $message = '', array $data = [], array $errors = [], int $status = 200): never
    {
        self::discardOutput();

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode(
            self::envelope($ok, $message, $data, $errors),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        exit;
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $errors
     * @return array{ok: bool, message: string, data: object|array<mixed>, errors: array<mixed>}
     */
    public static function envelope(bool $ok, string $message = '', array $data = [], array $errors = []): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'data' => $data === [] ? new \stdClass() : $data,
            'errors' => array_values($errors),
        ];
    }

    public static function redirect(string $url, int $status = 302): never
    {
        self::discardOutput();
        header('Location: ' . $url, true, $status);
        exit;
    }

    public static function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; font-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
    }

    public static function noCache(): void
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, private');
            header('Pragma: no-cache');
        }
    }

    public static function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

        return $xhr === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    private static function discardOutput(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
