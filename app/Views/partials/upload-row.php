<?php
/**
 * One row of "Your Uploaded Files". With $row === null it renders the empty
 * template that app JS fills in (textContent only — never innerHTML).
 *
 * @var array<string, mixed>|null $row
 * @var string $pdfIcon
 */
$r = $row ?? [
    'id' => '', 'status' => 'uploaded', 'title' => '', 'original_name' => '', 'pages_label' => '', 'status_label' => '', 'status_tone' => 'green',
    'size_label' => '', 'created_label' => '', 'created_at' => '', 'view_url' => '#', 'download_url' => '#', 'has_text' => true,
];
$sub = trim(implode(' · ', array_filter([(string) $r['pages_label'], (string) $r['original_name'] !== (string) $r['title'] . '.pdf' ? (string) $r['original_name'] : ''])));
?>
<tr data-row data-id="<?= e($r['id']) ?>" data-title="<?= e($r['title']) ?>" data-status="<?= e($r['status'] ?? 'uploaded') ?>" data-search="<?= e(\App\Helpers\Text::lower($r['title'] . ' ' . $r['original_name'])) ?>">
    <td>
        <div class="file-cell">
            <?= $pdfIcon ?>
            <div class="file-cell__text">
                <span class="file-cell__title" data-f="title"><?= e($r['title']) ?></span>
                <span class="file-cell__sub" data-f="sub"><?= e($sub) ?></span>
            </div>
        </div>
    </td>
    <td><span class="badge badge--<?= e($r['status_tone']) ?>" data-f="status"><?= e($r['status_label']) ?></span></td>
    <td class="table__muted" data-f="size"><?= e($r['size_label']) ?></td>
    <td class="table__muted" data-f="created"><time datetime="<?= e($r['created_at']) ?>"><?= e($r['created_label']) ?></time></td>
    <td class="table__actions">
        <div class="row-actions">
            <button class="btn btn-sm btn-primary" type="button" data-action="use"><?= icon('arrow-right', '', 16) ?><span>Use</span></button>
            <a class="btn btn-sm btn-secondary" data-f="download" href="<?= e($r['download_url']) ?>"><?= icon('download', '', 16) ?><span>Download</span></a>
            <button class="btn btn-sm btn-danger-soft" type="button" data-action="delete"><?= icon('trash-2', '', 16) ?><span>Delete</span></button>
            <div class="dropdown" data-dropdown>
                <button class="icon-btn" type="button" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="More actions"><?= icon('ellipsis', '', 18) ?></button>
                <div class="dropdown__menu dropdown__menu--compact" role="menu" hidden>
                    <a class="dropdown__item" role="menuitem" data-f="view" href="<?= e($r['view_url']) ?>" target="_blank" rel="noopener"><?= icon('eye', '', 17) ?><span>View PDF</span></a>
                    <button class="dropdown__item" type="button" role="menuitem" data-action="rename"><?= icon('pencil', '', 17) ?><span>Rename</span></button>
                    <a class="dropdown__item" role="menuitem" data-f="download2" href="<?= e($r['download_url']) ?>"><?= icon('download', '', 17) ?><span>Download original</span></a>
                    <button class="dropdown__item dropdown__item--danger" type="button" role="menuitem" data-action="delete"><?= icon('trash-2', '', 17) ?><span>Delete</span></button>
                </div>
            </div>
        </div>
    </td>
</tr>
