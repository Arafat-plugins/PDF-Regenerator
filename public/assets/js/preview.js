/* PDF Regenerator — Preview & Edit page, right side:
 *   template chooser, live preview (zoom, full screen, refresh after every save), Preview as PDF
 */
(function () {
    'use strict';

    var R = window.PdfRegen;
    var root = document.querySelector('[data-review]');
    var panel = document.querySelector('[data-preview]');
    if (!R || !root || !panel) { return; }

    var id = root.getAttribute('data-id');
    var src = panel.getAttribute('data-src');
    var draftUrl = panel.getAttribute('data-draft-url');
    var viewport = panel.querySelector('[data-preview-viewport]');
    var stage = panel.querySelector('[data-preview-stage]');
    var frame = panel.querySelector('[data-preview-frame]');
    var loading = panel.querySelector('[data-preview-loading]');
    var zoomValue = panel.querySelector('[data-zoom-value]');
    var fullBtn = panel.querySelector('[data-fullscreen]');
    var closeBtn = panel.querySelector('[data-fullscreen-close]');
    var draftBtn = panel.querySelector('[data-draft]');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-tpl-chooser] [data-template]'));
    var templateName = document.querySelector('[data-template-name]');
    var paletteLabel = document.querySelector('[data-palette-label]');
    var paletteSwatch = document.querySelector('[data-palette-swatch]');

    var PAGE_WIDTH = 1020;          // page width + grey margin in CSS px — measured from the preview after each load
    var LEVELS = [50, 67, 75, 90, 100, 110, 125, 150, 175, 200];
    var level = 4;                  // 100 % = page fits the panel width
    var fullscreen = false;
    var reloadTimer = null;
    var pendingScroll = 0;
    var loaded = false;

    function editor() { return R.editor || null; }

    // ------------------------------------------------------------ layout
    function layout() {
        if (!stage || !frame || !viewport) { return; }
        var width = viewport.clientWidth;
        var height = viewport.clientHeight;
        if (!width || !height) { return; }
        var fit = Math.min(1.6, width / PAGE_WIDTH);
        var scale = fit * LEVELS[level] / 100;
        frame.style.width = PAGE_WIDTH + 'px';
        frame.style.height = Math.ceil(height / scale) + 'px';
        frame.style.transform = 'scale(' + scale + ')';
        stage.style.width = Math.max(width, Math.ceil(PAGE_WIDTH * scale)) + 'px';
        stage.style.height = height + 'px';
        stage.classList.toggle('is-wide', PAGE_WIDTH * scale > width + 1);
        if (zoomValue) { zoomValue.textContent = LEVELS[level] + '%'; }
        panel.querySelector('[data-zoom="-1"]').disabled = level === 0;
        panel.querySelector('[data-zoom="1"]').disabled = level === LEVELS.length - 1;
    }

    if (window.ResizeObserver && viewport) {
        new ResizeObserver(function () { layout(); }).observe(viewport);
    } else {
        window.addEventListener('resize', layout);
    }

    panel.querySelectorAll('[data-zoom]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            level = Math.max(0, Math.min(LEVELS.length - 1, level + parseInt(btn.getAttribute('data-zoom'), 10)));
            layout();
        });
    });

    // ---------------------------------------------------------- loading
    function scrollOf() {
        try {
            return frame.contentWindow ? frame.contentWindow.scrollY : 0;
        } catch (e) {
            return 0;
        }
    }

    function load(keepScroll) {
        if (!frame || !src) { return; }
        pendingScroll = keepScroll ? scrollOf() : 0;
        panel.classList.add('is-loading');
        frame.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
    }

    if (frame) {
        frame.addEventListener('load', function () {
            panel.classList.remove('is-loading');
            loaded = true;
            try {
                // the template decides the page width (e.g. 980 px HTML design): fit that, not a fixed A4
                var page = frame.contentDocument.querySelector('.cover, .page');
                var pageWidth = page ? page.getBoundingClientRect().width : 0;
                if (pageWidth > 200) { PAGE_WIDTH = Math.round(pageWidth + 40); }
                if (pendingScroll) { frame.contentWindow.scrollTo(0, pendingScroll); }
            } catch (e) { /* not same-origin: ignore */ }
            layout();
        });
    }

    function scheduleReload(delay) {
        clearTimeout(reloadTimer);
        reloadTimer = setTimeout(function () { load(true); }, delay);
    }

    // refresh the preview after every stored change
    function hook() {
        var ed = editor();
        if (!ed) { return false; }
        ed.onSaved(function (action) {
            scheduleReload(action === 'set_template' || action === 'apply_profile' ? 0 : 450);
        });
        // template / colors can also change through a website profile
        if (ed.onModel) {
            ed.onModel(function (m) {
                var info = m && m.template;
                if (!info) { return; }
                if (!cards.some(function (c) { return c.classList.contains('is-busy'); })) { markSelected(info.id); }
                if (templateName) { templateName.textContent = info.name; }
                if (paletteLabel) { paletteLabel.textContent = info.palette_label; }
                if (paletteSwatch && /^#[0-9a-f]{6}$/i.test(info.palette_color)) { paletteSwatch.style.background = info.palette_color; }
            });
        }
        return true;
    }
    if (!hook()) {
        document.addEventListener('DOMContentLoaded', hook);
    }

    layout();
    load(false);

    // -------------------------------------------------------- full screen
    function setFullscreen(on) {
        fullscreen = on;
        panel.classList.toggle('is-fullscreen', on);
        document.body.classList.toggle('preview-open', on);
        if (closeBtn) { closeBtn.hidden = !on; }
        if (fullBtn) {
            fullBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
            fullBtn.setAttribute('aria-label', on ? 'Exit full screen' : 'Full screen preview');
        }
        requestAnimationFrame(layout);
        (on ? closeBtn : fullBtn).focus({ preventScroll: true });
    }

    if (fullBtn) { fullBtn.addEventListener('click', function () { setFullscreen(!fullscreen); }); }
    if (closeBtn) { closeBtn.addEventListener('click', function () { setFullscreen(false); }); }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && fullscreen && !document.querySelector('.modal-backdrop')) {
            event.preventDefault();
            setFullscreen(false);
        }
    });

    // --------------------------------------------------- template chooser
    function markSelected(templateId) {
        cards.forEach(function (card) {
            var on = card.getAttribute('data-template') === templateId;
            card.classList.toggle('is-selected', on);
            card.setAttribute('aria-checked', on ? 'true' : 'false');
            card.tabIndex = on ? 0 : -1;
        });
    }

    function choose(card) {
        var ed = editor();
        var templateId = card.getAttribute('data-template');
        if (!ed || card.classList.contains('is-selected') || card.classList.contains('is-busy')) { return; }
        var previous = cards.filter(function (c) { return c.classList.contains('is-selected'); })[0];
        markSelected(templateId);
        card.classList.add('is-busy');
        panel.classList.add('is-loading');
        ed.send('set_template', { template: templateId }).then(function (res) {
            card.classList.remove('is-busy');
            if (!res.ok) {
                markSelected(previous ? previous.getAttribute('data-template') : '');
                panel.classList.remove('is-loading');
                return;
            }
            var info = res.data.model && res.data.model.template;
            if (info) {
                if (templateName) { templateName.textContent = info.name; }
                if (paletteLabel) { paletteLabel.textContent = info.palette_label; }
                if (paletteSwatch && /^#[0-9a-f]{6}$/i.test(info.palette_color)) { paletteSwatch.style.background = info.palette_color; }
                var missing = res.data.model.mapping ? res.data.model.mapping.missing : 0;
                R.toast('Template changed to “' + info.name + '”.' + (missing ? ' ' + missing + (missing === 1 ? ' field has' : ' fields have') + ' no value yet.' : ''), 'success');
            }
        });
    }

    cards.forEach(function (card, i) {
        card.addEventListener('click', function () { choose(card); });
        card.addEventListener('keydown', function (event) {
            var keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
            if (!keys[event.key]) { return; }
            event.preventDefault();
            var next = cards[(i + keys[event.key] + cards.length) % cards.length];
            next.focus();
            choose(next);
        });
    });

    // ----------------------------------------------------- preview as PDF
    if (draftBtn) {
        draftBtn.addEventListener('click', function () {
            if (draftBtn.disabled) { return; }
            draftBtn.disabled = true;
            var ed = editor();
            (ed ? ed.flush() : Promise.resolve({})).then(function () {
                var dialog = R.busy({
                    title: 'Rendering a PDF draft',
                    message: 'Creating the real PDF with your current data — it is not saved to your files.',
                    icon: 'fileCheck'
                });
                return R.post(draftUrl, { action: 'draft', id: id }).then(function (res) {
                    dialog.close();
                    draftBtn.disabled = false;
                    if (!res.ok || !res.data || !res.data.url) {
                        R.toast(res.message || 'The PDF could not be generated. Please try again.', 'error');
                        return;
                    }
                    setTimeout(function () {
                        R.modal({
                            title: 'Your PDF draft is ready.',
                            message: 'This is exactly how the PDF will look (' + res.data.size_label + '). Drafts are temporary and are not listed under Generated Files.',
                            icon: 'eye',
                            confirmLabel: 'Close',
                            cancelLabel: null,
                            links: [{ label: 'Open draft', href: res.data.url, newTab: true, primary: true, icon: 'eye' }]
                        });
                    }, 180);
                });
            });
        });
    }

    window.PdfRegen.preview = { reload: function () { load(true); }, loaded: function () { return loaded; } };
})();
