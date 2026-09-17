/* PDF Regenerator — Stock Templates gallery: large preview dialog */
(function () {
    'use strict';

    var R = window.PdfRegen;
    var gallery = document.querySelector('[data-gallery]');
    if (!gallery || !R) { return; }

    function open(id, opener) {
        var tpl = gallery.querySelector('template[data-detail="' + id.replace(/[^a-z0-9-]/g, '') + '"]');
        if (!tpl) { return; }
        var backdrop = R.el('div', 'modal-backdrop lightbox-backdrop');
        var dialog = R.el('div', 'lightbox');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'lightbox-title');
        dialog.appendChild(tpl.content.cloneNode(true));
        var close = R.el('button', 'lightbox__close');
        close.type = 'button';
        close.setAttribute('aria-label', 'Close');
        close.innerHTML = R.icon('x');
        dialog.appendChild(close);
        backdrop.appendChild(dialog);
        document.body.appendChild(backdrop);
        document.body.classList.add('modal-open');
        close.focus();

        function shut() {
            document.removeEventListener('keydown', onKey, true);
            backdrop.classList.add('is-leaving');
            setTimeout(function () {
                backdrop.remove();
                if (!document.querySelector('.modal-backdrop')) { document.body.classList.remove('modal-open'); }
                opener.focus({ preventScroll: true });
            }, 160);
        }
        function onKey(event) {
            if (event.key === 'Escape') { event.preventDefault(); shut(); }
            if (event.key === 'Tab') {
                var items = dialog.querySelectorAll('a[href], button');
                var first = items[0];
                var last = items[items.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        }
        document.addEventListener('keydown', onKey, true);
        close.addEventListener('click', shut);
        backdrop.addEventListener('mousedown', function (event) { if (event.target === backdrop) { shut(); } });
    }

    gallery.querySelectorAll('[data-open]').forEach(function (btn) {
        btn.addEventListener('click', function () { open(btn.getAttribute('data-open'), btn); });
    });
})();
