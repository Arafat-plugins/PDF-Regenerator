<?php
/** @var string $username */
/** @var array{type: string, title: string, body?: string, contact?: bool}|null $notice */
$adminEmail = (string) config('admin_email');
$notice = $notice ?? null;
$noticeGlyphs = ['error' => icon('x', '', 16), 'success' => icon('check', '', 16), 'warning' => '<span class="notice__glyph">!</span>', 'info' => '<span class="notice__glyph">i</span>'];
?>
<div class="auth">
    <section class="auth__hero" aria-hidden="false">
        <svg class="auth__waves" viewBox="0 0 736 1024" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">
            <defs>
                <linearGradient id="wave-a" x1="736" y1="0" x2="120" y2="520" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#2a5bd7" stop-opacity=".5"/>
                    <stop offset=".55" stop-color="#1b3f9e" stop-opacity=".35"/>
                    <stop offset="1" stop-color="#12296b" stop-opacity="0"/>
                </linearGradient>
                <linearGradient id="wave-b" x1="736" y1="0" x2="420" y2="300" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#3a6cf0" stop-opacity=".32"/>
                    <stop offset="1" stop-color="#1d44a8" stop-opacity="0"/>
                </linearGradient>
                <linearGradient id="wave-c" x1="736" y1="640" x2="80" y2="1024" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#2d62e6" stop-opacity=".85"/>
                    <stop offset=".6" stop-color="#1a3f9f" stop-opacity=".6"/>
                    <stop offset="1" stop-color="#10286c" stop-opacity=".2"/>
                </linearGradient>
                <linearGradient id="wave-d" x1="0" y1="820" x2="320" y2="1024" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#1e4bbf" stop-opacity=".0"/>
                    <stop offset="1" stop-color="#1e4bbf" stop-opacity=".45"/>
                </linearGradient>
            </defs>
            <path d="M505 0C455 112 336 196 190 236C118 256 54 270 0 292V560C124 468 330 398 520 298C622 244 692 176 736 112V0Z" fill="url(#wave-a)"/>
            <path d="M620 0C600 58 560 108 505 150C590 132 668 92 736 34V0Z" fill="url(#wave-b)"/>
            <path d="M736 662C652 790 492 862 312 908C180 942 76 982 0 1024H736Z" fill="url(#wave-c)"/>
            <path d="M0 830C88 862 170 928 214 1024H0Z" fill="url(#wave-d)"/>
        </svg>

        <div class="auth__hero-inner">
            <div class="auth__logo"><?= partial('logo-mark', ['size' => 148, 'id' => 'hero']) ?></div>
            <p class="auth__title"><span>PDF</span> <span class="text-gradient">Regenerator</span></p>
            <p class="auth__tagline">Turn your basic PDF into a premium template.<br>Fast, simple, and powerful.</p>

            <ul class="auth__features">
                <li><?= icon('zap', '', 30) ?><span>Upload PDF</span></li>
                <li><?= icon('repeat', '', 30) ?><span>Apply Template</span></li>
                <li><?= icon('file-down', '', 30) ?><span>Get Premium PDF</span></li>
            </ul>
        </div>
    </section>

    <main class="auth__panel">
        <div class="auth__form-wrap">
            <h1 class="auth__heading">Welcome Back</h1>
            <p class="auth__sub">Login to your account to access the PDF Regenerator tool and manage your files.</p>

            <form class="auth-form" method="post" action="<?= e(url('login.php')) ?>" novalidate data-login-form>
                <?= csrf_field() ?>

                <div class="field">
                    <label class="sr-only" for="username">Username</label>
                    <span class="field__icon"><?= icon('user', '', 24) ?></span>
                    <input class="field__input" type="text" id="username" name="username"
                           value="<?= e($username) ?>" placeholder="Username"
                           autocomplete="username" autocapitalize="none" spellcheck="false"
                           maxlength="64" required <?= $username === '' ? 'autofocus' : '' ?>>
                </div>

                <div class="field">
                    <label class="sr-only" for="password">Password</label>
                    <span class="field__icon"><?= icon('lock', '', 24) ?></span>
                    <input class="field__input field__input--toggle" type="password" id="password" name="password"
                           placeholder="Password" autocomplete="current-password" maxlength="4096" required
                           <?= $username !== '' ? 'autofocus' : '' ?>>
                    <button class="field__toggle" type="button" data-password-toggle aria-controls="password" aria-pressed="false" aria-label="Show password">
                        <span class="field__toggle-show"><?= icon('eye', '', 22) ?></span>
                        <span class="field__toggle-hide"><?= icon('eye-off', '', 22) ?></span>
                    </button>
                </div>
                <p class="field__hint" data-capslock hidden><?= icon('triangle-alert', '', 15) ?> Caps Lock is on</p>

                <button class="btn btn-primary btn-block btn-lg" type="submit" data-submit>
                    <span class="btn__spinner" aria-hidden="true"></span>
                    <?= icon('log-in', 'btn__icon', 22) ?>
                    <span class="btn__label" data-label-default="Login" data-label-busy="Signing in…">Login</span>
                </button>
            </form>

            <?php if ($notice !== null): ?>
                <div class="notice notice--<?= e($notice['type']) ?>" role="<?= in_array($notice['type'], ['error', 'warning'], true) ? 'alert' : 'status' ?>">
                    <span class="notice__icon" aria-hidden="true"><?= $noticeGlyphs[$notice['type']] ?? $noticeGlyphs['info'] ?></span>
                    <div class="notice__content">
                        <p class="notice__title"><?= e($notice['title']) ?></p>
                        <?php if (!empty($notice['body'])): ?>
                            <p class="notice__text"><?= e($notice['body']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($notice['contact'])): ?>
                            <p class="notice__text">If you don’t have an account, please contact:<br>
                                <a href="mailto:<?= e($adminEmail) ?>"><?= e($adminEmail) ?></a></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($notice === null || empty($notice['contact'])): ?>
                <p class="auth__contact">Don’t have an account? Contact the administrator:
                    <a href="mailto:<?= e($adminEmail) ?>"><?= e($adminEmail) ?></a></p>
            <?php endif; ?>
        </div>

        <p class="auth__footer">&copy; <?= e(date('Y')) ?> <?= e(config('app_name')) ?> · Secure internal tool</p>
    </main>
</div>
