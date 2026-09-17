<?php
/**
 * Settings (Part B §2): account info, change password, read-only system check.
 *
 * @var \App\Auth\User $user
 * @var array<string, string> $errors
 * @var string|null $errorMessage
 * @var list<array{label: string, status: string, detail: string}> $checks
 * @var string $engine
 */
$rows = [
    ['label' => 'Name', 'value' => $user->displayName(), 'icon' => 'user-round'],
    ['label' => 'Username', 'value' => $user->username, 'icon' => 'at-sign'],
    ['label' => 'Email', 'value' => $user->email, 'icon' => 'mail'],
];
$fields = [
    ['name' => 'current_password', 'label' => 'Current password', 'autocomplete' => 'current-password', 'hint' => ''],
    ['name' => 'new_password', 'label' => 'New password', 'autocomplete' => 'new-password', 'hint' => 'At least ' . \App\Auth\AuthManager::MIN_PASSWORD . ' characters. A few unrelated words make a strong password.'],
    ['name' => 'confirm_password', 'label' => 'Confirm new password', 'autocomplete' => 'new-password', 'hint' => ''],
];
$counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $check) {
    $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
}
$engineLabel = match ($engine) {
    'poppler' => 'poppler',
    'smalot' => 'smalot (built-in)',
    default => 'unavailable',
};
?>
<header class="page-header page-header--icon">
    <span class="icon-tile icon-tile--blue icon-tile--hero" aria-hidden="true"><?= icon('settings', '', 32) ?></span>
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-sub">Your account information and security.</p>
    </div>
</header>

<div class="settings-grid">
    <section class="card settings-card" aria-labelledby="acct-title">
        <div class="card__head card__head--plain">
            <div class="card__head-main">
                <span class="avatar avatar--lg" aria-hidden="true"><?= e($user->initials()) ?></span>
                <div>
                    <h2 class="card__title" id="acct-title">Account</h2>
                    <p class="card__sub">Read-only. Accounts are managed by the administrator.</p>
                </div>
            </div>
            <span class="badge badge--green"><span class="dot"></span>Active</span>
        </div>
        <dl class="info-list">
            <?php foreach ($rows as $row): ?>
                <div class="info-list__row">
                    <dt><?= icon($row['icon'], '', 18) ?><span><?= e($row['label']) ?></span></dt>
                    <dd><?= e($row['value'] !== '' ? $row['value'] : '—') ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
        <div class="settings-note">
            <?= icon('shield-check', '', 18) ?>
            <p>Your session ends after <?= e((string) max(1, (int) round((int) config('session_timeout') / 60))) ?> minutes without activity. Use <strong>Logout</strong> on shared computers.</p>
        </div>
    </section>

    <section class="card settings-card" aria-labelledby="pw-title" id="password">
        <div class="card__head card__head--plain">
            <div class="card__head-main">
                <span class="icon-tile icon-tile--blue"><?= icon('key-round', '', 22) ?></span>
                <div>
                    <h2 class="card__title" id="pw-title">Change password</h2>
                    <p class="card__sub">You stay signed in here; other devices are signed out.</p>
                </div>
            </div>
        </div>

        <?php if ($errorMessage !== null && $errorMessage !== ''): ?>
            <div class="alert alert--error pw-alert" role="alert">
                <?= icon('circle-x', 'alert__icon', 20) ?>
                <div class="alert__body"><?= e($errorMessage) ?></div>
            </div>
        <?php endif; ?>

        <form class="pw-form" method="post" action="<?= e(url('settings.php')) ?>" novalidate data-password-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <input class="sr-only" type="text" name="username" value="<?= e($user->username) ?>" autocomplete="username" tabindex="-1" aria-hidden="true">
            <?php foreach ($fields as $i => $f): $err = $errors[$f['name']] ?? ''; ?>
                <div class="pw-field<?= $err !== '' ? ' has-error' : '' ?>">
                    <label class="pw-field__label" for="<?= e($f['name']) ?>"><?= e($f['label']) ?></label>
                    <div class="pw-field__control">
                        <input class="input pw-field__input" type="password" id="<?= e($f['name']) ?>" name="<?= e($f['name']) ?>"
                               autocomplete="<?= e($f['autocomplete']) ?>" maxlength="4096" required
                               <?= $f['name'] !== 'current_password' ? 'minlength="' . \App\Auth\AuthManager::MIN_PASSWORD . '"' : '' ?>
                               <?= $err !== '' ? 'aria-invalid="true" aria-describedby="' . e($f['name']) . '-error"' : ($f['hint'] !== '' ? 'aria-describedby="' . e($f['name']) . '-hint"' : '') ?>
                               <?= ($err !== '' && ($errors === [] || array_key_first($errors) === $f['name'])) ? 'autofocus' : '' ?>>
                        <button class="pw-field__toggle" type="button" data-toggle="<?= e($f['name']) ?>" aria-controls="<?= e($f['name']) ?>" aria-pressed="false" aria-label="Show password">
                            <span class="pw-field__show"><?= icon('eye', '', 19) ?></span>
                            <span class="pw-field__hide"><?= icon('eye-off', '', 19) ?></span>
                        </button>
                    </div>
                    <?php if ($f['name'] === 'new_password'): ?>
                        <div class="pw-meter" data-meter hidden aria-hidden="true"><span class="pw-meter__bar" data-meter-bar></span></div>
                    <?php endif; ?>
                    <p class="pw-field__error" id="<?= e($f['name']) ?>-error" data-error-for="<?= e($f['name']) ?>" <?= $err === '' ? 'hidden' : '' ?>><?= e($err) ?></p>
                    <?php if ($f['hint'] !== ''): ?>
                        <p class="pw-field__hint" id="<?= e($f['name']) ?>-hint" data-hint><?= e($f['hint']) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <p class="pw-field__hint pw-caps" data-capslock hidden><?= icon('triangle-alert', '', 15) ?> Caps Lock is on</p>
            <div class="pw-form__actions">
                <button class="btn btn-primary" type="submit" data-submit>
                    <span class="btn__spinner" aria-hidden="true"></span>
                    <?= icon('key-round', 'btn__icon', 18) ?>
                    <span>Change password</span>
                </button>
            </div>
        </form>
    </section>
