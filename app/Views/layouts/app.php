<?php
/** @var string $content */
/** @var string|null $pageTitle */
/** @var string|null $activeNav */
/** @var \App\Auth\User $user */
$activeNav = $activeNav ?? '';
$nav = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'house', 'href' => 'dashboard.php'],
    ['key' => 'upload', 'label' => 'Upload PDF', 'icon' => 'upload', 'href' => 'upload.php'],
    ['key' => 'templates', 'label' => 'Stock Templates', 'icon' => 'layout-grid', 'href' => 'templates.php'],
    ['key' => 'downloads', 'label' => 'Generated Files', 'icon' => 'file-text', 'href' => 'downloads.php'],
    ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'href' => 'settings.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e(($pageTitle ?? 'Dashboard') . ' · ' . config('app_name')) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preload" href="<?= e(url('assets/fonts/inter-latin-wght-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="app-body">
<a class="skip-link" href="#main">Skip to content</a>

<div class="app">
    <aside class="sidebar" id="sidebar" aria-label="Main navigation">
        <div class="sidebar__top">
            <a class="brand" href="<?= e(url('dashboard.php')) ?>">
                <?= partial('logo-mark', ['size' => 36, 'id' => 'side']) ?>
                <span class="brand__name"><?= e(config('app_name')) ?></span>
            </a>
            <button class="sidebar__close" type="button" data-nav-close aria-label="Close menu"><?= icon('x', '', 22) ?></button>
        </div>

        <nav class="nav">
            <ul>
                <?php foreach ($nav as $item): ?>
                    <li>
                        <a class="nav__link<?= is_active_nav($item['key'], $activeNav) ?>" href="<?= e(url($item['href'])) ?>"<?= $item['key'] === $activeNav ? ' aria-current="page"' : '' ?>>
                            <?= icon($item['icon'], 'nav__icon', 22) ?>
                            <span><?= e($item['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="sidebar__footer">
            <div class="user-card">
                <span class="avatar" aria-hidden="true"><?= e($user->initials()) ?></span>
                <span class="user-card__text">
                    <span class="user-card__name"><?= e($user->displayName()) ?></span>
                    <span class="user-card__email"><?= e($user->email) ?></span>
                </span>
            </div>
            <form method="post" action="<?= e(url('logout.php')) ?>" class="logout-form">
                <?= csrf_field() ?>
                <button type="submit" class="nav__link nav__link--button">
                    <?= icon('log-out', 'nav__icon', 22) ?>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </aside>
    <div class="sidebar-backdrop" data-nav-close hidden></div>

    <div class="main">
        <header class="topbar">
            <button class="topbar__menu" type="button" data-nav-open aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">
                <?= icon('menu', '', 22) ?>
            </button>
            <a class="topbar__brand" href="<?= e(url('dashboard.php')) ?>">
                <?= partial('logo-mark', ['size' => 28, 'id' => 'top']) ?>
                <span><?= e(config('app_name')) ?></span>
            </a>

            <div class="topbar__right">
                <div class="topbar__date">
                    <?= icon('calendar', '', 18) ?>
                    <time datetime="<?= e(date('Y-m-d')) ?>"><?= e(format_date(time())) ?></time>
                </div>
                <span class="topbar__divider" aria-hidden="true"></span>
                <div class="dropdown" data-dropdown>
                    <button class="topbar__user" type="button" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false">
                        <?= icon('user', '', 20) ?>
                        <span class="topbar__username"><?= e($user->username) ?></span>
                        <?= icon('chevron-down', 'topbar__chevron', 16) ?>
                    </button>
                    <div class="dropdown__menu" role="menu" hidden>
                        <div class="dropdown__header">
                            <span class="avatar avatar--sm" aria-hidden="true"><?= e($user->initials()) ?></span>
                            <span>
                                <strong><?= e($user->displayName()) ?></strong>
                                <small><?= e($user->email) ?></small>
                            </span>
                        </div>
                        <a class="dropdown__item" role="menuitem" href="<?= e(url('settings.php')) ?>"><?= icon('settings', '', 18) ?><span>Settings</span></a>
                        <form method="post" action="<?= e(url('logout.php')) ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="dropdown__item dropdown__item--danger" role="menuitem"><?= icon('log-out', '', 18) ?><span>Logout</span></button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content" id="main" tabindex="-1">
            <?php foreach (['success' => 'circle-check', 'error' => 'circle-x', 'info' => 'info'] as $type => $flashIcon): ?>
                <?php if ($flash = \App\Core\SessionManager::flash($type)): ?>
                    <div class="alert alert--<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>" data-dismissible>
                        <?= icon($flashIcon, 'alert__icon', 20) ?>
                        <div class="alert__body"><?= e($flash) ?></div>
                        <button type="button" class="alert__close" data-dismiss aria-label="Dismiss"><?= icon('x', '', 16) ?></button>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script type="application/json" id="ui-icons"><?= json_encode([
    'check' => icon('check', '', 16),
    'x' => icon('x', '', 16),
    'alert' => icon('triangle-alert', '', 22),
    'trash' => icon('trash-2', '', 22),
    'pencil' => icon('pencil', '', 22),
    'info' => icon('info', '', 22),
    'circleCheck' => icon('circle-check', '', 18),
    'circleX' => icon('circle-x', '', 18),
    'arrowRight' => icon('arrow-right', '', 16),
    'refresh' => icon('refresh-cw', '', 16),
    'scan' => icon('scan-text', '', 22),
    'zap' => icon('zap', '', 22),
    'fileCheck' => icon('file-check', '', 22),
    'eye' => icon('eye', '', 17),
    'download' => icon('download', '', 17),
    'refreshLg' => icon('refresh-cw', '', 22),
    'alertSm' => icon('triangle-alert', '', 18),
    'infoSm' => icon('info', '', 18),
    'grip' => icon('grip-vertical', '', 16),
    'trashSm' => icon('trash-2', '', 16),
    'plus' => icon('plus', '', 16),
    'checkSm' => icon('check', '', 14),
    'circleAlert' => icon('circle-alert', '', 13),
    'restore' => icon('archive-restore', '', 16),
    'restoreLg' => icon('rotate-ccw', '', 22),
    'undo' => icon('undo-2', '', 14),
    'searchX' => icon('search-x', '', 30),
    'archive' => icon('archive', '', 30),
    'cloudCheck' => icon('cloud-check', '', 16),
    'cloudAlert' => icon('cloud-alert', '', 16),
    'loader' => icon('loader-circle', '', 16),
    'listPlus' => icon('list-plus', '', 16),
    'sigma' => icon('sigma', '', 13),
    'circlePlus' => icon('circle-plus', '', 22),
    'globe' => icon('globe', '', 16),
    'globeLg' => icon('globe', '', 22),
    'chevronDown' => icon('chevron-down', '', 16),
    'ellipsis' => icon('ellipsis', '', 18),
    'pencilSm' => icon('pencil', '', 16),
    'image' => icon('image', '', 22),
    'upload' => icon('upload', '', 16),
    'palette' => icon('palette', '', 16),
    'link' => icon('link', '', 15),
    'history' => icon('history', '', 15),
    'layoutTemplate' => icon('layout-template', '', 15),
    'chevronLeft' => icon('chevron-left', '', 17),
    'chevronRight' => icon('chevron-right', '', 17),
    'fileText' => icon('file-text', '', 26),
    'checkLg' => icon('check', '', 30),
    'xLg' => icon('x', '', 28),
    'xSm' => icon('x', '', 13),
    'shield' => icon('shield-check', '', 15),
    'files' => icon('files', '', 17),
    'rotate' => icon('rotate-ccw', '', 17),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e(asset('js/' . $script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
