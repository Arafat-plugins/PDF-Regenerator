<?php

declare(strict_types=1);

namespace App\Files;

/**
 * Shapes a generated.json record for the UI. Values are plain strings — escape on output.
 */
final class GeneratedPresenter
{
    /**
     * Browser PDF viewers (Chrome, Edge, Firefox) open the report at actual size (100 %), so the
     * 980 px wide report looks exactly as wide as the HTML design instead of being shrunk to fit.
     */
    public const VIEW_ZOOM = '#zoom=100';

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function row(array $item): array
    {
        $id = (string) $item['id'];
        $template = (string) ($item['template'] ?? '');
        $source = ($item['source_available'] ?? true) ? (string) ($item['source_id'] ?? '') : '';

        return [
            'id' => $id,
            'title' => (string) ($item['title'] ?? ''),
            'file_name' => (string) ($item['stored_filename'] ?? ''),
            'template' => (string) ($item['template'] ?? ''),
            'template_name' => (string) ($item['template_name'] ?? $item['template'] ?? ''),
            'source_original_name' => (string) ($item['source_original_name'] ?? ''),
            'reporting_period' => (string) ($item['reporting_period'] ?? ''),
            'size' => (int) ($item['size'] ?? 0),
            'size_label' => format_bytes((int) ($item['size'] ?? 0)),
            'created_at' => (string) ($item['created_at'] ?? ''),
            'created_label' => format_datetime((string) ($item['created_at'] ?? '')),
            'preview_url' => url('preview.php?type=generated&id=' . rawurlencode($id)) . self::VIEW_ZOOM,
            'download_url' => url('preview.php?type=generated&id=' . rawurlencode($id) . '&download=1'),
            'tone' => self::tone($template),
            'missing' => (bool) ($item['missing'] ?? false),
            'source_id' => $source,
            'editor_url' => $source !== '' ? url('editor.php?id=' . rawurlencode($source)) : '',
            'search' => \App\Helpers\Text::lower(implode(' ', [
                (string) ($item['title'] ?? ''), (string) ($item['stored_filename'] ?? ''), (string) ($item['source_original_name'] ?? ''),
            ])),
        ];
    }

    /** A stable badge / icon color per template. */
    public static function tone(string $template): string
    {
        $known = ['woodford-weekly-maintenance' => 'red', 'simple-light-report' => 'blue'];
        if (isset($known[$template])) {
            return $known[$template];
        }
        $tones = ['purple', 'green', 'orange', 'teal', 'blue'];

        return $tones[crc32($template) % count($tones)];
    }
}
