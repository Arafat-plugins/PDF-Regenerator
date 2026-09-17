<?php
/**
 * Template thumbnail (preview.png through asset.php, or the neutral placeholder).
 * With $full = true the large preview shows every page (preview-full.png) when the template has it.
 *
 * @var \App\Templates\Template $tpl
 * @var string|null $thumbClass
 * @var bool|null $full
 */
$fullFile = !empty($full) ? $tpl->fullPreviewFile() : null;
$file = $fullFile ?? $tpl->previewFile();
$hasPreview = $file !== null;
$size = $hasPreview ? @getimagesize($file) : false;
$width = $size !== false ? (int) $size[0] : 600;
$height = $size !== false ? (int) $size[1] : 849;
?>
<img class="<?= e($thumbClass ?? 'tpl-thumb') ?>" loading="lazy" alt="" width="<?= $width ?>" height="<?= $height ?>"
     src="<?= e($hasPreview ? url('asset.php?kind=' . ($fullFile !== null ? 'full' : 'thumb') . '&t=' . rawurlencode($tpl->id) . '&v=' . (int) filemtime($file)) : asset('images/template-placeholder.svg')) ?>"
     <?= $hasPreview ? '' : 'data-placeholder' ?>>
