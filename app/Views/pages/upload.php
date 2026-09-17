<?php
/** @var list<array<string, mixed>> $rows */
/** @var int $maxSize */
/** @var list<array<string, mixed>> $profiles */
$maxLabel = format_bytes($maxSize);
$steps = [
    'Upload your PDF file and choose its website profile (data will be extracted).',
    'Select a template, logo and colors (saved profiles fill them in).',
    'Review & edit the data (remove or modify if needed).',
    'Generate your final PDF and save it to your files.',
];
$pdfIcon = partial('pdf-icon');
?>
<header class="page-header page-header--icon">
    <span class="icon-tile icon-tile--blue icon-tile--hero" aria-hidden="true"><?= icon('cloud-upload', '', 34) ?></span>
    <div>
        <h1 class="page-title">Upload PDF</h1>
        <p class="page-sub">Upload your PDF file to extract data and create a new template.</p>
    </div>
</header>

<div class="upload-grid">
    <section class="card upload-card" aria-label="Upload a PDF">
        <div class="dropzone" data-dropzone
             data-endpoint="<?= e(url('upload.php')) ?>"
             data-extract-url="<?= e(url('extract.php')) ?>"
             data-editor-url="<?= e(url('editor.php')) ?>"
             data-max-size="<?= (int) $maxSize ?>"
             data-max-label="<?= e($maxLabel) ?>">
            <input class="sr-only" type="file" id="pdf-input" name="pdf" accept="application/pdf,.pdf" data-file-input tabindex="-1">
            <span class="dropzone__icon" aria-hidden="true"><?= icon('cloud-upload', '', 68) ?></span>
            <h2 class="dropzone__title">Drag &amp; Drop your PDF here</h2>
            <p class="dropzone__text">or click to browse</p>
            <p class="dropzone__hint">Only PDF files are allowed <span class="nowrap">(Max: <?= e($maxLabel) ?>)</span></p>
            <button class="btn btn-primary dropzone__button" type="button" data-choose>
                <?= icon('folder-open', '', 20) ?><span>Choose File</span>
            </button>
            <p class="dropzone__drop-label" aria-hidden="true"><?= icon('file-down', '', 22) ?> Drop to upload</p>
        </div>

        <div class="file-chip" data-file-chip hidden aria-live="polite">
            <span class="file-chip__status" data-chip-icon></span>
            <div class="file-chip__body">
                <p class="file-chip__name" data-chip-name></p>
                <p class="file-chip__meta" data-chip-meta></p>
                <div class="progress" data-chip-progress hidden><span class="progress__bar" data-chip-bar></span></div>
            </div>
            <div class="file-chip__actions" data-chip-actions></div>
            <button class="icon-btn file-chip__close" type="button" data-chip-close aria-label="Dismiss"><?= icon('x', '', 18) ?></button>
        </div>

        <section class="profile-step" data-profile-step hidden aria-labelledby="profile-step-title">
            <div class="profile-step__head">
                <span class="profile-step__num" aria-hidden="true">2</span>
                <div>
                    <h2 class="profile-step__title" id="profile-step-title">Website Profile</h2>
                    <p class="profile-step__sub" data-profile-file></p>
                </div>
            </div>
            <div class="profile-step__body" data-profile-body></div>
            <div class="profile-step__foot">
                <p class="profile-step__note"><?= icon('info', '', 15) ?><span data-profile-note>Choose a profile to continue.</span></p>
                <button class="btn btn-primary" type="button" data-profile-continue disabled>
                    <span>Extract &amp; Continue</span><span class="btn__trail"><?= icon('arrow-right', '', 18) ?></span>
                </button>
            </div>
        </section>
    </section>

    <aside class="card how-it-works how-it-works--upload" aria-labelledby="hiw-upload-title">
        <div class="how-it-works__head">
            <span class="icon-tile icon-tile--blue icon-tile--round"><?= icon('lightbulb', '', 22) ?></span>
            <h2 class="card__title" id="hiw-upload-title">How it works?</h2>
        </div>
        <ol class="steps steps--roomy">
            <?php foreach ($steps as $i => $step): ?>
                <li><span class="steps__num"><?= $i + 1 ?></span><span><?= e($step) ?></span></li>
            <?php endforeach; ?>
        </ol>
        <div class="info-box">
            <?= icon('info', '', 24) ?>
            <p>The uploaded file will be saved in your own folder on the server for future use.</p>
        </div>
    </aside>
</div>

<section class="card card--pad-0 uploads" aria-labelledby="uploads-title" data-uploads>
    <div class="card__head">
        <div class="card__head-main">
            <h2 class="card__title" id="uploads-title">Your Uploaded Files</h2>
            <span class="count-pill" data-uploads-count><?= count($rows) ?></span>
        </div>
        <label class="search" <?= $rows === [] ? 'hidden' : '' ?> data-uploads-search-wrap>
            <?= icon('search', 'search__icon', 18) ?>
            <span class="sr-only">Search uploaded files</span>
            <input type="search" placeholder="Search files…" data-uploads-search autocomplete="off">
        </label>
    </div>

    <div class="empty-state" data-uploads-empty <?= $rows === [] ? '' : 'hidden' ?>>
        <span class="empty-state__art" aria-hidden="true">
            <span class="empty-state__sheet empty-state__sheet--back"></span>
            <span class="empty-state__sheet"><?= icon('file-up', '', 30) ?></span>
        </span>
        <h3 class="empty-state__title">No PDFs uploaded yet.</h3>
        <p class="empty-state__text">Upload your first source PDF to get started.</p>
        <button class="btn btn-primary" type="button" data-choose><?= icon('upload', '', 18) ?><span>Upload PDF</span></button>
    </div>

    <div class="empty-state empty-state--compact" data-uploads-nomatch hidden>
        <h3 class="empty-state__title">No files match your search.</h3>
        <p class="empty-state__text">Try a different name.</p>
    </div>

    <div class="table-wrap" data-uploads-table <?= $rows === [] ? 'hidden' : '' ?>>
        <table class="table table--files">
            <thead>
            <tr>
                <th scope="col">File Name</th>
                <th scope="col">Status</th>
                <th scope="col">Size</th>
                <th scope="col">Uploaded Date</th>
                <th scope="col" class="table__actions-head">Actions</th>
            </tr>
            </thead>
            <tbody data-uploads-body>
            <?php foreach ($rows as $row): ?>
                <?= partial('upload-row', ['row' => $row, 'pdfIcon' => $pdfIcon]) ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-footer" data-uploads-footer <?= $rows === [] ? 'hidden' : '' ?>>
        <p class="table-footer__info" data-page-info></p>
        <div class="pager" data-pager>
            <button class="icon-btn icon-btn--bordered" type="button" data-page-prev aria-label="Previous page"><?= icon('chevron-left', '', 18) ?></button>
            <span class="pager__label" data-page-label></span>
            <button class="icon-btn icon-btn--bordered" type="button" data-page-next aria-label="Next page"><?= icon('chevron-right', '', 18) ?></button>
        </div>
    </div>

    <?= partial('profiles-data', ['profiles' => $profiles]) ?>
    <template data-row-template>
        <?= partial('upload-row', ['row' => null, 'pdfIcon' => $pdfIcon]) ?>
    </template>
</section>
