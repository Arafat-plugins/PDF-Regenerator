/* PDF Regenerator — "Generating Your PDF" dialog (Part D §9, mockup 09_30_10 without the
 * percentage ring and countdown).
 *
 * The job runs one server request per step (generate.php action=start, then action=step);
 * every step state and duration shown here is the one the server recorded.
 *
 *   PdfRegen.generation.run({id, url, filesUrl, onSuccess(data)}) → Promise<{ok, item, profile}> (resolves when the dialog closes)
 */
(function () {
    'use strict';

    var R = window.PdfRegen;
    if (!R) { return; }
    var el = R.el;

    function icon(name, cls) {
        var span = el('span', cls);
        span.setAttribute('aria-hidden', 'true');
        span.innerHTML = R.icon(name);
        return span;
    }

    function run(opts) {
        return new Promise(function (resolve) {
            var previousFocus = document.activeElement;
            var backdrop = el('div', 'modal-backdrop modal-backdrop--busy');
            var dialog = el('div', 'modal gen-modal');
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.setAttribute('aria-labelledby', 'gen-modal-title');
            dialog.setAttribute('aria-describedby', 'gen-modal-sub');
            dialog.tabIndex = -1;

            var close = el('button', 'gen-modal__close');
            close.type = 'button';
            close.setAttribute('aria-label', 'Close');
            close.innerHTML = R.icon('x');
            close.hidden = true;
            dialog.appendChild(close);

            var badge = el('div', 'gen-modal__badge is-running');
            badge.appendChild(el('span', 'gen-modal__ring'));
            var badgeIcon = icon('fileText', 'gen-modal__badge-icon');
            badge.appendChild(badgeIcon);
            dialog.appendChild(badge);

            var title = el('h2', 'gen-modal__title', 'Generating Your PDF');
            title.id = 'gen-modal-title';
            dialog.appendChild(title);
            var sub = el('p', 'gen-modal__sub', 'Please wait while we apply the data to your template…');
            sub.id = 'gen-modal-sub';
            dialog.appendChild(sub);

            var list = el('ol', 'gen-steps');
            list.setAttribute('aria-live', 'polite');
            var stepEls = {};
            [['read', 'Reading PDF data'], ['template', 'Processing template'], ['pdf', 'Generating PDF'], ['save', 'Saving file']].forEach(function (s) {
                var li = el('li', 'gen-step is-pending');
                var mark = el('span', 'gen-step__mark');
                li.appendChild(mark);
                var label = el('span', 'gen-step__label', s[1]);
                li.appendChild(label);
                var state = el('span', 'sr-only', ' — waiting');
                li.appendChild(state);
                var time = el('span', 'gen-step__time', '–');
                li.appendChild(time);
                list.appendChild(li);
                stepEls[s[0]] = { li: li, mark: mark, time: time, state: state };
            });
            dialog.appendChild(list);

            var detail = el('p', 'gen-modal__detail');
            detail.hidden = true;
            dialog.appendChild(detail);

            var foot = el('div', 'gen-modal__foot');
            var note = el('p', 'gen-modal__note');
            note.appendChild(icon('shield', 'gen-modal__note-icon'));
            note.appendChild(el('span', null, 'Each step is confirmed by the server — this only takes a moment.'));
            foot.appendChild(note);
            var actions = el('div', 'gen-modal__actions');
            actions.hidden = true;
            foot.appendChild(actions);
            dialog.appendChild(foot);

            backdrop.appendChild(dialog);
            document.body.appendChild(backdrop);
            document.body.classList.add('modal-open');
            dialog.focus();

            var finished = false;
            var outcome = { ok: false, item: null, profile: null };

            function setStep(key, state, time) {
                var s = stepEls[key];
                if (!s) { return; }
                s.li.className = 'gen-step is-' + state;
                s.mark.innerHTML = state === 'done' ? R.icon('checkSm') : (state === 'failed' ? R.icon('xSm') : '');
                s.state.textContent = { pending: ' — waiting', active: ' — in progress', done: ' — done', failed: ' — failed' }[state] || '';
                if (time !== undefined) { s.time.textContent = time || '–'; }
                if (state === 'active') { s.li.setAttribute('aria-current', 'step'); } else { s.li.removeAttribute('aria-current'); }
            }

            function render(job) {
                (job.steps || []).forEach(function (s) { setStep(s.key, s.state, s.time); });
            }

            function link(label, href, cls, iconName, newTab) {
                var a = el('a', 'btn ' + cls);
                a.href = href;
                if (newTab) { a.target = '_blank'; a.rel = 'noopener'; }
                a.appendChild(icon(iconName, 'btn__icon'));
                a.appendChild(el('span', null, label));
                return a;
            }

            function button(label, cls, iconName) {
                var b = el('button', 'btn ' + cls);
                b.type = 'button';
                if (iconName) { b.appendChild(icon(iconName, 'btn__icon')); }
                b.appendChild(el('span', null, label));
                return b;
            }

            function finish() {
                finished = true;
                close.hidden = false;
                note.hidden = true;
                actions.hidden = false;
                badge.classList.remove('is-running');
            }

            function succeed(data) {
                finish();
                outcome = { ok: true, item: data.item, profile: data.profile || null };
                if (opts.onSuccess) { opts.onSuccess(data); }
                badge.classList.add('is-done');
                badgeIcon.innerHTML = R.icon('checkLg');
                title.textContent = 'Your PDF is ready.';
                var item = data.item || {};
                sub.textContent = 'It has been saved in your files (' + (item.size_label || '') + ').';
                detail.hidden = false;
                detail.textContent = item.file_name || '';
                if (data.profile) {
                    var p = el('p', 'gen-modal__profile');
                    p.appendChild(icon('globe', 'gen-modal__profile-icon'));
                    p.appendChild(el('span', null, data.profile.created
                        ? 'Website profile “' + data.profile.name + '” was created — next time its template, colors, logo and texts are filled in for you.'
                        : 'Website profile “' + data.profile.name + '” was updated.'));
                    dialog.insertBefore(p, foot);
                }
                actions.appendChild(link('Preview PDF', item.preview_url, 'btn-secondary', 'eye', true));
                var dl = link('Download PDF', item.download_url, 'btn-primary', 'download', false);
                actions.appendChild(dl);
                var more = el('div', 'gen-modal__more');
                if (opts.filesUrl) {
                    var files = el('a', 'link', 'Open Generated Files');
                    files.href = opts.filesUrl + (opts.filesUrl.indexOf('?') === -1 ? '?' : '&') + 'new=' + encodeURIComponent(item.id);
                    more.appendChild(files);
                }
                var closeLink = el('button', 'link gen-modal__close-link', 'Close');
                closeLink.type = 'button';
                closeLink.addEventListener('click', done);
                more.appendChild(closeLink);
                foot.appendChild(more);
                dl.focus();
            }

            function fail(res, activeKey) {
                finish();
                badge.classList.add('is-failed');
                badgeIcon.innerHTML = R.icon('xLg');
                title.textContent = 'The PDF could not be generated.';
                sub.textContent = (res && res.message) || 'Please try again.';
                if (res && res.data && res.data.job) {
                    render(res.data.job);
                } else if (activeKey) {
                    setStep(activeKey, 'failed');
                }
                var again = button('Try again', 'btn-primary', 'rotate');
                again.addEventListener('click', function () {
                    teardown();
                    run(opts).then(resolve);
                });
                var closeBtn = button('Close', 'btn-secondary');
                closeBtn.addEventListener('click', done);
                actions.appendChild(closeBtn);
                actions.appendChild(again);
                again.focus();
            }

            function teardown() {
                document.removeEventListener('keydown', onKey, true);
                backdrop.classList.add('is-leaving');
                setTimeout(function () {
                    backdrop.remove();
                    if (!document.querySelector('.modal-backdrop')) { document.body.classList.remove('modal-open'); }
                }, 160);
            }

            function done() {
                teardown();
                if (previousFocus && previousFocus.focus) { previousFocus.focus({ preventScroll: true }); }
                resolve(outcome);
            }

            function onKey(event) {
                var stack = document.querySelectorAll('.modal-backdrop');
                if (stack[stack.length - 1] !== backdrop) { return; }
                if (event.key === 'Escape') {
                    event.preventDefault();
                    if (finished) { done(); }
                }
                if (event.key === 'Tab') {
                    var focusable = Array.prototype.filter.call(dialog.querySelectorAll('button, a[href]'), function (n) { return !n.hidden && n.offsetParent !== null; });
                    if (!focusable.length) { event.preventDefault(); return; }
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];
                    if (event.shiftKey && (document.activeElement === first || document.activeElement === dialog)) { event.preventDefault(); last.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                }
            }
            document.addEventListener('keydown', onKey, true);
            close.addEventListener('click', done);

            // --------------------------------------------------------- steps
            function next(job) {
                render(job);
                if (job.status === 'done') { return; }
                var key = job.next;
                if (!key) { fail({ message: 'The generation stopped unexpectedly. Please try again.' }); return; }
                setStep(key, 'active');
                R.post(opts.url, { action: 'step', job: job.id, step: key }).then(function (res) {
                    handle(res, key);
                });
            }

            function handle(res, key) {
                if (!res.ok) {
                    fail(res, key);
                    return;
                }
                if (res.data.job.status === 'done') {
                    render(res.data.job);
                    succeed(res.data);
                    return;
                }
                next(res.data.job);
            }

            setStep('read', 'active');
            R.post(opts.url, { action: 'start', id: opts.id }).then(function (res) {
                handle(res, 'read');
            });
        });
    }

    R.generation = { run: run };
})();
