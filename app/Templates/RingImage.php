<?php

declare(strict_types=1);

namespace App\Templates;

use RuntimeException;

/**
 * PageSpeed score ring as a PNG (Part E §17.1c): drawn at 4× and downscaled for smooth
 * edges; the score is drawn INTO the image (mPDF cannot overlay text on an image).
 */
final class RingImage
{
    public const SIZE = 360;           // output pixels
    private const SCALE = 4;
    private const TRACK = '#2b2b30';

    public static function isSupported(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    public static function create(?int $score, string $color, string $outputFile, ?string $fontFile = null): void
    {
        if (!self::isSupported()) {
            throw new RuntimeException('GD is not available.');
        }
        if (!PaletteManager::isHex($color)) {
            $color = TemplateData::pagespeedColor($score);
        }

        $big = self::SIZE * self::SCALE;
        $im = imagecreatetruecolor($big, $big);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, $big, $big, $transparent);

        $center = $big / 2;
        $radius = $big * 0.39;            // ring center line
        $width = $big * 0.075;            // stroke width
        $outer = $radius + $width / 2;
        $inner = $radius - $width / 2;

        // Track: full donut
        [$tr, $tg, $tb] = PaletteManager::rgb(self::TRACK);
        $track = imagecolorallocate($im, $tr, $tg, $tb);
        imagefilledellipse($im, (int) $center, (int) $center, (int) (2 * $outer), (int) (2 * $outer), $track);
        imagefilledellipse($im, (int) $center, (int) $center, (int) (2 * $inner), (int) (2 * $inner), $transparent);

        // Progress arc from 12 o'clock, clockwise, with round caps
        [$r, $g, $b] = PaletteManager::rgb($color);
        $arc = imagecolorallocate($im, $r, $g, $b);
        $value = $score === null ? 0 : max(0, min(100, $score));
        if ($value > 0) {
            imagealphablending($im, true);
            $sweep = 360.0 * $value / 100;
            $steps = max(8, (int) ceil($sweep * 2));
            $points = [];
            for ($i = 0; $i <= $steps; $i++) {
                $angle = deg2rad(-90 + $sweep * $i / $steps);
                $points[] = $center + $outer * cos($angle);
                $points[] = $center + $outer * sin($angle);
            }
            for ($i = $steps; $i >= 0; $i--) {
                $angle = deg2rad(-90 + $sweep * $i / $steps);
                $points[] = $center + $inner * cos($angle);
                $points[] = $center + $inner * sin($angle);
            }
            if ($value >= 100) {
                imagefilledellipse($im, (int) $center, (int) $center, (int) (2 * $outer), (int) (2 * $outer), $arc);
                imagealphablending($im, false);
                imagefilledellipse($im, (int) $center, (int) $center, (int) (2 * $inner), (int) (2 * $inner), $transparent);
            } else {
                self::polygon($im, $points, $arc);
                foreach ([-90.0, -90.0 + $sweep] as $deg) {
                    $angle = deg2rad($deg);
                    imagefilledellipse(
                        $im,
                        (int) round($center + $radius * cos($angle)),
                        (int) round($center + $radius * sin($angle)),
                        (int) $width,
                        (int) $width,
                        $arc
                    );
                }
            }
        }

        // Score text
        imagealphablending($im, true);
        $text = $score === null ? '–' : (string) $score;
        $white = imagecolorallocate($im, 0xf8, 0xfa, 0xfc);
        if ($fontFile !== null && is_file($fontFile) && function_exists('imagettftext')) {
            $size = $big * 0.155;
            $box = imagettfbbox($size, 0, $fontFile, $text);
            if ($box !== false) {
                $w = $box[2] - $box[0];
                $h = $box[1] - $box[7];
                imagettftext($im, $size, 0, (int) round($center - $w / 2 - $box[0]), (int) round($center + $h / 2 - $box[1]), $white, $fontFile, $text);
            }
        } else {
            // Built-in bitmap font fallback, scaled up.
            $small = imagecreatetruecolor(imagefontwidth(5) * strlen($text), imagefontheight(5));
            imagesavealpha($small, true);
            imagealphablending($small, false);
            imagefilledrectangle($small, 0, 0, 200, 50, imagecolorallocatealpha($small, 0, 0, 0, 127));
            imagestring($small, 5, 0, 0, $text, imagecolorallocate($small, 0xf8, 0xfa, 0xfc));
            $factor = (int) ($big * 0.14 / imagefontheight(5));
            $w = imagesx($small) * $factor;
            $h = imagesy($small) * $factor;
            imagecopyresized($im, $small, (int) ($center - $w / 2), (int) ($center - $h / 2), 0, 0, $w, $h, imagesx($small), imagesy($small));
            imagedestroy($small);
        }

        // Downscale for smooth edges
        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefilledrectangle($out, 0, 0, self::SIZE, self::SIZE, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, self::SIZE, self::SIZE, $big, $big);

        $dir = dirname($outputFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create the image folder.');
        }
        $ok = imagepng($out, $outputFile, 9);
        imagedestroy($im);
        imagedestroy($out);
        if (!$ok) {
            throw new RuntimeException('Cannot write the ring image.');
        }
    }

    /**
     * @param list<float> $points
     */
    private static function polygon(\GdImage $im, array $points, int $color): void
    {
        imagefilledpolygon($im, array_map(static fn (float $v): int => (int) round($v), $points), $color);
    }

    /** Data URI for the HTML preview context. */
    public static function dataUri(string $file): string
    {
        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($file));
    }
}
