<?php
/** @var int|null $size */
/** @var string|null $id */
$size = $size ?? 40;
$gid = 'lm-' . preg_replace('/[^a-z0-9-]/i', '', $id ?? 'a');
?>
<svg class="logo-mark" width="<?= (int) $size ?>" height="<?= (int) $size ?>" viewBox="0 0 64 64" fill="none" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="<?= e($gid) ?>" x1="10" y1="4" x2="58" y2="60" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#6aa8ff"/>
            <stop offset="1" stop-color="#2f6ff0"/>
        </linearGradient>
    </defs>
    <path d="M30 56H16a6 6 0 0 1-6-6V12a6 6 0 0 1 6-6h20l14 14v9" stroke="url(#<?= e($gid) ?>)" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M36 6v9a5 5 0 0 0 5 5h9z" fill="url(#<?= e($gid) ?>)"/>
    <g transform="translate(29.5 29.5) scale(1.3)" stroke="url(#<?= e($gid) ?>)" stroke-width="3.1" stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
        <path d="M21 3v5h-5"/>
        <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
        <path d="M8 16H3v5"/>
    </g>
</svg>
