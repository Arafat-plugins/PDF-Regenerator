/* PDF Regenerator — Review & Edit Data page
 *
 *  - extract / extract again / generate PDF
 *  - extracted data editor: edit, include/exclude, remove/restore, add fields, list rows,
 *    drag or arrow-key reordering, search, Reset, Edit Data / Removed tabs
 *  - autosave: one change at a time to data.php; every answer carries the stored model
 *
 * All data is inserted with textContent / input.value. Only server-rendered icons use innerHTML.
 */
(function () {
    'use strict';

    var R = window.PdfRegen;
    var root = document.querySelector('[data-review]');
    if (!root || !R) { return; }

    var id = root.getAttribute('data-id');
    var extractUrl = root.getAttribute('data-extract-url');
    var dataUrl = root.getAttribute('data-data-url');
    var generateUrl = root.getAttribute('data-generate-url');
    var generateBtn = document.querySelector('[data-generate]');
    var generatedList = document.querySelector('[data-generated-list]');
    var generatedTemplate = document.querySelector('[data-generated-template]');
    var countPill = document.querySelector('[data-generated-count]');
    var emptyNote = document.querySelector('[data-generated-empty]');
    var busy = false;

    var editor = null;
    var editorRoot = document.querySelector('[data-editor]');
    var modelBlock = document.getElementById('editor-model');
    if (editorRoot && modelBlock) {
        try {
            editor = createEditor(editorRoot, JSON.parse(modelBlock.textContent || '{}'));
            R.editor = editor;
        } catch (e) {
            editor = null;
            if (window.console) { window.console.error(e); }
        }
    }

    function flushEdits() {
        return editor ? editor.flush() : Promise.resolve({ ok: true, invalid: 0 });
    }

    // ============================================================ extraction
    function runExtraction(extra) {
        if (busy) { return Promise.resolve({ ok: false }); }
        busy = true;
        var dialog = R.busy({
            title: 'Extracting data',
            message: 'Reading the text of your PDF and matching its report layout…',
            icon: 'scan'
        });
        var fields = { action: 'extract', id: id };
        Object.keys(extra || {}).forEach(function (k) { fields[k] = extra[k]; });
        return R.post(extractUrl, fields).then(function (res) {
            if (res.ok && res.data && res.data.redirect) {
                dialog.update('Done — loading the results…');
                if (editor) { editor.detach(); }
                window.location.href = res.data.redirect;
                return new Promise(function () {});
            }
            busy = false;
            dialog.close();
            if (!(res.data && res.data.field)) {
                R.toast(res.message || 'We couldn\'t extract readable data from this PDF.', 'error');
            }
            return res;
        });
    }

    document.querySelectorAll('[data-extract]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!R.profileChooser) { runExtraction({}); return; }
            R.profileChooser.open({
                title: 'Website Profile',
                message: 'Is this report for an existing website or a new one? The data is extracted right after your choice.',
                confirmLabel: 'Extract & Continue',
                submit: function (choice) { return runExtraction(R.profileChooser.toFields(choice)); }
            });
        });
    });

    document.querySelectorAll('[data-reextract]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            R.modal({
                title: 'Extract the data again?',
                message: 'The PDF is read again from the start. All values on this page — including your edits, added fields and removed items — are replaced with the new result. The website profile, template, logo and colors stay.',
                icon: 'refreshLg',
                confirmLabel: 'Extract again'
            }).then(function (result) {
                if (!result.confirmed) { return; }
                flushEdits().then(function () { runExtraction({ profile_mode: 'keep' }); });
            });
        });
    });

    // ============================================================ generation
    function addGenerated(item) {
        if (!generatedList || !generatedTemplate) { return; }
        var li = generatedTemplate.content.firstElementChild.cloneNode(true);
        li.querySelector('[data-g="name"]').textContent = item.file_name;
        li.querySelector('[data-g="name"]').title = item.file_name;
        li.querySelector('[data-g="meta"]').textContent = item.created_label + ' • ' + item.size_label;
        li.querySelector('[data-g="preview"]').href = item.preview_url;
        li.querySelector('[data-g="download"]').href = item.download_url;
        generatedList.insertBefore(li, generatedList.firstChild);
        setTimeout(function () { li.classList.remove('is-new'); }, 2400);
        if (countPill) { countPill.textContent = String(generatedList.children.length); }
        if (emptyNote) { emptyNote.hidden = true; }
    }

    function generate() {
        busy = true;
        generateBtn.disabled = true;
        R.generation.run({
            id: id,
            url: generateUrl,
            filesUrl: root.getAttribute('data-files-url'),
            onSuccess: function (data) {
                addGenerated(data.item);
                if (editor) {
                    editor.markGenerated();
                    if (data.profile) { editor.send('load', {}); }
                }
            }
        }).then(function () {
            busy = false;
            generateBtn.disabled = false;
        });
    }

    if (generateBtn) {
        generateBtn.addEventListener('click', function () {
            if (busy || generateBtn.disabled) { return; }
            busy = true;
            flushEdits().then(function (state) {
                busy = false;
                if (state.offline) {
                    R.toast('Your latest changes are not saved yet (connection problem). Please try again in a moment.', 'error');
                    return;
                }
                if (state.invalid > 0) {
                    R.modal({
                        title: state.invalid === 1 ? 'One value is not saved' : state.invalid + ' values are not saved',
                        message: 'The highlighted ' + (state.invalid === 1 ? 'value has' : 'values have') + ' a problem, so the report would use the last saved ' + (state.invalid === 1 ? 'value' : 'values') + ' instead.',
                        icon: 'alert',
                        confirmLabel: 'Generate anyway',
                        cancelLabel: 'Fix values'
                    }).then(function (result) {
                        if (result.confirmed) { generate(); } else if (editor) { editor.focusFirstError(); }
                    });
                    return;
                }
                generate();
            });
        });
    }

    // ================================================================ editor
    function createEditor(host, initialModel) {
        var el = R.el;
        var body = host.querySelector('[data-editor-body]');
        var removedBody = host.querySelector('[data-removed-body]');
        var removedEmpty = host.querySelector('[data-removed-empty]');
        var emptyBox = host.querySelector('[data-editor-empty]');
        var emptyTerm = host.querySelector('[data-empty-term]');
        var searchInput = host.querySelector('[data-editor-search]');
        var checkAll = host.querySelector('[data-check-all]');
        var countText = host.querySelector('[data-editor-count]');
        var removedCount = host.querySelector('[data-removed-count]');
        var reviewPill = host.querySelector('[data-review-pill]');
        var saveState = host.querySelector('[data-save-state]');
        var addForm = host.querySelector('[data-add-field]');
        var addOpen = host.querySelector('[data-add-open]');
        var addLabel = host.querySelector('[data-add-label]');
        var addValue = host.querySelector('[data-add-value]');
        var addError = host.querySelector('[data-add-error]');
        var tabs = Array.prototype.slice.call(host.querySelectorAll('[data-editor-tab]'));
        var panels = {
            edit: host.querySelector('[data-panel="edit"]'),
            removed: host.querySelector('[data-panel="removed"]'),
            mapping: host.querySelector('[data-panel="mapping"]')
        };
        var mapBody = host.querySelector('[data-map-body]');
        var mapIntro = host.querySelector('[data-map-intro]');
        var mapEmpty = host.querySelector('[data-map-empty]');
        var missingCount = host.querySelector('[data-missing-count]');
        var savedListeners = [];
        var modelListeners = [];

        // "Some template fields are missing" banner above the Edit Data table
        var missingBanner = el('div', 'map-banner');
        missingBanner.hidden = true;
        var missingBannerIcon = el('span', 'map-banner__icon');
        missingBannerIcon.innerHTML = R.icon('alertSm');
        missingBanner.appendChild(missingBannerIcon);
        var missingBannerText = el('span', 'map-banner__text');
        missingBanner.appendChild(missingBannerText);
        var missingBannerBtn = el('button', 'link-btn', 'Fill them in');
        missingBannerBtn.type = 'button';
        missingBanner.appendChild(missingBannerBtn);
        if (panels.edit) { panels.edit.insertBefore(missingBanner, panels.edit.firstChild); }

        var model = initialModel;       // model as last stored on the server
        var view = initialModel;        // model + changes still waiting to be saved
        var term = '';
        var detached = false;
        var everSaved = false;

        var groupEls = {};
        var fieldEls = {};
        var listEls = {};
        var datalists = {};
        var datalistHost = el('div');
        datalistHost.hidden = true;
        host.appendChild(datalistHost);

        body.textContent = '';
        var noData = el('div', 'editor-nodata');
        noData.hidden = true;
        var noDataIcon = el('span', 'editor-empty__icon');
        noDataIcon.innerHTML = R.icon('circlePlus');
        noData.appendChild(noDataIcon);
        noData.appendChild(el('p', 'editor-empty__title', 'No values to edit yet.'));
        noData.appendChild(el('p', 'editor-empty__text', 'Use “Add field” to enter the information for your report manually.'));

        // ------------------------------------------------------------ utils
        function clone(value) { return JSON.parse(JSON.stringify(value)); }

        function fold(text) {
            var s = String(text == null ? '' : text).toLowerCase();
            return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
        }

        function iconSpan(name, cls) {
            var span = el('span', cls || 'btn__icon');
            span.innerHTML = R.icon(name);
            return span;
        }

        function button(cls, label, iconName, text) {
            var b = el('button', cls);
            b.type = 'button';
            if (label) { b.setAttribute('aria-label', label); b.title = label; }
            if (iconName) { b.appendChild(iconSpan(iconName, 'btn__icon')); }
            if (text) { b.appendChild(el('span', null, text)); }
            return b;
        }

        function findField(m, key) {
            for (var i = 0; i < m.fields.length; i++) { if (m.fields[i].key === key) { return m.fields[i]; } }
            return null;
        }

        function findList(m, key) {
            for (var i = 0; i < m.lists.length; i++) { if (m.lists[i].key === key) { return m.lists[i]; } }
            return null;
        }

        function findRow(list, rid) {
            if (!list) { return null; }
            for (var i = 0; i < list.rows.length; i++) { if (list.rows[i].rid === rid) { return list.rows[i]; } }
            return null;
        }

        function datalistFor(name, options) {
            if (!options || !options.length) { return null; }
            if (!datalists[name]) {
                var dl = el('datalist');
                dl.id = 'dl-' + name.replace(/[^a-z0-9_-]/gi, '-');
                options.forEach(function (option) {
                    var o = el('option');
                    o.value = option;
                    dl.appendChild(o);
                });
                datalistHost.appendChild(dl);
                datalists[name] = dl.id;
            }
            return datalists[name];
        }

        function autoGrow(area) {
            if (area.offsetParent === null) {
                area._needsGrow = true; // hidden right now — measure when it is shown
                return;
            }
            area._needsGrow = false;
            area.style.height = 'auto';
            area.style.height = Math.min(area.scrollHeight + 2, 420) + 'px';
        }

        function growAll() {
            Array.prototype.forEach.call(host.querySelectorAll('textarea'), function (area) {
                if (area._needsGrow) { autoGrow(area); }
            });
        }

        var EDITED = { label: 'Edited', tone: 'blue', hint: 'Entered by you' };

        // ------------------------------------------------ optimistic changes
        /** Apply a queued change to a model copy, so the page never flickers back. */
        function optimistic(m, action, p) {
            var f, list, row, i, from;
            switch (action) {
                case 'set_value':
                    f = findField(m, p.key);
                    if (f) {
                        if (f.value !== p.value) { f.badge = EDITED; f.recalculable = f.derived; }
                        f.value = p.value;
                        f.needs_review = false;
                        f.can_confirm = false;
                    }
                    break;
                case 'set_label':
                    f = findField(m, p.key);
                    if (f) { f.label = p.label; }
                    break;
                case 'confirm':
                    f = findField(m, p.key);
                    if (f) { f.needs_review = false; f.can_confirm = false; }
                    break;
                case 'include':
                    f = findField(m, p.key);
                    if (f) { f.included = !!p.included; }
                    break;
                case 'include_many':
                    p.items.forEach(function (item) {
                        if (item.list) {
                            row = findRow(findList(m, item.list), item.rid);
                            if (row && !row.removed) { row.included = !!p.included; }
                        } else {
                            var ff = findField(m, item.key);
                            if (ff && !ff.removed) { ff.included = !!p.included; }
                        }
                    });
                    break;
                case 'remove':
                case 'restore':
                    f = findField(m, p.key);
                    if (f) {
                        f.removed = action === 'remove';
                        if (action === 'restore') { f.included = true; }
                    }
                    break;
                case 'move_field':
                    from = -1;
                    for (i = 0; i < m.fields.length; i++) { if (m.fields[i].key === p.key) { from = i; } }
                    if (from < 0) { break; }
                    f = m.fields.splice(from, 1)[0];
                    var at = -1;
                    for (i = 0; i < m.fields.length; i++) {
                        if (p.before ? m.fields[i].key === p.before : m.fields[i].section === f.section) {
                            at = p.before ? i : i + 1;
                            if (p.before) { break; }
                        }
                    }
                    m.fields.splice(at < 0 ? m.fields.length : at, 0, f);
                    break;
                case 'list_set':
                    row = findRow(findList(m, p.list), p.rid);
                    if (row) {
                        if (row.cells[p.column] !== p.value) { row.badge = EDITED; }
                        row.cells[p.column] = p.value;
                        row.needs_review = false;
                    }
                    break;
                case 'list_include':
                    row = findRow(findList(m, p.list), p.rid);
                    if (row) { row.included = !!p.included; }
                    break;
                case 'list_remove':
                case 'list_restore':
                    row = findRow(findList(m, p.list), p.rid);
                    if (row) {
                        row.removed = action === 'list_remove';
                        if (action === 'list_restore') { row.included = true; }
                    }
                    break;
                case 'list_move':
                    list = findList(m, p.list);
                    if (!list) { break; }
                    from = -1;
                    for (i = 0; i < list.rows.length; i++) { if (list.rows[i].rid === p.rid) { from = i; } }
                    if (from < 0) { break; }
                    row = list.rows.splice(from, 1)[0];
                    var to = list.rows.length;
                    if (p.before) {
                        for (i = 0; i < list.rows.length; i++) { if (list.rows[i].rid === p.before) { to = i; break; } }
                    }
                    list.rows.splice(to, 0, row);
                    break;
                case 'set_mapping':
                    if (m.mapping) {
                        m.mapping.fields.forEach(function (row) {
                            if (row.field === p.field) { row.choice = p.source === '' ? null : p.source; }
                        });
                    }
                    break;
                case 'list_confirm':
                    list = findList(m, p.list);
                    if (!list) { break; }
                    if (p.rid) {
                        row = findRow(list, p.rid);
                        if (row) { row.needs_review = false; }
                    } else {
                        list.needs_review = false;
                        list.note = '';
                    }
                    break;
            }
        }

        function counts(m) {
            var c = { review: 0, removed: 0, items: 0, groupReview: {} };
            m.fields.forEach(function (f) {
                if (f.removed) { c.removed++; return; }
                c.items++;
                if (f.needs_review && f.included) {
                    c.review++;
                    c.groupReview[f.section] = (c.groupReview[f.section] || 0) + 1;
                }
            });
            m.lists.forEach(function (list) {
                var flagged = list.needs_review;
                list.rows.forEach(function (r) {
                    if (r.removed) { c.removed++; return; }
                    c.items++;
                    if (r.needs_review && r.included) { c.review++; flagged = true; }
                });
                if (list.needs_review) { c.review++; }
                if (flagged) { c.groupReview[list.section] = (c.groupReview[list.section] || 0) + 1; }
            });
            return c;
        }

        // ------------------------------------------------------------ saver
        var queue = [];
        var inflight = null;
        var offline = false;
        var retryTimer = null;
        var dirty = {};      // change key → value typed but not stored yet
        var errors = {};     // change key → message
        var timers = {};     // change key → debounce timer
        var waiters = [];    // flush() callbacks

        function changeKey(action, p) {
            if (action === 'set_value') { return 'v:' + p.key; }
            if (action === 'set_label') { return 'n:' + p.key; }
            if (action === 'list_set') { return 'c:' + p.list + ':' + p.rid + ':' + p.column; }
            return null;
        }

        function send(action, payload, options) {
            var quiet = !!(options && options.quiet);
            return new Promise(function (resolve) {
                var ck = changeKey(action, payload);
                if (ck) {
                    for (var i = 0; i < queue.length; i++) {
                        if (queue[i].ck === ck) {
                            queue[i].payload = payload;
                            queue[i].done.push(resolve);
                            refresh();
                            return;
                        }
                    }
                }
                queue.push({ action: action, payload: payload, ck: ck, done: [resolve], quiet: quiet });
                refresh();
                pump();
            });
        }

        function pump() {
            if (inflight || offline || detached || !queue.length) {
                settle();
                return;
            }
            inflight = queue.shift();
            renderSaveState();
            var op = inflight;
            var bodyData = { id: id, action: op.action };
            Object.keys(op.payload).forEach(function (k) { bodyData[k] = op.payload[k]; });
            R.api(dataUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(bodyData)
            }).then(function (res) {
                inflight = null;
                handle(op, res);
                pump();
                renderSaveState();
            });
        }

        function handle(op, res) {
            var code = res.errors && res.errors[0];
            if (res.ok && res.data && res.data.model) {
                everSaved = true;
                if (op.ck) {
                    if (dirty[op.ck] === op.payload.value || dirty[op.ck] === op.payload.label) { delete dirty[op.ck]; }
                    delete errors[op.ck];
                }
                model = res.data.model;
                refresh();
                modelListeners.forEach(function (fn) { fn(model, op.action); });
                op.done.forEach(function (fn) { fn(res); });
                if (op.action !== 'load') {
                    savedListeners.forEach(function (fn) { fn(op.action, res.data); });
                }
                return;
            }
            if (code === 'network' || code === 'parse' || code === 'generation' || !code) {
                // Keep the change and try again shortly.
                queue.unshift(op);
                goOffline();
                return;
            }
            if (code === 'invalid' && op.ck) {
                errors[op.ck] = res.message;
                refresh();
            } else if (code === 'stale') {
                R.toast(res.message || 'This page was out of date and has been refreshed.', 'warning');
                reload();
            } else if (code === 'csrf') {
                R.toast('Your session has expired. Please refresh the page — your last change was not saved.', 'error');
                offline = true;
                queue.length = 0;
            } else {
                if (!op.quiet) { R.toast(res.message || 'Your change could not be saved. Please try again.', 'error'); }
                refresh();
            }
            op.done.forEach(function (fn) { fn(res); });
        }

        function goOffline() {
            offline = true;
            clearTimeout(retryTimer);
            retryTimer = setTimeout(retry, 8000);
            renderSaveState();
            settle();
        }

        function retry() {
            clearTimeout(retryTimer);
            offline = false;
            renderSaveState();
            pump();
        }

        function reload() {
            send('load', {}).then(function (res) {
                if (res.ok) {
                    Object.keys(dirty).forEach(function (k) { delete dirty[k]; });
                    Object.keys(errors).forEach(function (k) { delete errors[k]; });
                    refresh(true);
                }
            });
        }

        function pendingCount() {
            return queue.length + (inflight ? 1 : 0) + Object.keys(timers).length;
        }

        function settle() {
            if (pendingCount() > 0 && !offline) { return; }
            var state = { ok: !offline && !Object.keys(errors).length, invalid: Object.keys(errors).length, offline: offline };
            var list = waiters;
            waiters = [];
            list.forEach(function (fn) { fn(state); });
        }

        /** Save everything typed so far; resolves with {ok, invalid, offline}. */
        function flush() {
            Object.keys(timers).forEach(function (ck) { commitTimer(ck); });
            return new Promise(function (resolve) {
                waiters.push(resolve);
                if (offline) { retry(); }
                settle();
            });
        }

        function schedule(ck, fn, delay) {
            clearTimeout(timers[ck] && timers[ck].id);
            timers[ck] = { fn: fn, id: setTimeout(function () { commitTimer(ck); }, delay) };
            renderSaveState();
        }

        function commitTimer(ck) {
            var t = timers[ck];
            if (!t) { return; }
            clearTimeout(t.id);
            delete timers[ck];
            t.fn();
            renderSaveState();
            settle();
        }

        function renderSaveState() {
            if (!saveState) { return; }
            var invalid = Object.keys(errors).length;
            var working = pendingCount() > 0;
            var iconName = 'cloudCheck';
            var text = everSaved ? 'All changes saved' : 'Changes save automatically';
            var tone = 'ok';
            if (offline) {
                iconName = 'cloudAlert'; tone = 'error';
                text = 'Not saved — connection problem';
            } else if (working) {
                iconName = 'loader'; tone = 'busy';
                text = 'Saving…';
            } else if (invalid) {
                iconName = 'cloudAlert'; tone = 'warn';
                text = invalid === 1 ? '1 value not saved' : invalid + ' values not saved';
            }
            saveState.className = 'save-state save-state--' + tone;
            saveState.textContent = '';
            saveState.appendChild(iconSpan(iconName, 'save-state__icon'));
            saveState.appendChild(el('span', null, text));
            if (offline) {
                var again = button('link-btn', null, null, 'Retry');
                again.addEventListener('click', retry);
                saveState.appendChild(again);
            }
        }

        window.addEventListener('beforeunload', function (event) {
            if (!detached && (pendingCount() > 0 || offline)) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        // --------------------------------------------------------- render
        function refresh(force) {
            view = clone(model);
            queue.concat(inflight ? [inflight] : []).forEach(function (op) { optimistic(view, op.action, op.payload); });
            render(force);
        }

        function reconcile(container, wanted) {
            var keep = new Set(wanted);
            Array.prototype.slice.call(container.children).forEach(function (child) {
                if (!keep.has(child)) { container.removeChild(child); }
            });
            var ref = container.firstElementChild;
            wanted.forEach(function (node) {
                if (ref === node) {
                    ref = ref.nextElementSibling;
                    return;
                }
                container.insertBefore(node, ref);
            });
        }

        function render(force) {
            var c = counts(view);
            var wanted = [];
            view.sections.forEach(function (section) {
                var fields = view.fields.filter(function (f) { return f.section === section.id && !f.removed; });
                var lists = view.lists.filter(function (l) { return l.section === section.id; });
                if (!fields.length && !lists.length) { return; }
                var group = groupEls[section.id] || (groupEls[section.id] = buildGroup(section));
                updateGroup(group, section, fields, lists, c.groupReview[section.id] || 0, force);
                wanted.push(group);
            });
            noData.hidden = wanted.length > 0;
            wanted.push(noData);
            reconcile(body, wanted);
            renderRemoved();
            renderMapping(force);
            renderCounts(c);
            applyFilter();
        }

        function renderCounts(c) {
            if (removedCount) { removedCount.textContent = String(c.removed); }
            if (reviewPill) {
                reviewPill.classList.toggle('review-pill--ok', c.review === 0);
                reviewPill.textContent = '';
                reviewPill.appendChild(iconSpan(c.review === 0 ? 'circleCheck' : 'alertSm', 'review-pill__icon'));
                reviewPill.appendChild(el('span', null, c.review === 0
                    ? 'Nothing to review'
                    : c.review + (c.review === 1 ? ' field needs' : ' fields need') + ' your review'));
            }
            host.setAttribute('data-items', String(c.items));
        }

        // ---------------------------------------------------------- groups
        function buildGroup(section) {
            var group = el('section', 'edit-group');
            group.setAttribute('data-section', section.id);
            var title = el('h3', 'edit-group__title');
            var ic = el('span', 'edit-group__icon');
            ic.innerHTML = section.icon;
            title.appendChild(ic);
            title.appendChild(el('span', 'edit-group__name', section.title));
            var flag = el('span', 'edit-group__flag');
            flag.hidden = true;
            title.appendChild(flag);
            group.appendChild(title);
            var fieldsBox = el('div', 'edit-group__fields');
            var listsBox = el('div', 'edit-group__lists');
            group.appendChild(fieldsBox);
            group.appendChild(listsBox);
            group._title = section.title;
            group._flag = flag;
            group._fields = fieldsBox;
            group._lists = listsBox;
            return group;
        }

        function updateGroup(group, section, fields, lists, review, force) {
            group._flag.hidden = review === 0;
            group._flag.textContent = review + ' to review';
            reconcile(group._fields, fields.map(function (f) {
                var row = fieldEls[f.key];
                if (!row || row._kind !== f.input) {
                    row = buildFieldRow(f);
                    fieldEls[f.key] = row;
                }
                updateFieldRow(row, f, force);
                return row;
            }));
            reconcile(group._lists, lists.map(function (list) {
                var block = listEls[list.key] || (listEls[list.key] = buildList(list));
                updateList(block, list, force);
                return block;
            }));
        }

        // ---------------------------------------------------------- fields
        function buildFieldRow(f) {
            var row = el('div', 'edit-row');
            row.setAttribute('data-key', f.key);
            row._key = f.key;
            row._kind = f.input;

            var lead = el('div', 'edit-row__lead');
            var grip = button('grip', null, 'grip');
            grip.setAttribute('aria-label', 'Move ' + f.label + ' (drag, or use the arrow keys)');
            grip.title = 'Drag to reorder';
            lead.appendChild(grip);
            var check = el('input', 'checkbox');
            check.type = 'checkbox';
            lead.appendChild(check);
            row.appendChild(lead);

            var labelBox = el('div', 'edit-row__label');
            var name;
            if (f.custom) {
                name = el('input', 'edit-input edit-input--label');
                name.type = 'text';
                name.maxLength = 60;
                name.setAttribute('aria-label', 'Field name');
            } else {
                name = el('span', 'edit-row__name');
            }
            labelBox.appendChild(name);
            var review = el('span', 'edit-row__review');
            review.appendChild(iconSpan('circleAlert', 'edit-row__review-icon'));
            review.appendChild(el('span', null, 'Please confirm'));
            var confirmBtn = button('confirm-btn', null, 'checkSm', 'Confirm');
            review.appendChild(confirmBtn);
            labelBox.appendChild(review);
            var excluded = el('span', 'edit-row__excluded', 'Not in the report');
            labelBox.appendChild(excluded);
            row.appendChild(labelBox);

            var valueBox = el('div', 'edit-row__value');
            var input;
            if (f.input === 'textarea') {
                input = el('textarea', 'edit-input edit-input--area');
                input.rows = 2;
            } else {
                input = el('input', 'edit-input');
                input.type = f.input;
            }
            input.id = 'fld-' + f.key;
            input.setAttribute('inputmode', f.inputmode);
            if (f.placeholder) { input.placeholder = f.placeholder; }
            input.autocomplete = 'off';
            input.spellcheck = f.input === 'textarea' || f.input === 'text';
            var dl = datalistFor(f.key, f.suggest);
            if (dl && f.input === 'text') { input.setAttribute('list', dl); }
            valueBox.appendChild(input);
            var errorBox = el('p', 'edit-error');
            errorBox.id = 'err-' + f.key;
            errorBox.hidden = true;
            valueBox.appendChild(errorBox);
            var note = el('p', 'edit-row__note');
            valueBox.appendChild(note);
            var recalc = button('link-btn edit-row__recalc', null, 'sigma', 'Use calculated value');
            valueBox.appendChild(recalc);
            row.appendChild(valueBox);

            var meta = el('div', 'edit-row__meta');
            var badge = el('span', 'badge');
            meta.appendChild(badge);
            var page = el('span', 'page-tag');
            page.title = 'Page in the original PDF';
            meta.appendChild(page);
            labelBox.insertBefore(meta, review);

            var action = el('div', 'edit-row__action');
            var del = button('del-btn', null, 'trashSm');
            action.appendChild(del);
            row.appendChild(action);

            row._grip = grip;
            row._check = check;
            row._name = name;
            row._review = review;
            row._excluded = excluded;
            row._input = input;
            row._error = errorBox;
            row._note = note;
            row._recalc = recalc;
            row._badge = badge;
            row._page = page;
            row._del = del;

            var ckValue = 'v:' + f.key;
            var ckLabel = 'n:' + f.key;

            check.addEventListener('change', function () {
                send('include', { key: row._key, included: check.checked });
            });
            confirmBtn.addEventListener('click', function () {
                send('confirm', { key: row._key });
                input.focus();
            });
            recalc.addEventListener('click', function () {
                clearTimeout(timers[ckValue] && timers[ckValue].id);
                delete timers[ckValue];
                delete dirty[ckValue];
                delete errors[ckValue];
                send('recalculate', { key: row._key });
            });
            del.addEventListener('click', function () { removeField(row); });
            grip.addEventListener('pointerdown', function (event) { startDrag(event, row, 'field'); });
            grip.addEventListener('keydown', function (event) { keyMove(event, row, 'field'); });

            input.addEventListener('input', function () {
                if (input.tagName === 'TEXTAREA') { autoGrow(input); }
                dirty[ckValue] = input.value;
                if (errors[ckValue]) {
                    delete errors[ckValue];
                    showError(row, '');
                }
                schedule(ckValue, function () { commitValue(row); }, input.type === 'date' ? 150 : 700);
            });
            input.addEventListener('change', function () { commitTimerOrValue(ckValue, row); });
            input.addEventListener('blur', function () {
                commitTimerOrValue(ckValue, row);
                if (dirty[ckValue] === undefined && !errors[ckValue]) {
                    var f2 = findField(view, row._key);
                    if (f2 && input.value !== f2.value) { input.value = f2.value; }
                }
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' && input.tagName !== 'TEXTAREA') {
                    event.preventDefault();
                    commitTimerOrValue(ckValue, row);
                }
                if (event.key === 'Escape') {
                    var f2 = findField(model, row._key);
                    if (f2) {
                        clearTimeout(timers[ckValue] && timers[ckValue].id);
                        delete timers[ckValue];
                        delete dirty[ckValue];
                        delete errors[ckValue];
                        input.value = f2.value;
                        showError(row, '');
                        if (input.tagName === 'TEXTAREA') { autoGrow(input); }
                        renderSaveState();
                        settle();
                    }
                }
            });

            if (f.custom) {
                name.addEventListener('input', function () {
                    dirty[ckLabel] = name.value.trim();
                    schedule(ckLabel, function () { commitLabel(row); }, 800);
                });
                name.addEventListener('blur', function () {
                    commitTimerOrValue(ckLabel, row, true);
                    if (!errors[ckLabel] && name.value.trim() === '') {
                        var f2 = findField(model, row._key);
                        if (f2) { name.value = f2.label; delete dirty[ckLabel]; }
                    }
                });
                name.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') { event.preventDefault(); input.focus(); }
                });
            }
            return row;
        }

        function commitTimerOrValue(ck, row, isLabel) {
            if (timers[ck]) {
                commitTimer(ck);
            } else if (dirty[ck] !== undefined && !errors[ck]) {
                if (isLabel) { commitLabel(row); } else { commitValue(row); }
            }
        }

        function commitValue(row) {
            var ck = 'v:' + row._key;
            var value = row._input.value;
            var stored = findField(model, row._key);
            if (inflight && inflight.ck === ck && inflight.payload.value === value && !queue.some(function (op) { return op.ck === ck; })) {
                return; // this exact value is being saved right now
            }
            var queued = queue.some(function (op) { return op.ck === ck; }) || (inflight && inflight.ck === ck);
            if (stored && stored.value === value && !queued && !errors[ck]) {
                delete dirty[ck];
                if (stored.needs_review) { send('set_value', { key: row._key, value: value }); }
                return;
            }
            dirty[ck] = value;
            send('set_value', { key: row._key, value: value }).then(function (res) {
                if (!res.ok && res.errors && res.errors[0] === 'invalid') { showError(row, res.message); }
            });
        }

        function commitLabel(row) {
            var ck = 'n:' + row._key;
            var label = row._name.value.trim();
            var stored = findField(model, row._key);
            if (label === '' || (stored && stored.label === label)) {
                delete dirty[ck];
                return;
            }
            dirty[ck] = label;
            send('set_label', { key: row._key, label: label }).then(function (res) {
                if (!res.ok && res.errors && res.errors[0] === 'invalid') {
                    delete errors[ck];
                    R.toast(res.message, 'error');
                    renderSaveState();
                }
            });
        }

        function showError(row, message) {
            row._error.textContent = message;
            row._error.hidden = !message;
            row.classList.toggle('has-error', !!message);
            if (message) {
                row._input.setAttribute('aria-invalid', 'true');
                row._input.setAttribute('aria-describedby', row._error.id);
            } else {
                row._input.removeAttribute('aria-invalid');
                row._input.removeAttribute('aria-describedby');
            }
        }

        function updateFieldRow(row, f, force) {
            var ck = 'v:' + f.key;
            row._label = f.label;
            if (f.custom) {
                if (document.activeElement !== row._name && dirty['n:' + f.key] === undefined) { row._name.value = f.label; }
            } else {
                row._name.textContent = f.label;
            }
            row._check.checked = f.included;
            row._check.setAttribute('aria-label', 'Include ' + f.label + ' in the report');
            row._del.setAttribute('aria-label', 'Remove ' + f.label);
            row._del.title = 'Remove';
            row._input.setAttribute('aria-label', f.label);
            row.classList.toggle('needs-review', f.needs_review && f.included);
            row.classList.toggle('is-excluded', !f.included);
            row.classList.toggle('is-custom', f.custom);
            row._review.hidden = !(f.needs_review && f.included);
            row._review.lastChild.hidden = !f.can_confirm;
            row._excluded.hidden = f.included;

            var focused = document.activeElement === row._input;
            if (force || (!focused && dirty[ck] === undefined && !errors[ck])) {
                if (row._input.value !== f.value) {
                    row._input.value = f.value;
                    if (row._input.tagName === 'TEXTAREA') { requestAnimationFrame(function () { autoGrow(row._input); }); }
                }
            }
            if (row._input.tagName === 'TEXTAREA' && !row._grown) {
                row._grown = true;
                requestAnimationFrame(function () { autoGrow(row._input); });
            }
            showError(row, errors[ck] || '');
            row._note.textContent = f.note || '';
            row._note.hidden = !f.note;
            row._recalc.hidden = !f.recalculable;
            row._badge.className = 'badge badge--' + f.badge.tone;
            row._badge.textContent = f.badge.label;
            row._badge.title = f.badge.hint || '';
            row._page.hidden = f.page === null || f.page === undefined;
            row._page.textContent = 'p' + f.page;
        }

        function removeField(row) {
            var key = row._key;
            var label = row._label;
            var ck = 'v:' + key;
            if (timers[ck]) { commitTimer(ck); }
            var next = row.nextElementSibling || row.previousElementSibling;
            send('remove', { key: key });
            refresh();
            focusAfterRemoval(next, '.del-btn');
            R.toast('“' + label + '” moved to Removed.', 'info', {
                label: 'Undo',
                onClick: function () { send('restore', { key: key }); refresh(); }
            });
        }

        function focusAfterRemoval(next, selector) {
            if (next && next.isConnected && next.querySelector(selector)) {
                next.querySelector(selector).focus({ preventScroll: true });
            } else if (searchInput) {
                searchInput.focus({ preventScroll: true });
            }
        }

        // ----------------------------------------------------------- lists
        function buildList(list) {
            var block = el('div', 'edit-list');
            block.setAttribute('data-list', list.key);
            block._key = list.key;

            var head = el('div', 'edit-list__head');
            var titleBox = el('div', 'edit-list__titles');
            titleBox.appendChild(el('span', 'edit-list__title', list.label));
            var count = el('span', 'count-pill');
            titleBox.appendChild(count);
            var review = el('span', 'edit-row__review');
            review.appendChild(iconSpan('circleAlert', 'edit-row__review-icon'));
            var reviewText = el('span');
            review.appendChild(reviewText);
            titleBox.appendChild(review);
            head.appendChild(titleBox);
            var add = button('btn btn-secondary btn-xs edit-list__add', null, 'plus', 'Add row');
            head.appendChild(add);
            block.appendChild(head);

            var noteBox = el('div', 'edit-list__note');
            var noteText = el('span');
            noteBox.appendChild(iconSpan('circleAlert', 'edit-row__review-icon'));
            noteBox.appendChild(noteText);
            var noteOk = button('confirm-btn', null, 'checkSm', 'Looks right');
            noteBox.appendChild(noteOk);
            block.appendChild(noteBox);

            var wrap = el('div', 'table-wrap edit-list__wrap');
            var table = el('table', 'edit-table');
            var colgroup = el('colgroup');
            var colLead = el('col', 'col-lead');
            colgroup.appendChild(colLead);
            colgroup.appendChild(el('col', 'col-num'));
            list.columns.forEach(function (column) { colgroup.appendChild(el('col', 'col-' + column.size)); });
            colgroup.appendChild(el('col', 'col-source'));
            colgroup.appendChild(el('col', 'col-action'));
            table.appendChild(colgroup);
            // keep free-text columns readable: the table scrolls sideways instead of squeezing them
            var sizes = { xs: 92, sm: 104, md: 150, auto: 190 };
            table.style.minWidth = list.columns.reduce(function (sum, column) { return sum + (sizes[column.size] || 190); }, 64 + 34 + 162 + 50) + 'px';
            var thead = el('thead');
            var tr = el('tr');
            var th = el('th', 'edit-table__lead');
            th.appendChild(el('span', 'sr-only', 'Include and move'));
            tr.appendChild(th);
            tr.appendChild(el('th', 'edit-table__num', '#'));
            list.columns.forEach(function (column) {
                var cell = el('th', column.numeric ? 'is-num' : null, column.label);
                cell.scope = 'col';
                tr.appendChild(cell);
            });
            tr.appendChild(el('th', 'edit-table__source', 'Source'));
            var thAction = el('th', 'edit-table__action');
            thAction.appendChild(el('span', 'sr-only', 'Remove'));
            tr.appendChild(thAction);
            thead.appendChild(tr);
            table.appendChild(thead);
            var tbody = el('tbody');
            table.appendChild(tbody);
            wrap.appendChild(table);
            block.appendChild(wrap);
            var empty = el('p', 'edit-list__empty', 'No rows. Use “Add row” to add one.');
            block.appendChild(empty);

            block._count = count;
            block._review = review;
            block._reviewText = reviewText;
            block._note = noteBox;
            block._noteText = noteText;
            block._tbody = tbody;
            block._wrap = wrap;
            block._empty = empty;
            block._add = add;
            block._rows = {};
            block._label = list.label;

            add.addEventListener('click', function () {
                if (add.disabled) { return; }
                add.disabled = true;
                send('list_add', { list: list.key }).then(function (res) {
                    add.disabled = false;
                    if (res.ok && res.data.result && res.data.result.rid) {
                        var tr2 = block._rows[res.data.result.rid];
                        if (tr2) {
                            tr2.classList.add('is-new');
                            setTimeout(function () { tr2.classList.remove('is-new'); }, 2400);
                            var first = tr2.querySelector('.edit-input');
                            if (first) {
                                first.focus({ preventScroll: true });
                                tr2.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                            }
                        }
                    }
                });
            });
            noteOk.addEventListener('click', function () { send('list_confirm', { list: list.key }); });
            return block;
        }

        function updateList(block, list, force) {
            var visibleRows = list.rows.filter(function (r) { return !r.removed; });
            var review = visibleRows.filter(function (r) { return r.needs_review && r.included; }).length;
            block._count.textContent = String(visibleRows.length);
            block._review.hidden = review === 0;
            block._reviewText.textContent = review + (review === 1 ? ' row to confirm' : ' rows to confirm');
            block._note.hidden = !list.note && !list.needs_review;
            block._noteText.textContent = list.note || 'Please check this list.';
            block._note.lastChild.hidden = !list.needs_review;
            block.classList.toggle('needs-review', list.needs_review);
            block._add.hidden = list.rows.length >= list.max_rows;
            block._empty.hidden = visibleRows.length > 0;
            block._wrap.hidden = visibleRows.length === 0;
            block._list = list;

            var n = 0;
            reconcile(block._tbody, visibleRows.map(function (r) {
                var tr = block._rows[r.rid] || (block._rows[r.rid] = buildListRow(block, list, r));
                n++;
                updateListRow(tr, list, r, n, force);
                return tr;
            }));
        }

        function buildListRow(block, list, r) {
            var tr = el('tr', 'edit-trow');
            tr.setAttribute('data-rid', r.rid);
            tr._rid = r.rid;
            tr._list = list.key;
            tr._inputs = {};

            var lead = el('td', 'edit-table__lead');
            var leadBox = el('div', 'edit-row__lead');
            var grip = button('grip', 'Move row (drag, or use the arrow keys)', 'grip');
            leadBox.appendChild(grip);
            var check = el('input', 'checkbox');
            check.type = 'checkbox';
            leadBox.appendChild(check);
            lead.appendChild(leadBox);
            tr.appendChild(lead);
            var num = el('td', 'edit-table__num');
            tr.appendChild(num);

            list.columns.forEach(function (column) {
                var td = el('td', column.numeric ? 'is-num' : null);
                var input = el('input', 'edit-input edit-input--cell' + (column.numeric ? ' is-num' : ''));
                var textCell = r.text_cells.indexOf(column.name) !== -1;
                input.type = textCell ? 'text' : column.input;
                input.setAttribute('inputmode', column.inputmode);
                if (column.placeholder && !column.numeric) { input.placeholder = column.placeholder; }
                input.autocomplete = 'off';
                input.spellcheck = false;
                input.setAttribute('aria-label', column.label);
                var dl = datalistFor(list.key + '.' + column.name, column.suggest);
                if (dl) { input.setAttribute('list', dl); }
                td.appendChild(input);
                var err = el('p', 'edit-error edit-error--cell');
                err.hidden = true;
                td.appendChild(err);
                tr.appendChild(td);
                tr._inputs[column.name] = { input: input, error: err };
                wireCell(tr, block, column.name, input, err);
            });

            var source = el('td', 'edit-table__source');
            var sourceBox = el('div', 'edit-src');
            var badge = el('span', 'badge');
            sourceBox.appendChild(badge);
            var review = button('confirm-btn', null, 'checkSm', 'Confirm');
            review.title = 'Please confirm — this value was only shown as an icon in the PDF';
            sourceBox.appendChild(review);
            source.appendChild(sourceBox);
            tr.appendChild(source);

            var action = el('td', 'edit-table__action');
            var del = button('del-btn', 'Remove row', 'trashSm');
            action.appendChild(del);
            tr.appendChild(action);

            tr._check = check;
            tr._num = num;
            tr._badge = badge;
            tr._review = review;
            tr._del = del;
            tr._grip = grip;

            check.addEventListener('change', function () {
                send('list_include', { list: tr._list, rid: tr._rid, included: check.checked });
            });
            review.addEventListener('click', function () {
                send('list_confirm', { list: tr._list, rid: tr._rid });
            });
            del.addEventListener('click', function () { removeListRow(block, tr); });
            grip.addEventListener('pointerdown', function (event) { startDrag(event, tr, 'row'); });
            grip.addEventListener('keydown', function (event) { keyMove(event, tr, 'row'); });
            return tr;
        }

        function wireCell(tr, block, column, input, err) {
            var ck = 'c:' + tr._list + ':' + tr._rid + ':' + column;
            function commit() {
                var list = findList(model, tr._list);
                var stored = findRow(list, tr._rid);
                if (inflight && inflight.ck === ck && inflight.payload.value === input.value && !queue.some(function (op) { return op.ck === ck; })) {
                    return;
                }
                var queued = queue.some(function (op) { return op.ck === ck; }) || (inflight && inflight.ck === ck);
                if (stored && stored.cells[column] === input.value && !queued && !errors[ck]) {
                    delete dirty[ck];
                    return;
                }
                dirty[ck] = input.value;
                send('list_set', { list: tr._list, rid: tr._rid, column: column, value: input.value }).then(function (res) {
                    if (!res.ok && res.errors && res.errors[0] === 'invalid') { cellError(tr, column, res.message); }
                });
            }
            input.addEventListener('input', function () {
                dirty[ck] = input.value;
                if (errors[ck]) {
                    delete errors[ck];
                    cellError(tr, column, '');
                }
                schedule(ck, commit, input.type === 'date' ? 150 : 700);
            });
            function now() {
                if (timers[ck]) { commitTimer(ck); } else if (dirty[ck] !== undefined && !errors[ck]) { commit(); }
            }
            input.addEventListener('change', now);
            input.addEventListener('blur', function () {
                now();
                if (dirty[ck] === undefined && !errors[ck]) {
                    var r = findRow(findList(view, tr._list), tr._rid);
                    if (r && input.value !== r.cells[column]) { input.value = r.cells[column]; }
                }
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); now(); }
                if (event.key === 'Escape') {
                    var r = findRow(findList(model, tr._list), tr._rid);
                    if (r) {
                        clearTimeout(timers[ck] && timers[ck].id);
                        delete timers[ck];
                        delete dirty[ck];
                        delete errors[ck];
                        input.value = r.cells[column];
                        cellError(tr, column, '');
                        renderSaveState();
                        settle();
                    }
                }
            });
        }

        function cellError(tr, column, message) {
            var cell = tr._inputs[column];
            cell.error.textContent = message;
            cell.error.hidden = !message;
            cell.input.classList.toggle('is-invalid', !!message);
            if (message) { cell.input.setAttribute('aria-invalid', 'true'); } else { cell.input.removeAttribute('aria-invalid'); }
            if (message) { cell.input.title = message; } else { cell.input.removeAttribute('title'); }
        }

        function updateListRow(tr, list, r, n, force) {
            tr._num.textContent = String(n);
            tr._check.checked = r.included;
            tr._check.setAttribute('aria-label', 'Include row ' + n + ' of ' + list.label + ' in the report');
            tr._review.setAttribute('aria-label', 'Confirm row ' + n + ' of ' + list.label);
            tr._del.setAttribute('aria-label', 'Remove row ' + n + ' of ' + list.label);
            tr.classList.toggle('needs-review', r.needs_review && r.included);
            tr.classList.toggle('is-excluded', !r.included);
            tr._review.hidden = !(r.needs_review && r.included);
            tr._badge.className = 'badge badge--' + r.badge.tone;
            tr._badge.textContent = r.badge.label;
            tr._badge.title = r.badge.hint || '';
            tr._summary = list.columns.map(function (c) { return r.cells[c.name]; }).filter(Boolean)[0] || '';
            list.columns.forEach(function (column) {
                var ck = 'c:' + list.key + ':' + r.rid + ':' + column.name;
                var cell = tr._inputs[column.name];
                var value = r.cells[column.name] || '';
                var focused = document.activeElement === cell.input;
                if (force || (!focused && dirty[ck] === undefined && !errors[ck])) {
                    if (cell.input.type === 'date' && value !== '' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) { cell.input.type = 'text'; }
                    if (cell.input.value !== value) { cell.input.value = value; }
                }
                cellError(tr, column.name, errors[ck] || '');
            });
        }

        function removeListRow(block, tr) {
            var list = tr._list;
            var rid = tr._rid;
            Object.keys(timers).forEach(function (ck) {
                if (ck.indexOf('c:' + list + ':' + rid + ':') === 0) { commitTimer(ck); }
            });
            var next = tr.nextElementSibling || tr.previousElementSibling;
            var summary = tr._summary;
            send('list_remove', { list: list, rid: rid });
            refresh();
            focusAfterRemoval(next, '.del-btn');
            R.toast('Row ' + (summary ? '“' + (summary.length > 40 ? summary.slice(0, 40) + '…' : summary) + '” ' : '') + 'moved to Removed.', 'info', {
                label: 'Undo',
                onClick: function () { send('list_restore', { list: list, rid: rid }); refresh(); }
            });
        }

        // ------------------------------------------------------- reordering
        function siblingsOf(node) {
            return Array.prototype.slice.call(node.parentNode.children).filter(function (child) { return !child.hidden; });
        }

        function flip(nodes, mutate) {
            var before = nodes.map(function (n) { return n.getBoundingClientRect().top; });
            mutate();
            nodes.forEach(function (n, i) {
                var delta = before[i] - n.getBoundingClientRect().top;
                if (!delta || n.classList.contains('is-dragging')) { return; }
                n.style.transition = 'none';
                n.style.transform = 'translateY(' + delta + 'px)';
                n.getBoundingClientRect();
                n.style.transition = 'transform .16s cubic-bezier(.2,.7,.2,1)';
                n.style.transform = '';
            });
        }

        function commitMove(node, kind) {
            var next = node.nextElementSibling;
            while (next && next.hidden) { next = next.nextElementSibling; }
            if (kind === 'field') {
                send('move_field', { key: node._key, before: next ? next._key : null });
            } else {
                send('list_move', { list: node._list, rid: node._rid, before: next ? next._rid : null });
            }
            refresh();
        }

        function keyMove(event, node, kind) {
            if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') { return; }
            event.preventDefault();
            if (term) {
                R.toast('Clear the search to reorder rows.', 'info');
                return;
            }
            var sibs = siblingsOf(node);
            var i = sibs.indexOf(node);
            var other = event.key === 'ArrowUp' ? sibs[i - 1] : sibs[i + 1];
            if (!other) { return; }
            flip(sibs, function () {
                // move the neighbour, never the focused row
                if (event.key === 'ArrowUp') {
                    node.parentNode.insertBefore(other, node.nextElementSibling);
                } else {
                    node.parentNode.insertBefore(other, node);
                }
            });
            commitMove(node, kind);
            if (kind === 'row') { renumber(node.parentNode); }
            node._grip.focus({ preventScroll: true });
            node.scrollIntoView({ block: 'nearest' });
        }

        function renumber(tbody) {
            var n = 0;
            Array.prototype.forEach.call(tbody.children, function (tr) { tr._num.textContent = String(++n); });
        }

        var drag = null;

        function startDrag(event, node, kind) {
            if (event.button !== undefined && event.button !== 0) { return; }
            if (term) {
                R.toast('Clear the search to reorder rows.', 'info');
                return;
            }
            event.preventDefault();
            var rect = node.getBoundingClientRect();
            drag = {
                node: node,
                kind: kind,
                pointer: event.pointerId,
                y: event.clientY,
                offset: event.clientY - rect.top,
                startNext: node.nextElementSibling,
                scroll: 0,
                frame: 0
            };
            node.classList.add('is-dragging');
            document.body.classList.add('is-sorting');
            document.addEventListener('pointermove', onDragMove, { passive: false });
            document.addEventListener('pointerup', endDrag);
            document.addEventListener('pointercancel', endDrag);
            drag.frame = requestAnimationFrame(dragFrame);
        }

        function onDragMove(event) {
            if (!drag || event.pointerId !== drag.pointer) { return; }
            event.preventDefault();
            drag.y = event.clientY;
            var top = 140; // sticky top bar + a margin
            var bottom = window.innerHeight - 70;
            drag.scroll = event.clientY < top ? -Math.min(24, Math.ceil((top - event.clientY) / 4))
                : (event.clientY > bottom ? Math.min(24, Math.ceil((event.clientY - bottom) / 4)) : 0);
            position();
        }

        function dragFrame() {
            if (!drag) { return; }
            if (drag.scroll) {
                window.scrollBy(0, drag.scroll);
                position();
            }
            drag.frame = requestAnimationFrame(dragFrame);
        }

        function position() {
            var node = drag.node;
            var sibs = siblingsOf(node);
            var guard = 0;
            var moved = true;
            while (moved && guard++ < 200) {
                moved = false;
                var i = sibs.indexOf(node);
                var prev = sibs[i - 1];
                var next = sibs[i + 1];
                if (next) {
                    var nr = next.getBoundingClientRect();
                    if (drag.y > nr.top + nr.height / 2) {
                        flip(sibs, function () { node.parentNode.insertBefore(next, node); });
                        sibs = siblingsOf(node);
                        moved = true;
                        continue;
                    }
                }
                if (prev) {
                    var pr = prev.getBoundingClientRect();
                    if (drag.y < pr.top + pr.height / 2) {
                        flip(sibs, function () { node.parentNode.insertBefore(prev, node.nextElementSibling); });
                        sibs = siblingsOf(node);
                        moved = true;
                    }
                }
            }
            node.style.transform = 'none';
            var natural = node.getBoundingClientRect().top;
            node.style.transform = 'translateY(' + (drag.y - drag.offset - natural) + 'px)';
        }

        function endDrag(event) {
            if (!drag || (event && event.pointerId !== drag.pointer)) { return; }
            var d = drag;
            drag = null;
            cancelAnimationFrame(d.frame);
            document.removeEventListener('pointermove', onDragMove);
            document.removeEventListener('pointerup', endDrag);
            document.removeEventListener('pointercancel', endDrag);
            document.body.classList.remove('is-sorting');
            d.node.style.transition = 'transform .16s cubic-bezier(.2,.7,.2,1)';
            d.node.style.transform = '';
            setTimeout(function () {
                d.node.classList.remove('is-dragging');
                d.node.style.transition = '';
            }, 170);
            if (d.kind === 'row') { renumber(d.node.parentNode); }
            if (d.node.nextElementSibling !== d.startNext) { commitMove(d.node, d.kind); }
        }

        // ---------------------------------------------------------- removed
        function renderRemoved() {
            removedBody.textContent = '';
            var total = 0;
            view.sections.forEach(function (section) {
                var items = [];
                view.fields.forEach(function (f) {
                    if (f.section === section.id && f.removed) {
                        items.push({ label: f.label, value: f.value, kind: 'Field', restore: function () { return ['restore', { key: f.key }]; } });
                    }
                });
                view.lists.forEach(function (list) {
                    if (list.section !== section.id) { return; }
                    list.rows.forEach(function (r) {
                        if (!r.removed) { return; }
                        var parts = list.columns.map(function (c) { return r.cells[c.name]; }).filter(Boolean);
                        items.push({
                            label: parts[0] || 'Empty row',
                            value: parts.slice(1).join(' · '),
                            kind: list.label + ' row',
                            restore: function () { return ['list_restore', { list: list.key, rid: r.rid }]; }
                        });
                    });
                });
                if (!items.length) { return; }
                var group = el('section', 'removed-group');
                var title = el('h3', 'edit-group__title');
                var ic = el('span', 'edit-group__icon');
                ic.innerHTML = section.icon;
                title.appendChild(ic);
                title.appendChild(el('span', 'edit-group__name', section.title));
                group.appendChild(title);
                items.forEach(function (item) {
                    var row = el('div', 'removed-row');
                    row._search = fold(section.title + ' ' + item.label + ' ' + item.value + ' ' + item.kind);
                    var text = el('div', 'removed-row__text');
                    text.appendChild(el('p', 'removed-row__label', item.label));
                    text.appendChild(el('p', 'removed-row__value', item.value || 'Empty'));
                    if (!item.value) { text.lastChild.classList.add('is-empty'); }
                    row.appendChild(text);
                    row.appendChild(el('span', 'removed-row__kind', item.kind));
                    var restore = button('btn btn-secondary btn-sm', 'Restore ' + item.label, 'restore', 'Restore');
                    restore.addEventListener('click', function () {
                        var op = item.restore();
                        var next = row.nextElementSibling || row.previousElementSibling;
                        send(op[0], op[1]);
                        refresh();
                        R.toast('“' + item.label + '” is back in Edit Data.', 'success');
                        if (next && next.isConnected) {
                            var b = next.querySelector('button');
                            if (b) { b.focus({ preventScroll: true }); }
                        }
                    });
                    row.appendChild(restore);
                    group.appendChild(row);
                    total++;
                });
                removedBody.appendChild(group);
            });
            removedBody._total = total;
        }

        // ----------------------------------------------------------- search
        function textOf(row) {
            if (row._input) {
                return fold(row._label + ' ' + row._input.value + ' ' + row._badge.textContent);
            }
            var parts = [];
            Object.keys(row._inputs).forEach(function (k) { parts.push(row._inputs[k].input.value); });
            return fold(parts.join(' ') + ' ' + row._badge.textContent);
        }

        function applyFilter() {
            var shown = 0;
            var total = 0;
            Object.keys(groupEls).forEach(function (sectionId) {
                var group = groupEls[sectionId];
                if (!group.isConnected) { return; }
                var sectionHit = term !== '' && fold(group._title).indexOf(term) !== -1;
                var any = false;
                Array.prototype.forEach.call(group._fields.children, function (row) {
                    var hit = term === '' || sectionHit || textOf(row).indexOf(term) !== -1;
                    row.hidden = !hit;
                    total++;
                    if (hit) { shown++; any = true; }
                });
                Array.prototype.forEach.call(group._lists.children, function (block) {
                    var listHit = term === '' || sectionHit || fold(block._label).indexOf(term) !== -1;
                    var rowsShown = 0;
                    Array.prototype.forEach.call(block._tbody.children, function (tr) {
                        var hit = listHit || textOf(tr).indexOf(term) !== -1;
                        tr.hidden = !hit;
                        total++;
                        if (hit) { shown++; rowsShown++; }
                    });
                    block.hidden = !(listHit || rowsShown > 0);
                    if (!block.hidden) { any = true; }
                });
                group.hidden = !any;
            });
            host.classList.toggle('is-filtering', term !== '');
            emptyBox.hidden = !(term !== '' && shown === 0);
            if (emptyTerm) { emptyTerm.textContent = searchInput.value.trim(); }
            countText.textContent = term === ''
                ? 'Showing ' + total + ' of ' + total + ' items'
                : 'Showing ' + shown + ' of ' + total + ' items';

            var removedShown = 0;
            Array.prototype.forEach.call(removedBody.querySelectorAll('.removed-row'), function (row) {
                var hit = term === '' || row._search.indexOf(term) !== -1;
                row.hidden = !hit;
                if (hit) { removedShown++; }
            });
            Array.prototype.forEach.call(removedBody.children, function (group) {
                group.hidden = !group.querySelector('.removed-row:not([hidden])');
            });
            removedEmpty.hidden = removedShown > 0;
            removedEmpty.querySelector('.editor-empty__title').textContent = removedBody._total > 0 ? 'No matching removed items.' : 'No removed items.';

            if (mapBody) {
                var mapShown = 0;
                Array.prototype.forEach.call(mapBody.children, function (row) {
                    if (row.classList.contains('map-lists')) {
                        var anyList = false;
                        Array.prototype.forEach.call(row.querySelectorAll('.map-list-row'), function (item) {
                            item.hidden = term !== '' && item._search.indexOf(term) === -1;
                            anyList = anyList || !item.hidden;
                        });
                        row.hidden = !anyList;
                        return;
                    }
                    var hit = term === '' || (row._search || '').indexOf(term) !== -1;
                    row.hidden = !hit;
                    if (hit) { mapShown++; }
                });
                if (mapEmpty) { mapEmpty.hidden = !(term !== '' && mapShown === 0); }
            }
            updateCheckAll();
            growAll();
        }

        function visibleItems() {
            var items = [];
            Object.keys(groupEls).forEach(function (sectionId) {
                var group = groupEls[sectionId];
                if (!group.isConnected || group.hidden) { return; }
                Array.prototype.forEach.call(group._fields.children, function (row) {
                    if (!row.hidden) { items.push({ node: row, item: { key: row._key } }); }
                });
                Array.prototype.forEach.call(group._lists.children, function (block) {
                    if (block.hidden) { return; }
                    Array.prototype.forEach.call(block._tbody.children, function (tr) {
                        if (!tr.hidden) { items.push({ node: tr, item: { list: tr._list, rid: tr._rid } }); }
                    });
                });
            });
            return items;
        }

        function updateCheckAll() {
            if (!checkAll) { return; }
            var items = visibleItems();
            var on = items.filter(function (x) { return x.node._check.checked; }).length;
            checkAll.disabled = items.length === 0;
            checkAll.checked = items.length > 0 && on === items.length;
            checkAll.indeterminate = on > 0 && on < items.length;
        }

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                var items = visibleItems();
                if (!items.length) { return; }
                var included = checkAll.checked;
                send('include_many', { included: included, items: items.map(function (x) { return x.item; }) });
                refresh();
            });
        }

        var searchTimer = null;
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    term = fold(searchInput.value.trim());
                    applyFilter();
                }, 120);
            });
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && searchInput.value !== '') {
                    event.preventDefault();
                    clearSearch();
                }
            });
        }

        function clearSearch() {
            searchInput.value = '';
            term = '';
            applyFilter();
            searchInput.focus();
        }

        host.querySelectorAll('[data-search-clear]').forEach(function (b) { b.addEventListener('click', clearSearch); });


        // ----------------------------------------------------- template fields
        var METHODS = {
            exact: ['Same field', 'blue'],
            matched: ['Matched', 'purple'],
            similar: ['Suggested', 'amber'],
            manual: ['Your choice', 'green'],
            empty: ['Left empty', 'gray'],
            none: ['Missing', 'red']
        };
        var mapRows = {};
        var mapLists = null;

        function sourceOptions(select, mapping, row) {
            select.textContent = '';
            var auto = el('option', null, row.choice === null && row.source
                ? 'Automatic — ' + row.source_label
                : (row.choice === null ? 'Automatic — nothing found' : 'Automatic'));
            auto.value = '';
            select.appendChild(auto);
            var none = el('option', null, 'Leave empty');
            none.value = '-';
            select.appendChild(none);
            var groups = {};
            mapping.sources.forEach(function (src) {
                if (!groups[src.section]) {
                    groups[src.section] = el('optgroup');
                    groups[src.section].label = src.section;
                    select.appendChild(groups[src.section]);
                }
                var o = el('option', null, src.label + (src.key === row.field ? ' (same field)' : ''));
                o.value = src.key;
                groups[src.section].appendChild(o);
            });
            select.value = row.choice === null ? '' : row.choice;
            if (select.value !== (row.choice === null ? '' : row.choice)) { select.value = ''; }
        }

        function buildMapRow(row) {
            var node = el('div', 'map-row');
            node._field = row.field;
            var fieldBox = el('div', 'map-row__field');
            var name = el('span', 'map-row__label');
            fieldBox.appendChild(name);
            var meta = el('span', 'map-row__meta');
            var key = el('code', 'map-row__key');
            meta.appendChild(key);
            var badge = el('span', 'badge');
            meta.appendChild(badge);
            fieldBox.appendChild(meta);
            node.appendChild(fieldBox);

            var select = el('select', 'edit-input map-row__select');
            select.addEventListener('change', function () {
                send('set_mapping', { field: node._field, source: select.value });
            });
            node.appendChild(select);

            var valueBox = el('div', 'map-row__value');
            node.appendChild(valueBox);

            node._name = name;
            node._key = key;
            node._badge = badge;
            node._select = select;
            node._valueBox = valueBox;
            node._mode = '';
            return node;
        }

        function mapInput(node, row) {
            var ck = 'v:' + row.field;
            var input = el('input', 'edit-input map-row__input');
            input.type = row.input === 'date' ? 'date' : row.input;
            input.setAttribute('inputmode', row.inputmode);
            input.placeholder = 'Empty — enter value';
            input.setAttribute('aria-label', row.label);
            input.autocomplete = 'off';
            var err = el('p', 'edit-error');
            err.hidden = true;
            function commit() {
                clearTimeout(timers[ck] && timers[ck].id);
                delete timers[ck];
                if (input.value.trim() === '') {
                    delete dirty[ck];
                    renderSaveState();
                    settle();
                    return;
                }
                dirty[ck] = input.value;
                send('set_value', { key: node._field, value: input.value }).then(function (res) {
                    if (!res.ok && res.errors && res.errors[0] === 'invalid') {
                        err.textContent = res.message;
                        err.hidden = false;
                        input.setAttribute('aria-invalid', 'true');
                    }
                });
            }
            input.addEventListener('input', function () {
                dirty[ck] = input.value;
                if (errors[ck]) {
                    delete errors[ck];
                    err.hidden = true;
                    input.removeAttribute('aria-invalid');
                }
                schedule(ck, commit, input.type === 'date' ? 150 : 900);
            });
            input.addEventListener('change', function () { if (timers[ck]) { commitTimer(ck); } });
            input.addEventListener('blur', function () {
                if (timers[ck]) { commitTimer(ck); }
                setTimeout(function () { renderMapping(false); applyFilter(); }, 0); // show the saved value once the field is left
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') { event.preventDefault(); if (timers[ck]) { commitTimer(ck); } }
            });
            node._valueBox.appendChild(input);
            node._valueBox.appendChild(err);
            node._input = input;
        }

        function updateMapRow(node, row, mapping, force) {
            var focused = node.contains(document.activeElement);
            node._name.textContent = row.label;
            node._key.textContent = row.field;
            var method = row.missing ? METHODS.none : (METHODS[row.method] || METHODS.none);
            node._badge.className = 'badge badge--' + method[1];
            node._badge.textContent = method[0];
            node.classList.toggle('is-missing', row.missing);
            node._search = fold(row.label + ' ' + row.field + ' ' + row.value + ' ' + (row.source_label || ''));
            if (document.activeElement !== node._select) { sourceOptions(node._select, mapping, row); }
            node._select.setAttribute('aria-label', 'Data used for ' + row.label);

            var mode = row.missing && !row.derived && (row.source === null || row.source === row.field) ? 'input' : 'text';
            if (focused && !force && node._mode === 'input') { return; }
            if (node._mode !== mode || mode === 'text' || force) {
                node._valueBox.textContent = '';
                node._input = null;
                node._mode = mode;
                if (mode === 'input') {
                    mapInput(node, row);
                } else {
                    var shown = row.display || row.value;
                    var text = el('span', 'map-row__text', row.method === 'empty' ? 'Left empty in the report' : (shown || 'Empty'));
                    if (row.method === 'empty' || !shown) { text.classList.add('is-empty'); }
                    text.title = shown;
                    node._valueBox.appendChild(text);
                    if (row.missing && row.derived) {
                        node._valueBox.appendChild(el('span', 'map-row__hint', 'Calculated from other values'));
                    }
                }
            }
        }

        function renderMapping(force) {
            var mapping = view.mapping;
            if (!mapBody) { return; }
            if (!mapping) {
                mapBody.textContent = '';
                if (mapIntro) { mapIntro.textContent = 'No template is selected.'; }
                return;
            }
            var wanted = mapping.fields.map(function (row) {
                var node = mapRows[row.field] || (mapRows[row.field] = buildMapRow(row));
                updateMapRow(node, row, mapping, force);
                return node;
            });
            if (!mapLists) {
                mapLists = el('div', 'map-lists');
            }
            mapLists.textContent = '';
            if (mapping.lists.length) {
                mapLists.appendChild(el('p', 'map-lists__title', 'Lists and tables in this template'));
                mapping.lists.forEach(function (list) {
                    var item = el('div', 'map-list-row');
                    item._search = fold(list.label + ' ' + list.field);
                    item.appendChild(el('span', 'map-list-row__label', list.label));
                    var info = list.derived ? 'Built automatically' : (list.rows > 0 ? list.rows + (list.rows === 1 ? ' row' : ' rows') : 'No rows — the block is left out');
                    var tag = el('span', 'badge badge--' + (list.derived ? 'green' : (list.rows > 0 ? 'blue' : 'gray')), info);
                    item.appendChild(tag);
                    mapLists.appendChild(item);
                });
            }
            wanted.push(mapLists);
            reconcile(mapBody, wanted);

            var missing = mapping.missing;
            if (missingCount) {
                missingCount.hidden = missing === 0;
                missingCount.textContent = String(missing);
            }
            if (mapIntro) {
                mapIntro.textContent = '';
                mapIntro.className = 'map-intro' + (missing ? ' map-intro--warn' : '');
                var ic = el('span', 'map-intro__icon');
                ic.innerHTML = R.icon(missing ? 'alertSm' : 'infoSm');
                mapIntro.appendChild(ic);
                var txt = el('p');
                txt.appendChild(el('strong', null, missing ? 'Some template fields are missing.' : 'Every template field has data.'));
                txt.appendChild(document.createTextNode(missing
                    ? ' Enter a value, pick other data, or leave them empty — the PDF is created either way.'
                    : ' You can still choose which data fills each field.'));
                mapIntro.appendChild(txt);
            }
            missingBanner.hidden = missing === 0;
            missingBannerText.textContent = missing === 1
                ? '1 field of the selected template has no value.'
                : missing + ' fields of the selected template have no value.';
        }

        missingBannerBtn.addEventListener('click', function () {
            selectTab('mapping', false);
            var first = mapBody && mapBody.querySelector('.map-row.is-missing input, .map-row.is-missing select');
            if (first) { first.focus(); }
        });

        // ------------------------------------------------------------- tabs
        function selectTab(name, focus) {
            tabs.forEach(function (tab) {
                var active = tab.getAttribute('data-editor-tab') === name;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
                if (active && focus) { tab.focus(); }
            });
            Object.keys(panels).forEach(function (key) { panels[key].hidden = key !== name; });
            if (name === 'edit') { growAll(); }
        }

        tabs.forEach(function (tab, i) {
            tab.addEventListener('click', function () { selectTab(tab.getAttribute('data-editor-tab'), false); });
            tab.addEventListener('keydown', function (event) {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' && event.key !== 'Home' && event.key !== 'End') { return; }
                event.preventDefault();
                var next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
                    : (i + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
                selectTab(tabs[next].getAttribute('data-editor-tab'), true);
            });
        });

        // -------------------------------------------------------- add field
        function openAdd() {
            addForm.hidden = false;
            addOpen.hidden = true;
            addError.hidden = true;
            addLabel.focus();
            addForm.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }

        function closeAdd() {
            addForm.hidden = true;
            addOpen.hidden = false;
            addLabel.value = '';
            addValue.value = '';
            addError.hidden = true;
            addLabel.removeAttribute('aria-invalid');
        }

        if (addForm) {
            addOpen.addEventListener('click', function () {
                selectTab('edit', false);
                openAdd();
            });
            host.querySelector('[data-add-cancel]').addEventListener('click', function () {
                closeAdd();
                addOpen.focus();
            });
            addForm.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeAdd();
                    addOpen.focus();
                }
            });
            addForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var label = addLabel.value.trim();
                if (label === '') {
                    addError.textContent = 'Please enter a field name.';
                    addError.hidden = false;
                    addLabel.setAttribute('aria-invalid', 'true');
                    addLabel.focus();
                    return;
                }
                var submit = addForm.querySelector('[type="submit"]');
                submit.disabled = true;
                send('add_field', { label: label, value: addValue.value }).then(function (res) {
                    submit.disabled = false;
                    if (!res.ok) {
                        addError.textContent = res.message || 'The field could not be added.';
                        addError.hidden = false;
                        (res.data && res.data.field === 'value' ? addValue : addLabel).focus();
                        return;
                    }
                    var key = res.data.result && res.data.result.key;
                    closeAdd();
                    if (term) { clearSearch(); }
                    var row = key && fieldEls[key];
                    if (row) {
                        row.classList.add('is-new');
                        setTimeout(function () { row.classList.remove('is-new'); }, 2400);
                        row.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        row._input.focus({ preventScroll: true });
                    }
                    R.toast('Field “' + label + '” added.', 'success');
                });
            });
        }

        // ------------------------------------------------------------ reset
        host.querySelectorAll('[data-editor-reset]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                R.modal({
                    title: 'Reset all data?',
                    message: 'Your edits, added fields, removed items and row order are replaced with the data originally extracted from the PDF. This cannot be undone.',
                    icon: 'restoreLg',
                    confirmLabel: 'Reset data'
                }).then(function (result) {
                    if (!result.confirmed) { return; }
                    Object.keys(timers).forEach(function (ck) {
                        clearTimeout(timers[ck].id);
                        delete timers[ck];
                    });
                    queue.length = 0;
                    Object.keys(dirty).forEach(function (k) { delete dirty[k]; });
                    Object.keys(errors).forEach(function (k) { delete errors[k]; });
                    btn.disabled = true;
                    var run = function () {
                        send('reset', {}).then(function (res) {
                            btn.disabled = false;
                            if (res.ok) {
                                // rows are rebuilt so custom rows and ids start fresh
                                Object.keys(fieldEls).forEach(function (k) { delete fieldEls[k]; });
                                Object.keys(listEls).forEach(function (k) { delete listEls[k]; });
                                Object.keys(groupEls).forEach(function (k) { delete groupEls[k]; });
                                refresh(true);
                                R.toast(res.message || 'The original extracted data has been restored.', 'success');
                            }
                        });
                    };
                    if (inflight) { flush().then(run); } else { run(); }
                });
            });
        });

        // ------------------------------------------------------------- init
        refresh(true);
        renderSaveState();

        return {
            flush: flush,
            send: send,
            onSaved: function (fn) { savedListeners.push(fn); },
            onModel: function (fn) { modelListeners.push(fn); },
            reload: reload,
            selectTab: selectTab,
            model: function () { return model; },
            detach: function () { detached = true; },
            markGenerated: function () {},
            focusFirstError: function () {
                // after the dialog has handed focus back
                setTimeout(function () {
                    var bad = host.querySelector('[aria-invalid="true"]');
                    if (bad) {
                        selectTab('edit', false);
                        if (term) { clearSearch(); }
                        bad.scrollIntoView({ block: 'center', behavior: 'smooth' });
                        bad.focus({ preventScroll: true });
                    }
                }, 220);
            }
        };
    }
})();
