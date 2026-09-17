<?php
/**
 * Review & Edit Data page (Part D §6, mockup 09_23_35 left side).
 *
 * The data table is rendered by editor.js from the JSON model; every edit is saved
 * through data.php.
 *
 * @var array<string, mixed> $source
 * @var array<string, mixed>|null $doc
 * @var array<string, mixed>|null $model
 * @var array<string, \App\Templates\Template> $templates
 * @var \App\Templates\Template|null $template
 * @var array<string, string>|null $palette
 * @var array<string, string>|null $theme
 * @var list<array<string, mixed>> $generated
 * @var list<array<string, mixed>> $profiles
 */
$pdfIcon = partial('pdf-icon');
$extracted = $doc !== null;
$review = $extracted ? (int) $doc['review_count'] : 0;
$steps = [
    ['label' => 'Upload PDF', 'state' => 'done'],
    ['label' => 'Select Template', 'state' => $template !== null ? 'done' : 'todo'],
    ['label' => 'Review & Edit Data', 'state' => $extracted ? 'current' : 'todo'],
    ['label' => 'Generate', 'state' => $generated !== [] ? 'done' : 'todo'],
];
$meta = array_filter([$source['size_label'], $source['pages_label'], 'Uploaded: ' . $source['created_label']]);
$brand = $model['branding'] ?? null;
$profileInfo = $brand['profile'] ?? ['mode' => 'none', 'name' => '', 'url' => ''];
$profileText = match ($profileInfo['mode']) {
    'existing' => 'Profile: ' . $profileInfo['name'],
    'new' => 'New website: ' . $profileInfo['name'],
    default => 'No website profile',
};
?>
<header class="page-header page-header--back">
    <a class="back-btn" href="<?= e(url('upload.php')) ?>" aria-label="Back to uploads"><?= icon('arrow-left', '', 22) ?></a>
    <div>
        <h1 class="page-title">Preview &amp; Edit Data</h1>
        <p class="page-sub">Review the extracted data, make changes if needed, and select a template to generate your PDF.</p>
    </div>
</header>

<ol class="stepper" aria-label="Progress">
    <?php foreach ($steps as $i => $step): ?>
        <li class="stepper__step stepper__step--<?= e($step['state']) ?>"<?= $step['state'] === 'current' ? ' aria-current="step"' : '' ?>>
            <span class="stepper__dot"><?= $step['state'] === 'done' ? icon('check', '', 16) : $i + 1 ?></span>
            <span class="stepper__label"><?= e($step['label']) ?><?= $step['state'] === 'done' ? icon('check', 'stepper__tick', 14) : '' ?></span>
        </li>
    <?php endforeach; ?>
</ol>

