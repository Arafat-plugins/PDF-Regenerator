<?php

declare(strict_types=1);

namespace App\Pdf\Extract;

use App\Core\Config;
use Smalot\PdfParser\Config as SmalotConfig;
use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementNumeric;
use Smalot\PdfParser\Element\ElementXRef;
use Smalot\PdfParser\Font;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\PDFObject;
use Smalot\PdfParser\XObject\Form;

/**
 * Pure-PHP positioned text (Part D §4) on top of smalot/pdfparser.
 *
 * smalot's own position helpers only look at Tm. Real generators (react-pdf,
 * browsers, …) place text through nested q / cm / Q transforms, so this walker
 * keeps its own graphics state: q/Q push/pop the CTM, cm concatenates it,
 * BT resets the text matrix, Tm, Td, TD, T-star and TL update it, Tf/Tc/Tw/Tz/Ts feed the
 * advance calculation, and every Tj/TJ/'/" run is placed at Tm × CTM.
 * Glyph advances come from the font's Widths / W arrays. Runs in linear time.
 */
final class SmalotPdfExtractor implements PdfExtractorInterface
{
    private const MAX_FORM_DEPTH = 4;
    private const IDENTITY = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    /** @var array<string, array{font: ?Font, bytes: int, widths: array<int, float>, default: float, space: bool, unicode: array<int, string>}> */
    private array $fontCache = [];

    /** @var list<PositionedText> */
    private array $runs = [];

    private int $opsBudget = 0;

    public function name(): string
    {
        return 'smalot';
    }

    public function isAvailable(): bool
    {
        return class_exists(Parser::class);
    }

    public function extract(string $filePath): ExtractionResult
    {
        if (!$this->isAvailable()) {
            throw new ExtractionException('The PDF text engine is not available.', 'smalot missing');
        }

        $config = new SmalotConfig();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);

        try {
            $document = (new Parser([], $config))->parseFile($filePath);
            $pages = $document->getPages();
        } catch (\Throwable $e) {
            throw new ExtractionException("We couldn't extract readable data from this PDF.", 'smalot: ' . $e->getMessage());
        }

        $this->runs = [];
        $this->opsBudget = 2_000_000; // hard stop for hostile files
        $sizes = [];
        $warnings = [];
        $maxBytes = (int) Config::get('max_extracted_text_bytes', 5 * 1024 * 1024);
        $bytes = 0;

        foreach (array_values($pages) as $index => $page) {
            $pageNo = $index + 1;
            [$width, $height, $originX, $originY] = self::pageBox($page);
            $sizes[$pageNo] = ['width' => $width, 'height' => $height];
            $this->fontCache = [];

            try {
                $commands = $page->extractRawData();
            } catch (\Throwable $e) {
                $warnings[] = "Page {$pageNo}: " . $e->getMessage();
                continue;
            }

            $before = count($this->runs);
            // Device space with the page origin moved to (0,0).
            $base = [1.0, 0.0, 0.0, 1.0, -$originX, -$originY];
            $this->walk($commands, $page, $base, $pageNo, $height, 0);

            for ($i = $before, $n = count($this->runs); $i < $n; $i++) {
                $bytes += strlen($this->runs[$i]->text);
            }
            if ($bytes > $maxBytes) {
                throw new ExtractionException('This PDF contains too much text to process.', "text bytes > {$maxBytes}");
            }
        }

