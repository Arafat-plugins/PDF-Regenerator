<?php

declare(strict_types=1);

namespace App\Core;

use App\Pdf\PdfInspector;

/**
 * Read-only environment report (Part B §4a). Used by bin/system-check.php and,
 * from Phase 8, by the Settings page.
 */
final class SystemCheck
{
    /**
     * @return list<array{label: string, status: 'ok'|'warn'|'fail', detail: string}>
     */
    public static function run(): array
    {
        $rows = [];

        $rows[] = self::row('PHP version', PHP_VERSION_ID >= 80100 ? 'ok' : 'fail', PHP_VERSION . ' (8.1+ required)');

        foreach ([
            'mbstring' => ['fail', 'needed by mPDF'],
            'gd' => ['fail', 'PageSpeed ring & images'],
            'fileinfo' => ['warn', 'MIME check of uploads'],
            'zlib' => ['fail', 'PDF streams'],
            'iconv' => ['fail', 'PDF text decoding'],
            'intl' => ['warn', 'Unicode normalisation of extracted text'],
            'json' => ['fail', ''],
            'session' => ['fail', ''],
            'ctype' => ['fail', ''],
        ] as $ext => [$severity, $why]) {
            $loaded = extension_loaded($ext);
            $detail = $loaded ? 'loaded' : 'missing' . ($why !== '' ? ' - ' . $why : '');
            if ($ext === 'gd' && $loaded) {
                $info = function_exists('gd_info') ? gd_info() : [];
                $detail .= !empty($info['FreeType Support']) ? ' (FreeType yes)' : ' (FreeType missing)';
            }
            $rows[] = self::row('ext-' . $ext, $loaded ? 'ok' : $severity, $detail);
        }

        $vendor = is_file(APP_ROOT . '/vendor/autoload.php');
        $rows[] = self::row('Libraries (vendor/)', $vendor ? 'ok' : 'warn', $vendor
            ? 'smalot/pdfparser ' . (class_exists(\Smalot\PdfParser\Parser::class) ? 'yes' : 'no') . ', mPDF ' . (class_exists(\Mpdf\Mpdf::class) ? 'yes' : 'no')
            : 'not installed - run composer install or unpack vendor-bundle.zip');

        $engine = PdfInspector::availableEngine();
        $rows[] = self::row('PDF engine', $engine === 'basic' ? 'warn' : 'ok', match ($engine) {
            'poppler' => 'poppler (pdftotext) - preferred',
            'smalot' => 'smalot (pure PHP) - ' . (Config::get('pdftotext_path') ? 'poppler not usable at ' . Config::get('pdftotext_path') : 'poppler not installed (optional)'),
            default => 'basic only - install vendor/ or poppler',
        });
        $fonts = \App\Pdf\PdfGenerator::interInstalled();
        $rows[] = self::row('PDF fonts', $fonts ? 'ok' : 'warn', $fonts ? 'Inter (resources/fonts)' : 'Inter missing - DejaVu Sans is used instead');
        $templates = count((new \App\Templates\TemplateManager())->all());
        $rows[] = self::row('Stock templates', $templates > 0 ? 'ok' : 'fail', $templates . ' found in stock-templates/');
        $profiles = count((new \App\Extraction\SourceProfileManager())->all());
        $rows[] = self::row('Source profiles', $profiles > 0 ? 'ok' : 'warn', $profiles . ' found in source-profiles/');
        $rows[] = self::row('proc_open', function_exists('proc_open') ? 'ok' : 'warn', function_exists('proc_open') ? 'enabled' : 'disabled (poppler cannot be used)');

        foreach (['storage', 'storage/uploads', 'storage/generated', 'storage/metadata', 'storage/sessions', 'storage/logs', 'storage/tmp', 'storage/tmp/mpdf'] as $dir) {
            $path = $dir === 'storage' ? Config::storagePath() : Config::storagePath(substr($dir, 8));
            if (is_dir($path)) {
                $ok = is_writable($path);
                $rows[] = self::row($dir . '/', $ok ? 'ok' : 'fail', $ok ? 'writable' : 'not writable');
                continue;
            }
            // not there yet: fine when the nearest existing parent is writable (created on first use)
            $parent = dirname($path);
            while (!is_dir($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }
            $ok = is_writable($parent);
            $rows[] = self::row($dir . '/', $ok ? 'ok' : 'fail', $ok ? 'created on first use' : 'missing and cannot be created');
        }

        $csv = (string) Config::get('users_csv');
        $rows[] = self::row('config/users.csv', is_file($csv) && is_writable($csv) ? 'ok' : (is_file($csv) ? 'warn' : 'fail'),
            is_file($csv) ? (is_writable($csv) ? 'present, writable' : 'present, read-only (password change will fail)') : 'missing - run bin/create-user.php');

        $upload = ini_bytes((string) ini_get('upload_max_filesize'));
        $post = ini_bytes((string) ini_get('post_max_size'));
        $max = (int) Config::get('max_upload_size');
        $rows[] = self::row('upload_max_filesize', $upload >= $max ? 'ok' : 'fail', (string) ini_get('upload_max_filesize') . ' (needs at least ' . format_bytes($max) . ')');
        $rows[] = self::row('post_max_size', $post > $max ? 'ok' : 'fail', (string) ini_get('post_max_size') . ' (needs > ' . format_bytes($max) . ')');

        $memory = (string) ini_get('memory_limit');
        $memOk = $memory === '-1' || ini_bytes($memory) >= 256 * 1024 * 1024;
        $rows[] = self::row('memory_limit', $memOk ? 'ok' : 'warn', $memory . ' (256M+ recommended)');

        $time = (int) ini_get('max_execution_time');
        $rows[] = self::row('max_execution_time', $time === 0 || $time >= 120 ? 'ok' : 'warn', ($time === 0 ? 'unlimited' : $time . ' s') . ' (120 s+ recommended)');

        return $rows;
    }

    /**
     * @return array{label: string, status: 'ok'|'warn'|'fail', detail: string}
     */
    private static function row(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
