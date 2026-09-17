<?php

declare(strict_types=1);

namespace App\Pdf;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Process;
use Smalot\PdfParser\Config as SmalotConfig;
use Smalot\PdfParser\Parser;

/**
 * PDF bomb guard (Part C §12): counts pages and measures the extractable text
 * before a PDF is accepted.
 *
 *   1. raw scan (cheap, always)   → encryption marker, page-count estimate
 *   2. poppler (pdfinfo/pdftotext) when configured and runnable
 *   3. smalot/pdfparser otherwise (pure PHP)
 *
 * Rejects: > max_pdf_pages pages, > max_extracted_text_bytes of text,
 * password-protected or unreadable files.
 */
final class PdfInspector
{
    public const ENGINE_POPPLER = 'poppler';
    public const ENGINE_SMALOT = 'smalot';
    public const ENGINE_BASIC = 'basic';

    private int $maxPages;
    private int $maxTextBytes;
    private ?string $pdftotext;

    public function __construct(?int $maxPages = null, ?int $maxTextBytes = null, ?string $pdftotextPath = null, private ?string $forceEngine = null)
    {
        $this->maxPages = $maxPages ?? (int) Config::get('max_pdf_pages', 50);
        $this->maxTextBytes = $maxTextBytes ?? (int) Config::get('max_extracted_text_bytes', 5 * 1024 * 1024);
        $this->pdftotext = $pdftotextPath ?? (Config::get('pdftotext_path') ?: null);
    }

    public static function availableEngine(?string $pdftotextPath = null): string
    {
        $path = $pdftotextPath ?? (Config::get('pdftotext_path') ?: null);
        if (self::popplerUsable($path)) {
            return self::ENGINE_POPPLER;
        }

        return class_exists(Parser::class) ? self::ENGINE_SMALOT : self::ENGINE_BASIC;
    }

