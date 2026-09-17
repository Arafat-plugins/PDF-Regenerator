<?php
/**
 * Generated Files (Part D §11, mockup 09_24_48).
 *
 * @var list<array<string, mixed>> $rows
 * @var array<string, mixed>|null $flash
 * @var array<string, string> $templates template id => name
 */
?>
<header class="page-header page-header--icon">
    <span class="icon-tile icon-tile--blue icon-tile--hero" aria-hidden="true"><?= icon('file-text', '', 32) ?></span>
    <div>
        <h1 class="page-title">Generated Files</h1>
        <p class="page-sub">Here are all your generated PDF files. You can download, preview, or delete them anytime.</p>
    </div>
</header>

<?php if ($flash !== null): ?>
    <div class="success-banner" role="status" data-flash>
        <span class="success-banner__icon" aria-hidden="true"><?= icon('check', '', 26) ?></span>
        <div class="success-banner__text">
            <p class="success-banner__title">PDF Generated Successfully!</p>
            <p class="success-banner__sub">Your file <strong><?= e($flash['file_name']) ?></strong> has been created and saved on the server.</p>
        </div>
        <span class="success-banner__time"><?= e($flash['created_label']) ?></span>
        <button class="icon-btn success-banner__close" type="button" data-flash-close aria-label="Dismiss"><?= icon('x', '', 18) ?></button>
    </div>
<?php endif; ?>

<section class="card card--pad-0 generated" aria-label="Generated PDF files" data-generated data-new="<?= e($flash['id'] ?? '') ?>"
         data-list-url="<?= e(url('downloads.php?format=json')) ?>" data-endpoint="<?= e(url('downloads.php')) ?>">
    <div class="generated__tools" data-tools <?= $rows === [] ? 'hidden' : '' ?>>
        <label class="search generated__search">
            <?= icon('search', 'search__icon', 18) ?>
            <span class="sr-only">Search generated files</span>
            <input type="search" placeholder="Search by title or file name..." autocomplete="off" data-search>
        </label>
        <label class="select-wrap generated__filter">
            <span class="sr-only">Filter by template</span>
            <select class="select" data-filter>
                <option value="">All Templates</option>
                <?php foreach ($templates as $id => $name): ?>
                    <option value="<?= e($id) ?>"><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <?= icon('chevron-down', 'select-wrap__chevron', 18) ?>
        </label>
        <button class="btn btn-secondary generated__refresh" type="button" data-refresh><?= icon('refresh-cw', '', 17) ?><span>Refresh</span></button>
    </div>

    <div class="empty-state" data-empty <?= $rows === [] ? '' : 'hidden' ?>>
        <span class="empty-state__art" aria-hidden="true">
            <span class="empty-state__sheet empty-state__sheet--back"></span>
            <span class="empty-state__sheet"><?= icon('file-check', '', 30) ?></span>
        </span>
        <h2 class="empty-state__title">No generated PDFs yet.</h2>
        <p class="empty-state__text">Upload a PDF, review its data and click <strong>Generate PDF</strong> — your reports will be listed here.</p>
        <a class="btn btn-primary" href="<?= e(url('upload.php')) ?>"><?= icon('upload', '', 18) ?><span>Upload PDF</span></a>
    </div>

    <div class="empty-state empty-state--compact" data-nomatch hidden>
        <h2 class="empty-state__title">No files match your search.</h2>
        <p class="empty-state__text">Try another name or choose “All Templates”.</p>
        <button class="btn btn-secondary btn-sm" type="button" data-clear>Clear filters</button>
    </div>

    <div class="table-wrap" data-table <?= $rows === [] ? 'hidden' : '' ?>>
        <table class="table table--generated">
            <thead>
            <tr>
                <th scope="col" class="gen-num">#</th>
                <th scope="col">Title</th>
                <th scope="col">Template</th>
                <th scope="col">Original File</th>
                <th scope="col">Generated On</th>
                <th scope="col">File Size</th>
                <th scope="col" class="table__actions-head">Actions</th>
            </tr>
            </thead>
            <tbody data-body>
            <?php foreach ($rows as $row): ?>
                <?= partial('generated-row', ['row' => $row]) ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer" data-footer <?= $rows === [] ? 'hidden' : '' ?>>
        <p class="table-footer__info" data-page-info>Showing 1 to <?= min(10, count($rows)) ?> of <?= count($rows) ?> files</p>
        <nav class="pager pager--pages" aria-label="Pages" data-pager></nav>
    </div>

    <template data-row-template>
        <?= partial('generated-row', ['row' => null]) ?>
    </template>
</section>

<p class="generated__note"><?= icon('info', '', 15) ?><span>Files are saved in your own folder on the server.</span></p>
