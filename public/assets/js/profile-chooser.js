/* PDF Regenerator — "Website Profile" chooser (Part D §14)
 *
 * Used on the Upload page (inline card and "Use" dialog) and on the editor page
 * (profile chip, "Extract data"). All text is inserted with textContent.
 *
 *   PdfRegen.profileChooser.create({profiles, initial, onChange}) → chooser
 *   PdfRegen.profileChooser.open({title, message, confirmLabel, profiles, initial, submit}) → Promise<{confirmed, value}>
 *
 * chooser.value() → {mode: 'existing', id} | {mode: 'new', name, url}
 */
(function () {
    'use strict';

    var R = window.PdfRegen;
    if (!R) { return; }
    var el = R.el;
    var uid = 0;

    function readProfiles() {
        var block = document.getElementById('profiles-data');
        if (!block) { return null; }
        try {
            var data = JSON.parse(block.textContent || '{}');
            return data;
        } catch (e) {
            return null;
        }
    }

    var pageData = readProfiles() || { profiles: [], url: 'blueprints.php' };
    var store = {
        list: pageData.profiles || [],
        url: pageData.url || 'blueprints.php',
        listeners: [],
        set: function (list) {
            store.list = list || [];
            store.listeners.forEach(function (fn) { fn(store.list); });
        }
    };

    function iconEl(name, cls) {
        var span = el('span', cls || 'pc-ic');
        span.setAttribute('aria-hidden', 'true');
        span.innerHTML = R.icon(name);
        return span;
    }

    function profileMark(p) {
        var mark = el('span', 'pc-mark');
        mark.setAttribute('aria-hidden', 'true');
        if (p.logo_url) {
            var img = el('img');
            img.src = p.logo_url;
            img.alt = '';
            img.loading = 'lazy';
            mark.appendChild(img);
        } else {
            mark.textContent = (p.name || '?').trim().charAt(0).toUpperCase();
            mark.style.background = p.color || '#64748b';
            mark.classList.add('pc-mark--letter');
        }
        return mark;
    }

    function subline(p) {
        var parts = [];
        if (p.host) { parts.push(p.host); }
        if (p.used_label) { parts.push('used ' + p.used_label); }
        return parts.join(' · ');
    }

    /**
     * @param {{profiles?: Array, initial?: Object, onChange?: Function, compact?: boolean}} opts
     */
    function create(opts) {
        opts = opts || {};
        var n = ++uid;
        var profiles = opts.profiles || store.list;
        var initial = opts.initial || {};
        var state = {
            mode: initial.mode === 'new' ? 'new' : (initial.mode === 'existing' ? 'existing' : ''),
            id: initial.id || null,
            open: false,
            active: -1
        };
        var changeFns = opts.onChange ? [opts.onChange] : [];

        var root = el('div', 'pc' + (opts.compact ? ' pc--compact' : ''));
        if (opts.question !== false) {
            root.appendChild(el('p', 'pc__question', 'Is this report for an existing website or a new one?'));
        }

        var grid = el('div', 'pc__grid');
        grid.setAttribute('role', 'radiogroup');
        grid.setAttribute('aria-label', 'Website profile');
        root.appendChild(grid);

        // ------------------------------------------------ existing profile
        var exBox = el('div', 'pc-option');
        var exHead = el('label', 'pc-option__head');
        var exRadio = el('input', 'radio');
        exRadio.type = 'radio';
        exRadio.name = 'pc-mode-' + n;
        exRadio.value = 'existing';
        exHead.appendChild(exRadio);
        var exText = el('span', 'pc-option__text');
        exText.appendChild(el('span', 'pc-option__title', 'Existing Profile'));
        var exHint = el('span', 'pc-option__hint', 'Template, colors, logo and texts are filled in for you.');
        exText.appendChild(exHint);
        exHead.appendChild(exText);
        exBox.appendChild(exHead);

        var select = el('div', 'pc-select');
        select.setAttribute('data-escape-guard', '');
        var selectBtn = el('button', 'pc-select__button');
        selectBtn.type = 'button';
        selectBtn.id = 'pc-select-' + n;
        selectBtn.setAttribute('aria-haspopup', 'listbox');
        selectBtn.setAttribute('aria-expanded', 'false');
        var selectValue = el('span', 'pc-select__value');
        selectBtn.appendChild(selectValue);
        selectBtn.appendChild(iconEl('chevronDown', 'pc-select__chevron'));
        select.appendChild(selectBtn);
        var listbox = el('ul', 'pc-select__list');
        listbox.id = 'pc-list-' + n;
        listbox.setAttribute('role', 'listbox');
        listbox.setAttribute('aria-labelledby', selectBtn.id);
        listbox.tabIndex = -1;
        listbox.hidden = true;
        select.appendChild(listbox);
        selectBtn.setAttribute('aria-controls', listbox.id);
        exBox.appendChild(select);

        var summary = el('div', 'pc-summary');
        var summaryText = el('p', 'pc-summary__text');
        summary.appendChild(summaryText);
        var menuWrap = el('div', 'dropdown pc-summary__menu');
        menuWrap.setAttribute('data-dropdown', '');
        var menuBtn = el('button', 'icon-btn icon-btn--bordered');
        menuBtn.type = 'button';
        menuBtn.setAttribute('data-dropdown-toggle', '');
        menuBtn.setAttribute('aria-haspopup', 'menu');
        menuBtn.setAttribute('aria-expanded', 'false');
        menuBtn.setAttribute('aria-label', 'Rename or delete this profile');
        menuBtn.innerHTML = R.icon('ellipsis');
        menuWrap.appendChild(menuBtn);
        var menu = el('div', 'dropdown__menu dropdown__menu--compact');
        menu.setAttribute('role', 'menu');
        menu.hidden = true;
        var renameItem = el('button', 'dropdown__item');
        renameItem.type = 'button';
        renameItem.setAttribute('role', 'menuitem');
        renameItem.appendChild(iconEl('pencilSm', 'dropdown__icon'));
        renameItem.appendChild(el('span', null, 'Rename'));
        var deleteItem = el('button', 'dropdown__item dropdown__item--danger');
        deleteItem.type = 'button';
        deleteItem.setAttribute('role', 'menuitem');
        deleteItem.appendChild(iconEl('trashSm', 'dropdown__icon'));
        deleteItem.appendChild(el('span', null, 'Delete'));
        menu.appendChild(renameItem);
        menu.appendChild(deleteItem);
        menuWrap.appendChild(menu);
        summary.appendChild(menuWrap);
        exBox.appendChild(summary);

        var exEmpty = el('p', 'pc-empty', 'No saved profiles yet. A profile is saved automatically the first time you generate a report for a new website.');
        exBox.appendChild(exEmpty);
        grid.appendChild(exBox);

        // ------------------------------------------------------ new website
        var nwBox = el('div', 'pc-option');
        var nwHead = el('label', 'pc-option__head');
        var nwRadio = el('input', 'radio');
        nwRadio.type = 'radio';
        nwRadio.name = 'pc-mode-' + n;
        nwRadio.value = 'new';
        nwHead.appendChild(nwRadio);
        var nwText = el('span', 'pc-option__text');
        nwText.appendChild(el('span', 'pc-option__title', 'New Website'));
        nwText.appendChild(el('span', 'pc-option__hint', 'Saved as a profile when you generate the report.'));
        nwHead.appendChild(nwText);
        nwBox.appendChild(nwHead);

        function field(labelText, type, placeholder, maxLength, optional) {
            var wrap = el('div', 'pc-field');
            var label = el('label', 'pc-field__label', labelText);
            var input = el('input', 'input');
            input.type = type;
            input.id = 'pc-' + labelText.toLowerCase().replace(/\W+/g, '-') + '-' + n;
            input.placeholder = placeholder;
            input.maxLength = maxLength;
            input.autocomplete = 'off';
            input.spellcheck = false;
            label.htmlFor = input.id;
            if (optional) { label.appendChild(el('span', 'pc-field__optional', ' (optional)')); }
            wrap.appendChild(label);
            wrap.appendChild(input);
            var error = el('p', 'pc-field__error');
            error.id = input.id + '-error';
            error.hidden = true;
            wrap.appendChild(error);
            nwBox.appendChild(wrap);
            return { wrap: wrap, input: input, error: error };
        }
        var nameField = field('Website name', 'text', 'e.g. Woodford Oil', 60, false);
        var urlField = field('Website URL', 'url', 'https://example.com', 300, true);
        nwBox.appendChild(el('p', 'pc-field__hint', 'The address is filled in from the PDF when it contains one.'));
        nameField.input.value = initial.mode === 'new' ? (initial.name || '') : '';
        urlField.input.value = initial.mode === 'new' ? (initial.url || '') : '';
        grid.appendChild(nwBox);

        var formError = el('p', 'pc__error');
        formError.setAttribute('role', 'alert');
        formError.hidden = true;
        root.appendChild(formError);

        // --------------------------------------------------------- behaviour
        function selected() {
            for (var i = 0; i < profiles.length; i++) {
                if (profiles[i].id === state.id) { return profiles[i]; }
            }
            return null;
        }

        function renderValue() {
            selectValue.textContent = '';
            var p = selected();
            if (!p) {
                selectValue.appendChild(el('span', 'pc-select__placeholder', profiles.length ? 'Choose a website…' : 'No saved profiles yet'));
                summary.hidden = true;
                return;
            }
            selectValue.appendChild(profileMark(p));
            var text = el('span', 'pc-select__text');
            text.appendChild(el('span', 'pc-select__name', p.name));
            text.appendChild(el('span', 'pc-select__sub', subline(p)));
            selectValue.appendChild(text);

            summary.hidden = false;
            summaryText.textContent = '';
            var line1 = el('span', 'pc-summary__line');
            line1.appendChild(iconEl('layoutTemplate', 'pc-summary__icon'));
            line1.appendChild(el('span', 'pc-summary__strong', p.template));
            summaryText.appendChild(line1);
            var line2 = el('span', 'pc-summary__line');
            var sw = el('span', 'swatch swatch--sm');
            sw.style.background = p.color;
            line2.appendChild(sw);
            line2.appendChild(el('span', null, p.palette));
            line2.appendChild(el('span', 'pc-summary__dot', '·'));
            line2.appendChild(el('span', null, p.logo ? 'Own logo' : 'Template logo'));
            summaryText.appendChild(line2);
        }

        function renderList() {
            listbox.textContent = '';
            profiles.forEach(function (p, i) {
                var li = el('li', 'pc-select__option');
                li.id = 'pc-opt-' + n + '-' + i;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', p.id === state.id ? 'true' : 'false');
                li.appendChild(profileMark(p));
                var text = el('span', 'pc-select__text');
                text.appendChild(el('span', 'pc-select__name', p.name));
                text.appendChild(el('span', 'pc-select__sub', subline(p) + (p.reports ? ' · ' + p.reports + (p.reports === 1 ? ' report' : ' reports') : '')));
                li.appendChild(text);
                if (p.id === state.id) { li.appendChild(iconEl('checkSm', 'pc-select__tick')); }
                li.addEventListener('mousedown', function (event) { event.preventDefault(); });
                li.addEventListener('click', function () { pick(i); });
                listbox.appendChild(li);
            });
        }

        function setActive(i) {
            state.active = i;
            Array.prototype.forEach.call(listbox.children, function (li, j) {
                li.classList.toggle('is-active', i === j);
                if (i === j) {
                    listbox.setAttribute('aria-activedescendant', li.id);
                    li.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function openList() {
            if (!profiles.length) { return; }
            setMode('existing');
            renderList();
            listbox.hidden = false;
            select.classList.add('is-open');
            selectBtn.setAttribute('aria-expanded', 'true');
            var current = -1;
            profiles.forEach(function (p, i) { if (p.id === state.id) { current = i; } });
            setActive(current < 0 ? 0 : current);
            listbox.focus({ preventScroll: true });
        }

        function closeList(focusButton) {
            if (!select.classList.contains('is-open')) { return; }
            listbox.hidden = true;
            select.classList.remove('is-open');
            selectBtn.setAttribute('aria-expanded', 'false');
            listbox.removeAttribute('aria-activedescendant');
            if (focusButton) { selectBtn.focus({ preventScroll: true }); }
        }
        select.addEventListener('guard-close', function () { closeList(true); });

        function pick(i) {
            state.id = profiles[i] ? profiles[i].id : null;
            closeList(true);
            renderValue();
            clearErrors();
            emit();
        }

        selectBtn.addEventListener('click', function () {
            if (select.classList.contains('is-open')) { closeList(true); } else { openList(); }
        });
        selectBtn.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                openList();
            }
        });
        listbox.addEventListener('keydown', function (event) {
            var count = profiles.length;
            if (event.key === 'ArrowDown') { event.preventDefault(); setActive((state.active + 1) % count); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); setActive((state.active - 1 + count) % count); }
            else if (event.key === 'Home') { event.preventDefault(); setActive(0); }
            else if (event.key === 'End') { event.preventDefault(); setActive(count - 1); }
            else if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); event.stopPropagation(); pick(state.active); }
            else if (event.key === 'Tab') { closeList(false); }
            else if (event.key === 'Escape') { event.preventDefault(); closeList(true); }
        });
        listbox.addEventListener('blur', function () {
            setTimeout(function () { if (!select.contains(document.activeElement)) { closeList(false); } }, 0);
        });
        document.addEventListener('mousedown', function (event) {
            if (!select.contains(event.target)) { closeList(false); }
        });

        function setMode(mode) {
            if (mode === 'existing' && !profiles.length) { mode = 'new'; }
            var changed = state.mode !== mode;
            state.mode = mode;
            exRadio.checked = mode === 'existing';
            nwRadio.checked = mode === 'new';
            exBox.classList.toggle('is-active', mode === 'existing');
            nwBox.classList.toggle('is-active', mode === 'new');
            if (changed) { clearErrors(); }
            emit();
        }

        exRadio.addEventListener('change', function () { if (exRadio.checked) { setMode('existing'); } });
        nwRadio.addEventListener('change', function () { if (nwRadio.checked) { setMode('new'); } });
        [nameField.input, urlField.input].forEach(function (input) {
            input.addEventListener('focus', function () { if (state.mode !== 'new') { setMode('new'); } });
            input.addEventListener('input', function () { clearErrors(); emit(); });
        });
        exBox.addEventListener('click', function (event) {
            if (state.mode !== 'existing' && profiles.length && !event.target.closest('.pc-option__head')) { setMode('existing'); }
        });

        function clearErrors() {
            [nameField, urlField].forEach(function (f) {
                f.error.hidden = true;
                f.input.removeAttribute('aria-invalid');
                f.input.removeAttribute('aria-describedby');
            });
            formError.hidden = true;
        }

        function showError(fieldName, message) {
            var target = fieldName === 'website_name' || fieldName === 'name' ? nameField : (fieldName === 'website_url' || fieldName === 'url' ? urlField : null);
            if (target) {
                if (state.mode !== 'new') { setMode('new'); }
                target.error.textContent = message;
                target.error.hidden = false;
                target.input.setAttribute('aria-invalid', 'true');
                target.input.setAttribute('aria-describedby', target.error.id);
                target.input.focus();
                return;
            }
            formError.textContent = message;
            formError.hidden = false;
        }

        function value() {
            if (state.mode === 'existing') { return { mode: 'existing', id: state.id }; }
            if (state.mode === 'new') { return { mode: 'new', name: nameField.input.value.trim(), url: urlField.input.value.trim() }; }
            return { mode: '' };
        }

        function isValid() {
            var v = value();
            return (v.mode === 'existing' && !!selected()) || (v.mode === 'new' && v.name !== '');
        }

        function emit() {
            changeFns.forEach(function (fn) { fn(value(), isValid()); });
        }

        function setProfiles(list) {
            profiles = list || [];
            if (!selected()) { state.id = profiles.length ? profiles[0].id : null; }
            exBox.classList.toggle('is-disabled', !profiles.length);
            exRadio.disabled = !profiles.length;
            selectBtn.disabled = !profiles.length;
            exEmpty.hidden = !!profiles.length;
            select.hidden = !profiles.length;
            exHint.textContent = profiles.length ? 'Template, colors, logo and texts are filled in for you.' : 'No saved profiles yet';
            if (!profiles.length && state.mode === 'existing') { state.mode = 'new'; }
            renderValue();
            setMode(state.mode || (profiles.length ? 'existing' : 'new'));
        }

        // -------------------------------------------- rename / delete profile
        renameItem.addEventListener('click', function () {
            var p = selected();
            if (!p) { return; }
            R.modal({
                title: 'Rename website profile',
                message: 'Reports already generated keep their file names.',
                icon: 'pencil',
                input: { label: 'Profile name', value: p.name, maxLength: 60 },
                confirmLabel: 'Save',
                beforeClose: function (name) {
                    if (name === p.name) { return true; }
                    return R.post(store.url, { action: 'rename', id: p.id, name: name }).then(function (res) {
                        if (!res.ok) {
                            R.toast(res.message || 'The profile could not be renamed.', 'error');
                            return false;
                        }
                        store.set(res.data.profiles);
                        R.toast(res.message, 'success');
                        return true;
                    });
                }
            });
        });

        deleteItem.addEventListener('click', function () {
            var p = selected();
            if (!p) { return; }
            R.modal({
                title: 'Delete “' + p.name + '”?',
                message: 'The saved template, colors, logo and texts for this website are removed. Your generated PDFs are kept.',
                tone: 'danger',
                confirmLabel: 'Delete profile',
                beforeClose: function () {
                    return R.post(store.url, { action: 'delete', id: p.id }).then(function (res) {
                        if (!res.ok) {
                            R.toast(res.message || 'The profile could not be deleted.', 'error');
                            if (res.errors && res.errors[0] === 'not_found') { refreshList(); }
                            return false;
                        }
                        state.id = null;
                        store.set(res.data.profiles);
                        R.toast(res.message, 'success');
                        return true;
                    });
                }
            });
        });

        store.listeners.push(function (list) { setProfiles(list); });

        if (state.mode === 'existing' && !state.id && profiles.length) { state.id = profiles[0].id; }
        if (!state.id && profiles.length) { state.id = profiles[0].id; }
        setProfiles(profiles);

        return {
            node: root,
            value: value,
            isValid: isValid,
            showError: showError,
            onChange: function (fn) { changeFns.push(fn); },
            setProfiles: setProfiles,
            focus: function () {
                if (state.mode === 'new') { return nameField.input; }
                return selectBtn.disabled ? nwRadio : selectBtn;
            }
        };
    }

    function refreshList() {
        return R.api(store.url).then(function (res) {
            if (res.ok && res.data) { store.set(res.data.profiles); }
            return store.list;
        });
    }

    /** Fields for extract.php. */
    function toFields(v) {
        if (v.mode === 'existing') { return { profile_mode: 'existing', profile: v.id }; }
        if (v.mode === 'new') { return { profile_mode: 'new', website_name: v.name, website_url: v.url }; }
        return {};
    }

    /**
     * Chooser in a dialog. submit(value) → Promise<{ok, message, errors, data}>; a failed answer keeps the dialog open.
     */
    function open(opts) {
        var chooser = create({ initial: opts.initial, profiles: store.list, question: false });
        var wrap = el('div', 'modal__content');
        if (opts.source) {
            var src = el('p', 'pc-source');
            src.appendChild(iconEl('fileCheck', 'pc-source__icon'));
            src.appendChild(el('span', null, opts.source));
            wrap.appendChild(src);
        }
        wrap.appendChild(chooser.node);
        return R.modal({
            title: opts.title || 'Website Profile',
            message: opts.message || '',
            icon: 'globeLg',
            wide: true,
            content: wrap,
            confirmLabel: opts.confirmLabel || 'Continue',
            focus: chooser.focus,
            beforeClose: function () {
                var v = chooser.value();
                if (!chooser.isValid()) {
                    chooser.showError(v.mode === 'new' ? 'website_name' : '', v.mode === 'new' ? 'Please enter a website name.' : 'Please choose a website profile.');
                    return false;
                }
                return Promise.resolve(opts.submit(v)).then(function (res) {
                    if (res && res.ok) { return true; }
                    var field = res && res.data && res.data.field;
                    chooser.showError(field || '', (res && res.message) || 'Please check your choice.');
                    if (res && res.errors && res.errors[0] === 'profile') { refreshList(); }
                    return false;
                });
            }
        });
    }

    R.profileChooser = {
        create: create,
        open: open,
        toFields: toFields,
        refresh: refreshList,
        profiles: function () { return store.list; },
        onProfiles: function (fn) { store.listeners.push(fn); }
    };
})();
