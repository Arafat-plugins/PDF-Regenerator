/* PDF Regenerator — login page behaviour (no inline scripts: CSP script-src 'self') */
(function () {
    'use strict';

    var form = document.querySelector('[data-login-form]');
    if (!form) { return; }

    var password = form.querySelector('#password');
    var username = form.querySelector('#username');
    var toggle = form.querySelector('[data-password-toggle]');
    var capsHint = form.querySelector('[data-capslock]');
    var submit = form.querySelector('[data-submit]');

    // Show / hide password
    if (toggle && password) {
        toggle.addEventListener('click', function () {
            var show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            password.focus({ preventScroll: true });
            var len = password.value.length;
            try { password.setSelectionRange(len, len); } catch (e) { /* ignore */ }
        });
    }

    // Caps Lock warning
    if (password && capsHint) {
        var updateCaps = function (event) {
            if (typeof event.getModifierState !== 'function') { return; }
            capsHint.hidden = !event.getModifierState('CapsLock');
        };
        password.addEventListener('keydown', updateCaps);
        password.addEventListener('keyup', updateCaps);
        password.addEventListener('blur', function () { capsHint.hidden = true; });
    }

    // Client-side required check + busy state (server validates again)
    form.addEventListener('submit', function (event) {
        var missing = null;
        [username, password].forEach(function (input) {
            var empty = !input || input.value.trim() === '';
            if (input) { input.closest('.field').classList.toggle('has-error', empty); }
            if (empty && !missing) { missing = input; }
        });

        if (missing) {
            event.preventDefault();
            missing.focus();
            return;
        }

        if (form.dataset.busy === '1') {
            event.preventDefault();
            return;
        }
        form.dataset.busy = '1';

        if (submit) {
            submit.classList.add('is-busy');
            submit.setAttribute('aria-disabled', 'true');
            var label = submit.querySelector('.btn__label');
            if (label) { label.textContent = label.getAttribute('data-label-busy') || 'Signing in…'; }
        }
    });

    [username, password].forEach(function (input) {
        if (!input) { return; }
        input.addEventListener('input', function () {
            input.closest('.field').classList.remove('has-error');
        });
    });

    // Restore the button if the page is shown again from the back/forward cache
    window.addEventListener('pageshow', function () {
        form.dataset.busy = '';
        if (submit) {
            submit.classList.remove('is-busy');
            submit.removeAttribute('aria-disabled');
            var label = submit.querySelector('.btn__label');
            if (label) { label.textContent = label.getAttribute('data-label-default') || 'Login'; }
        }
    });
})();
