<?php

declare(strict_types=1);

namespace App\Profiles;

use App\Core\Config;
use App\Core\JsonStore;
use App\Core\Logger;
use App\Files\UploadRepository;
use App\Files\UserStorage;

/**
 * Custom logos (Part C §12, Part D §6):
 *
 *   storage/metadata/USR_xxx/drafts/{src_id}/logo.{png|jpg}   uploaded for one job (temporary)
 *   storage/metadata/USR_xxx/assets/{bp_id}/logo.{png|jpg}    the website profile's logo
 *
 * JPG, PNG, WebP and SVG, max `max_logo_size`:
 *  - JPG / PNG: extension + MIME + getimagesize() must agree
 *  - WebP: checked the same way, then converted to PNG (transparency kept) — mPDF has no WebP alpha
 *  - SVG: parsed and rebuilt by SvgSanitizer (no scripts, events, external references, DOCTYPE)
 * The server always picks the file name. Template logos are never touched.
 */
final class LogoStore
{
    public const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];

    /** Stored logo file types (WebP is stored as PNG). */
    public const STORED = ['png', 'jpg', 'svg'];

    public const ACCEPT = 'image/png,image/jpeg,image/webp,image/svg+xml,.png,.jpg,.jpeg,.webp,.svg';
    public const LABEL = 'JPG, PNG, WebP or SVG';
    public const MAX_DIMENSION = 6000;

    public function __construct(private UserStorage $storage)
    {
    }

    public static function maxSize(): int
    {
        return (int) Config::get('max_logo_size', 2 * 1024 * 1024);
    }

    public function draftDir(string $sourceId): string
    {
        if (!UploadRepository::isValidId($sourceId)) {
            throw new \InvalidArgumentException('Invalid source id.');
        }

        return $this->storage->metadataDir() . DIRECTORY_SEPARATOR . 'drafts' . DIRECTORY_SEPARATOR . $sourceId;
    }

    public function draftPath(string $sourceId): ?string
    {
        return self::existing($this->draftDir($sourceId));
    }

    /** logo.png / logo.jpg / logo.svg inside $dir, or null. */
    public static function existing(string $dir): ?string
    {
        foreach (self::STORED as $ext) {
            $path = $dir . DIRECTORY_SEPARATOR . 'logo.' . $ext;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Validate an uploaded logo and store it as this job's draft logo.
     *
     * @param mixed $file one entry of $_FILES
     * @return string stored path
     * @throws ProfileException
     */
    public function saveDraft(string $sourceId, mixed $file, bool $viaHttp = true): string
    {
        try {
            [$ext, $content] = $this->prepare($file, $viaHttp);
        } catch (ProfileException $e) {
            Logger::warning('upload', 'Logo rejected', ['user_id' => $this->storage->userId(), 'source' => $sourceId, 'reason' => $e->errorCode]);
            throw $e;
        }

        $dir = $this->draftDir($sourceId);
        JsonStore::ensureDirectory($dir);
        $target = $dir . DIRECTORY_SEPARATOR . 'logo.' . $ext;
        $temp = $dir . DIRECTORY_SEPARATOR . '.upload_' . bin2hex(random_bytes(6));
        $tmp = (string) $file['tmp_name'];
        $ok = $content !== null
            ? @file_put_contents($temp, $content, LOCK_EX) === strlen($content)
            : ($viaHttp ? @move_uploaded_file($tmp, $temp) : @copy($tmp, $temp));
        if ($content !== null && $viaHttp) {
            @unlink($tmp);
        }
        if (!$ok) {
            @unlink($temp);
            throw new ProfileException('The logo could not be saved. Please try again.', 'store', 500);
        }
        @chmod($temp, 0640);
        foreach (self::STORED as $other) {
            @unlink($dir . DIRECTORY_SEPARATOR . 'logo.' . $other);
        }
        if (!@rename($temp, $target)) {
            @unlink($temp);
            throw new ProfileException('The logo could not be saved. Please try again.', 'store', 500);
        }
        clearstatcache(true, $target);
        Logger::info('upload', 'Draft logo stored', ['user_id' => $this->storage->userId(), 'source' => $sourceId, 'bytes' => filesize($target)]);

        return $target;
    }

    /**
     * @return string normalized stored extension (png|jpg|svg)
     * @throws ProfileException
     */
    public function validate(mixed $file, bool $viaHttp = true): string
    {
        return $this->prepare($file, $viaHttp)[0];
    }

    /**
     * Validate an upload and produce what will be stored.
     *
     * @return array{0: string, 1: ?string} stored extension, new file content (null = keep the uploaded bytes)
     * @throws ProfileException
     */
    private function prepare(mixed $file, bool $viaHttp): array
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name']) || is_array($file['error'])) {
            throw new ProfileException('Please choose one image file (' . self::LABEL . ').', 'no_file');
        }
        $max = self::maxSize();
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ProfileException('This logo is too large. The maximum size is ' . format_bytes($max) . '.', 'too_large');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new ProfileException('The logo could not be uploaded. Please try again.', 'upload_error');
        }
        $tmp = (string) $file['tmp_name'];
        if (($viaHttp && !is_uploaded_file($tmp)) || !is_file($tmp)) {
            throw new ProfileException('The logo could not be uploaded. Please try again.', 'not_uploaded', 400);
        }

        $name = basename(str_replace('\\', '/', (string) $file['name']));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext]) || preg_match('/\.(php\d*|phtml|phar|svgz|html?|xhtml|js)(\.|$)/i', $name)
            || preg_match('/\.svg\./i', $name)) {
            throw new ProfileException('Only ' . self::LABEL . ' logos are allowed.', 'type');
        }

        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw new ProfileException('The selected logo file is empty.', 'empty');
        }
        if ($size > $max) {
            throw new ProfileException('This logo is too large (' . format_bytes($size) . '). The maximum size is ' . format_bytes($max) . '.', 'too_large');
        }

        $expected = self::TYPES[$ext];
        $mime = '';
        if (class_exists(\finfo::class)) {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        }

        if ($ext === 'svg') {
            if ($mime !== '' && !in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true)) {
                throw new ProfileException('This file is not a valid SVG image.', 'signature');
            }
            $clean = SvgSanitizer::sanitize((string) file_get_contents($tmp));
            [$width, $height] = SvgSanitizer::dimensions($clean) ?? [0, 0];
            if ($width <= 0 || $height <= 0 || $width > 20000 || $height > 20000) {
                throw new ProfileException('The SVG logo has an invalid size.', 'dimensions');
            }

            return ['svg', $clean];
        }

        $info = @getimagesize($tmp);
        $label = strtoupper($ext === 'jpeg' ? 'jpg' : $ext);
        if ($info === false || ($info['mime'] ?? '') !== $expected || ($mime !== '' && $mime !== $expected)) {
            throw new ProfileException('This file is not a valid ' . $label . ' image.', 'signature');
        }
        [$width, $height] = $info;
        if ($width < 8 || $height < 8 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new ProfileException('The logo must be between 8 and ' . self::MAX_DIMENSION . ' pixels wide and high.', 'dimensions');
        }

        if ($ext === 'webp') {
            return ['png', self::webpToPng($tmp)];
        }

        return [$ext === 'png' ? 'png' : 'jpg', null];
    }

    /**
     * @throws ProfileException
     */
    private static function webpToPng(string $file): string
    {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagepng')) {
            throw new ProfileException('WebP logos need the GD WebP support on this server. Please upload a PNG instead.', 'webp_unsupported', 422);
        }
        $image = @imagecreatefromwebp($file);
        if ($image === false) {
            throw new ProfileException('This file is not a valid WEBP image.', 'signature');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        $ok = imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        if (!$ok || $png === '') {
            throw new ProfileException('The logo could not be converted. Please upload a PNG instead.', 'convert', 500);
        }

        return $png;
    }

    /**
     * Intrinsic size of a stored logo in px (raster or SVG), or null.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function dimensions(?string $file): ?array
    {
        if ($file === null || !is_file($file)) {
            return null;
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'svg') {
            return SvgSanitizer::dimensions((string) file_get_contents($file));
        }
        $info = @getimagesize($file);

        return $info !== false && $info[0] > 0 && $info[1] > 0 ? [(float) $info[0], (float) $info[1]] : null;
    }

    public function deleteDraft(string $sourceId): void
    {
        self::removeDirectory($this->draftDir($sourceId));
    }

    /**
     * Move this job's draft logo into a profile folder (replacing the old profile logo).
     *
     * @return string|null the new path
     */
    public function promoteDraft(string $sourceId, string $profileDir): ?string
    {
        $draft = $this->draftPath($sourceId);
        if ($draft === null) {
            return null;
        }
        JsonStore::ensureDirectory($profileDir);
        $target = $profileDir . DIRECTORY_SEPARATOR . basename($draft);
        $temp = $profileDir . DIRECTORY_SEPARATOR . '.move_' . bin2hex(random_bytes(6));
        if (!@copy($draft, $temp)) {
            Logger::error('files', 'Could not move the draft logo', ['user_id' => $this->storage->userId(), 'source' => $sourceId]);

            return null;
        }
        foreach (self::STORED as $ext) {
            @unlink($profileDir . DIRECTORY_SEPARATOR . 'logo.' . $ext);
        }
        if (!@rename($temp, $target)) {
            @unlink($temp);

            return null;
        }
        $this->deleteDraft($sourceId);

        return $target;
    }

    /**
     * Delete a logo folder (only logo files and temp files are expected inside).
     */
    public static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
