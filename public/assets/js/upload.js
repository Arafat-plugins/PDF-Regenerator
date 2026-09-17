/* PDF Regenerator — Upload page (drag & drop, instant upload, file table) */
(function () {
    'use strict';

    var R = window.PdfRegen;
    var zone = document.querySelector('[data-dropzone]');
    if (!zone || !R) { return; }

    var endpoint = zone.getAttribute('data-endpoint');
    var extractUrl = zone.getAttribute('data-extract-url');
    var editorUrl = zone.getAttribute('data-editor-url');
    var maxSize = parseInt(zone.getAttribute('data-max-size'), 10) || 0;
    var maxLabel = zone.getAttribute('data-max-label') || '';
    var input = zone.querySelector('[data-file-input]');

    var chip = document.querySelector('[data-file-chip]');
    var chipIcon = chip.querySelector('[data-chip-icon]');
    var chipName = chip.querySelector('[data-chip-name]');
    var chipMeta = chip.querySelector('[data-chip-meta]');
    var chipProgress = chip.querySelector('[data-chip-progress]');
    var chipBar = chip.querySelector('[data-chip-bar]');
    var chipActions = chip.querySelector('[data-chip-actions]');
    var chipClose = chip.querySelector('[data-chip-close]');

    var section = document.querySelector('[data-uploads]');
    var tbody = section.querySelector('[data-uploads-body]');
    var template = section.querySelector('[data-row-template]');
    var tableWrap = section.querySelector('[data-uploads-table]');
    var emptyState = section.querySelector('[data-uploads-empty]');
    var noMatch = section.querySelector('[data-uploads-nomatch]');
    var footer = section.querySelector('[data-uploads-footer]');
    var countPill = section.querySelector('[data-uploads-count]');
    var searchWrap = section.querySelector('[data-uploads-search-wrap]');
    var search = section.querySelector('[data-uploads-search]');
    var pageInfo = section.querySelector('[data-page-info]');
    var pageLabel = section.querySelector('[data-page-label]');
    var prevBtn = section.querySelector('[data-page-prev]');
    var nextBtn = section.querySelector('[data-page-next]');

    var step = document.querySelector('[data-profile-step]');
    var stepBody = step ? step.querySelector('[data-profile-body]') : null;
    var stepFile = step ? step.querySelector('[data-profile-file]') : null;
    var stepNote = step ? step.querySelector('[data-profile-note]') : null;
    var stepBtn = step ? step.querySelector('[data-profile-continue]') : null;
    var stepChooser = null;
    var stepItem = null;

    var PAGE_SIZE = 10;
    var page = 1;
    var xhr = null;
    var busy = false;

    function formatBytes(bytes) {
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return (i === 0 ? String(Math.round(bytes)) : String(parseFloat(bytes.toFixed(1)))) + ' ' + units[i];
    }

    // ------------------------------------------------------------- chip
    function setChip(state, name, meta, options) {
        options = options || {};
        chip.hidden = false;
        chip.className = 'file-chip file-chip--' + state;
        chipName.textContent = name;
        chipMeta.textContent = meta;
        chipProgress.hidden = state !== 'uploading' && state !== 'checking';
        chipProgress.classList.toggle('progress--indeterminate', state === 'checking');
        chipIcon.innerHTML = '';
        if (state === 'uploading' || state === 'checking') {
            chipIcon.appendChild(R.el('span', 'spinner'));
        } else {
            chipIcon.innerHTML = R.icon(state === 'error' ? 'x' : 'check');
        }
        chipActions.innerHTML = '';
        if (options.action) { chipActions.appendChild(options.action); }
        chipClose.setAttribute('aria-label', state === 'uploading' ? 'Cancel upload' : 'Dismiss');
    }

    function setProgress(ratio) {
        chipBar.style.width = Math.max(2, Math.round(ratio * 100)) + '%';
    }

    chipClose.addEventListener('click', function () {
        if (xhr && busy) {
            xhr.abort();
            return;
        }
        chip.hidden = true;
        hideStep();
    });

    // ------------------------------------------------------------ upload
    function pick() {
        if (busy) { return; }
        input.value = '';
        input.click();
    }

    document.querySelectorAll('[data-choose]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.stopPropagation();
            if (!zone.contains(btn)) { zone.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            pick();
        });
    });
    zone.addEventListener('click', function (event) {
        if (event.target === input) { return; }
        pick();
    });
    zone.setAttribute('tabindex', '0');
    zone.setAttribute('role', 'button');
    zone.setAttribute('aria-label', 'Upload a PDF: drag and drop a file here or press Enter to browse. Maximum ' + maxLabel + '.');
    zone.addEventListener('keydown', function (event) {
        if (event.target === zone && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            pick();
        }
    });

    input.addEventListener('change', function () {
        if (input.files && input.files.length) { start(input.files[0], input.files.length); }
    });

    var dragDepth = 0;
    ['dragenter', 'dragover'].forEach(function (type) {
        zone.addEventListener(type, function (event) {
            event.preventDefault();
            if (type === 'dragenter') { dragDepth++; }
            if (event.dataTransfer) { event.dataTransfer.dropEffect = busy ? 'none' : 'copy'; }
            zone.classList.add('is-dragover');
        });
    });
    zone.addEventListener('dragleave', function () {
        dragDepth = Math.max(0, dragDepth - 1);
        if (dragDepth === 0) { zone.classList.remove('is-dragover'); }
    });
    zone.addEventListener('drop', function (event) {
        event.preventDefault();
        dragDepth = 0;
        zone.classList.remove('is-dragover');
        var files = event.dataTransfer && event.dataTransfer.files;
        if (files && files.length && !busy) { start(files[0], files.length); }
    });
    // Dropping a file anywhere else must not navigate away from the page.
    window.addEventListener('dragover', function (event) { event.preventDefault(); });
    window.addEventListener('drop', function (event) { event.preventDefault(); });

    function start(file, count) {
        var name = file.name || 'document.pdf';
        var isPdf = /\.pdf$/i.test(name);

        if (!isPdf) {
            setChip('error', name, 'Only PDF files are allowed.', { action: retryButton() });
            return;
        }
        if (file.size === 0) {
            setChip('error', name, 'The selected file is empty.', { action: retryButton() });
            return;
        }
        if (maxSize && file.size > maxSize) {
            setChip('error', name, 'This file is too large (' + formatBytes(file.size) + '). The maximum size is ' + maxLabel + '.', { action: retryButton() });
            return;
        }

        upload(file, count > 1);
    }

    function upload(file, multiple) {
        busy = true;
        zone.classList.add('is-busy');
        hideStep();
        var name = file.name;
        setChip('uploading', name, 'Uploading… 0% of ' + formatBytes(file.size));
        setProgress(0);

        var data = new FormData();
        data.append('action', 'upload');
        data.append('pdf', file, name);

        xhr = new XMLHttpRequest();
        xhr.open('POST', endpoint, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-Token', R.csrf());
        xhr.responseType = 'text';

        xhr.upload.addEventListener('progress', function (event) {
            if (!event.lengthComputable) { return; }
            var ratio = event.loaded / event.total;
            setProgress(ratio);
            chipMeta.textContent = 'Uploading… ' + Math.round(ratio * 100) + '% of ' + formatBytes(file.size);
        });
        // All bytes sent: the server is now validating the PDF (real state, no timer).
        xhr.upload.addEventListener('load', function () {
            setChip('checking', name, 'Checking the PDF (type, size, pages)…');
        });

        xhr.addEventListener('load', function () {
            var res = parse(xhr);
            if (xhr.status === 401) { window.location.href = 'login.php?expired=1'; return; }
            finish();
            if (res.ok && res.data && res.data.item) {
                var item = res.data.item;
                var metaParts = [item.size_label];
                if (item.pages_label) { metaParts.push(item.pages_label); }
                metaParts.push(item.has_text ? 'Saved to your folder' : 'Saved · no readable text found');
                setChip(item.has_text ? 'success' : 'warning', item.title + '.pdf', metaParts.join(' · '));
                addRow(item);
                showStep(item);
                R.toast(multiple ? res.message + ' (only the first file was used)' : res.message, item.has_text ? 'success' : 'warning');
            } else {
                setChip('error', name, res.message || 'Unable to upload the PDF. Please check the file and try again.', { action: retryButton() });
            }
        });
        xhr.addEventListener('error', function () {
            finish();
            setChip('error', name, 'Network error. Please check your connection and try again.', { action: retryButton() });
        });
        xhr.addEventListener('abort', function () {
            finish();
            setChip('error', name, 'Upload cancelled.', { action: retryButton() });
        });

        xhr.send(data);
    }

    function finish() {
        busy = false;
        zone.classList.remove('is-busy');
    }

    function parse(request) {
        try {
            return JSON.parse(request.responseText);
        } catch (e) {
            if (request.status === 413) {
                return { ok: false, message: 'This file is too large. The maximum size is ' + maxLabel + '.' };
            }
            return { ok: false, message: 'Unable to upload the PDF. Please check the file and try again.' };
        }
    }

    function retryButton() {
        var btn = R.el('button', 'btn btn-sm btn-secondary', 'Choose another file');
        btn.type = 'button';
        btn.addEventListener('click', pick);
        return btn;
    }

    // ------------------------------------------------ website profile step
    function stepHint(v, valid) {
        if (!stepNote) { return; }
        if (valid && v.mode === 'existing') {
            stepNote.textContent = 'The saved template, colors, logo and texts are applied after extraction.';
        } else if (valid) {
            stepNote.textContent = 'A profile for “' + v.name + '” is saved when you generate the report.';
        } else {
            stepNote.textContent = v.mode === 'new' ? 'Enter the website name to continue.' : 'Choose a profile to continue.';
        }
    }

    function showStep(item) {
        if (!step || !R.profileChooser) { return; }
        stepItem = item;
        stepBody.textContent = '';
        stepChooser = R.profileChooser.create({
            onChange: function (v, valid) {
                stepBtn.disabled = !valid;
                stepHint(v, valid);
            }
        });
        stepBody.appendChild(stepChooser.node);
        stepFile.textContent = 'For “' + item.title + '.pdf”';
        stepBtn.disabled = !stepChooser.isValid();
        stepHint(stepChooser.value(), stepChooser.isValid());
        step.hidden = false;
        step.classList.add('is-new');
        setTimeout(function () { step.classList.remove('is-new'); }, 600);
        step.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideStep() {
        if (!step) { return; }
        step.hidden = true;
        stepItem = null;
        stepChooser = null;
    }

    if (stepBtn) {
        stepBtn.addEventListener('click', function () {
            if (!stepChooser || !stepItem || stepBtn.disabled) { return; }
            if (!stepChooser.isValid()) { return; }
            stepBtn.disabled = true;
            extract(stepItem.id, stepItem.title, stepChooser.value()).then(function (res) {
                stepBtn.disabled = false;
                if (!res.ok) {
                    var field = res.data && res.data.field;
                    if (field) { stepChooser.showError(field, res.message); }
                    if (res.errors && res.errors[0] === 'profile' && R.profileChooser) { R.profileChooser.refresh(); }
                }
            });
        });
    }

    /**
     * Run extraction with the chosen profile; on success the page moves to the editor.
     * Resolves with the envelope when it failed (the caller shows field errors).
     */
    function extract(id, title, choice) {
        var dialog = R.busy({
            title: 'Extracting data',
            message: 'Reading “' + title + '” and matching its report layout…',
            icon: 'scan'
        });
        var fields = { action: 'extract', id: id };
        var extra = R.profileChooser ? R.profileChooser.toFields(choice) : {};
        Object.keys(extra).forEach(function (k) { fields[k] = extra[k]; });
        return R.post(extractUrl, fields).then(function (res) {
            if (res.ok && res.data && res.data.redirect) {
                dialog.update(res.data.scanned ? 'No readable text found — opening the results…' : (choice.mode === 'existing' ? 'Done — applying the website profile…' : 'Done — opening the extracted data…'));
                window.location.href = res.data.redirect;
                return new Promise(function () {}); // stay busy while the page changes
            }
            dialog.close();
            var tr = tbody.querySelector('tr[data-row][data-id="' + id + '"]');
            if (tr && res.errors && res.errors[0] === 'extraction') { setStatus(tr, 'failed', 'Failed', 'red'); }
            if (!(res.data && res.data.field)) {
                R.toast(res.message || 'We couldn\'t extract readable data from this PDF.', 'error');
            }
            return res;
        });
    }

    // ------------------------------------------------------------ table
    function rowSub(item) {
        var parts = [];
        if (item.pages_label) { parts.push(item.pages_label); }
        if (item.original_name && item.original_name !== item.title + '.pdf') { parts.push(item.original_name); }
        return parts.join(' · ');
    }

    function fillRow(tr, item) {
        tr.setAttribute('data-id', item.id);
        tr.setAttribute('data-title', item.title);
        tr.setAttribute('data-status', item.status);
        tr.setAttribute('data-search', (item.title + ' ' + item.original_name).toLowerCase());
        tr.querySelector('[data-f="title"]').textContent = item.title;
        tr.querySelector('[data-f="sub"]').textContent = rowSub(item);
        var badge = tr.querySelector('[data-f="status"]');
        badge.textContent = item.status_label;
        badge.className = 'badge badge--' + item.status_tone;
        tr.querySelector('[data-f="size"]').textContent = item.size_label;
        var time = tr.querySelector('[data-f="created"] time');
        time.textContent = item.created_label;
        time.setAttribute('datetime', item.created_at);
        tr.querySelector('[data-f="download"]').href = item.download_url;
        tr.querySelector('[data-f="download2"]').href = item.download_url;
        tr.querySelector('[data-f="view"]').href = item.view_url;
    }

    function addRow(item) {
        var tr = template.content.firstElementChild.cloneNode(true);
        fillRow(tr, item);
        tr.classList.add('is-new');
        tbody.insertBefore(tr, tbody.firstChild);
        search.value = '';
        page = 1;
        refresh();
        setTimeout(function () { tr.classList.remove('is-new'); }, 2400);
    }

    function rows() {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-row]'));
    }

    function refresh() {
        var all = rows();
        var term = (search.value || '').trim().toLowerCase();
        var matches = all.filter(function (tr) { return !term || tr.getAttribute('data-search').indexOf(term) !== -1; });
        var pages = Math.max(1, Math.ceil(matches.length / PAGE_SIZE));
        page = Math.min(Math.max(1, page), pages);
        var from = (page - 1) * PAGE_SIZE;

        all.forEach(function (tr) { tr.hidden = true; });
        matches.slice(from, from + PAGE_SIZE).forEach(function (tr) { tr.hidden = false; });

        countPill.textContent = String(all.length);
        emptyState.hidden = all.length !== 0;
        searchWrap.hidden = all.length === 0;
        noMatch.hidden = !(all.length && !matches.length);
        tableWrap.hidden = !matches.length;
        footer.hidden = !matches.length;

        if (matches.length) {
            pageInfo.textContent = 'Showing ' + (from + 1) + ' to ' + Math.min(from + PAGE_SIZE, matches.length) + ' of ' + matches.length + ' file' + (matches.length === 1 ? '' : 's');
            pageLabel.textContent = page + ' / ' + pages;
            prevBtn.disabled = page <= 1;
            nextBtn.disabled = page >= pages;
            section.querySelector('[data-pager]').hidden = pages <= 1;
        }
    }

    search.addEventListener('input', function () { page = 1; refresh(); });
    prevBtn.addEventListener('click', function () { page--; refresh(); });
    nextBtn.addEventListener('click', function () { page++; refresh(); });

    tbody.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-action]');
        if (!btn) { return; }
        var tr = btn.closest('tr[data-row]');
        var id = tr.getAttribute('data-id');
        var title = tr.getAttribute('data-title');
        R.closeDropdowns(null);

        if (btn.getAttribute('data-action') === 'use') { useFile(id, title); }
        if (btn.getAttribute('data-action') === 'rename') { renameFile(tr, id, title); }
        if (btn.getAttribute('data-action') === 'delete') { deleteFile(tr, id, title); }
    });

    // "Use": open the extracted data, or choose the website profile and read the PDF first.
    function useFile(id, title) {
        var tr = tbody.querySelector('tr[data-row][data-id="' + id + '"]');
        var status = tr ? tr.getAttribute('data-status') : '';
        if (status === 'extracted' || status === 'editing' || status === 'generated') {
            window.location.href = editorUrl + '?id=' + encodeURIComponent(id);
            return;
        }
        if (!R.profileChooser) {
            extract(id, title, { mode: '' });
            return;
        }
        R.profileChooser.open({
            title: 'Website Profile',
            message: 'Is this report for an existing website or a new one? The data is extracted right after your choice.',
            source: title + '.pdf',
            confirmLabel: 'Extract & Continue',
            submit: function (choice) { return extract(id, title, choice); }
        });
    }

    function setStatus(tr, status, label, tone) {
        tr.setAttribute('data-status', status);
        var badge = tr.querySelector('[data-f="status"]');
        badge.textContent = label;
        badge.className = 'badge badge--' + tone;
    }

    function renameFile(tr, id, title) {
        R.modal({
            title: 'Rename file',
            message: 'Only the display title changes — the stored file stays the same.',
            icon: 'pencil',
            input: { label: 'Title', value: title, maxLength: 120 },
            confirmLabel: 'Save'
        }).then(function (result) {
            if (!result.confirmed || result.value === title) { return; }
            R.post(endpoint, { action: 'rename', id: id, title: result.value }).then(function (res) {
                if (res.ok) {
                    fillRow(tr, res.data.item);
                    refresh();
                    R.toast(res.message, 'success');
                } else {
                    R.toast(res.message || 'The title could not be changed.', 'error');
                }
            });
        });
    }

    function deleteFile(tr, id, title) {
        R.modal({
            title: 'Delete this file?',
            message: '“' + title + '” and its extracted data will be permanently removed from your folder. Generated reports made from it are kept.',
            tone: 'danger',
            confirmLabel: 'Delete'
        }).then(function (result) {
            if (!result.confirmed) { return; }
            tr.classList.add('is-removing');
            R.post(endpoint, { action: 'delete', id: id }).then(function (res) {
                if (res.ok) {
                    setTimeout(function () { tr.remove(); refresh(); }, 180);
                    R.toast(res.message, 'success');
                } else {
                    tr.classList.remove('is-removing');
                    R.toast(res.message || 'The file could not be deleted.', 'error');
                }
            });
        });
    }

    refresh();
})();
