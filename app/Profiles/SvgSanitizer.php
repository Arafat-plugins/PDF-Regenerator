<?php

declare(strict_types=1);

namespace App\Profiles;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Makes an uploaded SVG logo safe (Part C §12): only drawing elements and presentation
 * attributes survive; scripts, event handlers, external references, embedded HTML,
 * DOCTYPE / entities and CSS that loads anything are removed or refused.
 *
 * The result is a fresh, re-serialized document — the uploaded bytes are never stored.
 */
final class SvgSanitizer
{
    /** Elements kept (local names, lower case). Everything else is dropped with its content. */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line',
        'polyline', 'polygon', 'text', 'tspan', 'textpath', 'lineargradient', 'radialgradient', 'stop',
        'clippath', 'mask', 'pattern', 'image', 'style', 'switch',
    ];

    /** Attributes kept (lower case, without namespace prefix except xlink:href). */
    private const ATTRIBUTES = [
        'id', 'class', 'style', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points',
        'width', 'height', 'viewbox', 'preserveaspectratio', 'transform', 'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset', 'opacity', 'offset', 'stop-color', 'stop-opacity',
        'gradientunits', 'gradienttransform', 'spreadmethod', 'fx', 'fy', 'fr', 'clip-path', 'clip-rule',
        'clippathunits', 'mask', 'maskunits', 'maskcontentunits', 'patternunits', 'patterncontentunits',
        'patterntransform', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor',
        'dominant-baseline', 'letter-spacing', 'word-spacing', 'dx', 'dy', 'rotate', 'textlength',
        'lengthadjust', 'href', 'xlink:href', 'version', 'xmlns', 'xmlns:xlink', 'display', 'visibility',
        'color', 'vector-effect', 'shape-rendering', 'xml:space', 'startoffset', 'method', 'spacing',
    ];

    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_NODES = 20000;

    /**
     * @throws ProfileException when the file is not a usable SVG
     */
    public static function sanitize(string $xml): string
    {
        if ($xml === '' || strlen($xml) > self::MAX_BYTES) {
            throw new ProfileException('This SVG logo is empty or too large.', 'svg_size');
        }
        // Entities are the classic XML attack (billion laughs, XXE) — refuse any DOCTYPE outright.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new ProfileException('This SVG file is not allowed (it contains a DOCTYPE).', 'svg_doctype');
        }
        if (!preg_match('/<svg[\s>]/i', $xml)) {
            throw new ProfileException('This file is not a valid SVG image.', 'signature');
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->documentElement;
        if (!$loaded || !$root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            throw new ProfileException('This file is not a valid SVG image.', 'signature');
        }

        $count = 0;
        self::clean($root, $count);
        if ($count > self::MAX_NODES) {
            throw new ProfileException('This SVG logo is too complex.', 'svg_complex');
        }
        if (self::dimensions($root) === null) {
            throw new ProfileException('The SVG logo needs a width and height or a viewBox.', 'dimensions');
        }
        if (!$root->hasAttribute('xmlns')) {
            $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        }

        $out = $doc->saveXML($root);
        if (!is_string($out) || $out === '') {
            throw new ProfileException('This file is not a valid SVG image.', 'signature');
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out . "\n";
    }

    /**
     * Intrinsic size in px (width/height attributes, else the viewBox), or null.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function dimensions(DOMElement|string $svg): ?array
    {
        if (is_string($svg)) {
            $doc = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $ok = !preg_match('/<!DOCTYPE|<!ENTITY/i', $svg) && $doc->loadXML($svg, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$ok || !$doc->documentElement instanceof DOMElement) {
                return null;
            }
            $svg = $doc->documentElement;
        }
        $w = self::length($svg->getAttribute('width'));
        $h = self::length($svg->getAttribute('height'));
        $box = preg_split('/[\s,]+/', trim($svg->getAttribute('viewBox')));
        $boxOk = is_array($box) && count($box) === 4 && is_numeric($box[2]) && is_numeric($box[3]) && (float) $box[2] > 0 && (float) $box[3] > 0;
        if ($w !== null && $h !== null) {
            return [$w, $h];
        }
        if ($boxOk) {
            $bw = (float) $box[2];
            $bh = (float) $box[3];
            if ($w !== null) {
                return [$w, $w * $bh / $bw];
            }
            if ($h !== null) {
                return [$h * $bw / $bh, $h];
            }

            return [$bw, $bh];
        }

        return null;
    }

    private static function length(string $value): ?float
    {
        if (!preg_match('/^\s*(\d*\.?\d+)\s*(px|pt|mm|cm|in)?\s*$/i', $value, $m) || (float) $m[1] <= 0) {
            return null; // %, em, auto … → use the viewBox
        }
        $factor = match (strtolower($m[2] ?? '')) {
            'pt' => 96 / 72,
            'mm' => 96 / 25.4,
            'cm' => 96 / 2.54,
            'in' => 96.0,
            default => 1.0,
        };

        return (float) $m[1] * $factor;
    }

    private static function clean(DOMElement $element, int &$count): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if (++$count > self::MAX_NODES) {
                return;
            }
            if ($child instanceof DOMElement) {
                $name = strtolower($child->localName);
                $svgNs = $child->namespaceURI === null || $child->namespaceURI === 'http://www.w3.org/2000/svg';
                if (!$svgNs || !in_array($name, self::ELEMENTS, true)) {
                    $element->removeChild($child);
                    continue;
                }
                if ($name === 'style') {
                    $css = self::safeCss($child->textContent);
                    if ($css === null) {
                        $element->removeChild($child);
                        continue;
                    }
                    while ($child->firstChild) {
                        $child->removeChild($child->firstChild);
                    }
                    $child->appendChild($child->ownerDocument->createTextNode($css));
                }
                self::cleanAttributes($child);
                if ($name === 'image' && !$child->hasAttribute('href') && !$child->hasAttribute('xlink:href')) {
                    $element->removeChild($child);
                    continue;
                }
                self::clean($child, $count);
            } elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                if ($child->nodeType === XML_CDATA_SECTION_NODE) {
                    $element->replaceChild($element->ownerDocument->createTextNode($child->textContent), $child);
                }
            } else {
                // comments, processing instructions, entity references …
                $element->removeChild($child);
            }
        }
        if ($element === $element->ownerDocument?->documentElement) {
            self::cleanAttributes($element);
        }
    }

    private static function cleanAttributes(DOMElement $element): void
    {
        $name = strtolower($element->localName);
        foreach (iterator_to_array($element->attributes ?? []) as $attr) {
            /** @var \DOMAttr $attr */
            $full = strtolower($attr->prefix !== '' && $attr->prefix !== null ? $attr->prefix . ':' . $attr->localName : $attr->localName);
            $value = (string) $attr->value;
            $keep = in_array($full, self::ATTRIBUTES, true) && !str_starts_with($full, 'on');
            if ($keep && ($full === 'href' || $full === 'xlink:href')) {
                $keep = self::safeHref($value, $name);
            }
            if ($keep && $full === 'style') {
                $css = self::safeCss($value);
                if ($css === null) {
                    $keep = false;
                } else {
                    $attr->value = $css;
                }
            }
            if ($keep && preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $value)) {
                $keep = false; // only local references such as url(#gradient)
            }
            if (!$keep) {
                $element->removeAttributeNode($attr);
            }
        }
        // namespace declarations other than svg / xlink are dropped by re-serialisation of kept nodes
    }

    private static function safeHref(string $value, string $element): bool
    {
        $value = trim($value);
        if (str_starts_with($value, '#')) {
            return $element !== 'image';
        }

        // embedded raster images only (no external files, no nested SVG)
        return $element === 'image' && (bool) preg_match('~^data:image/(?:png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$~', $value);
    }

    private static function safeCss(string $css): ?string
    {
        if (preg_match('/@import|expression\s*\(|javascript:|behavior\s*:|-moz-binding|<\s*\//i', $css)) {
            return null;
        }
        if (preg_match('/url\s*\(\s*[\'"]?\s*(?!#)/i', $css)) {
            return null;
        }

        return $css;
    }
}