        return new ExtractionResult('smalot', count($pages), $this->runs, $sizes, false, $warnings);
    }

    /**
     * @param list<array{t?: string, o: string, c: mixed}> $commands
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $ctm
     */
    private function walk(array $commands, Page $resources, array $ctm, int $pageNo, float $pageHeight, int $depth): void
    {
        $stack = [];
        $state = [
            'font' => null, 'size' => 0.0, 'tc' => 0.0, 'tw' => 0.0, 'th' => 1.0, 'tl' => 0.0, 'rise' => 0.0,
        ];
        $tm = self::IDENTITY;
        $tlm = self::IDENTITY;

        foreach ($commands as $command) {
            if (--$this->opsBudget < 0) {
                throw new ExtractionException('This PDF is too complex to process.', 'operator budget exhausted');
            }

            $op = (string) ($command['o'] ?? '');
            $operand = $command['c'] ?? '';

            switch ($op) {
                case 'q':
                    $stack[] = [$ctm, $state];
                    break;

                case 'Q':
                    if ($stack !== []) {
                        [$ctm, $state] = array_pop($stack);
                    }
                    break;

                case 'cm':
                    $m = self::numbers($operand, 6);
                    if ($m !== null) {
                        $ctm = self::multiply($m, $ctm);
                    }
                    break;

                case 'BT':
                    $tm = $tlm = self::IDENTITY;
                    break;

                case 'Tf':
                    $parts = preg_split('/\s+/', trim((string) $operand)) ?: [];
                    $size = (float) array_pop($parts);
                    $name = ltrim((string) implode(' ', $parts), '/');
                    $state['font'] = $this->font($resources, $name);
                    $state['size'] = $size;
                    break;

                case 'Tc':
                    $state['tc'] = (float) $operand;
                    break;

                case 'Tw':
                    $state['tw'] = (float) $operand;
                    break;

                case 'Tz':
                    $state['th'] = ((float) $operand) / 100;
                    break;

                case 'TL':
                    $state['tl'] = (float) $operand;
                    break;

                case 'Ts':
                    $state['rise'] = (float) $operand;
                    break;

                case 'Tm':
                    $m = self::numbers($operand, 6);
                    if ($m !== null) {
                        $tm = $tlm = $m;
                    }
                    break;

                case 'Td':
                case 'TD':
                    $m = self::numbers($operand, 2);
                    if ($m !== null) {
                        if ($op === 'TD') {
                            $state['tl'] = -$m[1];
                        }
                        $tm = $tlm = self::multiply([1.0, 0.0, 0.0, 1.0, $m[0], $m[1]], $tlm);
                    }
                    break;

                case 'T*':
                    $tm = $tlm = self::multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$state['tl']], $tlm);
                    break;

                case "'":
                case '"':
                    if ($op === '"') {
                        // aw ac (string) "
                        if (is_string($operand) && preg_match('/^\s*(-?[\d.]+)\s+(-?[\d.]+)\s*(.*)$/s', $operand, $mm)) {
                            $state['tw'] = (float) $mm[1];
                            $state['tc'] = (float) $mm[2];
                            $operand = $mm[3];
                        }
                    }
                    $tm = $tlm = self::multiply([1.0, 0.0, 0.0, 1.0, 0.0, -$state['tl']], $tlm);
                    $this->show([['t' => $command['t'] ?? '(', 'c' => $operand]], $state, $tm, $ctm, $pageNo, $pageHeight);
                    break;

                case 'Tj':
                    $this->show([['t' => $command['t'] ?? '(', 'c' => $operand]], $state, $tm, $ctm, $pageNo, $pageHeight);
                    break;

                case 'TJ':
                    if (is_array($operand)) {
                        $this->show($operand, $state, $tm, $ctm, $pageNo, $pageHeight);
                    }
                    break;

                case 'Do':
                    if ($depth < self::MAX_FORM_DEPTH) {
                        $this->form($resources, trim((string) $operand), $ctm, $pageNo, $pageHeight, $depth);
                    }
                    break;
            }
        }
    }

    /**
     * Place and advance one text-showing operation (Tj or TJ array).
     *
     * Strings of one TJ array are merged into a single run. A kerning adjustment becomes a
     * space only when it is clearly wider than the array's usual adjustment (letter-spaced
     * text is written as uniform adjustments between every glyph), and a very wide one
     * (≥ 24 pt) starts a new run so the cleaner can make a new cell.
     *
     * @param list<array{t?: string, c: mixed}> $items
     * @param array<string, mixed> $state
     */
    private function show(array $items, array $state, array &$tm, array $ctm, int $pageNo, float $pageHeight): void
    {
        $font = $state['font'];
        $size = (float) $state['size'];
        $th = (float) $state['th'];

        // Typical adjustment (em) of this array = tracking; word gaps must exceed it by 0.15 em.
        $gaps = [];
        foreach ($items as $item) {
            if (self::isAdjustment($item)) {
                $gaps[] = -((float) $item['c']) / 1000;
            }
        }
        $tracking = 0.0;
        if (count($gaps) >= 3) {
            sort($gaps);
            $tracking = max(0.0, $gaps[intdiv(count($gaps), 2)]);
        }
        $wordGap = $tracking + TextCleaner::SPACE_RATIO;

        $run = null;
        $pendingSpace = false;
        foreach ($items as $item) {
            if (self::isAdjustment($item)) {
                $em = -((float) $item['c']) / 1000;
                $tx = $em * $size * $th;
                $tm = self::multiply([1.0, 0.0, 0.0, 1.0, $tx, 0.0], $tm);
                if ($run !== null) {
                    $gapPoints = abs($tx) * self::scaleOf($tm, $ctm, $size, $th) / max(0.0001, $size * $th);
                    if ($gapPoints >= TextCleaner::CELL_GAP) {
                        $this->addRun($run, $pageNo);
                        $run = null;
                    } elseif ($em > $wordGap) {
                        $pendingSpace = true;
                    }
                }
                continue;
            }

            $type = (string) ($item['t'] ?? '');
            $bytes = $type === '<'
                ? self::hexToBytes((string) ($item['c'] ?? ''))
                : self::literalToBytes((string) ($item['c'] ?? ''));
            if ($bytes === '') {
                continue;
            }

            $render = self::multiply([$size * $th, 0.0, 0.0, $size, 0.0, (float) $state['rise']], self::multiply($tm, $ctm));
            $startX = $render[4];
            $startY = $render[5];
            $scale = sqrt($render[2] * $render[2] + $render[3] * $render[3]);

            $codeLength = $font['bytes'] ?? 1;
            $advance = 0.0;
            for ($i = 0, $len = strlen($bytes); $i < $len; $i += $codeLength) {
                $code = $codeLength === 2 ? (ord($bytes[$i]) << 8) | ord($bytes[$i + 1] ?? "\0") : ord($bytes[$i]);
                $w0 = ($font['widths'][$code] ?? ($font['default'] ?? 500.0)) / 1000;
                $wordSpace = ($codeLength === 1 && $code === 32) ? (float) $state['tw'] : 0.0;
                $advance += ($w0 * $size + (float) $state['tc'] + $wordSpace) * $th;
            }
            $tm = self::multiply([1.0, 0.0, 0.0, 1.0, $advance, 0.0], $tm);
            $end = self::multiply([$size * $th, 0.0, 0.0, $size, 0.0, (float) $state['rise']], self::multiply($tm, $ctm));

            $text = self::decode($font['font'] ?? null, $bytes, $font['unicode'] ?? [], $codeLength);
            $x0 = min($startX, $end[4]);
            $x1 = max($startX, $end[4]);

            if ($run !== null && abs($run['y'] - ($pageHeight - $startY)) <= 0.5) {
                if ($pendingSpace && !str_ends_with($run['text'], ' ') && !str_starts_with($text, ' ')) {
                    $run['text'] .= ' ';
                }
                $run['text'] .= $text;
                $run['x0'] = min($run['x0'], $x0);
                $run['x1'] = max($run['x1'], $x1);
            } else {
                if ($run !== null) {
                    $this->addRun($run, $pageNo);
                }
                $run = ['x0' => $x0, 'x1' => $x1, 'y' => $pageHeight - $startY, 'text' => $text, 'size' => $scale > 0 ? $scale : $size];
            }
            $pendingSpace = false;
        }

        if ($run !== null) {
            $this->addRun($run, $pageNo);
        }
    }

    /**
     * @param array{t?: string, c: mixed} $item
     */
    private static function isAdjustment(array $item): bool
    {
        $type = (string) ($item['t'] ?? '');
        $content = $item['c'] ?? '';

        return $type === 'n' || (is_numeric($content) && $type !== '(' && $type !== '<');
    }

    /**
     * Device-space length of one unit of the text matrix x axis, times font size.
     */
    private static function scaleOf(array $tm, array $ctm, float $size, float $th): float
    {
        $m = self::multiply([$size * $th, 0.0, 0.0, $size, 0.0, 0.0], self::multiply($tm, $ctm));

        return sqrt($m[0] * $m[0] + $m[1] * $m[1]);
    }

    /**
     * @param array{x0: float, x1: float, y: float, text: string, size: float} $run
     */
    private function addRun(array $run, int $pageNo): void
    {
        if (trim($run['text']) === '') {
            return;
        }
        $this->runs[] = new PositionedText($pageNo, $run['x0'], $run['y'], $run['x1'], $run['text'], $run['size']);
    }

    private function form(Page $resources, string $name, array $ctm, int $pageNo, float $pageHeight, int $depth): void
    {
        $name = ltrim($name, '/');
        try {
            $xobject = $resources->getXObject($name);
        } catch (\Throwable) {
            return;
        }
        if (!$xobject instanceof Form) {
            return;
        }

        $matrix = self::IDENTITY;
        try {
            $m = $xobject->get('Matrix');
            if ($m instanceof ElementArray) {
                $values = array_map(static fn ($v) => (float) (is_object($v) ? $v->getContent() : $v), $m->getContent());
                if (count($values) === 6) {
                    $matrix = $values;
                }
            }
            $content = (string) $xobject->getContent();
            $commands = [];
            foreach ($xobject->getSectionsText($content) as $section) {
                foreach ($xobject->getCommandsText($section) as $cmd) {
                    $commands[] = $cmd;
                }
            }
        } catch (\Throwable) {
            return;
        }

        $saved = $this->fontCache;
        $this->fontCache = [];
        $this->walk($commands, $xobject, self::multiply($matrix, $ctm), $pageNo, $pageHeight, $depth + 1);
        $this->fontCache = $saved;
    }

    /**
     * Font info with a code → width map (1/1000 text space units).
     *
     * @return array{font: ?Font, bytes: int, widths: array<int, float>, default: float, space: bool}
     */
    private function font(Page $resources, string $name): array
    {
        if (isset($this->fontCache[$name])) {
            return $this->fontCache[$name];
        }

        $info = ['font' => null, 'bytes' => 1, 'widths' => [], 'default' => 500.0, 'space' => true, 'unicode' => []];

        try {
            $font = $resources->getFont($name);
        } catch (\Throwable) {
            $font = null;
        }

        if ($font instanceof Font) {
            $info['font'] = $font;
            $info['unicode'] = self::toUnicodeMap($font);
            try {
                $subtype = (string) ($font->get('Subtype')->getContent() ?? '');
            } catch (\Throwable) {
                $subtype = '';
            }

            if ($subtype === 'Type0') {
                $info['bytes'] = 2;
                $info['default'] = 1000.0;
                $descendant = self::firstDescendant($font);
                if ($descendant !== null) {
                    $dw = self::scalar($descendant->get('DW'));
                    if ($dw !== null) {
                        $info['default'] = $dw;
                    }
                    $info['widths'] = self::cidWidths($descendant->get('W'));
                }
            } else {
                $first = (int) (self::scalar($font->get('FirstChar')) ?? 0);
                foreach (self::arrayItems($font->get('Widths')) ?? [] as $i => $w) {
                    $info['widths'][$first + $i] = (float) (is_object($w) ? $w->getContent() : $w);
                }
                $missing = self::scalar(self::descriptorValue($font, 'MissingWidth'));
                if ($missing !== null && $missing > 0) {
                    $info['default'] = $missing;
                }
            }
        }

        return $this->fontCache[$name] = $info;
    }

    private static function firstDescendant(Font $font): ?PDFObject
    {
        try {
            $descendants = $font->get('DescendantFonts');
            $list = $descendants instanceof ElementArray ? $descendants->getContent()
                : ($descendants instanceof PDFObject ? $descendants->getHeader()->getElements() : []);
            foreach ($list as $item) {
                $object = $item instanceof ElementXRef ? $item->getObject() : $item;
                if ($object instanceof PDFObject) {
                    return $object;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Parse a CIDFont /W array: [c [w1 w2 …]  cFirst cLast w  …].
     *
     * @return array<int, float>
     */
    private static function cidWidths(mixed $w): array
    {
        $items = self::arrayItems($w);
        if ($items === null) {
            return [];
        }

        $map = [];
        $i = 0;
        $n = count($items);
        $guard = 0;
        while ($i < $n && $guard++ < 100000) {
            $first = $items[$i];
            $next = $items[$i + 1] ?? null;
            if (!$first instanceof ElementNumeric) {
                $i++;
                continue;
            }
            $start = (int) $first->getContent();
            $nested = $next instanceof ElementArray || $next instanceof ElementXRef ? self::arrayItems($next) : null;
            if ($nested !== null) {
                foreach ($nested as $k => $value) {
                    $map[$start + $k] = (float) (is_object($value) ? $value->getContent() : $value);
                }
                $i += 2;
            } elseif ($next instanceof ElementNumeric && ($items[$i + 2] ?? null) instanceof ElementNumeric) {
                $last = (int) $next->getContent();
                $width = (float) $items[$i + 2]->getContent();
                for ($c = $start; $c <= $last && $c - $start < 65536; $c++) {
                    $map[$c] = $width;
                }
                $i += 3;
            } else {
                $i++;
            }
        }

        return $map;
    }

    /**
     * Items of a PDF array, whether it is inline ([…]) or an indirect object (12 0 R).
     *
     * @return list<mixed>|null
     */
    private static function arrayItems(mixed $value): ?array
    {
        try {
            if ($value instanceof ElementXRef) {
                $value = $value->getObject();
            }
            if ($value instanceof ElementArray) {
                return array_values($value->getContent());
            }
            if ($value instanceof PDFObject && !$value instanceof Font) {
                $elements = $value->getHeader()->getElements();

                return $elements !== [] && array_is_list($elements) ? array_values($elements) : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function descriptorValue(Font $font, string $key): mixed
    {
        try {
            $descriptor = $font->get('FontDescriptor');
            $descriptor = $descriptor instanceof ElementXRef ? $descriptor->getObject() : $descriptor;

            return $descriptor instanceof PDFObject ? $descriptor->get($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function scalar(mixed $element): ?float
    {
        if ($element instanceof ElementNumeric) {
            return (float) $element->getContent();
        }
        if (is_object($element) && method_exists($element, 'getContent')) {
            $value = $element->getContent();

            return is_numeric($value) ? (float) $value : null;
        }

        return is_numeric($element) ? (float) $element : null;
    }

    /**
     * @param array<int, string> $unicode code => UTF-8 text from the font's ToUnicode CMap
     */
    private static function decode(?Font $font, string $bytes, array $unicode, int $codeLength): string
    {
        $text = '';
        if ($unicode !== []) {
            // Own CMap lookup: handles multi-character targets such as ligatures (<0030> <0066 0069> = "fi"),
            // which smalot's table decodes wrongly.
            $pending = '';
            for ($i = 0, $len = strlen($bytes); $i < $len; $i += $codeLength) {
                $chunk = substr($bytes, $i, $codeLength);
                $code = $codeLength === 2 ? (ord($chunk[0]) << 8) | ord($chunk[1] ?? "\0") : ord($chunk[0]);
                if (isset($unicode[$code])) {
                    if ($pending !== '') {
                        $text .= self::smalotDecode($font, $pending);
                        $pending = '';
                    }
                    $text .= $unicode[$code];
                } else {
                    $pending .= $chunk;
                }
            }
            if ($pending !== '') {
                $text .= self::smalotDecode($font, $pending);
            }
        } else {
            $text = self::smalotDecode($font, $bytes);
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Drop control characters except tab.
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
    }

    private static function smalotDecode(?Font $font, string $bytes): string
    {
        if ($font === null) {
            return (string) @mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }
        try {
            return (string) $font->decodeContent($bytes);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Parse the font's ToUnicode CMap (bfchar / bfrange).
     *
     * @return array<int, string>
     */
    private static function toUnicodeMap(Font $font): array
    {
        try {
            $stream = $font->get('ToUnicode');
            $stream = $stream instanceof ElementXRef ? $stream->getObject() : $stream;
            if (!$stream instanceof PDFObject) {
                return [];
            }
            $cmap = (string) $stream->getContent();
        } catch (\Throwable) {
            return [];
        }
        if ($cmap === '' || strlen($cmap) > 2_000_000) {
            return [];
        }

        $map = [];
        $hex = static function (string $h): string {
            $h = (string) preg_replace('/[^0-9A-Fa-f]/', '', $h);

            return strlen($h) % 2 === 0 ? (string) hex2bin($h) : '';
        };
        $utf16 = static function (string $raw): string {
            if ($raw === '') {
                return '';
            }
            if (strlen($raw) % 2 === 1) {
                $raw = "\0" . $raw;
            }
            $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');

            return is_string($out) ? $out : '';
        };
        $code = static function (string $raw): int {
            $value = 0;
            foreach (str_split($raw) as $b) {
                $value = ($value << 8) | ord($b);
            }

            return $value;
        };

        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*<([0-9A-Fa-f\s]*)>/', $block, $pairs, PREG_SET_ORDER)) {
                    foreach ($pairs as $pair) {
                        $map[$code($hex($pair[1]))] = $utf16($hex($pair[2]));
                    }
                }
            }
        }

        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(?:<([0-9A-Fa-f\s]*)>|\[([^\]]*)\])/', $block, $ranges, PREG_SET_ORDER)) {
                    foreach ($ranges as $r) {
                        $lo = $code($hex($r[1]));
                        $hi = $code($hex($r[2]));
                        if ($hi < $lo || $hi - $lo > 65535) {
                            continue;
                        }
                        if (isset($r[4]) && $r[4] !== '') {
                            preg_match_all('/<([0-9A-Fa-f\s]*)>/', $r[4], $items);
                            foreach ($items[1] as $k => $item) {
                                if ($lo + $k > $hi) {
                                    break;
                                }
                                $map[$lo + $k] = $utf16($hex($item));
                            }
                        } else {
                            $start = $hex($r[3]);
                            if ($start === '') {
                                continue;
                            }
                            $base = $code(substr($start, -2));
                            $prefix = substr($start, 0, -2);
                            for ($c = $lo; $c <= $hi; $c++) {
                                $unit = $base + ($c - $lo);
                                $map[$c] = $utf16($prefix . chr(($unit >> 8) & 0xFF) . chr($unit & 0xFF));
                            }
                        }
                    }
                }
            }
        }

        return $map;
    }

    private static function hexToBytes(string $hex): string
    {
        $hex = (string) preg_replace('/[^0-9A-Fa-f]/', '', $hex);
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }

        return $hex === '' ? '' : (string) hex2bin($hex);
    }

    /**
     * Unescape a PDF literal string body (without the outer parentheses).
     */
    private static function literalToBytes(string $literal): string
    {
        $out = '';
        $len = strlen($literal);
        for ($i = 0; $i < $len; $i++) {
            $ch = $literal[$i];
            if ($ch !== '\\') {
                $out .= $ch;
                continue;
            }
            $next = $literal[++$i] ?? '';
            switch ($next) {
                case 'n': $out .= "\n"; break;
                case 'r': $out .= "\r"; break;
                case 't': $out .= "\t"; break;
                case 'b': $out .= "\x08"; break;
                case 'f': $out .= "\x0C"; break;
                case "\r":
                    if (($literal[$i + 1] ?? '') === "\n") {
                        $i++;
                    }
                    break;
                case "\n":
                    break;
                default:
                    if ($next !== '' && ctype_digit($next) && $next < '8') {
                        $oct = $next;
                        for ($k = 0; $k < 2 && isset($literal[$i + 1]) && $literal[$i + 1] >= '0' && $literal[$i + 1] <= '7'; $k++) {
                            $oct .= $literal[++$i];
                        }
                        $out .= chr(octdec($oct) & 0xFF);
                    } else {
                        $out .= $next;
                    }
            }
        }

        return $out;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private static function numbers(mixed $operand, int $count): ?array
    {
        if (!is_string($operand)) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($operand)) ?: [];
        if (count($parts) < $count) {
            return null;
        }
        $parts = array_slice($parts, -$count);
        foreach ($parts as $p) {
            if (!is_numeric($p)) {
                return null;
            }
        }

        return array_map('floatval', $parts);
    }

    /**
     * Row-vector matrix product A × B for [a b c d e f].
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $a
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $b
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function multiply(array $a, array $b): array
    {
        return [
            $a[0] * $b[0] + $a[1] * $b[2],
            $a[0] * $b[1] + $a[1] * $b[3],
            $a[2] * $b[0] + $a[3] * $b[2],
            $a[2] * $b[1] + $a[3] * $b[3],
            $a[4] * $b[0] + $a[5] * $b[2] + $b[4],
            $a[4] * $b[1] + $a[5] * $b[3] + $b[5],
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} width, height, originX, originY
     */
    private static function pageBox(Page $page): array
    {
        try {
            $box = $page->get('MediaBox');
            if ($box instanceof ElementArray) {
                $v = array_map(static fn ($x) => (float) (is_object($x) ? $x->getContent() : $x), array_values($box->getContent()));
                if (count($v) === 4) {
                    return [abs($v[2] - $v[0]), abs($v[3] - $v[1]), min($v[0], $v[2]), min($v[1], $v[3])];
                }
            }
            $details = $page->getDetails();
            if (isset($details['MediaBox']) && is_array($details['MediaBox']) && count($details['MediaBox']) === 4) {
                $v = array_map('floatval', array_values($details['MediaBox']));

                return [abs($v[2] - $v[0]), abs($v[3] - $v[1]), min($v[0], $v[2]), min($v[1], $v[3])];
            }
        } catch (\Throwable) {
        }

        return [595.28, 841.89, 0.0, 0.0];
    }
}
