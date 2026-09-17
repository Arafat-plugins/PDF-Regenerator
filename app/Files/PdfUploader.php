<?php

declare(strict_types=1);

namespace App\Files;

use App\Core\Config;
use App\Core\JsonStore;
use App\Core\Logger;
use App\Helpers\Text;
use App\Pdf\PdfInspector;
use App\Pdf\PdfRejectedException;

/**
 * Validates and stores an uploaded source PDF (Part C §12, Part D §3).
 *
 * Order: upload error code → size → file name / extension → real upload →
 * %PDF- signature → MIME type → PDF bomb guard → safe move → metadata.
 */
final class PdfUploader
{
    public const MSG_GENERIC = 'Unable to upload the PDF. Please check the file and try again.';

    private const ALLOWED_MIME = ['application/pdf', 'application/x-pdf', 'application/acrobat', 'applications/vnd.pdf', 'text/pdf'];
    private const BLOCKED_EXTENSION_SEGMENT = '/\.(php\d*|phtml|pht|phar|phps|inc|cgi|pl|py|rb|sh|bash|exe|com|bat|cmd|ps1|vbs|js|mjs|html?|xhtml|shtml|svgz?|asp|aspx|jsp|htaccess)(\.|$)/i';

    private int $maxSize;

    public function __construct(
        private UserStorage $storage,
        private UploadRepository $uploads,
        private PdfInspector $inspector,
        ?int $maxSize = null,
    ) {
        $this->maxSize = $maxSize ?? (int) Config::get('max_upload_size', 10 * 1024 * 1024);
    }

    /**
     * @param mixed $file one entry of $_FILES
     * @param bool $viaHttp false only for trusted local imports (CLI / tests)
     * @return array<string, mixed> the stored upload record
     */
    public function handle(mixed $file, bool $viaHttp = true): array
    {
        try {
            return $this->process($file, $viaHttp);
        } catch (UploadException $e) {
            Logger::warning('upload', 'Upload rejected: ' . $e->reason, [
                'user_id' => $this->storage->userId(),
                'name' => is_array($file) && is_string($file['name'] ?? null) ? Text::cleanFileName($file['name']) : '',
                'detail' => $e->detail,
            ]);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function process(mixed $file, bool $viaHttp): array
    {
        // ---- structure & PHP upload error code
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name']) || is_array($file['error'])) {
            throw new UploadException('Please choose one PDF file to upload.', 'no_file', 'missing or multiple files');
        }

        $error = (int) $file['error'];
        if ($error !== UPLOAD_ERR_OK) {
            throw $this->errorFromCode($error);
        }

        $tmp = (string) $file['tmp_name'];
        $originalName = Text::cleanFileName((string) $file['name']);

        if ($viaHttp && !is_uploaded_file($tmp)) {
            throw new UploadException(self::MSG_GENERIC, 'not_uploaded', 'is_uploaded_file failed', 400);
        }
        if (!is_file($tmp)) {
            throw new UploadException(self::MSG_GENERIC, 'missing_tmp', 'temporary file missing', 400);
        }

        // ---- size (measured, not trusted from the client)
        clearstatcache(false, $tmp);
        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw new UploadException('The selected file is empty.', 'empty', 'size 0');
        }
        if ($size > $this->maxSize) {
            throw new UploadException($this->tooLargeMessage(), 'too_large', "size {$size}", 413);
        }

        // ---- file name & extension
        $rawName = (string) $file['name'];
        if ($originalName === '' || str_contains($rawName, "\0")) {
            throw new UploadException('This file name is not allowed.', 'bad_name', 'empty or null byte');
        }
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new UploadException('Only PDF files are allowed.', 'bad_extension', 'extension');
        }
        if (preg_match(self::BLOCKED_EXTENSION_SEGMENT, (string) preg_replace('/\.pdf$/i', '', $originalName))) {
            throw new UploadException('This file name is not allowed. Please rename the file and try again.', 'bad_name', 'dangerous extension segment');
        }

