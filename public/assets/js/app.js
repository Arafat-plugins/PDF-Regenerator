/* PDF Regenerator — app shell behaviour */
(function () {
    'use strict';

    var body = document.body;

    // ------------------------------------------------------------ helpers
    window.PdfRegen = window.PdfRegen || {};

    /** CSRF token for AJAX calls (sent as X-CSRF-Token). */
    window.PdfRegen.csrf = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    /**
     * fetch() wrapper returning the standard JSON envelope {ok, message, data, errors}.
     */
    window.PdfRegen.api = function (url, options) {
        options = options || {};
        var headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }, options.headers || {});
        var method = (options.method || 'GET').toUpperCase();
        if (method !== 'GET') { headers['X-CSRF-Token'] = window.PdfRegen.csrf(); }

        return fetch(url, Object.assign({}, options, { headers: headers, credentials: 'same-origin' }))
            .then(function (res) {
                if (res.status === 401) {
                    window.location.href = 'login.php?expired=1';
                }
                return res.json().catch(function () {
                    return { ok: false, message: 'Unexpected server response. Please try again.', data: {}, errors: ['parse'] };
                });
            })
            .catch(function () {
                return { ok: false, message: 'Network error. Please check your connection and try again.', data: {}, errors: ['network'] };
            });
    };

    // ---------------------------------------------------------------- icons
    var icons = {};
    try {
        var iconBlock = document.getElementById('ui-icons');
        if (iconBlock) { icons = JSON.parse(iconBlock.textContent || '{}'); }
    } catch (e) { icons = {}; }
    /** Static, trusted SVG markup rendered by the server. */
    window.PdfRegen.icon = function (name) { return icons[name] || ''; };

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = text; }
        return node;
    }
    window.PdfRegen.el = el;

    // --------------------------------------------------------------- toasts
    var toastStack = null;
    /**
     * PdfRegen.toast(message, type = 'success'|'error'|'warning'|'info', action = {label, onClick})
     */
    window.PdfRegen.toast = function (message, type, action) {
        type = type || 'success';
        if (!toastStack) {
            toastStack = el('div', 'toast-stack');
            toastStack.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastStack);
        }
        var toast = el('div', 'toast toast--' + type);
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        var ic = el('span', 'toast__icon');
        ic.innerHTML = window.PdfRegen.icon(type === 'error' ? 'circleX' : (type === 'warning' ? 'alertSm' : (type === 'info' ? 'infoSm' : 'circleCheck')));
        toast.appendChild(ic);
        toast.appendChild(el('span', 'toast__text', message));
        var actionBtn = null;
        if (action && action.label) {
            actionBtn = el('button', 'toast__action', action.label);
            actionBtn.type = 'button';
            toast.appendChild(actionBtn);
        }
        var close = el('button', 'toast__close');
        close.type = 'button';
        close.setAttribute('aria-label', 'Dismiss');
        close.innerHTML = window.PdfRegen.icon('x');
        toast.appendChild(close);
        toastStack.appendChild(toast);

        var remove = function () {
            toast.classList.add('is-leaving');
            setTimeout(function () { toast.remove(); }, 220);
        };
        close.addEventListener('click', remove);
        if (actionBtn) {
            actionBtn.addEventListener('click', function () {
                remove();
                action.onClick();
            });
        }
        var timer = setTimeout(remove, type === 'error' ? 7000 : (action ? 6500 : 4000));
        toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
        toast.addEventListener('mouseleave', function () { timer = setTimeout(remove, 2500); });
        return { close: remove };
    };

    // --------------------------------------------------------------- modals
    /**
     * PdfRegen.modal({title, message, tone: 'danger'|'info'|'primary', icon,
     *                 confirmLabel, cancelLabel|null, input: {label, value, maxLength},
     *                 content: Node, wide, focus: () => Node, beforeClose: (value) => Promise<boolean>})
     * → Promise<{confirmed: boolean, value: string}>
     * All text is inserted with textContent.
     */
    window.PdfRegen.modal = function (opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var previousFocus = document.activeElement;
            var backdrop = el('div', 'modal-backdrop');
            var dialog = el('div', 'modal modal--' + (opts.tone || 'primary') + (opts.wide ? ' modal--wide' : ''));
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            var titleId = 'modal-title-' + Date.now();
            dialog.setAttribute('aria-labelledby', titleId);

            var head = el('div', 'modal__head');
            var badge = el('span', 'modal__icon');
            badge.innerHTML = window.PdfRegen.icon(opts.icon || (opts.tone === 'danger' ? 'trash' : 'info'));
            head.appendChild(badge);
            var title = el('h2', 'modal__title', opts.title || '');
            title.id = titleId;
            head.appendChild(title);
            dialog.appendChild(head);

            if (opts.message) { dialog.appendChild(el('p', 'modal__message', opts.message)); }
            if (opts.detail) { dialog.appendChild(el('p', 'modal__detail', opts.detail)); }

            if (opts.content) { dialog.appendChild(opts.content); }

            var input = null;
            var error = null;
            if (opts.input) {
                var label = el('label', 'modal__label', opts.input.label || '');
                input = el('input', 'input');
                input.type = 'text';
                input.value = opts.input.value || '';
                input.maxLength = opts.input.maxLength || 120;
                input.id = 'modal-input-' + Date.now();
                label.htmlFor = input.id;
                dialog.appendChild(label);
                dialog.appendChild(input);
                error = el('p', 'modal__error');
                error.hidden = true;
                dialog.appendChild(error);
            }

            var foot = el('div', 'modal__foot');
            (opts.links || []).forEach(function (link) {
                var a = el('a', 'btn ' + (link.primary ? 'btn-primary' : 'btn-secondary'));
                a.href = link.href;
                if (link.newTab) { a.target = '_blank'; a.rel = 'noopener'; }
                if (link.icon) {
                    var ic = el('span', 'btn__icon');
                    ic.innerHTML = window.PdfRegen.icon(link.icon);
                    a.appendChild(ic);
                }
                a.appendChild(el('span', null, link.label));
                foot.appendChild(a);
            });
            var cancel = null;
            if (opts.cancelLabel !== null) {
                cancel = el('button', 'btn btn-secondary', opts.cancelLabel || 'Cancel');
                cancel.type = 'button';
                foot.appendChild(cancel);
            }
            var confirm = el('button', 'btn ' + (opts.tone === 'danger' ? 'btn-danger' : (opts.links && opts.links.length ? 'btn-secondary' : 'btn-primary')), opts.confirmLabel || 'OK');
            confirm.type = 'button';
            foot.appendChild(confirm);
            dialog.appendChild(foot);

            backdrop.appendChild(dialog);
            document.body.appendChild(backdrop);
            body.classList.add('modal-open');
            var firstFocus = opts.focus ? opts.focus() : null;
            (firstFocus || input || confirm).focus();
            if (input) { input.select(); }

            var closing = false;
            function close(confirmed) {
                if (closing) { return; }
                if (confirmed && input && input.value.trim() === '') {
                    error.textContent = 'This field cannot be empty.';
                    error.hidden = false;
                    input.focus();
                    return;
                }
                if (confirmed && opts.beforeClose) {
                    // beforeClose() → Promise<boolean>: false keeps the dialog open (e.g. a server error was shown)
                    closing = true;
                    confirm.disabled = true;
                    confirm.classList.add('is-busy');
                    Promise.resolve(opts.beforeClose(input ? input.value.trim() : '')).then(function (okay) {
                        closing = false;
                        confirm.disabled = false;
                        confirm.classList.remove('is-busy');
                        if (okay) { finish(true); }
                    });
                    return;
                }
                finish(confirmed);
            }

            function finish(confirmed) {
                closing = true;
                document.removeEventListener('keydown', onKey, true);
                backdrop.classList.add('is-leaving');
                setTimeout(function () {
                    backdrop.remove();
                    if (!document.querySelector('.modal-backdrop')) { body.classList.remove('modal-open'); }
                    if (previousFocus && previousFocus.focus) { previousFocus.focus({ preventScroll: true }); }
                }, 160);
                resolve({ confirmed: confirmed, value: input ? input.value.trim() : '' });
            }

            function onKey(event) {
                var stack = document.querySelectorAll('.modal-backdrop');
                if (stack[stack.length - 1] !== backdrop) { return; } // a dialog opened on top handles its own keys
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    // an open menu / list inside the dialog closes first
                    var open = dialog.querySelector('[data-dropdown].is-open, [data-escape-guard].is-open');
                    if (open) {
                        closeAllDropdowns(null);
                        open.dispatchEvent(new CustomEvent('guard-close'));
                        return;
                    }
                    close(false);
                }
                if (event.key === 'Enter' && (event.target === input || (opts.content && event.target.matches && event.target.matches('input[type="text"], input[type="url"]') && dialog.contains(event.target)))) { event.preventDefault(); close(true); }
                if (event.key === 'Tab') {
                    var focusable = Array.prototype.filter.call(dialog.querySelectorAll('button, input, select, textarea, a[href], [tabindex="0"]'), function (node) {
                        return !node.disabled && node.offsetParent !== null;
                    });
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                }
            }

            document.addEventListener('keydown', onKey, true);
            confirm.addEventListener('click', function () { close(true); });
            if (cancel) { cancel.addEventListener('click', function () { close(false); }); }
            backdrop.addEventListener('mousedown', function (event) { if (event.target === backdrop) { close(false); } });
        });
    };

    /**
     * Blocking "working…" dialog for a real server request.
     * PdfRegen.busy({title, message, icon}) → {update(message), close()}
     */
    window.PdfRegen.busy = function (opts) {
        opts = opts || {};
        var previousFocus = document.activeElement;
        var backdrop = el('div', 'modal-backdrop modal-backdrop--busy');
        var dialog = el('div', 'modal modal--busy');
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-busy', 'true');
        var titleId = 'busy-title-' + Date.now();
        dialog.setAttribute('aria-labelledby', titleId);

        var spin = el('span', 'modal__spinner');
        spin.appendChild(el('span', 'spinner spinner--lg'));
        var badge = el('span', 'modal__spinner-icon');
        badge.innerHTML = window.PdfRegen.icon(opts.icon || 'scan');
        spin.appendChild(badge);
        dialog.appendChild(spin);
        var title = el('h2', 'modal__title modal__title--center', opts.title || 'Working…');
        title.id = titleId;
        dialog.appendChild(title);
        var message = el('p', 'modal__message modal__message--center', opts.message || '');
        message.setAttribute('aria-live', 'polite');
        dialog.appendChild(message);
        var bar = el('div', 'progress progress--indeterminate');
        bar.appendChild(el('span', 'progress__bar'));
        dialog.appendChild(bar);

        backdrop.appendChild(dialog);
        document.body.appendChild(backdrop);
        body.classList.add('modal-open');
        dialog.tabIndex = -1;
        dialog.focus();
        var blockKeys = function (event) { if (event.key === 'Escape' || event.key === 'Tab') { event.preventDefault(); } };
        document.addEventListener('keydown', blockKeys, true);

        return {
            update: function (text) { message.textContent = text; },
            close: function () {
                document.removeEventListener('keydown', blockKeys, true);
                backdrop.classList.add('is-leaving');
                setTimeout(function () {
                    backdrop.remove();
                    if (!document.querySelector('.modal-backdrop')) { body.classList.remove('modal-open'); }
                    if (previousFocus && previousFocus.focus) { previousFocus.focus({ preventScroll: true }); }
                }, 160);
            }
        };
    };

    /** POST form data to an endpoint and return the JSON envelope. */
    window.PdfRegen.post = function (url, fields) {
        var data = new FormData();
        Object.keys(fields || {}).forEach(function (key) { data.append(key, fields[key]); });
        return window.PdfRegen.api(url, { method: 'POST', body: data });
    };

    // ------------------------------------------------------ mobile sidebar
    var openBtn = document.querySelector('[data-nav-open]');
    var backdrop = document.querySelector('.sidebar-backdrop');
    var sidebar = document.getElementById('sidebar');

    function setNav(open) {
        body.classList.toggle('nav-open', open);
        if (backdrop) { backdrop.hidden = !open; }
        if (openBtn) { openBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        if (open && sidebar) {
            var first = sidebar.querySelector('.nav__link');
            if (first) { first.focus({ preventScroll: true }); }
        } else if (!open && openBtn && sidebar && sidebar.contains(document.activeElement)) {
            openBtn.focus({ preventScroll: true });
        }
    }

    if (openBtn) { openBtn.addEventListener('click', function () { setNav(true); }); }
    document.querySelectorAll('[data-nav-close]').forEach(function (el) {
        el.addEventListener('click', function () { setNav(false); });
    });
    window.matchMedia('(min-width: 1025px)').addEventListener('change', function (e) {
        if (e.matches) { setNav(false); }
    });

    // ------------------------------------------------------------ dropdowns
    // Event delegation, so rows added later (AJAX) work too. Menus inside a
    // scrolling table are positioned "fixed" so they are never clipped.
    function closeDropdown(dd) {
        var btn = dd.querySelector('[data-dropdown-toggle]');
        var menu = dd.querySelector('.dropdown__menu');
        if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        if (menu) {
            menu.hidden = true;
            menu.classList.remove('is-fixed', 'is-up');
            menu.style.top = menu.style.left = '';
        }
        dd.classList.remove('is-open');
    }

    function closeAllDropdowns(except) {
        document.querySelectorAll('[data-dropdown].is-open').forEach(function (dd) {
            if (dd !== except) { closeDropdown(dd); }
        });
    }
    window.PdfRegen.closeDropdowns = closeAllDropdowns;

    function positionMenu(dd) {
        var btn = dd.querySelector('[data-dropdown-toggle]');
        var menu = dd.querySelector('.dropdown__menu');
        if (!btn || !menu || !dd.closest('.table-wrap')) { return; }
        var rect = btn.getBoundingClientRect();
        menu.classList.add('is-fixed');
        menu.classList.remove('is-up');
        var width = menu.offsetWidth;
        var height = menu.offsetHeight;
        var top = rect.bottom + 6;
        if (top + height > window.innerHeight - 8 && rect.top - height - 6 > 8) {
            top = rect.top - height - 6;
            menu.classList.add('is-up');
        }
        menu.style.top = top + 'px';
        menu.style.left = Math.min(window.innerWidth - width - 8, Math.max(8, rect.right - width)) + 'px';
    }

    function openDropdown(dd) {
        var btn = dd.querySelector('[data-dropdown-toggle]');
        var menu = dd.querySelector('.dropdown__menu');
        if (!btn || !menu) { return; }
        closeAllDropdowns(dd);
        dd.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        menu.hidden = false;

        positionMenu(dd);

        var firstItem = menu.querySelector('.dropdown__item');
        if (firstItem) { firstItem.focus({ preventScroll: true }); }
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest ? event.target.closest('[data-dropdown-toggle]') : null;
        if (toggle) {
            var dd = toggle.closest('[data-dropdown]');
            event.preventDefault();
            if (dd.classList.contains('is-open')) { closeDropdown(dd); } else { openDropdown(dd); }
            return;
        }
        var inside = event.target.closest ? event.target.closest('[data-dropdown]') : null;
        closeAllDropdowns(null);
        if (inside) { closeDropdown(inside); }
    });

    document.addEventListener('keydown', function (event) {
        var menu = document.activeElement && document.activeElement.closest ? document.activeElement.closest('.dropdown__menu') : null;
        if (!menu || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')) { return; }
        var items = Array.prototype.slice.call(menu.querySelectorAll('.dropdown__item'));
        var index = items.indexOf(document.activeElement);
        event.preventDefault();
        var next = event.key === 'ArrowDown' ? (index + 1) % items.length : (index - 1 + items.length) % items.length;
        items[next].focus();
    });

    window.addEventListener('resize', function () {
        document.querySelectorAll('[data-dropdown].is-open').forEach(positionMenu);
    });
    document.addEventListener('scroll', function () {
        document.querySelectorAll('[data-dropdown].is-open').forEach(positionMenu);
    }, { capture: true, passive: true });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || document.querySelector('.modal-backdrop')) { return; }
        if (body.classList.contains('nav-open')) { setNav(false); }
        document.querySelectorAll('[data-dropdown].is-open').forEach(function (dd) {
            var btn = dd.querySelector('[data-dropdown-toggle]');
            closeDropdown(dd);
            if (btn) { btn.focus(); }
        });
    });

    // --------------------------------------------------------------- alerts
    document.querySelectorAll('[data-dismissible]').forEach(function (alert) {
        var close = alert.querySelector('[data-dismiss]');
        if (close) {
            close.addEventListener('click', function () { alert.remove(); });
        }
        if (alert.classList.contains('alert--success') || alert.classList.contains('alert--info')) {
            setTimeout(function () {
                alert.style.transition = 'opacity .3s ease';
                alert.style.opacity = '0';
                setTimeout(function () { alert.remove(); }, 320);
            }, 6000);
        }
    });

    // Prevent double submission of any form marked data-once
    document.querySelectorAll('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (form.dataset.submitted === '1') { event.preventDefault(); return; }
            form.dataset.submitted = '1';
            setTimeout(function () { form.dataset.submitted = ''; }, 4000);
        });
    });
})();
