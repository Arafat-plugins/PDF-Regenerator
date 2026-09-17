<?php
/**
 * One row of the Generated Files table. With $row === null it renders the empty template that
 * downloads.js fills in (textContent only).
 *
 * @var array<string, mixed>|null $row
 */
$r = $row ?? [
    'id' => '', 'title' => '', 'file_name' => '', 'template' => '', 'template_name' => '', 'source_original_name' => '',
    'size_label' => '', 'created_at' => '', 'created_label' => '', 'preview_url' => '#', 'download_url' => '#',
    'tone' => 'blue', 'missing' => false, 'editor_url' => '', 'search' => '', 'reporting_period' => '',
];
?>
<tr data-row data-id="<?= e($r['id']) ?>" data-title="<?= e($r['title']) ?>" data-template="<?= e($r['template']) ?>" data-search="<?= e($r['search']) ?>"<?= $r['missing'] ? ' data-missing' : '' ?>>
    <td class="gen-num" data-f="num"></td>
    <td>
        <div class="file-cell">
            <span class="gen-icon gen-icon--<?= e($r['tone']) ?>" data-f="icon" aria-hidden="true"><?= icon('file-text', '', 20) ?></span>
            <div class="file-cell__text">
                <span class="file-cell__title" data-f="title" title="<?= e($r['title']) ?>"><?= e($r['title']) ?></span>
                <span class="file-cell__sub" data-f="file" title="<?= e($r['file_name']) ?>"><?= e($r['file_name']) ?></span>
            </div>
        </div>
    </td>
    <td><span class="badge badge--<?= e($r['tone']) ?> gen-template" data-f="template"><?= e($r['template_name']) ?></span></td>
    <td class="gen-original"><span data-f="original" title="<?= e($r['source_original_name']) ?>"><?= e($r['source_original_name'] !== '' ? $r['source_original_name'] : '—') ?></span></td>
    <td class="table__muted gen-date"><time data-f="created" datetime="<?= e($r['created_at']) ?>"><?= e($r['created_label']) ?></time></td>
    <td class="table__muted gen-size" data-f="size"><?= e($r['size_label']) ?></td>
    <td class="table__actions">
        <div class="row-actions">
            <span class="badge badge--red gen-missing" data-f="missing" <?= $r['missing'] ? '' : 'hidden' ?>>File missing</span>
            <a class="btn btn-sm btn-soft-blue" data-f="view" aria-label="View PDF" title="View" href="<?= e($r['preview_url']) ?>" target="_blank" rel="noopener" <?= $r['missing'] ? 'hidden' : '' ?>><?= icon('eye', '', 16) ?><span>View</span></a>
            <a class="btn btn-sm btn-soft-green" data-f="download" aria-label="Download PDF" title="Download" href="<?= e($r['download_url']) ?>" <?= $r['missing'] ? 'hidden' : '' ?>><?= icon('download', '', 16) ?><span>Download</span></a>
            <button class="btn btn-sm btn-danger-soft" type="button" data-action="delete" aria-label="Delete PDF" title="Delete"><?= icon('trash-2', '', 16) ?><span>Delete</span></button>
            <div class="dropdown" data-dropdown>
                <button class="icon-btn" type="button" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="More actions"><?= icon('ellipsis', '', 18) ?></button>
                <div class="dropdown__menu dropdown__menu--compact" role="menu" hidden>
                    <button class="dropdown__item" type="button" role="menuitem" data-action="rename"><?= icon('pencil', '', 17) ?><span>Rename</span></button>
                    <a class="dropdown__item" role="menuitem" data-f="editor" href="<?= e($r['editor_url'] !== '' ? $r['editor_url'] : '#') ?>" <?= $r['editor_url'] === '' ? 'hidden' : '' ?>><?= icon('square-pen', '', 17) ?><span>Edit data &amp; regenerate</span></a>
                    <button class="dropdown__item" type="button" role="menuitem" data-action="copy"><?= icon('copy', '', 17) ?><span>Copy file name</span></button>
                    <button class="dropdown__item dropdown__item--danger" type="button" role="menuitem" data-action="delete"><?= icon('trash-2', '', 17) ?><span>Delete</span></button>
                </div>
            </div>
        </div>
    </td>
</tr>
