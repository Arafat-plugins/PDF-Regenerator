/* PDF Regenerator — Settings page: password form (show/hide, strength hint, client checks).
 * The server validates everything again.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-password-form]');
    if (!form) { return; }
    var MIN = 10;
    var inputs = {
        current: form.querySelector('#current_password'),
        next: form.querySelector('#new_password'),
        confirm: form.querySelector('#confirm_password')
    };
    var submit = form.querySelector('[data-submit]');
    var caps = form.querySelector('[data-capslock]');
    var meter = form.querySelector('[data-meter]');
    var bar = form.querySelector('[data-meter-bar]');

    form.querySelectorAll('[data-toggle]').forEach(function (btn) {
        var input = form.querySelector('#' + btn.getAttribute('data-toggle'));
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus({ preventScroll: true });
        });
    });

    function setError(input, message) {
        var box = input.closest('.pw-field');
        var err = form.querySelector('[data-error-for="' + input.id + '"]');
        box.classList.toggle('has-error', !!message);
        err.textContent = message || '';
        err.hidden = !message;
        if (message) {
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', err.id);
        } else {
            input.removeAttribute('aria-invalid');
            var hint = box.querySelector('[data-hint]');
            if (hint) { input.setAttribute('aria-describedby', hint.id); } else { input.removeAttribute('aria-describedby'); }
        }
    }

    function strength(value) {
        var score = 0;
        if (value.length >= MIN) { score++; }
        if (value.length >= 14) { score++; }
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { score++; }
        if (/\d/.test(value)) { score++; }
        if (/[^A-Za-z0-9]/.test(value) || /\s/.test(value)) { score++; }
        return value.length < MIN ? Math.min(score, 1) : score;
    }

    Object.keys(inputs).forEach(function (k) {
        var input = inputs[k];
        input.addEventListener('input', function () {
            if (input.getAttribute('aria-invalid')) { setError(input, ''); }
            if (input === inputs.next && meter) {
                var v = input.value;
                meter.hidden = v === '';
                var s = strength(v);
                bar.style.width = Math.max(12, s * 20) + '%';
                meter.className = 'pw-meter pw-meter--' + (s <= 1 ? 'weak' : (s <= 3 ? 'fair' : 'strong'));
            }
        });
        var capsCheck = function (event) {
            if (caps && typeof event.getModifierState === 'function') { caps.hidden = !event.getModifierState('CapsLock'); }
        };
        input.addEventListener('keydown', capsCheck);
        input.addEventListener('keyup', capsCheck);
        input.addEventListener('blur', function () { if (caps) { caps.hidden = true; } });
    });

    form.addEventListener('submit', function (event) {
        var first = null;
        var fail = function (input, message) {
            setError(input, message);
            first = first || input;
        };
        if (!inputs.current.value) { fail(inputs.current, 'Please enter your current password.'); }
        if (!inputs.next.value) {
            fail(inputs.next, 'Please enter a new password.');
        } else if (inputs.next.value.length < MIN) {
            fail(inputs.next, 'The new password must be at least ' + MIN + ' characters long.');
        }
        if (!inputs.confirm.value) {
            fail(inputs.confirm, 'Please repeat the new password.');
        } else if (inputs.next.value.length >= MIN && inputs.confirm.value !== inputs.next.value) {
            fail(inputs.confirm, 'The two new passwords do not match.');
        }
        if (first) {
            event.preventDefault();
            first.focus();
            return;
        }
        submit.disabled = true;
        submit.classList.add('is-busy');
    });
})();
