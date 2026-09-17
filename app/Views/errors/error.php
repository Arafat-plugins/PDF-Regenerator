<?php
/** @var string $heading */
/** @var string $message */
?>
<main class="error-page">
    <div class="error-card">
        <span class="icon-tile icon-tile--red icon-tile--lg"><?= icon('triangle-alert', '', 28) ?></span>
        <h1><?= e($heading) ?></h1>
        <p><?= e($message) ?></p>
        <a class="btn btn-primary" href="<?= e(url('dashboard.php')) ?>"><?= icon('arrow-left', '', 18) ?><span>Back to dashboard</span></a>
    </div>
</main>