        // ---- signature
        // Security software (e.g. Windows Defender) may lock a suspicious temp file: fail closed, no warning output.
        $handle = @fopen($tmp, 'rb');
        if ($handle === false) {
            throw new UploadException('This file could not be read. It may have been blocked by security software.', 'unreadable_tmp', 'fopen failed');
        }
        $head = (string) fread($handle, 1024);
        fclose($handle);
        if (!str_starts_with($head, '%PDF-')) {
            throw new UploadException('This file is not a valid PDF. Only PDF files are allowed.', 'bad_signature', 'magic bytes');
        }

        // ---- MIME type (content based)
        $mime = self::detectMime($tmp);
        if ($mime !== null && !in_array($mime, self::ALLOWED_MIME, true)) {
            throw new UploadException('This file is not a valid PDF. Only PDF files are allowed.', 'bad_mime', 'mime ' . $mime);
        }

        // ---- PDF bomb guard
        try {
            $inspection = $this->inspector->inspect($tmp);
        } catch (PdfRejectedException $e) {
            throw new UploadException($e->getMessage(), $e->reason, $e->detail);
        }

        // ---- store under a generated name, outside the web root
        $dir = $this->storage->uploadsDir();
        JsonStore::ensureDirectory($dir);

        do {
            $id = UploadRepository::newId();
            $target = $dir . DIRECTORY_SEPARATOR . $id . '.pdf';
        } while (file_exists($target));

        $moved = $viaHttp ? @move_uploaded_file($tmp, $target) : @copy($tmp, $target);
        if (!$moved) {
            throw new UploadException(self::MSG_GENERIC, 'store_failed', 'move failed', 500);
        }
        @chmod($target, 0640);

        $now = date('Y-m-d H:i:s');
        $record = [
            'id' => $id,
            'title' => Text::titleFromFileName($originalName),
            'original_name' => $originalName,
            'stored_filename' => $id . '.pdf',
            'size' => $size,
            'pages' => $inspection->pages,
            'text_bytes' => $inspection->textBytes,
            'has_text' => $inspection->hasText,
            'inspected_with' => $inspection->engine,
            'sha256' => hash_file('sha256', $target),
            'status' => 'uploaded',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            $record = $this->uploads->add($record);
        } catch (\Throwable $e) {
            @unlink($target);
            throw new UploadException(self::MSG_GENERIC, 'metadata_failed', $e->getMessage(), 500);
        }

        Logger::info('upload', 'PDF uploaded', [
            'user_id' => $this->storage->userId(),
            'id' => $id,
            'size' => $size,
            'pages' => $inspection->pages,
            'engine' => $inspection->engine,
        ]);

        return $record;
    }

    public static function detectMime(string $file): ?string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file);

            return is_string($mime) ? strtolower($mime) : null;
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($file);

            return is_string($mime) ? strtolower($mime) : null;
        }

        static $warned = false;
        if (!$warned) {
            Logger::warning('upload', 'fileinfo extension missing: MIME check skipped (signature check still applies)');
            $warned = true;
        }

        return null;
    }

    private function errorFromCode(int $code): UploadException
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => new UploadException($this->tooLargeMessage(), 'too_large', 'upload error ' . $code, 413),
            UPLOAD_ERR_PARTIAL => new UploadException('The upload was interrupted. Please try again.', 'partial', 'upload error 3'),
            UPLOAD_ERR_NO_FILE => new UploadException('Please choose a PDF file to upload.', 'no_file', 'upload error 4'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => new UploadException(self::MSG_GENERIC, 'server', 'upload error ' . $code, 500),
            default => new UploadException(self::MSG_GENERIC, 'unknown', 'upload error ' . $code),
        };
    }

    private function tooLargeMessage(): string
    {
        return 'This file is too large. The maximum size is ' . format_bytes($this->maxSize) . '.';
    }
}