<div class="review-layout" data-review
     data-id="<?= e($source['id']) ?>"
     data-extract-url="<?= e(url('extract.php')) ?>"
     data-data-url="<?= e(url('data.php')) ?>"
     data-generate-url="<?= e(url('generate.php')) ?>"
     data-files-url="<?= e(url('downloads.php')) ?>">

    <div class="review-main">
        <section class="card source-card" aria-label="Source file">
            <div class="source-file">
                <span class="source-file__icon"><?= $pdfIcon ?></span>
                <div class="source-file__text">
                    <p class="source-file__name"><?= e($source['title']) ?></p>
                    <p class="source-file__meta"><?= e(implode(' • ', $meta)) ?></p>
                </div>
                <div class="source-file__actions">
                    <a class="btn btn-sm btn-secondary" href="<?= e($source['view_url']) ?>" target="_blank" rel="noopener"><?= icon('eye', '', 16) ?><span>View original</span></a>
                    <?php if ($extracted): ?>
                        <button class="btn btn-sm btn-secondary" type="button" data-reextract><?= icon('scan-text', '', 16) ?><span>Extract again</span></button>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-secondary" href="<?= e(url('upload.php')) ?>"><?= icon('refresh-cw', '', 16) ?><span>Change File</span></a>
                </div>
            </div>
            <?php if ($extracted): ?>
                <div class="chips">
                    <span class="chip chip--profile chip--<?= e($profileInfo['mode']) ?>" data-profile-chip>
                        <?= icon('globe', '', 15) ?><span data-profile-chip-text><?= e($profileText) ?></span>
                        <button class="chip__action" type="button" data-profile-change><?= $profileInfo['mode'] === 'none' ? 'Choose' : 'Change' ?></button>
                    </span>
                    <span class="chip"><?= icon('file-search', '', 15) ?><span><?= $doc['profile_name'] !== '' ? e($doc['profile_name']) : 'General document (no report rules matched)' ?></span></span>
                    <span class="chip"><?= icon('layers', '', 15) ?><span><?= e((string) $doc['page_count']) ?> pages read · <?= e($doc['engine_label']) ?></span></span>
                    <span class="chip"><?= icon('clock', '', 15) ?><span>Extracted <?= e($doc['extracted_label']) ?></span></span>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($extracted): $match = $brand['match'] ?? null; ?>
            <div class="notice notice--match" role="status" data-profile-match <?= $match === null ? 'hidden' : '' ?>>
                <span class="notice__icon"><?= icon('globe', '', 22) ?></span>
                <div class="notice__content">
                    <p class="notice__title">This looks like <strong data-match-name><?= e($match['name'] ?? '') ?></strong>. Apply that profile?</p>
                    <p class="notice__text">The website address in this PDF (<span data-match-host><?= e($match['host'] ?? '') ?></span>) belongs to a saved profile — its template, colors, logo and texts would be used.</p>
                </div>
                <div class="notice__actions">
                    <button class="btn btn-sm btn-primary" type="button" data-match-apply>Apply</button>
                    <button class="btn btn-sm btn-secondary" type="button" data-match-ignore>Ignore</button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$extracted): ?>
            <section class="card">
                <div class="empty-state">
                    <span class="empty-state__art" aria-hidden="true">
                        <span class="empty-state__sheet empty-state__sheet--back"></span>
                        <span class="empty-state__sheet"><?= icon('scan-text', '', 30) ?></span>
                    </span>
                    <h2 class="empty-state__title">No data extracted yet.</h2>
                    <p class="empty-state__text">Read this PDF to find its values — it only takes a moment.</p>
                    <button class="btn btn-primary" type="button" data-extract><?= icon('scan-text', '', 18) ?><span>Extract data</span></button>
                </div>
            </section>
        <?php else: ?>
            <?php foreach ($doc['notices'] as $notice): ?>
                <div class="notice notice--<?= e($notice['type'] === 'warning' ? 'warning' : 'info') ?>" role="status">
                    <span class="notice__icon"><?= icon($notice['type'] === 'warning' ? 'triangle-alert' : 'info', '', 22) ?></span>
                    <div class="notice__content">
                        <p class="notice__title"><?= e($notice['lines'][0]) ?></p>
                        <?php foreach (array_slice($notice['lines'], 1) as $line): ?>
                            <p class="notice__text"><?= e($line) ?></p>
                        <?php endforeach; ?>
                        <?php if ($notice['code'] === 'scanned'): ?>
                            <p class="notice__text">No values were guessed. You can still generate the report and enter the information manually.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <section class="card card--pad-0 editor-card" aria-labelledby="data-title" data-editor>
                <div class="editor-head">
                    <div class="editor-head__text">
                        <h2 class="card__title" id="data-title">Extracted Data</h2>
                        <p class="card__sub">You can edit, remove or rearrange the data as needed.</p>
                    </div>
                    <div class="editor-head__tools">
                        <label class="search editor-search">
                            <span class="sr-only">Search the extracted data</span>
                            <span class="search__icon"><?= icon('search', '', 17) ?></span>
                            <input type="search" placeholder="Search..." autocomplete="off" spellcheck="false" data-editor-search>
                        </label>
                        <button class="btn btn-secondary editor-reset" type="button" data-editor-reset><?= icon('rotate-ccw', '', 17) ?><span>Reset</span></button>
                    </div>
                </div>

                <div class="editor-bar">
                    <div class="editor-tabs" role="tablist" aria-label="Data views">
                        <button class="editor-tab is-active" type="button" role="tab" id="tab-edit" aria-selected="true" aria-controls="panel-edit" data-editor-tab="edit">
                            <?= icon('square-pen', '', 16) ?><span>Edit Data</span>
                        </button>
                        <button class="editor-tab" type="button" role="tab" id="tab-mapping" aria-selected="false" aria-controls="panel-mapping" tabindex="-1" data-editor-tab="mapping">
                            <?= icon('layout-template', '', 16) ?><span>Template Fields</span><span class="count-pill count-pill--warn" data-missing-count <?= ($model['mapping']['missing'] ?? 0) > 0 ? '' : 'hidden' ?>><?= e((string) ($model['mapping']['missing'] ?? 0)) ?></span>
                        </button>
                        <button class="editor-tab" type="button" role="tab" id="tab-removed" aria-selected="false" aria-controls="panel-removed" tabindex="-1" data-editor-tab="removed">
                            <?= icon('archive', '', 16) ?><span>Removed</span><span class="count-pill" data-removed-count><?= e((string) $model['removed_count']) ?></span>
                        </button>
                    </div>
                    <div class="editor-bar__status">
                        <span class="review-pill<?= $model['review_count'] === 0 ? ' review-pill--ok' : '' ?>" data-review-pill>
                            <?= $model['review_count'] === 0 ? icon('circle-check', '', 16) : icon('triangle-alert', '', 16) ?>
                            <span data-review-text><?= $model['review_count'] === 0 ? 'Nothing to review' : e((string) $model['review_count']) . ($model['review_count'] === 1 ? ' field needs' : ' fields need') . ' your review' ?></span>
                        </span>
                        <span class="save-state" data-save-state role="status" aria-live="polite">
                            <span class="save-state__icon"><?= icon('cloud-check', '', 16) ?></span><span data-save-text>Changes save automatically</span>
                        </span>
                    </div>
                </div>

                <div class="editor-panel" id="panel-edit" role="tabpanel" aria-labelledby="tab-edit" data-panel="edit">
                    <div class="edit-grid-head">
                        <span class="edit-grid-head__check"><input class="checkbox" type="checkbox" aria-label="Include every visible row in the report" title="Include / exclude every visible row" data-check-all></span>
                        <span>Field / Column</span>
                        <span>Extracted Data</span>
                        <span class="edit-grid-head__action">Action</span>
                    </div>
                    <div class="editor-body" data-editor-body>
                        <noscript><p class="editor-noscript">Please enable JavaScript to edit the extracted data.</p></noscript>
                    </div>
                    <div class="editor-empty" data-editor-empty hidden>
                        <span class="editor-empty__icon"><?= icon('search-x', '', 30) ?></span>
                        <p class="editor-empty__title">No matching data.</p>
                        <p class="editor-empty__text">Nothing matches “<span data-empty-term></span>”.</p>
                        <button class="btn btn-secondary btn-sm" type="button" data-search-clear>Clear search</button>
                    </div>

                    <form class="add-field" data-add-field hidden novalidate>
                        <span class="add-field__icon"><?= icon('circle-plus', '', 20) ?></span>
                        <div class="add-field__inputs">
                            <label class="add-field__control">
                                <span class="add-field__label">Field name</span>
                                <input class="edit-input" type="text" maxlength="60" placeholder="e.g. Account Manager" autocomplete="off" data-add-label required>
                            </label>
                            <label class="add-field__control add-field__control--value">
                                <span class="add-field__label">Value</span>
                                <input class="edit-input" type="text" maxlength="1000" placeholder="Value shown in the report" autocomplete="off" data-add-value>
                            </label>
                        </div>
                        <div class="add-field__actions">
                            <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus', '', 16) ?><span>Add field</span></button>
                            <button class="btn btn-secondary btn-sm" type="button" data-add-cancel>Cancel</button>
                        </div>
                        <p class="edit-error add-field__error" data-add-error hidden></p>
                    </form>

                    <div class="editor-foot">
                        <p class="editor-foot__count" data-editor-count>Showing <?= e((string) $model['item_count']) ?> of <?= e((string) $model['item_count']) ?> items</p>
                        <button class="btn btn-secondary btn-sm" type="button" data-add-open><?= icon('plus', '', 16) ?><span>Add field</span></button>
                    </div>
                </div>

                <div class="editor-panel" id="panel-mapping" role="tabpanel" aria-labelledby="tab-mapping" data-panel="mapping" hidden>
                    <div class="map-intro" data-map-intro></div>
                    <div class="map-head">
                        <span>Template field</span>
                        <span>Filled from</span>
                        <span>Value in the report</span>
                    </div>
                    <div class="map-list" data-map-body></div>
                    <div class="editor-empty" data-map-empty hidden>
                        <span class="editor-empty__icon"><?= icon('search-x', '', 30) ?></span>
                        <p class="editor-empty__title">No matching template fields.</p>
                    </div>
                </div>

                <div class="editor-panel" id="panel-removed" role="tabpanel" aria-labelledby="tab-removed" data-panel="removed" hidden>
                    <div class="removed-list" data-removed-body></div>
                    <div class="editor-empty" data-removed-empty hidden>
                        <span class="editor-empty__icon"><?= icon('archive', '', 30) ?></span>
                        <p class="editor-empty__title">No removed items.</p>
                        <p class="editor-empty__text">Fields and rows you delete appear here, and you can restore them at any time.</p>
                    </div>
                </div>

                <?php if ($doc['unmatched'] !== []): ?>
                    <details class="unmatched">
                        <summary><?= icon('chevron-right', 'unmatched__chevron', 16) ?><span>Other text in the PDF</span><span class="count-pill"><?= e((string) count($doc['unmatched'])) ?></span><span class="unmatched__hint">not used in the report</span></summary>
                        <ul class="unmatched__list">
                            <?php foreach ($doc['unmatched'] as $line): ?>
                                <li><span class="page-tag">p<?= e((string) $line['page']) ?></span><span><?= e($line['text']) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </section>

            <div class="tip-bar"><?= icon('lightbulb', '', 18) ?><p><strong>Tip:</strong> You can remove unwanted data or edit values before generating the final PDF. Unticked rows are left out of the report; rows marked <em>Please confirm</em> could not be read with certainty.</p></div>
            <script type="application/json" id="editor-model"><?= \App\Editor\EditorPresenter::json($model) ?></script>
        <?php endif; ?>

        <?= partial('profiles-data', ['profiles' => $profiles]) ?>

        <section class="card generated-card" aria-labelledby="gen-title">
            <div class="generated-card__head">
                <h2 class="card__title" id="gen-title">Generated PDFs</h2>
                <span class="count-pill" data-generated-count><?= count($generated) ?></span>
            </div>
            <p class="card__sub" data-generated-empty <?= $generated === [] ? '' : 'hidden' ?>>No generated documents yet.</p>
            <ul class="generated-list" data-generated-list>
                <?php foreach ($generated as $item): ?>
                    <li class="generated-item">
                        <span class="generated-item__icon"><?= icon('file-check', '', 20) ?></span>
                        <div class="generated-item__text">
                            <p class="generated-item__name" title="<?= e($item['file_name']) ?>"><?= e($item['file_name']) ?></p>
                            <p class="generated-item__meta"><?= e($item['created_label'] . ' • ' . $item['size_label']) ?></p>
                        </div>
                        <div class="generated-item__actions">
                            <a class="icon-btn icon-btn--bordered" href="<?= e($item['preview_url']) ?>" target="_blank" rel="noopener" aria-label="Preview PDF" title="Preview"><?= icon('eye', '', 17) ?></a>
                            <a class="icon-btn icon-btn--bordered" href="<?= e($item['download_url']) ?>" aria-label="Download PDF" title="Download"><?= icon('download', '', 17) ?></a>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <template data-generated-template>
                <li class="generated-item is-new">
                    <span class="generated-item__icon"><?= icon('file-check', '', 20) ?></span>
                    <div class="generated-item__text">
                        <p class="generated-item__name" data-g="name"></p>
                        <p class="generated-item__meta" data-g="meta"></p>
                    </div>
                    <div class="generated-item__actions">
                        <a class="icon-btn icon-btn--bordered" data-g="preview" href="#" target="_blank" rel="noopener" aria-label="Preview PDF" title="Preview"><?= icon('eye', '', 17) ?></a>
                        <a class="icon-btn icon-btn--bordered" data-g="download" href="#" aria-label="Download PDF" title="Download"><?= icon('download', '', 17) ?></a>
                    </div>
                </li>
            </template>
        </section>
    </div>

    <aside class="review-side">
        <section class="card tpl-chooser" aria-labelledby="tpl-title" data-tpl-chooser>
            <div class="tpl-chooser__head">
                <div>
                    <h2 class="card__title" id="tpl-title">Choose Template</h2>
                    <p class="card__sub">Select a template from your stock templates.</p>
                </div>
                <a class="link" href="<?= e(url('templates.php')) ?>">View All</a>
            </div>
            <?php if ($templates === []): ?>
                <p class="card__sub">No templates available.</p>
            <?php else: ?>
                <div class="tpl-cards" role="radiogroup" aria-labelledby="tpl-title">
                    <?php foreach ($templates as $tpl): $selected = $template !== null && $tpl->id === $template->id; ?>
                        <button class="tpl-card<?= $selected ? ' is-selected' : '' ?>" type="button" role="radio" aria-checked="<?= $selected ? 'true' : 'false' ?>"
                                tabindex="<?= $selected ? '0' : '-1' ?>" data-template="<?= e($tpl->id) ?>" title="<?= e($tpl->description) ?>" <?= $extracted ? '' : 'disabled' ?>>
                            <span class="tpl-card__thumb"><?= partial('template-thumb', ['tpl' => $tpl, 'thumbClass' => 'tpl-card__img']) ?></span>
                            <span class="tpl-card__check" aria-hidden="true"><?= icon('check', '', 13) ?></span>
                            <span class="tpl-card__name"><?= e($tpl->name) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php if ($template !== null && $theme !== null): ?>
                    <p class="tpl-chooser__foot">
                        <span class="swatch" style="background: <?= e($theme['theme_primary']) ?>;" data-palette-swatch></span>
                        <span><span data-template-name><?= e($template->name) ?></span> · <span data-palette-label><?= e($palette !== null && $palette['label'] !== '' ? $palette['label'] : 'Default colors') ?></span></span>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($extracted && $brand !== null && $template !== null): $logo = $brand['logo']; ?>
            <section class="card brand-card" aria-labelledby="brand-title" data-branding>
                <div class="brand-card__head">
                    <div>
                        <h2 class="card__title" id="brand-title">Branding</h2>
                        <p class="card__sub">Logo and colors for this report.</p>
                    </div>
                    <p class="brand-card__note" data-brand-note></p>
                </div>

                <div class="brand-row">
                    <span class="brand-row__label">Logo</span>
                    <div class="brand-logo">
                        <span class="brand-logo__thumb<?= $logo['url'] === '' ? ' is-empty' : '' ?>" data-logo-thumb>
                            <img src="<?= e($logo['url']) ?>" alt="" data-logo-img <?= $logo['url'] === '' ? 'hidden' : '' ?>>
                            <span class="brand-logo__none" data-logo-none <?= $logo['url'] === '' ? '' : 'hidden' ?>><?= icon('image', '', 20) ?></span>
                        </span>
                        <div class="brand-logo__text">
                            <p class="brand-logo__name" data-logo-label><?= e($logo['label']) ?></p>
                            <p class="brand-logo__hint"><?= e(\App\Profiles\LogoStore::LABEL) ?> · max <?= e($logo['max_label']) ?></p>
                        </div>
                        <div class="brand-logo__actions">
                            <input class="sr-only" type="file" accept="<?= e(\App\Profiles\LogoStore::ACCEPT) ?>" tabindex="-1" data-logo-input
                                   data-upload-url="<?= e(url('logo.php')) ?>" data-max-size="<?= (int) \App\Profiles\LogoStore::maxSize() ?>">
                            <button class="btn btn-sm btn-secondary" type="button" data-logo-upload><?= icon('upload', '', 16) ?><span data-logo-upload-text><?= $logo['mode'] === 'draft' || $logo['mode'] === 'profile' ? 'Replace' : 'Upload logo' ?></span></button>
                            <button class="btn btn-sm btn-secondary" type="button" data-logo-restore <?= $logo['can_restore'] ? '' : 'hidden' ?>><?= icon('rotate-ccw', '', 15) ?><span>Profile logo</span></button>
                            <button class="icon-btn icon-btn--bordered" type="button" data-logo-remove aria-label="Remove logo" title="Remove logo — use the template logo" <?= $logo['can_remove'] ? '' : 'hidden' ?>><?= icon('trash-2', '', 16) ?></button>
                        </div>
                    </div>
                </div>
                <p class="brand-error" data-logo-error role="alert" hidden></p>

                <div class="brand-row">
                    <span class="brand-row__label" id="palette-label">Colors</span>
                    <div class="brand-colors">
                        <div class="swatches" role="radiogroup" aria-labelledby="palette-label" data-swatches>
                            <?php foreach ($brand['palettes'] as $pal): $on = $brand['palette'] === $pal['id']; ?>
                                <button class="swatch-btn<?= $on ? ' is-selected' : '' ?>" type="button" role="radio" aria-checked="<?= $on ? 'true' : 'false' ?>"
                                        tabindex="<?= $on ? '0' : '-1' ?>" data-palette="<?= e($pal['id']) ?>" title="<?= e($pal['label']) ?>" aria-label="<?= e($pal['label']) ?>">
                                    <span class="swatch-btn__fill" style="background: linear-gradient(135deg, <?= e($pal['color']) ?> 0 50%, <?= e($pal['cover']) ?> 50% 100%);"></span>
                                </button>
                            <?php endforeach; $on = $brand['palette'] === 'custom'; ?>
                            <button class="swatch-btn swatch-btn--custom<?= $on ? ' is-selected' : '' ?>" type="button" role="radio" aria-checked="<?= $on ? 'true' : 'false' ?>"
                                    tabindex="<?= $on ? '0' : '-1' ?>" data-palette="custom" title="Custom colors" aria-label="Custom colors" aria-controls="custom-colors" aria-expanded="false">
                                <span class="swatch-btn__fill" data-custom-fill <?= $on ? 'style="background: linear-gradient(135deg, ' . e($brand['custom']['accent']) . ' 0 50%, ' . e($brand['custom']['cover']) . ' 50% 100%);"' : '' ?>><?= $on ? '' : icon('plus', '', 14) ?></span>
                            </button>
                        </div>
                        <span class="brand-colors__name" data-palette-name><?= e($model['template']['palette_label'] ?? '') ?></span>
                    </div>
                </div>

                <div class="custom-colors" id="custom-colors" data-custom-panel hidden>
                    <div class="custom-colors__fields">
                        <div class="color-field">
                            <label class="color-field__label" for="accent-hex">Accent color</label>
                            <span class="color-field__control">
                                <input class="color-field__picker" type="color" value="<?= e($brand['custom']['accent']) ?>" aria-label="Pick the accent color" data-color-picker="accent">
                                <input class="input color-field__hex" id="accent-hex" type="text" maxlength="7" spellcheck="false" autocomplete="off" value="<?= e($brand['custom']['accent']) ?>" data-color-hex="accent">
                            </span>
                        </div>
                        <div class="color-field">
                            <label class="color-field__label" for="cover-hex">Cover color</label>
                            <span class="color-field__control">
                                <input class="color-field__picker" type="color" value="<?= e($brand['custom']['cover']) ?>" aria-label="Pick the cover color" data-color-picker="cover">
                                <input class="input color-field__hex" id="cover-hex" type="text" maxlength="7" spellcheck="false" autocomplete="off" value="<?= e($brand['custom']['cover']) ?>" data-color-hex="cover">
                            </span>
                        </div>
                        <div class="color-field">
                            <label class="color-field__label" for="success-hex">Success color</label>
                            <span class="color-field__control">
                                <input class="color-field__picker" type="color" value="<?= e($brand['custom']['success']) ?>" aria-label="Pick the success color (check marks, OK badges)" data-color-picker="success">
                                <input class="input color-field__hex" id="success-hex" type="text" maxlength="7" spellcheck="false" autocomplete="off" value="<?= e($brand['custom']['success']) ?>" data-color-hex="success" title="Check marks, OK / ACTIVE badges and positive values">
                            </span>
                        </div>
                        <button class="btn btn-sm btn-primary custom-colors__apply" type="button" data-custom-apply><?= icon('check', '', 16) ?><span>Apply colors</span></button>
                    </div>
                    <p class="brand-error" data-custom-error role="alert" hidden></p>
                </div>

                <p class="brand-warning" data-contrast <?= $brand['light_cover'] ? '' : 'hidden' ?>><?= icon('triangle-alert', '', 16) ?><span>White cover text may be hard to read on this cover color.</span></p>
            </section>
        <?php endif; ?>

        <section class="card tpl-preview" aria-labelledby="preview-title" data-preview
                 data-src="<?= e($extracted ? url('live-preview.php?id=' . rawurlencode($source['id'])) : '') ?>"
                 data-draft-url="<?= e(url('draft.php')) ?>">
            <div class="tpl-preview__head">
                <h2 class="card__title" id="preview-title">Template Preview <span class="tpl-preview__sub">(with your data)</span></h2>
                <div class="tpl-preview__tools" role="group" aria-label="Preview zoom">
                    <button class="zoom-btn" type="button" data-zoom="-1" aria-label="Zoom out" title="Zoom out"><?= icon('zoom-out', '', 17) ?></button>
                    <output class="zoom-value" data-zoom-value aria-live="polite">100%</output>
                    <button class="zoom-btn" type="button" data-zoom="1" aria-label="Zoom in" title="Zoom in"><?= icon('zoom-in', '', 17) ?></button>
                    <button class="zoom-btn zoom-btn--full" type="button" data-fullscreen aria-label="Full screen preview" title="Full screen"><?= icon('maximize-2', '', 16) ?></button>
                </div>
            </div>
            <div class="tpl-preview__viewport" data-preview-viewport>
                <?php if ($extracted): ?>
                    <div class="tpl-preview__stage" data-preview-stage>
                        <iframe class="tpl-preview__frame" title="Template preview with your data" sandbox="allow-same-origin" referrerpolicy="same-origin" data-preview-frame></iframe>
                    </div>
                    <div class="tpl-preview__loading" data-preview-loading><span class="spinner"></span><span>Updating preview…</span></div>
                <?php else: ?>
                    <div class="tpl-preview__empty"><?= icon('eye-off', '', 26) ?><p>Extract the data to see your report here.</p></div>
                <?php endif; ?>
            </div>
            <div class="tpl-preview__foot">
                <p class="tpl-preview__note"><?= icon('info', '', 15) ?><span>Screen preview — the PDF is the exact result.</span></p>
                <button class="btn btn-secondary btn-sm" type="button" data-draft <?= $extracted && $template !== null ? '' : 'disabled' ?>><?= icon('file-search', '', 16) ?><span>Preview as PDF</span></button>
            </div>
            <div class="review-actions">
                <a class="btn btn-secondary" href="<?= e(url('upload.php')) ?>"><?= icon('arrow-left', '', 18) ?><span>Back</span></a>
                <button class="btn btn-primary btn-lg" type="button" data-generate <?= $extracted && $template !== null ? '' : 'disabled' ?>>
                    <?= icon('zap', '', 18) ?><span>Generate PDF</span>
                </button>
            </div>
            <button class="tpl-preview__close" type="button" data-fullscreen-close aria-label="Close full screen" hidden><?= icon('x', '', 20) ?></button>
        </section>
    </aside>
</div>
