<?php

declare(strict_types=1);

namespace App\Files;

/**
 * Shapes an uploads.json record for the UI (server-rendered rows and AJAX responses).
 * Values are plain strings — escaping happens where they are printed.
 */
final class UploadPresenter
{
    public const STATUS = [
        'uploaded' => ['label' => 'Uploaded', 'tone' => 'green'],
        'processing' => ['label' => 'Processing', 'tone' => 'amber'],
        'extracted' => ['label' => 'Extracted', 'tone' => 'purple'],
        'editing' => ['label' => 'Editing', 'tone' => 'blue'],
        'generated' => ['label' => 'Generated', 'tone' => 'blue'],
        'failed' => ['label' => 'Failed', 'tone' => 'red'],
    ];

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function row(array $item): array
    {
        $id = (string) $item['id'];
        $status = (string) ($item['status'] ?? 'uploaded');
        $meta = self::STATUS[$status] ?? self::STATUS['uploaded'];
        $pages = (int) ($item['pages'] ?? 0);

        return [
            'id' => $id,
            'title' => (string) ($item['title'] ?? ''),
            'original_name' => (string) ($item['original_name'] ?? ''),
            'size' => (int) ($item['size'] ?? 0),
            'size_label' => format_bytes((int) ($item['size'] ?? 0)),
            'pages' => $pages,
            'pages_label' => $pages > 0 ? $pages . ' page' . ($pages === 1 ? '' : 's') : '',
            'has_text' => (bool) ($item['has_text'] ?? true),
            'status' => $status,
            'status_label' => $meta['label'],
            'status_tone' => $meta['tone'],
            'created_at' => (string) ($item['created_at'] ?? ''),
            'created_label' => format_datetime((string) ($item['created_at'] ?? '')),
            'view_url' => url('preview.php?type=source&id=' . rawurlencode($id)),
            'download_url' => url('preview.php?type=source&id=' . rawurlencode($id) . '&download=1'),
        ];
    }
}