    public function inspect(string $file): PdfInspection
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new PdfRejectedException(PdfRejectedException::UNREADABLE, self::msgUnreadable(), 'file missing');
        }

        @set_time_limit(120);
        $this->raiseMemoryLimit();

        $raw = (string) file_get_contents($file);
        $rawPages = self::rawPageEstimate($raw);

        if (self::looksEncrypted($raw)) {
            throw new PdfRejectedException(
                PdfRejectedException::ENCRYPTED,
                'This PDF is password-protected. Please upload an unprotected copy.',
                'encrypt dictionary present'
            );
        }

        // Cheap early exit before any heavy parsing.
        if ($rawPages > $this->maxPages) {
            $this->rejectPages($rawPages, 'raw');
        }

        $engine = $this->forceEngine ?? self::availableEngine($this->pdftotext);
        unset($raw);

        return match ($engine) {
            self::ENGINE_POPPLER => $this->inspectWithPoppler($file),
            self::ENGINE_SMALOT => $this->inspectWithSmalot($file),
            default => $this->inspectBasic($file, $rawPages),
        };
    }

    // ------------------------------------------------------------ engines

    private function inspectWithPoppler(string $file): PdfInspection
    {
        $pdfinfo = self::siblingTool((string) $this->pdftotext, 'pdfinfo');

        $pages = null;
        if (Process::isExecutable($pdfinfo)) {
            $info = Process::run([$pdfinfo, $file], 20, 1024 * 1024);
            if ($info['exit'] === 0 && preg_match('/^Pages:\s+(\d+)/m', $info['stdout'], $m)) {
                $pages = (int) $m[1];
            } elseif (stripos($info['stdout'], 'Encrypted:') !== false && preg_match('/^Encrypted:\s+yes/mi', $info['stdout'])) {
                throw new PdfRejectedException(PdfRejectedException::ENCRYPTED, 'This PDF is password-protected. Please upload an unprotected copy.', 'pdfinfo encrypted');
            }
        }

        if ($pages === null) {
            // Fall back to smalot for the page count only.
            return $this->inspectWithSmalot($file);
        }

        if ($pages < 1) {
            throw new PdfRejectedException(PdfRejectedException::UNREADABLE, self::msgUnreadable(), 'pdfinfo reported 0 pages');
        }
        if ($pages > $this->maxPages) {
            $this->rejectPages($pages, 'pdfinfo');
        }

        $text = Process::run([(string) $this->pdftotext, '-q', '-enc', 'UTF-8', $file, '-'], 30, $this->maxTextBytes);
        if ($text['timed_out']) {
            throw new PdfRejectedException(PdfRejectedException::UNREADABLE, 'This PDF took too long to read. Please try another file.', 'pdftotext timeout');
        }
        if ($text['truncated']) {
            $this->rejectText($text['stdout_bytes'], 'pdftotext');
        }
        if ($text['exit'] !== 0) {
            throw new PdfRejectedException(PdfRejectedException::UNREADABLE, self::msgUnreadable(), 'pdftotext exit ' . $text['exit']);
        }

        return new PdfInspection($pages, $text['stdout_bytes'], self::meaningfulText($text['stdout']), self::ENGINE_POPPLER);
    }

    private function inspectWithSmalot(string $file): PdfInspection
    {
        if (!class_exists(Parser::class)) {
            return $this->inspectBasic($file, self::rawPageEstimate((string) file_get_contents($file)));
        }

        $config = new SmalotConfig();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        try {
            $document = (new Parser([], $config))->parseFile($file);
            $pages = $document->getPages();
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'secured') !== false || stripos($e->getMessage(), 'encrypt') !== false) {
                throw new PdfRejectedException(PdfRejectedException::ENCRYPTED, 'This PDF is password-protected. Please upload an unprotected copy.', $e->getMessage());
            }
            throw new PdfRejectedException(PdfRejectedException::UNREADABLE, self::msgUnreadable(), 'smalot: ' . $e->getMessage());
        }

        $count = count($pages);
        if ($count < 1) {
            throw new PdfRejectedException(PdfRejectedException::UNREADABLE, self::msgUnreadable(), 'smalot found 0 pages');
        }
        if ($count > $this->maxPages) {
            $this->rejectPages($count, 'smalot');
        }

        // 1) Cheap estimate from the decoded content streams (linear time).
        //    smalot's getText() is quadratic in the number of text operators, so a
        //    crafted page could otherwise tie up the server for minutes.
        $estimate = 0;
        $cost = 0.0;
        foreach ($pages as $page) {
            [$bytes, $ops] = self::textStats(self::pageContent($page));
            $estimate += $bytes;
            $cost += ($ops / 1000) ** 2;
            if ($estimate > $this->maxTextBytes) {
                $this->rejectText($estimate, 'smalot-estimate');
            }
        }
        try {
            foreach ($document->getObjectsByType('XObject', 'Form') as $form) {
                [$bytes, $ops] = self::textStats((string) $form->getContent());
                $estimate += $bytes;
                $cost += ($ops / 1000) ** 2;
            }
        } catch (\Throwable) {
            // forms are optional
        }
        if ($estimate > $this->maxTextBytes) {
            $this->rejectText($estimate, 'smalot-estimate');
        }

        // 2) Exact measurement when it is affordable (cost 250 ≈ 8 s in smalot).
        if ($cost > 250) {
            Logger::info('upload', 'Text size estimated (document too complex for a full text pass)', ['estimate' => $estimate, 'cost' => round($cost)]);

            return new PdfInspection($count, $estimate, $estimate >= 20, self::ENGINE_SMALOT);
        }

        $bytes = 0;
        $sample = '';
        foreach ($pages as $page) {
            try {
                $text = $page->getText();
            } catch (\Throwable $e) {
                Logger::warning('upload', 'Page text could not be read during inspection', ['error' => $e->getMessage()]);
                continue;
            }
            $bytes += strlen($text);
            if (strlen($sample) < 20000) {
                $sample .= $text;
            }
            if ($bytes > $this->maxTextBytes) {
                $this->rejectText($bytes, 'smalot');
            }
        }

        return new PdfInspection($count, $bytes, self::meaningfulText($sample), self::ENGINE_SMALOT);
    }

    /**
     * Decoded content stream(s) of a page (same assembly rules as smalot's Page::getText()).
     */
    private static function pageContent(\Smalot\PdfParser\Page $page): string
    {
        try {
            $contents = $page->get('Contents');
            if ($contents instanceof \Smalot\PdfParser\Element\ElementArray) {
                $out = '';
                foreach ($contents->getContent() as $part) {
                    $out .= (is_object($part) && method_exists($part, 'getContent') ? (string) $part->getContent() : '') . "\n";
                }

                return $out;
            }
            if ($contents instanceof \Smalot\PdfParser\PDFObject) {
                $elements = $contents->getHeader()->getElements();
                if (is_numeric(key($elements))) {
                    $out = '';
                    foreach ($elements as $element) {
                        $out .= $element instanceof \Smalot\PdfParser\Element\ElementXRef
                            ? (string) $element->getObject()->getContent()
                            : (string) $element->getContent();
                    }

                    return $out;
                }

                return (string) $contents->getContent();
            }
        } catch (\Throwable) {
            return '';
        }

        return '';
    }

    /**
     * [bytes of string operands, number of text-showing operators] of a content stream.
     *
     * @return array{0: int, 1: int}
     */
    public static function textStats(string $content): array
    {
        if ($content === '') {
            return [0, 0];
        }

        $ops = (int) preg_match_all('/(?<=[\s\)\]>])(?:Tj|TJ|\'|")(?=[\s]|$)/', $content);

        $bytes = 0;
        if (preg_match_all('/\((?:[^()\\\\]|\\\\.|\((?:[^()\\\\]|\\\\.)*+\))*+\)/s', $content, $m)) {
            foreach ($m[0] as $literal) {
                $bytes += strlen($literal) - 2;
            }
        }
        if (preg_match_all('/<([0-9A-Fa-f\s]+)>(?=\s*(?:Tj|\]|\'|"|[-\d.\s]*[<(]))/', $content, $m)) {
            foreach ($m[1] as $hex) {
                $bytes += intdiv(strlen((string) preg_replace('/\s+/', '', $hex)), 2);
            }
        }

        return [$bytes, $ops];
    }

    private function inspectBasic(string $file, int $rawPages): PdfInspection
    {
        if ($rawPages < 1) {
            throw new PdfRejectedException(PdfRejectedException::UNREADABLE, self::msgUnreadable(), 'basic scan found no pages');
        }
        if ($rawPages > $this->maxPages) {
            $this->rejectPages($rawPages, 'basic');
        }

        // Without a parser the text size cannot be measured; it is checked again at extraction time.
        return new PdfInspection($rawPages, 0, true, self::ENGINE_BASIC);
    }

    // ------------------------------------------------------------ helpers

    /**
     * Highest /Count of any /Type /Pages node, or the number of /Type /Page objects.
     */
    public static function rawPageEstimate(string $raw): int
    {
        $max = 0;
        if (preg_match_all('#/Type\s*/Pages\b(?:(?!>>).){0,400}?/Count\s+(\d+)#s', $raw, $m)) {
            $max = max(array_map('intval', $m[1]));
        }
        if (preg_match_all('#/Count\s+(\d+)(?:(?!>>).){0,400}?/Type\s*/Pages\b#s', $raw, $m)) {
            $max = max($max, ...array_map('intval', $m[1]));
        }
        if ($max > 0) {
            return $max;
        }

        // No readable page tree (e.g. compressed object streams): count page objects instead.
        return (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $raw);
    }

    public static function looksEncrypted(string $raw): bool
    {
        // The /Encrypt entry lives in the trailer (or the cross-reference stream dictionary).
        return (bool) preg_match('#/Encrypt\s*(?:\d+\s+\d+\s+R|<<)#', $raw);
    }

    private static function meaningfulText(string $text): bool
    {
        $letters = preg_match_all('/[\p{L}\p{N}]/u', $text);

        return is_int($letters) && $letters >= 20;
    }

    private static function popplerUsable(?string $path): bool
    {
        return function_exists('proc_open') && Process::isExecutable($path);
    }

    private static function siblingTool(string $pdftotext, string $tool): string
    {
        $dir = dirname($pdftotext);
        $ext = strtolower(pathinfo($pdftotext, PATHINFO_EXTENSION)) === 'exe' ? '.exe' : '';

        return $dir . DIRECTORY_SEPARATOR . $tool . $ext;
    }

    private function rejectPages(int $pages, string $engine): never
    {
        Logger::warning('upload', 'PDF bomb guard: too many pages', ['pages' => $pages, 'limit' => $this->maxPages, 'engine' => $engine]);

        throw new PdfRejectedException(
            PdfRejectedException::TOO_MANY_PAGES,
            sprintf('This PDF has too many pages (%d). The maximum is %d pages.', $pages, $this->maxPages),
            "pages={$pages}"
        );
    }

    private function rejectText(int $bytes, string $engine): never
    {
        Logger::warning('upload', 'PDF bomb guard: extracted text too large', ['bytes' => $bytes, 'limit' => $this->maxTextBytes, 'engine' => $engine]);

        throw new PdfRejectedException(
            PdfRejectedException::TEXT_TOO_LARGE,
            sprintf('This PDF contains too much text to process (limit %s).', format_bytes($this->maxTextBytes)),
            "text_bytes>{$bytes}"
        );
    }

    private static function msgUnreadable(): string
    {
        return 'This PDF could not be read. It may be damaged — please check the file and try again.';
    }

    private function raiseMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');
        if ($current === '-1') {
            return;
        }
        $bytes = self::toBytes($current);
        if ($bytes < 256 * 1024 * 1024) {
            @ini_set('memory_limit', '256M');
        }
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
