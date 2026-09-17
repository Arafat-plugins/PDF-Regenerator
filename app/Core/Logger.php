<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Simple file logger writing to storage/logs/{channel}-{Y-m-d}.log (Part C §16).
 *
 * Channels used by the app: auth, upload, extraction, generation, files, storage, app.
 * Sensitive context keys (passwords, tokens, hashes) are always redacted.
 */
final class Logger
{
    private const SENSITIVE = ['password', 'pass', 'pwd', 'new_password', 'current_password', 'confirm_password',
        'password_hash', 'token', 'csrf', '_csrf', 'secret', 'session_id'];

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write('INFO', $channel, $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write('WARNING', $channel, $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write('ERROR', $channel, $message, $context);
    }

    public static function write(string $level, string $channel, string $message, array $context = []): void
    {
        $channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?: 'app';
        $dir = Config::storagePath('logs');

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s.%s: %s%s\n",
            date('Y-m-d H:i:s'),
            $channel,
            $level,
            self::oneLine($message),
            $context === [] ? '' : ' ' . json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        @file_put_contents($dir . DIRECTORY_SEPARATOR . $channel . '-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            } elseif (is_string($value)) {
                $context[$key] = self::oneLine($value);
            }
        }

        return $context;
    }

    private static function oneLine(string $value): string
    {
        // Prevent log injection: no raw newlines/control chars in a log entry.
        $value = function_exists('mb_substr') ? mb_substr($value, 0, 2000) : substr($value, 0, 2000);

        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    }
}

