<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\SecurityManager;

/*
 * Global view/helper functions. Everything printed into HTML goes through e().
 */

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        if ($value === null || is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('base_path_url')) {
    /**
     * URL prefix of the public/ directory ('' when public/ is the document root,
     * '/sub/dir' when the app is served from a sub-folder).
     */
    function base_path_url(): string
    {
        static $base = null;

        if ($base === null) {
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            $base = ($dir === '.' || $dir === '/') ? '' : $dir;
            // Whole project uploaded into a folder (root .htaccess serves public/): the visitor asked for
            // /folder/login.php, PHP runs /folder/public/login.php — keep "/public" out of the links.
            $request = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if (str_ends_with($base, '/public') && $request !== '' && !str_starts_with($request, $base . '/')) {
                $outer = substr($base, 0, -strlen('/public'));
                if ($outer === '' || str_starts_with($request, $outer . '/')) {
                    $base = $outer;
                }
            }
        }

        return $base;
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return base_path_url() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * Public asset URL with a cache-busting version.
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = dirname(__DIR__, 2) . '/public/assets/' . $path;
        $version = is_file($file) ? (string) filemtime($file) : '1';

        return url('assets/' . $path) . '?v=' . $version;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return SecurityManager::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="' . SecurityManager::CSRF_FIELD . '" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('icon')) {
    /**
     * Inline Lucide SVG icon (decorative by default).
     */
    function icon(string $name, string $class = '', int $size = 20, ?string $label = null): string
    {
        static $icons = null;
        $icons ??= require __DIR__ . '/icons.php';

        $inner = $icons[$name] ?? '';
        $aria = $label === null ? 'aria-hidden="true" focusable="false"' : 'role="img" aria-label="' . e($label) . '"';

        return sprintf(
            '<svg class="icon %s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" %s>%s</svg>',
            e($class),
            $size,
            $size,
            $aria,
            $inner
        );
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(string|int|null $value): string
    {
        $ts = is_int($value) ? $value : ($value ? strtotime($value) : false);

        return $ts ? date((string) config('date_format', 'M d, Y h:i A'), $ts) : '';
    }
}

if (!function_exists('format_date')) {
    function format_date(string|int|null $value): string
    {
        $ts = is_int($value) ? $value : ($value ? strtotime($value) : false);

        return $ts ? date((string) config('display_date_format', 'M d, Y'), $ts) : '';
    }
}

if (!function_exists('format_bytes')) {
    function format_bytes(int|float $bytes, int $precision = 1): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max(0, (float) $bytes);
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        $formatted = $i === 0 ? (string) (int) $bytes : rtrim(rtrim(number_format($bytes, $precision, '.', ''), '0'), '.');

        return $formatted . ' ' . $units[$i];
    }
}

if (!function_exists('is_active_nav')) {
    function is_active_nav(string $key, string $active): string
    {
        return $key === $active ? ' is-active' : '';
    }
}

if (!function_exists('partial')) {
    /**
     * Render app/Views/partials/{name}.php and return the HTML.
     *
     * @param array<string, mixed> $data
     */
    function partial(string $name, array $data = []): string
    {
        return \App\Core\View::partial($name, $data);
    }
}

if (!function_exists('ini_bytes')) {
    /**
     * Convert php.ini shorthand ("8M", "2G") to bytes.
     */
    function ini_bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
