<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Minimal PHP view renderer for the application UI (not the PDF template engine).
 *
 * Views live in app/Views and are plain PHP; every dynamic value is printed with e().
 */
final class View
{
    private static function viewPath(string $name): string
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#i', $name) || str_contains($name, '..')) {
            throw new RuntimeException('Invalid view name');
        }

        $file = dirname(__DIR__) . '/Views/' . $name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('View not found: ' . $name);
        }

        return $file;
    }

    /**
     * Render a page inside a layout ("app" = sidebar shell, "auth" = login, "bare" = minimal).
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = [], string $layout = 'app'): void
    {
        $content = self::fetch($view, $data);

        if ($layout === '') {
            echo $content;

            return;
        }

        Response::sendSecurityHeaders();
        Response::noCache();
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        echo self::fetch('layouts/' . $layout, $data + ['content' => $content]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fetch(string $view, array $data = []): string
    {
        $__file = self::viewPath($view);

        ob_start();
        try {
            (static function (string $__file, array $__data): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($__file, $data);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Render a partial (no layout) and return it.
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $view, array $data = []): string
    {
        return self::fetch('partials/' . $view, $data);
    }
}
