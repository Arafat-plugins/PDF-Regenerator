<?php
/**
 * Saved website profiles for profile-chooser.js (JSON, escaped for a <script> block).
 *
 * @var list<array<string, mixed>> $profiles Branding::chooserList()
 */
$items = array_map(static function (array $p): array {
    $p['logo_url'] = $p['logo_url'] !== '' ? url($p['logo_url']) : '';

    return $p;
}, $profiles);
?>
<script type="application/json" id="profiles-data"><?= \App\Editor\EditorPresenter::json(['profiles' => $items, 'url' => url('blueprints.php')]) ?></script>
