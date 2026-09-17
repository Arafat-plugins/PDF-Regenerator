<?php
/**
 * Stock Templates gallery (Part D §13).
 *
 * @var list<array<string, mixed>> $cards
 */
?>
<header class="page-header page-header--icon">
    <span class="icon-tile icon-tile--blue icon-tile--hero" aria-hidden="true"><?= icon('layout-grid', '', 32) ?></span>
    <div>
        <h1 class="page-title">Stock Templates</h1>
        <p class="page-sub">Premium templates for your reports. You choose one on the Preview &amp; Edit page after uploading a PDF.</p>
    </div>
</header>

<?php if ($cards === []): ?>
    <section class="card">
        <div class="empty-state">
            <span class="empty-state__art" aria-hidden="true">
                <span class="empty-state__sheet empty-state__sheet--back"></span>
                <span class="empty-state__sheet"><?= icon('layout-template', '', 30) ?></span>
            </span>
            <h2 class="empty-state__title">No templates available.</h2>
            <p class="empty-state__text">Add a template folder to <code>stock-templates/</code> — see the README.</p>
        </div>
    </section>
<?php else: ?>
    <div class="gallery" data-gallery>
        <?php foreach ($cards as $i => $card): $tpl = $card['template']; ?>
            <article class="card gallery-card">
                <button class="gallery-card__thumb" type="button" data-open="<?= e($tpl->id) ?>" aria-label="Show a large preview of <?= e($tpl->name) ?>">
                    <?= partial('template-thumb', ['tpl' => $tpl, 'thumbClass' => 'gallery-card__img']) ?>
                    <span class="gallery-card__zoom"><?= icon('zoom-in', '', 18) ?><span>Preview</span></span>
                </button>
                <div class="gallery-card__body">
                    <h2 class="gallery-card__name"><?= e($tpl->name) ?></h2>
                    <p class="gallery-card__desc"><?= e($tpl->description) ?></p>
                    <ul class="gallery-card__facts">
                        <li><?= icon('file-text', '', 15) ?><span><?= e(\App\Templates\Template::pageLabel($tpl)) ?></span></li>
                        <li><?= icon('list', '', 15) ?><span><?= e((string) $card['field_count']) ?> fields</span></li>
                        <li><?= icon('file-search', '', 15) ?><span><?= e($card['made_for']) ?></span></li>
                    </ul>
                    <?php if ($card['palettes'] !== []): ?>
                        <div class="gallery-card__palettes" aria-label="Color palettes">
                            <?php foreach ($card['palettes'] as $palette): ?>
                                <span class="swatch<?= $palette['default'] ? ' swatch--default' : '' ?>" style="background: <?= e($palette['color']) ?>;" title="<?= e($palette['label'] . ($palette['default'] ? ' (default)' : '')) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <template data-detail="<?= e($tpl->id) ?>">
                <div class="lightbox__media"><?= partial('template-thumb', ['tpl' => $tpl, 'thumbClass' => 'lightbox__img', 'full' => true]) ?></div>
                <div class="lightbox__info">
                    <h2 class="lightbox__title" id="lightbox-title"><?= e($tpl->name) ?></h2>
                    <p class="lightbox__desc"><?= e($tpl->description) ?></p>
                    <ul class="gallery-card__facts">
                        <li><?= icon('file-text', '', 15) ?><span><?= e(\App\Templates\Template::pageLabel($tpl)) ?></span></li>
                        <li><?= icon('file-search', '', 15) ?><span><?= e($card['made_for']) ?></span></li>
                    </ul>
                    <?php if ($card['palettes'] !== []): ?>
                        <p class="lightbox__label">Colors</p>
                        <ul class="lightbox__palettes">
                            <?php foreach ($card['palettes'] as $palette): ?>
                                <li><span class="swatch" style="background: <?= e($palette['color']) ?>;"></span><span><?= e($palette['label']) ?><?= $palette['default'] ? ' <em>(default)</em>' : '' ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <p class="lightbox__label">Fields (<?= e((string) $card['field_count']) ?>)</p>
                    <div class="lightbox__fields">
                        <?php foreach ($card['fields'] as $section => $labels): ?>
                            <p class="lightbox__section"><?= e($section) ?></p>
                            <p class="lightbox__chips"><?php foreach ($labels as $label): ?><span class="chip chip--sm"><?= e($label) ?></span><?php endforeach; ?></p>
                        <?php endforeach; ?>
                    </div>
                    <p class="lightbox__note"><?= icon('info', '', 15) ?><span>To use this template, upload a PDF and pick it on the Preview &amp; Edit page.</span></p>
                    <a class="btn btn-primary" href="<?= e(url('upload.php')) ?>"><?= icon('upload', '', 18) ?><span>Upload a PDF</span></a>
                </div>
            </template>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
