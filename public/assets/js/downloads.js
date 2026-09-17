/* PDF Regenerator — Generated Files page (search, template filter, refresh, pagination,
 * rename, delete). Values are inserted with textContent only.
 */
(function () {
    'use strict';

    var R = window.PdfRegen;
    var section = document.querySelector('[data-generated]');
    if (!R || !section) { return; }

    var endpoint = section.getAttribute('data-endpoint');
    var listUrl = section.getAttribute('data-list-url');
    var newId = section.getAttribute('data-new');
    var tbody = section.querySelector('[data-body]');
    var template = section.querySelector('[data-row-template]');
    var tools = section.querySelector('[data-tools]');
    var search = section.querySelector('[data-search]');
    var filter = section.querySelector('[data-filter]');
    var refreshBtn = section.querySelector('[data-refresh]');
    var tableWrap = section.querySelector('[data-table]');
    var empty = section.querySelector('[data-empty]');
    var noMatch = section.querySelector('[data-nomatch]');
    var footer = section.querySelector('[data-footer]');
    var pageInfo = section.querySelector('[data-page-info]');
    var pager = section.querySelector('[data-pager]');
    var PAGE_SIZE = 10;
    var page = 1;

    // ------------------------------------------------------------ banner
    var flash = document.querySelector('[data-flash]');
    if (flash) {
        flash.querySelector('[data-flash-close]').addEventListener('click', function () {
            flash.classList.add('is-leaving');
            setTimeout(function () { flash.remove(); }, 200);
        });
        if (window.history && window.history.replaceState) {
            // a reload should not show the banner again
            window.history.replaceState(null, '', window.location.pathname);
        }
    }

    // --------------------------------------------------------------- rows
    function rows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-row]'));
    }

    function fill(tr, item) {
        tr.setAttribute('data-id', item.id);
        tr.setAttribute('data-title', item.title);
        tr.setAttribute('data-template', item.template);
        tr.setAttribute('data-search', item.search);
        if (item.missing) { tr.setAttribute('data-missing', ''); } else { tr.removeAttribute('data-missing'); }
        var title = tr.querySelector('[data-f="title"]');
        title.textContent = item.title;
        title.title = item.title;
        var file = tr.querySelector('[data-f="file"]');
        file.textContent = item.file_name;
        file.title = item.file_name;
        tr.querySelector('[data-f="icon"]').className = 'gen-icon gen-icon--' + item.tone;
        var badge = tr.querySelector('[data-f="template"]');
        badge.textContent = item.template_name;
        badge.className = 'badge badge--' + item.tone + ' gen-template';
        var original = tr.querySelector('[data-f="original"]');
        original.textContent = item.source_original_name || '—';
        original.title = item.source_original_name;
        var time = tr.querySelector('[data-f="created"]');
        time.textContent = item.created_label;
        time.setAttribute('datetime', item.created_at);
        tr.querySelector('[data-f="size"]').textContent = item.size_label;
        var view = tr.querySelector('[data-f="view"]');
        var download = tr.querySelector('[data-f="download"]');
        view.href = item.preview_url;
        download.href = item.download_url;
        view.hidden = download.hidden = !!item.missing;
        tr.querySelector('[data-f="missing"]').hidden = !item.missing;
        var editor = tr.querySelector('[data-f="editor"]');
        editor.hidden = !item.editor_url;
        editor.href = item.editor_url || '#';
    }

    function build(item) {
        var tr = template.content.firstElementChild.cloneNode(true);
        fill(tr, item);
        return tr;
    }

    // ------------------------------------------------ filter + pagination
    function matches() {
        var term = (search.value || '').trim().toLowerCase();
        var tpl = filter.value;
        return rows().filter(function (tr) {
            return (!term || tr.getAttribute('data-search').indexOf(term) !== -1)
                && (!tpl || tr.getAttribute('data-template') === tpl);
        });
    }

    function pageButton(label, target, opts) {
        var btn = R.el('button', 'pager__btn' + (opts.current ? ' is-current' : ''));
        btn.type = 'button';
        if (opts.icon) {
            btn.innerHTML = R.icon(opts.icon);
            btn.setAttribute('aria-label', label);
        } else {
            btn.textContent = label;
            btn.setAttribute('aria-label', 'Page ' + label);
        }
        if (opts.current) { btn.setAttribute('aria-current', 'page'); }
        btn.disabled = !!opts.disabled;
        btn.addEventListener('click', function () {
            page = target;
            refresh();
            section.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        });
        return btn;
    }

    function refresh() {
        var all = rows();
        var found = matches();
        var pages = Math.max(1, Math.ceil(found.length / PAGE_SIZE));
        page = Math.min(Math.max(1, page), pages);
        var from = (page - 1) * PAGE_SIZE;

        all.forEach(function (tr) { tr.hidden = true; });
        found.slice(from, from + PAGE_SIZE).forEach(function (tr, i) {
            tr.hidden = false;
            tr.querySelector('[data-f="num"]').textContent = String(from + i + 1);
        });

        tools.hidden = all.length === 0;
        empty.hidden = all.length !== 0;
        noMatch.hidden = !(all.length && !found.length);
        tableWrap.hidden = !found.length;
        footer.hidden = !found.length;
        if (found.length) {
            pageInfo.textContent = 'Showing ' + (from + 1) + ' to ' + Math.min(from + PAGE_SIZE, found.length) + ' of ' + found.length + (found.length === 1 ? ' file' : ' files');
        }
        pager.textContent = '';
        pager.appendChild(pageButton('Previous page', page - 1, { icon: 'chevronLeft', disabled: page <= 1 }));
        var list = [];
        for (var p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || Math.abs(p - page) <= 1) { list.push(p); } else if (list[list.length - 1] !== '…') { list.push('…'); }
        }
        list.forEach(function (p) {
            if (p === '…') {
                pager.appendChild(R.el('span', 'pager__gap', '…'));
            } else {
                pager.appendChild(pageButton(String(p), p, { current: p === page }));
            }
        });
        pager.appendChild(pageButton('Next page', page + 1, { icon: 'chevronRight', disabled: page >= pages }));
    }

    var timer = null;
    search.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { page = 1; refresh(); }, 120);
    });
    filter.addEventListener('change', function () { page = 1; refresh(); });
    section.querySelector('[data-clear]').addEventListener('click', function () {
        search.value = '';
        filter.value = '';
        page = 1;
        refresh();
        search.focus();
    });

    // ------------------------------------------------------------ refresh
    function syncFilter(items) {
        var current = filter.value;
        var names = {};
        items.forEach(function (item) { names[item.template] = item.template_name; });
        while (filter.options.length > 1) { filter.remove(1); }
        Object.keys(names).sort(function (a, b) { return names[a].localeCompare(names[b]); }).forEach(function (id) {
            var option = R.el('option', null, names[id]);
            option.value = id;
            filter.appendChild(option);
        });
        filter.value = names[current] !== undefined ? current : '';
    }

    refreshBtn.addEventListener('click', function () {
        refreshBtn.disabled = true;
        refreshBtn.classList.add('is-spinning');
        R.api(listUrl).then(function (res) {
            refreshBtn.disabled = false;
            refreshBtn.classList.remove('is-spinning');
            if (!res.ok || !res.data) {
                R.toast(res.message || 'The list could not be loaded. Please try again.', 'error');
                return;
            }
            var before = {};
            rows().forEach(function (tr) { before[tr.getAttribute('data-id')] = true; });
            tbody.textContent = '';
            var added = 0;
            res.data.items.forEach(function (item) {
                var tr = build(item);
                if (!before[item.id]) {
                    tr.classList.add('is-new');
                    added++;
                }
                tbody.appendChild(tr);
            });
            syncFilter(res.data.items);
            refresh();
            R.toast(added ? added + (added === 1 ? ' new file.' : ' new files.') : 'The list is up to date.', 'success');
        });
    });

    // ------------------------------------------------------------ actions
    tbody.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-action]');
        if (!btn) { return; }
        var tr = btn.closest('tr[data-row]');
        R.closeDropdowns(null);
        var action = btn.getAttribute('data-action');
        if (action === 'rename') { renameFile(tr); }
        if (action === 'delete') { deleteFile(tr); }
        if (action === 'copy') { copyName(tr); }
    });

    function renameFile(tr) {
        var id = tr.getAttribute('data-id');
        var title = tr.getAttribute('data-title');
        R.modal({
            title: 'Rename file',
            message: 'Only the title shown here changes — the stored file name stays the same.',
            icon: 'pencil',
            input: { label: 'Title', value: title, maxLength: 160 },
            confirmLabel: 'Save',
            beforeClose: function (value) {
                if (value === title) { return true; }
                return R.post(endpoint, { action: 'rename', id: id, title: value }).then(function (res) {
                    if (!res.ok) {
                        R.toast(res.message || 'The title could not be changed.', 'error');
                        if (res.errors && res.errors[0] === 'not_found') { tr.remove(); refresh(); return true; }
                        return false;
                    }
                    fill(tr, res.data.item);
                    refresh();
                    R.toast(res.message, 'success');
                    return true;
                });
            }
        });
    }

    function deleteFile(tr) {
        var id = tr.getAttribute('data-id');
        var name = tr.querySelector('[data-f="file"]').textContent;
        R.modal({
            title: 'Delete this PDF?',
            message: '“' + name + '” will be permanently removed from your folder. The source PDF and its data are kept, so you can generate it again.',
            tone: 'danger',
            confirmLabel: 'Delete'
        }).then(function (result) {
            if (!result.confirmed) { return; }
            tr.classList.add('is-removing');
            R.post(endpoint, { action: 'delete', id: id }).then(function (res) {
                if (res.ok || (res.errors && res.errors[0] === 'not_found')) {
                    setTimeout(function () { tr.remove(); refresh(); }, 180);
                    R.toast(res.ok ? res.message : 'This file was already deleted.', res.ok ? 'success' : 'info');
                } else {
                    tr.classList.remove('is-removing');
                    R.toast(res.message || 'The file could not be deleted.', 'error');
                }
            });
        });
    }

    function copyName(tr) {
        var name = tr.querySelector('[data-f="file"]').textContent;
        var done = function () { R.toast('File name copied.', 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(name).then(done, function () { R.toast(name, 'info'); });
        } else {
            R.toast(name, 'info');
        }
    }

    // ------------------------------------------------------------- start
    if (newId) {
        var fresh = tbody.querySelector('tr[data-row][data-id="' + newId + '"]');
        if (fresh) { fresh.classList.add('is-new'); }
    }
    refresh();
})();