</div>

<section class="card card--pad-0 syscheck" aria-labelledby="sys-title">
    <div class="card__head">
        <div class="card__head-main">
            <span class="icon-tile icon-tile--green"><?= icon('server', '', 22) ?></span>
            <div>
                <h2 class="card__title" id="sys-title">System check</h2>
                <p class="card__sub">PDF engine: <strong><?= e($engineLabel) ?></strong> · read-only report of this server.</p>
            </div>
        </div>
        <div class="syscheck__summary">
            <span class="badge badge--green"><?= e((string) $counts['ok']) ?> OK</span>
            <?php if ($counts['warn'] > 0): ?><span class="badge badge--amber"><?= e((string) $counts['warn']) ?> <?= $counts['warn'] === 1 ? 'warning' : 'warnings' ?></span><?php endif; ?>
            <?php if ($counts['fail'] > 0): ?><span class="badge badge--red"><?= e((string) $counts['fail']) ?> <?= $counts['fail'] === 1 ? 'problem' : 'problems' ?></span><?php endif; ?>
        </div>
    </div>
    <details class="syscheck__details" <?= $counts['fail'] > 0 ? 'open' : '' ?>>
        <summary><?= icon('chevron-right', 'syscheck__chevron', 16) ?><span>Show all <?= e((string) count($checks)) ?> checks</span></summary>
        <ul class="syscheck__list">
            <?php foreach ($checks as $check): ?>
                <li class="syscheck__row syscheck__row--<?= e($check['status']) ?>">
                    <span class="syscheck__mark"><?= icon($check['status'] === 'ok' ? 'circle-check' : ($check['status'] === 'warn' ? 'triangle-alert' : 'circle-x'), '', 17) ?></span>
                    <span class="syscheck__label"><?= e($check['label']) ?></span>
                    <span class="syscheck__detail"><?= e($check['detail']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
</section>
