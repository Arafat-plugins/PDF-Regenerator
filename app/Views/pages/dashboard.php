<?php
/** @var \App\Auth\User $user */
/** @var array{uploads:int, profiles:int, generated:int, total:int} $stats */
/** @var list<array<string, mixed>> $recent */
/** @var string $latestKind */
$cards = [
    ['value' => $stats['uploads'], 'label' => 'Uploaded PDFs', 'icon' => 'file-text', 'tone' => 'blue'],
    ['value' => $stats['profiles'], 'label' => 'Website Profiles', 'icon' => 'globe', 'tone' => 'purple'],
    ['value' => $stats['generated'], 'label' => 'Generated Files', 'icon' => 'file-check', 'tone' => 'green'],
    ['value' => $stats['total'], 'label' => 'Total Files', 'icon' => 'database', 'tone' => 'orange'],
];
$actions = [
    ['href' => 'upload.php', 'title' => 'Upload PDF', 'sub' => 'Add your PDF file', 'icon' => 'upload', 'tone' => 'blue'],
    ['href' => 'templates.php', 'title' => 'Browse Templates', 'sub' => 'Choose a template', 'icon' => 'layout-grid', 'tone' => 'purple'],
    ['href' => 'downloads.php', 'title' => 'View Generated Files', 'sub' => 'Check your results', 'icon' => 'file-text', 'tone' => 'green'],
    ['href' => 'settings.php', 'title' => 'Settings', 'sub' => 'Manage your account', 'icon' => 'settings', 'tone' => 'orange'],
];
?>
<header class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-sub">Welcome back, <?= e($user->displayName()) ?>! Here’s what’s happening with your PDF files.</p>
    </div>
</header>

<section class="stats" aria-label="File statistics">
    <?php foreach ($cards as $card): ?>
        <article class="card stat-card">
            <span class="icon-tile icon-tile--<?= e($card['tone']) ?>"><?= icon($card['icon'], '', 24) ?></span>
            <p class="stat-card__value"><?= e(number_format($card['value'])) ?></p>
            <p class="stat-card__label"><?= e($card['label']) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<section class="card card--pad-0 recent" aria-labelledby="recent-title">
    <div class="card__head">
        <h2 class="card__title" id="recent-title">Recent Files</h2>
        <?php if ($recent !== []): ?>
            <a class="link" href="<?= e(url($latestKind === 'generated' ? 'downloads.php' : 'upload.php')) ?>">View All</a>
        <?php endif; ?>
    </div>

    <?php if ($recent === []): ?>
        <div class="empty-state">
            <span class="empty-state__art" aria-hidden="true">
                <span class="empty-state__sheet empty-state__sheet--back"></span>
                <span class="empty-state__sheet"><?= icon('file-up', '', 30) ?></span>
            </span>
            <h3 class="empty-state__title">No generated PDFs yet.</h3>
            <p class="empty-state__text">Upload a source PDF and generate your first report.</p>
            <a class="btn btn-primary" href="<?= e(url('upload.php')) ?>"><?= icon('upload', '', 18) ?><span>Upload PDF</span></a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table table--recent">
                <thead>
                <tr>
                    <th scope="col" class="table__type">Type</th>
                    <th scope="col">Name</th>
                    <th scope="col">Status</th>
                    <th scope="col">Date</th>
                    <th scope="col" class="table__actions-head">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $row): $gen = $row['kind'] === 'generated'; ?>
                    <tr>
                        <td class="table__type"><span class="file-icon file-icon--<?= $gen ? 'purple' : 'blue' ?>" title="<?= $gen ? 'Generated PDF' : 'Uploaded PDF' ?>"><?= icon($gen ? 'file-check' : 'file-text', '', 22) ?></span><span class="sr-only"><?= $gen ? 'Generated PDF' : 'Uploaded PDF' ?></span></td>
                        <td class="table__name">
                            <span class="recent__name" title="<?= e($row['name']) ?>"><?= e($row['name']) ?></span>
                            <span class="recent__sub" title="<?= e($row['sub']) ?>"><?= e($row['sub']) ?></span>
                        </td>
                        <td><span class="badge badge--<?= e($row['status_tone']) ?>"><?= e($row['status_label']) ?></span></td>
                        <td class="table__muted recent__date"><time datetime="<?= e($row['created_at']) ?>"><?= e($row['created_label']) ?></time></td>
                        <td class="table__actions">
                            <div class="dropdown" data-dropdown>
                                <button class="icon-btn" type="button" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Actions for <?= e($row['name']) ?>"><?= icon('ellipsis', '', 18) ?></button>
                                <div class="dropdown__menu dropdown__menu--compact" role="menu" hidden>
                                    <?php foreach ($row['actions'] as $action): ?>
                                        <a class="dropdown__item" role="menuitem" href="<?= e($action['href']) ?>"<?= !empty($action['new']) ? ' target="_blank" rel="noopener"' : '' ?>><?= icon($action['icon'], '', 17) ?><span><?= e($action['label']) ?></span></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="dash-bottom">
    <section class="card quick-actions" aria-labelledby="qa-title">
        <h2 class="card__title" id="qa-title">Quick Actions</h2>
        <div class="quick-actions__grid">
            <?php foreach ($actions as $action): ?>
                <a class="action-card" href="<?= e(url($action['href'])) ?>">
                    <span class="icon-tile icon-tile--<?= e($action['tone']) ?>"><?= icon($action['icon'], '', 24) ?></span>
                    <span class="action-card__title"><?= e($action['title']) ?></span>
                    <span class="action-card__sub"><?= e($action['sub']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?= partial('how-it-works') ?>
</div>
