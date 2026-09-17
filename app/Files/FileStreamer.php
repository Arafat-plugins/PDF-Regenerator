<?php

declare(strict_types=1);

namespace App\Files;

use App\Helpers\Text;

/**
 * Streams already-authorised files to the browser (Part C §12): PDFs and images.
 */
final class FileStreamer
{
    public static function pdf(string $path, string $downloadName, bool $inline): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $size = (int) filesize($path);
        $ascii = Text::readableFileName((string) preg_replace('/\.pdf$/i', '', $downloadName), 'document') . '.pdf';
        $utf8 = rawurlencode(Text::cleanTitle((string) preg_replace('/\.pdf$/i', '', $downloadName)) . '.pdf');

        // A PDF is not an HTML page: drop the page CSP (it can stop the browser's PDF viewer).
        header_remove('Content-Security-Policy');
        header('Content-Type: application/pdf');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $utf8);
        header('Content-Length: ' . $size);
        header('Cache-Control: private, no-store, max-age=0');
        header('Accept-Ranges: none');
        header('Cross-Origin-Resource-Policy: same-origin');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
            exit;
        }

        $handle = fopen($path, 'rb');
        if ($handle !== false) {
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        }
        exit;
    }

    public const IMAGE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];

    /**
     * Stream an image after checking that its bytes really are that type.
     * SVG (template artwork only) is sent with a sandboxing CSP, so it can never run script.
     *
     * @return bool false when the file is not an allowed image (nothing was sent)
     */
    public static function image(string $path, bool $allowSvg = false): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::IMAGE_TYPES[$ext] ?? null;
        if ($mime === null || !is_file($path) || ($ext === 'svg' && !$allowSvg)) {
            return false;
        }
        if ($ext === 'svg') {
            $head = (string) file_get_contents($path, false, null, 0, 1024);
            if (!preg_match('/<svg[\s>]/i', $head)) {
                return false;
            }
        } else {
            $info = @getimagesize($path);
            if ($info === false || ($info['mime'] ?? '') !== $mime) {
                return false;
            }
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $size = (int) filesize($path);
        $etag = '"' . substr(md5($path . '|' . filemtime($path) . '|' . $size), 0, 20) . '"';
        header_remove('Content-Security-Policy');
        if ($ext === 'svg') {
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        }
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('ETag: ' . $etag);
        header('Cross-Origin-Resource-Policy: same-origin');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . $size);
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            readfile($path);
        }
        exit;
    }
}
