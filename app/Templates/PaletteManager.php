<?php

declare(strict_types=1);

namespace App\Templates;

/**
 * Theme colors for templates (Part D §14). Everything returned is a validated
 * #rrggbb string — color keys are the only placeholders allowed inside CSS.
 */
final class PaletteManager
{
    public const HEX = '/^#[0-9a-fA-F]{6}$/';
    public const PRESET_KEYS = ['primary', 'primary_dark', 'primary_bright', 'cover_start', 'cover_mid', 'cover_end'];

    /** "Good / done" color (check marks, OK pills, positive values) unless a palette or the user sets one. */
    public const SUCCESS = '#34d399';

    /** Card surface the "soft" tints are blended over. */
    public const SURFACE = '#17171a';

    /** Fallback when a template has no palettes. */
    private const FALLBACK = [
        'primary' => '#2563eb', 'primary_dark' => '#1e40af', 'primary_bright' => '#60a5fa',
        'cover_start' => '#172554', 'cover_mid' => '#2563eb', 'cover_end' => '#1e3a8a',
    ];

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match(self::HEX, $value);
    }

    /**
     * @param array<mixed> $palette
     * @return array<string, string>|null
     */
    public static function validatePreset(array $palette): ?array
    {
        $out = ['label' => is_string($palette['label'] ?? null) ? mb_substr(trim($palette['label']), 0, 40) : ''];
        foreach (self::PRESET_KEYS as $key) {
            if (!self::isHex($palette[$key] ?? null)) {
                return null;
            }
            $out[$key] = strtolower((string) $palette[$key]);
        }

        return $out;
    }

    /**
     * theme_* placeholder values for a template.
     *
     * @param array{accent?: string, cover?: string, success?: string}|null $custom used when $paletteId === 'custom'
     * @return array<string, string>
     */
    public static function theme(Template $template, ?string $paletteId, ?array $custom = null): array
    {
        if ($paletteId === 'custom' && $custom !== null && self::isHex($custom['accent'] ?? null) && self::isHex($custom['cover'] ?? null)) {
            $colors = self::derive((string) $custom['accent'], (string) $custom['cover']);
        } else {
            $colors = $template->palettes[$paletteId ?? ''] ?? ($template->palettes[(string) $template->defaultPalette()] ?? self::FALLBACK);
        }

        $theme = [];
        foreach (self::PRESET_KEYS as $key) {
            $theme['theme_' . $key] = self::isHex($colors[$key] ?? null) ? strtolower($colors[$key]) : self::FALLBACK[$key];
        }
        $theme['theme_primary_soft'] = self::blend($theme['theme_primary'], self::SURFACE, 0.12);
        $theme['theme_primary_faint'] = self::blend($theme['theme_primary'], self::SURFACE, 0.05);
        $theme['theme_primary_light'] = self::blend($theme['theme_primary'], '#ffffff', 0.10); // tint for light templates

        $success = $paletteId === 'custom' && self::isHex($custom['success'] ?? null)
            ? (string) $custom['success']
            : (self::isHex($colors['success'] ?? null) ? (string) $colors['success'] : self::SUCCESS);
        $theme += self::successShades($success);

        return $theme;
    }

    /**
     * theme_success and the shades drawn with it (the defaults reproduce the original design).
     *
     * @return array<string, string>
     */
    public static function successShades(string $success): array
    {
        $success = self::isHex($success) ? strtolower($success) : self::SUCCESS;
        if ($success === self::SUCCESS) {
            return [
                'theme_success' => self::SUCCESS,
                'theme_success_bright' => '#72e7bb',
                'theme_success_line' => '#267a62',
                'theme_success_bg' => '#172622',
                'theme_success_fill' => '#1a2a27',
            ];
        }

        return [
            'theme_success' => $success,
            'theme_success_bright' => self::shade($success, 0.33),
            'theme_success_line' => self::blend($success, self::SURFACE, 0.53),
            'theme_success_bg' => self::blend($success, '#111113', 0.08),
            'theme_success_fill' => self::blend($success, '#111113', 0.11),
        ];
    }

    /**
     * Shades for custom colors, computed in PHP (no CSS functions in mPDF).
     *
     * @return array<string, string>
     */
    public static function derive(string $accent, string $cover): array
    {
        return [
            'primary' => strtolower($accent),
            'primary_dark' => self::shade($accent, -0.25),
            'primary_bright' => self::shade($accent, 0.20),
            'cover_start' => self::shade($cover, -0.45),
            'cover_mid' => strtolower($cover),
            'cover_end' => self::shade($cover, -0.40),
        ];
    }

    /** Relative luminance (WCAG) 0..1. */
    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);
        $lin = static function (int $c): float {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }

    /** $alpha of $color painted over $background, as a solid hex. */
    public static function blend(string $color, string $background, float $alpha): string
    {
        [$r1, $g1, $b1] = self::rgb($color);
        [$r2, $g2, $b2] = self::rgb($background);

        return self::hex(
            (int) round($r1 * $alpha + $r2 * (1 - $alpha)),
            (int) round($g1 * $alpha + $g2 * (1 - $alpha)),
            (int) round($b1 * $alpha + $b2 * (1 - $alpha)),
        );
    }

    /** Darken (negative) or lighten (positive) by mixing with black / white. */
    public static function shade(string $hex, float $amount): string
    {
        return $amount < 0 ? self::blend('#000000', $hex, -$amount) : self::blend('#ffffff', $hex, $amount);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        if (!self::isHex($hex)) {
            return [0, 0, 0];
        }

        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    public static function hex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
    }
}
