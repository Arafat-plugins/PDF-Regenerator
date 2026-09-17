<?php
/** @var string $title */
/** @var string $subtitle */
/** @var string $icon */
/** @var string $phase */
/** @var string $headline */
/** @var list<string> $bullets */
?>
<header class="page-header">
    <div>
        <h1 class="page-title"><?= e($title) ?></h1>
        <p class="page-sub"><?= e($subtitle) ?></p>
    </div>
</header>

<section class="card coming-soon">
    <span class="icon-tile icon-tile--blue icon-tile--xl"><?= icon($icon, '', 32) ?></span>
    <span class="badge badge--blue badge--lg"><?= icon('clock', '', 14) ?><span>Arrives in <?= e($phase) ?></span></span>
    <h2 class="coming-soon__title"><?= e($headline) ?></h2>
    <ul class="coming-soon__list">
        <?php foreach ($bullets as $bullet): ?>
            <li><?= icon('check', '', 16) ?><span><?= e($bullet) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <a class="btn btn-secondary" href="<?= e(url('dashboard.php')) ?>"><?= icon('arrow-left', '', 18) ?><span>Back to dashboard</span></a>
</section>
