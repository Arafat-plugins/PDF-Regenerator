<?php
$steps = [
    'Upload your PDF file (data will be extracted).',
    'Select a template from stock templates.',
    'Review & edit the data (remove or modify if needed).',
    'Generate your final PDF and save it to your files.',
];
?>
<aside class="card how-it-works" aria-labelledby="hiw-title">
    <div class="how-it-works__head">
        <span class="icon-tile icon-tile--blue icon-tile--round"><?= icon('lightbulb', '', 22) ?></span>
        <h2 class="card__title" id="hiw-title">How it works?</h2>
    </div>
    <ol class="steps">
        <?php foreach ($steps as $i => $step): ?>
            <li><span class="steps__num"><?= $i + 1 ?></span><span><?= e($step) ?></span></li>
        <?php endforeach; ?>
    </ol>
    <p class="how-it-works__note"><?= icon('circle-check', '', 18) ?><span>Your files are saved in your own folder on the server.</span></p>
</aside>
